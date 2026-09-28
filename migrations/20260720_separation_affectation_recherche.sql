-- Separation de l'affectation et du resultat d'une recherche.
-- Migration idempotente pour les installations existantes.

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'formulaires_manquants'
          AND column_name = 'date_echeance_recherche'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD COLUMN `date_echeance_recherche` DATE NULL COMMENT ''Date limite de l affectation de recherche en cours'' AFTER `responsable_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'formulaires_manquants'
          AND index_name = 'idx_fm_echeance_recherche'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD INDEX `idx_fm_echeance_recherche` (`date_echeance_recherche`, `responsable_id`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;
