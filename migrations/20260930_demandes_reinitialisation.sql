-- Limitation des demandes "mot de passe oublie" par adresse e-mail et par IP.
-- Table distincte de tentatives_connexion : ces demandes ne doivent pas
-- consommer le quota d'echecs de connexion d'une adresse IP.
CREATE TABLE IF NOT EXISTS `demandes_reinitialisation` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email_hash`  CHAR(64)    NOT NULL,
    `adresse_ip`  VARCHAR(45) NULL,
    `demandee_le` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_dr_email_date` (`email_hash`, `demandee_le`),
    INDEX `idx_dr_ip_date` (`adresse_ip`, `demandee_le`),
    INDEX `idx_dr_date` (`demandee_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
