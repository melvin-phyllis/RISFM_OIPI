-- P1 : historique complet et immuable des recherches effectuees.

CREATE TABLE IF NOT EXISTS `recherches_formulaire` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `formulaire_id`         INT UNSIGNED NOT NULL,
    `localisation_id`       INT UNSIGNED NULL,
    `localisation_libelle`  VARCHAR(150) NULL,
    `responsable_id`        INT UNSIGNED NULL,
    `responsable_nom`       VARCHAR(205) NULL,
    `statut_id`             INT UNSIGNED NULL,
    `statut_libelle`        VARCHAR(100) NOT NULL,
    `date_recherche`        DATE NOT NULL,
    `resultat`              VARCHAR(255) NULL,
    `observations`          TEXT NULL,
    `saisi_par`             INT UNSIGNED NULL,
    `saisi_par_nom`         VARCHAR(205) NULL,
    `source`                ENUM('creation','mise_a_jour','nouvelle_recherche','reprise') NOT NULL DEFAULT 'nouvelle_recherche',
    `cree_le`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rf_formulaire` FOREIGN KEY (`formulaire_id`) REFERENCES `formulaires_manquants`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rf_localisation` FOREIGN KEY (`localisation_id`) REFERENCES `localisations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rf_responsable` FOREIGN KEY (`responsable_id`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rf_statut` FOREIGN KEY (`statut_id`) REFERENCES `statuts`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rf_saisi_par` FOREIGN KEY (`saisi_par`) REFERENCES `utilisateurs`(`id`) ON DELETE SET NULL,
    INDEX `idx_rf_formulaire_date` (`formulaire_id`, `date_recherche`, `id`),
    INDEX `idx_rf_formulaire_localisation` (`formulaire_id`, `localisation_id`),
    INDEX `idx_rf_responsable` (`responsable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reprise de la situation courante des dossiers deja existants. Une seule
-- entree de reprise est creee par formulaire, meme si la migration est relancee.
INSERT INTO `recherches_formulaire`
    (`formulaire_id`, `localisation_id`, `localisation_libelle`,
     `responsable_id`, `responsable_nom`, `statut_id`, `statut_libelle`,
     `date_recherche`, `resultat`, `observations`, `saisi_par`,
     `saisi_par_nom`, `source`, `cree_le`)
SELECT
    f.id,
    f.localisation_id,
    l.libelle,
    f.responsable_id,
    CONCAT_WS(' ', r.nom, r.prenoms),
    f.statut_id,
    s.libelle,
    COALESCE(f.date_recherche, DATE(f.mis_a_jour_le)),
    NULLIF(f.resultat, ''),
    NULLIF(f.observations, ''),
    f.cree_par,
    CONCAT_WS(' ', a.nom, a.prenoms),
    'reprise',
    f.mis_a_jour_le
FROM formulaires_manquants f
JOIN statuts s ON s.id = f.statut_id
LEFT JOIN localisations l ON l.id = f.localisation_id
LEFT JOIN utilisateurs r ON r.id = f.responsable_id
LEFT JOIN utilisateurs a ON a.id = f.cree_par
WHERE (
       f.date_recherche IS NOT NULL
    OR f.localisation_id IS NOT NULL
    OR f.responsable_id IS NOT NULL
    OR NULLIF(f.resultat, '') IS NOT NULL
    OR NULLIF(f.observations, '') IS NOT NULL
)
AND NOT EXISTS (
    SELECT 1
    FROM recherches_formulaire h
    WHERE h.formulaire_id = f.id
      AND h.source = 'reprise'
);
