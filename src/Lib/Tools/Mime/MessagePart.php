<?php
declare(strict_types=1);

namespace App\Lib\Tools\Mime;

/**
 * A single MIME part: headers + payload.
 *
 * Ported from MISP7's `app/Lib/Tools/SendEmail.php` (MessagePart class).
 * Used by `App\Lib\Tools\GpgMailer` to assemble RFC 3156 envelopes
 * (`multipart/signed`, `multipart/encrypted`).
 */
class MessagePart
{
    /**
     * @var array<string, string>
     */
    private array $headers = [];

    /**
     * @var array<int, string>
     */
    private array $payload = [];

    /**
     * @param string $name Header name.
     * @param array<int|string, string>|string $value Header value (array elements joined with `; `).
     * @return void
     */
    public function addHeader(string $name, $value): void
    {
        if (is_array($value)) {
            $value = implode('; ', $value);
        }

        $this->headers[$name] = (string)$value;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param array<int, string>|string $payload Lines as an array, or a single string (split on \r?\n).
     * @return void
     */
    public function setPayload($payload): void
    {
        if (is_string($payload)) {
            // Normalize CRLF so renderer-emitted bodies don't end up with `\r\r\n`
            // when MimeMultipart re-joins parts with `\r\n` — important for
            // signature canonicalization.
            $payload = preg_split("/\r?\n/", $payload) ?: [];
        }

        $this->payload = $payload;
    }

    /**
     * @param bool $withHeaders Whether to prepend headers + blank-line separator.
     * @return array<int, string>
     */
    public function render(bool $withHeaders = true): array
    {
        $msg = [];
        if ($withHeaders) {
            foreach ($this->headers as $name => $value) {
                $msg[] = $name . ': ' . $value;
            }
            $msg[] = '';
        }

        return array_merge($msg, $this->payload);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return implode("\n", $this->render());
    }
}
