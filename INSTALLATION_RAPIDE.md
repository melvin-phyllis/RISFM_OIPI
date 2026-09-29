# RISFM — Installation rapide en local

Ce guide permet d'installer et de lancer l'application RISFM sur un poste
Linux en quelques minutes.

## Prérequis

Vérifier que ces trois outils sont installés :

```bash
php -v          # PHP 8.1 ou plus
mysql --version # MySQL 8 ou MariaDB 10.6+
composer --version
```

Extensions PHP nécessaires : `pdo_mysql`, `mbstring`, `gd`, `zip`, `xml`,
`fileinfo`.

## Installation (à faire une seule fois)

### 1. Aller dans le dossier du projet

```bash
cd chemin/vers/RISFM_OIPI_v2
```

### 2. Installer les dépendances

```bash
composer install
```

### 3. Créer la base de données

Remplacer `Risfm_2026` par un mot de passe de votre choix (et le reporter à
l'étape suivante). **Ne pas utiliser le caractère `!`** dans ce mot de passe :
le terminal l'interprète et la commande échoue avec `event not found`.

```bash
sudo mysql -e "CREATE DATABASE IF NOT EXISTS oipi_risfm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS 'risfm_user'@'localhost' IDENTIFIED BY 'Risfm_2026'; ALTER USER 'risfm_user'@'localhost' IDENTIFIED BY 'Risfm_2026'; GRANT ALL PRIVILEGES ON oipi_risfm.* TO 'risfm_user'@'localhost'; FLUSH PRIVILEGES;"
```

Cette commande peut être relancée sans risque si elle a échoué la première
fois.

### 4. Créer le fichier de configuration

```bash
cat > .env <<'EOF'
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=oipi_risfm
DB_USER=risfm_user
DB_PASS=Risfm_2026
ENABLE_LOGIN_OTP=false
EOF
chmod 600 .env
```

### 5. Créer les tables

```bash
mysql -u risfm_user -p'Risfm_2026' oipi_risfm < schema.sql
php scripts/migrate.php
```

### 6. Remplir les données et créer le compte administrateur

```bash
php scripts/seed.php
php scripts/create_admin.php
```

La première commande crée les rôles, statuts, types de titres, localisations
et paramètres. La seconde demande interactivement le nom, l’adresse e-mail et
le mot de passe du premier administrateur. Le mot de passe doit contenir au
moins 10 caractères, avec une majuscule, une minuscule, un chiffre et un
caractère spécial. Aucun mot de passe administrateur par défaut n’est livré.

Pour avoir aussi des comptes et formulaires d'exemple sur un poste de test,
créer d’abord l’administrateur puis lancer `php scripts/seed.php --demo`.

## Lancer l'application

```bash
php -S localhost:8000 -t public public/router.php
```

Ouvrir **http://localhost:8000/login** dans le navigateur et utiliser l’adresse
e-mail et le mot de passe choisis avec `create_admin.php`.

Pour arrêter l'application : `Ctrl+C` dans le terminal.

> Les fois suivantes, il suffit de se placer dans le dossier du projet puis de
> lancer la commande ci-dessus.

## En cas de problème

| Message | Solution |
|---|---|
| `Access denied for user risfm_user` | Le mot de passe dans `.env` ne correspond pas à celui de l'étape 3. |
| `Base table or view not found` | Refaire l'étape 5. |
| `Table 'roles' doesn't exist` pendant l'étape 6 | Faire d'abord l'étape 5 (tables et migrations). |
| Connexion administrateur refusée | Utiliser l’adresse choisie avec `create_admin.php` ou réinitialiser le mot de passe depuis l’application. |
| La page ne s'ouvre pas | Vérifier que la commande de lancement tourne toujours dans le terminal. |

Pour aller plus loin (déploiement sur cPanel, e-mails, sauvegardes), voir
[README.md](README.md) et [docs/GUIDE_DEPLOIEMENT.md](docs/GUIDE_DEPLOIEMENT.md).
