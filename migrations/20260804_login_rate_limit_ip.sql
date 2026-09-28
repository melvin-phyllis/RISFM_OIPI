-- Indexation du rate-limiting par identifiant et par adresse IP.
-- Les gardes permettent une execution sur une base issue du schema courant.
SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'tentatives_connexion'
          AND index_name = 'idx_tc_identifiant_echec_date'
    ),
    'SELECT 1',
    'ALTER TABLE tentatives_connexion ADD INDEX idx_tc_identifiant_echec_date (identifiant, succes, tentee_le)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'tentatives_connexion'
          AND index_name = 'idx_tc_ip_echec_date'
    ),
    'SELECT 1',
    'ALTER TABLE tentatives_connexion ADD INDEX idx_tc_ip_echec_date (adresse_ip, succes, tentee_le)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
