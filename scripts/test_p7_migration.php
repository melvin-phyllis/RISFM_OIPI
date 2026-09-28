<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

$testPath = BASE_PATH . '/scripts/test_p7_migration.sql';
$migrationPath = BASE_PATH . '/migrations/20260719_p7_migration_identifiants.sql';
$testSql = file_get_contents($testPath);
$migrationSql = file_get_contents($migrationPath);
if ($testSql === false || $migrationSql === false) {
    fwrite(STDERR, "ECHEC: scripts SQL P7 introuvables.\n");
    exit(1);
}

$sql = str_replace(
    'SOURCE migrations/20260719_p7_migration_identifiants.sql;',
    $migrationSql,
    $testSql
);

$config = require BASE_PATH . '/config/database.php';
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['dbname'],
    $config['charset']
);

try {
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $pdo->exec($sql);
    $result = (string) $pdo->query('SELECT resultat FROM p7_test_result LIMIT 1')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, 'ECHEC: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result !== 'P7 MIGRATION TEMPORAIRE OK') {
    fwrite(STDERR, "ECHEC: {$result}\n");
    exit(1);
}

echo $result . " , collisions, id a sept chiffres et idempotence verifies sans modifier les comptes reels.\n";
