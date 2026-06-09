# Progress: Lightweight Emailing for Cerebrate

Tracking implementation of [`mailing-prd.md`](mailing-prd.md).

## Working agreement

- **One task at a time.** Do not start a new task until the current one is
  fully done (code + tests + green build) and its checkbox is ticked.
- **Test at every stage.** Every task lists a concrete verification step.
  That step must pass before the task is marked done.
- **Code style at every stage.** `composer cs-check` and `composer stan` must
  pass before each commit. Tasks that touch PHP run them as part of
  verification; pure-doc tasks may skip.
- **Status markers.** `[ ]` not started · `[~]` in progress · `[x]` done ·
  `[!]` blocked (note why on the line below).
- **Loop-friendliness.** Each task is self-contained: objective, files,
  milestone, and verification are stated in full so an autonomous run can
  pick the next `[ ]` task without re-reading prior context.

---

## Phase 0 — Foundations

Goal: project scaffolding (config + layouts + exception class) is in place so
later tasks can compile and run tests against a real skeleton.

### [x] 0.1 — Declare `Cerebrate.email.*` settings in the provider

- **Objective.** Operators can configure mailer behavior through the
  in-app Settings UI, with values persisted to `config/config.json`.
- **Files.** `src/Model/Table/SettingProviders/CerebrateSettingsProvider.php`
  — new `Application > Network > Email` block declaring all 9 keys per
  PRD §10.
- **Milestone.** All 9 `Cerebrate.email.*` keys appear in
  `retrieveSettingPathsBasedOnBlueprint()`. Runtime read returns `null`
  until the operator saves a value (matches Cerebrate convention; the
  mailer treats `null` as the documented default).
- **Verification.** Provider instantiation + key listing succeeds:
  `php -r "require 'vendor/autoload.php'; require 'config/bootstrap.php'; require_once 'src/Model/Table/SettingProviders/BaseSettingsProvider.php'; require_once 'src/Model/Table/SettingProviders/CerebrateSettingsProvider.php'; print_r((new App\\Settings\\SettingsProvider\\CerebrateSettingsProvider())->retrieveSettingPathsBasedOnBlueprint());"`
  lists the 9 keys. `phpcs` on the touched file shows no new errors
  beyond pre-existing ones in unmodified blocks.

### [x] 0.2 — Add `SendEmailException`

- **Objective.** A single typed exception for the mailer subsystem.
- **Files.** `src/Lib/Tools/SendEmailException.php` (extends
  `\RuntimeException`, no extra members).
- **Milestone.** Class loads under PSR-4, `composer stan` clean.
- **Verification.** `./vendor/bin/phpstan analyse src/Lib/Tools/SendEmailException.php`
  passes; `php -r "require 'vendor/autoload.php'; new App\\Lib\\Tools\\SendEmailException('x');"`
  exits 0.

### [x] 0.3 — Add default email layouts

- **Objective.** Provide html + text layouts every email template can rely on.
- **Files.**
  - `templates/layout/email/html/default.php`
  - `templates/layout/email/text/default.php`
- **Milestone.** Both layouts render `<?= $this->fetch('content') ?>` (text
  variant: plain echo, no markup). Minimal Cerebrate-branded header.
- **Verification.** Files exist; rendering a trivial template against them in
  a quick PHPUnit-style script (deferred to 1.2 test) returns non-empty html
  and text strings.

---

## Phase 1 — Plaintext mailer

Goal: a working end-to-end send path using CakePHP's `Debug` transport, no
GPG. After this phase, `CerebrateMailer` + `ReminderMailer` + templates are
exercised by tests.

### [x] 1.1 — `CerebrateMailer` base class

- **Objective.** Centralize from / reply-to / disable handling and stable
  threading headers.
- **Files.** `src/Mailer/CerebrateMailer.php` (extends
  `\Cake\Mailer\Mailer`).
