# odoosalesync

Module PrestaShop 8/9 qui crée automatiquement une commande de vente (`sale.order`) et, si besoin, le client (`res.partner`) dans Odoo dès qu'un paiement est validé sur la boutique.

## Ce que fait le module

1. À chaque fois qu'une commande PrestaShop atteint un état de paiement accepté (hook `actionPaymentConfirmation`), le module :
   - recherche le client dans Odoo par email ; s'il n'existe pas, le crée (nom, email, adresse, téléphone, pays) ;
   - retrouve chaque produit de la commande dans Odoo par sa référence (`product.product.default_code` = référence PrestaShop) ;
   - crée une commande `sale.order` avec ces lignes, et la confirme automatiquement (option désactivable) ;
   - enregistre le résultat dans une table de suivi (`ps_odoosync_order`), visible dans **Modules > Synchronisation Odoo > Journal**.
2. Toute erreur (Odoo injoignable, produit non mappé, etc.) est capturée : **le paiement du client n'est jamais bloqué**. L'erreur est journalisée et peut être corrigée puis rejouée manuellement, ou rattrapée automatiquement par le cron.

## Prérequis côté Odoo

1. Créer un utilisateur technique dédié (ex. `prestashop-sync@votredomaine.com`) avec les droits :
   - Ventes : Utilisateur (création/lecture de commandes) ;
   - Contacts : création/lecture.
2. Générer une **clé API** pour cet utilisateur : Réglages > Utilisateurs & Companies > Utilisateurs > (ouvrir l'utilisateur) > onglet Sécurité du compte > Clés API > Nouvelle clé API.
   C'est cette clé, et non le mot de passe du compte, qui doit être utilisée dans la configuration du module.
3. Noter : l'URL de base d'Odoo, le nom de la base de données, le login et la clé API.
4. Vérifier que le serveur PrestaShop peut atteindre l'URL Odoo (`curl https://votre-odoo/jsonrpc` doit répondre, même par une erreur JSON-RPC plutôt qu'un timeout).

## Installation

1. Copier le dossier `odoosalesync/` dans `modules/` de l'installation PrestaShop.
2. Dans le back-office : Modules > Gestionnaire de modules > rechercher "Synchronisation Odoo" > Installer.
3. Aller dans Modules > **Synchronisation Odoo** (ou via le menu Administration créé par le module) et renseigner :
   - URL Odoo, base de données, login API, clé API ;
   - la **date de début de synchro** (voir ci-dessous) ;
   - activer/désactiver la confirmation automatique de la commande dans Odoo.
4. Cliquer sur **Tester la connexion** pour valider les identifiants avant d'enregistrer.

## Date de début de synchro

Champ **Date de début de synchro** (format `AAAA-MM-JJ`) : les commandes PrestaShop **créées avant cette date ne sont jamais envoyées à Odoo**, que ce soit par le hook de paiement ou par le cron de rattrapage.

Objectif : lors d'une première installation sur un PrestaShop **déjà en production**, éviter d'importer tout l'historique des commandes dans Odoo. Seules les commandes à partir de la date choisie sont synchronisées.

- À l'installation (ou à la montée de version 1.1.0), le champ est initialisé automatiquement à la **date du jour**.
- Laisser le champ **vide** pour synchroniser toutes les commandes, sans filtre de date.
- La comparaison se fait sur la date de **création** de la commande (`date_add`), à partir de 00:00:00 le jour indiqué.

## Cron de rattrapage

Ce cron rejoue la synchro pour les commandes payées des dernières 48h qui n'ont pas encore de synchro réussie (utile si Odoo était temporairement indisponible au moment du paiement).

### Mode recommandé : ligne de commande (CLI)

PrestaShop 9 bloque par défaut l'accès HTTP direct aux fichiers `.php` des modules (`modules/.htaccess` → 403). Le cron doit donc être appelé en **CLI** depuis le crontab système, ce qui contourne le serveur web et ne nécessite aucun token :

