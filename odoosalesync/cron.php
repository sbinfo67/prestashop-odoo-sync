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

$orders = OdooOrderSync::getOrdersToRetry();
$sync = new OdooOrderSync();

$success = 0;
$failed = 0;

foreach ($orders as $row) {
    try {
        // En CLI, le contexte (boutique/langue/devise) n'est pas initialisé par PrestaShop :
        // on le renseigne depuis la commande, sinon la lecture des produits/prix échoue.
        $order = new Order((int) $row['id_order']);
        if (Validate::isLoadedObject($order)) {
            $context = Context::getContext();
            $context->shop = new Shop((int) $order->id_shop);
            $context->language = new Language((int) $order->id_lang);
            $context->currency = new Currency((int) $order->id_currency);
        }

        $sync->syncOrder((int) $row['id_order']);
        $success++;
    } catch (Throwable $e) {
        $failed++;
    }
}

echo sprintf("Terminé : %d commande(s) synchronisée(s), %d échec(s), %d commande(s) traitée(s) au total.\n", $success, $failed, count($orders));
