<?php
namespace App\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\Utility\Text;
use Cake\Utility\Security;
use Cake\Utility\Hash;
use \Cake\Http\Session;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\FormData;
use Cake\Http\Exception\NotFoundException;
use Cake\Utility\Inflector;

class AuthKeycloakBehavior extends Behavior
{

    public function getMappedFieldList(): array
    {
        $mappers = Configure::read('keycloak.user_meta_mapping');
        if (empty($mappers)) {
            return [];
        }
        return explode(',', $mappers);
    }

    /*
     * Parse the keycloak.org_meta_mapping setting into a [field => attribute] map.
     * Each comma separated entry is either a plain meta-field name (the keycloak
     * attribute name is then derived by sanitising the field name) or an explicit
     * "field=attribute" pair, allowing field names that aren't keycloak-safe (e.g.
     * "ISO 3166-1 Code") to be exposed under a clean attribute/claim name. The
     * resulting attribute is always namespaced with an "org_" prefix so it cannot
     * collide with a user meta-field attribute that happens to share the name.
     */
    public function getMappedOrgFields(): array
    {
        $raw = Configure::read('keycloak.org_meta_mapping');
        if (empty($raw)) {
            return [];
        }
        $result = [];
        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '=') !== false) {
                [$field, $attribute] = explode('=', $entry, 2);
                $field = trim($field);
                $attribute = $this->sanitiseAttributeName(trim($attribute));
            } else {
                $field = $entry;
                $attribute = $this->sanitiseAttributeName($entry);
            }
            if ($field !== '' && $attribute !== '') {
                // Namespace org attributes under an "org_" prefix so they can't
                // collide with a user meta-field attribute that shares the name.
                $result[$field] = 'org_' . $attribute;
            }
        }
        return $result;
    }

    private function sanitiseAttributeName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/[^a-z0-9]+/', '_', $name);
        return trim($name, '_');
    }

    /*
     * Batch-load the organisation-scoped meta-fields for the given org ids.
     * Returns [orgId => [field => value]]. Used to push org attributes to keycloak
     * without an N+1 query per user (org fields are shared across an org's users).
     */
    private function getOrgMetaFieldsByOrgIds(array $orgIds): array
    {
        $orgIds = array_values(array_unique(array_filter($orgIds)));
        if (empty($orgIds)) {
            return [];
        }
        // Query the MetaFields table directly rather than via the Users->MetaFields
        // association, whose find() would bake in a conflicting scope = 'user' condition.
        $metaFields = $this->_table->getTableLocator()->get('MetaFields')->find()
            ->where([
                'MetaFields.scope' => 'organisation',
                'MetaFields.parent_id IN' => $orgIds,
            ])
            ->select(['MetaFields.field', 'MetaFields.parent_id', 'MetaFields.value'])
            ->disableHydration()
            ->toArray();
        $result = [];
        foreach ($metaFields as $metaField) {
            $result[$metaField['parent_id']][$metaField['field']] = $metaField['value'];
        }
        return $result;
    }

    /*
     * Build the [attribute => value] map of org meta-fields for a single user.
     * Reads from a pre-populated $user['org_meta_fields'] when available (full sync),
     * otherwise fetches the user's organisation meta-fields on demand (enrol/update).
     */
    private function buildOrgAttributes(array $user): array
    {
        $orgFields = $this->getMappedOrgFields();
        if (empty($orgFields)) {
            return [];
        }
        if (isset($user['org_meta_fields'])) {
            $orgMetaFields = $user['org_meta_fields'];
        } else {
            $orgId = $user['organisation']['id'] ?? null;
            $fetched = $this->getOrgMetaFieldsByOrgIds([$orgId]);
            $orgMetaFields = ($orgId && isset($fetched[$orgId])) ? $fetched[$orgId] : [];
        }
        $attributes = [];
        foreach ($orgFields as $field => $attribute) {
            $attributes[$attribute] = $orgMetaFields[$field] ?? '';
        }
        return $attributes;
    }

    public function getUser(EntityInterface $profile, Session $session)
    {
        $userId = $session->read('Auth.User.id');
        if ($userId) {
            return $this->_table->get($userId);
        }

        $raw_profile_payload = $profile->access_token->getJwt()->getPayload();
        $user = $this->extractProfileData($raw_profile_payload);
        if (!$user) {
            throw new \RuntimeException('Unable to authenticate user. The KeyCloak and Cerebrate states of the user differ. This could be due to a missing synchronisation of the data.');
        }

        return $user;
    }

    private function extractProfileData($profile_payload)
    {
        $mapping = Configure::read('keycloak.mapping');
        $fields = [
            'username' => 'preferred_username',
            'email' => 'email',
            'first_name' => 'given_name',
            'last_name' => 'family_name'
        ];
        foreach ($fields as $field => $default) {
            if (!empty($mapping[$field])) {
                $fields[$field] = $mapping[$field];
            }
        }
        $existingUser = $this->_table->find()
            ->where(['username' => $profile_payload[$fields['username']]])
            ->contain('Individuals')
            ->first();
        if (mb_strtolower($existingUser['individual']['email']) !== mb_strtolower($profile_payload[$fields['email']])) {
            return false;
        }
        return $existingUser;
    }

    /*
     * Run a rest query against keycloak
     * Auto sets the headers and uses a sprintf string to build the URL, injecting the baseurl + realm into the $pathString
     */
    private function restApiRequest(string $pathString, array $payload, string $postRequestType = 'post'): Object
    {
        $token = $this->getAdminAccessToken();
        $keycloakConfig = Configure::read('keycloak');
        $http = new Client();
        $url = sprintf(
            $pathString,
            $keycloakConfig['provider']['baseUrl'],
            $keycloakConfig['provider']['realm']
        );
        return $http->$postRequestType(
            $url,
            json_encode($payload),
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ]
            ]
        );
    }

    public function getUserIdByUsername(string $username)
    {
        $response = $this->restApiRequest(
            '%s/admin/realms/%s/users/?username=' . $this->urlencodeEscapeForSprintf($username),
            [],
            'GET'
        );
        if (!$response->isOk()) {
            $responseBody = json_decode($response->getStringBody(), true);
            $this->_table->auditLogs()->insert([
                'request_action' => 'keycloakGetUser',
                'model' => 'User',
                'model_id' => 0,
                'model_title' => __('Failed to fetch user ({0}) from keycloak', $username),
                'changed' => ['error' => empty($responseBody['errorMessage']) ? 'Unknown error.' : $responseBody['errorMessage']]
            ]);
        }
        $responseBody = json_decode($response->getStringBody(), true);
        if (empty($responseBody[0]['id'])) {
            return false;
        }
        return $responseBody[0]['id'];
    }

    public function deleteUser($data): bool
    {
        $userId = $this->getUserIdByUsername($data['username']);
        if ($userId === false) {
            $this->_table->auditLogs()->insert([
                'request_action' => 'keycloakUserDeletion',
                'model' => 'User',
                'model_id' => 0,
                'model_title' => __('User {0} not found in keycloak, deleting the user locally.', $data['username']),
                'changed' => []
            ]);
            return true;
        }
        $response = $this->restApiRequest(
            '%s/admin/realms/%s/users/' . urlencode($userId),
            [],
            'delete'
        );
        if (!$response->isOk()) {
            $responseBody = json_decode($response->getStringBody(), true);
            $this->_table->auditLogs()->insert([
                'request_action' => 'keycloakUserDeletion',
                'model' => 'User',
                'model_id' => 0,
                'model_title' => __('Failed to delete user {0} ({1}) in keycloak', $data['username'], $userId),
                'changed' => ['error' => empty($responseBody['errorMessage']) ? 'Unknown error.' : $responseBody['errorMessage']]
            ]);
            return false;
        }
        return true;
    }

    public function enrollUser($data): bool
    {
        $roleConditions = [
            'id' => $data['role_id']
        ];
        $user = [
            'username' => $data['username'],
            'disabled' => false,
            'individual' => $this->_table->Individuals->find()->where(
                [
                    'id' => $data['individual_id']
                ]
            )->first(),
            'role' => $this->_table->Roles->find()->where($roleConditions)->first(),
            'organisation' => $this->_table->Organisations->find()->where(
                [
                    'id' => $data['organisation_id']
                ]
            )->first()
        ];
        $clientId = $this->getClientId();
        $newUserId = $this->createUser($user, $clientId);
        if (!$newUserId) {
            $logChange = [
                'username' => $user['username'],
                'individual_id' => $user['individual']['id'],
                'role_id' => $user['role']['id']
            ];
            $this->_table->auditLogs()->insert([
                'request_action' => 'enrollUser',
                'model' => 'User',
                'model_id' => 0,
                'model_title' => __('Failed Keycloak enrollment for user {0}', $user['username']),
                'changed' => $logChange
            ]);
        } else {
            $logChange = [
                'username' => $user['username'],
                'individual_id' => $user['individual']['id'],
                'role_id' => $user['role']['id']
            ];
            $this->_table->auditLogs()->insert([
                'request_action' => 'enrollUser',
                'model' => 'User',
                'model_id' => 0,
                'model_title' => __('Successful Keycloak enrollment for user {0}', $user['username']),
                'changed' => $logChange
            ]);
            $saved_user = $this->getCerebrateUsers($user['id']);
            $clientId = $this->getClientId();
            $this->syncUsers($saved_user, $clientId);
            $response = $this->restApiRequest(
                '%s/admin/realms/%s/users/' . urlencode($newUserId) . '/execute-actions-email',
                ['UPDATE_PASSWORD'],
                'put'
            );
            if (!$response->isOk()) {
                $responseBody = json_decode($response->getStringBody(), true);
                $this->_table->auditLogs()->insert([
                    'request_action' => 'keycloakWelcomeEmail',
                    'model' => 'User',
                    'model_id' => 0,
                    'model_title' => __('Failed to send welcome mail to user ({0}) in keycloak', $user['username']),
                    'changed' => ['error' => empty($responseBody['errorMessage']) ? 'Unknown error.' : $responseBody['errorMessage']]
                ]);
            }
        }
        return true;
    }

    /**
     * handleUserUpdate
     *
     * @param \App\Model\Entity\User $user
     * @return array Containing changes if successful
     */
    public function handleUserUpdate(\App\Model\Entity\User $user): array
    {
        $user['individual'] = $this->_table->Individuals->find()->where([
            'id' => $user['individual_id']
        ])->first();
        $user['role'] = $this->_table->Roles->find()->where([
             'id' => $user['role_id']
        ])->first();
        $user['organisation'] = $this->_table->Organisations->find()->where([
            'id' => $user['organisation_id']
        ])->first();

        $users = [$user->toArray()];
        $clientId = $this->getClientId();
        $changes = $this->syncUsers($users, $clientId);
        return $changes;
    }

    public function keyCloaklogout(): string
    {
        $keycloakConfig = Configure::read('keycloak');
        $logoutUrl = sprintf(
            '%s/realms/%s/protocol/openid-connect/logout?redirect_uri=%s',
            $keycloakConfig['provider']['baseUrl'],
            $keycloakConfig['provider']['realm'],
            urlencode(Configure::read('App.fullBaseUrl'))
        );
        return $logoutUrl;
    }

    private function getAdminAccessToken()
    {
        $keycloakConfig = Configure::read('keycloak');
        $http = new Client();
        $tokenUrl = sprintf(
            '%s/realms/%s/protocol/openid-connect/token',
            $keycloakConfig['provider']['baseUrl'],
            $keycloakConfig['provider']['realm']
        );
        $response = $http->post(
            $tokenUrl,
            sprintf(
                'grant_type=client_credentials&client_id=%s&client_secret=%s',
                urlencode(Configure::read('keycloak.provider.applicationId')),
                urlencode(Configure::read('keycloak.provider.applicationSecret'))
            ),
            [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]
        );
        $parsedResponse = json_decode($response->getStringBody(), true);
        return $parsedResponse['access_token'];
    }

    private function getClientId(): string
    {
        $response = $this->restApiRequest('%s/admin/realms/%s/clients?clientId=' . Configure::read('keycloak.provider.applicationId'), [], 'get');
        $clientId = json_decode($response->getStringBody(), true);
        if (!empty($clientId[0]['id'])) {
            return $clientId[0]['id'];
        } else {
            throw new NotFoundException(__('Keycloak client ID not found or service account doesn\'t have the "view-clients" privilege.'));
        }
    }

    public function syncWithKeycloak(): array
    {
        $this->updateMappers();
        $results = [];
        $data['Users'] = $this->getCerebrateUsers();
        $clientId = $this->getClientId();
        return $this->syncUsers($data['Users'], $clientId);
    }

    private function syncUsers(array $users, $clientId): array
    {
        $keycloakUsersParsed = $this->getParsedKeycloakUser();
        $changes = [
            'created' => [],
            'modified' => [],
        ];
        foreach ($users as &$user) {
            try {
                if (empty($keycloakUsersParsed[$user['username']])) {
                    if ($this->createUser($user, $clientId)) {
                        $changes['created'][] = $user['username'];
                    }
                } else {
                    if ($this->checkAndUpdateUser($keycloakUsersParsed[$user['username']], $user)) {
                        $changes['modified'][] = $user['username'];
                    }
                }
            } catch (\Exception $e) {
                $this->_table->auditLogs()->insert([
                    'request_action' => 'syncUsers',
                    'model' => 'User',
                    'model_id' => 0,
                    'model_title' => __('Failed to create or modify user ({0}) in keycloak', $user['username']),
                    'changed' => [
                        'message' => $e->getMessage(),
                    ]
                ]);
            }
        }
        return $changes;
    }

    public function getParsedKeycloakUser(): array
    {
        $response = $this->restApiRequest('%s/admin/realms/%s/users/?max=999999', [], 'get');
        $keycloakUsers = json_decode($response->getStringBody(), true);
        $keycloakUsersParsed = [];
        $mappers = array_merge(
            ['role_name', 'role_uuid', 'org_uuid', 'org_name'],
            $this->getMappedFieldList(),
            array_values($this->getMappedOrgFields())
        );
        foreach ($keycloakUsers as $u) {
            $attributes = [];
            $keycloakUsersParsed[$u['username']] = [
                'id' => $u['id'],
                'username' => $u['username'],
                'enabled' => $u['enabled'],
                'firstName' => $u['firstName'],
                'lastName' => $u['lastName'],
                'email' => $u['email'],
                'attributes' => []
            ];
            foreach ($mappers as $mapper) {
                $keycloakUsersParsed[$u['username']]['attributes'][$mapper] = $u['attributes'][$mapper][0] ?? '';
            }
        }
        return $keycloakUsersParsed;
    }

    private function getCerebrateUsers($id = null): array
    {
        $metaFieldsSelector = ['fields' => ['MetaFields.field', 'MetaFields.parent_id', 'MetaFields.value']];
        $query = $this->_table->find()->contain(['Individuals', 'Organisations', 'Roles', 'MetaFields' => $metaFieldsSelector])->select([
            'id',
            'uuid',
            'username',
            'disabled',
            'Individuals.email',
            'Individuals.first_name',
            'Individuals.last_name',
            'Individuals.uuid',
            'Roles.name',
            'Roles.uuid',
            'Organisations.id',
            'Organisations.name',
            'Organisations.uuid'
        ]);
        if ($id) {
            $query->where(['Users.id' => $id]);
        }
        $results = $query->disableHydration()->toArray();
        foreach ($results as &$result) {
            if (!empty($result['meta_fields'])) {
                $temp = [];
                foreach ($result['meta_fields'] as $meta_field) {
                    $temp[$meta_field['field']] = $meta_field['value'];
                }
                $result['meta_fields'] = $temp;
            }
        }
        unset($result);
        $orgMetaByOrgId = $this->getOrgMetaFieldsByOrgIds(
            array_map(function ($result) {
                return $result['organisation']['id'] ?? null;
            }, $results)
        );
        foreach ($results as &$result) {
            $orgId = $result['organisation']['id'] ?? null;
            $result['org_meta_fields'] = ($orgId && isset($orgMetaByOrgId[$orgId])) ? $orgMetaByOrgId[$orgId] : [];
        }
        unset($result);
        return $results;
    }

    private function checkAndUpdateUser(array $keycloakUser, array $user): bool
    {
        if (!empty($user['meta_fields'][0])) {
            $temp = [];
            foreach ($user['meta_fields'] as $meta_field) {
                $temp[$meta_field['field']] = $meta_field['value'];
            }
            $user['meta_fields'] = $temp;
        }
        if ($this->checkKeycloakUserRequiresUpdate($keycloakUser, $user)) {
            $default_mappers = [
                'role' => [
                    'uuid',
                    'name'
                ],
                'organisation' => [
                    'uuid',
                    'name'
                ]
            ];
            $custom_mappers = $this->getMappedFieldList();
            $change = [
                'enabled' => !$user['disabled'],
                'firstName' => $user['individual']['first_name'],
                'lastName' => $user['individual']['last_name'],
                'email' => $user['individual']['email'],
                'attributes' => []
            ];
            foreach ($default_mappers as $object_type => $object_data) {
                foreach ($object_data as $mapper) {
                    $object_type_kc = $object_type === 'organisation' ? 'org' : $object_type;
                    $change['attributes'][$object_type_kc . '_' . $mapper] = $user[$object_type][$mapper];
                }
            }
            foreach ($custom_mappers as $mapper) {
                $change['attributes'][$mapper] = $user['meta_fields'][$mapper] ?? '';
            }
            foreach ($this->buildOrgAttributes($user) as $attribute => $value) {
                $change['attributes'][$attribute] = $value;
            }
            $response = $this->restApiRequest('%s/admin/realms/%s/users/' . $keycloakUser['id'], $change, 'put');
            if (!$response->isOk()) {
                $this->_table->auditLogs()->insert([
                    'request_action' => 'keycloakUpdateUser',
                    'model' => 'User',
                    'model_id' => 0,
                    'model_title' => __('Failed to update user ({0}) in keycloak', $user['username']),
                    'changed' => [
                        'code' => $response->getStatusCode(),
                        'error_body' => $response->getStringBody()
                    ]
                ]);
            } else {
                return true;
            }
        }
        return false;
    }

    public function checkKeycloakStatus(array $users, array $keycloakUsers): array
    {
        $users = Hash::combine($users, '{n}.username', '{n}');
        $keycloakUsersParsed = Hash::combine($keycloakUsers, '{n}.username', '{n}');
        $status = [];
        foreach ($users as $username => $user) {
            $temp = [];
            foreach ($user['meta_fields'] as $meta_field) {
                $temp[$meta_field['field']] = $meta_field['value'];
            }
            $user['meta_fields'] = $temp;
            $differences = [];
            $keycloakUser = $keycloakUsersParsed[$username] ?? [];
            if (empty($keycloakUser)) {
                $requireUpdate = true;
                $differences = [
                    'user' => [
                        'keycloak' => __('ERROR or USER NOT FOUND'),
                        'cerebrate' => $user['username']
                    ]
                ];
            } else {
                $requireUpdate = $this->checkKeycloakUserRequiresUpdate($keycloakUser, $user, $differences);
            }
            $status[$user['id']] = [
                'require_update' => $requireUpdate,
                'differences' => $differences,
            ];
        }
        return $status;
    }

    private function checkKeycloakUserRequiresUpdate(array $keycloakUser, array $user, array &$differences = []): bool
    {   
        $mappedFields = $this->getMappedFieldList();
        $condEnabled = $keycloakUser['enabled'] == $user['disabled'];
        $condFirstname = mb_strtolower($keycloakUser['firstName']) !== mb_strtolower($user['individual']['first_name']);
        $condLastname = mb_strtolower($keycloakUser['lastName']) !== mb_strtolower($user['individual']['last_name']);
        $condEmail = mb_strtolower($keycloakUser['email']) !== mb_strtolower($user['individual']['email']);
        $condRolename = (empty($keycloakUser['attributes']['role_name']) || mb_strtolower($keycloakUser['attributes']['role_name']) !== mb_strtolower($user['role']['name']));
        $condRoleuuid = (empty($keycloakUser['attributes']['role_uuid']) || mb_strtolower($keycloakUser['attributes']['role_uuid']) !== mb_strtolower($user['role']['uuid']));
        $condOrgname = (empty($keycloakUser['attributes']['org_name']) || mb_strtolower($keycloakUser['attributes']['org_name']) !== mb_strtolower($user['organisation']['name']));
        $condOrguuid = (empty($keycloakUser['attributes']['org_uuid']) || mb_strtolower($keycloakUser['attributes']['org_uuid']) !== mb_strtolower($user['organisation']['uuid']));
        $condMapped = false;
        if (!empty($user['meta_fields']) && isset($user['meta_fields'][0])) {
            $temp = [];
            foreach ($user['meta_fields'] as $meta_field) {
                $temp[$meta_field['field']] = $meta_field['value'];
            }
            $user['meta_fields'] = $temp;
        }
        foreach ($mappedFields as $mappedField) {
            if (($keycloakUser['attributes'][$mappedField] ?? '') != ($user['meta_fields'][$mappedField] ?? '')) {
                $condMapped = true;
                $differences[$mappedField] = [
                    'keycloak' => $keycloakUser['attributes'][$mappedField] ?? '',
                    'cerebrate' => $user['meta_fields'][$mappedField] ?? ''
                ];
            }
        }
        $condOrgMapped = false;
        foreach ($this->buildOrgAttributes($user) as $attribute => $value) {
            if (($keycloakUser['attributes'][$attribute] ?? '') != ($value ?? '')) {
                $condOrgMapped = true;
                $differences[$attribute] = [
                    'keycloak' => $keycloakUser['attributes'][$attribute] ?? '',
                    'cerebrate' => $value
                ];
            }
        }
        if ($condEnabled || $condFirstname || $condLastname || $condEmail || $condRolename || $condRoleuuid || $condOrgname || $condOrguuid || $condMapped || $condOrgMapped) {
            if ($condEnabled) {
                $differences['enabled'] = ['keycloak' => $keycloakUser['enabled'], 'cerebrate' => $user['disabled']];
            }
            if ($condFirstname) {
                $differences['first_name'] = ['keycloak' => $keycloakUser['firstName'], 'cerebrate' => $user['individual']['first_name']];
            }
            if ($condLastname) {
                $differences['last_name'] = ['keycloak' => $keycloakUser['lastName'], 'cerebrate' => $user['individual']['last_name']];
            }
            if ($condEmail) {
                $differences['email'] = ['keycloak' => $keycloakUser['email'], 'cerebrate' => $user['individual']['email']];
            }
            if ($condRolename) {
                $differences['role_name'] = ['keycloak' => $keycloakUser['attributes']['role_name'], 'cerebrate' => $user['role']['name']];
            }
            if ($condRoleuuid) {
                $differences['role_uuid'] = ['keycloak' => $keycloakUser['attributes']['role_uuid'], 'cerebrate' => $user['role']['uuid']];
            }
            if ($condOrgname) {
                $differences['org_name'] = ['keycloak' => $keycloakUser['attributes']['org_name'], 'cerebrate' => $user['organisation']['name']];
            }
            if ($condOrguuid) {
                $differences['org_uuid'] = ['keycloak' => $keycloakUser['attributes']['org_uuid'], 'cerebrate' => $user['organisation']['uuid']];
            }
            return true;
        }
        return false;
    }

    private function createUser(array $user, string $clientId)
    {
        $newUser = [
            'username' => $user['username'],
            'enabled' => !$user['disabled'],
            'firstName' => $user['individual']['first_name'],
            'lastName' => $user['individual']['last_name'],
            'email' => $user['individual']['email'],
            'attributes' => [
                'role_name' => $user['role']['name'],
                'role_uuid' => $user['role']['uuid'],
                'org_name' => $user['organisation']['name'],
                'org_uuid' => $user['organisation']['uuid']
            ]
        ];
        foreach ($this->buildOrgAttributes($user) as $attribute => $value) {
            $newUser['attributes'][$attribute] = $value;
        }
        $response = $this->restApiRequest('%s/admin/realms/%s/users', $newUser, 'post');
        if (!$response->isOk()) {
            $this->_table->auditLogs()->insert([
                'request_action' => 'createUser',
                'model' => 'User',
                'model_id' => 0,
                'model_title' => __('Failed to create user ({0}) in keycloak {0}', $user['username']),
                'changed' => [
                    'code' => $response->getStatusCode(),
                    'error_body' => $response->getStringBody()
                ]
            ]);
        }
        $newUser = $this->restApiRequest(
            '%s/admin/realms/%s/users?username=' . $this->urlencodeEscapeForSprintf(urlencode($user['username'])),
            [],
            'get'
        );
        $users = json_decode($newUser->getStringBody(), true);
        if (empty($users[0]['id'])) {
            return false;
        }
        if (is_array($users[0]['id'])) {
            $users[0]['id'] = $users[0]['id'][0];
        }
        $user['id'] = $users[0]['id'];
        return $user['id'];
    }

    private function urlencodeEscapeForSprintf(string $input): string
    {
        return str_replace('%', '%%', $input);
    }

    public function updateMappers(): bool
    {
        $clientId = $this->getClientId();
        $response = $this->restApiRequest('%s/admin/realms/%s/clients/' . $clientId . '/protocol-mappers/models?protocolMapper=oidc-usermodel-attribute-mapper', [], 'get');
        if ($response->isOk()) {
            $mappers = json_decode($response->getStringBody(), true);
        } else {
            return false;
        }
        $enabledMappers = [];
        $defaultMappers = [
            'org_name' => 0,
            'org_uuid' => 0,
            'role_name' => 0,
            'role_uuid' => 0
        ];
        $mappersToEnable = array_filter(array_map('trim', array_merge(
            explode(',', (string)Configure::read('keycloak.user_meta_mapping')),
            array_values($this->getMappedOrgFields())
        )));
        foreach ($mappers as $mapper) {
            if ($mapper['protocolMapper'] !== 'oidc-usermodel-attribute-mapper') {
                continue;
            }
            if (in_array($mapper['name'], array_keys($defaultMappers))) {
                $defaultMappers[$mapper['name']] = 1;
                continue;
            }
            $enabledMappers[$mapper['name']] = $mapper;
        }
        $payload = [];
        foreach ($mappersToEnable as $mapperToEnable) {
            $payload[] = [
                'protocol' => 'openid-connect',
                'name' => $mapperToEnable,
                'protocolMapper' => 'oidc-usermodel-attribute-mapper',
                'config' => [
                    'id.token.claim' => true,
                    'access.token.claim' => true,
                    'userinfo.token.claim' => true,
                    'user.attribute' => $mapperToEnable,
                    'claim.name' => $mapperToEnable
                ]
            ];
        }
        foreach ($defaultMappers as $defaultMapper => $enabled) {
            if (!$enabled) {
                $payload[] = [
                    'protocol' => 'openid-connect',
                    'name' => $defaultMapper,
                    'protocolMapper' => 'oidc-usermodel-attribute-mapper',
                    'config' => [
                        'id.token.claim' => true,
                        'access.token.claim' => true,
                        'userinfo.token.claim' => true,
                        'user.attribute' => $defaultMapper,
                        'claim.name' => $defaultMapper
                    ]
                ];
            }
        }
        if (!empty($payload)) {
            $response = $this->restApiRequest('%s/admin/realms/%s/clients/' . $clientId . '/protocol-mappers/add-models', $payload, 'post');
            if (!$response->isOk()) {
                return false;
            }
        }
        return true;
    }
}
