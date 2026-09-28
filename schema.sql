-- =====================================================================
-- OIPI - Registre Intelligent de Suivi des Formulaires Manquants (RISFM)
-- Script de creation de la base de donnees - MySQL 8.0+
--
-- Installation :
--   mysql -u root -p -e "CREATE DATABASE oipi_risfm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
--   mysql -u root -p oipi_risfm < schema.sql
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO';

-- ---------------------------------------------------------------------
-- Table : schema_migrations (historique des evolutions du schema)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE `schema_migrations` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `migration`     VARCHAR(180) NOT NULL UNIQUE,
    `fichier`       VARCHAR(255) NOT NULL,
    `checksum`      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `batch`         INT UNSIGNED NOT NULL,
    `duree_ms`      INT UNSIGNED NOT NULL DEFAULT 0,
    `appliquee_le`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_schema_migrations_batch` (`batch`, `appliquee_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : roles
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(30)  NOT NULL UNIQUE,
    `libelle`     VARCHAR(80)  NOT NULL,
    `description` VARCHAR(255) NULL,
    `cree_le`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : permissions (catalogue informatif, utilise pour l'ecran
-- Parametres > Roles ; l'application applique la matrice definie dans
-- config/roles.php pour la verification effective des droits).
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(60)  NOT NULL UNIQUE,
    `module`      VARCHAR(60)  NOT NULL,
    `libelle`     VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : utilisateurs
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `utilisateurs`;
CREATE TABLE `utilisateurs` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `identifiant`         VARCHAR(50)  NOT NULL UNIQUE,
    `nom`                 VARCHAR(100) NOT NULL,
    `prenoms`             VARCHAR(100) NOT NULL,
    `email`               VARCHAR(150) NOT NULL UNIQUE,
    `telephone`           VARCHAR(30)  NULL,
    `mot_de_passe`        VARCHAR(255) NOT NULL,
    `role`                VARCHAR(30)  NOT NULL COMMENT 'code du role, coherent avec roles.code et config/roles.php',
    `role_id`             INT UNSIGNED NULL,
    `service`             VARCHAR(100) NULL,
    `photo`                VARCHAR(255) NULL,
    `actif`               TINYINT(1)   NOT NULL DEFAULT 1,
    `doit_changer_mdp`    TINYINT(1)   NOT NULL DEFAULT 0,
    `session_version`     INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'incremente pour revoquer les sessions existantes',
    `derniere_connexion`  DATETIME NULL,
    `cree_par`            INT UNSIGNED NULL,
    `cree_le`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `mis_a_jour_le`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_user_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_user_cree_par` FOREIGN KEY (`cree_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    INDEX `idx_users_role` (`role`),
    INDEX `idx_users_actif` (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : tokens_reinitialisation (mot de passe oublie)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tokens_reinitialisation`;
CREATE TABLE `tokens_reinitialisation` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `utilisateur_id` INT UNSIGNED NOT NULL,
    `token_hash`     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE COMMENT 'SHA-256 du jeton transmis par e-mail',
    `expire_le`      DATETIME NOT NULL,
    `utilise`        TINYINT(1) NOT NULL DEFAULT 0,
    `cree_le`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_token_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE,
    INDEX `idx_token_expiration` (`expire_le`, `utilise`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : tentatives_connexion (journalisation de TOUTES les tentatives)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tentatives_connexion`;
CREATE TABLE `tentatives_connexion` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `identifiant` VARCHAR(50) NOT NULL,
    `succes`      TINYINT(1)  NOT NULL,
    `adresse_ip`  VARCHAR(45) NULL,
    `navigateur`  VARCHAR(255) NULL,
    `tentee_le`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tc_identifiant` (`identifiant`),
    INDEX `idx_tc_date` (`tentee_le`),
    INDEX `idx_tc_identifiant_echec_date` (`identifiant`, `succes`, `tentee_le`),
    INDEX `idx_tc_ip_echec_date` (`adresse_ip`, `succes`, `tentee_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : connexions (historique des sessions reussies)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `connexions`;
CREATE TABLE `connexions` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `utilisateur_id`  INT UNSIGNED NOT NULL,
    `adresse_ip`      VARCHAR(45) NULL,
    `navigateur`      VARCHAR(255) NULL,
    `statut`          ENUM('actif','termine') NOT NULL DEFAULT 'actif',
    `connecte_le`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `derniere_activite` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deconnecte_le`   DATETIME NULL,
    `duree_secondes`  INT UNSIGNED NULL,
    CONSTRAINT `fk_conn_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE,
    INDEX `idx_conn_user` (`utilisateur_id`),
    INDEX `idx_conn_date` (`connecte_le`),
    INDEX `idx_conn_activite` (`statut`, `derniere_activite`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : activites (journal d'audit complet)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `activites`;
CREATE TABLE `activites` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `utilisateur_id`  INT UNSIGNED NULL,
    `acteur_id`       BIGINT UNSIGNED NULL COMMENT 'Identifiant permanent de l acteur au moment de l action',
    `acteur_identifiant` VARCHAR(50) NULL COMMENT 'Identifiant de connexion conserve pour audit',
    `acteur_nom`      VARCHAR(205) NULL COMMENT 'Nom complet conserve pour audit',
    `type_action`     VARCHAR(50)  NOT NULL COMMENT 'connexion, deconnexion, ajout, modification, suppression, export, impression, activation, desactivation, securite ...',
    `description`     TEXT NOT NULL,
    `adresse_ip`      VARCHAR(45) NULL,
    `entite_type`     VARCHAR(50) NULL COMMENT 'Type fonctionnel concerne, ex: formulaire',
    `entite_id`       BIGINT UNSIGNED NULL COMMENT 'Identifiant de l''entite concernee',
    `donnees_avant`   JSON NULL COMMENT 'Instantane des valeurs avant modification',
    `donnees_apres`   JSON NULL COMMENT 'Instantane des valeurs apres modification',
    `cree_le`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_act_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    INDEX `idx_act_type` (`type_action`),
    INDEX `idx_act_date` (`cree_le`),
    INDEX `idx_act_user` (`utilisateur_id`),
    INDEX `idx_act_entite` (`entite_type`, `entite_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : types_titres (liste parametrable)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `types_titres`;
CREATE TABLE `types_titres` (
    `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`    VARCHAR(40)  NOT NULL UNIQUE,
    `libelle` VARCHAR(100) NOT NULL,
    `actif`   TINYINT(1)   NOT NULL DEFAULT 1,
    `ordre`   SMALLINT     NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : statuts (workflow fixe ; libelles et couleurs personnalisables)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `statuts`;
CREATE TABLE `statuts` (
    `id`       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`     VARCHAR(40)  NOT NULL UNIQUE,
    `systeme`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 pour les sept etapes techniques du workflow RISFM',
    `libelle`  VARCHAR(100) NOT NULL,
    `couleur`  VARCHAR(20)  NOT NULL DEFAULT 'secondary' COMMENT 'classe couleur Bootstrap (badge)',
    `resolu`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 si ce statut compte comme "formulaire retrouve/traite"',
    `actif`    TINYINT(1)   NOT NULL DEFAULT 1,
    `ordre`    SMALLINT     NOT NULL DEFAULT 0,
    INDEX `idx_statuts_actif` (`actif`, `ordre`),
    INDEX `idx_statuts_systeme` (`systeme`, `ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : localisations (liste parametrable)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `localisations`;
CREATE TABLE `localisations` (
    `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `libelle` VARCHAR(150) NOT NULL UNIQUE,
    `actif`   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : formulaires_manquants (table centrale)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `recherches_formulaire`;
DROP TABLE IF EXISTS `reouvertures_formulaire`;
DROP TABLE IF EXISTS `finalisations_formulaire`;
DROP TABLE IF EXISTS `relances_missions`;
DROP TABLE IF EXISTS `missions_recherche`;
DROP TABLE IF EXISTS `formulaires_manquants`;
CREATE TABLE `formulaires_manquants` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `numero_auto`        VARCHAR(30)  NOT NULL UNIQUE COMMENT 'reference generee automatiquement, ex: FM-2026-000123',
    `type_titre_id`      INT UNSIGNED NOT NULL,
    `annee`              SMALLINT UNSIGNED NOT NULL,
    `numero_formulaire`  VARCHAR(60)  NOT NULL COMMENT 'numero du formulaire tel qu''enregistre a l''origine',
    `date_depot`         DATE NULL,
    `deposant`           VARCHAR(200) NULL,
    `mandataire`         VARCHAR(200) NULL,
    `statut_id`          INT UNSIGNED NOT NULL,
    `cycle_suivi`        INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'incremente a chaque reouverture du dossier',
    `localisation_id`    INT UNSIGNED NULL,
    `responsable_id`     INT UNSIGNED NULL COMMENT 'agent/responsable en charge de la recherche',
    `date_echeance_recherche` DATE NULL COMMENT 'date limite de l''affectation de recherche en cours',
    `date_recherche`     DATE NULL,
    `date_resolution`    DATETIME NULL COMMENT 'date immuable du premier passage vers un statut resolu',
    `resultat`           VARCHAR(255) NULL,
    `observations`       TEXT NULL,
    `niveau_urgence`     ENUM('Faible','Moyen','Eleve','Critique') NOT NULL DEFAULT 'Moyen',
    `priorite`           ENUM('Basse','Normale','Haute','Urgente') NOT NULL DEFAULT 'Normale',
    `est_archive`        TINYINT(1) NOT NULL DEFAULT 0,
    `motif_archivage`    VARCHAR(500) NULL,
    `archive_par`        INT UNSIGNED NULL,
    `archive_le`         DATETIME NULL,
    `cree_par`           INT UNSIGNED NULL,
    `cree_le`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `mis_a_jour_le`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_fm_type`         FOREIGN KEY (`type_titre_id`)   REFERENCES `types_titres`(`id`),
    CONSTRAINT `fk_fm_statut`       FOREIGN KEY (`statut_id`)       REFERENCES `statuts`(`id`),
    CONSTRAINT `fk_fm_localisation` FOREIGN KEY (`localisation_id`) REFERENCES `localisations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_fm_responsable`  FOREIGN KEY (`responsable_id`)  REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_fm_cree_par`     FOREIGN KEY (`cree_par`)        REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_fm_archive_par`  FOREIGN KEY (`archive_par`)     REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_fm_annee_type_numero` (`annee`, `type_titre_id`, `numero_formulaire`),
    INDEX `idx_fm_annee` (`annee`),
    INDEX `idx_fm_type` (`type_titre_id`),
    INDEX `idx_fm_statut` (`statut_id`),
    INDEX `idx_fm_responsable` (`responsable_id`),
    INDEX `idx_fm_echeance_recherche` (`date_echeance_recherche`, `responsable_id`),
    INDEX `idx_fm_date_resolution` (`date_resolution`),
    INDEX `idx_fm_priorite` (`priorite`),
    INDEX `idx_fm_archive` (`est_archive`, `archive_le`),
    INDEX `idx_fm_archive_annee_id` (`est_archive`, `annee`, `id`),
    INDEX `idx_fm_archive_statut_annee` (`est_archive`, `statut_id`, `annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : missions_recherche (affectations pouvant etre simultanees)
-- ---------------------------------------------------------------------
CREATE TABLE `missions_recherche` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_parent_id` BIGINT UNSIGNED NULL COMMENT 'mission annulee remplacee lors d une reaffectation',
    `formulaire_id` INT UNSIGNED NOT NULL,
    `cycle_suivi` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'cycle du formulaire auquel appartient la mission',
    `localisation_id` INT UNSIGNED NOT NULL,
    `responsable_id` INT UNSIGNED NOT NULL,
    `affecte_par` INT UNSIGNED NULL,
    `cloture_par` INT UNSIGNED NULL,
    `recherche_historique_id` BIGINT UNSIGNED NULL,
    `etat` ENUM('affectee','en_cours','terminee','annulee') NOT NULL DEFAULT 'affectee',
    `resultat_code` ENUM('retrouve','non_retrouve','a_verifier') NULL,
    `resultat` VARCHAR(255) NULL,
    `observations` TEXT NULL,
    `priorite` ENUM('Basse','Normale','Haute','Urgente') NOT NULL DEFAULT 'Normale',
    `date_affectation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_echeance` DATE NULL,
    `date_recherche` DATE NULL,
    `date_cloture` DATETIME NULL,
    `motif_annulation` VARCHAR(500) NULL,
    `cree_le` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `mis_a_jour_le` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `cle_affectation_active` VARCHAR(100) GENERATED ALWAYS AS (
        CASE WHEN `etat` IN ('affectee','en_cours')
             THEN CONCAT(`formulaire_id`, ':', `localisation_id`, ':', `responsable_id`)
             ELSE NULL END
    ) STORED,
    UNIQUE KEY `uk_mr_affectation_active` (`cle_affectation_active`),
    UNIQUE KEY `uk_mr_historique` (`recherche_historique_id`),
    CONSTRAINT `fk_mr_responsable` FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_mr_affecte_par` FOREIGN KEY (`affecte_par`) REFERENCES `utilisateurs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_mr_cloture_par` FOREIGN KEY (`cloture_par`) REFERENCES `utilisateurs`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_mr_parent` FOREIGN KEY (`mission_parent_id`) REFERENCES `missions_recherche`(`id`) ON DELETE SET NULL,
    INDEX `idx_mr_formulaire_etat` (`formulaire_id`, `etat`),
    INDEX `idx_mr_formulaire_cycle_etat` (`formulaire_id`, `cycle_suivi`, `etat`),
    INDEX `idx_mr_responsable_etat` (`responsable_id`, `etat`, `date_echeance`),
    INDEX `idx_mr_localisation` (`formulaire_id`, `localisation_id`),
    INDEX `idx_mr_resultat` (`resultat_code`, `date_cloture`),
    INDEX `idx_mr_formulaire_responsable` (`formulaire_id`, `responsable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : recherches_formulaire (historique metier des recherches)
-- ---------------------------------------------------------------------
CREATE TABLE `recherches_formulaire` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formulaire_id`         INT UNSIGNED NOT NULL,
    `mission_id`            BIGINT UNSIGNED NULL,
    `localisation_id`       INT UNSIGNED NULL,
    `localisation_libelle`  VARCHAR(150) NULL,
    `responsable_id`        INT UNSIGNED NULL,
    `responsable_nom`       VARCHAR(205) NULL,
    `statut_id`             INT UNSIGNED NULL,
    `statut_libelle`        VARCHAR(100) NOT NULL,
    `date_recherche`        DATE NOT NULL,
    `resultat`              VARCHAR(255) NULL,
    `observations`          TEXT NULL,
    `saisi_par`             INT UNSIGNED NULL,
    `saisi_par_nom`         VARCHAR(205) NULL,
    `source`                ENUM('creation','mise_a_jour','nouvelle_recherche','reprise') NOT NULL DEFAULT 'nouvelle_recherche',
    `cree_le`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rf_formulaire` FOREIGN KEY (`formulaire_id`) REFERENCES `formulaires_manquants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rf_mission` FOREIGN KEY (`mission_id`) REFERENCES `missions_recherche`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rf_localisation` FOREIGN KEY (`localisation_id`) REFERENCES `localisations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rf_responsable` FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rf_statut` FOREIGN KEY (`statut_id`) REFERENCES `statuts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rf_saisi_par` FOREIGN KEY (`saisi_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    INDEX `idx_rf_formulaire_date` (`formulaire_id`, `date_recherche`, `id`),
    INDEX `idx_rf_mission` (`mission_id`),
    INDEX `idx_rf_formulaire_localisation` (`formulaire_id`, `localisation_id`),
    INDEX `idx_rf_responsable` (`responsable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : finalisations_formulaire (Retrouve -> Numerise -> Saisi)
-- ---------------------------------------------------------------------
CREATE TABLE `finalisations_formulaire` (
    `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formulaire_id`     INT UNSIGNED NOT NULL,
    `cycle_suivi`       INT UNSIGNED NOT NULL DEFAULT 1,
    `etape`             ENUM('retrouve','numerise','saisi') NOT NULL,
    `statut_id`         INT UNSIGNED NULL,
    `effectue_par`      INT UNSIGNED NULL,
    `effectue_par_nom`  VARCHAR(205) NULL COMMENT 'instantane permanent du nom de l acteur',
    `commentaire`       VARCHAR(500) NULL,
    `effectue_le`       DATETIME NOT NULL COMMENT 'date metier declaree pour l etape',
    `cree_le`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'horodatage reel de saisie',
    CONSTRAINT `fk_ff_formulaire` FOREIGN KEY (`formulaire_id`) REFERENCES `formulaires_manquants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ff_statut` FOREIGN KEY (`statut_id`) REFERENCES `statuts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ff_acteur` FOREIGN KEY (`effectue_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_ff_formulaire_cycle_etape` (`formulaire_id`, `cycle_suivi`, `etape`),
    INDEX `idx_ff_etape_date` (`etape`, `effectue_le`),
    INDEX `idx_ff_acteur` (`effectue_par`, `cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : reouvertures_formulaire (nouveau cycle apres correction)
-- ---------------------------------------------------------------------
CREATE TABLE `reouvertures_formulaire` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formulaire_id`         INT UNSIGNED NOT NULL,
    `cycle_avant`           INT UNSIGNED NOT NULL,
    `cycle_apres`           INT UNSIGNED NOT NULL,
    `statut_avant_id`       INT UNSIGNED NULL,
    `statut_avant_libelle`  VARCHAR(100) NOT NULL,
    `motif`                 VARCHAR(500) NOT NULL,
    `reouvert_par`          INT UNSIGNED NULL,
    `reouvert_par_nom`      VARCHAR(205) NULL,
    `reouvert_le`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rof_formulaire` FOREIGN KEY (`formulaire_id`) REFERENCES `formulaires_manquants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rof_statut` FOREIGN KEY (`statut_avant_id`) REFERENCES `statuts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rof_acteur` FOREIGN KEY (`reouvert_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_rof_formulaire_cycle` (`formulaire_id`, `cycle_apres`),
    INDEX `idx_rof_date` (`reouvert_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : pieces_jointes
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `pieces_jointes`;
CREATE TABLE `pieces_jointes` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formulaire_id`  INT UNSIGNED NOT NULL,
    `nom_original`   VARCHAR(255) NOT NULL,
    `nom_fichier`    VARCHAR(255) NOT NULL COMMENT 'nom physique aleatoire sur le disque',
    `type_mime`      VARCHAR(100) NULL,
    `taille_octets`  INT UNSIGNED NULL,
    `televerse_par`  INT UNSIGNED NULL,
    `televerse_le`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_pj_formulaire` FOREIGN KEY (`formulaire_id`) REFERENCES `formulaires_manquants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pj_user` FOREIGN KEY (`televerse_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : notifications
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `notification_lectures`;
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `utilisateur_id` INT UNSIGNED NULL COMMENT 'NULL = notification diffusee a tous',
    `titre`          VARCHAR(150) NOT NULL,
    `message`        VARCHAR(500) NOT NULL,
    `type`           ENUM('info','alerte','rappel','systeme') NOT NULL DEFAULT 'info',
    `lien`           VARCHAR(255) NULL,
    `lu`             TINYINT(1) NOT NULL DEFAULT 0,
    `cree_le`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE,
    INDEX `idx_notif_user_lu` (`utilisateur_id`, `lu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Etat de lecture propre a chaque utilisateur, y compris pour une
-- notification generale (notifications.utilisateur_id IS NULL).
CREATE TABLE `notification_lectures` (
    `notification_id` INT UNSIGNED NOT NULL,
    `utilisateur_id`  INT UNSIGNED NOT NULL,
    `lu_le`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`, `utilisateur_id`),
    CONSTRAINT `fk_nl_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nl_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE,
    INDEX `idx_nl_utilisateur_date` (`utilisateur_id`, `lu_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : relances_missions (historique et anti-doublon du cron)
-- ---------------------------------------------------------------------
CREATE TABLE `relances_missions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_id` BIGINT UNSIGNED NOT NULL,
    `destinataire_id` INT UNSIGNED NOT NULL,
    `notification_id` INT UNSIGNED NULL,
    `type_relance` ENUM('avant_echeance','echeance','retard','escalade') NOT NULL,
    `date_relance` DATE NOT NULL,
    `jours_ecart` SMALLINT NOT NULL COMMENT 'positif avant echeance, negatif apres echeance',
    `email_envoye` TINYINT(1) NOT NULL DEFAULT 0,
    `email_tente_le` DATETIME NULL,
    `email_erreur` VARCHAR(500) NULL,
    `cree_le` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_relance_mission` FOREIGN KEY (`mission_id`) REFERENCES `missions_recherche`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_relance_destinataire` FOREIGN KEY (`destinataire_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_relance_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_relance_mission_dest_type_date` (`mission_id`, `destinataire_id`, `type_relance`, `date_relance`),
    INDEX `idx_relance_email` (`email_envoye`, `date_relance`),
    INDEX `idx_relance_destinataire` (`destinataire_id`, `date_relance`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : parametres (cle / valeur, configuration generale)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `parametres`;
CREATE TABLE `parametres` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `cle`         VARCHAR(80)  NOT NULL UNIQUE,
    `valeur`      TEXT NULL,
    `description` VARCHAR(255) NULL,
    `mis_a_jour_le` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table : sauvegardes
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `sauvegardes`;
CREATE TABLE `sauvegardes` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nom_fichier`   VARCHAR(255) NOT NULL,
    `taille_octets` BIGINT UNSIGNED NULL,
    `cree_par`      INT UNSIGNED NULL,
    `cree_le`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sauve_user` FOREIGN KEY (`cree_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- VUES SQL STATISTIQUES
-- =====================================================================

CREATE OR REPLACE VIEW `vue_stats_annuelle` AS
SELECT
    f.annee,
    COUNT(*) AS total_formulaires,
    SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) AS total_retrouves,
    SUM(CASE WHEN s.resolu = 0 THEN 1 ELSE 0 END) AS total_restants,
    ROUND(SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) AS taux_resolution
FROM formulaires_manquants f
JOIN statuts s ON s.id = f.statut_id
WHERE f.est_archive = 0
GROUP BY f.annee
ORDER BY f.annee;

CREATE OR REPLACE VIEW `vue_stats_type_titre` AS
SELECT
    t.id AS type_titre_id,
    t.libelle AS type_titre,
    COUNT(f.id) AS total_formulaires,
    SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) AS total_retrouves,
    SUM(CASE WHEN s.resolu = 0 THEN 1 ELSE 0 END) AS total_restants,
    ROUND(SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(f.id), 0) * 100, 1) AS taux_resolution
FROM types_titres t
LEFT JOIN formulaires_manquants f ON f.type_titre_id = t.id AND f.est_archive = 0
LEFT JOIN statuts s ON s.id = f.statut_id
GROUP BY t.id, t.libelle;

CREATE OR REPLACE VIEW `vue_stats_statut` AS
SELECT
    s.id AS statut_id,
    s.libelle AS statut,
    s.couleur,
    COUNT(f.id) AS total_formulaires
FROM statuts s
LEFT JOIN formulaires_manquants f ON f.statut_id = s.id AND f.est_archive = 0
GROUP BY s.id, s.libelle, s.couleur;

CREATE OR REPLACE VIEW `vue_stats_responsable` AS
SELECT
    u.id AS utilisateur_id,
    CONCAT(u.nom, ' ', u.prenoms) AS responsable,
    COUNT(DISTINCT f.id) AS total_dossiers,
    COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END) AS total_retrouves
FROM utilisateurs u
LEFT JOIN missions_recherche m ON m.responsable_id = u.id
LEFT JOIN formulaires_manquants f ON f.id = m.formulaire_id AND f.est_archive = 0
LEFT JOIN statuts s ON s.id = f.statut_id
GROUP BY u.id, u.nom, u.prenoms;

CREATE OR REPLACE VIEW `vue_stats_mensuelle` AS
SELECT
    DATE_FORMAT(f.date_resolution, '%Y-%m') AS mois,
    COUNT(*) AS retrouves_dans_le_mois
FROM formulaires_manquants f
WHERE f.est_archive = 0
  AND f.date_resolution IS NOT NULL
GROUP BY DATE_FORMAT(f.date_resolution, '%Y-%m')
ORDER BY mois;

-- =====================================================================
-- PROCEDURE STOCKEE : indicateurs globaux consolides (KPI dashboard)
-- =====================================================================
DELIMITER $$
DROP PROCEDURE IF EXISTS `sp_kpi_globaux` $$
CREATE PROCEDURE `sp_kpi_globaux`()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_session_lifetime_minutes INT DEFAULT 20;

    SELECT COALESCE(MAX(CASE WHEN `cle` = 'session_lifetime_minutes'
                            THEN CAST(`valeur` AS UNSIGNED) END), 20)
      INTO v_session_lifetime_minutes
      FROM `parametres`;

    SET v_session_lifetime_minutes = LEAST(120, GREATEST(5, v_session_lifetime_minutes));

    SELECT
        (SELECT COUNT(*) FROM formulaires_manquants WHERE est_archive = 0) AS total_formulaires,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 1) AS total_retrouves,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 0) AS total_restants,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.code IN ('numerise','saisi')) AS total_numerises,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.code = 'saisi') AS total_saisis,
        (SELECT COUNT(*) FROM utilisateurs WHERE actif = 1) AS total_utilisateurs_actifs,
        (SELECT COUNT(DISTINCT utilisateur_id)
           FROM connexions
          WHERE statut = 'actif'
            AND derniere_activite >= (NOW() - INTERVAL v_session_lifetime_minutes MINUTE)
        ) AS total_connectes;
END $$
DELIMITER ;

-- =====================================================================
-- DONNEES DE REFERENCE (SEED)
-- =====================================================================

INSERT INTO `roles` (`code`, `libelle`, `description`) VALUES
('administrateur', 'Administrateur', 'Acces complet: utilisateurs, statistiques, archivage, sauvegardes, configuration, journal, export, impression'),
('responsable',    'Responsable',    'Peut declarer, modifier les metadonnees, affecter, traiter ses missions et exporter'),
('agent',          'Agent',          'Peut declarer et traiter uniquement les recherches qui lui sont affectees'),
('consultation',   'Consultation',   'Lecture seule');

INSERT INTO `permissions` (`code`, `module`, `libelle`) VALUES
('formulaires.view',        'formulaires',   'Consulter les formulaires manquants'),
('formulaires.create',      'formulaires',   'Ajouter un formulaire manquant'),
('formulaires.update_metadata', 'formulaires', 'Modifier les informations generales'),
('formulaires.assign',      'formulaires',   'Affecter ou reaffecter une recherche'),
('formulaires.record_result_any', 'formulaires', 'Enregistrer tout resultat de recherche'),
('formulaires.record_result_own', 'formulaires', 'Enregistrer le resultat d une recherche affectee'),
('formulaires.finalize',    'formulaires',   'Valider la numerisation et la saisie d un formulaire retrouve'),
('formulaires.reopen',      'formulaires',   'Rouvrir un formulaire resolu apres correction'),
('formulaires.attach_any',  'formulaires',   'Ajouter une piece sur tout dossier'),
('formulaires.attach_own',  'formulaires',   'Ajouter une piece sur un dossier affecte'),
('formulaires.delete_attachment_any', 'formulaires', 'Supprimer toute piece jointe'),
('formulaires.delete_attachment_own', 'formulaires', 'Supprimer sa propre piece sur un dossier affecte'),
('formulaires.archive',     'formulaires',   'Archiver et restaurer un formulaire'),
('formulaires.export',      'formulaires',   'Exporter la liste des formulaires'),
('dashboard.view',          'dashboard',     'Acceder au tableau de bord'),
('statistiques.view',       'statistiques',  'Acceder aux statistiques detaillees'),
('recherche.view',          'recherche',     'Utiliser la recherche avancee'),
('utilisateurs.manage',     'utilisateurs',  'Gerer les utilisateurs'),
('journal.view',            'journal',       'Consulter le journal d''audit'),
('parametres.manage',       'parametres',    'Gerer les parametres de l''application'),
('sauvegardes.manage',      'sauvegardes',   'Creer/restaurer les sauvegardes'),
('notifications.view',      'notifications', 'Consulter ses notifications'),
('profil.update',           'profil',        'Modifier son propre profil');

-- Association large administrateur -> toutes permissions
INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT (SELECT id FROM roles WHERE code = 'administrateur'), id FROM permissions;

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT (SELECT id FROM roles WHERE code = 'responsable'), id FROM permissions
WHERE code IN ('formulaires.view','formulaires.create','formulaires.update_metadata','formulaires.assign',
               'formulaires.record_result_own','formulaires.finalize','formulaires.attach_own','formulaires.delete_attachment_own','formulaires.export',
               'dashboard.view','statistiques.view','recherche.view','journal.view','notifications.view','profil.update');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT (SELECT id FROM roles WHERE code = 'agent'), id FROM permissions
WHERE code IN ('formulaires.view','formulaires.create','formulaires.record_result_own',
               'formulaires.attach_own','formulaires.delete_attachment_own',
               'dashboard.view','recherche.view','notifications.view','profil.update');

INSERT INTO `role_permissions` (`role_id`, `permission_id`)
SELECT (SELECT id FROM roles WHERE code = 'consultation'), id FROM permissions
WHERE code IN ('formulaires.view','dashboard.view','recherche.view','notifications.view','profil.update');

INSERT INTO `types_titres` (`code`, `libelle`, `ordre`) VALUES
('marque',    'Marque', 1),
('nom_commercial', 'Noms commerciaux', 2),
('dmi',       'Dessins et Modeles Industriels', 3),
('brevet',    'Brevet', 4),
('modele_utilite', 'Modeles d''utilite', 5),
('obtention_vegetale', 'Obtentions vegetales', 6),
('autre',     'Autres', 7);

INSERT INTO `statuts` (`code`, `systeme`, `libelle`, `couleur`, `resolu`, `ordre`) VALUES
('introuvable', 1, 'Introuvable',  'danger',  0, 1),
('en_recherche',1, 'En recherche', 'warning', 0, 2),
('a_verifier',  1, 'A verifier',   'info',    0, 3),
('retrouve',    1, 'Retrouve',     'primary', 1, 4),
('numerise',    1, 'Numerise',     'info',    1, 5),
('saisi',       1, 'Saisi',        'success', 1, 6),
('archive',     1, 'Archive',      'secondary', 1, 7);

INSERT INTO `localisations` (`libelle`) VALUES
('Archives centrales'),
('Direction Technique'),
('Direction Juridique'),
('Salle des archives'),
('OAPI'),
('Service Documentation'),
('Bureau regional'),
('Deposant'),
('Cabinet conseil'),
('Autre');

INSERT INTO `parametres` (`cle`, `valeur`, `description`) VALUES
('app_nom', 'OIPI - RISFM', 'Nom affiche de l''application'),
('app_logo', '', 'Chemin du logo OIPI (public/uploads/logos)'),
('couleur_primaire', '#F68B1F', 'Orange principal OIPI'),
('couleur_secondaire', '#00A651', 'Vert principal OIPI'),
('couleur_accent', '#17352B', 'Vert profond utilise pour le texte et les contrastes'),
('session_lifetime_minutes', '20', 'Duree d''inactivite avant deconnexion automatique (minutes)');

-- Aucun compte ni mot de passe n'est livre dans le schema de production.
-- Apres l'import, creer le premier administrateur avec :
--   php scripts/create_admin.php
-- Les comptes et formulaires d'exemple sont reserves a demo_data.sql.

-- =====================================================================
-- Fin du script
-- =====================================================================
