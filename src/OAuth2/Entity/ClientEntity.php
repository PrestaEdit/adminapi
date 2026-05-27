<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use EntityTrait;
    use ClientTrait;

    public function __construct(string $identifier, string $name)
    {
        $this->setIdentifier($identifier);
        $this->name = $name;
        $this->redirectUri = [];
        $this->isConfidential = true;
    }
}