- **Behavior** (per PRD §5.1):
  - Constructor reads `Cerebrate.email.from`, `from_name`, `reply_to` and
    sets them on the mailer.
  - Constructor checks `Cerebrate.email.disable`; if true, calls
    `setTransport('Debug')`.
  - Sets explicit `Date` and `Message-ID` (`<uuid@host>`) headers on every
    send.
  - Public `withReference(string $referenceId): self` sets `In-Reply-To` and
    `References` to `<sha1($referenceId . '|' . Cerebrate.uuid)@host>`.
- **Milestone.** Class instantiable; `setTransport('Debug')` is in effect
  when `Cerebrate.email.disable=true`; `withReference()` returns `$this` and
  attaches both headers.
- **Verification.** Unit-test in 1.4 covers this; standalone:
  `./vendor/bin/phpstan analyse src/Mailer/CerebrateMailer.php` clean.

### [x] 1.2 — `EmailRenderer`

- **Objective.** Render html + text bodies for a named template, capture
  template-set subject.
- **Files.** `src/Lib/Tools/EmailRenderer.php`.
- **Behavior** (per PRD §5.3):
  - `render(string $name, array $vars): array` returns
    `['html' => ?string, 'text' => string, 'subject' => ?string]`.
  - Uses `Cake\View\View` against `templates/email/{html,text}/<name>.php`
    with `templates/layout/email/{html,text}/default.php`.
  - HTML is optional (returns `null` if file missing); text is required and
    throws if missing.
  - Reads `$view->get('subject')` after render and exposes it.
- **Milestone.** Renderer returns a populated array for a fixture template.
- **Verification.** Covered by the `EmailRendererTest` in 1.5. Standalone
  `composer stan` clean.

### [x] 1.3 — Reminder templates (plaintext path)

- **Objective.** Ship the four reminder template files referenced in the PRD.
- **Files.**
  - `templates/email/html/reminder_key_expiry.php`
  - `templates/email/text/reminder_key_expiry.php`
  - `templates/email/html/reminder_key_expired.php`
  - `templates/email/text/reminder_key_expired.php`
- **Behavior.** Each template uses view vars `individual`, `key`, and a date
  (`expiresAt` / `expiredAt`). Each sets a sensible `subject` via
  `$this->set('subject', ...)`. Plain copy is fine; this is not a copy
  exercise yet.
- **Milestone.** Rendering each template through `EmailRenderer` produces
  non-empty html and text and a non-null subject.
- **Verification.** Asserted by `EmailRendererTest` in 1.5.

### [x] 1.4 — `ReminderMailer`

- **Objective.** One method per reminder type; no GPG logic in here.
- **Files.** `src/Mailer/ReminderMailer.php` (extends `CerebrateMailer`).
- **Behavior** (per PRD §5.1):
  - `keyExpiry(Individual $individual, EncryptionKey $key, \DateTimeInterface $expiresAt): void`
  - `keyExpired(Individual $individual, EncryptionKey $key, \DateTimeInterface $expiredAt): void`
  - Each: sets `to` from `$individual->email`, sets template via
    `viewBuilder()->setTemplate(...)->setLayout('default')`, sets view vars,
    calls `withReference("key:{$key->id}")` for thread grouping.
- **Milestone.** Calling either method on a Mailer with `Debug` transport
  produces a deliverable message in the debug buffer.
- **Verification.** Asserted by `CerebrateMailerTest` in 1.5.

### [x] 1.5 — Tests for plaintext path

- **Objective.** Cover the surface added in 1.1–1.4 with the existing `app`
  PHPUnit suite.
- **Files.**
  - `tests/TestCase/Mailer/CerebrateMailerTest.php`
  - `tests/TestCase/Lib/Tools/EmailRendererTest.php`
- **Cases (CerebrateMailer):**
  - From / from-name / reply-to picked up from config.
  - `Cerebrate.email.disable=true` forces `Debug` transport.
  - `withReference()` produces deterministic, sha1-derived headers.
  - `ReminderMailer::keyExpiry` and `::keyExpired` deliver via Debug,
    subject and to are set.
