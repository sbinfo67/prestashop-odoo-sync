<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/OdooClient.php';
require_once __DIR__ . '/classes/OdooOrderSync.php';

class Odoosalesync extends Module
{
    public function __construct()
    {
        $this->name = 'odoosalesync';
        $this->tab = 'administration';
        $this->version = '1.8.1';
        $this->author = 'SBINFO';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Synchronisation Odoo');
        $this->description = $this->l('Crée automatiquement la commande et le client dans Odoo lorsqu\'un paiement PrestaShop est validé.');
        $this->confirmUninstall = $this->l('Êtes-vous sûr de vouloir désinstaller ce module ? Le journal de synchronisation sera supprimé.');
        $this->ps_versions_compliancy = ['min' => '8.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        include_once __DIR__ . '/sql/install.php';

        return parent::install()
            && $this->registerHook('actionPaymentConfirmation')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->installTabs()
            && Configuration::updateValue('ODOOSALESYNC_URL', '')
            && Configuration::updateValue('ODOOSALESYNC_DB', '')
            && Configuration::updateValue('ODOOSALESYNC_LOGIN', '')
            && Configuration::updateValue('ODOOSALESYNC_API_KEY', '')
            && Configuration::updateValue('ODOOSALESYNC_AUTOCONFIRM', 1)
            && Configuration::updateValue('ODOOSALESYNC_STATE_DELIVERY', (int) Configuration::get('PS_OS_PREPARATION'))
            && Configuration::updateValue('ODOOSALESYNC_STATE_INVOICE', (int) Configuration::get('PS_OS_SHIPPING'))
            && Configuration::updateValue('ODOOSALESYNC_PAYMENT_TERM', '')
            && Configuration::updateValue('ODOOSALESYNC_PAYMENT_TERM_ID', 0)
            && Configuration::updateValue('ODOOSALESYNC_STATES_FULL', '')
            && Configuration::updateValue('ODOOSALESYNC_INVOICE_POST', 1)
            && Configuration::updateValue('ODOOSALESYNC_SHIPPING_REF', '')
            && Configuration::updateValue('ODOOSALESYNC_DISCOUNT_REF', '')
            && Configuration::updateValue('ODOOSALESYNC_START_DATE', date('Y-m-d'))
            && Configuration::updateValue('ODOOSALESYNC_CRON_TOKEN', bin2hex(random_bytes(16)));
    }

    public function uninstall()
    {
        include_once __DIR__ . '/sql/uninstall.php';

        return parent::uninstall()
            && $this->uninstallTabs()
            && Configuration::deleteByName('ODOOSALESYNC_URL')
            && Configuration::deleteByName('ODOOSALESYNC_DB')
            && Configuration::deleteByName('ODOOSALESYNC_LOGIN')
            && Configuration::deleteByName('ODOOSALESYNC_API_KEY')
            && Configuration::deleteByName('ODOOSALESYNC_AUTOCONFIRM')
            && Configuration::deleteByName('ODOOSALESYNC_STATE_DELIVERY')
            && Configuration::deleteByName('ODOOSALESYNC_STATE_INVOICE')
            && Configuration::deleteByName('ODOOSALESYNC_PAYMENT_TERM')
            && Configuration::deleteByName('ODOOSALESYNC_PAYMENT_TERM_ID')
            && Configuration::deleteByName('ODOOSALESYNC_STATES_FULL')
            && Configuration::deleteByName('ODOOSALESYNC_INVOICE_POST')
            && Configuration::deleteByName('ODOOSALESYNC_SHIPPING_REF')
            && Configuration::deleteByName('ODOOSALESYNC_DISCOUNT_REF')
            && Configuration::deleteByName('ODOOSALESYNC_START_DATE')
            && Configuration::deleteByName('ODOOSALESYNC_CRON_TOKEN');
    }

    protected function installTabs()
    {
        $tabs = [
            [
                'class_name' => 'AdminOdooSyncSettings',
                'name' => 'Synchronisation Odoo',
            ],
            [
                'class_name' => 'AdminOdooSyncLog',
                'name' => 'Journal de synchronisation Odoo',
            ],
        ];

        foreach ($tabs as $data) {
            if (Tab::getIdFromClassName($data['class_name'])) {
                continue;
            }

            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $data['class_name'];
            $tab->module = $this->name;
            $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentModules');

            foreach (Language::getLanguages(false) as $lang) {
                $tab->name[$lang['id_lang']] = $data['name'];
            }

            if (!$tab->save()) {
                return false;
            }
        }

        return true;
    }

    protected function uninstallTabs()
    {
        foreach (['AdminOdooSyncSettings', 'AdminOdooSyncLog'] as $className) {
            $idTab = (int) Tab::getIdFromClassName($className);

            if ($idTab) {
                $tab = new Tab($idTab);
                $tab->delete();
            }
        }

        return true;
    }

    public function getContent()
    {
        Tools::redirectAdmin(
            $this->context->link->getAdminLink('AdminOdooSyncSettings')
        );
    }

    /**
     * Déclenché à chaque changement d'état de commande : sert à valider le bon de livraison
     * puis à établir la facture dans Odoo, selon les états configurés.
     * Comme le hook de paiement, il ne doit jamais interrompre le back-office.
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }

        $idOrder = (int) $params['id_order'];
        $idState = (int) $params['newOrderStatus']->id;

        $steps = [
            (int) Configuration::get('ODOOSALESYNC_STATE_DELIVERY') => 'syncDelivery',
            (int) Configuration::get('ODOOSALESYNC_STATE_INVOICE') => 'syncInvoice',
        ];

        if (!$idState || !isset($steps[$idState])) {
            return;
        }

        try {
            $sync = new OdooOrderSync();
            $sync->{$steps[$idState]}($idOrder);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Odoosalesync: ' . $steps[$idState] . ' a échoué pour la commande #' . $idOrder . ' : ' . $e->getMessage(),
                3,
                null,
                'Order',
                $idOrder,
                true
            );
        }
    }

    /**
     * Déclenché une fois lorsqu'une commande atteint un état de paiement accepté.
     * Ne doit jamais casser le tunnel de commande : toute erreur est capturée et loguée,
     * le cron de rattrapage se chargera de réessayer.
     */
    public function hookActionPaymentConfirmation($params)
    {
        if (empty($params['id_order'])) {
            return;
        }

        $idOrder = (int) $params['id_order'];

        try {
            $sync = new OdooOrderSync();
            $sync->syncOrder($idOrder);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                'Odoosalesync: échec de synchronisation de la commande #' . $idOrder . ' : ' . $e->getMessage(),
                3,
                null,
                'Order',
                $idOrder,
                true
            );
        }
    }
}
