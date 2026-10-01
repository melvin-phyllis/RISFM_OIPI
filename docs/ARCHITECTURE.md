# Architecture technique du RISFM

Derniere mise a jour : 29 septembre 2026

## Vue d'ensemble

Le RISFM utilise une architecture MVC légère en PHP :

- `public/index.php` initialise l'application et transmet la requête au routeur ;
- `routes/{module}.php` contient les routes de chaque module ; `config/routes.php`
  liste ces fichiers (équivalent du `withRouting()` de l'ERP) ;
- `app/Http/Controllers/{Module}/` lit la requête, contrôle les droits,
  appelle le service et choisit la réponse (vue, message, redirection) ;
- `app/Services/{Module}/` porte les règles métier (missions, finalisation,
  archivage, import, relances) ;
- `app/Repositories/{Module}/` porte l'accès aux données MySQL : c'est la seule
  couche métier qui écrit du SQL. Les seules exceptions sont les composants
  d'infrastructure `MigrationRunner`, `DatabaseBackup` et la base générique
  `Repository` ;
- `app/Core/` contient l'infrastructure (routeur, base, sécurité, session,
  e-mails, journal) ;
- `views/` contient les écrans PHP, avec des partials pour les blocs volumineux ;
- `database/seeders/` écrit les données initiales (`php scripts/seed.php`) :
  référence (rôles, statuts, types de titres, localisations, directions, services, paramètres),
  premier administrateur, et données fictives avec `--demo`. Une relance
  réinitialise le mot de passe administrateur. `schema.sql` ne contient que la
  structure.

Etat actuel de `app/` et `database/`, hors dépendances Composer :

- 135 fichiers PHP ;
- 18 contrôleurs, 14 services métier et 21 repositories, répartis en modules ;
- 26 composants d’infrastructure dans `app/Core/` ;
- 81 routes HTTP ;
- 26 tables, 5 vues statistiques et 1 procédure KPI ;
- 27 migrations automatiques ordonnées parmi 29 fichiers SQL de migration.

## Organisation en modules

Le code suit la même organisation que l'API Laravel de l'ERP OIPI : un
dossier par couche, puis un dossier par module, avec des namespaces PSR-4.

| Namespace | Dossier | Contenu |
|---|---|---|
| `App\Core` | `app/Core/` | Routeur, base, sécurité, session, permissions, journal, e-mails, sauvegardes |
| `App\Http\Controllers\{Module}` | `app/Http/Controllers/` | Contrôleurs |
| `App\Http\Requests\{Module}` | `app/Http/Requests/` | Validation du format des formulaires (FormRequest) |
| `App\Dto\{Module}` | `app/Dto/` | Données validées transmises aux services (DTO) |
| `App\Services\{Module}` | `app/Services/` | Règles métier |
| `App\Repositories\{Module}` | `app/Repositories/` | Accès aux données (SQL) |
| `App\Exceptions` | `app/Exceptions/` | `ValidationException`, `ConfirmationRequiseException` |
| `Database\Seeders` | `database/seeders/` | Données initiales |

| Module | Contrôleurs | Services | Repositories |
|---|---|---|---|
| `Auth` | Auth, Profil | PasswordReset | — |
| `Formulaire` | Formulaire, Archivage, Finalisation, PieceJointe, Import, Export | Formulaire, Archivage, Finalisation, FormulaireImport, FormulaireMetierValidator, FormulaireHistoriqueBuilder | Formulaire, FinalisationFormulaire, PieceJointe, RechercheFormulaire, ReouvertureFormulaire |
| `Mission` | MissionRecherche | MissionRecherche, MissionReminder | MissionRecherche, RelanceMission |
| `Utilisateur` | User | — | User, TokenReset, Connexion, TentativeConnexion |
| `Administration` | Parametre, Sauvegarde, Journal, Connexion | — | Parametre, Sauvegarde, Activite |
| `Referentiel` | — | — | Statut, TypeTitre, Localisation |
| `Pilotage` | Dashboard, Statistique | — | — |
| `Notification` | Notification | — | Notification |
| `Api` | Api (tableaux DataTables) | — | — |

Conventions de nommage reprises de l'ERP :

| Couche | Suffixe de classe | Préfixe des méthodes publiques |
|---|---|---|
| Repository | `Repository` (`FormulaireRepository`) | `repo_` (`repo_find()`, `repo_verrouiller()`) |
| Service | `Service` | `srv_` (`srv_creer()`, `srv_affecter()`) |
| FormRequest | `FormRequest` (`CreateFormulaireFormRequest`) | — |
| DTO | `DTO` (`CreateFormulaireDTO`) | — |
| Contrôleur | `Controller` | `ctrl_` (`ctrl_store()`, `ctrl_assign()`) : obligatoire, le `Router` refuse une route vers une méthode sans ce préfixe |

