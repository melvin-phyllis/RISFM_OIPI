<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    throw new RuntimeException('Autoloader Composer absent pour le test des relances.');
}
require_once $composerAutoload;
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    throw new RuntimeException('PHPMailer doit etre charge dans le contexte CLI des relances.');
}

spl_autoload_register(static function (string $class): void {
    foreach (['core', 'models', 'controllers'] as $directory) {
        $file = BASE_PATH . '/' . $directory . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/scripts/test_support.php';

if ((string) env('RISFM_REMINDER_CHILD', '0') !== '1') {
    $config = require BASE_PATH . '/config/database.php';
    $database = 'oipi_risfm_restore_test_reminders_' . bin2hex(random_bytes(5));
    $quotedDatabase = '`' . $database . '`';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $admin = new PDO(
        sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $config['charset']),
        (string) ($config['maintenance_user'] ?? $config['user']),
        (string) ($config['maintenance_pass'] ?? $config['pass']),
        $options
    );
    $created = false;
    try {
        $admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $created = true;
        $db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $database, $config['charset']),
            (string) ($config['maintenance_user'] ?? $config['user']),
            (string) ($config['maintenance_pass'] ?? $config['pass']),
            $options
        );
        $schema = file_get_contents(BASE_PATH . '/schema.sql');
        if (!is_string($schema)) {
            throw new RuntimeException('schema.sql illisible.');
        }
        foreach (SqlStatementParser::parse($schema) as $statement) {
            $db->exec($statement);
        }
        risfmLoadTestSql($db, BASE_PATH . '/demo_data.sql', 'demo_data.sql');
        unset($db);

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment = array_replace($environment, [
            'DB_NAME' => $database,
            'RISFM_REMINDER_CHILD' => '1',
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://example.invalid',
            'MAIL_DRY_RUN' => 'true',
            'MAIL_HOST' => 'smtp.example.invalid',
            'MAIL_USER' => 'recette',
            'MAIL_PASS' => 'recette',
            'MAIL_FROM' => 'recette@example.invalid',
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Demarrage du test enfant impossible.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        echo is_string($stdout) ? $stdout : '';
        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: 'Le test enfant a echoue.');
        }
    } finally {
        if ($created) {
            $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
        }
    }
    exit(0);
}

$db = Database::getConnection();
$typeId = (int) $db->query('SELECT id FROM types_titres ORDER BY id LIMIT 1')->fetchColumn();
$statusId = (int) $db->query("SELECT id FROM statuts WHERE code = 'en_recherche'")->fetchColumn();
$locationId = (int) $db->query('SELECT id FROM localisations ORDER BY id LIMIT 1')->fetchColumn();
$responsibleId = (int) $db->query("SELECT id FROM utilisateurs WHERE role IN ('agent','responsable') AND actif = 1 ORDER BY id LIMIT 1")->fetchColumn();
$adminId = (int) $db->query("SELECT id FROM utilisateurs WHERE role = 'administrateur' AND actif = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (min($typeId, $statusId, $locationId, $responsibleId, $adminId) < 1) {
    throw new RuntimeException('Referentiels de test insuffisants.');
}

$insertForm = $db->prepare(
    'INSERT INTO formulaires_manquants
        (numero_auto, type_titre_id, annee, numero_formulaire, statut_id,
         localisation_id, responsable_id, date_echeance_recherche, priorite, cree_par)
     VALUES
        (:numero_auto, :type_id, 2026, :numero, :statut_id,
         :localisation_id, :responsable_id, :date_echeance, :priorite, :cree_par)'
);
$insertMission = $db->prepare(
    "INSERT INTO missions_recherche
        (formulaire_id, localisation_id, responsable_id, affecte_par, etat, priorite, date_echeance)
     VALUES
        (:formulaire_id, :localisation_id, :responsable_id, :affecte_par, 'affectee', :priorite, :date_echeance)"
);
$deadlines = ['2026-07-23', '2026-07-21', '2026-07-20', '2026-07-14'];
$missionIds = [];
foreach ($deadlines as $index => $deadline) {
    $insertForm->execute([
        'numero_auto' => sprintf('RAPPEL-2026-%03d', $index + 1),
        'type_id' => $typeId,
        'numero' => sprintf('TEST-RAPPEL-%03d', $index + 1),
        'statut_id' => $statusId,
        'localisation_id' => $locationId,
        'responsable_id' => $responsibleId,
        'date_echeance' => $deadline,
        'priorite' => 'Haute',
        'cree_par' => $adminId,
    ]);
    $formId = (int) $db->lastInsertId();
    $insertMission->execute([
        'formulaire_id' => $formId,
        'localisation_id' => $locationId,
        'responsable_id' => $responsibleId,
        'affecte_par' => $adminId,
        'priorite' => 'Haute',
        'date_echeance' => $deadline,
    ]);
    $missionIds[] = (int) $db->lastInsertId();
}

