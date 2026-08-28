<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Suivi du bon de livraison et de la facture Odoo dans le journal.
 */
function upgrade_module_1_6_0($module)
{
    $table = _DB_PREFIX_ . 'odoosync_order';
    $existing = [];

    foreach (Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . '`') as $column) {
        $existing[$column['Field']] = true;
    }

    $columns = [
        'id_odoo_picking' => 'INT UNSIGNED NULL DEFAULT NULL',
        'odoo_picking_name' => 'VARCHAR(64) NULL DEFAULT NULL',
        'picking_status' => 'VARCHAR(10) NULL DEFAULT NULL',
        'picking_message' => 'TEXT NULL DEFAULT NULL',
        'id_odoo_invoice' => 'INT UNSIGNED NULL DEFAULT NULL',
        'odoo_invoice_name' => 'VARCHAR(64) NULL DEFAULT NULL',
        'invoice_status' => 'VARCHAR(10) NULL DEFAULT NULL',
        'invoice_message' => 'TEXT NULL DEFAULT NULL',
    ];

    foreach ($columns as $name => $definition) {
        if (isset($existing[$name])) {
            continue;
        }

        if (!Db::getInstance()->execute('ALTER TABLE `' . $table . '` ADD `' . $name . '` ' . $definition)) {
            return false;
        }
    }

    return $module->registerHook('actionOrderStatusPostUpdate');
}
