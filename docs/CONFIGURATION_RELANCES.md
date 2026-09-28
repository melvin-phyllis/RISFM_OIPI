# Configuration des relances automatiques

Derniere mise a jour : 10 aout 2026

## Fonctionnement

Le script `scripts/relances.php` traite uniquement les missions actives qui
possedent une date limite :

- J-2 : rappel au responsable ;
- jour J : rappel d'echeance ;
- J+1 puis tous les 3 jours : alerte de retard ;
- J+7 puis toutes les semaines : escalade aux administrateurs actifs.

Chaque relance cree une notification interne et tente d'envoyer un e-mail. La
table `relances_missions` conserve l'historique et empeche deux envois identiques
le meme jour. Les e-mails en echec sont retentes pendant sept jours sans
dupliquer les notifications internes. Une mission terminee, annulee ou rattachee
a un formulaire archive n'est plus relancee.

## Installation Linux automatisee

Appliquer d'abord les migrations :

```bash
php scripts/migrate.php
```

Installer ou mettre a jour la tache quotidienne du compte d'exploitation :

```bash
php scripts/reminder_scheduler.php install
```

Verifier sa presence sans envoyer de rappel :

```bash
php scripts/reminder_scheduler.php status
```

L'installateur preserve les autres lignes de la crontab, utilise le chemin
absolu du projet et ecrit la sortie dans
`storage/logs/relances-cron.log`. Il peut etre relance apres une mise a jour du
projet. Apres un changement du chemin absolu du projet,
supprimez explicitement l'ancien bloc puis relancez l'installation depuis le
nouveau chemin.

L'horaire est configurable dans `.env` :

```dotenv
ENABLE_MISSION_REMINDERS=true
REMINDER_CRON_SCHEDULE="0 8 * * *"
```

Le script utilise un verrou MySQL : si une execution est deja en cours, une
seconde execution s'arrete sans envoyer de doublon.

Le contexte CLI charge explicitement `vendor/autoload.php` avant de traiter
les missions. Si les dependances Composer ou PHPMailer sont absentes, le
script s'arrete immediatement avec une erreur exploitable, avant toute ecriture
de notification.

La configuration manuelle reste possible lorsque l'hebergeur interdit la
commande `crontab` :

```cron
0 8 * * * cd /var/www/risfm && /usr/bin/php scripts/relances.php >> storage/logs/relances-cron.log 2>&1
```

## Installation sur cPanel

Sur cPanel, ne pas utiliser `scripts/reminder_scheduler.php install` : les
hebergements mutualises interdisent souvent au processus PHP de modifier la
crontab. Creer plutot la tache dans **Advanced > Cron Jobs**.

Identifier d'abord le binaire PHP depuis **Terminal** avec `which php`. Ajouter
ensuite une tache quotidienne a 08:00 en adaptant le compte et le chemin :

```cron
0 8 * * * /usr/local/bin/php /home/COMPTE_CPANEL/risfm/scripts/relances.php >> /home/COMPTE_CPANEL/risfm/storage/logs/relances-cron.log 2>&1
```

Le binaire peut etre different selon l'hebergeur, par exemple
`/usr/local/bin/ea-php83`. Les deux chemins utilises dans la commande doivent
etre absolus. Le dossier `storage/logs/` doit exister et etre inscriptible par
le compte cPanel.

Tester manuellement la meme version de PHP avant d'activer la planification :

```bash
cd /home/COMPTE_CPANEL/risfm
/usr/local/bin/php scripts/relances.php --preview
/usr/local/bin/php scripts/relances.php
/usr/local/bin/php scripts/relances.php --status
```

Une fois la tache enregistree, verifier le lendemain son etat dans
**Administration > Configuration > Rappels automatiques** et consulter
`storage/logs/relances-cron.log`. Une ligne Cron presente dans cPanel ne prouve
pas a elle seule que PHP, le SMTP et les permissions fonctionnent.

## Supervision

La section **Administration > Configuration > Rappels automatiques** affiche :

- l'etat de la derniere execution ;
- ses heures de debut et de fin ;
- le nombre de missions, rappels, e-mails et erreurs ;
- une alerte si aucune execution n'a eu lieu depuis plus de 36 heures.

Le meme controle est disponible en ligne de commande :

```bash
php scripts/relances.php --status
```

Cette commande retourne le code `2` si aucune execution n'a encore ete
enregistree, si la derniere a echoue, si des e-mails sont en erreur ou si la
tache est silencieuse depuis plus de 36 heures. Elle peut donc etre branchee a
une supervision externe sans envoyer de courriel.

Avant une premiere execution reelle, afficher sans aucune ecriture le nombre
de notifications et d'e-mails qui seraient produits :

```bash
php scripts/relances.php --preview
```

## XAMPP ou WAMP sous Windows

Utiliser le Planificateur de taches Windows :

- declencheur : tous les jours a 08:00 ;
- programme : `C:\xampp\php\php.exe` ou le `php.exe` de WAMP ;
- argument : `C:\xampp\htdocs\RISFM\scripts\relances.php` ;
- demarrer dans : `C:\xampp\htdocs\RISFM`.

## Conditions necessaires

- la migration `20260721_15_relances_missions` doit etre appliquee ;
- les dependances doivent etre installees avec `composer install` afin que
  PHPMailer soit disponible dans le contexte CLI ;
- `ENABLE_MISSION_REMINDERS` doit etre a `true` ;
- les variables `MAIL_*` de `.env` doivent etre valides pour les e-mails ;
- `APP_URL` doit contenir l'URL publique complete afin que les liens des e-mails
  pointent vers l'application ;
- les responsables doivent avoir une adresse e-mail valide et un compte actif ;
- `storage/logs/` doit etre inscriptible par le compte qui lance la tache.

Une panne SMTP ne bloque pas la notification interne. Le script retourne le
code `2` lorsqu'au moins un e-mail echoue, afin que la supervision puisse le
signaler.

## Verification apres deploiement

```bash
php scripts/relances.php --preview
php scripts/relances.php
php scripts/relances.php --status
```

La premiere commande ne modifie rien. La deuxieme effectue un passage reel et
la derniere controle le heartbeat enregistré. Verifier ensuite le journal
`storage/logs/relances-cron.log`, les notifications internes et, si SMTP est
actif, la reception du courriel par un compte de test.
