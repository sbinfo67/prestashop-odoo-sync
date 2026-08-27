<?php
/**
 * Montée de version 1.0.0 -> 1.1.0 : introduction de la date de début de synchro.
 *
 * Sur une installation déjà en production, on initialise la date de début à la date
 * de la montée de version : les commandes antérieures ne seront pas synchronisées,
 * seules les nouvelles le seront. La valeur reste modifiable dans la configuration.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    // Ne pas écraser une valeur éventuellement déjà présente.
    $current = Configuration::get('ODOOSALESYNC_START_DATE');

    if ($current === false || trim((string) $current) === '') {
        Configuration::updateValue('ODOOSALESYNC_START_DATE', date('Y-m-d'));
    }

    return true;
}
