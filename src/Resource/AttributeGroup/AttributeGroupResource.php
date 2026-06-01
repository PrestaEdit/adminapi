<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\AttributeGroup;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class AttributeGroupResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/attribute-groups',
            'identifierKey'     => 'attributeGroupId',
            'operations'        => [
                'get'        => ['scope' => 'attribute_group_read',  'method' => 'GET'],
                'list'       => ['scope' => 'attribute_group_read',  'method' => 'GET'],
                'create'     => ['scope' => 'attribute_group_write', 'method' => 'POST'],
                'update'     => ['scope' => 'attribute_group_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'attribute_group_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'attribute_group_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $ag = new \AttributeGroup($id, $context['langId']);
        if (!\Validate::isLoadedObject($ag)) {
            throw new ResourceNotFoundException('AttributeGroup', $id);
        }
        return $this->map($ag);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('ag.id_attribute_group');
        $q->from('attribute_group', 'ag');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'ag.id_attribute_group', [
            'attributeGroupId' => 'ag.id_attribute_group',
            'position'         => 'ag.position',
        ]);
        $this->applyPagination($q, $filters, 'id_attribute_group');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_attribute_group'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['names']);

        $ag               = new \AttributeGroup();
        $ag->name         = $this->buildPsLocalizedField($data['names']);
        $ag->public_name  = $this->buildPsLocalizedField($data['publicNames'] ?? $data['names']);
        $ag->group_type   = in_array($data['groupType'] ?? '', ['select', 'radio', 'color'], true)
            ? $data['groupType']
            : 'select';
        $ag->position       = (int) ($data['position'] ?? 0);
        $ag->is_color_group = (bool) ($data['isColorGroup'] ?? false);

        if (!$ag->save()) {
            throw new \RuntimeException('Failed to create attribute group.', 500);
        }
        return $this->get((int) $ag->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $ag = new \AttributeGroup($id, $context['langId']);
        if (!\Validate::isLoadedObject($ag)) {
            throw new ResourceNotFoundException('AttributeGroup', $id);
        }
        if (isset($data['names']))       { $ag->name           = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['publicNames'])) { $ag->public_name    = $this->buildPsLocalizedField($data['publicNames']); }
        if (isset($data['groupType']) && in_array($data['groupType'], ['select', 'radio', 'color'], true)) {
            $ag->group_type = $data['groupType'];
        }
        if (isset($data['position']))     { $ag->position      = (int) $data['position']; }
        if (isset($data['isColorGroup'])) { $ag->is_color_group = (bool) $data['isColorGroup']; }

        if (!$ag->save()) {
            throw new \RuntimeException('Failed to update attribute group.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $ag = new \AttributeGroup($id);
        if (!\Validate::isLoadedObject($ag)) {
            throw new ResourceNotFoundException('AttributeGroup', $id);
        }
        if (!$ag->delete()) {
            throw new \RuntimeException('Failed to delete attribute group.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['attributeGroupIds'] ?? []);
        foreach ($ids as $id) {
            $ag = new \AttributeGroup($id);
            if (\Validate::isLoadedObject($ag)) {
                $ag->delete();
            }
        }
    }

    private function map(\AttributeGroup $ag): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name`, `public_name`
             FROM `' . _DB_PREFIX_ . 'attribute_group_lang`
             WHERE `id_attribute_group` = ' . (int) $ag->id
        );
        $names       = array_column($rows ?: [], 'name',        'id_lang');
        $publicNames = array_column($rows ?: [], 'public_name', 'id_lang');

        return [
            'attributeGroupId' => (int) $ag->id,
            'groupType'        => $ag->group_type,
            'position'         => (int) $ag->position,
            'isColorGroup'     => (bool) $ag->is_color_group,
            'names'            => $this->getLocalizedField($names),
            'publicNames'      => $this->getLocalizedField($publicNames),
        ];
    }
}
