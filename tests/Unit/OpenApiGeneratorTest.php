<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaEdit\ApiModule\Api\OpenApiGenerator;
use PrestaEdit\ApiModule\Resource\ResourceRegistry;

class OpenApiGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        ResourceRegistry::reset();
    }

    /** @return array<string,mixed> */
    private function spec(): array
    {
        return (new OpenApiGenerator())->generate();
    }

    public function testTopLevelStructure(): void
    {
        $spec = $this->spec();
        $this->assertSame('3.0.3', $spec['openapi']);
        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
    }

    public function testOauth2SecurityScheme(): void
    {
        $spec   = $this->spec();
        $scheme = $spec['components']['securitySchemes']['oauth2'];
        $this->assertSame('oauth2', $scheme['type']);
        $flow = $scheme['flows']['clientCredentials'];
        $this->assertSame('/admin-api/access_token', $flow['tokenUrl']);
        $this->assertArrayHasKey('contact_read', $flow['scopes']);
        $this->assertArrayHasKey('product_write', $flow['scopes']);
    }

    public function testContactCollectionAndItemPaths(): void
    {
        $paths = $this->spec()['paths'];

        $this->assertArrayHasKey('/contacts', $paths);
        $this->assertArrayHasKey('get', $paths['/contacts']);
        $this->assertArrayHasKey('post', $paths['/contacts']);

        $this->assertArrayHasKey('/contacts/{id}', $paths);
        $this->assertArrayHasKey('get', $paths['/contacts/{id}']);
        $this->assertArrayHasKey('patch', $paths['/contacts/{id}']);
        $this->assertArrayHasKey('delete', $paths['/contacts/{id}']);

        $this->assertArrayHasKey('/contacts/bulk-delete', $paths);
        $this->assertArrayHasKey('delete', $paths['/contacts/bulk-delete']);
    }

    public function testOperationCarriesScopeAndOperationId(): void
    {
        $get = $this->spec()['paths']['/contacts']['get'];
        $this->assertArrayHasKey('operationId', $get);
        $this->assertArrayHasKey('responses', $get);
        $this->assertSame(['contact_read'], $get['security'][0]['oauth2']);
    }

    public function testListExposesPaginationParams(): void
    {
        $get   = $this->spec()['paths']['/contacts']['get'];
        $names = array_column($get['parameters'] ?? [], 'name');
        $this->assertContains('limit', $names);
        $this->assertContains('offset', $names);
        $this->assertContains('orderBy', $names);
        $this->assertContains('sortOrder', $names);
    }

    public function testModuleIsReadOnly(): void
    {
        $paths = $this->spec()['paths'];
        $this->assertArrayHasKey('/modules', $paths);
        $this->assertArrayHasKey('get', $paths['/modules']);
        $this->assertArrayNotHasKey('post', $paths['/modules']);
        $this->assertArrayHasKey('/modules/{id}', $paths);
        $this->assertArrayNotHasKey('delete', $paths['/modules/{id}']);
        $this->assertArrayHasKey('patch', $paths['/modules/{id}']);
    }
}
