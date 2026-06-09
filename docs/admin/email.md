# Email & PGP — administrator guide

This guide is for operators running a Cerebrate instance. It covers
how to configure outbound email, sign messages with PGP, encrypt
mail to recipients whose public keys are stored in Cerebrate, and
verify the setup from the CLI.

> Status: the mailer subsystem is shipped. The cron-driven key
> expiry sweep that consumes it is planned separately. Until that
> ships, the practical use is manual / CLI sends.

---

## What Cerebrate sends

Cerebrate's outbound mail is intentionally minimal. The subsystem
exists to deliver operational notifications — currently the two
PGP-key-expiry reminder templates — through a single, signed and
optionally encrypted channel:

| Template                | Purpose                                                  |
|-------------------------|----------------------------------------------------------|
| `reminder_key_expiry`   | A user's PGP key is approaching its expiry date.         |
| `reminder_key_expired`  | A user's PGP key has already expired.                    |

You can also drive the mailer directly from the CLI for ad-hoc
sends, smoke tests, and one-off notifications during incidents.

---

## Prerequisites

1. **An SMTP transport.** Cerebrate uses CakePHP's transport layer
   — it does not run its own MTA. You need an SMTP relay reachable
   from the Cerebrate host. For development, `mailhog` or `maildev`
   on `localhost:25` works well.
2. **For PGP:** GnuPG installed on the host (`gpg --version` should
   succeed), and a GnuPG home directory writable by the user that
   PHP runs as (typically `www-data`).
3. **For encryption to recipients:** each intended recipient must
   exist as an `Individual` in Cerebrate, with at least one PGP
   public key stored under their *Encryption keys* tab.

---

## 1. Configure the SMTP transport

SMTP transport configuration is **not** managed in the Settings
UI. It lives in `config/app_local.php` so it can sit alongside
other deploy-time secrets.

Edit `config/app_local.php` and adjust the `EmailTransport.default`
block:

```php
'EmailTransport' => [
    'default' => [
        'className' => 'Cake\Mailer\Transport\SmtpTransport',
        'host'      => 'smtp.example.org',
        'port'      => 587,
        'timeout'   => 30,
        'username'  => 'cerebrate@example.org',
        'password'  => env('SMTP_PASSWORD'),
        'tls'       => true,
    ],
],
```

For local development against `mailhog`:

```php
'EmailTransport' => [
    'default' => [
        'className' => 'Cake\Mailer\Transport\SmtpTransport',
        'host'      => '127.0.0.1',
        'port'      => 1025,
        'tls'       => false,
    ],
],
```

After editing, no restart is needed — the next request will pick
up the new config.

---

## 2. Configure email behaviour (Settings UI)

The 9 application-level email settings live under:

> **Administration → Settings → Application → Network → Email**

These are persisted to `config/config.json` and read at runtime by
the mailer. Default values shown below match the on-disk defaults
declared by `CerebrateSettingsProvider`.

| Setting | Default | What it does |
|---|---|---|
| `Cerebrate.email.from` | *(empty)* | **Required.** Envelope and header `From:` address used on every outbound mail. If this is empty, Cerebrate refuses to send. There is no fallback placeholder — by design, an unconfigured `from` disables the mailer. |
| `Cerebrate.email.from_name` | `Cerebrate` | Optional display name paired with `from`. Appears as `Name <addr@example.org>` in the `From:` header. |
| `Cerebrate.email.reply_to` | *(empty)* | Optional `Reply-To:` header. Leave empty to omit. |
| `Cerebrate.email.disable` | `false` | When `true`, all outbound mail is routed through CakePHP's `Debug` transport — nothing leaves the box. Use during development and disaster drills. |
| `Cerebrate.email.gpg_sign` | `false` | When `true`, every outbound mail is signed with the configured server PGP key (PGP/MIME, RFC 3156). |
| `Cerebrate.email.gpg_signing_key` | *(empty)* | Identifier (email or fingerprint) of the server signing key in the GnuPG home directory. Required when signing is enabled. |
| `Cerebrate.email.gpg_signing_passphrase` | *(empty)* | Passphrase that unlocks the signing key. Stored in `config/config.json`. Required when signing is enabled and the key is passphrase-protected. |
| `Cerebrate.email.gpg_obscure_subject` | `false` | When the message is **both** signed and encrypted, replace the outer `Subject:` with `...`. The real subject is still visible to the recipient inside the protected (signed) headers. Hides subject metadata from anyone who only sees the envelope. |
| `Cerebrate.email.only_encrypted` | `false` | When `true`, Cerebrate refuses to send any mail that could not be encrypted to the recipient. Use only if every intended recipient has a valid public key on file — otherwise sends will fail. |

---

## 3. Set up PGP signing (server-side key)

If you intend to sign outbound mail, the server needs a PGP secret
key in the GnuPG home directory that PHP can read.

### 3.1 Choose the GnuPG home directory

By default Cerebrate uses `ROOT/.gnupg` (i.e. `/var/www/cerebrate/.gnupg`
in a typical install). To override, add a block in `config/app_local.php`:

