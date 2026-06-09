<?php

namespace App\Model\Table;

use App\Model\Table\AppTable;
use Cake\ORM\TableRegistry;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use Cake\Event\EventInterface;
use ArrayObject;

class EncryptionKeysTable extends AppTable
{

    public $gpg = null;

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('UUID');
        $this->addBehavior('AuditLog');
        $this->addBehavior('Timestamp');
        $this->belongsTo(
            'Individuals',
            [
                'foreignKey' => 'owner_id',
                'conditions' => ['owner_model' => 'individual']
            ]
        );
        $this->belongsTo(
            'Organisations',
            [
                'foreignKey' => 'owner_id',
                'conditions' => ['owner_model' => 'organisation']
            ]
        );
        $this->setDisplayField('encryption_key');
    }

    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options)
    {
        if (empty($data['owner_id'])) {
            if (empty($data['owner_model'])) {
                return false;
            }
            if (empty($data[$data['owner_model'] . '_id'])) {
                return false;
            }
            $data['owner_id'] = $data[$data['owner_model'] . '_id'];
        }
    }

    /**
     * Reset the reminder cadence whenever the underlying key material changes
     * (new upload, key rotation, expiry extension, etc.). The next sweep run
     * will treat the new blob as a fresh key and re-evaluate thresholds against
     * its current expiry.
     *
     * @param \Cake\Event\EventInterface $event Fired save event.
     * @param \Cake\Datasource\EntityInterface $entity Row being saved.
     * @param \ArrayObject $options Save-time options array.
     * @return void
     */
    public function beforeSave(
        EventInterface $event,
        \Cake\Datasource\EntityInterface $entity,
        ArrayObject $options
    ): void {
        if (!$entity->isNew() && $entity->isDirty('encryption_key')) {
            $entity->set('last_reminder_threshold', null);
        }
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('type')
            ->notEmptyString('encryption_key')
            ->notEmptyString('owner_id')
            ->notEmptyString('owner_model')
            ->requirePresence(['type', 'encryption_key', 'owner_id', 'owner_model'], 'create');
        return $validator;
    }

    /**
     * 0 - true if key is valid
     * 1 - User e-mail
     * 2 - Error message
     * 3 - Not used
     * 4 - Key fingerprint
     * 5 - Key fingerprint
     * @param \App\Model\Entity\EncryptionKey $encryptionKey
     * @return array
     */
    public function verifySingleGPG(\App\Model\Entity\EncryptionKey $encryptionKey): array
    {
        $result = [0 => false, 1 => null];

        $gpg = $this->initializeGpg();
        if (!$gpg) {
            $result[2] = 'GnuPG is not configured on this system.';
            return $result;
        }

        try {
            $currentTimestamp = time();
            $keys = $gpg->keyInfo($encryptionKey['encryption_key']);
            if (count($keys) !== 1) {
                $result[2] = 'Multiple or no key found';
                return $result;
            }

            $key = $keys[0];
            $primaryKey = $key->getPrimaryKey();
            $subKeys = $key->getSubKeys();
            if ($primaryKey === null || empty($subKeys)) {
                // The key was read by GnuPG but parsed into no usable subkeys. This is
                // typically not a Cerebrate issue but the local GnuPG rejecting the key's
                // algorithm or curve - e.g. a Brainpool key on a host in FIPS mode or under
                // a restrictive system-wide crypto policy (common on RHEL). Surface that
                // explicitly instead of the generic "no valid subkey" message (or a fatal
                // on the null primary key).
                $result[2] = __('The PGP key could be read but exposes no usable subkeys. This usually means the local GnuPG installation rejected the key\'s algorithm or curve (for example a Brainpool key on a host running in FIPS mode or under a restrictive system-wide crypto policy). On this host, check it with `gpg --import-options show-only --with-colons --import <keyfile>` and review `update-crypto-policies --show` / FIPS status.');
                return $result;
            }
            $result[4] = $primaryKey->getFingerprint();
            $result[5] = $result[4];

            $sortedKeys = ['valid' => 0, 'expired' => 0, 'noEncrypt' => 0];
            foreach ($subKeys as $subKey) {
                $expiration = $subKey->getExpirationDate();
                if ($expiration != 0 && $currentTimestamp > $expiration) {
                    $sortedKeys['expired']++;
                    continue;
                }
                if (!$subKey->canEncrypt()) {
                    $sortedKeys['noEncrypt']++;
                    continue;
                }
                $sortedKeys['valid']++;
            }
            if (!$sortedKeys['valid']) {
                $result[2] = 'The user\'s PGP key does not include a valid subkey that could be used for encryption.';
                if ($sortedKeys['expired']) {
                    $result[2] .= ' ' . __n(__('Found 1 subkey that has expired.'), __('Found {0} subkeys that have expired.', $sortedKeys['expired']), $sortedKeys['expired']);
                }
                if ($sortedKeys['noEncrypt']) {
                    $result[2] .= ' ' . __n(__('Found 1 subkey that is sign only.'), __('Found {0} subkeys that are sign only.', $sortedKeys['noEncrypt']), $sortedKeys['noEncrypt']);
                }
            } else {
                $result[0] = true;
            }
        } catch (\Exception $e) {
            $result[2] = $e->getMessage();
        }
        return $result;
    }


    /**
     * Initialize GPG. Returns `null` if initialization failed.
     *
     * @return null|CryptGpgExtended
     */
    public function initializeGpg()
    {
        require_once(ROOT . '/src/Lib/Tools/GpgTool.php');
        if ($this->gpg !== null) {
            if ($this->gpg === false) { // initialization failed
                return null;
            }
            return $this->gpg;
        }

        try {
            $this->gpg = \App\Lib\Tools\GpgTool::initializeGpg();
            return $this->gpg;
        } catch (\Exception $e) {
            //$this->logException("GPG couldn't be initialized, GPG encryption and signing will be not available.", $e, LOG_NOTICE);
            $this->gpg = false;
            return null;
        }
    }

    public function canEdit($user, $entity): bool
    {
        if ($entity['owner_model'] === 'organisation') {
            return $this->canEditForOrganisation($user, $entity);
        } else if ($entity['owner_model'] === 'individual') {
            return $this->canEditForIndividual($user, $entity);
        }
        return false;
    }

    public function canEditForOrganisation($user, $entity): bool
    {
        if ($entity['owner_model'] !== 'organisation') {
            return false;
        }
        if (!empty($user['role']['perm_community_admin'])) {
            return true;
        }
        if (
            $user['role']['perm_org_admin'] && 
            $entity['owner_id'] === $user['organisation_id']
        ) {
            return true;
        }
        return false;
    }

    public function canEditForIndividual($user, $entity): bool
    {
        if ($entity['owner_model'] !== 'individual') {
            return false;
        }
        if (!empty($user['role']['perm_community_admin'])) {
            return true;
        }
        if ($user['role']['perm_org_admin']) {
            $this->Alignments = TableRegistry::get('Alignments');
            $validIndividuals = $this->Alignments->find('list', [
                'keyField' => 'individual_id',
                'valueField' => 'id',
                'conditions' => ['organisation_id' => $user['organisation_id']]
            ])->toArray();
            if (isset($validIndividuals[$entity['owner_id']])) {
                return true;
            }
        } else {
            if ($entity['owner_id'] === $user['individual_id']) {
                return true;
            }
        }
        return false;
    }
}
