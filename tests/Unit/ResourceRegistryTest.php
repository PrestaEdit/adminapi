<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaEdit\ApiModule\Resource\ResourceRegistry;

class ResourceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ResourceRegistry::reset();
    }

    public function testResolvesContactList(): void
    {
        $result = ResourceRegistry::resolve('/contacts', 'GET');
        $this->assertNotNull($result);
        $this->assertSame('list', $result[1]);
    }

    public function testResolvesContactGet(): void
    {
        $result = ResourceRegistry::resolve('/contacts/42', 'GET');
        $this->assertNotNull($result);
        $this->assertSame('get', $result[1]);
        $this->assertSame('42', $result[2]['id']);
    }

    public function testResolvesContactDelete(): void
    {
        $result = ResourceRegistry::resolve('/contacts/42', 'DELETE');
        $this->assertNotNull($result);
        $this->assertSame('delete', $result[1]);
    }

    public function testResolvesContactBulkDelete(): void
    {
        $result = ResourceRegistry::resolve('/contacts/bulk-delete', 'DELETE');
        $this->assertNotNull($result);
        $this->assertSame('bulkDelete', $result[1]);
    }

    public function testReturnsNullForUnknownRoute(): void
    {
        $this->assertNull(ResourceRegistry::resolve('/unknown-resource', 'GET'));
    }

    public function testScopeExistsForKnownScope(): void
    {
        $this->assertTrue(ResourceRegistry::scopeExists('contact_read'));
        $this->assertTrue(ResourceRegistry::scopeExists('contact_write'));
    }

    public function testScopeExistsReturnsFalseForUnknown(): void
    {
        $this->assertFalse(ResourceRegistry::scopeExists('nonexistent_scope'));
    }
}
