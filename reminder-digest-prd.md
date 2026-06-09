# PRD: Reminder Digests for the PGP Key Expiry Sweep

Status: In progress
Owner: andras.iklody@gmail.com
Target branch: develop
Builds on: [`reminder-sweep-prd.md`](reminder-sweep-prd.md), [`mailing-prd.md`](mailing-prd.md)

## 1. Background

`CheckExpiringKeysCommand` sends one reminder mail **per key**. Idempotency
and flood-control are per-key (via `encryption_keys.last_reminder_threshold`),
so a single key is never over-mailed. But the dedup is keyed on the key, not
the recipient: an individual who owns N keys all crossing a threshold in the
same run receives **N separate mails** in that run.

This PRD adds per-recipient batching ("digests"): collapse all of an
individual's crossing keys for one run into a **single** mail with a table.

## 2. Design decisions (settled)

1. **Always use the digest template.** There is one render path and one
   template pair (`reminder_key_digest.{html,text}`). A digest of one key is a
   one-row table. The single-key subject is preserved for N=1 (see §5) so the
   common case looks unchanged to recipients. The pre-existing
   `reminder_key_expiry` / `reminder_key_expired` templates and the
   `ReminderMailer::keyExpiry()` / `keyExpired()` methods stay in place — they
   remain the manual `send_email --template=…` surface and a public API — but
   the **sweep** no longer uses them.
2. **One combined digest with a status column.** A recipient with both
   expiring and already-expired keys gets a single mail; each table row is
   tagged "expires on …" or "EXPIRED on …". Rows are ordered expired-first,
   then soonest-expiry-first.
3. **No digesting under `--encrypt`.** Digesting applies to the plaintext path
   only. With `--encrypt`, the sweep keeps today's behavior: one GPG-encrypted
   mail per key (a digest can only be encrypted to one key, and expired keys
   can't be encrypted to at all). The per-key encrypted mail still renders
   through the digest template as a one-row digest, so the body shape is
   consistent across modes.

## 3. Idempotency is unchanged

`last_reminder_threshold` is still advanced **per key**, to that key's own
crossed threshold, only after the covering mail is delivered. Digesting changes
*delivery batching*, not threshold bookkeeping. If a digest send fails, **none**
of its keys advance (they retry next run); per-key isolation across *different*
individuals is preserved (one individual's failed digest does not block
another's).

## 4. Functional changes

- `App\Mailer\ReminderMailer::keyDigest(Individual $individual, array $items): void`
  - `$items`: list of `['key' => EncryptionKey, 'expiry' => DateTimeInterface, 'expired' => bool, 'threshold' => int]`.
  - Sorts items (expired first, then soonest), sets `to` / subject / template /
    view vars, and threads via `withReference()` — `key:<id>` when a single key
    (parity with current per-key threading), `digest:individual:<id>` for a
    multi-key digest.
- New templates `templates/email/{html,text}/reminder_key_digest.php` — a table
  of `(key id, status, date)` rows.
- `CheckExpiringKeysCommand`:
  - Pass 1 computes crossings for every candidate key (unchanged threshold
    logic), emitting the existing per-key `individual=… key_id=… threshold=…`
    line.
  - Pass 2 delivers: `--encrypt` → per-key encrypted; otherwise group crossings
    by individual → one digest each.
  - Exit-code semantics unchanged: error only when every attempted mail fails.

## 5. Subject lines

- N=1, expiring: `Your GPG key expires on YYYY-MM-DD` (unchanged).
- N=1, expired: `Your GPG key expired on YYYY-MM-DD` (unchanged).
- N>1: `GPG key reminders (N keys)`.

The digest templates recompute the same subject via `$this->set('subject', …)`
so the `EmailRenderer` / `GpgMailer` path matches the native plaintext subject
(same dual-source pattern noted in `progress.md` §1.4).

## 6. Out of scope

- Per-individual opt-out / `List-Unsubscribe` (separate deferred item).
- Encrypted digests (would need a "which key do we encrypt to?" rule; §2.3).
- Changing the cadence or threshold math.

## 7. Tasks

- [x] 7.1 — `ReminderMailer::keyDigest()` + `digestSubject()`.
- [x] 7.2 — `reminder_key_digest.{text,html}` templates.
- [x] 7.3 — Rewire `CheckExpiringKeysCommand` to the two-pass / group-by-individual flow.
- [x] 7.4 — Tests: multi-key single digest, two individuals → two mails, mixed expiring+expired, N=1 parity. Extend `EmailRendererTest` with a digest render case.
- [x] 7.5 — `composer cs-check` clean on touched `src/`+`tests/` files (phpcs exit 0); affected suites green; live `--dry-run` smoke verified. **Commit pending user approval.**

## 9. Status / results

- `EmailRendererTest` 7/7, `CerebrateMailerTest` 8/8 (legacy `keyExpiry`/`keyExpired`
  path still green), `CheckExpiringKeysCommandTest` 10/10 (7 original + 3 digest).
- `phpcs` (CakePHP standard) exit 0 on the four touched `src/`+`tests/` files.
  Templates are outside the `cs-check` scope (`src/ tests/` only).
- `./bin/cake check_expiring_keys --dry-run` → `Would send 0 mail(s) covering 0 key(s).`
  / `Done. attempted=0 sent=0 failed=0 skipped=10 (dry-run)` — new output format intact.
- Not yet committed; nothing staged. The legacy single-key templates/methods were
  kept (still the `send_email --template=…` surface), so the change is additive plus
  the sweep rewire.

## 8. Verification

- `./vendor/bin/phpunit tests/TestCase/Command/CheckExpiringKeysCommandTest.php tests/TestCase/Mailer/CerebrateMailerTest.php tests/TestCase/Lib/Tools/EmailRendererTest.php` green.
- `./bin/cake check_expiring_keys --dry-run` still reports the per-key lines.
- `composer cs-check` shows no new violations on touched files.
</content>
</invoke>