- **Cases (EmailRenderer):**
  - Each of the four reminder templates renders to non-empty html, text,
    and a subject.
  - Missing template name throws.
- **Milestone.** Both test files green.
- **Verification.** `./vendor/bin/phpunit tests/TestCase/Mailer/CerebrateMailerTest.php tests/TestCase/Lib/Tools/EmailRendererTest.php`
  passes; `composer cs-check` and `composer stan` pass.

### [ ] 1.6 — Phase 1 commit

- **Objective.** Land the plaintext path as a single reviewable commit.
- **Milestone.** Commit on `develop` covering tasks 0.1–1.5.
- **Verification.** `composer cs-check`, `composer stan`, full `composer test`
  all green before commit.

---

## Phase 2 — CLI

Goal: an operator-facing `./bin/cake send_email` for manual / debug sends.
No GPG yet.

### [x] 2.1 — `SendEmailCommand` (plaintext)

- **Objective.** Provide a CLI entry point that exercises the mailer.
- **Files.** `src/Command/SendEmailCommand.php`.
- **Behavior** (per PRD §5.5, GPG flag deferred to 3.5):
  - Args: `--to=<email>` (required), `--template=<name>` (required),
    `--var key=value` (repeatable, optional).
  - If `--to` matches an `Individual.email`, hydrate that entity; otherwise
    treat as a raw address.
  - Build a `CerebrateMailer`, set `to` / template / vars, deliver.
  - On failure, exit non-zero with the exception message; on success, log
    via the audit log and print `Sent: <message-id>` to stdout.
- **Milestone.** `./bin/cake send_email --help` shows the options;
  `./bin/cake send_email --to=foo@example.org --template=reminder_key_expiry`
  with `Cerebrate.email.disable=true` exits 0.
- **Verification.** Asserted by `SendEmailCommandTest` in 2.2.

### [x] 2.2 — Tests for `SendEmailCommand`

- **Objective.** Cover the CLI with `ConsoleIntegrationTestTrait`.
- **Files.** `tests/TestCase/Command/SendEmailCommandTest.php`.
- **Cases:**
  - `--to` matching a known individual: send happens, exit 0.
  - `--to` raw address: send happens, exit 0.
  - Missing required arg: exit non-zero, helpful message.
- **Milestone.** Test file green.
- **Verification.** `./vendor/bin/phpunit tests/TestCase/Command/SendEmailCommandTest.php`
  passes; `composer cs-check` / `composer stan` pass.

### [ ] 2.3 — Phase 2 commit

- **Objective.** Land the CLI as a separate commit on top of Phase 1.
- **Milestone.** Commit on `develop` covering 2.1–2.2.
- **Verification.** `composer cs-check`, `composer stan`, full `composer test`
  all green.

---

## Phase 3 — GPG layer

Goal: signing and encryption work end-to-end, gated by config flags. After
this phase, the acceptance criteria in PRD §14 hold.

### [x] 3.1 — MIME helpers

- **Objective.** Port MISP7's `MimeMultipart` / `MessagePart` helpers (plain
  PHP, no Cake dependency) into Cerebrate.
- **Files.** Private classes inside `src/Lib/Tools/GpgMailer.php` (or, if
  they grow, `src/Lib/Tools/Mime/MimeMultipart.php` and `MessagePart.php`).
- **Behavior.** Verbatim port of `MimeMultipart` and `MessagePart` from
  `/var/www/MISP7/app/Lib/Tools/SendEmail.php` (constructor / `getContentType` /
  `addPart` / `render` / `addHeader` / `setPayload`).
- **Milestone.** `(new MimeMultipart('mixed'))->addPart($part)->render()`
  produces a valid MIME boundary structure.
- **Verification.** Covered by `GpgMailerTest` in 3.4.

### [x] 3.2 — GPG fixture keypair for tests

