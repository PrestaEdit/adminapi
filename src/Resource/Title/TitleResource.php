<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\Title;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class TitleResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/titles',
            'identifierKey'     => 'titleId',
            'operations'        => [
                'get'        => ['scope' => 'title_read',  'method' => 'GET'],
                'list'       => ['scope' => 'title_read',  'method' => 'GET'],
                'create'     => ['scope' => 'title_write', 'method' => 'POST'],
                'update'     => ['scope' => 'title_write', 'method' => 'PATCH'],
                'delete'     => ['scope' => 'title_write', 'method' => 'DELETE'],
                'bulkDelete' => [
                    'scope'     => 'title_write',
                    'method'    => 'DELETE',
                    'uriSuffix' => '/bulk-delete',
                ],
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $gender = new \Gender($id, $context['langId']);
        if (!\Validate::isLoadedObject($gender)) {
            throw new ResourceNotFoundException('Title', $id);
        }
        return $this->map($gender);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('id_gender');
        $q->from('gender');

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'id_gender', ['titleId' => 'id_gender']);
        $this->applyPagination($q, $filters, 'id_gender');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_gender'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        $this->requireFields($data, ['type', 'names']);

        $gender       = new \Gender();
        $gender->type = (int) $data['type'];
        $gender->name = $this->buildPsLocalizedField($data['names']);

        if (!$gender->save()) {
            throw new \RuntimeException('Failed to create title.', 500);
        }
        return $this->get((int) $gender->id, $context);
    }

    public function update(int $id, array $data, array $context): array
    {
        $gender = new \Gender($id, $context['langId']);
        if (!\Validate::isLoadedObject($gender)) {
            throw new ResourceNotFoundException('Title', $id);
        }
        if (isset($data['type']))  { $gender->type = (int) $data['type']; }
        if (isset($data['names'])) { $gender->name = $this->buildPsLocalizedField($data['names']); }

        if (!$gender->save()) {
            throw new \RuntimeException('Failed to update title.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $gender = new \Gender($id);
        if (!\Validate::isLoadedObject($gender)) {
            throw new ResourceNotFoundException('Title', $id);
        }
        if (!$gender->delete()) {
            throw new \RuntimeException('Failed to delete title.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['titleIds'] ?? []);
        foreach ($ids as $id) {
            $gender = new \Gender($id);
            if (\Validate::isLoadedObject($gender)) {
                $gender->delete();
            }
        }
    }

    private function map(\Gender $gender): array
    {
        $nameRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `name` FROM `' . _DB_PREFIX_ . 'gender_lang`
             WHERE `id_gender` = ' . (int) $gender->id
        );
        $names = array_column($nameRows ?: [], 'name', 'id_lang');

        return [
            'titleId' => (int) $gender->id,
            'type'    => (int) $gender->type,
            'names'   => $this->getLocalizedField($names),
        ];
    }
}
