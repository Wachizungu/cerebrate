<?php
declare(strict_types=1);

namespace App\Command;

use App\Lib\Tools\GpgMailer;
use App\Lib\Tools\GpgTool;
use App\Lib\Tools\ReminderSweep;
use App\Mailer\ReminderMailer;
use App\Model\Entity\EncryptionKey;
use Cake\Console\Arguments;
use Cake\Console\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Cake\Log\Log;
use Closure;
use Crypt_GPG;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Cron-driven reminder sweep for individual PGP keys nearing or past expiry.
 *
 * Walks every `encryption_keys` row owned by an Individual, parses the
 * soonest encryption-capable subkey expiry on the fly via Crypt_GPG, and
 * dispatches a `ReminderMailer` send when a configured threshold is crossed.
 * Idempotent through `encryption_keys.last_reminder_threshold` — see
 * `reminder-sweep-prd.md` §5.2.
 */
class CheckExpiringKeysCommand extends Command
{
    /**
     * Test-only override for the GPG-backed expiry parser. When non-null, the
     * command consults this Closure instead of running `Crypt_GPG::keyInfo()`.
     * Production code must never assign to it.
     *
     * Signature: `function (EncryptionKey $key): ?DateTimeImmutable`.
     *
     * @var \Closure|null
     */
    public static ?Closure $expiryResolverOverride = null;

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Parser to extend.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription(
            'Send PGP-key expiry reminders to individuals whose keys cross a configured threshold.'
        );
        $parser->addOption('thresholds', [
            'help' => 'Comma-separated positive integers (days before expiry) at which a reminder fires. '
                . 'Defaults to Cerebrate.reminders.default_thresholds or "30,7,1".',
            'default' => null,
        ]);
        $parser->addOption('dry-run', [
            'help' => 'Print the would-be sends and exit without delivering anything or updating the DB.',
            'boolean' => true,
        ]);
        $parser->addOption('encrypt', [
            'help' => 'GPG-encrypt each reminder to the recipient\'s public key via the standard mailer pipeline.',
            'boolean' => true,
        ]);

