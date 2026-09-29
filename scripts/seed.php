<?php
declare(strict_types=1);

use App\Core\Database;
use Database\Seeders\DatabaseSeeder;

/**
 * Remplit la base avec ses donnees initiales (voir database/seeders/).
 *
 *   php scripts/seed.php          donnees de reference + premier administrateur
 *   php scripts/seed.php --demo   + comptes et formulaires de demonstration
 *
 * A lancer apres php scripts/migrate.php. La commande peut etre relancee :
 * les references existantes sont conservees, mais le mot de passe du premier
 * administrateur est reinitialise.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage : php scripts/seed.php [--demo]\n";
    echo "  sans option : roles, statuts, types de titres, localisations, parametres et premier administrateur\n";
    echo "  --demo      : ajoute des comptes et formulaires fictifs (interdit en production)\n";
    exit(0);
}

$demo = in_array('--demo', $argv, true);
if ($demo && APP_ENV === 'production') {
    fwrite(STDERR, "ECHEC : les donnees de demonstration sont interdites quand APP_ENV=production.\n");
    exit(1);
}

try {
    $resume = (new DatabaseSeeder(Database::getConnection()))->run(admin: true, demo: $demo);
} catch (Throwable $exception) {
    fwrite(STDERR, "ECHEC SEEDERS : " . $exception->getMessage() . "\nAucune donnee n'a ete ecrite.\n");
    exit(1);
}

foreach ($resume as $seeder => $ligne) {
    printf("%-24s %s\n", substr($seeder, (int) strrpos($seeder, '\\') + 1), $ligne);
}
echo "SEEDERS OK\n";
