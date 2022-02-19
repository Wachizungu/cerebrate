<?php
namespace App\Controller;

use App\Controller\AppController;
use Cake\Utility\Hash;
use Cake\Utility\Text;
use Cake\ORM\TableRegistry;
use \Cake\Database\Expression\QueryExpression;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Exception\MethodNotAllowedException;
use Cake\Core\Configure;

class UsersController extends AppController
{
    public $filterFields = ['Individuals.uuid', 'username', 'Individuals.email', 'Individuals.first_name', 'Individuals.last_name', 'Organisations.name'];
    public $quickFilterFields = ['Individuals.uuid', ['username' => true], ['Individuals.first_name' => true], ['Individuals.last_name' => true], 'Individuals.email'];
    public $containFields = ['Individuals', 'Roles', 'UserSettings', 'Organisations'];

    public function index()
    {
        $currentUser = $this->ACL->getUser();
        $conditions = [];
        if (empty($currentUser['role']['perm_admin'])) {
            $conditions['organisation_id'] = $currentUser['organisation_id'];
        }
        $this->CRUD->index([
            'contain' => $this->containFields,
            'filters' => $this->filterFields,
            'quickFilters' => $this->quickFilterFields,
            'conditions' => $conditions
        ]);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set(
            'validRoles',
            $this->Users->Roles->find('list')->select(['id', 'name'])->order(['name' => 'asc'])->where(['perm_admin' => 0])->all()->toArray()
        );
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function add()
    {
        $currentUser = $this->ACL->getUser();
        $validRoles = [];
        if (!$currentUser['role']['perm_admin']) {
            $validRoles = $this->Users->Roles->find('list')->select(['id', 'name'])->order(['name' => 'asc'])->where(['perm_admin' => 0])->all()->toArray();
        } else {
            $validRoles = $this->Users->Roles->find('list')->order(['name' => 'asc'])->all()->toArray();
        }
        $defaultRole = $this->Users->Roles->find()->select(['id'])->first()->toArray();

        $this->CRUD->add([
            'beforeSave' => function($data) use ($currentUser, $validRoles, $defaultRole) {
                if (!isset($data['role_id']) && !empty($defaultRole)) {
                    $data['role_id'] = $defaultRole['id'];
                }
                if (!$currentUser['role']['perm_admin']) {
                    $data['organisation_id'] = $currentUser['organisation_id'];
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You do not have permission to assign that role.'));
                    }
                }
                $this->Users->enrollUserRouter($data);
                return $data;
            }
        ]);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        /*
        $alignments = $this->Users->Individuals->Alignments->find('list', [
            //'keyField' => 'id',
            'valueField' => 'organisation_id',
            'groupField' => 'individual_id'
        ])->toArray();
        $alignments = array_map(function($value) { return array_values($value); }, $alignments);
        */
        $org_conditions = [];
        if (empty($currentUser['role']['perm_admin'])) {
            $org_conditions = ['id' => $currentUser['organisation_id']];
        }
        $dropdownData = [
            'role' => $validRoles,
            'individual' => $this->Users->Individuals->find('list', [
                'sort' => ['email' => 'asc']
            ]),
            'organisation' => $this->Users->Organisations->find('list', [
                'sort' => ['name' => 'asc'],
                'conditions' => $org_conditions
            ])
        ];
        $this->set(compact('dropdownData'));
        $this->set('defaultRole', $defaultRole['id'] ?? null);
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function view($id = false)
    {
        $currentUser = $this->ACL->getUser();
        if (empty($id) || (empty($currentUser['role']['perm_org_admin']) && empty($currentUser['role']['perm_admin']))) {
            $id = $this->ACL->getUser()['id'];
        }
        $this->CRUD->view($id, [
            'contain' => ['Individuals' => ['Alignments' => 'Organisations'], 'Roles', 'Organisations']
        ]);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function edit($id = false)
    {
        $currentUser = $this->ACL->getUser();
        $validRoles = [];
        if (!$currentUser['role']['perm_admin']) {
            $validRoles = $this->Users->Roles->find('list')->select(['id', 'name'])->order(['name' => 'asc'])->where(['perm_admin' => 0])->all()->toArray();
        } else {
            $validRoles = $this->Users->Roles->find('list')->order(['name' => 'asc'])->all()->toArray();
        }
        if (empty($id)) {
            $id = $currentUser['id'];
        } else {
            $id = intval($id);
            if ((empty($currentUser['role']['perm_org_admin']) && empty($currentUser['role']['perm_admin']))) {
                if ($id !== $currentUser['id']) {
                    throw new MethodNotAllowedException(__('You are not authorised to edit that user.'));
                }
            }
        }

        $params = [
            'get' => [
                'fields' => [
                    'id', 'individual_id', 'role_id', 'username', 'disabled'
                ]
            ],
            'removeEmpty' => [
                'password'
            ],
            'fields' => [
                'password', 'confirm_password'
            ]
        ];
        if (!empty($this->ACL->getUser()['role']['perm_admin'])) {
            $params['fields'][] = 'individual_id';
            $params['fields'][] = 'username';
            $params['fields'][] = 'role_id';
            $params['fields'][] = 'organisation_id';
            $params['fields'][] = 'disabled';
        } else if (!empty($this->ACL->getUser()['role']['perm_org_admin'])) {
            $params['fields'][] = 'username';
            $params['fields'][] = 'role_id';
            $params['fields'][] = 'disabled';
            if (!$currentUser['role']['perm_admin']) {
                $params['afterFind'] = function ($data, &$params) use ($currentUser, $validRoles) {
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You cannot edit the given privileged user.'));
                    }
                    if ($data['organisation_id'] !== $currentUser['organisation_id']) {
                        throw new MethodNotAllowedException(__('You cannot edit the given user.'));
                    }
                    return $data;
                };
                $params['beforeSave'] = function ($data) use ($currentUser, $validRoles) {
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You cannot assign the chosen role to a user.'));
                    }
                    return $data;
                };
            }
        }
        $this->CRUD->edit($id, $params);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $dropdownData = [
            'role' => $validRoles,
            'individual' => $this->Users->Individuals->find('list', [
                'sort' => ['email' => 'asc']
            ]),
            'organisation' => $this->Users->Organisations->find('list', [
                'sort' => ['name' => 'asc']
            ])
        ];
        $this->set(compact('dropdownData'));
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
        $this->render('add');
    }

