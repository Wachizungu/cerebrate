<?php

declare(strict_types=1);

namespace App\Test\TestCase\Api\Inbox;

use Cake\TestSuite\TestCase;
use App\Test\Fixture\AuthKeysFixture;
use App\Test\Helper\ApiTestTrait;

class ViewInboxApiTest extends TestCase
{
    use ApiTestTrait;

    protected const ENDPOINT = '/inbox/view';

    protected $fixtures = [
        'app.Inbox',
        'app.Organisations',
        'app.Individuals',
        'app.Roles',
        'app.Users',
        'app.AuthKeys'
    ];

    public function testViewInboxRedactsRegistrationPassword(): void
    {
        $this->skipOpenApiValidations();
        $this->setAuthToken(AuthKeysFixture::ADMIN_API_KEY);
        // id 1 is the user registration entry from InboxFixture
        $this->get(self::ENDPOINT . '/1');

        $this->assertResponseOk();
        $this->assertResponseContains('"password": "*****"');
        $this->assertResponseNotContains('$2y$10$');
    }
}
