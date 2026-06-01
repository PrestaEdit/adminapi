<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Resource\StockAvailable;

use PrestaEdit\ApiModule\Exception\ResourceNotFoundException;
use PrestaEdit\ApiModule\Resource\AbstractResource;
use PrestaEdit\ApiModule\Resource\ResourceInterface;

class StockAvailableResource extends AbstractResource implements ResourceInterface
{
    public static function definition(): array
    {
        return [
            'uriTemplate'       => '/stock-availables',
            'identifierKey'     => 'stockAvailableId',
            'operations'        => [
                'get'    => ['scope' => 'product_read',  'method' => 'GET'],
                'list'   => ['scope' => 'product_read',  'method' => 'GET'],
                'update' => ['scope' => 'product_write', 'method' => 'PATCH'],
                // create and delete managed automatically by PrestaShop
            ],
            'exceptionToStatus' => [],
        ];
    }

    public function get(int $id, array $context): array
    {
        $sa = new \StockAvailable($id);
        if (!\Validate::isLoadedObject($sa)) {
            throw new ResourceNotFoundException('StockAvailable', $id);
        }
        return $this->mapRow($sa);
    }

    public function list(array $filters, array $context): array
    {
        $q = new \DbQuery();
        $q->select('sa.id_stock_available');
        $q->from('stock_available', 'sa');

        if (isset($filters['productId'])) {
            $q->where('sa.id_product = ' . (int) $filters['productId']);
        }

        $total = $this->countQuery($q);
        $this->applySort($q, $filters, 'sa.id_stock_available', [
            'stockAvailableId'    => 'sa.id_stock_available',
            'idProduct'           => 'sa.id_product',
            'idProductAttribute'  => 'sa.id_product_attribute',
        ]);
        $this->applyPagination($q, $filters, 'id_stock_available');

        $rows = \Db::getInstance()->executeS($q) ?: [];
        return $this->paginatedResponse(
            array_map(function (array $r) use ($context): array {
                return $this->get((int) $r['id_stock_available'], $context);
            }, $rows),
            $total,
            $filters
        );
    }

    public function create(array $data, array $context): array
    {
        throw new \RuntimeException('StockAvailable records are managed automatically by PrestaShop.', 405);
    }

    public function update(int $id, array $data, array $context): array
    {
        $sa = new \StockAvailable($id);
        if (!\Validate::isLoadedObject($sa)) {
            throw new ResourceNotFoundException('StockAvailable', $id);
        }

        if (isset($data['quantity'])) {
            \StockAvailable::setQuantity(
                (int) $sa->id_product,
                (int) $sa->id_product_attribute,
                (int) $data['quantity'],
                (int) $sa->id_shop
            );
            // Reload so subsequent save() does not overwrite the new quantity with stale data
            $sa = new \StockAvailable($id);
        }

        $dirty = false;
        if (isset($data['outOfStock'])) {
            $sa->out_of_stock = (int) $data['outOfStock'];
            $dirty = true;
        }
        if (isset($data['location'])) {
            $sa->location = $data['location'];
            $dirty = true;
        }
        if ($dirty) {
            $sa->save();
        }

        return $this->get($id, $context);
    }

    public function delete(int $id, array $context): void
    {
        throw new \RuntimeException('StockAvailable records are managed automatically by PrestaShop.', 405);
    }

    private function mapRow(\StockAvailable $sa): array
    {
        return [
            'stockAvailableId'   => (int) $sa->id,
            'idProduct'          => (int) $sa->id_product,
            'idProductAttribute' => (int) $sa->id_product_attribute,
            'idShop'             => (int) $sa->id_shop,
            'idShopGroup'        => (int) $sa->id_shop_group,
            'quantity'           => (int) $sa->quantity,
            'dependsOnStock'     => (bool) $sa->depends_on_stock,
            'outOfStock'         => (int) $sa->out_of_stock,
            'location'           => $sa->location ?? '',
        ];
    }
}
