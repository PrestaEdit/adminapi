<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Tax;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class TaxResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/taxes',
            'identifierKey'     => 'taxId',
            'operations'        => [
                'get'        => ['scope' => 'tax_read',  'method' => 'GET'],
                'list'       => ['scope' => 'tax_read',  'method' => 'GET'],
                'create'     => ['scope' => 'tax_write', 'method' => 'POST'],
                'update'     => ['scope' => 'tax_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'tax_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'tax_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $tax = new \Tax($id, $context['langId']);
        if (!\Validate::isLoadedObject($tax) || $tax->deleted) {
            throw new ResourceNotFoundException('Tax', $id);
        }
        return $this->map($tax);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_tax');
        $q->from('tax');
        $q->where('deleted = 0');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_tax', [
            'taxId' => 'id_tax',
            'rate'  => 'rate',
        ]);
        $this->applyPagination($q, $filters, 'id_tax');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_tax'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['rate', 'names']);

        $tax         = new \Tax();
        $tax->rate   = (float) $data['rate'];
        $tax->active = (bool) ($data['active'] ?? true);
        $tax->name   = $this->buildPsLocalizedField($data['names']);

        if (!$tax->save()) {
            throw new \RuntimeException('Failed to create tax.', 500);
        }
        return $this->get((int) $tax->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $tax = new \Tax($id, $context['langId']);
        if (!\Validate::isLoadedObject($tax) || $tax->deleted) {
            throw new ResourceNotFoundException('Tax', $id);
        }
        if (isset($data['rate']))   { $tax->rate   = (float) $data['rate']; }
        if (isset($data['active'])) { $tax->active  = (bool) $data['active']; }
        if (isset($data['names']))  { $tax->name    = $this->buildPsLocalizedField($data['names']); }

        if (!$tax->save()) {
            throw new \RuntimeException('Failed to update tax.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $tax = new \Tax($id);
        if (!\Validate::isLoadedObject($tax) || $tax->deleted) {
            throw new ResourceNotFoundException('Tax', $id);
        }
        $tax->deleted = 1;
        if (!$tax->save()) {
            throw new \RuntimeException('Failed to delete tax.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['taxIds'] ?? []);
        foreach ($ids as $id) {
            $tax = new \Tax($id);
            if (\Validate::isLoadedObject($tax) && !$tax->deleted) {
                $tax->deleted = 1;
                $tax->save();
            }
        }
    }

    private function map(\Tax $tax): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'tax_lang`
             WHERE `id_tax` = ' . (int) $tax->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'taxId'  => (int) $tax->id,
            'rate'   => $this->decimal($tax->rate),
            'active' => (bool) $tax->active,
            'names'  => $this->getLocalizedField($names),
        ];
    }
}
