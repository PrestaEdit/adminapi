<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\SearchAlias;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class SearchAliasResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/search-aliases',
            'identifierKey'     => 'searchAliasId',
            'operations'        => [
                'get'        => ['scope' => 'search_alias_read',  'method' => 'GET'],
                'list'       => ['scope' => 'search_alias_read',  'method' => 'GET'],
                'create'     => ['scope' => 'search_alias_write', 'method' => 'POST'],
                'update'     => ['scope' => 'search_alias_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'search_alias_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'search_alias_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $alias = new \Alias($id);
        if (!\Validate::isLoadedObject($alias)) {
            throw new ResourceNotFoundException('SearchAlias', $id);
        }
        return $this->mapRow([
            'id_alias' => $alias->id,
            'search'   => $alias->search,
            'alias'    => $alias->alias,
            'active'   => $alias->active,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_alias, search, alias, active');
        $q->from('alias');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_alias', [
            'searchAliasId' => 'id_alias',
            'search'        => 'search',
            'alias'         => 'alias',
        ]);
        $this->applyPagination($q, $filters, 'id_alias');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['search', 'alias']);

        $alias         = new \Alias();
        $alias->search = $data['search'];
        $alias->alias  = $data['alias'];
        $alias->active = (bool) ($data['active'] ?? true);

        if (!$alias->save()) {
            throw new \RuntimeException('Failed to create search alias.', 500);
        }
        return $this->get((int) $alias->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $alias = new \Alias($id);
        if (!\Validate::isLoadedObject($alias)) {
            throw new ResourceNotFoundException('SearchAlias', $id);
        }
        if (isset($data['search'])) { $alias->search = $data['search']; }
        if (isset($data['alias']))  { $alias->alias  = $data['alias']; }
        if (isset($data['active'])) { $alias->active  = (bool) $data['active']; }

        if (!$alias->save()) {
            throw new \RuntimeException('Failed to update search alias.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $alias = new \Alias($id);
        if (!\Validate::isLoadedObject($alias)) {
            throw new ResourceNotFoundException('SearchAlias', $id);
        }
        if (!$alias->delete()) {
            throw new \RuntimeException('Failed to delete search alias.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['searchAliasIds'] ?? []);
        foreach ($ids as $id) {
            $alias = new \Alias($id);
            if (\Validate::isLoadedObject($alias)) {
                $alias->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'searchAliasId' => (int) $row['id_alias'],
            'search'        => $row['search'],
            'alias'         => $row['alias'],
            'active'        => (bool) $row['active'],
        ];
    }
}
