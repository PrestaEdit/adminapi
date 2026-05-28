<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource;

interface ResourceInterface
{
    /** @return array{uriTemplate:string,identifierKey:string,operations:array,exceptionToStatus:array} */
    public static function definition(): array;

    /** @param array<string,mixed> $context */
    public function get(int $id, array $context): array;

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $context
     */
    public function list(array $filters, array $context): array;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     */
    public function create(array $data, array $context): array;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     */
    public function update(int $id, array $data, array $context): array;

    /** @param array<string,mixed> $context */
    public function delete(int $id, array $context): void;
}
