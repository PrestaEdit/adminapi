<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2;

use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer as LeagueResourceServer;
use PrestaEdit\ApiModule\OAuth2\Repository\AccessTokenRepository;

class ResourceServer
{
    private static $instance = null;

    public static function getInstance(): LeagueResourceServer
    {
        if (self::$instance === null) {
            self::$instance = new LeagueResourceServer(
                new AccessTokenRepository(),
                new CryptKey('file://' . \Adminapi::getPublicKeyPath(), null, false)
            );
        }
        return self::$instance;
    }
}