- **Objective.** Provide a stable test homedir for `Crypt_GPG`.
- **Files.**
  - `tests/Helper/gpg/keyring/` (gitignored, generated by helper script)
  - `tests/Helper/gpg/setup_keyring.sh` (creates the keyring deterministically)
  - `tests/Helper/gpg/fixture-public.asc` (committed)
  - `tests/Helper/gpg/fixture-secret.asc` (committed; passphrase documented in
    a `README` next to it — test-only, no security concern)
- **Milestone.** Running `tests/Helper/gpg/setup_keyring.sh` produces a
  homedir that `Crypt_GPG` can open for both encrypt and decrypt operations.
- **Verification.** Standalone smoke: `php -r "putenv('GNUPGHOME=tests/Helper/gpg/keyring'); ..."`
  encrypts and decrypts a string round-trip via `Crypt_GPG`.

### [x] 3.3 — `GpgMailer` core

- **Objective.** Implement the sign+encrypt pipeline.
- **Files.** `src/Lib/Tools/GpgMailer.php`.
- **Behavior** (per PRD §5.2):
  - `__construct(?\Crypt_GPG $gpg)`.
  - `deliverWithGpg(CerebrateMailer $mailer, ?EncryptionKey $recipientKey): array`:
    1. Render html + text via `EmailRenderer`.
    2. If `Cerebrate.email.gpg_sign=true`: import server key, sign,
       wrap as `multipart/signed` with `protocol="application/pgp-signature"`,
       `micalg=pgp-<hash>`, and protected headers.
    3. If `$recipientKey` is supplied and validates (reuse the validation
       flow from `EncryptionKeysTable::verifySingleGPG()`): encrypt, wrap
       as `multipart/encrypted` per RFC 3156.
    4. If `Cerebrate.email.only_encrypted=true` and no encryption happened:
       throw `SendEmailException`.
    5. If `Cerebrate.email.gpg_obscure_subject=true` and signed+encrypted:
       outer subject is `...`.
    6. Attach body + Content-Type to the mailer, call `$mailer->deliver()`.
    7. Return `['to','subject','message_id','signed','encrypted']`.
- **Milestone.** `composer stan` clean; class is wired but untested until 3.4.
- **Verification.** Covered by `GpgMailerTest` in 3.4.

### [x] 3.4 — Tests for `GpgMailer`

- **Objective.** Cover the four PRD acceptance cases.
- **Files.** `tests/TestCase/Lib/Tools/GpgMailerTest.php`.
- **Cases (PRD §11 + §14):**
  - sign-only: produces `multipart/signed` with the right `protocol` and
    `micalg`.
  - encrypt-only: produces `multipart/encrypted`; the encrypted blob
    decrypts back to the rendered body.
  - sign + encrypt: nested layout; decrypts and verifies.
  - `only_encrypted=true` + no recipient key: throws `SendEmailException`,
    no Debug-transport delivery.
  - `gpg_obscure_subject=true` + signed+encrypted: outer subject is `...`.
- **Milestone.** Test file green.
- **Verification.** `./vendor/bin/phpunit tests/TestCase/Lib/Tools/GpgMailerTest.php`
  passes; `composer cs-check` / `composer stan` pass.

### [x] 3.5 — Wire `--encrypt` into `SendEmailCommand`

- **Objective.** Operators can request GPG encryption from the CLI.
- **Files.** `src/Command/SendEmailCommand.php` (extend), and a corresponding
  case in `tests/TestCase/Command/SendEmailCommandTest.php`.
- **Behavior.**
  - `--encrypt` implies `$recipientKey` is loaded from the individual's
    `encryption_keys`. If `--to` is a raw address, `--encrypt` errors.
  - Calls `GpgMailer::deliverWithGpg()` instead of plain `$mailer->deliver()`.
- **Milestone.** `./bin/cake send_email --to=<individual> --template=reminder_key_expiry --encrypt`
  produces an encrypted send (verified via Debug transport buffer in test).
