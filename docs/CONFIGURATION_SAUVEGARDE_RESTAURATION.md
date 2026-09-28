# Configuration MySQL des sauvegardes et restaurations

Derniere mise a jour : 10 aout 2026

Le module restaure toujours le fichier dans une base ephemere nommee
`oipi_risfm_restore_test_<suffixe>` avant de toucher la base principale.

Une sauvegarde n'est declaree complete que si elle contient le manifeste P10 :
23 tables, 5 vues statistiques, la procedure `sp_kpi_globaux` et les triggers
requis (aucun actuellement). Les vues et la procedure sont ajoutees depuis les
definitions canoniques de l'application puis recreees de facon controlee apres
la restauration, sans reutiliser le `DEFINER` du serveur source.

Il est recommande d'utiliser un compte MySQL dedie. A executer avec un compte
d'administration MySQL, en remplacant le mot de passe :

```sql
CREATE USER IF NOT EXISTS 'risfm_backup'@'localhost'
IDENTIFIED BY 'MotDePasseMaintenanceTresFort!';

GRANT ALL PRIVILEGES ON `oipi_risfm`.*
TO 'risfm_backup'@'localhost';

GRANT ALL PRIVILEGES ON `oipi\_risfm\_restore\_test\_%`.*
TO 'risfm_backup'@'localhost';

FLUSH PRIVILEGES;
```

Le second `GRANT` est obligatoire. Sans lui, MySQL peut accepter la creation de
la base de test mais refuser ensuite sa connexion avec l'erreur `1044 Access
denied ... restore_test_*`. Verifier avec :

```sql
SHOW GRANTS FOR 'risfm_backup'@'localhost';
```

## Cas particulier d'un hebergement cPanel

Dans cPanel, les noms de bases et d'utilisateurs sont generalement prefixes par
le nom du compte, par exemple `compte_oipi_risfm`. Il faut renseigner ces noms
complets dans `.env`.

Sur de nombreuses offres mutualisees, l'utilisateur MySQL ne peut pas executer
`CREATE DATABASE`, `DROP DATABASE` ni recevoir un droit generique sur des bases
temporaires. Le `GRANT` ci-dessus ne peut alors etre effectue que par
l'hebergeur, pas depuis phpMyAdmin avec le compte cPanel.

Avant d'autoriser une restauration depuis le back-office, executer depuis
**Terminal** ou SSH :

```bash
cd /home/COMPTE_CPANEL/risfm
php scripts/test_p10_backup_restore.php
```

Si le test echoue sur la creation de la base temporaire, ne jamais modifier le
code pour restaurer directement la base principale. Trois solutions restent
sures :

1. demander au support d'hebergement un compte de maintenance limite aux bases
   temporaires du RISFM ;
2. restaurer depuis le module de sauvegarde cPanel ou phpMyAdmin sous le controle
   de l'administrateur de l'hebergement ;
3. migrer vers une offre VPS ou cPanel disposant des privileges necessaires.

La creation d'une sauvegarde reste possible meme si la restauration securisee
n'est pas autorisee. Verifier toutefois que `mysqldump` est disponible ; sinon
l'application utilise son export PDO de repli.

Aucun privilege global `SHOW_ROUTINE` ou `SYSTEM_USER` n'est requis : les
routines sont restaurees depuis le manifeste canonique de l'application.

Puis renseigner le fichier `.env` :

```dotenv
DB_MAINTENANCE_USER=risfm_backup
DB_MAINTENANCE_PASS=MotDePasseMaintenanceTresFort!
```

Le mot de passe n'est jamais place dans la commande `mysql` ou `mysqldump`.
L'application utilise un fichier d'options temporaire en permission `0600`,
efface son contenu puis le supprime a la fin de chaque operation.

## Deroulement d'une restauration

1. validation du format, de la taille, des instructions et du manifeste ;
2. import reel dans une base temporaire ;
3. recreation et lecture des cinq vues, puis controle fonctionnel de la
   procedure KPI ;
4. creation d'une sauvegarde automatique `risfm_before_restore_*.sql` de la
   base principale ;
5. test reel de cette sauvegarde de securite dans une autre base temporaire ;
6. import principal, recreation controlee des objets programmables et nouveau
   controle du manifeste.

Si une etape echoue avant l'import principal, la base principale reste intacte.
Si l'import principal echoue, le fichier de securite est conserve dans
`storage/backups/` et son nom est affiche a l'administrateur.

## Test periodique recommande

Le script suivant cree une sauvegarde native et une sauvegarde PDO, restaure
reellement chacune dans une base temporaire, controle tous les objets puis
nettoie les fichiers et bases de test :

```bash
php scripts/test_p10_backup_restore.php
```

Exemple de verification hebdomadaire en production :

```cron
0 3 * * 0 cd /var/www/risfm && /usr/bin/php scripts/test_p10_backup_restore.php >> storage/logs/maintenance.log 2>&1
```

Les anciennes sauvegardes sans marqueur P10 sont refusees par securite : elles
ne prouvent pas la presence des vues, routines et triggers attendus.

Ne jamais tester une restauration directement sur la base principale. Utiliser
le test P10 ou la validation temporaire intégrée au back-office. Une
restauration principale reste une action critique réservée à l'administrateur
et exige une confirmation explicite.
