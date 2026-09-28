<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Exports complets du registre des formulaires manquants.
 *
 * Aucun format n'applique de limite silencieuse. Les donnees sont lues par
 * lots sur un instantane coherent de la base ; le CSV est ecrit en flux dans
 * un fichier temporaire avant son envoi au navigateur.
 */
class ExportController extends Controller
{
    private const BATCH_SIZE = 500;

    /** Les neuf colonnes officielles, dans leur ordre contractuel. */
    private const COLUMNS = [
        'N°',
        'Type de titre',
        'Annee',
        'Numero du formulaire',
        'Statut',
        'Localisation recherchee',
        'Responsable',
        'Date de recherche',
        'Resultat',
    ];

    /** Export multi-feuilles du tableau de pilotage, avec le meme perimetre que l'ecran. */
    public function statistiquesExcel(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('statistiques.view');
        Permission::requireOrFail('formulaires.export');

        if (!class_exists(Spreadsheet::class)) {
            setFlash('error', 'La bibliotheque PhpSpreadsheet est indisponible. Executez composer install.');
            $this->redirect('statistiques');
        }

        @set_time_limit(0);
        ignore_user_abort(true);
        $path = '';
        $spreadsheet = null;
        $db = Database::getConnection();
        $startedTransaction = !$db->inTransaction();

        try {
            if (!Security::ensureDirectory(STORAGE_PATH . '/tmp')) {
                throw new RuntimeException('Le dossier temporaire des exports est indisponible.');
            }

            $normalized = FormulaireModel::normaliserFiltresStatistiques($_GET);
            if ($normalized['error'] !== null) {
                throw new DomainException($normalized['error']);
            }
            $filters = $normalized['filters'];

            if ($startedTransaction) {
                $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $db->beginTransaction();
            }
            $model = new FormulaireModel();
            $statistics = $model->statistiquesDetaillees($filters);
            $options = $model->optionsStatistiques();
            if ($startedTransaction) {
                $db->commit();
            }

            $path = $this->temporaryFile('xlsx');
            $spreadsheet = new Spreadsheet();
            $spreadsheet->getProperties()
                ->setCreator(appName())
                ->setTitle('Statistiques du registre des formulaires manquants')
                ->setSubject('Tableau de pilotage OIPI')
                ->setDescription('Statistiques filtrees generees par ' . appName());

            $this->buildStatisticsSummarySheet(
                $spreadsheet,
                $statistics,
                $this->statisticsFilterLabels($filters, $options)
            );
            $this->addStatisticsDataSheet(
                $spreadsheet,
                'Par statut',
                ['Statut actuel', 'Nombre de formulaires', 'Part du registre (%)'],
                array_map(static function (array $row) use ($statistics): array {
                    $total = (int) ($statistics['kpi']['total_formulaires'] ?? 0);
                    return [
                        (string) $row['statut'],
                        (int) $row['total_formulaires'],
                        pct((int) $row['total_formulaires'], $total),
                    ];
                }, $statistics['par_statut']),
                [32, 24, 24]
            );
            $this->addStatisticsDataSheet(
                $spreadsheet,
                'Par type de titre',
                ['Type de titre', 'Total', 'Resolus', 'A traiter', 'Taux de resolution (%)'],
                array_map(static fn (array $row): array => [
                    (string) $row['type_titre'],
                    (int) $row['total_formulaires'],
                    (int) $row['total_resolus'],
                    (int) $row['total_restants'],
                    (float) $row['taux_resolution'],
                ], $statistics['par_type']),
                [34, 14, 14, 14, 25]
            );
            $this->addStatisticsDataSheet(
                $spreadsheet,
                'Par annee du titre',
                ['Annee du titre', 'Total', 'Resolus', 'A traiter', 'Taux de resolution (%)'],
                array_map(static fn (array $row): array => [
                    (int) $row['annee'],
                    (int) $row['total_formulaires'],
                    (int) $row['total_resolus'],
                    (int) $row['total_restants'],
                    (float) $row['taux_resolution'],
                ], $statistics['par_annee']),
                [20, 14, 14, 14, 25]
            );
            $this->addStatisticsDataSheet(
                $spreadsheet,
                'Progression mensuelle',
                ['Mois de premiere resolution', 'Premieres resolutions'],
                array_map(static fn (array $row): array => [
                    (string) $row['mois'],
                    (int) $row['resolutions_dans_le_mois'],
                ], $statistics['mensuelles']),
                [30, 24]
            );

            $spreadsheet->setActiveSheetIndex(0);
            $writer = new Xlsx($spreadsheet);
            $writer->setUseDiskCaching(true, STORAGE_PATH . '/tmp');
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();
            $spreadsheet = null;

            $total = (int) ($statistics['kpi']['total_formulaires'] ?? 0);
            if (!Logger::log(
                Auth::id(),
                'export',
                "Export Excel des statistiques du registre : {$total} formulaire(s) dans le perimetre",
                null,
                'statistiques',
                null,
                null,
                [
                    'format' => 'Excel',
                    'formulaires_dans_perimetre' => $total,
                    'filtres' => array_filter($filters, static fn ($value): bool => $value !== '' && $value !== 0),
                ]
            )) {
                throw new RuntimeException('L’export ne peut pas etre livre sans sa trace d’audit.');
            }

            $this->sendTemporaryFile(
                $path,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'statistiques_risfm_' . date('Ymd_His') . '.xlsx',
                $total,
                $total,
                false
            );
        } catch (Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
            error_log('[Export statistiques Excel] ' . $e->getMessage());
            setFlash('error', $e instanceof DomainException
                ? $e->getMessage()
                : 'L’export Excel des statistiques n’a pas pu etre genere.');
            $this->redirect('statistiques');
        }
    }