```
*/10 * * * * www-data php /var/www/html/modules/odoosalesync/cron.php >/dev/null 2>&1
```

(adapter le chemin et l'utilisateur système à votre installation).

### Mode URL (optionnel)

Un token est généré à l'installation et affiché sur l'écran de configuration. Si vous devez impérativement déclencher le cron par une URL (ordonnanceur externe), il faut **autoriser explicitement ce fichier** dans la configuration de votre serveur web (lever le blocage de `modules/.htaccess` pour `cron.php`), puis appeler :

```
https://votre-boutique.example/modules/odoosalesync/cron.php?token=XXXXXXXX
```

## Journal / réessai manuel

Modules > Synchronisation Odoo > **Journal** liste toutes les tentatives de synchro (succès/erreur, message d'erreur, IDs Odoo créés). Un bouton "Réessayer" permet de relancer une commande en échec, et un bouton en haut de la liste permet de réessayer toutes les commandes en erreur d'un coup.

## Limites connues (v1)

- **Taxes** : le module envoie le prix unitaire HT de PrestaShop, mais ne mappe pas finement les taux de TVA PrestaShop vers Odoo — c'est la configuration fiscale du produit/du partenaire dans Odoo qui détermine la taxe appliquée à la ligne. À vérifier/adapter selon votre configuration comptable.
- **Pas de création de produit à la volée** : si une référence produit PrestaShop n'existe pas dans Odoo (`default_code`), la synchro de la commande échoue proprement (visible dans le Journal) plutôt que de créer une commande partielle.
- **Pas de synchronisation retour** : les annulations/remboursements faits dans PrestaShop ne sont pas répercutés automatiquement dans Odoo.
- **Multi-boutique** : le hook s'exécute dans le contexte de la boutique de la commande, et le cron réinitialise le contexte (boutique/langue/devise) à partir de chaque commande traitée. Le mapping produit se fait par référence globale ; si vous gérez des références différentes par boutique, une adaptation peut être nécessaire.

## Guide de test manuel (à faire sur un environnement de STAGING, pas en production)

1. Installer le module, configurer l'URL/DB/login/clé API Odoo, cliquer "Tester la connexion" → doit afficher "Connexion Odoo réussie".
2. Passer une commande test côté PrestaShop avec un moyen de paiement qui valide immédiatement le paiement (ex. paiement en espèces / virement marqué payé en back-office, ou module de paiement de test).
   → Vérifier dans Odoo (Ventes) qu'une commande a bien été créée avec les bonnes lignes de produits et le bon montant, et confirmée si l'option est activée.
   → Vérifier dans Contacts qu'un `res.partner` a été créé avec le bon email/adresse si le client n'existait pas.
   → Vérifier dans Modules > Synchronisation Odoo > Journal qu'une ligne `success` apparaît avec les bons IDs.
3. Repasser une commande pour le **même client** → vérifier qu'aucun doublon de contact n'est créé dans Odoo (le partner existant est réutilisé).
4. Tester le cas d'erreur : renseigner temporairement une mauvaise clé API, passer une commande → vérifier que le paiement PrestaShop aboutit normalement pour le client, qu'une ligne `error` apparaît dans le Journal avec un message clair, puis remettre la bonne clé API et cliquer "Réessayer" (ou attendre le cron) → la commande doit passer en `success`.
5. Tester une référence produit absente d'Odoo → vérifier que la synchro échoue proprement avec un message explicite dans le Journal, sans casser le tunnel de commande PrestaShop.

## Note sur la vérification de ce module

Ce module a été écrit et relu (conventions PrestaShop, cohérence de l'API JSON-RPC Odoo) mais **n'a pas été exécuté** dans un PrestaShop/Odoo réel : aucune instance des deux logiciels n'était disponible dans l'environnement où il a été développé. Le guide de test ci-dessus doit impérativement être suivi sur un environnement de staging avant toute mise en production.
