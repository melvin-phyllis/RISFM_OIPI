-- Test isole P7 : toutes les modifications restent dans des tables temporaires.
-- A executer depuis la racine du projet avec le client mysql.

CREATE TEMPORARY TABLE `p7_users_source` LIKE `utilisateurs`;
INSERT INTO `p7_users_source` SELECT * FROM `utilisateurs`;
CREATE TEMPORARY TABLE `p7_activities_structure` LIKE `activites`;

CREATE TEMPORARY TABLE `utilisateurs` LIKE `p7_users_source`;
INSERT INTO `utilisateurs` SELECT * FROM `p7_users_source`;
CREATE TEMPORARY TABLE `activites` LIKE `p7_activities_structure`;

-- Ajoute un ID a sept chiffres afin de verifier qu'il n'est jamais tronque.
INSERT INTO utilisateurs
    (id, identifiant, nom, prenoms, email, mot_de_passe, role, role_id,
     service_id, actif, doit_changer_mdp, session_version)
SELECT
    1000001,
    'ancien.identifiant.large',
    'Test',
    'Grand identifiant',
    CONCAT('p7-large-', REPLACE(UUID(), '-', ''), '@test.invalid'),
    mot_de_passe,
    role,
    role_id,
    service_id,
    1,
    1,
    1
FROM p7_users_source
ORDER BY id
LIMIT 1;

-- Cree une collision croisee entre les deux premiers comptes lorsque cela est
-- possible. La migration en deux phases doit la resoudre sans erreur 1062.
SET @p7_id_1 = (SELECT id FROM utilisateurs ORDER BY id LIMIT 1);
SET @p7_id_2 = (SELECT id FROM utilisateurs WHERE id <> @p7_id_1 ORDER BY id LIMIT 1);
SET @p7_swap_tmp = CONCAT('P7-SWAP-', REPLACE(UUID(), '-', ''));
UPDATE utilisateurs SET identifiant = @p7_swap_tmp WHERE id = @p7_id_1 AND @p7_id_2 IS NOT NULL;
UPDATE utilisateurs
SET identifiant = CONCAT('OIPI-RISFM-', LPAD(@p7_id_1, GREATEST(6, CHAR_LENGTH(CAST(@p7_id_1 AS CHAR))), '0'))
WHERE id = @p7_id_2 AND @p7_id_2 IS NOT NULL;
UPDATE utilisateurs
SET identifiant = CONCAT('OIPI-RISFM-', LPAD(@p7_id_2, GREATEST(6, CHAR_LENGTH(CAST(@p7_id_2 AS CHAR))), '0'))
WHERE id = @p7_id_1 AND @p7_id_2 IS NOT NULL;

SOURCE migrations/20260719_p7_migration_identifiants.sql;

SET @p7_logs_after_first_run = (SELECT COUNT(*) FROM activites WHERE type_action = 'migration_identifiant');

-- Deuxieme passage : doit etre strictement idempotent.
SOURCE migrations/20260719_p7_migration_identifiants.sql;

SET @p7_invalid_identifiers = (
    SELECT COUNT(*) FROM utilisateurs
    WHERE identifiant <> CONCAT(
        'OIPI-RISFM-',
        LPAD(id, GREATEST(6, CHAR_LENGTH(CAST(id AS CHAR))), '0')
    )
);
SET @p7_large_identifier = (
    SELECT identifiant FROM utilisateurs WHERE id = 1000001 LIMIT 1
);
SET @p7_logs_after_second_run = (
    SELECT COUNT(*) FROM activites WHERE type_action = 'migration_identifiant'
);

CREATE TEMPORARY TABLE `p7_test_result` (`resultat` VARCHAR(100) NOT NULL);
INSERT INTO `p7_test_result` (`resultat`)
SELECT IF(
    @p7_invalid_identifiers = 0
    AND @p7_large_identifier = 'OIPI-RISFM-1000001'
    AND @p7_logs_after_second_run = @p7_logs_after_first_run,
    'P7 MIGRATION TEMPORAIRE OK',
    'ECHEC P7 MIGRATION TEMPORAIRE'
) AS resultat;

DROP TEMPORARY TABLE IF EXISTS `utilisateurs`;
DROP TEMPORARY TABLE IF EXISTS `activites`;
DROP TEMPORARY TABLE IF EXISTS `p7_users_source`;
DROP TEMPORARY TABLE IF EXISTS `p7_activities_structure`;
