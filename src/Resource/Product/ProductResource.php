<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Product;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ProductResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/products',
            'identifierKey'     => 'productId',
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
        $product = new \Product($id, false, $context['langId']);
        if (!\Validate::isLoadedObject($product)) {
            throw new ResourceNotFoundException('Product', $id);
        }
        return $this->map($product, $context);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('p.id_product');
        $q->from('product', 'p');
        $q->where('p.state = 1');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'p.id_product', [
            'productId' => 'p.id_product',
            'reference' => 'p.reference',
            'price'     => 'p.price',
            'dateAdd'   => 'p.date_add',
        ]);
        $this->applyPagination($q, $filters, 'id_product');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_product'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names', 'linkRewrites', 'price']);

        $product = new \Product();
        $this->hydrate($product, $data);

        // Defaults for new product
        $product->state = 1;
        if (!isset($data['idCategoryDefault'])) {
            $product->id_category_default = (int) \Configuration::get('PS_HOME_CATEGORY');
        }

        if (!$product->save()) {
            throw new \RuntimeException('Failed to create product.', 500);
        }

        // Associate to default category
        $product->addToCategories([(int) $product->id_category_default]);

        return $this->get((int) $product->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $product = new \Product($id, false, $context['langId']);
        if (!\Validate::isLoadedObject($product)) {
            throw new ResourceNotFoundException('Product', $id);
        }
        $this->hydrate($product, $data);

        if (!$product->save()) {
            throw new \RuntimeException('Failed to update product.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $product = new \Product($id);
        if (!\Validate::isLoadedObject($product)) {
            throw new ResourceNotFoundException('Product', $id);
        }
        if (!$product->delete()) {
            throw new \RuntimeException('Failed to delete product.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['productIds'] ?? []);
        foreach ($ids as $id) {
            $product = new \Product($id);
            if (\Validate::isLoadedObject($product)) {
                $product->delete();
            }
        }
    }

    // ── Hydrate ──────────────────────────────────────────────────────────────

    private function hydrate(\Product $product, array $data): void
    {
        if (isset($data['names']))             { $product->name                    = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['linkRewrites']))      { $product->link_rewrite            = $this->buildPsLocalizedField($data['linkRewrites']); }
        if (isset($data['descriptions']))      { $product->description             = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['shortDescriptions'])) { $product->description_short       = $this->buildPsLocalizedField($data['shortDescriptions']); }
        if (isset($data['metaTitles']))        { $product->meta_title              = $this->buildPsLocalizedField($data['metaTitles']); }
        if (isset($data['metaDescriptions'])) { $product->meta_description         = $this->buildPsLocalizedField($data['metaDescriptions']); }
        if (isset($data['metaKeywords']))      { $product->meta_keywords           = $this->buildPsLocalizedField($data['metaKeywords']); }
        if (isset($data['availableNow']))      { $product->available_now           = $this->buildPsLocalizedField($data['availableNow']); }
        if (isset($data['availableLater']))    { $product->available_later         = $this->buildPsLocalizedField($data['availableLater']); }

        if (isset($data['price']))                 { $product->price                    = (float) $data['price']; }
        if (isset($data['wholesalePrice']))        { $product->wholesale_price          = (float) $data['wholesalePrice']; }
        if (isset($data['ecotax']))                { $product->ecotax                   = (float) $data['ecotax']; }
        if (isset($data['unitPriceRatio']))        { $product->unit_price_ratio         = (float) $data['unitPriceRatio']; }
        if (isset($data['additionalShippingCost'])){ $product->additional_shipping_cost = (float) $data['additionalShippingCost']; }
        if (isset($data['weight']))                { $product->weight                   = (float) $data['weight']; }
        if (isset($data['width']))                 { $product->width                    = (float) $data['width']; }
        if (isset($data['height']))                { $product->height                   = (float) $data['height']; }
        if (isset($data['depth']))                 { $product->depth                    = (float) $data['depth']; }

        if (isset($data['idCategoryDefault']))  { $product->id_category_default      = (int) $data['idCategoryDefault']; }
        if (isset($data['idTaxRulesGroup']))    { $product->id_tax_rules_group       = (int) $data['idTaxRulesGroup']; }
        if (isset($data['idManufacturer']))     { $product->id_manufacturer          = (int) $data['idManufacturer']; }
        if (isset($data['idSupplier']))         { $product->id_supplier              = (int) $data['idSupplier']; }

        if (isset($data['reference']))          { $product->reference                = $data['reference']; }
        if (isset($data['ean13']))              { $product->ean13                    = $data['ean13']; }
        if (isset($data['isbn']))               { $product->isbn                     = $data['isbn']; }
        if (isset($data['upc']))                { $product->upc                      = $data['upc']; }
        if (isset($data['mpn']))                { $product->mpn                      = $data['mpn']; }
        if (isset($data['unity']))              { $product->unity                    = $data['unity']; }
        if (isset($data['minimalQuantity']))    { $product->minimal_quantity         = (int) $data['minimalQuantity']; }
        if (isset($data['lowStockThreshold'])) { $product->low_stock_threshold      = (int) $data['lowStockThreshold']; }

        if (isset($data['active']))              { $product->active                   = (bool) $data['active']; }
        if (isset($data['availableForOrder']))   { $product->available_for_order      = (bool) $data['availableForOrder']; }
        if (isset($data['onSale']))              { $product->on_sale                  = (bool) $data['onSale']; }
        if (isset($data['onlineOnly']))          { $product->online_only              = (bool) $data['onlineOnly']; }
        if (isset($data['showCondition']))       { $product->show_condition           = (bool) $data['showCondition']; }
        if (isset($data['availableDate']))       { $product->available_date           = $data['availableDate']; }

        if (isset($data['visibility']) && in_array($data['visibility'], ['both', 'catalog', 'search', 'none'], true)) {
            $product->visibility = $data['visibility'];
        }
        if (isset($data['condition']) && in_array($data['condition'], ['new', 'used', 'refurbished'], true)) {
            $product->condition = $data['condition'];
        }
    }

    // ── Map ──────────────────────────────────────────────────────────────────

    private function map(\Product $product, array $context): array
    {
        // Fetch all lang fields; ps_product_lang has composite PK (id_product, id_lang, id_shop)
        $langRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name`, `description`, `description_short`, `link_rewrite`,
                    `meta_title`, `meta_description`, `meta_keywords`,
                    `available_now`, `available_later`
             FROM `' . _DB_PREFIX_ . 'product_lang`
             WHERE `id_product` = ' . (int) $product->id . '
               AND `id_shop` = 1'
        );

        $names          = array_column($langRows ?: [], 'name',              'id_lang');
        $descs          = array_column($langRows ?: [], 'description',       'id_lang');
        $shortDescs     = array_column($langRows ?: [], 'description_short', 'id_lang');
        $linkRewrites   = array_column($langRows ?: [], 'link_rewrite',      'id_lang');
        $metaTitles     = array_column($langRows ?: [], 'meta_title',        'id_lang');
        $metaDescs      = array_column($langRows ?: [], 'meta_description',  'id_lang');
        $metaKeys       = array_column($langRows ?: [], 'meta_keywords',     'id_lang');
        $availableNow   = array_column($langRows ?: [], 'available_now',     'id_lang');
        $availableLater = array_column($langRows ?: [], 'available_later',   'id_lang');

        // Stock quantity (base product, no combination)
        $stockRow = \Db::getInstance()->getRow(
            'SELECT `quantity` FROM `' . _DB_PREFIX_ . 'stock_available`
             WHERE `id_product` = ' . (int) $product->id . '
               AND `id_product_attribute` = 0
               AND `id_shop` = 1'
        );
        $quantity = $stockRow ? (int) $stockRow['quantity'] : 0;

        return [
            'productId'              => (int) $product->id,
            'reference'              => $product->reference ?? '',
            'ean13'                  => $product->ean13 ?? '',
            'isbn'                   => $product->isbn ?? '',
            'upc'                    => $product->upc ?? '',
            'mpn'                    => $product->mpn ?? '',
            'idCategoryDefault'      => (int) $product->id_category_default,
            'idTaxRulesGroup'        => (int) $product->id_tax_rules_group,
            'idManufacturer'         => (int) $product->id_manufacturer,
            'idSupplier'             => (int) $product->id_supplier,
            'price'                  => $this->decimal($product->price),
            'wholesalePrice'         => $this->decimal($product->wholesale_price),
            'ecotax'                 => $this->decimal($product->ecotax),
            'unitPriceRatio'         => $this->decimal($product->unit_price_ratio),
            'additionalShippingCost' => $this->decimal($product->additional_shipping_cost),
            'weight'                 => $this->decimal($product->weight),
            'width'                  => $this->decimal($product->width),
            'height'                 => $this->decimal($product->height),
            'depth'                  => $this->decimal($product->depth),
            'minimalQuantity'        => (int) $product->minimal_quantity,
            'lowStockThreshold'      => (int) $product->low_stock_threshold,
            'quantity'               => $quantity,
            'active'                 => (bool) $product->active,
            'availableForOrder'      => (bool) $product->available_for_order,
            'onSale'                 => (bool) $product->on_sale,
            'onlineOnly'             => (bool) $product->online_only,
            'showCondition'          => (bool) $product->show_condition,
            'visibility'             => $product->visibility ?? 'both',
            'condition'              => $product->condition ?? 'new',
            'availableDate'          => $product->available_date ?? '',
            'unity'                  => $product->unity ?? '',
            'dateAdd'                => $product->date_add ?? '',
            'dateUpd'                => $product->date_upd ?? '',
            'names'                  => $this->getLocalizedField($names),
            'descriptions'           => $this->getLocalizedField($descs),
            'shortDescriptions'      => $this->getLocalizedField($shortDescs),
            'linkRewrites'           => $this->getLocalizedField($linkRewrites),
            'metaTitles'             => $this->getLocalizedField($metaTitles),
            'metaDescriptions'       => $this->getLocalizedField($metaDescs),
            'metaKeywords'           => $this->getLocalizedField($metaKeys),
            'availableNow'           => $this->getLocalizedField($availableNow),
            'availableLater'         => $this->getLocalizedField($availableLater),
        ];
    }
}
