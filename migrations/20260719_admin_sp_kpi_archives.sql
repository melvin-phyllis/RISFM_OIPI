-- A executer avec un compte MySQL administrateur (par exemple via sudo mysql).
-- Aligne la procedure SQL optionnelle avec les archives et l'activite reelle.

DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_kpi_globaux` $$

CREATE PROCEDURE `sp_kpi_globaux`()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_session_lifetime_minutes INT DEFAULT 20;

    SELECT COALESCE(MAX(CASE WHEN `cle` = 'session_lifetime_minutes'
                            THEN CAST(`valeur` AS UNSIGNED) END), 20)
      INTO v_session_lifetime_minutes
      FROM `parametres`;

    SET v_session_lifetime_minutes = LEAST(120, GREATEST(5, v_session_lifetime_minutes));

    SELECT
        (SELECT COUNT(*) FROM formulaires_manquants WHERE est_archive = 0) AS total_formulaires,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 1) AS total_retrouves,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 0) AS total_restants,
        (SELECT COUNT(*) FROM utilisateurs WHERE actif = 1) AS total_utilisateurs_actifs,
        (SELECT COUNT(DISTINCT utilisateur_id)
           FROM connexions
          WHERE statut = 'actif'
            AND derniere_activite >= (NOW() - INTERVAL v_session_lifetime_minutes MINUTE)
        ) AS total_connectes;
END $$

DELIMITER ;
