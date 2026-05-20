<?php
declare(strict_types=1);

namespace App\Test\TestCase\Lib\Tools;

use App\Lib\Tools\EmailRenderer;
use App\Lib\Tools\SendEmailException;
use App\Model\Entity\EncryptionKey;
use App\Model\Entity\Individual;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \App\Lib\Tools\EmailRenderer
 */
class EmailRendererTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function reminderVars(string $dateKey): array
    {
        return [
            'individual' => new Individual([
                'first_name' => 'Alice',
                'last_name' => 'Tester',
                'email' => 'alice@example.org',
            ]),
            'key' => new EncryptionKey(['id' => 42]),
            $dateKey => new DateTimeImmutable('2026-07-04 12:00:00', new DateTimeZone('UTC')),
        ];
    }

    /**
     * @return void
     */
    public function testRendersKeyExpiryTemplate(): void
    {
        $out = (new EmailRenderer())->render('reminder_key_expiry', $this->reminderVars('expiresAt'));

        $this->assertNotNull($out['html']);
        $this->assertNotEmpty(trim($out['text']));
        $this->assertNotNull($out['subject']);
        $this->assertStringContainsString('2026-07-04', (string)$out['subject']);
        $this->assertStringContainsString('Alice', $out['html']);
        $this->assertStringContainsString('Alice', $out['text']);
    }

    /**
     * @return void
     */
    public function testRendersKeyExpiredTemplate(): void
    {
        $out = (new EmailRenderer())->render('reminder_key_expired', $this->reminderVars('expiredAt'));

        $this->assertNotNull($out['html']);
        $this->assertNotEmpty(trim($out['text']));
        $this->assertNotNull($out['subject']);
        $this->assertStringContainsString('expired', strtolower((string)$out['subject']));
        $this->assertStringContainsString('2026-07-04', $out['html']);
    }

    /**
     * @return void
     */
    public function testHtmlIsOptional(): void
    {
        $fixtureDir = ROOT . DS . 'templates' . DS . 'email' . DS . 'text' . DS;
        $fixturePath = $fixtureDir . '_only_text.php';
        file_put_contents($fixturePath, "<?php \$this->set('subject', 'TXT'); ?>\nplain body\n");

        try {
            $out = (new EmailRenderer())->render('_only_text', []);
            $this->assertNull($out['html']);
            $this->assertStringContainsString('plain body', $out['text']);
            $this->assertSame('TXT', $out['subject']);
        } finally {
            unlink($fixturePath);
        }
    }

    /**
     * @return void
     */
    public function testMissingTextTemplateThrows(): void
    {
        $this->expectException(SendEmailException::class);
        (new EmailRenderer())->render('_does_not_exist_anywhere', []);
    }

    /**
     * @return void
     */
    public function testLayoutIsApplied(): void
    {
        $textDir = ROOT . DS . 'templates' . DS . 'email' . DS . 'text' . DS;
        $htmlDir = ROOT . DS . 'templates' . DS . 'email' . DS . 'html' . DS;
        $textPath = $textDir . '_layout_probe.php';
        $htmlPath = $htmlDir . '_layout_probe.php';
        file_put_contents($textPath, "TXT-BODY\n");
        file_put_contents($htmlPath, "<p>HTML-BODY</p>\n");

        try {
            $out = (new EmailRenderer())->render('_layout_probe', []);
            $this->assertStringContainsString('Cerebrate', $out['html']);
            $this->assertStringContainsString('HTML-BODY', $out['html']);
            $this->assertStringContainsString('TXT-BODY', $out['text']);
        } finally {
            if (is_file($textPath)) {
                unlink($textPath);
            }
            if (is_file($htmlPath)) {
                unlink($htmlPath);
            }
        }
    }
}
