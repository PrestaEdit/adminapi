# PrestaShop Admin API — Plan C : Ressources complexes

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implémenter les 11 ressources restantes du module Admin API (AttributeGroup, Attribute, Feature, FeatureValue, CustomerGroup, Customer, Category, CartRule, Discount, Module, ApiClient) complétant ainsi la totalité des 29 domaines de scopes.

**Architecture:** Même pattern que Plans A/B : classe PHP 7.4 dans `src/Resource/{Domain}/{Domain}Resource.php`, extending `AbstractResource`, implementing `ResourceInterface`. Nouveautés : Customer expose des champs sensibles (passwd jamais exposé), Category gère une structure arborescente (idParent), CartRule/Discount partagent la table `ps_cart_rule`, Module est read-only (pas de create/delete dans `definition()`), ApiClient pointe sur notre propre table `ps_apimodule_client`.

**Tech Stack:** PHP >=7.4, PrestaShop 1.7.6+, ObjectModel PS, PHPUnit ^9.

**Note :** `ShowcaseCard` reste hors-scope (PS 9 only — aucun ObjectModel en PS 1.7/8). `Product` fait l'objet du Plan D (trop complexe pour être groupé ici).

---

## Structure des fichiers

```
src/Resource/
├── AttributeGroup/AttributeGroupResource.php   ← Task 1
├── Attribute/AttributeResource.php             ← Task 1
├── Feature/FeatureResource.php                 ← Task 2
├── FeatureValue/FeatureValueResource.php       ← Task 2
├── CustomerGroup/CustomerGroupResource.php     ← Task 3
├── Customer/CustomerResource.php               ← Task 4
├── Category/CategoryResource.php               ← Task 5
├── CartRule/CartRuleResource.php               ← Task 6
├── Discount/DiscountResource.php               ← Task 6
├── Module/ModuleResource.php                   ← Task 7
└── ApiClient/ApiClientResource.php             ← Task 7
src/Resource/ResourceRegistry.php               ← Modifié dans chaque tâche
```

---

## Task 1 : AttributeGroup + Attribute

**Goal:** Implémenter AttributeGroupResource (groupes d'attributs produit, ex. « Couleur ») et AttributeResource (valeurs d'attributs, ex. « Rouge »).

