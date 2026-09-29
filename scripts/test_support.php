<?php
declare(strict_types=1);

use App\Core\SqlStatementParser;
use Database\Seeders\AdminSeeder;
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
 * Remplit une base de test avec les donnees de reference, puis selon les
 * options un administrateur strictement reserve aux tests et les donnees demo.
 */
function risfmSeed(PDO $db, bool $admin = true, bool $demo = false): void
{
    (new DatabaseSeeder($db))->run();

    if ($admin) {
        $existing = (int) $db->query(
            "SELECT COUNT(*) FROM utilisateurs WHERE role = 'administrateur'"
        )->fetchColumn();
        if ($existing === 0) {
            (new AdminSeeder(
                $db,
                'Administrateur',
                'Recette',
                'recette.admin@oipi.test',
                'Tests automatises',
                'Recette_Admin2026#'
            ))->run();
        }
    }

    if ($demo) {
        (new DatabaseSeeder($db))->run(demo: true);
    }
}
