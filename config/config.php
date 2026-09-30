<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

date_default_timezone_set((string) env('APP_TIMEZONE', 'Africa/Abidjan'));

define('APP_NAME', env('APP_NAME', 'OIPI - RISFM'));
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', (bool) env('APP_DEBUG', false));
define('APP_URL', rtrim((string) env('APP_URL', ''), '/'));
// HTTPS impose (cookie de session Secure + HSTS), meme derriere un proxy ou un
// hebergeur qui ne transmet pas $_SERVER['HTTPS']. Par defaut : APP_URL en https.
define('APP_FORCE_HTTPS', (bool) env('APP_FORCE_HTTPS', str_starts_with(strtolower(APP_URL), 'https://')));

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('UPLOADS_PATH', BASE_PATH . '/public/uploads');
define('PRIVATE_UPLOADS_PATH', STORAGE_PATH . '/uploads');

define('SESSION_LIFETIME_MINUTES', (int) env('SESSION_LIFETIME_MINUTES', 20));
define('SESSION_NAME', env('SESSION_NAME', 'RISFM_SESSION'));
define('SESSION_ACTIVITY_WRITE_INTERVAL_SECONDS', max(15, (int) env('SESSION_ACTIVITY_WRITE_INTERVAL_SECONDS', 60)));
define('SESSION_ACTIVITY_LOCK_WAIT_SECONDS', max(1, min(5, (int) env('SESSION_ACTIVITY_LOCK_WAIT_SECONDS', 1))));
define('LOGIN_RATE_LIMIT_WINDOW_MINUTES', max(1, min(120, (int) env('LOGIN_RATE_LIMIT_WINDOW_MINUTES', 10))));
define('LOGIN_IDENTIFIER_MAX_ATTEMPTS', max(3, min(20, (int) env('LOGIN_IDENTIFIER_MAX_ATTEMPTS', 5))));
define(
    'LOGIN_IP_MAX_ATTEMPTS',
    max(LOGIN_IDENTIFIER_MAX_ATTEMPTS, min(500, (int) env('LOGIN_IP_MAX_ATTEMPTS', 30)))
);
// Mot de passe oublie : demandes acceptees par adresse e-mail et par IP sur la fenetre.
define('PASSWORD_RESET_WINDOW_MINUTES', max(5, min(1440, (int) env('PASSWORD_RESET_WINDOW_MINUTES', 60))));
define('PASSWORD_RESET_EMAIL_MAX_REQUESTS', max(1, min(10, (int) env('PASSWORD_RESET_EMAIL_MAX_REQUESTS', 3))));
define('PASSWORD_RESET_IP_MAX_REQUESTS', max(PASSWORD_RESET_EMAIL_MAX_REQUESTS, min(200, (int) env('PASSWORD_RESET_IP_MAX_REQUESTS', 10))));
define('LOGIN_ATTEMPT_RETENTION_DAYS', max(1, min(365, (int) env('LOGIN_ATTEMPT_RETENTION_DAYS', 30))));
define('UPLOAD_MAX_MB', (int) env('UPLOAD_MAX_MB', 15));
define('BACKUP_MAX_MB', max(10, (int) env('BACKUP_MAX_MB', 250)));

// Affichage des erreurs uniquement en environnement de developpement
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

ini_set('log_errors', '1');
ini_set('error_log', STORAGE_PATH . '/logs/php-error.log');
