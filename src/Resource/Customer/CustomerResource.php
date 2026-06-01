<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Customer;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CustomerResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/customers',
            'identifierKey'     => 'customerId',
            'operations'        => [
                'get'        => ['scope' => 'customer_read',  'method' => 'GET'],
                'list'       => ['scope' => 'customer_read',  'method' => 'GET'],
                'create'     => ['scope' => 'customer_write', 'method' => 'POST'],
                'update'     => ['scope' => 'customer_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'customer_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'customer_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $customer = new \Customer($id);
        if (!\Validate::isLoadedObject($customer) || $customer->deleted) {
            throw new ResourceNotFoundException('Customer', $id);
        }
        return $this->mapRow($customer);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_customer, c.id_gender, c.id_default_group, c.firstname, c.lastname,
                    c.email, c.birthday, c.newsletter, c.optin, c.website, c.company,
                    c.active, c.is_guest, c.date_add, c.date_upd');
        $q->from('customer', 'c');
        $q->where('c.deleted = 0');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'c.id_customer', [
            'customerId' => 'c.id_customer',
            'lastname'   => 'c.lastname',
            'email'      => 'c.email',
        ]);
        $this->applyPagination($q, $filters, 'id_customer');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r): array { return $this->mapFromRow($r); }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['email', 'firstname', 'lastname', 'password']);

        $customer                   = new \Customer();
        $customer->email            = $data['email'];
        $customer->firstname        = $data['firstname'];
        $customer->lastname         = $data['lastname'];
        $customer->passwd           = \Tools::encrypt($data['password']);
        $customer->id_gender        = (int) ($data['idGender'] ?? 0);
        $customer->id_default_group = (int) ($data['idDefaultGroup'] ?? (int) \Configuration::get('PS_CUSTOMER_GROUP'));
        $customer->birthday         = $data['birthday'] ?? '0000-00-00';
        $customer->newsletter       = (bool) ($data['newsletter'] ?? false);
        $customer->optin            = (bool) ($data['optin'] ?? false);
        $customer->website          = $data['website'] ?? '';
        $customer->company          = $data['company'] ?? '';
        $customer->active           = (bool) ($data['active'] ?? true);
        $customer->is_guest         = 0;

        if (!$customer->save()) {
            throw new \RuntimeException('Failed to create customer.', 500);
        }
        return $this->get((int) $customer->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $customer = new \Customer($id);
        if (!\Validate::isLoadedObject($customer) || $customer->deleted) {
            throw new ResourceNotFoundException('Customer', $id);
        }
        if (isset($data['email']))           { $customer->email            = $data['email']; }
        if (isset($data['firstname']))       { $customer->firstname        = $data['firstname']; }
        if (isset($data['lastname']))        { $customer->lastname         = $data['lastname']; }
        if (isset($data['password']))        { $customer->passwd           = \Tools::encrypt($data['password']); }
        if (isset($data['idGender']))        { $customer->id_gender        = (int) $data['idGender']; }
        if (isset($data['idDefaultGroup']))  { $customer->id_default_group = (int) $data['idDefaultGroup']; }
        if (isset($data['birthday']))        { $customer->birthday         = $data['birthday']; }
        if (isset($data['newsletter']))      { $customer->newsletter       = (bool) $data['newsletter']; }
        if (isset($data['optin']))           { $customer->optin            = (bool) $data['optin']; }
        if (isset($data['website']))         { $customer->website          = $data['website']; }
        if (isset($data['company']))         { $customer->company          = $data['company']; }
        if (isset($data['active']))          { $customer->active           = (bool) $data['active']; }

        if (!$customer->save()) {
            throw new \RuntimeException('Failed to update customer.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $customer = new \Customer($id);
        if (!\Validate::isLoadedObject($customer) || $customer->deleted) {
            throw new ResourceNotFoundException('Customer', $id);
        }
        if (!$customer->delete()) {
            throw new \RuntimeException('Failed to delete customer.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['customerIds'] ?? []);
        foreach ($ids as $id) {
            $customer = new \Customer($id);
            if (\Validate::isLoadedObject($customer) && !$customer->deleted) {
                $customer->delete();
            }
        }
    }

    private function mapRow(\Customer $c): array
    {
        return $this->mapFromRow([
            'id_customer'      => $c->id,
            'id_gender'        => $c->id_gender,
            'id_default_group' => $c->id_default_group,
            'firstname'        => $c->firstname,
            'lastname'         => $c->lastname,
            'email'            => $c->email,
            'birthday'         => $c->birthday,
            'newsletter'       => $c->newsletter,
            'optin'            => $c->optin,
            'website'          => $c->website,
            'company'          => $c->company,
            'active'           => $c->active,
            'is_guest'         => $c->is_guest,
            'date_add'         => $c->date_add,
            'date_upd'         => $c->date_upd,
        ]);
    }

    private function mapFromRow(array $row): array
    {
        // passwd is intentionally excluded from all responses
        return [
            'customerId'      => (int) $row['id_customer'],
            'idGender'        => (int) ($row['id_gender'] ?? 0),
            'idDefaultGroup'  => (int) ($row['id_default_group'] ?? 0),
            'firstname'       => $row['firstname'] ?? '',
            'lastname'        => $row['lastname'] ?? '',
            'email'           => $row['email'] ?? '',
            'birthday'        => $row['birthday'] ?? '',
            'newsletter'      => (bool) ($row['newsletter'] ?? false),
            'optin'           => (bool) ($row['optin'] ?? false),
            'website'         => $row['website'] ?? '',
            'company'         => $row['company'] ?? '',
            'active'          => (bool) ($row['active'] ?? true),
            'isGuest'         => (bool) ($row['is_guest'] ?? false),
            'dateAdd'         => $row['date_add'] ?? '',
            'dateUpd'         => $row['date_upd'] ?? '',
        ];
    }
}
