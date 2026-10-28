<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Synchronisation déclenchée par l'état de la commande, et non plus par le seul hook de
 * paiement : les commandes saisies à la main dans le back-office arrivent désormais dans Odoo.
 * Ajoute le repère de dernier passage du cron affiché sur l'écran de configuration.
 */
function upgrade_module_1_10_0($module)
{
    if (!Configuration::hasKey('ODOOSALESYNC_CRON_LAST')) {
        Configuration::updateValue('ODOOSALESYNC_CRON_LAST', 0);
    }

    return true;
}
