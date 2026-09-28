-- P9 : ne conserver que l'empreinte SHA-256 des jetons de reinitialisation.
-- La migration transforme les eventuels jetons existants sans invalider leur
-- lien, puis supprime definitivement la colonne contenant le secret en clair.

SET @p9_schema := DATABASE();

SET @p9_has_hash := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @p9_schema
      AND table_name = 'tokens_reinitialisation'
      AND column_name = 'token_hash'
);
SET @p9_sql := IF(
    @p9_has_hash = 0,
    'ALTER TABLE `tokens_reinitialisation` ADD COLUMN `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT ''SHA-256 du jeton transmis par e-mail'' AFTER `utilisateur_id`',
    'SELECT 1'
);
PREPARE p9_stmt FROM @p9_sql;
EXECUTE p9_stmt;
DEALLOCATE PREPARE p9_stmt;

SET @p9_has_plain := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @p9_schema
      AND table_name = 'tokens_reinitialisation'
      AND column_name = 'token'
);
SET @p9_sql := IF(
    @p9_has_plain = 1,
    'UPDATE `tokens_reinitialisation` SET `token_hash` = LOWER(SHA2(`token`, 256)) WHERE `token_hash` IS NULL AND `token` IS NOT NULL',
    'SELECT 1'
);
PREPARE p9_stmt FROM @p9_sql;
EXECUTE p9_stmt;
DEALLOCATE PREPARE p9_stmt;

-- Une ligne impossible a convertir ne doit jamais rester utilisable.
DELETE FROM `tokens_reinitialisation`
WHERE `token_hash` IS NULL OR `token_hash` NOT REGEXP '^[a-f0-9]{64}$';

ALTER TABLE `tokens_reinitialisation`
    MODIFY COLUMN `token_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
    COMMENT 'SHA-256 du jeton transmis par e-mail';

SET @p9_has_plain := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @p9_schema
      AND table_name = 'tokens_reinitialisation'
      AND column_name = 'token'
);
SET @p9_sql := IF(
    @p9_has_plain = 1,
    'ALTER TABLE `tokens_reinitialisation` DROP COLUMN `token`',
    'SELECT 1'
);
PREPARE p9_stmt FROM @p9_sql;
EXECUTE p9_stmt;
DEALLOCATE PREPARE p9_stmt;

SET @p9_has_unique_hash := (
    SELECT COUNT(DISTINCT index_name)
    FROM information_schema.statistics
    WHERE table_schema = @p9_schema
      AND table_name = 'tokens_reinitialisation'
      AND column_name = 'token_hash'
      AND non_unique = 0
);
SET @p9_sql := IF(
    @p9_has_unique_hash = 0,
    'ALTER TABLE `tokens_reinitialisation` ADD UNIQUE KEY `uk_token_reset_hash` (`token_hash`)',
    'SELECT 1'
);
PREPARE p9_stmt FROM @p9_sql;
EXECUTE p9_stmt;
DEALLOCATE PREPARE p9_stmt;

SET @p9_has_expiration_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @p9_schema
      AND table_name = 'tokens_reinitialisation'
      AND index_name = 'idx_token_expiration'
);
SET @p9_sql := IF(
    @p9_has_expiration_index = 0,
    'ALTER TABLE `tokens_reinitialisation` ADD INDEX `idx_token_expiration` (`expire_le`, `utilise`)',
    'SELECT 1'
);
PREPARE p9_stmt FROM @p9_sql;
EXECUTE p9_stmt;
DEALLOCATE PREPARE p9_stmt;

-- Les versions anterieures ne devaient pas journaliser les liens, mais cette
-- reprise nettoie defensivement les descriptions historiques concernees.
UPDATE `activites`
SET `description` = 'Trace historique nettoyee : lien de reinitialisation masque'
WHERE LOWER(`description`) REGEXP 'reinitialiser/[a-f0-9]{32,128}'
   OR LOWER(`description`) REGEXP '(token|jeton)[ =:]+[a-f0-9]{32,128}';

-- Purge initiale des jetons deja expires.
DELETE FROM `tokens_reinitialisation` WHERE `expire_le` < NOW();

SET @p9_schema := NULL;
SET @p9_has_hash := NULL;
SET @p9_has_plain := NULL;
SET @p9_has_unique_hash := NULL;
SET @p9_has_expiration_index := NULL;
SET @p9_sql := NULL;
