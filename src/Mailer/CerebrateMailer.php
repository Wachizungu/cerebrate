<?php
declare(strict_types=1);

namespace App\Mailer;

use App\Lib\Tools\SendEmailException;
use Cake\Core\Configure;
use Cake\Mailer\Mailer;
use Cake\Mailer\Message;
use Cake\Utility\Text;

/**
 * Cerebrate base mailer.
 *
 * Centralizes outbound-mail conventions: From / Reply-To pulled from
 * `Cerebrate.email.*`, optional master-switch transport override, and
 * stable threading headers (Message-ID, Date, References / In-Reply-To).
 */
class CerebrateMailer extends Mailer
{
    /**
     * @inheritDoc
     */
    protected $messageClass = CerebrateMessage::class;

    /**
     * Whether `Cerebrate.email.from` was set at construction. Cake's Message defaults
     * From to `you@localhost`, so we can't rely on `getFrom()` to detect missing config.
     *
     * @var bool
     */
    protected bool $fromConfigured = false;

    /**
     * Pre-rendered body keyed by content type (`text`, `html`). When non-null, the
     * Cake render pipeline is bypassed and these strings are pushed directly onto
     * the underlying Message. Useful for callers that want full control over the
     * rendered output (CLI / GpgMailer).
     *
     * @var array<string, string>|null
     */
    protected ?array $preRenderedBody = null;

    /**
     * @param array<string, mixed>|string|null $config Profile name or config array forwarded to Cake\Mailer\Mailer.
     */
    public function __construct($config = null)
    {
        parent::__construct($config);

        $from = Configure::read('Cerebrate.email.from');
        if (!empty($from)) {
            $fromName = Configure::read('Cerebrate.email.from_name');
            $this->setFrom($from, !empty($fromName) ? $fromName : null);
            $this->fromConfigured = true;
        }

        $replyTo = Configure::read('Cerebrate.email.reply_to');
        if (!empty($replyTo)) {
            $this->setReplyTo($replyTo);
        }

        if (Configure::read('Cerebrate.email.disable') === true) {
            $this->setTransport('Debug');
        }
    }

    /**
     * Group messages into a stable thread.
     *
     * Sets In-Reply-To and References to `<sha1(referenceId|App.uuid)@host>`
     * so reminders for the same logical subject (e.g. one expiring key)
     * land in a single thread across reminder cycles.
     *
     * @param string $referenceId Caller-supplied logical thread id (e.g. "key:42").
     * @return $this
     */
    public function withReference(string $referenceId)
    {
        $host = $this->resolveHost();
        $instanceUuid = (string)Configure::read('App.uuid');
        $ref = '<' . sha1($referenceId . '|' . $instanceUuid) . '@' . $host . '>';
        $this->getMessage()->addHeaders([
            'In-Reply-To' => $ref,
            'References' => $ref,
        ]);

        return $this;
    }

    /**
     * Skip the default render pipeline when a pre-rendered body has been supplied.
     *
     * @param string $content Optional pre-rendered body (ignored when setRenderedBody() was called).
     * @return $this
     */
    public function render(string $content = '')
    {
        $message = $this->getMessage();
        if ($message instanceof CerebrateMessage && $message->hasRawEnvelope()) {
            return $this;
        }
        if ($this->preRenderedBody !== null) {
            $message->setBody($this->preRenderedBody);

            return $this;
        }

        return parent::render($content);
    }

    /**
     * Inject already-rendered html and text bodies, bypassing the view layer.
     *
     * @param string $text Plain-text body (required).
     * @param string|null $html HTML body (optional — when null the email is text-only).
     * @return $this
     */
    public function setRenderedBody(string $text, ?string $html = null)
    {
        $this->setEmailFormat($html !== null ? Message::MESSAGE_BOTH : Message::MESSAGE_TEXT);
        $body = ['text' => $text];
        if ($html !== null) {
            $body['html'] = $html;
        }
        $this->preRenderedBody = $body;

        return $this;
    }

    /**
     * Apply Cerebrate metadata then delegate to the parent transport pipeline.
     *
     * @param string $content Optional pre-rendered body.
     * @return array<string, string>
     * @throws \App\Lib\Tools\SendEmailException When `Cerebrate.email.from` is not configured.
     */
    public function deliver(string $content = '')
    {
        if (!$this->fromConfigured) {
            throw new SendEmailException(
                'Cerebrate.email.from is not configured; refusing to send mail from a placeholder address.'
            );
        }

        $this->applyMessageMetadata();

        return parent::deliver($content);
    }

    /**
     * Stamp Message-ID and Date so they survive GPG signing.
     *
     * @return void
     */
    protected function applyMessageMetadata(): void
    {
        $host = $this->resolveHost();
        $this->getMessage()->setMessageId('<' . Text::uuid() . '@' . $host . '>');
        $this->getMessage()->addHeaders([
            'Date' => date(DATE_RFC2822),
        ]);
    }

    /**
     * Best-effort host part for Message-ID / References.
     *
     * Prefers the From address domain; falls back to App.fullBaseUrl host;
     * finally `localhost` so callers never produce malformed Message-IDs.
     *
     * @return string
     */
    protected function resolveHost(): string
    {
        $from = $this->getMessage()->getFrom();
        if (!empty($from)) {
            $address = (string)array_key_first($from);
            $at = strrpos($address, '@');
            if ($at !== false) {
                $domain = substr($address, $at + 1);
                if ($domain !== '') {
                    return $domain;
                }
            }
        }

        $baseUrl = (string)Configure::read('App.fullBaseUrl');
        if ($baseUrl !== '') {
            $host = parse_url($baseUrl, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }

        return 'localhost';
    }
}
