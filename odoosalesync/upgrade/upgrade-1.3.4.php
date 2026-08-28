<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Supprime les lignes de journal sans commande associée (id_order = 0).
 *
 * Le bouton « Réessayer » transmettait un identifiant de commande vide : chaque clic créait
 * une ligne fantôme « Commande PrestaShop #0 introuvable ». Ces lignes ne correspondent à
 * aucune commande et n'ont donc rien à conserver.
 */
function upgrade_module_1_3_4($module)
{
    return Db::getInstance()->execute(
        'DELETE FROM `' . _DB_PREFIX_ . 'odoosync_order` WHERE id_order = 0'
    );
}
