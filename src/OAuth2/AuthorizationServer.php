<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2;

use DateInterval;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer as LeagueServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use PrestaEdit\ApiModule\OAuth2\Repository\AccessTokenRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\ClientRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\ScopeRepository;

class AuthorizationServer
{
    private static $instance = null;

    public static function getInstance(): LeagueServer
    {
        if (self::$instance === null) {
            $server = new LeagueServer(
                new ClientRepository(),
                new AccessTokenRepository(),
                new ScopeRepository(),
                new CryptKey('file://' . \Apimodule::getPrivateKeyPath(), null, false),
                Key::loadFromAsciiSafeString(\Configuration::get('APIMODULE_ENCRYPTION_KEY'))
            );
            $server->enableGrantType(
                new ClientCredentialsGrant(),
                new DateInterval('PT1H')
            );
            self::$instance = $server;
        }
        return self::$instance;
    }
}
