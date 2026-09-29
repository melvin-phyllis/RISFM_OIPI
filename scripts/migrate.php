<?php
declare(strict_types=1);

use App\Core\MigrationRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

try {
    $runner = new MigrationRunner();
    if (in_array('--status', $argv, true)) {
        foreach ($runner->status() as $migration) {
            echo sprintf("%-20s %s\n", strtoupper($migration['status']), $migration['name']);
        }
        exit(0);
    }

    $result = $runner->migrate();
    foreach ($result['applied'] as $migration) {
        echo "APPLIQUEE  {$migration}\n";
    }
    foreach ($result['skipped'] as $migration) {
        echo "DEJA FAITE {$migration}\n";
    }
    echo sprintf(
        "MIGRATIONS OK: %d appliquee(s), %d deja presente(s), batch %d.\n",
        count($result['applied']),
        count($result['skipped']),
        $result['batch']
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'ECHEC MIGRATIONS: ' . $exception->getMessage() . "\n");
    exit(1);
}
