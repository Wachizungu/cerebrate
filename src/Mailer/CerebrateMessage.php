<?php
declare(strict_types=1);

namespace App\Mailer;

use Cake\Mailer\Message;

/**
 * Cerebrate-specific Message subclass.
 *
 * Adds a "raw envelope" mode used by `App\Lib\Tools\GpgMailer` to send a
 * pre-assembled MIME body (e.g. `multipart/signed`, `multipart/encrypted`)
 * without Cake's `Message::getHeaders()` overriding the Content-Type or
 * Content-Transfer-Encoding it auto-derives from `emailFormat`.
 */
class CerebrateMessage extends Message
{
    /**
     * Pre-rendered envelope body (already wrapped with MIME parts), or null
     * when the standard Cake render pipeline should be used.
     *
     * @var string|null
     */
    protected ?string $rawBody = null;

    /**
     * Full Content-Type value to apply when `$rawBody` is set.
     *
     * @var string|null
     */
    protected ?string $rawContentType = null;

    /**
     * Pre-split body lines, cached so `getBody()` (called from both the header
     * pass and the transport's body pass) doesn't re-split a multi-KB envelope.
     *
     * @var array<int, string>|null
     */
    protected ?array $rawBodyLines = null;

    /**
     * Switch the message into raw-envelope mode.
     *
     * @param string $contentType Full Content-Type header value (no `Content-Type:` prefix).
     * @param string $body Pre-rendered envelope body, including all internal MIME boundaries.
     * @return $this
     */
    public function setRawEnvelope(string $contentType, string $body)
    {
        $this->rawContentType = $contentType;
        $this->rawBody = $body;
        $this->rawBodyLines = explode("\r\n", $body);

        return $this;
    }

    /**
     * @return bool
     */
    public function hasRawEnvelope(): bool
    {
        return $this->rawBody !== null;
    }

    /**
     * Override Cake's auto Content-Type / Content-Transfer-Encoding so the GPG
     * envelope's MIME header survives. Date / Message-ID / Subject still flow
     * through the parent so threading and operator-visible headers stay intact.
     *
     * @param array<int|string, bool|string> $include Header categories the caller wants populated.
     * @return array<string, string>
     */
    public function getHeaders(array $include = []): array
    {
        $headers = parent::getHeaders($include);
        if (!$this->hasRawEnvelope()) {
            return $headers;
        }

        $headers['Content-Type'] = (string)$this->rawContentType;
        unset($headers['Content-Transfer-Encoding']);

        return $headers;
    }

    /**
     * @return array<int, string>
     */
    public function getBody()
    {
        if (!$this->hasRawEnvelope()) {
            return parent::getBody();
        }

        return $this->rawBodyLines ?? [];
    }
}
