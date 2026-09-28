-- Rend explicite la frontiere entre les etapes techniques du workflow et les
-- eventuels statuts libres crees par les anciennes versions du back-office.

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'statuts'
             AND column_name = 'systeme'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `statuts`
       ADD COLUMN `systeme` TINYINT(1) NOT NULL DEFAULT 0 AFTER `code`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

UPDATE statuts
SET systeme = CASE
        WHEN code IN ('introuvable','en_recherche','a_verifier','retrouve','numerise','saisi','archive') THEN 1
        ELSE 0
    END,
    actif = CASE
        WHEN code IN ('introuvable','en_recherche','a_verifier','retrouve','numerise','saisi','archive') THEN 1
        ELSE 0
    END,
    resolu = CASE
        WHEN code IN ('retrouve','numerise','saisi','archive') THEN 1
        WHEN code IN ('introuvable','en_recherche','a_verifier') THEN 0
        ELSE resolu
    END,
    ordre = CASE code
        WHEN 'introuvable' THEN 1
        WHEN 'en_recherche' THEN 2
        WHEN 'a_verifier' THEN 3
        WHEN 'retrouve' THEN 4
        WHEN 'numerise' THEN 5
        WHEN 'saisi' THEN 6
        WHEN 'archive' THEN 7
        ELSE ordre
    END;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'statuts'
             AND index_name = 'idx_statuts_systeme'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `statuts` ADD INDEX `idx_statuts_systeme` (`systeme`,`ordre`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;
