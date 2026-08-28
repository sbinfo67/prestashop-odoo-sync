# odoosalesync

Module PrestaShop 8/9 qui crée automatiquement une commande de vente (`sale.order`) et, si besoin, le client (`res.partner`) dans Odoo dès qu'un paiement est validé sur la boutique.

## Ce que fait le module

1. À chaque fois qu'une commande PrestaShop atteint un état de paiement accepté (hook `actionPaymentConfirmation`), le module :
   - recherche le client dans Odoo par email ; s'il n'existe pas, le crée (nom, email, adresse, téléphone, pays) ;
   - retrouve chaque produit de la commande dans Odoo par sa référence (`product.product.default_code` = référence PrestaShop, comparaison **sensible à la casse**) ;
   - crée une commande `sale.order` avec ces lignes, et la confirme automatiquement (option désactivable) ;
   - enregistre le résultat dans une table de suivi (`ps_odoosync_order`), consultable via le bouton **Ouvrir le journal de synchronisation** de l'écran de configuration.
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
3. Ouvrir la configuration du module (Gestionnaire de modules > **Configurer**) et renseigner :
   - URL Odoo, base de données, login API, clé API ;
   - la **date de début de synchro** (voir ci-dessous) ;
   - les **références des articles Odoo de frais de port et de remise** (voir « Montants et TVA ») ;
   - activer/désactiver la confirmation automatique de la commande dans Odoo.
4. Cliquer sur **Tester la connexion** pour valider les identifiants avant d'enregistrer.

## Mettre à jour le module

> **Ne jamais désinstaller/réinstaller pour mettre à jour.** La désinstallation supprime la table du journal de synchronisation **et toute la configuration** : URL, base, login, clé API et date de début. Il faudrait tout resaisir, et regénérer une clé API dans Odoo.

La mise à jour conserve la configuration et le journal : seuls les fichiers sont remplacés.

**Méthode 1 — par le back-office (zip)**

1. Créer une archive du dossier du module (le zip doit contenir un dossier `odoosalesync/` à sa racine) :
   ```bash
   zip -r odoosalesync.zip odoosalesync
   ```
2. Modules > Gestionnaire de modules > **Ajouter un module** > envoyer le zip.
   PrestaShop écrase les fichiers existants et détecte le changement de version.

**Méthode 2 — par copie de fichiers (serveur)**

1. Remplacer le contenu de `modules/odoosalesync/` par la nouvelle version.
2. Rétablir les droits si nécessaire : `chown -R www-data:www-data modules/odoosalesync`.
3. Déclencher la mise à jour :
   ```bash
   sudo -u www-data php bin/console prestashop:module upgrade odoosalesync
   ```
   À défaut, un simple passage sur la page Gestionnaire de modules suffit généralement à la déclencher.

**Vérifier que la mise à jour a bien été prise en compte**

Le numéro de version affiché sous le nom du module dans le Gestionnaire de modules doit correspondre à celui de la nouvelle version. S'il reste bloqué sur l'ancien :

```bash
sudo -u www-data php bin/console cache:clear
```

PrestaShop met en cache les informations des modules ; tant que le cache n'est pas vidé, l'ancienne version peut continuer à s'afficher — et surtout, les anciens fichiers PHP peuvent rester chargés.

**Notes**

- Les scripts du dossier `upgrade/` (ex. `upgrade-1.1.0.php`) sont exécutés automatiquement par PrestaShop lors du passage à la version correspondante ; ils n'écrasent jamais un paramètre déjà renseigné.
- La version est déclarée à deux endroits qui doivent rester cohérents : `$this->version` dans `odoosalesync.php` et `<version>` dans `config.xml`.
- Après mise à jour, vérifier rapidement que la page de configuration s'ouvre et cliquer sur **Tester la connexion**.

## Montants et TVA : le TTC PrestaShop fait foi

Le montant qui compte est celui réellement encaissé par le prestataire de paiement (Stripe). Le module s'assure que la commande Odoo reproduit ce montant, et refuse de laisser passer un écart silencieux.

**Ce qui est transmis à Odoo**

| Élément | Source PrestaShop | Ligne Odoo |
| --- | --- | --- |
| Produits | prix unitaire HT + taux de TVA de la ligne | ligne article, taxe imposée explicitement |
| Frais de port | `total_shipping_tax_excl` | article de service dédié (référence à configurer) |
| Remises / bons | une ligne **par bon de réduction** | article de service dédié, en montant **négatif** |

