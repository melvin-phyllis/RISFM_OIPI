# Import du registre CSV et Excel

Derniere mise a jour : 30 septembre 2026

## Acces

L'import en masse est reserve a l'administrateur. Depuis **Formulaires**,
cliquer sur **Importer**, puis telecharger au besoin le modele CSV ou XLSX.

Le fichier est d'abord copie dans `storage/imports/`, hors de la racine Web.
Il est supprime apres la confirmation, l'annulation ou l'expiration de
l'apercu (30 minutes).

## Colonnes

Le modele reprend exactement la saisie manuelle d'un formulaire :

| Colonne | Obligatoire | Valeurs |
|---|---|---|
| `Type de titre` | oui | code ou libelle actif |
| `Annee` | oui | quatre chiffres, entre 2006 et l'annee courante |
| `Numero du formulaire` | oui | numero officiel, 60 caracteres maximum |
| `Priorite` | non | `Basse`, `Normale`, `Haute` ou `Urgente` ; vide = `Normale` |

Types de titres livres (nomenclature OIPI) : `BRV` Brevet, `DMI` Dessin &
Modele Industriel, `IG` Indication Geographique, `MAQ` Marque, `MC` Marque
Collective, `MU` Modele d'Utilite, `NC` Nom Commercial, `OV` Obtention
Vegetale. Le code est la saisie la plus sure ; le libelle est aussi accepte.

La colonne `N°` est reconnue et ignoree. Les colonnes de l'ancien modele de
reprise historique (`Statut`, `Localisation recherchee`, `Responsable`,
`Date de recherche`, `Resultat`, `Date du depot`, `Deposant`, `Mandataire`,
`Observations`) sont refusees avec un message qui les nomme : aucune donnee
n'est ignoree sans que l'utilisateur le sache.

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

Chaque formulaire recoit une reference automatique `FM-AAAA-NNNNNN` et est
cree au statut `Introuvable`, sans mission, exactement comme une saisie
manuelle. Les affectations se font ensuite depuis la fiche du formulaire.

## Recette

Le test non destructif peut etre execute seul :

```bash
php scripts/test_formulaire_import.php
```

Il teste CSV et XLSX, les lignes invalides, le refus des anciennes colonnes,
l'absence d'import partiel, le statut initial, la journalisation et les deux
modeles. Ses ecritures sont placees dans une transaction annulee a la fin du
test.

La recette a ete reexecutee avec succes le 30 septembre 2026.
