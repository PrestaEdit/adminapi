# PrestaShop Admin API — Plan B : Ressources simples

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implémenter les 14 ressources simples (Zone, Hook, TaxRulesGroup, SearchEngine, SearchAlias, WebserviceKey, Title, Profile, Tax, Country, Tab, Manufacturer, Supplier, Store, Address) en suivant exactement le pattern de ContactResource.

**Architecture:** Chaque ressource est une classe PHP 7.4 dans `src/Resource/{Domain}/{Domain}Resource.php` qui étend `AbstractResource` et implémente `ResourceInterface`. Chaque tâche crée les classes et les enregistre dans `ResourceRegistry`. Pattern : `definition()` + `get/list/create/update/delete/bulkDelete` + méthode privée `map()` (localisé) ou `mapRow()` (non-localisé). `countQuery()` TOUJOURS avant `applySort()`/`applyPagination()`. PHP 7.4 strict : pas de `mixed` type hint, pas de `match`, pas de `str_ends_with`.

**Tech Stack:** PHP >=7.4, PrestaShop 1.7.6+, ObjectModel PS, DbQuery PS, PHPUnit ^9.

**Note :** `ShowcaseCard` est spécifique à PS 9 — scope déclaré dans l'UI admin mais aucun endpoint en Plan B.

---

## Structure des fichiers

```
src/Resource/
├── Zone/ZoneResource.php
├── Hook/HookResource.php
├── TaxRulesGroup/TaxRulesGroupResource.php
├── SearchEngine/SearchEngineResource.php
├── SearchAlias/SearchAliasResource.php
├── WebserviceKey/WebserviceKeyResource.php
├── Title/TitleResource.php
├── Profile/ProfileResource.php
├── Tax/TaxResource.php
├── Country/CountryResource.php
├── Tab/TabResource.php
├── Manufacturer/ManufacturerResource.php
├── Supplier/SupplierResource.php
├── Store/StoreResource.php
└── Address/AddressResource.php
src/Resource/ResourceRegistry.php  ← modifié dans chaque tâche
```

---

## Task 1 : Zone + Hook

**Goal:** Implémenter ZoneResource et HookResource (ressources non-localisées, schéma minimal) et les enregistrer dans ResourceRegistry.

