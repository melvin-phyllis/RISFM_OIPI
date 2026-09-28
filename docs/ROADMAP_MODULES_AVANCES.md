# Feuille de route , Modules avances et extensions hors noyau V1

Derniere mise a jour : 29 juillet 2026

Ces modules ont ete identifies comme recommandations complementaires. Ils ne
font pas partie de la demande initiale minimale du registre et doivent être
confirmes par le cahier des charges avant developpement. Les relances et la
finalisation, initialement classees dans cette feuille de route, sont maintenant
livrees ; seule l'affectation automatique reste a developper dans ce domaine.

## 1. Scanner de codes QR / codes-barres pour les boites d'archives

**Objectif :** associer chaque boite d'archive physique a un code QR/code-barres permettant de la localiser instantanement.

**Implementation envisagee :**
- Nouvelle table `boites_archives` (id, code_barre, libelle, localisation_id, capacite, statut).
- Colonne `boite_id` (FK nullable) sur `formulaires_manquants` pour rattacher un formulaire retrouve a sa boite.
- Generation de codes QR cote serveur avec la librairie `endroid/qr-code` (Composer) , pas d'appel a un service externe, generation locale.
- Lecture cote client via l'API `BarcodeDetector` du navigateur (Chrome/Edge) ou la librairie JS `html5-qrcode`, sans dependance serveur supplementaire.
- Ecran dedie : `/archives/boites` (CRUD) + action "Scanner" ouvrant la camera pour rechercher une boite par son code.

## 2. Carte des archives (emplacement physique des boites)

**Objectif :** representation visuelle (plan/etageres) de la localisation physique des boites d'archives.

**Implementation envisagee :**
- Extension de `boites_archives` avec des coordonnees logiques (`salle`, `rangee`, `etagere`, `position`).
- Rendu HTML/CSS en grille (pas de dependance cartographique lourde necessaire, une salle d'archives n'est pas une carte geographique) : chaque etagere = une grille cliquable, chaque case = une boite colore selon son taux de completude.
- Alternative si plusieurs sites : integration Leaflet.js (open-source, sans cle API) avec un plan scanne en image de fond.

## 3. Workflow d'affectation automatique avec suivi des delais et relances

**Objectif :** assigner automatiquement les recherches aux responsables disponibles et relancer en cas de depassement de delai.

**Etat au 29 juillet 2026 :** les relances planifiees, la supervision et
l'escalade sont **entierement implementees et migrees**. Le systeme cree des
rappels a J-2, J, J+1 puis tous les 3 jours, avec escalade aux
administrateurs a J+7 et chaque semaine. L'installateur
`scripts/reminder_scheduler.php` configure le cron, le back-office supervise
la derniere execution et signale toute absence superieure a 36 heures.

La finalisation en trois etapes (Retrouve → Numerise → Saisi) avec cycles de
suivi et historique par cycle est aussi implementee.

L'affectation automatique selon des regles ou en round-robin reste a confirmer
et a developper ; les affectations sont encore decidees manuellement.

**Implementation envisagee (restante) :**
- Table `regles_affectation` (type_titre_id nullable, priorite, responsable_id, actif) definissant des regles simples (round-robin ou affectation fixe par type de titre).
- Extension du script de relances pour inclure l'auto-affectation des dossiers non affectes.

## 4. Module de numerisation avance et GED (OCR, indexation automatique)

**Objectif :** aller au-dela du televersement simple de pieces jointes (deja fonctionnel en v1.0) pour offrir une reconnaissance de texte et une indexation automatique du contenu scanne.

**Implementation envisagee :**
- OCR via `tesseract-ocr` (binaire systeme) pilote en PHP par la librairie `thiagoalessio/tesseract_ocr`, execute en tache asynchrone (file d'attente simple en base : table `taches_ocr`) pour ne pas bloquer la requete HTTP de televersement.
- Le texte extrait est stocke dans une nouvelle colonne `pieces_jointes.texte_ocr` (TEXT, avec index FULLTEXT MySQL) pour permettre une recherche plein texte dans les documents scannes, en complement de la recherche avancee deja disponible sur les metadonnees.
- Interface de validation manuelle (l'OCR n'est jamais fiable a 100%) avant indexation definitive.

## 5. Rapports statistiques automatiques periodiques (quotidien/mensuel/annuel)

**Objectif :** generation et diffusion automatique de rapports (deja exportables manuellement en v1.0 via Excel/PDF/Word) selon un calendrier.

**Implementation envisagee :**
- Reutilisation directe des methodes `FormulaireModel::stats*()` et des controleurs d'export existants.
- Tache planifiee (cron) generant les fichiers dans `storage/rapports/` et les rattachant a une notification pour la Direction Generale (table `notifications`, type `systeme`).
- Envoi par e-mail via le connecteur SMTP central `AppMailer`, desormais utilise par `AuthController::forgot()` et directement reutilisable pour la diffusion des rapports.

## 6. Synthese des efforts estimes

| Module | Complexite | Dependance externe |
|---|---|---|
| QR / codes-barres | Moyenne | `endroid/qr-code` (Composer, local) |
| Carte des archives (grille) | Faible | Aucune |
| Affectation automatique | Moyenne | Regles métier à confirmer ; relances déjà livrées |
| GED / OCR | Elevee | `tesseract-ocr` (binaire systeme) |
| Rapports periodiques | Faible | Tache planifiee (cron) + SMTP optionnel |

Ces modules peuvent etre developpes independamment les uns des autres et ajoutes progressivement sans remettre en cause l'architecture MVC existante.
