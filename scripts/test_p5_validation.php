<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Utilisateur\UserRepository;
use App\Services\Formulaire\FormulaireMetierValidator;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$db = Database::getConnection();
$type = $db->query('SELECT * FROM types_titres WHERE actif = 1 ORDER BY id LIMIT 1')->fetch();
$statusOpen = $db->query('SELECT * FROM statuts WHERE systeme = 1 AND actif = 1 AND resolu = 0 ORDER BY ordre LIMIT 1')->fetch();
$statusResolved = $db->query('SELECT * FROM statuts WHERE systeme = 1 AND actif = 1 AND resolu = 1 ORDER BY ordre LIMIT 1')->fetch();
$location = $db->query('SELECT * FROM localisations WHERE actif = 1 ORDER BY id LIMIT 1')->fetch();
$responsible = $db->query(
    "SELECT * FROM utilisateurs
     WHERE actif = 1 AND role IN ('administrateur', 'responsable', 'agent')
     ORDER BY id LIMIT 1"
)->fetch();

if (!$type || !$statusOpen || !$statusResolved || !$location || !$responsible) {
    fwrite(STDERR, "ECHEC: les referentiels P5 actifs sont incomplets.\n");
    exit(1);
}

$validator = new FormulaireMetierValidator();
$today = new DateTimeImmutable('today');
$valid = [
    'type_titre_id' => (string) $type['id'],
    'annee' => (string) date('Y'),
    'numero_formulaire' => 'TEST-P5-SANS-ECRITURE',
    'date_depot' => $today->modify('-2 days')->format('Y-m-d'),
    'deposant' => 'Test P5',
    'mandataire' => '',
    'statut_id' => (string) $statusOpen['id'],
    'localisation_id' => null,
    'responsable_id' => (string) $responsible['id'],
    'date_recherche' => null,
    'resultat' => '',
    'observations' => '',
    'niveau_urgence' => 'Moyen',
    'priorite' => 'Normale',
];

$failures = [];
$assertSame = static function (string $name, mixed $expected, mixed $actual) use (&$failures): void {
    if ($expected !== $actual) {
        $failures[] = $name . ' , attendu ' . var_export($expected, true) . ', recu ' . var_export($actual, true);
    }
};
$assertContains = static function (string $name, string $needle, ?string $actual) use (&$failures): void {
    if ($actual === null || !str_contains($actual, $needle)) {
        $failures[] = $name . ' , message inattendu : ' . var_export($actual, true);
    }
};

$assertSame('formulaire minimal valide', null, $validator->validateForm($valid));

$case = $valid;
$case['type_titre_id'] = $type['id'] . 'abc';
$assertContains('identifiant strict', 'type de titre', $validator->validateForm($case));

$case = $valid;
$case['type_titre_id'] = '999999999';
$assertContains('type inexistant', 'inexistant ou inactif', $validator->validateForm($case));

$case = $valid;
$case['statut_id'] = '999999999';
$assertContains('statut inexistant', 'inexistant ou inutilisable', $validator->validateForm($case));

$case = $valid;
$case['localisation_id'] = '999999999';
$assertContains('localisation inexistante', 'inexistante ou inactive', $validator->validateForm($case));

$case = $valid;
$case['responsable_id'] = '999999999';
$assertContains('responsable inexistant', 'inexistant ou inactif', $validator->validateForm($case));

$case = $valid;
$case['annee'] = date('Y') . 'abc';
$assertContains('annee stricte', 'quatre chiffres', $validator->validateForm($case));

$case = $valid;
$case['date_depot'] = date('Y') . '-02-30';
$assertContains('date impossible', 'date de depot', $validator->validateForm($case));

$case = $valid;
$case['date_depot'] = $today->modify('+1 day')->format('Y-m-d');
$assertContains('depot futur', 'futur', $validator->validateForm($case));

$case = $valid;
$case['priorite'] = 'Immediatement';
$assertContains('priorite enumeree', 'priorite', $validator->validateForm($case));

$resolved = $valid;
$resolved['statut_id'] = (string) $statusResolved['id'];
$assertContains('resolution incomplete', 'localisation', $validator->validateForm($resolved));

$changedStatus = $valid;
$assertContains(
    'changement de statut historise',
    'localisation',
    $validator->validateForm($changedStatus, [
        'annee' => date('Y'),
        'statut_id' => (int) $statusResolved['id'],
    ])
);

$research = $valid;
$research['statut_id'] = (string) $statusResolved['id'];
$research['localisation_id'] = (string) $location['id'];
$research['date_recherche'] = $today->modify('-1 day')->format('Y-m-d');
$research['resultat'] = 'Formulaire retrouve et verifie';
$assertSame('resolution complete', null, $validator->validateForm($research));

$research['resultat'] = '';
$assertContains('resultat exige pour resolution', 'statut resolu', $validator->validateForm($research));
$research['resultat'] = 'Formulaire retrouve et verifie';

$research['date_recherche'] = $today->modify('-3 days')->format('Y-m-d');
$assertContains('recherche avant depot', 'anterieure', $validator->validateForm($research));

$research['date_recherche'] = $today->modify('+1 day')->format('Y-m-d');
$assertContains('recherche future', 'futur', $validator->validateForm($research));

$assignment = [
    'localisation_id' => (string) $location['id'],
    'responsable_id' => (string) $responsible['id'],
    'date_echeance_recherche' => $today->modify('+3 days')->format('Y-m-d'),
    'priorite' => 'Haute',
];
$assertSame('affectation valide', null, $validator->validateAssignment($assignment));

$case = $assignment;
$case['date_echeance_recherche'] = $today->modify('-1 day')->format('Y-m-d');
$assertContains('echeance passee interdite', 'passe', $validator->validateAssignment($case));

$case = $assignment;
$case['localisation_id'] = '';
$assertContains('localisation affectation obligatoire', 'obligatoire', $validator->validateAssignment($case));

$case = $assignment;
$case['priorite'] = 'Immediate';
$assertContains('priorite affectation enumeree', 'priorite', $validator->validateAssignment($case));

$consultation = $db->query(
    "SELECT * FROM utilisateurs WHERE actif = 1 AND role = 'consultation' ORDER BY id LIMIT 1"
)->fetch();
if ($consultation) {
    $case = $valid;
    $case['responsable_id'] = (string) $consultation['id'];
    $assertContains('role responsable interdit', 'role autorise', $validator->validateForm($case));
}

$existing = $db->query(
    'SELECT id, annee, type_titre_id, numero_formulaire FROM formulaires_manquants ORDER BY id LIMIT 1'
)->fetch();
if ($existing) {
    $model = new FormulaireRepository();
    $assertSame(
        'doublon detecte',
        true,
        $model->repo_duplicateExists(
            (int) $existing['annee'],
            (int) $existing['type_titre_id'],
            (string) $existing['numero_formulaire']
        )
    );
    $assertSame(
        'ligne courante exclue en modification',
        false,
        $model->repo_duplicateExists(
            (int) $existing['annee'],
            (int) $existing['type_titre_id'],
            (string) $existing['numero_formulaire'],
            (int) $existing['id']
        )
    );
}

foreach ((new UserRepository())->repo_activeUsers() as $user) {
    if (!in_array((string) $user['role'], ['administrateur', 'responsable', 'agent'], true)) {
        $failures[] = 'liste des responsables , role interdit retourne : ' . $user['role'];
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "P5 OK: validation metier et detection des doublons verifiees sans ecriture en base.\n";
