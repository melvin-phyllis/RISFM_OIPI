# Audit fonctionnel priorise , OIPI RISFM

Date de l'audit initial : 19 juillet 2026

Derniere mise a jour : 4 aout 2026

Projet : Registre informatise de suivi des formulaires manquants (RISFM)

## 1. Conclusion generale

L'application dispose maintenant d'un noyau V1 complet et testable : registre,
missions paralleles, historique des recherches, finalisation, audit,
notifications, imports, exports, sauvegardes et migrations sont implementes.
Les corrections P1 a P12 ont ete integrees. La recette complete, incluant le
rate-limiting par identifiant/IP, les migrations et 20 000 formulaires de test,
a ete reexecutee avec succes le 4 aout 2026.

Le projet peut entrer en preproduction technique. Une mise en production
nationale reste conditionnee par la validation formelle du cahier des charges,
des definitions statistiques par la Direction Generale, de la politique
d'exploitation (SMTP, cron, sauvegardes, supervision) et par une recette
complete dans l'environnement cible.

## 2. Fonctionnalites deja presentes

- [x] Registre avec les neuf colonnes demandees.
- [x] Ajout, modification et consultation des formulaires.
- [x] Recherche et filtres avances.
- [x] Contrainte de doublon sur l'annee, le type et le numero du formulaire.
- [x] Numerotation automatique des enregistrements.
- [x] Affectation et reaffectation des responsables.
- [x] Notifications internes lors des affectations.
- [x] Envoi d'e-mails lors des affectations et reaffectations.
- [x] Exports Excel, PDF, Word et CSV.
- [x] Gestion des utilisateurs et des roles.
- [x] Journal d'activite et historique des connexions.
- [x] Statistiques et tableaux de bord.
- [x] Pieces jointes conservees dans un espace prive.
- [x] Mot de passe oublie par e-mail.
- [x] Verification de connexion par code e-mail, configurable dans `.env`.
- [x] Sauvegarde et restauration avec confirmation critique et base de test.
- [x] Import historique CSV/XLSX avec apercu et transaction atomique.
- [x] Missions paralleles, annulation, reaffectation et reouverture par cycle.
- [x] Finalisation sequentielle Retrouve → Numerise → Saisi.
- [x] Relances automatiques supervisables et installateur de tache cron.
- [x] Interface responsive avec identite visuelle OIPI/RISFM.

## 3. Corrections obligatoires avant une mise en production

### P1 , Historique complet des recherches

Statut : **Implemente, migre et teste**

- [x] Creer une table `historique_recherches` ou `recherches_formulaire`.
- [x] Enregistrer chaque tentative de recherche au lieu d'ecraser la precedente.
- [x] Conserver pour chaque tentative :
  - le formulaire concerne ;
  - la localisation inspectee ;
  - le responsable ;
  - la date de recherche ;
  - le statut ou resultat ;
  - les observations ;
  - l'utilisateur ayant saisi l'action ;
  - la date et l'heure de saisie.
- [x] Afficher la chronologie des recherches sur la fiche du formulaire.
- [x] Signaler qu'une localisation a deja ete inspectee afin d'eviter une
  recherche redondante.
- [x] Conserver les champs actuels du formulaire comme resume de la derniere
  situation, si cela reste utile pour le registre principal.
- [x] Migration enregistree dans le systeme de migrations et recette dynamique
  effectuee.

Implementation actuelle : `recherches_formulaire` conserve chaque resultat,
`missions_recherche` conserve les affectations et leur cycle, tandis que
`formulaires_manquants` reste le resume de la situation courante affichee dans
le registre.

### P2 , Journal metier et tracabilite fiable

Statut : **Implemente, migre et teste**

- [x] Enregistrer les anciennes et nouvelles valeurs lors des modifications.
- [x] Attribuer chaque action a l'utilisateur reellement connecte.
- [x] Conserver un instantane permanent de l'identite de l'acteur, meme si son
  compte est ensuite renomme ou supprime.
- [x] Corriger le trigger SQL qui attribue actuellement un changement de statut
  au responsable du dossier.
- [x] Enregistrer explicitement les changements de statut et d'affectation.
- [x] Ajouter l'identifiant de l'entite concernee dans le journal.
- [x] Remplacer la suppression definitive d'un formulaire par un archivage
  logique.
