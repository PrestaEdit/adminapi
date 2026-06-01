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

        $clientId  = isset($data['clientId']) && $data['clientId'] !== ''
            ? $data['clientId']
            : bin2hex(random_bytes(16));
        $rawSecret = bin2hex(random_bytes(32));
        $scopes    = $data['scopes'] ?? [];

        \Db::getInstance()->insert('apimodule_client', [
            'client_id'     => pSQL($clientId),
            'client_secret' => pSQL(password_hash($rawSecret, PASSWORD_BCRYPT)),
            'client_name'   => pSQL($data['clientName']),
            'scopes'        => pSQL((string) json_encode($scopes)),
            'active'        => (int) (bool) ($data['active'] ?? true),
            'date_add'      => date('Y-m-d H:i:s'),
            'date_upd'      => date('Y-m-d H:i:s'),
        ]);

        $newId  = (int) \Db::getInstance()->Insert_ID();
        $result = $this->get($newId, $context);
        // Expose secret once only — it cannot be retrieved after this response
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
        if (isset($data['clientName']))   { $updates['client_name'] = pSQL($data['clientName']); }
        if (isset($data['active']))       { $updates['active']       = (int) (bool) $data['active']; }
        if (isset($data['scopes']))       { $updates['scopes']       = pSQL((string) json_encode($data['scopes'])); }
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
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);
        return [
            'apiClientId' => (int) $row['id'],
            'clientId'    => $row['client_id'],
            'clientName'  => $row['client_name'],
            'scopes'      => is_array($scopes) ? $scopes : [],
            'active'      => (bool) $row['active'],
            'dateAdd'     => $row['date_add'] ?? '',
            'dateUpd'     => $row['date_upd'] ?? '',
        ];
    }
}
