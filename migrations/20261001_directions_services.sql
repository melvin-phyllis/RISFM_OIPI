-- Normalise l'organigramme : les directions deviennent un referentiel parent
-- et les services leur sont rattaches par une cle etrangere.
CREATE TABLE IF NOT EXISTS `directions` (
    `id`      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`    VARCHAR(40)  NOT NULL UNIQUE COMMENT 'cle interne stable',
    `libelle` VARCHAR(150) NOT NULL UNIQUE,
    `actif`   TINYINT(1)   NOT NULL DEFAULT 1,
    `ordre`   SMALLINT     NOT NULL DEFAULT 0,
    INDEX `idx_directions_actif` (`actif`, `ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `directions` (`code`, `libelle`, `ordre`) VALUES
('DG','Direction générale (DG)',10),
('DBIITT','Direction des brevets d''invention, de l''innovation et du transfert de technologie (DBIITT)',20),
('DMDMIDAQE','Direction des marques, des DMI, des droits d''auteurs et des questions émergentes (DMDMIDAQE)',30),
('DIGMCDS','Direction des indications géographiques, des marques collectives et du développement des services (DIGMCDS)',40),
('DSIDS','Direction du système d''information, de la documentation et des statistiques (DSIDS)',50),
('DCHBC','Direction du capital humain, du budget et de la comptabilité (DCHBC)',60)
ON DUPLICATE KEY UPDATE `code` = VALUES(`code`);

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'services' AND column_name = 'direction_id'
    ),
    'DO 0',
    'ALTER TABLE `services` ADD COLUMN `direction_id` INT UNSIGNED NULL AFTER `abreviation`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'services' AND column_name = 'direction'
    ),
    'UPDATE `services` s JOIN `directions` d ON d.libelle = s.direction SET s.direction_id = d.id WHERE s.direction_id IS NULL',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `services`
SET `direction_id` = (SELECT `id` FROM `directions` WHERE `code` = 'DG' LIMIT 1)
WHERE `direction_id` IS NULL;

ALTER TABLE `services` MODIFY COLUMN `direction_id` INT UNSIGNED NOT NULL;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'services' AND constraint_name = 'fk_service_direction'
    ),
    'DO 0',
    'ALTER TABLE `services` ADD CONSTRAINT `fk_service_direction`
       FOREIGN KEY (`direction_id`) REFERENCES `directions`(`id`) ON DELETE RESTRICT'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'services' AND column_name = 'direction'
    ),
    'ALTER TABLE `services` DROP INDEX `uk_services_direction_libelle`, DROP COLUMN `direction`',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'services' AND index_name = 'uk_services_direction_libelle'
    ),
    'DO 0',
    'ALTER TABLE `services` ADD UNIQUE KEY `uk_services_direction_libelle` (`direction_id`, `libelle`)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