**Files:**
- Create: `src/Resource/AttributeGroup/AttributeGroupResource.php`
- Create: `src/Resource/Attribute/AttributeResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /attribute-groups` → 200 avec `names` localisé et `publicNames` localisé
- [ ] `POST /attribute-groups` sans `names` → 422
- [ ] `GET /attributes` → 200 avec `names` localisé et `idAttributeGroup`
- [ ] `POST /attributes` sans `idAttributeGroup` ou `names` → 422
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/AttributeGroup/AttributeGroupResource.php`**

Note : classe PS `AttributeGroup` (table `ps_attribute_group`, primary `id_attribute_group`, lang table `ps_attribute_group_lang` avec colonnes `name` et `public_name`). Champ `group_type` : `'select'`, `'radio'`, ou `'color'`.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\AttributeGroup;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class AttributeGroupResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/attribute-groups',
            'identifierKey'     => 'attributeGroupId',
            'operations'        => [
                'get'        => ['scope' => 'attribute_group_read',  'method' => 'GET'],
                'list'       => ['scope' => 'attribute_group_read',  'method' => 'GET'],
                'create'     => ['scope' => 'attribute_group_write', 'method' => 'POST'],
                'update'     => ['scope' => 'attribute_group_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'attribute_group_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'attribute_group_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $ag = new \AttributeGroup($id, $context['langId']);
        if (!\Validate::isLoadedObject($ag)) {
            throw new ResourceNotFoundException('AttributeGroup', $id);
        }
        return $this->map($ag);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('ag.id_attribute_group');
        $q->from('attribute_group', 'ag');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'ag.id_attribute_group', [
            'attributeGroupId' => 'ag.id_attribute_group',
            'position'         => 'ag.position',
        ]);
        $this->applyPagination($q, $filters, 'id_attribute_group');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_attribute_group'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $ag               = new \AttributeGroup();
        $ag->name         = $this->buildPsLocalizedField($data['names']);
        $ag->public_name  = $this->buildPsLocalizedField($data['publicNames'] ?? $data['names']);
        $ag->group_type   = in_array($data['groupType'] ?? '', ['select', 'radio', 'color'], true)
            ? $data['groupType']
            : 'select';
        $ag->position     = (int) ($data['position'] ?? 0);
        $ag->is_color_group = (bool) ($data['isColorGroup'] ?? false);

        if (!$ag->save()) {
            throw new \RuntimeException('Failed to create attribute group.', 500);
        }
        return $this->get((int) $ag->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $ag = new \AttributeGroup($id, $context['langId']);
        if (!\Validate::isLoadedObject($ag)) {
            throw new ResourceNotFoundException('AttributeGroup', $id);
        }
        if (isset($data['names']))       { $ag->name          = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['publicNames'])) { $ag->public_name   = $this->buildPsLocalizedField($data['publicNames']); }
        if (isset($data['groupType']) && in_array($data['groupType'], ['select', 'radio', 'color'], true)) {
            $ag->group_type = $data['groupType'];
        }
        if (isset($data['position']))      { $ag->position      = (int) $data['position']; }
        if (isset($data['isColorGroup']))  { $ag->is_color_group = (bool) $data['isColorGroup']; }

        if (!$ag->save()) {
            throw new \RuntimeException('Failed to update attribute group.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $ag = new \AttributeGroup($id);
        if (!\Validate::isLoadedObject($ag)) {
            throw new ResourceNotFoundException('AttributeGroup', $id);
        }
        if (!$ag->delete()) {
            throw new \RuntimeException('Failed to delete attribute group.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['attributeGroupIds'] ?? []);
        foreach ($ids as $id) {
            $ag = new \AttributeGroup($id);
            if (\Validate::isLoadedObject($ag)) {
                $ag->delete();
            }
        }
    }

    private function map(\AttributeGroup $ag): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name`, `public_name`
             FROM `' . _DB_PREFIX_ . 'attribute_group_lang`
             WHERE `id_attribute_group` = ' . (int) $ag->id
        );
        $names       = array_column($rows ?: [], 'name',        'id_lang');
        $publicNames = array_column($rows ?: [], 'public_name', 'id_lang');

        return [
            'attributeGroupId' => (int) $ag->id,
            'groupType'        => $ag->group_type,
            'position'         => (int) $ag->position,
            'isColorGroup'     => (bool) $ag->is_color_group,
            'names'            => $this->getLocalizedField($names),
            'publicNames'      => $this->getLocalizedField($publicNames),
        ];
    }
}
```

- [ ] **Step 2 : Créer `src/Resource/Attribute/AttributeResource.php`**

Note : classe PS `Attribute` (table `ps_attribute`, primary `id_attribute`, lang table `ps_attribute_lang` avec colonne `name`). Champ `color` = code couleur hex (ex. `#FF0000`) pour les groupes de type `color`.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Attribute;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class AttributeResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/attributes',
            'identifierKey'     => 'attributeId',
            'operations'        => [
                'get'        => ['scope' => 'attribute_read',  'method' => 'GET'],
                'list'       => ['scope' => 'attribute_read',  'method' => 'GET'],
                'create'     => ['scope' => 'attribute_write', 'method' => 'POST'],
                'update'     => ['scope' => 'attribute_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'attribute_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'attribute_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $attr = new \Attribute($id, $context['langId']);
        if (!\Validate::isLoadedObject($attr)) {
            throw new ResourceNotFoundException('Attribute', $id);
        }
        return $this->map($attr);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('a.id_attribute');
        $q->from('attribute', 'a');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'a.id_attribute', [
            'attributeId'      => 'a.id_attribute',
            'idAttributeGroup' => 'a.id_attribute_group',
            'position'         => 'a.position',
        ]);
        $this->applyPagination($q, $filters, 'id_attribute');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_attribute'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idAttributeGroup', 'names']);

        $attr                    = new \Attribute();
        $attr->id_attribute_group = (int) $data['idAttributeGroup'];
        $attr->name              = $this->buildPsLocalizedField($data['names']);
        $attr->color             = $data['color'] ?? '';
        $attr->position          = (int) ($data['position'] ?? 0);

        if (!$attr->save()) {
            throw new \RuntimeException('Failed to create attribute.', 500);
        }
        return $this->get((int) $attr->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $attr = new \Attribute($id, $context['langId']);
        if (!\Validate::isLoadedObject($attr)) {
            throw new ResourceNotFoundException('Attribute', $id);
        }
        if (isset($data['idAttributeGroup'])) { $attr->id_attribute_group = (int) $data['idAttributeGroup']; }
        if (isset($data['names']))            { $attr->name               = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['color']))            { $attr->color              = $data['color']; }
        if (isset($data['position']))         { $attr->position           = (int) $data['position']; }

        if (!$attr->save()) {
            throw new \RuntimeException('Failed to update attribute.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $attr = new \Attribute($id);
        if (!\Validate::isLoadedObject($attr)) {
            throw new ResourceNotFoundException('Attribute', $id);
        }
        if (!$attr->delete()) {
            throw new \RuntimeException('Failed to delete attribute.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['attributeIds'] ?? []);
        foreach ($ids as $id) {
            $attr = new \Attribute($id);
            if (\Validate::isLoadedObject($attr)) {
                $attr->delete();
            }
        }
    }

    private function map(\Attribute $attr): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'attribute_lang`
             WHERE `id_attribute` = ' . (int) $attr->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'attributeId'      => (int) $attr->id,
            'idAttributeGroup' => (int) $attr->id_attribute_group,
            'color'            => $attr->color ?? '',
            'position'         => (int) $attr->position,
            'names'            => $this->getLocalizedField($names),
        ];
    }
}
```

- [ ] **Step 3 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\AttributeGroup\AttributeGroupResource;
use PrestaEdit\ApiModule\Resource\Attribute\AttributeResource;
// dans $resources :
AttributeGroupResource::class,
AttributeResource::class,
```

- [ ] **Step 4 : Vérifier et committer**

```bash
php -l src/Resource/AttributeGroup/AttributeGroupResource.php
php -l src/Resource/Attribute/AttributeResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/AttributeGroup/ src/Resource/Attribute/ src/Resource/ResourceRegistry.php
git commit -m "feat: AttributeGroupResource + AttributeResource"
```

---

## Task 2 : Feature + FeatureValue

**Goal:** Implémenter FeatureResource (caractéristiques produit, ex. « Matière ») et FeatureValueResource (valeurs, ex. « Coton »).

