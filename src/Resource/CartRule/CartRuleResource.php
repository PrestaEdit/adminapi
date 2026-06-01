<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\CartRule;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CartRuleResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/cart-rules',
            'identifierKey'     => 'cartRuleId',
            'operations'        => [
                'get'        => ['scope' => 'cart_rule_read',  'method' => 'GET'],
                'list'       => ['scope' => 'cart_rule_read',  'method' => 'GET'],
                'create'     => ['scope' => 'cart_rule_write', 'method' => 'POST'],
                'update'     => ['scope' => 'cart_rule_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'cart_rule_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'cart_rule_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $cr = new \CartRule($id, $context['langId']);
        if (!\Validate::isLoadedObject($cr)) {
            throw new ResourceNotFoundException('CartRule', $id);
        }
        return $this->map($cr);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('cr.id_cart_rule');
        $q->from('cart_rule', 'cr');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'cr.id_cart_rule', [
            'cartRuleId' => 'cr.id_cart_rule',
            'dateFrom'   => 'cr.date_from',
            'dateTo'     => 'cr.date_to',
        ]);
        $this->applyPagination($q, $filters, 'id_cart_rule');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_cart_rule'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names', 'dateFrom', 'dateTo']);
        return $this->persist(new \CartRule(), $data, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $cr = new \CartRule($id, $context['langId']);
        if (!\Validate::isLoadedObject($cr)) {
            throw new ResourceNotFoundException('CartRule', $id);
        }
        return $this->persist($cr, $data, $context);
    }

    public function delete(int $id, array $context): void
    {
        $cr = new \CartRule($id);
        if (!\Validate::isLoadedObject($cr)) {
            throw new ResourceNotFoundException('CartRule', $id);
        }
        if (!$cr->delete()) {
            throw new \RuntimeException('Failed to delete cart rule.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['cartRuleIds'] ?? []);
        foreach ($ids as $id) {
            $cr = new \CartRule($id);
            if (\Validate::isLoadedObject($cr)) {
                $cr->delete();
            }
        }
    }

    protected function persist(\CartRule $cr, array $data, array $context): array
    {
        if (isset($data['names']))            { $cr->name              = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['code']))             { $cr->code              = $data['code']; }
        if (isset($data['description']))      { $cr->description       = $data['description']; }
        if (isset($data['dateFrom']))         { $cr->date_from         = $data['dateFrom']; }
        if (isset($data['dateTo']))           { $cr->date_to           = $data['dateTo']; }
        if (isset($data['quantity']))         { $cr->quantity          = (int) $data['quantity']; }
        if (isset($data['quantityPerUser'])) { $cr->quantity_per_user  = (int) $data['quantityPerUser']; }
        if (isset($data['active']))           { $cr->active            = (bool) $data['active']; }
        if (isset($data['freeShipping']))     { $cr->free_shipping     = (bool) $data['freeShipping']; }
        if (isset($data['reductionPercent'])){ $cr->reduction_percent  = (float) $data['reductionPercent']; }
        if (isset($data['reductionAmount'])) { $cr->reduction_amount  = (float) $data['reductionAmount']; }
        if (isset($data['reductionTax']))    { $cr->reduction_tax     = (bool) $data['reductionTax']; }
        if (isset($data['minimumAmount']))   { $cr->minimum_amount    = (float) $data['minimumAmount']; }
        if (isset($data['idCustomer']))      { $cr->id_customer       = (int) $data['idCustomer']; }
        if (isset($data['highlight']))       { $cr->highlight         = (bool) $data['highlight']; }

        // defaults for new cart rule only
        if (!$cr->id) {
            if (!isset($data['quantity']))       { $cr->quantity       = 1; }
            if (!isset($data['active']))         { $cr->active         = true; }
            $cr->partial_use = 1;
            $cr->priority    = 1;
        }

        if (!$cr->save()) {
            throw new \RuntimeException('Failed to save cart rule.', 500);
        }
        return $this->get((int) $cr->id, $context);
    }

    protected function map(\CartRule $cr): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'cart_rule_lang`
             WHERE `id_cart_rule` = ' . (int) $cr->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'cartRuleId'       => (int) $cr->id,
            'code'             => $cr->code ?? '',
            'description'      => $cr->description ?? '',
            'dateFrom'         => $cr->date_from,
            'dateTo'           => $cr->date_to,
            'quantity'         => (int) $cr->quantity,
            'quantityPerUser'  => (int) $cr->quantity_per_user,
            'active'           => (bool) $cr->active,
            'freeShipping'     => (bool) $cr->free_shipping,
            'reductionPercent' => $this->decimal($cr->reduction_percent),
            'reductionAmount'  => $this->decimal($cr->reduction_amount),
            'reductionTax'     => (bool) $cr->reduction_tax,
            'minimumAmount'    => $this->decimal($cr->minimum_amount),
            'idCustomer'       => (int) $cr->id_customer,
            'highlight'        => (bool) $cr->highlight,
            'names'            => $this->getLocalizedField($names),
        ];
    }
}
