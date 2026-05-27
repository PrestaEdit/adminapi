# Design — Module PrestaShop Admin API (PS 1.7 / 8)

**Date :** 2026-05-27  
**Statut :** Approuvé  
**Compatibilité :** PrestaShop 1.7.6.0 → 8.99.99 · PHP 7.4+

---

## Contexte

`ps_apiresources` est le module officiel PrestaShop 9 qui expose une Admin API REST complète (25+ domaines) basée sur API Platform 3 + CQRS. Ce module n'existe pas pour PrestaShop 1.7.

L'objectif est de créer un module PrestaShop 1.7 qui :
- Expose les **mêmes routes** que `ps_apiresources` (`/admin-api/*`)
- Implémente le **même protocole OAuth2** (Client Credentials · JWT · `POST /admin-api/access_token`)
- Couvre les **mêmes domaines** avec le même contrat JSON
- Supporte le **multi-shop** avec les mêmes paramètres de contexte

---

## Architecture générale

### Structure du module

```
apimodule/
├── apimodule.php                        # Module principal + hookModuleRoutes()
├── composer.json                        # league/oauth2-server ^8.x
├── controllers/front/
│   └── api.php                          # Dispatcher unique, gère toutes les routes
├── src/
│   ├── Api/
│   │   ├── Dispatcher.php               # Résolution route → resource → handler
│   │   ├── Request.php                  # Wrapper autour de $_SERVER / php://input
│   │   └── Response.php                 # JSON response builder + HTTP status
│   ├── OAuth2/
│   │   ├── AuthorizationServer.php      # Config league/oauth2-server
│   │   ├── ResourceServer.php           # Validation Bearer token
│   │   └── Repository/
│   │       ├── ClientRepository.php
│   │       ├── ScopeRepository.php
│   │       └── AccessTokenRepository.php
│   ├── Resource/
│   │   ├── ResourceInterface.php        # Contrat commun
│   │   ├── AbstractResource.php         # Helpers localisés, décimaux, shop context
│   │   ├── Address/AddressResource.php
│   │   ├── ApiClient/ApiClientResource.php
│   │   ├── Attribute/AttributeResource.php
│   │   ├── Attribute/AttributeGroupResource.php
│   │   ├── CartRule/CartRuleResource.php
│   │   ├── Category/CategoryResource.php
│   │   ├── Contact/ContactResource.php
│   │   ├── Country/CountryResource.php
│   │   ├── Customer/CustomerResource.php
│   │   ├── Customer/CustomerGroupResource.php
│   │   ├── Discount/DiscountResource.php
│   │   ├── Feature/FeatureResource.php
│   │   ├── Feature/FeatureValueResource.php
│   │   ├── Hook/HookResource.php
│   │   ├── Manufacturer/ManufacturerResource.php
│   │   ├── Module/ModuleResource.php
│   │   ├── Product/ProductResource.php
│   │   ├── Product/CombinationResource.php
│   │   ├── Product/ProductImageResource.php
│   │   ├── Profile/ProfileResource.php
│   │   ├── SearchAlias/SearchAliasResource.php
│   │   ├── SearchEngine/SearchEngineResource.php
│   │   ├── Store/StoreResource.php
│   │   ├── Supplier/SupplierResource.php
│   │   ├── Tab/TabResource.php
│   │   ├── Tax/TaxResource.php
│   │   ├── TaxRulesGroup/TaxRulesGroupResource.php
│   │   ├── Title/TitleResource.php
│   │   ├── WebserviceKey/WebserviceKeyResource.php
│   │   └── Zone/ZoneResource.php
│   └── Registry/
│       └── ResourceRegistry.php         # Table de routing statique
├── sql/
│   ├── install.sql
│   └── uninstall.sql
└── var/
    └── keys/                            # Clés RSA (protégées par .htaccess)
        ├── private.key
        └── public.key
```

### Flux d'une requête

