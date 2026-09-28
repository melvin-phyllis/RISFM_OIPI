# Recette automatisee RISFM (P12)

Derniere mise a jour : 4 aout 2026

## Commande unique

Depuis la racine du projet :

```bash
php scripts/recette.php
```

La commande n'utilise pas la base applicative `oipi_risfm`. Elle cree une base
dont le nom commence par `oipi_risfm_restore_test_recette_`, charge
`schema.sql`, execute les tests, puis supprime cette base meme en cas d'echec.

## Prerequis

- MySQL doit etre demarre ;
- les identifiants de `.env` doivent permettre de creer et supprimer les bases
  temporaires `oipi_risfm_restore_test_%` ;
- les dependances Composer doivent etre installees ;
- `mysqldump` et Google Chrome doivent etre disponibles.

## Couverture

La recette couvre :

- authentification, code de connexion, politique de mot de passe et droits des
  quatre roles ;
- huit creations concurrentes de numeros automatiques ;
- validations metier et contrainte anti-doublon ;
- import CSV/XLSX, aperçu, atomicité, reprise de l'historique et modèles ;
- affectations paralleles, annulation manuelle avec motif, reaffectation liee,
  reouverture dans un nouveau cycle et conservation des cycles precedents ;
- finalisation en trois etapes (Retrouve → Numerise → Saisi) avec controle de
  l'ordre sequentiel et historique par cycle ;
- integrite du workflow systeme : les sept statuts proteges, le flag `resolu`,
  l'ordre et la coherence sont verifies a chaque execution ;
- notifications individuelles et generales, contenu du courriel et journal
  d'audit ;
- statistiques sur un jeu connu de trois dossiers ;
- exports CSV, XLSX, PDF et DOCX, avec controle MIME et structure interne ;
- revocation immediate d'un utilisateur deja connecte ;
- sauvegardes et restaurations completes par `mysqldump` et PDO ;
- limitation des echecs de connexion par identifiant et IP, delai et purge ;
- migrations SQL, idempotence et checksums (21 migrations ordonnees) ;
- affichage reel dans Chrome aux formats ordinateur (1366 px), tablette
  (820 px) et telephone (390 px), sans debordement horizontal de la page.

Les e-mails sont simules exclusivement lorsque `APP_ENV=testing` et
`MAIL_DRY_RUN=true`. La connexion SMTP n'est donc pas sollicitee pendant une
recette automatique et aucun utilisateur reel ne recoit de message.

Le resultat attendu se termine par :

```text
RECETTE COMPLETE OK , toutes les donnees de test etaient isolees.
```

Le sous-test fonctionnel P12 peut etre relance seul pendant le developpement :

```bash
php scripts/test_p12_acceptance.php
```

Il a ete reexecute avec succes le 4 aout 2026. Il doit etre relance apres
chaque modification de securite et ne remplace pas
`scripts/recette.php` avant une livraison, car la commande complete ajoute les
tests de migration, sauvegarde, performance, import et resilience.
