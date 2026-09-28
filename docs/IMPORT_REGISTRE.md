# Import du registre CSV et Excel

Derniere mise a jour : 29 juillet 2026

## Acces

L'import en masse est reserve a l'administrateur. Depuis **Formulaires**,
cliquer sur **Importer**, puis telecharger au besoin le modele CSV ou XLSX.

Le fichier est d'abord copie dans `storage/imports/`, hors de la racine Web.
Il est supprime apres la confirmation, l'annulation ou l'expiration de
l'apercu (30 minutes).

## Colonnes

Les trois colonnes obligatoires sont :

- `Type de titre` : code ou libelle actif ;
- `Annee` : quatre chiffres, entre 2006 et l'annee courante ;
- `Numero du formulaire` : numero officiel, limite a 60 caracteres.

Colonnes facultatives :

- `Statut` : code ou libelle du workflow ; vide signifie `Introuvable` ;
- `Localisation recherchee` ;
- `Responsable` : identifiant OIPI-RISFM, e-mail ou nom complet non ambigu ;
- `Date de recherche` ;
- `Resultat` ;
- `Date du depot` ;
- `Deposant` ;
- `Mandataire` ;
- `Observations` ;
- `Priorite` : `Basse`, `Normale`, `Haute` ou `Urgente`.

La colonne `N°` d'un export RISFM est reconnue et ignoree. Pour un dossier
ayant deja fait l'objet d'une recherche, la localisation, le responsable, la
date et le resultat sont tous obligatoires.

## Formats et limites

- CSV UTF-8, Windows-1252 ou ISO-8859-1 ; separateur point-virgule, virgule ou
  tabulation ;
- Excel `.xlsx` ;
- 10 Mo maximum ;
- 5 000 lignes de donnees maximum ;
- en-tete situe dans les 10 premieres lignes ;
- formules Excel interdites.

## Deroulement

1. Le serveur controle le type reel et la structure du fichier.
2. Toutes les lignes sont normalisees et comparees aux referentiels actifs.
3. L'apercu indique les erreurs a corriger en priorite et un rapport CSV
   telechargeable reprend toutes les lignes.
4. Si une seule erreur subsiste, le bouton de confirmation n'est pas propose.
5. Lors de la confirmation, le fichier est analyse une seconde fois et son
   empreinte est controlee.
6. Toutes les lignes sont ecrites dans une seule transaction. Une collision
   concurrente ou un echec d'audit annule l'import complet.

Chaque formulaire recoit une reference automatique `FM-AAAA-NNNNNN`. Une
recherche historique complete cree egalement la mission, l'instantane
d'historique et, pour un dossier retrouve/numerise/saisi, les jalons de
finalisation necessaires. Aucun e-mail d'affectation n'est envoye pendant cette
reprise historique.

## Recette

Le test non destructif peut etre execute seul :

```bash
php scripts/test_formulaire_import.php
```

Il teste CSV et XLSX, les lignes invalides, l'absence d'import partiel, la
reconstruction de l'historique, la journalisation et les deux modeles. Ses
ecritures sont placees dans une transaction annulee a la fin du test.

La recette a ete reexecutee avec succes le 29 juillet 2026.
