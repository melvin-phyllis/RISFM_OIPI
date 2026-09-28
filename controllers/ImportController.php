<?php
declare(strict_types=1);

/**
 * Import administratif du registre CSV/XLSX avec aperçu obligatoire.
 */
class ImportController extends Controller
{
    private const SESSION_KEY = '_risfm_form_import';
    private const SESSION_TTL = 1800;

    public function index(): void
    {
        $this->requirePermission('formulaires.import');
        $this->cleanupExpiredFiles();
        $state = $this->currentState();
        $analysis = null;
        $error = null;
        if ($state !== null) {
            try {
                $analysis = (new FormulaireImportService())->analyse($state['path'], $state['extension']);
            } catch (Throwable $e) {
                $error = $e->getMessage();
                $this->clearState();
                $state = null;
            }
        }
        $this->renderImport($analysis, $state, $error);
    }

    public function analyse(): void
    {
        $this->requirePermission('formulaires.import');
        $this->cleanupExpiredFiles();
        $service = new FormulaireImportService();
        $state = null;
        try {
            $file = $_FILES['fichier_import'] ?? [];
            $inspection = $service->inspectFile($file);
            $directory = STORAGE_PATH . '/imports';
            if (!Security::ensureDirectory($directory)) {
                throw new RuntimeException('Le dossier privé des imports est indisponible.');
            }

            $this->clearState();
            $token = bin2hex(random_bytes(32));
            $path = $directory . '/import_' . $token . '.' . $inspection['extension'];
            if (!move_uploaded_file((string) $file['tmp_name'], $path)) {
                throw new RuntimeException('Le fichier n’a pas pu être placé dans le dossier sécurisé.');
            }
            @chmod($path, 0640);

            $state = [
                'token' => $token,
                'path' => $path,
                'extension' => $inspection['extension'],
                'original_name' => $inspection['original_name'],
                'size' => $inspection['size'],
                'sha256' => hash_file('sha256', $path),
                'created_at' => time(),
                'user_id' => (int) Auth::id(),
            ];
            $_SESSION[self::SESSION_KEY] = $state;
            $analysis = $service->analyse($path, $inspection['extension']);
            Logger::log(
                Auth::id(),
                'import_analyse',
                "Analyse du fichier d’import {$inspection['original_name']} : {$analysis['total']} ligne(s)",
                null,
                'import_formulaires',
                null,
                null,
                [
                    'total' => $analysis['total'],
                    'valides' => $analysis['valid_count'],
                    'erreurs' => $analysis['error_count'],
                    'empreinte' => $state['sha256'],
                ]
            );
            $this->renderImport($analysis, $state, null);
        } catch (Throwable $e) {
            if ($state !== null && is_file((string) $state['path'])) {
                @unlink((string) $state['path']);
            }
            unset($_SESSION[self::SESSION_KEY]);
            Logger::log(
                Auth::id(),
                'securite',
                'Import de registre refusé : ' . $e->getMessage()
            );
            $this->renderImport(null, null, $e->getMessage());
        }
    }

    public function confirm(): void
    {
        $this->requirePermission('formulaires.import');
        $state = $this->currentState();
        $token = (string) $this->input('import_token', '');
        if ($state === null || $token === '' || !hash_equals((string) $state['token'], $token)) {
            setFlash('error', 'L’aperçu d’import a expiré. Téléversez de nouveau le fichier.');
            $this->redirect('formulaires/importer');
        }

        try {
            $currentHash = hash_file('sha256', (string) $state['path']);
            if (!is_string($currentHash) || !hash_equals((string) $state['sha256'], $currentHash)) {
                throw new RuntimeException('Le fichier temporaire a changé depuis son analyse.');
            }
            $result = (new FormulaireImportService())->import(
                (string) $state['path'],
                (string) $state['extension'],
                (int) Auth::id()
            );
            $this->clearState();
            setFlash(
                'success',
                $result['imported'] . ' formulaire(s) importé(s). '
                . 'Chaque dossier possède une référence RISFM et une trace d’audit.'
            );
            $this->redirect('formulaires');
        } catch (DomainException|InvalidArgumentException $e) {
            $analysis = null;
            try {
                $analysis = (new FormulaireImportService())->analyse(
                    (string) $state['path'],
                    (string) $state['extension']
                );
            } catch (Throwable) {
                // Le message initial reste prioritaire.
            }
            $this->renderImport($analysis, $state, $e->getMessage());
        } catch (Throwable $e) {
            error_log('[Import formulaires] ' . $e->getMessage());
            $this->renderImport(
                null,
                $state,
                'L’import a échoué et a été entièrement annulé. Aucun formulaire n’a été ajouté.'
            );
        }
    }

    public function cancel(): void
    {
        $this->requirePermission('formulaires.import');
        $this->clearState();
        setFlash('success', 'L’aperçu d’import a été annulé et le fichier temporaire supprimé.');
        $this->redirect('formulaires');
    }