- [x] Demander un motif lors d'un archivage ou d'une annulation.
- [x] Permettre a l'administrateur de consulter les enregistrements archives.
- [x] Rendre atomiques les changements de formulaire et leur trace d'audit :
  si le journal ne peut pas etre ecrit, la modification est annulee.
- [x] Migration applicative P2 appliquee et recette dynamique locale effectuee
  le 19 juillet 2026. La migration est relancable apres une interruption.

Implementation : le registre actif, les recherches, les exports et les
statistiques excluent les formulaires archives. Leur historique et leurs pieces
jointes restent conserves. L'administrateur peut les consulter en lecture seule
depuis `/formulaires/archives`, puis les restaurer. Le journal affiche la cible
et les differences avant/apres.

La procedure SQL `sp_kpi_globaux`, lorsqu'elle appartient a `root`, se met a
jour separement avec `migrations/20260719_admin_sp_kpi_archives.sql`. Elle n'est
plus une dependance du tableau de bord : les KPI applicatifs sont calcules par
le modele avec les memes regles.

### P3 , Date de resolution et statistiques exactes

Statut : **Implemente dans le code et applique sur la base locale le 19 juillet 2026 , interpretation metier a valider avec la Direction Generale**

- [x] Ajouter une colonne `date_resolution`.
- [x] Renseigner cette date lors du premier passage vers un statut resolu.
- [x] Ne pas deplacer la date de resolution lors d'une modification ulterieure.
- [x] Calculer la progression mensuelle a partir de `date_resolution` et non de
  `mis_a_jour_le`.
- [x] Corriger le compteur utilisateur des dossiers retrouves : il utilise
  actuellement seulement les dix dossiers recents.
- [x] Utiliser le champ `statuts.resolu` plutot que comparer les libelles
  `Retrouve` et `Saisi`.
- [ ] Verifier la signification de chaque graphique avec la Direction Generale.

Definitions actuellement appliquees :

- « Situation par annee du titre » regroupe les dossiers selon l'annee du titre
  et leur statut actuel.
- « Repartition par type/statut/responsable » porte sur les dossiers actifs,
  non archives, dans leur situation actuelle.
- « Premieres resolutions par mois » compte une seule fois chaque formulaire,
  au mois de sa premiere `date_resolution`. Une reouverture ulterieure ne
  deplace ni ne supprime cet evenement historique.
- Le compteur individuel porte sur tous les dossiers actifs actuellement
  assignes a l'utilisateur, sans limite de pagination, et utilise
  `statuts.resolu`.

Migration : `migrations/20260719_p3_date_resolution.sql` (idempotente).

### P4 , Revocation immediate des acces

Statut : **Implemente dans le code, applique et teste sur la base locale le 19 juillet 2026**

- [x] Verifier a chaque requete que l'utilisateur existe encore et reste actif.
- [x] Recharger le role depuis la base ou invalider la session apres un
  changement de role.
- [x] Fermer toutes les sessions lors de la desactivation d'un compte.
- [x] Fermer toutes les sessions lors de la suppression d'un compte.
- [x] Fermer les autres sessions apres une reinitialisation de mot de passe.
- [x] Empecher un compte desactive ou supprime de continuer a utiliser une
  ancienne session.

Implementation : `utilisateurs.session_version` est copie dans chaque session
authentifiee puis controle au debut de chaque requete. Une desactivation, une
reactivation ou un changement de mot de passe incremente cette version et clot
les connexions concernees. Le role, le nom, le statut actif et l'obligation de
changer le mot de passe sont recharges depuis la base a chaque requete. Les
challenges 2FA et les liens de reinitialisation emis avant une revocation sont
egalement invalides. Lors d'un changement de mot de passe personnel, seule la
session courante ayant prouve le mot de passe actuel peut rester ouverte.

Migration : `migrations/20260719_p4_revocation_sessions.sql` (idempotente).

### P5 , Validation metier des formulaires

Statut : **Corrige le 19/07/2026**

- [x] Verifier cote serveur que le type de titre existe et est actif.
- [x] Verifier que le statut existe et peut etre utilise.
- [x] Verifier que la localisation existe et est active.
- [x] Verifier que le responsable existe, est actif et possede un role autorise.
- [x] Valider strictement l'annee et les dates.
- [x] Controler la coherence entre la date de depot et la date de recherche.
- [x] Valider les valeurs de priorite cote serveur. Le niveau d'urgence,
  redondant avec la priorite, a ete retire de l'interface le 22/07/2026. Sa
  colonne reste uniquement pour la compatibilite des anciennes bases et n'est
  plus modifiee sur les dossiers existants.
