<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Integration;

class ApiClientInfosTest extends ApiTestCase
{
    public function testInfosRequiresAuthentication(): void
    {
        $this->requestWithoutToken('GET', '/admin-api/api-client/infos', 401);
    }

    public function testInfosReturnsAuthenticatedClient(): void
    {
        // Any valid token may introspect its own client — no specific scope.
        $response = $this->getItem('/admin-api/api-client/infos', ['contact_read']);

        $this->assertSame('test_client', $response['clientId']);
        $this->assertSame('Test Client', $response['clientName']);
        $this->assertSame(self::$testClientId, $response['apiClientId']);
        $this->assertTrue($response['active']);

        // The token's granted scopes are echoed back.
        $this->assertContains('contact_read', $response['tokenScopes']);

        // The client's full configured scope set is also returned.
        $this->assertIsArray($response['scopes']);
        $this->assertContains('contact_read', $response['scopes']);
    }
}
