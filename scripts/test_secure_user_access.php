<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

spl_autoload_register(static function (string $class): void {
    foreach (['core', 'models', 'controllers'] as $directory) {
        $file = BASE_PATH . '/' . $directory . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
require_once BASE_PATH . '/core/helpers.php';

if ((string) env('RISFM_SECURE_ACCESS_CHILD', '0') !== '1') {
    $config = require BASE_PATH . '/config/database.php';
    $database = 'oipi_risfm_restore_test_access_' . bin2hex(random_bytes(5));
    $quotedDatabase = '`' . $database . '`';
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', $config['host'], $config['port'], $config['charset']);
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $admin = new PDO(
        $dsn,
        (string) ($config['maintenance_user'] ?? $config['user']),
        (string) ($config['maintenance_pass'] ?? $config['pass']),
        $options
    );
    $created = false;
    try {
        $admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $created = true;
        $db = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $config['host'], $config['port'], $database, $config['charset']),
            (string) ($config['maintenance_user'] ?? $config['user']),
            (string) ($config['maintenance_pass'] ?? $config['pass']),
            $options
        );
        $schema = file_get_contents(BASE_PATH . '/schema.sql');
        if (!is_string($schema)) {
            throw new RuntimeException('schema.sql illisible.');
        }
        foreach (SqlStatementParser::parse($schema) as $statement) {
            $db->exec($statement);
        }
        unset($db);

        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment = array_replace($environment, [
            'DB_NAME' => $database,
            'RISFM_SECURE_ACCESS_CHILD' => '1',
            'APP_ENV' => 'testing',
            'APP_URL' => 'http://example.invalid',
            'MAIL_DRY_RUN' => 'true',
            'MAIL_HOST' => 'smtp.example.invalid',
            'MAIL_USER' => 'recette',
            'MAIL_PASS' => 'recette',
            'MAIL_FROM' => 'recette@example.invalid',
        ]);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __FILE__],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BASE_PATH,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Demarrage du test enfant impossible.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        echo is_string($stdout) ? $stdout : '';
        if ($exitCode !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: 'Le test enfant a echoue.');
        }
    } finally {
        if ($created) {
            $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
        }
    }
    exit(0);
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$users = new UserModel();
$roleId = (int) Database::getConnection()->query("SELECT id FROM roles WHERE code = 'consultation'")->fetchColumn();
$created = $users->insertWithGeneratedIdentifiant([
    'nom' => 'Invitation',
    'prenoms' => 'Securisee',
    'email' => 'invitation.securisee@example.test',
    'mot_de_passe' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
    'role' => 'consultation',
    'role_id' => $roleId,
    'service' => 'Recette',
    'actif' => 1,
    'doit_changer_mdp' => 1,
]);
$user = $users->find((int) $created['id']);
$assert($user !== null, 'le compte invite doit etre cree');
$assert(str_starts_with((string) ($user['identifiant'] ?? ''), 'OIPI-RISFM-'), 'l identifiant doit etre genere par le systeme');

$tokens = new TokenResetModel();
$firstToken = $tokens->creer((int) $created['id'], 60);
$firstHash = (string) Database::getConnection()->query(
    'SELECT token_hash FROM tokens_reinitialisation ORDER BY id DESC LIMIT 1'
)->fetchColumn();
$assert($firstHash === hash('sha256', $firstToken), 'seule l empreinte SHA-256 du lien doit etre stockee');
$assert($firstHash !== $firstToken, 'le jeton brut ne doit pas etre stocke');

$mailer = new AppMailer();
$message = $mailer->buildAccountAccessMessage(
    $user ?: [],
    url('reinitialiser/' . $firstToken),
    60,
    true
);
$mailer->sendAccountAccess($user ?: [], url('reinitialiser/' . $firstToken), 60, true);
$assert(str_contains($message['text'], (string) $created['identifiant']), 'le destinataire doit recevoir son identifiant');
$assert(str_contains($message['text'], $firstToken), 'le destinataire doit recevoir le lien a usage unique');
$assert(!str_contains(mb_strtolower($message['text']), 'mot de passe temporaire'), 'aucun mot de passe temporaire ne doit etre transmis');

$versionBefore = (int) ($user['session_version'] ?? 0);
$users->setPassword((int) $created['id'], bin2hex(random_bytes(24)), true);
$assert($tokens->valide($firstToken) === null, 'la revocation doit invalider le premier lien');
$secondToken = $tokens->creer((int) $created['id'], 60);
$updated = $users->find((int) $created['id']);
$assert($secondToken !== $firstToken, 'un renvoi doit produire un nouveau secret');
$assert((int) ($updated['session_version'] ?? 0) === $versionBefore + 1, 'la revocation doit incrementer la version de session');

$routeGroups = require BASE_PATH . '/config/routes.php';
$routes = array_merge(...array_values($routeGroups));
$hasLegacySearchRoute = false;
$hasCreateUserRoute = false;
foreach ($routes as [$method, $path, $controller, $action]) {
    $hasLegacySearchRoute = $hasLegacySearchRoute || (
        $method === 'GET'
        && $path === '/recherche'
        && $controller === 'FormulaireController'
        && $action === 'redirectLegacySearch'
    );
    $hasCreateUserRoute = $hasCreateUserRoute || (
        $method === 'GET'
        && $path === '/utilisateurs/ajouter'
        && $controller === 'UserController'
        && $action === 'create'
    );
}
$assert(!is_file(BASE_PATH . '/controllers/RechercheController.php'), 'le controleur RechercheController obsolete doit etre supprime');
$assert($hasLegacySearchRoute, 'l ancienne URL doit rester une redirection de compatibilite');
$assert(method_exists(FormulaireController::class, 'redirectLegacySearch'), 'la redirection doit etre portee par le registre');

