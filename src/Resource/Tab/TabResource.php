<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Tab;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class TabResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/tabs',
            'identifierKey'     => 'tabId',
            'operations'        => [
                'get'        => ['scope' => 'tab_read',  'method' => 'GET'],
                'list'       => ['scope' => 'tab_read',  'method' => 'GET'],
                'create'     => ['scope' => 'tab_write', 'method' => 'POST'],
                'update'     => ['scope' => 'tab_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'tab_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'tab_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $tab = new \Tab($id, $context['langId']);
        if (!\Validate::isLoadedObject($tab)) {
            throw new ResourceNotFoundException('Tab', $id);
        }
        return $this->map($tab);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_tab');
        $q->from('tab');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_tab', [
            'tabId'     => 'id_tab',
            'position'  => 'position',
            'className' => 'class_name',
        ]);
        $this->applyPagination($q, $filters, 'id_tab');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_tab'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['className', 'names']);

        $tab             = new \Tab();
        $tab->class_name = $data['className'];
        $tab->name       = $this->buildPsLocalizedField($data['names']);
        $tab->id_parent  = (int) ($data['idParent'] ?? 0);
        $tab->position   = (int) ($data['position'] ?? 0);
        $tab->active     = (bool) ($data['active'] ?? true);
        $tab->module     = $data['module'] ?? '';
        $tab->icon       = $data['icon'] ?? '';

        if (!$tab->save()) {
            throw new \RuntimeException('Failed to create tab.', 500);
        }
        return $this->get((int) $tab->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $tab = new \Tab($id, $context['langId']);
        if (!\Validate::isLoadedObject($tab)) {
            throw new ResourceNotFoundException('Tab', $id);
        }
        if (isset($data['className'])) { $tab->class_name = $data['className']; }
        if (isset($data['names']))     { $tab->name       = $this->buildPsLocalizedField($data['names']); }
        if (isset($data['idParent']))  { $tab->id_parent  = (int) $data['idParent']; }
        if (isset($data['position']))  { $tab->position   = (int) $data['position']; }
        if (isset($data['active']))    { $tab->active      = (bool) $data['active']; }
        if (isset($data['icon']))      { $tab->icon        = $data['icon']; }

        if (!$tab->save()) {
            throw new \RuntimeException('Failed to update tab.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $tab = new \Tab($id);
        if (!\Validate::isLoadedObject($tab)) {
            throw new ResourceNotFoundException('Tab', $id);
        }
        if (!$tab->delete()) {
            throw new \RuntimeException('Failed to delete tab.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['tabIds'] ?? []);
        foreach ($ids as $id) {
            $tab = new \Tab($id);
            if (\Validate::isLoadedObject($tab)) {
                $tab->delete();
            }
        }
    }

    private function map(\Tab $tab): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'tab_lang`
             WHERE `id_tab` = ' . (int) $tab->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'tabId'     => (int) $tab->id,
            'className' => $tab->class_name,
            'idParent'  => (int) $tab->id_parent,
            'position'  => (int) $tab->position,
            'module'    => $tab->module ?? '',
            'active'    => (bool) $tab->active,
            'icon'      => $tab->icon ?? '',
            'names'     => $this->getLocalizedField($names),
        ];
    }
}
