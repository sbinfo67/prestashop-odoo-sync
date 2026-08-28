<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Une ligne = une commande PrestaShop, mise à jour au fil des tentatives de synchro.
 * Sert à la fois de garde-fou d'idempotence et de journal affiché en back-office.
 */
class OdooSyncOrder extends ObjectModel
{
    public $id_order;
    public $id_odoo_order;
    public $odoo_order_name;
    public $id_odoo_partner;
    public $id_odoo_picking;
    public $odoo_picking_name;
    public $picking_status;
    public $picking_message;
    public $id_odoo_invoice;
    public $odoo_invoice_name;
    public $invoice_status;
    public $invoice_message;
    public $status;
    public $message;
    public $date_add;
    public $date_upd;

    public static $definition = [
        'table' => 'odoosync_order',
        'primary' => 'id_odoosync_order',
        'fields' => [
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_odoo_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'odoo_order_name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'id_odoo_partner' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_odoo_picking' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'odoo_picking_name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'picking_status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 10],
            'picking_message' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml'],
            'id_odoo_invoice' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'odoo_invoice_name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 64],
            'invoice_status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'size' => 10],
            'invoice_message' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml'],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 10],
            'message' => ['type' => self::TYPE_STRING, 'validate' => 'isCleanHtml'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];
}