```php
'GnuPG' => [
    'homedir' => '/var/www/cerebrate/.gnupg',
    'binary'  => '/usr/bin/gpg',
],
```

The directory must be **owned and writable** by the user that PHP
runs as (typically `www-data`). A wrong-ownership homedir is the
single most common cause of mysterious GPG failures.

```bash
sudo mkdir -p /var/www/cerebrate/.gnupg
sudo chown -R www-data:www-data /var/www/cerebrate/.gnupg
sudo chmod 700 /var/www/cerebrate/.gnupg
```

### 3.2 Import the server signing key

Import as the same user PHP runs as, so the keyring ends up
readable:

```bash
sudo -u www-data gpg --homedir /var/www/cerebrate/.gnupg \
    --import /path/to/cerebrate-signing-key.asc
```

Mark the key as trusted (otherwise GnuPG will pause on the first
sign):

```bash
sudo -u www-data gpg --homedir /var/www/cerebrate/.gnupg \
    --edit-key cerebrate@example.org trust quit
```

Choose option `5 (I trust ultimately)` for keys you generated for
this server. Confirm the key is visible:

```bash
sudo -u www-data gpg --homedir /var/www/cerebrate/.gnupg --list-secret-keys
```

### 3.3 Turn on signing in the UI

1. Settings → Application → Network → Email
2. Set **GPG signing** = `true`
3. **GPG signing key** = the key's email or fingerprint (matching what
   `gpg --list-secret-keys` shows)
4. **GPG signing key passphrase** = the unlock passphrase, or leave
   empty if the key has no passphrase
5. Optionally enable **Obscure subject when signed and encrypted**
6. Save

### 3.4 Verify

