<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\CustomerGroup;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CustomerGroupResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/customer-groups',
            'identifierKey'     => 'customerGroupId',
            'operations'        => [
                'get'        => ['scope' => 'customer_group_read',  'method' => 'GET'],
                'list'       => ['scope' => 'customer_group_read',  'method' => 'GET'],
                'create'     => ['scope' => 'customer_group_write', 'method' => 'POST'],
                'update'     => ['scope' => 'customer_group_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'customer_group_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'customer_group_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $group = new \Group($id, $context['langId']);
        if (!\Validate::isLoadedObject($group)) {
            throw new ResourceNotFoundException('CustomerGroup', $id);
        }
        return $this->map($group);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('g.id_group');
        $q->from('group', 'g');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'g.id_group', [
            'customerGroupId' => 'g.id_group',
        ]);
        $this->applyPagination($q, $filters, 'id_group');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_group'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $group                       = new \Group();
        $group->name                 = $this->buildPsLocalizedField($data['names']);
        $group->reduction            = (float) ($data['reduction'] ?? 0);
        $group->price_display_method = (int) ($data['priceDisplayMethod'] ?? 0);
        $group->show_prices          = (bool) ($data['showPrices'] ?? true);

        if (!$group->save()) {
            throw new \RuntimeException('Failed to create customer group.', 500);
        }
        return $this->get((int) $group->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $group = new \Group($id, $context['langId']);
        if (!\Validate::isLoadedObject($group)) {
            throw new ResourceNotFoundException('CustomerGroup', $id);
        }
        if (isset($data['names']))              { $group->name                 = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['reduction']))          { $group->reduction            = (float) $data['reduction']; }
        if (isset($data['priceDisplayMethod'])) { $group->price_display_method = (int) $data['priceDisplayMethod']; }
        if (isset($data['showPrices']))         { $group->show_prices          = (bool) $data['showPrices']; }

        if (!$group->save()) {
            throw new \RuntimeException('Failed to update customer group.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $group = new \Group($id);
        if (!\Validate::isLoadedObject($group)) {
            throw new ResourceNotFoundException('CustomerGroup', $id);
        }
        if (!$group->delete()) {
            throw new \RuntimeException('Failed to delete customer group.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['customerGroupIds'] ?? []);
        foreach ($ids as $id) {
            $group = new \Group($id);
            if (\Validate::isLoadedObject($group)) {
                $group->delete();
            }
        }
    }

    private function map(\Group $group): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'group_lang`
             WHERE `id_group` = ' . (int) $group->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'customerGroupId'    => (int) $group->id,
            'reduction'          => $this->decimal($group->reduction),
            'priceDisplayMethod' => (int) $group->price_display_method,
            'showPrices'         => (bool) $group->show_prices,
            'dateAdd'            => $group->date_add ?? '',
            'dateUpd'            => $group->date_upd ?? '',
            'names'              => $this->getLocalizedField($names),
        ];
    }
}
