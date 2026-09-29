<?php
declare(strict_types=1);

use App\Core\SqlStatementParser;
use Database\Seeders\DatabaseSeeder;

/**
 * Charge un fichier SQL dans la connexion de test courante.
 *
 * Les fichiers utilises ne doivent pas contenir USE afin de garantir que la
 * recette reste dans sa base temporaire.
 */
function risfmLoadTestSql(PDO $db, string $path, string $label): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException($label . ' illisible.');
    }

    foreach (SqlStatementParser::parse($sql) as $position => $statement) {
        try {
            $db->exec($statement);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                $label . ' instruction #' . ($position + 1) . ' : ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }
}

/**
 * Remplit une base de test avec les seeders (donnees de reference, et selon
 * les options premier administrateur et donnees de demonstration).
 */
function risfmSeed(PDO $db, bool $admin = true, bool $demo = false): void
{
    (new DatabaseSeeder($db))->run(admin: $admin, demo: $demo);
}
