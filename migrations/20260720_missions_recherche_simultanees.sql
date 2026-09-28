-- Missions de recherche independantes : plusieurs responsables peuvent chercher
-- simultanement un meme formulaire dans des localisations differentes.

CREATE TABLE IF NOT EXISTS `missions_recherche` (
    `id`                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formulaire_id`            INT UNSIGNED NOT NULL,
    `localisation_id`          INT UNSIGNED NOT NULL,
    `responsable_id`           INT UNSIGNED NOT NULL,
    `affecte_par`              INT UNSIGNED NULL,
    `cloture_par`              INT UNSIGNED NULL,
    `recherche_historique_id`  BIGINT UNSIGNED NULL,
    `etat`                     ENUM('affectee','en_cours','terminee','annulee') NOT NULL DEFAULT 'affectee',
    `resultat_code`            ENUM('retrouve','non_retrouve','a_verifier') NULL,
    `resultat`                 VARCHAR(255) NULL,
    `observations`             TEXT NULL,
    `priorite`                 ENUM('Basse','Normale','Haute','Urgente') NOT NULL DEFAULT 'Normale',
    `date_affectation`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_echeance`            DATE NULL,
    `date_recherche`           DATE NULL,
    `date_cloture`             DATETIME NULL,
    `motif_annulation`         VARCHAR(500) NULL,
    `cree_le`                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `mis_a_jour_le`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `cle_affectation_active` VARCHAR(100)
        GENERATED ALWAYS AS (
            CASE WHEN `etat` IN ('affectee','en_cours')
                 THEN CONCAT(`formulaire_id`, ':', `localisation_id`, ':', `responsable_id`)
                 ELSE NULL END
        ) STORED,
    UNIQUE KEY `uk_mr_affectation_active` (`cle_affectation_active`),
    UNIQUE KEY `uk_mr_historique` (`recherche_historique_id`),
    INDEX `idx_mr_formulaire_etat` (`formulaire_id`, `etat`),
    INDEX `idx_mr_responsable_etat` (`responsable_id`, `etat`, `date_echeance`),
    INDEX `idx_mr_localisation` (`formulaire_id`, `localisation_id`),
    INDEX `idx_mr_resultat` (`resultat_code`, `date_cloture`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'recherches_formulaire' AND column_name = 'mission_id'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `recherches_formulaire` ADD COLUMN `mission_id` BIGINT UNSIGNED NULL AFTER `formulaire_id`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

SET @sql = IF(
    EXISTS(SELECT 1 FROM information_schema.statistics
           WHERE table_schema = DATABASE() AND table_name = 'recherches_formulaire' AND index_name = 'idx_rf_mission'),
    'SET @risfm_noop = 1',
    'ALTER TABLE `recherches_formulaire` ADD INDEX `idx_rf_mission` (`mission_id`)'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

-- Chaque ancienne recherche terminee devient une mission terminee, sans
-- supprimer ni reecrire l'historique metier existant.
INSERT INTO `missions_recherche`
    (`formulaire_id`, `localisation_id`, `responsable_id`, `cloture_par`,
     `recherche_historique_id`, `etat`, `resultat_code`, `resultat`, `observations`,
     `date_affectation`, `date_recherche`, `date_cloture`)
SELECT
    r.formulaire_id,
    r.localisation_id,
    r.responsable_id,
    r.saisi_par,
    r.id,
    'terminee',
    CASE
        WHEN s.code IN ('retrouve', 'saisi') THEN 'retrouve'
        WHEN s.code = 'a_verifier' THEN 'a_verifier'
        ELSE 'non_retrouve'
    END,
    r.resultat,
    r.observations,
    r.cree_le,
    r.date_recherche,
    r.cree_le
FROM `recherches_formulaire` r
LEFT JOIN `statuts` s ON s.id = r.statut_id
WHERE r.localisation_id IS NOT NULL
  AND r.responsable_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `missions_recherche` m WHERE m.recherche_historique_id = r.id
  );

UPDATE `recherches_formulaire` r
JOIN `missions_recherche` m ON m.recherche_historique_id = r.id
SET r.mission_id = m.id
WHERE r.mission_id IS NULL;

-- Reprise des affectations encore actives au moment de la migration.
INSERT INTO `missions_recherche`
    (`formulaire_id`, `localisation_id`, `responsable_id`, `affecte_par`,
     `etat`, `priorite`, `date_echeance`, `date_affectation`)
SELECT
    f.id, f.localisation_id, f.responsable_id, f.cree_par,
    'affectee', f.priorite, f.date_echeance_recherche, f.mis_a_jour_le
FROM `formulaires_manquants` f
JOIN `statuts` s ON s.id = f.statut_id AND s.code = 'en_recherche'
WHERE f.est_archive = 0
  AND f.localisation_id IS NOT NULL
  AND f.responsable_id IS NOT NULL
  AND f.date_recherche IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM `missions_recherche` m
      WHERE m.formulaire_id = f.id
        AND m.localisation_id = f.localisation_id
        AND m.responsable_id = f.responsable_id
        AND m.etat IN ('affectee', 'en_cours')
  );
