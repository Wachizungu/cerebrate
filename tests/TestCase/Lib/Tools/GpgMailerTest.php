<?php
declare(strict_types=1);

namespace App\Test\TestCase\Lib\Tools;

use App\Lib\Tools\GpgMailer;
use App\Lib\Tools\SendEmailException;
use App\Mailer\CerebrateMailer;
use App\Model\Entity\EncryptionKey;
use App\Model\Entity\Individual;
use Cake\Core\Configure;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;
use Crypt_GPG;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \App\Lib\Tools\GpgMailer
 * @covers \App\Mailer\CerebrateMessage
 */
class GpgMailerTest extends TestCase
{
    /**
     * Fixture homedir used by every test in this class.
     *
     * @var string
     */
    protected string $homedir = '';

    /**
     * @var array<string, mixed>
     */
    protected array $savedConfig = [];

    /**
     * Bring the fixture keyring up before each test (idempotent reset).
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->homedir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'Helper'
            . DIRECTORY_SEPARATOR . 'gpg' . DIRECTORY_SEPARATOR . 'keyring';
        if (!is_dir($this->homedir)) {
            $script = dirname($this->homedir) . DIRECTORY_SEPARATOR . 'setup_keyring.sh';
            if (is_file($script)) {
                exec(sprintf('%s 2>&1', escapeshellarg($script)));
            }
        }
        if (!is_dir($this->homedir)) {
            $this->markTestSkipped(
                'GPG fixture keyring missing; run tests/Helper/gpg/setup_keyring.sh first.'
            );
        }

        if (!TransportFactory::getConfig('Debug')) {
            TransportFactory::setConfig('Debug', ['className' => 'Debug']);
        }

        $this->savedConfig = [
            'Cerebrate.email.from' => Configure::read('Cerebrate.email.from'),
            'Cerebrate.email.from_name' => Configure::read('Cerebrate.email.from_name'),
            'Cerebrate.email.disable' => Configure::read('Cerebrate.email.disable'),
            'Cerebrate.email.gpg_sign' => Configure::read('Cerebrate.email.gpg_sign'),
            'Cerebrate.email.gpg_signing_key' => Configure::read('Cerebrate.email.gpg_signing_key'),
            'Cerebrate.email.gpg_signing_passphrase' => Configure::read('Cerebrate.email.gpg_signing_passphrase'),
            'Cerebrate.email.gpg_obscure_subject' => Configure::read('Cerebrate.email.gpg_obscure_subject'),
            'Cerebrate.email.only_encrypted' => Configure::read('Cerebrate.email.only_encrypted'),
            'App.uuid' => Configure::read('App.uuid'),
            'GnuPG.homedir' => Configure::read('GnuPG.homedir'),
            'GnuPG.binary' => Configure::read('GnuPG.binary'),
        ];

        Configure::write('Cerebrate.email.from', 'noreply@cerebrate.test');
        Configure::write('Cerebrate.email.from_name', 'Cerebrate Notifier');
        Configure::write('Cerebrate.email.disable', true);
        Configure::write('Cerebrate.email.gpg_sign', false);
        Configure::write('Cerebrate.email.gpg_signing_key', 'test@cerebrate.test');
        Configure::write('Cerebrate.email.gpg_signing_passphrase', 'cerebrate-test');
        Configure::write('Cerebrate.email.gpg_obscure_subject', false);
        Configure::write('Cerebrate.email.only_encrypted', false);
        Configure::write('App.uuid', 'gpg-test-uuid');
        Configure::write('GnuPG.homedir', $this->homedir);
        Configure::write('GnuPG.binary', '/usr/bin/gpg');
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
     * @return \App\Mailer\CerebrateMailer
     */
    protected function mailerWithTemplate(): CerebrateMailer
    {
        $individual = new Individual([
            'first_name' => 'Alice',
            'last_name' => 'Tester',
            'email' => 'alice@example.org',
        ]);
        $key = new EncryptionKey(['id' => 42]);
        $when = new DateTimeImmutable('2026-07-04 12:00:00', new DateTimeZone('UTC'));

        $mailer = new CerebrateMailer();
        $mailer->setTo('alice@example.org');
        $mailer->viewBuilder()->setTemplate('reminder_key_expiry');
        $mailer->setViewVars([
            'individual' => $individual,
            'key' => $key,
            'expiresAt' => $when,
        ]);

        return $mailer;
    }