Les méthodes privées ne portent pas de préfixe.

Les fonctions globales des vues (`url()`, `e()`, `asset()`...) sont dans
`app/Core/helpers.php`. Les vues et scripts importent les classes qu'ils
utilisent avec `use App\...;`.

## Cycle d'une requete

1. `public/index.php` charge la configuration, l'autoloader partagé
   (`config/autoload.php`, aussi utilisé par tous les scripts CLI) et les
   protections globales.
2. `Auth::bootSession()` controle immédiatement l'utilisateur, son statut,
   son rôle, sa version de session et le délai d'inactivité.
3. Les en-têtes de sécurité et le contrôle CSRF global sont appliqués.
4. Les routes des fichiers `routes/*.php` (listés par `config/routes.php`) sont
   enregistrées dans `Router`, qui n'accepte que des actions `ctrl_`.
5. Le contrôleur vérifie la permission métier, délègue l'opération à un
   service ou à un modèle, puis rend une vue ou une réponse de
   téléchargement/API.

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

Une écriture suit le même flux que dans l'ERP :

```
POST ─> Controller ─> FormRequest (format) ─> Service ─> DTO ─> Repository ─> MySQL
           │               └─ erreur : 1er message + redirection (validateRequest)
           └─ droits (Permission), puis message et redirection selon le résultat
```

1. Le contrôleur vérifie les droits, puis appelle
   `$this->validateRequest(XFormRequest::class, $pageRetour)`. En cas d'erreur de
   format, la première erreur est affichée et l'utilisateur est renvoyé sur
   `$pageRetour` : le contrôleur ne gère aucun message de validation.
2. Le FormRequest ne contrôle que la **forme** (identifiants stricts, dates,
   longueurs, listes de valeurs). Tout ce qui demande la base (référence
   existante, compte actif, doublon) reste dans le service, via
   `FormulaireMetierValidator`, également utilisé par l'import CSV/XLSX.
3. Le service reçoit les données validées, construit son DTO
   (`XDTO::fromArray()`), applique les règles métier et écrit via les
   repositories.

| Service | Opérations |
|---|---|
| `FormulaireService` | `srv_creer`, `srv_modifier` |
| `MissionRechercheService` | `srv_affecter`, `srv_annuler`, `srv_reaffecter`, `srv_enregistrerResultat` |
| `FinalisationService` | `srv_avancer` (Retrouvé → Numérisé → Saisi), `srv_rouvrir` |
| `ArchivageService` | `srv_archiver`, `srv_restaurer` |
| `PieceJointeService` | `srv_ajouter`, `srv_supprimer` |
| `UserService` | `srv_creer`, `srv_modifier`, `srv_supprimer`, `srv_basculerStatut`, `srv_renouvelerAcces`, `srv_definirMotDePasse` |
| `ProfilService` | `srv_modifier`, `srv_changerMotDePasse` |
| `PasswordResetService` | `srv_demander`, `srv_reinitialiser` (mot de passe oublié) |
| `ParametreService` | `srv_modifierGeneraux`, `srv_ajouterElement`, `srv_modifierElement`, `srv_basculerElement` |
| `SauvegardeService` | `srv_creer`, `srv_restaurer` (test sur base temporaire + sauvegarde de sécurité), `srv_supprimer` |

Toutes les écritures passent par ce flux, sauf deux, volontairement :

- la connexion et le code de vérification (`AuthController`) réaffichent la
  page de connexion au lieu de rediriger, et s'appuient sur les composants de
  sécurité de `app/Core` (limitation des tentatives, OTP) ;
- l'import CSV/XLSX, dont le fichier est vérifié par
  `FormulaireImportService::srv_inspectFile()` avant un aperçu en session.

Règles d'écriture d'un FormRequest :

- un mot de passe utilise la règle `raw` : il n'est ni nettoyé ni renvoyé dans
  le formulaire après une erreur ;
- un fichier utilise `file:ext,...` : l'extension **et** le contenu réel sont
  vérifiés (un script renommé en `.png` est refusé), `max_mb:N` borne la taille ;
- un formulaire public (mot de passe oublié) redéfinit `authorize()` ;
- `failed()` permet de tracer un refus de sécurité (fichier suspect,
  confirmation de restauration invalide) ;
- `route('cle')` donne accès aux paramètres de l'URL (liste modifiée, dossier).

