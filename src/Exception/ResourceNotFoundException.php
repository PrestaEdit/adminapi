<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Exception;

class ResourceNotFoundException extends \RuntimeException
{
    public function __construct(string $type, int $id)
    {
        parent::__construct(
            sprintf('%s with id %d was not found.', $type, $id),
            404
        );
    }
}
