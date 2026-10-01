<?php
declare(strict_types=1);

/**
 * Manifeste canonique P10 du schema RISFM.
 * Les objets programmables sont recrees depuis ces definitions apres chaque
 * restauration afin de ne pas dependre du DEFINER du serveur d'origine.
 */
return [
    'tables' => [
        'schema_migrations',
        'roles',
        'permissions',
        'role_permissions',
        'directions',
        'services',
        'utilisateurs',
        'tokens_reinitialisation',
        'demandes_reinitialisation',
        'tentatives_connexion',
        'connexions',
        'activites',
        'types_titres',
        'statuts',
        'localisations',
        'formulaires_manquants',
        'missions_recherche',
        'recherches_formulaire',
        'finalisations_formulaire',
        'reouvertures_formulaire',
        'pieces_jointes',
        'notifications',
        'notification_lectures',
        'relances_missions',
        'parametres',
        'sauvegardes',
    ],
    'views' => [
        'vue_stats_annuelle' => <<<'SQL'
CREATE OR REPLACE VIEW `vue_stats_annuelle` AS
SELECT
    f.annee,
    COUNT(*) AS total_formulaires,
    SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) AS total_retrouves,
    SUM(CASE WHEN s.resolu = 0 THEN 1 ELSE 0 END) AS total_restants,
    ROUND(SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) AS taux_resolution
FROM formulaires_manquants f
JOIN statuts s ON s.id = f.statut_id
WHERE f.est_archive = 0
GROUP BY f.annee
ORDER BY f.annee
SQL,
        'vue_stats_type_titre' => <<<'SQL'
CREATE OR REPLACE VIEW `vue_stats_type_titre` AS
SELECT
    t.id AS type_titre_id,
    t.libelle AS type_titre,
    COUNT(f.id) AS total_formulaires,
    SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) AS total_retrouves,
    SUM(CASE WHEN s.resolu = 0 THEN 1 ELSE 0 END) AS total_restants,
    ROUND(SUM(CASE WHEN s.resolu = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(f.id), 0) * 100, 1) AS taux_resolution
FROM types_titres t
LEFT JOIN formulaires_manquants f ON f.type_titre_id = t.id AND f.est_archive = 0
LEFT JOIN statuts s ON s.id = f.statut_id
GROUP BY t.id, t.libelle
SQL,
        'vue_stats_statut' => <<<'SQL'
CREATE OR REPLACE VIEW `vue_stats_statut` AS
SELECT
    s.id AS statut_id,
    s.libelle AS statut,
    s.couleur,
    COUNT(f.id) AS total_formulaires
FROM statuts s
LEFT JOIN formulaires_manquants f ON f.statut_id = s.id AND f.est_archive = 0
GROUP BY s.id, s.libelle, s.couleur
SQL,
        'vue_stats_responsable' => <<<'SQL'
CREATE OR REPLACE VIEW `vue_stats_responsable` AS
SELECT
    u.id AS utilisateur_id,
    CONCAT(u.nom, ' ', u.prenoms) AS responsable,
    COUNT(DISTINCT f.id) AS total_dossiers,
    COUNT(DISTINCT CASE WHEN s.resolu = 1 THEN f.id END) AS total_retrouves
FROM utilisateurs u
LEFT JOIN missions_recherche m ON m.responsable_id = u.id
LEFT JOIN formulaires_manquants f ON f.id = m.formulaire_id AND f.est_archive = 0
LEFT JOIN statuts s ON s.id = f.statut_id
GROUP BY u.id, u.nom, u.prenoms
SQL,
        'vue_stats_mensuelle' => <<<'SQL'
CREATE OR REPLACE VIEW `vue_stats_mensuelle` AS
SELECT
    DATE_FORMAT(f.date_resolution, '%Y-%m') AS mois,
    COUNT(*) AS retrouves_dans_le_mois
FROM formulaires_manquants f
WHERE f.est_archive = 0
  AND f.date_resolution IS NOT NULL
GROUP BY DATE_FORMAT(f.date_resolution, '%Y-%m')
ORDER BY mois
SQL,
    ],
    'procedures' => [
        'sp_kpi_globaux' => <<<'PROC'
CREATE PROCEDURE `sp_kpi_globaux`()
SQL SECURITY INVOKER
BEGIN
    DECLARE v_session_lifetime_minutes INT DEFAULT 20;

    SELECT COALESCE(MAX(CASE WHEN `cle` = 'session_lifetime_minutes' THEN CAST(`valeur` AS UNSIGNED) END), 20)
      INTO v_session_lifetime_minutes
      FROM `parametres`;

    SET v_session_lifetime_minutes = LEAST(120, GREATEST(5, v_session_lifetime_minutes));

    SELECT
        (SELECT COUNT(*) FROM formulaires_manquants WHERE est_archive = 0) AS total_formulaires,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 1) AS total_retrouves,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 0) AS total_restants,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.code IN ('numerise','saisi')) AS total_numerises,
        (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.code = 'saisi') AS total_saisis,
        (SELECT COUNT(*) FROM utilisateurs WHERE actif = 1) AS total_utilisateurs_actifs,
        (SELECT COUNT(DISTINCT utilisateur_id)
           FROM connexions
          WHERE statut = 'actif'
            AND derniere_activite >= (NOW() - INTERVAL v_session_lifetime_minutes MINUTE)
        ) AS total_connectes;
END
PROC,
    ],
    // Aucun trigger n'est requis : l'historique metier est ecrit par
    // l'application. Ce trigger historique attribuait un mauvais acteur.
    'triggers' => [],
    'forbidden_triggers' => ['trg_formulaire_resolu'],
];
