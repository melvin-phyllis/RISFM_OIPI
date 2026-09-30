# Matrice des droits du registre des formulaires

Derniere mise a jour : 30 septembre 2026

La matrice effective est definie dans `config/roles.php`. Les lignes de la table
`permissions` constituent le catalogue affiche par le back-office, mais les
controles de securite doivent toujours etre realises cote serveur.

| Action | Administrateur | Responsable | Agent | Consultation |
|---|---:|---:|---:|---:|
| Consulter le registre | Oui | Oui | Oui | Oui |
| Declarer un formulaire manquant | Oui | Oui | Oui | Non |
| Modifier les informations generales | Oui | Oui | Non | Non |
| Ajouter une mission parallele | Oui | Oui | Non | Non |
| Annuler ou reaffecter une mission active | Oui | Oui | Non | Non |
| Enregistrer un resultat | Tous les dossiers | Seulement si affecte | Seulement si affecte | Non |
| Declarer un formulaire retrouve sans mission | Oui, Retrouve | Oui, Retrouve | Oui, A verifier | Non |
| Confirmer un signalement A verifier | Oui | Oui | Non | Non |
| Valider Retrouve vers Numerise puis Saisi | Oui | Oui | Non | Non |
| Rouvrir un dossier finalise | Oui | Non | Non | Non |
| Ajouter une piece | Tous les dossiers actifs | Seulement si affecte | Seulement si affecte | Non |
| Supprimer une piece | Toute piece d'un dossier actif | Sa propre piece si toujours affecte | Sa propre piece si toujours affecte | Non |
| Exporter | Oui | Oui | Non | Non |
| Importer un registre CSV/XLSX | Oui | Non | Non | Non |
| Archiver ou restaurer | Oui | Non | Non | Non |

## Permissions granulaires

- `formulaires.update_metadata` : modifier le type, le numero officiel et les informations generales.
- `formulaires.assign` : choisir la localisation, le responsable, l'echeance et la priorite.
- `formulaires.declare_found` : signaler un formulaire trouve sans mission (a la creation ou depuis sa fiche), en indiquant la localisation et la date ; le dossier passe a `A verifier`.
- `formulaires.declare_found_validated` : declarer ou confirmer directement un formulaire retrouve ; le dossier passe a `Retrouve` et les missions en cours sont cloturees. Ajouter cette permission a l'agent dans `config/roles.php` pour qu'il valide seul.
- `formulaires.finalize` : valider, dans l'ordre, les etapes Numerise et Saisi.
- `formulaires.reopen` : rouvrir un dossier resolu dans un nouveau cycle ; reserve a l'administrateur.
- `formulaires.record_result_any` : saisir un resultat sur tout dossier ; reserve a l'administrateur.
- `formulaires.record_result_own` : saisir uniquement le resultat d'une mission affectee a l'utilisateur connecte.
- `formulaires.attach_any` : ajouter une piece sur tout dossier actif ; reserve a l'administrateur.
- `formulaires.attach_own` : ajouter une piece sur un dossier affecte a l'utilisateur connecte.
- `formulaires.delete_attachment_any` : supprimer toute piece d'un dossier actif ; reserve a l'administrateur.
- `formulaires.delete_attachment_own` : supprimer uniquement une piece televersee par l'utilisateur, tant que le dossier lui est affecte.
- `formulaires.import` : analyser et confirmer un import atomique CSV/XLSX ; reserve a l'administrateur.

## Regles obligatoires cote serveur

1. Un formulaire archive reste en lecture seule, y compris pour les pieces jointes.
2. Masquer un bouton ne remplace jamais la verification de la permission dans le controleur.
3. Le createur d'un dossier n'en devient pas automatiquement le responsable de recherche.
4. Une affectation ne cree aucune ligne dans l'historique des resultats.
5. Le resultat ne peut etre enregistre que pour une mission active au statut `affectee` ou `en_cours`.
6. Une reaffectation annule la mission precedente et cree une mission de remplacement liee, dans le meme cycle.
7. L'administrateur conserve un droit exceptionnel afin d'eviter le blocage d'un dossier.
8. « Ajouter une mission » conserve les missions deja actives et permet une recherche parallele.
9. « Reaffecter » annule la mission precedente et cree une mission de remplacement liee, dans le meme cycle.
10. Une annulation ne supprime rien : son motif, son acteur et sa date restent historises.
11. Une reouverture cree un nouveau cycle sans effacer les recherches ni les jalons de finalisation precedents.
12. Un import n'est jamais partiel : toute ligne invalide bloque le fichier
    complet et aucune donnee ne doit etre ecrite avant la confirmation de
    l'apercu.
13. Une declaration directe cree une mission au nom du declarant, deja cloturee
    avec son resultat : l'historique, la finalisation et les statistiques par
    responsable et par localisation restent complets.