```
GET /admin-api/products/42
  → api.php (ModuleFrontController)
    → ResourceServer::validateBearerToken()   # 401 si invalide ou expiré
    → ShopContextResolver::fromRequest()      # 400 si multistore ON et param absent
    → Dispatcher::resolve('/products', 42)    # trouve ProductResource
    → scope check ('product_read')            # 403 si scope absent du token
    → ProductResource::get(42, $context)      # lit ObjectModel PS 1.7
    → Response::json($data, 200)
```

---

## OAuth2

### Endpoint token

```
POST /admin-api/access_token
Content-Type: multipart/form-data

grant_type    = client_credentials
client_id     = your_client_id
client_secret = your_client_secret
scope[]       = product_read
scope[]       = customer_write
```

**Réponse :**

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9..."
}
```

**Utilisation :**

```bash
curl /admin-api/products \
  --header "Authorization: Bearer eyJ..."
```

### Grant supporté

**Client Credentials uniquement** — adapté à un contexte machine-to-machine (app mobile, ERP, intégration tierce). Pas de flux Authorization Code.

### JWT (RSA)

- Tokens signés avec une clé privée RSA générée à l'installation
- Vérification sans hit DB (signature uniquement)
- Clés stockées dans `var/keys/` avec `.htaccess` `Deny from all`
- Bouton de régénération des clés disponible dans le back-office

### Tables SQL

```sql
-- Clients API
CREATE TABLE `{PREFIX}apimodule_client` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `client_id`     VARCHAR(80)  NOT NULL UNIQUE,
  `client_secret` VARCHAR(255) NOT NULL,   -- bcrypt
  `client_name`   VARCHAR(255) NOT NULL,
  `scopes`        TEXT,                    -- JSON: ["product_read","customer_write"]
  `active`        TINYINT(1)  DEFAULT 1,
  `date_add`      DATETIME    NOT NULL,
  `date_upd`      DATETIME    NOT NULL
);

-- Access tokens
CREATE TABLE `{PREFIX}apimodule_access_token` (
  `id`         VARCHAR(255) PRIMARY KEY,
  `client_id`  VARCHAR(80)  NOT NULL,
  `scopes`     TEXT,
  `revoked`    TINYINT(1)  DEFAULT 0,
  `expires_at` DATETIME    NOT NULL
);
```

### Scopes — convention PS9

`{entity_snake_case}_{read|write}`

Exemples : `product_read`, `product_write`, `customer_read`, `attribute_group_write`, `tax_rules_group_read`

- `_read` → opérations GET (item + liste)
- `_write` → POST, PATCH, DELETE
- Chaque token ne peut contenir que les scopes accordés à son client

---

## Déclaration des ressources (Approche A — Config-array)

### Interface

```php
interface ResourceInterface
{
    public static function definition(): array;
    public function get(int $id, array $context): array;
    public function list(array $filters, array $context): array;
    public function create(array $data, array $context): array;
    public function update(int $id, array $data, array $context): array;
    public function delete(int $id, array $context): bool;
}
```

### Définition d'une ressource

```php
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
                'bulkDelete' => ['scope' => 'product_write', 'method' => 'DELETE',
                                 'uriSuffix' => '/bulk-delete'],
            ],
            'exceptionToStatus' => [
                ProductNotFoundException::class => 404,
            ],
        ];
    }
}
```

### Registry

Enregistrement explicite de toutes les ressources (PHP 7.4 : pas d'auto-scan) :

```php
class ResourceRegistry
{
    private static array $resources = [
        ProductResource::class,
        CustomerResource::class,
        // ... 25+ classes
    ];

