<?php
declare(strict_types=1);

namespace App\Services\Formulaire;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Security;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Referentiel\StatutRepository;
use App\Repositories\Referentiel\TypeTitreRepository;
use DateTimeInterface;
use DomainException;
use finfo;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use RuntimeException;
use ZipArchive;

/**
 * Analyse et importe en masse des formulaires manquants (CSV/XLSX).
 *
 * Chaque ligne cree le meme dossier qu'une saisie manuelle : type, annee,
 * numero et priorite, au statut Introuvable et sans mission. Une analyse sans
 * erreur est obligatoire avant l'ecriture. L'import est atomique : une
 * collision ou une erreur d'audit annule toutes les lignes.
 */
class FormulaireImportService
{
    public const MAX_ROWS = 5000;
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Colonnes reconnues. Les trois premieres sont obligatoires. */
    public const TEMPLATE_COLUMNS = [
        'Type de titre',
        'Annee',
        'Numero du formulaire',
        'Priorite',
    ];

    private const REQUIRED = ['type_titre', 'annee', 'numero_formulaire'];

    private const HEADER_ALIASES = [
        'n' => 'numero_ligne',
        'no' => 'numero_ligne',
        'numero' => 'numero_ligne',
        'type' => 'type_titre',
        'type_titre' => 'type_titre',
        'type_de_titre' => 'type_titre',
        'annee' => 'annee',
        'numero_formulaire' => 'numero_formulaire',
        'numero_du_formulaire' => 'numero_formulaire',
        'priorite' => 'priorite',
    ];

    /**
     * Colonnes de l'ancien modele de reprise historique. Elles sont refusees
     * plutot qu'ignorees pour qu'aucune donnee ne soit perdue sans le savoir.
     */
    private const RETIRED_HEADERS = [
        'statut' => 'Statut',
        'localisation' => 'Localisation recherchee',
        'localisation_recherchee' => 'Localisation recherchee',
        'responsable' => 'Responsable',
        'date_recherche' => 'Date de recherche',
        'date_de_recherche' => 'Date de recherche',
        'resultat' => 'Resultat',
        'date_depot' => 'Date du depot',
        'date_du_depot' => 'Date du depot',
        'deposant' => 'Deposant',
        'mandataire' => 'Mandataire',
        'observations' => 'Observations',
        'observation' => 'Observations',
    ];

    private const DISPLAY_COLUMNS = [
        'type_titre' => 'Type de titre',
        'annee' => 'Année',
        'numero_formulaire' => 'Numéro du formulaire',
        'priorite' => 'Priorité',
    ];

    /**
     * @return array{extension:string,mime:string,size:int,original_name:string}
     */
    public function srv_inspectFile(array $file): array
    {
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $path = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $originalName = Security::cleanString((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'xlsx'], true)) {
            throw new InvalidArgumentException('Le fichier doit être au format CSV ou Excel XLSX.');
        }
        if (!is_file($path) || $size < 1) {
            throw new InvalidArgumentException('Le fichier transmis est vide ou introuvable.');
        }
        if ($size > self::MAX_BYTES) {
            throw new InvalidArgumentException('Le fichier dépasse la limite de 10 Mo.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $mime = is_string($mime) ? $mime : '';
        if ($extension === 'xlsx') {
            $this->assertSafeXlsx($path);
        } else {
            $sample = file_get_contents($path, false, null, 0, min($size, 65536));
            if (!is_string($sample) || str_contains($sample, "\0")) {
                throw new InvalidArgumentException('Le fichier CSV contient des données binaires invalides.');
            }
        }

        return [
            'extension' => $extension,
            'mime' => $mime,
            'size' => $size,
            'original_name' => $originalName,
        ];
    }

    /**
     * @return array{
     *   total:int,valid_count:int,error_count:int,header_row:int,
     *   rows:array<int,array>,columns:array<int,string>
     * }
     */
    public function srv_analyse(string $path, string $extension): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException('Le fichier d’import temporaire est introuvable.');
        }
        $extension = strtolower($extension);
        $physicalRows = match ($extension) {
            'csv' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new InvalidArgumentException('Format d’import non pris en charge.'),
        };

