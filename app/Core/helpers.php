<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Security;
use App\Repositories\Administration\ParametreRepository;
use App\Repositories\Utilisateur\UserRepository;

/**
 * Fonctions utilitaires globales disponibles dans les vues et controleurs.
 */

/**
 * Determine le sous-dossier physique dans lequel l'application est montee
 * (ex: "/RISFM/public" si le DocumentRoot Apache pointe sur htdocs/ et que
 * l'appli est accedee via http://localhost/RISFM/public/...), a partir de
 * SCRIPT_NAME. Retourne "" si l'application est montee a la racine du
 * domaine ou derriere un VirtualHost dedie (DocumentRoot = public/).
 */
function basePath(): string
{
    static $base = null;
    if ($base === null) {
        // Avec le serveur integre (`php -S ... -t public router.php`), le
        // DocumentRoot est deja `public/`. Pour certaines URL ressemblant a
        // des fichiers (par exemple une sauvegarde `.sql`), PHP renseigne
        // SCRIPT_NAME avec le chemin demande au lieu de `/index.php`. Le
        // prendre pour un sous-dossier casserait alors la route dynamique.
        if (PHP_SAPI === 'cli-server') {
            $base = '';
            return $base;
        }

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $dir = str_replace('\\', '/', dirname($scriptName));
        $dir = ($dir === '.' || $dir === '/') ? '' : rtrim($dir, '/');

        // Le .htaccess racine reecrit en interne "/RISFM/xxx" vers "public/xxx"
        // sans que l'URL vue par le navigateur (REQUEST_URI) ne contienne "/public".
        // SCRIPT_NAME, lui, pointe vers le fichier reellement execute (.../public/index.php).
        // On retire donc ce segment final pour que le prefixe corresponde a l'URL reelle.
        if ($dir === 'public') {
            $dir = '';
        } elseif (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }

        $base = $dir;
    }
    return $base;
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');

    // APP_URL explicite dans .env : prioritaire (recommande en production).
    $configured = rtrim(APP_URL, '/');
    if ($configured !== '') {
        return $path !== '' ? "{$configured}/{$path}" : "{$configured}/";
    }

    // A defaut, deduction automatique du sous-dossier de montage (pratique
    // sans configuration prealable sur un poste local XAMPP/WAMP).
    $base = basePath();
    return $path !== '' ? "{$base}/{$path}" : "{$base}/";
}

function asset(string $path): string
{
    $path = ltrim($path, '/');
    // La date de modification dans l'URL force le navigateur a recharger un
    // fichier modifie, sans Ctrl+F5 ni vidage du cache.
    $file = BASE_PATH . '/public/assets/' . rawurldecode($path);
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('assets/' . $path) . $version;
}

function e(?string $value): string
{
    return Security::e($value);
}

function old(string $key, string $default = ''): string
{
    $value = $_SESSION['_old'][$key] ?? $default;
    return e((string) $value);
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash_' . $type] = $message;
}

function getFlash(string $type): ?string
{
    if (empty($_SESSION['flash_' . $type])) {
        return null;
    }
    $msg = $_SESSION['flash_' . $type];
    unset($_SESSION['flash_' . $type]);
    return $msg;
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function formatDate(?string $datetime, string $format = 'd/m/Y H:i'): string
{
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return '-';
    }
    try {
        return (new DateTime($datetime))->format($format);
    } catch (Exception) {
        return '-';
    }
}

/** Classe visuelle commune pour rendre la priorite immediatement identifiable. */
function priorityLevelClass(?string $priority): string
{
    return match (strtolower(trim((string) $priority))) {
        'urgente' => 'priority-urgent',
        'haute' => 'priority-high',
        'basse' => 'priority-low',
        default => 'priority-normal',
    };
}

function priorityBadgeClass(?string $priority): string
{
    $badgeClass = match (strtolower(trim((string) $priority))) {
        'urgente' => 'badge-danger',
        'haute' => 'badge-warning',
        'basse' => 'badge-light',
        default => 'badge-secondary',
    };

    return $badgeClass . ' ' . priorityLevelClass($priority);
}

function priorityIconClass(?string $priority): string
{
    return strtolower(trim((string) $priority)) === 'urgente'
        ? 'fas fa-exclamation-triangle'
        : 'fas fa-flag';
}

function pct(int|float $part, int|float $total): float
{
    if ($total <= 0) {
        return 0.0;
    }
    return round(($part / $total) * 100, 1);
}

function currentUser(): ?array
{
    static $user = null;
    static $loaded = false;
    if (!$loaded) {
        $loaded = true;
        if (Auth::check()) {
            $model = new UserRepository();
            $user = $model->repo_find((int) Auth::id());
        }
    }
    return $user;
}

/** Parametres applicatifs en base, charges une seule fois par requete. */
function appSettings(): array
{
    static $settings = null;
    if ($settings === null) {
        try {
            $settings = (new ParametreRepository())->repo_tous();
        } catch (Throwable $e) {
            error_log('[Parametres] Lecture impossible : ' . $e->getMessage());
            $settings = [];
        }
    }
    return $settings;
}

function appSetting(string $key, ?string $default = null): ?string
{
    $settings = appSettings();
    $value = $settings[$key] ?? $default;
    return $value === '' ? $default : $value;
}

function appName(): string
{
    return (string) appSetting('app_nom', (string) APP_NAME);
}

function appColor(string $key, string $default): string
{
    $value = (string) appSetting($key, $default);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtoupper($default);
    }

    // Les installations creees avant la nouvelle identite visuelle gardent
    // leurs anciennes valeurs en base. On ne remplace que ces anciens
    // defauts ; toute autre couleur personnalisee reste prioritaire.
    $legacyDefaults = [
        'couleur_primaire' => [
            'values' => ['#1F3864', '#AFAA0D'],
            'replacement' => '#F68B1F',
        ],
        'couleur_secondaire' => [
            'values' => ['#F5811F', '#FE7606'],
            'replacement' => '#00A651',
        ],
        'couleur_accent' => [
            'values' => ['#2E7D32', '#000000', '#C74343'],
            'replacement' => '#17352B',
        ],
    ];
    $normalized = strtoupper($value);
    if (
        isset($legacyDefaults[$key])
        && in_array($normalized, $legacyDefaults[$key]['values'], true)
    ) {
        return $legacyDefaults[$key]['replacement'];
    }

    return $normalized;
}

function appLogoUrl(bool $compact = false): string
{
    $filename = basename((string) appSetting('app_logo', ''));
    if ($filename !== '' && is_file(UPLOADS_PATH . '/logos/' . $filename)) {
        return asset('uploads/logos/' . rawurlencode($filename));
    }
    return asset($compact ? 'img/logo-risfm-mark.png' : 'img/logo-risfm.png');
}

function sessionLifetimeMinutes(): int
{
    $configured = (int) appSetting('session_lifetime_minutes', (string) SESSION_LIFETIME_MINUTES);
    return max(5, min(120, $configured));
}
