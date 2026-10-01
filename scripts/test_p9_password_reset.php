<?php
declare(strict_types=1);

use App\Core\Database;
use App\Repositories\Utilisateur\TokenResetRepository;
use App\Repositories\Utilisateur\UserRepository;
use App\Services\Auth\PasswordResetService;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

$db = Database::getConnection();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$suffix = strtoupper(bin2hex(random_bytes(5)));
$identifiant = 'P9-TEST-' . $suffix;
$email = strtolower($identifiant) . '@example.invalid';
$userId = null;
$serviceId = (int) $db->query("SELECT id FROM services WHERE code = 'DG'")->fetchColumn();

try {
    $insertUser = $db->prepare(
        "INSERT INTO utilisateurs
            (identifiant, nom, prenoms, email, mot_de_passe, role, service_id, actif, doit_changer_mdp, session_version)
         VALUES
            (:identifiant, 'Test', 'Securite P9', :email, :mot_de_passe, 'consultation', :service_id, 1, 0, 1)"
    );
    $insertUser->execute([
        'identifiant' => $identifiant,
        'email' => $email,
        'mot_de_passe' => password_hash('Ancien@2026!', PASSWORD_DEFAULT),
        'service_id' => $serviceId,
    ]);
    $userId = (int) $db->lastInsertId();

    $connection = $db->prepare(
        "INSERT INTO connexions
            (utilisateur_id, adresse_ip, navigateur, statut, connecte_le, derniere_activite)
         VALUES (:id, '127.0.0.1', 'Test P9', 'actif', NOW(), NOW())"
    );
    $connection->execute(['id' => $userId]);
    $connectionId = (int) $db->lastInsertId();

    $failedAttempt = $db->prepare(
        "INSERT INTO tentatives_connexion
            (identifiant, succes, adresse_ip, navigateur, tentee_le)
         VALUES (:identifiant, 0, '127.0.0.1', 'Test P9', NOW())"
    );
    $failedAttempt->execute(['identifiant' => $identifiant]);

    $tokens = new TokenResetRepository();
    $firstToken = $tokens->repo_creer($userId, 60);
    $assert(strlen($firstToken) === 64, 'le jeton public doit contenir 64 caracteres hexadecimaux');

    $firstRow = $db->prepare(
        'SELECT token_hash, utilise FROM tokens_reinitialisation
         WHERE utilisateur_id = :id ORDER BY id DESC LIMIT 1'
    );
    $firstRow->execute(['id' => $userId]);
    $stored = $firstRow->fetch();
    $assert($stored !== false, 'le premier jeton doit etre cree');
    $assert(
        isset($stored['token_hash']) && hash_equals(hash('sha256', $firstToken), (string) $stored['token_hash']),
        'la base doit contenir uniquement le SHA-256 du jeton'
    );
    $assert((string) ($stored['token_hash'] ?? '') !== $firstToken, 'le jeton brut ne doit pas etre stocke');
    $assert($tokens->repo_valide('format-invalide') === null, 'un jeton de format invalide doit etre refuse');

    $secondToken = $tokens->repo_creer($userId, 60);
    $assert($tokens->repo_valide($firstToken) === null, 'une nouvelle demande doit invalider le premier lien');
    $assert($tokens->repo_valide($secondToken) !== null, 'le lien le plus recent doit rester valide');

    $newPassword = 'Nouveau@2026!P9';
    $resetUserId = (new PasswordResetService())->srv_reset($secondToken, $newPassword);
    $assert($resetUserId === $userId, 'le lien valide doit reinitialiser le bon compte');
    $assert(
        (new PasswordResetService())->srv_reset($secondToken, 'Autre@2026!P9') === null,
        'un lien consomme ne doit jamais etre reutilisable'
    );

    $user = (new UserRepository())->repo_find($userId);
    $assert($user !== null && password_verify($newPassword, (string) $user['mot_de_passe']), 'le nouveau mot de passe doit etre enregistre');
    $assert((int) ($user['session_version'] ?? 0) === 2, 'la version de session doit etre incrementee une seule fois');

    $activeConnection = $db->prepare(
        "SELECT COUNT(*) FROM connexions WHERE id = :id AND statut = 'actif'"
    );
    $activeConnection->execute(['id' => $connectionId]);
    $assert((int) $activeConnection->fetchColumn() === 0, 'les connexions existantes doivent etre fermees');

    $activeTokens = $db->prepare(
        'SELECT COUNT(*) FROM tokens_reinitialisation WHERE utilisateur_id = :id AND utilise = 0'
    );
    $activeTokens->execute(['id' => $userId]);
    $assert((int) $activeTokens->fetchColumn() === 0, 'tous les jetons du compte doivent etre invalides');

    $remainingAttempts = $db->prepare(
        'SELECT COUNT(*) FROM tentatives_connexion WHERE identifiant = :identifiant AND succes = 0'
    );
    $remainingAttempts->execute(['identifiant' => $identifiant]);
    $assert((int) $remainingAttempts->fetchColumn() === 0, 'les echecs de connexion doivent etre purges apres reinitialisation');

    $expiredHash = hash('sha256', bin2hex(random_bytes(32)));
    $expired = $db->prepare(
        "INSERT INTO tokens_reinitialisation
            (utilisateur_id, token_hash, expire_le, utilise, cree_le)
         VALUES (:id, :hash, NOW() - INTERVAL 1 MINUTE, 0, NOW() - INTERVAL 2 MINUTE)"
    );
    $expired->execute(['id' => $userId, 'hash' => $expiredHash]);
    $assert($tokens->repo_purgerExpires() >= 1, 'la purge doit supprimer les jetons expires');

    $expiredCheck = $db->prepare('SELECT COUNT(*) FROM tokens_reinitialisation WHERE token_hash = :hash');
    $expiredCheck->execute(['hash' => $expiredHash]);
    $assert((int) $expiredCheck->fetchColumn() === 0, 'le jeton expire ne doit plus exister');
} finally {
    if ($userId !== null) {
        $deleteAttempts = $db->prepare('DELETE FROM tentatives_connexion WHERE identifiant = :identifiant');
        $deleteAttempts->execute(['identifiant' => $identifiant]);
        $deleteUser = $db->prepare('DELETE FROM utilisateurs WHERE id = :id');
        $deleteUser->execute(['id' => $userId]);
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "P9 OK: empreinte seule, ancien lien invalide, consommation unique, sessions fermees et purge active.\n";
