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

$db = Database::getConnection();
$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$suffix = strtoupper(bin2hex(random_bytes(4)));
$prefix = 'RATE-' . $suffix . '-';
$identifier = $prefix . 'COMPTE';
$ip = '198.51.100.77';
$neutralIp = '203.0.113.200';
$insert = $db->prepare(
    'INSERT INTO tentatives_connexion
        (identifiant, succes, adresse_ip, navigateur, tentee_le)
     VALUES (:identifiant, :succes, :ip, :agent, :tentee_le)'
);
$limiter = new LoginRateLimiter($db);

$addAttempt = static function (
    string $login,
    bool $success,
    string $address,
    ?string $attemptedAt = null
) use ($insert): void {
    $insert->execute([
        'identifiant' => $login,
        'succes' => $success ? 1 : 0,
        'ip' => $address,
        'agent' => 'Recette rate limiter',
        'tentee_le' => $attemptedAt ?? date('Y-m-d H:i:s'),
    ]);
};

try {
    $assert(!$limiter->inspect($identifier, $neutralIp)['blocked'], 'un compteur vide ne doit pas bloquer');
    $assert(LoginRateLimiter::normalizeIp('adresse-invalide') === null, 'une fausse IP doit etre ignoree');

    // Une reussite et un ancien echec ne participent pas au verrou courant.
    $addAttempt($identifier, true, '198.51.100.1');
    $oldWindowAttempt = (new DateTimeImmutable())
        ->modify('-' . (LOGIN_RATE_LIMIT_WINDOW_MINUTES + 2) . ' minutes')
        ->format('Y-m-d H:i:s');
    $addAttempt($identifier, false, '198.51.100.2', $oldWindowAttempt);

    for ($index = 0; $index < LOGIN_IDENTIFIER_MAX_ATTEMPTS - 1; $index++) {
        $addAttempt($identifier, false, '198.51.100.' . (10 + $index));
    }
    $beforeIdentifierLimit = $limiter->inspect($identifier, $neutralIp);
    $assert(!$beforeIdentifierLimit['blocked'], 'le compte doit rester accessible avant le seuil');

    $addAttempt($identifier, false, '198.51.100.90');
    $identifierBlocked = $limiter->inspect($identifier, $neutralIp);
    $assert($identifierBlocked['blocked'], 'le seuil par identifiant doit bloquer le compte');
    $assert(in_array('identifiant', $identifierBlocked['scopes'], true), 'la cause identifiant doit etre exposee en interne');
    $assert($identifierBlocked['retry_after_seconds'] > 0, 'le delai restant doit etre calcule');

    // Plusieurs identifiants distincts depuis une meme IP finissent par
    // bloquer la source sans utiliser les en-tetes transmis par le client.
    for ($index = 0; $index < LOGIN_IP_MAX_ATTEMPTS - 1; $index++) {
        $addAttempt($prefix . 'IP-' . $index, false, $ip);
    }
    $beforeIpLimit = $limiter->inspect($prefix . 'NOUVEAU', $ip);
    $assert(!$beforeIpLimit['blocked'], 'l IP doit rester autorisee avant son seuil dedie');

    $addAttempt($prefix . 'IP-DERNIER', false, $ip);
    $ipBlocked = $limiter->inspect($prefix . 'AUTRE', $ip);
    $assert($ipBlocked['blocked'], 'le seuil cumule par IP doit bloquer les identifiants tournants');
    $assert(in_array('ip', $ipBlocked['scopes'], true), 'la cause IP doit etre exposee en interne');

    $expiredIdentifier = $prefix . 'ANCIEN';
    $expiredDate = (new DateTimeImmutable())
        ->modify('-' . (LOGIN_ATTEMPT_RETENTION_DAYS + 1) . ' days')
        ->format('Y-m-d H:i:s');
    $addAttempt($expiredIdentifier, false, '192.0.2.44', $expiredDate);
    $deleted = $limiter->pruneExpired();
    $expiredCheck = $db->prepare('SELECT COUNT(*) FROM tentatives_connexion WHERE identifiant = :identifiant');
    $expiredCheck->execute(['identifiant' => $expiredIdentifier]);
    $assert($deleted >= 1, 'la purge doit supprimer au moins la tentative expiree de recette');
    $assert((int) $expiredCheck->fetchColumn() === 0, 'une tentative hors retention doit disparaitre');
} finally {
    $cleanup = $db->prepare(
        'DELETE FROM tentatives_connexion
         WHERE identifiant LIKE :prefix'
    );
    $cleanup->execute(['prefix' => $prefix . '%']);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "ECHEC: {$failure}\n");
    }
    exit(1);
}

echo "SECURITE CONNEXION OK: seuil par identifiant, cumul par IP, delai et retention verifies.\n";
