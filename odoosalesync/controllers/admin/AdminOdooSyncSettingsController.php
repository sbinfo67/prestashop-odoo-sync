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

        $this->meta_title = $this->l('Synchronisation Odoo');
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

        $apiKey = trim((string) Tools::getValue('ODOOSALESYNC_API_KEY'));
        if ($apiKey !== '') {
            Configuration::updateValue('ODOOSALESYNC_API_KEY', $apiKey);
        }

        $this->confirmations[] = $this->l('Paramètres mis à jour.');
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

            $this->confirmations[] = sprintf($this->l('Connexion Odoo réussie (uid %d).'), $uid);
        } catch (Throwable $e) {
            $this->errors[] = sprintf($this->l('Échec de connexion à Odoo : %s'), $e->getMessage());
        }
    }

    protected function renderForm()
    {
        $cronCli = '*/10 * * * * php ' . _PS_MODULE_DIR_ . 'odoosalesync/cron.php';
        $cronUrl = $this->context->link->getBaseLink() . 'modules/odoosalesync/cron.php?token=' . Configuration::get('ODOOSALESYNC_CRON_TOKEN');

        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Connexion à Odoo'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('URL Odoo'),
                        'name' => 'ODOOSALESYNC_URL',
                        'desc' => $this->l('Exemple : https://odoo.mondomaine.local'),
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Base de données Odoo'),
                        'name' => 'ODOOSALESYNC_DB',
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Login API'),
                        'name' => 'ODOOSALESYNC_LOGIN',
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->l('Clé API'),
                        'name' => 'ODOOSALESYNC_API_KEY',
                        'desc' => $this->l('Laisser vide pour conserver la clé actuellement enregistrée.'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Confirmer automatiquement la commande dans Odoo'),
                        'name' => 'ODOOSALESYNC_AUTOCONFIRM',
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Oui')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('Non')],
                        ],
                    ],
                    [
                        'type' => 'html',
                        'name' => 'cron_info',
                        'label' => $this->l('Cron de rattrapage'),
                        'html_content' => '<p class="help-block">'
                            . $this->l('Recommandé (CLI, contourne le blocage .htaccess de PrestaShop 9) — à ajouter au crontab système :')
                            . '</p><code>' . htmlspecialchars($cronCli) . '</code>'
                            . '<p class="help-block" style="margin-top:10px">'
                            . $this->l('Mode URL optionnel (nécessite d\'autoriser le fichier dans la config du serveur web) :')
                            . '</p><code>' . htmlspecialchars($cronUrl) . '</code>',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Enregistrer'),
                    'name' => 'submitOdooSyncSettings',
                ],
                'buttons' => [
                    [
                        'title' => $this->l('Tester la connexion'),
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
        $helper->module = $this;
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
            'ODOOSALESYNC_AUTOCONFIRM' => (int) Configuration::get('ODOOSALESYNC_AUTOCONFIRM'),
        ];
    }
}
