<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use PrestaEdit\ApiModule\OAuth2\Repository\ClientRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\ScopeRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\AccessTokenRepository;

class RepositoriesTest extends TestCase
{
    public function testClientRepositoryValidateClientReturnsFalseForUnknownClient(): void
    {
        $repo = new ClientRepository();
        $this->assertFalse($repo->validateClient('unknown', 'secret', 'client_credentials'));
    }

    public function testScopeRepositoryReturnsNullForUnknownScope(): void
    {
        $repo = new ScopeRepository();
        $this->assertNull($repo->getScopeEntityByIdentifier('nonexistent_scope'));
    }

    public function testAccessTokenRepositoryReturnsFalseForUnknownToken(): void
    {
        $repo = new AccessTokenRepository();
        $this->assertFalse($repo->isAccessTokenRevoked('unknown-token-id'));
    }
}
