<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Refuse le demarrage Web d'une configuration de production manifestement
 * dangereuse. Les erreurs restent generiques cote navigateur et detaillees
 * dans le journal technique par ErrorHandler.
 */
final class EnvironmentGuard
{
    /** @return list<string> */
    public static function productionIssues(): array
    {
        $issues = [];

        if (APP_DEBUG) {
            $issues[] = 'APP_DEBUG doit etre false.';
        }
        if (parse_url(APP_URL, PHP_URL_SCHEME) !== 'https') {
            $issues[] = 'APP_URL doit utiliser HTTPS.';
        }
        if (!(bool) env('ENABLE_LOGIN_OTP', true)) {
            $issues[] = 'ENABLE_LOGIN_OTP doit etre true.';
        }
        if ((bool) env('MAIL_DRY_RUN', false)) {
            $issues[] = 'MAIL_DRY_RUN doit etre false.';
        }

        $dbPassword = trim((string) env('DB_PASS', ''));
        if ($dbPassword === '' || self::isPlaceholder($dbPassword)) {
            $issues[] = 'DB_PASS doit contenir un secret reel.';
        }

        $mailHost = trim((string) env('MAIL_HOST', ''));
        $mailUser = trim((string) env('MAIL_USER', ''));
        $mailPassword = trim((string) env('MAIL_PASS', ''));
        $mailFrom = trim((string) env('MAIL_FROM', ''));
        if (
            $mailHost === ''
            || $mailUser === ''
            || $mailPassword === ''
            || self::isPlaceholder($mailPassword)
            || filter_var($mailFrom, FILTER_VALIDATE_EMAIL) === false
        ) {
            $issues[] = 'La configuration SMTP doit etre complete et valide.';
        }

        return $issues;
    }

    public static function assertSafeForRuntime(): void
    {
        if (APP_ENV !== 'production') {
            return;
        }

        $issues = self::productionIssues();
        if ($issues === []) {
            return;
        }

        error_log('[RISFM] Configuration de production refusee : ' . implode(' ', $issues));
        throw new RuntimeException(
            'La configuration de production RISFM est incomplete ou non securisee.'
        );
    }

    private static function isPlaceholder(string $value): bool
    {
        return in_array(mb_strtolower($value), [
            'change_me',
            'change_me_backup',
            'mot_de_passe_solide',
            'secret_smtp',
            'password',
        ], true);
    }
}
