<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Colonne Odoo dans la liste des commandes, et alerte email récapitulative.
 */
function upgrade_module_1_9_0($module)
{
    if (!Configuration::hasKey('ODOOSALESYNC_ALERT_EMAIL')) {
        Configuration::updateValue('ODOOSALESYNC_ALERT_EMAIL', (string) Configuration::get('PS_SHOP_EMAIL'));
        Configuration::updateValue('ODOOSALESYNC_ALERT_DELAY', 60);
        Configuration::updateValue('ODOOSALESYNC_ALERT_LAST', 0);
    }

    $registered = $module->registerHook('actionOrderGridDefinitionModifier')
        && $module->registerHook('actionOrderGridQueryBuilderModifier')
        && $module->registerHook('actionOrderGridDataModifier');

    // La définition de la liste des commandes est mise en cache : sans ce vidage, la colonne
    // n'apparaîtrait qu'après une intervention manuelle.
    require_once _PS_MODULE_DIR_ . 'odoosalesync/classes/OdooOrderSync.php';
    OdooOrderSync::clearCache();

    return $registered;
}
