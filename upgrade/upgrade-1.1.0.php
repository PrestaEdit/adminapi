<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.1.0 — adds the "API Documentation" back-office tab (Swagger UI page).
 *
 * @param Adminapi $module
 */
function upgrade_module_1_1_0($module): bool
{
    $parentId = (int) Tab::getIdFromClassName('AdminParentStats') ?: -1;

    return $module->addTab('AdminAdminapiDoc', 'API Documentation', $parentId);
}
