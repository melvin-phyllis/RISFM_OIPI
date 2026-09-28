# Performance sur grands volumes

Derniere mise a jour documentaire : 29 juillet 2026

## Objectif

Verifier que le registre reste utilisable avec un volume nettement superieur
aux donnees de demonstration, sans modifier la base de travail.

Le script `scripts/test_performance_large_volume.php` cree une base MySQL
ephemere, importe `schema.sql`, genere des donnees realistes, mesure les parcours
principaux puis supprime toujours cette base. Le jeu par defaut contient 20 000
formulaires et 25 000 missions, dont des recherches paralleles.

## Mesures de reference du 21/07/2026

| Parcours | Mesure | Seuil automatique |
| --- | ---: | ---: |
| Premiere page du registre (25 lignes) | 454,3 ms | 1 000 ms |
| Comptage complet | 69,0 ms | 500 ms |
| Filtre annee + statut + responsable | 2,9 ms | 1 000 ms |
| Comptage filtre par responsable | 39,1 ms | 500 ms |
| Missions actives du dashboard agent | 31,3 ms | 500 ms |
| Cinq series statistiques | 250,9 ms | 1 500 ms |
| Export complet de 20 000 lignes | 10,45 s | 25 s |

L'export par lots n'a ajoute que 2 Mio au pic memoire. Les temps dependent du
serveur et de sa charge ; le seuil volontairement plus large de 25 secondes
evite les echecs intermittents tout en detectant une regression importante.

## Optimisations appliquees

- le comptage du registre n'execute plus les trois sous-requetes d'affichage
  des responsables, missions et localisations pour chaque dossier ;
- index `(est_archive, annee, id)` pour la pagination stable du registre et de
  l'export ;
- index `(est_archive, statut_id, annee)` pour les filtres usuels ;
- index `(formulaire_id, responsable_id)` pour les recherches par responsable.

Ces index sont installes par la migration
`20260721_14_performance_grand_volume`.

## Commandes

```bash
php scripts/migrate.php
php scripts/test_performance_large_volume.php
```

Pour une campagne ponctuelle plus importante :

```bash
RISFM_PERF_VOLUME=50000 php scripts/test_performance_large_volume.php
```

La valeur est bornee entre 5 000 et 100 000. Le compte de maintenance configure
dans `.env` doit pouvoir creer et supprimer les bases de test dont le nom
commence par `oipi_risfm_restore_test_`.
