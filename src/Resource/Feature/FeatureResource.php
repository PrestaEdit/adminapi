<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Feature;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class FeatureResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/features',
            'identifierKey'     => 'featureId',
            'operations'        => [
                'get'        => ['scope' => 'feature_read',  'method' => 'GET'],
                'list'       => ['scope' => 'feature_read',  'method' => 'GET'],
                'create'     => ['scope' => 'feature_write', 'method' => 'POST'],
                'update'     => ['scope' => 'feature_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'feature_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'feature_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $feature = new \Feature($id, $context['langId']);
        if (!\Validate::isLoadedObject($feature)) {
            throw new ResourceNotFoundException('Feature', $id);
        }
        return $this->map($feature);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('f.id_feature');
        $q->from('feature', 'f');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'f.id_feature', [
            'featureId' => 'f.id_feature',
            'position'  => 'f.position',
        ]);
        $this->applyPagination($q, $filters, 'id_feature');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_feature'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $feature           = new \Feature();
        $feature->name     = $this->buildPsLocalizedField($data['names']);
        $feature->position = (int) ($data['position'] ?? 0);

        if (!$feature->save()) {
            throw new \RuntimeException('Failed to create feature.', 500);
        }
        return $this->get((int) $feature->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $feature = new \Feature($id, $context['langId']);
        if (!\Validate::isLoadedObject($feature)) {
            throw new ResourceNotFoundException('Feature', $id);
        }
        if (isset($data['names']))    { $feature->name     = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['position'])) { $feature->position = (int) $data['position']; }

        if (!$feature->save()) {
            throw new \RuntimeException('Failed to update feature.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $feature = new \Feature($id);
        if (!\Validate::isLoadedObject($feature)) {
            throw new ResourceNotFoundException('Feature', $id);
        }
        if (!$feature->delete()) {
            throw new \RuntimeException('Failed to delete feature.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['featureIds'] ?? []);
        foreach ($ids as $id) {
            $feature = new \Feature($id);
            if (\Validate::isLoadedObject($feature)) {
                $feature->delete();
            }
        }
    }

    private function map(\Feature $feature): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'feature_lang`
             WHERE `id_feature` = ' . (int) $feature->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'featureId' => (int) $feature->id,
            'position'  => (int) $feature->position,
            'names'     => $this->getLocalizedField($names),
        ];
    }
}
