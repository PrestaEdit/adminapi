# PrestaShop Admin API Module — Plan A : Infrastructure

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers-extended-cc:subagent-driven-development (recommended) or superpowers-extended-cc:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Poser le socle complet du module — OAuth2, couche HTTP, système de ressources, back-office — avec le resource Contact comme preuve E2E d'un endpoint fonctionnel.

**Architecture:** Module PrestaShop 1.7/8 avec `hookModuleRoutes()` qui expose `/admin-api/*`. L'authentification utilise `league/oauth2-server` avec des JWT RSA. Chaque ressource est une classe PHP 7.4 avec un `definition()` statique et des méthodes CRUD explicites.

**Tech Stack:** PHP 7.4+, PrestaShop 1.7.6+, `league/oauth2-server ^8.5`, `nyholm/psr7 ^1.8`, `nyholm/psr7-server ^1.1`, PHPUnit ^9

---

## Structure des fichiers

```
apimodule/
├── apimodule.php
├── composer.json
├── controllers/
│   ├── front/
│   │   └── api.php                          ← FrontController unique
│   └── admin/
│       └── AdminApimoduleClientController.php
├── src/
│   ├── Api/
│   │   ├── Dispatcher.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   └── ShopContextResolver.php
│   ├── Exception/
│   │   ├── ResourceNotFoundException.php
│   │   └── ValidationException.php
│   ├── OAuth2/
│   │   ├── AuthorizationServer.php
│   │   ├── ResourceServer.php
│   │   ├── Entity/
│   │   │   ├── ClientEntity.php
│   │   │   ├── ScopeEntity.php
│   │   │   └── AccessTokenEntity.php
│   │   └── Repository/
│   │       ├── ClientRepository.php
│   │       ├── ScopeRepository.php
│   │       └── AccessTokenRepository.php
│   └── Resource/
│       ├── ResourceInterface.php
│       ├── AbstractResource.php
│       ├── ResourceRegistry.php
│       └── Contact/
│           └── ContactResource.php          ← Ressource E2E canonique
├── views/
│   └── templates/admin/
│       └── apimodule_client/
│           ├── list.tpl
│           └── form.tpl
├── sql/
│   ├── install.sql
│   └── uninstall.sql
├── tests/
│   ├── Integration/
│   │   ├── ApiTestCase.php
│   │   └── ContactEndpointTest.php
│   └── Unit/
│       ├── DispatcherTest.php
│       └── bootstrap.php
└── var/
    └── keys/
        └── .gitkeep
```

---

## Task 1 : Module scaffold + SQL + routing

**Goal:** Créer le module PrestaShop avec installation/désinstallation, génération des clés RSA, et routage via `hookModuleRoutes()`.

**Files:**
- Create: `apimodule.php`
- Create: `composer.json`
- Create: `sql/install.sql`
- Create: `sql/uninstall.sql`
- Create: `var/keys/.gitkeep`

**Acceptance Criteria:**
- [ ] `composer install` complète sans erreur
- [ ] Le module s'installe sans erreur dans PS 1.7.6+
- [ ] Les tables `apimodule_client` et `apimodule_access_token` sont créées
- [ ] `var/keys/private.key` et `var/keys/public.key` existent après install, protégés par `.htaccess`
- [ ] `Configuration::get('APIMODULE_ENCRYPTION_KEY')` retourne une valeur non vide

**Verify:** `composer validate && php -l apimodule.php` → no errors

**Steps:**

- [ ] **Step 1 : Créer `composer.json`**

```json
{
    "name": "prestaedit/apimodule",
    "description": "PrestaShop Admin API — port of ps_apiresources for PS 1.7+",
    "type": "prestashop-module",
    "license": "AFL-3.0",
    "require": {
        "php": ">=7.4",
        "league/oauth2-server": "^8.5",
        "nyholm/psr7": "^1.8",
        "nyholm/psr7-server": "^1.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.5"
    },
    "autoload": {
        "psr-4": {
            "PrestaEdit\\ApiModule\\": "src/"
        },
        "classmap": [
            "apimodule.php",
            "controllers/"
        ]
    },
    "autoload-dev": {
        "psr-4": {
            "PrestaEdit\\ApiModule\\Tests\\": "tests/"
        }
    },
    "config": {
        "optimize-autoloader": true,
        "prepend-autoloader": false
    }
}
```

- [ ] **Step 2 : Créer `sql/install.sql`**