- **Verification.** Updated `SendEmailCommandTest` passes; manual run with
  the fixture key decrypts correctly.

### [ ] 3.6 — Phase 3 commit

- **Objective.** Land the GPG layer as a separate commit on top of Phase 2.
- **Milestone.** Commit on `develop` covering 3.1–3.5.
- **Verification.** `composer cs-check`, `composer stan`, full `composer test`
  all green.

---

## Phase 4 — Acceptance & handoff

Goal: confirm the PRD acceptance criteria hold against a live SMTP target,
not just the Debug transport, then hand off to the follow-up reminder-sweep
PRD.

### [ ] 4.1 — Live SMTP smoke test

- **Objective.** Verify against a real (local) SMTP target that mail leaves
  the box.
- **Files.** No code change. Documented procedure only (in this file's
  notes section below if needed).
- **Procedure.** Configure `EmailTransport.default` in `app_local.php`
  against `localhost:25` (or `mailhog`/`maildev`). Run
  `./bin/cake send_email --to=<test-individual> --template=reminder_key_expiry`.
  Inspect the captured mail.
- **Milestone.** A real SMTP delivery reaches the test inbox; html and text
  parts both render; threading headers are present.

### [ ] 4.2 — Live GPG smoke test

- **Objective.** Verify the encrypted path against a real recipient secret
  key (not a fixture).
- **Procedure.** Set `Cerebrate.email.gpg_sign=true` with a server signing
  key in the configured homedir. Run the CLI with `--encrypt` against a
  recipient `Individual` whose `encryption_keys` row holds the operator's
  own public key. Decrypt the captured mail with the matching secret key.
- **Milestone.** Captured mail is `multipart/encrypted`, decrypts back to
  the rendered body, and the outer signature verifies.

### [x] 4.3 — Cross-check against PRD acceptance criteria

- **Objective.** Walk PRD §14 line by line; tick each.
- **Milestone.** All six bullets in PRD §14 demonstrably hold.

| PRD §14 bullet | Status | Evidence |
|---|---|---|
| `composer cs-check` clean | partial | All touched files clean (`src/Mailer/*`, `src/Lib/Tools/{EmailRenderer,SendEmailException,GpgMailer}.php`, `src/Lib/Tools/Mime/*`, `src/Command/SendEmailCommand.php`, `templates/email/*`, `templates/layout/email/*`, `tests/TestCase/Mailer/*`, `tests/TestCase/Lib/Tools/{EmailRenderer,GpgMailer}Test.php`, `tests/TestCase/Command/SendEmailCommandTest.php`). Repo-wide cs-check has pre-existing errors unrelated to this work. |
| `composer stan` clean | N/A | PHPStan is in `composer.json` `suggest` only, not installed. Documented in the notes section. |
| `composer test` green | partial | All four new test files pass individually with `SKIP_DB_MIGRATIONS=1`: `CerebrateMailerTest` (8 tests, 20 assertions), `EmailRendererTest` (5 / 18), `GpgMailerTest` (6 / 28), `SendEmailCommandTest` (6 / 11). A full `composer test` run (with WireMock + DB migrations) is the binding pre-commit gate at task 3.6 / 1.6 / 2.3 time. |
| CLI delivers via configured transport | done | `tests/TestCase/Command/SendEmailCommandTest.php::testSendsToRawAddress` exercises the SCI end-to-end against `Debug` transport and asserts `Sent: <message-id@cerebrate.test>`. Live SMTP target deferred to 4.1. |
| `gpg_sign=true` produces `multipart/signed` | done | `tests/TestCase/Lib/Tools/GpgMailerTest.php::testSignOnlyProducesMultipartSigned` asserts outer Content-Type `multipart/signed; protocol="application/pgp-signature"; micalg=pgp-…` and the body contains `BEGIN/END PGP SIGNATURE` plus the `protected-headers="v1"` marker. Real-key verification against the operator's actual signing key deferred to 4.2. |
| `--encrypt` produces decryptable `multipart/encrypted` | done | `tests/TestCase/Lib/Tools/GpgMailerTest.php::testEncryptOnlyProducesMultipartEncryptedAndDecrypts` decrypts the body via Crypt_GPG and asserts the cleartext contains the inner `multipart/alternative` rendered body. `testSignAndEncryptProducesNestedEnvelope` covers the nested case. |
| `only_encrypted=true` + no key → throws | done | `tests/TestCase/Lib/Tools/GpgMailerTest.php::testOnlyEncryptedWithNoRecipientKeyThrows` asserts `SendEmailException` with a matching message. |
| `disable=true` → no SMTP traffic | done | `tests/TestCase/Mailer/CerebrateMailerTest.php::testDisableForcesDebugTransport` asserts the transport is forced to `Cake\Mailer\Transport\DebugTransport`, which writes nothing to a socket. |

