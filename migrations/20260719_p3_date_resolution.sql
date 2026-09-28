-- P3 : date immuable de premiere resolution et statistiques exactes.
-- Migration idempotente : elle peut etre relancee apres une interruption.

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'formulaires_manquants'
          AND column_name = 'date_resolution'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD COLUMN `date_resolution` DATETIME NULL COMMENT ''Date immuable du premier passage vers un statut resolu'' AFTER `date_recherche`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'formulaires_manquants'
          AND index_name = 'idx_fm_date_resolution'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `formulaires_manquants` ADD INDEX `idx_fm_date_resolution` (`date_resolution`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

-- Reconstitue en priorite la premiere resolution connue dans l'historique P1,
-- y compris si le dossier a ensuite ete replace dans un statut non resolu.
UPDATE formulaires_manquants f
JOIN (
    SELECT r.formulaire_id, MIN(TIMESTAMP(r.date_recherche)) AS premiere_resolution
    FROM recherches_formulaire r
    JOIN statuts s ON s.id = r.statut_id AND s.resolu = 1
    GROUP BY r.formulaire_id
) h ON h.formulaire_id = f.id
SET f.date_resolution = h.premiere_resolution
WHERE f.date_resolution IS NULL;

-- Repli pour les anciennes lignes resolues sans historique exploitable.
UPDATE formulaires_manquants f
JOIN statuts s ON s.id = f.statut_id AND s.resolu = 1
SET f.date_resolution = COALESCE(
    TIMESTAMP(f.date_recherche),
    f.mis_a_jour_le,
    f.cree_le,
    NOW()
)
WHERE f.date_resolution IS NULL;

CREATE OR REPLACE VIEW `vue_stats_mensuelle` AS
SELECT
    DATE_FORMAT(f.date_resolution, '%Y-%m') AS mois,
    COUNT(*) AS retrouves_dans_le_mois
FROM formulaires_manquants f
WHERE f.est_archive = 0
  AND f.date_resolution IS NOT NULL
GROUP BY DATE_FORMAT(f.date_resolution, '%Y-%m')
ORDER BY mois;
