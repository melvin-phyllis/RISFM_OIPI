-- Cles etrangeres manquantes sur missions_recherche. L'application n'efface
-- jamais un formulaire (archivage) ni une localisation (desactivation) : ces
-- contraintes empechent seulement une suppression manuelle (phpMyAdmin, script)
-- de laisser des missions orphelines.

-- 1. Arret sans rien modifier si des missions pointent deja vers un formulaire
--    ou une localisation disparus. Le message d'erreur indique leur nombre.
SET @orphelins := (
    SELECT COUNT(*)
    FROM missions_recherche m
    LEFT JOIN formulaires_manquants f ON f.id = m.formulaire_id
    LEFT JOIN localisations l ON l.id = m.localisation_id
    WHERE f.id IS NULL OR l.id IS NULL
);
SET @sql := IF(
    @orphelins = 0,
    'DO 0',
    CONCAT('SELECT 1 FROM `ARRET ', @orphelins, ' mission(s) orpheline(s), rien modifie`')
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Un lien vers une ligne d'historique disparue est vide, comme le ferait
--    ON DELETE SET NULL.
UPDATE missions_recherche m
LEFT JOIN recherches_formulaire r ON r.id = m.recherche_historique_id
SET m.recherche_historique_id = NULL
WHERE m.recherche_historique_id IS NOT NULL AND r.id IS NULL;

-- 3. Contraintes, chacune ajoutee une seule fois.
SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'missions_recherche'
          AND constraint_name = 'fk_mr_formulaire'
    ),
    'DO 0',
    'ALTER TABLE `missions_recherche` ADD CONSTRAINT `fk_mr_formulaire`
       FOREIGN KEY (`formulaire_id`) REFERENCES `formulaires_manquants`(`id`) ON DELETE RESTRICT'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'missions_recherche'
          AND constraint_name = 'fk_mr_localisation'
    ),
    'DO 0',
    'ALTER TABLE `missions_recherche` ADD CONSTRAINT `fk_mr_localisation`
       FOREIGN KEY (`localisation_id`) REFERENCES `localisations`(`id`) ON DELETE RESTRICT'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'missions_recherche'
          AND constraint_name = 'fk_mr_historique'
    ),
    'DO 0',
    'ALTER TABLE `missions_recherche` ADD CONSTRAINT `fk_mr_historique`
       FOREIGN KEY (`recherche_historique_id`) REFERENCES `recherches_formulaire`(`id`) ON DELETE SET NULL'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
