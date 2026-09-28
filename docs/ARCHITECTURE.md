# Architecture technique du RISFM

Derniere mise a jour : 5 aout 2026

## Vue d'ensemble

Le RISFM utilise une architecture MVC légère en PHP :

- `public/index.php` initialise l'application et transmet la requête au routeur ;
- `config/routes.php` contient la table des routes, organisée par domaine ;
- `controllers/` orchestre les cas d'utilisation et les contrôles d'accès ;
- `models/` porte l'accès aux données MySQL ;
- `core/` contient les services transverses et métier ;
- `views/` contient les écrans PHP, avec des partials pour les blocs volumineux.

Etat actuel hors dépendances Composer :

- 135 fichiers PHP pour environ 23 800 lignes ;
- 19 classes de contrôleurs et 15 modèles ;
- 28 composants dans `core/` ;
- 80 routes HTTP ;
- 23 tables, 5 vues statistiques et 1 procédure KPI ;
- 21 migrations automatiques ordonnées parmi 23 fichiers SQL de migration.

## Cycle d'une requete

1. `public/index.php` charge la configuration, l'autoloader et les protections
   globales.
2. `Auth::bootSession()` controle immédiatement l'utilisateur, son statut,
   son rôle, sa version de session et le délai d'inactivité.
3. Les en-têtes de sécurité et le contrôle CSRF global sont appliqués.
4. Les routes de `config/routes.php` sont enregistrées dans `Router`.
5. Le contrôleur vérifie la permission métier, utilise les modèles et rend une
   vue ou une réponse de téléchargement/API.

Les erreurs non interceptées sont prises en charge par `ErrorHandler`. En
production, les détails techniques restent dans `storage/logs/php-error.log`
et ne sont pas affichés à l'utilisateur.

## Registre des formulaires

Les responsabilités du registre sont réparties ainsi :

| Contrôleur | Responsabilité |
|---|---|
| `FormulaireController` | liste, consultation, création et modification des informations générales |
| `MissionRechercheController` | affectation, annulation, réaffectation et saisie des résultats |
| `FinalisationController` | progression Retrouvé → Numérisé → Saisi et réouverture |
| `PieceJointeController` | ajout, téléchargement et suppression des pièces |
| `ArchivageController` | archivage et restauration |
| `ImportController` | préparation, validation et import CSV/XLSX |
| `ExportController` | exports CSV, Excel, PDF et Word |

`MissionRechercheActions` isole le workflow volumineux des missions du contrôleur
principal. Il conserve les transactions, les verrouillages, les notifications et
les traces d'audit dans une seule unité métier.

La vue `views/formulaires/show.php` contient le corps de la fiche. Les fenêtres
de dialogue sont regroupées dans `views/formulaires/partials/modals.php`.

## Composants transverses importants

| Composant | Rôle |
|---|---|
| `Auth`, `LoginOtp`, `Permission` | sessions, OTP et contrôle des droits |
| `LoginRateLimiter` | blocage temporaire par identifiant et IP, calcul du delai et retention |
| `Csrf`, `Security`, `EnvironmentGuard` | protection des requêtes et de la configuration |
| `Database`, `Model` | connexion PDO et accès générique aux données |
| `Logger` | journal d'audit avec acteur, cible et valeurs avant/après |
| `AppMailer` | mot de passe oublié, création de compte, OTP, missions et relances |
| `MigrationRunner` | migrations ordonnées, verrouillées et contrôlées par checksum |
| `DatabaseBackup` | sauvegarde, validation et restauration sur base temporaire |
| `MissionReminderService` | rappels d'échéance et escalades |
| `FormulaireImportService` | analyse et import atomique CSV/XLSX |

## Donnees sensibles

- `.env` ne doit jamais être publié ;
- les pièces métier sont dans `storage/uploads/formulaires/`, hors racine Web ;
- les imports temporaires sont dans `storage/imports/` ;
- les sauvegardes sont dans `storage/backups/` ;
- seuls les logos et photos explicitement publics sont dans `public/uploads/`.

Les opérations métier critiques utilisent des transactions MySQL et une trace
d'audit. Une mutation est annulée si sa journalisation obligatoire échoue.

## Règles de modification

- Une route doit être ajoutée dans `config/routes.php`, pas dans le point d'entrée.
- Une action POST doit vérifier l'authentification, la permission et le jeton CSRF.
- Une mutation métier importante doit être transactionnelle et journalisée.
- Une règle métier partagée doit être placée dans `core/` ou dans un composant
  métier dédié, pas dupliquée dans une vue.
- Une nouvelle migration doit être enregistrée dans `config/migrations.php`.

## Vérifications minimales

Avant livraison :

```bash
find controllers views config -name '*.php' -print0 | xargs -0 -n1 php -l
php scripts/test_formulaire_import.php
php scripts/test_p12_acceptance.php
git diff --check
```
