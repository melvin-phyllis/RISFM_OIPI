<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\Permission;
use App\Exceptions\ValidationException;
use App\Http\Requests\Mission\DeclarerRetrouveFormRequest;
use App\Services\Formulaire\FormulaireService;
use App\Services\Mission\MissionRechercheService;

/**
 * Declaration directe d'un formulaire retrouve : droits par role, statut
 * Retrouve ou A verifier, mission et historique reconstitues, missions en
 * cours cloturees, creation atomique. Toutes les ecritures sont annulees.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';
require_once BASE_PATH . '/app/Core/helpers.php';

$db = Database::getConnection();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Droits : un agent signale, un responsable ou un administrateur valide.
$assert(Permission::has('agent', 'formulaires.declare_found') && !Permission::has('agent', 'formulaires.declare_found_validated'), 'un agent signale sans valider');
$assert(Permission::has('responsable', 'formulaires.declare_found_validated'), 'un responsable valide directement');
$assert(Permission::has('administrateur', 'formulaires.declare_found_validated'), 'un administrateur valide directement');
$assert(!Permission::has('consultation', 'formulaires.declare_found'), 'la consultation ne declare rien');

// Saisie : localisation obligatoire, date non future.
foreach ([
    ['retrouve_date' => date('Y-m-d')],
    ['retrouve_localisation_id' => '1', 'retrouve_date' => (new DateTimeImmutable('tomorrow'))->format('Y-m-d')],
] as $input) {
    try {
        (new DeclarerRetrouveFormRequest($input))->validated();
        $assert(false, 'une declaration incomplete ou datee dans le futur doit etre refusee');
    } catch (ValidationException) {
    }
}

$type = $db->query('SELECT id FROM types_titres WHERE actif = 1 ORDER BY ordre, id LIMIT 1')->fetchColumn();
$locations = $db->query('SELECT id FROM localisations WHERE actif = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if (!$type || count($locations) < 2) {
    fwrite(STDERR, "DECLARATION ECHEC: referentiels manquants.\n");
    exit(1);
}
[$locationA, $locationB] = array_map('intval', $locations);

$statusCode = static fn (int $formId): string => (string) $db->query(
    'SELECT s.code FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id WHERE f.id = ' . $formId
)->fetchColumn();
$count = static fn (string $sql): int => (int) $db->query($sql)->fetchColumn();

$db->beginTransaction();
try {
    $suffix = strtoupper(bin2hex(random_bytes(3)));
    $newUser = static function (string $role, string $tag) use ($db, $suffix): int {
        $db->prepare(
            'INSERT INTO utilisateurs (identifiant, nom, prenoms, email, mot_de_passe, role, actif)
             VALUES (:identifiant, :nom, :prenoms, :email, :pass, :role, 1)'
        )->execute([
            'identifiant' => "TEST-DECL-{$tag}-{$suffix}",
            'nom' => 'Test',
            'prenoms' => ucfirst($role),
            'email' => "decl.{$tag}.{$suffix}@example.invalid",
            'pass' => password_hash('x', PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        return (int) $db->lastInsertId();
    };
    $responsable = $newUser('responsable', 'R');
    $agent = $newUser('agent', 'A1');
    $autreAgent = $newUser('agent', 'A2');

    $formService = new FormulaireService();
    $missionService = new MissionRechercheService();
    $newForm = static fn (string $number): int => (int) $formService->srv_creer(
        ['type_titre_id' => (int) $type, 'annee' => (int) date('Y'), 'numero_formulaire' => $number],
        $responsable
    )['id'];
    $declaration = static fn (int $location): array => [
        'retrouve_localisation_id' => $location,
        'retrouve_date' => date('Y-m-d'),
        'retrouve_precision' => 'Carton 12',
    ];

    // 1. Declaration validee : les missions en cours d'un autre agent sont closes.
    $form1 = $newForm('DECL-A-' . $suffix);
    $missionService->srv_affecter($form1, [
        'affectation_localisation_id' => $locationB,
        'affectation_responsable_id' => $autreAgent,
        'affectation_date_echeance' => null,
        'affectation_priorite' => 'Normale',
        'confirmer_affectation_localisation_deja_recherchee' => false,
    ], $responsable);
    $closed = $missionService->srv_declarerRetrouve($form1, $declaration($locationA), $responsable, true);
    $assert($statusCode($form1) === 'retrouve', 'une declaration validee passe le formulaire a Retrouve');
    $assert(count($closed) === 1 && (int) $closed[0]['responsable_id'] === $autreAgent, 'la mission en cours de l autre agent est retournee pour notification');
    $assert($count("SELECT COUNT(*) FROM missions_recherche WHERE formulaire_id = {$form1} AND etat IN ('affectee','en_cours')") === 0, 'plus aucune mission active');
    $assert($count("SELECT COUNT(*) FROM missions_recherche WHERE formulaire_id = {$form1} AND etat = 'terminee' AND resultat_code = 'retrouve' AND responsable_id = {$responsable} AND localisation_id = {$locationA}") === 1, 'une mission terminee au nom du declarant est creee');
    $assert($count("SELECT COUNT(*) FROM recherches_formulaire WHERE formulaire_id = {$form1} AND localisation_id = {$locationA} AND resultat LIKE '%Carton 12%'") === 1, 'l historique garde le lieu et la precision');
    $assert($count("SELECT COUNT(*) FROM finalisations_formulaire WHERE formulaire_id = {$form1} AND etape = 'retrouve'") === 1, 'l etape Retrouve de la finalisation est creee');
    try {
        $missionService->srv_declarerRetrouve($form1, $declaration($locationA), $responsable, true);
        $assert(false, 'un formulaire deja retrouve ne peut pas etre declare une seconde fois');
    } catch (DomainException) {
    }

    // 2. Signalement d'un agent (A verifier) puis confirmation par un responsable.
    $form2 = $newForm('DECL-B-' . $suffix);
    $missionService->srv_declarerRetrouve($form2, $declaration($locationA), $agent, false);
    $assert($statusCode($form2) === 'a_verifier', 'un signalement d agent passe le formulaire a A verifier');
    $assert($count("SELECT COUNT(*) FROM finalisations_formulaire WHERE formulaire_id = {$form2}") === 0, 'un signalement ne cree aucune finalisation');
    $missionService->srv_declarerRetrouve($form2, $declaration($locationA), $responsable, true);
    $assert($statusCode($form2) === 'retrouve', 'le responsable confirme le signalement');

    // 3. La mission active du declarant sur ce lieu est reprise, pas dupliquee.
    $form3 = $newForm('DECL-C-' . $suffix);
    $missionService->srv_affecter($form3, [
        'affectation_localisation_id' => $locationA,
        'affectation_responsable_id' => $agent,
        'affectation_date_echeance' => null,
        'affectation_priorite' => 'Haute',
        'confirmer_affectation_localisation_deja_recherchee' => false,
    ], $responsable);
    $missionService->srv_declarerRetrouve($form3, $declaration($locationA), $agent, false);
    $assert($count("SELECT COUNT(*) FROM missions_recherche WHERE formulaire_id = {$form3}") === 1, 'la mission existante du declarant est cloturee au lieu d en creer une seconde');

    // 4. Creation d'un formulaire deja retrouve : tout ou rien.
    $created = $formService->srv_creerRetrouve(
        ['type_titre_id' => (int) $type, 'annee' => (int) date('Y'), 'numero_formulaire' => 'DECL-D-' . $suffix],
        $declaration($locationB),
        $responsable,
        true
    );
    $assert($statusCode((int) $created['id']) === 'retrouve', 'un formulaire peut etre enregistre directement comme retrouve');
    $db->exec("UPDATE localisations SET actif = 0 WHERE id = {$locationB}");
    try {
        $formService->srv_creerRetrouve(
            ['type_titre_id' => (int) $type, 'annee' => (int) date('Y'), 'numero_formulaire' => 'DECL-E-' . $suffix],
            $declaration($locationB),
            $responsable,
            true
        );
        $assert(false, 'une localisation inactive doit etre refusee');
    } catch (DomainException) {
    }
    $assert($count("SELECT COUNT(*) FROM formulaires_manquants WHERE numero_formulaire = 'DECL-E-{$suffix}'") === 0, 'un refus de declaration n enregistre pas le formulaire');

    // 5. Notification : un signalement previent les valideurs actifs.
    $missionService->srv_notifierDeclaration($form3, 'FM-TEST', false, [], $agent);
    $assert($count("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = {$responsable} AND titre = 'Formulaire signale retrouve'") === 1, 'le responsable est prevenu d un signalement');
    $assert($count("SELECT COUNT(*) FROM notifications WHERE utilisateur_id = {$agent} AND titre = 'Formulaire signale retrouve'") === 0, 'le declarant ne se previent pas lui-meme');
} catch (Throwable $e) {
    $failures[] = 'exception inattendue : ' . $e->getMessage();
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'ECHEC: ' . implode("\nECHEC: ", $failures) . "\n");
    exit(1);
}
echo "DECLARATION RETROUVE OK: droits, Retrouve et A verifier, confirmation, missions closes, historique, creation atomique et notifications verifies.\n";
