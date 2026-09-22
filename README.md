# prestashop-odoo-sync

Module PrestaShop 8/9 qui synchronise automatiquement les commandes vers Odoo : dès qu'un paiement est validé sur la boutique, le module crée la commande de vente (`sale.order`) dans Odoo et, si besoin, le client (`res.partner`) correspondant.

Le module suit ensuite le cycle complet de la commande — bon de livraison, facturation — et journalise chaque synchronisation pour permettre un réessai en un clic en cas d'erreur (produit non mappé, Odoo injoignable, etc.), sans jamais bloquer le paiement du client.

## Fonctionnalités principales

- Création automatique de la commande et du client dans Odoo au paiement
- - Rapprochement des produits par référence (`default_code`), avec gestion fine de la TVA (taxes incluses ou non, listes de prix)
  - - Contrôle du total facturé au centime près, avec commande conservée en brouillon en cas d'écart
    - - Suivi du bon de livraison et de la facturation selon les statuts de commande PrestaShop
      - - Protection anti-doublon et réessai manuel depuis un journal de synchronisation dédié
        - - Reprise d'un historique déjà livré, cron de rattrapage, alertes email en cas d'erreur
         
          - ## Documentation
         
          - La documentation complète (prérequis Odoo, génération de la clé API, installation, mise à jour, gestion de la TVA, guide de test, limites connues...) se trouve dans [`odoosalesync/README.md`](odoosalesync/README.md).
         
          - ## Licence
         
          - Distribué sous licence MIT — voir [LICENSE](LICENSE).
          - 
