<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'odoosalesync/classes/OdooSyncOrder.php';
require_once _PS_MODULE_DIR_ . 'odoosalesync/classes/OdooOrderSync.php';

class AdminOdooSyncLogController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'odoosync_order';
        $this->identifier = 'id_odoosync_order';
        $this->className = 'OdooSyncOrder';
        $this->lang = false;
        $this->list_no_link = true;
        $this->_defaultOrderBy = 'date_upd';
        $this->_defaultOrderWay = 'DESC';

        $this->fields_list = [
            'id_odoosync_order' => ['title' => $this->l('ID'), 'align' => 'center'],
            'id_order' => ['title' => $this->l('Commande PrestaShop'), 'align' => 'center'],
            'id_odoo_order' => ['title' => $this->l('Commande Odoo'), 'align' => 'center'],
            'id_odoo_partner' => ['title' => $this->l('Client Odoo'), 'align' => 'center'],
            'status' => [
                'title' => $this->l('Statut'),
                'align' => 'center',
                'type' => 'select',
                'list' => ['success' => $this->l('Succès'), 'error' => $this->l('Erreur')],
                'filter_key' => 'status',
            ],
            'message' => ['title' => $this->l('Message'), 'orderby' => false, 'search' => false],
            'date_upd' => ['title' => $this->l('Dernière tentative'), 'type' => 'datetime'],
        ];

        parent::__construct();

        $this->addRowAction('retry');

        $this->toolbar_btn['retry_all_errors'] = [
            'href' => self::$currentIndex . '&retryAllErrors=1&token=' . $this->token,
            'desc' => $this->l('Réessayer toutes les synchros en erreur'),
            'icon' => 'process-icon-refresh',
        ];
    }

    public function displayRetryLink($token, $id, $row = null)
    {
        $idOrder = (int) ($row['id_order'] ?? 0);
        $url = self::$currentIndex . '&id_order=' . $idOrder . '&retryOrder=1&token=' . $this->token;

        return '<a class="btn btn-default" href="' . $url . '" title="' . $this->l('Réessayer') . '">'
            . '<i class="icon-refresh"></i> ' . $this->l('Réessayer')
            . '</a>';
    }

    public function postProcess()
    {
        if (Tools::isSubmit('retryOrder')) {
            $this->retryOrders([(int) Tools::getValue('id_order')]);
        } elseif (Tools::isSubmit('retryAllErrors')) {
            $rows = Db::getInstance()->executeS(
                'SELECT id_order FROM `' . _DB_PREFIX_ . 'odoosync_order` WHERE status = "error"'
            );
            $this->retryOrders(array_map('intval', array_column($rows ?: [], 'id_order')));
        }

        parent::postProcess();
    }

    protected function retryOrders(array $idOrders)
    {
        if (empty($idOrders)) {
            return;
        }

        $sync = new OdooOrderSync();
        $success = 0;
        $failed = 0;

        foreach ($idOrders as $idOrder) {
            try {
                $sync->syncOrder($idOrder);
                $success++;
            } catch (Throwable $e) {
                $failed++;
            }
        }

        if ($success) {
            $this->confirmations[] = sprintf($this->l('%d commande(s) synchronisée(s) avec succès.'), $success);
        }

        if ($failed) {
            $this->errors[] = sprintf($this->l('%d commande(s) toujours en échec, voir le message ci-dessous.'), $failed);
        }
    }
}
