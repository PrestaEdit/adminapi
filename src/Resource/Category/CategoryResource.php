<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Category;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class CategoryResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/categories',
            'identifierKey'     => 'categoryId',
            'operations'        => [
                'get'        => ['scope' => 'category_read',  'method' => 'GET'],
                'list'       => ['scope' => 'category_read',  'method' => 'GET'],
                'create'     => ['scope' => 'category_write', 'method' => 'POST'],
                'update'     => ['scope' => 'category_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'category_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'category_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $category = new \Category($id, $context['langId']);
        if (!\Validate::isLoadedObject($category)) {
            throw new ResourceNotFoundException('Category', $id);
        }
        return $this->map($category);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('c.id_category');
        $q->from('category', 'c');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'c.id_category', [
            'categoryId' => 'c.id_category',
            'position'   => 'c.position',
            'idParent'   => 'c.id_parent',
        ]);
        $this->applyPagination($q, $filters, 'id_category');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_category'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['idParent', 'names', 'linkRewrites']);

        $category               = new \Category();
        $category->id_parent    = (int) $data['idParent'];
        $category->name         = $this->buildPsLocalizedField($data['names']);
        $category->link_rewrite = $this->buildPsLocalizedField($data['linkRewrites']);
        $category->active       = (bool) ($data['active'] ?? true);
        $category->position     = (int) ($data['position'] ?? 0);

        if (isset($data['descriptions']))     { $category->description     = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['metaTitles']))       { $category->meta_title      = $this->buildPsLocalizedField($data['metaTitles']); }
        if (isset($data['metaDescriptions'])) { $category->meta_description = $this->buildPsLocalizedField($data['metaDescriptions']); }
        if (isset($data['metaKeywords']))     { $category->meta_keywords   = $this->buildPsLocalizedField($data['metaKeywords']); }

        if (!$category->save()) {
            throw new \RuntimeException('Failed to create category.', 500);
        }
        return $this->get((int) $category->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $category = new \Category($id, $context['langId']);
        if (!\Validate::isLoadedObject($category)) {
            throw new ResourceNotFoundException('Category', $id);
        }
        if (isset($data['idParent']))         { $category->id_parent        = (int) $data['idParent']; }
        if (isset($data['names']))            { $category->name             = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['linkRewrites']))     { $category->link_rewrite     = $this->buildPsLocalizedField($data['linkRewrites']); }
        if (isset($data['active']))           { $category->active           = (bool) $data['active']; }
        if (isset($data['position']))         { $category->position         = (int) $data['position']; }
        if (isset($data['descriptions']))     { $category->description      = $this->buildPsLocalizedField($data['descriptions']); }
        if (isset($data['metaTitles']))       { $category->meta_title       = $this->buildPsLocalizedField($data['metaTitles']); }
        if (isset($data['metaDescriptions'])) { $category->meta_description  = $this->buildPsLocalizedField($data['metaDescriptions']); }
        if (isset($data['metaKeywords']))     { $category->meta_keywords    = $this->buildPsLocalizedField($data['metaKeywords']); }

        if (!$category->save()) {
            throw new \RuntimeException('Failed to update category.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $category = new \Category($id);
        if (!\Validate::isLoadedObject($category) || $category->is_root_category) {
            throw new ResourceNotFoundException('Category', $id);
        }
        if (!$category->delete()) {
            throw new \RuntimeException('Failed to delete category.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['categoryIds'] ?? []);
        foreach ($ids as $id) {
            $category = new \Category($id);
            if (\Validate::isLoadedObject($category) && !$category->is_root_category) {
                $category->delete();
            }
        }
    }

    private function map(\Category $category): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name`, `description`, `meta_title`, `meta_keywords`,
                    `meta_description`, `link_rewrite`
             FROM `' . _DB_PREFIX_ . 'category_lang`
             WHERE `id_category` = ' . (int) $category->id
        );

        $names        = array_column($rows ?: [], 'name',             'id_lang');
        $descs        = array_column($rows ?: [], 'description',      'id_lang');
        $metaTitles   = array_column($rows ?: [], 'meta_title',       'id_lang');
        $metaDescs    = array_column($rows ?: [], 'meta_description',  'id_lang');
        $metaKeys     = array_column($rows ?: [], 'meta_keywords',    'id_lang');
        $linkRewrites = array_column($rows ?: [], 'link_rewrite',     'id_lang');

        return [
            'categoryId'       => (int) $category->id,
            'idParent'         => (int) $category->id_parent,
            'position'         => (int) $category->position,
            'active'           => (bool) $category->active,
            'levelDepth'       => (int) $category->level_depth,
            'isRootCategory'   => (bool) $category->is_root_category,
            'names'            => $this->getLocalizedField($names),
            'descriptions'     => $this->getLocalizedField($descs),
            'metaTitles'       => $this->getLocalizedField($metaTitles),
            'metaDescriptions' => $this->getLocalizedField($metaDescs),
            'metaKeywords'     => $this->getLocalizedField($metaKeys),
            'linkRewrites'     => $this->getLocalizedField($linkRewrites),
        ];
    }
}
