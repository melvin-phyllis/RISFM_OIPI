-- Donnees de demonstration RISFM, applicables sans reinitialiser la base.
-- Le script est idempotent : il peut etre relance sans creer de doublons.
-- NE JAMAIS IMPORTER CE FICHIER EN PRODUCTION.
-- Tous les comptes crees ici utilisent le mot de passe Demo@2026! et imposent
-- son changement a la premiere connexion.

UPDATE `types_titres` SET `libelle` = 'Marque' WHERE `code` = 'marque';
UPDATE `types_titres` SET `libelle` = 'Brevet' WHERE `code` = 'brevet';

SET @type_marque_id = (SELECT id FROM types_titres WHERE code = 'marque' LIMIT 1);
SET @type_brevet_id = (SELECT id FROM types_titres WHERE code = 'brevet' LIMIT 1);
SET @statut_introuvable_id = (SELECT id FROM statuts WHERE code = 'introuvable' LIMIT 1);
SET @statut_retrouve_id = (SELECT id FROM statuts WHERE code = 'retrouve' LIMIT 1);
SET @localisation_archives_id = (
    SELECT id FROM localisations WHERE libelle = 'Archives centrales' LIMIT 1
);
SET @localisation_technique_id = (
    SELECT id FROM localisations WHERE libelle = 'Direction Technique' LIMIT 1
);

-- Une base de demonstration peut partir d'un schema propre sans administrateur.
SET @admin_id = (
    SELECT id FROM utilisateurs
    WHERE role = 'administrateur'
    ORDER BY id ASC LIMIT 1
);

INSERT INTO `utilisateurs`
    (`identifiant`, `nom`, `prenoms`, `email`, `mot_de_passe`, `role`, `role_id`,
     `service`, `actif`, `doit_changer_mdp`)
SELECT
    CONCAT('TMP-DEMO-', REPLACE(UUID(), '-', '')),
    'Administrateur', 'Demonstration', 'demo.admin@oipi.test',
    '$2y$10$XoYnWLyLH8RgiPypwcoh9eUhpaJi/cvc.3DXTYb/.xgvyZvohI3r.',
    'administrateur', (SELECT id FROM roles WHERE code = 'administrateur'),
    'Direction Generale', 1, 1
WHERE @admin_id IS NULL;

UPDATE utilisateurs
SET identifiant = CONCAT(
        'OIPI-RISFM-',
        LPAD(id, GREATEST(6, CHAR_LENGTH(CAST(id AS CHAR))), '0')
    ),
    nom = 'Administrateur', prenoms = 'Demonstration',
    role = 'administrateur',
    role_id = (SELECT id FROM roles WHERE code = 'administrateur'),
    service = 'Direction Generale', actif = 1, doit_changer_mdp = 1
WHERE email = 'demo.admin@oipi.test';

SET @admin_id = (
    SELECT id FROM utilisateurs
    WHERE role = 'administrateur'
    ORDER BY id ASC LIMIT 1
);
SET @documentation_id = (
    SELECT id FROM utilisateurs WHERE email = 'demo.documentation@oipi.test' LIMIT 1
);
SET @chef_projet_id = (
    SELECT id FROM utilisateurs WHERE email = 'demo.chef.projet@oipi.test' LIMIT 1
);
SET @admin_nom = (
    SELECT CONCAT_WS(' ', nom, prenoms) FROM utilisateurs WHERE id = @admin_id LIMIT 1
);

-- Les marqueurs TMP servent uniquement lors de la toute premiere insertion.
-- L'identifiant final est ensuite derive de l'ID reel, comme dans l'application.
INSERT INTO `utilisateurs`
    (`identifiant`, `nom`, `prenoms`, `email`, `mot_de_passe`, `role`, `role_id`, `service`, `actif`, `doit_changer_mdp`, `cree_par`)
SELECT
    CONCAT('TMP-DEMO-', REPLACE(UUID(), '-', '')), 'Service', 'Documentation', 'demo.documentation@oipi.test',
     '$2y$10$XoYnWLyLH8RgiPypwcoh9eUhpaJi/cvc.3DXTYb/.xgvyZvohI3r.',
     'agent', (SELECT id FROM roles WHERE code = 'agent'), 'Service Documentation', 1, 1, @admin_id
WHERE @documentation_id IS NULL;

INSERT INTO `utilisateurs`
    (`identifiant`, `nom`, `prenoms`, `email`, `mot_de_passe`, `role`, `role_id`, `service`, `actif`, `doit_changer_mdp`, `cree_par`)
SELECT
    CONCAT('TMP-DEMO-', REPLACE(UUID(), '-', '')), 'Chef', 'de projet', 'demo.chef.projet@oipi.test',
     '$2y$10$XoYnWLyLH8RgiPypwcoh9eUhpaJi/cvc.3DXTYb/.xgvyZvohI3r.',
     'responsable', (SELECT id FROM roles WHERE code = 'responsable'), 'Direction Technique', 1, 1, @admin_id
WHERE @chef_projet_id IS NULL;