    public function template(string $format): void
    {
        $this->requirePermission('formulaires.import');
        $format = strtolower($format);
        if (!in_array($format, ['csv', 'xlsx'], true)) {
            http_response_code(404);
            return;
        }
        if (!Security::ensureDirectory(STORAGE_PATH . '/tmp')) {
            throw new RuntimeException('Le dossier temporaire est indisponible.');
        }
        $path = tempnam(STORAGE_PATH . '/tmp', 'risfm_modele_import_');
        if ($path === false) {
            throw new RuntimeException('Impossible de préparer le modèle d’import.');
        }
        $service = new FormulaireImportService();
        try {
            if ($format === 'csv') {
                $service->createCsvTemplate($path);
                $mime = 'text/csv; charset=utf-8';
            } else {
                $service->createXlsxTemplate($path);
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }
            Logger::log(Auth::id(), 'export', "Téléchargement du modèle d’import {$format}");
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="modele_import_formulaires_risfm.' . $format . '"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: no-store, max-age=0');
            readfile($path);
        } finally {
            @unlink($path);
        }
    }

    public function report(): void
    {
        $this->requirePermission('formulaires.import');
        $state = $this->currentState();
        if ($state === null) {
            setFlash('error', 'Aucun aperçu d’import actif.');
            $this->redirect('formulaires/importer');
        }
        if (!Security::ensureDirectory(STORAGE_PATH . '/tmp')) {
            throw new RuntimeException('Le dossier temporaire est indisponible.');
        }
        $path = tempnam(STORAGE_PATH . '/tmp', 'risfm_rapport_import_');
        if ($path === false) {
            throw new RuntimeException('Impossible de préparer le rapport d’import.');
        }

        $handle = null;
        try {
            $analysis = (new FormulaireImportService())->analyse(
                (string) $state['path'],
                (string) $state['extension']
            );
            $handle = fopen($path, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Impossible de créer le rapport d’import.');
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                'Ligne', 'Etat', 'Type de titre', 'Annee', 'Numero du formulaire',
                'Statut', 'Localisation recherchee', 'Responsable',
                'Date de recherche', 'Resultat', 'Erreurs',
            ], ';');
            foreach ($analysis['rows'] as $row) {
                $display = $row['display'];
                fputcsv($handle, array_map([$this, 'safeReportCell'], [
                    (string) $row['line'],
                    $row['valid'] ? 'Valide' : 'A corriger',
                    (string) ($display['type_titre'] ?? ''),
                    (string) ($display['annee'] ?? ''),
                    (string) ($display['numero_formulaire'] ?? ''),
                    (string) ($display['statut'] ?? ''),
                    (string) ($display['localisation'] ?? ''),
                    (string) ($display['responsable'] ?? ''),
                    (string) ($display['date_recherche'] ?? ''),
                    (string) ($display['resultat'] ?? ''),
                    implode(' | ', $row['errors']),
                ]), ';');
            }
            fclose($handle);
            $handle = null;

            Logger::log(Auth::id(), 'export', 'Téléchargement du rapport d’analyse avant import');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="rapport_analyse_import_risfm.csv"');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: no-store, max-age=0');
            readfile($path);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($path);
        }
    }

    private function renderImport(?array $analysis, ?array $state, ?string $error): void
    {
        $this->render('formulaires/import', [
            '__title' => 'Importer le registre',
            '__active' => 'formulaires',
            '__page_icon' => 'fas fa-file-import',
            '__subtitle' => 'Contrôler un fichier CSV ou Excel avant son intégration au registre.',
            '__header_actions' => [[
                'label' => 'Retour au registre',
                'url' => url('formulaires'),
                'icon' => 'fas fa-arrow-left',
                'class' => 'btn-outline-secondary',
            ]],
            'analysis' => $analysis,
            'importState' => $state,
            'importError' => $error,
            'previewLimit' => 100,
        ]);
    }

    private function currentState(): ?array
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($state)
            || (int) ($state['user_id'] ?? 0) !== (int) Auth::id()
            || (int) ($state['created_at'] ?? 0) < time() - self::SESSION_TTL
            || !is_file((string) ($state['path'] ?? ''))
        ) {
            $this->clearState();
            return null;
        }
        $expectedDirectory = realpath(STORAGE_PATH . '/imports');
        $realPath = realpath((string) $state['path']);
        if ($expectedDirectory === false
            || $realPath === false
            || !str_starts_with($realPath, $expectedDirectory . DIRECTORY_SEPARATOR)
        ) {
            $this->clearState();
            return null;
        }
        return $state;
    }

    private function clearState(): void
    {
        $state = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        if (!is_array($state)) {
            return;
        }
        $path = (string) ($state['path'] ?? '');
        $expectedDirectory = realpath(STORAGE_PATH . '/imports');
        $realPath = $path !== '' ? realpath($path) : false;
        if ($expectedDirectory !== false
            && $realPath !== false
            && str_starts_with($realPath, $expectedDirectory . DIRECTORY_SEPARATOR)
        ) {
            @unlink($realPath);
        }
    }

    private function safeReportCell(string $value): string
    {
        return preg_match('/^[=+\-@]/', ltrim($value)) === 1 ? "'" . $value : $value;
    }

    private function cleanupExpiredFiles(): void
    {
        $directory = STORAGE_PATH . '/imports';
        if (!is_dir($directory)) {
            return;
        }
        $cutoff = time() - self::SESSION_TTL;
        foreach (new DirectoryIterator($directory) as $file) {
            if (!$file->isFile()
                || !str_starts_with($file->getFilename(), 'import_')
                || $file->getMTime() >= $cutoff
            ) {
                continue;
            }
            @unlink($file->getPathname());
        }
    }
}
