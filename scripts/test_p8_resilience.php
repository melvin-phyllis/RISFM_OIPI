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

if (in_array('--probe-production-error', $argv, true)) {
    ErrorHandler::register();
    throw new PDOException('DETAIL_SQL_QUI_NE_DOIT_PAS_ETRE_AFFICHE');
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// L'espacement est teste sans attendre reellement une minute.
$assert(ConnectionActivity::shouldWrite(0, 1_000, 60), 'la premiere ecriture doit etre autorisee');
$assert(!ConnectionActivity::shouldWrite(1_000, 1_059, 60), 'une seconde ecriture avant 60 s doit etre ignoree');
$assert(ConnectionActivity::shouldWrite(1_000, 1_060, 60), 'une ecriture apres 60 s doit etre autorisee');

$config = require BASE_PATH . '/config/database.php';
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $config['host'],
    $config['port'],
    $config['dbname'],
    $config['charset']
);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$locker = new PDO($dsn, (string) $config['user'], (string) $config['pass'], $options);
$worker = new PDO($dsn, (string) $config['user'], (string) $config['pass'], $options);
$testConnectionId = null;

try {
    $userId = (int) $worker->query('SELECT id FROM utilisateurs ORDER BY id LIMIT 1')->fetchColumn();
    if ($userId <= 0) {
        throw new RuntimeException('Aucun utilisateur disponible pour le test de verrou.');
    }

    $insert = $worker->prepare(
        "INSERT INTO connexions
            (utilisateur_id, adresse_ip, navigateur, statut, connecte_le, derniere_activite)
         VALUES (:utilisateur_id, '127.0.0.1', 'Test automatique P8', 'actif', NOW(), NOW())"
    );
    $insert->execute(['utilisateur_id' => $userId]);
    $testConnectionId = (int) $worker->lastInsertId();

    $locker->beginTransaction();
    $lock = $locker->prepare('SELECT id FROM connexions WHERE id = :id FOR UPDATE');
    $lock->execute(['id' => $testConnectionId]);

    $startedAt = microtime(true);
    $lockedResult = ConnectionActivity::touch($worker, $testConnectionId, $userId, 1);
    $elapsed = microtime(true) - $startedAt;

    $assert($lockedResult === false, 'la mise a jour verrouillee doit etre abandonnee sans exception');
    $assert($elapsed < 3.5, sprintf('le verrou a dure trop longtemps (%.2f s)', $elapsed));

    $locker->rollBack();

    $worker->beginTransaction();
    $assert(
        ConnectionActivity::touch($worker, $testConnectionId, $userId, 1),
        'la mise a jour doit reussir apres liberation du verrou'
    );
    $worker->rollBack();
} finally {
    if ($locker->inTransaction()) {
        $locker->rollBack();
    }
    if ($worker->inTransaction()) {
        $worker->rollBack();
    }
    if ($testConnectionId !== null) {
        $delete = $worker->prepare('DELETE FROM connexions WHERE id = :id');
        $delete->execute(['id' => $testConnectionId]);
    }
}

// Un processus distinct force le mode production et verifie que le detail de
// l'exception n'est jamais renvoye a la console/utilisateur.
$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg(__FILE__)
    . ' --probe-production-error';
$pipes = [];
$process = proc_open(
    $command,
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    BASE_PATH,
    array_merge($_ENV, ['APP_ENV' => 'production', 'APP_DEBUG' => 'false'])
);

if (!is_resource($process)) {
    $failures[] = 'impossible de lancer le test isole de la page 500';
} else {
    $publicOutput = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $assert($exitCode === 1, 'le gestionnaire global doit terminer avec le code 1');
    $assert(
        !str_contains($publicOutput, 'DETAIL_SQL_QUI_NE_DOIT_PAS_ETRE_AFFICHE'),
        'le detail SQL a ete expose en mode production'
    );
    $assert(
        str_contains($publicOutput, 'Reference :'),
        'la reponse technique ne contient pas de numero de reference'
    );
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "P8 OK: frequence limitee, verrou abandonne rapidement, erreur production masquee et referencee.\n";
