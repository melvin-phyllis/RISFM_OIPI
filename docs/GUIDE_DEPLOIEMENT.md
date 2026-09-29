# Guide de deploiement — OIPI RISFM

Derniere mise a jour : 10 aout 2026

Ce guide couvre l'installation locale sur XAMPP/WAMP et le deploiement de
production sur cPanel. Les installations LAMP, Apache, Nginx et VPS sont
conservees comme solutions alternatives.

## 1. Prerequis

- PHP 8.1 ou superieur (teste pour PHP 8.3), avec les extensions : `pdo_mysql`, `mbstring`, `gd`, `zip`, `dom`, `xml`, `fileinfo`.
- MySQL 8.0+ ou MariaDB 10.6+.
- Apache 2.4+ avec `mod_rewrite` active (ou Nginx, voir section 7).
- Composer 2.x.
- Acces reseau sortant pour `composer install` (telechargement de PhpSpreadsheet, PHPWord, Dompdf) , ou depot Composer miroir/local en environnement ferme.

## 2. Deploiement de production sur cPanel

### 2.1. Preparer le domaine et PHP

1. Dans **Domains**, creer le domaine ou sous-domaine public, par exemple
   `risfm.exemple.ci`.
2. Dans **MultiPHP Manager** ou **Select PHP Version**, choisir PHP 8.1 ou une
   version superieure et activer `pdo_mysql`, `mbstring`, `gd`, `zip`, `dom`,
   `xml` et `fileinfo`.
3. Dans **SSL/TLS Status**, lancer AutoSSL et verifier que l'URL HTTPS fonctionne.

La structure securisee recommandee est :

```text
/home/COMPTE_CPANEL/risfm/          application complete, non publique
/home/COMPTE_CPANEL/risfm/public/  seule racine exposee par le domaine
```

Configurer le **Document Root** du domaine vers
`/home/COMPTE_CPANEL/risfm/public`. Le projet complet ne doit pas etre place
directement dans `public_html` : `.env`, `config/`, `storage/`, `vendor/`, les
migrations et les scripts CLI ne sont pas des ressources publiques.

Si l'hebergeur interdit un Document Root hors de `public_html`, demander au
support cPanel de le faire pointer vers `risfm/public` ou d'autoriser un lien
symbolique gere par l'hebergeur. Le `.htaccess` racine apporte une protection de
repli, mais ne remplace pas cette separation en production.

### 2.2. Televerser le projet et installer les dependances

Televerser une archive du projet avec **File Manager**, puis l'extraire dans
`/home/COMPTE_CPANEL/risfm`. Ne pas televerser le fichier `.env` du poste de
developpement.

Si cPanel fournit **Terminal** ou SSH :

```bash
cd /home/COMPTE_CPANEL/risfm
composer install --no-dev --optimize-autoloader
```

Si Composer n'est pas disponible sur le serveur, executer la meme commande sur
un poste utilisant une version PHP compatible, puis televerser le dossier
`vendor/` obtenu avec le reste de l'application. Un acces Terminal ou SSH reste
fortement recommande pour appliquer proprement les futures migrations.

### 2.3. Creer la base MySQL

Dans **MySQL Database Wizard** :

1. creer la base `oipi_risfm` ;
2. creer un utilisateur avec un mot de passe long et unique ;
3. rattacher cet utilisateur a la base avec **ALL PRIVILEGES** ;
4. noter les noms complets affiches par cPanel.

cPanel prefixe generalement les noms avec le compte d'hebergement. Les valeurs
reelles peuvent donc etre `compte_oipi_risfm` et `compte_risfm`, et non
`oipi_risfm` et `risfm`.

Pour une installation neuve, ouvrir **phpMyAdmin**, selectionner la base et
importer `schema.sql`. Pour mettre a jour une installation existante, ne jamais
reimporter `schema.sql` : sauvegarder la base puis executer uniquement le
gestionnaire de migrations.

### 2.4. Configurer `.env`

Copier `.env.example` vers `.env` dans `/home/COMPTE_CPANEL/risfm`, puis adapter
au minimum les valeurs suivantes :