- [x] Definir les regles de coherence entre statut et resultat.
- [x] Afficher un message precis pour les erreurs de cle etrangere au lieu de
  toujours annoncer un doublon.
- [x] Gerer proprement une collision lors de la modification d'un formulaire.

Regles retenues : l'annee est comprise entre 2006 et l'annee courante ; les
dates de depot et de recherche ne peuvent pas etre futures ; une recherche ne
peut pas preceder le depot. Seuls les comptes actifs ayant le role
`administrateur`, `responsable` ou `agent` peuvent etre affectes. Seuls les
sept statuts systeme actifs du workflow sont utilisables. Leur code, ordre,
activation et caractere resolu sont proteges ; seuls leur libelle et leur
couleur sont personnalisables. Un statut dont `resolu = 1`
exige une localisation, un responsable, une date de recherche et un resultat.
Le statut reste la source de verite pour les statistiques ; le texte libre du
resultat sert uniquement de justification.

Implementation : `core/FormulaireMetierValidator.php`, controles de collision
dans `models/FormulaireModel.php` et traduction des erreurs SQL dans
`controllers/FormulaireController.php`. Test non destructif :
`php scripts/test_p5_validation.php`.

### P6 , Export complet du registre

Statut : **Corrige le 19/07/2026**

- [x] Supprimer la limite silencieuse de 5 000 formulaires.
- [x] Utiliser un export par flux ou par lots pour les grands volumes.
- [x] Afficher le nombre de lignes exportees.
- [x] Avertir explicitement l'utilisateur si un export est partiel.
- [x] Verifier que les neuf colonnes officielles restent dans l'ordre demande.

Les lignes sont parcourues par lots de 500 avec un curseur stable
`annee DESC, id ASC`, sur un instantane MySQL coherent. Le CSV est construit en
flux dans un fichier temporaire ; Excel, Word et PDF consomment le meme
iterateur sans tableau intermediaire limite a 5 000 lignes. Pour les tres gros
volumes, CSV reste le format recommande car PDF, PHPWord et PhpSpreadsheet ont
un cout memoire propre au rendu du document.

Le nombre filtre est visible avant l'export, figure dans les documents Excel,
Word et PDF, ainsi que dans le nom de tous les fichiers telecharges. Les
reponses exposent aussi les en-tetes
`X-RISFM-Export-Row-Count`, `X-RISFM-Export-Expected-Count` et
`X-RISFM-Export-Partial`. Si les nombres divergent, le fichier est nomme avec
le prefixe `EXPORT_PARTIEL_` et contient un avertissement visible. Une erreur
de generation avant livraison ne transmet aucun fichier incomplet.

Les neuf colonnes officielles sont centralisees dans
`controllers/ExportController.php` et conservees dans cet ordre : N°, Type de
titre, Annee, Numero du formulaire, Statut, Localisation recherchee,
Responsable, Date de recherche, Resultat. Les cellules Excel sont forcees en
texte et le CSV neutralise les valeurs pouvant etre interpretees comme des
formules.

Tests : `php scripts/test_p6_iterator.php`,
`php scripts/test_p6_large_volume.php`, puis
`php scripts/test_p6_export_format.php csv|xlsx|pdf|docx`. Les quatre fichiers
ont ete generes et controles (CSV UTF-8, archives XLSX/DOCX valides et PDF
lisible). Le parcours exhaustif a egalement ete valide sur une table temporaire
de 5 207 formulaires, sans ecriture dans le registre reel.

### P7 , Migration des anciens identifiants

Statut : **Corrige et applique sur la base locale le 19/07/2026**

- [x] Decider si `admin` reste un identifiant special permanent.
- [x] Migrer les comptes existants vers `OIPI-RISFM-XXXXXX` si cette convention
  doit s'appliquer a tout le monde.
- [x] Remplacer les identifiants de demonstration `service.documentation` et
  `chef.projet`.
- [x] Mettre a jour `schema.sql`, `demo_data.sql` et le guide de deploiement.
- [x] Supprimer les identifiants et mots de passe universels d'une installation
  de production.

