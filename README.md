# OIPI RISFM

Application web de l’Office Ivoirien de la Propriété Intellectuelle destinée au suivi centralisé des formulaires de titres de propriété intellectuelle manquants, introuvables ou en cours de recherche.

RISFM permet de suivre un dossier depuis son inscription au registre jusqu’à sa finalisation, d’éviter les recherches redondantes et de fournir des indicateurs de pilotage fiables.

## Fonctionnalités principales

- registre central des formulaires manquants ;
- recherche, filtres, tri et pagination ;
- affectation et suivi des missions de recherche ;
- cycle de finalisation : retrouvé, numérisé, puis saisi ;
- priorités, échéances, rappels et escalades automatiques ;
- notifications internes et courriels ;
- tableaux de bord et statistiques ;
- exports Excel, PDF et Word ;
- import contrôlé du registre ;
- gestion des utilisateurs, rôles et permissions ;
- référentiels des directions, services, localisations et types de titres ;
- journal d’activité et historique des connexions ;
- sauvegarde et restauration contrôlée de la base de données.

## Prérequis

- PHP 8.1 ou supérieur ;
- MySQL 8.0+ ou MariaDB 10.6+ ;
- Apache avec `mod_rewrite` ;
- Composer 2 ;
- extensions PHP : `pdo_mysql`, `mbstring`, `gd`, `zip`, `dom`, `xml` et `fileinfo`.

## Installation locale rapide

```bash
git clone https://github.com/melvin-phyllis/RISFM_OIPI.git risfm
cd risfm
composer install
cp .env.example .env
```

Créer une base MySQL, renseigner les paramètres `DB_*` dans `.env`, puis exécuter :

```bash
php scripts/migrate.php
php scripts/seed.php
php -S localhost:8000 -t public public/router.php
```

Ouvrir ensuite <http://localhost:8000>.

Compte administrateur initial :

- identifiant : `admin@oipi.ci` ;
- mot de passe provisoire : `Admin_Oipi2026#`.

Le changement du mot de passe est imposé à la première connexion. Relancer `php scripts/seed.php` réinitialise ce compte ; ne pas exécuter cette commande sans raison sur une installation en exploitation.

## Données de démonstration

Uniquement hors production :

```bash
php scripts/seed.php --demo
```

Le seeder de démonstration refuse de s’exécuter lorsque `APP_ENV=production`.

## Commandes utiles

| Besoin | Commande |
|---|---|
| Appliquer les migrations | `php scripts/migrate.php` |
| Vérifier les migrations | `php scripts/migrate.php --status` |
| Initialiser les référentiels et l’administrateur | `php scripts/seed.php` |
| Réinitialiser uniquement l’administrateur | `php scripts/seed.php --only=admin` |
| Prévisualiser les relances | `php scripts/relances.php --preview` |
| Exécuter les relances | `php scripts/relances.php` |
| Contrôler le cron de relances | `php scripts/relances.php --status` |
| Purger les données de sécurité expirées | `php scripts/purge_reset_security_data.php` |
| Contrôler la configuration de production | `php scripts/check_production.php` |
| Lancer la recette automatisée | `php scripts/recette.php` |

## Déploiement sur cPanel

Le guide complet est disponible dans [docs/INSTALLATION_CPANEL.md](docs/INSTALLATION_CPANEL.md).

Points essentiels :

1. faire pointer le domaine vers le dossier `public/` ;
2. créer la base et l’utilisateur MySQL depuis cPanel ;
3. configurer `.env` avec `APP_ENV=production` et `APP_DEBUG=false` ;
4. installer les dépendances avec `composer install --no-dev --optimize-autoloader` ;
5. exécuter les migrations, les seeders et le contrôle de production ;
6. configurer les tâches cron de relances et de maintenance ;
7. activer HTTPS et vérifier les permissions.

## Architecture

```text
app/          cœur applicatif, contrôleurs, services et repositories
config/       configuration et déclaration des migrations
database/     seeders
docs/         documentation technique et guides d’exploitation
migrations/   évolutions versionnées de la base
public/       seule racine Web publique
routes/       routes HTTP par domaine fonctionnel
scripts/      migrations, seeders, cron, contrôles et recettes
storage/      journaux, sauvegardes et fichiers applicatifs non publics
views/        vues PHP
```

Les règles d’architecture détaillées sont décrites dans [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Sécurité de production

- ne jamais versionner ni exposer `.env` ;
- utiliser HTTPS et `APP_FORCE_HTTPS=true` ;
- conserver `APP_DEBUG=false` en production ;
- utiliser des mots de passe uniques pour MySQL et SMTP ;
- changer immédiatement le mot de passe administrateur initial ;
- ne jamais attribuer la permission `777` ;
- sauvegarder la base avant toute mise à jour ;
- exécuter `php scripts/check_production.php` après chaque déploiement.

## Mise à jour

```bash
git pull --ff-only
composer install --no-dev --optimize-autoloader
php scripts/migrate.php
php scripts/check_production.php
```

Toujours effectuer une sauvegarde avant la mise à jour et vérifier l’application sur un environnement de préproduction.

## Documentation

- [Installation sur cPanel](docs/INSTALLATION_CPANEL.md)
- [Guide général de déploiement](docs/GUIDE_DEPLOIEMENT.md)
- [Configuration des relances](docs/CONFIGURATION_RELANCES.md)
- [Sauvegarde et restauration](docs/CONFIGURATION_SAUVEGARDE_RESTAURATION.md)
- [Architecture](docs/ARCHITECTURE.md)

## Licence et confidentialité

Projet propriétaire destiné à l’OIPI. Toute diffusion, copie ou exploitation en dehors du cadre autorisé doit faire l’objet d’une validation préalable.
