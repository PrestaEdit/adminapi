<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Integration;

class ContactEndpointTest extends ApiTestCase
{
    private static int $createdContactId;

    public function testGetContactListWithoutToken(): void
    {
        $this->requestWithoutToken('GET', '/admin-api/contacts', 401);
    }

    public function testGetContactListWithWrongScope(): void
    {
        $this->listItems('/admin-api/contacts', ['contact_write'], [], 403);
    }

    public function testGetContactList(): void
    {
        $response = $this->listItems('/admin-api/contacts', ['contact_read']);
        $this->assertArrayHasKey('items', $response);
        $this->assertArrayHasKey('totalItems', $response);
        $this->assertArrayHasKey('offset', $response);
        $this->assertArrayHasKey('limit', $response);
    }

    public function testCreateContactWithMissingName(): void
    {
        $this->createItem('/admin-api/contacts', ['email' => 'test@test.com'], ['contact_write'], 422);
    }

    public function testCreateContact(): void
    {
        $response = $this->createItem('/admin-api/contacts', [
            'names'           => ['en-US' => 'Test Contact', 'fr-FR' => 'Contact Test'],
            'email'           => 'apitest@example.com',
            'customerService' => true,
        ], ['contact_write']);

        $this->assertArrayHasKey('contactId', $response);
        $this->assertSame('apitest@example.com', $response['email']);
        $this->assertArrayHasKey('en-US', $response['names']);

        self::$createdContactId = (int) $response['contactId'];
    }

    /** @depends testCreateContact */
    public function testGetContact(): void
    {
        $response = $this->getItem('/admin-api/contacts/' . self::$createdContactId, ['contact_read']);
        $this->assertSame(self::$createdContactId, $response['contactId']);
        $this->assertSame('Test Contact', $response['names']['en-US'] ?? '');
    }

    /** @depends testGetContact */
    public function testUpdateContact(): void
    {
        $response = $this->updateItem(
            '/admin-api/contacts/' . self::$createdContactId,
            ['names' => ['en-US' => 'Updated Contact', 'fr-FR' => 'Contact Modifié']],
            ['contact_write']
        );
        $this->assertSame('Updated Contact', $response['names']['en-US'] ?? '');
    }

    /** @depends testUpdateContact */
    public function testDeleteContact(): void
    {
        $this->deleteItem('/admin-api/contacts/' . self::$createdContactId, ['contact_write']);
    }

    /** @depends testDeleteContact */
    public function testGetDeletedContactReturns404(): void
    {
        $this->getItem('/admin-api/contacts/' . self::$createdContactId, ['contact_read'], 404);
    }
}
