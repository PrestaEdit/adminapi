<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Manufacturer;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ManufacturerResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/manufacturers',
            'identifierKey'     => 'manufacturerId',
            'operations'        => [
                'get'        => ['scope' => 'manufacturer_read',  'method' => 'GET'],
                'list'       => ['scope' => 'manufacturer_read',  'method' => 'GET'],
                'create'     => ['scope' => 'manufacturer_write', 'method' => 'POST'],
                'update'     => ['scope' => 'manufacturer_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'manufacturer_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'manufacturer_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $m = new \Manufacturer($id, $context['langId']);
        if (!\Validate::isLoadedObject($m)) {
            throw new ResourceNotFoundException('Manufacturer', $id);
        }
        return $this->map($m);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('m.id_manufacturer');
        $q->from('manufacturer', 'm');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'm.id_manufacturer', [
            'manufacturerId' => 'm.id_manufacturer',
            'name'           => 'm.name',
        ]);
        $this->applyPagination($q, $filters, 'id_manufacturer');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_manufacturer'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['name']);

        $m         = new \Manufacturer();
        $m->name   = $data['name'];
        $m->active = (bool) ($data['active'] ?? true);

        if (isset($data['descriptions']))      { $m->description       = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['shortDescriptions'])) { $m->short_description = $this->buildPsLocalizedField($data['shortDescriptions']); }

        if (!$m->save()) {
            throw new \RuntimeException('Failed to create manufacturer.', 500);
        }
        return $this->get((int) $m->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $m = new \Manufacturer($id, $context['langId']);
        if (!\Validate::isLoadedObject($m)) {
            throw new ResourceNotFoundException('Manufacturer', $id);
        }
        if (isset($data['name']))              { $m->name              = $data['name']; }
        if (isset($data['active']))            { $m->active            = (bool) $data['active']; }
        if (isset($data['descriptions']))      { $m->description       = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['shortDescriptions'])) { $m->short_description = $this->buildPsLocalizedField($data['shortDescriptions']); }

        if (!$m->save()) {
            throw new \RuntimeException('Failed to update manufacturer.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $m = new \Manufacturer($id);
        if (!\Validate::isLoadedObject($m)) {
            throw new ResourceNotFoundException('Manufacturer', $id);
        }
        if (!$m->delete()) {
            throw new \RuntimeException('Failed to delete manufacturer.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['manufacturerIds'] ?? []);
        foreach ($ids as $id) {
            $m = new \Manufacturer($id);
            if (\Validate::isLoadedObject($m)) {
                $m->delete();
            }
        }
    }

    private function map(\Manufacturer $m): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `description`, `short_description`
             FROM `' . _DB_PREFIX_ . 'manufacturer_lang`
             WHERE `id_manufacturer` = ' . (int) $m->id
        );

        $descs      = array_column($rows ?: [], 'description',       'id_lang');
        $shortDescs = array_column($rows ?: [], 'short_description', 'id_lang');

        return [
            'manufacturerId'    => (int) $m->id,
            'name'              => $m->name,
            'active'            => (bool) $m->active,
            'dateAdd'           => $m->date_add,
            'dateUpd'           => $m->date_upd,
            'descriptions'      => $this->getLocalizedField($descs),
            'shortDescriptions' => $this->getLocalizedField($shortDescs),
        ];
    }
}
