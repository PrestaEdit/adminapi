<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Discount;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\CartRule\CartRuleResource;

class DiscountResource extends CartRuleResource
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/discounts',
            'identifierKey'     => 'discountId',
            'operations'        => [
                'get'        => ['scope' => 'discount_read',  'method' => 'GET'],
                'list'       => ['scope' => 'discount_read',  'method' => 'GET'],
                'create'     => ['scope' => 'discount_write', 'method' => 'POST'],
                'update'     => ['scope' => 'discount_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'discount_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'discount_write',
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
        if (!\Validate::isLoadedObject($cr) || $cr->code === '') {
            throw new ResourceNotFoundException('Discount', $id);
        }
        $data               = $this->map($cr);
        $data['discountId'] = $data['cartRuleId'];
        unset($data['cartRuleId']);
        return $data;
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('cr.id_cart_rule');
        $q->from('cart_rule', 'cr');
        $q->where("cr.code != ''");

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'cr.id_cart_rule', [
            'discountId' => 'cr.id_cart_rule',
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
        $this->requireFields($data, ['names', 'code', 'dateFrom', 'dateTo']);
        $result               = $this->persist(new \CartRule(), $data, $context);
        $result['discountId'] = $result['cartRuleId'];
        unset($result['cartRuleId']);
        return $result;
    }

    public function update(int $id, array $data, array $context): array
    {
        $cr = new \CartRule($id, $context['langId']);
        if (!\Validate::isLoadedObject($cr) || $cr->code === '') {
            throw new ResourceNotFoundException('Discount', $id);
        }
        $result               = $this->persist($cr, $data, $context);
        $result['discountId'] = $result['cartRuleId'];
        unset($result['cartRuleId']);
        return $result;
    }

    public function delete(int $id, array $context): void
    {
        $cr = new \CartRule($id);
        if (!\Validate::isLoadedObject($cr) || $cr->code === '') {
            throw new ResourceNotFoundException('Discount', $id);
        }
        if (!$cr->delete()) {
            throw new \RuntimeException('Failed to delete discount.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['discountIds'] ?? []);
        foreach ($ids as $id) {
            $cr = new \CartRule($id);
            if (\Validate::isLoadedObject($cr) && $cr->code !== '') {
                $cr->delete();
            }
        }
    }
}
