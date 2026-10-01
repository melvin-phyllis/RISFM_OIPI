# Installation de RISFM sur cPanel

Ce guide décrit une installation neuve et sécurisée de RISFM sur un hébergement cPanel. Remplacer dans les commandes `COMPTE_CPANEL`, `risfm.exemple.ci` et les identifiants MySQL par les valeurs réelles de l’hébergement.

## 1. Vérifier les prérequis

Dans **MultiPHP Manager** ou **Select PHP Version** :

- sélectionner PHP 8.1 ou supérieur ;
- activer `pdo_mysql`, `mbstring`, `gd`, `zip`, `dom`, `xml` et `fileinfo` ;
- vérifier que Composer 2, Terminal ou SSH est disponible.

Activer un certificat dans **SSL/TLS Status** et vérifier que le domaine répond en HTTPS.

## 2. Préparer le domaine

La structure recommandée est :

```text
/home/COMPTE_CPANEL/risfm/          projet complet, non public
/home/COMPTE_CPANEL/risfm/public/   racine Web du domaine
```

Dans **Domains**, configurer le `Document Root` de `risfm.exemple.ci` sur :

```text
/home/COMPTE_CPANEL/risfm/public
```

Seul `public/` doit être accessible depuis Internet. Ne pas placer `.env`, `config/`, `storage/`, `vendor/`, `migrations/` ou `scripts/` directement dans `public_html`.

Si cPanel refuse cette racine, demander au support de faire pointer le domaine vers `risfm/public`. Ne pas copier tout le projet dans la racine publique pour contourner cette restriction.

## 3. Envoyer les fichiers

Depuis **File Manager** :

1. téléverser l’archive du projet dans `/home/COMPTE_CPANEL/` ;
2. extraire l’archive ;
3. renommer le dossier obtenu en `risfm` si nécessaire ;
4. supprimer l’archive après vérification ;
5. ne jamais envoyer le `.env` utilisé en développement.

Avec Git et SSH, il est également possible de cloner le dépôt privé dans ce dossier.

## 4. Installer les dépendances

Dans **Terminal** :

```bash
cd /home/COMPTE_CPANEL/risfm
composer install --no-dev --optimize-autoloader
```

Si Composer n’est pas disponible sur le serveur, exécuter cette commande sur une machine utilisant une version PHP compatible, puis téléverser également le dossier `vendor/`. Un accès Terminal reste recommandé pour les migrations et les mises à jour.

## 5. Créer la base de données

Dans **MySQL Database Wizard** :

1. créer une base, par exemple `oipi_risfm` ;
2. créer un utilisateur MySQL avec un mot de passe long et unique ;
3. rattacher cet utilisateur à la base avec **ALL PRIVILEGES** ;
4. conserver les noms complets générés par cPanel.

cPanel ajoute généralement un préfixe. Les valeurs finales peuvent ressembler à :

```text
Base : compte_oipi_risfm
Utilisateur : compte_risfm
```

Il n’est pas nécessaire d’importer `schema.sql` si les migrations sont exécutées depuis le Terminal. Pour une installation existante, ne jamais réimporter `schema.sql` : effectuer une sauvegarde puis appliquer uniquement les migrations.

## 6. Configurer l’environnement

Créer `.env` depuis le modèle :

```bash
cd /home/COMPTE_CPANEL/risfm
cp .env.example .env
```

Renseigner au minimum :

```dotenv
APP_ENV=production
APP_URL=https://risfm.exemple.ci
APP_DEBUG=false
APP_TIMEZONE=Africa/Abidjan
APP_FORCE_HTTPS=true

DB_HOST=localhost
DB_PORT=3306
DB_NAME=COMPTE_CPANEL_oipi_risfm
DB_USER=COMPTE_CPANEL_risfm
DB_PASS=UN_MOT_DE_PASSE_MYSQL_LONG_ET_UNIQUE

SESSION_NAME=RISFM_SESSION
ENABLE_LOGIN_OTP=true
ENABLE_CAPTCHA=false

MAIL_HOST=mail.exemple.ci
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=no-reply@exemple.ci
MAIL_PASS=UN_MOT_DE_PASSE_SMTP_UNIQUE
MAIL_FROM=no-reply@exemple.ci
MAIL_FROM_NAME="OIPI - RISFM"

ENABLE_MISSION_REMINDERS=true
REMINDER_CRON_SCHEDULE="0 8 * * *"

UPLOAD_MAX_MB=15
BACKUP_MAX_MB=250
```

Pour le SMTP, créer la boîte dans **Email Accounts** puis reprendre exactement les paramètres indiqués par **Connect Devices**. Selon l’hébergeur, utiliser le port 587 avec `tls` ou 465 avec `ssl`.

Ne jamais communiquer ni versionner `.env`.

## 7. Installer la base et les données initiales

Identifier d’abord le bon exécutable PHP :

```bash
which php
php -v
```

Puis exécuter :

```bash
cd /home/COMPTE_CPANEL/risfm
php scripts/migrate.php
php scripts/migrate.php --status
php scripts/seed.php
php scripts/check_production.php
```

Le seeder crée les référentiels et prépare le compte administrateur :

- identifiant : `admin@oipi.ci` ;
- mot de passe provisoire : `Admin_Oipi2026#`.

Se connecter immédiatement et choisir un nouveau mot de passe. L’application impose ce changement. Ne pas utiliser `--demo` en production.

Attention : relancer `php scripts/seed.php` réinitialise le mot de passe de cet administrateur et impose un nouveau changement.

