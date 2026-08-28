<?php
/**
 * Cron de rattrapage : relance la synchro Odoo pour les commandes payées qui n'ont pas
 * encore de synchro réussie (ex. Odoo injoignable au moment du paiement).
 *
 * DEUX MODES D'EXÉCUTION :
 *
 * 1. En ligne de commande (RECOMMANDÉ) — via le crontab système, contourne Apache :
 *      * / 10 * * * * php /var/www/html/modules/odoosalesync/cron.php
 *    Aucun token n'est requis en CLI (exécution locale de confiance).
 *
 * 2. Par URL (optionnel) — nécessite un token. ATTENTION : PrestaShop 9 bloque par défaut
 *    l'accès HTTP direct aux .php des modules (modules/.htaccess => 403). Pour utiliser ce
 *    mode, il faut autoriser explicitement ce fichier dans la configuration du serveur web.
 *      curl "https://votre-boutique/modules/odoosalesync/cron.php?token=XXXX"
 */

require_once dirname(__DIR__, 2) . '/config/config.inc.php';
require_once __DIR__ . '/classes/OdooOrderSync.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');

    // En mode web, on exige le token (le mode CLI est déjà protégé par l'accès système).
    $expectedToken = Configuration::get('ODOOSALESYNC_CRON_TOKEN');
    $providedToken = Tools::getValue('token');

    if (!$expectedToken || !$providedToken || !hash_equals((string) $expectedToken, (string) $providedToken)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

$result = OdooOrderSync::runCatchUp();

echo sprintf(
    "Terminé : %d commande(s) synchronisée(s), %d échec(s), %d commande(s) traitée(s) au total.\n",
    $result['success'],
    $result['failed'],
    $result['total']
);

// Une facture n'a de numéro qu'une fois validée dans Odoo : si elle l'est entre-temps,
// le journal se complète tout seul au passage suivant du cron.
try {
    $filled = (new OdooOrderSync())->backfillNames();

    if (array_sum($filled)) {
        echo sprintf(
            "Numéros Odoo récupérés : %d commande(s), %d bon(s) de livraison, %d facture(s).\n",
            $filled['order'],
            $filled['picking'],
            $filled['invoice']
        );
    }
} catch (Throwable $e) {
    echo 'Récupération des numéros impossible : ' . $e->getMessage() . "\n";
}