```dotenv
APP_NAME="OIPI - RISFM"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://risfm.exemple.ci
APP_TIMEZONE=Africa/Abidjan

DB_HOST=localhost
DB_PORT=3306
DB_NAME=COMPTE_CPANEL_oipi_risfm
DB_USER=COMPTE_CPANEL_risfm
DB_PASS=MotDePasseMySQLReelEtUnique

ENABLE_LOGIN_OTP=true

MAIL_HOST=mail.exemple.ci
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USER=no-reply@exemple.ci
MAIL_PASS=MotDePasseReelDeLaBoite
MAIL_FROM=no-reply@exemple.ci
MAIL_FROM_NAME="OIPI - RISFM"
MAIL_DRY_RUN=false

ENABLE_MISSION_REMINDERS=true
REMINDER_CRON_SCHEDULE="0 8 * * *"
```

Creer au besoin l'adresse d'envoi dans **Email Accounts**, puis utiliser les
parametres exacts affiches par **Connect Devices**. Selon l'hebergeur, le port
sera 587 avec `tls` ou 465 avec `ssl`. Ne jamais publier `.env` dans Git, un
ticket d'assistance ou une capture d'ecran.

En mode `production`, l'application refuse volontairement de demarrer si HTTPS,
l'OTP, le SMTP ou le mot de passe MySQL ne sont pas correctement configures.

### 2.5. Appliquer les migrations et creer l'administrateur

Dans **Terminal**, verifier d'abord le chemin de PHP :

```bash
which php
php -v
```

Puis executer depuis la racine du projet :

```bash
cd /home/COMPTE_CPANEL/risfm
php scripts/migrate.php
php scripts/migrate.php --status
php scripts/seed.php
php scripts/check_production.php
```

`seed.php` ecrit les donnees de reference et cree le premier administrateur
(`admin@oipi.ci`, mot de passe `Admin_Oipi2026#`, voir
`database/seeders/AdminSeeder.php`). Ce mot de passe etant public, se connecter
immediatement pour le remplacer : l'application l'exige a la premiere
connexion. Toute relance de `seed.php` reinitialise ce mot de passe et impose
un nouveau changement. Si `php` ne correspond pas a la version choisie dans MultiPHP
Manager, utiliser le chemin fourni par l'hebergeur, par exemple
`/usr/local/bin/ea-php83`.

Sans Terminal ni SSH, le deploiement initial peut etre prepare localement puis
importe avec phpMyAdmin, mais l'application ne pourra pas etre maintenue de
maniere fiable. Demander l'activation du Terminal ou un accompagnement de
l'hebergeur avant la mise en exploitation.

### 2.6. Regler les permissions

Avec **File Manager**, appliquer les valeurs les plus restrictives compatibles
avec l'hebergement :

- `.env` : `600` ;
- fichiers applicatifs : `640` ou `644` ;
- dossiers : `750` ou `755` ;
- `storage/` et `public/uploads/` : inscriptibles par le compte PHP cPanel.

Ne jamais utiliser `777`. Verifier ensuite que l'application peut ecrire dans
`storage/logs/`, `storage/backups/` et `storage/uploads/formulaires/`.

### 2.7. Creer les taches Cron cPanel

Dans **Cron Jobs**, creer une execution quotidienne a 08:00 pour les rappels :

```cron
0 8 * * * /usr/local/bin/php /home/COMPTE_CPANEL/risfm/scripts/relances.php >> /home/COMPTE_CPANEL/risfm/storage/logs/relances-cron.log 2>&1
```

Ajouter la maintenance de securite quotidienne a 02:00 :

```cron
0 2 * * * /usr/local/bin/php /home/COMPTE_CPANEL/risfm/scripts/purge_reset_security_data.php >> /home/COMPTE_CPANEL/risfm/storage/logs/maintenance.log 2>&1
```

Remplacer `/usr/local/bin/php` par le resultat de `which php` ou par le binaire
PHP indique par cPanel. Sur cPanel, utiliser l'interface **Cron Jobs** plutot que
`scripts/reminder_scheduler.php install`, car le serveur peut interdire la
modification directe de la crontab.

