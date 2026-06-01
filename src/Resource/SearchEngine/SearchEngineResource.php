<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\SearchEngine;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class SearchEngineResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/search-engines',
            'identifierKey'     => 'searchEngineId',
            'operations'        => [
                'get'        => ['scope' => 'search_engine_read',  'method' => 'GET'],
                'list'       => ['scope' => 'search_engine_read',  'method' => 'GET'],
                'create'     => ['scope' => 'search_engine_write', 'method' => 'POST'],
                'update'     => ['scope' => 'search_engine_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'search_engine_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'search_engine_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $se = new \SearchEngine($id);
        if (!\Validate::isLoadedObject($se)) {
            throw new ResourceNotFoundException('SearchEngine', $id);
        }
        return $this->mapRow([
            'id_search_engine' => $se->id,
            'server'           => $se->server,
            'getvar'           => $se->getvar,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_search_engine, server, getvar');
        $q->from('search_engine');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_search_engine', [
            'searchEngineId' => 'id_search_engine',
            'server'         => 'server',
        ]);
        $this->applyPagination($q, $filters, 'id_search_engine');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['server', 'getvar']);

        $se         = new \SearchEngine();
        $se->server = $data['server'];
        $se->getvar = $data['getvar'];

        if (!$se->save()) {
            throw new \RuntimeException('Failed to create search engine.', 500);
        }
        return $this->get((int) $se->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $se = new \SearchEngine($id);
        if (!\Validate::isLoadedObject($se)) {
            throw new ResourceNotFoundException('SearchEngine', $id);
        }
        if (isset($data['server'])) { $se->server = $data['server']; }
        if (isset($data['getvar'])) { $se->getvar = $data['getvar']; }

        if (!$se->save()) {
            throw new \RuntimeException('Failed to update search engine.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $se = new \SearchEngine($id);
        if (!\Validate::isLoadedObject($se)) {
            throw new ResourceNotFoundException('SearchEngine', $id);
        }
        if (!$se->delete()) {
            throw new \RuntimeException('Failed to delete search engine.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['searchEngineIds'] ?? []);
        foreach ($ids as $id) {
            $se = new \SearchEngine($id);
            if (\Validate::isLoadedObject($se)) {
                $se->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'searchEngineId' => (int) $row['id_search_engine'],
            'server'         => $row['server'],
            'getvar'         => $row['getvar'],
        ];
    }
}
