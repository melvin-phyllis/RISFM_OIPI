<?php
declare(strict_types=1);

use App\Repositories\Utilisateur\UserRepository;

/**
 * Seeders de demonstration : deux executions successives dans une base
 * ephemere doivent produire exactement les memes donnees, sans doublon.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';
require_once BASE_PATH . '/scripts/test_support.php';

$config = require BASE_PATH . '/config/database.php';
$database = 'oipi_risfm_restore_test_demo_' . bin2hex(random_bytes(5));
$quotedDatabase = '`' . $database . '`';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$user = (string) ($config['maintenance_user'] ?? $config['user']);
$pass = (string) ($config['maintenance_pass'] ?? $config['pass']);
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $config['charset']),
    $user,
    $pass,
    $options
);
$created = false;
$failure = null;

try {
    $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $created = true;
    $db = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $database, $config['charset']),
        $user,
        $pass,
        $options
    );
    risfmLoadTestSql($db, BASE_PATH . '/schema.sql', 'schema.sql');

    $snapshot = static fn (): array => $db->query(
        "SELECT (SELECT COUNT(*) FROM utilisateurs) AS utilisateurs,
                (SELECT COUNT(*) FROM formulaires_manquants) AS formulaires,
                (SELECT COUNT(*) FROM recherches_formulaire) AS recherches,
                (SELECT COUNT(*) FROM finalisations_formulaire) AS finalisations,
                (SELECT GROUP_CONCAT(identifiant ORDER BY id) FROM utilisateurs) AS identifiants"
    )->fetch();

    risfmSeed($db, demo: true);
    $first = $snapshot();
    risfmSeed($db, demo: true);
    $second = $snapshot();

    if ((int) $first['utilisateurs'] !== 3 || (int) $first['formulaires'] !== 2
        || (int) $first['recherches'] !== 2 || (int) $first['finalisations'] !== 1
    ) {
        throw new RuntimeException('donnees de demonstration incompletes : ' . json_encode($first));
    }
    if ($first !== $second) {
        throw new RuntimeException('la seconde execution a modifie les donnees : ' . json_encode($second));
    }

    foreach ($db->query('SELECT id, identifiant, mot_de_passe, doit_changer_mdp, role FROM utilisateurs') as $row) {
        if ((string) $row['identifiant'] !== UserRepository::repo_generatedIdentifiant((int) $row['id'])
            || password_get_info((string) $row['mot_de_passe'])['algo'] === null
            || (int) $row['doit_changer_mdp'] !== ((string) $row['role'] === 'administrateur' ? 0 : 1)
        ) {
            throw new RuntimeException("compte #{$row['id']} mal initialise");
        }
    }

    $brevet = $db->query(
        "SELECT s.code, f.date_resolution FROM formulaires_manquants f
         JOIN statuts s ON s.id = f.statut_id WHERE f.numero_formulaire = 'B-2017-000154'"
    )->fetch();
    if (!$brevet || $brevet['code'] !== 'retrouve' || $brevet['date_resolution'] === null) {
        throw new RuntimeException('le brevet de demonstration doit etre retrouve avec une date de resolution');
    }
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($created) {
        $server->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    }
}

if ($failure !== null) {
    fwrite(STDERR, 'ECHEC: ' . $failure->getMessage() . "\n");
    exit(1);
}
echo "P7 DEMO DATA OK , double execution des seeders sans doublon ni modification.\n";
