<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

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
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/core/helpers.php';

$formats = [
    'csv' => 'formulairesCsv',
    'xlsx' => 'formulairesExcel',
    'pdf' => 'formulairesPdf',
    'docx' => 'formulairesWord',
];
$format = strtolower((string) ($argv[1] ?? ''));
if (!isset($formats[$format])) {
    fwrite(STDERR, "Usage: php scripts/test_p6_export_format.php csv|xlsx|pdf|docx\n");
    exit(2);
}

$db = Database::getConnection();
$user = $db->query(
    "SELECT id, identifiant, nom, prenoms, role, session_version
     FROM utilisateurs WHERE actif = 1 AND role = 'administrateur'
     ORDER BY id LIMIT 1"
)->fetch();
if (!$user) {
    fwrite(STDERR, "ECHEC: aucun administrateur actif pour tester l'autorisation d'export.\n");
    exit(1);
}

$_SESSION = [
    'user_id' => (int) $user['id'],
    'user_identifiant' => (string) $user['identifiant'],
    'user_nom' => trim((string) $user['nom'] . ' ' . (string) $user['prenoms']),
    'user_role' => (string) $user['role'],
    'session_version' => (int) $user['session_version'],
    'must_change_password' => false,
];
$_GET = isset($argv[2]) && trim((string) $argv[2]) !== ''
    ? ['mot_cle' => trim((string) $argv[2])]
    : [];
$_POST = [];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$method = $formats[$format];
(new ExportController())->{$method}();