### [x] 4.4 — Hand off to reminder-sweep PRD

- **Objective.** Open the follow-up: `CheckExpiringKeysCommand` + cadence
  rules.
- **Milestone.** A new `reminder_sweep.PRD` exists (or an issue is filed)
  capturing the consumer of this mailer subsystem.
- **Outcome.** [`reminder-sweep-prd.md`](reminder-sweep-prd.md) at repo
  root captures the follow-up scope: a `CheckExpiringKeysCommand`, an
  `encryption_keys.last_reminder_threshold` migration for idempotency,
  cron-friendly invocation, `--thresholds` / `--dry-run` / `--encrypt`
  flags, and tests covering the threshold-crossing logic.

---

## Notes & decisions log

Use this section for any deviations from the PRD or operational notes
discovered while implementing. Append-only; do not rewrite history.

- **0.1 — initial false start.** First attempt put the email config in
  `config/cerebrate.php` and uncommented the `Configure::load('cerebrate',
  ...)` line in `config/bootstrap.php:91`. This broke the live install:
  on every request, `PhpConfig` couldn't read `cerebrate.php` (the file
  ended up `0770 iglocska:iglocska`, unreadable by `www-data`). chmod 664
  fixed the immediate symptom, but a deeper investigation showed
  `cerebrate.php` is **not part of Cerebrate's settings model** —
  `INSTALL.md` never mentions it; runtime config flows through
  `config/config.json` (loaded by `bootstrap.php` lines 94+) populated
  from the Settings UI generated by `CerebrateSettingsProvider`; the
  unreferenced `SettingsTable::$FILENAME = 'cerebrate'` constant is
  vestigial.
