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
        $existing = $this->getSyncRow($idOrder);

        if ($existing && $existing['status'] === 'success') {
            return [
                'id_odoo_order' => (int) $existing['id_odoo_order'],
                'id_odoo_partner' => (int) $existing['id_odoo_partner'],
            ];
        }

        try {
            $result = $this->doSync($idOrder);

            // Commande antérieure à la date de début de synchro : on l'ignore sans rien enregistrer.
            if (!empty($result['skipped'])) {
                return $result;
            }

            $this->saveSyncRow($idOrder, 'success', $result['id_odoo_order'], $result['id_odoo_partner'], null);

            return $result;
        } catch (Throwable $e) {
            $this->saveSyncRow($idOrder, 'error', null, null, $e->getMessage());

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
        $orderLines = $this->buildOrderLines($products);

        $idOdooOrder = $this->client->create('sale.order', [
            'partner_id' => $idOdooPartner,
            'client_order_ref' => $order->reference,
            'date_order' => $order->date_add,
            'order_line' => $orderLines,
        ]);

        if (!$idOdooOrder) {
            throw new OdooOrderSyncException('Odoo n\'a pas renvoyé d\'identifiant pour la commande créée.');
        }

        if (Configuration::get('ODOOSALESYNC_AUTOCONFIRM')) {
            $this->client->executeKw('sale.order', 'action_confirm', [[$idOdooOrder]]);
        }

        return [
            'id_odoo_order' => $idOdooOrder,
            'id_odoo_partner' => $idOdooPartner,
        ];
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

            $idOdooProduct = $this->findProductByReference($reference);

            if (!$idOdooProduct) {
                throw new OdooOrderSyncException('Aucun produit Odoo trouvé pour la référence "' . $reference . '".');
            }

            $orderLines[] = [0, 0, [
                'product_id' => $idOdooProduct,
                'name' => $product['product_name'],
                'product_uom_qty' => (float) $product['product_quantity'],
                'price_unit' => (float) $product['unit_price_tax_excl'],
            ]];
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
        $result = $this->client->searchRead('product.product', [['default_code', '=', $reference]], ['id'], 1);

        return !empty($result) ? (int) $result[0]['id'] : null;
    }

    public function getSyncRow($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'odoosync_order` WHERE id_order = ' . (int) $idOrder
        );
    }

    private function saveSyncRow($idOrder, $status, $idOdooOrder, $idOdooPartner, $message)
    {
        $existing = $this->getSyncRow($idOrder);

        $data = [
            'id_order' => (int) $idOrder,
            'id_odoo_order' => $idOdooOrder !== null ? (int) $idOdooOrder : null,
            'id_odoo_partner' => $idOdooPartner !== null ? (int) $idOdooPartner : null,
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

        $sql = 'SELECT o.id_order
                FROM `' . _DB_PREFIX_ . 'orders` o
                INNER JOIN `' . _DB_PREFIX_ . 'order_state` os ON os.id_order_state = o.current_state
                LEFT JOIN `' . _DB_PREFIX_ . 'odoosync_order` s ON s.id_order = o.id_order
                WHERE os.paid = 1
                  AND o.date_add >= DATE_SUB(NOW(), INTERVAL ' . (int) $hours . ' HOUR)' . $startDateCondition . '
                  AND (s.id_odoosync_order IS NULL OR s.status = "error")
                ORDER BY o.id_order ASC
                LIMIT ' . (int) $limit;

        return Db::getInstance()->executeS($sql) ?: [];
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

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }

        // On borne au début de la journée pour inclure toute commande passée le jour choisi.
        return date('Y-m-d', $timestamp) . ' 00:00:00';
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
