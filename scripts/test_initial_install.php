<?php
declare(strict_types=1);

use App\Repositories\Utilisateur\UserRepository;
use Database\Seeders\AdminSeeder;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';
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

    foreach (['utilisateurs', 'roles', 'statuts'] as $table) {
        if ((int) $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() !== 0) {
            throw new RuntimeException("schema.sql ne doit livrer aucune donnee ({$table}).");
        }
    }

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment = array_replace($environment, [
        'APP_ENV' => 'testing',
        'DB_NAME' => $database,
    ]);

    /** Lance scripts/seed.php sur la base temporaire et retourne son code de sortie. */
    $seed = static function (array $arguments = [], array $overrides = []) use ($environment): array {
        $pipes = [];
        $process = proc_open(
            array_merge([PHP_BINARY, BASE_PATH . '/scripts/seed.php'], $arguments),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            array_replace($environment, $overrides)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Demarrage de seed.php impossible.');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout . $stderr];
    };
    $counts = static fn (): array => $db->query(
        "SELECT (SELECT COUNT(*) FROM roles) AS roles, (SELECT COUNT(*) FROM permissions) AS permissions,
                (SELECT COUNT(*) FROM role_permissions) AS droits, (SELECT COUNT(*) FROM statuts) AS statuts,
                (SELECT COUNT(*) FROM types_titres) AS types, (SELECT COUNT(*) FROM localisations) AS localisations,
                (SELECT COUNT(*) FROM parametres) AS parametres, (SELECT COUNT(*) FROM utilisateurs) AS utilisateurs"
    )->fetch();

    [$exit, $output] = $seed();
    if ($exit !== 0) {
        throw new RuntimeException('Premier seed en echec : ' . trim($output));
    }
    $first = $counts();
    if ((int) $first['roles'] !== 4 || (int) $first['statuts'] !== 7 || (int) $first['types'] !== 7
        || (int) $first['localisations'] !== 10 || (int) $first['parametres'] !== 6
        || (int) $first['permissions'] !== 23 || (int) $first['utilisateurs'] !== 1
    ) {
        throw new RuntimeException('Donnees de reference incompletes : ' . json_encode($first));
    }

    $admin = $db->query(
        "SELECT id, identifiant, email, mot_de_passe, role, doit_changer_mdp
         FROM utilisateurs WHERE role = 'administrateur' LIMIT 1"
    )->fetch();
    if (
        !$admin
        || (string) $admin['identifiant'] !== UserRepository::repo_generatedIdentifiant((int) $admin['id'])
        || (string) $admin['email'] !== AdminSeeder::EMAIL
        || !password_verify(AdminSeeder::MOT_DE_PASSE, (string) $admin['mot_de_passe'])
        || (int) $admin['doit_changer_mdp'] !== 1
    ) {
        throw new RuntimeException('Le premier administrateur cree est invalide.');
    }

    // Relance : rien ne doit etre ajoute ni modifie.
    $db->exec("UPDATE parametres SET valeur = 'Nom personnalise' WHERE cle = 'app_nom'");
    [$exit, $output] = $seed();
    if ($exit !== 0 || $counts() !== $first) {
        throw new RuntimeException('Le seed relance a modifie les donnees : ' . trim($output));
    }
    if ((string) $db->query("SELECT valeur FROM parametres WHERE cle = 'app_nom'")->fetchColumn() !== 'Nom personnalise') {
        throw new RuntimeException('Le seed a ecrase un parametre modifie depuis l administration.');
    }

    [$exit] = $seed(['--demo'], ['APP_ENV' => 'production']);
    if ($exit === 0 || $counts() !== $first) {
        throw new RuntimeException('Les donnees de demonstration doivent etre refusees en production.');
    }

    echo "INSTALLATION INITIALE OK: schema sans donnee, seeders complets, relance sans effet et admin verifie.\n";
} finally {
    if ($created) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    }
}
