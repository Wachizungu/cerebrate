<?php
declare(strict_types=1);

namespace App\Lib\Tools;

use App\Lib\Tools\Mime\MessagePart;
use App\Lib\Tools\Mime\MimeMultipart;
use App\Mailer\CerebrateMailer;
use App\Mailer\CerebrateMessage;
use App\Model\Entity\EncryptionKey;
use Cake\Core\Configure;
use Crypt_GPG;
use Crypt_GPG_BadPassphraseException;
use Crypt_GPG_Exception;
use Crypt_GPG_KeyNotFoundException;
use Crypt_GPG_NoDataException;

/**
 * Cerebrate's GPG sign+encrypt pipeline for outbound mail.
 *
 * Drives a `CerebrateMailer` through the EmailRenderer to produce
 * plain text + html bodies, optionally signs with the server key,
 * optionally encrypts to a recipient's public key, and pushes the
 * resulting RFC 3156 envelope into the mailer's underlying Message
 * via `CerebrateMessage::setRawEnvelope()`.
 *
 * MIME envelope construction is a faithful port from MISP7's
 * `SendEmail.php` (sign / encrypt paths). Refer to
 * draft-autocrypt-lamps-protected-headers-02 for the protected-headers
 * convention used on the signed inner part.
 */
class GpgMailer
{
    /**
     * @var \Crypt_GPG|null
     */
    private ?Crypt_GPG $gpg;

    /**
     * @param \Crypt_GPG|null $gpg Optional pre-built GPG instance. When null, one is built lazily
     *     from `Cerebrate.email.gpg_signing_key` / `GnuPG.homedir` config on first use.
     */
    public function __construct(?Crypt_GPG $gpg = null)
    {
        $this->gpg = $gpg;
        if ($this->gpg !== null) {
            $this->gpg->clearDecryptKeys()
                ->clearEncryptKeys()
                ->clearSignKeys()
                ->clearPassphrases();
        }
    }

    /**
     * Render, optionally sign, optionally encrypt, then deliver via the supplied mailer.
     *
     * @param \App\Mailer\CerebrateMailer $mailer Mailer with `to`, template, and view vars already configured.
     * @param \App\Model\Entity\EncryptionKey|null $recipientKey Recipient public key; null disables encryption.
     * @return array{to: string, subject: string, message_id: string, signed: bool, encrypted: bool}
     * @throws \App\Lib\Tools\SendEmailException
     */
    public function deliverWithGpg(CerebrateMailer $mailer, ?EncryptionKey $recipientKey): array
    {
        $template = $mailer->viewBuilder()->getTemplate();
        if ($template === '' || $template === null) {
            throw new SendEmailException('Mailer has no template configured; cannot render GPG envelope.');
        }
        $vars = $mailer->viewBuilder()->getVars();

        $rendered = (new EmailRenderer())->render($template, $vars);
        if ($rendered['subject'] !== null && $rendered['subject'] !== '') {
            $mailer->setSubject($rendered['subject']);
        }
        $message = $mailer->getMessage();
        if (!$message instanceof CerebrateMessage) {
            throw new SendEmailException('GpgMailer requires a CerebrateMessage instance on the mailer.');
        }

        $innerBody = $this->buildInnerBody($rendered['text'], $rendered['html']);
        $innerContentType = $rendered['html'] !== null
            ? 'multipart/alternative; boundary="' . $innerBody['boundary'] . '"'
            : 'text/plain; charset=UTF-8';

        $signed = false;
        $encrypted = false;

        $shouldSign = (bool)Configure::read('Cerebrate.email.gpg_sign');
        if ($shouldSign) {
            [$envelopeContentType, $envelopeBody] = $this->buildSigned(
                $innerContentType,
                $innerBody['body'],
                $message
            );
            $signed = true;
        } else {
            $envelopeContentType = $innerContentType;
            $envelopeBody = $innerBody['body'];
        }

        if ($recipientKey !== null) {
            $fingerprint = $this->importAndValidateRecipientKey($recipientKey);
            if ($fingerprint !== null) {
                [$envelopeContentType, $envelopeBody] = $this->buildEncrypted(
                    $envelopeContentType,
                    $envelopeBody,
                    $fingerprint
                );
                $encrypted = true;
            }
        }

        if (!$encrypted && Configure::read('Cerebrate.email.only_encrypted') === true) {
            throw new SendEmailException(
                'Cerebrate.email.only_encrypted is enabled but the message '
                . 'could not be encrypted (no valid recipient key).'
            );
        }

        if ($signed && $encrypted && Configure::read('Cerebrate.email.gpg_obscure_subject') === true) {
            $mailer->setSubject('...');
        }

        $message->setRawEnvelope($envelopeContentType, $envelopeBody);

        $mailer->deliver();

        $to = $message->getTo();
        $toAddress = !empty($to) ? (string)array_key_first($to) : '';
        $messageIdRaw = $message->getMessageId();

        return [
            'to' => $toAddress,
            'subject' => (string)$message->getSubject(),
            'message_id' => is_string($messageIdRaw) ? $messageIdRaw : '',
            'signed' => $signed,
            'encrypted' => $encrypted,
        ];
    }

