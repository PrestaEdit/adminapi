<?php
declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';

// PrestaShop stubs for unit testing
// These let us exercise DB-dependent code paths without a real PS installation.
if (!class_exists('Db')) {
    class Db
    {
        /** @var array<string,mixed>|false Next row to return from getRow(); false = no row */
        public static $nextRow = false;

        public static function getInstance(): self
        {
            return new self();
        }

        /**
         * @return array<string,mixed>|false
         */
        public function getRow(string $sql)
        {
            $result = self::$nextRow;
            self::$nextRow = false; // auto-reset after one use
            return $result;
        }

        public function insert(string $table, array $data): bool
        {
            return true;
        }

        public function update(string $table, array $data, string $where = ''): bool
        {
            return true;
        }
    }
}

if (!function_exists('pSQL')) {
    /**
     * @param mixed $value
     */
    function pSQL($value, bool $htmlOk = false): string
    {
        return addslashes((string) $value);
    }
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!class_exists('Apimodule', false)) {
    class Apimodule
    {
        public static function getPrivateKeyPath(): string
        {
            return dirname(__DIR__, 2) . '/var/keys/private.key';
        }

        public static function getPublicKeyPath(): string
        {
            return dirname(__DIR__, 2) . '/var/keys/public.key';
        }
    }
}

if (!class_exists('Configuration', false)) {
    class Configuration
    {
        /** @return string|false */
        public static function get(string $key)
        {
            return '';
        }
    }
}