        return $parser;
    }

    /**
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io IO surface.
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        $thresholds = $this->parseThresholds($args, $io);
        if ($thresholds === null) {
            return static::CODE_ERROR;
        }
        $dryRun = (bool)$args->getOption('dry-run');
        $encrypt = (bool)$args->getOption('encrypt');

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = $this->loadCandidateKeys();

        $attempted = 0;
        $succeeded = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $expiry = $this->resolveExpiry($row);
            if ($expiry === null) {
                $skipped++;
                continue;
            }
            $daysUntil = $this->daysUntil($now, $expiry);
            $crossed = ReminderSweep::computeCrossedThreshold(
                $daysUntil,
                $this->currentThreshold($row),
                $thresholds
            );
            if ($crossed === null) {
                $skipped++;
                continue;
            }

            $io->out(sprintf(
                'individual=%s key_id=%d expires=%s threshold=%d',
                $row->individual->email,
                (int)$row->id,
                $expiry->format(DATE_ATOM),
                $crossed
            ));

            if ($dryRun) {
                continue;
            }

            $attempted++;
            try {
                $this->sendReminder($row, $expiry, $crossed, $encrypt);
                $row->set('last_reminder_threshold', $crossed);
                $this->fetchTable('EncryptionKeys')->saveOrFail($row);
                $succeeded++;
            } catch (\Throwable $e) {
                Log::error(sprintf(
                    'check_expiring_keys: send failed for individual=%s key_id=%d threshold=%d: %s',
                    $row->individual->email,
                    (int)$row->id,
                    $crossed,
                    $e->getMessage()
                ));
                $io->error(sprintf(
                    '  failed: %s (key_id=%d): %s',
                    $row->individual->email,
                    (int)$row->id,
                    $e->getMessage()
                ));
            }
        }

        $io->out(sprintf(
            'Done. attempted=%d sent=%d failed=%d skipped=%d%s',
            $attempted,
            $succeeded,
            $attempted - $succeeded,
            $skipped,
            $dryRun ? ' (dry-run)' : ''
        ));

        if (!$dryRun && $attempted > 0 && $succeeded === 0) {
            return static::CODE_ERROR;
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Parse and validate the `--thresholds` option, falling back to config and then to the PRD default.
     *
     * @param \Cake\Console\Arguments $args CLI arguments.
     * @param \Cake\Console\ConsoleIo $io Used to surface errors.
     * @return array<int, int>|null Parsed positive ints, or null on parse failure.
     */
    protected function parseThresholds(Arguments $args, ConsoleIo $io): ?array
    {
        $raw = $args->getOption('thresholds');
        if ($raw === null || $raw === '') {
            $raw = (string)(Configure::read('Cerebrate.reminders.default_thresholds') ?? '30,7,1');
        }
        $parts = array_filter(array_map('trim', explode(',', (string)$raw)), fn($p) => $p !== '');
        if (empty($parts)) {
            $io->error('--thresholds must contain at least one positive integer.');

            return null;
        }
        $out = [];
        foreach ($parts as $part) {
            if (!ctype_digit($part) || (int)$part <= 0) {
                $io->error(sprintf('--thresholds value "%s" is not a positive integer.', $part));

                return null;
            }
            $out[] = (int)$part;
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * Load individual-owned encryption_keys rows with a reachable contact email.
     *
     * @return array<int, \App\Model\Entity\EncryptionKey>
     */
    protected function loadCandidateKeys(): array
    {
        $rows = $this->fetchTable('EncryptionKeys')
            ->find()
            ->where(['EncryptionKeys.owner_model' => 'individual'])
            ->contain(['Individuals'])
            ->all()
            ->toArray();

        return array_values(array_filter(
            $rows,
            fn($row) => $row->individual !== null && !empty($row->individual->email)
        ));
    }

    /**
     * Resolve the soonest expiry for the given key, honoring the test-only override when set.
     *
     * @param \App\Model\Entity\EncryptionKey $key Row under inspection.
     * @return \DateTimeImmutable|null Null when no usable expiry can be derived (no encryption subkey,
     *     no subkey expiry, GPG unavailable, or material unparseable).
     */
    protected function resolveExpiry(EncryptionKey $key): ?DateTimeImmutable
    {
        if (self::$expiryResolverOverride !== null) {
            return (self::$expiryResolverOverride)($key);
        }

        return $this->parseExpiryWithGpg($key);
    }

    /**
     * Production parse path: run the key blob through Crypt_GPG and return the soonest non-zero expiry
     * among encryption-capable subkeys.
     *
     * @param \App\Model\Entity\EncryptionKey $key Row under inspection.
     * @return \DateTimeImmutable|null
     */
    protected function parseExpiryWithGpg(EncryptionKey $key): ?DateTimeImmutable
    {
        $material = (string)($key->encryption_key ?? '');
        if ($material === '') {
            return null;
        }
        $gpg = $this->getGpg();
        if ($gpg === null) {
            return null;
        }
        try {
            $keys = $gpg->keyInfo($material);
        } catch (\Throwable $e) {
            return null;
        }
        if (count($keys) !== 1) {
            return null;
        }
        $soonest = null;
        foreach ($keys[0]->getSubKeys() as $sub) {
            if (!$sub->canEncrypt()) {
                continue;
            }
            $exp = $sub->getExpirationDate();
            if ($exp === 0) {
                continue;
            }
            if ($soonest === null || $exp < $soonest) {
                $soonest = $exp;
            }
        }
        if ($soonest === null) {
            return null;
        }

        return (new DateTimeImmutable('@' . $soonest))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return \Crypt_GPG|null Null when GPG can't be initialized (skipped silently — every row is then
     *     unparseable and the sweep becomes a no-op).
     */
    protected function getGpg(): ?Crypt_GPG
    {
        try {
            return GpgTool::initializeGpg();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Read the recorded threshold off the entity, coercing DB-driven types into a strict ?int.
     *
     * @param \App\Model\Entity\EncryptionKey $key Row under inspection.
     * @return int|null
     */
    protected function currentThreshold(EncryptionKey $key): ?int
    {
        $value = $key->get('last_reminder_threshold');
        if ($value === null) {
            return null;
        }

        return (int)$value;
    }

    /**
     * Floor-divided days between `now` and `expiry`. Negative when expiry is in the past.
     *
     * @param \DateTimeImmutable $now Reference instant.
     * @param \DateTimeImmutable $expiry Key expiry instant.
     * @return int Days remaining; expressed in 86400-second buckets.
     */
    protected function daysUntil(DateTimeImmutable $now, DateTimeImmutable $expiry): int
    {
        $diff = $expiry->getTimestamp() - $now->getTimestamp();

        return (int)floor($diff / 86400);
    }

    /**
     * Build a `ReminderMailer` for the right reminder type and dispatch it.
     *
     * @param \App\Model\Entity\EncryptionKey $key Row under reminder.
     * @param \DateTimeImmutable $expiry Computed expiry instant.
     * @param int $threshold Crossed threshold value (positive days, or `ReminderSweep::EXPIRED`).
     * @param bool $encrypt Whether to route the send through `GpgMailer::deliverWithGpg`.
     * @return void
     * @throws \App\Lib\Tools\SendEmailException When the send pipeline fails.
     */
    protected function sendReminder(EncryptionKey $key, DateTimeImmutable $expiry, int $threshold, bool $encrypt): void
    {
        $individual = $key->individual;
        $mailer = new ReminderMailer();
        if ($threshold === ReminderSweep::EXPIRED) {
            $mailer->keyExpired($individual, $key, $expiry);
        } else {
            $mailer->keyExpiry($individual, $key, $expiry);
        }

        if ($encrypt) {
            (new GpgMailer())->deliverWithGpg($mailer, $key);
        } else {
            $mailer->deliver();
        }
    }
}
