<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\ProductImage;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class ProductImageResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/product-images',
            'identifierKey'     => 'imageId',
            'operations'        => [
                'get'    => ['scope' => 'product_read',  'method' => 'GET'],
                'list'   => ['scope' => 'product_read',  'method' => 'GET'],
                'update' => ['scope' => 'product_write', 'method' => 'PATCH'],
                'delete' => ['scope' => 'product_write', 'method' => 'DELETE'],
                // create excluded — binary upload is not handled by the JSON dispatcher
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $image = new \Image($id);
        if (!\Validate::isLoadedObject($image)) {
            throw new ResourceNotFoundException('ProductImage', $id);
        }
        return $this->map($image);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('i.id_image');
        $q->from('image', 'i');

        if (isset($filters['productId'])) {
            $q->where('i.id_product = ' . (int) $filters['productId']);
        }

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'i.id_image', [
            'imageId'   => 'i.id_image',
            'idProduct' => 'i.id_product',
            'position'  => 'i.position',
        ]);
        $this->applyPagination($q, $filters, 'id_image');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_image'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        throw new \RuntimeException('Image upload is not supported via the JSON API.', 405);
    }

    public function update(int $id, array $data, array $context): array
    {
        $image = new \Image($id);
        if (!\Validate::isLoadedObject($image)) {
            throw new ResourceNotFoundException('ProductImage', $id);
        }

        if (isset($data['position'])) {
            $image->position = (int) $data['position'];
        }
        if (isset($data['legends'])) {
            $image->legend = $this->buildPsLocalizedField($data['legends']);
        }
        if (isset($data['cover']) && (bool) $data['cover'] === true) {
            \Image::deleteCover((int) $image->id_product);
            $image->cover = 1;
        } elseif (isset($data['cover'])) {
            $image->cover = 0;
        }

        if (!$image->save()) {
            throw new \RuntimeException('Failed to update product image.', 500);
        }
        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        $image = new \Image($id);
        if (!\Validate::isLoadedObject($image)) {
            throw new ResourceNotFoundException('ProductImage', $id);
        }
        if (!$image->delete()) {
            throw new \RuntimeException('Failed to delete product image.', 500);
        }
    }

    public function bulkDelete(array $data, array $context): void
    {
        $ids = array_map('intval', $data['imageIds'] ?? []);
        foreach ($ids as $id) {
            $image = new \Image($id);
            if (\Validate::isLoadedObject($image)) {
                $image->delete();
            }
        }
    }

    private function map(\Image $image): array
    {
        $legendRows = \Db::getInstance()->executeS(
            'SELECT `id_lang`, `legend` FROM `' . _DB_PREFIX_ . 'image_lang`
             WHERE `id_image` = ' . (int) $image->id
        );
        $legends = array_column($legendRows ?: [], 'legend', 'id_lang');

        return [
            'imageId'   => (int) $image->id,
            'idProduct' => (int) $image->id_product,
            'position'  => (int) $image->position,
            'cover'     => (bool) $image->cover,
            'legends'   => $this->getLocalizedField($legends),
        ];
    }
}