Une localisation déjà inspectée lève une `ConfirmationRequiseException` :
le contrôleur l'affiche comme un avertissement et l'utilisateur confirme.

Chaque opération s'exécute dans `Database::transaction()`, verrouille le
dossier avec `FormulaireRepository::repo_verrouiller()` (et le responsable avec
`UserRepository::repo_lockUser()` si besoin) puis écrit sa trace d'audit. Un refus métier
est signalé par une `DomainException` dont le message est présenté tel quel à
l'utilisateur ; toute autre exception annule la transaction et produit un
message générique. Les notifications et e-mails sont envoyés par le contrôleur,
après validation de la transaction.

La vue `views/formulaires/show.php` contient le corps de la fiche. Les fenêtres
de dialogue sont regroupées dans `views/formulaires/partials/modals.php`.

## Composants transverses importants

| Composant | Rôle |
|---|---|
| `Auth`, `LoginOtp`, `Permission` | sessions, OTP et contrôle des droits |
| `LoginRateLimiter` | blocage temporaire par identifiant et IP ; la persistance passe par `TentativeConnexionRepository` |
| `Csrf`, `Security`, `EnvironmentGuard` | protection des requêtes et de la configuration |
| `Database`, `Transaction`, `Repository` | connexion PDO, transactions (`Database::transaction()`, `Database::ouvrirTransaction()`) et base des repositories (`repo_find`, `repo_insert`, `repo_update`...) |
| `Logger` | journal d'audit avec acteur, cible et valeurs avant/après, persisté par `ActiviteRepository` |
| `AppMailer` | mot de passe oublié, création de compte, OTP, missions et relances |
| `MigrationRunner` | migrations ordonnées, verrouillées et contrôlées par checksum |
| `DatabaseBackup` | sauvegarde, validation et restauration sur base temporaire |
| `MissionReminderService` | rappels d'échéance et escalades via les repositories Mission, Relance, Utilisateur et Notification |
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

- Une route est ajoutée dans le fichier `routes/{module}.php` de son module ; un
  nouveau fichier de routes doit être déclaré dans `config/routes.php`. L'action
  appelée est une méthode publique `ctrl_…` du contrôleur.
- Une action POST doit vérifier l'authentification, la permission et le jeton CSRF.
- Une mutation métier importante doit être transactionnelle
  (`Database::transaction()`) et journalisée.
- Le SQL n'existe que dans `app/Repositories/`, plus quatre classes techniques
  (`Repository`, `Database`, `MigrationRunner`, `DatabaseBackup`) et les
  seeders. Un service ou un contrôleur ne récupère jamais la connexion : il
  appelle un repository, ou `Database::transaction()` pour grouper des écritures.
- `Database::transaction($operation, $isolation)` : appelée dans une
  transaction déjà ouverte, elle pose un point de reprise (SAVEPOINT), si bien
  qu'un échec n'annule que son propre travail. `$isolation` (`REPEATABLE READ`…)
  s'applique à une nouvelle transaction.
- `Database::ouvrirTransaction()` renvoie une `Transaction` à valider
  (`valider()`) ou annuler (`annuler()`) plus tard : c'est le cas de l'export,
  qui lit un état cohérent de la base pendant toute la production du fichier.
- Contrôle avant livraison, qui ne doit rien afficher :
  `grep -rlE '(->prepare\(|->query\(|->exec\(|SELECT |INSERT INTO|UPDATE [a-z_`]+ SET|DELETE FROM|FOR UPDATE|SAVEPOINT|Database::getConnection)' app --include=*.php | grep -vE '^app/Repositories/|^app/Core/(Repository|Database|MigrationRunner|DatabaseBackup)\.php$'`
- Une règle métier doit être placée dans un service de `app/Services/`, pas
  dans un contrôleur ni dans une vue ; le SQL reste dans `app/Repositories/`.
- Une vue ne doit pas interroger la base : le contrôleur lui fournit ses
  données (voir `Controller::render()` pour les données communes du layout).
- Une nouvelle classe est chargée automatiquement (PSR-4) si son namespace
  correspond à son chemin : `App\Services\Mission\X` dans
  `app/Services/Mission/X.php`. Ne pas réécrire d'autoloader dans un script :
  inclure `config/autoload.php`.
- Une nouvelle migration doit être enregistrée dans `config/migrations.php`.

## Vérifications minimales

Avant livraison :

```bash
find app database routes views config scripts public -name '*.php' -print0 | xargs -0 -n1 php -l
php scripts/recette.php   # recette complète sur base éphémère
php scripts/test_formulaire_import.php
php scripts/test_p12_acceptance.php
git diff --check
```