    /**
     * Build the innermost MIME body — plain text or multipart/alternative.
     *
     * @param string $text Required text body.
     * @param string|null $html Optional html body.
     * @return array{body: string, boundary: ?string}
     */
    protected function buildInnerBody(string $text, ?string $html): array
    {
        if ($html === null) {
            return ['body' => $text, 'boundary' => null];
        }

        $alt = new MimeMultipart('alternative');

        $textPart = new MessagePart();
        $textPart->addHeader('Content-Type', 'text/plain; charset=UTF-8');
        $textPart->setPayload($text);
        $alt->addPart($textPart);

        $htmlPart = new MessagePart();
        $htmlPart->addHeader('Content-Type', 'text/html; charset=UTF-8');
        $htmlPart->setPayload($html);
        $alt->addPart($htmlPart);

        return [
            'body' => implode("\r\n", $alt->render()),
            'boundary' => $alt->boundary(),
        ];
    }

    /**
     * Wrap a body in a `multipart/signed` envelope with protected headers.
     *
     * @param string $innerContentType Content-Type of the body being signed.
     * @param string $innerBody Body to sign (the signature is detached, so the body is preserved verbatim).
     * @param \App\Mailer\CerebrateMessage $message Source of protected headers (From / To / Subject / etc.).
     * @return array{0: string, 1: string} Tuple of [envelope Content-Type, envelope body].
     * @throws \App\Lib\Tools\SendEmailException
     */
    protected function buildSigned(string $innerContentType, string $innerBody, CerebrateMessage $message): array
    {
        $gpg = $this->requireGpg();

        $signingId = (string)Configure::read('Cerebrate.email.gpg_signing_key');
        if ($signingId === '') {
            throw new SendEmailException(
                'Cerebrate.email.gpg_signing_key is required when '
                . 'Cerebrate.email.gpg_sign is enabled.'
            );
        }
        $passphrase = (string)Configure::read('Cerebrate.email.gpg_signing_passphrase');

        $gpg->clearSignKeys()->clearPassphrases();
        try {
            $gpg->addSignKey($signingId, $passphrase);
        } catch (Crypt_GPG_BadPassphraseException $e) {
            throw new SendEmailException('Bad passphrase for the configured Cerebrate signing key.', 0, $e);
        } catch (Crypt_GPG_KeyNotFoundException $e) {
            throw new SendEmailException(
                'The configured Cerebrate signing key was not found in the GPG home directory.',
                0,
                $e
            );
        }

        $innerPart = new MessagePart();
        $innerPart->addHeader('Content-Type', [$innerContentType, 'protected-headers="v1"']);
        $protectedHeaders = $this->collectProtectedHeaders($message);
        foreach ($protectedHeaders as $name => $value) {
            $innerPart->addHeader($name, $value);
        }
        $innerPart->setPayload($innerBody);

        $messageToSign = implode("\r\n", $innerPart->render());

        try {
            $signature = $gpg->sign($messageToSign, Crypt_GPG::SIGN_MODE_DETACHED);
            $signatureInfo = $gpg->getLastSignatureInfo();
        } catch (Crypt_GPG_Exception $e) {
            throw new SendEmailException('GPG signing failed: ' . $e->getMessage(), 0, $e);
        }

        $signaturePart = new MessagePart();
        $signaturePart->addHeader('Content-Type', ['application/pgp-signature', 'name="signature.asc"']);
        $signaturePart->addHeader('Content-Description', 'OpenPGP digital signature');
        $signaturePart->addHeader('Content-Disposition', ['attachment', 'filename="signature.asc"']);
        $signaturePart->setPayload($signature);

        $hashAlgorithm = strtolower(str_replace('-', '', $signatureInfo->getHashAlgorithmName() ?? 'sha256'));
        $output = new MimeMultipart('signed', [
            'micalg=pgp-' . $hashAlgorithm,
            'protocol="application/pgp-signature"',
        ]);
        $output->addPart($innerPart);
        $output->addPart($signaturePart);

        return [$output->getContentType(), implode("\r\n", $output->render())];
    }