    public function formulairesCsv(): void
    {
        $context = [];
        $path = '';
        $out = null;

        try {
            $context = $this->openExportContext();
            $path = $this->temporaryFile('csv');
            $out = fopen($path, 'wb');
            if ($out === false) {
                throw new RuntimeException('Impossible de creer le fichier CSV temporaire.');
            }

            fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($out, self::COLUMNS, ';');

            $generated = 0;
            foreach ($this->exportRows($context) as $row) {
                fputcsv($out, $this->protectCsvRow($row), ';');
                $generated++;
            }

            $partial = $generated !== $context['expected'];
            if ($partial) {
                fputcsv($out, [
                    'ATTENTION : EXPORT PARTIEL', '', '', '', '', '', '', '',
                    "{$generated} ligne(s) generee(s) sur {$context['expected']} attendue(s)",
                ], ';');
            }
            fclose($out);
            $out = null;

            $this->commitExportContext($context);
            $this->logExport('CSV', $generated, $context['expected'], $partial, $context['filters']);
            $this->sendTemporaryFile(
                $path,
                'text/csv; charset=utf-8',
                $this->filename('csv', $generated, $partial),
                $generated,
                $context['expected'],
                $partial
            );
        } catch (Throwable $e) {
            if (is_resource($out)) {
                fclose($out);
            }
            $this->failExport($context, [$path], 'CSV', $e);
        }
    }