**Files:**
- Create: `src/Resource/Feature/FeatureResource.php`
- Create: `src/Resource/FeatureValue/FeatureValueResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /features` → 200 avec `names` localisé et `position`
- [ ] `POST /features` sans `names` → 422
- [ ] `GET /feature-values` → 200 avec `values` localisé et `idFeature`
- [ ] `POST /feature-values` sans `idFeature` ou `values` → 422
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Feature/FeatureResource.php`**

Note : classe PS `Feature` (table `ps_feature`, primary `id_feature`, lang table `ps_feature_lang` avec colonne `name`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Feature;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class FeatureResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/features',
            'identifierKey'     => 'featureId',
            'operations'        => [
                'get'        => ['scope' => 'feature_read',  'method' => 'GET'],
                'list'       => ['scope' => 'feature_read',  'method' => 'GET'],
                'create'     => ['scope' => 'feature_write', 'method' => 'POST'],
                'update'     => ['scope' => 'feature_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'feature_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'feature_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $feature = new \Feature($id, $context['langId']);
        if (!\Validate::isLoadedObject($feature)) {
            throw new ResourceNotFoundException('Feature', $id);
        }
        return $this->map($feature);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('f.id_feature');
        $q->from('feature', 'f');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'f.id_feature', [
            'featureId' => 'f.id_feature',
            'position'  => 'f.position',
        ]);
        $this->applyPagination($q, $filters, 'id_feature');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_feature'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $feature           = new \Feature();
        $feature->name     = $this->buildPsLocalizedField($data['names']);
        $feature->position = (int) ($data['position'] ?? 0);

        if (!$feature->save()) {
            throw new \RuntimeException('Failed to create feature.', 500);
        }
        return $this->get((int) $feature->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $feature = new \Feature($id, $context['langId']);
        if (!\Validate::isLoadedObject($feature)) {
            throw new ResourceNotFoundException('Feature', $id);
        }
        if (isset($data['names']))    { $feature->name     = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['position'])) { $feature->position = (int) $data['position']; }

        if (!$feature->save()) {
            throw new \RuntimeException('Failed to update feature.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $feature = new \Feature($id);
        if (!\Validate::isLoadedObject($feature)) {
            throw new ResourceNotFoundException('Feature', $id);
        }
        if (!$feature->delete()) {
            throw new \RuntimeException('Failed to delete feature.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['featureIds'] ?? []);
        foreach ($ids as $id) {
            $feature = new \Feature($id);
            if (\Validate::isLoadedObject($feature)) {
                $feature->delete();
            }
        }
    }

    private function map(\Feature $feature): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'feature_lang`
             WHERE `id_feature` = ' . (int) $feature->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'featureId' => (int) $feature->id,
            'position'  => (int) $feature->position,
            'names'     => $this->getLocalizedField($names),
        ];
    }
}
```

- [ ] **Step 2 : Créer `src/Resource/FeatureValue/FeatureValueResource.php`**

Note : classe PS `FeatureValue` (table `ps_feature_value`, primary `id_feature_value`, lang table `ps_feature_value_lang` avec colonne `value`). Champ `custom` : 1 si valeur personnalisée produit par produit.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\FeatureValue;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class FeatureValueResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/feature-values',
            'identifierKey'     => 'featureValueId',
            'operations'        => [
                'get'        => ['scope' => 'feature_value_read',  'method' => 'GET'],
                'list'       => ['scope' => 'feature_value_read',  'method' => 'GET'],
                'create'     => ['scope' => 'feature_value_write', 'method' => 'POST'],
                'update'     => ['scope' => 'feature_value_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'feature_value_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'feature_value_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $fv = new \FeatureValue($id, $context['langId']);
        if (!\Validate::isLoadedObject($fv)) {
            throw new ResourceNotFoundException('FeatureValue', $id);
        }
        return $this->map($fv);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('fv.id_feature_value');
        $q->from('feature_value', 'fv');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'fv.id_feature_value', [
            'featureValueId' => 'fv.id_feature_value',
            'idFeature'      => 'fv.id_feature',
        ]);
        $this->applyPagination($q, $filters, 'id_feature_value');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_feature_value'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idFeature', 'values']);

        $fv            = new \FeatureValue();
        $fv->id_feature = (int) $data['idFeature'];
        $fv->value     = $this->buildPsLocalizedField($data['values']);
        $fv->custom    = (bool) ($data['custom'] ?? false);

        if (!$fv->save()) {
            throw new \RuntimeException('Failed to create feature value.', 500);
        }
        return $this->get((int) $fv->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $fv = new \FeatureValue($id, $context['langId']);
        if (!\Validate::isLoadedObject($fv)) {
            throw new ResourceNotFoundException('FeatureValue', $id);
        }
        if (isset($data['idFeature'])) { $fv->id_feature = (int) $data['idFeature']; }
        if (isset($data['values']))    { $fv->value      = $this->buildPsLocalizedField($data['values']); }
        if (isset($data['custom']))    { $fv->custom     = (bool) $data['custom']; }

        if (!$fv->save()) {
            throw new \RuntimeException('Failed to update feature value.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $fv = new \FeatureValue($id);
        if (!\Validate::isLoadedObject($fv)) {
            throw new ResourceNotFoundException('FeatureValue', $id);
        }
        if (!$fv->delete()) {
            throw new \RuntimeException('Failed to delete feature value.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['featureValueIds'] ?? []);
        foreach ($ids as $id) {
            $fv = new \FeatureValue($id);
            if (\Validate::isLoadedObject($fv)) {
                $fv->delete();
            }
        }
    }

    private function map(\FeatureValue $fv): array
    {
        $valueRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `value` FROM `' . _DB_PREFIX_ . 'feature_value_lang`
             WHERE `id_feature_value` = ' . (int) $fv->id
        );
        $values = array_column($valueRows ?: [], 'value', 'id_lang');

        return [
            'featureValueId' => (int) $fv->id,
            'idFeature'      => (int) $fv->id_feature,
            'custom'         => (bool) $fv->custom,
            'values'         => $this->getLocalizedField($values),
        ];
    }
}
```

