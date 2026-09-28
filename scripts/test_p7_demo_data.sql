-- Test isole de demo_data.sql : les tables reelles sont uniquement copiees.

CREATE TEMPORARY TABLE `p7_demo_users_source` LIKE `utilisateurs`;
INSERT INTO `p7_demo_users_source` SELECT * FROM `utilisateurs`;
CREATE TEMPORARY TABLE `p7_demo_types_source` LIKE `types_titres`;
INSERT INTO `p7_demo_types_source` SELECT * FROM `types_titres`;
CREATE TEMPORARY TABLE `p7_demo_forms_source` LIKE `formulaires_manquants`;
INSERT INTO `p7_demo_forms_source` SELECT * FROM `formulaires_manquants`;
CREATE TEMPORARY TABLE `p7_demo_research_source` LIKE `recherches_formulaire`;
INSERT INTO `p7_demo_research_source` SELECT * FROM `recherches_formulaire`;
CREATE TEMPORARY TABLE `p7_demo_finalization_source` LIKE `finalisations_formulaire`;
INSERT INTO `p7_demo_finalization_source` SELECT * FROM `finalisations_formulaire`;

CREATE TEMPORARY TABLE `utilisateurs` LIKE `p7_demo_users_source`;
INSERT INTO `utilisateurs` SELECT * FROM `p7_demo_users_source`;
CREATE TEMPORARY TABLE `types_titres` LIKE `p7_demo_types_source`;
INSERT INTO `types_titres` SELECT * FROM `p7_demo_types_source`;
CREATE TEMPORARY TABLE `formulaires_manquants` LIKE `p7_demo_forms_source`;
INSERT INTO `formulaires_manquants` SELECT * FROM `p7_demo_forms_source`;
CREATE TEMPORARY TABLE `recherches_formulaire` LIKE `p7_demo_research_source`;
INSERT INTO `recherches_formulaire` SELECT * FROM `p7_demo_research_source`;
CREATE TEMPORARY TABLE `finalisations_formulaire` LIKE `p7_demo_finalization_source`;
INSERT INTO `finalisations_formulaire` SELECT * FROM `p7_demo_finalization_source`;

SOURCE_DEMO_DATA;
SOURCE_DEMO_DATA;

SET @p7_demo_user_count = (
    SELECT COUNT(*) FROM utilisateurs
    WHERE email IN ('demo.documentation@oipi.test', 'demo.chef.projet@oipi.test')
);
SET @p7_demo_invalid_ids = (
    SELECT COUNT(*) FROM utilisateurs
    WHERE email IN ('demo.documentation@oipi.test', 'demo.chef.projet@oipi.test')
      AND identifiant <> CONCAT(
          'OIPI-RISFM-',
          LPAD(id, GREATEST(6, CHAR_LENGTH(CAST(id AS CHAR))), '0')
      )
);
SET @p7_demo_form_count = (
    SELECT COUNT(*) FROM formulaires_manquants
    WHERE numero_formulaire IN ('M-2014-005421', 'B-2017-000154')
);
SET @p7_demo_history_forms = (
    SELECT COUNT(DISTINCT formulaire_id)
    FROM recherches_formulaire
    WHERE formulaire_id IN (
        SELECT id FROM formulaires_manquants
        WHERE numero_formulaire IN ('M-2014-005421', 'B-2017-000154')
    )
);

CREATE TEMPORARY TABLE `p7_demo_test_result` (`resultat` VARCHAR(100) NOT NULL);
INSERT INTO `p7_demo_test_result` (`resultat`)
SELECT IF(
    @p7_demo_user_count = 2
    AND @p7_demo_invalid_ids = 0
    AND @p7_demo_form_count = 2
    AND @p7_demo_history_forms = 2,
    'P7 DEMO DATA TEMPORAIRE OK',
    'ECHEC P7 DEMO DATA TEMPORAIRE'
);

DROP TEMPORARY TABLE IF EXISTS `utilisateurs`;
DROP TEMPORARY TABLE IF EXISTS `types_titres`;
DROP TEMPORARY TABLE IF EXISTS `formulaires_manquants`;
DROP TEMPORARY TABLE IF EXISTS `recherches_formulaire`;
DROP TEMPORARY TABLE IF EXISTS `finalisations_formulaire`;
DROP TEMPORARY TABLE IF EXISTS `p7_demo_users_source`;
DROP TEMPORARY TABLE IF EXISTS `p7_demo_types_source`;
DROP TEMPORARY TABLE IF EXISTS `p7_demo_forms_source`;
DROP TEMPORARY TABLE IF EXISTS `p7_demo_research_source`;
DROP TEMPORARY TABLE IF EXISTS `p7_demo_finalization_source`;
