# PRD: PGP key-expiry reminder sweep

Status: Draft
Owner: andras.iklody@gmail.com
Target branch: develop
Depends on: [`mailing-prd.md`](mailing-prd.md) (the mailer subsystem this PRD consumes)

## 1. Background and motivation

The mailing PRD (`mailing-prd.md`) built a generic mailer subsystem (`CerebrateMailer`,
`ReminderMailer`, `EmailRenderer`, `GpgMailer`, `SendEmailCommand`, plus the
`reminder_key_expiry` / `reminder_key_expired` templates). It does **not** trigger
reminders on its own — every send is initiated by an explicit caller.

This PRD adds the scheduled sweep that consumes that subsystem and actually
notifies individuals whose published GPG keys are about to expire or have
expired.

## 2. Goals

- Detect `encryption_keys` rows that are entering an "expiring soon" window
  (default: 30, 7, 1 days before expiry) and rows that have just transitioned
  to expired.
- For each detection, queue exactly **one** reminder per individual per
  threshold per key, never sending the same reminder twice for the same
  threshold crossing.
- Use `ReminderMailer::keyExpiry()` / `keyExpired()` for delivery so the
  template, threading, and GPG envelope behavior built in the mailing PRD all
  apply unchanged.
- Run as a cron-friendly Cake console command with sensible defaults and
  operator overrides via flags.

## 3. Non-goals (explicitly deferred)

- A new "sent reminders" audit table. The existing `AuditLogBehavior` plus
  application logs are sufficient for v1. Idempotency is tracked by a small
  marker on the `encryption_keys` row (see §5.2), not a separate ledger.
- Per-individual user preferences ("do not remind me before N days"). Out of
  scope for v1; cadence is operator-wide.
- Reminders for **server** signing-key expiry. Different audience, different
  template, different escalation path — separate PRD when needed.
- Reminders for organisation-level keys. Cerebrate stores them
  (`owner_model='organisation'`) but there's no canonical email-of-record for
  an org; defer.

## 4. User stories

- **As a Cerebrate operator**, I want a `./bin/cake check_expiring_keys`
  command I can run from cron so that individuals get warned before their
  PGP keys go stale.
- **As a Cerebrate operator**, I want to be able to dry-run the sweep and see
  what would be sent before committing to sends.
- **As a Cerebrate operator**, I want to override the reminder cadence per run
  for testing without changing config.
- **As a recipient with an expiring key**, I want exactly one reminder per
  threshold crossing (30d, 7d, 1d) per key, not one per day for thirty days.

## 5. Functional requirements

### 5.1 CLI surface

- `App\Command\CheckExpiringKeysCommand` invoked as
  `./bin/cake check_expiring_keys [--thresholds=30,7,1] [--dry-run] [--encrypt]`.
- `--thresholds` (default `30,7,1`) — days-before-expiry boundaries to
  evaluate, comma-separated. The command also always evaluates the "just
  expired" case (key whose expiry has passed since the previous run that
  hasn't been notified).
- `--dry-run` — print the would-be send list (one row per recipient + key +
  threshold) and exit without delivering anything.
