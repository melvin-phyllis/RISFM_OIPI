<?php
declare(strict_types=1);

use App\Core\EnvironmentGuard;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$issues = EnvironmentGuard::productionIssues();
if ($issues !== []) {
    fwrite(STDERR, "Configuration de production refusee :\n");
    foreach ($issues as $issue) {
        fwrite(STDERR, '- ' . $issue . "\n");
    }
    exit(1);
}

echo "CONFIGURATION PRODUCTION OK\n";
