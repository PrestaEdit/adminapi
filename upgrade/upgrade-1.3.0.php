<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.3.0 — collapse the two tabs (AdminAdminapiClient + AdminAdminapiDoc) into a
 * single AdminAdminAPI tab under "Advanced Parameters", matching PrestaShop's
 * own admin API naming. Swagger UI is now a secondary action of that single
 * controller.
 *
 * @param Adminapi $module
 */
function upgrade_module_1_3_0($module): bool
{
    // Remove the old tabs (and the legacy single tab if present).
    foreach (['AdminAdminapiClient', 'AdminAdminapiDoc'] as $old) {
        $id = (int) Tab::getIdFromClassName($old);
        if ($id) {
            (new Tab($id))->delete();
        }
    }

    $parentId = (int) Tab::getIdFromClassName('AdminAdvancedParameters') ?: -1;

    return $module->addTab('AdminAdminAPI', 'Admin API', $parentId);
}
