-- Annulation manuelle, reaffectation tracable et reouverture sans perte des
-- cycles precedents de recherche/finalisation.

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'formulaires_manquants'
             AND column_name = 'cycle_suivi'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants`
       ADD COLUMN `cycle_suivi` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `statut_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'missions_recherche'
             AND column_name = 'cycle_suivi'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `missions_recherche`
       ADD COLUMN `cycle_suivi` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `formulaire_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'missions_recherche'
             AND index_name = 'idx_mr_formulaire_cycle_etat'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `missions_recherche`
       ADD INDEX `idx_mr_formulaire_cycle_etat` (`formulaire_id`,`cycle_suivi`,`etat`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'missions_recherche'
             AND column_name = 'mission_parent_id'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `missions_recherche`
       ADD COLUMN `mission_parent_id` BIGINT UNSIGNED NULL AFTER `id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.referential_constraints
           WHERE constraint_schema = DATABASE() AND table_name = 'missions_recherche'
             AND constraint_name = 'fk_mr_parent'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `missions_recherche`
       ADD CONSTRAINT `fk_mr_parent`
       FOREIGN KEY (`mission_parent_id`) REFERENCES `missions_recherche`(`id`) ON DELETE SET NULL'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'finalisations_formulaire'
             AND column_name = 'cycle_suivi'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `finalisations_formulaire`
       ADD COLUMN `cycle_suivi` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `formulaire_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'finalisations_formulaire'
             AND index_name = 'uk_ff_formulaire_cycle_etape'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `finalisations_formulaire`
       ADD UNIQUE KEY `uk_ff_formulaire_cycle_etape` (`formulaire_id`,`cycle_suivi`,`etape`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'finalisations_formulaire'
             AND index_name = 'uk_ff_formulaire_etape'),
    'ALTER TABLE `finalisations_formulaire` DROP INDEX `uk_ff_formulaire_etape`',
    'SET @risfm_noop = 1'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

CREATE TABLE IF NOT EXISTS `reouvertures_formulaire` (
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

INSERT INTO `permissions` (`code`, `module`, `libelle`)
VALUES ('formulaires.reopen', 'formulaires', 'Rouvrir un formulaire resolu apres correction')
ON DUPLICATE KEY UPDATE
    `module` = VALUES(`module`),
    `libelle` = VALUES(`libelle`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'formulaires.reopen'
WHERE r.code = 'administrateur';
