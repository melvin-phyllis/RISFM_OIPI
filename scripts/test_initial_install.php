<?php
declare(strict_types=1);

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
require_once BASE_PATH . '/scripts/test_support.php';

$config = require BASE_PATH . '/config/database.php';
$database = 'oipi_risfm_restore_test_install_' . bin2hex(random_bytes(5));
$quotedDatabase = '`' . $database . '`';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $config['charset']),
    (string) ($config['maintenance_user'] ?? $config['user']),
    (string) ($config['maintenance_pass'] ?? $config['pass']),
    $options
);
$created = false;

try {
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db = new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $database,
            $config['charset']
        ),
        (string) ($config['maintenance_user'] ?? $config['user']),
        (string) ($config['maintenance_pass'] ?? $config['pass']),
        $options
    );
    risfmLoadTestSql($db, BASE_PATH . '/schema.sql', 'schema.sql');

    if ((int) $db->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn() !== 0) {
        throw new RuntimeException('schema.sql ne doit livrer aucun compte.');
    }

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment = array_replace($environment, [
        'APP_ENV' => 'testing',
        'DB_NAME' => $database,
    ]);

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, BASE_PATH . '/scripts/create_admin.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Demarrage de create_admin.php impossible.');
    }
    fwrite(
        $pipes[0],
        "Administrateur\nRecette\nadmin.recette@example.invalid\nDirection Generale\nRecette@2026!\nRecette@2026!\n"
    );
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException(trim((string) $stderr) ?: 'Creation administrateur en echec.');
    }

    $admin = $db->query(
        "SELECT id, identifiant, email, mot_de_passe, role
         FROM utilisateurs WHERE role = 'administrateur' LIMIT 1"
    )->fetch();
    if (
        !$admin
        || (string) $admin['identifiant'] !== UserModel::generatedIdentifiant((int) $admin['id'])
        || (string) $admin['email'] !== 'admin.recette@example.invalid'
        || !password_verify('Recette@2026!', (string) $admin['mot_de_passe'])
    ) {
        throw new RuntimeException('Le premier administrateur cree est invalide.');
    }

    echo "INSTALLATION INITIALE OK: schema sans compte et administrateur interactif verifie.\n";
} finally {
    if ($created) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    }
}