    public static function resolve(string $uri, string $method): array
    {
        // Retourne [ResourceClass, operation, params]
    }
}
```

---

## Routing

### hookModuleRoutes()

Un seul contrôleur front `api` capture toutes les routes via plusieurs patterns :

```php
public function hookModuleRoutes(): array
{
    $base = ['fc' => 'module', 'module' => $this->name, 'controller' => 'api'];
    return [
        'apimodule-token' => [
            'rule'    => 'admin-api/access_token',
            'keywords' => [],
            'params'  => $base,
        ],
        'apimodule-resource-item' => [
            'rule'    => 'admin-api/{resource}/{id}',
            'keywords' => [
                'resource' => ['regexp' => '[a-z\-\/]+', 'param' => 'resource'],
                'id'       => ['regexp' => '[0-9]+',     'param' => 'id'],
            ],
            'params'  => $base,
        ],
        'apimodule-resource-collection' => [
            'rule'    => 'admin-api/{resource}',
            'keywords' => [
                'resource' => ['regexp' => '[a-z\-\/]+', 'param' => 'resource'],
            ],
            'params'  => $base,
        ],
    ];
}
```

---

## Dispatcher et réponses HTTP

### Routes couvertes

| Méthode | URI | Opération |
|---------|-----|-----------|
| POST    | `/admin-api/access_token` | Obtenir un token |
| GET     | `/admin-api/products` | Liste paginée |
| GET     | `/admin-api/products/42` | Item unique |
| POST    | `/admin-api/products` | Création |
| PATCH   | `/admin-api/products/42` | Mise à jour partielle |
| DELETE  | `/admin-api/products/42` | Suppression |
| DELETE  | `/admin-api/products/bulk-delete` | Suppression en masse |
| GET     | `/admin-api/products/42/combinations` | Sous-ressource |

### Format de réponse — item

```json
{
  "productId": 42,
  "enabled": true,
  "names": { "en-US": "T-Shirt", "fr-FR": "T-Shirt" },
  "priceTaxExcluded": "19.990000"
}
```

### Format de réponse — liste paginée

```json
{
  "items": [ { "productId": 1, "enabled": true }, { "productId": 2, "enabled": false } ],
  "totalItems": 150,
  "orderBy": "productId",
  "sortOrder": "asc",
  "offset": 0,
  "limit": 20
}
```

### Codes HTTP

| Situation | Code |
|-----------|------|
| GET / PATCH réussi | `200` |
| POST réussi (création) | `201` |
| DELETE réussi | `204` |
| Token manquant / invalide / expiré | `401` |
| Scope insuffisant | `403` |
| Ressource introuvable | `404` |
| Validation échouée | `422` |
| Multistore requis, paramètre absent | `400` |

### Format erreur

```json
{
  "type": "https://tools.ietf.org/html/rfc2616#section-10",
  "title": "An error occurred",
  "status": 404,
  "detail": "Product with id 42 was not found."
}
```

---

## Couche données (PS 1.7 → format PS9)

### Principe

- **Items** : accès via ObjectModel PS 1.7 (`new Product($id, false, $langId)`)
- **Listes** : requêtes `Db::getInstance()->executeS()` pour les performances
- **Format de sortie** : identique au contrat JSON PS9 (même noms de champs, mêmes types)

### Champs localisés

Retournés sous forme `{ "en-US": "...", "fr-FR": "..." }` via `Language::getLanguages()` pour résoudre les locales.

### Décimaux

Toujours sérialisés en `string` avec 6 décimales (`"19.990000"`), jamais en `float` natif PHP.

### Paramètres de liste

Transmis par query string : `?limit=20&offset=0&orderBy=productId&sortOrder=asc`

---

## Contexte multi-shop

### Paramètres acceptés (query string ou JSON body)

| Paramètre | Comportement |
|-----------|-------------|
| `?shopId=2` | Contexte boutique unique |
| `?shopGroupId=1` | Contexte groupe de boutiques |
| `?shopIds=2,3` | Ensemble de boutiques |
| `?allShops=1` | Toutes les boutiques |
| *(absent)* | Boutique par défaut si multistore OFF ; **400** si multistore ON |

### Application dans PS 1.7

```php
Shop::setContext(Shop::CONTEXT_SHOP, $context['shopId']);   // single
Shop::setContext(Shop::CONTEXT_ALL);                         // all
Shop::setContext(Shop::CONTEXT_GROUP, $context['shopGroupId']); // group
```

Réinitialisé après chaque opération.

---

## Back-office

### Onglet admin

`Administration > API Manager` avec :

- **Liste des clients** : id, nom, scopes, statut, date de création
- **Formulaire création/édition** :
  - Nom du client
  - Client ID (auto-généré ou saisi)
  - Client Secret (généré aléatoirement, affiché **une seule fois**)
  - Scopes (checkboxes groupées par domaine)
- **Bouton régénération des clés RSA**

### Sécurité

- Client secret stocké en bcrypt — pas de récupération, régénération uniquement
- Clés RSA inaccessibles via HTTP (`.htaccess Deny from all`)

---

## Compatibilité et versioning

| Contrainte | Valeur |
|------------|--------|
| PHP minimum | 7.4 |
| PrestaShop minimum | **1.7.6.0** |
| PrestaShop maximum déclaré | 8.99.99 |
| Cohabitation avec `ps_apiresources` | ⚠️ Sur PS8 : les deux modules ne peuvent pas être actifs simultanément (collision de routes `/admin-api/*`). Notre module est un remplacement, pas un complément. |

```php
$this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => '8.99.99'];
```

---

## Tests

### Structure

```
tests/
├── Integration/
│   ├── ApiTestCase.php           # Bootstrap OAuth2, helpers request/assert
│   ├── OAuth2Test.php            # Token endpoint, scopes, expiry
│   ├── ProductEndpointTest.php
│   ├── CustomerEndpointTest.php
│   └── ... (un fichier par domaine)
└── Unit/
    ├── DispatcherTest.php
    └── ResourceRegistryTest.php
```

### Helpers ApiTestCase

```php
$this->getItem('/admin-api/products/42', ['product_read']);
$this->createItem('/admin-api/products', $data, ['product_write']);
$this->listItems('/admin-api/products', ['product_read'], ['limit' => 5]);
$this->deleteItem('/admin-api/products/42', ['product_write']);
```

### Couverture attendue

- `401` sans token, `403` scope incorrect
- CRUD complet par domaine
- Format de réponse (champs, types, locales `en-US` / `fr-FR`)
- Pagination (`offset`, `limit`, `totalItems`)
- Multi-shop (`?shopId=`, `400` si absent et multistore ON)
- `422` sur données invalides, `404` sur ID inexistant

---

## Ressources couvertes (25 domaines)

| Domaine | Scopes |
|---------|--------|
| Address | `address_read`, `address_write` |
| ApiClient | `api_client_read`, `api_client_write` |
| Attribute | `attribute_read`, `attribute_write` |
| AttributeGroup | `attribute_group_read`, `attribute_group_write` |
| CartRule | `cart_rule_read`, `cart_rule_write` |
| Category | `category_read`, `category_write` |
| Contact | `contact_read`, `contact_write` |
| Country | `country_read`, `country_write` |
| Customer | `customer_read`, `customer_write` |
| CustomerGroup | `customer_group_read`, `customer_group_write` |
| Discount | `discount_read`, `discount_write` |
| Feature | `feature_read`, `feature_write` |
| FeatureValue | `feature_value_read`, `feature_value_write` |
| Hook | `hook_read`, `hook_write` |
| Manufacturer | `manufacturer_read`, `manufacturer_write` |
| Module | `module_read`, `module_write` |
| Product | `product_read`, `product_write` |
| Combination | `product_read`, `product_write` |
| ProductImage | `product_read`, `product_write` |
| Profile | `profile_read`, `profile_write` |
| SearchAlias | `search_alias_read`, `search_alias_write` |
| SearchEngine | `search_engine_read`, `search_engine_write` |
| Store | `store_read`, `store_write` |
| Supplier | `supplier_read`, `supplier_write` |
| Tab | `tab_read`, `tab_write` |
| Tax | `tax_read`, `tax_write` |
| TaxRulesGroup | `tax_rules_group_read`, `tax_rules_group_write` |
| Title | `title_read`, `title_write` |
| WebserviceKey | `webservice_key_read`, `webservice_key_write` |
| Zone | `zone_read`, `zone_write` |
| ShowcaseCard | `showcase_card_read`, `showcase_card_write` |