Decision : aucun identifiant special n'est conserve. Chaque compte utilise
`OIPI-RISFM-` suivi de son ID sur au moins six chiffres. Le compte `admin` de
la base locale est ainsi devenu `OIPI-RISFM-000001` et le compte historique
`chef.projet`, dont l'ID reel est 4, est devenu `OIPI-RISFM-000004`. Les
nouveaux comptes utilisaient deja cette convention.

La migration `migrations/20260719_p7_migration_identifiants.sql` fonctionne en
deux phases pour eviter les collisions et est idempotente. Elle ne modifie ni
les mots de passe, ni les e-mails, ni les roles, ni les relations metier. Les
correspondances avant/apres sont ajoutees au journal. Les anciennes valeurs de
`activites.acteur_identifiant` et `tentatives_connexion.identifiant` restent
volontairement intactes car elles decrivent l'identifiant utilise au moment de
l'evenement.

Depuis le durcissement P0, une installation neuve ne livre plus aucun compte.
Le premier administrateur est cree interactivement avec
`php scripts/create_admin.php`, sans secret dans le schema ou l'historique du
terminal. Les comptes et formulaires d'exemple sont limites a `demo_data.sql`,
qui est explicitement interdit en production.

Tests : collision croisee, ID a sept chiffres, double execution idempotente et
verification apres application sur la base locale. Script de test isole :
`php scripts/test_p7_migration.php`. Le script `demo_data.sql` a egalement ete
execute deux fois sur des copies temporaires via
`php scripts/test_p7_demo_data.php`, sans doublon ni modification des tables
reelles.

### P8 , Resistance aux erreurs et blocages MySQL

Statut : **Implemente et teste**

- [x] Empêcher la mise a jour de `derniere_activite` de provoquer une erreur
  fatale sur toute la page.
- [x] Gerer les erreurs de verrouillage avec une tentative courte ou un abandon
  silencieux controle.
- [x] Ajouter une gestion globale des exceptions avec une page 500 propre.
- [x] Journaliser l'erreur technique sans afficher les details SQL en production.
- [x] Revoir la frequence d'ecriture de l'activite de session pour ne pas mettre
  a jour la base a chaque requete.

Des erreurs `Lock wait timeout exceeded` ont deja ete observees sur la mise a
jour de `connexions.derniere_activite`.

Realisation : l'activite d'une session est ecrite au maximum une fois toutes
les 60 secondes (valeur configurable). Cette ecriture non critique utilise un
delai de verrou MySQL d'une seconde, restaure ensuite la configuration de la
connexion PDO, puis abandonne proprement en cas de verrou ou de panne. Une
tentative echouee n'est pas repetee sur chaque requete.

Les exceptions non interceptees et les erreurs PHP fatales passent desormais
par un gestionnaire central. La page HTML et la reponse JSON des API utilisent
un numero d'incident ; les details et la trace restent uniquement dans
`storage/logs/php-error.log` lorsque `APP_DEBUG=false`. La connexion PDO ne
fait plus de `die()` avec un message SQL.

Test automatise (il cree puis nettoie une connexion technique ephemere) :
`php scripts/test_p8_resilience.php`. Le test maintient volontairement un
verrou InnoDB, verifie l'abandon en moins de quelques secondes, libere le
verrou, controle la reprise et confirme le masquage des details en production.

### P9 , Securisation des reinitialisations de mot de passe

Statut : **Implemente et teste**

- [x] Stocker uniquement l'empreinte du jeton de reinitialisation en base.
- [x] Invalider les anciens jetons lorsqu'un nouveau jeton est genere.
- [x] Invalider tous les jetons apres une reinitialisation reussie.
- [x] Fermer les sessions existantes apres le changement du mot de passe.
- [x] Purger periodiquement les jetons expires.
- [x] Nettoyer les anciens journaux contenant des URL de reinitialisation.

Realisation : le secret aleatoire de 256 bits n'est transmis qu'a
l'utilisateur. La table ne conserve que son empreinte SHA-256 dans
`token_hash`. La migration P9 convertit les eventuels anciens jetons avant de
supprimer la colonne en clair et peut etre executee plusieurs fois.

