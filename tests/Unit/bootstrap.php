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

if (!class_exists('Language', false)) {
    class Language
    {
        /**
         * @return array<int, array{id_lang:int, locale:string, language_code:string, name:string}>
         */
        public static function getLanguages(bool $active = true, $idShop = false, bool $idsOnly = false): array
        {
            return [
                ['id_lang' => 1, 'locale' => 'en-US', 'language_code' => 'en', 'name' => 'English'],
                ['id_lang' => 2, 'locale' => 'fr-FR', 'language_code' => 'fr', 'name' => 'Français'],
            ];
        }
    }
}

if (!class_exists('DbQuery', false)) {
    class DbQuery
    {
        private string $sql = '';
        public function select(string $fields): self { $this->sql .= " SELECT {$fields}"; return $this; }
        public function from(string $table, string $alias = ''): self { $this->sql .= " FROM {$table} {$alias}"; return $this; }
        public function limit(int $limit, int $offset = 0): self { $this->sql .= " LIMIT {$offset}, {$limit}"; return $this; }
        public function orderBy(string $fields): self { $this->sql .= " ORDER BY {$fields}"; return $this; }
        public function build(): string { return $this->sql; }
    }
}
