<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Adminapi extends Module
{
    public function __construct()
    {
        $this->name = 'adminapi';
        $this->tab = 'administration';
        $this->version = '1.2.0';
        $this->author = 'PrestaEdit';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => '8.99.99'];
        parent::__construct();
        $this->displayName = $this->l('Admin API Module');
        $this->description = $this->l('PrestaShop Admin API — port of ps_apiresources for PS 1.7+');
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
            && $this->removeRsaKeys()
            && $this->uninstallTab();
    }

    public function hookModuleRoutes(): array
    {
        $base = ['fc' => 'module', 'module' => $this->name, 'controller' => 'api'];
        // NOTE: PrestaShop's Dispatcher::loadRoutes() requires each route to
        // expose ALL of 'controller', 'rule', 'keywords' and 'params' at the
        // top level — a route missing any one of them is silently skipped.
        return [
            'adminapi-token' => [
                'controller' => 'api',
                'rule'       => 'admin-api/access_token',
                'keywords'   => [],
                'params'     => $base,
            ],
            'adminapi-openapi' => [
                'controller' => 'api',
                'rule'       => 'admin-api/openapi.json',
                'keywords'   => [],
                'params'     => $base,
            ],
            // Self-introspection of the authenticated client. Must be declared
            // before the generic {resource}/{id} rules: 'infos' is non-numeric
            // so it would otherwise match nothing and 404.
            'adminapi-client-infos' => [
                'controller' => 'api',
                'rule'       => 'admin-api/api-client/infos',
                'keywords'   => [],
                'params'     => $base,
            ],
            'adminapi-sub-item' => [
                'controller' => 'api',
                'rule'       => 'admin-api/{resource}/{id}/{subresource}/{subid}',
                'keywords'   => [
                    'resource'    => ['regexp' => '[a-z][a-z0-9\-]+',  'param' => 'resource'],
                    'id'          => ['regexp' => '[0-9]+',             'param' => 'id'],
                    'subresource' => ['regexp' => '[a-z][a-z0-9\-]+',  'param' => 'subresource'],
                    'subid'       => ['regexp' => '[0-9]+',             'param' => 'subid'],
                ],
                'params'     => $base,
            ],
            'adminapi-sub-collection' => [
                'controller' => 'api',
                'rule'       => 'admin-api/{resource}/{id}/{subresource}',
                'keywords'   => [
                    'resource'    => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                    'id'          => ['regexp' => '[0-9]+',            'param' => 'id'],
                    'subresource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'subresource'],
                ],
                'params'     => $base,
            ],
            'adminapi-bulk' => [
                'controller' => 'api',
                'rule'       => 'admin-api/{resource}/bulk-{action}',
                'keywords'   => [
                    'resource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                    'action'   => ['regexp' => '[a-z\-]+',          'param' => 'action'],
                ],
                'params'     => $base,
            ],
            'adminapi-item' => [
                'controller' => 'api',
                'rule'       => 'admin-api/{resource}/{id}',
                'keywords'   => [
                    'resource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                    'id'       => ['regexp' => '[0-9]+',            'param' => 'id'],
                ],
                'params'     => $base,
            ],
            'adminapi-collection' => [
                'controller' => 'api',
                'rule'       => 'admin-api/{resource}',
                'keywords'   => [
                    'resource' => ['regexp' => '[a-z][a-z0-9\-]+', 'param' => 'resource'],
                ],
                'params'     => $base,
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
        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $content);
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

        if (!openssl_pkey_export($key, $privateKeyPem)) {
            return false;
        }
        $details = openssl_pkey_get_details($key);
        if (!$details || empty($details['key'])) {
            return false;
        }

        // Write private key atomically with correct permissions
        $tmpPath = self::getPrivateKeyPath() . '.tmp';
        file_put_contents($tmpPath, $privateKeyPem);
        chmod($tmpPath, 0600);
        rename($tmpPath, self::getPrivateKeyPath());
        file_put_contents(self::getPublicKeyPath(), $details['key']);

        $encryptionKey = \Defuse\Crypto\Key::createNewRandomKey()->saveToAsciiSafeString();
        \Configuration::updateValue('ADMINAPI_ENCRYPTION_KEY', $encryptionKey);

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
        \Configuration::deleteByName('ADMINAPI_ENCRYPTION_KEY');
        return true;
    }

    // ── Back-office tab ──────────────────────────────────────────────────

    private function installTab(): bool
    {
        // Live under "Advanced Parameters", next to Webservice — same place as
        // PrestaShop's own admin API.
        $parentId = (int) \Tab::getIdFromClassName('AdminAdvancedParameters') ?: -1;

        return $this->addTab('AdminAdminapiClient', 'API Manager', $parentId)
            && $this->addTab('AdminAdminapiDoc', 'API Documentation', $parentId);
    }

    /** Create (or skip if already present) a back-office tab for a controller. */
    public function addTab(string $className, string $label, int $parentId): bool
    {
        if ((int) \Tab::getIdFromClassName($className) > 0) {
            return true; // idempotent — already installed
        }

        $tab = new \Tab();
        $tab->active = 1;
        $tab->class_name = $className;
        $tab->module = $this->name;
        $tab->id_parent = $parentId;
        $tab->name = [];
        foreach (\Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $label;
        }
        return (bool) $tab->add();
    }

    private function uninstallTab(): bool
    {
        $ok = true;
        foreach (['AdminAdminapiClient', 'AdminAdminapiDoc'] as $className) {
            $id = (int) \Tab::getIdFromClassName($className);
            if ($id) {
                $ok = (new \Tab($id))->delete() && $ok;
            }
        }
        return $ok;
    }
}
