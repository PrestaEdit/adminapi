<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Contact;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ContactResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'   => '/contacts',
            'identifierKey' => 'contactId',
            'operations'    => [
                'get'        => ['scope' => 'contact_read',  'method' => 'GET'],
                'list'       => ['scope' => 'contact_read',  'method' => 'GET'],
                'create'     => ['scope' => 'contact_write', 'method' => 'POST'],
                'update'     => ['scope' => 'contact_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'contact_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'contact_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $contact = new \Contact($id, $context['langId']);
        if (!\Validate::isLoadedObject($contact)) {
            throw new ResourceNotFoundException('Contact', $id);
        }
        return $this->map($contact, $context['langId']);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_contact, c.email, c.customer_service');
        $q->from('contact', 'c');

        $total = $this->countQuery($q);

        $this->applySort($q, $filters, 'c.id_contact', [
            'contactId'       => 'c.id_contact',
            'email'           => 'c.email',
            'customerService' => 'c.customer_service',
        ]);
        $this->applyPagination($q, $filters, 'id_contact');

        $rows   = \Db::getInstance()->executeS($q);
        $langId = $context['langId'];

        $items = array_map(function (array $row) use ($langId): array {
            $contact = new \Contact((int) $row['id_contact'], $langId);
            return $this->map($contact, $langId);
        }, $rows ?: []);

        return $this->paginatedResponse($items, $total, $filters);
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $contact = new \Contact();
        $contact->name = $this->buildPsLocalizedField($data['names']);

        if (isset($data['email'])) {
            $contact->email = $data['email'];
        }
        if (isset($data['customerService'])) {
            $contact->customer_service = (int) $data['customerService'];
        }
        if (isset($data['descriptions'])) {
            $contact->description = $this->buildPsLocalizedField($data['descriptions']);
        }

        if (!$contact->save()) {
            throw new \RuntimeException('Failed to create contact.', 500);
        }

        return $this->get($contact->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $contact = new \Contact($id, $context['langId']);
        if (!\Validate::isLoadedObject($contact)) {
            throw new ResourceNotFoundException('Contact', $id);
        }

        if (isset($data['names'])) {
            $contact->name = $this->buildPsLocalizedField($data['names']);
        }
        if (isset($data['email'])) {
            $contact->email = $data['email'];
        }
        if (isset($data['customerService'])) {
            $contact->customer_service = (int) $data['customerService'];
        }
        if (isset($data['descriptions'])) {
            $contact->description = $this->buildPsLocalizedField($data['descriptions']);
        }

        if (!$contact->save()) {
            throw new \RuntimeException('Failed to update contact.', 500);
        }

        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $contact = new \Contact($id);
        if (!\Validate::isLoadedObject($contact)) {
            throw new ResourceNotFoundException('Contact', $id);
        }
        if (!$contact->delete()) {
            throw new \RuntimeException('Failed to delete contact.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['contactIds'] ?? []);
        foreach ($ids as $id) {
            $contact = new \Contact($id);
            if (\Validate::isLoadedObject($contact)) {
                $contact->delete();
            }
        }
    }

    private function map(\Contact $contact, int $langId): array
    {
        $names = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'contact_lang`
             WHERE `id_contact` = ' . (int) $contact->id
        );
        $descriptions = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `description` FROM `' . _DB_PREFIX_ . 'contact_lang`
             WHERE `id_contact` = ' . (int) $contact->id
        );

        $namesArray = array_column($names ?: [], 'name', 'id_lang');
        $descsArray = array_column($descriptions ?: [], 'description', 'id_lang');

        return [
            'contactId'       => (int) $contact->id,
            'names'           => $this->getLocalizedField($namesArray),
            'email'           => $contact->email,
            'customerService' => (bool) $contact->customer_service,
            'descriptions'    => $this->getLocalizedField($descsArray),
        ];
    }
}
