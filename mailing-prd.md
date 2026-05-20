# PRD: Lightweight Emailing for Cerebrate

Status: Draft
Owner: andras.iklody@gmail.com
Target branch: develop
Progress tracker: [`progress.md`](progress.md)

## How to use this PRD

This document is the contract for what gets built. The companion file
[`progress.md`](progress.md) is the contract for **how** it gets built — it
breaks the work into phases and tasks, each with a clear objective,
milestone, and verification step.

**When invoking an assistant against this PRD, the expected workflow is:**

1. Read this PRD end-to-end before writing any code.
2. Open [`progress.md`](progress.md) and find the first task whose status is
   `[ ]` (not started). The working-agreement block at the top of
   `progress.md` is binding.
3. Take **one task at a time**. Do not start a new task until the current
   one is fully done — code, tests, and a green build — and its checkbox is
   ticked in `progress.md`.
4. Verify every task with the concrete command(s) listed under its
   "Verification" line. `composer cs-check` and `composer stan` must pass
   before each commit; `composer test` must pass before a phase commit.
5. Update `progress.md` as you go: flip `[ ]` → `[~]` when starting,
   `[~]` → `[x]` when verified done, `[!]` if blocked (with a note on the
   line below). Append any deviation from this PRD to the
   "Notes & decisions log" at the bottom of `progress.md`.
6. Phase commits (1.6, 2.3, 3.6) are explicit checkpoints. Land each phase
   as a single reviewable commit on `develop`.

This workflow is designed to support either a human-in-the-middle review
cycle (one task per turn, user reviews) or an autonomous loop (`/loop` over
the next `[ ]` task) — the task descriptions in `progress.md` are
self-contained enough for either mode.

## 1. Background and motivation

Cerebrate currently has no outbound email capability. Only CakePHP's stock
`EmailTransport` / `Email` config blocks and the unmodified default templates
exist; there are no `Mailer` classes, no `->send()` / `->deliver()` call sites,
and no email-related migrations.

Reminder emails (in particular: warnings to individuals whose PGP keys are
about to expire or have expired) are on the roadmap. To deliver that, we need
a small, well-shaped emailing subsystem first. This PRD covers the emailing
foundation only. The PGP key-expiry sweep that consumes it is a follow-up.

Cerebrate already has the GPG building blocks needed to send encrypted mail:

- `pear/crypt_gpg` is in `composer.json`.
- `src/Lib/Tools/GpgTool.php` initializes GPG.
- `src/Lib/Tools/CryptGpgExtended.php` extends `Crypt_GPG`.
- `EncryptionKeysTable::initializeGpg()` and `verifySingleGPG()` exist.
- `encryption_keys` is polymorphic (`owner_model = individual|organisation`),
  so per-individual recipient keys are addressable.

The MISP7 codebase (`/var/www/MISP7/app/Lib/Tools/SendEmail.php` +
`SendEmailTemplate.php`) is the reference implementation. Cerebrate runs
CakePHP 4.x, where `Cake\Mailer\Mailer` replaces much of MISP7's hand-rolled
`CakeEmail` wrapping; only the GPG MIME-construction pieces port across.

## 2. Goals

- Provide a reusable, lightweight mailer subsystem for Cerebrate.
- Support GPG signing (server-side) and GPG encryption (per-recipient) using
  the existing `Crypt_GPG` plumbing.
- Render `text` and `html` bodies from CakePHP view templates with a shared
  layout.
- Honor a global "disable emailing" flag so tests and dev environments can run
  without an SMTP server.
- Produce stable `Message-ID`, `Date`, and `In-Reply-To` / `References`
  headers so reminder threads group correctly per recipient + key.
- Ship a manual `./bin/cake send_email` command for operators / testing.
- Lay the API surface that the future `CheckExpiringKeysCommand` will consume,
  without building that command in this round.

## 3. Non-goals (explicitly deferred)

- The PGP key-expiry sweep itself (separate PRD / follow-up).
- S/MIME signing or encryption. Cerebrate has no `certif_public` field on
  individuals today; adding it is net-new schema and out of scope here.
- Asynchronous queueing. Sends are synchronous; a queue worker can be layered
  later if volume warrants.
- A `sent_emails` audit table. Delivery results are written via the existing
  `AuditLogBehavior` and CakePHP's logger; a dedicated table is a v2 concern.