```sql
CREATE TABLE IF NOT EXISTS `PREFIX_apimodule_client` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`     VARCHAR(80)  NOT NULL,
  `client_secret` VARCHAR(255) NOT NULL,
  `client_name`   VARCHAR(255) NOT NULL,
  `scopes`        TEXT,
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `date_add`      DATETIME     NOT NULL,
  `date_upd`      DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `PREFIX_apimodule_access_token` (
  `id`         VARCHAR(255) NOT NULL,
  `client_id`  VARCHAR(80)  NOT NULL,
  `scopes`     TEXT,
  `revoked`    TINYINT(1)   NOT NULL DEFAULT 0,
  `expires_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

- [ ] **Step 3 : Créer `sql/uninstall.sql`**

```sql
DROP TABLE IF EXISTS `PREFIX_apimodule_access_token`;
DROP TABLE IF EXISTS `PREFIX_apimodule_client`
```

- [ ] **Step 4 : Créer `apimodule.php`**

```php
<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Apimodule extends Module
{
    public function __construct()
    {
        $this->name = 'apimodule';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'PrestaEdit';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => '8.99.99'];
        $this->displayName = $this->l('Admin API Module');
        $this->description = $this->l('PrestaShop Admin API — port of ps_apiresources for PS 1.7+');
        parent::__construct();
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installSql()
            && $this->generateRsaKeys()
            && $this->registerHook('moduleRoutes')
            && $this->installTab();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallSql()
            && $this->removeRsaKeys();
    }

    public function hookModuleRoutes(): array
    {
        $base = ['fc' => 'module', 'module' => $this->name, 'controller' => 'api'];
        return [
            'apimodule-token' => [
                'rule'     => 'admin-api/access_token',
                'keywords' => [],
                'params'   => $base,
            ],
            'apimodule-sub-item' => [
                'rule'     => 'admin-api/{resource}/{id}/{subresource}/{subid}',
                'keywords' => [
                    'resource'    => ['regexp' => '[a-z][a-z0-9\-]+',  'param' => 'resource'],
                    'id'          => ['regexp' => '[0-9]+',             'param' => 'id'],
                    'subresource' => ['regexp' => '[a-z][a-z0-9\-]+',  'param' => 'subresource'],
                    'subid'       => ['regexp' => '[0-9]+',             'param' => 'subid'],
                ],
                'params'   => $base,
            ],
            'apimodule-sub-collection' => [
                'rule'     => 'admin-api/{resource}/{id}/{subresource}',
                'keywords' => [
                    'resource'    => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                    'id'          => ['regexp' => '[0-9]+',            'param' => 'id'],
                    'subresource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'subresource'],
                ],
                'params'   => $base,
            ],
            'apimodule-bulk' => [
                'rule'     => 'admin-api/{resource}/bulk-{action}',
                'keywords' => [
                    'resource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                    'action'   => ['regexp' => '[a-z\-]+',          'param' => 'action'],
                ],
                'params'   => $base,
            ],
            'apimodule-item' => [
                'rule'     => 'admin-api/{resource}/{id}',
                'keywords' => [
                    'resource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                    'id'       => ['regexp' => '[0-9]+',            'param' => 'id'],
                ],
                'params'   => $base,
            ],
            'apimodule-collection' => [
                'rule'     => 'admin-api/{resource}',
                'keywords' => [
                    'resource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                ],
                'params'   => $base,
            ],
        ];
    }

    // ── SQL ──────────────────────────────────────────────────────────────

    private function installSql(): bool
    {
        return $this->executeSqlFile(__DIR__ . '/sql/install.sql');
    }

    private function uninstallSql(): bool
    {
        return $this->executeSqlFile(__DIR__ . '/sql/uninstall.sql');
    }

    private function executeSqlFile(string $path): bool
    {
        $sql = str_replace('PREFIX_', _DB_PREFIX_, (string) file_get_contents($path));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $query) {
            if (!\Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    // ── RSA keys ─────────────────────────────────────────────────────────

    public static function getKeysDir(): string
    {
        return __DIR__ . '/var/keys/';
    }

    public static function getPrivateKeyPath(): string
    {
        return self::getKeysDir() . 'private.key';
    }

    public static function getPublicKeyPath(): string
    {
        return self::getKeysDir() . 'public.key';
    }

    private function generateRsaKeys(): bool
    {
        $dir = self::getKeysDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        file_put_contents($dir . '.htaccess', implode("\n", [
            'Deny from all',
            '<IfModule mod_authz_core.c>',
            '    Require all denied',
            '</IfModule>',
            '',
        ]));

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$key) {
            return false;
        }

        openssl_pkey_export($key, $privateKeyPem);
        $details = openssl_pkey_get_details($key);

        file_put_contents(self::getPrivateKeyPath(), $privateKeyPem);
        file_put_contents(self::getPublicKeyPath(), $details['key']);
        chmod(self::getPrivateKeyPath(), 0600);

        $encryptionKey = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
        \Configuration::updateValue('APIMODULE_ENCRYPTION_KEY', $encryptionKey);

        return true;
    }

    private function removeRsaKeys(): bool
    {
        foreach (['.htaccess', 'private.key', 'public.key'] as $file) {
            $path = self::getKeysDir() . $file;
            if (file_exists($path)) {
                unlink($path);
            }
        }
        \Configuration::deleteByName('APIMODULE_ENCRYPTION_KEY');
        return true;
    }

    // ── Back-office tab ──────────────────────────────────────────────────

    private function installTab(): bool
    {
        $tab = new \Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminApimoduleClient';
        $tab->module = $this->name;
        $tab->id_parent = (int) \Tab::getIdFromClassName('AdminParentStats');
        $tab->name = [];
        foreach (\Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'API Manager';
        }
        return (bool) $tab->add();
    }
}
```

- [ ] **Step 5 : Créer `var/keys/.gitkeep`** (fichier vide pour tracker le dossier dans git)

- [ ] **Step 6 : Installer les dépendances**

```bash
cd /path/to/ps/modules/apimodule && composer install
```

Expected: `Installing dependencies from lock file (or resolving...)` — no errors.

- [ ] **Step 7 : Commit**

```bash
git add apimodule.php composer.json sql/ var/keys/.gitkeep
git commit -m "feat: module scaffold with OAuth2 dependencies and hookModuleRoutes"
```

---

## Task 2 : OAuth2 — Entités et repositories

**Goal:** Implémenter les entités PSR (Client, Scope, AccessToken) et leurs repositories qui font le lien avec la DB PrestaShop.

**Files:**
- Create: `src/OAuth2/Entity/ClientEntity.php`
- Create: `src/OAuth2/Entity/ScopeEntity.php`
- Create: `src/OAuth2/Entity/AccessTokenEntity.php`
- Create: `src/OAuth2/Repository/ClientRepository.php`
- Create: `src/OAuth2/Repository/ScopeRepository.php`
- Create: `src/OAuth2/Repository/AccessTokenRepository.php`

**Acceptance Criteria:**
- [ ] `ClientRepository::validateClient()` retourne `false` pour un secret incorrect
- [ ] `ClientRepository::validateClient()` retourne `false` pour un client inactif
- [ ] `ScopeRepository::getScopeEntityByIdentifier()` retourne `null` pour un scope inexistant
- [ ] `AccessTokenRepository::isAccessTokenRevoked()` retourne `true` après `revokeAccessToken()`

**Verify:** `vendor/bin/phpunit tests/Unit/ --testdox` → all passing

**Steps:**

- [ ] **Step 1 : Écrire les tests unitaires d'abord**

Créer `tests/Unit/bootstrap.php` :

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
```

Créer `tests/Unit/OAuth2/RepositoriesTest.php` :

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Unit\OAuth2;

use PHPUnit\Framework\TestCase;
use PrestaEdit\ApiModule\OAuth2\Repository\ClientRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\ScopeRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\AccessTokenRepository;

class RepositoriesTest extends TestCase
{
    public function testClientRepositoryValidateClientReturnsFalseForUnknownClient(): void
    {
        $repo = new ClientRepository();
        $this->assertFalse($repo->validateClient('unknown', 'secret', 'client_credentials'));
    }

    public function testScopeRepositoryReturnsNullForUnknownScope(): void
    {
        $repo = new ScopeRepository();
        $this->assertNull($repo->getScopeEntityByIdentifier('nonexistent_scope'));
    }

    public function testAccessTokenRepositoryRevoke(): void
    {
        $repo = new AccessTokenRepository();
        // Token non existant = non révoqué (on ne lance pas d'exception)
        $this->assertFalse($repo->isAccessTokenRevoked('unknown-token-id'));
    }
}
```

Run: `vendor/bin/phpunit tests/Unit/ --testdox`
Expected: FAIL — classes not found yet.

- [ ] **Step 2 : Créer `src/OAuth2/Entity/ClientEntity.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Entity;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ClientEntity implements ClientEntityInterface
{
    use EntityTrait;
    use ClientTrait;

    public function __construct(string $identifier, string $name)
    {
        $this->setIdentifier($identifier);
        $this->name = $name;
        $this->redirectUri = [];
        $this->isConfidential = true;
    }
}
```

- [ ] **Step 3 : Créer `src/OAuth2/Entity/ScopeEntity.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Entity;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;

    public function __construct(string $identifier)
    {
        $this->setIdentifier($identifier);
    }

    public function jsonSerialize(): string
    {
        return $this->getIdentifier();
    }
}
```

- [ ] **Step 4 : Créer `src/OAuth2/Entity/AccessTokenEntity.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Entity;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

class AccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;
}
```

- [ ] **Step 5 : Créer `src/OAuth2/Repository/ClientRepository.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use PrestaEdit\ApiModule\OAuth2\Entity\ClientEntity;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `client_name` FROM `' . _DB_PREFIX_ . 'apimodule_client`
             WHERE `client_id` = \'' . pSQL($clientIdentifier) . '\' AND `active` = 1'
        );

        if (!$row) {
            return null;
        }

        return new ClientEntity($clientIdentifier, $row['client_name']);
    }

    public function validateClient(
        string $clientIdentifier,
        ?string $clientSecret,
        ?string $grantType
    ): bool {
        if ($grantType !== 'client_credentials') {
            return false;
        }

        $row = \Db::getInstance()->getRow(
            'SELECT `client_secret` FROM `' . _DB_PREFIX_ . 'apimodule_client`
             WHERE `client_id` = \'' . pSQL($clientIdentifier) . '\' AND `active` = 1'
        );

        if (!$row) {
            return false;
        }

        return password_verify((string) $clientSecret, $row['client_secret']);
    }
}
```

- [ ] **Step 6 : Créer `src/OAuth2/Repository/ScopeRepository.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Repository;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use PrestaEdit\ApiModule\OAuth2\Entity\ScopeEntity;
use PrestaEdit\ApiModule\Resource\ResourceRegistry;

class ScopeRepository implements ScopeRepositoryInterface
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        if (!ResourceRegistry::scopeExists($identifier)) {
            return null;
        }
        return new ScopeEntity($identifier);
    }

    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null
    ): array {
        $clientRow = \Db::getInstance()->getRow(
            'SELECT `scopes` FROM `' . _DB_PREFIX_ . 'apimodule_client`
             WHERE `client_id` = \'' . pSQL($clientEntity->getIdentifier()) . '\''
        );

        if (!$clientRow || !$clientRow['scopes']) {
            return [];
        }

        $allowedScopes = json_decode($clientRow['scopes'], true) ?? [];

        return array_filter($scopes, static function (ScopeEntityInterface $scope) use ($allowedScopes): bool {
            return in_array($scope->getIdentifier(), $allowedScopes, true);
        });
    }
}
```

- [ ] **Step 7 : Créer `src/OAuth2/Repository/AccessTokenRepository.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2\Repository;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use PrestaEdit\ApiModule\OAuth2\Entity\AccessTokenEntity;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        ?string $userIdentifier = null
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $scopes = array_map(
            static fn (ScopeEntityInterface $s): string => $s->getIdentifier(),
            $accessTokenEntity->getScopes()
        );

        \Db::getInstance()->insert('apimodule_access_token', [
            'id'         => pSQL($accessTokenEntity->getIdentifier()),
            'client_id'  => pSQL($accessTokenEntity->getClient()->getIdentifier()),
            'scopes'     => pSQL(json_encode($scopes)),
            'revoked'    => 0,
            'expires_at' => pSQL($accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s')),
        ]);
    }

    public function revokeAccessToken(string $tokenId): void
    {
        \Db::getInstance()->update(
            'apimodule_access_token',
            ['revoked' => 1],
            '`id` = \'' . pSQL($tokenId) . '\''
        );
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `revoked` FROM `' . _DB_PREFIX_ . 'apimodule_access_token`
             WHERE `id` = \'' . pSQL($tokenId) . '\''
        );

        if (!$row) {
            return false;
        }

        return (bool) $row['revoked'];
    }
}
```

- [ ] **Step 8 : Relancer les tests**

Run: `vendor/bin/phpunit tests/Unit/ --testdox`
Expected: 3 tests PASS (les tests ne touchent pas la DB — ils vérifient le comportement pour des données inexistantes).

- [ ] **Step 9 : Commit**

```bash
git add src/OAuth2/ tests/Unit/
git commit -m "feat: OAuth2 PSR entities and DB-backed repositories"
```

---

## Task 3 : OAuth2 — Serveurs et endpoint token

**Goal:** Câbler `AuthorizationServer` et `ResourceServer` de `league/oauth2-server`, et implémenter `POST /admin-api/access_token`.

**Files:**
- Create: `src/OAuth2/AuthorizationServer.php`
- Create: `src/OAuth2/ResourceServer.php`
- Create: `controllers/front/api.php` (première version — token endpoint uniquement)

**Acceptance Criteria:**
- [ ] `POST /admin-api/access_token` avec credentials valides → 200 + `{"token_type":"Bearer","expires_in":3600,"access_token":"eyJ..."}`
- [ ] `POST /admin-api/access_token` avec mauvais secret → 401
- [ ] `POST /admin-api/access_token` avec scope non accordé → token sans ce scope (scope ignoré)
- [ ] `GET /admin-api/anything` sans `Authorization` → 401

**Verify:** `curl -X POST http://localhost/admin-api/access_token -F "grant_type=client_credentials" -F "client_id=test" -F "client_secret=test"` → 401 (client inexistant)

