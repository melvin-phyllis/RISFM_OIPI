<?php
declare(strict_types=1);

use App\Core\AppMailer;
use App\Core\Auth;
use App\Core\Database;
use App\Core\LoginOtp;
use App\Core\Permission;
use App\Core\Security;
use App\Core\SqlStatementParser;
use App\Services\Formulaire\FormulaireService;
use App\Repositories\Formulaire\FinalisationFormulaireRepository;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Repositories\Notification\NotificationRepository;
use App\Repositories\Referentiel\StatutRepository;
use App\Repositories\Utilisateur\UserRepository;

/**
 * Recette P12 isolee : ce script cree sa propre base ephemere, execute les
 * controles metier/HTTP/navigateur, puis supprime la base dans tous les cas.
 */

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
require_once BASE_PATH . '/app/Core/helpers.php';
require_once BASE_PATH . '/scripts/test_support.php';

/** @return array<string,string> */
function p12Environment(array $overrides = []): array
{
    $environment = getenv();
    return array_merge(is_array($environment) ? $environment : [], $overrides);
}

/** @return array{exit:int,stdout:string,stderr:string} */
function p12Process(array $command, array $environment, int $timeoutSeconds = 120): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Impossible de lancer le processus de recette.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $startedAt = microtime(true);
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $startedAt) > $timeoutSeconds) {
            proc_terminate($process);
            throw new RuntimeException('Le processus de recette a depasse le delai autorise.');
        }
        usleep(20_000);
    } while (true);
    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit === -1) {
        $exit = (int) ($status['exitcode'] ?? -1);
    }
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
}

if (($argv[1] ?? '') === '--cdp-probe' && isset($argv[2])) {
    $probe = new P12CdpClient((string) $argv[2]);
    $probe->command('Browser.getVersion');
    $probe->close();
    echo "CDP OK\n";
    exit(0);
}

if (in_array('--numero-worker', $argv, true)) {
    $position = array_search('--numero-worker', $argv, true);
    $worker = (int) ($argv[$position + 1] ?? 0);
    $typeId = (int) ($argv[$position + 2] ?? 0);
    $statusId = (int) ($argv[$position + 3] ?? 0);
    $userId = (int) ($argv[$position + 4] ?? 0);
    $result = (new FormulaireRepository())->repo_insertWithGeneratedNumero([
        'type_titre_id' => $typeId,
        'annee' => 2025,
        'numero_formulaire' => sprintf('P12-CONCURRENT-%02d', $worker),
        'statut_id' => $statusId,
        'responsable_id' => $userId,
        'niveau_urgence' => 'Moyen',
        'priorite' => 'Normale',
        'cree_par' => $userId,
    ]);
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit(0);
}

if (!in_array('--inside', $argv, true)) {
    $config = require BASE_PATH . '/config/database.php';
    $database = 'oipi_risfm_restore_test_p12_' . bin2hex(random_bytes(5));
    $quotedDatabase = '`' . $database . '`';
    $serverDsn = sprintf(
        'mysql:host=%s;port=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['charset']
    );
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $admin = new PDO(
        $serverDsn,
        (string) ($config['maintenance_user'] ?? $config['user']),
        (string) ($config['maintenance_pass'] ?? $config['pass']),
        $options
    );
    $created = false;
    $childResult = null;
    try {
        $admin->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $created = true;
        $db = new PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $database,
                $config['charset']
            ),
            (string) ($config['maintenance_user'] ?? $config['user']),
            (string) ($config['maintenance_pass'] ?? $config['pass']),
            $options
        );
        $schema = file_get_contents(BASE_PATH . '/schema.sql');
        if (!is_string($schema)) {
            throw new RuntimeException('schema.sql illisible.');
        }
        foreach (SqlStatementParser::parse($schema) as $position => $statement) {
            try {
                $db->exec($statement);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Import schema.sql instruction #' . ($position + 1) . ' : ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }
        }
        risfmSeed($db, demo: true);

        $childResult = p12Process(
            [PHP_BINARY, __FILE__, '--inside'],
            p12Environment([
                'APP_ENV' => 'testing',
                'APP_DEBUG' => 'true',
                'DB_NAME' => $database,
                'ENABLE_LOGIN_OTP' => 'false',
                'ENABLE_CAPTCHA' => 'false',
                'MAIL_DRY_RUN' => 'true',
                'MAIL_HOST' => 'smtp.example.invalid',
                'MAIL_USER' => 'recette',
                'MAIL_PASS' => 'recette',
                'MAIL_FROM' => 'recette@example.invalid',
                'SESSION_NAME' => 'RISFM_P12_' . bin2hex(random_bytes(4)),
            ]),
            240
        );
    } finally {
        if ($created) {
            $admin->exec('DROP DATABASE IF EXISTS ' . $quotedDatabase);
        }
    }
    if (!is_array($childResult)) {
        throw new RuntimeException('La recette P12 ne s est pas executee.');
    }
    echo $childResult['stdout'];
    if ($childResult['stderr'] !== '') {
        fwrite(STDERR, $childResult['stderr']);
    }
    exit($childResult['exit']);
}

$databaseName = (string) (require BASE_PATH . '/config/database.php')['dbname'];
if (APP_ENV !== 'testing' || !str_starts_with($databaseName, 'oipi_risfm_restore_test_p12_')) {
    fwrite(STDERR, "ECHEC: P12 refuse de s'executer hors de sa base temporaire.\n");
    exit(1);
}

$db = Database::getConnection();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$scalar = static function (string $sql, array $parameters = []) use ($db): mixed {
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
};

// Jeu de donnees volontairement connu et reproductible.
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'notification_lectures', 'notifications', 'pieces_jointes', 'reouvertures_formulaire',
    'finalisations_formulaire',
    'recherches_formulaire', 'missions_recherche',
    'formulaires_manquants', 'tokens_reinitialisation', 'tentatives_connexion',
    'connexions', 'activites',
] as $table) {
    $db->exec('TRUNCATE TABLE ' . $table);
}
$db->exec('DELETE FROM utilisateurs WHERE id <> 1');
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

$password = 'Recette@2026!';
$adminUpdate = $db->prepare(
    'UPDATE utilisateurs SET email = :email, mot_de_passe = :password,
     actif = 1, doit_changer_mdp = 0, session_version = 1 WHERE id = 1'
);
$adminUpdate->execute([
    'email' => 'p12.admin@example.invalid',
    'password' => password_hash($password, PASSWORD_DEFAULT),
]);

$roleIds = [];
foreach ($db->query('SELECT id, code FROM roles')->fetchAll() as $role) {
    $roleIds[(string) $role['code']] = (int) $role['id'];
}
$users = ['administrateur' => 1];
$insertUser = $db->prepare(
    'INSERT INTO utilisateurs
        (identifiant, nom, prenoms, email, mot_de_passe, role, role_id,
         service, actif, doit_changer_mdp, session_version, cree_par)
     VALUES
        (:identifiant, :nom, :prenoms, :email, :password, :role, :role_id,
         :service, 1, 0, 1, 1)'
);
foreach (['responsable', 'agent', 'consultation'] as $index => $role) {
    $insertUser->execute([
        'identifiant' => sprintf('OIPI-RISFM-%06d', $index + 2),
        'nom' => 'Recette',
        'prenoms' => ucfirst($role),
        'email' => "p12.{$role}@example.invalid",
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => $role,
        'role_id' => $roleIds[$role],
        'service' => 'Recette P12',
    ]);
    $users[$role] = (int) $db->lastInsertId();
}

$typeId = (int) $scalar("SELECT id FROM types_titres WHERE code = 'marque'");
$openStatusId = (int) $scalar("SELECT id FROM statuts WHERE code = 'introuvable'");
$inResearchStatusId = (int) $scalar("SELECT id FROM statuts WHERE code = 'en_recherche'");
$resolvedStatusId = (int) $scalar("SELECT id FROM statuts WHERE code = 'retrouve'");
$numerizedStatusId = (int) $scalar("SELECT id FROM statuts WHERE code = 'numerise'");
$enteredStatusId = (int) $scalar("SELECT id FROM statuts WHERE code = 'saisi'");
$locationId = (int) $scalar('SELECT id FROM localisations ORDER BY id LIMIT 1');

// Authentification, politique du mot de passe, 2FA et matrice RBAC.
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'RISFM P12';
$assert(Auth::validateCredentials('OIPI-RISFM-000002', 'incorrect') === null, 'un mauvais mot de passe doit etre refuse');
$assert(Auth::validateCredentials('OIPI-RISFM-000002', $password) !== null, 'un mot de passe correct doit etre accepte');
$assert(Security::passwordPolicyError('faible') !== null, 'un mot de passe faible doit etre refuse');
$assert(Security::passwordPolicyError($password) === null, 'le mot de passe fort de recette doit etre accepte');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
LoginOtp::start($users['agent'], 'OIPI-RISFM-000003', 1, '123456');
$assert(LoginOtp::verify('000000')['status'] === 'invalid', 'un code 2FA incorrect doit etre refuse');
$assert(LoginOtp::verify('123456')['status'] === 'valid', 'un code 2FA correct doit etre accepte une seule fois');
$assert(LoginOtp::verify('123456')['status'] === 'missing', 'un code 2FA consomme ne doit pas etre reutilisable');

$permissionCases = [
    ['administrateur', 'utilisateurs.manage', true],
    ['administrateur', 'formulaires.record_result_any', true],
    ['administrateur', 'formulaires.reopen', true],
    ['administrateur', 'formulaires.import', true],
    ['responsable', 'statistiques.view', true],
    ['responsable', 'utilisateurs.manage', false],
    ['responsable', 'formulaires.update_metadata', true],
    ['responsable', 'formulaires.assign', true],
    ['responsable', 'formulaires.finalize', true],
    ['responsable', 'formulaires.reopen', false],
    ['responsable', 'formulaires.record_result_any', false],
    ['responsable', 'formulaires.import', false],
    ['agent', 'formulaires.record_result_own', true],
    ['agent', 'formulaires.finalize', false],
    ['agent', 'formulaires.reopen', false],
    ['agent', 'formulaires.update_metadata', false],
    ['agent', 'formulaires.assign', false],
    ['agent', 'formulaires.export', false],
    ['consultation', 'formulaires.view', true],
    ['consultation', 'formulaires.create', false],
];
foreach ($permissionCases as [$role, $permission, $expected]) {
    $assert(Permission::has($role, $permission) === $expected, "permission {$permission} incorrecte pour {$role}");
}

// Reservations concurrentes : les processus demarrent avant toute lecture.
$workers = [];
for ($index = 1; $index <= 8; $index++) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--numero-worker', (string) $index, (string) $typeId, (string) $openStatusId, (string) $users['agent']],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH,
        p12Environment()
    );
    if (!is_resource($process)) {
        $failures[] = "processus concurrent #{$index} impossible";
        continue;
    }
    fclose($pipes[0]);
    $workers[] = ['index' => $index, 'process' => $process, 'out' => $pipes[1], 'err' => $pipes[2]];
}
$references = [];
foreach ($workers as $worker) {
    $output = stream_get_contents($worker['out']);
    $error = stream_get_contents($worker['err']);
    fclose($worker['out']);
    fclose($worker['err']);
    $exit = proc_close($worker['process']);
    if ($exit !== 0) {
        $failures[] = "creation concurrente #{$worker['index']} echouee : " . trim($error);
        continue;
    }
    try {
        $row = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $references[] = (string) ($row['numero_auto'] ?? '');
    } catch (Throwable $exception) {
        $failures[] = "sortie concurrente #{$worker['index']} invalide";
    }
}
sort($references);
$assert(count($references) === 8, 'les huit creations concurrentes doivent reussir');
$assert(count(array_unique($references)) === 8, 'les numeros automatiques concurrents doivent etre uniques');
$assert(
    $references === array_map(static fn (int $n): string => sprintf('FM-2025-%06d', $n), range(1, 8)),
    'les numeros concurrents doivent former une sequence complete de 1 a 8'
);