    /**
     * Wrap a body in a `multipart/encrypted` envelope per RFC 3156.
     *
     * @param string $innerContentType Content-Type of the body being encrypted.
     * @param string $innerBody Body to encrypt.
     * @param string $fingerprint Recipient key fingerprint.
     * @return array{0: string, 1: string} Tuple of [envelope Content-Type, envelope body].
     * @throws \App\Lib\Tools\SendEmailException
     */
    protected function buildEncrypted(string $innerContentType, string $innerBody, string $fingerprint): array
    {
        $gpg = $this->requireGpg();
        $gpg->clearEncryptKeys();
        try {
            $gpg->addEncryptKey($fingerprint);
        } catch (Crypt_GPG_KeyNotFoundException $e) {
            throw new SendEmailException(
                'Recipient key not found in the keyring after import: ' . $fingerprint,
                0,
                $e
            );
        }

        $partToEncrypt = new MessagePart();
        $partToEncrypt->addHeader('Content-Type', $innerContentType);
        $partToEncrypt->setPayload($innerBody);

        $plaintext = implode("\r\n", $partToEncrypt->render());

        try {
            $cipher = $gpg->encrypt($plaintext, true);
        } catch (Crypt_GPG_Exception $e) {
            throw new SendEmailException('GPG encryption failed: ' . $e->getMessage(), 0, $e);
        }

        $versionPart = new MessagePart();
        $versionPart->addHeader('Content-Type', 'application/pgp-encrypted');
        $versionPart->addHeader('Content-Description', 'PGP/MIME version identification');
        $versionPart->setPayload("Version 1\n");

        $cipherPart = new MessagePart();
        $cipherPart->addHeader('Content-Type', ['application/octet-stream', 'name="encrypted.asc"']);
        $cipherPart->addHeader('Content-Description', 'OpenPGP encrypted message');
        $cipherPart->addHeader('Content-Disposition', ['inline', 'filename="encrypted.asc"']);
        $cipherPart->setPayload($cipher);

        $output = new MimeMultipart('encrypted', ['protocol="application/pgp-encrypted"']);
        $output->addPart($versionPart);
        $output->addPart($cipherPart);

        return [$output->getContentType(), implode("\r\n", $output->render())];
    }