    public function toggle($id, $fieldName = 'disabled')
    {
        $params = [
            'contain' => 'Roles'
        ];
        $currentUser = $this->ACL->getUser();
        if (!$currentUser['role']['perm_admin']) {
            $params['afterFind'] = function ($user, &$params) use ($currentUser) {
                if (!$this->ACL->canEditUser($currentUser, $user)) {
                    throw new MethodNotAllowedException(__('You cannot edit the given user.'));
                }
                return $user;
            };
        }
        $this->CRUD->toggle($id, $fieldName, $params);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
    }

    public function delete($id)
    {
        $currentUser = $this->ACL->getUser();
        $validRoles = [];
        if (!$currentUser['role']['perm_admin']) {
            $validRoles = $this->Users->Roles->find('list')->order(['name' => 'asc'])->all()->toArray();
        }
        $params = [
            'beforeSave' => function($data) use ($currentUser, $validRoles) {
                if (!$currentUser['role']['perm_admin']) {
                    if ($data['organisation_id'] !== $currentUser['organisation_id']) {
                        throw new MethodNotAllowedException(__('You do not have permission to remove the given user.'));
                    }
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You do not have permission to remove the given user.'));
                    }
                }
                return $data;
            }
        ];
        $this->CRUD->delete($id);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function login()
    {
        $result = $this->Authentication->getResult();
        // If the user is logged in send them away.
        $logModel = $this->Users->auditLogs();
        if ($result->isValid()) {
            $user = $logModel->userInfo();
            $logModel->insert([
                'request_action' => 'login',
                'model' => 'Users',
                'model_id' => $user['id'],
                'model_title' => $user['name'],
                'changed' => []
            ]);
            $target = $this->Authentication->getLoginRedirect() ?? '/instance/home';
            return $this->redirect($target);
        }
        if ($this->request->is('post') && !$result->isValid()) {
            $logModel->insert([
                'request_action' => 'login_fail',
                'model' => 'Users',
                'model_id' => 0,
                'model_title' => 'unknown_user',
                'changed' => []
            ]);
            $this->Flash->error(__('Invalid username or password'));
        }
        $this->viewBuilder()->setLayout('login');
    }

    public function logout()
    {
        $result = $this->Authentication->getResult();
        if ($result->isValid()) {
            $logModel = $this->Users->auditLogs();
            $user = $logModel->userInfo();
            $logModel->insert([
                'request_action' => 'logout',
                'model' => 'Users',
                'model_id' => $user['id'],
                'model_title' => $user['name'],
                'changed' => []
            ]);
            $this->Authentication->logout();
            $this->Flash->success(__('Goodbye.'));
            return $this->redirect(\Cake\Routing\Router::url('/users/login'));
        }
    }

    public function settings($user_id=false)
    {
        $editingAnotherUser = false;
        $currentUser = $this->ACL->getUser();
        if (empty($currentUser['role']['perm_admin']) || $user_id == $currentUser->id) {
            $user = $currentUser;
        } else {
            $user = $this->Users->get($user_id, [
                'contain' => ['Roles', 'Individuals' => 'Organisations', 'Organisations', 'UserSettings']
            ]);
            $editingAnotherUser = true;
        }
        $this->set('editingAnotherUser', $editingAnotherUser);
        $this->set('user', $user);
        $all = $this->Users->UserSettings->getSettingsFromProviderForUser($user->id, true);
        $this->set('settingsProvider', $all['settingsProvider']);
        $this->set('settings', $all['settings']);
        $this->set('settingsFlattened', $all['settingsFlattened']);
        $this->set('notices', $all['notices']);
    }

    public function register()
    {
        if (empty(Configure::read('security.registration.self-registration'))) {
            throw new UnauthorizedException(__('User self-registration is not open.'));
        }
        if (!empty(Configure::read('security.registration.floodProtection'))) {
            $this->FloodProtection->check('register');
        }
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $this->InboxProcessors = TableRegistry::getTableLocator()->get('InboxProcessors');
            $processor = $this->InboxProcessors->getProcessor('User', 'Registration');
            $data = [
                'origin' => $this->request->clientIp(),
                'comment' => '-no comment-',
                'data' => [
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'password' => $data['password'],
                ],
            ];
            $processorResult = $processor->create($data);
            if (!empty(Configure::read('security.registration.floodProtection'))) {
                $this->FloodProtection->set('register');
            }
            return $processor->genHTTPReply($this, $processorResult, ['controller' => 'Inbox', 'action' => 'index']);
        }
        $this->viewBuilder()->setLayout('login');
    }
}
