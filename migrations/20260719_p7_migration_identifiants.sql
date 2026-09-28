-- P7 : normalisation de tous les identifiants de connexion.
-- Convention finale : OIPI-RISFM-XXXXXX, derivee de utilisateurs.id.
-- Script idempotent : une seconde execution ne modifie ni ne journalise rien.

DROP TEMPORARY TABLE IF EXISTS `p7_identifiants_a_migrer`;
CREATE TEMPORARY TABLE `p7_identifiants_a_migrer` (
    `utilisateur_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `ancien_identifiant` VARCHAR(50) NOT NULL,
    `nouvel_identifiant` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO `p7_identifiants_a_migrer`
    (`utilisateur_id`, `ancien_identifiant`, `nouvel_identifiant`)
SELECT
    u.id,
    u.identifiant,
    CONCAT(
        'OIPI-RISFM-',
        LPAD(u.id, GREATEST(6, CHAR_LENGTH(CAST(u.id AS CHAR))), '0')
    )
FROM utilisateurs u
WHERE u.identifiant <> CONCAT(
    'OIPI-RISFM-',
    LPAD(u.id, GREATEST(6, CHAR_LENGTH(CAST(u.id AS CHAR))), '0')
);

START TRANSACTION;

-- Tous les identifiants a migrer passent d'abord par une valeur temporaire
-- unique. Cette phase evite les collisions, meme si un ancien compte utilise
-- deja par erreur l'identifiant final appartenant a un autre ID.
UPDATE utilisateurs u
JOIN p7_identifiants_a_migrer m ON m.utilisateur_id = u.id
SET u.identifiant = CONCAT(
    'P7TMP-',
    LPAD(u.id, 10, '0'),
    SUBSTRING(REPLACE(UUID(), '-', ''), 1, 32)
);

UPDATE utilisateurs u
JOIN p7_identifiants_a_migrer m ON m.utilisateur_id = u.id
SET u.identifiant = m.nouvel_identifiant;

-- La correspondance historique reste consultable dans le journal metier.
-- acteur_identifiant n'est volontairement pas reecrit dans les anciennes
-- activites : il represente l'identite affichee au moment de chaque action.
INSERT INTO activites
    (utilisateur_id, acteur_id, acteur_identifiant, acteur_nom,
     type_action, description, adresse_ip,
     entite_type, entite_id, donnees_avant, donnees_apres, cree_le)
SELECT
    NULL,
    NULL,
    NULL,
    'Migration P7',
    'migration_identifiant',
    CONCAT('Migration de l identifiant utilisateur #', m.utilisateur_id),
    NULL,
    'utilisateur',
    m.utilisateur_id,
    JSON_OBJECT('identifiant', m.ancien_identifiant),
    JSON_OBJECT('identifiant', m.nouvel_identifiant),
    NOW()
FROM p7_identifiants_a_migrer m;

COMMIT;

DROP TEMPORARY TABLE IF EXISTS `p7_identifiants_a_migrer`;
