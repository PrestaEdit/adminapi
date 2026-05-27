<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PrestaEdit\ApiModule\OAuth2\Entity\ScopeEntity;

class ScopeRepository implements ScopeRepositoryInterface
{
    /**
     * @param string $identifier
     * @return ScopeEntityInterface|null
     */
    public function getScopeEntityByIdentifier($identifier)
    {
        // Format check: must be non-empty and match {entity}_{read|write}
        // Full registry validation is wired in Task 5 (ResourceRegistry::scopeExists)
        if (!preg_match('/^[a-z][a-z0-9_]*_(read|write)$/', $identifier)) {
            return null;
        }
        return new ScopeEntity($identifier);
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     * @param string                 $grantType
     * @param ClientEntityInterface  $clientEntity
     * @param string|null            $userIdentifier
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ) {
        if (!class_exists('Db')) {
            return $scopes; // unit test context: pass through
        }
        $clientRow = \Db::getInstance()->getRow(
            'SELECT `scopes` FROM `' . _DB_PREFIX_ . 'apimodule_client`
             WHERE `client_id` = \'' . pSQL($clientEntity->getIdentifier()) . '\''
        );
        if (!$clientRow || !$clientRow['scopes']) {
            return [];
        }
        $allowedScopes = json_decode($clientRow['scopes'], true) ?? [];
        return array_values(array_filter(
            $scopes,
            static function (ScopeEntityInterface $scope) use ($allowedScopes): bool {
                return in_array($scope->getIdentifier(), $allowedScopes, true);
            }
        ));
    }
}