// Une ancienne mission d'un cycle precedent ne doit jamais etre relancee
// apres la reouverture du formulaire.
$insertForm->execute([
    'numero_auto' => 'RAPPEL-2026-CYCLE',
    'type_id' => $typeId,
    'numero' => 'TEST-RAPPEL-ANCIEN-CYCLE',
    'statut_id' => $statusId,
    'localisation_id' => $locationId,
    'responsable_id' => $responsibleId,
    'date_echeance' => '2026-07-23',
    'priorite' => 'Haute',
    'cree_par' => $adminId,
]);
$oldCycleFormId = (int) $db->lastInsertId();
$insertMission->execute([
    'formulaire_id' => $oldCycleFormId,
    'localisation_id' => $locationId,
    'responsable_id' => $responsibleId,
    'affecte_par' => $adminId,
    'priorite' => 'Haute',
    'date_echeance' => '2026-07-23',
]);
$oldCycleMissionId = (int) $db->lastInsertId();
$db->prepare('UPDATE formulaires_manquants SET cycle_suivi = 2 WHERE id = :id')
    ->execute(['id' => $oldCycleFormId]);

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$count = static fn (string $table): int => (int) Database::getConnection()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
$service = new MissionReminderService();
$today = new DateTimeImmutable('2026-07-21');
$preview = $service->preview($today);
$assert(
    $preview['missions'] === 4
        && $preview['nouvelles_relances'] === 4
        && $preview['emails_potentiels'] === 4
        && $count('relances_missions') === 0
        && $count('notifications') === 0,
    'l apercu doit annoncer les actions sans aucune ecriture ni ancien cycle'
);
$first = $service->run($today);
$assert($first['missions'] === 4, 'seules les missions du cycle courant doivent etre traitees');
$assert($first['relances'] === 4, 'J-2, jour J, J+1 et escalade J+7 doivent creer quatre relances');
$assert($first['notifications'] === 4 && $first['emails'] === 4 && $first['erreurs'] === 0, 'chaque relance doit produire notification et e-mail');
$assert($count('relances_missions') === 4, 'quatre evenements doivent etre historises');
$assert($count('notifications') === 4, 'quatre notifications internes doivent etre creees');
$assert((int) $db->query("SELECT COUNT(*) FROM relances_missions WHERE type_relance = 'escalade' AND destinataire_id = {$adminId}")->fetchColumn() === 1, 'le retard J+7 doit etre escalade a l administrateur');
$assert(
    (int) $db->query("SELECT COUNT(*) FROM relances_missions WHERE mission_id = {$oldCycleMissionId}")->fetchColumn() === 0,
    'une mission d un ancien cycle ne doit produire aucune relance'
);
$health = (new ReminderRunState($db))->status();
$assert(
    $health['state'] === 'ok'
        && ($health['last_finished'] ?? null) !== null
        && (int) ($health['summary']['relances'] ?? -1) === 4,
    'chaque execution doit publier un heartbeat exploitable par le back-office'
);

$second = $service->run($today);
$assert($second['relances'] === 0 && $count('relances_missions') === 4 && $count('notifications') === 4, 'une seconde execution le meme jour ne doit rien dupliquer');

$third = $service->run(new DateTimeImmutable('2026-07-23'));
$assert($third['relances'] === 3, 'la cadence doit generer le jour J et les retards multiples de trois jours');
$assert($count('relances_missions') === 7, 'les nouvelles dates doivent conserver un historique distinct');

$db->prepare("UPDATE missions_recherche SET etat = 'terminee', date_cloture = NOW() WHERE id = :id")->execute(['id' => $missionIds[1]]);
$closedCount = $db->prepare('SELECT COUNT(*) FROM relances_missions WHERE mission_id = :id');
$closedCount->execute(['id' => $missionIds[1]]);
$beforeClosedCheck = (int) $closedCount->fetchColumn();
$service->run(new DateTimeImmutable('2026-07-24'));
$closedCount->execute(['id' => $missionIds[1]]);
$afterClosedCheck = (int) $closedCount->fetchColumn();
$assert($afterClosedCheck === $beforeClosedCheck, 'une mission terminee ne doit plus etre relancee');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "RELANCES MISSIONS OK: apercu sans ecriture, cycles, heartbeat, echeances, retards, escalade et anti-doublon verifies.\n";
