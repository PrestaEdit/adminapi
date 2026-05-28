<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Exception;

class ValidationException extends \RuntimeException
{
    /** @var array<string, string[]> */
    private array $errors;

    /** @param array<string, string[]> $errors */
    public function __construct(array $errors)
    {
        parent::__construct('Validation failed', 422);
        $this->errors = $errors;
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
