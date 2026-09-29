<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\SqlStatementParser;
use App\Repositories\Formulaire\FormulaireRepository;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';
require_once BASE_PATH . '/scripts/test_support.php';

$volume = max(5000, min(100000, (int) env('RISFM_PERF_VOLUME', 20000)));

if ((string) env('RISFM_PERF_CHILD', '0') !== '1') {
    $config = require BASE_PATH . '/config/database.php';
    $database = 'oipi_risfm_restore_test_perf_' . bin2hex(random_bytes(5));
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

    try {
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
        unset($db);

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['DB_NAME'] = $database;
        $environment['RISFM_PERF_CHILD'] = '1';
        $environment['RISFM_PERF_VOLUME'] = (string) $volume;
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Demarrage du processus de benchmark impossible.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        echo is_string($stdout) ? $stdout : '';
        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: 'Le benchmark a echoue.');
        }
    } finally {
        if ($created) {
            $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
        }
    }
    exit(0);
}

$db = Database::getConnection();
$typeIds = array_map('intval', $db->query('SELECT id FROM types_titres WHERE actif = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$locationIds = array_map('intval', $db->query('SELECT id FROM localisations WHERE actif = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$userIds = array_map('intval', $db->query('SELECT id FROM utilisateurs WHERE actif = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$statuses = $db->query("SELECT code, id FROM statuts WHERE code IN ('introuvable','en_recherche','retrouve')")->fetchAll(PDO::FETCH_KEY_PAIR);
if ($typeIds === [] || $locationIds === [] || $userIds === [] || count($statuses) !== 3) {
    throw new RuntimeException('Referentiels insuffisants pour le benchmark.');
}

$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['recherches_formulaire', 'missions_recherche', 'formulaires_manquants'] as $table) {
    $db->exec('TRUNCATE TABLE ' . $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

$insertForm = $db->prepare(
    'INSERT INTO formulaires_manquants
        (numero_auto, type_titre_id, annee, numero_formulaire, deposant, mandataire,
         statut_id, localisation_id, responsable_id, date_echeance_recherche,
         date_recherche, date_resolution, resultat, observations, niveau_urgence,
         priorite, est_archive, cree_par, cree_le, mis_a_jour_le)
     VALUES
        (:numero_auto, :type_id, :annee, :numero, :deposant, :mandataire,
         :statut_id, :localisation_id, :responsable_id, :echeance,
         :date_recherche, :date_resolution, :resultat, :observations, :urgence,
         :priorite, 0, :cree_par, :cree_le, :mis_a_jour_le)'
);
$insertMission = $db->prepare(
    'INSERT INTO missions_recherche
        (formulaire_id, localisation_id, responsable_id, affecte_par, etat,
         resultat_code, resultat, priorite, date_affectation, date_echeance,
         date_recherche, date_cloture)
     VALUES
        (:formulaire_id, :localisation_id, :responsable_id, :affecte_par, :etat,
         :resultat_code, :resultat, :priorite, :date_affectation, :date_echeance,
         :date_recherche, :date_cloture)'
);

$db->beginTransaction();
$startSeed = hrtime(true);
$missionCount = 0;
for ($index = 1; $index <= $volume; $index++) {
    $kind = $index % 3;
    $statusCode = $kind === 0 ? 'retrouve' : ($kind === 1 ? 'en_recherche' : 'introuvable');
    $active = $statusCode === 'en_recherche';
    $resolved = $statusCode === 'retrouve';
    $year = 2010 + ($index % 17);
    $created = sprintf('2026-%02d-%02d %02d:%02d:00', 1 + ($index % 7), 1 + ($index % 27), $index % 24, $index % 60);
    $responsibleId = $userIds[$index % count($userIds)];
    $locationId = $locationIds[$index % count($locationIds)];
    $insertForm->execute([
        'numero_auto' => sprintf('PERF-%04d-%07d', $year, $index),
        'type_id' => $typeIds[$index % count($typeIds)],
        'annee' => $year,
        'numero' => sprintf('PERF-FORM-%07d', $index),
        'deposant' => 'Deposant performance ' . ($index % 500),
        'mandataire' => 'Mandataire performance ' . ($index % 120),
        'statut_id' => (int) $statuses[$statusCode],
        'localisation_id' => $locationId,
        'responsable_id' => $active ? $responsibleId : null,
        'echeance' => $active ? sprintf('2026-%02d-%02d', 7 + ($index % 3), 1 + ($index % 27)) : null,
        'date_recherche' => $active ? null : sprintf('2026-%02d-%02d', 1 + ($index % 7), 1 + ($index % 27)),
        'date_resolution' => $resolved ? $created : null,
        'resultat' => $resolved ? 'Formulaire retrouve et saisi' : ($active ? null : 'Recherche sans resultat'),
        'observations' => 'Jeu realiste de performance lot ' . ($index % 40),
        'urgence' => ['Faible', 'Moyen', 'Eleve', 'Critique'][$index % 4],
        'priorite' => ['Basse', 'Normale', 'Haute', 'Urgente'][$index % 4],
        'cree_par' => $userIds[0],
        'cree_le' => $created,
        'mis_a_jour_le' => $created,
    ]);
    $formId = (int) $db->lastInsertId();
    $state = $active ? 'affectee' : 'terminee';
    $resultCode = $active ? null : ($resolved ? 'retrouve' : 'non_retrouve');
    $insertMission->execute([
        'formulaire_id' => $formId,
        'localisation_id' => $locationId,
        'responsable_id' => $responsibleId,
        'affecte_par' => $userIds[0],
        'etat' => $state,
        'resultat_code' => $resultCode,
        'resultat' => $active ? null : ($resolved ? 'Retrouve' : 'Non retrouve'),
        'priorite' => ['Basse', 'Normale', 'Haute', 'Urgente'][$index % 4],
        'date_affectation' => $created,
        'date_echeance' => sprintf('2026-%02d-%02d', 7 + ($index % 3), 1 + ($index % 27)),
        'date_recherche' => $active ? null : substr($created, 0, 10),
        'date_cloture' => $active ? null : $created,
    ]);
    $missionCount++;

    // Un quart des dossiers simule une recherche parallele dans un autre lieu.
    if ($index % 4 === 0 && count($locationIds) > 1) {
        $insertMission->execute([
            'formulaire_id' => $formId,
            'localisation_id' => $locationIds[($index + 1) % count($locationIds)],
            'responsable_id' => $userIds[($index + 1) % count($userIds)],
            'affecte_par' => $userIds[0],
            'etat' => $state,
            'resultat_code' => $resultCode,
            'resultat' => $active ? null : ($resolved ? 'Retrouve' : 'Non retrouve'),
            'priorite' => 'Normale',
            'date_affectation' => $created,
            'date_echeance' => sprintf('2026-%02d-%02d', 7 + ($index % 3), 1 + ($index % 27)),
            'date_recherche' => $active ? null : substr($created, 0, 10),
            'date_cloture' => $active ? null : $created,
        ]);
        $missionCount++;
    }
}
$db->commit();
$seedSeconds = (hrtime(true) - $startSeed) / 1e9;
$analyze = $db->query('ANALYZE TABLE formulaires_manquants, missions_recherche');
$analyze->fetchAll();
$analyze->closeCursor();

$model = new FormulaireRepository();
$results = [];
$measure = static function (string $name, callable $callback, float $limitMs) use (&$results): mixed {
    $start = hrtime(true);
    $value = $callback();
    $elapsed = (hrtime(true) - $start) / 1e6;
    $results[] = ['name' => $name, 'ms' => $elapsed, 'limit' => $limitMs, 'ok' => $elapsed <= $limitMs];
    return $value;
};

$rows = $measure('Registre, premiere page (25)', fn () => $model->repo_search([], null, 'f.mis_a_jour_le', 'DESC', 25, 0), 1000);
$count = $measure('Comptage complet', fn () => $model->repo_searchCount([]), 500);
$filtered = $measure(
    'Filtre annee + statut + responsable',
    fn () => $model->repo_search([
        'annee' => 2024,
        'statut_id' => (int) $statuses['en_recherche'],
        'responsable_id' => $userIds[1 % count($userIds)],
    ], null, 'f.annee', 'DESC', 25, 0),
    1000
);
$filteredCount = $measure(
    'Comptage filtre responsable',
    fn () => $model->repo_searchCount(['responsable_id' => $userIds[1 % count($userIds)]]),
    500
);
$missions = $measure(
    'Dashboard agent, missions actives',
    fn () => $model->repo_missionsActivesPourResponsable($userIds[1 % count($userIds)], 10),
    500
);
$stats = $measure('Statistiques (5 series)', fn () => [
    $model->repo_statsParAnnee(),
    $model->repo_statsParType(),
    $model->repo_statsParStatut(),
    $model->repo_statsParResponsable(),
    $model->repo_statsMensuelles(),
], 1500);

$exportCount = 0;
$memoryBefore = memory_get_usage(true);
$measure('Export complet par lots', function () use ($model, &$exportCount): void {
    foreach ($model->repo_iterateForExport([], null, 500) as $row) {
        $exportCount++;
    }
}, 25000);
$memoryDeltaMb = (memory_get_peak_usage(true) - $memoryBefore) / 1048576;

$checks = [
    [count($rows) === 25, 'la premiere page doit contenir 25 dossiers'],
    [$count === $volume, "le comptage doit retourner {$volume}"],
    [$filtered !== [], 'le filtre combine doit retourner des dossiers'],
    [$filteredCount > 0, 'le comptage par responsable doit etre non nul'],
    [$missions !== [], 'le dashboard agent doit retourner des missions'],
    [count($stats) === 5, 'les cinq series statistiques doivent etre disponibles'],
    [$exportCount === $volume, "l'export doit parcourir {$volume} dossiers"],
    [$memoryDeltaMb <= 128, 'le surcout memoire de l export doit rester sous 128 Mio'],
];
$failures = [];
foreach ($checks as [$valid, $message]) {
    if (!$valid) {
        $failures[] = $message;
    }
}
foreach ($results as $result) {
    if (!$result['ok']) {
        $failures[] = $result['name'] . ' depasse le seuil de ' . $result['limit'] . ' ms';
    }
}

echo sprintf("VOLUME: %d formulaires, %d missions (generation %.2f s)\n", $volume, $missionCount, $seedSeconds);
foreach ($results as $result) {
    echo sprintf("%-42s %8.1f ms  [seuil %.0f ms] %s\n", $result['name'], $result['ms'], $result['limit'], $result['ok'] ? 'OK' : 'ECHEC');
}
echo sprintf("Export: %d lignes; surcout memoire maximal: %.1f Mio\n", $exportCount, $memoryDeltaMb);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "PERFORMANCE GRAND VOLUME OK: donnees isolees, seuils respectes.\n";
