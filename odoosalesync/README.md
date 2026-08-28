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

1. Choisir le compte Odoo que le module utilisera pour se connecter. Il lui faut les droits :
   - Ventes : Utilisateur (création/lecture de commandes) ;
   - Contacts : création/lecture.

   **Compte dédié ou compte existant ?** Un utilisateur technique dédié (ex. `prestashop-sync@votredomaine.com`) est préférable — droits limités au strict nécessaire, révocable sans impacter personne. Mais sur **Odoo Enterprise, chaque utilisateur interne consomme une licence payante** : créer un compte dédié uniquement pour la synchronisation augmente la facture. Il n'existe pas de type de compte « API » gratuit (un utilisateur portail n'a pas les droits nécessaires pour créer des commandes de vente).

   Réutiliser un compte existant est donc un compromis acceptable. À garder en tête dans ce cas :
   - la clé API donne à PrestaShop **tous les droits de ce compte** ;
   - les commandes créées dans Odoo apparaîtront comme saisies par cet utilisateur ;
   - si le compte est désactivé (départ d'un salarié, par exemple), la synchronisation s'arrête — privilégier un compte pérenne, pas celui d'une personne physique susceptible de partir.

   > Sur Odoo 19, si vous créez un compte dédié, le faire **par l'interface**. Le champ `groups_id` de `res.users` a été renommé dans cette version : un script d'import qui l'utilise échoue avec `ValueError: Invalid field 'groups_id'`.

2. Générer une **clé API** pour ce compte (voir la section suivante, c'est le point qui piège le plus souvent).
3. Noter : l'URL de base d'Odoo, le nom de la base de données, le login et la clé API.
4. Vérifier que le serveur PrestaShop peut atteindre l'URL Odoo (`curl https://votre-odoo/jsonrpc` doit répondre, même par une erreur JSON-RPC plutôt qu'un timeout).

### Générer la clé API (⚠ piège classique)

Dans Odoo, **une clé API ne peut être générée que par l'utilisateur lui-même, pour son propre compte**. Un administrateur ne peut pas en créer pour un autre utilisateur depuis l'interface : en ouvrant la fiche d'un autre utilisateur, l'onglet « Sécurité » n'affiche que le mot de passe et la 2FA, **sans section « Clés API »**. Ce n'est pas un problème de droits.

**Méthode 1 — depuis l'interface (sans accès serveur)**

Se connecter à Odoo **avec le compte concerné lui-même**, puis :
avatar en haut à droite > **Préférences** (Mon profil) > onglet **Sécurité du compte** > **Nouvelle clé API** > confirmer avec le mot de passe de ce compte.

La clé n'est affichée qu'une seule fois : la copier immédiatement dans la configuration du module.

**Méthode 2 — depuis le serveur (Odoo on-premise)**

Utile si le mot de passe du compte n'est pas connu, ou pour automatiser le déploiement.

Ouvrir un shell Odoo. La commande doit être lancée **en tant qu'utilisateur système `odoo`** et pointer sur le fichier de configuration, sinon Odoo tente de se connecter à PostgreSQL avec le rôle correspondant à l'utilisateur courant et échoue (`FATAL: role "root" does not exist`) :

```bash
# Installation par paquet (.deb / .rpm) — cas le plus courant
sudo -u odoo odoo shell -c /etc/odoo/odoo.conf -d VOTRE_BASE --no-http

# Variante : identifiants PostgreSQL passés explicitement
sudo -u odoo odoo shell -d VOTRE_BASE \
  --db_host=localhost --db_user=odoo --db_password=VOTRE_MDP_PG --no-http

# Installation depuis les sources (le binaire s'appelle odoo-bin)
sudo -u odoo ./odoo-bin shell -c /etc/odoo/odoo.conf -d VOTRE_BASE --no-http

# Docker
docker compose exec odoo odoo shell -d VOTRE_BASE \
  --db_host=NOM_SERVICE_DB --db_user=odoo --db_password=odoo --no-http
```

Puis, dans le shell :

```python
user = env['res.users'].search([('login', '=', 'LOGIN_DU_COMPTE_ODOO')], limit=1)
key = env['res.users.apikeys'].with_user(user).sudo()._generate('rpc', 'prestashop-sync', False)
print(key)
env.cr.commit()
```

Le `.sudo()` n'est pas optionnel si le compte visé n'est pas administrateur. Sans lui, Odoo répond :

```
ValidationError: La clé API doit avoir une date d'expiration
```

En effet, `with_user()` repasse l'environnement en mode non-privilégié (`su=False`), et `_check_expiration_date` n'autorise une clé sans expiration que pour un utilisateur « système ». Le `.sudo()` restaure ce privilège **sans changer l'utilisateur propriétaire** de la clé, qui reste bien celui recherché.

> **Ne pas contourner l'erreur en passant simplement une date d'expiration.** Pour un compte non administrateur, la durée est plafonnée à `max(group.api_key_duration) or 1.0` jour — sans configuration particulière, la clé expirerait donc **au bout d'un jour** et la synchronisation s'arrêterait silencieusement (les commandes partiraient en `error` dans le journal). Une clé sans expiration, générée en `sudo`, est ce qu'il faut ici.

**À propos du mot de passe** : techniquement, le module fonctionne aussi si l'on saisit le mot de passe du compte à la place de la clé API (Odoo accepte les deux sur `execute_kw`). **C'est déconseillé en production** : le mot de passe se retrouve stocké dans la base PrestaShop, et la synchronisation cesse de fonctionner dès que la double authentification est activée sur ce compte — ce qui n'est pas le cas d'une clé API.

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
