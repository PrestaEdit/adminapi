<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\FeatureValue;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class FeatureValueResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/feature-values',
            'identifierKey'     => 'featureValueId',
            'operations'        => [
                'get'        => ['scope' => 'feature_value_read',  'method' => 'GET'],
                'list'       => ['scope' => 'feature_value_read',  'method' => 'GET'],
                'create'     => ['scope' => 'feature_value_write', 'method' => 'POST'],
                'update'     => ['scope' => 'feature_value_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'feature_value_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'feature_value_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $fv = new \FeatureValue($id, $context['langId']);
        if (!\Validate::isLoadedObject($fv)) {
            throw new ResourceNotFoundException('FeatureValue', $id);
        }
        return $this->map($fv);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('fv.id_feature_value');
        $q->from('feature_value', 'fv');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'fv.id_feature_value', [
            'featureValueId' => 'fv.id_feature_value',
            'idFeature'      => 'fv.id_feature',
        ]);
        $this->applyPagination($q, $filters, 'id_feature_value');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_feature_value'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idFeature', 'values']);

        $fv             = new \FeatureValue();
        $fv->id_feature = (int) $data['idFeature'];
        $fv->value      = $this->buildPsLocalizedField($data['values']);
        $fv->custom     = (bool) ($data['custom'] ?? false);

        if (!$fv->save()) {
            throw new \RuntimeException('Failed to create feature value.', 500);
        }
        return $this->get((int) $fv->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $fv = new \FeatureValue($id, $context['langId']);
        if (!\Validate::isLoadedObject($fv)) {
            throw new ResourceNotFoundException('FeatureValue', $id);
        }
        if (isset($data['idFeature'])) { $fv->id_feature = (int) $data['idFeature']; }
        if (isset($data['values']))    { $fv->value      = $this->buildPsLocalizedField($data['values']); }
        if (isset($data['custom']))    { $fv->custom     = (bool) $data['custom']; }

        if (!$fv->save()) {
            throw new \RuntimeException('Failed to update feature value.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $fv = new \FeatureValue($id);
        if (!\Validate::isLoadedObject($fv)) {
            throw new ResourceNotFoundException('FeatureValue', $id);
        }
        if (!$fv->delete()) {
            throw new \RuntimeException('Failed to delete feature value.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['featureValueIds'] ?? []);
        foreach ($ids as $id) {
            $fv = new \FeatureValue($id);
            if (\Validate::isLoadedObject($fv)) {
                $fv->delete();
            }
        }
    }

    private function map(\FeatureValue $fv): array
    {
        $valueRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `value` FROM `' . _DB_PREFIX_ . 'feature_value_lang`
             WHERE `id_feature_value` = ' . (int) $fv->id
        );
        $values = array_column($valueRows ?: [], 'value', 'id_lang');

        return [
            'featureValueId' => (int) $fv->id,
            'idFeature'      => (int) $fv->id_feature,
            'custom'         => (bool) $fv->custom,
            'values'         => $this->getLocalizedField($values),
        ];
    }
}
