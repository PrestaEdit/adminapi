<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Profile;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ProfileResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/profiles',
            'identifierKey'     => 'profileId',
            'operations'        => [
                'get'        => ['scope' => 'profile_read',  'method' => 'GET'],
                'list'       => ['scope' => 'profile_read',  'method' => 'GET'],
                'create'     => ['scope' => 'profile_write', 'method' => 'POST'],
                'update'     => ['scope' => 'profile_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'profile_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'profile_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $profile = new \Profile($id, $context['langId']);
        if (!\Validate::isLoadedObject($profile)) {
            throw new ResourceNotFoundException('Profile', $id);
        }
        return $this->map($profile);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_profile');
        $q->from('profile');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_profile', ['profileId' => 'id_profile']);
        $this->applyPagination($q, $filters, 'id_profile');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_profile'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $profile       = new \Profile();
        $profile->name = $this->buildPsLocalizedField($data['names']);

        if (!$profile->save()) {
            throw new \RuntimeException('Failed to create profile.', 500);
        }
        return $this->get((int) $profile->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $profile = new \Profile($id, $context['langId']);
        if (!\Validate::isLoadedObject($profile)) {
            throw new ResourceNotFoundException('Profile', $id);
        }
        if (isset($data['names'])) {
            $profile->name = $this->buildPsLocalizedField($data['names']);
        }
        if (!$profile->save()) {
            throw new \RuntimeException('Failed to update profile.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $profile = new \Profile($id);
        if (!\Validate::isLoadedObject($profile)) {
            throw new ResourceNotFoundException('Profile', $id);
        }
        if (!$profile->delete()) {
            throw new \RuntimeException('Failed to delete profile.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['profileIds'] ?? []);
        foreach ($ids as $id) {
            $profile = new \Profile($id);
            if (\Validate::isLoadedObject($profile)) {
                $profile->delete();
            }
        }
    }

    private function map(\Profile $profile): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'profile_lang`
             WHERE `id_profile` = ' . (int) $profile->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'profileId' => (int) $profile->id,
            'names'     => $this->getLocalizedField($names),
        ];
    }
}
