-- P11 : etat de lecture des notifications propre a chaque utilisateur.
-- Migration idempotente pour les installations anterieures.

CREATE TABLE IF NOT EXISTS `notification_lectures` (
    `notification_id` INT UNSIGNED NOT NULL,
    `utilisateur_id`  INT UNSIGNED NOT NULL,
    `lu_le`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`, `utilisateur_id`),
    CONSTRAINT `fk_nl_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_nl_utilisateur` FOREIGN KEY (`utilisateur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE,
    INDEX `idx_nl_utilisateur_date` (`utilisateur_id`, `lu_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reprend uniquement les notifications individuelles deja marquees lues.
-- Pour une ancienne notification generale, il est impossible de determiner
-- quel utilisateur l'avait lue : elle reste donc non lue pour chacun.
INSERT IGNORE INTO `notification_lectures` (`notification_id`, `utilisateur_id`, `lu_le`)
SELECT n.id, n.utilisateur_id, COALESCE(n.cree_le, NOW())
FROM notifications n
WHERE n.utilisateur_id IS NOT NULL AND n.lu = 1;
