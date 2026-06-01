# PrestaShop Admin API — Plan E : Sous-ressources Product

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implémenter ProductCombination (variantes produit) et StockAvailable (gestion des stocks) — les deux ressources qui complètent le cycle de vie d'un produit PS 1.7.

**Architecture:** Même pattern ResourceInterface/AbstractResource. `ProductCombination` utilise la classe PS `Combination` (table `ps_product_attribute`) et expose le tableau `attributeIds` pour lier les attributs. `StockAvailable` expose `ps_stock_available` en lecture + mise à jour partielle (pas de create/delete — gérés automatiquement par PS). Les deux ressources supportent le filtre `?productId=X` dans `list()`.

**Tech Stack:** PHP >=7.4, PrestaShop 1.7.6+, `Combination` ObjectModel, `StockAvailable` ObjectModel, PHPUnit ^9.

---

## Structure des fichiers

```
src/Resource/
├── ProductCombination/ProductCombinationResource.php  ← Task 1
└── StockAvailable/StockAvailableResource.php          ← Task 2
src/Resource/ResourceRegistry.php                      ← Modifié dans chaque tâche
```

---

## Task 1 : ProductCombination

**Goal:** Implémenter ProductCombinationResource — variantes d'un produit (taille, couleur, etc.) via la table `ps_product_attribute`.

**Files:**
- Create: `src/Resource/ProductCombination/ProductCombinationResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /product-combinations` → 200 paginé (toutes les combinaisons)
- [ ] `GET /product-combinations?productId=1` → 200, uniquement les combinaisons du produit 1
- [ ] `GET /product-combinations/1` → 200 avec `{combinationId, idProduct, attributeIds, price, reference, ...}`
- [ ] `POST /product-combinations` sans `idProduct` ou `attributeIds` → 422
- [ ] `POST /product-combinations` avec `{idProduct:1, attributeIds:[1,2], price:5}` → 201
- [ ] `PATCH /product-combinations/1` → 200
- [ ] `DELETE /product-combinations/1` → 204
- [ ] `DELETE /product-combinations/bulk-delete` → 204
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/ProductCombination/ProductCombinationResource.php`**

Note : la classe PS est `Combination` (table `ps_product_attribute`, primary `id_product_attribute`). Les attributs d'une combinaison sont dans `ps_product_attribute_combination` (id_product_attribute, id_attribute). La méthode `setAttributes(array $attributeIds)` de `Combination` gère la table de liaison. Pas de table `_lang` — les combinaisons ne sont pas localisées.

Le champ `price` dans `ps_product_attribute` est un **delta de prix** (pas le prix final). `weight` est aussi un delta. `default_on` : 1 si c'est la combinaison par défaut du produit.

```php
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

        $combo              = new \Combination();
        $combo->id_product  = (int) $data['idProduct'];
        $this->hydrate($combo, $data);

        if (!$combo->save()) {
            throw new \RuntimeException('Failed to create product combination.', 500);
        }

        // Link to attributes
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

        // Re-set attributes if provided
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
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\ProductCombination\ProductCombinationResource;
// dans $resources :
ProductCombinationResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/ProductCombination/ProductCombinationResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/ProductCombination/ src/Resource/ResourceRegistry.php
git commit -m "feat: ProductCombinationResource — product variants with attribute linking"
```

---

## Task 2 : StockAvailable

**Goal:** Implémenter StockAvailableResource — lecture et mise à jour du stock disponible par produit/combinaison. Pas de create ni delete (gérés automatiquement par PS lors du save d'un produit/combinaison).

**Files:**
- Create: `src/Resource/StockAvailable/StockAvailableResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /stock-availables` → 200 paginé
- [ ] `GET /stock-availables?productId=1` → 200, stock du produit 1 uniquement
- [ ] `GET /stock-availables/1` → 200 avec `{stockAvailableId, idProduct, idProductAttribute, idShop, quantity, outOfStock}`
- [ ] `PATCH /stock-availables/1` avec `{quantity: 50}` → 200
- [ ] `POST /stock-availables` → 405 (create non supporté)
- [ ] `DELETE /stock-availables/1` → 405 (delete non supporté)
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/StockAvailable/StockAvailableResource.php`**

