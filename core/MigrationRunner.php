<?php
declare(strict_types=1);

final class MigrationRunner
{
    private const LOCK_NAME = 'risfm_schema_migrations';

    private PDO $db;
    private array $migrations;

    public function __construct(?PDO $db = null, ?array $migrations = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->migrations = $migrations ?? require BASE_PATH . '/config/migrations.php';
        $this->validateManifest();
    }

    /** @return list<array{name:string,file:string,status:string}> */
    public function status(): array
    {
        $this->ensureRepository();
        $applied = $this->appliedByName();
        $status = [];
        foreach ($this->migrations as $migration) {
            $name = (string) $migration['name'];
            $checksum = $this->checksum((string) $migration['file']);
            $state = 'pending';
            if (isset($applied[$name])) {
                $state = hash_equals((string) $applied[$name]['checksum'], $checksum)
                    ? 'applied'
                    : 'checksum_mismatch';
            }
            $status[] = ['name' => $name, 'file' => (string) $migration['file'], 'status' => $state];
        }
        return $status;
    }

    /** @return array{applied:list<string>,skipped:list<string>,batch:int} */
    public function migrate(): array
    {
        $this->ensureRepository();
        if (!$this->acquireLock()) {
            throw new RuntimeException('Une autre execution des migrations est deja en cours.');
        }

        try {
            $appliedRows = $this->appliedByName();
            $batch = (int) $this->db->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();
            $result = ['applied' => [], 'skipped' => [], 'batch' => $batch];

            foreach ($this->migrations as $migration) {
                $name = (string) $migration['name'];
                $file = (string) $migration['file'];
                $checksum = $this->checksum($file);
                if (isset($appliedRows[$name])) {
                    if (!hash_equals((string) $appliedRows[$name]['checksum'], $checksum)) {
                        throw new RuntimeException("La migration {$name} a ete modifiee apres son application (checksum different).");
                    }
                    $result['skipped'][] = $name;
                    continue;
                }

                $startedAt = microtime(true);
                $this->executeFile($file);
                $duration = (int) round((microtime(true) - $startedAt) * 1000);

                $insert = $this->db->prepare(
                    'INSERT INTO schema_migrations
                        (migration, fichier, checksum, batch, duree_ms, appliquee_le)
                     VALUES (:migration, :fichier, :checksum, :batch, :duree_ms, NOW())'
                );
                $insert->execute([
                    'migration' => $name,
                    'fichier' => $file,
                    'checksum' => $checksum,
                    'batch' => $batch,
                    'duree_ms' => $duration,
                ]);
                $result['applied'][] = $name;
            }
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        } finally {
            $this->releaseLock();
        }
    }

    private function executeFile(string $file): void
    {
        $path = $this->migrationPath($file);
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Migration illisible : {$file}.");
        }
        $statements = SqlStatementParser::parse($sql);
        if ($statements === []) {
            throw new RuntimeException("Migration vide : {$file}.");
        }

        foreach ($statements as $position => $statement) {
            try {
                $query = $this->db->prepare($statement);
                $query->execute();
                do {
                    if ($query->columnCount() > 0) {
                        $query->fetchAll();
                    }
                } while ($query->nextRowset());
                $query->closeCursor();
            } catch (Throwable $exception) {
                $number = $position + 1;
                throw new RuntimeException(
                    "Echec de {$file}, instruction #{$number} : " . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
    }

    private function ensureRepository(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(180) NOT NULL UNIQUE,
                fichier VARCHAR(255) NOT NULL,
                checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                batch INT UNSIGNED NOT NULL,
                duree_ms INT UNSIGNED NOT NULL DEFAULT 0,
                appliquee_le DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_schema_migrations_batch (batch, appliquee_le)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function appliedByName(): array
    {
        $rows = $this->db->query(
            'SELECT migration, checksum, batch, appliquee_le FROM schema_migrations ORDER BY id'
        )->fetchAll();
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['migration']] = $row;
        }
        return $indexed;
    }

    private function validateManifest(): void
    {
        $names = [];
        $files = [];
        foreach ($this->migrations as $migration) {
            $name = (string) ($migration['name'] ?? '');
            $file = (string) ($migration['file'] ?? '');
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $name) || !preg_match('/^[a-zA-Z0-9_.-]+\.sql$/', $file)) {
                throw new RuntimeException('Manifeste de migrations invalide.');
            }
            if (isset($names[$name]) || isset($files[$file])) {
                throw new RuntimeException('Migration dupliquee dans le manifeste : ' . $name . '.');
            }
            $names[$name] = true;
            $files[$file] = true;
            $this->migrationPath($file);
        }
    }

    private function migrationPath(string $file): string
    {
        $directory = realpath(BASE_PATH . '/migrations');
        $path = realpath(BASE_PATH . '/migrations/' . $file);
        if ($directory === false || $path === false || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Fichier de migration introuvable ou interdit : ' . $file . '.');
        }
        return $path;
    }

    private function checksum(string $file): string
    {
        $checksum = hash_file('sha256', $this->migrationPath($file));
        if (!is_string($checksum)) {
            throw new RuntimeException('Checksum impossible pour ' . $file . '.');
        }
        return $checksum;
    }

    private function acquireLock(): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:name, 10)');
        $stmt->execute(['name' => self::LOCK_NAME]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => self::LOCK_NAME]);
        } catch (Throwable $exception) {
            error_log('[Migrations] Liberation du verrou impossible : ' . $exception->getMessage());
        }
    }
}