- [ ] **Step 3 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Feature\FeatureResource;
use PrestaEdit\ApiModule\Resource\FeatureValue\FeatureValueResource;
// dans $resources :
FeatureResource::class,
FeatureValueResource::class,
```

- [ ] **Step 4 : Vérifier et committer**

```bash
php -l src/Resource/Feature/FeatureResource.php
php -l src/Resource/FeatureValue/FeatureValueResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Feature/ src/Resource/FeatureValue/ src/Resource/ResourceRegistry.php
git commit -m "feat: FeatureResource + FeatureValueResource"
```

---

## Task 3 : CustomerGroup

**Goal:** Implémenter CustomerGroupResource (groupes de clients, ex. « Visiteur », « Client », « Grossiste »).

**Files:**
- Create: `src/Resource/CustomerGroup/CustomerGroupResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /customer-groups` → 200 avec `names` localisé, `reduction`, `showPrices`
- [ ] `POST /customer-groups` sans `names` → 422
- [ ] `POST /customer-groups` → 201
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/CustomerGroup/CustomerGroupResource.php`**

Note : classe PS `Group` (table `ps_group`, primary `id_group`, lang table `ps_group_lang` avec colonne `name`). Champ `price_display_method` : 0=TTC, 1=HT.

```php
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

        $group                      = new \Group();
        $group->name                = $this->buildPsLocalizedField($data['names']);
        $group->reduction           = (float) ($data['reduction'] ?? 0);
        $group->price_display_method = (int) ($data['priceDisplayMethod'] ?? 0);
        $group->show_prices         = (bool) ($data['showPrices'] ?? true);

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
        if (isset($data['names']))              { $group->name                = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['reduction']))          { $group->reduction           = (float) $data['reduction']; }
        if (isset($data['priceDisplayMethod'])) { $group->price_display_method = (int) $data['priceDisplayMethod']; }
        if (isset($data['showPrices']))         { $group->show_prices         = (bool) $data['showPrices']; }

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
            'customerGroupId'   => (int) $group->id,
            'reduction'         => $this->decimal($group->reduction),
            'priceDisplayMethod'=> (int) $group->price_display_method,
            'showPrices'        => (bool) $group->show_prices,
            'dateAdd'           => $group->date_add ?? '',
            'dateUpd'           => $group->date_upd ?? '',
            'names'             => $this->getLocalizedField($names),
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\CustomerGroup\CustomerGroupResource;
// dans $resources :
CustomerGroupResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/CustomerGroup/CustomerGroupResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/CustomerGroup/ src/Resource/ResourceRegistry.php
git commit -m "feat: CustomerGroupResource"
```

---

## Task 4 : Customer

**Goal:** Implémenter CustomerResource — non-localisé, `passwd` jamais exposé, password requis à la création.