Une nouvelle demande invalide tous les liens precedents. La consommation est
effectuee dans une transaction avec verrou de ligne : deux requetes concurrentes
ne peuvent donc pas utiliser le meme lien. Une reinitialisation reussie modifie
le mot de passe, incremente `session_version`, ferme toutes les connexions
actives, invalide tous les liens et efface les echecs de connexion.

Apres le premier clic, le jeton est place dans la session puis retire de l'URL
par redirection vers `/reinitialiser`. Les chemins techniques sont masques dans
le gestionnaire d'erreurs. La migration nettoie le journal metier et
`scripts/purge_reset_security_data.php` purge les jetons expires ainsi que les
anciens secrets presents dans `storage/logs/`.

Verification automatisee sans envoi d'e-mail :
`php scripts/test_p9_password_reset.php`. Le test cree et supprime un compte
technique et controle le hachage, l'invalidation, l'usage unique, la revocation
des sessions et la purge. La migration a egalement ete executee deux fois sur
la base locale pour confirmer son idempotence.

### P10 , Sauvegardes et restaurations completes

Statut : **Implemente et teste**

- [x] Verifier la presence de toutes les tables indispensables.
- [x] Verifier egalement les vues statistiques.
- [x] Verifier la procedure `sp_kpi_globaux`.
- [x] Verifier les triggers necessaires.
- [x] Ne pas qualifier une sauvegarde de complete si les routines sont absentes.
- [x] Ajouter la recreation controlee des vues et procedures apres restauration.
- [x] Creer automatiquement une sauvegarde de securite avant toute restauration.
- [x] Effectuer un test periodique reel de restauration.

Realisation : le manifeste P10 decrit 23 tables, 5 vues statistiques,
`sp_kpi_globaux` et aucun trigger requis. L'ancien
`trg_formulaire_resolu` est explicitement interdit. `mysqldump` et le moteur
PDO de repli ajoutent tous deux les definitions canoniques des objets
programmables ; un fichier qui en omet un n'est plus qualifie de complet.

Le test temporaire importe les donnees, recree les vues, appelle la procedure
KPI et compare ses cinq valeurs aux calculs SQL directs. La restauration
principale effectue les memes controles et cree auparavant une sauvegarde
`risfm_before_restore_*`, elle-meme restauree dans une autre base temporaire.

Script periodique : `php scripts/test_p10_backup_restore.php`. Sur la base
locale du 19/07/2026, les sauvegardes native et PDO ont chacune ete importees
dans une base temporaire, leurs 23 tables, 5 vues et la procedure KPI ont ete
controlees, puis les bases ont ete supprimees. Une sauvegarde sans procedure
est bien refusee. Aucun test ne modifie la base principale.

### P11 , Systeme de migrations SQL

Statut : **Implemente et teste**

- [x] Ajouter une table de version de schema.
- [x] Fournir une migration pour chaque changement de structure.
- [x] Ajouter les migrations manquantes, notamment pour
  `notification_lectures` sur une ancienne installation.
- [x] Creer une commande ou un script d'application des migrations.
- [x] Ne jamais demander de reimporter `schema.sql` sur une base contenant des
  donnees, car ce fichier supprime et recree les tables.
- [x] Corriger les anciens chemins du guide et pointer vers le fichier
  `schema.sql` situe a la racine.

Realisation : `schema_migrations` conserve le nom, le fichier, son checksum
SHA-256, le batch, la duree et la date d'application. La commande unique
`php scripts/migrate.php` prend un verrou MySQL, applique les vingt et une
migrations dans l'ordre defini par `config/migrations.php` et ignore celles
deja enregistrees. `--status` affiche l'etat sans rejouer les scripts. Un
checksum different bloque l'execution afin d'empecher la modification
silencieuse d'une migration historique.

Les deux anciens scripts de procedure KPI ne sont volontairement pas inclus
dans la liste automatique : l'un est obsolete et l'autre peut exiger le droit
`SYSTEM_USER` selon son ancien `DEFINER`. La procedure canonique est geree par
le mecanisme P10 sans imposer ce privilege global.

La migration `20260719_p11_notification_lectures.sql` cree la table manquante
sur une ancienne installation et reprend les notifications individuelles deja
marquees lues. `schema.sql` contient desormais la table de version pour une
installation neuve et son commentaire pointe vers le chemin reel.

