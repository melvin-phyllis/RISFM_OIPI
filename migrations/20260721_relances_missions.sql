CREATE TABLE IF NOT EXISTS `relances_missions` (
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
