<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

spl_autoload_register(static function (string $class): void {
    foreach (['core', 'models', 'controllers'] as $directory) {
        $file = BASE_PATH . '/' . $directory . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

$issues = EnvironmentGuard::productionIssues();
if ($issues !== []) {
    fwrite(STDERR, "Configuration de production refusee :\n");
    foreach ($issues as $issue) {
        fwrite(STDERR, '- ' . $issue . "\n");
    }
    exit(1);
}

echo "CONFIGURATION PRODUCTION OK\n";
