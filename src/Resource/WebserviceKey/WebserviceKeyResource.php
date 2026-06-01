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
        if (isset($data['isModule']))                    { $wsa->is_module   = (bool) $data['isModule']; }

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
