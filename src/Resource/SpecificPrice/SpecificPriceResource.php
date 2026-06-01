<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\SpecificPrice;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Exception\ValidationException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class SpecificPriceResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/specific-prices',
            'identifierKey'     => 'specificPriceId',
            'operations'        => [
                'get'        => ['scope' => 'product_read',  'method' => 'GET'],
                'list'       => ['scope' => 'product_read',  'method' => 'GET'],
                'create'     => ['scope' => 'product_write', 'method' => 'POST'],
                'update'     => ['scope' => 'product_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'product_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'product_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $sp = new \SpecificPrice($id);
        if (!\Validate::isLoadedObject($sp)) {
            throw new ResourceNotFoundException('SpecificPrice', $id);
        }
        return $this->mapRow($sp);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('sp.id_specific_price');
        $q->from('specific_price', 'sp');

        if (isset($filters['productId'])) {
            $q->where('sp.id_product = ' . (int) $filters['productId']);
        }

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'sp.id_specific_price', [
            'specificPriceId' => 'sp.id_specific_price',
            'idProduct'       => 'sp.id_product',
            'fromQuantity'    => 'sp.from_quantity',
        ]);
        $this->applyPagination($q, $filters, 'id_specific_price');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_specific_price'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idProduct']);
        $this->validateReductionType($data);

        $sp             = new \SpecificPrice();
        $sp->id_product = (int) $data['idProduct'];
        $sp->id_shop              = (int) ($data['idShop'] ?? 0);
        $sp->id_shop_group        = (int) ($data['idShopGroup'] ?? 0);
        $sp->id_currency          = (int) ($data['idCurrency'] ?? 0);
        $sp->id_country           = (int) ($data['idCountry'] ?? 0);
        $sp->id_group             = (int) ($data['idGroup'] ?? 0);
        $sp->id_customer          = (int) ($data['idCustomer'] ?? 0);
        $sp->id_product_attribute = (int) ($data['idProductAttribute'] ?? 0);
        $sp->price          = isset($data['price']) ? (float) $data['price'] : -1.0;
        $sp->from_quantity  = (int) ($data['fromQuantity'] ?? 1);
        $sp->reduction      = (float) ($data['reduction'] ?? 0);
        $sp->reduction_tax  = (int) (bool) ($data['reductionTax'] ?? true);
        $sp->reduction_type = $data['reductionType'] ?? 'amount';
        $sp->from           = $data['from'] ?? '0000-00-00 00:00:00';
        $sp->to             = $data['to'] ?? '0000-00-00 00:00:00';

        if (!$sp->save()) {
            throw new \RuntimeException('Failed to create specific price.', 500);
        }
        return $this->get((int) $sp->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $sp = new \SpecificPrice($id);
        if (!\Validate::isLoadedObject($sp)) {
            throw new ResourceNotFoundException('SpecificPrice', $id);
        }
        $this->validateReductionType($data);

        if (isset($data['idProduct']))          { $sp->id_product           = (int) $data['idProduct']; }
        if (isset($data['idShop']))             { $sp->id_shop              = (int) $data['idShop']; }
        if (isset($data['idShopGroup']))        { $sp->id_shop_group        = (int) $data['idShopGroup']; }
        if (isset($data['idCurrency']))         { $sp->id_currency          = (int) $data['idCurrency']; }
        if (isset($data['idCountry']))          { $sp->id_country           = (int) $data['idCountry']; }
        if (isset($data['idGroup']))            { $sp->id_group             = (int) $data['idGroup']; }
        if (isset($data['idCustomer']))         { $sp->id_customer          = (int) $data['idCustomer']; }
        if (isset($data['idProductAttribute'])) { $sp->id_product_attribute = (int) $data['idProductAttribute']; }
        if (isset($data['price']))              { $sp->price                = (float) $data['price']; }
        if (isset($data['fromQuantity']))       { $sp->from_quantity        = (int) $data['fromQuantity']; }
        if (isset($data['reduction']))          { $sp->reduction            = (float) $data['reduction']; }
        if (isset($data['reductionTax']))       { $sp->reduction_tax        = (int) (bool) $data['reductionTax']; }
        if (isset($data['reductionType']))      { $sp->reduction_type       = $data['reductionType']; }
        if (isset($data['from']))               { $sp->from                 = $data['from']; }
        if (isset($data['to']))                 { $sp->to                   = $data['to']; }

        if (!$sp->save()) {
            throw new \RuntimeException('Failed to update specific price.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $sp = new \SpecificPrice($id);
        if (!\Validate::isLoadedObject($sp)) {
            throw new ResourceNotFoundException('SpecificPrice', $id);
        }
        if (!$sp->delete()) {
            throw new \RuntimeException('Failed to delete specific price.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['specificPriceIds'] ?? []);
        foreach ($ids as $id) {
            $sp = new \SpecificPrice($id);
            if (\Validate::isLoadedObject($sp)) {
                $sp->delete();
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     * @throws ValidationException
     */
    private function validateReductionType(array $data): void
    {
        if (isset($data['reductionType'])
            && !in_array($data['reductionType'], ['amount', 'percentage'], true)) {
            throw new ValidationException([
                'reductionType' => ["Must be one of: 'amount', 'percentage'."],
            ]);
        }
    }

    private function mapRow(\SpecificPrice $sp): array
    {
        return [
            'specificPriceId'    => (int) $sp->id,
            'idProduct'          => (int) $sp->id_product,
            'idProductAttribute' => (int) $sp->id_product_attribute,
            'idShop'             => (int) $sp->id_shop,
            'idShopGroup'        => (int) $sp->id_shop_group,
            'idCurrency'         => (int) $sp->id_currency,
            'idCountry'          => (int) $sp->id_country,
            'idGroup'            => (int) $sp->id_group,
            'idCustomer'         => (int) $sp->id_customer,
            'price'              => $this->decimal($sp->price),
            'fromQuantity'       => (int) $sp->from_quantity,
            'reduction'          => $this->decimal($sp->reduction),
            'reductionTax'       => (bool) $sp->reduction_tax,
            'reductionType'      => $sp->reduction_type,
            'from'               => $sp->from,
            'to'                 => $sp->to,
        ];
    }
}