Send a signed test message to yourself (see [§5 — CLI](#5-manual-sends-via-the-cli)).
Open the raw message source and confirm:

- The outer `Content-Type` is `multipart/signed; protocol="application/pgp-signature"; micalg=pgp-...`.
- One MIME part contains `BEGIN PGP SIGNATURE` / `END PGP SIGNATURE`.
- The protected headers block (`protected-headers="v1"`) is present.
- Your MUA shows a green / verified signature when configured with
  the matching public key.

---

## 4. Store recipient public keys

Cerebrate encrypts outbound mail to recipients using the public
keys stored against each `Individual`.

To add a recipient's key:

1. Navigate to the **Individual** in the UI.
2. Open the **Encryption keys** tab.
3. **Add encryption key**, paste the ASCII-armoured public key
   block (`-----BEGIN PGP PUBLIC KEY BLOCK-----` … `-----END PGP
   PUBLIC KEY BLOCK-----`), set type = `pgp`.
4. Save.

Cerebrate validates the key on save — invalid, expired, revoked,
or non-encrypting keys are rejected.

You do **not** need to mark recipient keys as trusted manually.
Cerebrate sets the ownertrust automatically on import (the
operator's act of storing the key on the Individual is treated as
sufficient validation), so encryption to that recipient works on
the first send without a `gpg --import-ownertrust` ritual.

Multiple keys per Individual are supported. The CLI and the
mailer use the first available `pgp`-type key per Individual; if
you have multiple, revoke or delete the one you don't want
selected.

---

## 5. Manual sends via the CLI

The `send_email` CakePHP command exercises the entire mailer
pipeline. Use it for smoke tests, ad-hoc notifications, and
debugging.

```
./bin/cake send_email --to=<addr> --template=<name> [--var key=value]... [--reference <id>] [--encrypt]
```

| Flag | Required | Description |
|---|---|---|
| `--to=<addr>` | yes | Recipient email. If the address matches an `Individual.email`, that entity is hydrated and exposed in the template as `$individual`. Otherwise the address is treated as raw. |
| `--template=<name>` | yes | Template stem under `templates/email/{html,text}/`. The provided shipped names are `reminder_key_expiry` and `reminder_key_expired`. Add your own under the same path. |
| `--var key=value` | no | View variable in `key=value` form. Repeat for multiple variables. Values arrive as strings; templates currently expect strings or fall back to defaults. |
| `--reference <id>` | no | Threading reference id (e.g. `key:42`) added as `In-Reply-To:` / `References:`. Lets a MUA group related reminders into the same thread. |
| `--encrypt` | no | Encrypt the message to the recipient Individual's PGP public key. Requires `--to` to match an Individual that has a stored key. Errors with a raw address. |

### Examples

Send the "key expiring soon" reminder to a known Individual,
plaintext:

```bash
./bin/cake send_email \
    --to=jane@example.org \
    --template=reminder_key_expiry \
    --var key_id=ABCD1234 \
    --reference 'key:42'
```

Same, but encrypted to Jane's PGP key:

```bash
./bin/cake send_email \
    --to=jane@example.org \
    --template=reminder_key_expiry \
    --var key_id=ABCD1234 \
    --reference 'key:42' \
    --encrypt
```

Smoke-test the pipeline without sending real mail (set
`Cerebrate.email.disable = true` first, then):

```bash
./bin/cake send_email --to=admin@localhost --template=reminder_key_expiry
```

The CLI emits `Sent: <message-id>` to stdout on success, and
writes the same line to the application log. On failure it
returns non-zero with a one-line error.

---

## 6. Operational recipes

### Test the mailer without sending real mail

1. Settings → Application → Network → Email → **Disable outbound email** = `true`.
2. Run the CLI as above. Cerebrate routes through the `Debug`
   transport; nothing reaches the wire.
3. Inspect rendered content in `logs/debug.log`.
4. Turn the setting off when done.

### Verify MIME envelopes against a local mail catcher

When you want to inspect the actual wire format (`multipart/signed`
or `multipart/encrypted` envelopes, headers, MIME boundaries)
without involving a real SMTP relay, use a dev catcher like
[mailpit](https://github.com/axllent/mailpit) or
[mailhog](https://github.com/mailhog/MailHog):

1. Start mailpit on its default ports — SMTP `1025`, web UI `8025`.
2. In `config/app_local.php`, point `EmailTransport.default` at
   `127.0.0.1:1025` with `tls => false`.
3. Make sure **Disable outbound email** is `false` (so SMTP delivery
   happens) and that your test recipient has a stored encryption
   key if you're exercising `--encrypt`.
4. Fire a send with the CLI.
5. Open `http://localhost:8025` to see the captured message. The
   web UI shows headers, MIME parts, and the raw source — enough to
   verify the envelope is RFC 3156-compliant without needing a real
   MUA to decrypt.

Mailpit also exposes a JSON API at `/api/v1/messages` if you want
to assert the envelope shape from a script.

### Lock the instance down to encrypted-only delivery

For a CSIRT-style setup where every recipient is known and has a
key on file:

1. Confirm every Individual that could receive mail has a stored
   PGP public key.
2. Settings → Application → Network → Email → **Refuse to send
   unencrypted mail** = `true`.
3. From then on, any send that cannot be encrypted (no key on
   file, key revoked/expired, raw address) **fails** instead of
   leaking plaintext.

### Rotate the server signing key

1. Generate the new key, import into the GnuPG home directory as
   above.
2. Update **GPG signing key** (and **passphrase** if changed) in
   Settings.
3. Publish the new public key to your usual key distribution
   channels.
4. Keep the old secret key in the homedir long enough that
   incoming verification of in-flight messages doesn't break.

### Quiet the mailer during incidents or maintenance

Toggle **Disable outbound email** = `true`. This is reversible
and immediate. It does not stop the CLI from being invoked — sends
are simply routed to the Debug transport with no SMTP traffic.

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `SendEmailException: Cerebrate.email.from is not configured` | Empty `from` setting. | Set a real From address in Settings → Network → Email. |
| `Crypt_GPG_NoDataException` / `Crypt_GPG_BadPassphraseException` | Signing key missing, untrusted, or passphrase wrong. | Verify with `gpg --homedir <home> --list-secret-keys`. Trust the key (`gpg --edit-key … trust quit`). Confirm passphrase. |
| GPG operation hangs forever | The **server signing key** is untrusted — gpg prompts for `GET_BOOL untrusted_key.override` that `Crypt_GPG` can't answer. Recipient keys can't trigger this any more; Cerebrate auto-trusts them on import. | Mark the *server* key trusted: `sudo -u www-data gpg --homedir <home> --edit-key <signing-key-id> trust quit` (choose `5 - ultimate`). |
| `--encrypt requires --to to match a known Individual` | Used `--encrypt` with a raw address. | Use the Individual's email, or add the address to an Individual first. |
| `No usable GPG encryption key found for the recipient Individual.` | Individual exists but has no PGP key, or only revoked/expired keys. | Add a valid PGP public key under the Individual's Encryption Keys tab. |
| `SendEmailException: only_encrypted enabled but message was not encrypted` | `only_encrypted=true` and the message couldn't be encrypted. | Add a recipient key, or disable `only_encrypted` if plaintext is acceptable for this audience. |
| Mail leaves the box but the recipient sees an unsigned blob | `gpg_sign=false`. | Enable **Sign outbound mail with GPG** in Settings. |
| MUA shows a "broken" signature | Mail body modified in transit by a relay (e.g. footer injection, SRS rewriting). | Run mail through a relay that does not rewrite MIME bodies, or sign in a way the relay tolerates (PGP/MIME survives most relays; inline PGP does not). |

---

## 8. Where things live (for cross-reference)

| Concern | Location |
|---|---|
| SMTP transport config | `config/app_local.php` → `EmailTransport.default` |
| Application email settings | UI: Administration → Settings → Application → Network → Email · on-disk: `config/config.json` |
| GnuPG home directory | `config/app_local.php` → `GnuPG.homedir` (default `ROOT/.gnupg`) |
| Server signing key | The GnuPG home directory above |
| Recipient public keys | Per-Individual `Encryption keys` tab in the UI (DB table: `encryption_keys`) |
| Email templates | `templates/email/{html,text}/<name>.php` with a shared layout at `templates/layout/email/{html,text}/default.php` |
| CLI | `./bin/cake send_email …` |
| Application logs | `logs/debug.log`, `logs/error.log` |
