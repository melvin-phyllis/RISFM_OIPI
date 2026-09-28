-- Index composites correspondant aux filtres et tris les plus frequents du registre.
-- Les gardes rendent la migration rejouable sur schema.sql et sur une ancienne base.
SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'formulaires_manquants'
          AND index_name = 'idx_fm_archive_annee_id'
    ),
    'SELECT 1',
    'ALTER TABLE formulaires_manquants ADD INDEX idx_fm_archive_annee_id (est_archive, annee, id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'formulaires_manquants'
          AND index_name = 'idx_fm_archive_statut_annee'
    ),
    'SELECT 1',
    'ALTER TABLE formulaires_manquants ADD INDEX idx_fm_archive_statut_annee (est_archive, statut_id, annee)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'missions_recherche'
          AND index_name = 'idx_mr_formulaire_responsable'
    ),
    'SELECT 1',
    'ALTER TABLE missions_recherche ADD INDEX idx_mr_formulaire_responsable (formulaire_id, responsable_id)'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