    public function formulairesExcel(): void
    {
        if (!class_exists(Spreadsheet::class)) {
            $this->missingLibrary('PhpSpreadsheet (phpoffice/phpspreadsheet)');
            return;
        }

        $context = [];
        $path = '';
        $spreadsheet = null;

        try {
            $context = $this->openExportContext();
            $path = $this->temporaryFile('xlsx');
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Formulaires manquants');
            $primaryRgb = ltrim(appColor('couleur_primaire', '#F68B1F'), '#');

            $sheet->mergeCells('A1:I1');
            $sheet->setCellValue('A1', 'OIPI , Registre des formulaires manquants');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($primaryRgb);
            $sheet->mergeCells('A2:I2');

            $headerRow = 4;
            foreach (self::COLUMNS as $index => $column) {
                $sheet->setCellValueExplicitByColumnAndRow($index + 1, $headerRow, $column, DataType::TYPE_STRING);
            }
            $sheet->getStyle("A{$headerRow}:I{$headerRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$headerRow}:I{$headerRow}")->getFill()
                ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($primaryRgb);
            $sheet->getStyle("A{$headerRow}:I{$headerRow}")->getFont()->getColor()->setRGB('000000');

            $generated = 0;
            $sheetRow = $headerRow + 1;
            foreach ($this->exportRows($context) as $row) {
                foreach ($row as $columnIndex => $value) {
                    if ($columnIndex === 0 || $columnIndex === 2) {
                        $sheet->setCellValueByColumnAndRow($columnIndex + 1, $sheetRow, (int) $value);
                    } else {
                        // TYPE_STRING empeche qu'un numero ou resultat commencant
                        // par "=" soit interprete comme une formule Excel.
                        $sheet->setCellValueExplicitByColumnAndRow(
                            $columnIndex + 1,
                            $sheetRow,
                            (string) $value,
                            DataType::TYPE_STRING
                        );
                    }
                }
                $sheetRow++;
                $generated++;
            }

            // Toutes les lignes de l'instantane ont ete lues : on libere la
            // transaction avant la phase plus couteuse de compression XLSX.
            $this->commitExportContext($context);

            $partial = $generated !== $context['expected'];
            $sheet->setCellValue('A2', $this->summary($generated, $context['expected'], $partial));
            $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB($partial ? 'C00000' : '666666');
            $sheet->setAutoFilter("A{$headerRow}:I{$headerRow}");
            $sheet->freezePane('A' . ($headerRow + 1));

            $widths = ['A' => 8, 'B' => 22, 'C' => 10, 'D' => 24, 'E' => 18, 'F' => 26, 'G' => 26, 'H' => 18, 'I' => 42];
            foreach ($widths as $column => $width) {
                $sheet->getColumnDimension($column)->setWidth($width);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setUseDiskCaching(true, STORAGE_PATH . '/tmp');
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();
            $spreadsheet = null;

            $this->logExport('Excel', $generated, $context['expected'], $partial, $context['filters']);
            $this->sendTemporaryFile(
                $path,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $this->filename('xlsx', $generated, $partial),
                $generated,
                $context['expected'],
                $partial
            );
        } catch (Throwable $e) {
            if ($spreadsheet instanceof Spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            }
            $this->failExport($context, [$path], 'Excel', $e);
        }
    }

    public function formulairesPdf(): void
    {
        if (!class_exists(Dompdf::class)) {
            $this->missingLibrary('Dompdf (dompdf/dompdf)');
            return;
        }

        $context = [];
        $htmlPath = '';
        $html = null;

        try {
            $context = $this->openExportContext();
            $htmlPath = $this->temporaryFile('html');
            $html = fopen($htmlPath, 'wb');
            if ($html === false) {
                throw new RuntimeException('Impossible de creer le document PDF temporaire.');
            }

            $primaryColor = appColor('couleur_primaire', '#F68B1F');
            fwrite($html, '<!doctype html><html><head><meta charset="UTF-8"><style>'
                . '@page{margin:22px}body{font-family:"DejaVu Sans",sans-serif;color:#111}'
                . 'table{border-collapse:collapse;width:100%;font-size:8px}th,td{border:1px solid #bbb;padding:4px}'
                . 'th{background:' . $primaryColor . ';color:#000;text-align:left}tr:nth-child(even){background:#f4f6f9}'
                . '.meta{font-size:10px;color:#666}.warning{font-weight:bold;color:#c00000}'
                . '</style></head><body>');
            fwrite($html, '<h3 style="color:' . $primaryColor . '">OIPI , Registre des formulaires manquants</h3>');
            fwrite($html, '<p class="meta">Genere le ' . date('d/m/Y H:i') . ' , '
                . $context['expected'] . ' formulaire(s) attendu(s)</p><table><thead><tr>');
            foreach (self::COLUMNS as $column) {
                fwrite($html, '<th>' . $this->html($column) . '</th>');
            }
            fwrite($html, '</tr></thead><tbody>');

            $generated = 0;
            foreach ($this->exportRows($context) as $row) {
                fwrite($html, '<tr>');
                foreach ($row as $cell) {
                    fwrite($html, '<td>' . $this->html((string) $cell) . '</td>');
                }
                fwrite($html, '</tr>');
                $generated++;
            }
            fwrite($html, '</tbody></table>');

            $partial = $generated !== $context['expected'];
            fwrite($html, '<p class="' . ($partial ? 'warning' : 'meta') . '">'
                . $this->html($this->summary($generated, $context['expected'], $partial)) . '</p></body></html>');
            fclose($html);
            $html = null;

            // Le rendu Dompdf ne doit pas conserver inutilement l'instantane
            // MySQL ouvert une fois toutes les lignes ecrites dans le HTML.
            $this->commitExportContext($context);

            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->setChroot([BASE_PATH]);
            $dompdf = new Dompdf($options);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->loadHtmlFile($htmlPath, 'UTF-8');
            $dompdf->render();
            @unlink($htmlPath);

            $this->logExport('PDF', $generated, $context['expected'], $partial, $context['filters']);
            $this->sendCountHeaders($generated, $context['expected'], $partial);
            $dompdf->stream($this->filename('pdf', $generated, $partial), ['Attachment' => true]);
            exit;
        } catch (Throwable $e) {
            if (is_resource($html)) {
                fclose($html);
            }
            $this->failExport($context, [$htmlPath], 'PDF', $e);
        }
    }

    public function formulairesWord(): void
    {
        if (!class_exists(PhpWord::class)) {
            $this->missingLibrary('PHPWord (phpoffice/phpword)');
            return;
        }

        $context = [];
        $path = '';

        try {
            $context = $this->openExportContext();
            $path = $this->temporaryFile('docx');
            $phpWord = new PhpWord();
            $section = $phpWord->addSection([
                'orientation' => 'landscape',
                'marginTop' => 700,
                'marginBottom' => 700,
                'marginLeft' => 700,
                'marginRight' => 700,
            ]);
            $primaryRgb = ltrim(appColor('couleur_primaire', '#F68B1F'), '#');

            $section->addText('OIPI , Registre des formulaires manquants', [
                'bold' => true, 'size' => 16, 'color' => $primaryRgb,
            ]);
            $section->addText(
                'Genere le ' . date('d/m/Y H:i') . ' , ' . $context['expected'] . ' formulaire(s) attendu(s)',
                ['size' => 9, 'color' => '666666']
            );
            $section->addTextBreak(1);

            $table = $section->addTable([
                'borderSize' => 4, 'borderColor' => '999999', 'cellMargin' => 45,
            ]);
            $table->addRow();
            foreach (self::COLUMNS as $column) {
                $table->addCell(1500, ['bgColor' => $primaryRgb])
                    ->addText($column, ['bold' => true, 'color' => '000000', 'size' => 8]);
            }

            $generated = 0;
            foreach ($this->exportRows($context) as $row) {
                $table->addRow();
                foreach ($row as $value) {
                    $table->addCell(1500)->addText((string) $value, ['size' => 7]);
                }
                $generated++;
            }

            // PHPWord peut prendre du temps a compresser un document massif ;
            // la lecture coherente de la base est deja terminee a ce stade.
            $this->commitExportContext($context);

            $partial = $generated !== $context['expected'];
            $section->addTextBreak(1);
            $section->addText(
                $this->summary($generated, $context['expected'], $partial),
                ['size' => 9, 'bold' => $partial, 'color' => $partial ? 'C00000' : '666666']
            );

            $writer = WordIOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($path);

            $this->logExport('Word', $generated, $context['expected'], $partial, $context['filters']);
            $this->sendTemporaryFile(
                $path,
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $this->filename('docx', $generated, $partial),
                $generated,
                $context['expected'],
                $partial
            );
        } catch (Throwable $e) {
            $this->failExport($context, [$path], 'Word', $e);
        }
    }

    /**
     * @return array{db:PDO,transaction:bool,model:FormulaireModel,filters:array,current_user:array,expected:int}
     */
    private function openExportContext(): array
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.export');

        @set_time_limit(0);
        ignore_user_abort(true);
        if (!Security::ensureDirectory(STORAGE_PATH . '/tmp')) {
            throw new RuntimeException('Le dossier temporaire des exports est indisponible.');
        }

        $filters = [
            'annee' => $this->input('annee'),
            'type_titre_id' => $this->input('type_titre_id'),
            'statut_id' => $this->input('statut_id'),
            'responsable_id' => $this->input('responsable_id'),
            'numero_formulaire' => $this->input('numero_formulaire'),
            'mot_cle' => $this->input('mot_cle'),
            'date_debut' => $this->input('date_debut'),
            'date_fin' => $this->input('date_fin'),
            'mes_dossiers' => $this->input('mes_dossiers'),
        ];
        $currentUser = ['id' => (int) Auth::id()];
        $db = Database::getConnection();
        $startedTransaction = !$db->inTransaction();
        if ($startedTransaction) {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->beginTransaction();
        }

        try {
            $model = new FormulaireModel();
            $expected = $model->searchCount($filters, $currentUser);
        } catch (Throwable $e) {
            if ($startedTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return [
            'db' => $db,
            'transaction' => $startedTransaction,
            'model' => $model,
            'filters' => $filters,
            'current_user' => $currentUser,
            'expected' => $expected,
        ];
    }

    /** @return Generator<int, array<int, int|string>> */
    private function exportRows(array $context): Generator
    {
        $number = 1;
        foreach ($context['model']->iterateForExport(
            $context['filters'],
            $context['current_user'],
            self::BATCH_SIZE
        ) as $form) {
            $missionsActives = (int) ($form['missions_actives_count'] ?? 0);
            yield [
                $number++,
                (string) $form['type_libelle'],
                (int) $form['annee'],
                (string) $form['numero_formulaire'],
                (string) $form['statut_libelle'],
                $missionsActives > 0
                    ? (string) ($form['localisations_actives'] ?? '-')
                    : (string) ($form['localisation_libelle'] ?? '-'),
                $missionsActives > 0
                    ? (string) ($form['responsables_actifs'] ?? 'Non assigne')
                    : (string) ($form['responsable_nom'] ?? 'Non assigne'),
                $missionsActives > 0 ? '-' : formatDate($form['date_recherche'] ?? null, 'd/m/Y'),
                $missionsActives > 0 ? "Recherche en cours ({$missionsActives} mission(s))" : (string) ($form['resultat'] ?: '-'),
            ];
        }
    }

    private function commitExportContext(array $context): void
    {
        if ($context['transaction'] && $context['db']->inTransaction()) {
            $context['db']->commit();
        }
    }

    private function logExport(
        string $format,
        int $generated,
        int $expected,
        bool $partial,
        array $filters
    ): void {
        $state = $partial ? 'partiel' : 'complet';
        if (!Logger::log(
            Auth::id(),
            'export',
            "Export {$format} {$state} du registre : {$generated} ligne(s) sur {$expected}",
            null,
            'registre',
            null,
            null,
            [
                'format' => $format,
                'lignes_exportees' => $generated,
                'lignes_attendues' => $expected,
                'partiel' => $partial,
                'filtres' => array_filter($filters, static fn ($value): bool => $value !== null && $value !== ''),
            ]
        )) {
            throw new RuntimeException('L’export ne peut pas etre livre sans sa trace d’audit.');
        }
    }

    /** @param array{kpi:array,par_statut:array,par_type:array,par_annee:array,mensuelles:array} $statistics */
    private function buildStatisticsSummarySheet(
        Spreadsheet $spreadsheet,
        array $statistics,
        array $filterLabels
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Synthese');
        $primaryRgb = strtoupper(ltrim(appColor('couleur_primaire', '#F68B1F'), '#'));
        $secondaryRgb = strtoupper(ltrim(appColor('couleur_secondaire', '#00A651'), '#'));
        $darkRgb = strtoupper(ltrim(appColor('couleur_accent', '#17352B'), '#'));

        $sheet->mergeCells('A1:F1');
        $sheet->setCellValueExplicit('A1', 'OIPI - Tableau de pilotage du registre des formulaires manquants', DataType::TYPE_STRING);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($darkRgb);
        $sheet->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4E8');
        $sheet->getStyle('A1:F1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(30);

        $sheet->mergeCells('A2:F2');
        $sheet->setCellValueExplicit('A2', 'Genere le ' . date('d/m/Y H:i') . ' - Les chiffres respectent les filtres ci-dessous.', DataType::TYPE_STRING);
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('607269');

        $sheet->mergeCells('A4:F4');
        $sheet->setCellValueExplicit('A4', 'PERIMETRE DE L’ANALYSE', DataType::TYPE_STRING);
        $sheet->getStyle('A4:F4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('A4:F4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($darkRgb);

        $row = 5;
        if ($filterLabels === []) {
            $filterLabels = [['Perimetre', 'Tous les formulaires non archives']];
        }
        foreach ($filterLabels as [$label, $value]) {
            $sheet->setCellValueExplicit("A{$row}", (string) $label, DataType::TYPE_STRING);
            $sheet->mergeCells("B{$row}:F{$row}");
            $sheet->setCellValueExplicit("B{$row}", (string) $value, DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;
        }

        $kpi = $statistics['kpi'];
        $total = (int) ($kpi['total_formulaires'] ?? 0);
        $kpis = [
            ['Total formulaires', $total, $darkRgb],
            ['A retrouver', (int) ($kpi['total_restants'] ?? 0), $primaryRgb],
            ['Retrouves', (int) ($kpi['total_retrouves'] ?? 0), $secondaryRgb],
            ['Numerises', (int) ($kpi['total_numerises'] ?? 0), '17A2B8'],
            ['Saisis', (int) ($kpi['total_saisis'] ?? 0), $secondaryRgb],
            ['Taux de resolution', pct((int) ($kpi['total_resolus'] ?? 0), $total) . ' %', $darkRgb],
        ];

        $row += 2;
        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValueExplicit("A{$row}", 'INDICATEURS CLES', DataType::TYPE_STRING);
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($secondaryRgb);
        $row++;

        foreach (array_chunk($kpis, 3) as $kpiRow) {
            foreach ($kpiRow as $index => [$label, $value, $color]) {
                $startColumn = 1 + ($index * 2);
                $endColumn = $startColumn + 1;
                $start = Coordinate::stringFromColumnIndex($startColumn);
                $end = Coordinate::stringFromColumnIndex($endColumn);
                $sheet->mergeCells("{$start}{$row}:{$end}{$row}");
                $sheet->setCellValueExplicit("{$start}{$row}", (string) $label, DataType::TYPE_STRING);
                $sheet->getStyle("{$start}{$row}:{$end}{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $sheet->getStyle("{$start}{$row}:{$end}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
                $sheet->getStyle("{$start}{$row}:{$end}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->mergeCells("{$start}" . ($row + 1) . ":{$end}" . ($row + 1));
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValue("{$start}" . ($row + 1), $value);
                } else {
                    $sheet->setCellValueExplicit("{$start}" . ($row + 1), (string) $value, DataType::TYPE_STRING);
                }
                $sheet->getStyle("{$start}" . ($row + 1) . ":{$end}" . ($row + 1))->getFont()->setBold(true)->setSize(18)->getColor()->setRGB($darkRgb);
                $sheet->getStyle("{$start}" . ($row + 1) . ":{$end}" . ($row + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            $sheet->getRowDimension($row + 1)->setRowHeight(28);
            $row += 3;
        }

        $sheet->mergeCells("A{$row}:F{$row}");
        $sheet->setCellValueExplicit("A{$row}", 'PARCOURS DE FINALISATION (CHIFFRES CUMULATIFS)', DataType::TYPE_STRING);
        $sheet->getStyle("A{$row}:F{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A{$row}:F{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($darkRgb);
        $row++;
        $this->writeStatisticsRow($sheet, $row, ['Etape', 'Nombre', 'Part du registre (%)']);
        $sheet->getStyle("A{$row}:C{$row}")->getFont()->setBold(true);
        $sheet->getStyle("A{$row}:C{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF3F0');
        foreach ([
            ['Retrouves', (int) ($kpi['total_retrouves'] ?? 0)],
            ['Numerises', (int) ($kpi['total_numerises'] ?? 0)],
            ['Saisis', (int) ($kpi['total_saisis'] ?? 0)],
        ] as [$label, $value]) {
            $row++;
            $this->writeStatisticsRow($sheet, $row, [$label, $value, pct($value, $total)]);
        }

        $sheet->getStyle("A4:F{$row}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DDE6E1');
        foreach (['A' => 23, 'B' => 18, 'C' => 23, 'D' => 18, 'E' => 23, 'F' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->freezePane('A4');
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);
    }

    /** @param array<int,array<int,int|float|string>> $rows */
    private function addStatisticsDataSheet(
        Spreadsheet $spreadsheet,
        string $title,
        array $headers,
        array $rows,
        array $widths
    ): void {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $primaryRgb = strtoupper(ltrim(appColor('couleur_primaire', '#F68B1F'), '#'));
        $darkRgb = strtoupper(ltrim(appColor('couleur_accent', '#17352B'), '#'));
        $this->writeStatisticsRow($sheet, 1, $headers);
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($darkRgb);
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(24);

        $rowNumber = 2;
        foreach ($rows as $row) {
            $this->writeStatisticsRow($sheet, $rowNumber, $row);
            if ($rowNumber % 2 === 0) {
                $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7FAF8');
            }
            $rowNumber++;
        }
        if ($rows === []) {
            $sheet->mergeCells("A2:{$lastColumn}2");
            $sheet->setCellValueExplicit('A2', 'Aucune donnee dans ce perimetre.', DataType::TYPE_STRING);
            $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('607269');
            $rowNumber = 3;
        }

        $lastDataRow = max(1, $rowNumber - 1);
        $sheet->getStyle("A1:{$lastColumn}{$lastDataRow}")->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('DDE6E1');
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }
        if ($rows !== []) {
            $sheet->setAutoFilter("A1:{$lastColumn}1");
        }
        $sheet->freezePane('A2');
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);
        $sheet->getStyle("A2:{$lastColumn}{$lastDataRow}")->getFont()->getColor()->setRGB($darkRgb);
        $sheet->getStyle("A1:{$lastColumn}1")->getFill()->getStartColor()->setRGB($primaryRgb);
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->getColor()->setRGB('000000');
    }

    /** @param array<int,int|float|string> $values */
    private function writeStatisticsRow(object $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $column = $index + 1;
            if (is_int($value) || is_float($value)) {
                $sheet->setCellValueByColumnAndRow($column, $row, $value);
            } else {
                $sheet->setCellValueExplicitByColumnAndRow($column, $row, (string) $value, DataType::TYPE_STRING);
            }
        }
    }

    /** @return array<int,array{0:string,1:string}> */
    private function statisticsFilterLabels(array $filters, array $options): array
    {
        $findLabel = static function (array $rows, int $id, string $labelKey = 'libelle'): string {
            foreach ($rows as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    if ($labelKey === 'responsable') {
                        return trim((string) ($row['nom'] ?? '') . ' ' . (string) ($row['prenoms'] ?? ''));
                    }
                    return (string) ($row[$labelKey] ?? '');
                }
            }
            return 'Valeur #' . $id;
        };

        $labels = [];
        if ((int) $filters['annee'] > 0) {
            $labels[] = ['Annee du titre', (string) $filters['annee']];
        }
        if ((int) $filters['type_titre_id'] > 0) {
            $labels[] = ['Type de titre', $findLabel($options['types'], (int) $filters['type_titre_id'])];
        }
        if ((int) $filters['statut_id'] > 0) {
            $labels[] = ['Statut actuel', $findLabel($options['statuts'], (int) $filters['statut_id'])];
        }
        if ((string) $filters['priorite'] !== '') {
            $labels[] = ['Priorite', (string) $filters['priorite']];
        }
        if ((int) $filters['responsable_id'] > 0) {
            $labels[] = ['Responsable intervenu', $findLabel($options['responsables'], (int) $filters['responsable_id'], 'responsable')];
        }
        if ((int) $filters['localisation_id'] > 0) {
            $labels[] = ['Localisation inspectee', $findLabel($options['localisations'], (int) $filters['localisation_id'])];
        }
        if ((string) $filters['date_debut'] !== '') {
            $labels[] = ['Ajoute au registre du', formatDate((string) $filters['date_debut'], 'd/m/Y')];
        }
        if ((string) $filters['date_fin'] !== '') {
            $labels[] = ['Ajoute au registre au', formatDate((string) $filters['date_fin'], 'd/m/Y')];
        }
        return $labels;
    }

    private function temporaryFile(string $extension): string
    {
        $temporary = tempnam(STORAGE_PATH . '/tmp', 'risfm_export_');
        if ($temporary === false) {
            throw new RuntimeException('Impossible de reserver un fichier temporaire pour l’export.');
        }
        $path = $temporary . '.' . preg_replace('/[^a-z0-9]/i', '', $extension);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible de preparer le fichier temporaire de l’export.');
        }
        return $path;
    }

    private function sendTemporaryFile(
        string $path,
        string $contentType,
        string $filename,
        int $generated,
        int $expected,
        bool $partial
    ): never {
        if (!is_file($path)) {
            throw new RuntimeException('Le fichier d’export genere est introuvable.');
        }

        register_shutdown_function(static function () use ($path): void {
            if (is_file($path)) {
                @unlink($path);
            }
        });
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        $this->sendCountHeaders($generated, $expected, $partial);
        readfile($path);
        @unlink($path);
        exit;
    }

    private function sendCountHeaders(int $generated, int $expected, bool $partial): void
    {
        header('X-RISFM-Export-Row-Count: ' . $generated);
        header('X-RISFM-Export-Expected-Count: ' . $expected);
        header('X-RISFM-Export-Partial: ' . ($partial ? '1' : '0'));
    }

    private function filename(string $extension, int $generated, bool $partial): string
    {
        return ($partial ? 'EXPORT_PARTIEL_' : '')
            . 'formulaires_manquants_' . $generated . '_lignes_' . date('Ymd_His') . '.' . $extension;
    }

    private function summary(int $generated, int $expected, bool $partial): string
    {
        return $partial
            ? "ATTENTION , EXPORT PARTIEL : {$generated} ligne(s) generee(s) sur {$expected} attendue(s)."
            : "Export complet : {$generated} formulaire(s).";
    }

    /** @param array<int, int|string> $row */
    private function protectCsvRow(array $row): array
    {
        foreach ($row as $index => $value) {
            if (!is_string($value) || $value === '-' || $value === '') {
                continue;
            }
            $trimmed = ltrim($value);
            if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
                $row[$index] = "'" . $value;
            }
        }
        return $row;
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<int, string> $paths */
    private function failExport(array $context, array $paths, string $format, Throwable $exception): never
    {
        if (($context['transaction'] ?? false)
            && isset($context['db'])
            && $context['db'] instanceof PDO
            && $context['db']->inTransaction()
        ) {
            $context['db']->rollBack();
        }
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        error_log("[Export {$format}] " . $exception->getMessage());
        setFlash('error', "L’export {$format} n’a pas pu etre genere. Aucun fichier partiel n’a ete livre.");
        $this->redirect('formulaires#exports');
    }

    private function missingLibrary(string $library): never
    {
        setFlash(
            'error',
            "La bibliotheque {$library} n’est pas installee. Executez composer install a la racine du projet."
        );
        $this->redirect('formulaires#exports');
    }
}
