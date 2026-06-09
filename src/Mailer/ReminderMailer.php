<?php
declare(strict_types=1);

namespace App\Mailer;

use App\Model\Entity\EncryptionKey;
use App\Model\Entity\Individual;
use Cake\Mailer\Message;
use DateTimeInterface;

/**
 * Mailer for GPG-key lifecycle reminders.
 *
 * One method per reminder type. Each method only sets recipient,
 * template, and view vars; rendering, threading, and transport are
 * handled by the parent. No GPG logic lives here — that belongs to
 * `App\Lib\Tools\GpgMailer` (added in PRD §5.2 / phase 3).
 */
class ReminderMailer extends CerebrateMailer
{
    /**
     * Configure the mailer to send the "your key is about to expire" reminder.
     *
     * @param \App\Model\Entity\Individual $individual Recipient.
     * @param \App\Model\Entity\EncryptionKey $key Key approaching expiry.
     * @param \DateTimeInterface $expiresAt When the key expires.
     * @return void
     */
    public function keyExpiry(Individual $individual, EncryptionKey $key, DateTimeInterface $expiresAt): void
    {
        $this->prepare(
            $individual,
            $key,
            'reminder_key_expiry',
            ['expiresAt' => $expiresAt],
            sprintf('Your GPG key expires on %s', $expiresAt->format('Y-m-d'))
        );
    }

    /**
     * Configure the mailer to send the "your key has expired" reminder.
     *
     * @param \App\Model\Entity\Individual $individual Recipient.
     * @param \App\Model\Entity\EncryptionKey $key Key that already expired.
     * @param \DateTimeInterface $expiredAt When the key expired.
     * @return void
     */
    public function keyExpired(Individual $individual, EncryptionKey $key, DateTimeInterface $expiredAt): void
    {
        $this->prepare(
            $individual,
            $key,
            'reminder_key_expired',
            ['expiredAt' => $expiredAt],
            sprintf('Your GPG key expired on %s', $expiredAt->format('Y-m-d'))
        );
    }

    /**
     * Configure the mailer to send a single digest covering one or more of an
     * individual's keys that need attention (expiring and/or already expired).
     *
     * This is the path the sweep uses. A digest of a single key renders as a
     * one-row table and keeps the single-key subject, so the common case is
     * unchanged for recipients.
     *
     * @param \App\Model\Entity\Individual $individual Recipient.
     * @param array<int, array<string, mixed>> $items One record per key, each
     *     `['key' => EncryptionKey, 'expiry' => DateTimeInterface, 'expired' => bool, 'threshold' => int]`.
     *     `expiry` is the key's soonest encryption-subkey expiry, `expired` whether it has already passed,
     *     `threshold` the crossed reminder band (carried for caller symmetry; not rendered).
     * @return void
     */
    public function keyDigest(Individual $individual, array $items): void
    {
        usort($items, function (array $a, array $b): int {
            if (!empty($a['expired']) !== !empty($b['expired'])) {
                return !empty($a['expired']) ? -1 : 1;
            }

            return ($a['expiry'] ?? null) <=> ($b['expiry'] ?? null);
        });

        $this->setTo($individual->email);
        $this->setSubject($this->digestSubject($items));
        $this->setEmailFormat(Message::MESSAGE_BOTH);
        $this->viewBuilder()
            ->setTemplate('reminder_key_digest')
            ->setLayout('default');
        $this->setViewVars([
            'individual' => $individual,
            'items' => $items,
        ]);

        if (count($items) === 1) {
            $key = $items[0]['key'];
            $this->withReference('key:' . ($key->id ?? 'unknown'));
        } else {
            $this->withReference('digest:individual:' . ($individual->id ?? 'unknown'));
        }
    }

    /**
     * Compose the digest subject line. Mirrors the subject logic in the
     * `reminder_key_digest` templates so the EmailRenderer / GpgMailer path
     * produces the same subject as the native plaintext path.
     *
     * @param array<int, array<string, mixed>> $items Sorted digest items.
     * @return string
     */
    protected function digestSubject(array $items): string
    {
        if (count($items) === 1) {
            $item = $items[0];
            $expiry = $item['expiry'] ?? null;
            $date = $expiry instanceof DateTimeInterface ? $expiry->format('Y-m-d') : 'an upcoming date';

            return !empty($item['expired'])
                ? sprintf('Your GPG key expired on %s', $date)
                : sprintf('Your GPG key expires on %s', $date);
        }

        return sprintf('GPG key reminders (%d keys)', count($items));
    }

    /**
     * Shared setup for reminder methods.
     *
     * @param \App\Model\Entity\Individual $individual Recipient.
     * @param \App\Model\Entity\EncryptionKey $key Key the reminder is about.
     * @param string $template Template stem under `templates/email/{html,text}/`.
     * @param array<string, mixed> $extraVars Reminder-specific view vars (e.g. `expiresAt`).
     * @param string $subject Subject line for the Cake-native plaintext delivery path. The same
     *     subject is mirrored by the template's `$this->set('subject', ...)` for the
     *     GpgMailer / EmailRenderer path added in PRD §5.2.
     * @return void
     */
    protected function prepare(
        Individual $individual,
        EncryptionKey $key,
        string $template,
        array $extraVars,
        string $subject
    ): void {
        $this->setTo($individual->email);
        $this->setSubject($subject);
        $this->setEmailFormat(Message::MESSAGE_BOTH);
        $this->viewBuilder()
            ->setTemplate($template)
            ->setLayout('default');
        $this->setViewVars(array_merge([
            'individual' => $individual,
            'key' => $key,
        ], $extraVars));
        $this->withReference('key:' . ($key->id ?? 'unknown'));
    }
}