- A user-visible "send test email" UI. The CLI command is sufficient for now.
- Inbound mail / bounce handling.
- Unsubscribe management beyond emitting a `List-Unsubscribe` header when the
  caller supplies one.

## 4. User stories

- **As a Cerebrate operator**, I want to configure an SMTP transport and a
  `from` address so that the platform can send mail.
- **As a Cerebrate operator**, I want to configure a server GPG signing key
  so that outbound mail is verifiable.
- **As a Cerebrate operator**, I want to flip a single flag to disable all
  outbound mail in dev / test environments.
- **As a Cerebrate developer**, I want a `Mailer` API I can call from a model,
  controller, or command to send a templated mail to an `Individual`,
  optionally encrypted to that individual's GPG key.
- **As a Cerebrate developer**, I want to send a manual reminder from the CLI
  for testing without writing a one-off script.
- **As a recipient**, I want emails grouped into a single thread per topic
  (e.g. one thread per expiring key, not one thread per reminder cycle).
- **As a recipient with a published GPG key**, I want my reminders encrypted
  to that key.

## 5. Functional requirements

### 5.1 Mailer surface

- `App\Mailer\CerebrateMailer extends Cake\Mailer\Mailer`
  - Reads `Cerebrate.email.from`, `from_name`, `reply_to` from config and
    applies them to every message.
  - If `Cerebrate.email.disable` is true, the active transport is forced to
    `'Debug'` in the constructor.
  - Sets a stable `Message-ID` (`<uuid@host>`) and explicit `Date` header so
    they survive GPG signing.
  - Helper `withReference(string $referenceId): self` sets
    `In-Reply-To` and `References` to a deterministic value derived from
    `sha1($referenceId . '|' . Configure::read('Cerebrate.uuid'))`.
- `App\Mailer\ReminderMailer extends CerebrateMailer`
  - `keyExpiry(Individual $individual, EncryptionKey $key, DateTimeInterface $expiresAt): void`
  - `keyExpired(Individual $individual, EncryptionKey $key, DateTimeInterface $expiredAt): void`
  - Each method sets `to`, `subject`, view vars, template name, and reference
    id. No GPG logic in here.

### 5.2 GPG layer

- `App\Lib\Tools\GpgMailer` provides:
  - `deliverWithGpg(CerebrateMailer $mailer, ?EncryptionKey $recipientKey): array`
- Behavior:
  1. Render html + text bodies before any GPG operation, using
     `App\Lib\Tools\EmailRenderer`.
  2. If `Cerebrate.email.gpg_sign` is true: import server signing key from
     config, sign with detached signature, wrap as `multipart/signed`
     (`protocol="application/pgp-signature"`, `micalg=pgp-<hash>`), include
     `protected-headers="v1"` on the signed inner part, and copy
     `From`/`To`/`Subject`/`Date`/`Message-ID`/`Reply-To`/`In-Reply-To`/
     `References` into the protected headers per
     draft-autocrypt-lamps-protected-headers-02.
  3. If `$recipientKey` is supplied and validates (reusing the same checks as
     `EncryptionKeysTable::verifySingleGPG()`): import the recipient key,
     encrypt, wrap as `multipart/encrypted`
     (`protocol="application/pgp-encrypted"`) per RFC 3156.
  4. If `Cerebrate.email.only_encrypted` is true and encryption did not
     happen, throw `SendEmailException` and do not send plaintext.
     Default: `false` (so reminders still reach users with no key).
  5. If `Cerebrate.email.gpg_obscure_subject` is true and the message is both
     signed and encrypted, replace the outer subject with `...`.
  6. Return
     `['encrypted' => bool, 'signed' => bool, 'message_id' => string, 'subject' => string, 'to' => string]`
     for the caller to log.
- MIME helpers (`MimeMultipart`, `MessagePart`) port across from MISP7's
  `SendEmail.php` essentially verbatim and live as private classes inside
  `GpgMailer` (or under `src/Lib/Tools/Mime/`).

### 5.3 Template rendering

- `App\Lib\Tools\EmailRenderer` renders both `templates/email/html/<name>.php`
  and `templates/email/text/<name>.php` via `Cake\View\View`, applying the
  default layout from `templates/layout/email/{html,text}/default.php`.
- Subject can be overridden from inside the template via
  `$this->set('subject', ...)` and is read back by the renderer.
