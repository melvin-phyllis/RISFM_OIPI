<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

$testSql = file_get_contents(BASE_PATH . '/scripts/test_p7_demo_data.sql');
$demoSql = file_get_contents(BASE_PATH . '/demo_data.sql');
if ($testSql === false || $demoSql === false) {
    fwrite(STDERR, "ECHEC: scripts SQL de demonstration introuvables.\n");
    exit(1);
}

$sql = str_replace('SOURCE_DEMO_DATA;', $demoSql, $testSql);
$config = require BASE_PATH . '/config/database.php';
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'], $config['port'], $config['dbname'], $config['charset']
);

try {
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $pdo->exec($sql);
    $result = (string) $pdo->query('SELECT resultat FROM p7_demo_test_result LIMIT 1')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, 'ECHEC: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result !== 'P7 DEMO DATA TEMPORAIRE OK') {
    fwrite(STDERR, "ECHEC: {$result}\n");
    exit(1);
}

echo $result . " , double execution validee sans modifier les tables reelles.\n";
