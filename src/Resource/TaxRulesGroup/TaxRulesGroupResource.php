<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\TaxRulesGroup;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class TaxRulesGroupResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/tax-rules-groups',
            'identifierKey'     => 'taxRulesGroupId',
            'operations'        => [
                'get'        => ['scope' => 'tax_rules_group_read',  'method' => 'GET'],
                'list'       => ['scope' => 'tax_rules_group_read',  'method' => 'GET'],
                'create'     => ['scope' => 'tax_rules_group_write', 'method' => 'POST'],
                'update'     => ['scope' => 'tax_rules_group_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'tax_rules_group_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'tax_rules_group_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $trg = new \TaxRulesGroup($id);
        if (!\Validate::isLoadedObject($trg)) {
            throw new ResourceNotFoundException('TaxRulesGroup', $id);
        }
        return $this->mapRow([
            'id_tax_rules_group' => $trg->id,
            'name'               => $trg->name,
            'active'             => $trg->active,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_tax_rules_group, name, active');
        $q->from('tax_rules_group');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_tax_rules_group', [
            'taxRulesGroupId' => 'id_tax_rules_group',
            'name'            => 'name',
        ]);
        $this->applyPagination($q, $filters, 'id_tax_rules_group');

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

        $trg         = new \TaxRulesGroup();
        $trg->name   = $data['name'];
        $trg->active = (bool) ($data['active'] ?? true);

        if (!$trg->save()) {
            throw new \RuntimeException('Failed to create tax rules group.', 500);
        }
        return $this->get((int) $trg->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $trg = new \TaxRulesGroup($id);
        if (!\Validate::isLoadedObject($trg)) {
            throw new ResourceNotFoundException('TaxRulesGroup', $id);
        }
        if (isset($data['name']))   { $trg->name   = $data['name']; }
        if (isset($data['active'])) { $trg->active  = (bool) $data['active']; }

        if (!$trg->save()) {
            throw new \RuntimeException('Failed to update tax rules group.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $trg = new \TaxRulesGroup($id);
        if (!\Validate::isLoadedObject($trg)) {
            throw new ResourceNotFoundException('TaxRulesGroup', $id);
        }
        if (!$trg->delete()) {
            throw new \RuntimeException('Failed to delete tax rules group.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['taxRulesGroupIds'] ?? []);
        foreach ($ids as $id) {
            $trg = new \TaxRulesGroup($id);
            if (\Validate::isLoadedObject($trg)) {
                $trg->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'taxRulesGroupId' => (int) $row['id_tax_rules_group'],
            'name'            => $row['name'],
            'active'          => (bool) $row['active'],
        ];
    }
}
