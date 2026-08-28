<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'odoosalesync/classes/OdooClient.php';
require_once _PS_MODULE_DIR_ . 'odoosalesync/classes/OdooOrderSync.php';

class AdminOdooSyncSettingsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();

        $this->meta_title = $this->trans('Synchronisation Odoo', [], 'Modules.Odoosalesync.Admin');
    }

    public function initContent()
    {
        $this->content .= $this->renderJournalLink() . $this->renderForm();

        parent::initContent();
    }

    /**
     * Les onglets créés par le module n'apparaissent pas dans le menu de PrestaShop 9 :
     * sans ce lien, le journal de synchronisation est difficilement atteignable.
     */
    protected function renderJournalLink()
    {
        $url = $this->context->link->getAdminLink('AdminOdooSyncLog');

        return '<div class="panel"><a class="btn btn-default" href="' . htmlspecialchars($url) . '">'
            . '<i class="icon-list"></i> '
            . $this->trans('Ouvrir le journal de synchronisation', [], 'Modules.Odoosalesync.Admin')
            . '</a> <span class="help-block" style="display:inline-block;margin:0 0 0 10px">'
            . $this->trans('Détail des commandes synchronisées et des erreurs, avec possibilité de réessayer.', [], 'Modules.Odoosalesync.Admin')
            . '</span></div>';
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitOdooSyncTest')) {
            $this->testConnection();
        } elseif (Tools::isSubmit('submitOdooSyncNow')) {
            $this->syncNow();
        } elseif (Tools::isSubmit('submitOdooSyncSettings')) {
            $this->saveSettings();
        }

        parent::postProcess();
    }

    protected function saveSettings()
    {
        Configuration::updateValue('ODOOSALESYNC_URL', trim((string) Tools::getValue('ODOOSALESYNC_URL')));
        Configuration::updateValue('ODOOSALESYNC_DB', trim((string) Tools::getValue('ODOOSALESYNC_DB')));
        Configuration::updateValue('ODOOSALESYNC_LOGIN', trim((string) Tools::getValue('ODOOSALESYNC_LOGIN')));
        Configuration::updateValue('ODOOSALESYNC_AUTOCONFIRM', (int) Tools::getValue('ODOOSALESYNC_AUTOCONFIRM'));
        Configuration::updateValue('ODOOSALESYNC_SHIPPING_REF', trim((string) Tools::getValue('ODOOSALESYNC_SHIPPING_REF')));
        Configuration::updateValue('ODOOSALESYNC_DISCOUNT_REF', trim((string) Tools::getValue('ODOOSALESYNC_DISCOUNT_REF')));
        Configuration::updateValue('ODOOSALESYNC_STATE_DELIVERY', (int) Tools::getValue('ODOOSALESYNC_STATE_DELIVERY'));
        Configuration::updateValue('ODOOSALESYNC_STATE_INVOICE', (int) Tools::getValue('ODOOSALESYNC_STATE_INVOICE'));
        if (Tools::getIsset('ODOOSALESYNC_PAYMENT_TERM_ID')) {
            $idTerm = (int) Tools::getValue('ODOOSALESYNC_PAYMENT_TERM_ID');
            Configuration::updateValue('ODOOSALESYNC_PAYMENT_TERM_ID', $idTerm);

            // Le libellé est conservé pour l'affichage et comme repli si l'identifiant disparaît.
            $label = '';
            foreach ((array) $this->fetchPaymentTerms() as $term) {
                if ((int) $term['id'] === $idTerm) {
                    $label = $term['name'];
                    break;
                }
            }
            Configuration::updateValue('ODOOSALESYNC_PAYMENT_TERM', $label);
        } else {
            Configuration::updateValue('ODOOSALESYNC_PAYMENT_TERM', trim((string) Tools::getValue('ODOOSALESYNC_PAYMENT_TERM')));
        }
        Configuration::updateValue('ODOOSALESYNC_INVOICE_POST', (int) Tools::getValue('ODOOSALESYNC_INVOICE_POST'));

        // Saisie en JJ/MM/AAAA, stockage en ISO : c'est le seul format comparable en SQL.
        $startDate = trim((string) Tools::getValue('ODOOSALESYNC_START_DATE'));
        if ($startDate === '') {
            Configuration::updateValue('ODOOSALESYNC_START_DATE', '');
        } elseif (($isoDate = OdooOrderSync::parseDate($startDate)) !== null) {
            Configuration::updateValue('ODOOSALESYNC_START_DATE', $isoDate);
        } else {
            $this->errors[] = $this->trans('Date de début de synchro invalide (format attendu : JJ/MM/AAAA). Valeur non modifiée.', [], 'Modules.Odoosalesync.Admin');
        }

        $apiKey = trim((string) Tools::getValue('ODOOSALESYNC_API_KEY'));
        if ($apiKey !== '') {
            Configuration::updateValue('ODOOSALESYNC_API_KEY', $apiKey);
        }

        $this->confirmations[] = $this->trans('Paramètres mis à jour.', [], 'Modules.Odoosalesync.Admin');
    }

    /**
     * Rattrapage manuel : synchronise les commandes payées en attente depuis la date de début.
     * Sans fenêtre glissante, contrairement au cron, pour permettre une première synchro.
     */
    protected function syncNow()
    {
        $limit = 50;
        $result = OdooOrderSync::runCatchUp(null, $limit);

        if ($result['total'] === 0) {
            $this->confirmations[] = $this->trans(
                'Aucune commande à synchroniser : toutes les commandes payées depuis la date de début sont déjà dans Odoo.',
                [],
                'Modules.Odoosalesync.Admin'
            );

            return;
        }

        if ($result['success']) {
            $this->confirmations[] = sprintf(
                $this->trans('%d commande(s) synchronisée(s) vers Odoo.', [], 'Modules.Odoosalesync.Admin'),
                $result['success']
            );
        }

        if ($result['failed']) {
            $this->errors[] = sprintf(
                $this->trans('%d commande(s) en échec : voir le détail dans le Journal de synchronisation.', [], 'Modules.Odoosalesync.Admin'),
                $result['failed']
            );
        }

        if ($result['total'] >= $limit) {
            $this->confirmations[] = sprintf(
                $this->trans('Traitement limité à %d commandes par lot : relancez pour continuer.', [], 'Modules.Odoosalesync.Admin'),
                $limit
            );
        }
    }

    protected function testConnection()
    {
        // La clé saisie dans le formulaire prime : on doit pouvoir tester une nouvelle clé
        // sans l'avoir enregistrée au préalable. Sinon, on retombe sur celle déjà stockée.
        $apiKey = trim((string) Tools::getValue('ODOOSALESYNC_API_KEY'));
        if ($apiKey === '') {
            $apiKey = (string) Configuration::get('ODOOSALESYNC_API_KEY');
        }

        try {
            $client = new OdooClient(
                trim((string) Tools::getValue('ODOOSALESYNC_URL')),
                trim((string) Tools::getValue('ODOOSALESYNC_DB')),
                trim((string) Tools::getValue('ODOOSALESYNC_LOGIN')),
                $apiKey
            );

            $uid = $client->authenticate();

            $this->confirmations[] = sprintf(
                $this->trans('Connexion Odoo réussie (uid %d). Pensez à cliquer sur Enregistrer pour conserver ces paramètres.', [], 'Modules.Odoosalesync.Admin'),
                $uid
            );
        } catch (Throwable $e) {
            $this->errors[] = sprintf($this->trans('Échec de connexion à Odoo : %s', [], 'Modules.Odoosalesync.Admin'), $e->getMessage());
        }
    }

    /**
     * Doit rester "public" : AdminControllerCore::renderForm() l'est, et PHP interdit
     * de restreindre la visibilité d'une méthode héritée (fatal error au chargement).
     */
    public function renderForm()
    {
        $cronCli = '*/10 * * * * php ' . _PS_MODULE_DIR_ . 'odoosalesync/cron.php';
        $cronUrl = $this->context->link->getBaseLink() . 'modules/odoosalesync/cron.php?token=' . Configuration::get('ODOOSALESYNC_CRON_TOKEN');

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Connexion à Odoo', [], 'Modules.Odoosalesync.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('URL Odoo', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_URL',
                        'desc' => $this->trans('Exemple : https://odoo.mondomaine.local', [], 'Modules.Odoosalesync.Admin'),
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Base de données Odoo', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_DB',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Login API', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_LOGIN',
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('Clé API', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_API_KEY',
                        'desc' => $this->trans('Laisser vide pour conserver la clé actuellement enregistrée.', [], 'Modules.Odoosalesync.Admin')
                            . ' ' . $this->trans('La clé se génère dans Odoo en étant connecté avec le compte concerné : Préférences > Sécurité du compte > Nouvelle clé API. Un administrateur ne peut pas la générer depuis la fiche d\'un autre utilisateur.', [], 'Modules.Odoosalesync.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Date de début de synchro', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_START_DATE',
                        'desc' => $this->trans('Format JJ/MM/AAAA. Les commandes créées avant cette date ne sont jamais envoyées à Odoo (utile en première installation sur un site déjà en production). Laisser vide pour tout synchroniser.', [], 'Modules.Odoosalesync.Admin'),
                        'hint' => $this->trans('Exemple : 28/08/2026', [], 'Modules.Odoosalesync.Admin'),
                        'class' => 'fixed-width-lg',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Référence Odoo de l\'article "frais de port"', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_SHIPPING_REF',
                        'desc' => $this->trans('Référence interne (default_code) d\'un article de service Odoo servant à porter les frais de livraison, à recopier telle quelle depuis Odoo : la comparaison est sensible à la casse. Sans cette référence, le port n\'est pas transmis et le total Odoo différera du montant encaissé.', [], 'Modules.Odoosalesync.Admin'),
                        'class' => 'fixed-width-lg',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Référence Odoo de l\'article "remise"', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_DISCOUNT_REF',
                        'desc' => $this->trans('Référence interne (default_code) d\'un article de service Odoo servant à porter les remises et bons de réduction, en montant négatif. Sensible à la casse. Laisser vide si la boutique n\'en utilise pas.', [], 'Modules.Odoosalesync.Admin'),
                        'class' => 'fixed-width-lg',
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Confirmer automatiquement la commande dans Odoo', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_AUTOCONFIRM',
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Oui', [], 'Modules.Odoosalesync.Admin')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('Non', [], 'Modules.Odoosalesync.Admin')],
                        ],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Statut déclenchant la validation du BL Odoo', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_STATE_DELIVERY',
                        'desc' => $this->trans('Quand une commande atteint ce statut, le bon de livraison Odoo est validé. Le stock doit être suffisant, sinon l\'erreur est signalée dans le journal.', [], 'Modules.Odoosalesync.Admin'),
                        'options' => ['query' => $this->orderStateOptions(), 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Statut déclenchant la facture Odoo', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_STATE_INVOICE',
                        'desc' => $this->trans('Quand une commande atteint ce statut, la facture Odoo est créée. Le bon de livraison doit être validé au préalable, faute de quoi la facture serait vide.', [], 'Modules.Odoosalesync.Admin'),
                        'options' => ['query' => $this->orderStateOptions(), 'id' => 'id', 'name' => 'name'],
                    ],
                    $this->paymentTermField(),
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Comptabiliser la facture automatiquement', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'ODOOSALESYNC_INVOICE_POST',
                        'desc' => $this->trans('Désactiver pour créer la facture en brouillon et la valider vous-même dans Odoo.', [], 'Modules.Odoosalesync.Admin'),
                        'values' => [
                            ['id' => 'post_on', 'value' => 1, 'label' => $this->trans('Oui', [], 'Modules.Odoosalesync.Admin')],
                            ['id' => 'post_off', 'value' => 0, 'label' => $this->trans('Non', [], 'Modules.Odoosalesync.Admin')],
                        ],
                    ],
                    [
                        'type' => 'html',
                        'name' => 'cron_info',
                        'label' => $this->trans('Cron de rattrapage', [], 'Modules.Odoosalesync.Admin'),
                        'html_content' => '<p class="help-block">'
                            . $this->trans('Recommandé (CLI, contourne le blocage .htaccess de PrestaShop 9) — à ajouter au crontab système :', [], 'Modules.Odoosalesync.Admin')
                            . '</p><code>' . htmlspecialchars($cronCli) . '</code>'
                            . '<p class="help-block" style="margin-top:10px">'
                            . $this->trans('Mode URL optionnel (nécessite d\'autoriser le fichier dans la config du serveur web) :', [], 'Modules.Odoosalesync.Admin')
                            . '</p><code>' . htmlspecialchars($cronUrl) . '</code>',
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Enregistrer', [], 'Modules.Odoosalesync.Admin'),
                    'name' => 'submitOdooSyncSettings',
                ],
                'buttons' => [
                    [
                        'title' => $this->trans('Tester la connexion', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'submitOdooSyncTest',
                        'type' => 'submit',
                        'icon' => 'process-icon-refresh',
                        'class' => 'btn btn-default pull-right',
                    ],
                    [
                        'title' => $this->trans('Synchroniser maintenant', [], 'Modules.Odoosalesync.Admin'),
                        'name' => 'submitOdooSyncNow',
                        'type' => 'submit',
                        'icon' => 'process-icon-export',
                        'class' => 'btn btn-default pull-right',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->module = $this->module;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->submit_action = 'submitOdooSyncSettings';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminOdooSyncSettings', false);
        $helper->token = Tools::getAdminTokenLite('AdminOdooSyncSettings');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fieldsForm]);
    }

    /**
     * Condition de paiement : liste déroulante alimentée depuis Odoo.
     *
     * On stocke l'identifiant, stable et sans ambiguïté de langue — « 30 Days » sur une base
     * anglophone, « 30 jours » sur une base française. Si Odoo est injoignable au moment
     * d'afficher l'écran, on retombe sur une saisie libre du nom pour ne pas bloquer.
     */
    protected function paymentTermField()
    {
        $terms = $this->fetchPaymentTerms();

        if ($terms === null) {
            return [
                'type' => 'text',
                'label' => $this->trans('Condition de paiement Odoo', [], 'Modules.Odoosalesync.Admin'),
                'name' => 'ODOOSALESYNC_PAYMENT_TERM',
                'desc' => $this->trans('Odoo est injoignable : la liste des conditions n\'a pas pu être chargée. Saisissez le nom exact de la condition (ex. « 30 jours »), ou revenez sur cet écran une fois la connexion rétablie pour la choisir dans une liste.', [], 'Modules.Odoosalesync.Admin'),
                'class' => 'fixed-width-lg',
            ];
        }

        $options = [['id' => 0, 'name' => $this->trans('— Celle du client dans Odoo —', [], 'Modules.Odoosalesync.Admin')]];

        foreach ($terms as $term) {
            $options[] = ['id' => (int) $term['id'], 'name' => $term['name']];
        }

        return [
            'type' => 'select',
            'label' => $this->trans('Condition de paiement Odoo', [], 'Modules.Odoosalesync.Admin'),
            'name' => 'ODOOSALESYNC_PAYMENT_TERM_ID',
            'desc' => $this->trans('Condition appliquée à la facture créée dans Odoo. La liste provient directement de votre Odoo.', [], 'Modules.Odoosalesync.Admin'),
            'options' => ['query' => $options, 'id' => 'id', 'name' => 'name'],
        ];
    }

    /**
     * @return array|null les conditions de paiement Odoo, ou null si Odoo est injoignable
     */
    protected function fetchPaymentTerms()
    {
        try {
            return OdooOrderSync::buildClientFromConfig()->searchRead('account.payment.term', [], ['id', 'name']);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Statuts de commande PrestaShop, pour les listes déroulantes de déclenchement.
     */
    protected function orderStateOptions()
    {
        $options = [['id' => 0, 'name' => $this->trans('— Désactivé —', [], 'Modules.Odoosalesync.Admin')]];

        foreach (OrderState::getOrderStates((int) $this->context->language->id) as $state) {
            $options[] = ['id' => (int) $state['id_order_state'], 'name' => $state['name']];
        }

        return $options;
    }

    protected function getConfigFieldsValues()
    {
        return [
            'ODOOSALESYNC_URL' => Tools::getValue('ODOOSALESYNC_URL', Configuration::get('ODOOSALESYNC_URL')),
            'ODOOSALESYNC_DB' => Tools::getValue('ODOOSALESYNC_DB', Configuration::get('ODOOSALESYNC_DB')),
            'ODOOSALESYNC_LOGIN' => Tools::getValue('ODOOSALESYNC_LOGIN', Configuration::get('ODOOSALESYNC_LOGIN')),
            'ODOOSALESYNC_API_KEY' => '',
            'ODOOSALESYNC_START_DATE' => Tools::getValue(
                'ODOOSALESYNC_START_DATE',
                OdooOrderSync::formatDateForDisplay(Configuration::get('ODOOSALESYNC_START_DATE'))
            ),
            'ODOOSALESYNC_AUTOCONFIRM' => (int) Configuration::get('ODOOSALESYNC_AUTOCONFIRM'),
            'ODOOSALESYNC_STATE_DELIVERY' => (int) Configuration::get('ODOOSALESYNC_STATE_DELIVERY'),
            'ODOOSALESYNC_STATE_INVOICE' => (int) Configuration::get('ODOOSALESYNC_STATE_INVOICE'),
            'ODOOSALESYNC_PAYMENT_TERM' => Tools::getValue('ODOOSALESYNC_PAYMENT_TERM', Configuration::get('ODOOSALESYNC_PAYMENT_TERM')),
            'ODOOSALESYNC_PAYMENT_TERM_ID' => (int) Configuration::get('ODOOSALESYNC_PAYMENT_TERM_ID'),
            'ODOOSALESYNC_INVOICE_POST' => (int) Configuration::get('ODOOSALESYNC_INVOICE_POST'),
            'ODOOSALESYNC_SHIPPING_REF' => Tools::getValue('ODOOSALESYNC_SHIPPING_REF', Configuration::get('ODOOSALESYNC_SHIPPING_REF')),
            'ODOOSALESYNC_DISCOUNT_REF' => Tools::getValue('ODOOSALESYNC_DISCOUNT_REF', Configuration::get('ODOOSALESYNC_DISCOUNT_REF')),
        ];
    }
}