**Steps:**

- [ ] **Step 1 : Créer `src/OAuth2/AuthorizationServer.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2;

use DateInterval;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer as LeagueServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use PrestaEdit\ApiModule\OAuth2\Repository\AccessTokenRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\ClientRepository;
use PrestaEdit\ApiModule\OAuth2\Repository\ScopeRepository;

class AuthorizationServer
{
    private static ?LeagueServer $instance = null;

    public static function getInstance(): LeagueServer
    {
        if (self::$instance === null) {
            $server = new LeagueServer(
                new ClientRepository(),
                new AccessTokenRepository(),
                new ScopeRepository(),
                new CryptKey('file://' . \Apimodule::getPrivateKeyPath(), null, false),
                Key::loadFromAsciiSafeString(\Configuration::get('APIMODULE_ENCRYPTION_KEY'))
            );
            $server->enableGrantType(
                new ClientCredentialsGrant(),
                new DateInterval('PT1H')
            );
            self::$instance = $server;
        }
        return self::$instance;
    }
}
```

- [ ] **Step 2 : Créer `src/OAuth2/ResourceServer.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\OAuth2;

use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResourceServer as LeagueResourceServer;
use PrestaEdit\ApiModule\OAuth2\Repository\AccessTokenRepository;

class ResourceServer
{
    private static ?LeagueResourceServer $instance = null;

    public static function getInstance(): LeagueResourceServer
    {
        if (self::$instance === null) {
            self::$instance = new LeagueResourceServer(
                new AccessTokenRepository(),
                new CryptKey('file://' . \Apimodule::getPublicKeyPath(), null, false)
            );
        }
        return self::$instance;
    }
}
```

- [ ] **Step 3 : Créer `controllers/front/api.php`**

Ce fichier gère **toutes** les routes `/admin-api/*`. Pour l'instant il ne traite que le token endpoint ; le Dispatcher (Task 4) prendra le relais pour les ressources.

```php
<?php
declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use League\OAuth2\Server\Exception\OAuthServerException;
use PrestaEdit\ApiModule\OAuth2\AuthorizationServer;
use PrestaEdit\ApiModule\Api\Dispatcher;
use PrestaEdit\ApiModule\Api\Response;

class ApimoduleApiModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    public function initContent(): void
    {
        $factory  = new Psr17Factory();
        $creator  = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $psrRequest  = $creator->fromGlobals();
        $psrResponse = $factory->createResponse();

        // ── Normalise scope[] → scope (espace-séparé) ──────────────────
        $body = $psrRequest->getParsedBody();
        if (is_array($body) && isset($body['scope']) && is_array($body['scope'])) {
            $body['scope'] = implode(' ', $body['scope']);
            $psrRequest = $psrRequest->withParsedBody($body);
        }

        // ── Token endpoint ──────────────────────────────────────────────
        $uri = $psrRequest->getUri()->getPath();
        if (str_ends_with($uri, '/admin-api/access_token')) {
            try {
                $response = AuthorizationServer::getInstance()
                    ->respondToAccessTokenRequest($psrRequest, $psrResponse);
            } catch (OAuthServerException $e) {
                $response = $e->generateHttpResponse($psrResponse);
            }
            $this->sendPsrResponse($response);
            return;
        }

        // ── Ressources API ──────────────────────────────────────────────
        $dispatcher = new Dispatcher();
        $response   = $dispatcher->dispatch($psrRequest);
        $this->sendPsrResponse($response->toPsr($psrResponse));
    }

    private function sendPsrResponse(\Psr\Http\Message\ResponseInterface $response): void
    {
        header_remove();
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                header("{$name}: {$value}", false);
            }
        }
        echo $response->getBody();
        exit;
    }
}
```

Note : `str_ends_with()` nécessite PHP 8.0. Sur PHP 7.4, utiliser `substr($uri, -strlen('/admin-api/access_token')) === '/admin-api/access_token'`.

Corriger pour PHP 7.4 :

```php
if (substr($uri, -strlen('/admin-api/access_token')) === '/admin-api/access_token') {
```

- [ ] **Step 4 : Commit**

```bash
git add src/OAuth2/AuthorizationServer.php src/OAuth2/ResourceServer.php controllers/
git commit -m "feat: OAuth2 authorization/resource servers and token endpoint"
```

---

## Task 4 : HTTP Core — Request, Response, ShopContextResolver, Dispatcher

**Goal:** Implémenter la couche HTTP qui parse les requêtes, résout le contexte multi-shop, dispatche vers les ressources et construit les réponses JSON.

**Files:**
- Create: `src/Exception/ResourceNotFoundException.php`
- Create: `src/Exception/ValidationException.php`
- Create: `src/Api/Request.php`
- Create: `src/Api/Response.php`
- Create: `src/Api/ShopContextResolver.php`
- Create: `src/Api/Dispatcher.php`

**Acceptance Criteria:**
- [ ] `Dispatcher::dispatch()` retourne 401 sans token `Authorization`
- [ ] `Dispatcher::dispatch()` retourne 403 si scope insuffisant
- [ ] `Dispatcher::dispatch()` retourne 400 si multistore ON et `shopId`/`shopGroupId`/`allShops` absent
- [ ] `ShopContextResolver::fromRequest()` parse correctement `?shopId=2`, `?shopGroupId=1`, `?allShops`
- [ ] `Response::error(404, 'Not found.')` produit le format d'erreur PS9

**Verify:** `vendor/bin/phpunit tests/Unit/DispatcherTest.php --testdox` → all passing

**Steps:**

- [ ] **Step 1 : Créer `src/Exception/ResourceNotFoundException.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Exception;

class ResourceNotFoundException extends \RuntimeException
{
    public function __construct(string $type, int $id)
    {
        parent::__construct(
            sprintf('%s with id %d was not found.', $type, $id),
            404
        );
    }
}
```

- [ ] **Step 2 : Créer `src/Exception/ValidationException.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Exception;

class ValidationException extends \RuntimeException
{
    /** @var array<string, string[]> */
    private array $errors;

    /** @param array<string, string[]> $errors */
    public function __construct(array $errors)
    {
        parent::__construct('Validation failed', 422);
        $this->errors = $errors;
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

- [ ] **Step 3 : Créer `src/Api/Request.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

use Psr\Http\Message\ServerRequestInterface;

class Request
{
    private ServerRequestInterface $psr;

    public function __construct(ServerRequestInterface $psr)
    {
        $this->psr = $psr;
    }

    public function getMethod(): string
    {
        return strtoupper($this->psr->getMethod());
    }

    /** URI path only, e.g. /admin-api/products/42 */
    public function getPath(): string
    {
        return $this->psr->getUri()->getPath();
    }

    public function getBearerToken(): ?string
    {
        $header = $this->psr->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }
        return null;
    }

    /** @return array<string, string|string[]> */
    public function getQueryParams(): array
    {
        return $this->psr->getQueryParams();
    }

    public function getQueryParam(string $key, ?string $default = null): ?string
    {
        return isset($this->psr->getQueryParams()[$key])
            ? (string) $this->psr->getQueryParams()[$key]
            : $default;
    }

    /** Parsed JSON body or form-data as array */
    public function getBody(): array
    {
        $body = $this->psr->getParsedBody();
        if (is_array($body) && !empty($body)) {
            return $body;
        }
        $raw = (string) $this->psr->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    public function getPsr(): ServerRequestInterface
    {
        return $this->psr;
    }
}
```

- [ ] **Step 4 : Créer `src/Api/Response.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;

class Response
{
    private int $statusCode;
    /** @var array<string, mixed>|null */
    private ?array $data;
    /** @var array<string, string> */
    private array $headers = ['Content-Type' => 'application/json; charset=utf-8'];

    private function __construct(int $statusCode, ?array $data)
    {
        $this->statusCode = $statusCode;
        $this->data = $data;
    }

    public static function ok(array $data): self
    {
        return new self(200, $data);
    }

    public static function created(array $data): self
    {
        return new self(201, $data);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }

    public static function error(int $status, string $detail): self
    {
        return new self($status, [
            'type'   => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title'  => 'An error occurred',
            'status' => $status,
            'detail' => $detail,
        ]);
    }

