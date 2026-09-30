<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Services\Formulaire\FormulaireImportService;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
require_once BASE_PATH . '/app/Core/helpers.php';


$db = Database::getConnection();
$type = $db->query('SELECT * FROM types_titres WHERE actif = 1 ORDER BY ordre, id LIMIT 1')->fetch();
$actorId = (int) ($db->query(
    "SELECT id FROM utilisateurs WHERE actif = 1 AND role = 'administrateur' ORDER BY id LIMIT 1"
)->fetchColumn() ?: 0);

if (!$type || $actorId < 1) {
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
$writeCsv = static function (array $header, array $rows) use ($temp): string {
    $path = $temp('risfm_import_csv_');
    $handle = fopen($path, 'wb');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $header, ';');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ';');
    }
    fclose($handle);
    return $path;
};
$allErrors = static fn (array $analysis): string => implode(' ', array_merge(...array_map(
    static fn (array $row): array => $row['errors'],
    $analysis['rows']
)));
$service = new FormulaireImportService();
$suffix = strtoupper(bin2hex(random_bytes(4)));
$minimalNumber = 'IMPORT-MIN-' . $suffix;
$urgentNumber = 'IMPORT-URG-' . $suffix;
$badNumber = 'IMPORT-BAD-' . $suffix;

try {
    $assert(
        FormulaireImportService::TEMPLATE_COLUMNS === ['Type de titre', 'Annee', 'Numero du formulaire', 'Priorite'],
        'le modele doit se limiter aux quatre colonnes de la saisie manuelle'
    );

    // CSV : ligne valide, referentiel inconnu, formule, priorite invalide et
    // blocage de tout import partiel.
    $badCsv = $writeCsv(FormulaireImportService::TEMPLATE_COLUMNS, [
        [(string) $type['code'], date('Y'), $minimalNumber, 'Normale'],
        ['TYPE INCONNU', date('Y'), $badNumber, 'Normale'],
        [(string) $type['code'], date('Y'), '=2+2', 'Normale'],
        [(string) $type['code'], date('Y'), $badNumber . '-P', 'Extreme'],
    ]);
    $inspection = $service->srv_inspectFile([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $badCsv,
        'size' => filesize($badCsv),
        'name' => 'registre.csv',
    ]);
    $badAnalysis = $service->srv_analyse($badCsv, $inspection['extension']);
    $assert(
        $badAnalysis['total'] === 4
            && $badAnalysis['valid_count'] === 1
            && $badAnalysis['error_count'] === 3,
        'le CSV doit distinguer les lignes valides des referentiels inconnus, formules et priorites invalides'
    );
    $assert(str_contains($allErrors($badAnalysis), 'formules Excel'), 'les formules doivent etre refusees avant l import');
    $assert(str_contains($allErrors($badAnalysis), 'Priorité invalide'), 'une priorite hors liste doit etre refusee');
    $blocked = false;
    try {
        $service->srv_import($badCsv, 'csv', $actorId);
    } catch (DomainException) {
        $blocked = true;
    }
    $assert($blocked, 'une ligne invalide doit bloquer tout import partiel');
    $assert(
        (new FormulaireRepository())->repo_count('numero_formulaire = :numero', ['numero' => $minimalNumber]) === 0,
        'le fichier invalide ne doit creer aucun formulaire'
    );

    // Les colonnes de l'ancien modele de reprise sont refusees, pas ignorees.
    $legacyCsv = $writeCsv(
        ['Type de titre', 'Annee', 'Numero du formulaire', 'Statut', 'Deposant', 'Priorite'],
        [[(string) $type['code'], date('Y'), $minimalNumber, 'retrouve', 'Ancien deposant', 'Normale']]
    );
    try {
        $service->srv_analyse($legacyCsv, 'csv');
        $assert(false, 'un fichier avec les anciennes colonnes doit etre refuse');
    } catch (InvalidArgumentException $e) {
        $assert(
            str_contains($e->getMessage(), 'Statut') && str_contains($e->getMessage(), 'Deposant'),
            'le refus doit nommer les colonnes non prises en charge'
        );
    }

    // XLSX : en-tete en ligne 4, libelle du type, priorite vide et urgente.
    $xlsxPath = $temp('risfm_import_ok_');
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'OIPI , Formulaires manquants');
    foreach (FormulaireImportService::TEMPLATE_COLUMNS as $column => $label) {
        $sheet->setCellValueExplicitByColumnAndRow($column + 1, 4, $label, DataType::TYPE_STRING);
    }
    $rows = [
        [(string) $type['libelle'], date('Y'), $minimalNumber, ''],
        [(string) $type['code'], date('Y'), $urgentNumber, 'urgente'],
    ];
    foreach ($rows as $rowOffset => $values) {
        foreach ($values as $column => $value) {
            $sheet->setCellValueExplicitByColumnAndRow($column + 1, 5 + $rowOffset, (string) $value, DataType::TYPE_STRING);
        }
    }
    (new Xlsx($spreadsheet))->save($xlsxPath);
    $spreadsheet->disconnectWorksheets();

    $xlsxInspection = $service->srv_inspectFile([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $xlsxPath,
        'size' => filesize($xlsxPath),
        'name' => 'registre.xlsx',
    ]);
    $analysis = $service->srv_analyse($xlsxPath, $xlsxInspection['extension']);
    $assert(
        $analysis['header_row'] === 4 && $analysis['total'] === 2 && $analysis['error_count'] === 0,
        'le XLSX doit reconnaitre l en-tete et valider les deux lignes'
    );

    $db->beginTransaction();
    try {
        $result = $service->srv_import($xlsxPath, 'xlsx', $actorId);
        $assert($result['imported'] === 2, 'les deux lignes valides doivent etre importees atomiquement');
        $imported = $db->query(
            "SELECT f.id, f.numero_formulaire, f.priorite, s.code AS statut, f.localisation_id, f.responsable_id
             FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
             WHERE f.numero_formulaire IN (" . $db->quote($minimalNumber) . ', ' . $db->quote($urgentNumber) . ')'
        )->fetchAll();
        $byNumber = array_column($imported, null, 'numero_formulaire');
        $assert(count($imported) === 2, 'les deux dossiers importes doivent exister dans la transaction');
        $assert(
            ($byNumber[$minimalNumber]['priorite'] ?? null) === 'Normale'
                && ($byNumber[$urgentNumber]['priorite'] ?? null) === 'Urgente',
            'priorite vide = Normale, priorite reconnue sans tenir compte de la casse'
        );
        $ids = implode(',', array_map('intval', array_column($imported, 'id'))) ?: '0';
        $assert(
            array_unique(array_column($imported, 'statut')) === ['introuvable']
                && array_filter(array_column($imported, 'localisation_id')) === []
                && array_filter(array_column($imported, 'responsable_id')) === [],
            'un dossier importe est cree comme une saisie manuelle : Introuvable, sans localisation ni responsable'
        );
        $assert(
            (int) $db->query("SELECT COUNT(*) FROM missions_recherche WHERE formulaire_id IN ({$ids})")->fetchColumn() === 0
                && (int) $db->query("SELECT COUNT(*) FROM recherches_formulaire WHERE formulaire_id IN ({$ids})")->fetchColumn() === 0
                && (int) $db->query("SELECT COUNT(*) FROM finalisations_formulaire WHERE formulaire_id IN ({$ids})")->fetchColumn() === 0,
            'l import ne cree ni mission, ni historique, ni finalisation'
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
    $service->srv_createCsvTemplate($templateCsv);
    $service->srv_createXlsxTemplate($templateXlsx);
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

echo "IMPORT OK: CSV/XLSX, quatre colonnes, anciennes colonnes refusees, atomicite, statut initial et modeles verifies.\n";