- Returns `['html' => ?string, 'text' => string]`. HTML is optional; text is
  required.

### 5.4 Templates shipped in this PRD

- `templates/layout/email/html/default.php` — minimal Cerebrate-branded shell.
- `templates/layout/email/text/default.php` — minimal text shell.
- `templates/email/html/reminder_key_expiry.php`
- `templates/email/text/reminder_key_expiry.php`
- `templates/email/html/reminder_key_expired.php`
- `templates/email/text/reminder_key_expired.php`

The two existing stub files (`templates/email/{html,text}/default.php`) are
left in place — they are CakePHP's stock per-message default and are not
layouts.

### 5.5 CLI

- `App\Command\SendEmailCommand` invoked as
  `./bin/cake send_email --to=<email> --template=<name> [--encrypt]
  [--var key=value ...]`.
- Resolves the recipient via `IndividualsTable` if `--to` matches a known
  individual, otherwise treats `--to` as a raw address (no GPG encryption
  possible in that case).
- Logs the result via `AuditLogBehavior`.
- The cron-driven sweep (`CheckExpiringKeysCommand`) is **not** built here.

### 5.6 Configuration

Settings are declared in `CerebrateSettingsProvider` under
`Application > Network > Email`. See PRD §10 for the full key reference.

Decision: server signing key is loaded from disk via `Crypt_GPG`, the same
way MISP does it (operator imports it into the configured `homedir` and
references it by email/fingerprint via `Cerebrate.email.gpg_signing_key`).
It is **not** stored in `encryption_keys`.

`config/app_local.example.php` already has the `EmailTransport.default` and
`Email.default` blocks; documentation will direct operators to fill in SMTP
credentials there. No change required to `app_local.example.php` beyond a
comment pointing operators at the in-app Settings UI for
`Cerebrate.email.*`.

## 6. Non-functional requirements

- **Reliability.** A failed send must not crash the calling controller / CLI
  command. Failures are caught, logged via `AuditLogBehavior` + `Log::error`,
  and surfaced to the caller as `SendEmailException`.
- **Security.**
  - No bare-string interpolation of user-supplied content into headers.
  - Recipient GPG key is validated (not expired, has an encryption-capable
    subkey) before encryption is attempted.
  - When `only_encrypted` is true, plaintext fallback is forbidden.
  - Server signing-key passphrase is read from config, never logged.
  - GPG `homedir` is the same one already used by `EncryptionKeysTable`.
- **Compliance with standards.**
  - RFC 3156 for `multipart/encrypted` and `multipart/signed` layouts.
  - RFC 4880 hash naming for `micalg`.
  - draft-autocrypt-lamps-protected-headers-02 for the signed inner part.
  - RFC 5322 line-length compliance for any header we emit.
- **Performance.** Sends are synchronous; per-message latency is dominated by
  SMTP round-trip + GPG encryption (~tens of ms). Acceptable for the expected
  reminder volume (hundreds per day at most).
- **Coding standard.** PSR-12 / CakePHP via `composer cs-check`, passes
  `composer stan`.

## 7. Architecture overview

```
caller (model / controller / command)
  │
  ▼
ReminderMailer (extends CerebrateMailer extends Cake\Mailer\Mailer)
  │   builds Mailer state: to, subject, view vars, template, reference id
  ▼
GpgMailer::deliverWithGpg($mailer, ?$recipientKey)
  │   1. EmailRenderer renders html+text
  │   2. optional GPG sign (multipart/signed, protected headers)
  │   3. optional GPG encrypt to recipient (multipart/encrypted)
  │   4. enforces only_encrypted / obscure_subject
  │   5. attaches body + Content-Type to Mailer
  │   6. $mailer->deliver()
  ▼
Cake\Mailer transport (smtp / mail / debug)
```

## 8. Detailed file plan

```
src/Mailer/
    CerebrateMailer.php
    ReminderMailer.php

src/Lib/Tools/
    GpgMailer.php
    EmailRenderer.php
    SendEmailException.php

src/Command/
    SendEmailCommand.php

src/Model/Table/SettingProviders/
    CerebrateSettingsProvider.php              # add Application > Network > Email block

templates/email/
    html/reminder_key_expiry.php
    html/reminder_key_expired.php
    text/reminder_key_expiry.php
    text/reminder_key_expired.php

templates/layout/email/
    html/default.php
    text/default.php

tests/TestCase/Mailer/
    CerebrateMailerTest.php
tests/TestCase/Lib/Tools/
    GpgMailerTest.php
    EmailRendererTest.php
tests/TestCase/Command/
    SendEmailCommandTest.php
tests/Helper/gpg/
    keyring/                                   # fixture homedir for Crypt_GPG
    fixture-public.asc
    fixture-secret.asc
```

