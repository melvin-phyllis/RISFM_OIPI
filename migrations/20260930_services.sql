-- Service de l'utilisateur : le texte libre est remplace par une liste de
-- reference (organigramme OIPI). Les valeurs officielles sont aussi
-- inserees par cette migration afin qu une mise a jour reste autonome. Le
-- seeder idempotent permet ensuite de les recreer sur une installation neuve :
--   php scripts/seed.php --only=services
-- Les anciens textes libres ne sont pas repris.
CREATE TABLE IF NOT EXISTS `services` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code`        VARCHAR(40)  NOT NULL UNIQUE COMMENT 'cle interne stable',
    `libelle`     VARCHAR(150) NOT NULL,
    `abreviation` VARCHAR(30)  NULL,
    `direction`   VARCHAR(150) NOT NULL COMMENT 'direction de rattachement (regroupement des listes)',
    `actif`       TINYINT(1)   NOT NULL DEFAULT 1,
    `ordre`       SMALLINT     NOT NULL DEFAULT 0,
    UNIQUE KEY `uk_services_direction_libelle` (`direction`, `libelle`),
    INDEX `idx_services_actif` (`actif`, `ordre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `services` (`code`, `abreviation`, `libelle`, `direction`, `ordre`) VALUES ('DG','DG','Bureau du directeur général','Direction générale (DG)',10), ('AC','AC','Agence comptable','Direction générale (DG)',11), ('CB','CB','Contrôle budgétaire','Direction générale (DG)',12), ('SECR_DG','SECR DG','Secrétariat DG et protocole','Direction générale (DG)',13), ('SCE_COM_VULG','SCE COM VULG','Service communication et vulgarisation de la propriété intellectuelle','Direction générale (DG)',14), ('SCE_CISE','SCE CISE','Service contrôle interne et suivi-évaluation','Direction générale (DG)',15), ('SCE_CMR','SCE CMR','Service coopération et mobilisation des ressources','Direction générale (DG)',16), ('SCE_PATRIMOINE','SCE PATRIMOINE','Service gestion du patrimoine','Direction générale (DG)',17), ('SCE_JURIDIQUE','SCE JURIDIQUE','Service juridique','Direction générale (DG)',18), ('DBIITT','DBIITT','Bureau du directeur','Direction des brevets d''invention, de l''innovation et du transfert de technologie (DBIITT)',20), ('SDSPIACT','SDSPIACT','Sous-direction du soutien à la protection des inventions et autres créations techniques','Direction des brevets d''invention, de l''innovation et du transfert de technologie (DBIITT)',21), ('SDVTSITT','SDVTSITT','Sous-direction de la veille technologique, du soutien à l''innovation et au transfert de technologie','Direction des brevets d''invention, de l''innovation et du transfert de technologie (DBIITT)',22), ('DMDMIDAQE','DMDMIDAQE','Bureau du directeur','Direction des marques, des DMI, des droits d''auteurs et des questions émergentes (DMDMIDAQE)',30), ('SDSPM_DMI','SDSPM DMI','Sous-direction du soutien à la protection des marques et des DMI','Direction des marques, des DMI, des droits d''auteurs et des questions émergentes (DMDMIDAQE)',31), ('SDDAQE','SDDAQE','Sous-direction des droits d''auteurs et des questions émergentes','Direction des marques, des DMI, des droits d''auteurs et des questions émergentes (DMDMIDAQE)',32), ('DIGMCDS','DIGMCDS','Bureau du directeur','Direction des indications géographiques, des marques collectives et du développement des services (DIGMCDS)',40), ('SDIGMC','SDIGMC','Sous-direction des indications géographiques et des marques collectives','Direction des indications géographiques, des marques collectives et du développement des services (DIGMCDS)',41), ('SDDS','SDDS','Sous-direction du développement des services','Direction des indications géographiques, des marques collectives et du développement des services (DIGMCDS)',42), ('DSIDS','DSIDS','Bureau du directeur','Direction du système d''information, de la documentation et des statistiques (DSIDS)',50), ('SDSID','SDSID','Sous-direction du système d''information et de la documentation','Direction du système d''information, de la documentation et des statistiques (DSIDS)',51), ('SDES','SDES','Sous-direction des études et des statistiques','Direction du système d''information, de la documentation et des statistiques (DSIDS)',52), ('DCHBC','DCHBC','Bureau du directeur','Direction du capital humain, du budget et de la comptabilité (DCHBC)',60), ('SDCH','SDCH','Sous-direction du capital humain','Direction du capital humain, du budget et de la comptabilité (DCHBC)',61), ('SDBC','SDBC','Sous-direction du budget et de la comptabilité','Direction du capital humain, du budget et de la comptabilité (DCHBC)',62) ON DUPLICATE KEY UPDATE `code` = VALUES(`code`);

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'utilisateurs' AND column_name = 'service_id'
    ),
    'DO 0',
    'ALTER TABLE `utilisateurs` ADD COLUMN `service_id` INT UNSIGNED NULL AFTER `role_id`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE() AND table_name = 'utilisateurs' AND constraint_name = 'fk_user_service'
    ),
    'DO 0',
    'ALTER TABLE `utilisateurs` ADD CONSTRAINT `fk_user_service`
       FOREIGN KEY (`service_id`) REFERENCES `services`(`id`) ON DELETE RESTRICT'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'utilisateurs' AND column_name = 'service'
    ),
    'ALTER TABLE `utilisateurs` DROP COLUMN `service`',
    'DO 0'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE `utilisateurs` SET `service_id` = (SELECT `id` FROM `services` WHERE `code` = 'DG' LIMIT 1)
WHERE `service_id` IS NULL;
ALTER TABLE `utilisateurs` MODIFY COLUMN `service_id` INT UNSIGNED NOT NULL;
