<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\Utilisateur\UserRepository;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$db = Database::getConnection();
$users = $db->query(
    'SELECT id, identifiant, email, mot_de_passe FROM utilisateurs ORDER BY id'
)->fetchAll();
if ($users === []) {
    fwrite(STDERR, "ECHEC: aucun compte utilisateur.\n");
    exit(1);
}

foreach ($users as $user) {
    $expected = UserRepository::repo_generatedIdentifiant((int) $user['id']);
    if ((string) $user['identifiant'] !== $expected) {
        fwrite(STDERR, "ECHEC: compte #{$user['id']} non normalise.\n");
        exit(1);
    }
    if (password_get_info((string) $user['mot_de_passe'])['algo'] === null) {
        fwrite(STDERR, "ECHEC: mot de passe du compte #{$user['id']} invalide.\n");
        exit(1);
    }
}

$duplicates = (int) $db->query(
    'SELECT COUNT(*) FROM (
        SELECT identifiant FROM utilisateurs GROUP BY identifiant HAVING COUNT(*) > 1
     ) doublons'
)->fetchColumn();
if ($duplicates !== 0) {
    fwrite(STDERR, "ECHEC: doublon d'identifiant apres migration.\n");
    exit(1);
}

$orphans = (int) $db->query(
    'SELECT COUNT(*)
     FROM formulaires_manquants f
     LEFT JOIN utilisateurs u ON u.id = f.responsable_id
     WHERE f.responsable_id IS NOT NULL AND u.id IS NULL'
)->fetchColumn();
if ($orphans !== 0) {
    fwrite(STDERR, "ECHEC: relation responsable orpheline apres migration.\n");
    exit(1);
}

$legacyLogin = $db->query(
    "SELECT COUNT(*) FROM utilisateurs
     WHERE identifiant IN ('admin', 'service.documentation', 'chef.projet')
        OR identifiant LIKE 'P7TMP-%'"
)->fetchColumn();
if ((int) $legacyLogin !== 0) {
    fwrite(STDERR, "ECHEC: ancien identifiant encore actif.\n");
    exit(1);
}

// Le schema ne livre aucune donnee, et les seeders n'utilisent que des
// e-mails : les identifiants sont toujours derives de l'ID (OIPI-RISFM-XXXXXX).
$schema = (string) file_get_contents(BASE_PATH . '/schema.sql');
$seeders = '';
foreach (glob(BASE_PATH . '/database/seeders/*.php') ?: [] as $seederFile) {
    $seeders .= (string) file_get_contents($seederFile);
}
if (str_contains($schema, 'INSERT INTO')
    || !str_contains($seeders, "'demo.documentation@oipi.test'")
    || str_contains($seeders, "'OIPI-RISFM-")
    || str_contains($seeders, "'service.documentation'")
    || str_contains($seeders, "'chef.projet'")
) {
    fwrite(STDERR, "ECHEC: scripts de livraison non normalises.\n");
    exit(1);
}

$migrationLogs = (int) $db->query(
    "SELECT COUNT(*) FROM activites WHERE type_action = 'migration_identifiant'"
)->fetchColumn();

echo 'P7 ETAT OK: ' . count($users) . " compte(s) normalise(s), {$migrationLogs} trace(s) de migration, relations intactes.\n";