        [$headerOffset, $columnMap] = $this->detectHeaders($physicalRows);
        $missing = array_values(array_diff(self::REQUIRED, array_values($columnMap)));
        if ($missing !== []) {
            $labels = array_map(fn (string $key): string => self::DISPLAY_COLUMNS[$key] ?? $key, $missing);
            throw new InvalidArgumentException('Colonnes obligatoires absentes : ' . implode(', ', $labels) . '.');
        }

        $references = $this->referenceMaps();
        $formRepository = new FormulaireRepository();
        $validator = new FormulaireMetierValidator();
        $seen = [];
        $analysed = [];

        for ($index = $headerOffset + 1, $count = count($physicalRows); $index < $count; $index++) {
            $physical = $physicalRows[$index];
            if ($this->rowIsEmpty($physical['values'])) {
                continue;
            }
            if (count($analysed) >= self::MAX_ROWS) {
                throw new InvalidArgumentException('Le fichier dépasse la limite de ' . self::MAX_ROWS . ' lignes de données.');
            }

            $raw = [];
            foreach ($columnMap as $columnIndex => $canonical) {
                if ($canonical === 'numero_ligne') {
                    continue;
                }
                $raw[$canonical] = $physical['values'][$columnIndex] ?? null;
            }
            $line = (int) $physical['line'];
            [$data, $display, $errors] = $this->normalizeRow(
                $raw,
                $extension,
                $references,
                $validator
            );

            if ($errors === [] && $data !== null) {
                $duplicateKey = $data['annee'] . '|' . $data['type_titre_id'] . '|'
                    . $this->normalizeKey($data['numero_formulaire']);
                if (isset($seen[$duplicateKey])) {
                    $errors[] = "Doublon dans le fichier avec la ligne {$seen[$duplicateKey]}.";
                } else {
                    $seen[$duplicateKey] = $line;
                }
                if ($formRepository->repo_duplicateExists(
                    (int) $data['annee'],
                    (int) $data['type_titre_id'],
                    (string) $data['numero_formulaire']
                )) {
                    $errors[] = 'Ce formulaire existe déjà dans le registre.';
                }
            }

            $analysed[] = [
                'line' => $line,
                'valid' => $errors === [],
                'errors' => $errors,
                'display' => $display,
                'data' => $data,
            ];
        }

        if ($analysed === []) {
            throw new InvalidArgumentException('Le fichier ne contient aucune ligne de données.');
        }

