<?php
declare(strict_types=1);

/**
 * Operations sensibles de sauvegarde/restauration MySQL.
 *
 * Les processus sont lances sans shell et les identifiants sont transmis par
 * un fichier d'options temporaire en mode 0600, jamais dans la ligne de commande.
 */
final class DatabaseBackup
{
    private const FORMAT_MARKER = '-- RISFM BACKUP FORMAT P10';
    private const OBJECTS_BEGIN = '-- RISFM CONTROLLED OBJECTS BEGIN';
    private const OBJECTS_END = '-- RISFM CONTROLLED OBJECTS END';

    private array $config;
    private array $manifest;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require BASE_PATH . '/config/database.php';
        $this->manifest = require BASE_PATH . '/config/database_objects.php';
    }

    public function processAvailable(): bool
    {
        return function_exists('proc_open');
    }

    public function createDump(string $outputPath): array
    {
        if (!$this->processAvailable()) {
            return ['success' => false, 'error' => 'La fonction proc_open() est indisponible.'];
        }

        return $this->withCredentials(function (string $optionFile) use ($outputPath): array {
            $baseCommand = [
                'mysqldump',
                '--defaults-extra-file=' . $optionFile,
                '--single-transaction',
                '--quick',
                '--hex-blob',
                '--triggers',
                '--no-tablespaces',
                '--skip-comments',
            ];

            // Les routines sont ajoutees ensuite depuis le manifeste canonique.
            // Cela evite SHOW_ROUTINE et les DEFINER non transportables.
            $command = array_merge($baseCommand, [(string) $this->config['dbname']]);
            $result = $this->runProcess($command, null, $outputPath);
            $result['success'] = $result['code'] === 0
                && is_file($outputPath)
                && filesize($outputPath) > 0;
            if ($result['success']) {
                if (!$this->removeExplicitDefiners($outputPath)
                    || !$this->prependFormatMarker($outputPath)
                    || !$this->appendControlledObjects($outputPath)
                ) {
                    $result['success'] = false;
                    $result['error'] = 'Impossible de finaliser les objets programmables de la sauvegarde.';
                }
            }
            return $result;
        });
    }

    /** Export complet de repli lorsque mysqldump est indisponible. */
    public function createPhpDump(string $outputPath): array
    {
        $handle = null;
        try {
            $db = Database::getConnection();
            $tables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
            $handle = fopen($outputPath, 'wb');
            if ($handle === false) {
                return ['success' => false, 'error' => 'Impossible de creer le fichier de sauvegarde.'];
            }

            fwrite($handle, self::FORMAT_MARKER . "\n");
            fwrite($handle, '-- Sauvegarde logique RISFM PDO - ' . date('Y-m-d H:i:s') . "\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                $quotedTable = $this->quoteIdentifier((string) $table);
                $create = $db->query("SHOW CREATE TABLE {$quotedTable}")->fetch();
                $createSql = is_array($create) ? (string) array_values($create)[1] : '';
                if ($createSql === '') {
                    throw new RuntimeException('Structure illisible pour la table ' . $table . '.');
                }
                fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n\n");

                // Une colonne generee est reconstruite par MySQL depuis son
                // expression et ne peut pas recevoir de valeur a la restauration.
                $columnDefinitions = $db->query("SHOW FULL COLUMNS FROM {$quotedTable}")->fetchAll();
                $dataColumns = array_values(array_filter(
                    $columnDefinitions,
                    static fn (array $column): bool => !str_contains(strtoupper((string) ($column['Extra'] ?? '')), 'GENERATED')
                ));
                $selectColumns = array_map(
                    fn (array $column): string => $this->quoteIdentifier((string) $column['Field']),
                    $dataColumns
                );
                if ($selectColumns === []) {
                    continue;
                }
                $rows = $db->query("SELECT " . implode(',', $selectColumns) . " FROM {$quotedTable}");
                while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                    $columns = array_map(fn (string $column): string => $this->quoteIdentifier($column), array_keys($row));
                    $values = array_map(
                        static fn (mixed $value): string => $value === null ? 'NULL' : $db->quote((string) $value),
                        array_values($row)
                    );
                    fwrite(
                        $handle,
                        "INSERT INTO {$quotedTable} (" . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n"
                    );
                }
                fwrite($handle, "\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($handle);
            $handle = null;

            if (!$this->appendControlledObjects($outputPath)) {
                throw new RuntimeException('Impossible d’ajouter les objets programmables au fichier.');
            }

            return [
                'success' => is_file($outputPath) && filesize($outputPath) > 0,
                'error' => null,
                'warning' => 'Sauvegarde complete generee par le moteur PDO de repli.',
            ];
        } catch (Throwable $exception) {
            error_log('[Sauvegarde] Echec repli PHP : ' . $exception->getMessage());
            return ['success' => false, 'error' => $exception->getMessage()];
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** @return array{tables:list<string>,views:list<string>,procedures:list<string>,triggers:list<string>} */
    public function manifest(): array
    {
        return [
            'tables' => array_values($this->manifest['tables']),
            'views' => array_keys($this->manifest['views']),
            'procedures' => array_keys($this->manifest['procedures']),
            'triggers' => array_keys($this->manifest['triggers']),
        ];
    }

    /** Controle non destructif du schema actuellement utilise. */
    public function verifyCurrentDatabase(): array
    {
        try {
            return $this->verifyDatabaseObjects(
                $this->maintenancePdo((string) $this->config['dbname'])
            );
        } catch (Throwable $exception) {
            return ['success' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Validation conservatrice avant tout lancement du client MySQL.
     */
    public function validateSqlFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return $this->invalid('Le fichier SQL est introuvable ou illisible.');
        }

        $size = filesize($path);
        $maxBytes = BACKUP_MAX_MB * 1024 * 1024;
        if ($size === false || $size < 32) {
            return $this->invalid('Le fichier SQL est vide ou incomplet.');
        }
        if ($size > $maxBytes) {
            return $this->invalid('Le fichier SQL depasse la taille maximale de ' . BACKUP_MAX_MB . ' Mo.');
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            return $this->invalid('Impossible de lire le fichier SQL.');
        }
        if (str_contains($sql, "\0") || !mb_check_encoding($sql, 'UTF-8')) {
            return $this->invalid('Le fichier contient des donnees binaires ou un encodage invalide.');
        }
        if (preg_match('/(?:^|\R)(?:mysql|mysqldump):\s*(?:\[Warning\]|Error)/mi', $sql)) {
            return $this->invalid('Le fichier contient une erreur du client MySQL et constitue une sauvegarde incomplete.');
        }
        if (!str_contains($sql, self::FORMAT_MARKER)
            || !str_contains($sql, self::OBJECTS_BEGIN)
            || !str_contains($sql, self::OBJECTS_END)
        ) {
            return $this->invalid('Format de sauvegarde ancien ou incomplet : manifeste P10 absent.');
        }

        $securityScan = $this->sqlForSecurityScan($sql);
        $forbidden = [
            '/\b(?:CREATE|ALTER|DROP)\s+DATABASE\b/i' => 'creation, modification ou suppression de base',
            '/(?:^|[;\r\n])\s*USE\s+[`\w-]+\s*;/i' => 'changement de base avec USE',
            '/\b(?:CREATE|ALTER|DROP)\s+USER\b/i' => 'gestion de comptes MySQL',
            '/\b(?:GRANT|REVOKE)\b/i' => 'modification de privileges MySQL',
            '/\bSET\s+(?:GLOBAL|PERSIST)\b/i' => 'modification globale du serveur',
            '/\b(?:INSTALL|UNINSTALL)\s+(?:PLUGIN|COMPONENT)\b/i' => 'installation de composants serveur',
            '/\bLOAD\s+DATA(?:\s+LOCAL)?\s+INFILE\b/i' => 'lecture de fichiers serveur',
            '/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i' => 'ecriture de fichiers serveur',
            '/(?:^|\R)\s*(?:SOURCE|SYSTEM|\\!)\b/mi' => 'commande du client MySQL non autorisee',
        ];
        foreach ($forbidden as $pattern => $label) {
            if (preg_match($pattern, $securityScan)) {
                return $this->invalid('Instruction interdite detectee : ' . $label . '.');
            }
        }

        foreach ($this->manifest['tables'] as $table) {
            $pattern = '/\bCREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?' . preg_quote($table, '/') . '`?\s*\(/i';
            if (!preg_match($pattern, $sql)) {
                return $this->invalid("La table obligatoire {$table} est absente de la sauvegarde.");
            }
        }

        foreach (array_keys($this->manifest['views']) as $view) {
            $pattern = '/\bCREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+`?' . preg_quote($view, '/') . '`?\s+AS\b/i';
            if (!preg_match($pattern, $securityScan)) {
                return $this->invalid("La vue obligatoire {$view} est absente de la sauvegarde.");
            }
        }

        foreach (array_keys($this->manifest['procedures']) as $procedure) {
            $pattern = '/\bCREATE\s+PROCEDURE\s+`?' . preg_quote($procedure, '/') . '`?\s*\(/i';
            if (!preg_match($pattern, $securityScan)) {
                return $this->invalid("La procedure obligatoire {$procedure} est absente de la sauvegarde.");
            }
        }

        foreach (array_keys($this->manifest['triggers']) as $trigger) {
            $pattern = '/\bCREATE\s+TRIGGER\s+`?' . preg_quote($trigger, '/') . '`?\b/i';
            if (!preg_match($pattern, $securityScan)) {
                return $this->invalid("Le trigger obligatoire {$trigger} est absent de la sauvegarde.");
            }
        }

        foreach ($this->manifest['forbidden_triggers'] as $trigger) {
            $pattern = '/\bCREATE\s+TRIGGER\s+`?' . preg_quote($trigger, '/') . '`?\b/i';
            if (preg_match($pattern, $securityScan)) {
                return $this->invalid("Le trigger obsolete {$trigger} ne doit pas etre restaure.");
            }
        }

        preg_match_all('/\bCREATE\s+TRIGGER\s+`?([a-zA-Z0-9_]+)`?\b/i', $securityScan, $createdTriggers);
        $unexpectedTriggers = array_values(array_diff(
            array_unique($createdTriggers[1] ?? []),
            array_keys($this->manifest['triggers'])
        ));
        if ($unexpectedTriggers !== []) {
            return $this->invalid('Triggers non declares dans le manifeste : ' . implode(', ', $unexpectedTriggers) . '.');
        }

        return [
            'valid' => true,
            'error' => null,
            'size' => (int) $size,
            'sha256' => hash_file('sha256', $path),
            'manifest' => $this->manifest(),
        ];
    }

    /**
     * Importe vraiment le fichier dans une base isolee, controle les tables
     * essentielles puis supprime toujours cette base de test.
     */
    public function testRestore(string $sqlPath): array
    {
        $temporaryDatabase = $this->temporaryDatabaseName();
        $admin = null;
        $created = false;

        try {
            $admin = $this->maintenancePdo();
            $quotedDatabase = $this->quoteIdentifier($temporaryDatabase);
            $admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $created = true;

            $restore = $this->restoreToDatabase($sqlPath, $temporaryDatabase);
            if (!$restore['success']) {
                return [
                    'success' => false,
                    'error' => 'Le test d\'import dans la base temporaire a echoue : ' . $restore['error'],
                ];
            }

            $testDb = $this->maintenancePdo($temporaryDatabase);
            $repair = $this->repairProgrammableObjects($testDb);
            if (!$repair['success']) {
                return [
                    'success' => false,
                    'error' => 'Recreation des objets programmables impossible : ' . $repair['error'],
                ];
            }

            $integrity = $this->verifyDatabaseObjects($testDb);
            if (!$integrity['success']) {
                return $integrity;
            }

            // Force aussi la lecture de donnees dans les tables centrales.
            $testDb->query('SELECT COUNT(*) FROM utilisateurs')->fetchColumn();
            $testDb->query('SELECT COUNT(*) FROM formulaires_manquants')->fetchColumn();

            return ['success' => true, 'error' => null, 'manifest' => $integrity['manifest']];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'error' => 'Impossible de tester la restauration dans une base temporaire : ' . $e->getMessage(),
            ];
        } finally {
            if ($created && $admin instanceof PDO) {
                try {
                    $admin->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($temporaryDatabase));
                } catch (Throwable $cleanupError) {
                    error_log('[Restauration] Nettoyage impossible de la base temporaire ' . $temporaryDatabase . ' : ' . $cleanupError->getMessage());
                }
            }
        }
    }

    public function restoreToMainDatabase(string $sqlPath): array
    {
        $main = null;
        $importPath = null;
        try {
            $main = $this->maintenancePdo((string) $this->config['dbname']);

            // Preflight indispensable : si la procedure existante appartient a
            // un compte privilegie et est obsolete, on refuse avant les donnees.
            $procedure = $this->ensureProcedures($main);
            if (!$procedure['success']) {
                return [
                    'success' => false,
                    'error' => 'Precontrole de la procedure impossible : ' . $procedure['error'],
                ];
            }

            $importPath = $this->createMainImportCopy($sqlPath);
            $restore = $this->restoreToDatabase($importPath, (string) $this->config['dbname']);
            if (!$restore['success']) {
                return $restore;
            }

            $repair = $this->repairProgrammableObjects($main);
            if (!$repair['success']) {
                return $repair;
            }

            return $this->verifyDatabaseObjects($main);
        } catch (Throwable $exception) {
            return ['success' => false, 'error' => $exception->getMessage()];
        } finally {
            if ($importPath !== null && is_file($importPath)) {
                unlink($importPath);
            }
        }
    }

    private function restoreToDatabase(string $sqlPath, string $database): array
    {
        if (!$this->processAvailable()) {
            return ['success' => false, 'error' => 'La fonction proc_open() est indisponible.'];
        }

        return $this->withCredentials(function (string $optionFile) use ($sqlPath, $database): array {
            $result = $this->runProcess([
                'mysql',
                '--defaults-extra-file=' . $optionFile,
                '--database=' . $database,
                '--binary-mode',
            ], $sqlPath);
            $result['success'] = $result['code'] === 0;
            return $result;
        });
    }

    /** Recree les vues et garantit une procedure KPI fonctionnelle. */
    private function repairProgrammableObjects(PDO $db): array
    {
        try {
            foreach ($this->manifest['forbidden_triggers'] as $trigger) {
                $db->exec('DROP TRIGGER IF EXISTS ' . $this->quoteIdentifier((string) $trigger));
            }

            foreach ($this->manifest['views'] as $definition) {
                $db->exec((string) $definition);
            }

            return $this->ensureProcedures($db);
        } catch (Throwable $exception) {
            return ['success' => false, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Conserve une procedure existante si ses resultats sont exacts. Sinon,
     * tente de la remplacer par la definition canonique avant toute restauration.
     */
    private function ensureProcedures(PDO $db): array
    {
        foreach ($this->manifest['procedures'] as $name => $definition) {
            if ($name === 'sp_kpi_globaux' && $this->kpiProcedureIsCorrect($db)) {
                continue;
            }

            try {
                $db->exec('DROP PROCEDURE IF EXISTS ' . $this->quoteIdentifier((string) $name));
                $db->exec((string) $definition);
            } catch (Throwable $exception) {
                return [
                    'success' => false,
                    'error' => "La procedure {$name} ne peut pas etre recreee avec le compte de maintenance : " . $exception->getMessage(),
                ];
            }

            if ($name === 'sp_kpi_globaux' && !$this->kpiProcedureIsCorrect($db)) {
                return ['success' => false, 'error' => 'La procedure sp_kpi_globaux retourne des indicateurs incoherents.'];
            }
        }

        return ['success' => true, 'error' => null];
    }

    private function kpiProcedureIsCorrect(PDO $db): bool
    {
        try {
            $call = $db->query('CALL sp_kpi_globaux()');
            $actual = $call->fetch(PDO::FETCH_ASSOC);
            $call->closeCursor();
            if (!is_array($actual)) {
                return false;
            }

            $lifetime = (int) $db->query(
                "SELECT COALESCE(MAX(CASE WHEN cle = 'session_lifetime_minutes' THEN CAST(valeur AS UNSIGNED) END), 20)
                 FROM parametres"
            )->fetchColumn();
            $lifetime = max(5, min(120, $lifetime));

            $expected = $db->query(
                "SELECT
                    (SELECT COUNT(*) FROM formulaires_manquants WHERE est_archive = 0) AS total_formulaires,
                    (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 1) AS total_retrouves,
                    (SELECT COUNT(*) FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.est_archive = 0 AND s.resolu = 0) AS total_restants,
                    (SELECT COUNT(*) FROM utilisateurs WHERE actif = 1) AS total_utilisateurs_actifs,
                    (SELECT COUNT(DISTINCT c.utilisateur_id)
                       FROM connexions c
                      WHERE c.statut = 'actif'
                        AND c.derniere_activite >= (NOW() - INTERVAL {$lifetime} MINUTE)
                    ) AS total_connectes"
            )->fetch(PDO::FETCH_ASSOC);

            foreach (['total_formulaires', 'total_retrouves', 'total_restants', 'total_utilisateurs_actifs', 'total_connectes'] as $key) {
                if (!array_key_exists($key, $actual) || (int) $actual[$key] !== (int) ($expected[$key] ?? -1)) {
                    return false;
                }
            }
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function verifyDatabaseObjects(PDO $db): array
    {
        try {
            $tables = $db->query(
                "SELECT table_name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $views = $db->query(
                'SELECT table_name FROM information_schema.views WHERE table_schema = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN);
            $procedures = $db->query(
                "SELECT routine_name FROM information_schema.routines
                 WHERE routine_schema = DATABASE() AND routine_type = 'PROCEDURE'"
            )->fetchAll(PDO::FETCH_COLUMN);
            $triggers = $db->query(
                'SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE()'
            )->fetchAll(PDO::FETCH_COLUMN);

            $checks = [
                'tables' => [$this->manifest['tables'], $tables],
                'views' => [array_keys($this->manifest['views']), $views],
                'procedures' => [array_keys($this->manifest['procedures']), $procedures],
                'triggers' => [array_keys($this->manifest['triggers']), $triggers],
            ];
            foreach ($checks as $type => [$required, $present]) {
                $missing = array_values(array_diff($required, $present));
                if ($missing !== []) {
                    return [
                        'success' => false,
                        'error' => 'Structure restauree incomplete. ' . ucfirst($type) . ' absents : ' . implode(', ', $missing) . '.',
                    ];
                }
            }

            $forbidden = array_values(array_intersect($this->manifest['forbidden_triggers'], $triggers));
            if ($forbidden !== []) {
                return ['success' => false, 'error' => 'Triggers obsoletes presents : ' . implode(', ', $forbidden) . '.'];
            }
            $unexpectedTriggers = array_values(array_diff($triggers, array_keys($this->manifest['triggers'])));
            if ($unexpectedTriggers !== []) {
                return ['success' => false, 'error' => 'Triggers non declares presents : ' . implode(', ', $unexpectedTriggers) . '.'];
            }

            foreach (array_keys($this->manifest['views']) as $view) {
                $db->query('SELECT * FROM ' . $this->quoteIdentifier((string) $view) . ' LIMIT 0');
            }
            if (!$this->kpiProcedureIsCorrect($db)) {
                return ['success' => false, 'error' => 'La procedure sp_kpi_globaux est absente ou incoherente.'];
            }

            return ['success' => true, 'error' => null, 'manifest' => $this->manifest()];
        } catch (Throwable $exception) {
            return ['success' => false, 'error' => 'Controle du manifeste impossible : ' . $exception->getMessage()];
        }
    }

    /** Retire le bloc canonique avant l'import principal, puis il est recree via PDO. */
    private function createMainImportCopy(string $sourcePath): string
    {
        $sql = file_get_contents($sourcePath);
        if ($sql === false) {
            throw new RuntimeException('Impossible de lire la sauvegarde a restaurer.');
        }

        $withoutObjects = preg_replace(
            '/^' . preg_quote(self::OBJECTS_BEGIN, '/') . '.*?^' . preg_quote(self::OBJECTS_END, '/') . '\s*$/ms',
            '',
            $sql,
            1,
            $replacements
        );
        if (!is_string($withoutObjects) || $replacements !== 1) {
            throw new RuntimeException('Le bloc controle des vues et procedures est introuvable.');
        }

        $directory = STORAGE_PATH . '/tmp';
        if (!Security::ensureDirectory($directory)) {
            throw new RuntimeException('Le dossier temporaire est indisponible.');
        }
        $path = tempnam($directory, 'restore_main_');
        if ($path === false || file_put_contents($path, $withoutObjects, LOCK_EX) === false || !chmod($path, 0600)) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
            throw new RuntimeException('Impossible de preparer le fichier de restauration principal.');
        }
        return $path;
    }

    private function maintenancePdo(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;%scharset=%s',
            $this->config['host'],
            $this->config['port'],
            $database !== null ? 'dbname=' . $database . ';' : '',
            $this->config['charset']
        );

        return new PDO(
            $dsn,
            $this->config['maintenance_user'] ?? $this->config['user'],
            $this->config['maintenance_pass'] ?? $this->config['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private function withCredentials(callable $callback): array
    {
        $directory = STORAGE_PATH . '/tmp';
        if (!Security::ensureDirectory($directory)) {
            return ['success' => false, 'error' => 'Le dossier temporaire est indisponible.'];
        }

        $optionFile = tempnam($directory, 'mysql_options_');
        if ($optionFile === false) {
            return ['success' => false, 'error' => 'Impossible de creer le fichier d\'options MySQL.'];
        }

        $user = (string) ($this->config['maintenance_user'] ?? $this->config['user']);
        $pass = (string) ($this->config['maintenance_pass'] ?? $this->config['pass']);
        $content = "[client]\n"
            . 'host=' . $this->optionValue((string) $this->config['host']) . "\n"
            . 'port=' . (int) $this->config['port'] . "\n"
            . 'user=' . $this->optionValue($user) . "\n"
            . 'password=' . $this->optionValue($pass) . "\n"
            . 'default-character-set=' . $this->optionValue((string) $this->config['charset']) . "\n";

        try {
            if (file_put_contents($optionFile, $content, LOCK_EX) === false || !chmod($optionFile, 0600)) {
                return ['success' => false, 'error' => 'Impossible de securiser le fichier d\'options MySQL.'];
            }
            return $callback($optionFile);
        } finally {
            if (is_file($optionFile)) {
                file_put_contents($optionFile, str_repeat("\0", max(1, filesize($optionFile) ?: 1)), LOCK_EX);
                unlink($optionFile);
            }
        }
    }

    private function runProcess(array $command, ?string $inputPath = null, ?string $outputPath = null): array
    {
        $directory = STORAGE_PATH . '/tmp';
        $stderrPath = tempnam($directory, 'mysql_stderr_');
        $temporaryStdout = $outputPath === null ? tempnam($directory, 'mysql_stdout_') : null;
        $stdoutPath = $outputPath ?? $temporaryStdout;
        if ($stderrPath === false || $stdoutPath === false) {
            return ['code' => -1, 'error' => 'Impossible de creer les fichiers temporaires du processus.'];
        }

        $descriptors = [
            0 => $inputPath !== null ? ['file', $inputPath, 'r'] : ['pipe', 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];

        try {
            $pipes = [];
            $process = proc_open($command, $descriptors, $pipes, BASE_PATH, null, ['bypass_shell' => true]);
            if (!is_resource($process)) {
                return ['code' => -1, 'error' => 'Impossible de lancer le client MySQL.'];
            }
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            $code = proc_close($process);
            $error = trim((string) file_get_contents($stderrPath));
            return [
                'code' => $code,
                'error' => $error !== '' ? mb_substr($error, 0, 2000) : null,
            ];
        } finally {
            if (is_file($stderrPath)) {
                unlink($stderrPath);
            }
            if ($temporaryStdout !== null && is_file($temporaryStdout)) {
                unlink($temporaryStdout);
            }
        }
    }

    private function optionValue(string $value): string
    {
        return '"' . addcslashes($value, "\\\"\n\r\t") . '"';
    }

    /**
     * Retire les commentaires ordinaires et valeurs textuelles afin qu'un mot
     * comme "GRANT" present dans une donnee ne provoque pas un faux positif.
     * Les commentaires executables specifiques a MySQL sont conserves et inspectes.
     */
    private function sqlForSecurityScan(string $sql): string
    {
        $sql = preg_replace_callback(
            '/\/\*!(?:\d{5})?\s*(.*?)\*\//s',
            static fn (array $match): string => $match[1],
            $sql
        ) ?? $sql;
        $sql = preg_replace('/\/\*(?!\!).*?\*\//s', ' ', $sql) ?? $sql;
        $sql = preg_replace('/(?:^|\R)\s*--[^\r\n]*/m', "\n", $sql) ?? $sql;
        $sql = preg_replace('/(?:^|\R)\s*#[^\r\n]*/m', "\n", $sql) ?? $sql;
        return preg_replace(
            '/\'(?:\'\'|\\\\.|[^\'\\\\])*\'|"(?:""|\\\\.|[^"\\\\])*"/s',
            "''",
            $sql
        ) ?? $sql;
    }

    /** Evite qu'un import exige les privileges du compte ayant cree les routines. */
    private function removeExplicitDefiners(string $path): bool
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            return false;
        }
        $normalized = preg_replace(
            '/\s+DEFINER\s*=\s*(?:`[^`]*`|[^\s@]+)\s*@\s*(?:`[^`]*`|[^\s*]+)\s*/i',
            ' ',
            $sql
        );
        return is_string($normalized) && file_put_contents($path, $normalized, LOCK_EX) !== false;
    }

    private function prependFormatMarker(string $path): bool
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            return false;
        }
        return file_put_contents($path, self::FORMAT_MARKER . "\n" . $sql, LOCK_EX) !== false;
    }

    private function appendControlledObjects(string $path): bool
    {
        $sql = "\n" . self::OBJECTS_BEGIN . "\n";
        $sql .= '-- Vues statistiques canoniques' . "\n";
        foreach ($this->manifest['views'] as $name => $definition) {
            $sql .= 'DROP VIEW IF EXISTS ' . $this->quoteIdentifier((string) $name) . ";\n";
            $sql .= rtrim((string) $definition, ";\r\n") . ";\n\n";
        }

        if ($this->manifest['procedures'] !== []) {
            $sql .= "DELIMITER $$\n";
            foreach ($this->manifest['procedures'] as $name => $definition) {
                $sql .= 'DROP PROCEDURE IF EXISTS ' . $this->quoteIdentifier((string) $name) . " $$\n";
                $sql .= rtrim((string) $definition, ";\r\n") . " $$\n\n";
            }
            $sql .= "DELIMITER ;\n";
        }

        foreach ($this->manifest['triggers'] as $name => $definition) {
            $sql .= 'DROP TRIGGER IF EXISTS ' . $this->quoteIdentifier((string) $name) . ";\n";
            $sql .= rtrim((string) $definition, ";\r\n") . ";\n";
        }
        $sql .= self::OBJECTS_END . "\n";

        return file_put_contents($path, $sql, FILE_APPEND | LOCK_EX) !== false;
    }

    private function temporaryDatabaseName(): string
    {
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) $this->config['dbname']);
        return substr($prefix, 0, 32) . '_restore_test_' . bin2hex(random_bytes(6));
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Identifiant MySQL invalide.');
        }
        return '`' . $identifier . '`';
    }

    private function invalid(string $message): array
    {
        return ['valid' => false, 'error' => $message, 'size' => null, 'sha256' => null];
    }
}
