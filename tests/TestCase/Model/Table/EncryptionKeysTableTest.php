<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Test\Fixture\EncryptionKeysFixture;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * @covers \App\Model\Table\EncryptionKeysTable
 */
class EncryptionKeysTableTest extends TestCase
{
    protected $fixtures = [
        'app.Organisations',
        'app.Individuals',
        'app.EncryptionKeys',
    ];

    /**
     * @var \App\Model\Table\EncryptionKeysTable
     */
    protected $EncryptionKeys;

    public function setUp(): void
    {
        parent::setUp();
        TableRegistry::getTableLocator()->clear();
        /** @var \App\Model\Table\EncryptionKeysTable $table */
        $table = TableRegistry::getTableLocator()->get('EncryptionKeys');
        $this->EncryptionKeys = $table;
    }

    public function testBeforeSaveResetsLastReminderThresholdOnKeyReplacement(): void
    {
        $entity = $this->EncryptionKeys->get(EncryptionKeysFixture::ENCRYPTION_KEY_ORG_A_ID);
        $entity->set('last_reminder_threshold', 7);
        $this->EncryptionKeys->saveOrFail($entity);

        $reloaded = $this->EncryptionKeys->get(EncryptionKeysFixture::ENCRYPTION_KEY_ORG_A_ID);
        $this->assertSame(7, $reloaded->get('last_reminder_threshold'), 'Pre-condition: column persisted.');

        $reloaded->set('encryption_key', $reloaded->get('encryption_key') . "\n");
        $this->EncryptionKeys->saveOrFail($reloaded);

        $afterReplace = $this->EncryptionKeys->get(EncryptionKeysFixture::ENCRYPTION_KEY_ORG_A_ID);
        $this->assertNull(
            $afterReplace->get('last_reminder_threshold'),
            'beforeSave should null the column when encryption_key is dirty.'
        );
    }

    public function testBeforeSaveLeavesThresholdAloneWhenOnlyOtherFieldsChange(): void
    {
        $entity = $this->EncryptionKeys->get(EncryptionKeysFixture::ENCRYPTION_KEY_ORG_A_ID);
        $entity->set('last_reminder_threshold', 30);
        $this->EncryptionKeys->saveOrFail($entity);

        $reloaded = $this->EncryptionKeys->get(EncryptionKeysFixture::ENCRYPTION_KEY_ORG_A_ID);
        $reloaded->set('revoked', true);
        $this->EncryptionKeys->saveOrFail($reloaded);

        $afterUnrelated = $this->EncryptionKeys->get(EncryptionKeysFixture::ENCRYPTION_KEY_ORG_A_ID);
        $this->assertSame(
            30,
            $afterUnrelated->get('last_reminder_threshold'),
            'Unrelated field updates must not disturb the reminder cadence.'
        );
    }

    public function testBeforeSaveDoesNotResetOnInsert(): void
    {
        $faker = \Faker\Factory::create();
        $new = $this->EncryptionKeys->newEntity([
            'uuid' => $faker->uuid(),
            'type' => EncryptionKeysFixture::TYPE_PGP,
            'encryption_key' => EncryptionKeysFixture::getPublicKey(EncryptionKeysFixture::KEY_TYPE_EDCH),
            'revoked' => false,
            'expires' => null,
            'owner_id' => 1,
            'owner_model' => 'Organisation',
            'last_reminder_threshold' => 30,
        ]);
        $this->EncryptionKeys->saveOrFail($new);

        $reloaded = $this->EncryptionKeys->get($new->id);
        $this->assertSame(
            30,
            $reloaded->get('last_reminder_threshold'),
            'Inserting a fresh row with a pre-set threshold must not be clobbered by the hook.'
        );
    }
}
