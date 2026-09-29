# OIPI — RISFM (v2)

**Registre Informatisé de Suivi des Formulaires Manquants**  
*Application web institutionnelle pour l’Office Ivoirien de la Propriété Intellectuelle (OIPI)*

---

## Démarrage rapide en local

Cette section suffit pour installer une base neuve et ouvrir l’application sur
`http://localhost:8000`. Le [guide de déploiement](docs/GUIDE_DEPLOIEMENT.md)
contient ensuite les configurations XAMPP/WAMP et, en priorité, la mise en
production sur **cPanel**. Apache, Nginx et VPS restent documentés comme
alternatives.

### 1. Vérifier les prérequis

```bash
php -v
composer --version
mysql --version
```

PHP 8.1 minimum est requis avec `pdo_mysql`, `mbstring`, `gd`, `zip`, `xml` et
`fileinfo`. MySQL 8 ou MariaDB 10.6+ est recommandé.

### 2. Installer et préparer la configuration

Depuis la racine du projet :

```bash
composer install
cp .env.example .env
chmod 600 .env
```

Pour un poste local, renseigner au minimum les valeurs suivantes dans `.env` :

```dotenv
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=oipi_risfm
DB_USER=risfm_user
DB_PASS=VotreMotDePasseMySQLSolide

# Peut rester désactivé uniquement pendant la première installation locale.
ENABLE_LOGIN_OTP=false
```

`APP_URL` doit contenir le port réellement utilisé. Si le serveur est lancé sur
le port `8001`, utiliser `APP_URL=http://localhost:8001`. Une valeur vide permet
également à l’application de déduire automatiquement son adresse locale.

### 3. Créer la base et son utilisateur

Ouvrir MySQL avec un compte administrateur :

```bash
sudo mysql
```

Puis exécuter, en remplaçant le mot de passe par celui renseigné dans `.env` :

```sql
CREATE DATABASE oipi_risfm
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'risfm_user'@'localhost'
    IDENTIFIED BY 'VotreMotDePasseMySQLSolide';

ALTER USER 'risfm_user'@'localhost'
    IDENTIFIED BY 'VotreMotDePasseMySQLSolide';

GRANT ALL PRIVILEGES ON oipi_risfm.*
    TO 'risfm_user'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

Initialiser ensuite le schéma et appliquer les migrations :

```bash
mysql -u risfm_user -p oipi_risfm < schema.sql
php scripts/migrate.php
php scripts/migrate.php --status
```

> `schema.sql` contient des suppressions de tables. Il sert uniquement à créer
> une base neuve. Pour mettre à jour une installation existante, sauvegarder la
> base puis exécuter seulement `php scripts/migrate.php`.

### 4. Remplir les données et créer le premier administrateur

```bash
php scripts/seed.php
```

Les seeders (`database/seeders/`) écrivent les rôles, statuts, types de titres,
localisations, paramètres et le premier administrateur :

| E-mail | Mot de passe |
|---|---|
| `admin@oipi.ci` | `Admin_Oipi2026#` |

Ce mot de passe est public (il est dans le code) : l’application impose d’en
choisir un nouveau à la première connexion. La commande peut être relancée sans
risque : elle n’ajoute que ce qui manque. Sur un poste de test,
`php scripts/seed.php --demo` ajoute aussi des comptes et formulaires fictifs.

### 5. Lancer l’application

```bash
php -S localhost:8000 -t public public/router.php
```

