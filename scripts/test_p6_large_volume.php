<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\Formulaire\FormulaireRepository;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$db = Database::getConnection();
$typeId = (int) $db->query('SELECT id FROM types_titres ORDER BY id LIMIT 1')->fetchColumn();
$statusId = (int) $db->query('SELECT id FROM statuts ORDER BY id LIMIT 1')->fetchColumn();
if ($typeId < 1 || $statusId < 1) {
    fwrite(STDERR, "ECHEC: referentiels insuffisants pour le test de volume P6.\n");
    exit(1);
}

$volume = 5207;
$temporaryTableCreated = false;
$structureTableCreated = false;

try {
    // Une table temporaire portant le meme nom masque uniquement, pour cette
    // connexion, la table reelle. Aucun formulaire du registre n'est touche.
    $db->exec('CREATE TEMPORARY TABLE risfm_p6_formulaires_structure LIKE formulaires_manquants');
    $structureTableCreated = true;
    $db->exec('CREATE TEMPORARY TABLE formulaires_manquants LIKE risfm_p6_formulaires_structure');
    $temporaryTableCreated = true;
    $db->exec('DROP TEMPORARY TABLE risfm_p6_formulaires_structure');
    $structureTableCreated = false;
    $db->beginTransaction();

    $insert = $db->prepare(
        'INSERT INTO formulaires_manquants
            (numero_auto, type_titre_id, annee, numero_formulaire, statut_id,
             resultat, niveau_urgence, priorite, est_archive)
         VALUES
            (:numero_auto, :type_titre_id, :annee, :numero_formulaire, :statut_id,
             :resultat, :niveau_urgence, :priorite, 0)'
    );
    $currentYear = (int) date('Y');
    for ($index = 1; $index <= $volume; $index++) {
        $insert->execute([
            'numero_auto' => sprintf('P6-VOLUME-%06d', $index),
            'type_titre_id' => $typeId,
            'annee' => $currentYear - ($index % 10),
            'numero_formulaire' => sprintf('TEST-P6-%06d', $index),
            'statut_id' => $statusId,
            'resultat' => 'Test temporaire de volume',
            'niveau_urgence' => 'Moyen',
            'priorite' => 'Normale',
        ]);
    }

    $model = new FormulaireRepository();
    $expected = $model->repo_searchCount([]);
    $count = 0;
    $seen = [];
    foreach ($model->repo_iterateForExport([], null, 500) as $row) {
        $id = (int) $row['id'];
        if (isset($seen[$id])) {
            throw new RuntimeException("Le formulaire temporaire #{$id} est duplique.");
        }
        $seen[$id] = true;
        $count++;
    }

    if ($expected !== $volume || $count !== $volume) {
        throw new RuntimeException(
            "Volume incomplet : {$count} parcouru(s), {$expected} compte(s), {$volume} attendu(s)."
        );
    }

    $db->rollBack();
    $db->exec('DROP TEMPORARY TABLE formulaires_manquants');
    $temporaryTableCreated = false;
    echo "P6 VOLUME OK: {$count} lignes exportables, au-dela de l'ancien plafond de 5 000.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if ($temporaryTableCreated) {
        try {
            $db->exec('DROP TEMPORARY TABLE formulaires_manquants');
        } catch (Throwable) {
            // La fermeture du processus supprimera de toute facon la table temporaire.
        }
    }
    if ($structureTableCreated) {
        try {
            $db->exec('DROP TEMPORARY TABLE risfm_p6_formulaires_structure');
        } catch (Throwable) {
            // La fermeture du processus supprimera de toute facon la table temporaire.
        }
    }
    fwrite(STDERR, 'ECHEC: ' . $e->getMessage() . "\n");
    exit(1);
}
