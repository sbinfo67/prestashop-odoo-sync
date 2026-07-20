<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'odoosync_order`');