    public static function validationError(array $errors): self
    {
        return new self(422, [
            'type'       => 'https://tools.ietf.org/html/rfc2616#section-10',
            'title'      => 'An error occurred',
            'status'     => 422,
            'detail'     => 'Validation failed',
            'violations' => $errors,
        ]);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function toPsr(ResponseInterface $base): ResponseInterface
    {
        $response = $base->withStatus($this->statusCode);
        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        if ($this->data !== null) {
            $response->getBody()->write(
                json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
        return $response;
    }
}
```

- [ ] **Step 5 : Créer `src/Api/ShopContextResolver.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

class ShopContextResolver
{
    /**
     * Résout le contexte shop depuis les query params.
     * Lève une \InvalidArgumentException(400) si multistore ON et aucun paramètre fourni.
     *
     * @return array{shopId:int|null,shopGroupId:int|null,shopIds:int[]|null,allShops:bool,langId:int}
     */
    public static function fromRequest(Request $request): array
    {
        $q = $request->getQueryParams();

        $shopId      = isset($q['shopId'])      ? (int) $q['shopId']      : null;
        $shopGroupId = isset($q['shopGroupId'])  ? (int) $q['shopGroupId'] : null;
        $allShops    = array_key_exists('allShops', $q);
        $shopIds     = null;

        if (isset($q['shopIds'])) {
            $shopIds = array_map('intval', is_array($q['shopIds'])
                ? $q['shopIds']
                : explode(',', (string) $q['shopIds'])
            );
        }

        $multistoreEnabled = \Shop::isFeatureActive();

        if ($multistoreEnabled && !$shopId && !$shopGroupId && !$allShops && !$shopIds) {
            throw new \InvalidArgumentException(
                'A shop context parameter is required when multistore is enabled '
                . '(shopId, shopGroupId, shopIds or allShops).',
                400
            );
        }

        // Applique le contexte PS
        if ($shopId) {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $shopId);
        } elseif ($shopGroupId) {
            \Shop::setContext(\Shop::CONTEXT_GROUP, $shopGroupId);
        } elseif ($allShops) {
            \Shop::setContext(\Shop::CONTEXT_ALL);
        } elseif ($shopIds) {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $shopIds[0]); // premier shop de la liste
        } else {
            // Single shop : boutique par défaut
            \Shop::setContext(\Shop::CONTEXT_SHOP, (int) \Configuration::get('PS_SHOP_DEFAULT'));
            $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        }

        $langId = isset($q['langId'])
            ? (int) $q['langId']
            : (int) \Configuration::get('PS_LANG_DEFAULT');

        return compact('shopId', 'shopGroupId', 'shopIds', 'allShops', 'langId');
    }
}
```

- [ ] **Step 6 : Créer `src/Api/Dispatcher.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

use League\OAuth2\Server\Exception\OAuthServerException;
use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Exception\ValidationException;
use PrestaEdit\ApiModule\OAuth2\ResourceServer;
use PrestaEdit\ApiModule\Resource\ResourceRegistry;
use Psr\Http\Message\ServerRequestInterface;

class Dispatcher
{
    public function dispatch(ServerRequestInterface $psrRequest): Response
    {
        $request = new Request($psrRequest);

        // ── 1. Valider le Bearer token ──────────────────────────────────
        try {
            $authenticatedRequest = ResourceServer::getInstance()
                ->validateAuthenticatedRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return Response::error(401, $e->getMessage());
        }

        $tokenScopes = (array) $authenticatedRequest->getAttribute('oauth_scopes', []);

        // ── 2. Résoudre la route ────────────────────────────────────────
        $path   = $this->extractApiPath($request->getPath());
        $method = $request->getMethod();

        $resolved = ResourceRegistry::resolve($path, $method);
        if ($resolved === null) {
            return Response::error(404, "Route {$method} {$path} not found.");
        }

        [$resourceClass, $operation, $params] = $resolved;

        // ── 3. Vérifier le scope ────────────────────────────────────────
        $definition    = $resourceClass::definition();
        $requiredScope = $definition['operations'][$operation]['scope'];

        if (!in_array($requiredScope, $tokenScopes, true)) {
            return Response::error(
                403,
                "Scope '{$requiredScope}' is required for this operation."
            );
        }

        // ── 4. Résoudre le contexte shop ────────────────────────────────
        try {
            $context = ShopContextResolver::fromRequest($request);
        } catch (\InvalidArgumentException $e) {
            return Response::error(400, $e->getMessage());
        }

        // ── 5. Dispatcher vers le handler ───────────────────────────────
        $resource = new $resourceClass();

        try {
            switch ($operation) {
                case 'get':
                    return Response::ok($resource->get((int) $params['id'], $context));

                case 'list':
                    return Response::ok($resource->list($request->getQueryParams(), $context));

                case 'create':
                    return Response::created($resource->create($request->getBody(), $context));

                case 'update':
                    return Response::ok($resource->update((int) $params['id'], $request->getBody(), $context));

                case 'delete':
                    $resource->delete((int) $params['id'], $context);
                    return Response::noContent();

                case 'bulkDelete':
                    $resource->bulkDelete($request->getBody(), $context);
                    return Response::noContent();

                default:
                    return Response::error(405, "Operation '{$operation}' not supported.");
            }
        } catch (ResourceNotFoundException $e) {
            return Response::error(404, $e->getMessage());
        } catch (ValidationException $e) {
            return Response::validationError($e->getErrors());
        } catch (\Throwable $e) {
            $status = $e->getCode() >= 400 && $e->getCode() < 600 ? (int) $e->getCode() : 500;
            return Response::error($status, $e->getMessage());
        }
    }

