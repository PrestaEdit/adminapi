<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use PrestaEdit\ApiModule\OAuth2\Entity\ClientEntity;

class ClientRepository implements ClientRepositoryInterface
{
    /**
     * @param string $clientIdentifier
     * @return ClientEntityInterface|null
     */
    public function getClientEntity($clientIdentifier)
    {
        if (!class_exists('Db')) {
            return null;
        }
        $row = \Db::getInstance()->getRow(
            'SELECT `client_name` FROM `' . _DB_PREFIX_ . 'apimodule_client`
             WHERE `client_id` = \'' . pSQL($clientIdentifier) . '\' AND `active` = 1'
        );
        if (!$row) {
            return null;
        }
        return new ClientEntity($clientIdentifier, $row['client_name']);
    }

    /**
     * @param string      $clientIdentifier
     * @param string|null $clientSecret
     * @param string|null $grantType
     * @return bool
     */
    public function validateClient($clientIdentifier, $clientSecret, $grantType)
    {
        if ($grantType !== 'client_credentials') {
            return false;
        }
        if (!class_exists('Db')) {
            return false;
        }
        $row = \Db::getInstance()->getRow(
            'SELECT `client_secret` FROM `' . _DB_PREFIX_ . 'apimodule_client`
             WHERE `client_id` = \'' . pSQL($clientIdentifier) . '\' AND `active` = 1'
        );
        if (!$row) {
            return false;
        }
        return password_verify((string) $clientSecret, $row['client_secret']);
    }
}