**Files:**
- Create: `src/Resource/Customer/CustomerResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /customers` → 200, champ `passwd` absent de la réponse
- [ ] `POST /customers` sans `email`, `firstname`, `lastname` ou `password` → 422
- [ ] `POST /customers` → 201, `passwd` absent de la réponse
- [ ] `PATCH /customers/1` → 200, champ `passwd` absent
- [ ] `DELETE /customers/1` → 204
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Customer/CustomerResource.php`**

Note : classe PS `Customer` (table `ps_customer`, primary `id_customer`). Pas de table lang. Le mot de passe est haché via `\Tools::encrypt()` (= `md5(_COOKIE_KEY_ . $password)`) — **jamais exposé dans les réponses API**. `is_guest` distingue les comptes invités.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Customer;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CustomerResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/customers',
            'identifierKey'     => 'customerId',
            'operations'        => [
                'get'        => ['scope' => 'customer_read',  'method' => 'GET'],
                'list'       => ['scope' => 'customer_read',  'method' => 'GET'],
                'create'     => ['scope' => 'customer_write', 'method' => 'POST'],
                'update'     => ['scope' => 'customer_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'customer_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'customer_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $customer = new \Customer($id);
        if (!\Validate::isLoadedObject($customer)) {
            throw new ResourceNotFoundException('Customer', $id);
        }
        return $this->mapRow($customer);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_customer, c.id_gender, c.id_default_group, c.firstname, c.lastname,
                    c.email, c.birthday, c.newsletter, c.optin, c.website, c.company,
                    c.active, c.is_guest, c.date_add, c.date_upd');
        $q->from('customer', 'c');
        $q->where('c.deleted = 0');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'c.id_customer', [
            'customerId' => 'c.id_customer',
            'lastname'   => 'c.lastname',
            'email'      => 'c.email',
        ]);
        $this->applyPagination($q, $filters, 'id_customer');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapFromRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['email', 'firstname', 'lastname', 'password']);

        $customer                  = new \Customer();
        $customer->email           = $data['email'];
        $customer->firstname       = $data['firstname'];
        $customer->lastname        = $data['lastname'];
        $customer->passwd          = \Tools::encrypt($data['password']);
        $customer->id_gender       = (int) ($data['idGender'] ?? 0);
        $customer->id_default_group = (int) ($data['idDefaultGroup'] ?? (int) \Configuration::get('PS_CUSTOMER_GROUP'));
        $customer->birthday        = $data['birthday'] ?? '0000-00-00';
        $customer->newsletter      = (bool) ($data['newsletter'] ?? false);
        $customer->optin           = (bool) ($data['optin'] ?? false);
        $customer->website         = $data['website'] ?? '';
        $customer->company         = $data['company'] ?? '';
        $customer->active          = (bool) ($data['active'] ?? true);
        $customer->is_guest        = 0;

        if (!$customer->save()) {
            throw new \RuntimeException('Failed to create customer.', 500);
        }
        return $this->get((int) $customer->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $customer = new \Customer($id);
        if (!\Validate::isLoadedObject($customer) || $customer->deleted) {
            throw new ResourceNotFoundException('Customer', $id);
        }
        if (isset($data['email']))          { $customer->email           = $data['email']; }
        if (isset($data['firstname']))      { $customer->firstname       = $data['firstname']; }
        if (isset($data['lastname']))       { $customer->lastname        = $data['lastname']; }
        if (isset($data['password']))       { $customer->passwd          = \Tools::encrypt($data['password']); }
        if (isset($data['idGender']))       { $customer->id_gender       = (int) $data['idGender']; }
        if (isset($data['idDefaultGroup'])) { $customer->id_default_group = (int) $data['idDefaultGroup']; }
        if (isset($data['birthday']))       { $customer->birthday        = $data['birthday']; }
        if (isset($data['newsletter']))     { $customer->newsletter      = (bool) $data['newsletter']; }
        if (isset($data['optin']))          { $customer->optin           = (bool) $data['optin']; }
        if (isset($data['website']))        { $customer->website         = $data['website']; }
        if (isset($data['company']))        { $customer->company         = $data['company']; }
        if (isset($data['active']))         { $customer->active          = (bool) $data['active']; }

        if (!$customer->save()) {
            throw new \RuntimeException('Failed to update customer.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $customer = new \Customer($id);
        if (!\Validate::isLoadedObject($customer) || $customer->deleted) {
            throw new ResourceNotFoundException('Customer', $id);
        }
        // PS Customer uses soft-delete via deleted flag when calling delete()
        if (!$customer->delete()) {
            throw new \RuntimeException('Failed to delete customer.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['customerIds'] ?? []);
        foreach ($ids as $id) {
            $customer = new \Customer($id);
            if (\Validate::isLoadedObject($customer) && !$customer->deleted) {
                $customer->delete();
            }
        }
    }

    private function mapRow(\Customer $c): array
    {
        return $this->mapFromRow([
            'id_customer'      => $c->id,
            'id_gender'        => $c->id_gender,
            'id_default_group' => $c->id_default_group,
            'firstname'        => $c->firstname,
            'lastname'         => $c->lastname,
            'email'            => $c->email,
            'birthday'         => $c->birthday,
            'newsletter'       => $c->newsletter,
            'optin'            => $c->optin,
            'website'          => $c->website,
            'company'          => $c->company,
            'active'           => $c->active,
            'is_guest'         => $c->is_guest,
            'date_add'         => $c->date_add,
            'date_upd'         => $c->date_upd,
        ]);
    }

    private function mapFromRow(array $row): array
    {
        // passwd is intentionally excluded from all responses
        return [
            'customerId'      => (int) $row['id_customer'],
            'idGender'        => (int) ($row['id_gender'] ?? 0),
            'idDefaultGroup'  => (int) ($row['id_default_group'] ?? 0),
            'firstname'       => $row['firstname'] ?? '',
            'lastname'        => $row['lastname'] ?? '',
            'email'           => $row['email'] ?? '',
            'birthday'        => $row['birthday'] ?? '',
            'newsletter'      => (bool) ($row['newsletter'] ?? false),
            'optin'           => (bool) ($row['optin'] ?? false),
            'website'         => $row['website'] ?? '',
            'company'         => $row['company'] ?? '',
            'active'          => (bool) ($row['active'] ?? true),
            'isGuest'         => (bool) ($row['is_guest'] ?? false),
            'dateAdd'         => $row['date_add'] ?? '',
            'dateUpd'         => $row['date_upd'] ?? '',
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Customer\CustomerResource;
// dans $resources :
CustomerResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/Customer/CustomerResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Customer/ src/Resource/ResourceRegistry.php
git commit -m "feat: CustomerResource — passwd never exposed, soft-delete via PS"
```

---

## Task 5 : Category

**Goal:** Implémenter CategoryResource — localisé avec structure arborescente (idParent) et slug (linkRewrite).

