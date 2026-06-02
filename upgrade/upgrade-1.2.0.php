<?php
declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.2.0 — move the back-office tabs under "Advanced Parameters" (next to
 * Webservice), matching PrestaShop's own admin API placement.
 *
 * @param Adminapi $module
 */
function upgrade_module_1_2_0($module): bool
{
    $parentId = (int) Tab::getIdFromClassName('AdminAdvancedParameters');
    if (!$parentId) {
        return true; // parent not found — leave tabs where they are
    }

    $ok = true;
    foreach (['AdminAdminapiClient', 'AdminAdminapiDoc'] as $className) {
        $id = (int) Tab::getIdFromClassName($className);
        if ($id) {
            $tab = new Tab($id);
            $tab->id_parent = $parentId;
            $ok = (bool) $tab->save() && $ok;
        }
    }

    return $ok;
}
