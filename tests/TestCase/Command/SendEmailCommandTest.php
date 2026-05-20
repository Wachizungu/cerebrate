<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\TestCase;

/**
 * @covers \App\Command\SendEmailCommand
 */
class SendEmailCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

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
        $this->useCommandRunner();

        if (!TransportFactory::getConfig('Debug')) {
            TransportFactory::setConfig('Debug', ['className' => 'Debug']);
        }
        // Some test environments lack a log scope; make sure one exists so
        // Log::info/error don't blow up on a fresh process.
        if (!Log::getConfig('error')) {
            Log::setConfig('error', ['className' => 'Array']);
        }

        $this->savedConfig = [
            'Cerebrate.email.from' => Configure::read('Cerebrate.email.from'),
            'Cerebrate.email.from_name' => Configure::read('Cerebrate.email.from_name'),
            'Cerebrate.email.disable' => Configure::read('Cerebrate.email.disable'),
            'App.uuid' => Configure::read('App.uuid'),
        ];
        Configure::write('Cerebrate.email.from', 'noreply@cerebrate.test');
        Configure::write('Cerebrate.email.from_name', 'Cerebrate Notifier');
        Configure::write('Cerebrate.email.disable', true);
        Configure::write('App.uuid', 'fixture-uuid-cli');
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
    public function testSendsToRawAddress(): void
    {
        $this->exec(
            'send_email --to=alice@example.org --template=reminder_key_expiry'
        );
        $this->assertExitSuccess();
        $this->assertOutputContains('Sent: <');
        $this->assertOutputRegExp('/Sent: <[^>]+@cerebrate\.test>/');
    }

    /**
     * @return void
     */
    public function testMissingTemplateExitsNonZero(): void
    {
        $this->exec('send_email --to=alice@example.org');
        $this->assertExitError();
    }

    /**
     * @return void
     */
    public function testMissingToExitsNonZero(): void
    {
        $this->exec('send_email --template=reminder_key_expiry');
        $this->assertExitError();
    }

    /**
     * @return void
     */
    public function testUnconfiguredFromExitsWithErrorMessage(): void
    {
        Configure::write('Cerebrate.email.from', '');
        $this->exec(
            'send_email --to=alice@example.org --template=reminder_key_expiry'
        );
        $this->assertExitError();
        $this->assertErrorContains('Cerebrate.email.from');
    }

    /**
     * @return void
     */
    public function testEncryptWithRawAddressExitsWithError(): void
    {
        $this->exec(
            'send_email --to=raw-no-individual@example.org --template=reminder_key_expiry --encrypt'
        );
        $this->assertExitError();
        $this->assertErrorContains('Individual');
    }

    /**
     * @return void
     */
    public function testMalformedVarOptionExitsWithError(): void
    {
        $this->exec(
            'send_email --to=alice@example.org --template=reminder_key_expiry --var notKeyValue'
        );
        $this->assertExitError();
        $this->assertErrorContains('key=value');
    }
}
