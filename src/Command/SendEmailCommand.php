<?php
declare(strict_types=1);

namespace App\Command;

use App\Lib\Tools\EmailRenderer;
use App\Lib\Tools\GpgMailer;
use App\Lib\Tools\SendEmailException;
use App\Mailer\CerebrateMailer;
use App\Model\Entity\EncryptionKey;
use App\Model\Entity\Individual;
use Cake\Console\Arguments;
use Cake\Console\Command;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Log\Log;
use Cake\Mailer\Message;

/**
 * Operator-facing CLI for sending a Cerebrate-templated mail.
 *
 * Useful for manual reminders and for smoke-testing the mailer
 * subsystem from a shell. The scheduled reminder sweep is a separate
 * command, not built here.
 */
class SendEmailCommand extends Command
{
    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Parser to extend.
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('Send a Cerebrate templated email to one recipient.');
        $parser->addOption('to', [
            'help' => 'Recipient email. If matched to a known Individual, that entity is exposed in view vars.',
            'required' => true,
        ]);
        $parser->addOption('template', [
            'help' => 'Template stem under templates/email/{html,text}/ (e.g. reminder_key_expiry).',
            'required' => true,
        ]);
        $parser->addOption('var', [
            'help' => 'View variable in key=value form. Repeat for multiple variables.',
            'multiple' => true,
        ]);
        $parser->addOption('reference', [
            'help' => 'Threading reference id (e.g. "key:42") used for In-Reply-To / References headers.',
            'default' => null,
        ]);
        $parser->addOption('encrypt', [
            'help' => 'Encrypt the message to the recipient Individual\'s GPG public key. '
                . 'Requires --to to match a known Individual.',
            'boolean' => true,
        ]);

        return $parser;
    }

    /**
     * @param \Cake\Console\Arguments $args CLI arguments and options.
     * @param \Cake\Console\ConsoleIo $io IO surface for output / errors.
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io)
    {
        $to = (string)$args->getOption('to');
        $template = (string)$args->getOption('template');

        $rawVars = $args->getOption('var');
        if (!is_array($rawVars)) {
            $rawVars = $rawVars === null || $rawVars === '' ? [] : [(string)$rawVars];
        }
        $vars = $this->parseVarOptions($rawVars, $io);
        if ($vars === null) {
            return static::CODE_ERROR;
        }

        $encrypt = (bool)$args->getOption('encrypt');
        $individual = $this->findIndividual($to);
        if ($individual !== null) {
            $vars['individual'] = $individual;
        }
        if ($encrypt && $individual === null) {
            $io->error(
                '--encrypt requires --to to match a known Individual; '
                . 'no encryption key available for raw addresses.'
            );

            return static::CODE_ERROR;
        }

        try {
            $mailer = new CerebrateMailer();
            $mailer->setTo($to);
            $reference = $args->getOption('reference');
            if (is_string($reference) && $reference !== '') {
                $mailer->withReference($reference);
            }

            if ($encrypt) {
                $recipientKey = $this->findEncryptionKey($individual);
                if ($recipientKey === null) {
                    throw new SendEmailException(
                        'No usable GPG encryption key found for the recipient Individual.'
                    );
                }
                $mailer->viewBuilder()->setTemplate($template);
                $mailer->setEmailFormat(Message::MESSAGE_BOTH);
                $mailer->setViewVars($vars);
                $telemetry = (new GpgMailer())->deliverWithGpg($mailer, $recipientKey);
                $messageId = $telemetry['message_id'];
            } else {
                $renderer = new EmailRenderer();
                $rendered = $renderer->render($template, $vars);

                if ($rendered['subject'] !== null && $rendered['subject'] !== '') {
                    $mailer->setSubject($rendered['subject']);
                } else {
                    $mailer->setSubject(sprintf('[Cerebrate] %s', $template));
                }
                $mailer->setRenderedBody($rendered['text'], $rendered['html']);
                $mailer->deliver();
                $messageId = (string)$mailer->getMessage()->getMessageId();
            }

            Log::info(sprintf(
                'send_email CLI delivered template=%s to=%s message_id=%s',
                $template,
                $to,
                $messageId
            ));
            $io->out(sprintf('Sent: %s', $messageId));

            return static::CODE_SUCCESS;
        } catch (SendEmailException $e) {
            Log::error(sprintf('send_email CLI failed: %s', $e->getMessage()));
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }
    }

    /**
     * Parse `--var key=value` options into an associative array.
     *
     * @param array<int, string> $raw Raw values supplied to `--var`.
     * @param \Cake\Console\ConsoleIo $io For surfacing parse errors.
     * @return array<string, string>|null Parsed map, or null when malformed.
     */
    protected function parseVarOptions(array $raw, ConsoleIo $io): ?array
    {
        $vars = [];
        foreach ($raw as $entry) {
            $entry = (string)$entry;
            $eq = strpos($entry, '=');
            if ($eq === false || $eq === 0) {
                $io->error(sprintf('Invalid --var value "%s"; expected key=value form.', $entry));

                return null;
            }
            $key = substr($entry, 0, $eq);
            $value = substr($entry, $eq + 1);
            $vars[$key] = $value;
        }

        return $vars;
    }

    /**
     * Load the first available `pgp` encryption key for the given individual.
     *
     * @param \App\Model\Entity\Individual $individual Recipient.
     * @return \App\Model\Entity\EncryptionKey|null
     */
    protected function findEncryptionKey(Individual $individual): ?EncryptionKey
    {
        try {
            $keys = $this->fetchTable('EncryptionKeys');
            /** @var \App\Model\Entity\EncryptionKey|null $entity */
            $entity = $keys->find()
                ->where([
                    'owner_model' => 'individual',
                    'owner_id' => $individual->id,
                ])
                ->first();
        } catch (\Throwable $e) {
            return null;
        }

        return $entity;
    }

    /**
     * Hydrate an Individual by email, if one exists.
     *
     * Best-effort: any failure to reach the table (e.g. DB unavailable in a
     * test process) is swallowed and the address is treated as raw.
     *
     * @param string $email Address to match against Individual.email.
     * @return \App\Model\Entity\Individual|null
     */
    protected function findIndividual(string $email): ?Individual
    {
        if ($email === '') {
            return null;
        }

        try {
            /** @var \App\Model\Table\IndividualsTable $individuals */
            $individuals = $this->fetchTable('Individuals');
            /** @var \App\Model\Entity\Individual|null $entity */
            $entity = $individuals->find()->where(['email' => $email])->first();
        } catch (\Throwable $e) {
            return null;
        }

        return $entity;
    }
}