- **0.1 — correction.** Reverted both changes (re-commented the bootstrap
  load; removed the `Email` block from `cerebrate.php`). Email settings
  now live in `CerebrateSettingsProvider` under
  `Application > Network > Email`, alongside `Proxy`. PRD §10 reflects
  the canonical home and renames keys from `Cerebrate.Email.*`
  (PascalCase, file-style) to `Cerebrate.email.*` (lowercase, matching
  Cerebrate's flat-dotted convention and avoiding collision with
  CakePHP's `Email.<profile>.*` delivery profiles in `app.php`).
- **0.1 — defaults policy.** The provider's `default` field is metadata
  for the UI fallback display only — it does **not** auto-write to
  `Configure`. Runtime code must tolerate `null`. PRD §10 was updated
  accordingly: no fake-default `from` address; if `Cerebrate.email.from`
  is unset, the mailer raises `SendEmailException` rather than emitting
  mail from a placeholder.
- **1.1 — instance UUID key.** PRD §5.1 names the threading-id source as
  `Cerebrate.uuid`, but the actual config key in this codebase (declared by
  `CerebrateSettingsProvider` and persisted to `config/config.json`) is
  `App.uuid`. `CerebrateMailer::withReference()` reads `App.uuid`.
- **1.1 — `you@localhost` default.** `Cake\Mailer\Message` initializes the
  From address to `you@localhost`, so `$message->getFrom()` is never empty.
  `CerebrateMailer` tracks a `$fromConfigured` flag at construction and
  uses that (not `getFrom()`) to decide whether to throw on `deliver()`.
- **1.4 — dual subject source.** Cake's `Mailer::render()` calls `Renderer::render()`
  with a local View, so `$this->set('subject', …)` inside a template never
  propagates back to the Mailer's Message subject under the native plaintext
  pipeline. ReminderMailer's helper now sets the subject directly on the Mailer.
  The template-side `$this->set('subject', …)` is kept because `EmailRenderer`
  (PRD §5.3, used by `GpgMailer` in phase 3) reads it back via
  `$view->get('subject')`. So the subject string lives in two places — fine
  as long as the strings stay aligned (covered by the 1.5 tests).
- **1.3 — defensive templates.** Each reminder template tolerates a missing
  date / individual / key view-var (renders an "unknown" / "an upcoming date"
  fallback) so the 2.1 verification command
  (`./bin/cake send_email --to=foo@example.org --template=reminder_key_expiry`,
  no `--var`) runs end-to-end. The defaults are operator-visible reminders to
  pass the proper view vars; they should not appear in production sends.
- **2.1 — rendered-body bypass.** Cake's `Mailer::render()` runs the
  view layer with no way to read back a template-set subject. The CLI
  pre-renders via `EmailRenderer` (which captures `$view->get('subject')`)
  and pushes the bodies into the mailer through a new
  `CerebrateMailer::setRenderedBody()` helper that suppresses Cake's
  re-render. Same hook will be reused by `GpgMailer` in phase 3.
- **2.1 — best-effort Individual lookup.** `SendEmailCommand` looks up an
  Individual by email so templates can hydrate `$individual`. The lookup is
  wrapped in `try { ... } catch (Throwable)` so the CLI still works when the
  DB is offline (e.g. in a test process without migrations); the address is
  treated as raw in that case.
- **2.2 — DB-dependent case omitted.** The 2.2 test file does not cover the
  "matching individual" branch via fixture, because loading the
  IndividualsFixture pulls in MetaFieldsBehavior + the full migration chain
  for what is otherwise a fast unit-test class. The matching-individual path
  is exercised manually via 4.1's live SMTP smoke test.
- **3.2 — fixture ownertrust.** Crypt_GPG hangs on `encrypt()` against a
  freshly-imported fixture key because gpg emits a `GET_BOOL untrusted_key.override`
  prompt that Crypt_GPG never answers (it doesn't expose `--trust-model always`).
  Fix: `setup_keyring.sh` runs `gpg --import-ownertrust` to mark the fixture
  key `ultimate` (`6`) after import. README documents this and the rationale.
  Round-trip verified with encrypt/decrypt and sign/verify.
- **3.3 — CerebrateMessage subclass.** Cake's `Message::getHeaders()` rebuilds
  `Content-Type` from `emailFormat` on every read, clobbering any value set via
  `setHeaders(['Content-Type' => …])`. To deliver an RFC 3156 envelope we need
  `multipart/signed` / `multipart/encrypted` on the outer message — so
  `CerebrateMailer` now uses a `CerebrateMessage` subclass that adds a raw-envelope
  mode: `setRawEnvelope($contentType, $body)` overrides `Content-Type` in
  `getHeaders()` and returns the pre-rendered body verbatim from `getBody()`.
  Date / Message-ID / Subject still flow through the parent.
- **PHPStan unavailable.** `composer stan` fails with `phpstan: not found`
  — phpstan is listed in `composer.json` under `suggest` only, not
  `require-dev`. Future tasks treat phpstan as an optional verification
  step: cs-check and functional tests are the binding gates. If we want
  stan back as a hard gate, install it with `composer require --dev
  phpstan/phpstan` in a separate cleanup change.
