<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Security;
use Database\Seeders\AdminSeeder;

/**
 * Cree le premier administrateur sans identifiant ni mot de passe universel.
 *
 * Le mot de passe est demande de maniere interactive et n'est jamais accepte
 * dans les arguments afin de ne pas finir dans l'historique du terminal ou la
 * liste des processus.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage : php scripts/create_admin.php\n";
    echo "Cree uniquement le premier compte administrateur d'une installation neuve.\n";
    exit(0);
}

/** Lit une valeur non secrete et applique une valeur par defaut. */
function adminPrompt(string $label, string $default = ''): string
{
    $suffix = $default !== '' ? " [{$default}]" : '';
    fwrite(STDOUT, $label . $suffix . ' : ');
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Lecture de la saisie impossible.');
    }
    $value = trim($value);
    return $value !== '' ? $value : $default;
}

/** Lit un secret sans l'afficher lorsque le terminal le permet. */
function adminSecretPrompt(string $label): string
{
    fwrite(STDOUT, $label . ' : ');
    $hidden = DIRECTORY_SEPARATOR !== '\\'
        && function_exists('posix_isatty')
        && posix_isatty(STDIN)
        && function_exists('shell_exec')
        && trim((string) shell_exec('command -v stty 2>/dev/null')) !== '';

    if ($hidden) {
        shell_exec('stty -echo');
    }
    try {
        $value = fgets(STDIN);
    } finally {
        if ($hidden) {
            shell_exec('stty echo');
        }
        fwrite(STDOUT, PHP_EOL);
    }

    if ($value === false) {
        throw new RuntimeException('Lecture du mot de passe impossible.');
    }
    return rtrim($value, "\r\n");
}

$password = '';
$confirmation = '';

try {
    $nom = Security::cleanString(adminPrompt('Nom', 'Administrateur'));
    $prenoms = Security::cleanString(adminPrompt('Prenoms', 'Systeme'));
    $email = mb_strtolower(Security::cleanString(adminPrompt('Adresse e-mail')));
    $service = Security::cleanString(adminPrompt('Service', 'Direction Generale'));

    $password = adminSecretPrompt('Mot de passe');
    $confirmation = adminSecretPrompt('Confirmer le mot de passe');
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('Les deux mots de passe ne correspondent pas.');
    }

    $resultat = (new AdminSeeder(
        Database::getConnection(),
        $nom,
        $prenoms,
        $email,
        $service,
        $password
    ))->run();

    echo "\nAdministrateur cree avec succes.\n";
    echo $resultat . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\nECHEC : " . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $length = max(strlen($password), strlen($confirmation));
    if ($length > 0) {
        $password = $confirmation = str_repeat("\0", $length);
    }
}
