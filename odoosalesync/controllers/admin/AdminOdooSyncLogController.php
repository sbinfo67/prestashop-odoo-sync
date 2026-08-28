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
            'id_order' => [
                'title' => $this->trans('Commande PrestaShop', [], 'Modules.Odoosalesync.Admin'),
                'align' => 'center',
                'callback' => 'displayPrestaShopOrder',
            ],
            'odoo_order_name' => [
                'title' => $this->trans('Commande Odoo', [], 'Modules.Odoosalesync.Admin'),
                'align' => 'center',
                'callback' => 'displayOdooOrder',
                'search' => false,
                'orderby' => false,
            ],
            'odoo_picking_name' => [
                'title' => $this->trans('BL Odoo', [], 'Modules.Odoosalesync.Admin'),
                'align' => 'center',
                'callback' => 'displayOdooPicking',
                'search' => false,
                'orderby' => false,
            ],
            'odoo_invoice_name' => [
                'title' => $this->trans('Facture Odoo', [], 'Modules.Odoosalesync.Admin'),
                'align' => 'center',
                'callback' => 'displayOdooInvoice',
                'search' => false,
                'orderby' => false,
            ],
            'id_odoo_partner' => [
                'title' => $this->trans('Client Odoo', [], 'Modules.Odoosalesync.Admin'),
                'align' => 'center',
                'callback' => 'displayOdooPartner',
            ],
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

        // Déclarer des actions groupées suffit à faire apparaître les cases à cocher.
        // Volontairement limité au réessai : supprimer une ligne en succès rendrait la commande
        // à nouveau éligible et le cron en créerait un doublon dans Odoo.
        $this->bulk_actions = [
            'retry' => [
                'text' => $this->trans('Réessayer la synchronisation', [], 'Modules.Odoosalesync.Admin'),
                'icon' => 'icon-refresh',
            ],
        ];

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

    /**
     * Lien vers la fiche de la commande dans PrestaShop.
     */
    public function displayPrestaShopOrder($idOrder, $row)
    {
        $idOrder = (int) $idOrder;

        if (!$idOrder) {
            return '-';
        }

        // La route doit être nommée explicitement : sans elle, getAdminLink renvoie vers la
        // liste des commandes avec l'identifiant en paramètre, et non vers la fiche.
        $url = $this->context->link->getAdminLink(
            'AdminOrders',
            true,
            ['route' => 'admin_orders_view', 'orderId' => $idOrder]
        );

        return '<a href="' . htmlspecialchars($url) . '">' . $idOrder . '</a>';
    }

    /**
     * Lien vers la commande dans Odoo.
     *
     * On passe par /mail/view : Odoo y résout lui-même le bon format d'URL selon sa version
     * (en 19, il redirige vers /odoo/<modèle>/<id>), ce qui évite de coder en dur un format
     * susceptible de changer.
     *
     * L'URL utilisée est celle configurée dans le module : elle doit donc être joignable
     * depuis le navigateur, et pas seulement depuis le serveur PrestaShop.
     */
    protected function odooUrl($model, $idRecord)
    {
        $base = rtrim(preg_replace('#/jsonrpc/*$#i', '', rtrim(trim((string) Configuration::get('ODOOSALESYNC_URL')), '/')), '/');

        if ($base === '' || !$idRecord) {
            return null;
        }

        return $base . '/mail/view?model=' . urlencode($model) . '&res_id=' . (int) $idRecord;
    }

    public function displayOdooPicking($name, $row)
    {
        return $this->displayStep($name, $row, 'picking', 'stock.picking');
    }

    public function displayOdooInvoice($name, $row)
    {
        return $this->displayStep($name, $row, 'invoice', 'account.move');
    }

    /**
     * Rend une étape (BL ou facture) : numéro Odoo cliquable, ou motif de l'échec.
     * Une étape non encore déclenchée reste vide, pour la distinguer d'un échec.
     */
    protected function displayStep($name, $row, $step, $model)
    {
        $status = $row[$step . '_status'] ?? null;
        $idRecord = (int) ($row['id_odoo_' . $step] ?? 0);
        $message = trim((string) ($row[$step . '_message'] ?? ''));

        if (!$status) {
            return '<span class="text-muted">—</span>';
        }

        if ($status === 'error') {
            return '<span class="badge badge-danger" title="' . htmlspecialchars($message) . '">'
                . $this->trans('Erreur', [], 'Modules.Odoosalesync.Admin') . '</span>';
        }

        if (!$idRecord) {
            // Étape sans objet à créer (commande de services, BL déjà validé...).
            return '<span class="text-muted" title="' . htmlspecialchars($message) . '">'
                . $this->trans('Sans objet', [], 'Modules.Odoosalesync.Admin') . '</span>';
        }

        $label = $name !== null && $name !== '' ? $name : sprintf($this->trans('id %d', [], 'Modules.Odoosalesync.Admin'), $idRecord);
        $url = $this->odooUrl($model, $idRecord);

        return $url
            ? '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars($label) . '</a>'
            : htmlspecialchars($label);
    }

    /**
     * Lien vers la fiche client dans Odoo.
     */
    public function displayOdooPartner($idPartner, $row)
    {
        $idPartner = (int) $idPartner;

        if (!$idPartner) {
            return '-';
        }

        $url = $this->odooUrl('res.partner', $idPartner);

        return $url
            ? '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . $idPartner . '</a>'
            : (string) $idPartner;
    }

    /**
     * Affiche le numéro de commande Odoo (ex. S00513), seul identifiant recherchable dans Odoo.
     * Les lignes créées avant la 1.3.5 n'ont pas ce numéro : on retombe sur l'identifiant technique.
     */
    public function displayOdooOrder($name, $row)
    {
        $idOdooOrder = (int) ($row['id_odoo_order'] ?? 0);

        if (!$idOdooOrder) {
            return '-';
        }

        $label = !empty($name)
            ? $name
            : sprintf($this->trans('id %d', [], 'Modules.Odoosalesync.Admin'), $idOdooOrder);

        $url = $this->odooUrl('sale.order', $idOdooOrder);

        return $url
            ? '<a href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener">' . htmlspecialchars($label) . '</a>'
            : htmlspecialchars($label);
    }

    /**
     * PrestaShop appelle display{Action}Link($token, $id, $name) : le 3e argument est un libellé,
     * pas la ligne, et $id est la clé primaire du journal — pas l'identifiant de commande.
     * La commande est donc résolue au moment du clic, à partir de cette clé.
     */
    public function displayRetryLink($token, $id, $name = null)
    {
        $url = self::$currentIndex . '&id_odoosync_order=' . (int) $id . '&retryOrder=1&token=' . $this->token;

        return '<a class="btn btn-default" href="' . $url . '" title="' . $this->trans('Réessayer', [], 'Modules.Odoosalesync.Admin') . '">'
            . '<i class="icon-refresh"></i> ' . $this->trans('Réessayer', [], 'Modules.Odoosalesync.Admin')
            . '</a>';
    }

    public function postProcess()
    {
        if (Tools::isSubmit('retryOrder')) {
            $idOrder = (int) Db::getInstance()->getValue(
                'SELECT id_order FROM `' . _DB_PREFIX_ . 'odoosync_order`
                 WHERE id_odoosync_order = ' . (int) Tools::getValue('id_odoosync_order')
            );

            if ($idOrder) {
                $this->retryOrders([$idOrder]);
            } else {
                $this->errors[] = $this->trans('Ligne de journal introuvable.', [], 'Modules.Odoosalesync.Admin');
            }
        } elseif (Tools::isSubmit('retryAllErrors')) {
            $rows = Db::getInstance()->executeS(
                'SELECT id_order FROM `' . _DB_PREFIX_ . 'odoosync_order` WHERE status = "error"'
            );
            $this->retryOrders(array_map('intval', array_column($rows ?: [], 'id_order')));
        }

        parent::postProcess();
    }

    /**
     * Action groupée : réessaie les lignes cochées.
     * PrestaShop dépose les identifiants sélectionnés dans $this->boxes.
     */
    public function processBulkRetry()
    {
        $ids = array_map('intval', (array) $this->boxes);
        $ids = array_filter($ids);

        if (empty($ids)) {
            $this->errors[] = $this->trans('Aucune ligne sélectionnée.', [], 'Modules.Odoosalesync.Admin');

            return false;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT id_order FROM `' . _DB_PREFIX_ . 'odoosync_order`
             WHERE id_odoosync_order IN (' . implode(',', $ids) . ')'
        );

        $this->retryOrders(array_map('intval', array_column($rows ?: [], 'id_order')));

        return true;
    }

    protected function retryOrders(array $idOrders)
    {
        if (empty($idOrders)) {
            return;
        }

        $sync = new OdooOrderSync();
        $success = 0;
        $failed = 0;
        $noStep = 0;

        foreach ($idOrders as $idOrder) {
            try {
                // La chaîne complète est rejouée : les étapes déjà réussies sont ignorées,
                // celles en échec reprennent — un stock corrigé permet d'aller jusqu'à la facture.
                $result = $sync->syncPipeline($idOrder);

                if (isset($result['steps']) && empty($result['steps'])) {
                    $noStep++;
                } else {
                    $success++;
                }
            } catch (Throwable $e) {
                $failed++;
            }
        }

        if ($success) {
            $this->confirmations[] = sprintf($this->trans('%d commande(s) synchronisée(s) avec succès.', [], 'Modules.Odoosalesync.Admin'), $success);
        }

        if ($noStep) {
            $this->warnings[] = sprintf(
                $this->trans(
                    '%d commande(s) sans étape à exécuter : leur statut PrestaShop ne figure dans aucun '
                    . 'des statuts configurés (validation du BL, facture, cycle complet). '
                    . 'Renseignez ces statuts dans la configuration du module pour que le bon de '
                    . 'livraison et la facture soient traités.',
                    [],
                    'Modules.Odoosalesync.Admin'
                ),
                $noStep
            );
        }

        if ($failed) {
            $this->errors[] = sprintf($this->trans('%d commande(s) toujours en échec, voir le message ci-dessous.', [], 'Modules.Odoosalesync.Admin'), $failed);
        }
    }
}
