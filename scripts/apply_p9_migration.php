<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php scripts/apply_p9_migration.php --apply\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config/config.php';
$config = require BASE_PATH . '/config/database.php';
$migration = file_get_contents(BASE_PATH . '/migrations/20260719_p9_securisation_tokens_reset.sql');
if ($migration === false) {
    fwrite(STDERR, "ECHEC: migration P9 introuvable.\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['dbname'],
    $config['charset']
);

try {
    $pdo = new PDO($dsn, (string) $config['user'], (string) $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $pdo->exec($migration);

    $columns = $pdo->query(
        "SELECT column_name FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'tokens_reinitialisation'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $hashIndex = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = 'tokens_reinitialisation'
           AND column_name = 'token_hash'
           AND non_unique = 0"
    )->fetchColumn();
} catch (Throwable $exception) {
    fwrite(STDERR, 'ECHEC: ' . $exception->getMessage() . "\n");
    exit(1);
}

if (!in_array('token_hash', $columns, true)
    || in_array('token', $columns, true)
    || $hashIndex < 1
) {
    fwrite(STDERR, "ECHEC: structure P9 incomplete apres migration.\n");
    exit(1);
}

echo "P9 MIGRATION OK: secrets en clair supprimes, empreinte unique active.\n";