    /** Extrait la partie après /admin-api, ex. /products/42 */
    private function extractApiPath(string $fullPath): string
    {
        $pos = strpos($fullPath, '/admin-api');
        if ($pos === false) {
            return $fullPath;
        }
        return substr($fullPath, $pos + strlen('/admin-api')) ?: '/';
    }
}
```

- [ ] **Step 7 : Écrire les tests unitaires du Dispatcher**

Créer `tests/Unit/DispatcherTest.php` :

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Unit;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use PrestaEdit\ApiModule\Api\Dispatcher;

class DispatcherTest extends TestCase
{
    private function makeRequest(string $method, string $path, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, 'http://localhost' . $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        return $request;
    }

    public function testDispatchWithoutTokenReturns401(): void
    {
        $dispatcher = new Dispatcher();
        $response   = $dispatcher->dispatch($this->makeRequest('GET', '/admin-api/contacts'));
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testDispatchWithInvalidTokenReturns401(): void
    {
        $dispatcher = new Dispatcher();
        $response   = $dispatcher->dispatch(
            $this->makeRequest('GET', '/admin-api/contacts', ['Authorization' => 'Bearer invalid.token.here'])
        );
        $this->assertSame(401, $response->getStatusCode());
    }
}
```

Run: `vendor/bin/phpunit tests/Unit/DispatcherTest.php --testdox`
Expected: PASS (le ResourceServer rejette les tokens invalides sans nécessiter la DB).

- [ ] **Step 8 : Commit**

```bash
git add src/Api/ src/Exception/ tests/Unit/DispatcherTest.php
git commit -m "feat: HTTP core layer — Request, Response, ShopContextResolver, Dispatcher"
```

---

## Task 5 : ResourceInterface + AbstractResource + ResourceRegistry

**Goal:** Définir le contrat des ressources, la classe de base avec tous les helpers (localisations, décimaux, pagination), et le registry qui maintient la table de routing.

**Files:**
- Create: `src/Resource/ResourceInterface.php`
- Create: `src/Resource/AbstractResource.php`
- Create: `src/Resource/ResourceRegistry.php`

**Acceptance Criteria:**
- [ ] `ResourceRegistry::resolve('/contacts', 'GET')` retourne `[ContactResource::class, 'list', []]` (après Task 6)
- [ ] `ResourceRegistry::resolve('/contacts/42', 'GET')` retourne `[ContactResource::class, 'get', ['id' => 42]]`
- [ ] `ResourceRegistry::resolve('/contacts/42', 'DELETE')` retourne `[ContactResource::class, 'delete', ['id' => 42]]`
- [ ] `ResourceRegistry::resolve('/contacts/bulk-delete', 'DELETE')` retourne `[ContactResource::class, 'bulkDelete', []]`
- [ ] `ResourceRegistry::scopeExists('contact_read')` retourne `true`
- [ ] `AbstractResource::getLocalizedField([1 => 'T-Shirt', 2 => 'T-Shirt'])` retourne `['en-US' => 'T-Shirt', 'fr-FR' => 'T-Shirt']` (avec 2 langues installées)

**Verify:** `vendor/bin/phpunit tests/Unit/ResourceRegistryTest.php --testdox` → all passing

**Steps:**

- [ ] **Step 1 : Créer `src/Resource/ResourceInterface.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource;

interface ResourceInterface
{
    /** @return array{uriTemplate:string,identifierKey:string,operations:array,exceptionToStatus:array} */
    public static function definition(): array;

    /** @param array<string,mixed> $context */
    public function get(int $id, array $context): array;

    /**
     * @param array<string,mixed> $filters  query params (limit, offset, orderBy, sortOrder, ...)
     * @param array<string,mixed> $context
     */
    public function list(array $filters, array $context): array;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     */
    public function create(array $data, array $context): array;

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     */
    public function update(int $id, array $data, array $context): array;

    /** @param array<string,mixed> $context */
    public function delete(int $id, array $context): void;
}
```

- [ ] **Step 2 : Créer `src/Resource/AbstractResource.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource;

abstract class AbstractResource
{
    // ── Champs localisés ─────────────────────────────────────────────────

    /**
     * Convertit un tableau [id_lang => valeur] en [locale => valeur].
     * Ex. [1 => 'T-Shirt', 2 => 'T-Shirt FR'] → ['en-US' => 'T-Shirt', 'fr-FR' => 'T-Shirt FR']
     *
     * @param array<int,string> $psLangArray
     * @return array<string,string>
     */
    protected function getLocalizedField(array $psLangArray): array
    {
        $result = [];
        foreach (\Language::getLanguages(false, false, false) as $lang) {
            $locale = $lang['locale'];
            $result[$locale] = $psLangArray[(int) $lang['id_lang']] ?? '';
        }
        return $result;
    }

    /**
     * Construit un tableau [id_lang => valeur] depuis un payload [locale => valeur]
     * pour alimenter un ObjectModel.
     *
     * @param array<string,string> $localizedData
     * @return array<int,string>
     */
    protected function buildPsLocalizedField(array $localizedData): array
    {
        $result = [];
        foreach (\Language::getLanguages(false, false, false) as $lang) {
            $locale = $lang['locale'];
            if (isset($localizedData[$locale])) {
                $result[(int) $lang['id_lang']] = $localizedData[$locale];
            }
        }
        return $result;
    }

    // ── Décimaux ─────────────────────────────────────────────────────────

    /** Sérialise un décimal en string 6 décimales (jamais float) */
    protected function decimal(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    // ── Pagination ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $filters
     * @return array{limit:int,offset:int,orderBy:string,sortOrder:string}
     */
    protected function getPaginationParams(array $filters, string $defaultOrderBy = 'id'): array
    {
        $limit     = max(1, min(100, (int) ($filters['limit']    ?? 20)));
        $offset    = max(0, (int) ($filters['offset']   ?? 0));
        $orderBy   = preg_replace('/[^a-zA-Z0-9_]/', '', $filters['orderBy']   ?? $defaultOrderBy);
        $sortOrder = strtolower($filters['sortOrder'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        return compact('limit', 'offset', 'orderBy', 'sortOrder');
    }

    /** Applique LIMIT/OFFSET à une DbQuery */
    protected function applyPagination(\DbQuery $query, array $filters, string $defaultOrderBy = 'id'): void
    {
        $p = $this->getPaginationParams($filters, $defaultOrderBy);
        $query->limit($p['limit'], $p['offset']);
    }

    /** Applique ORDER BY à une DbQuery avec mapping de colonnes */
    protected function applySort(\DbQuery $query, array $filters, string $defaultColumn, array $columnMap): void
    {
        $p      = $this->getPaginationParams($filters);
        $column = $columnMap[$p['orderBy']] ?? $defaultColumn;
        $query->orderBy("{$column} {$p['sortOrder']}");
    }

    /**
     * Compte le total d'une DbQuery via sous-requête.
     * N'utilise PAS clone + select() car DbQuery::select() appende au lieu de remplacer.
     */
    protected function countQuery(\DbQuery $query): int
    {
        $row = \Db::getInstance()->getRow(
            'SELECT COUNT(*) AS n FROM (' . $query->build() . ') AS subcount'
        );
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Construit la réponse paginée standard.
     *
     * @param array<array<string,mixed>> $items
     * @param array<string,mixed> $filters
     */
    protected function paginatedResponse(array $items, int $total, array $filters): array
    {
        $p = $this->getPaginationParams($filters);
        return [
            'items'      => $items,
            'totalItems' => $total,
            'offset'     => $p['offset'],
            'limit'      => $p['limit'],
            'orderBy'    => $p['orderBy'],
            'sortOrder'  => strtolower($p['sortOrder']),
        ];
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data
     * @param string[] $requiredFields
     * @throws \PrestaEdit\ApiModule\Exception\ValidationException
     */
    protected function requireFields(array $data, array $requiredFields): void
    {
        $errors = [];
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                $errors[$field] = ['This field is required.'];
            }
        }
        if (!empty($errors)) {
            throw new \PrestaEdit\ApiModule\Exception\ValidationException($errors);
        }
    }

    // ── bulkDelete générique ─────────────────────────────────────────────

    /**
     * @param array<string,mixed> $data  Body avec une clé plurielle (ex. ['contactIds' => [1,2,3]])
     * @param array<string,mixed> $context
     */
    public function bulkDelete(array $data, array $context): void
    {
        throw new \RuntimeException('bulkDelete not implemented for ' . static::class, 405);
    }
}
```

- [ ] **Step 3 : Créer `src/Resource/ResourceRegistry.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource;

use PrestaEdit\ApiModule\Resource\Contact\ContactResource;
// Les imports des autres ressources seront ajoutés au fil des tâches suivantes

class ResourceRegistry
{
    /** @var string[] Liste de toutes les classes de ressources */
    private static array $resources = [
        ContactResource::class,
        // Les autres ressources seront ajoutées dans les tâches 7-20
    ];

    /** Table de routing construite au premier appel : [uri_pattern => [class, operation, paramKeys]] */
    private static ?array $routeTable = null;

    /** Ensemble de tous les scopes déclarés */
    private static ?array $allScopes = null;

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Résout un chemin + méthode vers [resourceClass, operation, params].
     * Retourne null si aucune route ne correspond.
     *
     * @return array{0:string,1:string,2:array<string,mixed>}|null
     */
    public static function resolve(string $path, string $method): ?array
    {
        $table = self::getRouteTable();

        // Normalise le path (retire le trailing slash sauf racine)
        $path = rtrim($path, '/') ?: '/';

        foreach ($table as $pattern => $routes) {
            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }
            if (!isset($routes[$method])) {
                continue;
            }
            [$class, $operation, $paramKeys] = $routes[$method];
            $params = [];
            foreach ($paramKeys as $key) {
                if (isset($matches[$key])) {
                    $params[$key] = $matches[$key];
                }
            }
            return [$class, $operation, $params];
        }

        return null;
    }

    public static function scopeExists(string $scope): bool
    {
        return in_array($scope, self::getAllScopes(), true);
    }

    // ── Build ─────────────────────────────────────────────────────────────

    /** @return array<string, array<string, array{0:string,1:string,2:string[]}>> */
    private static function getRouteTable(): array
    {
        if (self::$routeTable !== null) {
            return self::$routeTable;
        }

        self::$routeTable = [];

        foreach (self::$resources as $class) {
            $def       = $class::definition();
            $uriTpl    = $def['uriTemplate'];           // ex. /contacts
            $idKey     = $def['identifierKey'];          // ex. contactId
            $operations = $def['operations'];

            foreach ($operations as $operation => $opDef) {
                $method    = strtoupper($opDef['method']);
                $uriSuffix = $opDef['uriSuffix'] ?? null; // ex. /bulk-delete

                if ($uriSuffix) {
                    // Route avec suffixe fixe : /contacts/bulk-delete
                    $pattern = self::buildPattern($uriTpl . $uriSuffix);
                    self::$routeTable[$pattern][$method] = [$class, $operation, []];
                } elseif (in_array($operation, ['get', 'update', 'delete'], true)) {
                    // Route avec ID : /contacts/{contactId}
                    $pattern = self::buildPattern($uriTpl . '/(?P<id>[0-9]+)');
                    self::$routeTable[$pattern][$method] = [$class, $operation, ['id']];
                } elseif (in_array($operation, ['list', 'create'], true)) {
                    // Route collection : /contacts
                    $pattern = self::buildPattern($uriTpl);
                    self::$routeTable[$pattern][$method] = [$class, $operation, []];
                }
            }

            // Sous-ressources : /products/{productId}/combinations
            if (isset($def['parentResource'])) {
                // Géré par le parent — rien à faire ici, la définition suffit
            }
        }

        return self::$routeTable;
    }

    private static function buildPattern(string $uri): string
    {
        return '#^' . $uri . '$#';
    }

    /** @return string[] */
    private static function getAllScopes(): array
    {
        if (self::$allScopes !== null) {
            return self::$allScopes;
        }
        self::$allScopes = [];
        foreach (self::$resources as $class) {
            foreach ($class::definition()['operations'] as $opDef) {
                self::$allScopes[] = $opDef['scope'];
            }
        }
        return array_values(array_unique(self::$allScopes));
    }

    public static function reset(): void
    {
        self::$routeTable = null;
        self::$allScopes  = null;
    }
}
```

- [ ] **Step 4 : Écrire les tests du registry**

Créer `tests/Unit/ResourceRegistryTest.php` :

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaEdit\ApiModule\Resource\ResourceRegistry;

class ResourceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ResourceRegistry::reset();
    }

    public function testResolvesContactList(): void
    {
        $result = ResourceRegistry::resolve('/contacts', 'GET');
        $this->assertNotNull($result);
        $this->assertSame('list', $result[1]);
    }

    public function testResolvesContactGet(): void
    {
        $result = ResourceRegistry::resolve('/contacts/42', 'GET');
        $this->assertNotNull($result);
        $this->assertSame('get', $result[1]);
        $this->assertSame('42', $result[2]['id']);
    }

    public function testResolvesContactDelete(): void
    {
        $result = ResourceRegistry::resolve('/contacts/42', 'DELETE');
        $this->assertNotNull($result);
        $this->assertSame('delete', $result[1]);
    }

    public function testResolvesContactBulkDelete(): void
    {
        $result = ResourceRegistry::resolve('/contacts/bulk-delete', 'DELETE');
        $this->assertNotNull($result);
        $this->assertSame('bulkDelete', $result[1]);
    }

    public function testReturnsNullForUnknownRoute(): void
    {
        $this->assertNull(ResourceRegistry::resolve('/unknown-resource', 'GET'));
    }

    public function testScopeExistsForKnownScope(): void
    {
        $this->assertTrue(ResourceRegistry::scopeExists('contact_read'));
        $this->assertTrue(ResourceRegistry::scopeExists('contact_write'));
    }

    public function testScopeExistsReturnsFalseForUnknown(): void
    {
        $this->assertFalse(ResourceRegistry::scopeExists('nonexistent_scope'));
    }
}
```

Run: `vendor/bin/phpunit tests/Unit/ResourceRegistryTest.php --testdox`
Expected: FAIL (ContactResource n'existe pas encore — sera créé en Task 6).

- [ ] **Step 5 : Commit**

```bash
git add src/Resource/ResourceInterface.php src/Resource/AbstractResource.php src/Resource/ResourceRegistry.php tests/Unit/ResourceRegistryTest.php
git commit -m "feat: ResourceInterface, AbstractResource helpers, and ResourceRegistry routing"
```

---

## Task 6 : Resource Contact (preuve E2E)

**Goal:** Implémenter la ressource Contact complète — la ressource canonique de ps_apiresources — pour valider le pipeline de bout en bout.

**Files:**
- Create: `src/Resource/Contact/ContactResource.php`
- Modify: `src/Resource/ResourceRegistry.php` (import déjà présent)

**Acceptance Criteria:**
- [ ] `GET /admin-api/contacts` → 200 avec structure `{items, totalItems, offset, limit, orderBy, sortOrder}`
- [ ] `GET /admin-api/contacts/1` → 200 avec `{contactId, names, email, customerService, descriptions}`
- [ ] `POST /admin-api/contacts` sans `names` → 422
- [ ] `POST /admin-api/contacts` avec payload valide → 201 + item créé
- [ ] `PATCH /admin-api/contacts/1` → 200 + item mis à jour
- [ ] `DELETE /admin-api/contacts/1` → 204
- [ ] `DELETE /admin-api/contacts/bulk-delete` avec `{"contactIds":[1,2]}` → 204
- [ ] Tests du registry `testResolvesContactList()` etc. → PASS

**Verify:** `vendor/bin/phpunit tests/Unit/ResourceRegistryTest.php --testdox` → all passing

**Steps:**

- [ ] **Step 1 : Vérifier que les tests du registry échouent (TDD)**

Run: `vendor/bin/phpunit tests/Unit/ResourceRegistryTest.php --testdox`
Expected: FAIL — `ContactResource not found`

- [ ] **Step 2 : Créer `src/Resource/Contact/ContactResource.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Contact;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Exception\ValidationException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ContactResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/contacts',
            'identifierKey'     => 'contactId',
            'operations'        => [
                'get'        => ['scope' => 'contact_read',  'method' => 'GET'],
                'list'       => ['scope' => 'contact_read',  'method' => 'GET'],
                'create'     => ['scope' => 'contact_write', 'method' => 'POST'],
                'update'     => ['scope' => 'contact_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'contact_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'contact_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $contact = new \Contact($id, $context['langId']);
        if (!\Validate::isLoadedObject($contact)) {
            throw new ResourceNotFoundException('Contact', $id);
        }
        return $this->map($contact, $context['langId']);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_contact, c.email, c.customer_service');
        $q->from('contact', 'c');

        $total = $this->countQuery($q);

        $this->applySort($q, $filters, 'c.id_contact', [
            'contactId'       => 'c.id_contact',
            'email'           => 'c.email',
            'customerService' => 'c.customer_service',
        ]);
        $this->applyPagination($q, $filters, 'id_contact');

        $rows = \Db::getInstance()->executeS($q);
        $langId = $context['langId'];

        $items = array_map(function (array $row) use ($langId): array {
            $contact = new \Contact((int) $row['id_contact'], $langId);
            return $this->map($contact, $langId);
        }, $rows ?: []);

        return $this->paginatedResponse($items, $total, $filters);
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $contact = new \Contact();
        $contact->name = $this->buildPsLocalizedField($data['names']);

        if (isset($data['email'])) {
            $contact->email = $data['email'];
        }
        if (isset($data['customerService'])) {
            $contact->customer_service = (int) $data['customerService'];
        }
        if (isset($data['descriptions'])) {
            $contact->description = $this->buildPsLocalizedField($data['descriptions']);
        }

        if (!$contact->save()) {
            throw new \RuntimeException('Failed to create contact.', 500);
        }

        return $this->get($contact->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $contact = new \Contact($id, $context['langId']);
        if (!\Validate::isLoadedObject($contact)) {
            throw new ResourceNotFoundException('Contact', $id);
        }

        if (isset($data['names'])) {
            $contact->name = $this->buildPsLocalizedField($data['names']);
        }
        if (isset($data['email'])) {
            $contact->email = $data['email'];
        }
        if (isset($data['customerService'])) {
            $contact->customer_service = (int) $data['customerService'];
        }
        if (isset($data['descriptions'])) {
            $contact->description = $this->buildPsLocalizedField($data['descriptions']);
        }

        if (!$contact->save()) {
            throw new \RuntimeException('Failed to update contact.', 500);
        }

        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $contact = new \Contact($id);
        if (!\Validate::isLoadedObject($contact)) {
            throw new ResourceNotFoundException('Contact', $id);
        }
        if (!$contact->delete()) {
            throw new \RuntimeException('Failed to delete contact.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['contactIds'] ?? []);
        foreach ($ids as $id) {
            $contact = new \Contact($id);
            if (\Validate::isLoadedObject($contact)) {
                $contact->delete();
            }
        }
    }

    // ── Mapping ──────────────────────────────────────────────────────────

    private function map(\Contact $contact, int $langId): array
    {
        // Récupère les données multilingues complètes
        $names        = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'contact_lang`
             WHERE `id_contact` = ' . (int) $contact->id
        );
        $descriptions = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `description` FROM `' . _DB_PREFIX_ . 'contact_lang`
             WHERE `id_contact` = ' . (int) $contact->id
        );

        $namesArray = array_column($names ?: [], 'name', 'id_lang');
        $descsArray = array_column($descriptions ?: [], 'description', 'id_lang');

        return [
            'contactId'       => (int) $contact->id,
            'names'           => $this->getLocalizedField($namesArray),
            'email'           => $contact->email,
            'customerService' => (bool) $contact->customer_service,
            'descriptions'    => $this->getLocalizedField($descsArray),
        ];
    }

}
```

- [ ] **Step 3 : Relancer les tests du registry**

Run: `vendor/bin/phpunit tests/Unit/ResourceRegistryTest.php --testdox`
Expected: all 7 tests PASS.

- [ ] **Step 4 : Commit**

```bash
git add src/Resource/Contact/
git commit -m "feat: ContactResource — canonical CRUD + bulk delete"
```

---

## Task 7 : Back-office — Gestion des clients API

**Goal:** Créer l'onglet d'administration pour gérer les clients API (créer, éditer, activer/désactiver, supprimer, voir les scopes).

**Files:**
- Create: `controllers/admin/AdminApimoduleClientController.php`
- Create: `views/templates/admin/apimodule_client/helpers/list/list_content.tpl`
- Create: `views/templates/admin/apimodule_client/helpers/form/form.tpl`

**Acceptance Criteria:**
- [ ] L'onglet `API Manager` est visible dans le back-office PS
- [ ] La liste affiche `client_id`, `client_name`, `scopes`, `active`, `date_add`
- [ ] Le formulaire de création génère un `client_id` aléatoire et un `client_secret` (affiché en clair une seule fois)
- [ ] Le secret est stocké en bcrypt (`password_hash`)
- [ ] La liste des scopes est présentée par domaine (checkboxes)

**Verify:** Naviguer vers `Administration > API Manager` dans le back-office PS → page affichée sans erreur

**Steps:**

- [ ] **Step 1 : Créer `controllers/admin/AdminApimoduleClientController.php`**

```php
<?php
declare(strict_types=1);

class AdminApimoduleClientController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table       = 'apimodule_client';
        $this->className   = 'ApimoduleClient';
        $this->lang        = false;
        $this->addRowAction('edit');
        $this->addRowAction('delete');
        $this->bootstrap   = true;

        parent::__construct();

        $this->fields_list = [
            'id'          => ['title' => 'ID',          'align' => 'center', 'class' => 'fixed-width-xs'],
            'client_id'   => ['title' => 'Client ID'],
            'client_name' => ['title' => 'Name'],
            'active'      => ['title' => 'Active', 'active' => 'status', 'type' => 'bool', 'align' => 'center'],
            'date_add'    => ['title' => 'Created',     'type' => 'datetime'],
        ];
    }

    public function renderForm(): string
    {
        $allScopes   = $this->getAllScopes();
        $clientScopes = [];

        if ($this->object && $this->object->id) {
            $clientScopes = json_decode((string) $this->object->scopes, true) ?? [];
        }

        $this->fields_form = [
            'legend' => ['title' => 'API Client', 'icon' => 'icon-key'],
            'input'  => [
                [
                    'type'     => 'text',
                    'label'    => 'Client Name',
                    'name'     => 'client_name',
                    'required' => true,
                ],
                [
                    'type'     => 'text',
                    'label'    => 'Client ID',
                    'name'     => 'client_id',
                    'hint'     => 'Leave empty to auto-generate',
                ],
                [
                    'type'  => 'html',
                    'name'  => 'scopes_html',
                    'html_content' => $this->renderScopesCheckboxes($allScopes, $clientScopes),
                    'label' => 'Scopes',
                ],
                [
                    'type'   => 'switch',
                    'label'  => 'Active',
                    'name'   => 'active',
                    'values' => [
                        ['id' => 'active_on',  'value' => 1, 'label' => 'Yes'],
                        ['id' => 'active_off', 'value' => 0, 'label' => 'No'],
                    ],
                ],
            ],
            'submit' => ['title' => 'Save'],
        ];

        return parent::renderForm();
    }

    public function processSave(): void
    {
        $clientId   = Tools::getValue('client_id') ?: bin2hex(random_bytes(16));
        $clientName = Tools::getValue('client_name');
        $active     = (int) Tools::getValue('active');

        // Scopes sélectionnés
        $selectedScopes = [];
        foreach (array_keys($this->getAllScopes()) as $scope) {
            if (Tools::getValue('scope_' . md5($scope))) {
                $selectedScopes[] = $scope;
            }
        }

        if ($this->object && $this->object->id) {
            // Mise à jour
            $secret = null;
            if (($raw = Tools::getValue('client_secret')) !== '') {
                $secret = password_hash($raw, PASSWORD_BCRYPT);
            }

            $data = [
                'client_id'   => pSQL($clientId),
                'client_name' => pSQL($clientName),
                'scopes'      => pSQL(json_encode($selectedScopes)),
                'active'      => $active,
                'date_upd'    => date('Y-m-d H:i:s'),
            ];
            if ($secret) {
                $data['client_secret'] = pSQL($secret);
            }

            Db::getInstance()->update('apimodule_client', $data, 'id = ' . (int) $this->object->id);
        } else {
            // Création — génère un secret
            $rawSecret = bin2hex(random_bytes(32));
            $this->context->smarty->assign('generated_secret', $rawSecret);

            Db::getInstance()->insert('apimodule_client', [
                'client_id'     => pSQL($clientId),
                'client_secret' => pSQL(password_hash($rawSecret, PASSWORD_BCRYPT)),
                'client_name'   => pSQL($clientName),
                'scopes'        => pSQL(json_encode($selectedScopes)),
                'active'        => $active,
                'date_add'      => date('Y-m-d H:i:s'),
                'date_upd'      => date('Y-m-d H:i:s'),
            ]);

            $this->confirmations[] = sprintf(
                'Client created. Client ID: <strong>%s</strong><br>'
                . 'Secret (affiché une seule fois): <code>%s</code>',
                htmlspecialchars($clientId),
                htmlspecialchars($rawSecret)
            );
        }

        $this->redirect_after = self::$currentIndex . '&token=' . $this->token;
    }

    private function renderScopesCheckboxes(array $allScopes, array $selectedScopes): string
    {
        $html = '<div class="row">';
        foreach ($allScopes as $domain => $scopes) {
            $html .= '<div class="col-md-3"><strong>' . htmlspecialchars($domain) . '</strong><ul class="list-unstyled">';
            foreach ($scopes as $scope) {
                $id      = 'scope_' . md5($scope);
                $checked = in_array($scope, $selectedScopes, true) ? ' checked' : '';
                $html   .= sprintf(
                    '<li><label><input type="checkbox" name="%s" value="1"%s> %s</label></li>',
                    htmlspecialchars($id),
                    $checked,
                    htmlspecialchars($scope)
                );
            }
            $html .= '</ul></div>';
        }
        return $html . '</div>';
    }

    private function getAllScopes(): array
    {
        return [
            'Address'       => ['address_read', 'address_write'],
            'ApiClient'     => ['api_client_read', 'api_client_write'],
            'Attribute'     => ['attribute_read', 'attribute_write'],
            'AttributeGroup'=> ['attribute_group_read', 'attribute_group_write'],
            'CartRule'      => ['cart_rule_read', 'cart_rule_write'],
            'Category'      => ['category_read', 'category_write'],
            'Contact'       => ['contact_read', 'contact_write'],
            'Country'       => ['country_read', 'country_write'],
            'Customer'      => ['customer_read', 'customer_write'],
            'CustomerGroup' => ['customer_group_read', 'customer_group_write'],
            'Discount'      => ['discount_read', 'discount_write'],
            'Feature'       => ['feature_read', 'feature_write'],
            'FeatureValue'  => ['feature_value_read', 'feature_value_write'],
            'Hook'          => ['hook_read', 'hook_write'],
            'Manufacturer'  => ['manufacturer_read', 'manufacturer_write'],
            'Module'        => ['module_read', 'module_write'],
            'Product'       => ['product_read', 'product_write'],
            'Profile'       => ['profile_read', 'profile_write'],
            'SearchAlias'   => ['search_alias_read', 'search_alias_write'],
            'SearchEngine'  => ['search_engine_read', 'search_engine_write'],
            'ShowcaseCard'  => ['showcase_card_read', 'showcase_card_write'],
            'Store'         => ['store_read', 'store_write'],
            'Supplier'      => ['supplier_read', 'supplier_write'],
            'Tab'           => ['tab_read', 'tab_write'],
            'Tax'           => ['tax_read', 'tax_write'],
            'TaxRulesGroup' => ['tax_rules_group_read', 'tax_rules_group_write'],
            'Title'         => ['title_read', 'title_write'],
            'WebserviceKey' => ['webservice_key_read', 'webservice_key_write'],
            'Zone'          => ['zone_read', 'zone_write'],
        ];
    }
}
```

Note : `ApimoduleClient` n'est pas un ObjectModel — le contrôleur accède directement à la DB. Si PS exige un ObjectModel pour `className`, créer une classe vide :

```php
// src/Model/ApimoduleClient.php
class ApimoduleClient extends ObjectModel
{
    public static $definition = [
        'table'  => 'apimodule_client',
        'primary' => 'id',
        'fields' => [
            'client_id'     => ['type' => self::TYPE_STRING],
            'client_secret' => ['type' => self::TYPE_STRING],
            'client_name'   => ['type' => self::TYPE_STRING],
            'scopes'        => ['type' => self::TYPE_STRING],
            'active'        => ['type' => self::TYPE_BOOL],
            'date_add'      => ['type' => self::TYPE_DATE],
            'date_upd'      => ['type' => self::TYPE_DATE],
        ],
    ];
}
```

- [ ] **Step 2 : Commit**

```bash
git add controllers/admin/ src/Model/
git commit -m "feat: back-office API client management (list, create, edit, scopes)"
```

---

## Task 8 : Tests d'intégration E2E (Contact)

**Goal:** Valider le pipeline complet — token OAuth2 → endpoint Contact — avec une suite de tests d'intégration reproduisant l'approche `ApiTestCase` de `ps_apiresources`.

**Files:**
- Create: `tests/Integration/ApiTestCase.php`
- Create: `tests/Integration/ContactEndpointTest.php`
- Create: `phpunit.xml`

**Acceptance Criteria:**
- [ ] `ContactEndpointTest::testGetContactList()` → 200 + structure `{items, totalItems}`
- [ ] `ContactEndpointTest::testCreateContact()` → 201 + `contactId` présent
- [ ] `ContactEndpointTest::testGetContact()` → 200 + champs localisés
- [ ] `ContactEndpointTest::testUpdateContact()` → 200 + champ modifié
- [ ] `ContactEndpointTest::testDeleteContact()` → 204
- [ ] `ContactEndpointTest::testGetContactWithoutToken()` → 401
- [ ] `ContactEndpointTest::testGetContactWithWrongScope()` → 403

**Verify:** `vendor/bin/phpunit tests/Integration/ContactEndpointTest.php --testdox` → all passing (nécessite une installation PS fonctionnelle)

**Steps:**

- [ ] **Step 1 : Créer `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/Integration/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2 : Créer `tests/Integration/bootstrap.php`**

