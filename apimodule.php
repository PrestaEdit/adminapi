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
