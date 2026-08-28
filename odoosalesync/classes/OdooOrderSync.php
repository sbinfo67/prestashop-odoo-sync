<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/OdooClient.php';

class OdooOrderSyncException extends Exception
{
}

class OdooOrderSync
{
    /** @var OdooClient */
    private $client;

    /** @var array<string,int|null> cache ISO country code => Odoo res.country id, pour la durée de la requête */
    private static $countryCache = [];

    /** @var array<string,array|null> cache taux de TVA => enregistrement de taxe Odoo */
    private static $taxCache = [];

    /** @var array<int,array> cache identifiant de taxe => caractéristiques (taux, type) */
    private static $taxDetailCache = [];

    /** @var array<string,array|null> cache référence => article Odoo et ses taxes */
    private static $productCache = [];

    /** Écart maximal toléré entre le TTC PrestaShop et le TTC Odoo, en devise de la commande. */
    const AMOUNT_TOLERANCE = 0.01;

    /**
     * Tolérance, en points de pourcentage, pour rapprocher un taux de TVA d'une taxe Odoo.
     * Les taux du port et des remises sont déduits de montants déjà arrondis au centime :
     * 22,75 HT / 24,00 TTC donne 5,4945 % pour une TVA à 5,5 %. Les taux réels étant séparés
     * d'au moins 0,4 point (2,1 / 5,5 / 10 / 20), cette marge ne crée pas d'ambiguïté.
     */
    const RATE_TOLERANCE = 0.05;

    /** @var int|null identifiants créés lors de la tentative en cours, conservés en cas d'échec ultérieur */
    private $lastOdooOrder = null;

    /** @var int|null */
    private $lastOdooPartner = null;

    /** @var string|null numéro de commande Odoo (ex. S00513), affiché dans le journal */
    private $lastOdooOrderName = null;

    public function __construct(OdooClient $client = null)
    {
        $this->client = $client ?: self::buildClientFromConfig();
    }

    public static function buildClientFromConfig()
    {
        return new OdooClient(
            Configuration::get('ODOOSALESYNC_URL'),
            Configuration::get('ODOOSALESYNC_DB'),
            Configuration::get('ODOOSALESYNC_LOGIN'),
            Configuration::get('ODOOSALESYNC_API_KEY')
        );
    }

    /**
     * Point d'entrée principal. Idempotent : une commande déjà synchronisée avec succès
     * n'est jamais recréée dans Odoo.
     *
     * @return array{id_odoo_order:int,id_odoo_partner:int}
     */
    public function syncOrder($idOrder)
    {
        $idOrder = (int) $idOrder;

        // Contrôle avant le bloc try : une commande inexistante ne doit pas laisser de trace
        // dans le journal, sans quoi un appel erroné y créerait une ligne fantôme (id_order = 0).
        if ($idOrder <= 0 || !Validate::isLoadedObject(new Order($idOrder))) {
            throw new OdooOrderSyncException('Commande PrestaShop #' . $idOrder . ' introuvable.');
        }

        $existing = $this->getSyncRow($idOrder);

        if ($existing && $existing['status'] === 'success') {
            // Les lignes créées avant la 1.3.5 n'ont pas de numéro de commande Odoo, et ce
            // retour anticipé ne les corrigeait jamais : on complète au passage.
            $this->fillMissingOrderName($existing);

            return [
                'id_odoo_order' => (int) $existing['id_odoo_order'],
                'id_odoo_partner' => (int) $existing['id_odoo_partner'],
            ];
        }

        // Une tentative précédente peut avoir créé la commande dans Odoo puis échoué ensuite
        // (contrôle du TTC, confirmation...). Cette commande est incomplète ou fausse : on la
        // supprime pour la reconstruire, sinon le réessai ne corrigerait rien tout en annonçant
        // un succès. La suppression est strictement encadrée (voir discardFailedOdooOrder).
        if ($existing && (int) $existing['id_odoo_order'] > 0) {
            $this->discardFailedOdooOrder((int) $existing['id_odoo_order'], new Order($idOrder));
        }

        try {
            $result = $this->doSync($idOrder);

            // Commande antérieure à la date de début de synchro : on l'ignore sans rien enregistrer.
            if (!empty($result['skipped'])) {
                return $result;
            }

            $this->saveSyncRow($idOrder, 'success', $result['id_odoo_order'], $result['id_odoo_partner'], null, $this->lastOdooOrderName);

            return $result;
        } catch (Throwable $e) {
            // On conserve les identifiants déjà obtenus : si la commande a été créée dans Odoo
            // avant l'échec, elle ne doit jamais être recréée par une tentative ultérieure.
            $this->saveSyncRow($idOrder, 'error', $this->lastOdooOrder, $this->lastOdooPartner, $e->getMessage(), $this->lastOdooOrderName);

            PrestaShopLogger::addLog(
                'Odoosalesync: échec de synchronisation de la commande #' . $idOrder . ' : ' . $e->getMessage(),
                3,
                null,
                'Order',
                $idOrder,
                true
            );

            throw $e;
        }
    }

    /**
     * Traite une commande de bout en bout : commande, puis livraison et facture selon l'état
     * PrestaShop courant. Chaque étape est ignorée si elle a déjà réussi, ce qui rend l'appel
     * rejouable — un réessai après correction du stock reprend là où il s'était arrêté.
     */
    public function syncPipeline($idOrder)
    {
        $idOrder = (int) $idOrder;
        $result = $this->syncOrder($idOrder);

        if (!empty($result['skipped'])) {
            return $result;
        }

        $steps = $this->stepsForOrder($idOrder);

        foreach ($steps as $step) {
            $this->{$step}($idOrder);
        }

        // Permet à l'appelant de distinguer un vrai traitement d'un passage sans effet,
        // faute de statut configuré pour cette commande.
        $result['steps'] = $steps;

        return $result;
    }

    /**
     * Étapes attendues pour une commande, d'après son état PrestaShop courant.
     * Les états « finalisés » (commandes déjà livrées lors d'un rattrapage d'historique)
     * déclenchent la chaîne complète ; la facture implique toujours la livraison, sans quoi
     * elle serait vide.
     *
     * @return string[]
     */
    public function stepsForOrder($idOrder)
    {
        $order = new Order((int) $idOrder);
        $state = (int) $order->current_state;

        if (!$state) {
            return [];
        }

        if (in_array($state, self::getFullCycleStates(), true)
            || $state === (int) Configuration::get('ODOOSALESYNC_STATE_INVOICE')) {
            return ['syncDelivery', 'syncInvoice'];
        }

        if ($state === (int) Configuration::get('ODOOSALESYNC_STATE_DELIVERY')) {
            return ['syncDelivery'];
        }

        return [];
    }

