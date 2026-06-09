<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use App\Command\CheckExpiringKeysCommand;
use App\Lib\Tools\ReminderSweep;
use App\Model\Entity\EncryptionKey;
use App\Test\Fixture\EncryptionKeysFixture;
use App\Test\Fixture\IndividualsFixture;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * @covers \App\Command\CheckExpiringKeysCommand
 */
class CheckExpiringKeysCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use EmailTrait;

    protected $fixtures = [
        'app.Organisations',
        'app.Individuals',
        'app.EncryptionKeys',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $savedConfig = [];

    /**
     * @var int Seeded encryption_keys row id for the individual recipient.
     */
    protected int $individualKeyId;

    public function setUp(): void
    {
        parent::setUp();
        $this->useCommandRunner();

        if (!Log::getConfig('error')) {
            Log::setConfig('error', ['className' => 'Array']);
        }

        $this->savedConfig = [
            'Cerebrate.email.from' => Configure::read('Cerebrate.email.from'),
            'Cerebrate.email.from_name' => Configure::read('Cerebrate.email.from_name'),
            'Cerebrate.email.disable' => Configure::read('Cerebrate.email.disable'),
            'Cerebrate.reminders.default_thresholds' =>
                Configure::read('Cerebrate.reminders.default_thresholds'),
            'App.uuid' => Configure::read('App.uuid'),
        ];
        Configure::write('Cerebrate.email.from', 'noreply@cerebrate.test');
        Configure::write('Cerebrate.email.from_name', 'Cerebrate Notifier');
        Configure::write('Cerebrate.email.disable', false);
        Configure::write('Cerebrate.reminders.default_thresholds', '30,7,1');
        Configure::write('App.uuid', 'fixture-uuid-sweep');

        $this->individualKeyId = $this->seedIndividualKey();
    }

    public function tearDown(): void
    {
        CheckExpiringKeysCommand::$expiryResolverOverride = null;
        foreach ($this->savedConfig as $key => $value) {
            Configure::write($key, $value);
        }
        parent::tearDown();
    }

    public function testDryRunPrintsAndChangesNothing(): void
    {
        $this->stubExpiryDaysFromNow(6);

        $this->exec('check_expiring_keys --dry-run');
        $this->assertExitSuccess();
        $this->assertOutputContains('threshold=7');
        $this->assertOutputContains('(dry-run)');
        $this->assertNoMailSent();

        $row = $this->loadKey();
        $this->assertNull(
            $row->get('last_reminder_threshold'),
            'Dry-run must not advance last_reminder_threshold.'
        );
    }

    public function testLiveRunSendsAndAdvancesThreshold(): void
    {
        $this->stubExpiryDaysFromNow(6);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertOutputContains('threshold=7');
        $this->assertOutputContains('sent=1');
        $this->assertMailCount(1);
        $this->assertMailSubjectContains('Your GPG key expires on');

        $row = $this->loadKey();
        $this->assertSame(7, $row->get('last_reminder_threshold'));
    }

    public function testSecondRunIsNoop(): void
    {
        $this->stubExpiryDaysFromNow(6);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertMailCount(1);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertOutputContains('attempted=0');
        $this->assertMailCount(1, 'No second send for the same threshold bucket.');
    }

    public function testExpiredKeyTriggersExpiredMail(): void
    {
        $this->stubExpiryDaysFromNow(-3);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertOutputContains(sprintf('threshold=%d', ReminderSweep::EXPIRED));
        $this->assertMailCount(1);
        $this->assertMailSubjectContains('Your GPG key expired on');

        $row = $this->loadKey();
        $this->assertSame(ReminderSweep::EXPIRED, $row->get('last_reminder_threshold'));
    }

    public function testKeyOutsideCadenceIsSkipped(): void
    {
        $this->stubExpiryDaysFromNow(60);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertOutputContains('attempted=0');
        $this->assertOutputContains('skipped=1');
        $this->assertNoMailSent();

        $row = $this->loadKey();
        $this->assertNull($row->get('last_reminder_threshold'));
    }

    public function testInvalidThresholdsExitsWithError(): void
    {
        $this->stubExpiryDaysFromNow(6);

        $this->exec('check_expiring_keys --thresholds=foo');
        $this->assertExitError();
        $this->assertErrorContains('positive integer');
    }

    public function testThresholdOverrideUsesProvidedCadence(): void
    {
        $this->stubExpiryDaysFromNow(20);

        $this->exec('check_expiring_keys --thresholds=14,3 --dry-run');
        $this->assertExitSuccess();
        $this->assertOutputContains('skipped=1');
        $this->assertOutputContains('attempted=0');
    }

    public function testMultipleKeysForOneIndividualCollapseToOneDigest(): void
    {
        $secondKeyId = $this->seedKeyFor(IndividualsFixture::INDIVIDUAL_A_ID);
        $this->stubExpiryDaysFromNow(6);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertOutputContains('sent=1');
        $this->assertMailCount(1);
        $this->assertMailSubjectContains('GPG key reminders (2 keys)');

        $this->assertSame(7, $this->loadKeyById($this->individualKeyId)->get('last_reminder_threshold'));
        $this->assertSame(7, $this->loadKeyById($secondKeyId)->get('last_reminder_threshold'));
    }

    public function testTwoIndividualsEachReceiveTheirOwnDigest(): void
    {
        $bKeyId = $this->seedKeyFor(IndividualsFixture::INDIVIDUAL_B_ID);
        $this->stubExpiryDaysFromNow(6);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertOutputContains('sent=2');
        $this->assertMailCount(2);

        $this->assertSame(7, $this->loadKeyById($this->individualKeyId)->get('last_reminder_threshold'));
        $this->assertSame(7, $this->loadKeyById($bKeyId)->get('last_reminder_threshold'));
    }

    public function testMixedExpiringAndExpiredCollapseToOneDigest(): void
    {
        $expiredKeyId = $this->seedKeyFor(IndividualsFixture::INDIVIDUAL_A_ID);
        $this->stubExpiryByKeyId([
            $this->individualKeyId => 6,
            $expiredKeyId => -3,
        ]);

        $this->exec('check_expiring_keys');
        $this->assertExitSuccess();
        $this->assertMailCount(1);
        $this->assertMailSubjectContains('GPG key reminders (2 keys)');
        $this->assertMailContains('EXPIRED on');
        $this->assertMailContains('expires on');

        $this->assertSame(7, $this->loadKeyById($this->individualKeyId)->get('last_reminder_threshold'));
        $this->assertSame(
            ReminderSweep::EXPIRED,
            $this->loadKeyById($expiredKeyId)->get('last_reminder_threshold')
        );
    }

    /**
     * Configure the test-only resolver so the seeded key reports a deterministic expiry.
     *
     * @param int $days Days from now; negative for already-expired.
     */
    protected function stubExpiryDaysFromNow(int $days): void
    {
        $offsetSeconds = $days * 86400;
        CheckExpiringKeysCommand::$expiryResolverOverride =
            static function (EncryptionKey $_key) use ($offsetSeconds): ?DateTimeImmutable {
                return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->modify(sprintf('%+d seconds', $offsetSeconds));
            };
    }

    /**
     * Configure the resolver to report distinct expiries keyed by encryption_keys row id.
     * Keys absent from the map resolve to null (treated as unparseable → skipped).
     *
     * @param array<int, int> $daysByKeyId Map of key id to days-from-now (negative = already expired).
     */
    protected function stubExpiryByKeyId(array $daysByKeyId): void
    {
        CheckExpiringKeysCommand::$expiryResolverOverride =
            static function (EncryptionKey $key) use ($daysByKeyId): ?DateTimeImmutable {
                $id = (int)$key->id;
                if (!array_key_exists($id, $daysByKeyId)) {
                    return null;
                }

                return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                    ->modify(sprintf('%+d seconds', $daysByKeyId[$id] * 86400));
            };
    }

    /**
     * Seed an individual-owned encryption_keys row pointing at the setUp recipient (Individual A).
     *
     * @return int Row id of the inserted EncryptionKey.
     */
    protected function seedIndividualKey(): int
    {
        return $this->seedKeyFor(IndividualsFixture::INDIVIDUAL_A_ID);
    }

    /**
     * Seed an individual-owned encryption_keys row for the given individual.
     *
     * @param int $individualId Owning individual id.
     * @return int Row id of the inserted EncryptionKey.
     */
    protected function seedKeyFor(int $individualId): int
    {
        $faker = \Faker\Factory::create();
        $table = TableRegistry::getTableLocator()->get('EncryptionKeys');
        $entity = $table->newEntity([
            'uuid' => $faker->uuid(),
            'type' => EncryptionKeysFixture::TYPE_PGP,
            'encryption_key' => EncryptionKeysFixture::getPublicKey(EncryptionKeysFixture::KEY_TYPE_EDCH),
            'revoked' => false,
            'expires' => null,
            'owner_id' => $individualId,
            'owner_model' => 'individual',
        ]);
        $table->saveOrFail($entity);

        return (int)$entity->id;
    }

    /**
     * Reload the setUp-seeded EncryptionKey row.
     */
    protected function loadKey(): EncryptionKey
    {
        return $this->loadKeyById($this->individualKeyId);
    }

    /**
     * Reload an EncryptionKey row by id.
     *
     * @param int $id Row id.
     * @return \App\Model\Entity\EncryptionKey
     */
    protected function loadKeyById(int $id): EncryptionKey
    {
        return TableRegistry::getTableLocator()
            ->get('EncryptionKeys')
            ->get($id);
    }
}
