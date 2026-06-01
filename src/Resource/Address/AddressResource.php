<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Address;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class AddressResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/addresses',
            'identifierKey'     => 'addressId',
            'operations'        => [
                'get'        => ['scope' => 'address_read',  'method' => 'GET'],
                'list'       => ['scope' => 'address_read',  'method' => 'GET'],
                'create'     => ['scope' => 'address_write', 'method' => 'POST'],
                'update'     => ['scope' => 'address_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'address_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'address_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $address = new \Address($id);
        if (!\Validate::isLoadedObject($address) || $address->deleted) {
            throw new ResourceNotFoundException('Address', $id);
        }
        return $this->mapRow($address);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('a.id_address, a.id_customer, a.id_country, a.id_state, a.alias,
                    a.company, a.lastname, a.firstname, a.vat_number, a.address1, a.address2,
                    a.postcode, a.city, a.phone, a.phone_mobile, a.other, a.active,
                    a.date_add, a.date_upd');
        $q->from('address', 'a');
        $q->where('a.deleted = 0');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'a.id_address', [
            'addressId' => 'a.id_address',
            'lastname'  => 'a.lastname',
            'city'      => 'a.city',
        ]);
        $this->applyPagination($q, $filters, 'id_address');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapFromRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idCountry', 'alias', 'lastname', 'firstname', 'address1', 'city']);

        $a               = new \Address();
        $a->id_country   = (int) $data['idCountry'];
        $a->id_state     = (int) ($data['idState'] ?? 0);
        $a->id_customer  = (int) ($data['idCustomer'] ?? 0);
        $a->alias        = $data['alias'];
        $a->lastname     = $data['lastname'];
        $a->firstname    = $data['firstname'];
        $a->address1     = $data['address1'];
        $a->address2     = $data['address2'] ?? '';
        $a->postcode     = $data['postcode'] ?? '';
        $a->city         = $data['city'];
        $a->phone        = $data['phone'] ?? '';
        $a->phone_mobile = $data['phoneMobile'] ?? '';
        $a->company      = $data['company'] ?? '';
        $a->vat_number   = $data['vatNumber'] ?? '';
        $a->other        = $data['other'] ?? '';
        $a->active       = (bool) ($data['active'] ?? true);

        if (!$a->save()) {
            throw new \RuntimeException('Failed to create address.', 500);
        }
        return $this->get((int) $a->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $a = new \Address($id);
        if (!\Validate::isLoadedObject($a) || $a->deleted) {
            throw new ResourceNotFoundException('Address', $id);
        }
        if (isset($data['idCountry']))   { $a->id_country   = (int) $data['idCountry']; }
        if (isset($data['idState']))     { $a->id_state      = (int) $data['idState']; }
        if (isset($data['idCustomer']))  { $a->id_customer   = (int) $data['idCustomer']; }
        if (isset($data['alias']))       { $a->alias         = $data['alias']; }
        if (isset($data['lastname']))    { $a->lastname      = $data['lastname']; }
        if (isset($data['firstname']))   { $a->firstname     = $data['firstname']; }
        if (isset($data['address1']))    { $a->address1      = $data['address1']; }
        if (isset($data['address2']))    { $a->address2      = $data['address2']; }
        if (isset($data['postcode']))    { $a->postcode      = $data['postcode']; }
        if (isset($data['city']))        { $a->city          = $data['city']; }
        if (isset($data['phone']))       { $a->phone         = $data['phone']; }
        if (isset($data['phoneMobile'])) { $a->phone_mobile  = $data['phoneMobile']; }
        if (isset($data['company']))     { $a->company       = $data['company']; }
        if (isset($data['vatNumber']))   { $a->vat_number    = $data['vatNumber']; }
        if (isset($data['other']))       { $a->other         = $data['other']; }
        if (isset($data['active']))      { $a->active        = (bool) $data['active']; }

        if (!$a->save()) {
            throw new \RuntimeException('Failed to update address.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $a = new \Address($id);
        if (!\Validate::isLoadedObject($a) || $a->deleted) {
            throw new ResourceNotFoundException('Address', $id);
        }
        $a->deleted = 1;
        if (!$a->save()) {
            throw new \RuntimeException('Failed to delete address.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['addressIds'] ?? []);
        foreach ($ids as $id) {
            $a = new \Address($id);
            if (\Validate::isLoadedObject($a) && !$a->deleted) {
                $a->deleted = 1;
                $a->save();
            }
        }
    }

    private function mapRow(\Address $a): array
    {
        return $this->mapFromRow([
            'id_address'   => $a->id,
            'id_customer'  => $a->id_customer,
            'id_country'   => $a->id_country,
            'id_state'     => $a->id_state,
            'alias'        => $a->alias,
            'company'      => $a->company,
            'lastname'     => $a->lastname,
            'firstname'    => $a->firstname,
            'vat_number'   => $a->vat_number,
            'address1'     => $a->address1,
            'address2'     => $a->address2,
            'postcode'     => $a->postcode,
            'city'         => $a->city,
            'phone'        => $a->phone,
            'phone_mobile' => $a->phone_mobile,
            'other'        => $a->other,
            'active'       => $a->active,
            'date_add'     => $a->date_add,
            'date_upd'     => $a->date_upd,
        ]);
    }

    private function mapFromRow(array $row): array
    {
        return [
            'addressId'   => (int) $row['id_address'],
            'idCustomer'  => (int) ($row['id_customer'] ?? 0),
            'idCountry'   => (int) ($row['id_country'] ?? 0),
            'idState'     => (int) ($row['id_state'] ?? 0),
            'alias'       => $row['alias'] ?? '',
            'company'     => $row['company'] ?? '',
            'lastname'    => $row['lastname'] ?? '',
            'firstname'   => $row['firstname'] ?? '',
            'vatNumber'   => $row['vat_number'] ?? '',
            'address1'    => $row['address1'] ?? '',
            'address2'    => $row['address2'] ?? '',
            'postcode'    => $row['postcode'] ?? '',
            'city'        => $row['city'] ?? '',
            'phone'       => $row['phone'] ?? '',
            'phoneMobile' => $row['phone_mobile'] ?? '',
            'other'       => $row['other'] ?? '',
            'active'      => (bool) ($row['active'] ?? true),
            'dateAdd'     => $row['date_add'] ?? '',
            'dateUpd'     => $row['date_upd'] ?? '',
        ];
    }
}