// Trois dossiers exactement en 2024 : deux ouverts et un resolu.
$formRepository = new FormulaireRepository();
$missionRepository = new MissionRechercheRepository();
$knownIds = [];
foreach ([
    ['P12-KNOWN-1', $openStatusId, $users['agent']],
    ['P12-KNOWN-2', $openStatusId, $users['responsable']],
    ['P12-KNOWN-3', $resolvedStatusId, $users['responsable']],
] as $known) {
    $created = $formRepository->repo_insertWithGeneratedNumero([
        'type_titre_id' => $typeId,
        'annee' => 2024,
        'numero_formulaire' => $known[0],
        'statut_id' => $known[1],
        'localisation_id' => $locationId,
        'responsable_id' => $known[2],
        'date_recherche' => '2024-07-15',
        'resultat' => $known[1] === $resolvedStatusId ? 'Retrouve pendant la recette' : 'En cours',
        'niveau_urgence' => 'Moyen',
        'priorite' => 'Normale',
        'cree_par' => 1,
    ]);
    $knownIds[] = $created['id'];
}
$yearStats = null;
foreach ($formRepository->repo_statsParAnnee() as $row) {
    if ((int) $row['annee'] === 2024) {
        $yearStats = $row;
        break;
    }
}
$assert($yearStats !== null, 'les statistiques 2024 doivent exister');
$assert((int) ($yearStats['total_formulaires'] ?? -1) === 3, 'le total 2024 doit etre 3');
$assert((int) ($yearStats['total_retrouves'] ?? -1) === 1, 'le total resolu 2024 doit etre 1');
$assert((int) ($yearStats['total_restants'] ?? -1) === 2, 'le total restant 2024 doit etre 2');
$responsibleStats = $formRepository->repo_statsPourResponsable($users['responsable']);
$assert($responsibleStats === ['total_dossiers' => 2, 'total_resolus' => 1], 'les statistiques du responsable doivent etre exactes');
$currentMonth = date('Y-m');
$monthly = array_values(array_filter(
    $formRepository->repo_statsMensuelles(),
    static fn (array $row): bool => (string) $row['mois'] === $currentMonth
));
$assert(count($monthly) === 1 && (int) $monthly[0]['retrouves_dans_le_mois'] === 1, 'la progression mensuelle doit utiliser date_resolution');

$detailedStats = $formRepository->repo_statistiquesDetaillees(['annee' => 2024]);
$assert(
    (int) ($detailedStats['kpi']['total_formulaires'] ?? -1) === 3,
    'les KPI detailles doivent respecter le filtre par annee du titre'
);
$assert(
    (int) ($detailedStats['kpi']['total_resolus'] ?? -1) === 1
    && (int) ($detailedStats['kpi']['total_restants'] ?? -1) === 2,
    'les KPI detailles doivent distinguer les dossiers resolus et restants'
);
$assert(
    (int) ($detailedStats['kpi']['dossiers_non_assignes'] ?? -1) === 2,
    'les dossiers ouverts sans mission active doivent etre signales comme non assignes'
);
$assert(
    count($detailedStats['mensuelles']) === 1
    && (int) ($detailedStats['mensuelles'][0]['resolutions_dans_le_mois'] ?? -1) === 1,
    'la serie mensuelle detaillee doit compter les premieres resolutions'
);

// Doublon protege a la fois par le modele et par la contrainte SQL.
$assert($formRepository->repo_duplicateExists(2024, $typeId, 'P12-KNOWN-1'), 'le doublon metier doit etre detecte');
try {
    $formRepository->repo_insertWithGeneratedNumero([
        'type_titre_id' => $typeId,
        'annee' => 2024,
        'numero_formulaire' => 'P12-KNOWN-1',
        'statut_id' => $openStatusId,
        'niveau_urgence' => 'Moyen',
        'priorite' => 'Normale',
        'cree_par' => 1,
    ]);
    $failures[] = 'la contrainte SQL a accepte un doublon metier';
} catch (PDOException $exception) {
    $assert((string) $exception->getCode() === '23000', 'le doublon SQL doit produire une violation de contrainte');
}

// Reaffectation : ancien responsable, nouveau responsable, e-mail et audit.
$_SESSION = [
    'user_id' => 1,
    'user_identifiant' => 'OIPI-RISFM-000001',
    'user_nom' => 'Administrateur Systeme',
    'user_role' => 'administrateur',
    'session_version' => 1,
];
$reassignedId = $knownIds[0];
$before = $formRepository->repo_find($reassignedId);
$formRepository->repo_update($reassignedId, ['responsable_id' => $users['responsable']]);
$after = $formRepository->repo_find($reassignedId);
// Les notifications et l'e-mail de reaffectation sont verifies plus bas, sur
// la route HTTP /missions-recherche/reaffecter reellement utilisee.
$formulaireService = new FormulaireService();
$journal = new ReflectionMethod($formulaireService, 'journaliserChangements');
$journal->invoke($formulaireService, $before, $after, $reassignedId, 'modification', 'Modification de recette P12', 1);

$auditTrace = (int) $scalar(
    "SELECT COUNT(*) FROM activites WHERE type_action = 'reaffectation' AND entite_id = :id
       AND donnees_avant IS NOT NULL AND donnees_apres IS NOT NULL",
    ['id' => $reassignedId]
);
$assert($auditTrace === 1, 'la reaffectation doit conserver avant/apres dans le journal');

$newUser = (new UserRepository())->repo_find($users['responsable']);
$fullForm = $formRepository->repo_findWithRelations($reassignedId);
$assignmentMessage = (new AppMailer())->buildAssignmentMessage(
    $newUser ?: [],
    $fullForm ?: [],
    'http://example.invalid/formulaires/voir/' . $reassignedId,
    true
);
$assert(str_contains($assignmentMessage['subject'], (string) ($fullForm['numero_auto'] ?? '')), 'le sujet du mail doit contenir la reference');
$assert(str_contains($assignmentMessage['text'], 'réaffecté'), 'le mail doit distinguer une reaffectation');
$assert(str_contains($assignmentMessage['html'], 'http://example.invalid/formulaires/voir/'), 'le mail doit contenir le lien du dossier');

$accountAccessMessage = (new AppMailer())->buildAccountAccessMessage(
    $newUser ?: [],
    'http://example.invalid/reinitialiser/' . str_repeat('a', 64),
    60,
    true
);
$assert(str_contains($accountAccessMessage['text'], (string) ($newUser['identifiant'] ?? '')), 'l invitation doit contenir l identifiant genere');
$assert(str_contains($accountAccessMessage['html'], '/reinitialiser/'), 'l invitation doit contenir le lien a usage unique');
$assert(!str_contains(mb_strtolower($accountAccessMessage['text']), 'mot de passe temporaire'), 'aucun mot de passe temporaire ne doit etre envoye');

// Lecture individuelle d'une notification generale.
$notifications = new NotificationRepository();
$generalId = $notifications->repo_creer(null, 'Information P12', 'Notification generale de recette');
$agentBefore = $notifications->repo_nonLuesCount($users['agent']);
$responsibleBefore = $notifications->repo_nonLuesCount($users['responsable']);
$notifications->repo_marquerLue($generalId, $users['agent']);
$assert($notifications->repo_nonLuesCount($users['agent']) === $agentBefore - 1, 'la lecture generale doit etre propre a l agent');
$assert($notifications->repo_nonLuesCount($users['responsable']) === $responsibleBefore, 'la lecture de l agent ne doit pas lire la notification du responsable');

/** @return array{status:int,headers:string,body:string,content_type:string} */
$httpRequest = static function (
    string $baseUrl,
    string $path,
    string $cookieJar,
    string $method = 'GET',
    array $data = []
): array {
    $handle = curl_init($baseUrl . $path);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => 'RISFM-P12-HTTP',
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $raw = curl_exec($handle);
    if (!is_string($raw)) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Requete HTTP P12 impossible : ' . $message);
    }
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $result = [
        'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
        'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
    ];
    curl_close($handle);
    return $result;
};

$login = static function (string $baseUrl, string $identifiant, string $cookieJar) use ($httpRequest, $password): array {
    $page = $httpRequest($baseUrl, '/login', $cookieJar);
    if (!preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page['body'], $matches)) {
        throw new RuntimeException('Jeton CSRF de connexion introuvable.');
    }
    return $httpRequest($baseUrl, '/login', $cookieJar, 'POST', [
        'csrf_token' => $matches[1],
        'identifiant' => $identifiant,
        'mot_de_passe' => $password,
    ]);
};

// Serveur HTTP reel utilise pour les roles, exports et revocation.
$socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
if (!is_resource($socket)) {
    throw new RuntimeException('Port HTTP de recette indisponible : ' . $socketMessage);
}
$socketName = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr(strrchr((string) $socketName, ':'), 1);
$baseUrl = 'http://127.0.0.1:' . $port;
$serverLog = tempnam(sys_get_temp_dir(), 'risfm_p12_server_');
$serverProcess = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', BASE_PATH . '/public', BASE_PATH . '/public/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $serverPipes,
    BASE_PATH,
    p12Environment([
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_URL' => $baseUrl,
        'ENABLE_LOGIN_OTP' => 'false',
        'ENABLE_CAPTCHA' => 'false',
        'MAIL_DRY_RUN' => 'true',
    ])
);
if (!is_resource($serverProcess)) {
    throw new RuntimeException('Demarrage du serveur HTTP de recette impossible.');
}
fclose($serverPipes[0]);
$cookieFiles = [];
$chromeProcess = null;
$chromeDirectory = null;
$chromeLog = null;