        $validCount = count(array_filter($analysed, static fn (array $row): bool => $row['valid']));
        return [
            'total' => count($analysed),
            'valid_count' => $validCount,
            'error_count' => count($analysed) - $validCount,
            'header_row' => (int) $physicalRows[$headerOffset]['line'],
            'rows' => $analysed,
            'columns' => array_values(self::DISPLAY_COLUMNS),
        ];
    }

    /**
     * Importe toutes les lignes si, et seulement si, l'analyse est sans erreur.
     *
     * @return array{imported:int,references:array<int,string>}
     */
    public function srv_import(string $path, string $extension, int $actorId): array
    {
        if ($actorId < 1) {
            throw new InvalidArgumentException('L’acteur de l’import est invalide.');
        }
        $analysis = $this->srv_analyse($path, $extension);
        if ($analysis['error_count'] > 0) {
            throw new DomainException(
                "L’import est bloqué : {$analysis['error_count']} ligne(s) doivent être corrigées."
            );
        }

        // Tout ou rien : dans une transaction deja ouverte, l'import pose un
        // point de reprise et n'annule que son propre travail en cas d'echec.
        $references = Database::transaction(function () use ($analysis, $actorId): array {
            $references = [];
            foreach ($analysis['rows'] as $row) {
                $data = $row['data'];
                if (!is_array($data)) {
                    throw new RuntimeException('Une ligne validée ne contient plus ses données normalisées.');
                }
                $formRepository = new FormulaireRepository();
                if ($formRepository->repo_duplicateExists(
                    (int) $data['annee'],
                    (int) $data['type_titre_id'],
                    (string) $data['numero_formulaire']
                )) {
                    throw new DomainException(
                        "La ligne {$row['line']} est devenue un doublon depuis l’aperçu. Aucun dossier n’a été importé."
                    );
                }

                $record = $data;
                $record['cree_par'] = $actorId;

                $created = $formRepository->repo_insertWithGeneratedNumero($record);
                $formId = (int) $created['id'];
                $reference = (string) $created['numero_auto'];
                $references[] = $reference;

                if (!Logger::log(
                    $actorId,
                    'import',
                    "Import du formulaire {$reference} depuis la ligne {$row['line']}",
                    null,
                    'formulaire',
                    $formId,
                    null,
                    [
                        'numero_auto' => $reference,
                        'annee' => (int) $data['annee'],
                        'numero_formulaire' => (string) $data['numero_formulaire'],
                        'type_titre_id' => (int) $data['type_titre_id'],
                        'statut_id' => (int) $data['statut_id'],
                        'priorite' => (string) $data['priorite'],
                        'source_ligne' => (int) $row['line'],
                    ]
                )) {
                    throw new RuntimeException('Une ligne importée ne peut pas être validée sans sa trace d’audit.');
                }
            }

            if (!Logger::log(
                $actorId,
                'import',
                count($references) . ' formulaire(s) importé(s) dans le registre',
                null,
                'import_formulaires',
                null,
                null,
                ['total' => count($references), 'references' => $references]
            )) {
                throw new RuntimeException('L’import ne peut pas être validé sans son résumé d’audit.');
            }

            return $references;
        });

        return ['imported' => count($references), 'references' => $references];
    }

    public function srv_createCsvTemplate(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de créer le modèle CSV.');
        }
        try {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::TEMPLATE_COLUMNS, ';');
        } finally {
            fclose($handle);
        }
    }

    public function srv_createXlsxTemplate(string $path): void
    {
        if (!class_exists(Spreadsheet::class)) {
            throw new RuntimeException('PhpSpreadsheet est indisponible.');
        }
        $spreadsheet = new Spreadsheet();
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Import');
            foreach (self::TEMPLATE_COLUMNS as $index => $label) {
                $sheet->setCellValueExplicitByColumnAndRow($index + 1, 1, $label, DataType::TYPE_STRING);
            }
            $lastColumn = Coordinate::stringFromColumnIndex(count(self::TEMPLATE_COLUMNS));
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
            $sheet->getStyle("A1:{$lastColumn}1")->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB(ltrim(appColor('couleur_primaire', '#F68B1F'), '#'));
            $sheet->setAutoFilter("A1:{$lastColumn}1");
            $sheet->freezePane('A2');
            foreach (range(1, count(self::TEMPLATE_COLUMNS)) as $column) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(24);
            }

            $instructions = $spreadsheet->createSheet();
            $instructions->setTitle('Instructions');
            $instructions->fromArray([
                ['IMPORT RISFM , CONSIGNES'],
                ['Les colonnes Type de titre, Annee et Numero du formulaire sont obligatoires.'],
                ['Type de titre : code ou libelle actif affiche dans RISFM.'],
                ['Priorite : Basse, Normale, Haute ou Urgente. Vide = Normale.'],
                ['Chaque formulaire est cree au statut Introuvable ; les missions se creent ensuite depuis sa fiche.'],
                ['L’import est atomique : une seule ligne invalide bloque tout le fichier.'],
                ['Maximum : ' . self::MAX_ROWS . ' lignes et 10 Mo.'],
            ]);
            $instructions->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $instructions->getColumnDimension('A')->setWidth(110);

            $writer = new XlsxWriter($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /** @return array<int,array{line:int,values:array<int,mixed>}> */
    private function readCsv(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Impossible de lire le fichier CSV.');
        }
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $encoding = mb_detect_encoding($contents, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding === false) {
            throw new InvalidArgumentException('L’encodage du fichier CSV n’est pas reconnu.');
        }
        if ($encoding !== 'UTF-8') {
            $contents = mb_convert_encoding($contents, 'UTF-8', $encoding);
        }

        $delimiter = $this->detectDelimiter($contents);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Impossible de préparer la lecture CSV.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        $rows = [];
        $line = 0;
        try {
            while (($values = fgetcsv($stream, 0, $delimiter)) !== false) {
                $line++;
                $rows[] = ['line' => $line, 'values' => $values];
                if (count($rows) > self::MAX_ROWS + 20) {
                    throw new InvalidArgumentException('Le fichier dépasse la limite de ' . self::MAX_ROWS . ' lignes.');
                }
            }
        } finally {
            fclose($stream);
        }
        return $rows;
    }

    /** @return array<int,array{line:int,values:array<int,mixed>}> */
    private function readXlsx(string $path): array
    {
        if (!class_exists(XlsxReader::class)) {
            throw new RuntimeException('PhpSpreadsheet est indisponible.');
        }
        $this->assertSafeXlsx($path);
        $reader = new XlsxReader();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class implements IReadFilter {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return (int) $row <= FormulaireImportService::MAX_ROWS + 20
                    && Coordinate::columnIndexFromString((string) $columnAddress) <= 30;
            }
        });
        $spreadsheet = $reader->load($path);
        try {
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = min($sheet->getHighestDataRow(), self::MAX_ROWS + 20);
            $highestColumn = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);
            $range = 'A1:' . Coordinate::stringFromColumnIndex(max(1, $highestColumn)) . $highestRow;
            $matrix = $sheet->rangeToArray($range, null, false, false, false);
            $rows = [];
            foreach ($matrix as $offset => $values) {
                $rows[] = ['line' => $offset + 1, 'values' => $values];
            }
            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param array<int,array{line:int,values:array<int,mixed>}> $rows
     * @return array{0:int,1:array<int,string>}
     */
    private function detectHeaders(array $rows): array
    {
        foreach (array_slice($rows, 0, 10, true) as $offset => $row) {
            $mapping = [];
            $retired = [];
            foreach ($row['values'] as $index => $value) {
                $key = $this->normalizeKey($this->scalarString($value));
                if (isset(self::RETIRED_HEADERS[$key])) {
                    $retired[] = self::RETIRED_HEADERS[$key];
                }
                if ($key !== '' && isset(self::HEADER_ALIASES[$key])) {
                    $canonical = self::HEADER_ALIASES[$key];
                    if (in_array($canonical, $mapping, true)) {
                        throw new InvalidArgumentException("La colonne « {$this->scalarString($value)} » est présente plusieurs fois.");
                    }
                    $mapping[(int) $index] = $canonical;
                }
            }
            if (array_diff(self::REQUIRED, array_values($mapping)) === []) {
                if ($retired !== []) {
                    throw new InvalidArgumentException(
                        'Colonnes non prises en charge : ' . implode(', ', array_unique($retired))
                        . '. Supprimez-les ou utilisez le modèle officiel (Type de titre, Année, Numéro du formulaire, Priorité).'
                    );
                }
                return [(int) $offset, $mapping];
            }
        }
        throw new InvalidArgumentException(
            'En-tête introuvable dans les 10 premières lignes. Utilisez le modèle officiel RISFM.'
        );
    }

    /**
     * @return array{0:?array,1:array<string,string>,2:array<int,string>}
     */
    private function normalizeRow(
        array $raw,
        string $extension,
        array $references,
        FormulaireMetierValidator $validator
    ): array {
        $errors = [];
        foreach ($raw as $key => $value) {
            if (str_starts_with(ltrim($this->scalarString($value)), '=')) {
                $errors[] = 'Les formules Excel sont interdites dans les données importées.';
                $raw[$key] = '';
            }
        }

        $typeText = Security::cleanString($this->scalarString($raw['type_titre'] ?? ''));
        $yearText = Security::cleanString($this->scalarString($raw['annee'] ?? ''));
        $number = Security::cleanString($this->scalarString($raw['numero_formulaire'] ?? ''));
        $priorityText = Security::cleanString($this->scalarString($raw['priorite'] ?? ''));

        $type = $this->resolveReference($typeText, $references['types'], 'Type de titre', $errors);
        $priority = $this->normalizePriority($priorityText, $errors);
        $status = $references['initial_status'];
        if ($status === null) {
            $errors[] = 'Le statut initial Introuvable est absent ou inactif.';
        }

        // Meme dossier qu'une saisie manuelle : aucune recherche ni affectation.
        $data = [
            'type_titre_id' => isset($type['id']) ? (string) $type['id'] : '',
            'annee' => $yearText,
            'numero_formulaire' => $number,
            'statut_id' => isset($status['id']) ? (string) $status['id'] : '',
            'localisation_id' => null,
            'responsable_id' => null,
            'date_recherche' => null,
            'resultat' => '',
            'observations' => '',
            'date_depot' => null,
            'deposant' => '',
            'mandataire' => '',
            'niveau_urgence' => 'Moyen',
            'priorite' => $priority,
        ];

        if ($errors === []) {
            $validationError = $validator->validateForm($data);
            if ($validationError !== null) {
                $errors[] = $validationError;
            }
        }

        if ($errors === []) {
            $data['type_titre_id'] = (int) $data['type_titre_id'];
            $data['annee'] = (int) $data['annee'];
            $data['statut_id'] = (int) $data['statut_id'];
        }

        $display = [
            'type_titre' => $typeText,
            'annee' => $yearText,
            'numero_formulaire' => $number,
            'priorite' => $priorityText !== '' ? $priorityText : 'Normale',
        ];
        return [$errors === [] ? $data : null, $display, array_values(array_unique($errors))];
    }

    private function referenceMaps(): array
    {
        $types = [];
        foreach ((new TypeTitreRepository())->repo_actifs() as $row) {
            $this->addReference($types, $row, [(string) $row['code'], (string) $row['libelle']]);
        }
        $initialStatus = null;
        foreach ((new StatutRepository())->repo_actifs() as $row) {
            if ((string) $row['code'] === 'introuvable') {
                $initialStatus = $row;
            }
        }
        return ['types' => $types, 'initial_status' => $initialStatus];
    }

    private function addReference(array &$map, array $row, array $keys): void
    {
        foreach ($keys as $key) {
            $normalized = $this->normalizeKey($key);
            if ($normalized === '') {
                continue;
            }
            if (array_key_exists($normalized, $map) && (int) ($map[$normalized]['id'] ?? 0) !== (int) $row['id']) {
                $map[$normalized] = ['ambiguous' => true];
            } else {
                $map[$normalized] = $row;
            }
        }
    }

    private function resolveReference(string $value, array $map, string $label, array &$errors): ?array
    {
        if ($value === '') {
            $errors[] = "{$label} obligatoire.";
            return null;
        }
        $key = $this->normalizeKey($value);
        $row = $map[$key] ?? null;
        if ($row === null) {
            $errors[] = "{$label} « {$value} » introuvable ou inactif.";
            return null;
        }
        if (!empty($row['ambiguous'])) {
            $errors[] = "{$label} « {$value} » ambigu. Utilisez un code, un identifiant ou un e-mail unique.";
            return null;
        }
        return $row;
    }

    private function normalizePriority(string $value, array &$errors): string
    {
        if (trim($value) === '') {
            return 'Normale';
        }
        $map = [
            'basse' => 'Basse',
            'normale' => 'Normale',
            'haute' => 'Haute',
            'urgente' => 'Urgente',
        ];
        $key = $this->normalizeKey($value);
        if (!isset($map[$key])) {
            $errors[] = 'Priorité invalide : utilisez Basse, Normale, Haute ou Urgente.';
            return 'Normale';
        }
        return $map[$key];
    }

    private function detectDelimiter(string $contents): string
    {
        $firstLines = implode("\n", array_slice(preg_split('/\R/', $contents) ?: [], 0, 10));
        $scores = [];
        foreach ([';' => ';', ',' => ',', "\t" => "\t"] as $delimiter => $needle) {
            $scores[$delimiter] = substr_count($firstLines, $needle);
        }
        arsort($scores);
        $delimiter = (string) array_key_first($scores);
        if (($scores[$delimiter] ?? 0) < 2) {
            throw new InvalidArgumentException('Le séparateur du fichier CSV n’a pas pu être identifié.');
        }
        return $delimiter;
    }

    private function assertSafeXlsx(string $path): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('L’extension ZIP de PHP est requise pour lire un fichier XLSX.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Le fichier XLSX est corrompu ou n’est pas une archive Excel valide.');
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new InvalidArgumentException('Le contenu du fichier ne correspond pas au format XLSX.');
            }
            if ($zip->numFiles > 500) {
                throw new InvalidArgumentException('Le fichier XLSX contient trop d’éléments internes.');
            }
            $uncompressed = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $uncompressed += (int) ($stat['size'] ?? 0);
                if ($uncompressed > 30 * 1024 * 1024) {
                    throw new InvalidArgumentException('Le contenu décompressé du fichier XLSX est trop volumineux.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim($this->scalarString($value)) !== '') {
                return false;
            }
        }
        return true;
    }

    private function scalarString(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalizeKey(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii)) {
            $value = $ascii;
        }
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return trim($value, '_');
    }

    private function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille autorisée par le serveur.',
            UPLOAD_ERR_PARTIAL => 'Le téléversement du fichier est incomplet.',
            UPLOAD_ERR_NO_FILE => 'Sélectionnez un fichier CSV ou XLSX.',
            default => 'Le fichier n’a pas pu être téléversé.',
        };
    }
}
