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
            return [
                'id_odoo_order' => (int) $existing['id_odoo_order'],
                'id_odoo_partner' => (int) $existing['id_odoo_partner'],
            ];
        }

        // Une tentative précédente peut avoir créé la commande dans Odoo puis échoué ensuite
        // (confirmation, contrôle du TTC...). Sans ce garde-fou, le rattrapage en créerait une
        // seconde. On ne recrée que si la commande a réellement disparu d'Odoo (suppression
        // manuelle après correction).
        if ($existing && (int) $existing['id_odoo_order'] > 0) {
            $idExistingOdooOrder = (int) $existing['id_odoo_order'];

            if ($this->odooOrderExists($idExistingOdooOrder)) {
                return [
                    'id_odoo_order' => $idExistingOdooOrder,
                    'id_odoo_partner' => (int) $existing['id_odoo_partner'],
                    'already_in_odoo' => true,
                ];
            }
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
     * Si aucune taxe ne correspond au taux, on n'impose rien : Odoo applique la fiscalité de
     * l'article, et l'éventuel écart est détecté par le contrôle du TTC.
     */
    private function lineTaxValues($rate, array $productTaxIds, $priceExcl, $priceIncl)
    {
        $tax = $this->resolveTax($rate, $productTaxIds);

        if (!$tax) {
            return ['price_unit' => $priceExcl];
        }

        return [
            'price_unit' => !empty($tax['price_include']) ? $priceIncl : $priceExcl,
            // Odoo 19 : le champ s'appelle tax_ids sur sale.order.line (tax_id dans les versions
            // antérieures) — un mauvais nom déclenche "Invalid field ... on model sale.order.line".
            'tax_ids' => [[6, 0, [(int) $tax['id']]]],
        ];
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
     * Vérifie qu'une commande existe toujours dans Odoo (elle a pu être supprimée manuellement).
     */
    private function odooOrderExists($idOdooOrder)
    {
        try {
            $rows = $this->client->searchRead('sale.order', [['id', '=', (int) $idOdooOrder]], ['id'], 1);

            return !empty($rows);
        } catch (Throwable $e) {
            // Odoo injoignable : on suppose la commande présente plutôt que risquer un doublon.
            return true;
        }
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
                  AND (s.id_odoosync_order IS NULL OR s.status = "error")
                ORDER BY o.id_order ASC
                LIMIT ' . (int) $limit;

        return Db::getInstance()->executeS($sql) ?: [];
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

                $sync->syncOrder((int) $row['id_order']);
                $success++;
            } catch (Throwable $e) {
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed, 'total' => count($orders)];
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