No DB migration. No new permission, role, or settings-page wiring in this
round.

## 9. API contracts

### 9.1 `CerebrateMailer`

```php
namespace App\Mailer;

class CerebrateMailer extends \Cake\Mailer\Mailer
{
    public function __construct(?string $config = null)
    public function withReference(string $referenceId): self
}
```

### 9.2 `ReminderMailer`

```php
namespace App\Mailer;

use App\Model\Entity\EncryptionKey;
use App\Model\Entity\Individual;

class ReminderMailer extends CerebrateMailer
{
    public function keyExpiry(Individual $individual, EncryptionKey $key, \DateTimeInterface $expiresAt): void
    public function keyExpired(Individual $individual, EncryptionKey $key, \DateTimeInterface $expiredAt): void
}
```

### 9.3 `GpgMailer`

```php
namespace App\Lib\Tools;

use App\Mailer\CerebrateMailer;
use App\Model\Entity\EncryptionKey;

class GpgMailer
{
    public function __construct(?\Crypt_GPG $gpg)
    public function deliverWithGpg(CerebrateMailer $mailer, ?EncryptionKey $recipientKey): array
}
```

Returned array shape:

```php
[
    'to'         => string,
    'subject'    => string,
    'message_id' => string,
    'signed'     => bool,
    'encrypted'  => bool,
]
```

### 9.4 `SendEmailException`

A simple `extends \RuntimeException` in `src/Lib/Tools/`.

## 10. Configuration reference

Settings are declared in `src/Model/Table/SettingProviders/CerebrateSettingsProvider.php`
under `Application > Network > Email`. This is the canonical home for
Cerebrate-managed settings: the in-app Settings UI is generated from the
provider, operator values are persisted to `config/config.json` via
`SettingsTable::saveSettingOnDisk()`, and `bootstrap.php` writes them into
`Configure` at startup. The provider's `default` field is metadata for the
UI fallback display only — it is **not** auto-written into `Configure`, so
runtime code must tolerate `null` for unset keys.

| Key                                       | Type   | UI default | Purpose                                                                 |
| ----------------------------------------- | ------ | ---------- | ----------------------------------------------------------------------- |
| `Cerebrate.email.from`                    | string | `''`       | Envelope and header `From` address. **Empty == outbound email disabled** (mailer raises `SendEmailException`). |
| `Cerebrate.email.from_name`               | string | `Cerebrate`| Optional display name used alongside the From address.                  |
| `Cerebrate.email.reply_to`                | string | `''`       | Optional `Reply-To` address; empty means omit the header.               |
| `Cerebrate.email.disable`                 | bool   | `false`    | When true, route all mail through the `Debug` transport.                |
| `Cerebrate.email.gpg_sign`                | bool   | `false`    | Sign all outbound mail with the configured server key.                  |
| `Cerebrate.email.gpg_signing_key`         | string | `''`       | Email or fingerprint identifying the signing key in the GPG `homedir`.  |
| `Cerebrate.email.gpg_signing_passphrase`  | string | `''`       | Passphrase for the signing key.                                         |
| `Cerebrate.email.gpg_obscure_subject`     | bool   | `false`    | Replace outer subject with `...` when signed+encrypted.                 |
| `Cerebrate.email.only_encrypted`          | bool   | `false`    | Refuse to send if encryption was not possible.                          |

The mailer reads these via `Configure::read('Cerebrate.email.<name>')` and
treats `null` / `''` the same as the documented default. There is **no
fake fallback `from` address** — if the operator has not set
`Cerebrate.email.from`, sends fail loudly rather than emitting mail from
a placeholder.

SMTP transport itself (host / port / credentials) is configured in
`config/app_local.php` under `EmailTransport.default`, per CakePHP
convention. That stays out of the Settings UI because it is a deployment
concern, not a runtime knob.

## 11. Testing strategy