- `--encrypt` — pass `--encrypt` behavior through to each send (route through
  `GpgMailer::deliverWithGpg()` with the recipient's first usable key).
  Default behavior matches `SendEmailCommand` without `--encrypt` — plain
  rendered body.
- On any per-recipient failure, log via `Log::error` and continue with the
  next recipient. The command exits non-zero only if **every** attempted send
  failed.

### 5.2 Idempotency

A new nullable column `encryption_keys.last_reminder_threshold` (smallint) is
added by migration. Semantics:

- `NULL` — no reminder has been sent for this key.
- non-negative integer `t` — the smallest threshold (in days before expiry)
  for which a reminder has been delivered. Crossing a smaller threshold (or
  the "expired" case, modeled as `t = -1`) is the only trigger to send again.

The sweep:

1. Selects `encryption_keys` with `owner_model='individual'` joined to
   `individuals` (to get `email`) and parses the key's expiry timestamp from
   `encryption_key` via `Crypt_GPG::keyInfo()` (or a denormalized column —
   open question §6).
2. Computes the current `crossed_threshold`: the smallest configured
   threshold ≥ (expiry − now), or `-1` if expiry < now.
3. If `last_reminder_threshold IS NULL` OR `crossed_threshold < last_reminder_threshold`,
   queue a send; otherwise skip.
4. After a successful send, update `last_reminder_threshold = crossed_threshold`.
5. After a failed send, do **not** advance the column (the same threshold is
   retried on the next sweep run).

### 5.3 Cadence and scheduling

- Operator runs the command from cron, typically once per day:
  `0 7 * * * cd /var/www/cerebrate && ./bin/cake check_expiring_keys`.
- The command itself does not embed a schedule. Adding a Cake scheduler is
  out of scope; cron is the standard mechanism.

### 5.4 Configuration

No new `Cerebrate.email.*` settings are required. The sweep inherits the
mailer's GPG / from / disable / only_encrypted behavior unchanged.

One optional config: `Cerebrate.reminders.default_thresholds` (string of
comma-separated ints, default `"30,7,1"`). The `--thresholds` flag overrides
it per-run.

## 6. Open questions

- **Where does key expiry live?** Today `encryption_keys` stores the ASCII
  key blob but not a parsed expiry column. The sweep needs expiry data; we
  either parse on the fly (slow at scale, but small N for now) or add an
  `encryption_keys.expires_at` denormalized column and backfill via a
  migration. Recommendation: denormalize. The sweep then becomes a cheap
  SQL filter + bounded `keyInfo()` calls only when validating the still-
  current key state.
- **What if `encryption_keys.encryption_key` parses to multiple subkey
  expiries?** Use the soonest expiry among encryption-capable subkeys (the
  first one a recipient would lose the use of).
- **How do we handle a key whose `last_reminder_threshold` is already past
  the smallest threshold but the key gets replaced and the new key has a
  fresh expiry?** Reset the column to `NULL` when a row's `encryption_key`
  is updated (Behavior or `beforeSave` hook).

## 7. Architecture overview

```
cron
  │
  ▼
CheckExpiringKeysCommand
  │   1. SELECT individuals.* JOIN encryption_keys WHERE expires_at < now + max(thresholds)
  │   2. For each row, compute crossed_threshold
  │   3. Skip if already notified for this threshold
  │   4. Build ReminderMailer, call keyExpiry() or keyExpired()
  │   5. Optional: deliverWithGpg() if --encrypt
  │   6. On success, update last_reminder_threshold
  ▼
ReminderMailer  (from mailing-prd.md, unchanged)
```

## 8. Testing strategy

- Unit tests covering the threshold-crossing logic (table-driven: for each
  combination of (expiry, now, last_reminder_threshold, configured_thresholds),
  assert the right `crossed_threshold` or "skip").
- A `ConsoleIntegrationTestTrait`-based test that:
  - Seeds an `Individual` with an `encryption_keys` row whose parsed expiry
    is "in 6 days" (against the configured `30,7,1` cadence).
  - Runs `check_expiring_keys --dry-run` and asserts the recipient appears
    in stdout with `threshold=7`.
  - Runs without `--dry-run` and asserts the Debug transport captured one
    message with `Subject: Your GPG key expires on …` and that the row's
    `last_reminder_threshold` is now `7`.
  - Re-runs and asserts no second send and no DB update.
- Live SMTP / live GPG smoke tests are inherited from mailing-prd.md §14.

## 9. Acceptance criteria

- A new `check_expiring_keys` Cake console command exists, registered, and
  passes `./bin/cake check_expiring_keys --help`.
- Migration adds `encryption_keys.last_reminder_threshold` (nullable smallint).
- `--dry-run` prints the would-be send list and changes nothing in the DB.
- A live run sends exactly one mail per (individual, key, threshold) and
  advances `last_reminder_threshold`.
- A second live run with no new threshold crossings sends nothing.
- All new code passes `composer cs-check`. All new tests pass.

## 10. Out-of-scope (revisit later)

- Reminder digests (one email summarizing all expiring keys for an individual
  rather than one email per key).
- Per-individual unsubscribe via `List-Unsubscribe` header for the reminder
  flow.
- Web-UI for an operator to inspect pending reminders / nudge them manually.
