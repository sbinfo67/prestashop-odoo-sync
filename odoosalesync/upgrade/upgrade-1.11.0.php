<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Report de l'identifiant de paiement Payplug sur la facture Odoo, pour le rapprochement
 * des virements. La colonne mémorise ce qui a déjà été écrit, afin que la passe de
 * rattrapage du cron ne repasse pas sur les mêmes factures.
 */
function upgrade_module_1_11_0($module)
{
    $table = _DB_PREFIX_ . 'odoosync_order';
    $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "payplug_ref"');

    if (!empty($columns)) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . $table . '` ADD `payplug_ref` VARCHAR(255) NULL DEFAULT NULL AFTER `invoice_message`'
    );
}