Tester avant d'attendre le premier passage :

```bash
cd /home/COMPTE_CPANEL/risfm
php scripts/relances.php --preview
php scripts/relances.php
php scripts/relances.php --status
```

### 2.8. Sauvegarde et restauration sur cPanel

Le module de sauvegarde fonctionne avec `mysqldump` lorsqu'il est disponible et
possede un mode de repli PHP. En revanche, sa restauration securisee cree
d'abord une base temporaire. Les offres cPanel mutualisees interdisent souvent a
un utilisateur MySQL applicatif de creer ou supprimer des bases.

Avant d'activer la restauration en production, executer
`php scripts/test_p10_backup_restore.php`. Si cPanel refuse la base temporaire,
ne pas contourner cette securite : utiliser la restauration cPanel/phpMyAdmin
avec l'administrateur de l'hebergement, ou demander au support un compte de
maintenance limite aux bases temporaires requises. Voir
`CONFIGURATION_SAUVEGARDE_RESTAURATION.md`.

### 2.9. Controle apres mise en ligne

Executer `php scripts/check_production.php`, puis effectuer toute la checklist
de la section 8. Verifier en priorite :

- `https://risfm.exemple.ci/login` sans `/public` dans l'URL ;
- l'envoi du mot de passe oublie et du code OTP ;
- la creation d'un utilisateur et la reception de son lien d'activation ;
- une affectation de mission, sa notification et son rappel ;
- les exports, les pieces jointes et une sauvegarde manuelle ;
- l'absence d'acces Web a `.env`, `storage/`, `config/` et `vendor/`.

## 3. Installation sur XAMPP / WAMP (poste local Windows)

1. Copier le dossier `RISFM/` dans `C:\xampp\htdocs\` (XAMPP) ou `C:\wamp64\www\` (WAMP).
2. Demarrer Apache et MySQL depuis le panneau de controle XAMPP/WAMP.
3. Ouvrir phpMyAdmin (`http://localhost/phpmyadmin`), creer une base `oipi_risfm` (jeu de caracteres `utf8mb4_unicode_ci`), puis importer `schema.sql` via l'onglet **Importer**.
4. Dans le dossier `RISFM/`, copier `.env.example` en `.env` et renseigner :
   ```dotenv
   APP_ENV=development
   APP_DEBUG=true
   APP_URL=http://localhost/RISFM/public

   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=oipi_risfm
   DB_USER=root
   DB_PASS=

   # Desactiver l'OTP uniquement tant que le SMTP local n'est pas configure.
   ENABLE_LOGIN_OTP=false
   ```
5. Ouvrir une invite de commande dans le dossier `RISFM/` et executer `composer install`.
6. Executer `php scripts/migrate.php` afin d'enregistrer et verifier la version du schema.
7. Executer `php scripts/seed.php` pour ecrire les donnees de reference et
   creer le premier administrateur (ajouter `--demo` pour des donnees fictives).
8. Acceder a `http://localhost/RISFM/public/login`, se connecter avec
   `admin@oipi.ci` / `Admin_Oipi2026#`, puis choisir le nouveau mot de passe
   demande.

### Comptes initiaux d'une installation neuve

`schema.sql` ne cree que la structure. `php scripts/seed.php` ecrit ensuite
les donnees de reference et cree le premier administrateur (`admin@oipi.ci`),
dont l'identifiant `OIPI-RISFM-NNNNNN` est derive de son identifiant interne.
Son mot de passe initial figure dans le code : il doit etre change a la
premiere connexion, ce que l'application impose. Relancer le seeder
reinitialise uniquement le mot de passe de cet administrateur ; les donnees de
reference existantes sont conservees.

`php scripts/seed.php --demo` ajoute des comptes et formulaires fictifs. Il est
reserve aux postes de demonstration et refuse de s'executer quand
`APP_ENV=production`.

### Mise a niveau d'une installation existante

