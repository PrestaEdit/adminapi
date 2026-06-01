<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Country;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CountryResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/countries',
            'identifierKey'     => 'countryId',
            'operations'        => [
                'get'        => ['scope' => 'country_read',  'method' => 'GET'],
                'list'       => ['scope' => 'country_read',  'method' => 'GET'],
                'create'     => ['scope' => 'country_write', 'method' => 'POST'],
                'update'     => ['scope' => 'country_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'country_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'country_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $country = new \Country($id, $context['langId']);
        if (!\Validate::isLoadedObject($country)) {
            throw new ResourceNotFoundException('Country', $id);
        }
        return $this->map($country);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_country');
        $q->from('country', 'c');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'c.id_country', [
            'countryId' => 'c.id_country',
            'isoCode'   => 'c.iso_code',
        ]);
        $this->applyPagination($q, $filters, 'id_country');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_country'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['isoCode', 'idZone', 'names']);

        $country           = new \Country();
        $country->iso_code = $data['isoCode'];
        $country->id_zone  = (int) $data['idZone'];
        $country->name     = $this->buildPsLocalizedField($data['names']);
        $country->active   = (bool) ($data['active'] ?? true);

        if (isset($data['callPrefix']))               { $country->call_prefix                = (int) $data['callPrefix']; }
        if (isset($data['containsStates']))           { $country->contains_states            = (bool) $data['containsStates']; }
        if (isset($data['needIdentificationNumber'])) { $country->need_identification_number = (bool) $data['needIdentificationNumber']; }
        if (isset($data['needZipCode']))              { $country->need_zip_code              = (bool) $data['needZipCode']; }
        if (isset($data['zipCodeFormat']))            { $country->zip_code_format            = $data['zipCodeFormat']; }
        if (isset($data['displayTaxLabel']))          { $country->display_tax_label          = (bool) $data['displayTaxLabel']; }

        if (!$country->save()) {
            throw new \RuntimeException('Failed to create country.', 500);
        }
        return $this->get((int) $country->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $country = new \Country($id, $context['langId']);
        if (!\Validate::isLoadedObject($country)) {
            throw new ResourceNotFoundException('Country', $id);
        }
        if (isset($data['isoCode']))                  { $country->iso_code                   = $data['isoCode']; }
        if (isset($data['idZone']))                   { $country->id_zone                    = (int) $data['idZone']; }
        if (isset($data['names']))                    { $country->name                       = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['active']))                   { $country->active                     = (bool) $data['active']; }
        if (isset($data['callPrefix']))               { $country->call_prefix                = (int) $data['callPrefix']; }
        if (isset($data['containsStates']))           { $country->contains_states            = (bool) $data['containsStates']; }
        if (isset($data['needIdentificationNumber'])) { $country->need_identification_number = (bool) $data['needIdentificationNumber']; }
        if (isset($data['needZipCode']))              { $country->need_zip_code              = (bool) $data['needZipCode']; }
        if (isset($data['zipCodeFormat']))            { $country->zip_code_format            = $data['zipCodeFormat']; }
        if (isset($data['displayTaxLabel']))          { $country->display_tax_label          = (bool) $data['displayTaxLabel']; }

        if (!$country->save()) {
            throw new \RuntimeException('Failed to update country.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $country = new \Country($id);
        if (!\Validate::isLoadedObject($country)) {
            throw new ResourceNotFoundException('Country', $id);
        }
        if (!$country->delete()) {
            throw new \RuntimeException('Failed to delete country.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['countryIds'] ?? []);
        foreach ($ids as $id) {
            $country = new \Country($id);
            if (\Validate::isLoadedObject($country)) {
                $country->delete();
            }
        }
    }

    private function map(\Country $country): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'country_lang`
             WHERE `id_country` = ' . (int) $country->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'countryId'                => (int) $country->id,
            'isoCode'                  => $country->iso_code,
            'idZone'                   => (int) $country->id_zone,
            'callPrefix'               => (int) $country->call_prefix,
            'active'                   => (bool) $country->active,
            'containsStates'           => (bool) $country->contains_states,
            'needIdentificationNumber' => (bool) $country->need_identification_number,
            'needZipCode'              => (bool) $country->need_zip_code,
            'zipCodeFormat'            => $country->zip_code_format ?? '',
            'displayTaxLabel'          => (bool) $country->display_tax_label,
            'names'                    => $this->getLocalizedField($names),
        ];
    }
}
