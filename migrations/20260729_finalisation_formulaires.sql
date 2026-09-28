-- Workflow de fin de campagne : un formulaire retrouve doit ensuite etre
-- numerise, puis saisi. Chaque validation conserve sa date et son acteur.

INSERT INTO `statuts` (`code`, `libelle`, `couleur`, `resolu`, `actif`, `ordre`)
VALUES ('numerise', 'Numerise', 'info', 1, 1, 5)
ON DUPLICATE KEY UPDATE
    `libelle` = VALUES(`libelle`),
    `resolu` = 1,
    `actif` = 1;

UPDATE `statuts` SET `ordre` = 4 WHERE `code` = 'retrouve';
UPDATE `statuts` SET `ordre` = 5 WHERE `code` = 'numerise';
UPDATE `statuts` SET `ordre` = 6 WHERE `code` = 'saisi';
UPDATE `statuts` SET `ordre` = 7 WHERE `code` = 'archive';

CREATE TABLE IF NOT EXISTS `finalisations_formulaire` (
    `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formulaire_id`     INT UNSIGNED NOT NULL,
    `etape`             ENUM('retrouve','numerise','saisi') NOT NULL,
    `statut_id`         INT UNSIGNED NULL,
    `effectue_par`      INT UNSIGNED NULL,
    `effectue_par_nom`  VARCHAR(205) NULL COMMENT 'instantane permanent du nom de l acteur',
    `commentaire`       VARCHAR(500) NULL,
    `effectue_le`       DATETIME NOT NULL COMMENT 'date metier declaree pour l etape',
    `cree_le`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'horodatage reel de saisie',
    CONSTRAINT `fk_ff_formulaire` FOREIGN KEY (`formulaire_id`) REFERENCES `formulaires_manquants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ff_statut` FOREIGN KEY (`statut_id`) REFERENCES `statuts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ff_acteur` FOREIGN KEY (`effectue_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_ff_formulaire_etape` (`formulaire_id`, `etape`),
    INDEX `idx_ff_etape_date` (`etape`, `effectue_le`),
    INDEX `idx_ff_acteur` (`effectue_par`, `cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`code`, `module`, `libelle`)
VALUES ('formulaires.finalize', 'formulaires', 'Valider la numerisation et la saisie d un formulaire retrouve')
ON DUPLICATE KEY UPDATE
    `module` = VALUES(`module`),
    `libelle` = VALUES(`libelle`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.code = 'formulaires.finalize'
WHERE r.code IN ('administrateur', 'responsable');

-- Reprise transparente des dossiers deja resolus. Pour un ancien dossier
-- "Saisi", les trois jalons sont reconstruits a la date historique disponible.
INSERT IGNORE INTO `finalisations_formulaire`
    (`formulaire_id`, `etape`, `statut_id`, `effectue_par`, `effectue_par_nom`,
     `commentaire`, `effectue_le`)
SELECT
    f.id, 'retrouve', sr.id, f.responsable_id,
    CONCAT_WS(' ', u.nom, u.prenoms),
    'Etape reconstruite lors de la migration du workflow de finalisation',
    COALESCE(f.date_resolution, f.mis_a_jour_le, f.cree_le)
FROM formulaires_manquants f
JOIN statuts sc ON sc.id = f.statut_id AND sc.code IN ('retrouve','numerise','saisi')
JOIN statuts sr ON sr.code = 'retrouve'
LEFT JOIN utilisateurs u ON u.id = f.responsable_id;

INSERT IGNORE INTO `finalisations_formulaire`
    (`formulaire_id`, `etape`, `statut_id`, `effectue_par`, `effectue_par_nom`,
     `commentaire`, `effectue_le`)
SELECT
    f.id, 'numerise', sn.id, f.responsable_id,
    CONCAT_WS(' ', u.nom, u.prenoms),
    'Etape reconstruite lors de la migration du workflow de finalisation',
    COALESCE(f.date_resolution, f.mis_a_jour_le, f.cree_le)
FROM formulaires_manquants f
JOIN statuts sc ON sc.id = f.statut_id AND sc.code IN ('numerise','saisi')
JOIN statuts sn ON sn.code = 'numerise'
LEFT JOIN utilisateurs u ON u.id = f.responsable_id;

INSERT IGNORE INTO `finalisations_formulaire`
    (`formulaire_id`, `etape`, `statut_id`, `effectue_par`, `effectue_par_nom`,
     `commentaire`, `effectue_le`)
SELECT
    f.id, 'saisi', ss.id, f.responsable_id,
    CONCAT_WS(' ', u.nom, u.prenoms),
    'Etape reconstruite lors de la migration du workflow de finalisation',
    COALESCE(f.date_resolution, f.mis_a_jour_le, f.cree_le)
FROM formulaires_manquants f
JOIN statuts sc ON sc.id = f.statut_id AND sc.code = 'saisi'
JOIN statuts ss ON ss.code = 'saisi'
LEFT JOIN utilisateurs u ON u.id = f.responsable_id;