    /**
     * @return int[] états PrestaShop considérés comme finalisés
     */
    public static function getFullCycleStates()
    {
        $raw = trim((string) Configuration::get('ODOOSALESYNC_STATES_FULL'));

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /**
     * Valide le bon de livraison Odoo de la commande.
     *
     * Rien n'est validé si le stock ne couvre pas l'intégralité des lignes : l'opérateur doit
     * d'abord ajuster le stock dans Odoo. C'est volontaire — une validation partielle créerait
     * des reliquats et une facture incomplète.
     */
    public function syncDelivery($idOrder)
    {
        $idOrder = (int) $idOrder;
        $row = $this->requireSyncedOrder($idOrder);

        if (($row['picking_status'] ?? null) === 'success') {
            return ['id' => (int) $row['id_odoo_picking'], 'name' => $row['odoo_picking_name'], 'message' => null];
        }

        try {
            $result = $this->doSyncDelivery((int) $row['id_odoo_order']);
            $this->saveStepRow($idOrder, 'picking', 'success', $result['id'], $result['name'], $result['message']);

            return $result;
        } catch (Throwable $e) {
            $this->saveStepRow($idOrder, 'picking', 'error', null, null, $e->getMessage());
            throw $e;
        }
    }

    private function doSyncDelivery($idOdooOrder)
    {
        $order = $this->client->searchRead('sale.order', [['id', '=', $idOdooOrder]], ['picking_ids'], 1);

        if (empty($order)) {
            throw new OdooOrderSyncException('Commande Odoo #' . $idOdooOrder . ' introuvable.');
        }

        $pickingIds = array_map('intval', (array) $order[0]['picking_ids']);

        if (empty($pickingIds)) {
            // Commande sans article stocké (services uniquement) : il n'y a rien à livrer.
            return ['id' => null, 'name' => null, 'message' => 'Aucun bon de livraison à valider pour cette commande.'];
        }

        $pickings = $this->client->searchRead(
            'stock.picking',
            [['id', 'in', $pickingIds]],
            ['id', 'name', 'state', 'move_ids']
        );

        $validated = [];
        $lastId = null;
        $lastName = null;

        foreach ($pickings as $picking) {
            $lastId = (int) $picking['id'];
            $lastName = (string) $picking['name'];

            if (in_array($picking['state'], ['done', 'cancel'], true)) {
                continue;
            }

            $this->assertStockAvailable($picking);
            $this->client->executeKw('stock.picking', 'button_validate', [[(int) $picking['id']]]);
            $validated[] = $picking['name'];
        }

        return [
            'id' => $lastId,
            'name' => $lastName,
            'message' => $validated
                ? null
                : 'Bon(s) de livraison déjà validé(s) dans Odoo, aucune action nécessaire.',
        ];
    }

    /**
     * Refuse la validation si une ligne du bon n'est pas intégralement servie par le stock.
     */
    private function assertStockAvailable(array $picking)
    {
        $moveIds = array_map('intval', (array) $picking['move_ids']);

        if (empty($moveIds)) {
            return;
        }

        $moves = $this->client->searchRead(
            'stock.move',
            [['id', 'in', $moveIds]],
            ['product_id', 'product_uom_qty', 'quantity', 'state']
        );

        $missing = [];

        foreach ($moves as $move) {
            if (in_array($move['state'], ['done', 'cancel'], true)) {
                continue;
            }

            $demand = (float) $move['product_uom_qty'];
            $ready = (float) $move['quantity'];

            if ($ready + 0.0001 < $demand) {
                $label = is_array($move['product_id']) ? $move['product_id'][1] : $move['product_id'];
                $missing[] = sprintf('%s (%s sur %s)', $label, $this->qty($ready), $this->qty($demand));
            }
        }

        if ($missing) {
            throw new OdooOrderSyncException(sprintf(
                'Stock Odoo insuffisant pour valider le bon de livraison %s : %s. '
                . 'Ajustez le stock dans Odoo puis relancez, ou validez le bon manuellement.',
                $picking['name'],
                implode(', ', $missing)
            ));
        }
    }

    private function qty($value)
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',');
    }

    /**
     * Crée puis comptabilise la facture Odoo de la commande.
     *
     * Le bon de livraison doit être validé au préalable : la facture reprend les quantités
     * livrées, elle serait donc vide sans lui.
     */
    public function syncInvoice($idOrder)
    {
        $idOrder = (int) $idOrder;
        $row = $this->requireSyncedOrder($idOrder);

        if (($row['invoice_status'] ?? null) === 'success') {
            return ['id' => (int) $row['id_odoo_invoice'], 'name' => $row['odoo_invoice_name'], 'message' => null];
        }

        try {
            $result = $this->doSyncInvoice((int) $row['id_odoo_order']);
            $this->saveStepRow($idOrder, 'invoice', 'success', $result['id'], $result['name'], $result['message']);

            return $result;
        } catch (Throwable $e) {
            $this->saveStepRow($idOrder, 'invoice', 'error', null, null, $e->getMessage());
            throw $e;
        }
    }

    private function doSyncInvoice($idOdooOrder)
    {
        $this->assertDeliveryDone($idOdooOrder);

        $before = $this->client->searchRead('sale.order', [['id', '=', $idOdooOrder]], ['invoice_ids', 'invoice_status'], 1);
        $existing = !empty($before) ? array_map('intval', (array) $before[0]['invoice_ids']) : [];

        // Commande déjà facturée dans Odoo (facture établie à la main, ou reprise d'un suivi
        // perdu) : on rattache la facture existante au lieu d'échouer sur « No items available ».
        if (!empty($before) && $before[0]['invoice_status'] === 'invoiced' && $existing) {
            $idInvoice = (int) end($existing);
            $invoice = $this->client->searchRead('account.move', [['id', '=', $idInvoice]], ['name'], 1);

            return [
                'id' => $idInvoice,
                'name' => !empty($invoice) ? (string) $invoice[0]['name'] : null,
                'message' => 'Facture déjà présente dans Odoo, rattachée sans en créer de nouvelle.',
            ];
        }

        // _create_invoices est une méthode privée, inaccessible par l'API : il faut passer par
        // l'assistant de facturation. Le contexte doit lui être fourni dès sa création, sinon il
        // répond « No items are available to invoice » alors que la commande est facturable.
        $context = ['active_model' => 'sale.order', 'active_ids' => [$idOdooOrder], 'active_id' => $idOdooOrder];

        $idWizard = $this->client->executeKw(
            'sale.advance.payment.inv',
            'create',
            [['advance_payment_method' => 'delivered']],
            ['context' => $context]
        );

        if (is_array($idWizard)) {
            $idWizard = reset($idWizard);
        }

        $this->client->executeKw('sale.advance.payment.inv', 'create_invoices', [[(int) $idWizard]], ['context' => $context]);

        $after = $this->client->searchRead('sale.order', [['id', '=', $idOdooOrder]], ['invoice_ids'], 1);
        $all = !empty($after) ? array_map('intval', (array) $after[0]['invoice_ids']) : [];
        $new = array_values(array_diff($all, $existing));

        if (empty($new)) {
            throw new OdooOrderSyncException('Odoo n\'a créé aucune facture pour cette commande.');
        }

        $idInvoice = (int) $new[0];
        $this->applyPaymentTerm($idInvoice);

        if (Configuration::get('ODOOSALESYNC_INVOICE_POST')) {
            $this->client->executeKw('account.move', 'action_post', [[$idInvoice]]);
        }

        $invoice = $this->client->searchRead('account.move', [['id', '=', $idInvoice]], ['name', 'state'], 1);

        return [
            'id' => $idInvoice,
            'name' => !empty($invoice) ? (string) $invoice[0]['name'] : null,
            'message' => !empty($invoice) && $invoice[0]['state'] !== 'posted'
                ? 'Facture créée en brouillon (comptabilisation désactivée).'
                : null,
        ];
    }

    /**
     * Vérifie que la livraison est effectivement validée avant de facturer.
     */
    private function assertDeliveryDone($idOdooOrder)
    {
        $order = $this->client->searchRead('sale.order', [['id', '=', $idOdooOrder]], ['picking_ids'], 1);
        $pickingIds = !empty($order) ? array_map('intval', (array) $order[0]['picking_ids']) : [];

        if (empty($pickingIds)) {
            return;
        }

        $pending = $this->client->searchRead(
            'stock.picking',
            [['id', 'in', $pickingIds], ['state', 'not in', ['done', 'cancel']]],
            ['name', 'state']
        );

        if ($pending) {
            throw new OdooOrderSyncException(sprintf(
                'Le bon de livraison %s n\'est pas validé dans Odoo : la facture reprendrait des '
                . 'lignes vides. Validez la livraison, puis relancez la facturation.',
                implode(', ', array_column($pending, 'name'))
            ));
        }
    }

    /**
     * Applique la condition de paiement configurée.
     *
     * L'identifiant Odoo fait foi : il ne dépend pas de la langue de la base, contrairement au
     * nom (« 30 Days » / « 30 jours »). Le nom sert de repli si l'identifiant a disparu, par
     * exemple après une restauration sur une autre base.
     */
    private function applyPaymentTerm($idInvoice)
    {
        $idTerm = (int) Configuration::get('ODOOSALESYNC_PAYMENT_TERM_ID');
        $name = trim((string) Configuration::get('ODOOSALESYNC_PAYMENT_TERM'));

        if (!$idTerm && $name === '') {
            return;
        }

        $found = null;

        if ($idTerm) {
            $rows = $this->client->searchRead('account.payment.term', [['id', '=', $idTerm]], ['id'], 1);
            $found = !empty($rows) ? (int) $rows[0]['id'] : null;
        }

        if (!$found && $name !== '') {
            $rows = $this->client->searchRead('account.payment.term', [['name', '=', $name]], ['id'], 1);
            $found = !empty($rows) ? (int) $rows[0]['id'] : null;
        }

        if (!$found) {
            throw new OdooOrderSyncException(sprintf(
                'Condition de paiement introuvable dans Odoo (%s). Sélectionnez-la de nouveau '
                . 'dans la configuration du module.',
                $idTerm ? 'identifiant ' . $idTerm : 'nom « ' . $name . ' »'
            ));
        }

        $this->client->executeKw('account.move', 'write', [[(int) $idInvoice], ['invoice_payment_term_id' => $found]]);
    }

    /**
     * La commande doit avoir été synchronisée avec succès avant toute étape suivante.
     */
    private function requireSyncedOrder($idOrder)
    {
        $row = $this->getSyncRow($idOrder);

        if (!$row || $row['status'] !== 'success' || (int) $row['id_odoo_order'] <= 0) {
            throw new OdooOrderSyncException(
                'La commande #' . $idOrder . ' n\'est pas encore synchronisée dans Odoo : '
                . 'traitez d\'abord la synchronisation de la commande.'
            );
        }

        return $row;
    }

    /**
     * Enregistre le résultat d'une étape (picking ou invoice) dans le journal.
     */
    private function saveStepRow($idOrder, $step, $status, $idRecord, $name, $message)
    {
        Db::getInstance()->update('odoosync_order', [
            'id_odoo_' . $step => $idRecord !== null ? (int) $idRecord : null,
            'odoo_' . $step . '_name' => $name !== null ? pSQL($name) : null,
            $step . '_status' => pSQL($status),
            $step . '_message' => $message !== null ? pSQL($message, true) : null,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_order = ' . (int) $idOrder);
    }

    private function doSync($idOrder)
    {
        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order)) {
            throw new OdooOrderSyncException('Commande PrestaShop #' . $idOrder . ' introuvable.');
        }

        if (self::isBeforeStartDate($order->date_add)) {
            return ['skipped' => true];
        }

        $customer = new Customer((int) $order->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            throw new OdooOrderSyncException('Client introuvable pour la commande #' . $idOrder . '.');
        }

        $address = new Address((int) $order->id_address_invoice);
        $products = $order->getProducts();

        if (empty($products)) {
            throw new OdooOrderSyncException('La commande #' . $idOrder . ' ne contient aucun produit.');
        }

        $idOdooPartner = $this->findOrCreatePartner($customer, $address);
        $this->lastOdooPartner = $idOdooPartner;

        $orderLines = $this->buildOrderLines($products);
        $orderLines = array_merge($orderLines, $this->buildShippingLines($order), $this->buildDiscountLines($order));

        $idOdooOrder = $this->client->create('sale.order', [
            'partner_id' => $idOdooPartner,
            'client_order_ref' => $order->reference,
            'date_order' => $order->date_add,
            'order_line' => $orderLines,
        ]);

        if (!$idOdooOrder) {
            throw new OdooOrderSyncException('Odoo n\'a pas renvoyé d\'identifiant pour la commande créée.');
        }

        $this->lastOdooOrder = $idOdooOrder;

        // Contrôle du montant TTC : c'est le montant réellement encaissé (Stripe) qui fait foi.
        // Un écart signifie que la fiscalité Odoo ne reproduit pas celle de PrestaShop : on ne
        // confirme pas la commande et on remonte l'écart, mais on la conserve dans Odoo.
        $this->assertTotalMatches($order, $idOdooOrder);

        if (Configuration::get('ODOOSALESYNC_AUTOCONFIRM')) {
            $this->client->executeKw('sale.order', 'action_confirm', [[$idOdooOrder]]);

            // Odoo remet date_order à l'instant présent en confirmant (_prepare_confirmation_values).
            // On rétablit la date réelle de la commande PrestaShop, indispensable pour un rattrapage
            // d'historique où les commandes datent de plusieurs semaines.
            $this->client->executeKw('sale.order', 'write', [[$idOdooOrder], ['date_order' => $order->date_add]]);
        }

        return [
            'id_odoo_order' => $idOdooOrder,
            'id_odoo_partner' => $idOdooPartner,
            'odoo_order_name' => $this->lastOdooOrderName,
        ];
    }

    /**
     * Compare le TTC calculé par Odoo au montant payé dans PrestaShop.
     * Lève une exception si l'écart dépasse la tolérance : la commande reste créée dans Odoo
     * (et non confirmée), l'écart est visible dans le journal de synchronisation.
     */
    private function assertTotalMatches(Order $order, $idOdooOrder)
    {
        $rows = $this->client->searchRead('sale.order', [['id', '=', (int) $idOdooOrder]], ['amount_total', 'name'], 1);

        if (empty($rows)) {
            return;
        }

        // Le numéro affiché dans Odoo (ex. S00513) diffère de l'identifiant technique :
        // sans lui, la commande est introuvable depuis le journal.
        $this->lastOdooOrderName = isset($rows[0]['name']) ? (string) $rows[0]['name'] : null;

        $odooTotal = (float) $rows[0]['amount_total'];
        $shopTotal = (float) $order->total_paid_tax_incl;
        $delta = round($odooTotal - $shopTotal, 2);

        if (abs($delta) > self::AMOUNT_TOLERANCE) {
            throw new OdooOrderSyncException(sprintf(
                'Écart de montant TTC : PrestaShop %.2f, Odoo %.2f (écart %+.2f). '
                . 'La commande Odoo #%d a été créée mais non confirmée : vérifiez les taux de TVA '
                . 'et les articles de port/remise côté Odoo.',
                $shopTotal,
                $odooTotal,
                $delta,
                (int) $idOdooOrder
            ));
        }
    }

    /**
     * Ligne de frais de port. L'article de service Odoo est désigné par sa référence,
     * paramétrable dans le module ; si elle n'est pas renseignée, le port n'est pas transmis
     * (l'écart de TTC qui en résulte sera signalé par le contrôle des montants).
     */
    private function buildShippingLines(Order $order)
    {
        $shippingExcl = (float) $order->total_shipping_tax_excl;

        if (round($shippingExcl, 2) <= 0) {
            return [];
        }

        $reference = trim((string) Configuration::get('ODOOSALESYNC_SHIPPING_REF'));

        if ($reference === '') {
            return [];
        }

        $odooProduct = $this->findProductDataByReference($reference);

        if (!$odooProduct) {
            throw new OdooOrderSyncException(
                'Article de frais de port introuvable dans Odoo pour la référence "' . $reference . '".'
            );
        }

        $shippingIncl = (float) $order->total_shipping_tax_incl;

        return [[0, 0, array_merge(
            [
                'product_id' => $odooProduct['id'],
                'name' => 'Frais de port',
                'product_uom_qty' => 1,
            ],
            $this->lineTaxValues(
                $this->effectiveRate($shippingExcl, $shippingIncl),
                $odooProduct['taxes'],
                $shippingExcl,
                $shippingIncl
            )
        )]];
    }

    /**
     * Ligne de remise, en négatif. Même principe que le port pour l'article Odoo utilisé.
     */
    private function buildDiscountLines(Order $order)
    {
        $discountExcl = (float) $order->total_discounts_tax_excl;

        if (round($discountExcl, 2) <= 0) {
            return [];
        }

        $reference = trim((string) Configuration::get('ODOOSALESYNC_DISCOUNT_REF'));

        if ($reference === '') {
            return [];
        }

        $odooProduct = $this->findProductDataByReference($reference);

        if (!$odooProduct) {
            throw new OdooOrderSyncException(
                'Article de remise introuvable dans Odoo pour la référence "' . $reference . '".'
            );
        }

        $discountIncl = (float) $order->total_discounts_tax_incl;

        // Une ligne par règle de réduction : deux bons de nature différente (un sur un produit
        // à 5,5 %, un sur le port à 20 %) donnent, une fois agrégés par PrestaShop, un taux
        // moyen qui ne correspond à aucune taxe Odoo. Pris séparément, chacun retrouve la sienne.
        $lines = [];

        foreach ($order->getCartRules() as $rule) {
            $ruleIncl = round((float) $rule['value'], 2);
            $ruleExcl = round((float) $rule['value_tax_excl'], 2);

            if ($ruleIncl <= 0 && $ruleExcl <= 0) {
                continue;
            }

            $lines[] = [0, 0, array_merge(
                [
                    'product_id' => $odooProduct['id'],
                    'name' => trim((string) $rule['name']) !== '' ? $rule['name'] : 'Remise',
                    'product_uom_qty' => 1,
                ],
                $this->lineTaxValues(
                    $this->effectiveRate($ruleExcl, $ruleIncl),
                    $odooProduct['taxes'],
                    -$ruleExcl,
                    -$ruleIncl
                )
            )];
        }

        if ($lines) {
            return $lines;
        }

        // Remise sans règle rattachée (saisie manuelle sur la commande) : on retombe sur le total.
        return [[0, 0, array_merge(
            [
                'product_id' => $odooProduct['id'],
                'name' => 'Remise',
                'product_uom_qty' => 1,
            ],
            $this->lineTaxValues(
                $this->effectiveRate($discountExcl, $discountIncl),
                $odooProduct['taxes'],
                -$discountExcl,
                -$discountIncl
            )
        )]];
    }

    /**
     * Taux de TVA effectif déduit d'un couple HT/TTC, en pourcentage.
     */
    private function effectiveRate($excl, $incl)
    {
        if ($excl <= 0) {
            return 0.0;
        }

        return round((($incl / $excl) - 1) * 100, 3);
    }

    /**
     * Prix unitaire et taxe à poser sur une ligne de commande Odoo.
     *
     * Le prix envoyé dépend du mode de la taxe retenue : une taxe Odoo « prix TTC inclus »
     * (price_include, affichée « INC » dans l'interface) considère que le prix saisi contient
     * déjà la TVA. Lui transmettre un prix HT ferait tomber le total sur le montant hors taxes.
     *
     * Si aucune taxe ne correspond au taux, on n'impose rien : Odoo applique alors la fiscalité
     * de l'article. Le prix est malgré tout aligné sur le mode de ces taxes-là, faute de quoi un
     * article en TTC inclus recevrait un prix HT et le total serait faux sans raison apparente.
     * L'éventuel écart restant est détecté par le contrôle du TTC.
     */
    private function lineTaxValues($rate, array $productTaxIds, $priceExcl, $priceIncl)
    {
        $tax = $this->resolveTax($rate, $productTaxIds);

        if (!$tax) {
            return ['price_unit' => $this->productTaxesIncludePrice($productTaxIds) ? $priceIncl : $priceExcl];
        }

        return [
            'price_unit' => !empty($tax['price_include']) ? $priceIncl : $priceExcl,
            // Odoo 19 : le champ s'appelle tax_ids sur sale.order.line (tax_id dans les versions
            // antérieures) — un mauvais nom déclenche "Invalid field ... on model sale.order.line".
            'tax_ids' => [[6, 0, [(int) $tax['id']]]],
        ];
    }

    /**
     * Indique si les taxes de vente de l'article sont en mode « prix TTC inclus ».
     * Sert à choisir le prix à transmettre quand aucune taxe n'est imposée explicitement :
     * Odoo appliquera ces taxes-là, il faut donc lui parler dans la même unité.
     */
    private function productTaxesIncludePrice(array $productTaxIds)
    {
        foreach ($this->readTaxes($productTaxIds) as $tax) {
            if (!empty($tax['price_include'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Taxe à appliquer sur une ligne, par ordre de préférence :
     *
     * 1. une taxe déjà configurée sur l'article Odoo dont le taux correspond — c'est la bonne
     *    au sens comptable (compte de TVA, distinction biens/services faite par le comptable) ;
     * 2. à défaut, n'importe quelle taxe de vente Odoo au bon taux, pour que le TTC reste juste ;
     * 3. si aucune ne correspond, on n'impose rien : Odoo applique la fiscalité de l'article et
     *    l'écart éventuel est signalé par le contrôle des montants.
     *
     * @param array $productTaxIds identifiants des taxes de vente de l'article Odoo
     *
     * @return array|null enregistrement de taxe (id, amount, price_include...)
     */
    private function resolveTax($rate, array $productTaxIds)
    {
        $rate = round((float) $rate, 3);

        if ($rate <= 0) {
            return null;
        }

        $tax = $this->findTaxAmongProductTaxes($rate, $productTaxIds);

        return $tax ?: $this->findTaxByRate($rate);
    }

    /**
     * Cherche, parmi les taxes portées par l'article Odoo, celle dont le taux correspond.
     */
    private function findTaxAmongProductTaxes($rate, array $productTaxIds)
    {
        if (empty($productTaxIds)) {
            return null;
        }

        foreach ($this->readTaxes($productTaxIds) as $tax) {
            if ($tax['amount_type'] !== 'percent' || $tax['type_tax_use'] !== 'sale') {
                continue;
            }

            if (abs((float) $tax['amount'] - $rate) <= self::RATE_TOLERANCE) {
                return $tax;
            }
        }

        return null;
    }

    /**
     * Lit les caractéristiques de taxes Odoo, avec cache par identifiant.
     */
    private function readTaxes(array $ids)
    {
        $ids = array_map('intval', $ids);
        $missing = array_values(array_diff($ids, array_keys(self::$taxDetailCache)));

        if ($missing) {
            foreach ($this->client->searchRead(
                'account.tax',
                [['id', 'in', $missing]],
                ['id', 'amount', 'amount_type', 'type_tax_use', 'price_include']
            ) as $tax) {
                self::$taxDetailCache[(int) $tax['id']] = $tax;
            }
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset(self::$taxDetailCache[$id])) {
                $out[] = self::$taxDetailCache[$id];
            }
        }

        return $out;
    }

    /**
     * Recherche une taxe de vente Odoo au pourcentage donné, sans considération d'article.
     *
     * @return array|null
     */
    private function findTaxByRate($rate)
    {
        $rate = round((float) $rate, 3);

        if ($rate <= 0) {
            return null;
        }

        $key = (string) $rate;

        if (array_key_exists($key, self::$taxCache)) {
            return self::$taxCache[$key];
        }

        // Comparaison bornée : les taux sont des flottants des deux côtés.
        $result = $this->client->searchRead('account.tax', [
            ['type_tax_use', '=', 'sale'],
            ['amount_type', '=', 'percent'],
            ['amount', '>=', $rate - self::RATE_TOLERANCE],
            ['amount', '<=', $rate + self::RATE_TOLERANCE],
        ], ['id', 'amount', 'amount_type', 'type_tax_use', 'price_include'], 1);

        $tax = !empty($result) ? $result[0] : null;

        if ($tax) {
            self::$taxDetailCache[(int) $tax['id']] = $tax;
        }

        self::$taxCache[$key] = $tax;

        return $tax;
    }

    /**
     * Supprime la commande Odoo issue d'une tentative en échec, pour permettre sa reconstruction.
     *
     * Trois garde-fous avant toute suppression :
     * - Odoo doit répondre (sinon on interrompt, plutôt que de risquer un doublon) ;
     * - la commande doit porter la référence de la commande PrestaShop traitée (c'est bien la nôtre) ;
     * - elle ne doit pas être confirmée : une commande validée a des implications comptables et
     *   n'est jamais supprimée automatiquement.
     */
    private function discardFailedOdooOrder($idOdooOrder, Order $order)
    {
        try {
            $rows = $this->client->searchRead(
                'sale.order',
                [['id', '=', (int) $idOdooOrder]],
                ['id', 'state', 'client_order_ref'],
                1
            );
        } catch (Throwable $e) {
            throw new OdooOrderSyncException(
                'Odoo est injoignable, impossible de vérifier la commande #' . (int) $idOdooOrder
                . ' déjà créée : ' . $e->getMessage()
            );
        }

        // Déjà supprimée manuellement : il n'y a rien à écarter, la reconstruction peut avoir lieu.
        if (empty($rows)) {
            return;
        }

        $odooOrder = $rows[0];

        if ((string) $odooOrder['client_order_ref'] !== (string) $order->reference) {
            throw new OdooOrderSyncException(sprintf(
                'La commande Odoo #%d porte la référence « %s » et non « %s » : elle ne sera pas '
                . 'supprimée. Vérifiez le journal, puis corrigez ou supprimez cette commande dans Odoo.',
                (int) $idOdooOrder,
                (string) $odooOrder['client_order_ref'],
                (string) $order->reference
            ));
        }

        if (!in_array($odooOrder['state'], ['draft', 'sent'], true)) {
            throw new OdooOrderSyncException(sprintf(
                'La commande Odoo #%d est déjà confirmée (état « %s ») : elle n\'est pas modifiée '
                . 'automatiquement. Corrigez-la ou annulez-la dans Odoo, puis relancez la synchronisation.',
                (int) $idOdooOrder,
                (string) $odooOrder['state']
            ));
        }

        $this->client->executeKw('sale.order', 'unlink', [[(int) $idOdooOrder]]);
    }

    private function buildOrderLines(array $products)
    {
        $orderLines = [];

        foreach ($products as $product) {
            $reference = trim((string) $product['product_reference']);

            if ($reference === '') {
                throw new OdooOrderSyncException(
                    'Le produit "' . $product['product_name'] . '" n\'a pas de référence, impossible de le mapper dans Odoo.'
                );
            }

            $odooProduct = $this->findProductDataByReference($reference);

            if (!$odooProduct) {
                throw new OdooOrderSyncException('Aucun produit Odoo trouvé pour la référence "' . $reference . '".');
            }

            $idOdooProduct = $odooProduct['id'];

            // tax_rate est le taux réellement appliqué par PrestaShop sur la ligne. On le
            // reporte sur la taxe Odoo correspondante pour que le TTC coïncide avec l'encaissement.
            $rate = isset($product['tax_rate'])
                ? (float) $product['tax_rate']
                : $this->effectiveRate((float) $product['unit_price_tax_excl'], (float) $product['unit_price_tax_incl']);

            $orderLines[] = [0, 0, array_merge(
                [
                    'product_id' => $idOdooProduct,
                    'name' => $product['product_name'],
                    'product_uom_qty' => (float) $product['product_quantity'],
                ],
                $this->lineTaxValues(
                    $rate,
                    $odooProduct['taxes'],
                    (float) $product['unit_price_tax_excl'],
                    (float) $product['unit_price_tax_incl']
                )
            )];
        }

        return $orderLines;
    }

    private function findOrCreatePartner(Customer $customer, Address $address)
    {
        $email = trim((string) $customer->email);

        if ($email === '') {
            throw new OdooOrderSyncException('Le client #' . $customer->id . ' n\'a pas d\'adresse email.');
        }

        $existing = $this->client->searchRead('res.partner', [['email', '=', $email]], ['id'], 1);

        if (!empty($existing)) {
            return (int) $existing[0]['id'];
        }

        $partnerData = [
            'name' => trim($customer->firstname . ' ' . $customer->lastname),
            'email' => $email,
        ];

        if (Validate::isLoadedObject($address)) {
            $street = trim((string) $address->address1);
            if ($street !== '') {
                $partnerData['street'] = $street;
            }

            if (!empty($address->address2)) {
                $partnerData['street2'] = $address->address2;
            }

            if (!empty($address->city)) {
                $partnerData['city'] = $address->city;
            }

            if (!empty($address->postcode)) {
                $partnerData['zip'] = $address->postcode;
            }

            $phone = $address->phone ?: $address->phone_mobile;
            if (!empty($phone)) {
                $partnerData['phone'] = $phone;
            }

            $idCountry = $this->findCountryId((int) $address->id_country);
            if ($idCountry) {
                $partnerData['country_id'] = $idCountry;
            }
        }

        return $this->client->create('res.partner', $partnerData);
    }

    private function findCountryId($idCountryPs)
    {
        if (!$idCountryPs) {
            return null;
        }

        $isoCode = Country::getIsoById($idCountryPs);

        if (!$isoCode) {
            return null;
        }

        if (array_key_exists($isoCode, self::$countryCache)) {
            return self::$countryCache[$isoCode];
        }

        $result = $this->client->searchRead('res.country', [['code', '=', $isoCode]], ['id'], 1);
        $idOdooCountry = !empty($result) ? (int) $result[0]['id'] : null;

        self::$countryCache[$isoCode] = $idOdooCountry;

        return $idOdooCountry;
    }

    private function findProductByReference($reference)
    {
        $product = $this->findProductDataByReference($reference);

        return $product ? $product['id'] : null;
    }

    /**
     * Article Odoo et ses taxes de vente configurées, pour pouvoir choisir la taxe correcte
     * plutôt que la première du bon taux.
     *
     * @return array{id:int,taxes:int[]}|null
     */
    private function findProductDataByReference($reference)
    {
        if (array_key_exists($reference, self::$productCache)) {
            return self::$productCache[$reference];
        }

        $result = $this->client->searchRead(
            'product.product',
            [['default_code', '=', $reference]],
            ['id', 'taxes_id'],
            1
        );

        $product = null;
        if (!empty($result)) {
            $product = [
                'id' => (int) $result[0]['id'],
                'taxes' => array_map('intval', (array) ($result[0]['taxes_id'] ?? [])),
            ];
        }

        self::$productCache[$reference] = $product;

        return $product;
    }

    public function getSyncRow($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'odoosync_order` WHERE id_order = ' . (int) $idOrder
        );
    }

    private function saveSyncRow($idOrder, $status, $idOdooOrder, $idOdooPartner, $message, $odooOrderName = null)
    {
        $existing = $this->getSyncRow($idOrder);

        $data = [
            'id_order' => (int) $idOrder,
            'id_odoo_order' => $idOdooOrder !== null ? (int) $idOdooOrder : null,
            'id_odoo_partner' => $idOdooPartner !== null ? (int) $idOdooPartner : null,
            'odoo_order_name' => $odooOrderName !== null ? pSQL($odooOrderName) : null,
            'status' => pSQL($status),
            'message' => $message !== null ? pSQL($message, true) : null,
            'date_upd' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            Db::getInstance()->update('odoosync_order', $data, 'id_order = ' . (int) $idOrder);
        } else {
            $data['date_add'] = date('Y-m-d H:i:s');
            Db::getInstance()->insert('odoosync_order', $data);
        }
    }

    /**
     * Commandes payées récentes qui n'ont pas encore de synchro réussie, pour le cron de rattrapage.
     */
    public static function getOrdersToRetry($hours = 48, $limit = 50)
    {
        $startDateCondition = '';
        $startDate = self::getStartDate();
        if ($startDate !== null) {
            $startDateCondition = ' AND o.date_add >= "' . pSQL($startDate) . '"';
        }

        // $hours à null : pas de fenêtre glissante, on ne borne que par la date de début
        // (utilisé par la synchronisation manuelle, qui doit pouvoir rattraper au-delà de 48 h).
        $windowCondition = $hours === null
            ? ''
            : ' AND o.date_add >= DATE_SUB(NOW(), INTERVAL ' . (int) $hours . ' HOUR)';

        $sql = 'SELECT o.id_order
                FROM `' . _DB_PREFIX_ . 'orders` o
                INNER JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.id_order_state = o.current_state
                LEFT JOIN `' . _DB_PREFIX_ . 'odoosync_order` s ON s.id_order = o.id_order
                WHERE os.paid = 1' . $windowCondition . $startDateCondition . '
                  AND (s.id_odoosync_order IS NULL OR s.status = "error"' . self::pendingStepsCondition() . ')
                ORDER BY o.id_order ASC
                LIMIT ' . (int) $limit;

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Complète le numéro de commande Odoo d'une ligne qui n'en a pas.
     * Silencieux en cas d'indisponibilité d'Odoo : ce n'est qu'un confort d'affichage.
     */
    private function fillMissingOrderName(array $row)
    {
        $idOdooOrder = (int) ($row['id_odoo_order'] ?? 0);
        $name = trim((string) ($row['odoo_order_name'] ?? ''));

        if (!$idOdooOrder || ($name !== '' && $name !== '/')) {
            return;
        }

        try {
            $found = $this->client->searchRead('sale.order', [['id', '=', $idOdooOrder]], ['name'], 1);
        } catch (Throwable $e) {
            return;
        }

        if (empty($found) || (string) $found[0]['name'] === '' || (string) $found[0]['name'] === '/') {
            return;
        }

        Db::getInstance()->update(
            'odoosync_order',
            ['odoo_order_name' => pSQL((string) $found[0]['name'])],
            'id_order = ' . (int) $row['id_order']
        );
    }

    /**
     * Envoie, si nécessaire, un récapitulatif des commandes en erreur.
     *
     * Un délai minimal sépare deux envois : une panne d'Odoo fait échouer toutes les commandes,
     * et une alerte par erreur inonderait la boîte au moment où l'on a besoin d'y voir clair.
     *
     * @return array{sent:bool,count:int,reason:string}
     */
    public static function notifyErrors()
    {
        $email = trim((string) Configuration::get('ODOOSALESYNC_ALERT_EMAIL'));

        if ($email === '' || !Validate::isEmail($email)) {
            return ['sent' => false, 'count' => 0, 'reason' => 'alerte désactivée (aucune adresse valide)'];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT id_order, status, picking_status, invoice_status, message, picking_message, invoice_message
             FROM `' . _DB_PREFIX_ . 'odoosync_order`
             WHERE status = "error" OR picking_status = "error" OR invoice_status = "error"
             ORDER BY id_order DESC
             LIMIT 50'
        ) ?: [];

        if (empty($rows)) {
            return ['sent' => false, 'count' => 0, 'reason' => 'aucune erreur'];
        }

        $delay = max(0, (int) Configuration::get('ODOOSALESYNC_ALERT_DELAY'));
        $last = (int) Configuration::get('ODOOSALESYNC_ALERT_LAST');

        if ($delay > 0 && $last && (time() - $last) < $delay * 60) {
            return [
                'sent' => false,
                'count' => count($rows),
                'reason' => 'délai entre deux alertes non écoulé',
            ];
        }

        $txt = '';
        $html = '<ul>';

        foreach ($rows as $row) {
            $reason = trim((string) ($row['message'] ?: ($row['picking_message'] ?: $row['invoice_message'])));
            $step = $row['status'] === 'error'
                ? 'commande'
                : ($row['picking_status'] === 'error' ? 'bon de livraison' : 'facture');

            $line = sprintf('Commande #%d (%s) : %s', (int) $row['id_order'], $step, $reason);
            $txt .= $line . "\n";
            $html .= '<li><strong>Commande #' . (int) $row['id_order'] . '</strong> (' . htmlspecialchars($step) . ') : '
                . htmlspecialchars($reason) . '</li>';
        }

        $html .= '</ul>';

        $sent = Mail::Send(
            (int) Configuration::get('PS_LANG_DEFAULT'),
            'odoosync_alert',
            'Synchronisation Odoo : ' . count($rows) . ' commande(s) en erreur',
            [
                '{count}' => count($rows),
                '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
                '{errors_txt}' => $txt,
                '{errors_html}' => $html,
                '{journal_url}' => self::journalUrl(),
                '{delay}' => $delay,
            ],
            $email,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'odoosalesync/mails/'
        );

        if ($sent) {
            Configuration::updateValue('ODOOSALESYNC_ALERT_LAST', time());
        }

        return [
            'sent' => (bool) $sent,
            'count' => count($rows),
            'reason' => $sent ? 'envoyée' : "échec de l'envoi (vérifiez la configuration e-mail de PrestaShop)",
        ];
    }

    /**
     * Adresse du journal, telle qu'utilisable depuis un email.
     */
    private static function journalUrl()
    {
        $base = rtrim((string) Configuration::get('PS_SHOP_DOMAIN_SSL') ?: (string) Configuration::get('PS_SHOP_DOMAIN'), '/');

        return ($base ? 'https://' . $base : '') . __PS_BASE_URI__ . 'admin';
    }

    /**
     * Renseigne les numéros Odoo manquants dans le journal (commande, BL, facture).
     *
     * Les lignes anciennes, ou celles rattachées à un document existant sans que son numéro
     * ait été relu, n'affichent qu'un identifiant technique — inutilisable pour retrouver la
     * pièce dans Odoo. On va chercher les numéros par lots.
     *
     * @return array<string,int> nombre de numéros récupérés par type
     */
    public function backfillNames()
    {
        $models = [
            'order' => 'sale.order',
            'picking' => 'stock.picking',
            'invoice' => 'account.move',
        ];

        $filled = [];

        foreach ($models as $step => $model) {
            $idColumn = $step === 'order' ? 'id_odoo_order' : 'id_odoo_' . $step;
            $nameColumn = $step === 'order' ? 'odoo_order_name' : 'odoo_' . $step . '_name';

            $rows = Db::getInstance()->executeS(
                'SELECT DISTINCT `' . $idColumn . '` AS id_record
                 FROM `' . _DB_PREFIX_ . 'odoosync_order`
                 WHERE `' . $idColumn . '` > 0
                   AND (`' . $nameColumn . '` IS NULL OR `' . $nameColumn . '` = "" OR `' . $nameColumn . '` = "/")'
            );

            $ids = array_map('intval', array_column($rows ?: [], 'id_record'));
            $filled[$step] = 0;

            if (empty($ids)) {
                continue;
            }

            foreach ($this->client->searchRead($model, [['id', 'in', $ids]], ['id', 'name']) as $record) {
                $name = (string) $record['name'];

                // Une pièce non validée n'a pas encore de numéro dans Odoo (name vaut « / »).
                if ($name === '' || $name === '/') {
                    continue;
                }

                Db::getInstance()->update(
                    'odoosync_order',
                    [$nameColumn => pSQL($name)],
                    '`' . $idColumn . '` = ' . (int) $record['id']
                );
                $filled[$step]++;
            }
        }

        return $filled;
    }

    /**
     * Condition retenant aussi les commandes déjà synchronisées dont il reste une étape à faire.
     *
     * Sans cela, une commande passée en succès avant l'activation de la livraison et de la
     * facturation ne serait jamais reprise : le rattrapage ignore les lignes en succès.
     */
    private static function pendingStepsCondition()
    {
        $invoiceState = (int) Configuration::get('ODOOSALESYNC_STATE_INVOICE');
        $deliveryState = (int) Configuration::get('ODOOSALESYNC_STATE_DELIVERY');

        // États attendant la chaîne complète (livraison puis facture).
        $full = self::getFullCycleStates();
        if ($invoiceState) {
            $full[] = $invoiceState;
        }
        $full = array_values(array_unique(array_filter($full)));

        // États n'attendant que la livraison.
        $deliveryOnly = ($deliveryState && !in_array($deliveryState, $full, true)) ? [$deliveryState] : [];

        $clauses = [];

        if ($full) {
            $clauses[] = '(o.current_state IN (' . implode(',', $full) . ')'
                . ' AND (s.picking_status IS NULL OR s.picking_status = "error"'
                . ' OR s.invoice_status IS NULL OR s.invoice_status = "error"))';
        }

        if ($deliveryOnly) {
            $clauses[] = '(o.current_state IN (' . implode(',', $deliveryOnly) . ')'
                . ' AND (s.picking_status IS NULL OR s.picking_status = "error"))';
        }

        if (!$clauses) {
            return '';
        }

        return ' OR (s.status = "success" AND (' . implode(' OR ', $clauses) . '))';
    }

    /**
     * Synchronise les commandes payées en attente (jamais synchronisées, ou en erreur).
     * Utilisé par le cron et par le bouton « Synchroniser maintenant » du back-office.
     *
     * @param int|null $hours fenêtre glissante en heures, null pour ne pas en appliquer
     *
     * @return array{success:int,failed:int,total:int}
     */
    public static function runCatchUp($hours = 48, $limit = 50)
    {
        $orders = self::getOrdersToRetry($hours, $limit);
        $sync = new self();

        $success = 0;
        $failed = 0;
        $noStep = 0;

        foreach ($orders as $row) {
            try {
                // Hors requête front (CLI, ou back-office), le contexte boutique/langue/devise
                // n'est pas celui de la commande : on le repositionne, sinon la lecture des
                // produits et des prix échoue.
                $order = new Order((int) $row['id_order']);
                if (Validate::isLoadedObject($order)) {
                    $context = Context::getContext();
                    $context->shop = new Shop((int) $order->id_shop);
                    $context->language = new Language((int) $order->id_lang);
                    $context->currency = new Currency((int) $order->id_currency);
                }

                $result = $sync->syncPipeline((int) $row['id_order']);

                if (isset($result['steps']) && empty($result['steps'])) {
                    $noStep++;
                } else {
                    $success++;
                }
            } catch (Throwable $e) {
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed, 'no_step' => $noStep, 'total' => count($orders)];
    }

    /**
     * Date de début de synchro (format "Y-m-d H:i:s"), ou null si non configurée (= tout synchroniser).
     * Les commandes créées avant cette date ne sont jamais envoyées vers Odoo : utile lors d'une
     * première installation sur un PrestaShop déjà en production, pour ne pas importer l'historique.
     */
    public static function getStartDate()
    {
        $raw = trim((string) Configuration::get('ODOOSALESYNC_START_DATE'));

        if ($raw === '') {
            return null;
        }

        $isoDate = self::parseDate($raw);
        if ($isoDate === null) {
            return null;
        }

        // On borne au début de la journée pour inclure toute commande passée le jour choisi.
        return $isoDate . ' 00:00:00';
    }

    /**
     * Analyse une date saisie au format français (JJ/MM/AAAA) ou ISO (AAAA-MM-JJ)
     * et la renvoie au format ISO, seul format stocké et comparable en SQL.
     * Renvoie null si la date est invalide (ex. 31/02/2026).
     *
     * strtotime() est volontairement évité : il interprète "08/09/2026" à l'américaine.
     *
     * @return string|null date au format Y-m-d
     */
    public static function parseDate($raw)
    {
        $raw = trim((string) $raw);

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m)) {
            [, $day, $month, $year] = $m;
        } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $raw, $m)) {
            [, $year, $month, $day] = $m;
        } else {
            return null;
        }

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Formate une date ISO stockée pour l'affichage en français (JJ/MM/AAAA).
     */
    public static function formatDateForDisplay($isoDate)
    {
        $isoDate = trim((string) $isoDate);

        if ($isoDate === '' || !preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $isoDate, $m)) {
            return $isoDate;
        }

        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    public static function isBeforeStartDate($orderDate)
    {
        $startDate = self::getStartDate();

        if ($startDate === null) {
            return false;
        }

        $orderTimestamp = strtotime((string) $orderDate);
        if ($orderTimestamp === false) {
            return false;
        }

        return $orderTimestamp < strtotime($startDate);
    }
}
