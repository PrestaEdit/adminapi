<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Zone;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ZoneResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/zones',
            'identifierKey'     => 'zoneId',
            'operations'        => [
                'get'        => ['scope' => 'zone_read',  'method' => 'GET'],
                'list'       => ['scope' => 'zone_read',  'method' => 'GET'],
                'create'     => ['scope' => 'zone_write', 'method' => 'POST'],
                'update'     => ['scope' => 'zone_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'zone_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'zone_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $zone = new \Zone($id);
        if (!\Validate::isLoadedObject($zone)) {
            throw new ResourceNotFoundException('Zone', $id);
        }
        return $this->mapRow([
            'id_zone' => $zone->id,
            'name'    => $zone->name,
            'active'  => $zone->active,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_zone, name, active');
        $q->from('zone');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_zone', [
            'zoneId' => 'id_zone',
            'name'   => 'name',
        ]);
        $this->applyPagination($q, $filters, 'id_zone');

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

        $zone         = new \Zone();
        $zone->name   = $data['name'];
        $zone->active = (bool) ($data['active'] ?? true);

        if (!$zone->save()) {
            throw new \RuntimeException('Failed to create zone.', 500);
        }
        return $this->get((int) $zone->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $zone = new \Zone($id);
        if (!\Validate::isLoadedObject($zone)) {
            throw new ResourceNotFoundException('Zone', $id);
        }
        if (isset($data['name']))   { $zone->name   = $data['name']; }
        if (isset($data['active'])) { $zone->active  = (bool) $data['active']; }

        if (!$zone->save()) {
            throw new \RuntimeException('Failed to update zone.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $zone = new \Zone($id);
        if (!\Validate::isLoadedObject($zone)) {
            throw new ResourceNotFoundException('Zone', $id);
        }
        if (!$zone->delete()) {
            throw new \RuntimeException('Failed to delete zone.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['zoneIds'] ?? []);
        foreach ($ids as $id) {
            $zone = new \Zone($id);
            if (\Validate::isLoadedObject($zone)) {
                $zone->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'zoneId' => (int) $row['id_zone'],
            'name'   => $row['name'],
            'active' => (bool) $row['active'],
        ];
    }
}