UPDATE utilisateurs
SET identifiant = CONCAT(
        'OIPI-RISFM-',
        LPAD(id, GREATEST(6, CHAR_LENGTH(CAST(id AS CHAR))), '0')
    ),
    nom = 'Service', prenoms = 'Documentation',
    role = 'agent', role_id = (SELECT id FROM roles WHERE code = 'agent'),
    service = 'Service Documentation', actif = 1
WHERE email = 'demo.documentation@oipi.test';

UPDATE utilisateurs
SET identifiant = CONCAT(
        'OIPI-RISFM-',
        LPAD(id, GREATEST(6, CHAR_LENGTH(CAST(id AS CHAR))), '0')
    ),
    nom = 'Chef', prenoms = 'de projet',
    role = 'responsable', role_id = (SELECT id FROM roles WHERE code = 'responsable'),
    service = 'Direction Technique', actif = 1
WHERE email = 'demo.chef.projet@oipi.test';

SET @documentation_id = (
    SELECT id FROM utilisateurs WHERE email = 'demo.documentation@oipi.test' LIMIT 1
);
SET @chef_projet_id = (
    SELECT id FROM utilisateurs WHERE email = 'demo.chef.projet@oipi.test' LIMIT 1
);
SET @documentation_nom = (
    SELECT CONCAT_WS(' ', nom, prenoms) FROM utilisateurs WHERE id = @documentation_id LIMIT 1
);
SET @chef_projet_nom = (
    SELECT CONCAT_WS(' ', nom, prenoms) FROM utilisateurs WHERE id = @chef_projet_id LIMIT 1
);

INSERT INTO `formulaires_manquants`
    (`numero_auto`, `type_titre_id`, `annee`, `numero_formulaire`, `statut_id`,
     `localisation_id`, `responsable_id`, `date_recherche`, `date_resolution`, `resultat`, `cree_par`)
VALUES
    ('FM-2014-000001',
     @type_marque_id, 2014, 'M-2014-005421',
     @statut_introuvable_id,
     @localisation_archives_id,
     @documentation_id,
     '2026-07-15', NULL, 'En cours', @admin_id),
    ('FM-2017-000001',
     @type_brevet_id, 2017, 'B-2017-000154',
     @statut_retrouve_id,
     @localisation_technique_id,
     @chef_projet_id,
     '2026-07-18', '2026-07-18 00:00:00', 'Saisi', @admin_id)
ON DUPLICATE KEY UPDATE
    `statut_id` = VALUES(`statut_id`), `localisation_id` = VALUES(`localisation_id`),
    `responsable_id` = VALUES(`responsable_id`), `date_recherche` = VALUES(`date_recherche`),
    `date_resolution` = COALESCE(`date_resolution`, VALUES(`date_resolution`)),
    `resultat` = VALUES(`resultat`);

SET @historique_marque_existe = (
    SELECT COUNT(*)
    FROM recherches_formulaire h
    JOIN formulaires_manquants f ON f.id = h.formulaire_id
    WHERE f.numero_formulaire = 'M-2014-005421'
);
SET @historique_brevet_existe = (
    SELECT COUNT(*)
    FROM recherches_formulaire h
    JOIN formulaires_manquants f ON f.id = h.formulaire_id
    WHERE f.numero_formulaire = 'B-2017-000154'
);

INSERT INTO `recherches_formulaire`
    (`formulaire_id`, `localisation_id`, `localisation_libelle`,
     `responsable_id`, `responsable_nom`, `statut_id`, `statut_libelle`,
     `date_recherche`, `resultat`, `saisi_par`, `saisi_par_nom`, `source`)
SELECT
    f.id,
    f.localisation_id,
    l.libelle,
    f.responsable_id,
    CASE
        WHEN f.responsable_id = @documentation_id THEN @documentation_nom
        WHEN f.responsable_id = @chef_projet_id THEN @chef_projet_nom
        ELSE NULL
    END,
    f.statut_id,
    s.libelle,
    f.date_recherche,
    f.resultat,
    f.cree_par,
    @admin_nom,
    'reprise'
FROM formulaires_manquants f
JOIN statuts s ON s.id = f.statut_id
LEFT JOIN localisations l ON l.id = f.localisation_id
WHERE (f.numero_formulaire = 'M-2014-005421' AND @historique_marque_existe = 0)
   OR (f.numero_formulaire = 'B-2017-000154' AND @historique_brevet_existe = 0);

INSERT IGNORE INTO `finalisations_formulaire`
    (`formulaire_id`, `etape`, `statut_id`, `effectue_par`, `effectue_par_nom`,
     `commentaire`, `effectue_le`)
SELECT
    f.id,
    'retrouve',
    s.id,
    f.responsable_id,
    CONCAT_WS(' ', u.nom, u.prenoms),
    'Reprise de la situation de demonstration',
    COALESCE(f.date_resolution, f.mis_a_jour_le)
FROM formulaires_manquants f
JOIN statuts s ON s.code = 'retrouve'
LEFT JOIN utilisateurs u ON u.id = f.responsable_id
WHERE f.numero_formulaire = 'B-2017-000154'
  AND f.date_resolution IS NOT NULL;
