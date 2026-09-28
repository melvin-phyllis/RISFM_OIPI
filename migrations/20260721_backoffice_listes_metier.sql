-- Activation/desactivation des statuts depuis le back-office.
SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'statuts'
          AND column_name = 'actif'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `statuts` ADD COLUMN `actif` TINYINT(1) NOT NULL DEFAULT 1 AFTER `resolu`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'statuts'
          AND index_name = 'idx_statuts_actif'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `statuts` ADD INDEX `idx_statuts_actif` (`actif`, `ordre`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;
