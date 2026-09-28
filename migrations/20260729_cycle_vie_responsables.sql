-- Preserve l'identite des responsables et acteurs ayant participe a une
-- mission. Une suppression physique doit echouer ; le compte doit etre
-- desactive apres reaffectation ou cloture de ses missions actives.

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'missions_recherche'
          AND constraint_name = 'fk_mr_responsable'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `missions_recherche`
       ADD CONSTRAINT `fk_mr_responsable`
       FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs`(`id`) ON DELETE RESTRICT'
);
PREPARE risfm_stmt FROM @sql;
EXECUTE risfm_stmt;
DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'missions_recherche'
          AND constraint_name = 'fk_mr_affecte_par'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `missions_recherche`
       ADD CONSTRAINT `fk_mr_affecte_par`
       FOREIGN KEY (`affecte_par`) REFERENCES `utilisateurs`(`id`) ON DELETE RESTRICT'
);
PREPARE risfm_stmt FROM @sql;
EXECUTE risfm_stmt;
DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.referential_constraints
        WHERE constraint_schema = DATABASE()
          AND table_name = 'missions_recherche'
          AND constraint_name = 'fk_mr_cloture_par'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `missions_recherche`
       ADD CONSTRAINT `fk_mr_cloture_par`
       FOREIGN KEY (`cloture_par`) REFERENCES `utilisateurs`(`id`) ON DELETE RESTRICT'
);
PREPARE risfm_stmt FROM @sql;
EXECUTE risfm_stmt;
DEALLOCATE PREPARE risfm_stmt;