Ouvrir [http://localhost:8000/login](http://localhost:8000/login), puis se
connecter avec `admin@oipi.ci` et `Admin_Oipi2026#`. Arrêter le serveur avec
`Ctrl+C`.

## Premier parcours dans l’application

Une fois connecté comme administrateur :

1. Ouvrir **Administration → Configuration** pour vérifier le nom, le logo, les
   listes métier et l’état des rappels.
2. Ouvrir **Utilisateurs** et créer les responsables ou agents. Leur identifiant
   est généré automatiquement par le système.
3. Ouvrir **Formulaires**, puis **Ajouter un formulaire** pour enregistrer un
   document manquant.
4. Dans le menu d’actions du formulaire, affecter une mission à un agent, avec
   une localisation, une priorité et éventuellement une échéance.
5. L’agent retrouve la mission sur son tableau de bord et saisit son compte
   rendu : **Retrouvé**, **Non retrouvé** ou **À vérifier**.
6. Si le document est retrouvé, poursuivre le parcours **Retrouvé → Numérisé →
   Saisi** depuis sa fiche détaillée.
7. Utiliser **Statistiques**, **Journal** et **Sauvegardes** pour le pilotage et
   la traçabilité.

## Mot de passe oublié et e-mails

Le lien **Mot de passe oublié ?** fonctionne uniquement si la configuration
SMTP de `.env` est complète :

```dotenv
MAIL_HOST=smtp.exemple.ci
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=compte_smtp
MAIL_PASS=mot_de_passe_application
MAIL_FROM=no-reply@oipi.ci
MAIL_FROM_NAME="OIPI - RISFM"
MAIL_DRY_RUN=false
```

Le message public ne révèle jamais si l’adresse appartient à un utilisateur.
Lorsqu’un compte actif existe, le lien reçu est valable 60 minutes. En
production, activer aussi `ENABLE_LOGIN_OTP=true` afin d’exiger le code de
sécurité envoyé après le mot de passe.

Ne jamais publier `.env`, un mot de passe SMTP ou un mot de passe MySQL dans le
dépôt Git.

## Commandes courantes

| Besoin | Commande |
|---|---|
| Lancer sur le port 8000 | `php -S localhost:8000 -t public public/router.php` |
| Lancer sur le port 8001 | `php -S localhost:8001 -t public public/router.php` |
| Appliquer les mises à jour SQL | `php scripts/migrate.php` |
| Voir l’état des migrations | `php scripts/migrate.php --status` |
| Remplir les données et créer le premier administrateur | `php scripts/seed.php` |
| Ajouter les données de démonstration (poste de test) | `php scripts/seed.php --demo` |
| Vérifier la configuration de production | `php scripts/check_production.php` |
| Vérifier les rappels | `php scripts/relances.php --status` |
| Installer la tâche quotidienne sur Linux/VPS | `php scripts/reminder_scheduler.php install` |
| Installer les rappels sur cPanel | Utiliser **Cron Jobs**, voir le guide de déploiement |
| Lancer la recette complète sur base temporaire | `php scripts/recette.php` |

La recette complète crée puis supprime des bases temporaires. Elle nécessite le
compte MySQL de maintenance décrit dans
[`docs/CONFIGURATION_SAUVEGARDE_RESTAURATION.md`](docs/CONFIGURATION_SAUVEGARDE_RESTAURATION.md)
et ne doit pas être lancée au hasard sur un serveur en exploitation.

## Dépannage express

| Problème | Vérification |
|---|---|
| `localhost n’autorise pas la connexion` | Le serveur PHP n’est pas lancé, ou le port de l’URL n’est pas le bon. |
| Redirection vers le port 8000 depuis le port 8001 | Corriger `APP_URL` dans `.env`, puis relancer le serveur. |
| `Access denied for user risfm_user` | Vérifier `DB_USER`, `DB_PASS`, l’hôte `localhost` et les droits MySQL accordés à la base. |
| `Base table or view not found` | Importer `schema.sql` sur une base neuve, puis lancer `php scripts/migrate.php` et `php scripts/seed.php`. |
| Le code OTP ou le lien de réinitialisation n’arrive pas | Vérifier les variables `MAIL_*`, le dossier indésirable et `storage/logs/php-error.log`. |
| La restauration est indisponible | Vérifier les exécutables `mysql`, `mysqldump`, la fonction PHP `proc_open()` et le compte MySQL de maintenance. |
| Les anciens styles restent affichés | Effectuer un rechargement forcé avec `Ctrl+F5`. |

Les journaux applicatifs se trouvent dans `storage/logs/`. Ne partagez jamais un
journal brut sans vérifier qu’il ne contient pas d’informations confidentielles.

## Déploiement de production sur cPanel

Le déploiement officiel cible cPanel. La structure recommandée est la suivante :

```text
/home/COMPTE_CPANEL/risfm/          application complète, hors du Web
/home/COMPTE_CPANEL/risfm/public/  racine publique du domaine ou sous-domaine
```

Dans cPanel, le **Document Root** du domaine ou sous-domaine doit donc pointer
vers `/home/COMPTE_CPANEL/risfm/public`. Ne placez pas volontairement tout le
projet dans `public_html` : `.env`, `config/`, `storage/`, `vendor/` et les
scripts d’administration ne doivent pas être accessibles directement depuis le
Web.

Résumé du déploiement :

1. choisir PHP 8.1 ou supérieur dans **MultiPHP Manager** et activer les
   extensions requises ;
2. créer la base et l’utilisateur dans **MySQL Database Wizard**, puis leur
   attribuer tous les droits sur cette base ;
3. importer `schema.sql` avec phpMyAdmin uniquement pour une installation
   neuve ;
4. téléverser l’application hors de `public_html`, installer `vendor/` et créer
   un fichier `.env` de production ;
5. faire pointer le domaine vers `public/` et activer HTTPS avec AutoSSL ;
6. depuis **Terminal** ou SSH, exécuter les migrations puis les seeders
   (`php scripts/seed.php`, qui crée le premier administrateur) et contrôler la
   configuration ;
7. créer les tâches quotidiennes dans **Cron Jobs** pour les rappels et la
   maintenance.

Les noms MySQL sont généralement préfixés automatiquement par cPanel, par
exemple `moncompte_oipi_risfm` et `moncompte_risfm`. Il faut recopier dans
`.env` les noms complets affichés par cPanel, pas seulement leur suffixe.

La procédure détaillée, les commandes avec chemins absolus, le SMTP cPanel et
les restrictions possibles sur la restauration sont décrits dans le
[guide de déploiement cPanel](docs/GUIDE_DEPLOIEMENT.md#2-deploiement-de-production-sur-cpanel).

---

## 📌 1. Présentation Générale & Enjeux Métier

Le **RISFM** (*Registre Informatisé de Suivi des Formulaires Manquants*) est une solution web métier interne développée sur-mesure pour l’**Office Ivoirien de la Propriété Intellectuelle (OIPI)**.

Dans le cadre de la modernisation et de la numérisation du patrimoine documentaire national de l'OIPI, le RISFM répond à un besoin critique : **centraliser, organiser et piloter la recherche et la numérisation des formulaires de titres de propriété intellectuelle non retrouvés dans les archives physiques**.

### 🎯 Objectifs principaux
- **Éviter les recherches redondantes** : Historiser chaque tentative de recherche dans les cartons/locaux d'archives pour qu'un agent ne fouille pas deux fois la même localisation inutilement.
- **Pilotage de la chaîne de traitement** : Suivre la progression séquentielle de chaque document :  
  $$\text{Formulaire Manquant} \longrightarrow \text{Mission de Recherche} \longrightarrow \text{Retrouvé} \longrightarrow \text{Numérisé} \longrightarrow \text{Saisi}$$
- **Responsabilisation & Traçabilité** : Affecter des missions de recherche claires à des agents/responsables avec gestion des délais et escalade automatique en cas de retard.
- **Fiabilité des données** : Garantir l'intégrité des registres nationaux par des contrôles d'unicité stricts, des exports/imports sécurisés et un journal d'audit complet.

---

## 🛠️ 2. Architecture Technique & Choix de Conception

L'application repose sur une **architecture MVC légère et modulaire en PHP 8.1+**, conçue sans framework lourd afin de garantir une empreinte mémoire minimale, des performances optimales et une maîtrise totale de la chaîne de sécurité.

```
                    +---------------------------------------+
                    |           HTTP Request / Web          |
                    +---------------------------------------+
                                       |
                                       v
                    +---------------------------------------+
                    |            public/index.php           |
                    |  (Init Session, Auth & Security Guard)|
                    +---------------------------------------+
                                       |
                                       v
                    +---------------------------------------+
                    |   routes/*.php (par module) + Router  |
                    |        (Router & Controller Dispatch) |
                    +---------------------------------------+
                                       |
                   +-------------------+-------------------+
                   |                                       |
                   v                                       v
      +------------------------+              +------------------------+
      | app/Http/Controllers/  |              |      app/Services/     |
      | (Requête et réponse)   |              |    (Règles métier)     |
      +------------------------+              +------------------------+
                   |                                       |
                   +-------------------+-------------------+
                                       |
                                       v
                      +----------------------------------+
                      |        app/Repositories/         |
                      |   (Accès PDO MySQL / Requêtes)   |
                      +----------------------------------+
                                       |
                                       v
                      +----------------------------------+
                      |         Base MySQL / MariaDB     |
                      +----------------------------------+
```

### 🧰 Stack Technique complète
* **Backend** : PHP 8.1+ (extensions requises : `pdo_mysql`, `mbstring`, `gd`, `zip`, `xml`, `fileinfo`)
* **Base de données** : MySQL 8.0+ / MariaDB (Encodage strict `utf8mb4_unicode_ci`)
* **Accès aux données** : PDO MySQL avec requêtes préparées et transactions strictes
* **Frontend** : HTML5 / CSS3 / JavaScript (ES6+), Bootstrap 4 avec AdminLTE 3, jQuery, DataTables (recherche & pagination), Chart.js (statistiques)
* **Design System** : Palette visuelle officielle OIPI (Vert institutionnel, cartes d'état dynamique)
* **Dépendances Composer** :
  * `phpoffice/phpspreadsheet` : Import et export natif Excel (XLSX)
  * `phpoffice/phpword` : Génération de fiches synthétiques au format Word (.docx)
  * `dompdf/dompdf` : Génération des rapports et listes au format PDF
  * `phpmailer/phpmailer` : Expédition d'e-mails (notifications, alertes, OTP)

---

## ✨ 3. Fonctionnalités Détaillées

### 📋 3.1. Gestion du Registre National
- **Consultation & Recherche avancée** : Filtrage multi-critères (Type de titre, Année, Statut, Localisation, Responsable) avec pagination dynamique.
- **Unicité & Anti-doublon** : Garantie stricte d'unicité sur le triplet `(Type de titre, Année, Numéro de formulaire)`.
- **Numérotation automatique** : Attribution automatique d'identifiants uniques d'enregistrement (`OIPI-RISFM-XXXXXX`).

### 🔎 3.2. Missions de Recherche & Workflow Dynamique
- **Affectation & Réaffectation** : Assignation d'un ou plusieurs formulaires à un responsable avec date limite d'exécution.
- **Historisation par Cycle** : Conservation intégrale des tentatives de recherche, des dates d'inspection, des observations et des utilisateurs réels.
- **Annulation & Réouverture** : Possibilité d'annuler une mission ou de réouvrir un dossier avec motif obligatoire et traçabilité du cycle.

### 🏁 3.3. Progression Séquentielle de Finalisation
- Étape 1 : **Retrouvé** (Validation du formulaire physique localisé).
- Étape 2 : **Numérisé** (Attachement de la pièce jointe ou confirmation du scan).
- Étape 3 : **Saisi** (Intégration définitive dans le système national).

### ⏰ 3.4. Moteur de Relances Automatiques & Supervision
- **Rappels automatiques** : Déclenchement de relances par e-mail et notifications internes à J-2, J (échéance), J+1, J+3.
- **Escalade hiérarchique** : Notification des administrateurs à J+7 en cas de retard non justifié.
- **Supervision CLI & Cron** : Script d'installation automatique dans la crontab (`scripts/reminder_scheduler.php`) et panneau d'état du heartbeat dans le back-office.

### 📥 3.5. Importation & Exportation de Données
- **Import CSV / XLSX** : Module d'importation avec prévisualisation des lignes, validation des règles métier, détection des doublons et exécution sous transaction atomique.
- **Export Multi-formats** : Exportation intégrale ou filtrée vers Excel (XLSX), PDF, Word et CSV. Prise en charge des grands volumes (testé au-delà de 20 000 lignes avec itérateur par lots).

### 🔐 3.6. Sécurité, Audit & Administration
- **Authentification forte & OTP** : Validation à deux facteurs (OTP par e-mail) configurable dans `.env`.
- **Rate Limiting Hybride** : Double barrière contre les attaques par force brute (max 5 échecs par identifiant / max 30 échecs par IP sur 10 min) via `LoginRateLimiter`.
- **Journal d'Audit Intégral** : Traçabilité des actions utilisateurs avec sauvegarde de l'instantané des valeurs *avant/après* modification (`Logger`).
- **Archivage Logique** : Aucune suppression définitive de formulaire ; possibilité pour l'administrateur de consulter et restaurer les éléments archivés.
- **Sauvegarde & Restauration** : Outil natif de création et validation de sauvegardes SQL (`DatabaseBackup`).
- **Migrations Versionnées** : Gestionnaire de migrations SQL applicatives sécurisées par checksum (`MigrationRunner`).

---

## 📂 4. Structure du Projet

```
RISFM_OIPI_v2/
├── api/                 # Endpoints d'API internes (DataTables, requêtes AJAX)
├── config/              # Configuration globale, routes, rôles, base de données & migrations
│   ├── config.php       # Constantes système et chargement .env
│   ├── database.php     # Connexion PDO MySQL
│   ├── migrations.php   # Registre des migrations SQL applicatives
│   ├── roles.php        # Matrice des droits et permissions
│   └── routes.php       # Table de routage HTTP (80+ routes)
├── app/                 # Code PHP (namespaces PSR-4 App\...), organisé par couche puis par module
│   ├── Core/            # Infrastructure : routeur, base, sécurité, session, e-mails, sauvegardes
│   ├── Http/Controllers/# 18 contrôleurs par module (Auth, Formulaire, Mission, Administration...)
│   ├── Services/        # Règles métier par module (missions, finalisation, archivage, import...)
│   └── Repositories/    # 16 repositories d'accès aux données (SQL), méthodes repo_*
├── docs/                # Documentation technique et fonctionnelle détaillée (10 fichiers .md)
├── exports/             # Répertoire temporaire des fichiers d'export générés
├── migrations/          # 23 Fichiers SQL de migration de schéma
├── public/              # Racine Web publique (index.php, router.php, assets CSS/JS)
├── routes/              # Routes HTTP par module (auth, formulaires, missions, utilisateurs...)
├── schema.sql           # Structure initiale de la base de données (sans aucune donnée)
├── database/seeders/    # Données initiales : référence, premier admin et démonstration (--demo)
├── scripts/             # 30 Scripts CLI de maintenance, relances, migrations et tests
├── storage/             # Stockage privé hors Web (uploads, backups, imports, logs)
└── views/               # Vues PHP/HTML structurées par domaine (formulaires, users, etc.)
```

---

## 💻 5. Prérequis Système

* **Serveur Web** : Apache (avec `mod_rewrite`) ou Nginx
* **PHP** : `8.1` ou supérieur (Extensions : `pdo_mysql`, `mbstring`, `gd`, `zip`, `xml`, `fileinfo`)
* **Base de données** : MySQL 8.0+ ou MariaDB 10.5+
* **Gestionnaire de dépendances** : Composer 2.x
* **Outillage système** : Utilitaires CLI `mysql` et `mysqldump`
* **Serveur SMTP** : Pour l'envoi des OTP, des réinitialisations de mot de passe et des relances

---

## 🚀 6. Guide d'Installation & Déploiement

### Step 1 : Cloner le dépôt et installer les dépendances
```bash
git clone https://github.com/melvin-phyllis/RISFM.git
cd RISFM/RISFM_OIPI_v2
composer install
```
*(En production, utiliser `composer install --no-dev --optimize-autoloader`)*

### Step 2 : Configurer l'environnement `.env`
```bash
cp .env.example .env
chmod 600 .env
```
Éditer `.env` et renseigner les paramètres minimaux :
```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://risfm.oipi.ci

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=oipi_risfm
DB_USER=risfm_user
DB_PASS=VotreMotDePasseSolide

ENABLE_LOGIN_OTP=true

LOGIN_IDENTIFIER_MAX_ATTEMPTS=5
LOGIN_IP_MAX_ATTEMPTS=30
LOGIN_RATE_LIMIT_WINDOW_MINUTES=10

MAIL_HOST=smtp.oipi.ci
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=compte_smtp
MAIL_PASS=secret_smtp
MAIL_FROM=no-reply@oipi.ci
MAIL_FROM_NAME="OIPI - RISFM"
```

### Step 3 : Initialiser la Base de Données
Créer une base vide encodée en `utf8mb4`, puis importer le schéma et exécuter les migrations :
```bash
mysql -u risfm_user -p oipi_risfm < schema.sql
php scripts/migrate.php
php scripts/migrate.php --status
```

### Step 4 : Remplir les données et créer le premier administrateur
```bash
php scripts/seed.php
```
*Crée les données de référence et l'administrateur `admin@oipi.ci` (mot de passe `Admin_Oipi2026#`, à changer à la première connexion). Identifiant généré : `OIPI-RISFM-000001`.*

### Step 5 : Configurer le moteur de relances (Cron)
```bash
php scripts/reminder_scheduler.php install
php scripts/reminder_scheduler.php status
```

### Step 6 : Lancement en développement
```bash
php -S localhost:8000 -t public public/router.php
```
Accéder ensuite à l'application via `http://localhost:8000`.

---

## 👥 7. Matrice des Rôles et Permissions

| Rôle | Droits & Périmètre d'action |
|---|---|
| **Administrateur** | Accès total : Gestion des utilisateurs, configuration système, journal d'audit, sauvegardes/restaurations, validation des réouvertures et gestion des archives. |
| **Responsable** | Pilotage métier : Création et modification de formulaires, affectation et annulation de missions, suivi des équipes, imports/exports et statistiques. |
| **Agent** | Opérationnel : Saisie et mise à jour des résultats de recherche sur les missions qui lui sont spécifiquement affectées. |
| **Consultation** | Lecture seule : Recherche, filtrage et affichage des formulaires et tableaux de bord sans droit de modification. |

*La matrice détaillée des permissions est documentée dans [`docs/MATRICE_DROITS_FORMULAIRES.md`](docs/MATRICE_DROITS_FORMULAIRES.md).*

---

## 🧪 8. Assurance Qualité & Tests de Recette

Le projet intègre un harnais de recette automatisée complet s'exécutant sur une base de données MySQL éphémère et isolée afin de garantir l'absence de régression.

### Vérification de la syntaxe PHP
```bash
find . -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
```

### Exécution de la recette complète (17 suites de tests)
```bash
php scripts/recette.php
```

### Périmètre des tests automatisés :
1. **Installation initiale sécurisée** (`test_initial_install.php`)
2. **Validations métier & anti-doublon** (`test_p5_validation.php`)
3. **Importation CSV & XLSX** (`test_formulaire_import.php`)
4. **Exportation volumétrique & itérateur** (`test_p6_iterator.php` & `test_p6_large_volume.php`)
5. **Normalisation des identifiants** (`test_p7_migration.php` & `test_p7_state.php`)
6. **Résilience et verrous MySQL** (`test_p8_resilience.php`)
7. **Rate Limiting connexions IP/Identifiant** (`test_login_rate_limiter.php`)
8. **Réinitialisation sécurisée de mot de passe** (`test_p9_password_reset.php`)
9. **Cycle de vie des missions & accès restreints** (`test_secure_user_access.php`)
10. **Relances automatiques & escalade** (`test_mission_reminders.php`)
11. **Sauvegarde et restauration complète** (`test_p10_backup_restore.php`)
12. **Idempotence des migrations SQL** (`test_p11_migrations.php`)
13. **Benchmark de performance grand volume (20 000 formulaires)** (`test_performance_large_volume.php`)
14. **Recette globale & conformité responsive** (`test_p12_acceptance.php`)

---

## 📚 9. Index de la Documentation

Pour en savoir plus sur l'architecture et l'exploitation du projet, consultez les guides disponibles dans le dossier [`docs/`](docs/) :

- 📄 [Index de la documentation](docs/README.md)
- 📐 [Architecture technique détaillée](docs/ARCHITECTURE.md)
- 🚀 [Guide de déploiement en production](docs/GUIDE_DEPLOIEMENT.md)
- 💾 [Guide de sauvegarde et restauration](docs/CONFIGURATION_SAUVEGARDE_RESTAURATION.md)
- ⏰ [Guide de configuration des relances](docs/CONFIGURATION_RELANCES.md)
- 📥 [Spécifications de l'import de registre](docs/IMPORT_REGISTRE.md)
- 🔒 [Matrice des droits et habilitations](docs/MATRICE_DROITS_FORMULAIRES.md)
- ⚡ [Performances sur grand volume](docs/PERFORMANCES_GRAND_VOLUME.md)
- 🧪 [Procès-verbal de Recette P12](docs/RECETTE_P12.md)
- 📋 [Audit fonctionnel priorisé](docs/AUDIT_FONCTIONNEL_PRIORISE.md)
- 🗺️ [Feuille de route des modules avancés](docs/ROADMAP_MODULES_AVANCES.md)

---

## 🔒 10. Licence & Confidentialité

Ce logiciel est une propriété exclusive de l'**Office Ivoirien de la Propriété Intellectuelle (OIPI)**.  
Toutes les données, pièces jointes, sauvegardes et journaux de connexion traités par cette application sont strictement confidentiels.
