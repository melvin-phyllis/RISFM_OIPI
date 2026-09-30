<?php
declare(strict_types=1);

use App\Core\Database;
use App\Core\LoginRateLimiter;
use App\Core\PasswordResetRateLimiter;
use App\Services\Auth\PasswordResetService;

/**
 * Mot de passe oublie : quota par adresse e-mail et par IP, compteurs
 * distincts de ceux de la connexion, adresse stockee sous forme d'empreinte.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/config/autoload.php';

$db = Database::getConnection();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$suffix = bin2hex(random_bytes(4));
$email = "quota-{$suffix}@example.invalid";
$emailIp = '198.51.100.' . random_int(1, 120);
$sharedIp = '203.0.113.' . random_int(1, 250);
$limiter = new PasswordResetRateLimiter($db);
$ips = [$emailIp, $sharedIp];

try {
    // Quota par adresse, insensible a la casse, quelle que soit l'IP.
    for ($index = 0; $index < PASSWORD_RESET_EMAIL_MAX_REQUESTS; $index++) {
        $assert($limiter->attempt($index % 2 === 0 ? $email : strtoupper($email), $emailIp), "demande {$index} acceptee sous le quota de l'adresse");
    }
    $assert(!$limiter->attempt($email, $emailIp), 'le quota par adresse doit bloquer');
    $otherIp = '192.0.2.' . random_int(1, 250);
    $ips[] = $otherIp;
    $assert(!$limiter->attempt(' ' . strtoupper($email) . ' ', $otherIp), 'changer d IP ou de casse ne contourne pas le quota par adresse');

    $stored = $db->prepare('SELECT COUNT(*) FROM demandes_reinitialisation WHERE email_hash = :hash');
    $stored->execute(['hash' => hash('sha256', $email)]);
    $assert((int) $stored->fetchColumn() === PASSWORD_RESET_EMAIL_MAX_REQUESTS, 'une demande refusee n est pas comptee');
    $clear = $db->prepare('SELECT COUNT(*) FROM demandes_reinitialisation WHERE email_hash = :email');
    $clear->execute(['email' => $email]);
    $assert((int) $clear->fetchColumn() === 0, 'l adresse n est jamais stockee en clair');

    // Quota par IP avec des adresses tournantes.
    for ($index = 0; $index < PASSWORD_RESET_IP_MAX_REQUESTS; $index++) {
        $assert($limiter->attempt("ip-{$suffix}-{$index}@example.invalid", $sharedIp), "demande {$index} acceptee sous le quota de l IP");
    }
    $assert(!$limiter->attempt("ip-{$suffix}-nouvelle@example.invalid", $sharedIp), 'le quota par IP doit bloquer les adresses tournantes');

    // La connexion depuis cette IP n'est pas penalisee par ces demandes.
    $login = (new LoginRateLimiter($db))->inspect('RESET-' . strtoupper($suffix), $sharedIp);
    $assert(!$login['blocked'] && $login['ip_attempts'] === 0, 'les demandes de reinitialisation ne consomment pas le quota de connexion');

    // Le service applique le quota sans rien reveler a l'appelant.
    $serviceEmail = "service-{$suffix}@example.invalid";
    $serviceIp = '192.0.2.' . random_int(1, 250);
    $ips[] = $serviceIp;
    for ($index = 0; $index <= PASSWORD_RESET_EMAIL_MAX_REQUESTS + 1; $index++) {
        (new PasswordResetService())->srv_demander(['email' => $serviceEmail], $serviceIp);
    }
    $stored->execute(['hash' => hash('sha256', $serviceEmail)]);
    $assert((int) $stored->fetchColumn() === PASSWORD_RESET_EMAIL_MAX_REQUESTS, 'le service doit s arreter au quota de l adresse');

    // Purge : les demandes anciennes disparaissent, les recentes restent.
    $old = $db->prepare('INSERT INTO demandes_reinitialisation (email_hash, adresse_ip, demandee_le) VALUES (:hash, :ip, :date)');
    $old->execute(['hash' => hash('sha256', 'ancienne-' . $suffix), 'ip' => $emailIp, 'date' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
    $limiter->pruneExpired();
    $stored->execute(['hash' => hash('sha256', 'ancienne-' . $suffix)]);
    $assert((int) $stored->fetchColumn() === 0, 'les demandes anciennes doivent etre purgees');
    $stored->execute(['hash' => hash('sha256', $email)]);
    $assert((int) $stored->fetchColumn() === PASSWORD_RESET_EMAIL_MAX_REQUESTS, 'les demandes recentes doivent etre conservees');
} finally {
    $cleanup = $db->prepare('DELETE FROM demandes_reinitialisation WHERE adresse_ip = :ip');
    foreach ($ips as $ip) {
        $cleanup->execute(['ip' => $ip]);
    }
    $db->prepare('DELETE FROM demandes_reinitialisation WHERE email_hash = :hash')->execute(['hash' => hash('sha256', $email)]);
}

if ($failures !== []) {
    fwrite(STDERR, 'ECHEC: ' . implode("\nECHEC: ", $failures) . "\n");
    exit(1);
}
echo "SECURITE MOT DE PASSE OUBLIE OK: quota par adresse et par IP, compteurs separes de la connexion, empreinte et purge verifies.\n";
