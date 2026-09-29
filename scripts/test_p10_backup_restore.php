<?php
declare(strict_types=1);

use App\Core\DatabaseBackup;
use App\Core\Security;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

if (!Security::ensureDirectory(STORAGE_PATH . '/tmp')) {
    fwrite(STDERR, "ECHEC: dossier temporaire indisponible.\n");
    exit(1);
}

$backup = new DatabaseBackup();
$manifest = $backup->manifest();
$validateOnly = in_array('--validate-only', $argv, true);
$paths = [];
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $nativePath = tempnam(STORAGE_PATH . '/tmp', 'p10_native_');
    $phpPath = tempnam(STORAGE_PATH . '/tmp', 'p10_pdo_');
    $incompletePath = tempnam(STORAGE_PATH . '/tmp', 'p10_incomplete_');
    if ($nativePath === false || $phpPath === false || $incompletePath === false) {
        throw new RuntimeException('Creation des fichiers temporaires impossible.');
    }
    $paths = [$nativePath, $phpPath, $incompletePath];

    $native = $backup->createDump($nativePath);
    $assert(!empty($native['success']), 'mysqldump complet impossible : ' . ($native['error'] ?? 'erreur inconnue'));
    $nativeValidation = $backup->validateSqlFile($nativePath);
    $assert(!empty($nativeValidation['valid']), 'validation du dump natif refusee : ' . ($nativeValidation['error'] ?? ''));

    if (!$validateOnly && !empty($nativeValidation['valid'])) {
        $nativeRestore = $backup->testRestore($nativePath);
        $assert(!empty($nativeRestore['success']), 'restauration temporaire du dump natif echouee : ' . ($nativeRestore['error'] ?? ''));
    }

    $portable = $backup->createPhpDump($phpPath);
    $assert(!empty($portable['success']), 'dump PDO complet impossible : ' . ($portable['error'] ?? 'erreur inconnue'));
    $portableValidation = $backup->validateSqlFile($phpPath);
    $assert(!empty($portableValidation['valid']), 'validation du dump PDO refusee : ' . ($portableValidation['error'] ?? ''));

    if (!$validateOnly && !empty($portableValidation['valid'])) {
        $portableRestore = $backup->testRestore($phpPath);
        $assert(!empty($portableRestore['success']), 'restauration temporaire du dump PDO echouee : ' . ($portableRestore['error'] ?? ''));
    }

    $sql = file_get_contents($nativePath);
    if (!is_string($sql)) {
        throw new RuntimeException('Lecture du dump natif impossible.');
    }
    $incomplete = preg_replace('/CREATE\s+PROCEDURE\s+`sp_kpi_globaux`/i', 'CREATE PROCEDURE_MANQUANTE `sp_kpi_globaux`', $sql, 1);
    if (!is_string($incomplete) || file_put_contents($incompletePath, $incomplete, LOCK_EX) === false) {
        throw new RuntimeException('Creation du cas incomplet impossible.');
    }
    $rejected = $backup->validateSqlFile($incompletePath);
    $assert(empty($rejected['valid']), 'une sauvegarde sans sp_kpi_globaux a ete qualifiee de complete');

    $assert(count($manifest['tables']) === 23, 'le manifeste doit contenir 23 tables');
    $assert(count($manifest['views']) === 5, 'le manifeste doit contenir 5 vues');
    $assert($manifest['procedures'] === ['sp_kpi_globaux'], 'la procedure KPI doit etre obligatoire');
    $assert($manifest['triggers'] === [], 'aucun trigger metier ne doit etre requis actuellement');
} finally {
    foreach ($paths as $path) {
        if (is_string($path) && is_file($path)) {
            unlink($path);
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo sprintf(
    ($validateOnly ? 'P10 VALIDATION OK' : 'P10 OK')
    . ": %d tables, %d vues, %d procedure, %d trigger; "
    . ($validateOnly ? 'formats natif et PDO verifies.' : 'restaurations native et PDO testees.')
    . "\n",
    count($manifest['tables']),
    count($manifest['views']),
    count($manifest['procedures']),
    count($manifest['triggers'])
);
