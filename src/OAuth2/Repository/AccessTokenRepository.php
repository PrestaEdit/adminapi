<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Repository;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use PrestaEdit\ApiModule\OAuth2\Entity\AccessTokenEntity;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    /**
     * @param ClientEntityInterface  $clientEntity
     * @param ScopeEntityInterface[] $scopes
     * @param string|null            $userIdentifier
     * @return AccessTokenEntityInterface
     */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, $userIdentifier = null)
    {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        return $token;
    }

    /**
     * @param AccessTokenEntityInterface $accessTokenEntity
     * @return void
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity)
    {
        if (!class_exists('Db')) {
            return;
        }
        $scopes = array_map(
            static fn (ScopeEntityInterface $s): string => $s->getIdentifier(),
            $accessTokenEntity->getScopes()
        );
        \Db::getInstance()->insert('apimodule_access_token', [
            'id'         => pSQL($accessTokenEntity->getIdentifier()),
            'client_id'  => pSQL($accessTokenEntity->getClient()->getIdentifier()),
            'scopes'     => pSQL(json_encode($scopes)),
            'revoked'    => 0,
            'expires_at' => pSQL($accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s')),
        ]);
    }

    /**
     * @param string $tokenId
     * @return void
     */
    public function revokeAccessToken($tokenId)
    {
        if (!class_exists('Db')) {
            return;
        }
        \Db::getInstance()->update(
            'apimodule_access_token',
            ['revoked' => 1],
            '`id` = \'' . pSQL($tokenId) . '\''
        );
    }

    /**
     * @param string $tokenId
     * @return bool
     */
    public function isAccessTokenRevoked($tokenId)
    {
        if (!class_exists('Db')) {
            return false;
        }
        $row = \Db::getInstance()->getRow(
            'SELECT `revoked` FROM `' . _DB_PREFIX_ . 'apimodule_access_token`
             WHERE `id` = \'' . pSQL($tokenId) . '\''
        );
        if (!$row) {
            return false;
        }
        return (bool) $row['revoked'];
    }
}
