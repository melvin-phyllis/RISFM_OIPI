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
GROUP BY u.id, u.nom, u.prenoms;