**Files:**
- Create: `src/Resource/Zone/ZoneResource.php`
- Create: `src/Resource/Hook/HookResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /zones` → 200 avec `{items, totalItems, offset, limit, orderBy, sortOrder}`
- [ ] `GET /zones/1` → 200 avec `{zoneId, name, active}`
- [ ] `POST /zones` sans `name` → 422
- [ ] `POST /zones` avec `{name:"Europe"}` → 201
- [ ] `PATCH /zones/1` → 200
- [ ] `DELETE /zones/1` → 204
- [ ] `DELETE /zones/bulk-delete` → 204
- [ ] Idem pour Hook
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → tous passants

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Zone/ZoneResource.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Zone;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ZoneResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/zones',
            'identifierKey'     => 'zoneId',
            'operations'        => [
                'get'        => ['scope' => 'zone_read',  'method' => 'GET'],
                'list'       => ['scope' => 'zone_read',  'method' => 'GET'],
                'create'     => ['scope' => 'zone_write', 'method' => 'POST'],
                'update'     => ['scope' => 'zone_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'zone_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'zone_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $zone = new \Zone($id);
        if (!\Validate::isLoadedObject($zone)) {
            throw new ResourceNotFoundException('Zone', $id);
        }
        return $this->mapRow([
            'id_zone' => $zone->id,
            'name'    => $zone->name,
            'active'  => $zone->active,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_zone, name, active');
        $q->from('zone');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_zone', [
            'zoneId' => 'id_zone',
            'name'   => 'name',
        ]);
        $this->applyPagination($q, $filters, 'id_zone');

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

        $zone         = new \Zone();
        $zone->name   = $data['name'];
        $zone->active = (bool) ($data['active'] ?? true);

        if (!$zone->save()) {
            throw new \RuntimeException('Failed to create zone.', 500);
        }
        return $this->get((int) $zone->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $zone = new \Zone($id);
        if (!\Validate::isLoadedObject($zone)) {
            throw new ResourceNotFoundException('Zone', $id);
        }
        if (isset($data['name']))   { $zone->name   = $data['name']; }
        if (isset($data['active'])) { $zone->active  = (bool) $data['active']; }

        if (!$zone->save()) {
            throw new \RuntimeException('Failed to update zone.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $zone = new \Zone($id);
        if (!\Validate::isLoadedObject($zone)) {
            throw new ResourceNotFoundException('Zone', $id);
        }
        if (!$zone->delete()) {
            throw new \RuntimeException('Failed to delete zone.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['zoneIds'] ?? []);
        foreach ($ids as $id) {
            $zone = new \Zone($id);
            if (\Validate::isLoadedObject($zone)) {
                $zone->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'zoneId' => (int) $row['id_zone'],
            'name'   => $row['name'],
            'active' => (bool) $row['active'],
        ];
    }
}
```

- [ ] **Step 2 : Créer `src/Resource/Hook/HookResource.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Hook;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class HookResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/hooks',
            'identifierKey'     => 'hookId',
            'operations'        => [
                'get'        => ['scope' => 'hook_read',  'method' => 'GET'],
                'list'       => ['scope' => 'hook_read',  'method' => 'GET'],
                'create'     => ['scope' => 'hook_write', 'method' => 'POST'],
                'update'     => ['scope' => 'hook_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'hook_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'hook_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $hook = new \Hook($id);
        if (!\Validate::isLoadedObject($hook)) {
            throw new ResourceNotFoundException('Hook', $id);
        }
        return $this->mapRow([
            'id_hook'     => $hook->id,
            'name'        => $hook->name,
            'title'       => $hook->title,
            'description' => $hook->description,
            'active'      => $hook->active,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_hook, name, title, description, active');
        $q->from('hook');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_hook', [
            'hookId' => 'id_hook',
            'name'   => 'name',
        ]);
        $this->applyPagination($q, $filters, 'id_hook');

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

        $hook              = new \Hook();
        $hook->name        = $data['name'];
        $hook->title       = $data['title'] ?? '';
        $hook->description = $data['description'] ?? '';
        $hook->active      = (bool) ($data['active'] ?? true);

        if (!$hook->save()) {
            throw new \RuntimeException('Failed to create hook.', 500);
        }
        return $this->get((int) $hook->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $hook = new \Hook($id);
        if (!\Validate::isLoadedObject($hook)) {
            throw new ResourceNotFoundException('Hook', $id);
        }
        if (isset($data['name']))        { $hook->name        = $data['name']; }
        if (isset($data['title']))       { $hook->title       = $data['title']; }
        if (isset($data['description'])) { $hook->description = $data['description']; }
        if (isset($data['active']))      { $hook->active      = (bool) $data['active']; }

        if (!$hook->save()) {
            throw new \RuntimeException('Failed to update hook.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $hook = new \Hook($id);
        if (!\Validate::isLoadedObject($hook)) {
            throw new ResourceNotFoundException('Hook', $id);
        }
        if (!$hook->delete()) {
            throw new \RuntimeException('Failed to delete hook.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['hookIds'] ?? []);
        foreach ($ids as $id) {
            $hook = new \Hook($id);
            if (\Validate::isLoadedObject($hook)) {
                $hook->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'hookId'      => (int) $row['id_hook'],
            'name'        => $row['name'],
            'title'       => $row['title'] ?? '',
            'description' => $row['description'] ?? '',
            'active'      => (bool) ($row['active'] ?? true),
        ];
    }
}
```

- [ ] **Step 3 : Mettre à jour `src/Resource/ResourceRegistry.php`**

Ajouter en haut du fichier (après l'import existant de ContactResource) :

```php
use PrestaEdit\ApiModule\Resource\Zone\ZoneResource;
use PrestaEdit\ApiModule\Resource\Hook\HookResource;
```

Ajouter dans le tableau `$resources` :

```php
private static array $resources = [
    ContactResource::class,
    ZoneResource::class,
    HookResource::class,
    // Other resources added in future tasks
];
```

- [ ] **Step 4 : Vérifier**

```bash
php -l src/Resource/Zone/ZoneResource.php
php -l src/Resource/Hook/HookResource.php
vendor/bin/phpunit tests/Unit/ --testdox
```

Expected : `OK (15 tests, 21 assertions)` — aucun échec.

- [ ] **Step 5 : Commit**

```bash
git add src/Resource/Zone/ src/Resource/Hook/ src/Resource/ResourceRegistry.php
git commit -m "feat: ZoneResource + HookResource — non-localized simple resources"
```

---

## Task 2 : TaxRulesGroup + SearchEngine + SearchAlias

**Goal:** Implémenter TaxRulesGroupResource, SearchEngineResource et SearchAliasResource (toutes non-localisées) et les enregistrer.

**Files:**
- Create: `src/Resource/TaxRulesGroup/TaxRulesGroupResource.php`
- Create: `src/Resource/SearchEngine/SearchEngineResource.php`
- Create: `src/Resource/SearchAlias/SearchAliasResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /tax-rules-groups` → 200 avec structure paginée
- [ ] `POST /tax-rules-groups` sans `name` → 422
- [ ] `GET /search-engines` → 200
- [ ] `POST /search-engines` sans `server` → 422
- [ ] `GET /search-aliases` → 200
- [ ] `POST /search-aliases` sans `search` ou `alias` → 422
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → tous passants

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/TaxRulesGroup/TaxRulesGroupResource.php`**

```php
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
```

- [ ] **Step 2 : Créer `src/Resource/SearchEngine/SearchEngineResource.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\SearchEngine;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class SearchEngineResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/search-engines',
            'identifierKey'     => 'searchEngineId',
            'operations'        => [
                'get'        => ['scope' => 'search_engine_read',  'method' => 'GET'],
                'list'       => ['scope' => 'search_engine_read',  'method' => 'GET'],
                'create'     => ['scope' => 'search_engine_write', 'method' => 'POST'],
                'update'     => ['scope' => 'search_engine_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'search_engine_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'search_engine_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $se = new \SearchEngine($id);
        if (!\Validate::isLoadedObject($se)) {
            throw new ResourceNotFoundException('SearchEngine', $id);
        }
        return $this->mapRow([
            'id_search_engine' => $se->id,
            'server'           => $se->server,
            'getvar'           => $se->getvar,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_search_engine, server, getvar');
        $q->from('search_engine');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_search_engine', [
            'searchEngineId' => 'id_search_engine',
            'server'         => 'server',
        ]);
        $this->applyPagination($q, $filters, 'id_search_engine');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['server', 'getvar']);

        $se         = new \SearchEngine();
        $se->server = $data['server'];
        $se->getvar = $data['getvar'];

        if (!$se->save()) {
            throw new \RuntimeException('Failed to create search engine.', 500);
        }
        return $this->get((int) $se->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $se = new \SearchEngine($id);
        if (!\Validate::isLoadedObject($se)) {
            throw new ResourceNotFoundException('SearchEngine', $id);
        }
        if (isset($data['server'])) { $se->server = $data['server']; }
        if (isset($data['getvar'])) { $se->getvar = $data['getvar']; }

        if (!$se->save()) {
            throw new \RuntimeException('Failed to update search engine.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $se = new \SearchEngine($id);
        if (!\Validate::isLoadedObject($se)) {
            throw new ResourceNotFoundException('SearchEngine', $id);
        }
        if (!$se->delete()) {
            throw new \RuntimeException('Failed to delete search engine.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['searchEngineIds'] ?? []);
        foreach ($ids as $id) {
            $se = new \SearchEngine($id);
            if (\Validate::isLoadedObject($se)) {
                $se->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'searchEngineId' => (int) $row['id_search_engine'],
            'server'         => $row['server'],
            'getvar'         => $row['getvar'],
        ];
    }
}
```

- [ ] **Step 3 : Créer `src/Resource/SearchAlias/SearchAliasResource.php`**

Note : la classe PS est `Alias` (table `ps_alias`, primary `id_alias`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\SearchAlias;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class SearchAliasResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/search-aliases',
            'identifierKey'     => 'searchAliasId',
            'operations'        => [
                'get'        => ['scope' => 'search_alias_read',  'method' => 'GET'],
                'list'       => ['scope' => 'search_alias_read',  'method' => 'GET'],
                'create'     => ['scope' => 'search_alias_write', 'method' => 'POST'],
                'update'     => ['scope' => 'search_alias_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'search_alias_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'search_alias_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $alias = new \Alias($id);
        if (!\Validate::isLoadedObject($alias)) {
            throw new ResourceNotFoundException('SearchAlias', $id);
        }
        return $this->mapRow([
            'id_alias' => $alias->id,
            'search'   => $alias->search,
            'alias'    => $alias->alias,
            'active'   => $alias->active,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_alias, search, alias, active');
        $q->from('alias');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_alias', [
            'searchAliasId' => 'id_alias',
            'search'        => 'search',
            'alias'         => 'alias',
        ]);
        $this->applyPagination($q, $filters, 'id_alias');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['search', 'alias']);

        $alias         = new \Alias();
        $alias->search = $data['search'];
        $alias->alias  = $data['alias'];
        $alias->active = (bool) ($data['active'] ?? true);

        if (!$alias->save()) {
            throw new \RuntimeException('Failed to create search alias.', 500);
        }
        return $this->get((int) $alias->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $alias = new \Alias($id);
        if (!\Validate::isLoadedObject($alias)) {
            throw new ResourceNotFoundException('SearchAlias', $id);
        }
        if (isset($data['search'])) { $alias->search = $data['search']; }
        if (isset($data['alias']))  { $alias->alias  = $data['alias']; }
        if (isset($data['active'])) { $alias->active  = (bool) $data['active']; }

        if (!$alias->save()) {
            throw new \RuntimeException('Failed to update search alias.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $alias = new \Alias($id);
        if (!\Validate::isLoadedObject($alias)) {
            throw new ResourceNotFoundException('SearchAlias', $id);
        }
        if (!$alias->delete()) {
            throw new \RuntimeException('Failed to delete search alias.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['searchAliasIds'] ?? []);
        foreach ($ids as $id) {
            $alias = new \Alias($id);
            if (\Validate::isLoadedObject($alias)) {
                $alias->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'searchAliasId' => (int) $row['id_alias'],
            'search'        => $row['search'],
            'alias'         => $row['alias'],
            'active'        => (bool) $row['active'],
        ];
    }
}
```

- [ ] **Step 4 : Mettre à jour `src/Resource/ResourceRegistry.php`**

Ajouter les use statements :

```php
use PrestaEdit\ApiModule\Resource\TaxRulesGroup\TaxRulesGroupResource;
use PrestaEdit\ApiModule\Resource\SearchEngine\SearchEngineResource;
use PrestaEdit\ApiModule\Resource\SearchAlias\SearchAliasResource;
```

Ajouter dans `$resources` :

```php
TaxRulesGroupResource::class,
SearchEngineResource::class,
SearchAliasResource::class,
```

- [ ] **Step 5 : Vérifier et committer**

```bash
php -l src/Resource/TaxRulesGroup/TaxRulesGroupResource.php
php -l src/Resource/SearchEngine/SearchEngineResource.php
php -l src/Resource/SearchAlias/SearchAliasResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/TaxRulesGroup/ src/Resource/SearchEngine/ src/Resource/SearchAlias/ src/Resource/ResourceRegistry.php
git commit -m "feat: TaxRulesGroupResource + SearchEngineResource + SearchAliasResource"
```

---

## Task 3 : WebserviceKey

**Goal:** Implémenter WebserviceKeyResource — génération automatique de clé à la création.

**Files:**
- Create: `src/Resource/WebserviceKey/WebserviceKeyResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /webservice-keys` → 200 avec structure paginée
- [ ] `POST /webservice-keys` sans `description` → 201 (description optionnelle)
- [ ] `POST /webservice-keys` → clé `key` auto-générée (32 chars hex uppercase) si absente du payload
- [ ] `POST /webservice-keys` avec `key` fourni → utilise la clé fournie
- [ ] `PATCH /webservice-keys/1` → 200
- [ ] `DELETE /webservice-keys/1` → 204
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/WebserviceKey/WebserviceKeyResource.php`**

Note : la classe PS est `WebserviceAccount` (table `ps_webservice_account`, primary `id_webservice_account`). Pas de table `_lang`. La clé est auto-générée via `strtoupper(bin2hex(random_bytes(16)))` (32 chars hex uppercase).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\WebserviceKey;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class WebserviceKeyResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/webservice-keys',
            'identifierKey'     => 'webserviceKeyId',
            'operations'        => [
                'get'        => ['scope' => 'webservice_key_read',  'method' => 'GET'],
                'list'       => ['scope' => 'webservice_key_read',  'method' => 'GET'],
                'create'     => ['scope' => 'webservice_key_write', 'method' => 'POST'],
                'update'     => ['scope' => 'webservice_key_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'webservice_key_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'webservice_key_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $wsa = new \WebserviceAccount($id);
        if (!\Validate::isLoadedObject($wsa)) {
            throw new ResourceNotFoundException('WebserviceKey', $id);
        }
        return $this->mapRow([
            'id_webservice_account' => $wsa->id,
            'key'                   => $wsa->key,
            'description'           => $wsa->description,
            'active'                => $wsa->active,
            'is_module'             => $wsa->is_module,
        ]);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_webservice_account, `key`, description, active, is_module');
        $q->from('webservice_account');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_webservice_account', [
            'webserviceKeyId' => 'id_webservice_account',
            'key'             => '`key`',
        ]);
        $this->applyPagination($q, $filters, 'id_webservice_account');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $wsa              = new \WebserviceAccount();
        $wsa->key         = isset($data['key']) && $data['key'] !== ''
            ? $data['key']
            : strtoupper(bin2hex(random_bytes(16)));
        $wsa->description = $data['description'] ?? '';
        $wsa->active      = (bool) ($data['active'] ?? true);
        $wsa->is_module   = (bool) ($data['isModule'] ?? false);

        if (!$wsa->save()) {
            throw new \RuntimeException('Failed to create webservice key.', 500);
        }
        return $this->get((int) $wsa->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $wsa = new \WebserviceAccount($id);
        if (!\Validate::isLoadedObject($wsa)) {
            throw new ResourceNotFoundException('WebserviceKey', $id);
        }
        if (isset($data['key']) && $data['key'] !== '') { $wsa->key         = $data['key']; }
        if (isset($data['description']))                 { $wsa->description = $data['description']; }
        if (isset($data['active']))                      { $wsa->active      = (bool) $data['active']; }

        if (!$wsa->save()) {
            throw new \RuntimeException('Failed to update webservice key.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $wsa = new \WebserviceAccount($id);
        if (!\Validate::isLoadedObject($wsa)) {
            throw new ResourceNotFoundException('WebserviceKey', $id);
        }
        if (!$wsa->delete()) {
            throw new \RuntimeException('Failed to delete webservice key.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['webserviceKeyIds'] ?? []);
        foreach ($ids as $id) {
            $wsa = new \WebserviceAccount($id);
            if (\Validate::isLoadedObject($wsa)) {
                $wsa->delete();
            }
        }
    }

    private function mapRow(array $row): array
    {
        return [
            'webserviceKeyId' => (int) $row['id_webservice_account'],
            'key'             => $row['key'],
            'description'     => $row['description'] ?? '',
            'active'          => (bool) $row['active'],
            'isModule'        => (bool) ($row['is_module'] ?? false),
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\WebserviceKey\WebserviceKeyResource;
// dans $resources :
WebserviceKeyResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/WebserviceKey/WebserviceKeyResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/WebserviceKey/ src/Resource/ResourceRegistry.php
git commit -m "feat: WebserviceKeyResource with auto-generated key"
```

---

## Task 4 : Title + Profile

**Goal:** Implémenter TitleResource (localisé, table `ps_gender`/`ps_gender_lang`) et ProfileResource (localisé, table `ps_profile`/`ps_profile_lang`).

**Files:**
- Create: `src/Resource/Title/TitleResource.php`
- Create: `src/Resource/Profile/ProfileResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /titles` → 200 avec `names` localisé `{"en-US":"Mr.","fr-FR":"M."}`
- [ ] `POST /titles` sans `type` ou `names` → 422
- [ ] `POST /titles` avec `{type:0, names:{...}}` → 201
- [ ] `GET /profiles` → 200 avec `names` localisé
- [ ] `POST /profiles` sans `names` → 422
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Title/TitleResource.php`**

Note : la classe PS est `Gender` (table `ps_gender`, primary `id_gender`, lang table `ps_gender_lang`). `type` : 0=M, 1=F, 2=Neutre.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Title;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class TitleResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/titles',
            'identifierKey'     => 'titleId',
            'operations'        => [
                'get'        => ['scope' => 'title_read',  'method' => 'GET'],
                'list'       => ['scope' => 'title_read',  'method' => 'GET'],
                'create'     => ['scope' => 'title_write', 'method' => 'POST'],
                'update'     => ['scope' => 'title_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'title_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'title_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $gender = new \Gender($id, $context['langId']);
        if (!\Validate::isLoadedObject($gender)) {
            throw new ResourceNotFoundException('Title', $id);
        }
        return $this->map($gender);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_gender');
        $q->from('gender');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_gender', ['titleId' => 'id_gender']);
        $this->applyPagination($q, $filters, 'id_gender');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_gender'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['type', 'names']);

        $gender       = new \Gender();
        $gender->type = (int) $data['type'];
        $gender->name = $this->buildPsLocalizedField($data['names']);

        if (!$gender->save()) {
            throw new \RuntimeException('Failed to create title.', 500);
        }
        return $this->get((int) $gender->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $gender = new \Gender($id, $context['langId']);
        if (!\Validate::isLoadedObject($gender)) {
            throw new ResourceNotFoundException('Title', $id);
        }
        if (isset($data['type']))  { $gender->type = (int) $data['type']; }
        if (isset($data['names'])) { $gender->name = $this->buildPsLocalizedField($data['names']); }

        if (!$gender->save()) {
            throw new \RuntimeException('Failed to update title.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $gender = new \Gender($id);
        if (!\Validate::isLoadedObject($gender)) {
            throw new ResourceNotFoundException('Title', $id);
        }
        if (!$gender->delete()) {
            throw new \RuntimeException('Failed to delete title.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['titleIds'] ?? []);
        foreach ($ids as $id) {
            $gender = new \Gender($id);
            if (\Validate::isLoadedObject($gender)) {
                $gender->delete();
            }
        }
    }

    private function map(\Gender $gender): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'gender_lang`
             WHERE `id_gender` = ' . (int) $gender->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'titleId' => (int) $gender->id,
            'type'    => (int) $gender->type,
            'names'   => $this->getLocalizedField($names),
        ];
    }
}
```

- [ ] **Step 2 : Créer `src/Resource/Profile/ProfileResource.php`**

Note : la classe PS est `Profile` (table `ps_profile`, primary `id_profile`, lang table `ps_profile_lang`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Profile;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ProfileResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/profiles',
            'identifierKey'     => 'profileId',
            'operations'        => [
                'get'        => ['scope' => 'profile_read',  'method' => 'GET'],
                'list'       => ['scope' => 'profile_read',  'method' => 'GET'],
                'create'     => ['scope' => 'profile_write', 'method' => 'POST'],
                'update'     => ['scope' => 'profile_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'profile_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'profile_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $profile = new \Profile($id, $context['langId']);
        if (!\Validate::isLoadedObject($profile)) {
            throw new ResourceNotFoundException('Profile', $id);
        }
        return $this->map($profile);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_profile');
        $q->from('profile');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_profile', ['profileId' => 'id_profile']);
        $this->applyPagination($q, $filters, 'id_profile');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_profile'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $profile       = new \Profile();
        $profile->name = $this->buildPsLocalizedField($data['names']);

        if (!$profile->save()) {
            throw new \RuntimeException('Failed to create profile.', 500);
        }
        return $this->get((int) $profile->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $profile = new \Profile($id, $context['langId']);
        if (!\Validate::isLoadedObject($profile)) {
            throw new ResourceNotFoundException('Profile', $id);
        }
        if (isset($data['names'])) {
            $profile->name = $this->buildPsLocalizedField($data['names']);
        }
        if (!$profile->save()) {
            throw new \RuntimeException('Failed to update profile.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $profile = new \Profile($id);
        if (!\Validate::isLoadedObject($profile)) {
            throw new ResourceNotFoundException('Profile', $id);
        }
        if (!$profile->delete()) {
            throw new \RuntimeException('Failed to delete profile.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['profileIds'] ?? []);
        foreach ($ids as $id) {
            $profile = new \Profile($id);
            if (\Validate::isLoadedObject($profile)) {
                $profile->delete();
            }
        }
    }

    private function map(\Profile $profile): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'profile_lang`
             WHERE `id_profile` = ' . (int) $profile->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'profileId' => (int) $profile->id,
            'names'     => $this->getLocalizedField($names),
        ];
    }
}
```

- [ ] **Step 3 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Title\TitleResource;
use PrestaEdit\ApiModule\Resource\Profile\ProfileResource;
// dans $resources :
TitleResource::class,
ProfileResource::class,
```

- [ ] **Step 4 : Vérifier et committer**

```bash
php -l src/Resource/Title/TitleResource.php
php -l src/Resource/Profile/ProfileResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Title/ src/Resource/Profile/ src/Resource/ResourceRegistry.php
git commit -m "feat: TitleResource + ProfileResource — localized simple resources"
```

---

## Task 5 : Tax

**Goal:** Implémenter TaxResource — localisé, avec soft-delete (`deleted` flag) et champ `rate` décimal.

**Files:**
- Create: `src/Resource/Tax/TaxResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /taxes` → liste uniquement les taxes non supprimées (`deleted = 0`)
- [ ] `GET /taxes/1` → 404 si `deleted = 1`
- [ ] `POST /taxes` sans `rate` ou `names` → 422
- [ ] `POST /taxes` → 201 avec `rate` en string décimal (`"8.500000"`)
- [ ] `DELETE /taxes/1` → 204 (soft-delete, `deleted = 1`)
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Tax/TaxResource.php`**

Note : classe PS `Tax` (table `ps_tax`, primary `id_tax`, lang table `ps_tax_lang` pour `name`). Champ `deleted` = soft-delete. Champ `rate` = DECIMAL(10,3) — exposé comme string à 6 décimales via `$this->decimal()`.

```php
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
        // Soft-delete : ps_tax uses deleted flag
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
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Tax\TaxResource;
// dans $resources :
TaxResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/Tax/TaxResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Tax/ src/Resource/ResourceRegistry.php
git commit -m "feat: TaxResource — localized with soft-delete and decimal rate"
```

---

## Task 6 : Country

**Goal:** Implémenter CountryResource — localisé, avec zone FK et nombreux flags booléens.

**Files:**
- Create: `src/Resource/Country/CountryResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /countries` → 200 avec `names` localisé et `isoCode`
- [ ] `POST /countries` sans `isoCode`, `idZone` ou `names` → 422
- [ ] `POST /countries` → 201
- [ ] `PATCH /countries/1` → 200
- [ ] `DELETE /countries/1` → 204
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Country/CountryResource.php`**

Note : classe PS `Country` (table `ps_country`, primary `id_country`, lang table `ps_country_lang` pour `name`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Country;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CountryResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/countries',
            'identifierKey'     => 'countryId',
            'operations'        => [
                'get'        => ['scope' => 'country_read',  'method' => 'GET'],
                'list'       => ['scope' => 'country_read',  'method' => 'GET'],
                'create'     => ['scope' => 'country_write', 'method' => 'POST'],
                'update'     => ['scope' => 'country_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'country_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'country_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $country = new \Country($id, $context['langId']);
        if (!\Validate::isLoadedObject($country)) {
            throw new ResourceNotFoundException('Country', $id);
        }
        return $this->map($country);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_country');
        $q->from('country', 'c');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'c.id_country', [
            'countryId' => 'c.id_country',
            'isoCode'   => 'c.iso_code',
        ]);
        $this->applyPagination($q, $filters, 'id_country');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_country'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['isoCode', 'idZone', 'names']);

        $country          = new \Country();
        $country->iso_code = $data['isoCode'];
        $country->id_zone  = (int) $data['idZone'];
        $country->name     = $this->buildPsLocalizedField($data['names']);
        $country->active   = (bool) ($data['active'] ?? true);

        if (isset($data['callPrefix']))               { $country->call_prefix                 = (int) $data['callPrefix']; }
        if (isset($data['containsStates']))           { $country->contains_states             = (bool) $data['containsStates']; }
        if (isset($data['needIdentificationNumber'])) { $country->need_identification_number  = (bool) $data['needIdentificationNumber']; }
        if (isset($data['needZipCode']))              { $country->need_zip_code               = (bool) $data['needZipCode']; }
        if (isset($data['zipCodeFormat']))            { $country->zip_code_format             = $data['zipCodeFormat']; }
        if (isset($data['displayTaxLabel']))          { $country->display_tax_label           = (bool) $data['displayTaxLabel']; }

        if (!$country->save()) {
            throw new \RuntimeException('Failed to create country.', 500);
        }
        return $this->get((int) $country->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $country = new \Country($id, $context['langId']);
        if (!\Validate::isLoadedObject($country)) {
            throw new ResourceNotFoundException('Country', $id);
        }
        if (isset($data['isoCode']))                  { $country->iso_code                   = $data['isoCode']; }
        if (isset($data['idZone']))                   { $country->id_zone                    = (int) $data['idZone']; }
        if (isset($data['names']))                    { $country->name                       = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['active']))                   { $country->active                     = (bool) $data['active']; }
        if (isset($data['callPrefix']))               { $country->call_prefix                = (int) $data['callPrefix']; }
        if (isset($data['containsStates']))           { $country->contains_states            = (bool) $data['containsStates']; }
        if (isset($data['needIdentificationNumber'])) { $country->need_identification_number = (bool) $data['needIdentificationNumber']; }
        if (isset($data['needZipCode']))              { $country->need_zip_code              = (bool) $data['needZipCode']; }
        if (isset($data['zipCodeFormat']))            { $country->zip_code_format            = $data['zipCodeFormat']; }
        if (isset($data['displayTaxLabel']))          { $country->display_tax_label          = (bool) $data['displayTaxLabel']; }

        if (!$country->save()) {
            throw new \RuntimeException('Failed to update country.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $country = new \Country($id);
        if (!\Validate::isLoadedObject($country)) {
            throw new ResourceNotFoundException('Country', $id);
        }
        if (!$country->delete()) {
            throw new \RuntimeException('Failed to delete country.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['countryIds'] ?? []);
        foreach ($ids as $id) {
            $country = new \Country($id);
            if (\Validate::isLoadedObject($country)) {
                $country->delete();
            }
        }
    }

    private function map(\Country $country): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'country_lang`
             WHERE `id_country` = ' . (int) $country->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'countryId'                 => (int) $country->id,
            'isoCode'                   => $country->iso_code,
            'idZone'                    => (int) $country->id_zone,
            'callPrefix'                => (int) $country->call_prefix,
            'active'                    => (bool) $country->active,
            'containsStates'            => (bool) $country->contains_states,
            'needIdentificationNumber'  => (bool) $country->need_identification_number,
            'needZipCode'               => (bool) $country->need_zip_code,
            'zipCodeFormat'             => $country->zip_code_format ?? '',
            'displayTaxLabel'           => (bool) $country->display_tax_label,
            'names'                     => $this->getLocalizedField($names),
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Country\CountryResource;
// dans $resources :
CountryResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/Country/CountryResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Country/ src/Resource/ResourceRegistry.php
git commit -m "feat: CountryResource — localized with zone FK and boolean flags"
```

---

## Task 7 : Tab

**Goal:** Implémenter TabResource — menus du back-office PS, localisé avec `id_parent` et `position`.

**Files:**
- Create: `src/Resource/Tab/TabResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /tabs` → 200 avec `names` localisé, `className`, `idParent`
- [ ] `POST /tabs` sans `className` ou `names` → 422
- [ ] `POST /tabs` → 201
- [ ] `PATCH /tabs/1` → 200
- [ ] `DELETE /tabs/1` → 204
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Tab/TabResource.php`**

Note : classe PS `Tab` (table `ps_tab`, primary `id_tab`, lang table `ps_tab_lang` pour `name`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Tab;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class TabResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/tabs',
            'identifierKey'     => 'tabId',
            'operations'        => [
                'get'        => ['scope' => 'tab_read',  'method' => 'GET'],
                'list'       => ['scope' => 'tab_read',  'method' => 'GET'],
                'create'     => ['scope' => 'tab_write', 'method' => 'POST'],
                'update'     => ['scope' => 'tab_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'tab_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'tab_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $tab = new \Tab($id, $context['langId']);
        if (!\Validate::isLoadedObject($tab)) {
            throw new ResourceNotFoundException('Tab', $id);
        }
        return $this->map($tab);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_tab');
        $q->from('tab');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_tab', [
            'tabId'     => 'id_tab',
            'position'  => 'position',
            'className' => 'class_name',
        ]);
        $this->applyPagination($q, $filters, 'id_tab');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_tab'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['className', 'names']);

        $tab             = new \Tab();
        $tab->class_name = $data['className'];
        $tab->name       = $this->buildPsLocalizedField($data['names']);
        $tab->id_parent  = (int) ($data['idParent'] ?? 0);
        $tab->position   = (int) ($data['position'] ?? 0);
        $tab->active     = (bool) ($data['active'] ?? true);
        $tab->module     = $data['module'] ?? '';
        $tab->icon       = $data['icon'] ?? '';

        if (!$tab->save()) {
            throw new \RuntimeException('Failed to create tab.', 500);
        }
        return $this->get((int) $tab->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $tab = new \Tab($id, $context['langId']);
        if (!\Validate::isLoadedObject($tab)) {
            throw new ResourceNotFoundException('Tab', $id);
        }
        if (isset($data['className'])) { $tab->class_name = $data['className']; }
        if (isset($data['names']))     { $tab->name       = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['idParent']))  { $tab->id_parent  = (int) $data['idParent']; }
        if (isset($data['position']))  { $tab->position   = (int) $data['position']; }
        if (isset($data['active']))    { $tab->active      = (bool) $data['active']; }
        if (isset($data['icon']))      { $tab->icon        = $data['icon']; }

        if (!$tab->save()) {
            throw new \RuntimeException('Failed to update tab.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $tab = new \Tab($id);
        if (!\Validate::isLoadedObject($tab)) {
            throw new ResourceNotFoundException('Tab', $id);
        }
        if (!$tab->delete()) {
            throw new \RuntimeException('Failed to delete tab.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['tabIds'] ?? []);
        foreach ($ids as $id) {
            $tab = new \Tab($id);
            if (\Validate::isLoadedObject($tab)) {
                $tab->delete();
            }
        }
    }

    private function map(\Tab $tab): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'tab_lang`
             WHERE `id_tab` = ' . (int) $tab->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'tabId'     => (int) $tab->id,
            'className' => $tab->class_name,
            'idParent'  => (int) $tab->id_parent,
            'position'  => (int) $tab->position,
            'module'    => $tab->module ?? '',
            'active'    => (bool) $tab->active,
            'icon'      => $tab->icon ?? '',
            'names'     => $this->getLocalizedField($names),
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Tab\TabResource;
// dans $resources :
TabResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/Tab/TabResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Tab/ src/Resource/ResourceRegistry.php
git commit -m "feat: TabResource — localized back-office menu items"
```

---

## Task 8 : Manufacturer + Supplier

**Goal:** Implémenter ManufacturerResource et SupplierResource — localisés avec descriptions.

**Files:**
- Create: `src/Resource/Manufacturer/ManufacturerResource.php`
- Create: `src/Resource/Supplier/SupplierResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /manufacturers` → 200 avec `name`, `descriptions` localisé
- [ ] `POST /manufacturers` sans `name` → 422
- [ ] `GET /suppliers` → 200 avec `name`, `descriptions` localisé
- [ ] `POST /suppliers` sans `name` → 422
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Manufacturer/ManufacturerResource.php`**

Note : classe PS `Manufacturer` (table `ps_manufacturer`, primary `id_manufacturer`, lang table `ps_manufacturer_lang` avec colonnes `description`, `short_description`, `meta_title`, `meta_description`, `meta_keywords`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Manufacturer;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ManufacturerResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/manufacturers',
            'identifierKey'     => 'manufacturerId',
            'operations'        => [
                'get'        => ['scope' => 'manufacturer_read',  'method' => 'GET'],
                'list'       => ['scope' => 'manufacturer_read',  'method' => 'GET'],
                'create'     => ['scope' => 'manufacturer_write', 'method' => 'POST'],
                'update'     => ['scope' => 'manufacturer_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'manufacturer_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'manufacturer_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $manufacturer = new \Manufacturer($id, $context['langId']);
        if (!\Validate::isLoadedObject($manufacturer)) {
            throw new ResourceNotFoundException('Manufacturer', $id);
        }
        return $this->map($manufacturer);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('m.id_manufacturer');
        $q->from('manufacturer', 'm');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'm.id_manufacturer', [
            'manufacturerId' => 'm.id_manufacturer',
            'name'           => 'm.name',
        ]);
        $this->applyPagination($q, $filters, 'id_manufacturer');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_manufacturer'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['name']);

        $m         = new \Manufacturer();
        $m->name   = $data['name'];
        $m->active = (bool) ($data['active'] ?? true);

        if (isset($data['descriptions']))      { $m->description       = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['shortDescriptions'])) { $m->short_description = $this->buildPsLocalizedField($data['shortDescriptions']); }

        if (!$m->save()) {
            throw new \RuntimeException('Failed to create manufacturer.', 500);
        }
        return $this->get((int) $m->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $m = new \Manufacturer($id, $context['langId']);
        if (!\Validate::isLoadedObject($m)) {
            throw new ResourceNotFoundException('Manufacturer', $id);
        }
        if (isset($data['name']))              { $m->name              = $data['name']; }
        if (isset($data['active']))            { $m->active            = (bool) $data['active']; }
        if (isset($data['descriptions']))      { $m->description       = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['shortDescriptions'])) { $m->short_description = $this->buildPsLocalizedField($data['shortDescriptions']); }

        if (!$m->save()) {
            throw new \RuntimeException('Failed to update manufacturer.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $m = new \Manufacturer($id);
        if (!\Validate::isLoadedObject($m)) {
            throw new ResourceNotFoundException('Manufacturer', $id);
        }
        if (!$m->delete()) {
            throw new \RuntimeException('Failed to delete manufacturer.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['manufacturerIds'] ?? []);
        foreach ($ids as $id) {
            $m = new \Manufacturer($id);
            if (\Validate::isLoadedObject($m)) {
                $m->delete();
            }
        }
    }

    private function map(\Manufacturer $m): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `description`, `short_description`
             FROM `' . _DB_PREFIX_ . 'manufacturer_lang`
             WHERE `id_manufacturer` = ' . (int) $m->id
        );

        $descs       = array_column($rows ?: [], 'description', 'id_lang');
        $shortDescs  = array_column($rows ?: [], 'short_description', 'id_lang');

        return [
            'manufacturerId'    => (int) $m->id,
            'name'              => $m->name,
            'active'            => (bool) $m->active,
            'dateAdd'           => $m->date_add,
            'dateUpd'           => $m->date_upd,
            'descriptions'      => $this->getLocalizedField($descs),
            'shortDescriptions' => $this->getLocalizedField($shortDescs),
        ];
    }
}
```

- [ ] **Step 2 : Créer `src/Resource/Supplier/SupplierResource.php`**

Note : classe PS `Supplier` (table `ps_supplier`, primary `id_supplier`, lang table `ps_supplier_lang` avec colonnes `description`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Supplier;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class SupplierResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/suppliers',
            'identifierKey'     => 'supplierId',
            'operations'        => [
                'get'        => ['scope' => 'supplier_read',  'method' => 'GET'],
                'list'       => ['scope' => 'supplier_read',  'method' => 'GET'],
                'create'     => ['scope' => 'supplier_write', 'method' => 'POST'],
                'update'     => ['scope' => 'supplier_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'supplier_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'supplier_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $supplier = new \Supplier($id, $context['langId']);
        if (!\Validate::isLoadedObject($supplier)) {
            throw new ResourceNotFoundException('Supplier', $id);
        }
        return $this->map($supplier);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('s.id_supplier');
        $q->from('supplier', 's');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 's.id_supplier', [
            'supplierId' => 's.id_supplier',
            'name'       => 's.name',
        ]);
        $this->applyPagination($q, $filters, 'id_supplier');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_supplier'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['name']);

        $s         = new \Supplier();
        $s->name   = $data['name'];
        $s->active = (bool) ($data['active'] ?? true);

        if (isset($data['descriptions'])) {
            $s->description = $this->buildPsLocalizedField($data['descriptions']);
        }

        if (!$s->save()) {
            throw new \RuntimeException('Failed to create supplier.', 500);
        }
        return $this->get((int) $s->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $s = new \Supplier($id, $context['langId']);
        if (!\Validate::isLoadedObject($s)) {
            throw new ResourceNotFoundException('Supplier', $id);
        }
        if (isset($data['name']))         { $s->name        = $data['name']; }
        if (isset($data['active']))       { $s->active       = (bool) $data['active']; }
        if (isset($data['descriptions'])) { $s->description  = $this->buildPsLocalizedField($data['descriptions']); }

        if (!$s->save()) {
            throw new \RuntimeException('Failed to update supplier.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $s = new \Supplier($id);
        if (!\Validate::isLoadedObject($s)) {
            throw new ResourceNotFoundException('Supplier', $id);
        }
        if (!$s->delete()) {
            throw new \RuntimeException('Failed to delete supplier.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['supplierIds'] ?? []);
        foreach ($ids as $id) {
            $s = new \Supplier($id);
            if (\Validate::isLoadedObject($s)) {
                $s->delete();
            }
        }
    }

    private function map(\Supplier $s): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `description`
             FROM `' . _DB_PREFIX_ . 'supplier_lang`
             WHERE `id_supplier` = ' . (int) $s->id
        );
        $descs = array_column($rows ?: [], 'description', 'id_lang');

        return [
            'supplierId'   => (int) $s->id,
            'name'         => $s->name,
            'active'       => (bool) $s->active,
            'dateAdd'      => $s->date_add,
            'dateUpd'      => $s->date_upd,
            'descriptions' => $this->getLocalizedField($descs),
        ];
    }
}
```

- [ ] **Step 3 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Manufacturer\ManufacturerResource;
use PrestaEdit\ApiModule\Resource\Supplier\SupplierResource;
// dans $resources :
ManufacturerResource::class,
SupplierResource::class,
```

- [ ] **Step 4 : Vérifier et committer**

```bash
php -l src/Resource/Manufacturer/ManufacturerResource.php
php -l src/Resource/Supplier/SupplierResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Manufacturer/ src/Resource/Supplier/ src/Resource/ResourceRegistry.php
git commit -m "feat: ManufacturerResource + SupplierResource — localized with descriptions"
```

---

## Task 9 : Store

**Goal:** Implémenter StoreResource — localisé avec champs d'adresse et coordonnées géographiques.

**Files:**
- Create: `src/Resource/Store/StoreResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /stores` → 200 avec `city`, `names` localisé, `latitude`, `longitude`
- [ ] `POST /stores` sans `idCountry`, `city`, `postcode` ou `names` → 422
- [ ] `POST /stores` → 201
- [ ] `PATCH /stores/1` → 200
- [ ] `DELETE /stores/1` → 204
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Store/StoreResource.php`**

Note : classe PS `Store` (table `ps_store`, primary `id_store`, lang table `ps_store_lang` avec colonnes `name`, `address1`, `address2`, `hours`, `note`).

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Store;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class StoreResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/stores',
            'identifierKey'     => 'storeId',
            'operations'        => [
                'get'        => ['scope' => 'store_read',  'method' => 'GET'],
                'list'       => ['scope' => 'store_read',  'method' => 'GET'],
                'create'     => ['scope' => 'store_write', 'method' => 'POST'],
                'update'     => ['scope' => 'store_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'store_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'store_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $store = new \Store($id, $context['langId']);
        if (!\Validate::isLoadedObject($store)) {
            throw new ResourceNotFoundException('Store', $id);
        }
        return $this->map($store);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('s.id_store');
        $q->from('store', 's');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 's.id_store', [
            'storeId' => 's.id_store',
            'city'    => 's.city',
        ]);
        $this->applyPagination($q, $filters, 'id_store');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_store'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idCountry', 'city', 'postcode', 'names']);

        $store             = new \Store();
        $store->id_country = (int) $data['idCountry'];
        $store->id_state   = (int) ($data['idState'] ?? 0);
        $store->city       = $data['city'];
        $store->postcode   = $data['postcode'];
        $store->active     = (bool) ($data['active'] ?? true);
        $store->phone      = $data['phone'] ?? '';
        $store->fax        = $data['fax'] ?? '';
        $store->email      = $data['email'] ?? '';
        $store->latitude   = (float) ($data['latitude'] ?? 0);
        $store->longitude  = (float) ($data['longitude'] ?? 0);
        $store->name       = $this->buildPsLocalizedField($data['names']);

        if (isset($data['addressLines']))  { $store->address1 = $this->buildPsLocalizedField($data['addressLines']); }
        if (isset($data['addressLines2'])) { $store->address2 = $this->buildPsLocalizedField($data['addressLines2']); }
        if (isset($data['hours']))         { $store->hours    = $this->buildPsLocalizedField($data['hours']); }
        if (isset($data['notes']))         { $store->note     = $this->buildPsLocalizedField($data['notes']); }

        if (!$store->save()) {
            throw new \RuntimeException('Failed to create store.', 500);
        }
        return $this->get((int) $store->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $store = new \Store($id, $context['langId']);
        if (!\Validate::isLoadedObject($store)) {
            throw new ResourceNotFoundException('Store', $id);
        }
        if (isset($data['idCountry']))   { $store->id_country = (int) $data['idCountry']; }
        if (isset($data['idState']))     { $store->id_state   = (int) $data['idState']; }
        if (isset($data['city']))        { $store->city       = $data['city']; }
        if (isset($data['postcode']))    { $store->postcode   = $data['postcode']; }
        if (isset($data['active']))      { $store->active     = (bool) $data['active']; }
        if (isset($data['phone']))       { $store->phone      = $data['phone']; }
        if (isset($data['fax']))         { $store->fax        = $data['fax']; }
        if (isset($data['email']))       { $store->email      = $data['email']; }
        if (isset($data['latitude']))    { $store->latitude   = (float) $data['latitude']; }
        if (isset($data['longitude']))   { $store->longitude  = (float) $data['longitude']; }
        if (isset($data['names']))       { $store->name       = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['addressLines'])) { $store->address1  = $this->buildPsLocalizedField($data['addressLines']); }
        if (isset($data['addressLines2'])){ $store->address2  = $this->buildPsLocalizedField($data['addressLines2']); }
        if (isset($data['hours']))        { $store->hours     = $this->buildPsLocalizedField($data['hours']); }
        if (isset($data['notes']))        { $store->note      = $this->buildPsLocalizedField($data['notes']); }

        if (!$store->save()) {
            throw new \RuntimeException('Failed to update store.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $store = new \Store($id);
        if (!\Validate::isLoadedObject($store)) {
            throw new ResourceNotFoundException('Store', $id);
        }
        if (!$store->delete()) {
            throw new \RuntimeException('Failed to delete store.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['storeIds'] ?? []);
        foreach ($ids as $id) {
            $store = new \Store($id);
            if (\Validate::isLoadedObject($store)) {
                $store->delete();
            }
        }
    }

    private function map(\Store $store): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name`, `address1`, `address2`, `hours`, `note`
             FROM `' . _DB_PREFIX_ . 'store_lang`
             WHERE `id_store` = ' . (int) $store->id
        );

        $names    = array_column($rows ?: [], 'name',     'id_lang');
        $addr1    = array_column($rows ?: [], 'address1', 'id_lang');
        $addr2    = array_column($rows ?: [], 'address2', 'id_lang');
        $hours    = array_column($rows ?: [], 'hours',    'id_lang');
        $notes    = array_column($rows ?: [], 'note',     'id_lang');

        return [
            'storeId'      => (int) $store->id,
            'idCountry'    => (int) $store->id_country,
            'idState'      => (int) $store->id_state,
            'city'         => $store->city,
            'postcode'     => $store->postcode,
            'active'       => (bool) $store->active,
            'phone'        => $store->phone ?? '',
            'fax'          => $store->fax ?? '',
            'email'        => $store->email ?? '',
            'latitude'     => (float) $store->latitude,
            'longitude'    => (float) $store->longitude,
            'names'        => $this->getLocalizedField($names),
            'addressLines' => $this->getLocalizedField($addr1),
            'addressLines2'=> $this->getLocalizedField($addr2),
            'hours'        => $this->getLocalizedField($hours),
            'notes'        => $this->getLocalizedField($notes),
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Store\StoreResource;
// dans $resources :
StoreResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/Store/StoreResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Store/ src/Resource/ResourceRegistry.php
git commit -m "feat: StoreResource — localized with geo coordinates and hours"
```

---

## Task 10 : Address

**Goal:** Implémenter AddressResource — non-localisé avec soft-delete et nombreux champs FK.

**Files:**
- Create: `src/Resource/Address/AddressResource.php`
- Modify: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `GET /addresses` → liste uniquement les adresses non supprimées (`deleted = 0`)
- [ ] `GET /addresses/1` → 404 si `deleted = 1`
- [ ] `POST /addresses` sans `idCountry`, `alias`, `lastname`, `firstname`, `address1`, `city` → 422
- [ ] `POST /addresses` → 201
- [ ] `DELETE /addresses/1` → 204 (soft-delete)
- [ ] `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → 0 failure

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/Address/AddressResource.php`**

Note : classe PS `Address` (table `ps_address`, primary `id_address`). Champ `deleted` = soft-delete. Pas de table `_lang`.

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Address;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class AddressResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/addresses',
            'identifierKey'     => 'addressId',
            'operations'        => [
                'get'        => ['scope' => 'address_read',  'method' => 'GET'],
                'list'       => ['scope' => 'address_read',  'method' => 'GET'],
                'create'     => ['scope' => 'address_write', 'method' => 'POST'],
                'update'     => ['scope' => 'address_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'address_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'address_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $address = new \Address($id);
        if (!\Validate::isLoadedObject($address) || $address->deleted) {
            throw new ResourceNotFoundException('Address', $id);
        }
        return $this->mapRow($address);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('a.id_address, a.id_customer, a.id_country, a.id_state, a.alias,
                    a.company, a.lastname, a.firstname, a.vat_number, a.address1, a.address2,
                    a.postcode, a.city, a.phone, a.phone_mobile, a.other, a.active, a.date_add, a.date_upd');
        $q->from('address', 'a');
        $q->where('a.deleted = 0');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'a.id_address', [
            'addressId'  => 'a.id_address',
            'lastname'   => 'a.lastname',
            'city'       => 'a.city',
        ]);
        $this->applyPagination($q, $filters, 'id_address');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapFromRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idCountry', 'alias', 'lastname', 'firstname', 'address1', 'city']);

        $a              = new \Address();
        $a->id_country  = (int) $data['idCountry'];
        $a->id_state    = (int) ($data['idState'] ?? 0);
        $a->id_customer = (int) ($data['idCustomer'] ?? 0);
        $a->alias       = $data['alias'];
        $a->lastname    = $data['lastname'];
        $a->firstname   = $data['firstname'];
        $a->address1    = $data['address1'];
        $a->address2    = $data['address2'] ?? '';
        $a->postcode    = $data['postcode'] ?? '';
        $a->city        = $data['city'];
        $a->phone       = $data['phone'] ?? '';
        $a->phone_mobile= $data['phoneMobile'] ?? '';
        $a->company     = $data['company'] ?? '';
        $a->vat_number  = $data['vatNumber'] ?? '';
        $a->other       = $data['other'] ?? '';
        $a->active      = (bool) ($data['active'] ?? true);

        if (!$a->save()) {
            throw new \RuntimeException('Failed to create address.', 500);
        }
        return $this->get((int) $a->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $a = new \Address($id);
        if (!\Validate::isLoadedObject($a) || $a->deleted) {
            throw new ResourceNotFoundException('Address', $id);
        }
        if (isset($data['idCountry']))  { $a->id_country   = (int) $data['idCountry']; }
        if (isset($data['idState']))    { $a->id_state      = (int) $data['idState']; }
        if (isset($data['idCustomer'])) { $a->id_customer   = (int) $data['idCustomer']; }
        if (isset($data['alias']))      { $a->alias         = $data['alias']; }
        if (isset($data['lastname']))   { $a->lastname      = $data['lastname']; }
        if (isset($data['firstname']))  { $a->firstname     = $data['firstname']; }
        if (isset($data['address1']))   { $a->address1      = $data['address1']; }
        if (isset($data['address2']))   { $a->address2      = $data['address2']; }
        if (isset($data['postcode']))   { $a->postcode      = $data['postcode']; }
        if (isset($data['city']))       { $a->city          = $data['city']; }
        if (isset($data['phone']))      { $a->phone         = $data['phone']; }
        if (isset($data['phoneMobile'])){ $a->phone_mobile  = $data['phoneMobile']; }
        if (isset($data['company']))    { $a->company       = $data['company']; }
        if (isset($data['vatNumber']))  { $a->vat_number    = $data['vatNumber']; }
        if (isset($data['other']))      { $a->other         = $data['other']; }
        if (isset($data['active']))     { $a->active        = (bool) $data['active']; }

        if (!$a->save()) {
            throw new \RuntimeException('Failed to update address.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $a = new \Address($id);
        if (!\Validate::isLoadedObject($a) || $a->deleted) {
            throw new ResourceNotFoundException('Address', $id);
        }
        // Soft-delete : ps_address uses deleted flag
        $a->deleted = 1;
        if (!$a->save()) {
            throw new \RuntimeException('Failed to delete address.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['addressIds'] ?? []);
        foreach ($ids as $id) {
            $a = new \Address($id);
            if (\Validate::isLoadedObject($a) && !$a->deleted) {
                $a->deleted = 1;
                $a->save();
            }
        }
    }

    private function mapRow(\Address $a): array
    {
        return $this->mapFromRow([
            'id_address'   => $a->id,
            'id_customer'  => $a->id_customer,
            'id_country'   => $a->id_country,
            'id_state'     => $a->id_state,
            'alias'        => $a->alias,
            'company'      => $a->company,
            'lastname'     => $a->lastname,
            'firstname'    => $a->firstname,
            'vat_number'   => $a->vat_number,
            'address1'     => $a->address1,
            'address2'     => $a->address2,
            'postcode'     => $a->postcode,
            'city'         => $a->city,
            'phone'        => $a->phone,
            'phone_mobile' => $a->phone_mobile,
            'other'        => $a->other,
            'active'       => $a->active,
            'date_add'     => $a->date_add,
            'date_upd'     => $a->date_upd,
        ]);
    }

    private function mapFromRow(array $row): array
    {
        return [
            'addressId'  => (int) $row['id_address'],
            'idCustomer' => (int) ($row['id_customer'] ?? 0),
            'idCountry'  => (int) ($row['id_country'] ?? 0),
            'idState'    => (int) ($row['id_state'] ?? 0),
            'alias'      => $row['alias'] ?? '',
            'company'    => $row['company'] ?? '',
            'lastname'   => $row['lastname'] ?? '',
            'firstname'  => $row['firstname'] ?? '',
            'vatNumber'  => $row['vat_number'] ?? '',
            'address1'   => $row['address1'] ?? '',
            'address2'   => $row['address2'] ?? '',
            'postcode'   => $row['postcode'] ?? '',
            'city'       => $row['city'] ?? '',
            'phone'      => $row['phone'] ?? '',
            'phoneMobile'=> $row['phone_mobile'] ?? '',
            'other'      => $row['other'] ?? '',
            'active'     => (bool) ($row['active'] ?? true),
            'dateAdd'    => $row['date_add'] ?? '',
            'dateUpd'    => $row['date_upd'] ?? '',
        ];
    }
}
```

- [ ] **Step 2 : Mettre à jour `src/Resource/ResourceRegistry.php`**

```php
use PrestaEdit\ApiModule\Resource\Address\AddressResource;
// dans $resources :
AddressResource::class,
```

- [ ] **Step 3 : Vérifier et committer**

```bash
php -l src/Resource/Address/AddressResource.php
vendor/bin/phpunit tests/Unit/ --testdox
git add src/Resource/Address/ src/Resource/ResourceRegistry.php
git commit -m "feat: AddressResource — non-localized with soft-delete"
```