```php
<?php
declare(strict_types=1);

// Charge l'autoloader du module
require_once __DIR__ . '/../../vendor/autoload.php';

// Charge PrestaShop (adapter le chemin selon l'installation)
$psRoot = getenv('PS_ROOT') ?: '/var/www/html';
require_once $psRoot . '/config/config.inc.php';
require_once $psRoot . '/init.php';
```

- [ ] **Step 3 : Créer `tests/Integration/ApiTestCase.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Integration;

use PHPUnit\Framework\TestCase;

abstract class ApiTestCase extends TestCase
{
    protected static string $baseUrl;
    /** @var array<string, string> Tokens cachés par scope-key */
    private static array $tokenCache = [];
    /** @var int|null ID du client de test créé */
    protected static ?int $testClientId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::$baseUrl = rtrim(getenv('API_BASE_URL') ?: 'http://localhost', '/');
        static::createTestClient();
    }

    public static function tearDownAfterClass(): void
    {
        static::removeTestClient();
        parent::tearDownAfterClass();
    }

    // ── Client de test ────────────────────────────────────────────────────

    private static function createTestClient(): void
    {
        $scopes = static::getAllScopes();
        \Db::getInstance()->insert('apimodule_client', [
            'client_id'     => 'test_client',
            'client_secret' => password_hash('test_secret', PASSWORD_BCRYPT),
            'client_name'   => 'Test Client',
            'scopes'        => json_encode($scopes),
            'active'        => 1,
            'date_add'      => date('Y-m-d H:i:s'),
            'date_upd'      => date('Y-m-d H:i:s'),
        ]);
        $row = \Db::getInstance()->getRow(
            "SELECT id FROM `" . _DB_PREFIX_ . "apimodule_client` WHERE client_id = 'test_client'"
        );
        static::$testClientId = $row ? (int) $row['id'] : null;
    }

    private static function removeTestClient(): void
    {
        \Db::getInstance()->delete('apimodule_client', "client_id = 'test_client'");
        self::$tokenCache = [];
    }

    /** @return string[] */
    protected static function getAllScopes(): array
    {
        return [
            'contact_read', 'contact_write',
            'zone_read', 'zone_write',
            'product_read', 'product_write',
            // Compléter au fil des Plans B/C/D
        ];
    }

    // ── Token ─────────────────────────────────────────────────────────────

    protected function getBearerToken(array $scopes): string
    {
        $key = implode(',', $scopes);
        if (!isset(self::$tokenCache[$key])) {
            $ch = curl_init(static::$baseUrl . '/admin-api/access_token');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => array_merge(
                    [
                        'grant_type'    => 'client_credentials',
                        'client_id'     => 'test_client',
                        'client_secret' => 'test_secret',
                    ],
                    array_map(fn (string $s): string => $s, array_combine(
                        array_fill(0, count($scopes), 'scope[]'),
                        $scopes
                    ))
                ),
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($body, true);
            self::$tokenCache[$key] = $data['access_token'];
        }
        return self::$tokenCache[$key];
    }

    // ── Helpers de requête ────────────────────────────────────────────────

    protected function getItem(string $path, array $scopes, int $expectedCode = 200): array
    {
        return $this->request('GET', $path, [], $scopes, $expectedCode);
    }

    protected function listItems(string $path, array $scopes, array $filters = [], int $expectedCode = 200): array
    {
        $url = $path . ($filters ? '?' . http_build_query($filters) : '');
        return $this->request('GET', $url, [], $scopes, $expectedCode);
    }

    protected function createItem(string $path, array $data, array $scopes, int $expectedCode = 201): array
    {
        return $this->request('POST', $path, $data, $scopes, $expectedCode);
    }

    protected function updateItem(string $path, array $data, array $scopes, int $expectedCode = 200): array
    {
        return $this->request('PATCH', $path, $data, $scopes, $expectedCode);
    }

    protected function deleteItem(string $path, array $scopes, int $expectedCode = 204): void
    {
        $this->request('DELETE', $path, [], $scopes, $expectedCode);
    }

    protected function bulkDeleteItems(string $path, array $data, array $scopes, int $expectedCode = 204): void
    {
        $this->request('DELETE', $path, $data, $scopes, $expectedCode);
    }

    protected function requestWithoutToken(string $method, string $path, int $expectedCode = 401): array
    {
        return $this->request($method, $path, [], [], $expectedCode, false);
    }

    /**
     * @param array<string,mixed> $data
     * @param string[] $scopes
     */
    private function request(
        string $method,
        string $path,
        array $data,
        array $scopes,
        int $expectedCode,
        bool $withToken = true
    ): array {
        $url     = static::$baseUrl . $path;
        $headers = ['Content-Type: application/json', 'Accept: application/json'];

        if ($withToken && !empty($scopes)) {
            $headers[] = 'Authorization: Bearer ' . $this->getBearerToken($scopes);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $body    = curl_exec($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->assertSame($expectedCode, $status, "Expected HTTP {$expectedCode}, got {$status}. Body: {$body}");

        return json_decode($body, true) ?? [];
    }
}
```

