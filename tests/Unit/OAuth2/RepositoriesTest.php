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

    public function testValidateClientReturnsFalseForWrongSecret(): void
    {
        // Simulate DB returning a row with a bcrypt-hashed password for an active client.
        // Passing the wrong plaintext must make password_verify() return false.
        \Db::$nextRow = ['client_secret' => password_hash('correct_secret', PASSWORD_BCRYPT)];
        $repo = new ClientRepository();
        $this->assertFalse($repo->validateClient('test_client', 'wrong_secret', 'client_credentials'));
    }

    public function testValidateClientReturnsFalseForInactiveClient(): void
    {
        // The SQL query has AND active = 1. An inactive client produces no row.
        // Db::$nextRow is false by default — simulates the SQL finding no active row.
        // Reset explicitly for clarity.
        \Db::$nextRow = false;
        $repo = new ClientRepository();
        $this->assertFalse($repo->validateClient('inactive_client', 'any_secret', 'client_credentials'));
    }

    public function testScopeRepositoryReturnsNullForInvalidScopeFormat(): void
    {
        $repo = new ScopeRepository();
        $this->assertNull($repo->getScopeEntityByIdentifier('nonexistent_scope'));
    }

    public function testScopeRepositoryAcceptsValidFormatWithoutRegistryCheck(): void
    {
        $repo = new ScopeRepository();
        // Registry lookup is deferred to Task 5 (ResourceRegistry::scopeExists).
        // At this layer, any syntactically valid identifier is accepted.
        $this->assertNotNull($repo->getScopeEntityByIdentifier('any_valid_read'));
    }

    public function testAccessTokenRepositoryReturnsFalseForUnknownToken(): void
    {
        $repo = new AccessTokenRepository();
        $this->assertFalse($repo->isAccessTokenRevoked('unknown-token-id'));
    }
}