Apres sauvegarde de la base et deploiement du nouveau code, appliquer toutes
les migrations manquantes avec une seule commande. Le script lit les
identifiants MySQL depuis `.env`, verrouille l'execution concurrente et ne
rejoue jamais une migration deja enregistree :

```bash
php scripts/migrate.php
php scripts/migrate.php --status
```

La commande applique notamment la normalisation des identifiants, la
securisation des jetons et la table de lecture individuelle des notifications.
Elle controle le checksum de chaque fichier : une migration deja appliquee ne
doit jamais etre modifiee.

Pour controler les performances avant une mise en production volumineuse,
executer `php scripts/test_performance_large_volume.php`. Le test utilise et
supprime une base ephemere ; les mesures de reference sont documentees dans
`docs/PERFORMANCES_GRAND_VOLUME.md`.

> Ne jamais reimporter `schema.sql` sur une base existante : ce fichier contient
> des `DROP TABLE` et supprimerait les donnees. Il est reserve a la creation
> d'une base neuve. Pour une mise a niveau, utiliser uniquement
> `php scripts/migrate.php` apres une sauvegarde.

> Astuce : pour eviter `/public` dans l'URL, configurez un VirtualHost Apache dont le `DocumentRoot` pointe directement sur `RISFM/public` (voir section 5).

### Serveur PHP integre pour le developpement

Depuis la racine du projet :

```bash
php -S localhost:8000 -t public public/router.php
```

L'application est alors disponible sur `http://localhost:8000/login`. Ce
serveur convient uniquement au developpement local ; il ne doit pas etre
utilise en production.

## 4. Installation sur LAMP (Ubuntu/Debian)

```bash
sudo apt update
sudo apt install apache2 mysql-server php php-mysql php-mbstring php-gd php-zip php-xml php-curl composer -y
sudo a2enmod rewrite
sudo systemctl restart apache2

# Base de donnees
sudo mysql -e "CREATE DATABASE oipi_risfm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'risfm_user'@'localhost' IDENTIFIED BY 'MotDePasseFort2026!';"
sudo mysql -e "GRANT ALL PRIVILEGES ON oipi_risfm.* TO 'risfm_user'@'localhost'; FLUSH PRIVILEGES;"
sudo mysql oipi_risfm < schema.sql

# Deploiement de l'application
sudo cp -r RISFM /var/www/risfm
cd /var/www/risfm
cp .env.example .env
# Editer .env avec les identifiants ci-dessus
composer install --no-dev --optimize-autoloader
php scripts/migrate.php
php scripts/seed.php
# Le code reste possede par l'administrateur systeme et n'est pas inscriptible
# par le serveur Web.
sudo chown -R root:www-data /var/www/risfm
sudo find /var/www/risfm -type d -exec chmod 750 {} \;
sudo find /var/www/risfm -type f -exec chmod 640 {} \;

# Seuls les repertoires de donnees doivent etre inscriptibles par PHP.
sudo chown -R www-data:www-data /var/www/risfm/storage /var/www/risfm/public/uploads
sudo chmod 640 /var/www/risfm/.env
```

## 5. Configuration Apache (VirtualHost recommande)

Creer `/etc/apache2/sites-available/risfm.conf` :

```apache
<VirtualHost *:80>
    ServerName risfm.oipi.ci
    DocumentRoot /var/www/risfm/public

    <Directory /var/www/risfm/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/risfm_error.log
    CustomLog ${APACHE_LOG_DIR}/risfm_access.log combined
</VirtualHost>
```

```bash
sudo a2ensite risfm.conf
sudo systemctl reload apache2
```

Avec cette configuration, le `DocumentRoot` pointe directement sur `public/` : le `.htaccess` racine n'est alors plus necessaire (il ne sert que si le `DocumentRoot` reste sur la racine du projet).

## 6. VPS de production — recommandations complementaires

