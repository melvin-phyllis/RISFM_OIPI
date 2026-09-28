<?php
declare(strict_types=1);

/**
 * Controle d'acces base sur les roles (RBAC) defini dans config/roles.php.
 */
class Permission
{
    private static ?array $roles = null;

    private static function roles(): array
    {
        if (self::$roles === null) {
            self::$roles = require BASE_PATH . '/config/roles.php';
        }
        return self::$roles;
    }

    public static function has(string $roleCode, string $permission): bool
    {
        $roles = self::roles();
        if (!isset($roles[$roleCode])) {
            return false;
        }
        $perms = $roles[$roleCode]['permissions'];
        if (in_array('*', $perms, true)) {
            return true;
        }
        return in_array($permission, $perms, true);
    }

    public static function label(string $roleCode): string
    {
        $roles = self::roles();
        return $roles[$roleCode]['label'] ?? ucfirst($roleCode);
    }

    public static function requireOrFail(string $permission): void
    {
        $role = Auth::role();
        if ($role === null || !self::has($role, $permission)) {
            Logger::log(Auth::id(), 'securite', "Acces refuse a la permission [$permission]");
            http_response_code(403);
            require BASE_PATH . '/views/errors/403.php';
            exit;
        }
    }
}
