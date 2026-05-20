<?php
declare(strict_types=1);

namespace App\Lib\Tools\Mime;

/**
 * MIME multipart container with deterministic-per-instance boundary.
 *
 * Ported from MISP7's `app/Lib/Tools/SendEmail.php` (MimeMultipart class).
 * Used by `App\Lib\Tools\GpgMailer` to wrap signed and encrypted payloads
 * per RFC 3156.
 */
class MimeMultipart
{
    /**
     * @var array<int, \App\Lib\Tools\Mime\MessagePart>
     */
    private array $parts = [];

    /**
     * @var string
     */
    private string $subtype;

    /**
     * @var string
     */
    private string $boundary;

    /**
     * @var array<int, string>
     */
    private array $additionalTypes;

    /**
     * @param string $subtype Multipart subtype (e.g. `mixed`, `signed`, `encrypted`).
     * @param array<int, string> $additionalTypes Extra Content-Type parameters (e.g. `protocol="application/pgp-signature"`).
     */
    public function __construct(string $subtype = 'mixed', array $additionalTypes = [])
    {
        $this->subtype = $subtype;
        // 32 hex chars; random_bytes is cryptographically strong so a payload
        // can't be crafted to contain the boundary string by accident.
        $this->boundary = bin2hex(random_bytes(16));
        $this->additionalTypes = $additionalTypes;
    }

    /**
     * @return string Full `Content-Type` header value (without the leading `Content-Type:`).
     */
    public function getContentType(): string
    {
        $contentType = array_merge(['multipart/' . $this->subtype], $this->additionalTypes);
        $contentType[] = 'boundary="' . $this->boundary . '"';

        return implode('; ', $contentType);
    }

    /**
     * @return string The boundary string used between parts.
     */
    public function boundary(): string
    {
        return $this->boundary;
    }

    /**
     * @param \App\Lib\Tools\Mime\MessagePart $part Part to append.
     * @return void
     */
    public function addPart(MessagePart $part): void
    {
        $this->parts[] = $part;
    }

    /**
     * Render the multipart container as an array of lines (no trailing newline).
     *
     * @return array<int, string>
     */
    public function render(): array
    {
        $msg = ['--' . $this->boundary];
        foreach ($this->parts as $part) {
            $msg = array_merge($msg, $part->render());
            $msg[] = '--' . $this->boundary;
        }
        $msg[count($msg) - 1] .= '--';

        return $msg;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return implode("\n", $this->render());
    }
}