Tests : une base temporaire a ete creee sans `schema_migrations` ni
`notification_lectures`. Le premier passage a applique les migrations, le
second n'en a rejoue aucune, la lecture historique a ete reprise et une
alteration de checksum a ete detectee. Les vingt et une migrations ont ete
appliquees et controlees sur la base locale.

### P12 , Tests et recette

Statut : **Implemente ; recette P12 reexecutee avec succes le 29/07/2026**

- [x] Ajouter des tests automatises pour l'authentification et les permissions.
- [x] Tester la creation concurrente des numeros automatiques.
- [x] Tester les doublons et validations metier.
- [x] Tester les affectations, reaffectations, notifications et e-mails.
- [x] Tester les statistiques avec un jeu de donnees connu.
- [x] Tester les quatre formats d'export.
- [x] Tester la desactivation d'un utilisateur deja connecte.
- [x] Tester une restauration complete sur une base temporaire.
- [x] Effectuer une recette responsive sur ordinateur, tablette et telephone.
- [x] Effectuer une recette avec chaque role.

La commande unique est `php scripts/recette.php`. Elle cree une base isolee,
execute P5 a P12, teste deux restaurations completes, puis supprime toutes les
donnees temporaires. P12 utilise de vraies requetes HTTP et Chrome headless aux
largeurs 1366, 820 et 390 pixels. Le circuit e-mail est execute en mode simule
uniquement sous `APP_ENV=testing`, afin de verifier destinataire, contenu et
journalisation sans expedier de messages pendant chaque recette.

### Complement metier , annulation, reaffectation et reouverture

Statut : **Implemente et migre le 29 juillet 2026**

- [x] Annuler manuellement une mission active avec un motif obligatoire.
- [x] Informer le responsable dont la mission est annulee.
- [x] Distinguer une mission parallele d'une vraie reaffectation.
- [x] Lors d'une reaffectation, annuler l'ancienne mission et creer une mission
  de remplacement liee, dans le meme cycle.
- [x] Retirer immediatement a l'ancien responsable le droit de saisir le
  resultat de la mission transferee.
- [x] Autoriser uniquement l'administrateur a rouvrir un dossier Retrouve,
  Numerise ou Saisi.
- [x] Creer un nouveau cycle de suivi sans effacer les recherches et
  finalisations precedentes.
- [x] Journaliser l'annulation, la reaffectation et la reouverture avec leur
  motif et leur acteur.
- [x] Recalculer le resume du registre et les KPI d'apres le cycle et le statut
  actuellement actifs.

Migration : `migrations/20260729_annulation_reaffectation_reouverture.sql`.

### Complement metier , statuts configurables

Statut : **Corrige et migre le 29 juillet 2026**

- [x] Remplacer la configuration trompeuse par sept statuts systeme fixes :
  `introuvable`, `en_recherche`, `a_verifier`, `retrouve`, `numerise`, `saisi`
  et `archive`.
- [x] Autoriser uniquement la personnalisation du libelle et de la couleur.
- [x] Proteger cote serveur le code, l'ordre, l'activation et le caractere
  resolu, meme en cas de requete HTTP falsifiee.
- [x] Interdire la creation de statuts qui ne seraient jamais pris en charge
  par les transitions metier.
- [x] Conserver les anciens statuts personnalises en lecture seule et inactifs
  afin de ne pas alterer l'historique des dossiers.
- [x] Afficher un diagnostic dans le back-office si un statut systeme manque
  ou possede une definition incoherente.

Migration : `migrations/20260729_statuts_workflow_systeme.sql`.

## 4. Ameliorations secondaires

- [x] Limiter la liste des responsables aux roles autorises, pas simplement a
  tous les utilisateurs actifs.
- [x] Permettre de modifier ou desactiver un type de titre ou une localisation.
- [x] Permettre de personnaliser le libelle et la couleur des statuts systeme,
  sans exposer leurs proprietes techniques.
- [ ] Supprimer l'ancien logo lors de son remplacement dans la configuration.
- [x] Envoyer les identifiants temporaires par un canal securise au lieu de les
  laisser uniquement dans un message flash. Aucun mot de passe n'est desormais
  affiche ou envoye : l'utilisateur recoit son identifiant et un lien a usage
  unique de 60 minutes pour choisir lui-meme son mot de passe. Seule l'empreinte
  du jeton est conservee en base.
