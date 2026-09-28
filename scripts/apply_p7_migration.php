<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--apply') {
    fwrite(STDERR, "Usage: php scripts/apply_p7_migration.php --apply\n");
    exit(2);
}

require_once dirname(__DIR__) . '/config/config.php';
$config = require BASE_PATH . '/config/database.php';
$migration = file_get_contents(BASE_PATH . '/migrations/20260719_p7_migration_identifiants.sql');
if ($migration === false) {
    fwrite(STDERR, "ECHEC: migration P7 introuvable.\n");
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
    $pdo = new PDO($dsn, $config['user'], $config['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
    $before = $pdo->query('SELECT id, identifiant FROM utilisateurs ORDER BY id')->fetchAll();
    $pdo->exec($migration);
    $after = $pdo->query('SELECT id, identifiant FROM utilisateurs ORDER BY id')->fetchAll();
} catch (Throwable $e) {
    fwrite(STDERR, 'ECHEC: ' . $e->getMessage() . "\n");
    exit(1);
}

$beforeById = [];
foreach ($before as $user) {
    $beforeById[(int) $user['id']] = (string) $user['identifiant'];
}

$changes = 0;
foreach ($after as $user) {
    $id = (int) $user['id'];
    $expected = sprintf('OIPI-RISFM-%06d', $id);
    if ((string) $user['identifiant'] !== $expected) {
        fwrite(STDERR, "ECHEC: identifiant inattendu pour l'utilisateur #{$id}.\n");
        exit(1);
    }
    $old = $beforeById[$id] ?? '';
    if ($old !== $expected) {
        echo "#{$id}: {$old} -> {$expected}\n";
        $changes++;
    }
}

echo $changes > 0
    ? "P7 APPLIQUEE: {$changes} compte(s) migre(s).\n"
    : "P7 DEJA APPLIQUEE: aucun changement necessaire.\n";
