# adminapi — PrestaShop Admin API

[![CI](https://github.com/PrestaEdit/adminapi/actions/workflows/ci.yml/badge.svg)](https://github.com/PrestaEdit/adminapi/actions/workflows/ci.yml)

> A back-port of PrestaShop 9's Admin API (`ps_apiresources`) to PrestaShop **1.7.6+ / 8.x**.

The **adminapi** module (technical name `adminapi`, display name *Admin API Module*) exposes a modern, OAuth2-secured REST API under `/admin-api/*`. It replicates the resource model, scopes, and JSON shapes of the PS9 Admin API so that integrations written against PS9 work against PS 1.7/8 with minimal changes.

- **31 resources** covering the 29 PS9 scope domains, plus product variants and stock.
- **OAuth2 Client Credentials** grant with RSA-signed JWT access tokens.
- **Multi-shop aware** context resolution.
- **PHP 7.4+** compatible, zero hard dependency on PS9 internals.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Architecture](#architecture)
- [Authentication](#authentication)
- [Making requests](#making-requests)
- [OpenAPI specification](#openapi-specification)
- [Resources](#resources)
- [Multi-shop](#multi-shop)
- [Response & error format](#response--error-format)
- [Back-office: managing API clients](#back-office-managing-api-clients)
- [Development](#development)
- [Project layout](#project-layout)
- [Security notes](#security-notes)

---

## Requirements

| Component | Version |
|---|---|
| PrestaShop | 1.7.6.0 → 8.99.99 |
| PHP | >= 7.4 |
| OpenSSL extension | required (RSA key generation) |
| Friendly URLs | **must be enabled** (the API relies on `hookModuleRoutes`) |

Key Composer dependencies:

- `league/oauth2-server ~8.3.0` — OAuth2 authorization & resource servers
- `lcobucci/jwt ~3.4.0` — JWT (pinned for PHP 7.4)
- `defuse/php-encryption ^2.4` — OAuth2 encryption key
- `nyholm/psr7 ^1.8` + `nyholm/psr7-server ^1.1` — PSR-7 HTTP layer

---

## Installation

```bash
# 1. Place the module in your PrestaShop modules directory
cd /path/to/prestashop/modules/adminapi

# 2. Install PHP dependencies
composer install --no-dev --optimize-autoloader

# 3. Install the module (back-office or CLI)
php bin/console prestashop:module install adminapi
# …or via Back-office → Modules → Module Manager → Upload/Install
```

On install the module automatically:

1. Creates the SQL tables `PREFIX_adminapi_client` and `PREFIX_adminapi_access_token`.
2. Generates a 2048-bit RSA keypair in `var/keys/` (`private.key` / `public.key`), protected by an `.htaccess` (`Deny from all`).
3. Stores a `defuse/php-encryption` key in `Configuration` under `ADMINAPI_ENCRYPTION_KEY`.
4. Registers the `moduleRoutes` hook (exposes `/admin-api/*`).
5. Adds the **API Manager** tab to the back-office.

> ⚠️ The RSA private key and the encryption key are generated **per installation** and are never committed to git (see `.gitignore`). Back up `var/keys/` and the `ADMINAPI_ENCRYPTION_KEY` config value if you need token continuity across migrations.

Uninstalling drops the tables, removes the keys, deletes the config value, and removes the tab.

---

## Architecture

```
HTTP request  →  hookModuleRoutes (/admin-api/*)
              →  controllers/front/api.php  (AdminapiApiModuleFrontController)
                   │
                   ├─ POST /admin-api/access_token → AuthorizationServer (issue JWT)
                   │
                   ├─ GET  /admin-api/openapi.json  → OpenApiGenerator (unauthenticated)
                   │
                   └─ everything else → Api\Dispatcher
                          1. validate Bearer token  (ResourceServer)
                          2. resolve route           (ResourceRegistry)
                          3. check scope
                          4. resolve shop context    (ShopContextResolver)
                          5. dispatch to Resource     (get/list/create/update/delete/bulkDelete)
                          6. serialize                (Api\Response → PSR-7)
```

Each resource is a self-contained class under `src/Resource/{Domain}/{Domain}Resource.php` that:

- declares a static `definition()` (URI template, identifier key, operations → scope+method),
- implements `get / list / create / update / delete` (+ optional `bulkDelete`),
- extends `AbstractResource` for shared helpers (localized fields, decimals, pagination, validation).

`ResourceRegistry` builds a lazy route table from every resource's `definition()`, so adding a resource is a one-line registration.

---

## Authentication

The API uses the **OAuth2 Client Credentials** grant. You exchange a `client_id` / `client_secret` for a short-lived (1 hour) RSA-signed JWT, then send that JWT as a Bearer token.

### 1. Request a token

```bash
curl -X POST https://your-shop.com/admin-api/access_token \
  -d 'grant_type=client_credentials' \
  -d 'client_id=YOUR_CLIENT_ID' \
  -d 'client_secret=YOUR_CLIENT_SECRET' \
  -d 'scope[]=product_read' \
  -d 'scope[]=product_write'
```

Response:

```json
{
  "token_type": "Bearer",
  "expires_in": 3600,
  "access_token": "eyJ0eXAiOiJKV1QiLCJ..."
}
```

- Scopes are optional; if omitted, the client receives the scopes granted to it in the back-office. Requested scopes are intersected with the client's allowed scopes.
- Both `scope[]=a&scope[]=b` (array form) and `scope=a b` (space-separated) are accepted.

### 2. Call the API

```bash
curl https://your-shop.com/admin-api/products/1 \
  -H 'Authorization: Bearer eyJ0eXAiOiJKV1QiLCJ...'
```

| Situation | HTTP status |
|---|---|
| Valid token, sufficient scope | `200` / `201` / `204` |
| Missing or invalid token | `401` |
| Valid token, missing scope | `403` |
| Unknown route | `404` |

---

## Making requests

| Operation | Method | Path | Required scope |
|---|---|---|---|
| List | `GET` | `/admin-api/{resource}` | `{domain}_read` |
| Get one | `GET` | `/admin-api/{resource}/{id}` | `{domain}_read` |
| Create | `POST` | `/admin-api/{resource}` | `{domain}_write` |
| Update | `PATCH` | `/admin-api/{resource}/{id}` | `{domain}_write` |
| Delete | `DELETE` | `/admin-api/{resource}/{id}` | `{domain}_write` |
| Bulk delete | `DELETE` | `/admin-api/{resource}/bulk-delete` | `{domain}_write` |

Request and response bodies are JSON. Create/update accept JSON or form-encoded bodies.

### Pagination & sorting (list endpoints)

| Query param | Default | Notes |
|---|---|---|
| `limit` | `20` | clamped to `1..100` |
| `offset` | `0` | |
| `orderBy` | resource default | whitelisted columns only |
| `sortOrder` | `asc` | `asc` or `desc` |

List responses are wrapped:

```json
{
  "items": [ /* … */ ],
  "totalItems": 137,
  "offset": 0,
  "limit": 20,
  "orderBy": "id_product",
  "sortOrder": "asc"
}
```

### Localized fields

Multilingual fields are keyed by locale, not by language id:

```json
{
  "names": { "en-US": "T-shirt", "fr-FR": "T-shirt" }
}
```

### Examples

```bash
# Create a contact
curl -X POST https://shop/admin-api/contacts \
  -H 'Authorization: Bearer …' -H 'Content-Type: application/json' \
  -d '{"names":{"en-US":"Support"},"email":"support@shop.com","customerService":true}'

# List products, page 2, sorted by price desc
curl 'https://shop/admin-api/products?limit=20&offset=20&orderBy=price&sortOrder=desc' \
  -H 'Authorization: Bearer …'

# Update stock quantity
curl -X PATCH https://shop/admin-api/stock-availables/42 \
  -H 'Authorization: Bearer …' -H 'Content-Type: application/json' \
  -d '{"quantity":150}'

# Bulk delete
curl -X DELETE https://shop/admin-api/zones/bulk-delete \
  -H 'Authorization: Bearer …' -H 'Content-Type: application/json' \
  -d '{"zoneIds":[3,4,5]}'
```

---

## OpenAPI specification

A live OpenAPI 3.0 document describing every resource, operation, required scope, and the OAuth2 flow is generated from the resource registry and served — without authentication — at:

```
GET /admin-api/openapi.json
```

Import it into Swagger UI, Postman, or Insomnia to explore the API. The document is always in sync with the registered resources (it is built at request time from each resource's `definition()`).

---

## Resources

31 resources across 29 scope domains. Unless noted, every resource supports the full CRUD + bulk-delete set.

### Catalog

| Resource | Endpoint | Scope domain | Notes |
|---|---|---|---|
| Product | `/products` | `product` | 38 fields, 9 localized; stock via `ps_stock_available` |
| ProductCombination | `/product-combinations` | `product` | variants; `?productId=` filter; links attributes |
| StockAvailable | `/stock-availables` | `product` | **read + update only** (PS manages create/delete) |
| Category | `/categories` | `category` | tree (`idParent`), `linkRewrites`; root protected |
| Manufacturer | `/manufacturers` | `manufacturer` | localized descriptions |
| Supplier | `/suppliers` | `supplier` | localized descriptions |
| AttributeGroup | `/attribute-groups` | `attribute_group` | `names` + `publicNames` |
| Attribute | `/attributes` | `attribute` | color / position |
| Feature | `/features` | `feature` | |
| FeatureValue | `/feature-values` | `feature_value` | `?` linked to feature |

### Customers & pricing

| Resource | Endpoint | Scope domain | Notes |
|---|---|---|---|
| Customer | `/customers` | `customer` | `passwd` **never** exposed; soft-delete |
| CustomerGroup | `/customer-groups` | `customer_group` | reduction, price display |
| CartRule | `/cart-rules` | `cart_rule` | all cart rules |
| Discount | `/discounts` | `discount` | cart rules with a voucher code |
| Tax | `/taxes` | `tax` | decimal rate; soft-delete |
| TaxRulesGroup | `/tax-rules-groups` | `tax_rules_group` | |
| Address | `/addresses` | `address` | soft-delete |

### Localization & geography

| Resource | Endpoint | Scope domain |
|---|---|---|
| Country | `/countries` | `country` |
| Zone | `/zones` | `zone` |
| Store | `/stores` | `store` |
| Title | `/titles` | `title` |

### Configuration & system

| Resource | Endpoint | Scope domain | Notes |
|---|---|---|---|
| Contact | `/contacts` | `contact` | reference resource |
| Profile | `/profiles` | `profile` | |
| Tab | `/tabs` | `tab` | back-office menus |
| Hook | `/hooks` | `hook` | |
| Module | `/modules` | `module` | **read + update only** (no install/uninstall) |
| ApiClient | `/api-clients` | `api_client` | self-management; secret shown once at create |
| WebserviceKey | `/webservice-keys` | `webservice_key` | auto-generates key |
| SearchEngine | `/search-engines` | `search_engine` | |
| SearchAlias | `/search-aliases` | `search_alias` | |

> `ShowcaseCard` (PS9-only) is intentionally not implemented — it has no ObjectModel in PS 1.7/8.

---

## Multi-shop

When multi-shop is **enabled**, every request must specify a shop context via query parameter, otherwise the API returns `400`:

| Parameter | Meaning |
|---|---|
| `?shopId=2` | single shop |
| `?shopGroupId=1` | shop group |
| `?shopIds=2,3` | explicit list (first is used as primary) |
| `?allShops` | all-shops context |

`?langId=` overrides the language used for localized fields (defaults to `PS_LANG_DEFAULT`).

When multi-shop is **disabled**, the default shop is used automatically and no parameter is needed.

---

## Response & error format

Errors follow the PS9 / RFC-7807-style shape:

```json
{
  "type": "https://tools.ietf.org/html/rfc2616#section-10",
  "title": "An error occurred",
  "status": 404,
  "detail": "Product with id 999 was not found."
}
```

Validation errors (`422`) add a `violations` map:

```json
{
  "type": "https://tools.ietf.org/html/rfc2616#section-10",
  "title": "An error occurred",
  "status": 422,
  "detail": "Validation failed",
  "violations": { "names": ["This field is required."] }
}
```

Internal errors are logged server-side (`error_log('[adminapi] …')`) and returned to the client as a generic `500` — implementation details are never leaked.

---

## Back-office: managing API clients

**Back-office → (Stats parent) → API Manager**

- List, create, edit, enable/disable and delete OAuth2 clients.
- On creation a random `client_id` and `client_secret` are generated; the secret is displayed **once** and stored as a bcrypt hash.
- Scopes are assigned via checkboxes grouped by domain (29 domains × read/write).

Clients can also be managed programmatically through the `/api-clients` resource (requires `api_client_write`).

---

## Development

### Run unit tests

```bash
composer install
vendor/bin/phpunit --testdox
```

Unit tests run **without a PrestaShop installation** — PS classes (`Db`, `Validate`, `Configuration`, `Language`, `DbQuery`, …) are stubbed in `tests/Unit/bootstrap.php`.

### Run integration tests (against a live shop)

```bash
PS_ROOT=/var/www/prestashop API_BASE_URL=http://localhost \
  vendor/bin/phpunit -c phpunit.integration.xml --testdox
```

There are two PHPUnit configs:

- `phpunit.xml` — unit suite (stubs, no PS needed)
- `phpunit.integration.xml` — E2E suite (boots PrestaShop from `PS_ROOT`, hits the live HTTP API)

### Adding a new resource

1. Create `src/Resource/{Domain}/{Domain}Resource.php` extending `AbstractResource` and implementing `ResourceInterface`.
2. Define `definition()` with the URI template, identifier key, and operations.
3. Register the class in `ResourceRegistry::$resources`.
4. Add the scope domain to the back-office scope list (`AdminAdminapiClientController::getAllScopes()`).

Conventions enforced across all resources:

- `declare(strict_types=1)`, PHP 7.4-compatible (no `match`, union types, `str_ends_with`, …).
- `countQuery()` is called **before** `applySort()` / `applyPagination()`.
- Decimals are serialized as strings via `decimal()` (never floats).
- Localized fields go through `getLocalizedField()` / `buildPsLocalizedField()`.

---

## Project layout

```
adminapi/
├── adminapi.php                     # Module entry (install/uninstall, routes, keys)
├── composer.json
├── controllers/
│   ├── front/api.php                 # Single front controller for /admin-api/*
│   └── admin/AdminAdminapiClientController.php
├── sql/{install,uninstall}.sql
├── src/
│   ├── OAuth2/                        # Servers, entities, repositories
│   ├── Api/                           # Dispatcher, Request, Response, ShopContextResolver, OpenApiGenerator
│   ├── Exception/                     # ResourceNotFound, Validation
│   ├── Model/AdminapiClient.php      # ObjectModel for the client table
│   └── Resource/                      # 31 resources + AbstractResource + ResourceRegistry
├── tests/
│   ├── Unit/                          # stubbed, no PS
│   └── Integration/                   # E2E against a live shop
├── var/keys/                          # RSA keypair (git-ignored)
├── phpunit.xml
└── phpunit.integration.xml
```

---

## Security notes

- **RSA + encryption keys** are generated per install, stored outside the web root reach (`.htaccess` deny), and git-ignored.
- **Client secrets** are stored as bcrypt hashes; the plaintext is shown only once at creation.
- **Customer passwords** (`passwd`) are never returned by the API and are hashed with `Tools::encrypt()` on write.
- **Module install/uninstall** and **stock record create/delete** are deliberately not exposed (read/update only) to avoid destructive operations over the API.
- **Scope enforcement** happens per operation before any resource code runs.
- **SQL safety**: all identifiers are integer-cast before interpolation; sort columns are whitelisted.

---

## License

AFL-3.0 — © PrestaEdit