**Comment la TVA PrestaShop est reliée à celle d'Odoo**

Le rapprochement se fait sur le **taux**, mais en partant des taxes déjà configurées sur l'article Odoo — ce qui préserve la distinction biens / services et le bon compte comptable. Pour chaque ligne, dans cet ordre :

1. **Les taxes de l'article Odoo** (`taxes_id`) sont examinées ; si l'une d'elles a le taux appliqué par PrestaShop, c'est elle qui est retenue. C'est le cas normal, et le meilleur : bon taux *et* taxe choisie par votre comptable.
2. **À défaut**, une taxe de vente Odoo au bon taux est recherchée globalement. Cela arrive quand PrestaShop vend à un taux que l'article Odoo ne prévoit pas (produit configuré à 20 % côté Odoo mais vendu à 5,5 %). Le TTC reste exact, mais la taxe retenue peut ne pas être celle qu'aurait choisie votre comptable — un signe que les deux catalogues divergent.
3. **Si aucune ne correspond**, rien n'est imposé : Odoo applique la fiscalité de l'article, et l'écart de TTC est signalé.

Concrètement, si votre Odoo contient deux taxes à 20 % (une pour les biens, une pour les services), un article de biens reçoit la taxe « biens » et l'article de frais de port la taxe « services », automatiquement — sans table de correspondance à maintenir.

Le **libellé** de la taxe Odoo n'a aucune importance : seul son champ *Montant* est comparé, à 0,05 point près. Cette marge absorbe les arrondis : le taux du port et des remises est déduit de montants déjà arrondis au centime (22,75 HT / 24,00 TTC donne 5,4945 % pour une TVA à 5,5 %), et les taux réels sont trop éloignés les uns des autres pour créer une confusion.

**Taxes « prix TTC inclus »**

Une taxe Odoo peut être configurée en *prix TTC inclus* (`price_include`, affichée **INC** dans l'interface) : le prix saisi sur l'article contient alors déjà la TVA. C'est fréquent en vente au détail et avec le Point de Vente.

Le module s'y adapte ligne par ligne : il transmet le prix **TTC** quand la taxe retenue est en mode inclus, et le prix **HT** sinon. Les deux modes peuvent coexister dans une même commande — par exemple des produits en TVA « INC » et des frais de port en TVA hors taxes.

> Sans cette adaptation, un prix HT transmis à une taxe « INC » serait interprété par Odoo comme un prix TTC : le total de la commande tomberait alors exactement sur le montant **hors taxes**, et l'écart signalé vaudrait la totalité de la TVA.

**Listes de prix Odoo**

Le module transmet toujours le prix effectivement pratiqué dans PrestaShop, ligne par ligne. Une **liste de prix** configurée dans Odoo (tarif e-commerce, remise par client, prix fixe par article…) **ne modifie pas** ces montants : Odoo ne recalcule le prix que lorsqu'il doit le déterminer lui-même, à la saisie manuelle. Un prix transmis explicitement l'emporte, même si la liste de prix est attachée à la commande et prévoit un autre montant.

C'est le comportement recherché — le montant encaissé fait foi — mais deux conséquences en découlent :

- vos listes de prix ne servent pas pour les commandes synchronisées, et un écart entre le tarif Odoo et le prix PrestaShop ne sera pas signalé ;
- si vous **modifiez ensuite une ligne dans Odoo** (changement de quantité, par exemple), Odoo peut recalculer le prix à partir de la liste de prix et s'écarter du montant facturé.

**Contrôle du total**

Après création, le module compare le `amount_total` d'Odoo au `total_paid_tax_incl` de PrestaShop, avec une tolérance d'un centime. En cas d'écart :

- la commande Odoo **n'est pas confirmée** (elle reste en devis/brouillon) ;
- la synchronisation passe en **erreur**, avec les deux montants et l'écart dans le journal ;
- la commande Odoo est **conservée** et son identifiant enregistré : aucune tentative ultérieure n'en créera de doublon.

**Que faire en cas d'écart**

La cause la plus fréquente est un taux de TVA présent dans PrestaShop mais absent d'Odoo (par exemple 10 % ou 5,5 %), ou une référence d'article port/remise non configurée. Après correction dans Odoo, il suffit de cliquer sur **Réessayer** dans le journal : le module supprime la commande en brouillon issue de la tentative ratée et la reconstruit avec les bons montants. Rien à supprimer manuellement.

Cette suppression est strictement encadrée. Le module refuse d'y toucher, avec un message explicite, si :

- la commande Odoo est **déjà confirmée** — elle a des implications comptables, à vous de la corriger ou de l'annuler ;
- sa **référence client** ne correspond pas à la commande PrestaShop traitée, signe qu'elle ne provient pas de cette synchronisation ;
- **Odoo est injoignable** : la synchronisation s'interrompt plutôt que de risquer un doublon.

> Le module ne « force » jamais le TTC en recalculant le prix HT à rebours. Le total correspondrait, mais la ventilation HT/TVA serait fausse — donc la déclaration de TVA également. L'écart est signalé pour être corrigé à la source, dans la configuration fiscale.

**Articles de service pour le port et les remises**

Le module a besoin de deux articles Odoo de type *service*, dont vous renseignez les références internes (`default_code`) dans sa configuration :

- un article pour les **frais de port** ;
- un article pour les **remises**, si la boutique utilise des bons de réduction.

Inutile d'en créer de nouveaux si votre Odoo en possède déjà (l'article « Livraison » du module Odoo de livraison, par exemple) : relevez simplement leur référence interne existante et reportez-la dans le module. Seul cas nécessitant une intervention dans Odoo : un article **sans référence interne** — le module ne peut pas le retrouver, il faut donc lui en attribuer une (champ libre, sans incidence comptable).

