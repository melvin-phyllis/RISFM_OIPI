<?php
declare(strict_types=1);

use App\Core\MigrationRunner;
use App\Core\SqlStatementParser;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';
require_once BASE_PATH . '/scripts/test_support.php';

$config = require BASE_PATH . '/config/database.php';
$database = 'oipi_risfm_restore_test_p11_' . bin2hex(random_bytes(5));
$quotedDatabase = '`' . $database . '`';
$serverDsn = sprintf(
    'mysql:host=%s;port=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['charset']
);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$admin = new PDO(
    $serverDsn,
    (string) ($config['maintenance_user'] ?? $config['user']),
    (string) ($config['maintenance_pass'] ?? $config['pass']),
    $options
);
$created = false;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $testDsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $database,
        $config['charset']
    );
    $db = new PDO(
        $testDsn,
        (string) ($config['maintenance_user'] ?? $config['user']),
        (string) ($config['maintenance_pass'] ?? $config['pass']),
        $options
    );

    $schema = file_get_contents(BASE_PATH . '/schema.sql');
    if (!is_string($schema)) {
        throw new RuntimeException('schema.sql illisible.');
    }
    foreach (SqlStatementParser::parse($schema) as $position => $statement) {
        try {
            $db->exec($statement);
        } catch (Throwable $exception) {
            throw new RuntimeException('Import schema.sql instruction #' . ($position + 1) . ' : ' . $exception->getMessage(), 0, $exception);
        }
    }
    risfmSeed($db, demo: true);

    $userId = (int) $db->query('SELECT id FROM utilisateurs ORDER BY id LIMIT 1')->fetchColumn();
    if ($userId <= 0) {
        throw new RuntimeException('Le jeu de demonstration ne contient aucun utilisateur.');
    }
    $notification = $db->prepare(
        "INSERT INTO notifications (utilisateur_id, titre, message, type, lu, cree_le)
         VALUES (:utilisateur_id, 'Test P11', 'Reprise lecture', 'info', 1, NOW())"
    );
    $notification->execute(['utilisateur_id' => $userId]);
    $notificationId = (int) $db->lastInsertId();

    // Simule une installation anterieure qui ne possede ni suivi des versions
    // ni table de lectures individuelles.
    $db->exec('DROP TABLE notification_lectures');
    $db->exec('DROP TABLE schema_migrations');

    $runner = new MigrationRunner($db);
    $first = $runner->migrate();
    $expectedCount = count(require BASE_PATH . '/config/migrations.php');
    $assert(count($first['applied']) === $expectedCount, 'la premiere execution doit appliquer toutes les migrations');
    $assert(count($first['skipped']) === 0, 'aucune migration ne doit etre ignoree au premier passage');

    $recorded = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    $assert($recorded === $expectedCount, 'la table de versions doit contenir toutes les migrations');

    $reading = $db->prepare(
        'SELECT COUNT(*) FROM notification_lectures
         WHERE notification_id = :notification_id AND utilisateur_id = :utilisateur_id'
    );
    $reading->execute(['notification_id' => $notificationId, 'utilisateur_id' => $userId]);
    $assert((int) $reading->fetchColumn() === 1, 'la lecture individuelle historique doit etre reprise');

    $second = $runner->migrate();
    $assert(count($second['applied']) === 0, 'la seconde execution ne doit rien reappliquer');
    $assert(count($second['skipped']) === $expectedCount, 'toutes les migrations doivent etre reconnues au second passage');

    $firstName = (string) (require BASE_PATH . '/config/migrations.php')[0]['name'];
    $corrupt = $db->prepare("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE migration = :migration");
    $corrupt->execute(['migration' => $firstName]);
    $status = $runner->status();
    $assert($status[0]['status'] === 'checksum_mismatch', 'une migration modifiee doit etre detectee');

    $checksum = hash_file('sha256', BASE_PATH . '/migrations/' . (require BASE_PATH . '/config/migrations.php')[0]['file']);
    $restoreChecksum = $db->prepare('UPDATE schema_migrations SET checksum = :checksum WHERE migration = :migration');
    $restoreChecksum->execute(['checksum' => $checksum, 'migration' => $firstName]);
} finally {
    if ($created) {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "P11 OK: premiere execution, reprise notification, idempotence et checksum verifies.\n";
