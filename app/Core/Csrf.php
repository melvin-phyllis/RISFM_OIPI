<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Protection CSRF : jeton unique par session, renouvele apres chaque validation reussie
 * sur les actions sensibles (facultatif) et verifie sur toute requete POST/PUT/DELETE.
 */
class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    public static function verify(?string $submitted): bool
    {
        if (empty($_SESSION[self::SESSION_KEY]) || $submitted === null) {
            return false;
        }
        return hash_equals($_SESSION[self::SESSION_KEY], $submitted);
    }

    public static function verifyRequestOrFail(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
            if (!self::verify($submitted)) {
                http_response_code(419);
                Logger::log(null, 'securite', 'Jeton CSRF invalide ou expire sur ' . ($_SERVER['REQUEST_URI'] ?? ''));
                if (self::wantsJson()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Session expiree ou jeton de securite invalide. Merci de recharger la page.']);
                } else {
                    require BASE_PATH . '/views/errors/419.php';
                }
                exit;
            }
        }
    }

    private static function wantsJson(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}