**La taxe portée par ces articles est celle qui sera appliquée** à la ligne de port ou de remise. Vérifiez donc qu'elle correspond bien à la TVA de vos frais de livraison.

Pour lister vos articles de service avec leur référence et leur taxe, dans un shell Odoo :

```python
for p in env['product.product'].search([('type', '=', 'service')]):
    print("%-20s | %-35s | %s" % (
        p.default_code or '(AUCUNE REFERENCE)',
        p.name,
        ', '.join(t.name for t in p.taxes_id) or '(aucune taxe)'
    ))
```

Si la référence n'est pas renseignée dans le module, l'élément correspondant n'est pas transmis — le total Odoo sera alors inférieur au montant encaissé, et l'écart signalé.

### Les références sont sensibles à la casse

Le rapprochement des articles se fait par **égalité stricte** sur `default_code` : `livraison`, `Livraison` et `LIVRAISON` sont trois références différentes. Cela vaut aussi bien pour les articles de port et de remise que pour les **produits du catalogue**.

Recopiez donc la référence telle qu'elle apparaît dans Odoo, sans changer la casse ni ajouter d'espace. Une casse divergente produit exactement la même erreur qu'une référence absente : `Aucun produit Odoo trouvé pour la référence "..."`, ou l'article de port introuvable.

## Livraison et facturation

Au-delà de la commande, le module suit deux étapes déclenchées par les **statuts de commande PrestaShop**, choisis dans sa configuration.

