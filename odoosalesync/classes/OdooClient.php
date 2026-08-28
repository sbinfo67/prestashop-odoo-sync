<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class OdooClientException extends Exception
{
}

/**
 * Client JSON-RPC minimaliste pour l'API externe d'Odoo (endpoint /jsonrpc).
 * Volontairement sans dépendance externe (cURL brut) pour rester autonome.
 */
class OdooClient
{
    /** @var string */
    private $url;

    /** @var string */
    private $db;

    /** @var string */
    private $login;

    /** @var string */
    private $apiKey;

    /** @var int|null */
    private $uid;

    public function __construct($url, $db, $login, $apiKey)
    {
        // On attend l'URL de base d'Odoo, le point de terminaison /jsonrpc étant ajouté par call().
        // Une URL saisie avec ce suffixe est tolérée, sinon on appellerait /jsonrpc/jsonrpc.
        $this->url = rtrim(preg_replace('#/jsonrpc/*$#i', '', rtrim(trim((string) $url), '/')), '/');
        $this->db = (string) $db;
        $this->login = (string) $login;
        $this->apiKey = (string) $apiKey;
        $this->uid = null;
    }

    public function authenticate()
    {
        if ($this->uid !== null) {
            return $this->uid;
        }

        $missing = [];
        foreach (['URL' => $this->url, 'base de données' => $this->db, 'login' => $this->login, 'clé API' => $this->apiKey] as $label => $value) {
            if ($value === '') {
                $missing[] = $label;
            }
        }

        if ($missing) {
            throw new OdooClientException('Configuration Odoo incomplète, paramètre(s) manquant(s) : ' . implode(', ', $missing) . '.');
        }

        $result = $this->call('common', 'login', [$this->db, $this->login, $this->apiKey]);

        if (empty($result) || !is_int($result)) {
            throw new OdooClientException('Authentification Odoo refusée : vérifiez la base, le login et la clé API.');
        }

        $this->uid = $result;

        return $this->uid;
    }

    public function executeKw($model, $method, array $args, array $kwargs = [])
    {
        $uid = $this->authenticate();

        return $this->call('object', 'execute_kw', [
            $this->db,
            $uid,
            $this->apiKey,
            $model,
            $method,
            $args,
            $kwargs,
        ]);
    }

    /**
     * Crée un enregistrement et renvoie son id (entier).
     *
     * Odoo, appelé avec une liste de valeurs, renvoie une liste d'ids : on prend le premier.
     * Passer par cette méthode évite le piège du transtypage (int) d'un tableau.
     */
    public function create($model, array $values)
    {
        $result = $this->executeKw($model, 'create', [[$values]]);

        if (is_array($result)) {
            $result = reset($result);
        }

        return (int) $result;
    }

    public function searchRead($model, array $domain, array $fields = [], $limit = 0)
    {
        $kwargs = ['fields' => $fields];

        if ($limit > 0) {
            $kwargs['limit'] = $limit;
        }

        $result = $this->executeKw($model, 'search_read', [$domain], $kwargs);

        return is_array($result) ? $result : [];
    }

    private function call($service, $method, array $args)
    {
        $payload = [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => $service,
                'method' => $method,
                'args' => $args,
            ],
            'id' => mt_rand(1, PHP_INT_MAX),
        ];

        $ch = curl_init($this->url . '/jsonrpc');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlErrno) {
            throw new OdooClientException('Connexion à Odoo impossible : ' . $curlError);
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            throw new OdooClientException('Réponse Odoo invalide (JSON illisible).');
        }

        if (isset($decoded['error'])) {
            $message = $decoded['error']['data']['message']
                ?? $decoded['error']['message']
                ?? 'Erreur Odoo inconnue';

            throw new OdooClientException($message);
        }

        return $decoded['result'] ?? null;
    }
}
