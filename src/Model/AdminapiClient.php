<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (!class_exists('AdminapiClient')) {
    class AdminapiClient extends ObjectModel
    {
        public $client_id;
        public $client_secret;
        public $client_name;
        public $scopes;
        public $active = 1;
        public $date_add;
        public $date_upd;

        public static $definition = [
            'table'   => 'adminapi_client',
            'primary' => 'id',
            'fields'  => [
                'client_id'     => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 80],
                'client_secret' => ['type' => self::TYPE_STRING, 'size' => 255],
                'client_name'   => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 255],
                'scopes'        => ['type' => self::TYPE_STRING],
                'active'        => ['type' => self::TYPE_BOOL],
                'date_add'      => ['type' => self::TYPE_DATE],
                'date_upd'      => ['type' => self::TYPE_DATE],
            ],
        ];
    }
}