- [x] Nettoyer le controleur `/recherche`, qui redirige simplement vers le
  registre depuis la fusion des deux ecrans. Le controleur obsolete est supprime
  et l'ancien URL reste une redirection de compatibilite vers `/formulaires`.
- [x] Optimiser les recherches et comptages pour les grands volumes.
  Benchmark automatise sur 20 000 formulaires et 25 000 missions : premiere
  page 454 ms, comptage 69 ms, filtre combine 2,9 ms, statistiques 251 ms et
  export complet 10,45 s avec 2 Mio de memoire supplementaire. Voir
  `docs/PERFORMANCES_GRAND_VOLUME.md`.
- [x] Importer un registre historique Excel ou CSV. L'administrateur dispose
  d'un modele telechargeable, d'un apercu ligne par ligne et d'une validation
  complete avant ecriture. Les referentiels, dates, responsables, doublons,
  formules et limites de taille sont controles. Une seule ligne invalide bloque
  l'ensemble du fichier ; l'import valide est atomique, numerote, historise et
  journalise. Voir `docs/IMPORT_REGISTRE.md`.

## 5. Modules avances explicitement non implementes

Ces fonctions sont indiquees comme non codees dans
`docs/ROADMAP_MODULES_AVANCES.md`. Elles doivent etre confirmees dans le cahier
des charges avant developpement.

- [ ] Codes QR ou codes-barres des boites d'archives.
- [ ] Gestion physique des boites d'archives.
- [ ] Carte ou plan des archives.
- [ ] Affectation automatique des dossiers.
- [x] Dates limites et relances automatiques. Le script quotidien
  `scripts/relances.php` envoie des notifications internes et des e-mails a J-2,
  le jour J, a J+1 puis tous les trois jours. A partir de J+7, les
  administrateurs sont alertes chaque semaine. Depuis le 29 juillet 2026,
  `scripts/reminder_scheduler.php` installe la crontab sans ecraser les autres
  taches. Un heartbeat visible dans Administration > Configuration detecte une
  execution absente depuis plus de 36 heures. Les missions des anciens cycles
  sont exclues apres une reouverture.
- [x] Tableau des dossiers en retard. Le dashboard agent affiche le compteur et
  met en evidence les missions dont l'echeance est depassee.
- [ ] OCR et indexation du contenu numerise.
- [ ] Gestion electronique documentaire avancee.
- [ ] Rapports automatiques quotidiens, mensuels et annuels.

## 6. Prochaines etapes recommandees

1. Obtenir et faire valider le cahier des charges fonctionnel final.
2. Faire valider la signification des KPI et graphiques par la Direction
   Generale.
3. Executer `php scripts/recette.php` sur l'environnement de preproduction.
4. Configurer et tester le SMTP, le cron de relances, les sauvegardes et leur
   supervision avec les comptes d'exploitation definitifs.
5. Corriger le nettoyage de l'ancien logo lors de son remplacement.
6. Prioriser uniquement les modules avances confirmes par le cahier des
   charges.

## 7. Etat technique au 5 aout 2026

- [x] Tous les fichiers PHP (135 fichiers, ~23 800 lignes, hors `vendor`) passent la
  verification de syntaxe.
- [x] Le fichier `composer.json` est valide.
- [x] Les extensions PHP et dependances Composer requises sont installees.
- [x] Le schema contient 23 tables, 5 vues statistiques et 1 procedure KPI.
- [x] 21 migrations ordonnees (23 fichiers) sont enregistrees dans
  `config/migrations.php`.
- [x] Les 80 routes HTTP sont centralisees dans `config/routes.php`.
- [x] Les tentatives de connexion sont limitees par identifiant et par adresse
  IP, avec des seuils configurables et une purge quotidienne des anciennes
  lignes.
- [x] Le controleur principal du registre a ete decoupe par domaine :
  missions, finalisation, pieces jointes et archivage.
- [x] La vue de detail utilise un partial dedie pour ses fenetres modales.
- [x] Les complements metier du 29 juillet (annulation, reaffectation,
  reouverture, finalisation, statuts systeme) sont migres.
- [x] La recette P12 ciblee a ete reexecutee avec succes le 4 aout 2026.
- [x] La recette complete (`php scripts/recette.php`) a ete reexecutee sur une
  base isolee avec succes le 4 aout 2026. Elle reste obligatoire apres chaque
  deploiement significatif et avant toute mise en production.