**Validation du bon de livraison** — au statut configuré (par défaut *Préparation en cours*, celui que PrestaShop applique à l'impression du bon de livraison), le module valide le BL Odoo de la commande.

Si le stock Odoo ne couvre pas l'intégralité des lignes, **rien n'est validé** : la synchro passe en erreur en nommant les articles et les quantités manquantes. L'opérateur ajuste le stock dans Odoo, puis relance depuis le journal — ou valide le bon à la main. Une validation partielle n'est jamais faite d'office : elle créerait des reliquats et une facture incomplète.

**Facturation** — au statut configuré (par défaut *Expédié*), le module crée la facture Odoo, lui applique la condition de paiement choisie et la comptabilise.

Le bon de livraison doit être validé au préalable, sans quoi la facture reprendrait des lignes vides. Le module le vérifie et refuse de facturer tant que ce n'est pas le cas, en le signalant clairement.

La condition de paiement se choisit dans une **liste déroulante alimentée directement depuis votre Odoo** : pas de nom à saisir, donc pas d'écart de langue (« 30 Days » sur une base anglophone, « 30 jours » sur une base française) ni de faute de frappe. Le module retient l'identifiant Odoo, stable, et conserve le libellé comme repli si cet identifiant venait à disparaître.

Si Odoo est injoignable au moment d'afficher l'écran de configuration, le champ retombe sur une saisie libre du nom, afin de ne pas bloquer le paramétrage.

> La comptabilisation automatique peut être désactivée : la facture est alors créée en brouillon et vous la validez vous-même dans Odoo. C'est le réglage à privilégier pour une mise en route, le temps de contrôler les premiers cycles.
>
> Une facture en brouillon **n'a ni numéro ni date** dans Odoo : les deux lui sont attribués au moment de la validation. Une facture créée aujourd'hui et validée dans trois semaines portera donc la date de validation, et son échéance courra à partir de là. Aucune écriture ne peut ainsi tomber dans une période déjà déclarée, mais si vous validez par lots, tout le lot sera daté du jour de validation.

Ces deux étapes apparaissent dans le journal, colonnes **BL Odoo** et **Facture Odoo**, avec le numéro Odoo cliquable. Une étape non encore déclenchée reste vide (`—`), ce qui la distingue d'un échec.

Une facture affichée **Brouillon** n'a pas encore de numéro : dans Odoo, il n'est attribué qu'à la validation. Le journal se complète tout seul au passage suivant du cron, qui récupère les numéros des pièces validées entre-temps.

Contrairement aux factures, une **commande** et un **bon de livraison** reçoivent leur numéro dès leur création dans Odoo : un identifiant affiché à leur place signifie seulement qu'il n'a pas encore été relevé, jamais qu'il n'existe pas. Le cron les complète comme les factures.

Si une colonne affiche `id 4700` au lieu d'un numéro, c'est que la pièce Odoo est connue mais que son numéro n'a pas été relevé — lignes antérieures à la version 1.3.5, ou document rattaché sans relecture. Le bouton **Récupérer les numéros Odoo manquants**, dans le bandeau au-dessus de la liste, va les chercher dans Odoo et complète le journal.

> Si une relance affiche « sans étape à exécuter » et que ces colonnes restent vides, c'est que le statut PrestaShop de la commande ne figure dans aucun des statuts configurés. C'est le cas typique de commandes en *Livré* alors que seuls *Préparation en cours* et *Expédié* sont renseignés : ajoutez *Livré* aux **statuts déclenchant le cycle complet**. Le bouton **Réessayer** rejoue l'étape en erreur, sans refaire celles déjà réussies.

## Reprise d'un historique déjà livré

Lors de la première synchronisation d'une boutique en production, les commandes passées sont souvent déjà livrées. Valider leur bon de livraison puis créer leur facture une par une dans Odoo serait interminable.

Le champ **Statuts déclenchant le cycle complet** répond à ce cas : une liste à choix multiples (Ctrl+clic pour en sélectionner plusieurs) où une commande dont le statut PrestaShop figure (typiquement *Livré*) enchaîne **commande, bon de livraison et facture** en une seule passe, via le bouton *Synchroniser maintenant* ou le cron.

Le rattrapage reprend aussi les commandes **déjà synchronisées** dont il reste une étape à faire : celles envoyées dans Odoo avant l'activation de la livraison et de la facturation ne sont pas laissées de côté. Aucune commande Odoo n'est recréée au passage.

Une commande **déjà facturée dans Odoo** (facture établie à la main, par exemple) est rattachée telle quelle, sans qu'une seconde facture soit créée.

Chaque étape est ignorée si elle a déjà réussi : relancer la chaîne est donc sans risque. Si un bon de livraison échoue faute de stock, corrigez le stock dans Odoo puis cliquez sur **Réessayer** — la reprise repart du bon de livraison et poursuit jusqu'à la facture, sans recréer la commande.

> **Le stock Odoo est décrémenté au moment de la validation des bons de livraison**, y compris pour des commandes anciennes. Si vous avez déjà ajusté manuellement votre stock Odoo pour tenir compte de ces ventes, il serait décompté deux fois. Reprenez l'historique **avant** de caler votre stock, ou ajustez-le après coup.

### Dates

- La commande Odoo porte la **date réelle de la commande PrestaShop**. Odoo réinitialise cette date lors de la confirmation ; le module la rétablit ensuite, faute de quoi tout un historique se retrouverait daté du jour de la reprise.
- La facture, elle, est datée du **jour de la synchronisation**, date comptable comprise. C'est volontaire : antidater une facture ferait entrer des écritures dans une période potentiellement déjà déclarée. L'échéance court donc à partir de la date de reprise.

## Suivi depuis la liste des commandes

Une colonne **Odoo** est ajoutée à la liste des commandes de PrestaShop, avec un pictogramme par état :

| Pictogramme | Signification |
| --- | --- |
| coche verte | commande synchronisée dans Odoo |
| coche cerclée verte | livraison et/ou facture également traitées |
| triangle rouge | erreur — cliquez pour ouvrir le journal |
| tiret gris | commande jamais synchronisée |

Le pictogramme renvoie au journal de synchronisation.

> La définition de la liste des commandes est mise en cache par PrestaShop : la colonne n'apparaît qu'une fois ce cache vidé. **Le module s'en charge automatiquement lors de la mise à jour**, aucune manipulation n'est nécessaire.
>
> Si la colonne manque malgré tout, le bouton **Vider le cache PrestaShop** de l'écran de configuration fait la même chose en un clic. En dernier recours : Paramètres avancés > Performances > Vider le cache, ou `php bin/console cache:clear` en ligne de commande.

## Alerte par email

Le cron envoie un **récapitulatif** des commandes en erreur à l'adresse configurée, avec un **délai minimal entre deux envois** (60 minutes par défaut).

Ce fonctionnement est délibéré : une indisponibilité d'Odoo met toutes les commandes en erreur, et une alerte par commande inonderait la boîte au moment précis où l'on a besoin d'y voir clair. Un seul message récapitule l'ensemble.

- Laisser l'adresse **vide** désactive les alertes.
- Un délai de **0** envoie un message à chaque passage du cron ayant des erreurs.
- L'horodatage n'est mis à jour qu'en cas d'envoi réussi : si le mail échoue, la tentative sera refaite au passage suivant.

## Lancer une synchronisation manuelle

Le bouton **Synchroniser maintenant**, sur l'écran de configuration, traite les commandes payées en attente (jamais synchronisées, ou en erreur) depuis la date de début. Utile pour :

- lancer la toute première synchronisation après l'installation, sans attendre une nouvelle commande ;
- rattraper des commandes après avoir corrigé la cause d'une erreur (référence produit manquante dans Odoo, par exemple).

Contrairement au cron, il n'applique **pas** de fenêtre glissante de 48 h : seule la date de début limite la portée. Le traitement se fait par lots de 50 commandes — s'il en reste, relancer le bouton.

## Date de début de synchro

Champ **Date de début de synchro** (format `JJ/MM/AAAA`) : les commandes PrestaShop **créées avant cette date ne sont jamais envoyées à Odoo**, que ce soit par le hook de paiement, le bouton de synchronisation manuelle ou le cron de rattrapage.

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

Le journal s'ouvre depuis l'écran de configuration du module, bouton **Ouvrir le journal de synchronisation** (les onglets créés par le module n'apparaissent pas dans le menu latéral de PrestaShop 9). Il liste toutes les tentatives de synchro (succès/erreur, message d'erreur, commande et client Odoo créés).

Les colonnes **Commande PrestaShop**, **Commande Odoo** et **Client Odoo** sont cliquables et ouvrent directement la fiche correspondante dans le logiciel concerné. Les liens vers Odoo passent par sa route `/mail/view`, qui laisse Odoo déterminer lui-même le format d'URL adapté à sa version.

> Ces liens utilisent l'**URL Odoo configurée dans le module**. Elle doit donc être joignable depuis le navigateur de l'administrateur, et pas seulement depuis le serveur PrestaShop — une adresse interne (`http://odoo:8069`) produirait des liens inutilisables.

La colonne **Commande Odoo** affiche le numéro tel qu'il apparaît dans Odoo (ex. `S00513`), et non l'identifiant technique de la base — ce dernier ne permet pas de retrouver la commande depuis l'interface d'Odoo. Les lignes antérieures à la version 1.3.5 n'ont pas ce numéro et affichent `id 513` ; elles restent retrouvables dans Odoo en cherchant la **référence client**, où le module inscrit la référence de la commande PrestaShop.

Trois façons de relancer une synchronisation :

- le bouton **Réessayer** d'une ligne ;
- les **cases à cocher** et l'action groupée « Réessayer la synchronisation », pour un lot choisi ;
- le bouton **Réessayer toutes les synchros en erreur**, dans le bandeau au-dessus de la liste.

> La seule action groupée proposée est le réessai. La suppression de lignes du journal est volontairement absente : supprimer une ligne en succès rendrait la commande à nouveau éligible, et le cron en créerait un doublon dans Odoo.

## Limites connues (v1)

- **Taxes sans équivalent Odoo** : si ni l'article ni le référentiel Odoo ne proposent une taxe au taux vendu, le module n'impose rien et laisse Odoo appliquer la fiscalité de l'article. L'écart de TTC qui en découle est alors détecté et signalé (voir ci-dessous), mais il faut créer la taxe manquante dans Odoo pour le résoudre.
- **Pas de création de produit à la volée** : si une référence produit PrestaShop n'existe pas dans Odoo (`default_code`), la synchro de la commande échoue proprement (visible dans le Journal) plutôt que de créer une commande partielle.
- **Pas de synchronisation retour** : les annulations/remboursements faits dans PrestaShop ne sont pas répercutés automatiquement dans Odoo.
- **Multi-boutique** : le hook s'exécute dans le contexte de la boutique de la commande, et le cron réinitialise le contexte (boutique/langue/devise) à partir de chaque commande traitée. Le mapping produit se fait par référence globale ; si vous gérez des références différentes par boutique, une adaptation peut être nécessaire.

## Guide de test manuel (à faire sur un environnement de STAGING, pas en production)

1. Installer le module, configurer l'URL/DB/login/clé API Odoo, cliquer "Tester la connexion" → doit afficher "Connexion Odoo réussie".
2. Passer une commande test côté PrestaShop avec un moyen de paiement qui valide immédiatement le paiement (ex. paiement en espèces / virement marqué payé en back-office, ou module de paiement de test).
   → Vérifier dans Odoo (Ventes) qu'une commande a bien été créée avec les bonnes lignes de produits et le bon montant, et confirmée si l'option est activée.
   → Vérifier dans Contacts qu'un `res.partner` a été créé avec le bon email/adresse si le client n'existait pas.
   → Vérifier dans le journal de synchronisation (bouton depuis l'écran de configuration) qu'une ligne `success` apparaît avec les bons IDs.
3. Repasser une commande pour le **même client** → vérifier qu'aucun doublon de contact n'est créé dans Odoo (le partner existant est réutilisé).
4. Tester le cas d'erreur : renseigner temporairement une mauvaise clé API, passer une commande → vérifier que le paiement PrestaShop aboutit normalement pour le client, qu'une ligne `error` apparaît dans le Journal avec un message clair, puis remettre la bonne clé API et cliquer "Réessayer" (ou attendre le cron) → la commande doit passer en `success`.
5. Tester une référence produit absente d'Odoo → vérifier que la synchro échoue proprement avec un message explicite dans le Journal, sans casser le tunnel de commande PrestaShop.

## Note sur la vérification de ce module

Le module a été testé sur une installation réelle **PrestaShop 9.1.4 + Odoo 19** (conteneurs jetables) : création de la commande et du client dans Odoo à la validation du paiement, correspondance au centime des montants TTC (TVA 20 %, lignes de port et de remise), choix de la bonne taxe quand deux taxes partagent le même taux (biens vs services), repli sur le référentiel quand l'article ne porte pas le taux vendu, détection d'un écart quand un taux n'existe nulle part dans Odoo, absence de doublon après tentatives répétées, réutilisation d'un client existant sans doublon, idempotence du hook, échec propre sans casser le tunnel de commande quand Odoo est injoignable ou qu'une référence produit manque, rattrapage par le cron, filtre de date de début, et rendu des deux écrans du back-office.

Ces tests portent sur **Odoo 19 Community** : les modèles utilisés (`res.partner`, `product.product`, `sale.order`) et l'API JSON-RPC sont identiques en Enterprise, mais un module Enterprise modifiant le flux de vente pourrait changer le comportement.

Le guide de test ci-dessus reste à exécuter sur votre propre environnement de staging avant mise en production : lui seul reflète votre configuration fiscale, vos références produits et vos modules tiers.
