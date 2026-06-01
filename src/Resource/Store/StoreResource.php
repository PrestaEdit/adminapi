<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Store;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class StoreResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/stores',
            'identifierKey'     => 'storeId',
            'operations'        => [
                'get'        => ['scope' => 'store_read',  'method' => 'GET'],
                'list'       => ['scope' => 'store_read',  'method' => 'GET'],
                'create'     => ['scope' => 'store_write', 'method' => 'POST'],
                'update'     => ['scope' => 'store_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'store_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'store_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $store = new \Store($id, $context['langId']);
        if (!\Validate::isLoadedObject($store)) {
            throw new ResourceNotFoundException('Store', $id);
        }
        return $this->map($store);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('s.id_store');
        $q->from('store', 's');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 's.id_store', [
            'storeId' => 's.id_store',
            'city'    => 's.city',
        ]);
        $this->applyPagination($q, $filters, 'id_store');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_store'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idCountry', 'city', 'postcode', 'names']);

        $store             = new \Store();
        $store->id_country = (int) $data['idCountry'];
        $store->id_state   = (int) ($data['idState'] ?? 0);
        $store->city       = $data['city'];
        $store->postcode   = $data['postcode'];
        $store->active     = (bool) ($data['active'] ?? true);
        $store->phone      = $data['phone'] ?? '';
        $store->fax        = $data['fax'] ?? '';
        $store->email      = $data['email'] ?? '';
        $store->latitude   = (float) ($data['latitude'] ?? 0);
        $store->longitude  = (float) ($data['longitude'] ?? 0);
        $store->name       = $this->buildPsLocalizedField($data['names']);

        if (isset($data['addressLines']))  { $store->address1 = $this->buildPsLocalizedField($data['addressLines']); }
        if (isset($data['addressLines2'])) { $store->address2 = $this->buildPsLocalizedField($data['addressLines2']); }
        if (isset($data['hours']))         { $store->hours    = $this->buildPsLocalizedField($data['hours']); }
        if (isset($data['notes']))         { $store->note     = $this->buildPsLocalizedField($data['notes']); }

        if (!$store->save()) {
            throw new \RuntimeException('Failed to create store.', 500);
        }
        return $this->get((int) $store->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $store = new \Store($id, $context['langId']);
        if (!\Validate::isLoadedObject($store)) {
            throw new ResourceNotFoundException('Store', $id);
        }
        if (isset($data['idCountry']))     { $store->id_country = (int) $data['idCountry']; }
        if (isset($data['idState']))       { $store->id_state   = (int) $data['idState']; }
        if (isset($data['city']))          { $store->city       = $data['city']; }
        if (isset($data['postcode']))      { $store->postcode   = $data['postcode']; }
        if (isset($data['active']))        { $store->active     = (bool) $data['active']; }
        if (isset($data['phone']))         { $store->phone      = $data['phone']; }
        if (isset($data['fax']))           { $store->fax        = $data['fax']; }
        if (isset($data['email']))         { $store->email      = $data['email']; }
        if (isset($data['latitude']))      { $store->latitude   = (float) $data['latitude']; }
        if (isset($data['longitude']))     { $store->longitude  = (float) $data['longitude']; }
        if (isset($data['names']))         { $store->name       = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['addressLines']))  { $store->address1   = $this->buildPsLocalizedField($data['addressLines']); }
        if (isset($data['addressLines2'])) { $store->address2  = $this->buildPsLocalizedField($data['addressLines2']); }
        if (isset($data['hours']))         { $store->hours      = $this->buildPsLocalizedField($data['hours']); }
        if (isset($data['notes']))         { $store->note       = $this->buildPsLocalizedField($data['notes']); }

        if (!$store->save()) {
            throw new \RuntimeException('Failed to update store.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $store = new \Store($id);
        if (!\Validate::isLoadedObject($store)) {
            throw new ResourceNotFoundException('Store', $id);
        }
        if (!$store->delete()) {
            throw new \RuntimeException('Failed to delete store.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['storeIds'] ?? []);
        foreach ($ids as $id) {
            $store = new \Store($id);
            if (\Validate::isLoadedObject($store)) {
                $store->delete();
            }
        }
    }

    private function map(\Store $store): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name`, `address1`, `address2`, `hours`, `note`
             FROM `' . _DB_PREFIX_ . 'store_lang`
             WHERE `id_store` = ' . (int) $store->id
        );

        $names = array_column($rows ?: [], 'name',     'id_lang');
        $addr1 = array_column($rows ?: [], 'address1', 'id_lang');
        $addr2 = array_column($rows ?: [], 'address2', 'id_lang');
        $hours = array_column($rows ?: [], 'hours',    'id_lang');
        $notes = array_column($rows ?: [], 'note',     'id_lang');

        return [
            'storeId'       => (int) $store->id,
            'idCountry'     => (int) $store->id_country,
            'idState'       => (int) $store->id_state,
            'city'          => $store->city,
            'postcode'      => $store->postcode,
            'active'        => (bool) $store->active,
            'phone'         => $store->phone ?? '',
            'fax'           => $store->fax ?? '',
            'email'         => $store->email ?? '',
            'latitude'      => (float) $store->latitude,
            'longitude'     => (float) $store->longitude,
            'names'         => $this->getLocalizedField($names),
            'addressLines'  => $this->getLocalizedField($addr1),
            'addressLines2' => $this->getLocalizedField($addr2),
            'hours'         => $this->getLocalizedField($hours),
            'notes'         => $this->getLocalizedField($notes),
        ];
    }
}
