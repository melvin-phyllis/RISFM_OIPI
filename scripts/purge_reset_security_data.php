<?php
declare(strict_types=1);

use App\Core\LoginRateLimiter;
use App\Repositories\Utilisateur\TokenResetRepository;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$deletedTokens = (new TokenResetRepository())->repo_purgerExpires();
$deletedLoginAttempts = (new LoginRateLimiter())->pruneExpired();
$redactedOccurrences = 0;

foreach (glob(STORAGE_PATH . '/logs/*.log') ?: [] as $logFile) {
    if (!is_file($logFile) || !is_readable($logFile) || !is_writable($logFile)) {
        continue;
    }

    $content = file_get_contents($logFile);
    if (!is_string($content) || $content === '') {
        continue;
    }

    $redacted = preg_replace_callback(
        [
            '#(/reinitialiser/)[a-f0-9]{32,128}#i',
            '#([?&](?:token|jeton)=)[^&\s]+#i',
        ],
        static function (array $matches) use (&$redactedOccurrences): string {
            $redactedOccurrences++;
            return $matches[1] . '[JETON_MASQUE]';
        },
        $content
    );

    if (!is_string($redacted) || $redacted === $content) {
        continue;
    }

    $temporary = $logFile . '.p9-' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($temporary, $redacted, LOCK_EX) === false || !rename($temporary, $logFile)) {
        @unlink($temporary);
        throw new RuntimeException('Impossible de nettoyer le journal ' . basename($logFile));
    }
}

echo sprintf(
    "PURGE SECURITE OK: %d jeton(s) expire(s), %d tentative(s) de connexion ancienne(s) supprimee(s), %d secret(s) masque(s) dans les journaux.\n",
    $deletedTokens,
    $deletedLoginAttempts,
    $redactedOccurrences
);