    /**
     * Pull headers covered by the protected-headers extension from the source message.
     *
     * @param \App\Mailer\CerebrateMessage $message Source of the live message state.
     * @return array<string, string>
     */
    protected function collectProtectedHeaders(CerebrateMessage $message): array
    {
        $headers = $message->getHeaders(['from', 'to', 'subject', 'replyTo']);
        $protectedNames = ['From', 'To', 'Date', 'Message-ID', 'Subject', 'Reply-To', 'In-Reply-To', 'References'];
        $out = [];
        foreach ($protectedNames as $name) {
            if (isset($headers[$name]) && $headers[$name] !== '') {
                $out[$name] = $headers[$name];
            }
        }

        return $out;
    }

    /**
     * Reuse Cerebrate's existing GPG validation flow on the supplied recipient key entity.
     * Returns the fingerprint if the key is importable and has a valid encryption subkey, null otherwise.
     *
     * @param \App\Model\Entity\EncryptionKey $key Recipient public key entity.
     * @return string|null Fingerprint if the key validates, null when the key isn't usable.
     */
    protected function importAndValidateRecipientKey(EncryptionKey $key): ?string
    {
        $gpg = $this->requireGpg();
        $material = (string)($key->encryption_key ?? '');
        if ($material === '') {
            return null;
        }

        try {
            $keys = $gpg->keyInfo($material);
        } catch (Crypt_GPG_NoDataException $e) {
            return null;
        } catch (Crypt_GPG_Exception $e) {
            return null;
        }
        if (count($keys) !== 1) {
            return null;
        }

        $primary = $keys[0]->getPrimaryKey();
        if ($primary === null) {
            return null;
        }
        $fingerprint = $primary->getFingerprint();

        $now = time();
        $usable = false;
        foreach ($keys[0]->getSubKeys() as $sub) {
            $expiration = $sub->getExpirationDate();
            if ($expiration !== 0 && $now > $expiration) {
                continue;
            }
            if (!$sub->canEncrypt()) {
                continue;
            }
            $usable = true;
            break;
        }
        if (!$usable) {
            return null;
        }

        try {
            $gpg->importKey($material);
        } catch (Crypt_GPG_Exception $e) {
            return null;
        }

        if (!$this->setOwnerTrustUltimate($fingerprint)) {
            return null;
        }

        return $fingerprint;
    }

    /**
     * Mark a freshly-imported key as ultimately-trusted in Cerebrate's GPG
     * homedir so subsequent encrypt operations don't prompt for the
     * `untrusted_key.override` confirmation that Crypt_GPG can't answer
     * (which would hang the mailer forever).
     *
     * Crypt_GPG does not expose `--trust-model always` or any direct
     * ownertrust setter, so we shell out to `gpg --import-ownertrust` with
     * `<fingerprint>:6:` on stdin. Trust level 6 is acceptable here because
     * the operator has already vetted the key by storing it on an
     * Individual via Cerebrate's encryption_keys table.
     *
     * @param string $fingerprint Full fingerprint of the key to trust.
     * @return bool true on success, false on any failure.
     */
    protected function setOwnerTrustUltimate(string $fingerprint): bool
    {
        $homedir = Configure::read('GnuPG.homedir') ?? ROOT . '/.gnupg';
        $binary = Configure::read('GnuPG.binary') ?: '/usr/bin/gpg';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(
            [$binary, '--homedir', $homedir, '--batch', '--import-ownertrust'],
            $descriptors,
            $pipes
        );
        if (!is_resource($proc)) {
            return false;
        }
        fwrite($pipes[0], sprintf("%s:6:\n", $fingerprint));
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc) === 0;
    }

    /**
     * Lazy-initialize the Crypt_GPG instance from Cerebrate's GpgTool.
     *
     * @return \Crypt_GPG
     * @throws \App\Lib\Tools\SendEmailException
     */
    protected function requireGpg(): Crypt_GPG
    {
        if ($this->gpg === null) {
            try {
                $this->gpg = GpgTool::initializeGpg();
            } catch (\Exception $e) {
                throw new SendEmailException('GPG is not initialized: ' . $e->getMessage(), 0, $e);
            }
        }

        return $this->gpg;
    }
}
