<?php
declare(strict_types=1);

/**
 * Helpers de securite transverses : echappement XSS, nettoyage des entrees,
 * validation basique, en-tetes HTTP de durcissement.
 */
class Security
{
    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function cleanString(?string $value): string
    {
        $value = trim((string) $value);
        return strip_tags($value);
    }

    public static function isValidEmail(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Retourne le premier motif de refus du mot de passe, ou null s'il respecte
     * la politique commune a tous les parcours de changement/reinitialisation.
     */
    public static function passwordPolicyError(string $password): ?string
    {
        if (mb_strlen($password) < 10) {
            return 'Le mot de passe doit contenir au moins 10 caracteres.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            return 'Le mot de passe doit contenir au moins une lettre majuscule.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            return 'Le mot de passe doit contenir au moins une lettre minuscule.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            return 'Le mot de passe doit contenir au moins un chiffre.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            return 'Le mot de passe doit contenir au moins un caractere special.';
        }

        return null;
    }

    public static function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline';");
        if (!empty($_SERVER['HTTPS'])) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Genere un nom de fichier sur (sans traversee de repertoire) tout en
     * conservant l'extension d'origine.
     */
    public static function safeFilename(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext);
        return bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    }

    public static function isAllowedUploadExtension(string $ext, array $allowed = ['pdf', 'jpg', 'jpeg', 'png']): bool
    {
        return in_array(strtolower($ext), $allowed, true);
    }

    /**
     * Verifie que le contenu reel correspond bien a l'extension annoncee.
     * Retourne le type MIME valide, ou null si le fichier est trompeur.
     */
    public static function validatedUploadMime(string $tmpPath, string $extension, array $allowedExtensions): ?string
    {
        $extension = strtolower($extension);
        if (!in_array($extension, $allowedExtensions, true) || !is_file($tmpPath)) {
            return null;
        }

        $allowedMimes = [
            'pdf'  => ['application/pdf'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
        ];
        if (!isset($allowedMimes[$extension])) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpPath);
        return is_string($mime) && in_array($mime, $allowedMimes[$extension], true) ? $mime : null;
    }

    public static function ensureDirectory(string $path): bool
    {
        return is_dir($path) || mkdir($path, 0750, true);
    }
}
