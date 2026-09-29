<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\Formulaire\FormulaireRepository;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$model = new FormulaireRepository();
$filters = [];
$expected = $model->repo_searchCount($filters);
$seen = [];
$previousYear = null;
$previousId = null;
$count = 0;

// Deux lignes par lot forcent plusieurs requetes meme avec les donnees de demo.
foreach ($model->repo_iterateForExport($filters, null, 2) as $row) {
    foreach (['id', 'type_libelle', 'annee', 'numero_formulaire', 'statut_libelle'] as $requiredColumn) {
        if (!array_key_exists($requiredColumn, $row)) {
            fwrite(STDERR, "ECHEC: colonne d'export absente : {$requiredColumn}.\n");
            exit(1);
        }
    }

    $id = (int) $row['id'];
    $year = (int) $row['annee'];
    if (isset($seen[$id])) {
        fwrite(STDERR, "ECHEC: formulaire #{$id} retourne plusieurs fois.\n");
        exit(1);
    }
    if ($previousYear !== null
        && ($year > $previousYear || ($year === $previousYear && $id <= $previousId))
    ) {
        fwrite(STDERR, "ECHEC: ordre d'export instable autour du formulaire #{$id}.\n");
        exit(1);
    }

    $seen[$id] = true;
    $previousYear = $year;
    $previousId = $id;
    $count++;
}

if ($count !== $expected) {
    fwrite(STDERR, "ECHEC: {$count} ligne(s) parcourue(s) sur {$expected} attendue(s).\n");
    exit(1);
}

$sample = Database::getConnection()->query(
    "SELECT resultat FROM formulaires_manquants
     WHERE est_archive = 0 AND resultat IS NOT NULL AND resultat <> ''
     ORDER BY id LIMIT 1"
)->fetchColumn();
if (is_string($sample) && $sample !== '') {
    $keywordFilters = ['mot_cle' => $sample];
    $keywordExpected = $model->repo_searchCount($keywordFilters);
    $keywordCount = 0;
    foreach ($model->repo_iterateForExport($keywordFilters, null, 1) as $_row) {
        $keywordCount++;
    }
    if ($keywordCount !== $keywordExpected || $keywordCount < 1) {
        fwrite(STDERR, "ECHEC: le filtre mot-cle ne retourne pas un total coherent.\n");
        exit(1);
    }
}

echo "P6 ITERATEUR OK: {$count} ligne(s), lots forces, aucun doublon ni oubli.\n";
