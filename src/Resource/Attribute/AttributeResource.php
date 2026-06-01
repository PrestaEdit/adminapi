<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Attribute;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class AttributeResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/attributes',
            'identifierKey'     => 'attributeId',
            'operations'        => [
                'get'        => ['scope' => 'attribute_read',  'method' => 'GET'],
                'list'       => ['scope' => 'attribute_read',  'method' => 'GET'],
                'create'     => ['scope' => 'attribute_write', 'method' => 'POST'],
                'update'     => ['scope' => 'attribute_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'attribute_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'attribute_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $attr = new \Attribute($id, $context['langId']);
        if (!\Validate::isLoadedObject($attr)) {
            throw new ResourceNotFoundException('Attribute', $id);
        }
        return $this->map($attr);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('a.id_attribute');
        $q->from('attribute', 'a');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'a.id_attribute', [
            'attributeId'      => 'a.id_attribute',
            'idAttributeGroup' => 'a.id_attribute_group',
            'position'         => 'a.position',
        ]);
        $this->applyPagination($q, $filters, 'id_attribute');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_attribute'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idAttributeGroup', 'names']);

        $attr                     = new \Attribute();
        $attr->id_attribute_group = (int) $data['idAttributeGroup'];
        $attr->name               = $this->buildPsLocalizedField($data['names']);
        $attr->color              = $data['color'] ?? '';
        $attr->position           = (int) ($data['position'] ?? 0);

        if (!$attr->save()) {
            throw new \RuntimeException('Failed to create attribute.', 500);
        }
        return $this->get((int) $attr->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $attr = new \Attribute($id, $context['langId']);
        if (!\Validate::isLoadedObject($attr)) {
            throw new ResourceNotFoundException('Attribute', $id);
        }
        if (isset($data['idAttributeGroup'])) { $attr->id_attribute_group = (int) $data['idAttributeGroup']; }
        if (isset($data['names']))            { $attr->name               = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['color']))            { $attr->color              = $data['color']; }
        if (isset($data['position']))         { $attr->position           = (int) $data['position']; }

        if (!$attr->save()) {
            throw new \RuntimeException('Failed to update attribute.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $attr = new \Attribute($id);
        if (!\Validate::isLoadedObject($attr)) {
            throw new ResourceNotFoundException('Attribute', $id);
        }
        if (!$attr->delete()) {
            throw new \RuntimeException('Failed to delete attribute.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['attributeIds'] ?? []);
        foreach ($ids as $id) {
            $attr = new \Attribute($id);
            if (\Validate::isLoadedObject($attr)) {
                $attr->delete();
            }
        }
    }

    private function map(\Attribute $attr): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'attribute_lang`
             WHERE `id_attribute` = ' . (int) $attr->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'attributeId'      => (int) $attr->id,
            'idAttributeGroup' => (int) $attr->id_attribute_group,
            'color'            => $attr->color ?? '',
            'position'         => (int) $attr->position,
            'names'            => $this->getLocalizedField($names),
        ];
    }
}
