<?php
declare(strict_types=1);

/**
 * Limite les essais de connexion par compte et par adresse IP.
 *
 * Le seuil IP est distinct et plus eleve que celui d'un identifiant : dans
 * un reseau d'entreprise, plusieurs agents peuvent partager la meme adresse
 * publique. Seule REMOTE_ADDR est utilisee ; un en-tete transmis par le
 * navigateur ne doit jamais pouvoir choisir l'IP prise en compte.
 */
final class LoginRateLimiter
{
    private const PRUNE_BATCH_SIZE = 5000;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * @return array{
     *   blocked:bool,
     *   scopes:list<string>,
     *   retry_after_seconds:int,
     *   identifier_attempts:int,
     *   identifier_limit:int,
     *   ip_attempts:int,
     *   ip_limit:int
     * }
     */
    public function inspect(string $identifier, ?string $ip): array
    {
        $identifier = mb_substr(trim($identifier), 0, 50);
        $ip = self::normalizeIp($ip);
        $since = (new DateTimeImmutable())
            ->modify('-' . LOGIN_RATE_LIMIT_WINDOW_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $identifierState = $this->failureState(
            'identifiant',
            $identifier,
            LOGIN_IDENTIFIER_MAX_ATTEMPTS,
            $since
        );
        $ipState = $ip === null
            ? ['attempts' => 0, 'blocked' => false, 'retry_after_seconds' => 0]
            : $this->failureState('adresse_ip', $ip, LOGIN_IP_MAX_ATTEMPTS, $since);

        $scopes = [];
        if ($identifierState['blocked']) {
            $scopes[] = 'identifiant';
        }
        if ($ipState['blocked']) {
            $scopes[] = 'ip';
        }

        return [
            'blocked' => $scopes !== [],
            'scopes' => $scopes,
            'retry_after_seconds' => max(
                $identifierState['retry_after_seconds'],
                $ipState['retry_after_seconds']
            ),
            'identifier_attempts' => $identifierState['attempts'],
            'identifier_limit' => LOGIN_IDENTIFIER_MAX_ATTEMPTS,
            'ip_attempts' => $ipState['attempts'],
            'ip_limit' => LOGIN_IP_MAX_ATTEMPTS,
        ];
    }

    /** Supprime les tentatives sortant de la duree de conservation. */
    public function pruneExpired(): int
    {
        $cutoff = (new DateTimeImmutable())
            ->modify('-' . LOGIN_ATTEMPT_RETENTION_DAYS . ' days')
            ->format('Y-m-d H:i:s');
        $deleted = 0;

        do {
            $stmt = $this->db->prepare(
                'DELETE FROM tentatives_connexion
                 WHERE tentee_le < :cutoff
                 LIMIT ' . self::PRUNE_BATCH_SIZE
            );
            $stmt->execute(['cutoff' => $cutoff]);
            $batch = $stmt->rowCount();
            $deleted += $batch;
        } while ($batch === self::PRUNE_BATCH_SIZE);

        return $deleted;
    }

    public static function normalizeIp(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return mb_substr($ip, 0, 45);
    }

    /** @return array{attempts:int,blocked:bool,retry_after_seconds:int} */
    private function failureState(string $column, string $value, int $limit, string $since): array
    {
        if (!in_array($column, ['identifiant', 'adresse_ip'], true) || $value === '') {
            return ['attempts' => 0, 'blocked' => false, 'retry_after_seconds' => 0];
        }

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS attempts, MIN(tentee_le) AS first_attempt
             FROM tentatives_connexion
             WHERE succes = 0
               AND {$column} = :value
               AND tentee_le >= :since"
        );
        $stmt->execute(['value' => $value, 'since' => $since]);
        $row = $stmt->fetch() ?: [];
        $attempts = (int) ($row['attempts'] ?? 0);
        $blocked = $attempts >= $limit;
        $retry = 0;

        if ($blocked && !empty($row['first_attempt'])) {
            $firstAttempt = strtotime((string) $row['first_attempt']);
            if ($firstAttempt !== false) {
                $retry = max(1, $firstAttempt + LOGIN_RATE_LIMIT_WINDOW_MINUTES * 60 - time());
            }
        }

        return [
            'attempts' => $attempts,
            'blocked' => $blocked,
            'retry_after_seconds' => $retry,
        ];
    }
}
