<?php
declare(strict_types=1);

use App\Core\SqlStatementParser;

/**
 * Commande unique de recette avant livraison.
 *
 * Elle ne travaille jamais dans la base configuree pour l'application : une
 * base ephemere est creee, alimentee depuis schema.sql, puis supprimee meme si
 * un test echoue.
 */

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';
require_once BASE_PATH . '/scripts/test_support.php';

/** @return array<string,string> */
function recetteEnvironment(array $overrides = []): array
{
    $environment = getenv();
    return array_merge(is_array($environment) ? $environment : [], $overrides);
}

/** Execute une commande en affichant sa sortie au fur et a mesure. */
function recetteRun(array $command, array $environment, int $timeoutSeconds = 300): void
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Impossible de lancer : ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $startedAt = microtime(true);
    do {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 0, 100_000) !== false) {
            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk !== false && $chunk !== '') {
                    fwrite($stream === $pipes[2] ? STDERR : STDOUT, $chunk);
                }
            }
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $startedAt) > $timeoutSeconds) {
            proc_terminate($process);
            throw new RuntimeException('Delai depasse pour : ' . implode(' ', $command));
        }
    } while (true);
    foreach ([1, 2] as $pipe) {
        $chunk = stream_get_contents($pipes[$pipe]);
        if ($chunk !== false && $chunk !== '') {
            fwrite($pipe === 2 ? STDERR : STDOUT, $chunk);
        }
        fclose($pipes[$pipe]);
    }
    $exit = proc_close($process);
    if ($exit === -1) {
        $exit = (int) ($status['exitcode'] ?? -1);
    }
    if ($exit !== 0) {
        throw new RuntimeException('Test en echec : ' . implode(' ', $command));
    }
}

$config = require BASE_PATH . '/config/database.php';
$database = 'oipi_risfm_restore_test_recette_' . bin2hex(random_bytes(5));
$quotedDatabase = '`' . $database . '`';
$serverDsn = sprintf(
    'mysql:host=%s;port=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['charset']
);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$admin = new PDO(
    $serverDsn,
    (string) ($config['maintenance_user'] ?? $config['user']),
    (string) ($config['maintenance_pass'] ?? $config['pass']),
    $options
);
$created = false;
$failure = null;

try {
    echo "RISFM , recette de livraison\n";
    echo "Base isolee : {$database}\n\n";
    $admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
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
    $schema = file_get_contents(BASE_PATH . '/schema.sql');
    if (!is_string($schema)) {
        throw new RuntimeException('schema.sql illisible.');
    }
    foreach (SqlStatementParser::parse($schema) as $position => $statement) {
        try {
            $db->exec($statement);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Import schema.sql instruction #' . ($position + 1) . ' : ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }
    risfmSeed($db, demo: true);

    $environment = recetteEnvironment([
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'true',
        'DB_NAME' => $database,
        'ENABLE_LOGIN_OTP' => 'false',
        'ENABLE_CAPTCHA' => 'false',
        'MAIL_DRY_RUN' => 'true',
        'MAIL_HOST' => 'smtp.example.invalid',
        'MAIL_USER' => 'recette',
        'MAIL_PASS' => 'recette',
        'MAIL_FROM' => 'recette@example.invalid',
        'LOGIN_IDENTIFIER_MAX_ATTEMPTS' => '5',
        'LOGIN_IP_MAX_ATTEMPTS' => '8',
        'LOGIN_RATE_LIMIT_WINDOW_MINUTES' => '10',
        'LOGIN_ATTEMPT_RETENTION_DAYS' => '30',
        'SESSION_NAME' => 'RISFM_RECETTE_' . bin2hex(random_bytes(4)),
    ]);

    $tests = [
        ['Installation initiale securisee', 'test_initial_install.php', 60],
        ['P5 , validations metier', 'test_p5_validation.php', 60],
        ['Validation des formulaires (FormRequest)', 'test_form_requests.php', 30],
        ['Import , CSV et Excel', 'test_formulaire_import.php', 120],
        ['P6 , iterateur export', 'test_p6_iterator.php', 60],
        ['P6 , volume superieur a 5 000', 'test_p6_large_volume.php', 120],
        ['P7 , seeders de demonstration', 'test_p7_demo_data.php', 60],
        ['P7 , migration des identifiants', 'test_p7_migration.php', 60],
        ['P7 , etat final des identifiants', 'test_p7_state.php', 60],
        ['P8 , resilience MySQL', 'test_p8_resilience.php', 60],
        ['Transactions et points de reprise', 'test_database_transactions.php', 30],
        ['Securite , limitation des connexions', 'test_login_rate_limiter.php', 60],
        ['P9 , reinitialisation securisee', 'test_p9_password_reset.php', 60],
        ['Acces utilisateur securise et cycle de vie', 'test_secure_user_access.php', 120],
        ['Relances automatiques des missions', 'test_mission_reminders.php', 120],
        ['P10 , sauvegarde et restauration complete', 'test_p10_backup_restore.php', 180],
        ['P11 , migrations SQL', 'test_p11_migrations.php', 120],
        ['Performance , grand volume', 'test_performance_large_volume.php', 180],
        ['P12 , recette fonctionnelle et responsive', 'test_p12_acceptance.php', 300],
    ];

    foreach ($tests as [$label, $script, $timeout]) {
        echo "[TEST] {$label}\n";
        recetteRun([PHP_BINARY, BASE_PATH . '/scripts/' . $script], $environment, $timeout);
        echo "\n";
    }

    echo "RECETTE COMPLETE OK , toutes les donnees de test etaient isolees.\n";
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    if ($created) {
        $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
    }
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, "RECETTE EN ECHEC : {$failure->getMessage()}\n");
    exit(1);
}
