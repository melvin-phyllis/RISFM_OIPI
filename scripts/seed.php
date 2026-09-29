<?php
declare(strict_types=1);

use App\Core\Database;
use Database\Seeders\DatabaseSeeder;

/**
 * Remplit la base avec ses donnees initiales (voir database/seeders/).
 *
 *   php scripts/seed.php                       installation complete
 *   php scripts/seed.php --only=admin          uniquement AdminSeeder
 *   php scripts/seed.php --demo                installation + donnees demo
 *
 * A lancer apres php scripts/migrate.php. La commande peut etre relancee :
 * les references existantes sont conservees, mais le mot de passe du premier
 * administrateur est reinitialise lorsque AdminSeeder est execute.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';

$usage = static function (): void {
    echo "Usage : php scripts/seed.php [--demo | --only=nom]\n";
    echo "  sans option            : donnees de reference + premier administrateur\n";
    echo "  --demo                 : ajoute aussi les donnees fictives (interdit en production)\n";
    echo "  --only=nom             : execute uniquement le seeder demande\n";
    echo "  noms disponibles       : " . implode(', ', array_keys(DatabaseSeeder::NAMED)) . "\n";
};

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    $usage();
    exit(0);
}

$demo = false;
$only = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--demo') {
        if ($demo) {
            fwrite(STDERR, "ECHEC : l'option --demo est presente plusieurs fois.\n");
            exit(2);
        }
        $demo = true;
        continue;
    }
    if (str_starts_with($argument, '--only=')) {
        if ($only !== null) {
            fwrite(STDERR, "ECHEC : une seule option --only est autorisee.\n");
            exit(2);
        }
        $only = trim(substr($argument, strlen('--only=')));
        if ($only === '') {
            fwrite(STDERR, "ECHEC : indiquez un nom apres --only=.\n");
            $usage();
            exit(2);
        }
        continue;
    }

    fwrite(STDERR, "ECHEC : option inconnue {$argument}.\n");
    $usage();
    exit(2);
}

if ($demo && $only !== null) {
    fwrite(STDERR, "ECHEC : --demo et --only ne peuvent pas etre utilises ensemble.\n");
    exit(2);
}
$demoOnly = in_array($only, ['utilisateurs-demo', 'formulaires-demo'], true);
if (($demo || $demoOnly) && APP_ENV === 'production') {
    fwrite(STDERR, "ECHEC : les donnees de demonstration sont interdites quand APP_ENV=production.\n");
    exit(1);
}

try {
    $seeder = new DatabaseSeeder(Database::getConnection());
    $resume = $only !== null
        ? $seeder->runOnly($only)
        : $seeder->run(admin: true, demo: $demo);
} catch (Throwable $exception) {
    fwrite(STDERR, "ECHEC SEEDERS : " . $exception->getMessage() . "\nAucune donnee n'a ete ecrite.\n");
    exit(1);
}

foreach ($resume as $seeder => $ligne) {
    printf("%-24s %s\n", substr($seeder, (int) strrpos($seeder, '\\') + 1), $ligne);
}
echo "SEEDERS OK\n";
