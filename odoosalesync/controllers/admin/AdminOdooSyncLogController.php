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

        parent::__construct();

        // Après parent::__construct() uniquement : le traducteur n'existe pas avant,
        // et $this->trans() échouerait sur "Call to a member function trans() on null".
        $this->fields_list = [
            'id_odoosync_order' => ['title' => $this->trans('ID', [], 'Modules.Odoosalesync.Admin'), 'align' => 'center'],
            'id_order' => ['title' => $this->trans('Commande PrestaShop', [], 'Modules.Odoosalesync.Admin'), 'align' => 'center'],
            'id_odoo_order' => ['title' => $this->trans('Commande Odoo', [], 'Modules.Odoosalesync.Admin'), 'align' => 'center'],
            'id_odoo_partner' => ['title' => $this->trans('Client Odoo', [], 'Modules.Odoosalesync.Admin'), 'align' => 'center'],
            'status' => [
                'title' => $this->trans('Statut', [], 'Modules.Odoosalesync.Admin'),
                'align' => 'center',
                'type' => 'select',
                'list' => ['success' => $this->trans('Succès', [], 'Modules.Odoosalesync.Admin'), 'error' => $this->trans('Erreur', [], 'Modules.Odoosalesync.Admin')],
                'filter_key' => 'status',
            ],
            'message' => ['title' => $this->trans('Message', [], 'Modules.Odoosalesync.Admin'), 'orderby' => false, 'search' => false],
            'date_upd' => ['title' => $this->trans('Dernière tentative', [], 'Modules.Odoosalesync.Admin'), 'type' => 'datetime'],
        ];

        $this->addRowAction('retry');

        $this->toolbar_btn['back_to_settings'] = [
            'href' => $this->context->link->getAdminLink('AdminOdooSyncSettings'),
            'desc' => $this->trans('Configuration du module', [], 'Modules.Odoosalesync.Admin'),
            'icon' => 'process-icon-configure',
        ];

        $this->toolbar_btn['retry_all_errors'] = [
            'href' => self::$currentIndex . '&retryAllErrors=1&token=' . $this->token,
            'desc' => $this->trans('Réessayer toutes les synchros en erreur', [], 'Modules.Odoosalesync.Admin'),
            'icon' => 'process-icon-refresh',
        ];
    }

    public function displayRetryLink($token, $id, $row = null)
    {
        $idOrder = (int) ($row['id_order'] ?? 0);
        $url = self::$currentIndex . '&id_order=' . $idOrder . '&retryOrder=1&token=' . $this->token;

        return '<a class="btn btn-default" href="' . $url . '" title="' . $this->trans('Réessayer', [], 'Modules.Odoosalesync.Admin') . '">'
            . '<i class="icon-refresh"></i> ' . $this->trans('Réessayer', [], 'Modules.Odoosalesync.Admin')
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
            $this->confirmations[] = sprintf($this->trans('%d commande(s) synchronisée(s) avec succès.', [], 'Modules.Odoosalesync.Admin'), $success);
        }

        if ($failed) {
            $this->errors[] = sprintf($this->trans('%d commande(s) toujours en échec, voir le message ci-dessous.', [], 'Modules.Odoosalesync.Admin'), $failed);
        }
    }
}
