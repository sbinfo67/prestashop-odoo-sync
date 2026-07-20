<?php
/**
 * Point d'entrée cron de rattrapage : relance la synchro Odoo pour les commandes payées
 * qui n'ont pas encore de synchro réussie (ex. Odoo injoignable au moment du paiement).
 *
 * À appeler périodiquement (toutes les 5 à 10 minutes), par exemple :
 *   curl -s "https://votre-boutique.example/modules/odoosalesync/cron.php?token=XXXX"
 *
 * Le token est généré à l'installation du module et visible dans
 * Modules > Synchronisation Odoo > Paramètres.
 */

require_once dirname(__DIR__, 2) . '/config/config.inc.php';
require_once __DIR__ . '/classes/OdooOrderSync.php';

header('Content-Type: text/plain; charset=utf-8');

$expectedToken = Configuration::get('ODOOSALESYNC_CRON_TOKEN');
$providedToken = Tools::getValue('token');

if (!$expectedToken || !$providedToken || !hash_equals((string) $expectedToken, (string) $providedToken)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$orders = OdooOrderSync::getOrdersToRetry();
$sync = new OdooOrderSync();

$success = 0;
$failed = 0;

foreach ($orders as $row) {
    try {
        $sync->syncOrder((int) $row['id_order']);
        $success++;
    } catch (Throwable $e) {
        $failed++;
    }
}

echo sprintf("Terminé : %d commande(s) synchronisée(s), %d échec(s), %d commande(s) traitée(s) au total.\n", $success, $failed, count($orders));