    /**
     * @return \App\Model\Entity\EncryptionKey
     */
    protected function recipientKey(): EncryptionKey
    {
        $material = file_get_contents(dirname($this->homedir) . DIRECTORY_SEPARATOR . 'fixture-public.asc');

        return new EncryptionKey(['id' => 1, 'encryption_key' => (string)$material]);
    }

    /**
     * @param \App\Mailer\CerebrateMailer $mailer Mailer whose Message is being inspected.
     * @return string Outer Content-Type header value.
     */
    protected function outerContentType(CerebrateMailer $mailer): string
    {
        $headers = $mailer->getMessage()->getHeaders(['from', 'to', 'subject']);

        return $headers['Content-Type'] ?? '';
    }

    /**
     * @param \App\Mailer\CerebrateMailer $mailer Mailer whose body is being inspected.
     * @return string Joined body.
     */
    protected function bodyString(CerebrateMailer $mailer): string
    {
        return implode("\r\n", $mailer->getMessage()->getBody());
    }

    /**
     * @return void
     */
    public function testSignOnlyProducesMultipartSigned(): void
    {
        Configure::write('Cerebrate.email.gpg_sign', true);

        $mailer = $this->mailerWithTemplate();
        $result = (new GpgMailer())->deliverWithGpg($mailer, null);

        $this->assertTrue($result['signed']);
        $this->assertFalse($result['encrypted']);

        $ct = $this->outerContentType($mailer);
        $this->assertStringContainsString('multipart/signed', $ct);
        $this->assertStringContainsString('protocol="application/pgp-signature"', $ct);
        $this->assertMatchesRegularExpression('/micalg=pgp-[a-z0-9]+/', $ct);

        $body = $this->bodyString($mailer);
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $body);
        $this->assertStringContainsString('-----END PGP SIGNATURE-----', $body);
        $this->assertStringContainsString('protected-headers="v1"', $body);
    }

    /**
     * @return void
     */
    public function testEncryptOnlyProducesMultipartEncryptedAndDecrypts(): void
    {
        $mailer = $this->mailerWithTemplate();
        $result = (new GpgMailer())->deliverWithGpg($mailer, $this->recipientKey());

        $this->assertFalse($result['signed']);
        $this->assertTrue($result['encrypted']);

        $ct = $this->outerContentType($mailer);
        $this->assertStringContainsString('multipart/encrypted', $ct);
        $this->assertStringContainsString('protocol="application/pgp-encrypted"', $ct);

        $body = $this->bodyString($mailer);
        $this->assertStringContainsString('-----BEGIN PGP MESSAGE-----', $body);

        // The encrypted blob must decrypt back to the rendered inner body.
        $this->assertSame(
            1,
            preg_match('/-----BEGIN PGP MESSAGE-----.*?-----END PGP MESSAGE-----/s', $body, $matches),
            'Expected a PGP message block in the body'
        );
        $gpg = new Crypt_GPG(['homedir' => $this->homedir, 'binary' => '/usr/bin/gpg']);
        $gpg->addDecryptKey('test@cerebrate.test', 'cerebrate-test');
        $decrypted = $gpg->decrypt($matches[0]);
        $this->assertStringContainsString('multipart/alternative', $decrypted);
        $this->assertStringContainsString('Alice', $decrypted);
        $this->assertStringContainsString('2026-07-04', $decrypted);
    }

    /**
     * @return void
     */
    public function testSignAndEncryptProducesNestedEnvelope(): void
    {
        Configure::write('Cerebrate.email.gpg_sign', true);

        $mailer = $this->mailerWithTemplate();
        $result = (new GpgMailer())->deliverWithGpg($mailer, $this->recipientKey());

        $this->assertTrue($result['signed']);
        $this->assertTrue($result['encrypted']);

        $ct = $this->outerContentType($mailer);
        $this->assertStringContainsString('multipart/encrypted', $ct);

        // Decrypt and confirm the inner envelope is multipart/signed.
        $body = $this->bodyString($mailer);
        preg_match('/-----BEGIN PGP MESSAGE-----.*?-----END PGP MESSAGE-----/s', $body, $matches);
        $gpg = new Crypt_GPG(['homedir' => $this->homedir, 'binary' => '/usr/bin/gpg']);
        $gpg->addDecryptKey('test@cerebrate.test', 'cerebrate-test');
        $decrypted = $gpg->decrypt($matches[0]);
        $this->assertStringContainsString('multipart/signed', $decrypted);
        $this->assertStringContainsString('-----BEGIN PGP SIGNATURE-----', $decrypted);
    }

    /**
     * @return void
     */
    public function testOnlyEncryptedWithNoRecipientKeyThrows(): void
    {
        Configure::write('Cerebrate.email.only_encrypted', true);

        $this->expectException(SendEmailException::class);
        $this->expectExceptionMessageMatches('/only_encrypted/');

        (new GpgMailer())->deliverWithGpg($this->mailerWithTemplate(), null);
    }

    /**
     * @return void
     */
    public function testObscureSubjectWhenSignedAndEncrypted(): void
    {
        Configure::write('Cerebrate.email.gpg_sign', true);
        Configure::write('Cerebrate.email.gpg_obscure_subject', true);

        $mailer = $this->mailerWithTemplate();
        $result = (new GpgMailer())->deliverWithGpg($mailer, $this->recipientKey());

        $this->assertSame('...', $result['subject']);
        $this->assertSame('...', $mailer->getMessage()->getSubject());
    }

    /**
     * @return void
     */
    public function testObscureSubjectIgnoredWhenOnlyEncrypted(): void
    {
        Configure::write('Cerebrate.email.gpg_sign', false);
        Configure::write('Cerebrate.email.gpg_obscure_subject', true);

        $mailer = $this->mailerWithTemplate();
        $result = (new GpgMailer())->deliverWithGpg($mailer, $this->recipientKey());

        $this->assertNotSame('...', $result['subject']);
        $this->assertStringContainsString('2026-07-04', $result['subject']);
    }

    /**
     * Without auto-set ownertrust on the freshly-imported recipient key,
     * Crypt_GPG hangs forever waiting on `untrusted_key.override`. This
     * test runs the import against a fresh, empty homedir and asserts the
     * ownertrust DB has been populated with the recipient's fingerprint.
     *
     * @return void
     */
    public function testImportRecipientKeyAutoSetsOwnerTrust(): void
    {
        $freshHome = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'cerebrate-gpgmailer-fresh-' . bin2hex(random_bytes(4));
        if (!mkdir($freshHome, 0700, true)) {
            $this->fail('Could not create temp homedir for test');
        }
        Configure::write('GnuPG.homedir', $freshHome);

        try {
            $mailer = new GpgMailer();
            $method = new \ReflectionMethod($mailer, 'importAndValidateRecipientKey');
            $method->setAccessible(true);
            $fingerprint = $method->invoke($mailer, $this->recipientKey());

            $this->assertNotNull($fingerprint, 'Expected the recipient key to validate and import');

            $trustOutput = shell_exec(sprintf(
                '/usr/bin/gpg --homedir %s --export-ownertrust 2>/dev/null',
                escapeshellarg($freshHome)
            ));
            $this->assertNotNull($trustOutput);
            $this->assertStringContainsString(
                sprintf('%s:6:', $fingerprint),
                (string)$trustOutput,
                'Expected ownertrust=6 to be set on the imported recipient key'
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($freshHome));
        }
    }
}
