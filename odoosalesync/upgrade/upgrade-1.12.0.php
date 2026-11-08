<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Étiquette Odoo posée sur les fiches clients, pour regrouper ceux de la boutique.
 * Aucune étiquette par défaut : tant que rien n'est choisi dans la configuration,
 * le module ne touche pas aux fiches clients.
 */
function upgrade_module_1_12_0($module)
{
    if (!Configuration::hasKey('ODOOSALESYNC_PARTNER_TAG')) {
        Configuration::updateValue('ODOOSALESYNC_PARTNER_TAG', '');
        Configuration::updateValue('ODOOSALESYNC_PARTNER_TAG_ID', 0);
    }

    return true;
}
