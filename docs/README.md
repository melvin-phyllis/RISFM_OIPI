# Documentation du RISFM

Derniere mise a jour : 4 aout 2026

Ce dossier constitue la documentation technique et fonctionnelle de reference
du projet. En cas d'ecart avec un ancien courriel ou une ancienne archive, le
code versionne, `schema.sql`, `config/migrations.php` et les documents
ci-dessous font foi jusqu'a validation du cahier des charges final.

## Pour comprendre le projet

1. [Architecture technique](ARCHITECTURE.md) : organisation du code, routes et
   responsabilites des controleurs.
2. [Audit fonctionnel priorise](AUDIT_FONCTIONNEL_PRIORISE.md) : etat reel des
   fonctions P1 a P12 et reste a valider.
3. [Matrice des droits](MATRICE_DROITS_FORMULAIRES.md) : capacites de chaque
   role dans le registre.
4. [Modules avances](ROADMAP_MODULES_AVANCES.md) : fonctions hors noyau V1 qui
   exigent une confirmation metier.

## Pour installer ou exploiter

- [Guide de deploiement](GUIDE_DEPLOIEMENT.md)
- [Configuration des relances](CONFIGURATION_RELANCES.md)
- [Sauvegardes et restaurations](CONFIGURATION_SAUVEGARDE_RESTAURATION.md)
- [Import CSV et Excel](IMPORT_REGISTRE.md)
- [Recette automatisee](RECETTE_P12.md)
- [Performances sur grands volumes](PERFORMANCES_GRAND_VOLUME.md)

## Etat de reference

- PHP 8.1+ et MySQL 8/MariaDB 10.6+ ;
- 23 tables, 5 vues et 1 procedure KPI ;
- 21 migrations ordonnees dans `config/migrations.php` ;
- 80 routes centralisees dans `config/routes.php` ;
- 4 roles : administrateur, responsable, agent et consultation ;
- protection des connexions par identifiant et IP avec retention configurable ;
- recette complete reussie sur base isolee le 4 aout 2026.

## Commandes essentielles

```bash
composer install
php scripts/migrate.php
php scripts/migrate.php --status
php scripts/create_admin.php
php scripts/recette.php
```

`schema.sql` sert uniquement a une installation neuve. Il ne doit jamais etre
reimporte sur une base existante contenant des donnees.
