<?php
declare(strict_types=1);

namespace App\Test\TestCase\Mailer;

use App\Lib\Tools\SendEmailException;
use App\Mailer\CerebrateMailer;
use App\Mailer\ReminderMailer;
use App\Model\Entity\EncryptionKey;
use App\Model\Entity\Individual;
use Cake\Core\Configure;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \App\Mailer\CerebrateMailer
 * @covers \App\Mailer\ReminderMailer
 */
class CerebrateMailerTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    protected array $savedConfig = [];

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        if (!TransportFactory::getConfig('Debug')) {
            TransportFactory::setConfig('Debug', ['className' => 'Debug']);
        }
        $this->savedConfig = [
            'Cerebrate.email.from' => Configure::read('Cerebrate.email.from'),
            'Cerebrate.email.from_name' => Configure::read('Cerebrate.email.from_name'),
            'Cerebrate.email.reply_to' => Configure::read('Cerebrate.email.reply_to'),
            'Cerebrate.email.disable' => Configure::read('Cerebrate.email.disable'),
            'App.uuid' => Configure::read('App.uuid'),
        ];

        Configure::write('Cerebrate.email.from', 'noreply@cerebrate.test');
        Configure::write('Cerebrate.email.from_name', 'Cerebrate Notifier');
        Configure::write('Cerebrate.email.reply_to', 'ops@cerebrate.test');
        Configure::write('Cerebrate.email.disable', true);
        Configure::write('App.uuid', 'fixture-uuid-0001');
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        foreach ($this->savedConfig as $key => $value) {
            Configure::write($key, $value);
        }
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testConstructorReadsFromFromNameAndReplyTo(): void
    {
        $mailer = new CerebrateMailer();
        $msg = $mailer->getMessage();

        $this->assertSame(
            ['noreply@cerebrate.test' => 'Cerebrate Notifier'],
            $msg->getFrom()
        );
        $this->assertSame(
            ['ops@cerebrate.test' => 'ops@cerebrate.test'],
            $msg->getReplyTo()
        );
    }

    /**
     * @return void
     */
    public function testDisableForcesDebugTransport(): void
    {
        $mailer = new CerebrateMailer();
        $this->assertInstanceOf(
            \Cake\Mailer\Transport\DebugTransport::class,
            $mailer->getTransport()
        );
    }

    /**
     * @return void
     */
    public function testWithReferenceIsDeterministic(): void
    {
        $a = (new CerebrateMailer())->withReference('key:42');
        $b = (new CerebrateMailer())->withReference('key:42');
        $headersA = $a->getMessage()->getHeaders(['_headers']);
        $headersB = $b->getMessage()->getHeaders(['_headers']);

        $this->assertNotEmpty($headersA['In-Reply-To']);
        $this->assertNotEmpty($headersA['References']);
        $this->assertSame($headersA['In-Reply-To'], $headersA['References']);
        $this->assertSame($headersA['In-Reply-To'], $headersB['In-Reply-To']);
    }

    /**
     * @return void
     */
    public function testWithReferenceUsesShaOfReferenceIdAndAppUuid(): void
    {
        $expected = '<' . sha1('key:42|fixture-uuid-0001') . '@cerebrate.test>';
        $mailer = (new CerebrateMailer())->withReference('key:42');
        $headers = $mailer->getMessage()->getHeaders(['_headers']);

        $this->assertSame($expected, $headers['In-Reply-To']);
    }

    /**
     * @return void
     */
    public function testDeliverThrowsWhenFromUnconfigured(): void
    {
        Configure::write('Cerebrate.email.from', '');
        $mailer = new CerebrateMailer();
        $mailer->setTo('alice@example.org')->setSubject('x');

        $this->expectException(SendEmailException::class);
        $mailer->deliver('body');
    }

    /**
     * @return void
     */
    public function testDeliverStampsMessageIdAndDate(): void
    {
        $mailer = new CerebrateMailer();
        $mailer->setTo('alice@example.org')->setSubject('hi');
        $result = $mailer->deliver('body');

        $this->assertMatchesRegularExpression('/^Message-ID: <[^@]+@cerebrate\.test>/m', $result['headers']);
        $this->assertMatchesRegularExpression('/^Date: .+ [+-]\d{4}\r?$/m', $result['headers']);
    }

    /**
     * @return void
     */
    public function testReminderMailerKeyExpiryDelivers(): void
    {
        $ind = new Individual([
            'first_name' => 'Alice',
            'last_name' => 'Tester',
            'email' => 'alice@example.org',
        ]);
        $key = new EncryptionKey(['id' => 42]);
        $when = new DateTimeImmutable('2026-07-04 12:00:00', new DateTimeZone('UTC'));

        $mailer = new ReminderMailer();
        $mailer->keyExpiry($ind, $key, $when);
        $result = $mailer->deliver();

        $this->assertMatchesRegularExpression('/^To: alice@example\.org/m', $result['headers']);
        $this->assertMatchesRegularExpression(
            '/^Subject: Your GPG key expires on 2026-07-04/m',
            $result['headers']
        );
        $this->assertStringContainsString('Alice', $result['message']);
        $this->assertStringContainsString('2026-07-04', $result['message']);
        $this->assertMatchesRegularExpression('/^In-Reply-To: <[^>]+@cerebrate\.test>/m', $result['headers']);
    }

    /**
     * @return void
     */
    public function testReminderMailerKeyExpiredDelivers(): void
    {
        $ind = new Individual([
            'first_name' => 'Bob',
            'last_name' => 'Builder',
            'email' => 'bob@example.org',
        ]);
        $key = new EncryptionKey(['id' => 99]);
        $when = new DateTimeImmutable('2026-04-01 09:30:00', new DateTimeZone('UTC'));

        $mailer = new ReminderMailer();
        $mailer->keyExpired($ind, $key, $when);
        $result = $mailer->deliver();

        $this->assertMatchesRegularExpression('/^To: bob@example\.org/m', $result['headers']);
        $this->assertMatchesRegularExpression(
            '/^Subject: Your GPG key expired on 2026-04-01/m',
            $result['headers']
        );
        $this->assertStringContainsString('Bob', $result['message']);
        $this->assertStringContainsString('2026-04-01', $result['message']);
    }
}
