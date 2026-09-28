-- P2 : journal metier detaille et archivage logique des formulaires.

-- Chaque ALTER est conditionnel : la migration peut etre relancee sans erreur
-- apres une interruption (les DDL MySQL sont valides immediatement).
SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activites' AND column_name = 'acteur_id'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD COLUMN `acteur_id` BIGINT UNSIGNED NULL COMMENT ''Identifiant permanent de l acteur'' AFTER `utilisateur_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activites' AND column_name = 'acteur_identifiant'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD COLUMN `acteur_identifiant` VARCHAR(50) NULL COMMENT ''Identifiant de connexion conserve pour audit'' AFTER `acteur_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activites' AND column_name = 'acteur_nom'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD COLUMN `acteur_nom` VARCHAR(205) NULL COMMENT ''Nom complet conserve pour audit'' AFTER `acteur_identifiant`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activites' AND column_name = 'entite_type'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD COLUMN `entite_type` VARCHAR(50) NULL COMMENT ''Type fonctionnel concerne'' AFTER `adresse_ip`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activites' AND column_name = 'entite_id'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD COLUMN `entite_id` BIGINT UNSIGNED NULL COMMENT ''Identifiant de l entite concernee'' AFTER `entite_type`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activites' AND column_name = 'donnees_avant'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD COLUMN `donnees_avant` JSON NULL COMMENT ''Valeurs avant modification'' AFTER `entite_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'activites' AND column_name = 'donnees_apres'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD COLUMN `donnees_apres` JSON NULL COMMENT ''Valeurs apres modification'' AFTER `donnees_avant`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'activites' AND index_name = 'idx_act_entite'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `activites` ADD INDEX `idx_act_entite` (`entite_type`, `entite_id`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

UPDATE `activites` a
LEFT JOIN `utilisateurs` u ON u.id = a.utilisateur_id
SET a.acteur_id = a.utilisateur_id,
    a.acteur_identifiant = u.identifiant,
    a.acteur_nom = NULLIF(CONCAT_WS(' ', u.nom, u.prenoms), '')
WHERE a.utilisateur_id IS NOT NULL;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'formulaires_manquants' AND column_name = 'est_archive'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD COLUMN `est_archive` TINYINT(1) NOT NULL DEFAULT 0 AFTER `priorite`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'formulaires_manquants' AND column_name = 'motif_archivage'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD COLUMN `motif_archivage` VARCHAR(500) NULL AFTER `est_archive`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'formulaires_manquants' AND column_name = 'archive_par'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD COLUMN `archive_par` INT UNSIGNED NULL AFTER `motif_archivage`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'formulaires_manquants' AND column_name = 'archive_le'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD COLUMN `archive_le` DATETIME NULL AFTER `archive_par`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'formulaires_manquants' AND constraint_name = 'fk_fm_archive_par'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD CONSTRAINT `fk_fm_archive_par` FOREIGN KEY (`archive_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'formulaires_manquants' AND index_name = 'idx_fm_archive'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD INDEX `idx_fm_archive` (`est_archive`, `archive_le`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

INSERT INTO `permissions` (`code`, `module`, `libelle`)
VALUES ('formulaires.archive', 'formulaires', 'Archiver et restaurer un formulaire')
ON DUPLICATE KEY UPDATE `libelle` = VALUES(`libelle`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'formulaires.archive'
WHERE r.code = 'administrateur';

DELETE rp
FROM role_permissions rp
JOIN permissions p ON p.id = rp.permission_id
WHERE p.code = 'formulaires.delete';

DELETE FROM permissions WHERE code = 'formulaires.delete';

-- Le trigger historique attribuait a tort le changement au responsable du
-- dossier. L'application connait l'acteur authentifie et journalise desormais
-- elle-meme toutes les valeurs avant/apres.
DROP TRIGGER IF EXISTS `trg_formulaire_resolu`;

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
    COUNT(f.id) AS total_dossiers,
    SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) AS total_retrouves
FROM utilisateurs u
LEFT JOIN formulaires_manquants f ON f.responsable_id = u.id AND f.est_archive = 0
LEFT JOIN statuts s ON s.id = f.statut_id
GROUP BY u.id, responsable;

-- Si P3 est deja present, une relance de P2 conserve sa definition exacte de
-- la progression mensuelle. Avant P3, la vue historique reste compatible.
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'formulaires_manquants'
          AND column_name = 'date_resolution'
    ),
    'CREATE OR REPLACE VIEW `vue_stats_mensuelle` AS
       SELECT DATE_FORMAT(f.date_resolution, ''%Y-%m'') AS mois,
              COUNT(*) AS retrouves_dans_le_mois
       FROM formulaires_manquants f
       WHERE f.est_archive = 0 AND f.date_resolution IS NOT NULL
       GROUP BY DATE_FORMAT(f.date_resolution, ''%Y-%m'')
       ORDER BY mois',
    'CREATE OR REPLACE VIEW `vue_stats_mensuelle` AS
       SELECT DATE_FORMAT(f.mis_a_jour_le, ''%Y-%m'') AS mois,
              SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) AS retrouves_dans_le_mois
       FROM formulaires_manquants f
       JOIN statuts s ON s.id = f.statut_id
       WHERE f.est_archive = 0
       GROUP BY DATE_FORMAT(f.mis_a_jour_le, ''%Y-%m'')
       ORDER BY mois'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

-- La procedure historique peut appartenir a root et MySQL 8.0 refuse alors
-- qu'un utilisateur applicatif la remplace (erreur SYSTEM_USER). Le tableau de
-- bord calcule maintenant ces KPI directement. Pour aligner aussi la procedure
-- SQL optionnelle, executer en administrateur :
-- migrations/20260719_admin_sp_kpi_archives.sql
