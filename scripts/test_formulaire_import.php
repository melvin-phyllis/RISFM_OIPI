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
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
require_once BASE_PATH . '/core/helpers.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$db = Database::getConnection();
$type = $db->query('SELECT * FROM types_titres WHERE actif = 1 ORDER BY ordre, id LIMIT 1')->fetch();
$location = $db->query('SELECT * FROM localisations WHERE actif = 1 ORDER BY id LIMIT 1')->fetch();
$user = $db->query(
    "SELECT * FROM utilisateurs
     WHERE actif = 1 AND role IN ('administrateur','responsable','agent')
     ORDER BY id LIMIT 1"
)->fetch();
$actorId = (int) ($db->query(
    "SELECT id FROM utilisateurs WHERE actif = 1 AND role = 'administrateur' ORDER BY id LIMIT 1"
)->fetchColumn() ?: 0);

if (!$type || !$location || !$user || $actorId < 1) {
    fwrite(STDERR, "IMPORT ECHEC: referentiels ou administrateur manquants.\n");
    exit(1);
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$temporaryFiles = [];
$temp = static function (string $prefix) use (&$temporaryFiles): string {
    $path = tempnam(sys_get_temp_dir(), $prefix);
    if ($path === false) {
        throw new RuntimeException('Fichier temporaire indisponible.');
    }
    $temporaryFiles[] = $path;
    return $path;
};
$service = new FormulaireImportService();
$suffix = strtoupper(bin2hex(random_bytes(4)));
$minimalNumber = 'IMPORT-MIN-' . $suffix;
$resolvedNumber = 'IMPORT-RET-' . $suffix;
$badNumber = 'IMPORT-BAD-' . $suffix;

try {
    // CSV : encodage, separateur, ligne valide, referentiel invalide et
    // blocage de tout import partiel.
    $badCsv = $temp('risfm_import_bad_');
    $handle = fopen($badCsv, 'wb');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, FormulaireImportService::TEMPLATE_COLUMNS, ';');
    fputcsv($handle, [
        (string) $type['code'], date('Y'), $minimalNumber, '', '', '', '', '', '', '', '', '', 'Normale',
    ], ';');
    fputcsv($handle, [
        'TYPE INCONNU', date('Y'), $badNumber, '', '', '', '', '', '', '', '', '', 'Normale',
    ], ';');
    fputcsv($handle, [
        (string) $type['code'], date('Y'), '=2+2', '', '', '', '', '', '', '', '', '', 'Normale',
    ], ';');
    fclose($handle);

    $inspection = $service->inspectFile([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $badCsv,
        'size' => filesize($badCsv),
        'name' => 'registre.csv',
    ]);
    $badAnalysis = $service->analyse($badCsv, $inspection['extension']);
    $assert(
        $badAnalysis['total'] === 3
            && $badAnalysis['valid_count'] === 1
            && $badAnalysis['error_count'] === 2,
        'le CSV doit distinguer les lignes valides, les referentiels inconnus et les formules'
    );
    $assert(
        str_contains(
            implode(' ', array_merge(...array_map(
                static fn (array $row): array => $row['errors'],
                $badAnalysis['rows']
            ))),
            'formules Excel'
        ),
        'les formules doivent etre refusees avant l import'
    );
    $blocked = false;
    try {
        $service->import($badCsv, 'csv', $actorId);
    } catch (DomainException) {
        $blocked = true;
    }
    $assert($blocked, 'une ligne invalide doit bloquer tout import partiel');
    $assert(
        (new FormulaireModel())->count('numero_formulaire = :numero', ['numero' => $minimalNumber]) === 0,
        'le fichier invalide ne doit creer aucun formulaire'
    );

    // XLSX : en-tete en ligne 4 comme dans l'export RISFM, ligne minimale et
    // reprise d'un dossier retrouve avec mission, historique et finalisation.
    $xlsxPath = $temp('risfm_import_ok_');
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'OIPI , Registre historique');
    foreach (FormulaireImportService::TEMPLATE_COLUMNS as $column => $label) {
        $sheet->setCellValueExplicitByColumnAndRow($column + 1, 4, $label, DataType::TYPE_STRING);
    }
    $minimalRow = [
        (string) $type['libelle'], date('Y'), $minimalNumber, 'Introuvable',
        '', '', '', '', '', 'Import minimal', '', '', 'Normale',
    ];
    $resolvedRow = [
        (string) $type['code'], date('Y'), $resolvedNumber, 'retrouve',
        (string) $location['libelle'], (string) $user['identifiant'], date('d/m/Y'),
        'Formulaire retrouvé pendant la reprise historique', '', 'Déposant importé',
        '', 'Import de recette', 'Haute',
    ];
    foreach ([$minimalRow, $resolvedRow] as $rowOffset => $values) {
        foreach ($values as $column => $value) {
            $sheet->setCellValueExplicitByColumnAndRow(
                $column + 1,
                5 + $rowOffset,
                (string) $value,
                DataType::TYPE_STRING
            );
        }
    }
    (new Xlsx($spreadsheet))->save($xlsxPath);
    $spreadsheet->disconnectWorksheets();

    $xlsxInspection = $service->inspectFile([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $xlsxPath,
        'size' => filesize($xlsxPath),
        'name' => 'registre.xlsx',
    ]);
    $analysis = $service->analyse($xlsxPath, $xlsxInspection['extension']);
    $assert(
        $analysis['header_row'] === 4
            && $analysis['total'] === 2
            && $analysis['error_count'] === 0,
        'le XLSX doit reconnaitre l en-tete exporte et valider les deux lignes'
    );

    $db->beginTransaction();
    try {
        $result = $service->import($xlsxPath, 'xlsx', $actorId);
        $resolvedId = (int) $db->query(
            "SELECT id FROM formulaires_manquants
             WHERE numero_formulaire = " . $db->quote($resolvedNumber) . ' LIMIT 1'
        )->fetchColumn();
        $assert($result['imported'] === 2, 'les deux lignes valides doivent etre importees atomiquement');
        $assert(
            (new FormulaireModel())->count(
                'numero_formulaire IN (:minimal, :resolved)',
                ['minimal' => $minimalNumber, 'resolved' => $resolvedNumber]
            ) === 2,
            'les deux dossiers importes doivent exister dans la transaction'
        );
        $assert(
            $resolvedId > 0
                && (int) $db->query(
                    'SELECT COUNT(*) FROM missions_recherche WHERE formulaire_id = ' . $resolvedId
                )->fetchColumn() === 1
                && (int) $db->query(
                    'SELECT COUNT(*) FROM recherches_formulaire WHERE formulaire_id = ' . $resolvedId
                )->fetchColumn() === 1
                && (int) $db->query(
                    'SELECT COUNT(*) FROM finalisations_formulaire WHERE formulaire_id = ' . $resolvedId
                )->fetchColumn() === 1,
            'la reprise Retrouve doit reconstituer mission, historique et jalon de finalisation'
        );
        $assert(
            (int) $db->query(
                "SELECT COUNT(*) FROM activites
                 WHERE type_action = 'import'
                   AND (entite_type = 'formulaire' OR entite_type = 'import_formulaires')"
            )->fetchColumn() >= 3,
            'chaque ligne et le resume d import doivent etre journalises'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    // Les modeles telechargeables doivent etre effectivement generables.
    $templateCsv = $temp('risfm_modele_csv_');
    $templateXlsx = $temp('risfm_modele_xlsx_');
    $service->createCsvTemplate($templateCsv);
    $service->createXlsxTemplate($templateXlsx);
    $assert(filesize($templateCsv) > 50 && filesize($templateXlsx) > 1000, 'les modeles CSV et XLSX doivent etre generes');
} catch (Throwable $e) {
    $failures[] = 'exception inattendue : ' . $e->getMessage();
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    foreach ($temporaryFiles as $path) {
        @unlink($path);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "IMPORT ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "IMPORT OK: CSV/XLSX, aperçu, atomicité, doublons, historique et modèles vérifiés.\n";
