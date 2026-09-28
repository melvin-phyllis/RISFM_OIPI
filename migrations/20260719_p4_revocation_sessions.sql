-- P4 : revocation immediate des acces et revalidation a chaque requete.
-- Migration idempotente.

SET @sql = IF(
    EXISTS(
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'utilisateurs'
          AND column_name = 'session_version'
    ),
    'SET @risfm_noop = 1',
    'ALTER TABLE `utilisateurs` ADD COLUMN `session_version` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT ''Incremente pour revoquer les sessions existantes'' AFTER `doit_changer_mdp`'
);
PREPARE risfm_stmt FROM @sql; EXECUTE risfm_stmt; DEALLOCATE PREPARE risfm_stmt;

UPDATE utilisateurs SET session_version = 1 WHERE session_version < 1;

-- Nettoie les connexions qui etaient encore marquees actives pour un compte
-- deja desactive avant l'installation du P4.
UPDATE connexions c
JOIN utilisateurs u ON u.id = c.utilisateur_id AND u.actif = 0
SET c.statut = 'termine',
    c.deconnecte_le = COALESCE(c.deconnecte_le, NOW()),
    c.derniere_activite = NOW(),
    c.duree_secondes = COALESCE(c.duree_secondes, TIMESTAMPDIFF(SECOND, c.connecte_le, NOW()))
WHERE c.statut = 'actif';
