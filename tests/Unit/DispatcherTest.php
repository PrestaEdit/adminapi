<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use PrestaEdit\ApiModule\Api\Dispatcher;

class DispatcherTest extends TestCase
{
    private function makeRequest(string $method, string $path, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function testDispatchWithoutTokenReturns401(): void
    {
        $dispatcher = new Dispatcher();
        $response   = $dispatcher->dispatch($this->makeRequest('GET', '/admin-api/contacts'));
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDispatchWithInvalidTokenReturns401(): void
    {
        $dispatcher = new Dispatcher();
        $response   = $dispatcher->dispatch(
            $this->makeRequest('GET', '/admin-api/contacts', ['Authorization' => 'Bearer invalid.token.here'])
        );
        $this->assertSame(401, $response->getStatusCode());
    }
}