Note : la classe PS est `StockAvailable` (table `ps_stock_available`, primary `id_stock_available`). PS crée automatiquement les entrées lors du save d'un produit ou d'une combinaison — pas de create/delete via l'API. Seuls `get`, `list`, `update` sont déclarés dans `definition()`. Les méthodes `create()` et `delete()` (requises par ResourceInterface) lèvent une RuntimeException 405.

Lors du `update()`, utiliser `\StockAvailable::setQuantity()` pour mettre à jour la quantité proprement (PS met à jour les caches nécessaires). Si seulement `outOfStock` change, faire un `$sa->save()` direct.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\StockAvailable;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class StockAvailableResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/stock-availables',
            'identifierKey'     => 'stockAvailableId',
            'operations'        => [
                'get'    => ['scope' => 'product_read',  'method' => 'GET'],
                'list'   => ['scope' => 'product_read',  'method' => 'GET'],
                'update' => ['scope' => 'product_write', 'method' => 'PATCH'],
                // create and delete managed automatically by PrestaShop
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $sa = new \StockAvailable($id);
        if (!\Validate::isLoadedObject($sa)) {
            throw new ResourceNotFoundException('StockAvailable', $id);
        }
        return $this->mapRow($sa);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('sa.id_stock_available');
        $q->from('stock_available', 'sa');

        if (isset($filters['productId'])) {
            $q->where('sa.id_product = ' . (int) $filters['productId']);
        }

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'sa.id_stock_available', [
            'stockAvailableId'    => 'sa.id_stock_available',
            'idProduct'           => 'sa.id_product',
            'idProductAttribute'  => 'sa.id_product_attribute',
        ]);
        $this->applyPagination($q, $filters, 'id_stock_available');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_stock_available'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        throw new \RuntimeException('StockAvailable records are managed automatically by PrestaShop.', 405);
    }

    public function update(int $id, array $data, array $context): array
    {
        $sa = new \StockAvailable($id);
        if (!\Validate::isLoadedObject($sa)) {
            throw new ResourceNotFoundException('StockAvailable', $id);
        }

        if (isset($data['quantity'])) {
            // Use PS setQuantity to properly update stock and related caches
            \StockAvailable::setQuantity(
                (int) $sa->id_product,
                (int) $sa->id_product_attribute,
                (int) $data['quantity'],
                (int) $sa->id_shop
            );
        }

        if (isset($data['outOfStock'])) {
            $sa->out_of_stock = (int) $data['outOfStock'];
            $sa->save();
        }

        if (isset($data['location'])) {
            $sa->location = $data['location'];
            $sa->save();
        }

        // Reload after update
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        throw new \RuntimeException('StockAvailable records are managed automatically by PrestaShop.', 405);
    }

    private function mapRow(\StockAvailable $sa): array
    {
        return [
            'stockAvailableId'   => (int) $sa->id,
            'idProduct'          => (int) $sa->id_product,
            'idProductAttribute' => (int) $sa->id_product_attribute,
            'idShop'             => (int) $sa->id_shop,
            'idShopGroup'        => (int) $sa->id_shop_group,
            'quantity'           => (int) $sa->quantity,
            'dependsOnStock'     => (bool) $sa->depends_on_stock,
            'outOfStock'         => (int) $sa->out_of_stock,
            'location'           => $sa->location ?? '',
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\StockAvailable\StockAvailableResource;
// dans $resources :
StockAvailableResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/StockAvailable/StockAvailableResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/StockAvailable/ src/Resource/ResourceRegistry.php
git commit -m "feat: StockAvailableResource — stock management (read + update, no create/delete)"
```