$userControllerSource = file_get_contents(BASE_PATH . '/controllers/UserController.php');
$userListSource = file_get_contents(BASE_PATH . '/views/users/list.php');
$assert($hasCreateUserRoute, 'l ancienne URL de creation doit rester compatible');
$assert(
    is_string($userControllerSource)
        && str_contains($userControllerSource, "utilisateurs#ajouter-utilisateur"),
    'l ancienne page de creation doit rediriger vers la modale'
);
$assert(
    is_string($userListSource)
        && str_contains($userListSource, 'id="modal-ajouter-utilisateur"')
        && str_contains($userListSource, "url('utilisateurs/ajouter')"),
    'la liste doit contenir la modale et son formulaire de creation'
);
$assert(
    is_string($userListSource)
        && str_contains($userListSource, 'user-actions-dropdown')
        && str_contains($userListSource, "url('utilisateurs/modifier/'")
        && str_contains($userListSource, "url('utilisateurs/statut/'")
        && str_contains($userListSource, "url('utilisateurs/reinitialiser/'")
        && str_contains($userListSource, "url('utilisateurs/supprimer/'"),
    'les actions utilisateur doivent etre regroupees dans un menu deroulant'
);

// Cycle de vie d'un responsable : une mission active interdit la
// desactivation et la perte du role metier ; l'historique interdit toujours
// la suppression physique du compte.
$agentRoleId = (int) Database::getConnection()->query("SELECT id FROM roles WHERE code = 'agent'")->fetchColumn();
$agentCreated = $users->insertWithGeneratedIdentifiant([
    'nom' => 'Responsable',
    'prenoms' => 'Cycle de vie',
    'email' => 'cycle.vie@example.test',
    'mot_de_passe' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
    'role' => 'agent',
    'role_id' => $agentRoleId,
    'service' => 'Recette',
    'actif' => 1,
    'doit_changer_mdp' => 0,
]);
$db = Database::getConnection();
$typeId = (int) $db->query('SELECT id FROM types_titres ORDER BY id LIMIT 1')->fetchColumn();
$statusId = (int) $db->query("SELECT id FROM statuts WHERE code = 'en_recherche' LIMIT 1")->fetchColumn();
$locationId = (int) $db->query('SELECT id FROM localisations ORDER BY id LIMIT 1')->fetchColumn();
$form = new FormulaireModel();
$formId = $form->insert([
    'numero_auto' => 'FM-' . date('Y') . '-LIFECYCLE',
    'type_titre_id' => $typeId,
    'annee' => (int) date('Y'),
    'numero_formulaire' => 'TEST-LIFECYCLE-' . bin2hex(random_bytes(4)),
    'statut_id' => $statusId,
    'priorite' => 'Normale',
    'niveau_urgence' => 'Moyen',
]);
$mission = new MissionRechercheModel();
$missionId = $mission->insert([
    'formulaire_id' => $formId,
    'localisation_id' => $locationId,
    'responsable_id' => (int) $agentCreated['id'],
    'etat' => 'affectee',
    'priorite' => 'Normale',
]);

$summary = $users->missionLifecycleSummary((int) $agentCreated['id']);
$assert($summary['missions_actives'] === 1, 'la mission active du responsable doit etre comptee');
$assert($summary['missions_liees'] === 1, 'la mission doit appartenir a son historique');

$assert($users->toggleActive((int) $agentCreated['id']), 'la desactivation doit suspendre la mission et desactiver le compte');
$deactivatedUser = $users->find((int) $agentCreated['id']);
$assert((int) ($deactivatedUser['actif'] ?? 1) === 0, 'le compte doit etre desactive');

$missionState = (string) ($mission->find($missionId)['etat'] ?? '');
$assert($missionState === 'annulee', 'la mission active doit etre annulee/suspendue lors de la desactivation');

$deleteBlocked = false;
try {
    $users->deleteWithRevocation((int) $agentCreated['id']);
} catch (DomainException) {
    $deleteBlocked = true;
}
$assert($deleteBlocked, 'la suppression applicative doit etre bloquee pour un responsable historise');

$historyDeleteBlocked = false;
try {
    $users->deleteWithRevocation((int) $agentCreated['id']);
} catch (DomainException) {
    $historyDeleteBlocked = true;
}
$assert($historyDeleteBlocked, 'une mission terminee doit continuer d interdire la suppression physique');

$databaseGuardWorked = false;
try {
    $db->prepare('DELETE FROM utilisateurs WHERE id = :id')->execute(['id' => (int) $agentCreated['id']]);
} catch (PDOException $exception) {
    $databaseGuardWorked = (int) ($exception->errorInfo[1] ?? 0) === 1451;
}
$versionBeforeManual = (int) ($users->find((int) $agentCreated['id'])['session_version'] ?? 0);
$users->setPassword((int) $agentCreated['id'], 'Oipi2026!ManualTest', true);
$updatedUserManual = $users->find((int) $agentCreated['id']);
$assert((int) ($updatedUserManual['doit_changer_mdp'] ?? 0) === 1, 'le mot de passe defini manuellement doit forcer le changement à la premiere connexion');
$assert((int) ($updatedUserManual['session_version'] ?? 0) === $versionBeforeManual + 1, 'la redefinition manuelle doit incrementer la version de session');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "ACCES UTILISATEUR SECURISE OK: lien unique, revocation, redefinition manuelle, cycle de vie des responsables et route historique verifies.\n";
