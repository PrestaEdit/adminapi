<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Hook;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class HookResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/hooks',
            'identifierKey'     => 'hookId',
            'operations'        => [
                'get'        => ['scope' => 'hook_read',  'method' => 'GET'],
                'list'       => ['scope' => 'hook_read',  'method' => 'GET'],
                'create'     => ['scope' => 'hook_write', 'method' => 'POST'],
                'update'     => ['scope' => 'hook_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'hook_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'hook_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $hook = new \Hook($id);
        if (!\Validate::isLoadedObject($hook)) {
            throw new ResourceNotFoundException('Hook', $id);
        }
        return $this->mapRow([
            'id_hook'     => $hook->id,
            'name'        => $hook->name,
            'title'       => $hook->title,
            'description' => $hook->description,
            'active'      => $hook->active,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_hook, name, title, description, active');
        $q->from('hook');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_hook', [
            'hookId' => 'id_hook',
            'name'   => 'name',
        ]);
        $this->applyPagination($q, $filters, 'id_hook');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['name']);

        $hook              = new \Hook();
        $hook->name        = $data['name'];
        $hook->title       = $data['title'] ?? '';
        $hook->description = $data['description'] ?? '';
        $hook->active      = (bool) ($data['active'] ?? true);

        if (!$hook->save()) {
            throw new \RuntimeException('Failed to create hook.', 500);
        }
        return $this->get((int) $hook->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $hook = new \Hook($id);
        if (!\Validate::isLoadedObject($hook)) {
            throw new ResourceNotFoundException('Hook', $id);
        }
        if (isset($data['name']))        { $hook->name        = $data['name']; }
        if (isset($data['title']))       { $hook->title       = $data['title']; }
        if (isset($data['description'])) { $hook->description = $data['description']; }
        if (isset($data['active']))      { $hook->active      = (bool) $data['active']; }

        if (!$hook->save()) {
            throw new \RuntimeException('Failed to update hook.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $hook = new \Hook($id);
        if (!\Validate::isLoadedObject($hook)) {
            throw new ResourceNotFoundException('Hook', $id);
        }
        if (!$hook->delete()) {
            throw new \RuntimeException('Failed to delete hook.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['hookIds'] ?? []);
        foreach ($ids as $id) {
            $hook = new \Hook($id);
            if (\Validate::isLoadedObject($hook)) {
                $hook->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'hookId'      => (int) $row['id_hook'],
            'name'        => $row['name'],
            'title'       => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'active'      => (bool) $row['active'],
        ];
    }
}