try {
    $ready = false;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @file_get_contents($baseUrl . '/login');
        if (is_string($probe)) {
            $ready = true;
            break;
        }
        usleep(50_000);
    }
    $assert($ready, 'le serveur HTTP de recette doit demarrer');

    $roleRoutes = [
        'administrateur' => ['/utilisateurs' => 200, '/statistiques' => 200, '/formulaires/ajouter' => 200, '/formulaires/importer' => 200, '/parametres' => 200, '/journal' => 200, '/connexions' => 200, '/recherche' => 302],
        'responsable' => ['/utilisateurs' => 403, '/statistiques' => 200, '/formulaires/ajouter' => 200, '/formulaires/importer' => 403, '/parametres' => 403, '/journal' => 200, '/connexions' => 200, '/recherche' => 302],
        'agent' => ['/utilisateurs' => 403, '/statistiques' => 403, '/formulaires/ajouter' => 200, '/formulaires/importer' => 403, '/parametres' => 403, '/journal' => 403, '/connexions' => 403, '/recherche' => 302],
        'consultation' => ['/utilisateurs' => 403, '/statistiques' => 403, '/formulaires/ajouter' => 403, '/formulaires/importer' => 403, '/parametres' => 403, '/journal' => 403, '/connexions' => 403, '/recherche' => 302],
    ];
    $adminCookie = '';
    $roleCookies = [];
    foreach ($roleRoutes as $role => $routes) {
        $cookie = tempnam(sys_get_temp_dir(), 'risfm_p12_cookie_');
        $cookieFiles[] = $cookie;
        $identifiant = (string) $scalar('SELECT identifiant FROM utilisateurs WHERE id = :id', ['id' => $users[$role]]);
        $loginResult = $login($baseUrl, $identifiant, $cookie);
        $roleCookies[$role] = $cookie;
        $assert($loginResult['status'] === 302 && str_contains($loginResult['headers'], '/dashboard'), "connexion HTTP impossible pour {$role}");
        foreach ($routes as $route => $expectedStatus) {
            $response = $httpRequest($baseUrl, $route, $cookie);
            $assert($response['status'] === $expectedStatus, "{$role} : {$route} retourne {$response['status']} au lieu de {$expectedStatus}");
        }
        $exportStatus = $httpRequest($baseUrl, '/exports/formulaires/csv', $cookie)['status'];
        $expectedExportStatus = in_array($role, ['administrateur', 'responsable'], true) ? 200 : 403;
        $assert($exportStatus === $expectedExportStatus, "droit d export incorrect pour {$role}");
        $statisticsExportStatus = $httpRequest($baseUrl, '/exports/statistiques/excel?annee=2024', $cookie)['status'];
        $assert(
            $statisticsExportStatus === $expectedExportStatus,
            "droit d export des statistiques incorrect pour {$role}"
        );
        if ($role === 'administrateur') {
            $adminCookie = $cookie;
        }
    }

    $legacySearch = $httpRequest($baseUrl, '/recherche', $adminCookie);
    $assert(
        $legacySearch['status'] === 302 && str_contains($legacySearch['headers'], '/formulaires'),
        'l ancienne route /recherche doit rediriger vers le registre fusionne'
    );

    // Le serveur PHP integre renseigne parfois SCRIPT_NAME avec l'URL
    // demandee lorsqu'elle se termine par une extension. Le nom SQL ne doit
    // donc jamais faire disparaitre le prefixe de la route de telechargement.
    $missingBackupDownload = $httpRequest(
        $baseUrl,
        '/sauvegardes/telecharger/risfm_backup_inexistant.sql',
        $adminCookie
    );
    $assert(
        $missingBackupDownload['status'] === 302
            && str_contains($missingBackupDownload['headers'], '/sauvegardes'),
        'la route de telechargement SQL doit fonctionner avec le serveur PHP integre'
    );

    // Journal d'audit : types reels, filtrage serveur et detail avant/apres
    // consultable uniquement par les roles disposant du droit journal.view.
    $journalPage = $httpRequest($baseUrl, '/journal', $adminCookie);
    $assert(
        str_contains($journalPage['body'], 'id="journal-filter-panel"')
            && str_contains($journalPage['body'], 'id="modal-journal-detail"')
            && str_contains($journalPage['body'], 'value="reaffectation"'),
        'le journal doit proposer ses filtres dynamiques et la modale de detail'
    );
    $journalFiltered = $httpRequest(
        $baseUrl,
        '/api/journal-datatable?draw=1&start=0&length=25&type_action=reaffectation',
        $adminCookie
    );
    $journalFilteredData = json_decode($journalFiltered['body'], true);
    $assert(
        $journalFiltered['status'] === 200
            && is_array($journalFilteredData)
            && (int) ($journalFilteredData['recordsFiltered'] ?? 0) >= 1
            && str_contains((string) ($journalFilteredData['data'][0]['type_action'] ?? ''), 'Reaffectation'),
        'le filtre serveur du journal doit retourner les reaffectations avec leur libelle metier'
    );
    $auditDetailId = (int) $scalar(
        "SELECT id FROM activites
         WHERE donnees_avant IS NOT NULL AND donnees_apres IS NOT NULL
         ORDER BY id DESC LIMIT 1"
    );
    $auditDetail = $httpRequest($baseUrl, '/api/journal-detail/' . $auditDetailId, $adminCookie);
    $auditDetailData = json_decode($auditDetail['body'], true);
    $assert(
        $auditDetailId > 0
            && $auditDetail['status'] === 200
            && !empty($auditDetailData['success'])
            && str_contains((string) ($auditDetailData['activity']['details_html'] ?? ''), 'audit-change-list'),
        'le detail du journal doit restituer la comparaison avant/apres'
    );
    $assert(
        $httpRequest($baseUrl, '/api/journal-detail/' . $auditDetailId, $roleCookies['agent'])['status'] === 403,
        'un agent sans droit journal ne doit pas consulter le detail d audit'
    );

    // Une ligne encore marquee active en base mais trop ancienne doit etre
    // presentee comme expiree selon derniere_activite.
    $staleConnection = $db->prepare(
        "INSERT INTO connexions
            (utilisateur_id, adresse_ip, navigateur, statut, connecte_le, derniere_activite)
         VALUES
            (:utilisateur_id, '192.0.2.99', :navigateur, 'actif', DATE_SUB(NOW(), INTERVAL 2 HOUR), DATE_SUB(NOW(), INTERVAL 1 HOUR))"
    );
    $staleConnection->execute([
        'utilisateur_id' => $users['responsable'],
        'navigateur' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126.0.0.0 Safari/537.36',
    ]);
    $connectionsPage = $httpRequest($baseUrl, '/connexions', $adminCookie);
    $assert(
        str_contains($connectionsPage['body'], 'class="connection-analytics-shell"')
            && str_contains($connectionsPage['body'], 'id="chart-connection-trend"')
            && str_contains($connectionsPage['body'], 'id="chart-connection-status"')
            && str_contains($connectionsPage['body'], 'id="connection-filter-panel"')
            && str_contains($connectionsPage['body'], 'id="tbl-connexions"'),
        'l historique des connexions doit afficher son analyse, ses filtres et sa chronologie'
    );
    $expiredConnections = $httpRequest(
        $baseUrl,
        '/api/connexions-datatable?draw=1&start=0&length=25&statut=expire&mot_cle=192.0.2.99',
        $adminCookie
    );
    $expiredConnectionsData = json_decode($expiredConnections['body'], true);
    $assert(
        $expiredConnections['status'] === 200
            && (int) ($expiredConnectionsData['recordsFiltered'] ?? 0) === 1
            && ($expiredConnectionsData['data'][0]['statut_code'] ?? '') === 'expire'
            && str_contains((string) ($expiredConnectionsData['data'][0]['environnement'] ?? ''), 'Chrome'),
        'une session inactive doit etre classee expiree et son environnement doit etre resume'
    );
    $assert(
        $httpRequest($baseUrl, '/api/connexions-datatable?draw=1&start=0&length=10', $roleCookies['agent'])['status'] === 403,
        'un agent sans droit journal ne doit pas lire l historique des connexions'
    );

    // Creation de compte sans divulgation de mot de passe : l'utilisateur
    // recoit un lien a usage unique, egalement reutilise par l'action de reset.
    $newAccountPage = $httpRequest($baseUrl, '/utilisateurs', $adminCookie);
    $assert(
        str_contains($newAccountPage['body'], 'id="modal-ajouter-utilisateur"')
            && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $newAccountPage['body'], $accountCsrf) === 1,
        'la modale utilisateur doit exposer un jeton CSRF'
    );
    $accountToken = $accountCsrf[1] ?? '';
    $accountEmail = 'invitation.' . bin2hex(random_bytes(4)) . '@example.test';
    $createAccount = $httpRequest($baseUrl, '/utilisateurs/ajouter', $adminCookie, 'POST', [
        'csrf_token' => $accountToken,
        'nom' => 'Invitation',
        'prenoms' => 'Securisee',
        'email' => $accountEmail,
        'telephone' => '',
        'service' => 'Recette',
        'role' => 'consultation',
    ]);
    $invitedUser = (new UserRepository())->repo_findByEmail($accountEmail);
    $invitedUserId = (int) ($invitedUser['id'] ?? 0);
    $assert($createAccount['status'] === 302 && $invitedUserId > 0, 'la creation du compte invite doit reussir');
    $firstTokenHash = $invitedUserId > 0
        ? (string) $scalar(
            'SELECT token_hash FROM tokens_reinitialisation WHERE utilisateur_id = :id AND utilise = 0 ORDER BY id DESC LIMIT 1',
            ['id' => $invitedUserId]
        )
        : '';
    $assert(strlen($firstTokenHash) === 64, 'seule l empreinte du lien d activation doit etre conservee');
    $usersAfterInvitation = $httpRequest($baseUrl, '/utilisateurs', $adminCookie);
    $assert(str_contains($usersAfterInvitation['body'], 'lien sécurisé'), 'le succes doit confirmer l envoi du lien securise');
    $assert(!str_contains(mb_strtolower($usersAfterInvitation['body']), 'mot de passe temporaire'), 'le message administrateur ne doit divulguer aucun mot de passe');

    $sessionVersionBeforeReset = (int) ($invitedUser['session_version'] ?? 0);
    $resetAccount = $httpRequest($baseUrl, '/utilisateurs/reinitialiser/' . $invitedUserId, $adminCookie, 'POST', [
        'csrf_token' => $accountToken,
    ]);
    $secondTokenHash = (string) $scalar(
        'SELECT token_hash FROM tokens_reinitialisation WHERE utilisateur_id = :id AND utilise = 0 ORDER BY id DESC LIMIT 1',
        ['id' => $invitedUserId]
    );
    $sessionVersionAfterReset = (int) $scalar('SELECT session_version FROM utilisateurs WHERE id = :id', ['id' => $invitedUserId]);
    $activeAccessLinks = (int) $scalar(
        'SELECT COUNT(*) FROM tokens_reinitialisation WHERE utilisateur_id = :id AND utilise = 0',
        ['id' => $invitedUserId]
    );
    $assert($resetAccount['status'] === 302, 'le renvoi du lien d acces doit rediriger vers les utilisateurs');
    $assert($activeAccessLinks === 1 && $secondTokenHash !== $firstTokenHash, 'un nouvel envoi doit invalider le lien precedent');
    $assert($sessionVersionAfterReset === $sessionVersionBeforeReset + 1, 'le renouvellement doit revoquer les sessions existantes');

    // Back-office des listes metier : referentiels libres et workflow des
    // statuts fixe, dont seuls le libelle et la couleur sont personnalisables.
    $parameterPage = $httpRequest($baseUrl, '/parametres', $adminCookie);
    $assert(
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $parameterPage['body'], $parameterCsrf) === 1
            && str_contains($parameterPage['body'], 'id="modal-parametre-liste"')
            && str_contains($parameterPage['body'], 'id="supervision-relances"')
            && preg_match('/data-mode="add"\s+data-type="statuts"/', $parameterPage['body']) === 0
            && !str_contains($parameterPage['body'], 'name="resolu"'),
        'le back-office doit afficher les listes metier et la supervision des relances avec un jeton CSRF'
    );
    $parameterToken = $parameterCsrf[1] ?? '';
    $suffix = strtolower(bin2hex(random_bytes(3)));

    $testTypeLabel = 'Type P12 ' . $suffix;
    $testTypeCode = 'type_p12_' . $suffix;
    $httpRequest($baseUrl, '/parametres/liste/types_titres/ajouter', $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => $testTypeLabel,
        'code' => 'code_fourni_a_ignorer',
        'ordre' => '91',
    ]);
    $testTypeId = (int) $scalar('SELECT id FROM types_titres WHERE code = :code', ['code' => $testTypeCode]);
    $assert($testTypeId > 0, 'le back-office doit ajouter un type avec un code technique genere par le systeme');
    $httpRequest($baseUrl, '/parametres/liste/types_titres/modifier/' . $testTypeId, $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => 'Type P12 modifie',
        'code' => 'code_falsifie_ignore',
        'ordre' => '92',
    ]);
    $testType = $db->query('SELECT * FROM types_titres WHERE id = ' . $testTypeId)->fetch();
    $assert(
        ($testType['libelle'] ?? '') === 'Type P12 modifie'
            && ($testType['code'] ?? '') === $testTypeCode
            && (int) ($testType['ordre'] ?? 0) === 92,
        'la modification doit conserver le code technique et mettre a jour les champs autorises'
    );
    $httpRequest($baseUrl, '/parametres/liste/types_titres/statut/' . $testTypeId, $adminCookie, 'POST', ['csrf_token' => $parameterToken]);
    $assert((int) $scalar('SELECT actif FROM types_titres WHERE id = :id', ['id' => $testTypeId]) === 0, 'un type doit pouvoir etre desactive');

    $testStatusCode = 'p12_statut_' . $suffix;
    $httpRequest($baseUrl, '/parametres/liste/statuts/ajouter', $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => 'Statut P12 initial',
        'code' => $testStatusCode,
        'ordre' => '93',
        'couleur' => 'info',
        'resolu' => '0',
    ]);
    $assert(
        (int) $scalar('SELECT COUNT(*) FROM statuts WHERE code = :code', ['code' => $testStatusCode]) === 0,
        'le back-office ne doit pas permettre d ajouter un statut hors workflow'
    );

    $systemStatusBefore = $db->query('SELECT * FROM statuts WHERE id = ' . $inResearchStatusId)->fetch();
    $httpRequest($baseUrl, '/parametres/liste/statuts/modifier/' . $inResearchStatusId, $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => 'Recherche P12',
        'code' => 'code_falsifie_ignore',
        'ordre' => '94',
        'couleur' => 'warning',
        'resolu' => '1',
    ]);
    $testStatus = $db->query('SELECT * FROM statuts WHERE id = ' . $inResearchStatusId)->fetch();
    $assert(
        ($testStatus['libelle'] ?? '') === 'Recherche P12'
            && ($testStatus['couleur'] ?? '') === 'warning'
            && ($testStatus['code'] ?? '') === 'en_recherche'
            && (int) ($testStatus['ordre'] ?? 0) === (int) StatutRepository::WORKFLOW['en_recherche']['ordre']
            && (int) ($testStatus['resolu'] ?? 1) === (int) StatutRepository::WORKFLOW['en_recherche']['resolu']
            && (int) ($testStatus['systeme'] ?? 0) === 1,
        'un statut systeme ne doit permettre de modifier que son libelle et sa couleur'
    );
    $httpRequest($baseUrl, '/parametres/liste/statuts/statut/' . $inResearchStatusId, $adminCookie, 'POST', ['csrf_token' => $parameterToken]);
    $assert((int) $scalar('SELECT actif FROM statuts WHERE id = :id', ['id' => $inResearchStatusId]) === 1, 'un statut indispensable au workflow ne doit pas etre desactive');

    $legacyCode = 'p12_legacy_' . $suffix;
    $legacyInsert = $db->prepare(
        'INSERT INTO statuts (code, systeme, libelle, couleur, resolu, actif, ordre)
         VALUES (:code, 0, :libelle, :couleur, 0, 1, 99)'
    );
    $legacyInsert->execute([
        'code' => $legacyCode,
        'libelle' => 'Ancien statut P12',
        'couleur' => 'secondary',
    ]);
    $legacyStatusId = (int) $db->lastInsertId();
    $httpRequest($baseUrl, '/parametres/liste/statuts/statut/' . $legacyStatusId, $adminCookie, 'POST', ['csrf_token' => $parameterToken]);
    $assert(
        (int) $scalar('SELECT actif FROM statuts WHERE id = :id', ['id' => $legacyStatusId]) === 0,
        'un ancien statut encore actif doit pouvoir etre retire du workflow'
    );
    $httpRequest($baseUrl, '/parametres/liste/statuts/statut/' . $legacyStatusId, $adminCookie, 'POST', ['csrf_token' => $parameterToken]);
    $httpRequest($baseUrl, '/parametres/liste/statuts/modifier/' . $legacyStatusId, $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => 'Ancien statut falsifie',
        'couleur' => 'danger',
    ]);
    $legacyStatus = $db->query('SELECT * FROM statuts WHERE id = ' . $legacyStatusId)->fetch();
    $assert(
        (int) ($legacyStatus['actif'] ?? 1) === 0
            && ($legacyStatus['libelle'] ?? '') === 'Ancien statut P12',
        'un ancien statut doit rester inactif et en lecture seule pour proteger l historique'
    );

    $httpRequest($baseUrl, '/parametres/liste/statuts/modifier/' . $inResearchStatusId, $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => (string) ($systemStatusBefore['libelle'] ?? 'En recherche'),
        'couleur' => (string) ($systemStatusBefore['couleur'] ?? 'warning'),
    ]);

    $testLocationLabel = 'Localisation P12 ' . $suffix;
    $httpRequest($baseUrl, '/parametres/liste/localisations/ajouter', $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => $testLocationLabel,
    ]);
    $testLocationId = (int) $scalar('SELECT id FROM localisations WHERE libelle = :libelle', ['libelle' => $testLocationLabel]);
    $httpRequest($baseUrl, '/parametres/liste/localisations/modifier/' . $testLocationId, $adminCookie, 'POST', [
        'csrf_token' => $parameterToken,
        'libelle' => $testLocationLabel . ' modifiee',
    ]);
    $httpRequest($baseUrl, '/parametres/liste/localisations/statut/' . $testLocationId, $adminCookie, 'POST', ['csrf_token' => $parameterToken]);
    $assert(
        (string) $scalar('SELECT libelle FROM localisations WHERE id = :id', ['id' => $testLocationId]) === $testLocationLabel . ' modifiee'
            && (int) $scalar('SELECT actif FROM localisations WHERE id = :id', ['id' => $testLocationId]) === 0,
        'une localisation doit pouvoir etre modifiee puis desactivee'
    );

    $responsibleDashboardForCsrf = $httpRequest($baseUrl, '/dashboard', $roleCookies['responsable']);
    preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $responsibleDashboardForCsrf['body'], $responsibleParamCsrf);
    $forbiddenLabel = 'Interdit P12 ' . $suffix;
    $forbiddenParameterResponse = $httpRequest($baseUrl, '/parametres/liste/localisations/ajouter', $roleCookies['responsable'], 'POST', [
        'csrf_token' => $responsibleParamCsrf[1] ?? '',
        'libelle' => $forbiddenLabel,
    ]);
    $assert(
        $forbiddenParameterResponse['status'] === 403
            && (int) $scalar('SELECT COUNT(*) FROM localisations WHERE libelle = :libelle', ['libelle' => $forbiddenLabel]) === 0,
        'un non-administrateur ne doit pas modifier les listes metier'
    );

    // Le parcours de creation reste minimal. Les champs de recherche envoyes
    // frauduleusement sont ignores et ne creent aucune fausse tentative.
    $createPage = $httpRequest($baseUrl, '/formulaires/ajouter', $adminCookie);
    $assert(
        preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $createPage['body'], $createCsrf) === 1,
        'le formulaire de creation doit fournir un jeton CSRF'
    );
    $assert(
        !str_contains($createPage['body'], 'name="niveau_urgence"')
            && !str_contains($createPage['body'], 'name="date_depot"')
            && !str_contains($createPage['body'], 'name="deposant"')
            && !str_contains($createPage['body'], 'name="mandataire"'),
        'les champs hors registre ne doivent plus etre affiches a la creation'
    );
    $simpleNumber = 'P12-SIMPLE-' . strtoupper(bin2hex(random_bytes(3)));
    $createdResponse = $httpRequest($baseUrl, '/formulaires/ajouter', $adminCookie, 'POST', [
        'csrf_token' => $createCsrf[1] ?? '',
        'type_titre_id' => (string) $typeId,
        'annee' => date('Y'),
        'numero_formulaire' => $simpleNumber,
        'statut_id' => (string) $resolvedStatusId,
        'localisation_id' => (string) $locationId,
        'responsable_id' => (string) $users['responsable'],
        'date_recherche' => date('Y-m-d'),
        'resultat' => 'Valeur falsifiee a ignorer',
        'date_depot' => date('Y-m-d'),
        'deposant' => 'Valeur falsifiee',
        'mandataire' => 'Valeur falsifiee',
        'niveau_urgence' => 'Critique',
        'priorite' => 'Urgente',
    ]);
    $assert($createdResponse['status'] === 302, 'la creation minimale doit rediriger vers la fiche');
    $createdFormId = 0;
    if (preg_match('#/formulaires/voir/(\d+)#', $createdResponse['headers'], $createdLocation) === 1) {
        $createdFormId = (int) $createdLocation[1];
    }
    $assert($createdFormId > 0, 'la redirection doit contenir l identifiant du nouveau dossier');
    $simpleForm = $createdFormId > 0 ? $formRepository->repo_find($createdFormId) : null;
    $assert($simpleForm !== null, 'le dossier minimal doit exister');
    $assert((int) ($simpleForm['statut_id'] ?? 0) === $openStatusId, 'le statut initial doit rester Introuvable');
    $assert(empty($simpleForm['responsable_id']) && empty($simpleForm['localisation_id']), 'la creation ne doit pas affecter une recherche');
    $assert(empty($simpleForm['date_recherche']) && (string) ($simpleForm['resultat'] ?? '') === '', 'la creation ne doit pas enregistrer de resultat');
    $assert(
        empty($simpleForm['date_depot'])
            && (string) ($simpleForm['deposant'] ?? '') === ''
            && (string) ($simpleForm['mandataire'] ?? '') === '',
        'la creation doit ignorer les anciens champs hors registre meme s ils sont envoyes manuellement'
    );
    $assert(($simpleForm['niveau_urgence'] ?? '') === 'Moyen' && ($simpleForm['priorite'] ?? '') === 'Normale', 'les valeurs internes par defaut doivent etre imposees');
    $historyCount = $createdFormId > 0
        ? (int) $scalar('SELECT COUNT(*) FROM recherches_formulaire WHERE formulaire_id = :id', ['id' => $createdFormId])
        : -1;
    $assert($historyCount === 0, 'aucune recherche ne doit etre fabriquee pendant la creation');
    if ($createdFormId > 0) {
        $createdPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $adminCookie);
        $assert(
            $createdPage['status'] === 200
                && !str_contains($createdPage['body'], 'Affectation de la recherche')
                && !str_contains($createdPage['body'], 'Parcours de finalisation'),
            'la fiche Introuvable ne doit proposer ni affectation integree ni finalisation'
        );
        $registryJson = $httpRequest($baseUrl, '/api/formulaires-datatable?draw=1&start=0&length=50', $adminCookie);
        $assert(
            $registryJson['status'] === 200
                && str_contains($registryJson['body'], 'risfm-actions-dropdown')
                && str_contains($registryJson['body'], 'js-affecter-recherche')
                && str_contains($registryJson['body'], 'data-id=\"' . $createdFormId . '\"'),
            'le registre doit proposer le menu deroulant et l affectation depuis la colonne actions'
        );

        // L'ecran de modification generale ignore lui aussi toute tentative de
        // changement de statut ou d'affectation hors de la route de recherche.
        $editPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $adminCookie);
        $assert(
            $editPage['status'] === 200
                && str_contains($editPage['body'], 'id="modal-modifier-formulaire"')
                && str_contains($editPage['body'], 'class="detail-surface-card detail-overview-card"')
                && str_contains($editPage['body'], 'detail-history-scroll')
                && str_contains($editPage['body'], 'Historique du dossier')
                && str_contains($editPage['body'], 'Formulaire ajouté au registre')
                && str_contains($editPage['body'], 'detail-attachments-card')
                && str_contains($editPage['body'], '/formulaires/modifier/' . $createdFormId)
                && !str_contains($editPage['body'], 'name="niveau_urgence"')
                && !str_contains($editPage['body'], 'name="date_depot"')
                && !str_contains($editPage['body'], 'name="deposant"')
                && !str_contains($editPage['body'], 'name="mandataire"')
                && preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $editPage['body'], $editCsrf) === 1,
            'la fiche responsive doit fournir ses cartes contextuelles et le modal de modification securise'
        );
        $assert(
            str_contains($editPage['body'], 'data-target="#modal-archiver-formulaire"')
                && str_contains($editPage['body'], 'id="modal-archiver-formulaire"')
                && str_contains($editPage['body'], 'name="motif_archivage"')
                && !str_contains($editPage['body'], 'id="archivage-formulaire" class="collapse'),
            'l archivage doit etre confirme dans une modale avec un motif obligatoire'
        );
        $assert(
            $httpRequest($baseUrl, '/formulaires/modifier/' . $createdFormId, $adminCookie)['status'] === 404,
            'l ancienne page de modification ne doit plus etre accessible'
        );
        $editResponse = $httpRequest($baseUrl, '/formulaires/modifier/' . $createdFormId, $adminCookie, 'POST', [
            'csrf_token' => $editCsrf[1] ?? '',
            'type_titre_id' => (string) $typeId,
            'numero_formulaire' => $simpleNumber,
            'niveau_urgence' => 'Critique',
            'priorite' => 'Normale',
            'statut_id' => (string) $resolvedStatusId,
            'responsable_id' => (string) $users['responsable'],
            'localisation_id' => (string) $locationId,
            'date_recherche' => date('Y-m-d'),
            'resultat' => 'Falsification depuis la modification',
            'date_depot' => date('Y-m-d'),
            'deposant' => 'Falsification depuis la modification',
            'mandataire' => 'Falsification depuis la modification',
        ]);
        $assert($editResponse['status'] === 302, 'la modification generale doit etre enregistree');
        $afterEdit = $formRepository->repo_find($createdFormId);
        $assert(
            (int) ($afterEdit['statut_id'] ?? 0) === $openStatusId
                && empty($afterEdit['responsable_id'])
                && empty($afterEdit['localisation_id']),
            'la modification generale ne doit pas modifier le suivi de recherche'
        );
        $assert(
            ($afterEdit['niveau_urgence'] ?? '') === 'Moyen',
            'une ancienne valeur niveau_urgence envoyee manuellement doit etre ignoree'
        );
        $assert(
            empty($afterEdit['date_depot'])
                && (string) ($afterEdit['deposant'] ?? '') === ''
                && (string) ($afterEdit['mandataire'] ?? '') === '',
            'la modification doit ignorer les anciens champs hors registre'
        );

        // L'affectation passe par sa route dediee, change la situation
        // courante et ne fabrique pas encore de resultat dans l'historique.
        $createdPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $adminCookie);
        $assert(
            preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $createdPage['body'], $assignmentCsrf) === 1,
            'la fiche du dossier doit fournir un jeton CSRF'
        );
        $deadline = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $assignmentResponse = $httpRequest(
            $baseUrl,
            '/formulaires/affecter/' . $createdFormId,
            $adminCookie,
            'POST',
            [
                'csrf_token' => $assignmentCsrf[1] ?? '',
                'affectation_localisation_id' => (string) $locationId,
                'affectation_responsable_id' => (string) $users['responsable'],
                'affectation_date_echeance' => $deadline,
                'affectation_priorite' => 'Haute',
            ]
        );
        $assert(
            $assignmentResponse['status'] === 302,
            'l affectation valide doit rediriger vers la fiche'
        );
        $afterAssignment = $formRepository->repo_find($createdFormId);
        $assert(
            (int) ($afterAssignment['responsable_id'] ?? 0) === $users['responsable']
                && (int) ($afterAssignment['localisation_id'] ?? 0) === $locationId
                && (int) ($afterAssignment['statut_id'] ?? 0) === $inResearchStatusId
                && ($afterAssignment['date_echeance_recherche'] ?? null) === $deadline,
            'l affectation doit devenir la mission courante du dossier'
        );
        $assert(
            (int) $scalar('SELECT COUNT(*) FROM recherches_formulaire WHERE formulaire_id = :id', ['id' => $createdFormId]) === 0,
            'l affectation seule ne doit pas creer de faux resultat dans l historique'
        );

        $responsibleCookie = $roleCookies['responsable'];
        $responsibleDashboard = $httpRequest($baseUrl, '/dashboard', $responsibleCookie);
        $assert(
            $responsibleDashboard['status'] === 200
                && str_contains($responsibleDashboard['body'], 'Mes missions actives')
                && str_contains($responsibleDashboard['body'], $simpleNumber),
            'une nouvelle affectation doit apparaitre immediatement sur le tableau de bord du responsable'
        );
        $missionId = (int) $scalar(
            "SELECT id FROM missions_recherche
             WHERE formulaire_id = :formulaire_id AND responsable_id = :responsable_id
               AND etat IN ('affectee','en_cours') ORDER BY id DESC LIMIT 1",
            ['formulaire_id' => $createdFormId, 'responsable_id' => $users['responsable']]
        );
        $assert($missionId > 0, 'l affectation doit creer une mission independante');
        $activeMissions = $missionRepository->repo_activesPourResponsable($users['responsable']);
        $assert(
            count(array_filter(
                $activeMissions,
                static fn (array $mission): bool => (int) $mission['id'] === $missionId
            )) === 1,
            'la requete des missions actives doit retourner le dossier affecte'
        );

        // Une nouvelle authentification rappelle les missions actives sur la
        // premiere page uniquement. Le bouton "Plus tard" ne marque aucune
        // mission comme traitee et le rappel ne revient pas pendant la session.
        $activeStats = $missionRepository->repo_statsActives($users['responsable']);
        $assert(
            ($activeStats['total_actives'] ?? 0) >= 1
                && array_key_exists('total_en_retard', $activeStats)
                && array_key_exists('total_urgentes', $activeStats)
                && ($activeStats['prochaine_echeance'] ?? null) === $deadline,
            'le resume de connexion doit exposer le total et la prochaine echeance des missions'
        );
        $reminderCookie = tempnam(sys_get_temp_dir(), 'risfm_p12_reminder_');
        $cookieFiles[] = $reminderCookie;
        $responsibleIdentifier = (string) $scalar(
            'SELECT identifiant FROM utilisateurs WHERE id = :id',
            ['id' => $users['responsable']]
        );
        $reminderLogin = $login($baseUrl, $responsibleIdentifier, $reminderCookie);
        $assert(
            $reminderLogin['status'] === 302 && str_contains($reminderLogin['headers'], '/dashboard'),
            'la connexion de controle du rappel doit reussir'
        );
        $firstReminderDashboard = $httpRequest($baseUrl, '/dashboard', $reminderCookie);
        $secondReminderDashboard = $httpRequest($baseUrl, '/dashboard', $reminderCookie);
        $assert(
            $firstReminderDashboard['status'] === 200
                && str_contains($firstReminderDashboard['body'], 'window.RISFM_LOGIN_MISSION_REMINDER = {')
                && str_contains($firstReminderDashboard['body'], '"total_actives"'),
            'le rappel des missions doit etre injecte sur la premiere page apres connexion'
        );
        $assert(
            $secondReminderDashboard['status'] === 200
                && !str_contains($secondReminderDashboard['body'], 'window.RISFM_LOGIN_MISSION_REMINDER = {'),
            'le rappel des missions ne doit apparaitre qu une fois par connexion'
        );

        // Un agent non affecte ne peut ni modifier les metadonnees, ni
        // affecter la mission, ni soumettre son resultat par URL directe.
        $agentCookie = $roleCookies['agent'];
        $agentPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $agentCookie);
        $assert(
            $agentPage['status'] === 200
                && !str_contains($agentPage['body'], '/formulaires/affecter/' . $createdFormId)
                && !str_contains($agentPage['body'], '/missions-recherche/resultat/' . $missionId),
            'un agent non affecte doit consulter la fiche sans actions metier'
        );
        $assert(
            $httpRequest($baseUrl, '/formulaires/modifier/' . $createdFormId, $agentCookie)['status'] === 404,
            'la page autonome de modification doit etre supprimee pour tous les roles'
        );
        $agentCsrfPage = $httpRequest($baseUrl, '/formulaires/ajouter', $agentCookie);
        $assert(
            preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $agentCsrfPage['body'], $agentCsrf) === 1,
            'le compte agent doit fournir un jeton CSRF pour les tests de refus'
        );
        $assert(
            $httpRequest($baseUrl, '/formulaires/modifier/' . $createdFormId, $agentCookie, 'POST', [
                'csrf_token' => $agentCsrf[1] ?? '',
                'type_titre_id' => (string) $typeId,
                'numero_formulaire' => $simpleNumber,
                'niveau_urgence' => 'Moyen',
                'priorite' => 'Normale',
            ])['status'] === 403,
            'un agent ne doit jamais modifier les informations generales'
        );
        $assert(
            $httpRequest($baseUrl, '/formulaires/affecter/' . $createdFormId, $agentCookie, 'POST', [
                'csrf_token' => $agentCsrf[1] ?? '',
                'affectation_localisation_id' => (string) $locationId,
                'affectation_responsable_id' => (string) $users['agent'],
                'affectation_priorite' => 'Normale',
            ])['status'] === 403,
            'un agent ne doit pas pouvoir s affecter ou reaffecter une recherche'
        );
        $assert(
            $httpRequest($baseUrl, '/missions-recherche/resultat/' . $missionId, $agentCookie, 'POST', [
                'csrf_token' => $agentCsrf[1] ?? '',
                'recherche_resultat_code' => 'non_retrouve',
                'recherche_date' => date('Y-m-d'),
                'recherche_resultat' => 'Tentative interdite',
            ])['status'] === 403,
            'un utilisateur non affecte ne doit pas saisir le resultat'
        );

        // Le responsable effectivement affecte voit l'action et produit
        // exactement une entree append-only dans l'historique.
        $assignedPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $responsibleCookie);
        $assert(
            preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $assignedPage['body'], $researchCsrf) === 1
                && str_contains($assignedPage['body'], 'Saisir le compte rendu')
                && str_contains($assignedPage['body'], 'id="modal-resultat-mission"')
                && str_contains($assignedPage['body'], 'Mission confiée à')
                && !str_contains($assignedPage['body'], 'name="recherche_observations"'),
            'une mission active doit proposer la saisie de son resultat'
        );
        $researchResponse = $httpRequest(
            $baseUrl,
            '/missions-recherche/resultat/' . $missionId,
            $responsibleCookie,
            'POST',
            [
                'csrf_token' => $researchCsrf[1] ?? '',
                'recherche_resultat_code' => 'non_retrouve',
                'recherche_date' => date('Y-m-d'),
                'recherche_resultat' => 'Formulaire non retrouve dans la localisation affectee',
                'recherche_observations' => 'Premiere tentative P12',
            ]
        );
        $assert(
            $researchResponse['status'] === 302
                && str_contains($researchResponse['headers'], '#historique-recherches'),
            'le resultat doit rediriger vers l historique'
        );
        $afterResearch = $formRepository->repo_find($createdFormId);
        $assert(empty($afterResearch['date_echeance_recherche']), 'la saisie du resultat doit clore l echeance active');
        $assert(
            count(array_filter(
                $missionRepository->repo_activesPourResponsable($users['responsable']),
                static fn (array $mission): bool => (int) $mission['id'] === $missionId
            )) === 0,
            'une mission terminee doit quitter la liste des missions actives'
        );
        $assert(
            (int) $scalar('SELECT COUNT(*) FROM recherches_formulaire WHERE formulaire_id = :id', ['id' => $createdFormId]) === 1,
            'le resultat doit creer exactement une ligne d historique'
        );
        $researchHistoryPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $responsibleCookie);
        $assert(
            str_contains($researchHistoryPage['body'], 'Recherche infructueuse à')
                && str_contains($researchHistoryPage['body'], 'Compte rendu')
                && str_contains($researchHistoryPage['body'], 'Formulaire non retrouve dans la localisation affectee'),
            'l historique du dossier doit personnaliser la recherche et afficher son compte rendu'
        );

        // Plusieurs recherches peuvent ensuite etre conduites en parallele.
        $locationIds = array_map('intval', $db->query('SELECT id FROM localisations WHERE actif = 1 ORDER BY id LIMIT 4')->fetchAll(PDO::FETCH_COLUMN));
        $assert(count($locationIds) >= 4, 'la recette simultanee exige quatre localisations actives');
        $assignMission = static function (int $location, int $responsible) use (
            $httpRequest,
            $baseUrl,
            $adminCookie,
            $assignmentCsrf,
            $createdFormId,
            $deadline
        ): array {
            return $httpRequest($baseUrl, '/formulaires/affecter/' . $createdFormId, $adminCookie, 'POST', [
                'csrf_token' => $assignmentCsrf[1] ?? '',
                'affectation_localisation_id' => (string) $location,
                'affectation_responsable_id' => (string) $responsible,
                'affectation_date_echeance' => $deadline,
                'affectation_priorite' => 'Normale',
                'confirmer_affectation_localisation_deja_recherchee' => '1',
            ]);
        };

        $assignMission($locationIds[1], $users['responsable']);
        $assignMission($locationIds[2], $users['agent']);
        $parallelResponsibleMissionId = (int) $scalar(
            "SELECT id FROM missions_recherche WHERE formulaire_id = :formulaire_id
               AND responsable_id = :responsable_id AND localisation_id = :localisation_id
               AND etat IN ('affectee','en_cours') ORDER BY id DESC LIMIT 1",
            ['formulaire_id' => $createdFormId, 'responsable_id' => $users['responsable'], 'localisation_id' => $locationIds[1]]
        );
        $parallelAgentMissionId = (int) $scalar(
            "SELECT id FROM missions_recherche WHERE formulaire_id = :formulaire_id
               AND responsable_id = :responsable_id AND localisation_id = :localisation_id
               AND etat IN ('affectee','en_cours') ORDER BY id DESC LIMIT 1",
            ['formulaire_id' => $createdFormId, 'responsable_id' => $users['agent'], 'localisation_id' => $locationIds[2]]
        );
        $assert($parallelResponsibleMissionId > 0 && $parallelAgentMissionId > 0, 'deux missions simultanees doivent etre creees');
        $assert(
            (int) $scalar("SELECT COUNT(*) FROM missions_recherche WHERE formulaire_id = :id AND etat IN ('affectee','en_cours')", ['id' => $createdFormId]) === 2,
            'les deux missions doivent rester actives simultanement'
        );

        $httpRequest($baseUrl, '/missions-recherche/resultat/' . $parallelResponsibleMissionId, $responsibleCookie, 'POST', [
            'csrf_token' => $researchCsrf[1] ?? '',
            'recherche_resultat_code' => 'non_retrouve',
            'recherche_date' => date('Y-m-d'),
            'recherche_resultat' => 'Aucun formulaire retrouve dans la deuxieme localisation',
            'recherche_observations' => 'Mission parallele P12',
        ]);
        $assert(
            (int) ($formRepository->repo_find($createdFormId)['statut_id'] ?? 0) === $inResearchStatusId,
            'un echec ne doit pas cloturer le dossier tant qu une autre mission reste active'
        );
        $assert(
            (string) $scalar('SELECT etat FROM missions_recherche WHERE id = :id', ['id' => $parallelAgentMissionId]) === 'affectee',
            'la seconde mission doit rester ouverte apres l echec de la premiere'
        );

        // Une troisieme mission ouverte permet de verifier sa cloture
        // automatique lorsque la mission de l agent retrouve le formulaire.
        $assignMission($locationIds[3], $users['administrateur']);
        $parallelAdminMissionId = (int) $scalar(
            "SELECT id FROM missions_recherche WHERE formulaire_id = :formulaire_id
               AND responsable_id = :responsable_id AND localisation_id = :localisation_id
               AND etat IN ('affectee','en_cours') ORDER BY id DESC LIMIT 1",
            ['formulaire_id' => $createdFormId, 'responsable_id' => $users['administrateur'], 'localisation_id' => $locationIds[3]]
        );
        $agentFoundResponse = $httpRequest($baseUrl, '/missions-recherche/resultat/' . $parallelAgentMissionId, $agentCookie, 'POST', [
            'csrf_token' => $agentCsrf[1] ?? '',
            'recherche_resultat_code' => 'retrouve',
            'recherche_date' => date('Y-m-d'),
            'recherche_resultat' => 'Formulaire retrouve dans la troisieme localisation',
            'recherche_observations' => 'Resolution simultanee P12',
        ]);
        $assert($agentFoundResponse['status'] === 302, 'l agent affecte doit pouvoir declarer le formulaire retrouve');
        $assert(
            (int) ($formRepository->repo_find($createdFormId)['statut_id'] ?? 0) === $resolvedStatusId,
            'la premiere mission positive doit resoudre le formulaire'
        );
        $assert(
            (string) $scalar('SELECT etat FROM missions_recherche WHERE id = :id', ['id' => $parallelAdminMissionId]) === 'annulee',
            'les autres missions ouvertes doivent etre annulees automatiquement'
        );
        $assert(
            (int) $scalar("SELECT COUNT(*) FROM missions_recherche WHERE formulaire_id = :id AND etat IN ('affectee','en_cours')", ['id' => $createdFormId]) === 0,
            'aucune mission ne doit rester active apres la resolution du dossier'
        );

        $assert(
            (int) $scalar(
                "SELECT COUNT(*) FROM finalisations_formulaire
                 WHERE formulaire_id = :id AND etape = 'retrouve'",
                ['id' => $createdFormId]
            ) === 1,
            'une recherche positive doit ouvrir automatiquement le workflow de finalisation'
        );

        $foundPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $responsibleCookie);
        $assert(
            str_contains($foundPage['body'], 'Parcours de finalisation')
                && str_contains($foundPage['body'], 'Confirmer : Numérisé'),
            'un responsable doit voir la prochaine etape Numerise'
        );

        $agentCannotFinalize = $httpRequest(
            $baseUrl,
            '/formulaires/finaliser/' . $createdFormId,
            $agentCookie,
            'POST',
            [
                'csrf_token' => $agentCsrf[1] ?? '',
                'finalisation_etape' => 'numerise',
                'finalisation_date' => date('Y-m-d'),
            ]
        );
        $assert($agentCannotFinalize['status'] === 403, 'un agent ne doit pas valider la finalisation');

        $skipResponse = $httpRequest(
            $baseUrl,
            '/formulaires/finaliser/' . $createdFormId,
            $responsibleCookie,
            'POST',
            [
                'csrf_token' => $researchCsrf[1] ?? '',
                'finalisation_etape' => 'saisi',
                'finalisation_date' => date('Y-m-d'),
            ]
        );
        $assert($skipResponse['status'] === 302, 'une tentative de saut d etape doit etre refusee proprement');
        $assert(
            (int) ($formRepository->repo_find($createdFormId)['statut_id'] ?? 0) === $resolvedStatusId,
            'le formulaire doit rester Retrouve apres une tentative de saut vers Saisi'
        );

        $numerizedResponse = $httpRequest(
            $baseUrl,
            '/formulaires/finaliser/' . $createdFormId,
            $responsibleCookie,
            'POST',
            [
                'csrf_token' => $researchCsrf[1] ?? '',
                'finalisation_etape' => 'numerise',
                'finalisation_date' => date('Y-m-d'),
                'finalisation_commentaire' => 'Numerisation controlee pendant la recette P12',
            ]
        );
        $assert($numerizedResponse['status'] === 302, 'la validation Numerise doit rediriger vers la fiche');
        $assert(
            (int) ($formRepository->repo_find($createdFormId)['statut_id'] ?? 0) === $numerizedStatusId,
            'le statut doit passer de Retrouve a Numerise'
        );

        $enteredResponse = $httpRequest(
            $baseUrl,
            '/formulaires/finaliser/' . $createdFormId,
            $responsibleCookie,
            'POST',
            [
                'csrf_token' => $researchCsrf[1] ?? '',
                'finalisation_etape' => 'saisi',
                'finalisation_date' => date('Y-m-d'),
                'finalisation_commentaire' => 'Saisie finale controlee pendant la recette P12',
            ]
        );
        $assert($enteredResponse['status'] === 302, 'la validation Saisi doit rediriger vers la fiche');
        $assert(
            (int) ($formRepository->repo_find($createdFormId)['statut_id'] ?? 0) === $enteredStatusId,
            'le statut doit passer de Numerise a Saisi'
        );
        $assert(
            (int) $scalar(
                'SELECT COUNT(*) FROM finalisations_formulaire WHERE formulaire_id = :id',
                ['id' => $createdFormId]
            ) === 3,
            'les trois jalons Retrouve, Numerise et Saisi doivent etre historises une seule fois'
        );

        // Une correction ne detruit jamais le cycle termine. Seul
        // l'administrateur peut rouvrir, puis les missions du nouveau cycle
        // peuvent etre reaffectees ou annulees explicitement.
        $reopenForbidden = $httpRequest(
            $baseUrl,
            '/formulaires/reouvrir/' . $createdFormId,
            $responsibleCookie,
            'POST',
            [
                'csrf_token' => $researchCsrf[1] ?? '',
                'reouverture_motif' => 'Tentative de reouverture sans droit administrateur',
            ]
        );
        $assert($reopenForbidden['status'] === 403, 'un responsable ne doit pas rouvrir un dossier finalise');

        $reopenResponse = $httpRequest(
            $baseUrl,
            '/formulaires/reouvrir/' . $createdFormId,
            $adminCookie,
            'POST',
            [
                'csrf_token' => $assignmentCsrf[1] ?? '',
                'reouverture_motif' => 'Correction demandee apres controle de la saisie finale',
            ]
        );
        $assert($reopenResponse['status'] === 302, 'l administrateur doit pouvoir rouvrir un dossier finalise');
        $reopenedForm = $formRepository->repo_find($createdFormId);
        $verificationStatusId = (int) $scalar("SELECT id FROM statuts WHERE code = 'a_verifier'");
        $assert(
            (int) ($reopenedForm['cycle_suivi'] ?? 0) === 2
                && (int) ($reopenedForm['statut_id'] ?? 0) === $verificationStatusId,
            'la reouverture doit creer le cycle 2 au statut A verifier'
        );
        $assert(
            (int) $scalar('SELECT COUNT(*) FROM reouvertures_formulaire WHERE formulaire_id = :id', ['id' => $createdFormId]) === 1,
            'la reouverture doit conserver son motif et son acteur'
        );
        $assert(
            count((new FinalisationFormulaireRepository())->repo_pourFormulaire($createdFormId)) === 0
                && (int) $scalar('SELECT COUNT(*) FROM finalisations_formulaire WHERE formulaire_id = :id', ['id' => $createdFormId]) === 3,
            'le nouveau cycle doit repartir sans effacer les trois jalons precedents'
        );

        $assignMission($locationIds[0], $users['responsable']);
        $missionBeforeReassignment = (int) $scalar(
            "SELECT id FROM missions_recherche
             WHERE formulaire_id = :formulaire_id AND cycle_suivi = 2
               AND responsable_id = :responsable_id AND etat IN ('affectee','en_cours')
             ORDER BY id DESC LIMIT 1",
            ['formulaire_id' => $createdFormId, 'responsable_id' => $users['responsable']]
        );
        $assert($missionBeforeReassignment > 0, 'une mission doit pouvoir etre creee dans le cycle rouvert');
        $missionActionsPage = $httpRequest($baseUrl, '/formulaires/voir/' . $createdFormId, $adminCookie);
        $assert(
            str_contains($missionActionsPage['body'], 'id="modal-reaffecter-mission"')
                && str_contains($missionActionsPage['body'], 'id="modal-annuler-mission"'),
            'les actions de mission doivent etre proposees dans des modales confirmees'
        );

        $reassignResponse = $httpRequest(
            $baseUrl,
            '/missions-recherche/reaffecter/' . $missionBeforeReassignment,
            $adminCookie,
            'POST',
            [
                'csrf_token' => $assignmentCsrf[1] ?? '',
                'reaffectation_responsable_id' => (string) $users['agent'],
                'reaffectation_date_echeance' => $deadline,
                'reaffectation_priorite' => 'Urgente',
            ]
        );
        $assert($reassignResponse['status'] === 302, 'une mission active doit pouvoir etre reaffectee');
        $missionAfterReassignment = (int) $scalar(
            'SELECT id FROM missions_recherche WHERE mission_parent_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $missionBeforeReassignment]
        );
        $assert(
            (string) $scalar('SELECT etat FROM missions_recherche WHERE id = :id', ['id' => $missionBeforeReassignment]) === 'annulee'
                && $missionAfterReassignment > 0
                && (int) $scalar('SELECT cycle_suivi FROM missions_recherche WHERE id = :id', ['id' => $missionAfterReassignment]) === 2,
            'la reaffectation doit annuler l ancienne mission et creer un remplacement lie dans le meme cycle'
        );
        $assert(
            (int) $scalar(
                "SELECT COUNT(*) FROM notifications WHERE utilisateur_id = :id AND titre = 'Mission reaffectee'",
                ['id' => $users['responsable']]
            ) >= 1,
            'l ancien responsable doit etre prevenu du transfert de sa mission'
        );
        $assert(
            (int) $scalar(
                "SELECT COUNT(*) FROM notifications WHERE utilisateur_id = :id AND titre = 'Mission de recherche reaffectee'",
                ['id' => $users['agent']]
            ) >= 1,
            'le nouveau responsable doit recevoir la mission reaffectee'
        );
        $assert(
            (int) $scalar(
                "SELECT COUNT(*) FROM activites WHERE type_action = 'email' AND description LIKE :motif",
                ['motif' => '%de la mission #' . $missionAfterReassignment . ' %']
            ) === 1,
            'l envoi du courriel de reaffectation doit etre journalise'
        );

        $oldResponsibleRefused = $httpRequest(
            $baseUrl,
            '/missions-recherche/resultat/' . $missionBeforeReassignment,
            $responsibleCookie,
            'POST',
            [
                'csrf_token' => $researchCsrf[1] ?? '',
                'recherche_resultat_code' => 'non_retrouve',
                'recherche_date' => date('Y-m-d'),
                'recherche_resultat' => 'Tentative apres transfert',
            ]
        );
        $assert($oldResponsibleRefused['status'] === 403, 'l ancien responsable doit perdre immediatement le droit de resultat');

        $cancelResponse = $httpRequest(
            $baseUrl,
            '/missions-recherche/annuler/' . $missionAfterReassignment,
            $adminCookie,
            'POST',
            [
                'csrf_token' => $assignmentCsrf[1] ?? '',
                'annulation_motif' => 'Mission annulee pendant la recette fonctionnelle du nouveau cycle',
            ]
        );
        $assert($cancelResponse['status'] === 302, 'une mission active doit pouvoir etre annulee manuellement');
        $assert(
            (string) $scalar('SELECT etat FROM missions_recherche WHERE id = :id', ['id' => $missionAfterReassignment]) === 'annulee'
                && (int) ($formRepository->repo_find($createdFormId)['statut_id'] ?? 0) === $verificationStatusId,
            'l annulation de la seule mission du cycle rouvert doit ramener le dossier a A verifier'
        );

        $assignMission($locationIds[0], $users['agent']);
        $newCycleMissionId = (int) $scalar(
            "SELECT id FROM missions_recherche
             WHERE formulaire_id = :formulaire_id AND cycle_suivi = 2
               AND responsable_id = :responsable_id AND etat IN ('affectee','en_cours')
             ORDER BY id DESC LIMIT 1",
            ['formulaire_id' => $createdFormId, 'responsable_id' => $users['agent']]
        );
        $httpRequest($baseUrl, '/missions-recherche/resultat/' . $newCycleMissionId, $agentCookie, 'POST', [
            'csrf_token' => $agentCsrf[1] ?? '',
            'recherche_resultat_code' => 'retrouve',
            'recherche_date' => date('Y-m-d'),
            'recherche_resultat' => 'Formulaire retrouve apres reouverture du dossier',
            'recherche_observations' => 'Deuxieme cycle P12',
        ]);
        $assert(
            (int) ($formRepository->repo_find($createdFormId)['statut_id'] ?? 0) === $resolvedStatusId
                && count((new FinalisationFormulaireRepository())->repo_pourFormulaire($createdFormId)) === 1
                && (int) $scalar('SELECT COUNT(*) FROM finalisations_formulaire WHERE formulaire_id = :id', ['id' => $createdFormId]) === 4,
            'le cycle 2 doit pouvoir etre resolu sans collision avec les jalons historiques du cycle 1'
        );
        $assert(
            (int) $scalar(
                "SELECT COUNT(*) FROM activites
                 WHERE type_action IN ('reaffectation','annulation_mission','reouverture')
                   AND entite_id IN (:formulaire_id, :mission_1, :mission_2)",
                [
                    'formulaire_id' => $createdFormId,
                    'mission_1' => $missionBeforeReassignment,
                    'mission_2' => $missionAfterReassignment,
                ]
            ) >= 3,
            'la reouverture, la reaffectation et l annulation doivent etre journalisees'
        );
    }

    // Quatre fichiers reels, signature interne et neuf colonnes officielles.
    $exportChecks = [
        'csv' => ['type' => 'text/csv', 'magic' => "\xEF\xBB\xBF"],
        'excel' => ['type' => 'spreadsheetml', 'magic' => 'PK'],
        'pdf' => ['type' => 'application/pdf', 'magic' => '%PDF'],
        'word' => ['type' => 'wordprocessingml', 'magic' => 'PK'],
    ];
    foreach ($exportChecks as $format => $check) {
        $response = $httpRequest($baseUrl, '/exports/formulaires/' . $format, $adminCookie);
        $assert($response['status'] === 200, "export {$format} doit retourner HTTP 200");
        $assert(str_contains($response['content_type'], $check['type']), "type MIME incorrect pour l export {$format}");
        $assert(str_starts_with($response['body'], $check['magic']), "signature de fichier incorrecte pour l export {$format}");
        if ($format === 'csv') {
            $assert(str_contains($response['body'], 'Numero du formulaire'), 'le CSV doit contenir les colonnes officielles');
            continue;
        }
        if (in_array($format, ['excel', 'word'], true)) {
            $archivePath = tempnam(sys_get_temp_dir(), 'risfm_p12_export_');
            file_put_contents($archivePath, $response['body'], LOCK_EX);
            $archive = new ZipArchive();
            $opened = $archive->open($archivePath) === true;
            $assert($opened, "archive {$format} illisible");
            if ($opened) {
                $requiredEntry = $format === 'excel' ? 'xl/workbook.xml' : 'word/document.xml';
                $assert($archive->locateName($requiredEntry) !== false, "structure interne {$format} invalide");
                $archive->close();
            }
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        }
    }

    $statisticsExport = $httpRequest($baseUrl, '/exports/statistiques/excel?annee=2024', $adminCookie);
    $assert($statisticsExport['status'] === 200, 'export Excel des statistiques doit retourner HTTP 200');
    $assert(
        str_contains($statisticsExport['content_type'], 'spreadsheetml')
        && str_starts_with($statisticsExport['body'], 'PK'),
        'export Excel des statistiques doit etre un classeur XLSX valide'
    );
    $statisticsArchivePath = tempnam(sys_get_temp_dir(), 'risfm_p12_stats_');
    file_put_contents($statisticsArchivePath, $statisticsExport['body'], LOCK_EX);
    $statisticsArchive = new ZipArchive();
    $statisticsOpened = $statisticsArchive->open($statisticsArchivePath) === true;
    $assert($statisticsOpened, 'archive Excel des statistiques illisible');
    if ($statisticsOpened) {
        $workbookXml = (string) $statisticsArchive->getFromName('xl/workbook.xml');
        foreach (['Synthese', 'Par statut', 'Par type de titre', 'Par annee du titre', 'Progression mensuelle'] as $sheetName) {
            $assert(str_contains($workbookXml, $sheetName), "feuille {$sheetName} absente de l export statistique");
        }
        $statisticsArchive->close();
    }
    if (is_file($statisticsArchivePath)) {
        unlink($statisticsArchivePath);
    }

    // Une session deja ouverte doit etre rejetee des la requete suivante.
    $revokedCookie = tempnam(sys_get_temp_dir(), 'risfm_p12_revoked_');
    $cookieFiles[] = $revokedCookie;
    $login($baseUrl, 'OIPI-RISFM-000004', $revokedCookie);
    $assert($httpRequest($baseUrl, '/dashboard', $revokedCookie)['status'] === 200, 'la session consultation doit etre ouverte avant revocation');
    (new UserRepository())->repo_toggleActive($users['consultation']);
    $revoked = $httpRequest($baseUrl, '/dashboard', $revokedCookie);
    $assert($revoked['status'] === 302 && str_contains($revoked['headers'], '/login'), 'la desactivation doit revoquer immediatement la session');
    $activeConnections = (int) $scalar(
        "SELECT COUNT(*) FROM connexions WHERE utilisateur_id = :id AND statut = 'actif'",
        ['id' => $users['consultation']]
    );
    $assert($activeConnections === 0, 'les connexions du compte desactive doivent etre fermees');

    // Recette responsive dans un vrai Chrome headless authentifie.
    $chromeBinary = trim((string) shell_exec('command -v google-chrome 2>/dev/null'));
    $assert($chromeBinary !== '', 'Google Chrome doit etre disponible pour la recette responsive');
    if ($chromeBinary !== '') {
        $chromeSocket = stream_socket_server('tcp://127.0.0.1:0', $chromeSocketError, $chromeSocketMessage);
        if (!is_resource($chromeSocket)) {
            throw new RuntimeException('Port Chrome de recette indisponible.');
        }
        $chromeName = stream_socket_get_name($chromeSocket, false);
        fclose($chromeSocket);
        $chromePort = (int) substr(strrchr((string) $chromeName, ':'), 1);
        $chromeDirectory = sys_get_temp_dir() . '/risfm_p12_chrome_' . bin2hex(random_bytes(5));
        mkdir($chromeDirectory, 0700, true);
        $chromeLog = tempnam(sys_get_temp_dir(), 'risfm_p12_chrome_log_');
        $chromeProcess = proc_open(
            [
                $chromeBinary, '--headless=new', '--no-sandbox', '--disable-gpu',
                '--disable-dev-shm-usage', '--remote-allow-origins=*',
                '--remote-debugging-port=' . $chromePort,
                '--user-data-dir=' . $chromeDirectory, 'about:blank',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', $chromeLog, 'a'], 2 => ['file', $chromeLog, 'a']],
            $chromePipes,
            BASE_PATH
        );
        if (!is_resource($chromeProcess)) {
            throw new RuntimeException('Demarrage de Chrome headless impossible.');
        }
        fclose($chromePipes[0]);

        $targets = null;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $json = @file_get_contents('http://127.0.0.1:' . $chromePort . '/json');
            if (is_string($json)) {
                $decoded = json_decode($json, true);
                $pageTargets = is_array($decoded)
                    ? array_values(array_filter(
                        $decoded,
                        static fn (array $target): bool => ($target['type'] ?? '') === 'page'
                            && ($target['url'] ?? '') === 'about:blank'
                            && isset($target['webSocketDebuggerUrl'])
                    ))
                    : [];
                if (isset($pageTargets[0]['webSocketDebuggerUrl'])) {
                    $targets = $pageTargets;
                    break;
                }
            }
            usleep(50_000);
        }
        $assert(is_array($targets), 'l interface Chrome DevTools doit demarrer');
        if (is_array($targets)) {
            $cdp = new P12CdpClient((string) $targets[0]['webSocketDebuggerUrl']);
            $cdp->command('Page.enable');
            $cdp->command('Network.enable');
            $sessionValue = p12CookieValue($adminCookie, (string) env('SESSION_NAME', 'RISFM_SESSION'));
            $assert($sessionValue !== null, 'le cookie administrateur doit etre disponible pour Chrome');
            if ($sessionValue !== null) {
                $cookieResult = $cdp->command('Network.setCookie', [
                    'name' => (string) env('SESSION_NAME', 'RISFM_SESSION'),
                    'value' => $sessionValue,
                    'url' => $baseUrl,
                    'httpOnly' => true,
                    'sameSite' => 'Lax',
                ]);
                $assert(!empty($cookieResult['result']['success']), 'Chrome doit accepter le cookie de recette');
            }

            $viewports = [
                'ordinateur' => [1366, 768, ['/dashboard', '/formulaires', '/formulaires/ajouter', '/utilisateurs', '/connexions']],
                'tablette' => [820, 1180, ['/dashboard', '/formulaires', '/formulaires/ajouter', '/connexions']],
                'telephone' => [390, 844, ['/dashboard', '/formulaires', '/formulaires/ajouter', '/connexions']],
            ];
            foreach ($viewports as $device => [$width, $height, $routes]) {
                $cdp->command('Emulation.setDeviceMetricsOverride', [
                    'width' => $width,
                    'height' => $height,
                    'deviceScaleFactor' => 1,
                    'mobile' => $device !== 'ordinateur',
                ]);
                foreach ($routes as $route) {
                    $cdp->command('Page.navigate', ['url' => $baseUrl . $route]);
                    $state = [];
                    for ($attempt = 0; $attempt < 80; $attempt++) {
                        usleep(50_000);
                        $evaluation = $cdp->command('Runtime.evaluate', [
                            'expression' => '({ready:document.readyState,title:document.title,href:location.href,viewport:window.innerWidth,scroll:document.documentElement.scrollWidth,app:!!document.querySelector(".content-wrapper"),login:!!document.querySelector("input[name=identifiant]")})',
                            'returnByValue' => true,
                        ]);
                        $state = $evaluation['result']['result']['value'] ?? [];
                        if (($state['ready'] ?? '') === 'complete') {
                            break;
                        }
                    }
                    $assert(!empty($state['app']) && empty($state['login']), "{$device} {$route} doit afficher l application authentifiee");
                    $overflow = (int) ($state['scroll'] ?? 0) - (int) ($state['viewport'] ?? 0);
                    $assert($overflow <= 2, "{$device} {$route} deborde horizontalement de {$overflow}px");
                    $screenshot = $cdp->command('Page.captureScreenshot', ['format' => 'png', 'fromSurface' => true]);
                    $image = base64_decode((string) ($screenshot['result']['data'] ?? ''), true);
                    $assert(is_string($image) && strlen($image) > 1_000, "capture responsive {$device} {$route} invalide");
                }
            }
            $cdp->close();
        }
    }
} finally {
    if (is_resource($chromeProcess)) {
        proc_terminate($chromeProcess);
        proc_close($chromeProcess);
    }
    if (is_string($chromeDirectory) && is_dir($chromeDirectory)) {
        p12RemoveTree($chromeDirectory);
    }
    if (is_string($chromeLog) && is_file($chromeLog)) {
        unlink($chromeLog);
    }
    proc_terminate($serverProcess);
    proc_close($serverProcess);
    if (is_string($serverLog) && is_file($serverLog)) {
        unlink($serverLog);
    }
    foreach ($cookieFiles as $cookieFile) {
        if (is_string($cookieFile) && is_file($cookieFile)) {
            unlink($cookieFile);
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "P12 OK: authentification/RBAC, concurrence, cycles, annulation, reaffectation, reouverture, finalisation, notifications, exports, revocation et responsive verifies.\n";

function p12CookieValue(string $cookieFile, string $cookieName): ?string
{
    $lines = file($cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return null;
    }
    foreach ($lines as $line) {
        $normalized = str_starts_with($line, '#HttpOnly_') ? substr($line, strlen('#HttpOnly_')) : $line;
        if ($normalized === '' || str_starts_with($normalized, '#')) {
            continue;
        }
        $parts = explode("\t", $normalized);
        if (count($parts) >= 7 && $parts[5] === $cookieName) {
            return $parts[6];
        }
    }
    return null;
}

function p12RemoveTree(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($directory);
}

final class P12CdpClient
{
    /** @var resource */
    private $socket;
    private int $nextId = 1;

    public function __construct(string $webSocketUrl)
    {
        $parts = parse_url($webSocketUrl);
        if (!is_array($parts) || empty($parts['host']) || empty($parts['port']) || empty($parts['path'])) {
            throw new RuntimeException('URL WebSocket Chrome invalide.');
        }
        $socket = stream_socket_client(
            'tcp://' . $parts['host'] . ':' . $parts['port'],
            $errorNumber,
            $errorMessage,
            5
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('Connexion Chrome DevTools impossible : ' . $errorMessage);
        }
        stream_set_timeout($socket, 10);
        $key = base64_encode(random_bytes(16));
        $request = "GET {$parts['path']} HTTP/1.1\r\n"
            . "Host: {$parts['host']}:{$parts['port']}\r\n"
            . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n"
            . "Origin: http://localhost\r\n\r\n";
        fwrite($socket, $request);
        $headers = '';
        while (($line = fgets($socket)) !== false) {
            $headers .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        if (!str_contains($headers, ' 101 ')) {
            $statusLine = trim(strtok($headers, "\r\n") ?: 'reponse vide');
            stream_set_timeout($socket, 1);
            $body = stream_get_contents($socket);
            $detail = trim(preg_replace('/\s+/', ' ', is_string($body) ? $body : ''));
            throw new RuntimeException(
                'Handshake Chrome DevTools refuse : ' . $statusLine
                . ($detail !== '' ? ' , ' . mb_substr($detail, 0, 300) : '')
            );
        }
        $this->socket = $socket;
    }

    public function command(string $method, array $parameters = []): array
    {
        $id = $this->nextId++;
        $this->send(json_encode([
            'id' => $id,
            'method' => $method,
            'params' => (object) $parameters,
        ], JSON_THROW_ON_ERROR));
        while (true) {
            $message = json_decode($this->receive(), true, 512, JSON_THROW_ON_ERROR);
            if (($message['id'] ?? null) === $id) {
                if (isset($message['error'])) {
                    throw new RuntimeException("Chrome DevTools {$method} : " . json_encode($message['error']));
                }
                return $message;
            }
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    private function send(string $payload): void
    {
        $length = strlen($payload);
        $header = chr(0x81);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length <= 65_535) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('NN', 0, $length);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($index = 0; $index < $length; $index++) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }
        fwrite($this->socket, $header . $mask . $masked);
    }

    private function receive(): string
    {
        while (true) {
            $header = $this->readExact(2);
            $opcode = ord($header[0]) & 0x0F;
            $masked = (ord($header[1]) & 0x80) !== 0;
            $length = ord($header[1]) & 0x7F;
            if ($length === 126) {
                $length = unpack('nlength', $this->readExact(2))['length'];
            } elseif ($length === 127) {
                $bytes = $this->readExact(8);
                $length = 0;
                for ($index = 0; $index < 8; $index++) {
                    $length = ($length * 256) + ord($bytes[$index]);
                }
            }
            $mask = $masked ? $this->readExact(4) : '';
            $payload = $this->readExact($length);
            if ($masked) {
                for ($index = 0; $index < $length; $index++) {
                    $payload[$index] = $payload[$index] ^ $mask[$index % 4];
                }
            }
            if ($opcode === 0x9) {
                continue;
            }
            if ($opcode === 0x8) {
                throw new RuntimeException('Chrome DevTools a ferme la connexion.');
            }
            if ($opcode === 0x1) {
                return $payload;
            }
        }
    }

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Lecture Chrome DevTools interrompue.');
            }
            $data .= $chunk;
        }
        return $data;
    }
}
