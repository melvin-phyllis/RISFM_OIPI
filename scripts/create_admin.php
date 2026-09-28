<?php
declare(strict_types=1);

/**
 * Cree le premier administrateur sans identifiant ni mot de passe universel.
 *
 * Le mot de passe est demande de maniere interactive et n'est jamais accepte
 * dans les arguments de la commande afin de ne pas finir dans l'historique du
 * terminal ou la liste des processus.
 */

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

try {
    $db = Database::getConnection();
    $adminCount = (int) $db->query(
        "SELECT COUNT(*) FROM utilisateurs WHERE role = 'administrateur'"
    )->fetchColumn();
    if ($adminCount > 0) {
        throw new RuntimeException(
            'Un administrateur existe deja. Utilisez la gestion des utilisateurs dans l application.'
        );
    }

    echo "Creation securisee du premier administrateur RISFM\n\n";
    $nom = Security::cleanString(adminPrompt('Nom', 'Administrateur'));
    $prenoms = Security::cleanString(adminPrompt('Prenoms', 'Systeme'));
    $email = mb_strtolower(Security::cleanString(adminPrompt('Adresse e-mail')));
    $service = Security::cleanString(adminPrompt('Service', 'Direction Generale'));

    if ($nom === '' || $prenoms === '') {
        throw new InvalidArgumentException('Le nom et les prenoms sont obligatoires.');
    }
    if (!Security::isValidEmail($email)) {
        throw new InvalidArgumentException('Adresse e-mail invalide.');
    }

    $password = adminSecretPrompt('Mot de passe');
    $confirmation = adminSecretPrompt('Confirmer le mot de passe');
    if (!hash_equals($password, $confirmation)) {
        throw new InvalidArgumentException('Les deux mots de passe ne correspondent pas.');
    }
    $passwordError = Security::passwordPolicyError($password);
    if ($passwordError !== null) {
        throw new InvalidArgumentException($passwordError);
    }

    $roleId = (int) $db->query(
        "SELECT id FROM roles WHERE code = 'administrateur' LIMIT 1"
    )->fetchColumn();
    if ($roleId < 1) {
        throw new RuntimeException(
            'Le role administrateur est absent. Importez schema.sql et appliquez les migrations.'
        );
    }

    $model = new UserModel();
    $created = $model->insertWithGeneratedIdentifiant([
        'nom' => $nom,
        'prenoms' => $prenoms,
        'email' => $email,
        'mot_de_passe' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'administrateur',
        'role_id' => $roleId,
        'service' => $service,
        'actif' => 1,
        'doit_changer_mdp' => 0,
    ]);

    $password = $confirmation = str_repeat("\0", max(strlen($password), strlen($confirmation)));
    echo "\nAdministrateur cree avec succes.\n";
    echo 'Identifiant : ' . $created['identifiant'] . "\n";
    echo 'E-mail      : ' . $email . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "\nECHEC : " . $exception->getMessage() . "\n");
    exit(1);
}
