<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Supplier;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class SupplierResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/suppliers',
            'identifierKey'     => 'supplierId',
            'operations'        => [
                'get'        => ['scope' => 'supplier_read',  'method' => 'GET'],
                'list'       => ['scope' => 'supplier_read',  'method' => 'GET'],
                'create'     => ['scope' => 'supplier_write', 'method' => 'POST'],
                'update'     => ['scope' => 'supplier_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'supplier_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'supplier_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $s = new \Supplier($id, $context['langId']);
        if (!\Validate::isLoadedObject($s)) {
            throw new ResourceNotFoundException('Supplier', $id);
        }
        return $this->map($s);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('s.id_supplier');
        $q->from('supplier', 's');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 's.id_supplier', [
            'supplierId' => 's.id_supplier',
            'name'       => 's.name',
        ]);
        $this->applyPagination($q, $filters, 'id_supplier');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_supplier'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['name']);

        $s         = new \Supplier();
        $s->name   = $data['name'];
        $s->active = (bool) ($data['active'] ?? true);

        if (isset($data['descriptions'])) {
            $s->description = $this->buildPsLocalizedField($data['descriptions']);
        }

        if (!$s->save()) {
            throw new \RuntimeException('Failed to create supplier.', 500);
        }
        return $this->get((int) $s->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $s = new \Supplier($id, $context['langId']);
        if (!\Validate::isLoadedObject($s)) {
            throw new ResourceNotFoundException('Supplier', $id);
        }
        if (isset($data['name']))         { $s->name        = $data['name']; }
        if (isset($data['active']))       { $s->active       = (bool) $data['active']; }
        if (isset($data['descriptions'])) { $s->description  = $this->buildPsLocalizedField($data['descriptions']); }

        if (!$s->save()) {
            throw new \RuntimeException('Failed to update supplier.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $s = new \Supplier($id);
        if (!\Validate::isLoadedObject($s)) {
            throw new ResourceNotFoundException('Supplier', $id);
        }
        if (!$s->delete()) {
            throw new \RuntimeException('Failed to delete supplier.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['supplierIds'] ?? []);
        foreach ($ids as $id) {
            $s = new \Supplier($id);
            if (\Validate::isLoadedObject($s)) {
                $s->delete();
            }
        }
    }

    private function map(\Supplier $s): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `description`
             FROM `' . _DB_PREFIX_ . 'supplier_lang`
             WHERE `id_supplier` = ' . (int) $s->id
        );
        $descs = array_column($rows ?: [], 'description', 'id_lang');

        return [
            'supplierId'   => (int) $s->id,
            'name'         => $s->name,
            'active'       => (bool) $s->active,
            'dateAdd'      => $s->date_add,
            'dateUpd'      => $s->date_upd,
            'descriptions' => $this->getLocalizedField($descs),
        ];
    }
}
