<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\ProductCombination;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ProductCombinationResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/product-combinations',
            'identifierKey'     => 'combinationId',
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
        $combo = new \Combination($id);
        if (!\Validate::isLoadedObject($combo)) {
            throw new ResourceNotFoundException('ProductCombination', $id);
        }
        return $this->map($combo);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('pa.id_product_attribute');
        $q->from('product_attribute', 'pa');

        if (isset($filters['productId'])) {
            $q->where('pa.id_product = ' . (int) $filters['productId']);
        }

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'pa.id_product_attribute', [
            'combinationId' => 'pa.id_product_attribute',
            'idProduct'     => 'pa.id_product',
            'reference'     => 'pa.reference',
        ]);
        $this->applyPagination($q, $filters, 'id_product_attribute');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_product_attribute'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idProduct', 'attributeIds']);

        $combo             = new \Combination();
        $combo->id_product = (int) $data['idProduct'];
        $this->hydrate($combo, $data);

        if (!$combo->save()) {
            throw new \RuntimeException('Failed to create product combination.', 500);
        }

        $combo->setAttributes(array_map('intval', $data['attributeIds']));

        return $this->get((int) $combo->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $combo = new \Combination($id);
        if (!\Validate::isLoadedObject($combo)) {
            throw new ResourceNotFoundException('ProductCombination', $id);
        }
        $this->hydrate($combo, $data);

        if (!$combo->save()) {
            throw new \RuntimeException('Failed to update product combination.', 500);
        }

        if (isset($data['attributeIds'])) {
            $combo->setAttributes(array_map('intval', $data['attributeIds']));
        }

        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $combo = new \Combination($id);
        if (!\Validate::isLoadedObject($combo)) {
            throw new ResourceNotFoundException('ProductCombination', $id);
        }
        if (!$combo->delete()) {
            throw new \RuntimeException('Failed to delete product combination.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['combinationIds'] ?? []);
        foreach ($ids as $id) {
            $combo = new \Combination($id);
            if (\Validate::isLoadedObject($combo)) {
                $combo->delete();
            }
        }
    }

    private function hydrate(\Combination $combo, array $data): void
    {
        if (isset($data['reference']))          { $combo->reference          = $data['reference']; }
        if (isset($data['ean13']))              { $combo->ean13              = $data['ean13']; }
        if (isset($data['isbn']))               { $combo->isbn               = $data['isbn']; }
        if (isset($data['upc']))                { $combo->upc                = $data['upc']; }
        if (isset($data['mpn']))                { $combo->mpn                = $data['mpn']; }
        if (isset($data['price']))              { $combo->price              = (float) $data['price']; }
        if (isset($data['weight']))             { $combo->weight             = (float) $data['weight']; }
        if (isset($data['ecotax']))             { $combo->ecotax             = (float) $data['ecotax']; }
        if (isset($data['unitPriceImpact']))    { $combo->unit_price_impact  = (float) $data['unitPriceImpact']; }
        if (isset($data['minimalQuantity']))    { $combo->minimal_quantity   = (int) $data['minimalQuantity']; }
        if (isset($data['lowStockThreshold'])) { $combo->low_stock_threshold = (int) $data['lowStockThreshold']; }
        if (isset($data['defaultOn']))          { $combo->default_on         = (bool) $data['defaultOn']; }
        if (isset($data['availableDate']))      { $combo->available_date     = $data['availableDate']; }
    }

    private function map(\Combination $combo): array
    {
        $attrRows = \Db::getInstance()->executeS(
            'SELECT `id_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute_combination`
             WHERE `id_product_attribute` = ' . (int) $combo->id
        );
        $attributeIds = array_map('intval', array_column($attrRows ?: [], 'id_attribute'));

        return [
            'combinationId'     => (int) $combo->id,
            'idProduct'         => (int) $combo->id_product,
            'reference'         => $combo->reference ?? '',
            'ean13'             => $combo->ean13 ?? '',
            'isbn'              => $combo->isbn ?? '',
            'upc'               => $combo->upc ?? '',
            'mpn'               => $combo->mpn ?? '',
            'price'             => $this->decimal($combo->price),
            'weight'            => $this->decimal($combo->weight),
            'ecotax'            => $this->decimal($combo->ecotax),
            'unitPriceImpact'   => $this->decimal($combo->unit_price_impact),
            'minimalQuantity'   => (int) $combo->minimal_quantity,
            'lowStockThreshold' => (int) $combo->low_stock_threshold,
            'defaultOn'         => (bool) $combo->default_on,
            'availableDate'     => $combo->available_date ?? '',
            'attributeIds'      => $attributeIds,
        ];
    }
}
