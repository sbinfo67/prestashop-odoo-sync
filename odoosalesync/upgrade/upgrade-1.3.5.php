<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Ajoute le numéro de commande Odoo (ex. S00513) au journal.
 *
 * Le journal n'affichait que l'identifiant technique de la commande Odoo, qui ne permet pas
 * de la retrouver depuis l'interface d'Odoo : c'est le champ « name » qui y est affiché.
 * Les lignes existantes gardent une valeur vide et retombent sur l'identifiant.
 */
function upgrade_module_1_3_5($module)
{
    $table = _DB_PREFIX_ . 'odoosync_order';

    foreach (Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . '`') as $column) {
        if ($column['Field'] === 'odoo_order_name') {
            return true;
        }
    }

    return Db::getInstance()->execute(
        'ALTER TABLE `' . $table . '` ADD `odoo_order_name` VARCHAR(64) NULL DEFAULT NULL AFTER `id_odoo_order`'
    );
}
