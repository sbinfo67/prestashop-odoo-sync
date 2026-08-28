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
        $this->version = '1.9.1';
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
            && $this->registerHook('actionOrderGridDefinitionModifier')
            && $this->registerHook('actionOrderGridQueryBuilderModifier')
            && $this->registerHook('actionOrderGridDataModifier')
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
            && Configuration::updateValue('ODOOSALESYNC_ALERT_EMAIL', (string) Configuration::get('PS_SHOP_EMAIL'))
            && Configuration::updateValue('ODOOSALESYNC_ALERT_DELAY', 60)
            && Configuration::updateValue('ODOOSALESYNC_ALERT_LAST', 0)
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
            && Configuration::deleteByName('ODOOSALESYNC_ALERT_EMAIL')
            && Configuration::deleteByName('ODOOSALESYNC_ALERT_DELAY')
            && Configuration::deleteByName('ODOOSALESYNC_ALERT_LAST')
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
     * Ajoute une colonne « Odoo » à la liste des commandes.
     * La grille est gérée par Symfony : la colonne se déclare ici, les données sont jointes
     * par actionOrderGridQueryBuilderModifier et mises en forme par actionOrderGridDataModifier.
     */
    public function hookActionOrderGridDefinitionModifier($params)
    {
        $columns = $params['definition']->getColumns();

        // « actions » est la dernière colonne de la grille des commandes : on se place juste
        // avant. Viser une colonne inexistante fait échouer l'ajout sans message.
        $columns->addBefore(
            'actions',
            (new PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn('odoosync'))
                ->setName($this->l('Odoo'))
                ->setOptions(['field' => 'odoosync_html', 'clickable' => false])
        );
    }

    /**
     * Joint le journal de synchronisation à la requête de la liste des commandes.
     */
    public function hookActionOrderGridQueryBuilderModifier($params)
    {
        foreach (['search_query_builder', 'count_query_builder'] as $key) {
            if (empty($params[$key])) {
                continue;
            }

            $params[$key]->leftJoin(
                'o',
                _DB_PREFIX_ . 'odoosync_order',
                'odoosync',
                'odoosync.id_order = o.id_order'
            );
        }

        if (!empty($params['search_query_builder'])) {
            $params['search_query_builder']->addSelect(
                'odoosync.status AS odoosync_status,
                 odoosync.picking_status AS odoosync_picking_status,
                 odoosync.invoice_status AS odoosync_invoice_status'
            );
        }
    }

    /**
     * Construit l'icône affichée dans la colonne, cliquable vers le journal.
     */
    public function hookActionOrderGridDataModifier($params)
    {
        $data = $params['data'];
        $records = $data->getRecords()->all();
        $journalUrl = $this->context->link->getAdminLink('AdminOdooSyncLog');

        foreach ($records as $index => $record) {
            $records[$index]['odoosync_html'] = $this->renderOrderGridBadge($record, $journalUrl);
        }

        $params['data'] = new PrestaShop\PrestaShop\Core\Grid\Data\GridData(
            new PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection($records),
            $data->getRecordsTotal(),
            $data->getQuery()
        );
    }

    /**
     * Un pictogramme par état : erreur, synchro complète, partielle, ou jamais synchronisée.
     */
    protected function renderOrderGridBadge(array $record, $journalUrl)
    {
        $status = $record['odoosync_status'] ?? null;
        $steps = [$record['odoosync_picking_status'] ?? null, $record['odoosync_invoice_status'] ?? null];

        if (!$status) {
            return '<span class="text-muted" title="' . htmlspecialchars($this->l('Jamais synchronisée avec Odoo')) . '">–</span>';
        }

        if ($status === 'error' || in_array('error', $steps, true)) {
            $label = $this->l('Erreur de synchronisation Odoo — voir le journal');
            $icon = 'icon-warning-sign';
            $color = '#c62828';
        } elseif (in_array('success', $steps, true)) {
            $label = $this->l('Synchronisée dans Odoo (livraison et/ou facture traitées)');
            $icon = 'icon-check-circle';
            $color = '#2e7d32';
        } else {
            $label = $this->l('Commande synchronisée dans Odoo');
            $icon = 'icon-check';
            $color = '#2e7d32';
        }

        return '<a href="' . htmlspecialchars($journalUrl) . '" title="' . htmlspecialchars($label) . '"'
            . ' style="color:' . $color . '"><i class="' . $icon . '"></i></a>';
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
