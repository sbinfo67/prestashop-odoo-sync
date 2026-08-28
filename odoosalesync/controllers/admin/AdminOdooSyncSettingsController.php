<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'odoosalesync/classes/OdooClient.php';

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
        $this->content .= $this->renderForm();

        parent::initContent();
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitOdooSyncTest')) {
            $this->testConnection();
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

        $startDate = trim((string) Tools::getValue('ODOOSALESYNC_START_DATE'));
        if ($startDate === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            Configuration::updateValue('ODOOSALESYNC_START_DATE', $startDate);
        } else {
            $this->errors[] = $this->trans('Date de début de synchro invalide (format attendu : AAAA-MM-JJ). Valeur non modifiée.', [], 'Modules.Odoosalesync.Admin');
        }

        $apiKey = trim((string) Tools::getValue('ODOOSALESYNC_API_KEY'));
        if ($apiKey !== '') {
            Configuration::updateValue('ODOOSALESYNC_API_KEY', $apiKey);
        }

        $this->confirmations[] = $this->trans('Paramètres mis à jour.', [], 'Modules.Odoosalesync.Admin');
    }

    protected function testConnection()
    {
        try {
            $client = new OdooClient(
                trim((string) Tools::getValue('ODOOSALESYNC_URL')),
                trim((string) Tools::getValue('ODOOSALESYNC_DB')),
                trim((string) Tools::getValue('ODOOSALESYNC_LOGIN')),
                Configuration::get('ODOOSALESYNC_API_KEY')
            );

            $uid = $client->authenticate();

            $this->confirmations[] = sprintf($this->trans('Connexion Odoo réussie (uid %d).', [], 'Modules.Odoosalesync.Admin'), $uid);
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
                        'desc' => $this->trans('Format AAAA-MM-JJ. Les commandes créées avant cette date ne sont jamais envoyées à Odoo (utile en première installation sur un site déjà en production). Laisser vide pour tout synchroniser.', [], 'Modules.Odoosalesync.Admin'),
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

    protected function getConfigFieldsValues()
    {
        return [
            'ODOOSALESYNC_URL' => Tools::getValue('ODOOSALESYNC_URL', Configuration::get('ODOOSALESYNC_URL')),
            'ODOOSALESYNC_DB' => Tools::getValue('ODOOSALESYNC_DB', Configuration::get('ODOOSALESYNC_DB')),
            'ODOOSALESYNC_LOGIN' => Tools::getValue('ODOOSALESYNC_LOGIN', Configuration::get('ODOOSALESYNC_LOGIN')),
            'ODOOSALESYNC_API_KEY' => '',
            'ODOOSALESYNC_START_DATE' => Tools::getValue('ODOOSALESYNC_START_DATE', Configuration::get('ODOOSALESYNC_START_DATE')),
            'ODOOSALESYNC_AUTOCONFIRM' => (int) Configuration::get('ODOOSALESYNC_AUTOCONFIRM'),
        ];
    }
}