1. **HTTPS obligatoire** : installer un certificat (Let's Encrypt via `certbot`), forcer la redirection HTTP -> HTTPS.
2. **`.env`** : `APP_ENV=production`, `APP_DEBUG=false` (masque les erreurs detaillees aux utilisateurs).
3. **Limitation des connexions** : conserver les seuils recommandes de
   `.env.example` (5 echecs par compte et 30 par IP sur 10 minutes). Le seuil
   IP est plus large car plusieurs agents peuvent sortir par la meme adresse
   publique. L'application utilise uniquement `REMOTE_ADDR` ; derriere un
   reverse proxy, l'administrateur reseau doit transmettre l'IP cliente de
   maniere fiable au serveur Web sans accepter librement les en-tetes du client.
4. **Permissions** : `storage/` et `public/uploads/` doivent etre inscriptibles par l'utilisateur du serveur web uniquement (`www-data`), jamais accessibles en ecriture par d'autres comptes. Les pieces jointes metier sont conservees dans `storage/uploads/formulaires/` et servies uniquement par une route authentifiee ; ne rendez jamais ce dossier public.
5. **Sauvegardes automatiques** : planifier une tache cron appelant un script shell `mysqldump` quotidien, en complement du module de sauvegarde integre a l'application.
6. **Pare-feu** : n'exposer que les ports 80/443 ; MySQL ne doit pas etre accessible depuis Internet.
7. **Mise a jour** : `composer install --no-dev --optimize-autoloader` a chaque deploiement ; ne jamais executer `composer install` avec un `.env` de production expose publiquement.
8. **Journalisation** : superviser `storage/logs/php-error.log` et la table `activites` (journal d'audit applicatif).
9. **Entretien de la securite** : planifier la purge quotidienne suivante. Elle supprime les jetons expires, les tentatives de connexion sortant de la retention et masque les anciennes URL sensibles dans les journaux applicatifs :
   ```cron
   0 2 * * * cd /var/www/risfm && /usr/bin/php scripts/purge_reset_security_data.php >> storage/logs/maintenance.log 2>&1
   ```
   Les journaux d'acces Apache/Nginx ne se trouvent pas dans le projet : appliquez-leur une retention courte et des droits d'acces reserves aux administrateurs systeme.
10. **Relances des missions** : planifier l'execution quotidienne du moteur de
   rappels. La configuration Linux et Windows est detaillee dans
   `docs/CONFIGURATION_RELANCES.md` :
   ```bash
   php scripts/reminder_scheduler.php install
   php scripts/reminder_scheduler.php status
   ```
   Le back-office signale ensuite toute absence d'execution superieure a
   36 heures.
11. **Recette avant ouverture** : executer `php scripts/recette.php` avec le
    compte MySQL de maintenance. La recette travaille exclusivement sur une
    base temporaire préfixée `oipi_risfm_restore_test_`.

En mode `production`, l'application refuse volontairement de demarrer si
`APP_URL` n'utilise pas HTTPS, si l'OTP est desactive, si `MAIL_DRY_RUN=true`
ou si les identifiants MySQL/SMTP sont absents ou encore definis avec les
valeurs d'exemple.

## 7. Alternative Nginx (si prefere a Apache)

```nginx
server {
    listen 80;
    server_name risfm.oipi.ci;
    root /var/www/risfm/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }

    location ~ ^/(storage|config|core|models|controllers)/ {
        deny all;
    }
}
```

## 8. Verification post-installation (checklist)

- [ ] La page `/login` s'affiche avec le logo et le style OIPI.
- [ ] Les donnees et le premier administrateur ont ete crees avec `php scripts/seed.php`.
- [ ] La connexion avec `admin@oipi.ci` fonctionne et le mot de passe initial a ete remplace.
- [ ] Le tableau de bord administrateur affiche les cartes KPI (a 0 tant qu'aucun formulaire n'est saisi).
- [ ] La creation d'un formulaire manquant genere bien un numero automatique (`FM-AAAA-NNNNNN`).
- [ ] Les exports Excel/PDF/Word/CSV se telechargent sans erreur (necessite `composer install` reussi).
- [ ] La creation d'un utilisateur envoie son identifiant et un lien a usage unique lui permettant de choisir son mot de passe, sans afficher de secret a l'administrateur.
- [ ] Une sauvegarde manuelle se cree correctement dans `storage/backups/` (necessite `mysqldump` disponible en ligne de commande, sinon le mode de repli PHP est utilise automatiquement).
- [ ] Le journal d'activite enregistre bien les connexions, ajouts et modifications.
- [ ] La deconnexion automatique apres inactivite fonctionne (attendre la duree configuree en `.env`, 20 minutes par defaut).
- [ ] Le lien « mot de passe oublie » fonctionne, un second lien invalide le premier et toutes les anciennes sessions sont fermees apres la reinitialisation.
- [ ] Le code de connexion par e-mail fonctionne lorsque `ENABLE_LOGIN_OTP=true`.
- [ ] Cinq mots de passe incorrects bloquent temporairement l'identifiant sans
      bloquer un autre poste, et le seuil IP bloque une rotation d'identifiants.
- [ ] `php scripts/purge_reset_security_data.php` supprime les anciennes
      tentatives sans effacer les evenements recents.
- [ ] L'import CSV/XLSX refuse entièrement un fichier contenant une ligne invalide.
- [ ] Une mission affectee apparait sur le tableau de bord du responsable.
- [ ] La saisie d'un resultat « Retrouve » clot les autres missions actives du dossier.
- [ ] Le parcours Retrouve → Numerise → Saisi respecte l'ordre obligatoire.
- [ ] `php scripts/relances.php --status` confirme une execution recente du cron.
- [ ] `php scripts/recette.php` se termine par `RECETTE COMPLETE OK`.

## 9. Support technique

Pour toute anomalie, consulter en priorite :
1. `storage/logs/php-error.log` (erreurs PHP).
2. Le journal d'audit applicatif (menu **Journal d'activite**, action de type `securite`).
3. Les journaux Apache/Nginx (`/var/log/apache2/` ou `/var/log/nginx/`).

## 10. Depannage — erreur 404 "Page introuvable"

Si la page de connexion elle-meme s'affiche mais que toute action (connexion, navigation) renvoie la page 404 stylee de l'application (et non l'erreur 404 generique d'Apache), verifiez dans l'ordre :

1. **Mise a jour du 18/07/2026** : les versions anterieures du routeur ne géraient pas le cas ou l'application est installee dans un sous-dossier de `htdocs` (ex. `http://localhost/RISFM/public/...`). Ce correctif est integre depuis `app/Core/Router.php` v1.0.1 (detection automatique du sous-dossier via `SCRIPT_NAME`) , verifiez que vous utilisez bien la derniere archive fournie.
2. **`mod_rewrite` actif ?** Sur XAMPP, ouvrez `httpd.conf` et verifiez que la ligne `LoadModule rewrite_module modules/mod_rewrite.so` n'est pas commentee, puis redemarrez Apache.
3. **`AllowOverride All`** requis sur le dossier `public/` pour que le `.htaccess` soit pris en compte (sinon toutes les routes sauf `/` renverront une 404 Apache brute, pas la page 404 de l'application).
4. **URL correcte** : sans VirtualHost dedie, l'URL doit inclure `/public/`, par exemple `http://localhost/RISFM/public/login` (et non `http://localhost/RISFM/login`).
5. **`.env` absent ou `APP_URL` mal renseigne** : si le fichier `.env` n'existe pas (seul `.env.example` est fourni), l'application detecte automatiquement le sous-dossier RISFM, c'est le comportement attendu pour un usage local sans configuration. Si vous avez neanmoins defini `APP_URL` dans `.env`, assurez-vous qu'il correspond exactement a l'URL reelle (y compris `/public` si applicable), sinon les liens generes pointeront au mauvais endroit et provoqueront des 404 en cascade.
6. **Cache navigateur** : apres toute modification de `.htaccess` ou de `app/Core/Router.php`, videz le cache ou testez en navigation privee.

Si le probleme persiste apres ces verifications, consultez `storage/logs/php-error.log` : une erreur PHP fatale silencieuse (extension manquante, `composer install` non execute) peut aussi se traduire par une page blanche ou une 500 confondue avec une 404 par l'utilisateur.