**Files:**
- Create: `src/Resource/Category/CategoryResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /categories` → 200 avec `names`, `idParent`, `linkRewrites` localisés
- [ ] `POST /categories` sans `idParent`, `names` ou `linkRewrites` → 422
- [ ] `POST /categories` → 201
- [ ] `PATCH /categories/1` → 200
- [ ] `DELETE /categories/1` → 204
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Category/CategoryResource.php`**

Note : classe PS `Category` (table `ps_category`, primary `id_category`, lang table `ps_category_lang` avec colonnes `name`, `description`, `meta_title`, `meta_keywords`, `meta_description`, `link_rewrite`). `level_depth` est calculé auto par PS lors du save. `is_root_category` ne pas modifier.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Category;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CategoryResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/categories',
            'identifierKey'     => 'categoryId',
            'operations'        => [
                'get'        => ['scope' => 'category_read',  'method' => 'GET'],
                'list'       => ['scope' => 'category_read',  'method' => 'GET'],
                'create'     => ['scope' => 'category_write', 'method' => 'POST'],
                'update'     => ['scope' => 'category_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'category_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'category_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $category = new \Category($id, $context['langId']);
        if (!\Validate::isLoadedObject($category)) {
            throw new ResourceNotFoundException('Category', $id);
        }
        return $this->map($category);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_category');
        $q->from('category', 'c');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'c.id_category', [
            'categoryId' => 'c.id_category',
            'position'   => 'c.position',
            'idParent'   => 'c.id_parent',
        ]);
        $this->applyPagination($q, $filters, 'id_category');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_category'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idParent', 'names', 'linkRewrites']);

        $category              = new \Category();
        $category->id_parent   = (int) $data['idParent'];
        $category->name        = $this->buildPsLocalizedField($data['names']);
        $category->link_rewrite = $this->buildPsLocalizedField($data['linkRewrites']);
        $category->active      = (bool) ($data['active'] ?? true);
        $category->position    = (int) ($data['position'] ?? 0);

        if (isset($data['descriptions']))      { $category->description         = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['metaTitles']))        { $category->meta_title          = $this->buildPsLocalizedField($data['metaTitles']); }
        if (isset($data['metaDescriptions'])) { $category->meta_description    = $this->buildPsLocalizedField($data['metaDescriptions']); }
        if (isset($data['metaKeywords']))      { $category->meta_keywords       = $this->buildPsLocalizedField($data['metaKeywords']); }

        if (!$category->save()) {
            throw new \RuntimeException('Failed to create category.', 500);
        }
        return $this->get((int) $category->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $category = new \Category($id, $context['langId']);
        if (!\Validate::isLoadedObject($category)) {
            throw new ResourceNotFoundException('Category', $id);
        }
        if (isset($data['idParent']))         { $category->id_parent        = (int) $data['idParent']; }
        if (isset($data['names']))            { $category->name             = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['linkRewrites']))     { $category->link_rewrite     = $this->buildPsLocalizedField($data['linkRewrites']); }
        if (isset($data['active']))           { $category->active           = (bool) $data['active']; }
        if (isset($data['position']))         { $category->position         = (int) $data['position']; }
        if (isset($data['descriptions']))     { $category->description      = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['metaTitles']))       { $category->meta_title       = $this->buildPsLocalizedField($data['metaTitles']); }
        if (isset($data['metaDescriptions'])){ $category->meta_description  = $this->buildPsLocalizedField($data['metaDescriptions']); }
        if (isset($data['metaKeywords']))     { $category->meta_keywords    = $this->buildPsLocalizedField($data['metaKeywords']); }

        if (!$category->save()) {
            throw new \RuntimeException('Failed to update category.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $category = new \Category($id);
        if (!\Validate::isLoadedObject($category) || $category->is_root_category) {
            throw new ResourceNotFoundException('Category', $id);
        }
        if (!$category->delete()) {
            throw new \RuntimeException('Failed to delete category.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['categoryIds'] ?? []);
        foreach ($ids as $id) {
            $category = new \Category($id);
            if (\Validate::isLoadedObject($category) && !$category->is_root_category) {
                $category->delete();
            }
        }
    }

    private function map(\Category $category): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name`, `description`, `meta_title`, `meta_keywords`,
                    `meta_description`, `link_rewrite`
             FROM `' . _DB_PREFIX_ . 'category_lang`
             WHERE `id_category` = ' . (int) $category->id
        );

        $names        = array_column($rows ?: [], 'name',             'id_lang');
        $descs        = array_column($rows ?: [], 'description',      'id_lang');
        $metaTitles   = array_column($rows ?: [], 'meta_title',       'id_lang');
        $metaDescs    = array_column($rows ?: [], 'meta_description',  'id_lang');
        $metaKeys     = array_column($rows ?: [], 'meta_keywords',    'id_lang');
        $linkRewrites = array_column($rows ?: [], 'link_rewrite',     'id_lang');

        return [
            'categoryId'       => (int) $category->id,
            'idParent'         => (int) $category->id_parent,
            'position'         => (int) $category->position,
            'active'           => (bool) $category->active,
            'levelDepth'       => (int) $category->level_depth,
            'isRootCategory'   => (bool) $category->is_root_category,
            'names'            => $this->getLocalizedField($names),
            'descriptions'     => $this->getLocalizedField($descs),
            'metaTitles'       => $this->getLocalizedField($metaTitles),
            'metaDescriptions' => $this->getLocalizedField($metaDescs),
            'metaKeywords'     => $this->getLocalizedField($metaKeys),
            'linkRewrites'     => $this->getLocalizedField($linkRewrites),
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Category\CategoryResource;
// dans $resources :
CategoryResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/Category/CategoryResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Category/ src/Resource/ResourceRegistry.php
git commit -m "feat: CategoryResource — localized tree with link_rewrite"
```

---

## Task 6 : CartRule + Discount

**Goal:** Implémenter CartRuleResource (toutes les règles panier) et DiscountResource (règles avec code voucher). Les deux utilisent la table `ps_cart_rule`.

**Files:**
- Create: `src/Resource/CartRule/CartRuleResource.php`
- Create: `src/Resource/Discount/DiscountResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /cart-rules` → 200, liste toutes les règles panier
- [ ] `POST /cart-rules` sans `names`, `dateFrom` ou `dateTo` → 422
- [ ] `GET /discounts` → 200, liste uniquement les règles avec un code non vide
- [ ] `POST /discounts` sans `names`, `code`, `dateFrom` ou `dateTo` → 422
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/CartRule/CartRuleResource.php`**

Note : classe PS `CartRule` (table `ps_cart_rule`, primary `id_cart_rule`, lang table `ps_cart_rule_lang` avec colonne `name`). `date_from`/`date_to` au format `Y-m-d H:i:s`. Réduction : `reduction_percent` OU `reduction_amount` (mutuellement exclusifs).

```php
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
        if (isset($data['names']))            { $cr->name               = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['code']))             { $cr->code               = $data['code']; }
        if (isset($data['description']))      { $cr->description        = $data['description']; }
        if (isset($data['dateFrom']))         { $cr->date_from          = $data['dateFrom']; }
        if (isset($data['dateTo']))           { $cr->date_to            = $data['dateTo']; }
        if (isset($data['quantity']))         { $cr->quantity           = (int) $data['quantity']; }
        if (isset($data['quantityPerUser'])) { $cr->quantity_per_user  = (int) $data['quantityPerUser']; }
        if (isset($data['active']))           { $cr->active             = (bool) $data['active']; }
        if (isset($data['freeShipping']))     { $cr->free_shipping      = (bool) $data['freeShipping']; }
        if (isset($data['reductionPercent'])){ $cr->reduction_percent   = (float) $data['reductionPercent']; }
        if (isset($data['reductionAmount'])) { $cr->reduction_amount   = (float) $data['reductionAmount']; }
        if (isset($data['reductionTax']))    { $cr->reduction_tax      = (bool) $data['reductionTax']; }
        if (isset($data['minimumAmount']))   { $cr->minimum_amount     = (float) $data['minimumAmount']; }
        if (isset($data['idCustomer']))      { $cr->id_customer        = (int) $data['idCustomer']; }
        if (isset($data['highlight']))       { $cr->highlight          = (bool) $data['highlight']; }

        // defaults for new cart rule
        if (!$cr->id) {
            $cr->quantity        = $cr->quantity ?: 1;
            $cr->active          = isset($cr->active) ? $cr->active : true;
            $cr->partial_use     = 1;
            $cr->priority        = 1;
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
```

- [ ] **Step 2 : Créer `src/Resource/Discount/DiscountResource.php`**

Note : `Discount` = CartRule avec un code non vide. `list()` filtre `code != ''`. `create()` requiert `code` en plus des champs habituels. Hérite de `CartRuleResource` pour réutiliser `persist()` et `map()`.

```php
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
```

- [ ] **Step 3 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\CartRule\CartRuleResource;
use PrestaEdit\ApiModule\Resource\Discount\DiscountResource;
// dans $resources :
CartRuleResource::class,
DiscountResource::class,
```

- [ ] **Step 4 : Vérifier et committer**

```bash
php -l src/Resource/CartRule/CartRuleResource.php
php -l src/Resource/Discount/DiscountResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/CartRule/ src/Resource/Discount/ src/Resource/ResourceRegistry.php
git commit -m "feat: CartRuleResource + DiscountResource (vouchers)"
```

---

## Task 7 : Module + ApiClient

**Goal:** Implémenter ModuleResource (lecture seule des modules PS installés) et ApiClientResource (gestion des clients OAuth2 de ce module via l'API).

**Files:**
- Create: `src/Resource/Module/ModuleResource.php`
- Create: `src/Resource/ApiClient/ApiClientResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /modules` → 200 avec `{moduleId, name, active, version}`
- [ ] `PATCH /modules/1` → 200 (active/désactive le module)
- [ ] `POST /modules` → 405 (create non supporté)
- [ ] `DELETE /modules/1` → 405 (delete non supporté)
- [ ] `GET /api-clients` → 200 avec `{clientId, clientName, scopes, active}` — `clientSecret` absent
- [ ] `POST /api-clients` → 201 avec `clientSecret` en clair dans la réponse (une seule fois)
- [ ] `GET /api-clients/1` → 200 — `clientSecret` absent
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Module/ModuleResource.php`**

Note : la table `ps_module` (id_module, name, active, version) est gérée par le système PS. Pas de create ni de delete via l'API — trop risqué. Seul `update()` est exposé pour activer/désactiver. Les méthodes `create()` et `delete()` sont déclarées (exigées par ResourceInterface) mais lèvent une exception 405.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Module;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ModuleResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/modules',
            'identifierKey'     => 'moduleId',
            'operations'        => [
                'get'    => ['scope' => 'module_read',  'method' => 'GET'],
                'list'   => ['scope' => 'module_read',  'method' => 'GET'],
                'update' => ['scope' => 'module_write', 'method' => 'PATCH'],
                // create and delete intentionally excluded — too destructive
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_module`, `name`, `active`, `version`
             FROM `' . _DB_PREFIX_ . 'module`
             WHERE `id_module` = ' . (int) $id
        );
        if (!$row) {
            throw new ResourceNotFoundException('Module', $id);
        }
        return $this->mapRow($row);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_module, name, active, version');
        $q->from('module');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_module', [
            'moduleId' => 'id_module',
            'name'     => 'name',
        ]);
        $this->applyPagination($q, $filters, 'id_module');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        throw new \RuntimeException('Module installation is not supported via API.', 405);
    }

    public function update(int $id, array $data, array $context): array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_module`, `name`, `active`, `version`
             FROM `' . _DB_PREFIX_ . 'module`
             WHERE `id_module` = ' . (int) $id
        );
        if (!$row) {
            throw new ResourceNotFoundException('Module', $id);
        }

        if (isset($data['active'])) {
            \Db::getInstance()->update(
                'module',
                ['active' => (int) (bool) $data['active']],
                '`id_module` = ' . (int) $id
            );
            $row['active'] = (int) (bool) $data['active'];
        }

        return $this->mapRow($row);
    }

    public function delete(int $id, array $context): void
    {
        throw new \RuntimeException('Module uninstallation is not supported via API.', 405);
    }

    private function mapRow(array $row): array
    {
        return [
            'moduleId' => (int) $row['id_module'],
            'name'     => $row['name'],
            'active'   => (bool) $row['active'],
            'version'  => $row['version'] ?? '',
        ];
    }
}
```

- [ ] **Step 2 : Créer `src/Resource/ApiClient/ApiClientResource.php`**

Note : table `ps_apimodule_client` (id, client_id, client_secret, client_name, scopes, active, date_add, date_upd). `client_secret` est haché en bcrypt — **jamais exposé** sauf lors de la création où le secret en clair est retourné une seule fois. Sur `create()`, on génère un `client_id` aléatoire si absent et un `client_secret` aléatoire.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\ApiClient;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ApiClientResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/api-clients',
            'identifierKey'     => 'apiClientId',
            'operations'        => [
                'get'        => ['scope' => 'api_client_read',  'method' => 'GET'],
                'list'       => ['scope' => 'api_client_read',  'method' => 'GET'],
                'create'     => ['scope' => 'api_client_write', 'method' => 'POST'],
                'update'     => ['scope' => 'api_client_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'api_client_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'api_client_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id`, `client_id`, `client_name`, `scopes`, `active`, `date_add`, `date_upd`
             FROM `' . _DB_PREFIX_ . 'apimodule_client`
             WHERE `id` = ' . (int) $id
        );
        if (!$row) {
            throw new ResourceNotFoundException('ApiClient', $id);
        }
        return $this->mapRow($row);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id, client_id, client_name, scopes, active, date_add, date_upd');
        $q->from('apimodule_client');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id', [
            'apiClientId' => 'id',
            'clientName'  => 'client_name',
        ]);
        $this->applyPagination($q, $filters, 'id');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['clientName']);

        $clientId    = isset($data['clientId']) && $data['clientId'] !== ''
            ? $data['clientId']
            : bin2hex(random_bytes(16));
        $rawSecret   = bin2hex(random_bytes(32));
        $scopes      = $data['scopes'] ?? [];

        \Db::getInstance()->insert('apimodule_client', [
            'client_id'     => pSQL($clientId),
            'client_secret' => pSQL(password_hash($rawSecret, PASSWORD_BCRYPT)),
            'client_name'   => pSQL($data['clientName']),
            'scopes'        => pSQL((string) json_encode($scopes)),
            'active'        => (int) (bool) ($data['active'] ?? true),
            'date_add'      => date('Y-m-d H:i:s'),
            'date_upd'      => date('Y-m-d H:i:s'),
        ]);

        $newId = (int) \Db::getInstance()->Insert_ID();
        $result = $this->get($newId, $context);
        // Expose the secret once only, at creation time
        $result['clientSecret'] = $rawSecret;
        return $result;
    }

    public function update(int $id, array $data, array $context): array
    {
        $existing = \Db::getInstance()->getRow(
            'SELECT `id` FROM `' . _DB_PREFIX_ . 'apimodule_client` WHERE `id` = ' . (int) $id
        );
        if (!$existing) {
            throw new ResourceNotFoundException('ApiClient', $id);
        }

        $updates = ['date_upd' => date('Y-m-d H:i:s')];
        if (isset($data['clientName']))  { $updates['client_name'] = pSQL($data['clientName']); }
        if (isset($data['active']))      { $updates['active']       = (int) (bool) $data['active']; }
        if (isset($data['scopes']))      { $updates['scopes']       = pSQL((string) json_encode($data['scopes'])); }
        if (isset($data['clientSecret']) && $data['clientSecret'] !== '') {
            $updates['client_secret'] = pSQL(password_hash($data['clientSecret'], PASSWORD_BCRYPT));
        }

        if (!\Db::getInstance()->update('apimodule_client', $updates, '`id` = ' . (int) $id)) {
            throw new \RuntimeException('Failed to update API client.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $existing = \Db::getInstance()->getRow(
            'SELECT `id` FROM `' . _DB_PREFIX_ . 'apimodule_client` WHERE `id` = ' . (int) $id
        );
        if (!$existing) {
            throw new ResourceNotFoundException('ApiClient', $id);
        }
        if (!\Db::getInstance()->delete('apimodule_client', '`id` = ' . (int) $id)) {
            throw new \RuntimeException('Failed to delete API client.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['apiClientIds'] ?? []);
        foreach ($ids as $id) {
            $existing = \Db::getInstance()->getRow(
                'SELECT `id` FROM `' . _DB_PREFIX_ . 'apimodule_client` WHERE `id` = ' . (int) $id
            );
            if ($existing) {
                \Db::getInstance()->delete('apimodule_client', '`id` = ' . (int) $id);
            }
        }
    }

    private function mapRow(array $row): array
    {
        // client_secret is intentionally excluded — never expose the bcrypt hash
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true) ?? [];
        return [
            'apiClientId' => (int) $row['id'],
            'clientId'    => $row['client_id'],
            'clientName'  => $row['client_name'],
            'scopes'      => $scopes,
            'active'      => (bool) $row['active'],
            'dateAdd'     => $row['date_add'] ?? '',
            'dateUpd'     => $row['date_upd'] ?? '',
        ];
    }
}
```

- [ ] **Step 3 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Module\ModuleResource;
use PrestaEdit\ApiModule\Resource\ApiClient\ApiClientResource;
// dans $resources :
ModuleResource::class,
ApiClientResource::class,
```

- [ ] **Step 4 : Vérifier et committer**

```bash
php -l src/Resource/Module/ModuleResource.php
php -l src/Resource/ApiClient/ApiClientResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Module/ src/Resource/ApiClient/ src/Resource/ResourceRegistry.php
git commit -m "feat: ModuleResource (read-only) + ApiClientResource (self-referential)"
```
