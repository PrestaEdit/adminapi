<?php
declare(strict_types=1);

namespace PrestaEdit\ApiModule\Api;

class ShopContextResolver
{
    /**
     * Resolves shop context from query params.
     * Throws \InvalidArgumentException(400) if multistore ON and no param given.
     *
     * @return array{shopId:int|null,shopGroupId:int|null,shopIds:int[]|null,allShops:bool,langId:int}
     */
    public static function fromRequest(Request $request): array
    {
        $q = $request->getQueryParams();

        $shopId      = isset($q['shopId'])      ? (int) $q['shopId']      : null;
        $shopGroupId = isset($q['shopGroupId'])  ? (int) $q['shopGroupId'] : null;
        $allShops    = array_key_exists('allShops', $q);
        $shopIds     = null;

        if (isset($q['shopIds'])) {
            $shopIds = array_map('intval', is_array($q['shopIds'])
                ? $q['shopIds']
                : explode(',', (string) $q['shopIds'])
            );
        }

        $multistoreEnabled = \Shop::isFeatureActive();

        if ($multistoreEnabled && !$shopId && !$shopGroupId && !$allShops && !$shopIds) {
            throw new \InvalidArgumentException(
                'A shop context parameter is required when multistore is enabled '
                . '(shopId, shopGroupId, shopIds or allShops).',
                400
            );
        }

        if ($shopId) {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $shopId);
        } elseif ($shopGroupId) {
            \Shop::setContext(\Shop::CONTEXT_GROUP, $shopGroupId);
        } elseif ($allShops) {
            \Shop::setContext(\Shop::CONTEXT_ALL);
        } elseif ($shopIds) {
            \Shop::setContext(\Shop::CONTEXT_SHOP, $shopIds[0]);
        } else {
            \Shop::setContext(\Shop::CONTEXT_SHOP, (int) \Configuration::get('PS_SHOP_DEFAULT'));
            $shopId = (int) \Configuration::get('PS_SHOP_DEFAULT');
        }

        $langId = isset($q['langId'])
            ? (int) $q['langId']
            : (int) \Configuration::get('PS_LANG_DEFAULT');

        return compact('shopId', 'shopGroupId', 'shopIds', 'allShops', 'langId');
    }
}