- **`CerebrateMailerTest`** — uses Cake's `Debug` transport. Asserts that
  `from`, `reply_to`, `Message-ID`, and `Date` are set; that `withReference()`
  produces deterministic, sha1-derived `In-Reply-To` / `References`
  values; that `Cerebrate.email.disable` forces `Debug`.
- **`EmailRendererTest`** — renders each shipped template against fixture
  view vars; asserts both `html` and `text` come back; asserts that a
  template-set `subject` is captured.
- **`GpgMailerTest`** — drives `Crypt_GPG` against a fixture homedir under
  `tests/Helper/gpg/keyring/`. Cases:
  - sign-only produces `multipart/signed` with the right `protocol` and
    `micalg`;
  - encrypt-only produces `multipart/encrypted` and the inner part decrypts
    back to the rendered body;
  - sign+encrypt produces the nested layout and decrypts/verifies;
  - `only_encrypted=true` + missing recipient key throws `SendEmailException`;
  - `gpg_obscure_subject=true` replaces the outer subject.
- **`SendEmailCommandTest`** — `ConsoleIntegrationTestTrait` exercises the
  CLI in `Debug` mode; asserts a send happens for a known individual and
  for a raw address.

All tests live in the existing `app` PHPUnit suite; no WireMock involvement.

## 12. Phasing

The work is split into four phases. The full task-level breakdown — with
per-task objectives, milestones, and verification commands — lives in
[`progress.md`](progress.md), which is the source of truth for execution
order and status. The phases below are a high-level summary only.

- **Phase 0 — Foundations.** Config block, `SendEmailException`, default
  email layouts. (`progress.md` tasks 0.1–0.3.)
- **Phase 1 — Plaintext mailer.** `CerebrateMailer`, `EmailRenderer`,
  reminder templates, `ReminderMailer`, tests. End-to-end via the `Debug`
  transport, no GPG. Lands as a single commit. (Tasks 1.1–1.6.)
- **Phase 2 — CLI.** `SendEmailCommand` (plaintext) plus tests. Lands as a
  single commit. (Tasks 2.1–2.3.)
- **Phase 3 — GPG layer.** MIME helpers, GPG fixture keypair, `GpgMailer`,
  GPG tests, `--encrypt` wired into the CLI. Lands as a single commit.
  (Tasks 3.1–3.6.)
- **Phase 4 — Acceptance & handoff.** Live SMTP and GPG smoke tests, PRD
  acceptance cross-check, follow-up reminder-sweep PRD opened. (Tasks
  4.1–4.4.)

This split keeps each diff reviewable, verifies SMTP wiring before
introducing GPG complexity, and gives clean checkpoints for either
human-in-the-middle review or an autonomous loop.

## 13. Open questions / future work

- **Storage for delivery history.** Reuse `audit_logs` for now. Revisit if
  product wants a queryable "what reminders has user X received" surface.
- **Inline GPG vs PGP/MIME for clients with poor MIME support.** Out of
  scope; PGP/MIME only.
- **Autocrypt headers.** MISP7 emits them; not part of this PRD. Add later
  if there's demand.
- **Per-individual opt-out.** Not in scope. The `List-Unsubscribe` header is
  emitted only when a caller explicitly supplies a URL; building an
  unsubscribe registry is a separate feature.
- **The reminder sweep itself.** Out of scope here. Will be a separate PRD
  covering `CheckExpiringKeysCommand`, the reminder-cadence rules
  ("warn N days before; warn again after expiry; throttle to once per
  cadence window"), and operator config for thresholds.

## 14. Acceptance criteria

- `composer cs-check`, `composer stan`, and `composer test` all pass.
- `./bin/cake send_email --to=<known-individual> --template=reminder_key_expiry`
  delivers a rendered email through the configured transport.
- With `Cerebrate.email.gpg_sign=true` and a configured server key, outbound
  mail is `multipart/signed` and verifies against the server's public key.
- With `--encrypt` and a recipient that has a valid `encryption_keys` row,
  outbound mail is `multipart/encrypted` and decrypts back to the rendered
  body using the recipient's secret key.
- With `Cerebrate.email.only_encrypted=true` and a recipient with no usable
  key, the send raises `SendEmailException` and no mail is delivered.
- With `Cerebrate.email.disable=true`, no SMTP traffic occurs regardless of
  call-site configuration.