- [ ] **Step 4 : Créer `tests/Integration/ContactEndpointTest.php`**

```php
<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Tests\Integration;

class ContactEndpointTest extends ApiTestCase
{
    private static int $createdContactId;

    public function testGetContactListWithoutToken(): void
    {
        $this->requestWithoutToken('GET', '/admin-api/contacts', 401);
    }

    public function testGetContactListWithWrongScope(): void
    {
        // contact_write ne suffit pas pour lire
        $this->listItems('/admin-api/contacts', ['contact_write'], [], 403);
    }

    public function testGetContactList(): void
    {
        $response = $this->listItems('/admin-api/contacts', ['contact_read']);
        $this->assertArrayHasKey('items', $response);
        $this->assertArrayHasKey('totalItems', $response);
        $this->assertArrayHasKey('offset', $response);
        $this->assertArrayHasKey('limit', $response);
    }

    public function testCreateContactWithMissingName(): void
    {
        $this->createItem('/admin-api/contacts', ['email' => 'test@test.com'], ['contact_write'], 422);
    }

    public function testCreateContact(): void
    {
        $response = $this->createItem('/admin-api/contacts', [
            'names'           => ['en-US' => 'Test Contact', 'fr-FR' => 'Contact Test'],
            'email'           => 'apitest@example.com',
            'customerService' => true,
        ], ['contact_write']);

        $this->assertArrayHasKey('contactId', $response);
        $this->assertSame('apitest@example.com', $response['email']);
        $this->assertArrayHasKey('en-US', $response['names']);

        self::$createdContactId = $response['contactId'];
    }

    /** @depends testCreateContact */
    public function testGetContact(): void
    {
        $response = $this->getItem('/admin-api/contacts/' . self::$createdContactId, ['contact_read']);
        $this->assertSame(self::$createdContactId, $response['contactId']);
        $this->assertSame('Test Contact', $response['names']['en-US'] ?? '');
    }

    /** @depends testCreateContact */
    public function testUpdateContact(): void
    {
        $response = $this->updateItem(
            '/admin-api/contacts/' . self::$createdContactId,
            ['names' => ['en-US' => 'Updated Contact', 'fr-FR' => 'Contact Modifié']],
            ['contact_write']
        );
        $this->assertSame('Updated Contact', $response['names']['en-US'] ?? '');
    }

    /** @depends testUpdateContact */
    public function testDeleteContact(): void
    {
        $this->deleteItem('/admin-api/contacts/' . self::$createdContactId, ['contact_write']);
    }

    /** @depends testDeleteContact */
    public function testGetDeletedContactReturns404(): void
    {
        $this->getItem('/admin-api/contacts/' . self::$createdContactId, ['contact_read'], 404);
    }
}
```

