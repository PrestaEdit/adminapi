<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Module;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ModuleResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/modules',
            'identifierKey'     => 'moduleId',
            'operations'        => [
                'get'    => ['scope' => 'module_read',  'method' => 'GET'],
                'list'   => ['scope' => 'module_read',  'method' => 'GET'],
                'update' => ['scope' => 'module_write', 'method' => 'PATCH'],
                // create and delete intentionally excluded — too destructive
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_module`, `name`, `active`, `version`
             FROM `' . _DB_PREFIX_ . 'module`
             WHERE `id_module` = ' . (int) $id
        );
        if (!$row) {
            throw new ResourceNotFoundException('Module', $id);
        }
        return $this->mapRow($row);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_module, name, active, version');
        $q->from('module');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_module', [
            'moduleId' => 'id_module',
            'name'     => 'name',
        ]);
        $this->applyPagination($q, $filters, 'id_module');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        throw new \RuntimeException('Module installation is not supported via API.', 405);
    }

    public function update(int $id, array $data, array $context): array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_module`, `name`, `active`, `version`
             FROM `' . _DB_PREFIX_ . 'module`
             WHERE `id_module` = ' . (int) $id
        );
        if (!$row) {
            throw new ResourceNotFoundException('Module', $id);
        }

        if (isset($data['active'])) {
            \Db::getInstance()->update(
                'module',
                ['active' => (int) (bool) $data['active']],
                '`id_module` = ' . (int) $id
            );
            $row['active'] = (int) (bool) $data['active'];
        }

        return $this->mapRow($row);
    }

    public function delete(int $id, array $context): void
    {
        throw new \RuntimeException('Module uninstallation is not supported via API.', 405);
    }

    private function mapRow(array $row): array
    {
        return [
            'moduleId' => (int) $row['id_module'],
            'name'     => $row['name'],
            'active'   => (bool) $row['active'],
            'version'  => $row['version'] ?? '',
        ];
    }
}
