-- Aligne le KPI des utilisateurs connectes sur la duree d'inactivite
-- configuree dans l'application et evite de compter plusieurs sessions du
-- meme utilisateur.
DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_kpi_globaux` $$

CREATE PROCEDURE `sp_kpi_globaux`()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_session_lifetime_minutes INT DEFAULT 20;

    SELECT CAST(`valeur` AS UNSIGNED)
      INTO v_session_lifetime_minutes
      FROM `parametres`
     WHERE `cle` = 'session_lifetime_minutes'
     LIMIT 1;

    SET v_session_lifetime_minutes = LEAST(120, GREATEST(5, v_session_lifetime_minutes));

    SELECT
        (SELECT COUNT(*) FROM formulaires_manquants) AS total_formulaires,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE s.resolu = 1) AS total_retrouves,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE s.resolu = 0) AS total_restants,
        (SELECT COUNT(*) FROM utilisateurs WHERE actif = 1) AS total_utilisateurs_actifs,
        (SELECT COUNT(DISTINCT utilisateur_id)
           FROM connexions
          WHERE statut = 'actif'
            AND derniere_activite >= (NOW() - INTERVAL v_session_lifetime_minutes MINUTE)
        ) AS total_connectes;
END $$

DELIMITER ;
