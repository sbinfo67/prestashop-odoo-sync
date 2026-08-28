<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'odoosync_order` (
    `id_odoosync_order` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order` INT UNSIGNED NOT NULL,
    `id_odoo_order` INT UNSIGNED NULL DEFAULT NULL,
    `odoo_order_name` VARCHAR(64) NULL DEFAULT NULL,
    `id_odoo_partner` INT UNSIGNED NULL DEFAULT NULL,
    `status` ENUM(\'success\', \'error\') NOT NULL,
    `message` TEXT NULL DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_odoosync_order`),
    UNIQUE KEY `id_order` (`id_order`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