## 8. Régler les permissions

Valeurs recommandées, à adapter au fonctionnement PHP de l’hébergeur :

- `.env` : `600` ;
- fichiers : `640` ou `644` ;
- dossiers : `750` ou `755` ;
- `storage/` et les dossiers d’envoi de fichiers : inscriptibles par PHP.

Ne jamais utiliser `777`.

Vérifier notamment l’écriture dans :

```text
storage/logs/
storage/backups/
storage/uploads/formulaires/
```

## 9. Configurer les tâches cron

Dans **Advanced → Cron Jobs**, utiliser le chemin PHP retourné par `which php`. Le chemin peut être `/usr/local/bin/php` ou, par exemple, `/usr/local/bin/ea-php83`.

### Relances des missions — tous les jours à 08:00

```cron
0 8 * * * /usr/local/bin/php /home/COMPTE_CPANEL/risfm/scripts/relances.php >> /home/COMPTE_CPANEL/risfm/storage/logs/relances-cron.log 2>&1
```

Cette tâche produit les rappels à J-2, le jour J, après échéance et les escalades prévues.

### Maintenance de sécurité — tous les jours à 02:00

```cron
0 2 * * * /usr/local/bin/php /home/COMPTE_CPANEL/risfm/scripts/purge_reset_security_data.php >> /home/COMPTE_CPANEL/risfm/storage/logs/maintenance.log 2>&1
```

Cette tâche purge les jetons expirés, les anciennes tentatives et les demandes de réinitialisation obsolètes.

Tester les commandes avant d’attendre leur première exécution :

```bash
cd /home/COMPTE_CPANEL/risfm
php scripts/relances.php --preview
php scripts/relances.php
php scripts/relances.php --status
php scripts/purge_reset_security_data.php
```

Le lendemain, contrôler `storage/logs/relances-cron.log`, `storage/logs/maintenance.log` et la section **Administration → Configuration → Rappels automatiques**.

## 10. Contrôler la mise en production

```bash
cd /home/COMPTE_CPANEL/risfm
php scripts/check_production.php
```

Vérifier ensuite manuellement :

- l’ouverture de `https://risfm.exemple.ci/login`, sans `/public` dans l’URL ;
- la redirection HTTP vers HTTPS ;
- la connexion puis le changement du mot de passe initial ;
- l’envoi du code OTP et la procédure « mot de passe oublié » ;
- la création d’un utilisateur et la réception de son message ;
- la création d’un formulaire et l’affectation d’une mission ;
- les notifications, exports et pièces jointes ;
- l’exécution d’une sauvegarde manuelle ;
- le refus d’accès Web à `.env`, `storage/`, `config/`, `vendor/` et `scripts/`.

## 11. Sauvegarde et restauration

Le module utilise `mysqldump` lorsqu’il est disponible, avec un mode de repli PHP. La restauration sécurisée utilise une base temporaire ; certains hébergements mutualisés refusent sa création.

Avant d’autoriser une restauration en production :

```bash
php scripts/test_p10_backup_restore.php
```

Si le test échoue faute de privilèges, ne pas accorder des droits globaux au compte de l’application. Utiliser phpMyAdmin/cPanel avec l’administrateur de l’hébergement ou demander au support un compte de maintenance strictement limité. Consulter `docs/CONFIGURATION_SAUVEGARDE_RESTAURATION.md`.

## 12. Mettre l’application à jour

Effectuer d’abord une sauvegarde de la base et des fichiers, puis :

```bash
cd /home/COMPTE_CPANEL/risfm
git pull --ff-only
composer install --no-dev --optimize-autoloader
php scripts/migrate.php
php scripts/migrate.php --status
php scripts/check_production.php
```

Si le projet est déployé par archive, téléverser la nouvelle version sans remplacer `.env` ni supprimer `storage/`, puis lancer les mêmes commandes à partir de `composer install`.

Ne jamais modifier une migration déjà enregistrée. Toute évolution doit être ajoutée dans un nouveau fichier de migration.

## 13. Diagnostic rapide

| Symptôme | Vérification |
|---|---|
| Erreur 500 | Consulter `storage/logs/` et le journal d’erreurs cPanel |
| Page 404 | Vérifier le `Document Root`, `.htaccess` et `mod_rewrite` |
| Connexion MySQL impossible | Vérifier les noms préfixés cPanel et les droits de l’utilisateur |
| Courriels absents | Vérifier `MAIL_*`, le port, le chiffrement et les journaux |
| Cron silencieux | Utiliser un chemin PHP absolu et consulter les fichiers de log |
| Téléversement impossible | Vérifier les limites PHP et les permissions des dossiers |
| Migration bloquée par checksum | Restaurer le fichier d’origine ; ne jamais modifier une migration appliquée |
| Restauration refusée | Vérifier les privilèges de maintenance et le guide de sauvegarde |

## Checklist finale

- [ ] Domaine configuré avec `public/` comme racine
- [ ] Certificat HTTPS actif
- [ ] `.env` de production renseigné et protégé
- [ ] Dépendances Composer installées sans les outils de développement
- [ ] Base créée, migrations appliquées et seeders exécutés
- [ ] Mot de passe administrateur initial remplacé
- [ ] SMTP et OTP testés
- [ ] Permissions vérifiées, aucun dossier en `777`
- [ ] Cron de relances configuré et testé
- [ ] Cron de maintenance configuré et testé
- [ ] Sauvegarde testée avant l’ouverture aux utilisateurs
- [ ] `php scripts/check_production.php` terminé sans erreur