- [ ] **Step 5 : Lancer les tests d'intégration** (nécessite PS en cours d'exécution)

```bash
PS_ROOT=/var/www/prestashop API_BASE_URL=http://localhost vendor/bin/phpunit tests/Integration/ContactEndpointTest.php --testdox
```

Expected: 7 tests PASS.

- [ ] **Step 6 : Commit final Plan A**

```bash
git add tests/ phpunit.xml
git commit -m "feat: integration test suite (ApiTestCase + ContactEndpointTest) — Plan A complete"
```

---

## Résumé — Plan A terminé

À l'issue de ce plan, le module dispose de :

| Composant | État |
|---|---|
| Module PS 1.7.6+ installable | ✅ |
| OAuth2 Client Credentials + JWT RSA | ✅ |
| `POST /admin-api/access_token` | ✅ |
| HTTP core (Request/Response/Dispatcher) | ✅ |
| Multi-shop context resolver | ✅ |
| ResourceInterface + AbstractResource | ✅ |
| ResourceRegistry (routing table) | ✅ |
| Resource Contact — CRUD + bulk delete | ✅ |
| Back-office API Manager | ✅ |
| Tests unitaires + intégration E2E | ✅ |

**Suite : Plan B** — Ressources simples (Zone, Country, Title, Profile, Hook, Tab, Manufacturer, Supplier, Store, Address, SearchAlias, SearchEngine, WebserviceKey, ShowcaseCard)
