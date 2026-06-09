# PGP key reminder sweep — administrator guide

The `check_expiring_keys` command is Cerebrate's scheduled sweep that
notifies individuals whose PGP keys are approaching or past their
expiry date. It consumes the mailer subsystem documented in
[`email.md`](email.md); read that guide first.

This page is for operators wiring the sweep into cron and reasoning
about its idempotency and recovery surface.

---

## What it does

Once per run, the sweep:

1. Loads every `encryption_keys` row owned by an `Individual` whose
   record has a non-empty `email`.
2. Parses each key's soonest encryption-capable subkey expiry on the
   fly via GnuPG. Keys without an expiring encryption subkey are
   skipped (we don't pester recipients about keys that don't expire).
3. Computes whether the key has just crossed a configured threshold
   (default `30, 7, 1` days before expiry, plus an "expired" bucket
   once the key passes its expiry date).
4. Groups every key that crossed a threshold this run by its owning
   individual and sends that individual a single **digest** mail: a
   table listing each affected key with its status ("expires on …" or
   "EXPIRED on …"). One recipient receives one mail per run, no matter
   how many of their keys are in the window.
5. On a successful send, advances `encryption_keys.last_reminder_threshold`
   on every key the digest covered, so the same threshold is never
   re-sent.

The sweep does **not** notify operators about the server's own
signing key, and does **not** notify on organization-owned keys (no
canonical email-of-record for an organization).

---

## Digests

Reminders are **batched per recipient**. If an individual owns several
keys that cross a threshold in the same run — say one expiring in seven
days and another that expired last week — they receive a single mail
with a table of all of them, each row tagged as expiring or already
expired, instead of one mail per key.

Batching does not change idempotency: `last_reminder_threshold` is still
tracked and advanced **per key**, never per recipient. A digest advances
only the keys it actually covered; if the send fails, none of them
advance and they are retried on the next run. A failure for one
recipient never blocks another's digest.

The one exception is `--encrypt` (see below): an encrypted mail can only
be addressed to a single key, so in encrypted mode the sweep sends one
mail per key rather than a per-recipient digest.

---

## Cron entry

The command is self-contained. Drop the following into
`/etc/cron.d/cerebrate-reminders` (or the equivalent crontab line):

```
0 7 * * *  www-data  cd /var/www/cerebrate && ./bin/cake check_expiring_keys
```

Daily at 07:00 is a reasonable default. The window is not sensitive
— missed runs simply mean any threshold that *would* have fired
yesterday fires today instead, and `last_reminder_threshold` ensures
no double-send.

Run the command as the same user that owns Cerebrate's GnuPG home
directory (typically `www-data`), so the key blobs can be parsed
locally without permission surprises.

---

## Flags

```
./bin/cake check_expiring_keys
    [--thresholds=30,7,1]
    [--dry-run]
    [--encrypt]
```

- `--thresholds` — Comma-separated positive integers (days before
  expiry) at which a reminder fires. Defaults to the Cerebrate
  setting `Cerebrate.reminders.default_thresholds`, which itself
  defaults to `30,7,1`. The `-1` "expired" bucket is always evaluated
  and is not part of this list.
- `--dry-run` — Prints the list of `(individual, key, threshold)`
  rows that *would* be sent and exits without touching the mail
  transport or the database. Use to preview cadence changes before
  rolling them out.
- `--encrypt` — Encrypt each reminder to the recipient's PGP key
  using the same `GpgMailer` pipeline as the rest of the mailer.
  Because an encrypted mail can only target one key, this mode sends
  one mail per key instead of a per-recipient digest. Without this
  flag, reminders are signed-only (if `gpg_sign` is on) or plain
  (if not).

The command's exit code is `0` if every attempted send succeeded
(or if there was nothing to do), and non-zero only when **every**
attempted send failed. Per-row failures are logged via `Log::error`
and the sweep continues — one broken recipient does not block the
rest.

---

## Idempotency in practice

The `encryption_keys.last_reminder_threshold` column tracks how far
into the cadence each key has been notified:

- `NULL` — no reminder has ever been sent for this key.
- A positive integer `t` — the smallest threshold (in days) for
  which a reminder has been delivered. The sweep only re-sends when
  the newly-computed threshold is strictly smaller than `t`.
- `-1` — the "expired" reminder has been sent. No further reminders
  fire for this key.

A walkthrough for a key expiring on 2026-12-01 with thresholds
`30,7,1`:

| Sweep date | Days to expiry | Crossed | Recorded | Action          |
|------------|----------------|---------|----------|-----------------|
| 2026-10-20 | 42             | —       | `NULL`   | skip (out of window) |
| 2026-11-01 | 30             | 30      | `30`     | **send 30-day mail** |
| 2026-11-15 | 16             | 30      | `30`     | skip (same bucket) |
| 2026-11-24 | 7              | 7       | `7`      | **send 7-day mail** |
| 2026-11-30 | 1              | 1       | `1`      | **send 1-day mail** |
| 2026-12-02 | -1             | -1      | `-1`     | **send expired mail** |
| 2026-12-03 | -2             | -1      | `-1`     | skip (already expired-notified) |

Replacing the key blob through Cerebrate (re-upload, rotation, or
an extension that re-imports the armored key) automatically resets
`last_reminder_threshold` to `NULL` via the `EncryptionKeysTable`
`beforeSave` hook — the new key starts a fresh cadence against its
own expiry.

---

## Recovery and operator overrides

**Force a re-send for one key.** Set the column to `NULL` directly:

```sql
UPDATE encryption_keys SET last_reminder_threshold = NULL WHERE id = 42;
```

The next sweep will then evaluate the key from scratch and fire
whatever reminder its current expiry warrants.

**Force a re-send for everyone.** The same, without a `WHERE` clause.
Useful after fixing a misconfiguration that suppressed earlier sends:

```sql
UPDATE encryption_keys SET last_reminder_threshold = NULL;
```

**Suppress all reminders for one key.** Set the column to `-1`:

```sql
UPDATE encryption_keys SET last_reminder_threshold = -1 WHERE id = 42;
```

The sweep will treat this key as already expired-notified and skip
it forever (or until the blob changes and beforeSave clears the
column).

**Change the cadence without re-deploying.** Edit the
`Cerebrate.reminders.default_thresholds` setting via the admin UI
(or in `config/config.json`). New thresholds take effect on the
next sweep. The recorded `last_reminder_threshold` carries over —
keys that already advanced past a now-removed threshold do not
"un-fire" the prior reminder.

---

## Logging

The sweep logs to Cerebrate's `error` channel for failures and emits
one informational line per send via `Log::info` (matching the
`send_email` CLI pattern). The stdout output during a normal run
looks like:

```
individual=alice@example.org key_id=42 expires=2026-12-01T00:00:00+00:00 threshold=7
individual=alice@example.org key_id=51 expires=2026-04-30T00:00:00+00:00 threshold=-1
individual=bob@example.org key_id=99 expires=2026-12-05T00:00:00+00:00 threshold=7
Done. attempted=2 sent=2 failed=0 skipped=12
```

The per-key lines list every key that crossed a threshold this run
(here Alice has two — one expiring, one already expired). `attempted`
and `sent` count **mails** — one digest per recipient, or one per key
under `--encrypt` — so they can be lower than the number of per-key
lines when a recipient owns several expiring keys (above: three keys,
two mails). `failed` is the difference, and `skipped` covers rows that
produced no threshold crossing (out of window, unparseable blob,
missing email, etc.).

A `--dry-run` still prints the per-key lines, then a preview and a
no-op summary instead of sending:

```
Would send 2 mail(s) covering 3 key(s).
Done. attempted=0 sent=0 failed=0 skipped=12 (dry-run)
```

---

## See also

- [`email.md`](email.md) — the mailer subsystem this sweep consumes.
- [`reminder-sweep-prd.md`](../../reminder-sweep-prd.md) — the design
  document this implementation tracks.
