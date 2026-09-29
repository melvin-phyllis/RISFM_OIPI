<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Challenge de verification en deux etapes conserve dans la session avant
 * authentification. Le code en clair n'est jamais persiste ni journalise.
 */
final class LoginOtp
{
    private const SESSION_KEY = 'login_otp';
    public const VALIDITY_SECONDS = 600;
    public const MAX_ATTEMPTS = 5;
    public const MAX_SENDS = 3;
    public const RESEND_DELAY_SECONDS = 60;

    public static function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function start(int $userId, string $identifiant, int $sessionVersion, string $code): void
    {
        self::assertCode($code);
        $_SESSION[self::SESSION_KEY] = [
            'user_id' => $userId,
            'identifiant' => $identifiant,
            'session_version' => $sessionVersion,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + self::VALIDITY_SECONDS,
            'attempts' => 0,
            'sends' => 1,
            'sent_at' => time(),
        ];
    }

    public static function pending(): ?array
    {
        $challenge = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($challenge)
            || empty($challenge['user_id'])
            || empty($challenge['identifiant'])
            || !array_key_exists('session_version', $challenge)
            || empty($challenge['code_hash'])
        ) {
            return null;
        }
        return $challenge;
    }

    /** @return array{status:string, challenge?:array, remaining?:int} */
    public static function verify(string $code): array
    {
        $challenge = self::pending();
        if ($challenge === null) {
            return ['status' => 'missing'];
        }
        if (time() > (int) $challenge['expires_at']) {
            self::clear();
            return ['status' => 'expired', 'challenge' => $challenge];
        }

        if (preg_match('/^\d{6}$/', $code)
            && password_verify($code, (string) $challenge['code_hash'])
        ) {
            self::clear();
            return ['status' => 'valid', 'challenge' => $challenge];
        }

        $challenge['attempts'] = (int) ($challenge['attempts'] ?? 0) + 1;
        if ($challenge['attempts'] >= self::MAX_ATTEMPTS) {
            self::clear();
            return ['status' => 'blocked', 'challenge' => $challenge];
        }

        $_SESSION[self::SESSION_KEY] = $challenge;
        return [
            'status' => 'invalid',
            'challenge' => $challenge,
            'remaining' => self::MAX_ATTEMPTS - $challenge['attempts'],
        ];
    }

    /** @return array{allowed:bool, reason?:string, wait?:int} */
    public static function resendStatus(): array
    {
        $challenge = self::pending();
        if ($challenge === null) {
            return ['allowed' => false, 'reason' => 'missing'];
        }
        if (time() > (int) $challenge['expires_at']) {
            self::clear();
            return ['allowed' => false, 'reason' => 'expired'];
        }
        if ((int) ($challenge['sends'] ?? 1) >= self::MAX_SENDS) {
            return ['allowed' => false, 'reason' => 'limit'];
        }

        $wait = self::RESEND_DELAY_SECONDS - (time() - (int) $challenge['sent_at']);
        if ($wait > 0) {
            return ['allowed' => false, 'reason' => 'cooldown', 'wait' => $wait];
        }
        return ['allowed' => true];
    }

    public static function renew(string $code): void
    {
        self::assertCode($code);
        $challenge = self::pending();
        if ($challenge === null) {
            throw new RuntimeException('Aucun challenge de connexion actif.');
        }

        $challenge['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $challenge['expires_at'] = time() + self::VALIDITY_SECONDS;
        $challenge['sent_at'] = time();
        $challenge['sends'] = (int) ($challenge['sends'] ?? 1) + 1;
        $_SESSION[self::SESSION_KEY] = $challenge;
    }

    public static function secondsUntilResend(): int
    {
        $challenge = self::pending();
        if ($challenge === null) {
            return 0;
        }
        return max(0, self::RESEND_DELAY_SECONDS - (time() - (int) $challenge['sent_at']));
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    private static function assertCode(string $code): void
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException('Le code doit contenir exactement six chiffres.');
        }
    }
}
