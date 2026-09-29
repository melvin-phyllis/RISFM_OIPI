<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\Utilisateur\TentativeConnexionRepository;
use DateTimeImmutable;
use PDO;

/** Limite les essais de connexion par compte et par adresse IP. */
final class LoginRateLimiter
{
    private const PRUNE_BATCH_SIZE = 5000;

    private TentativeConnexionRepository $attempts;

    public function __construct(?PDO $db = null)
    {
        $this->attempts = new TentativeConnexionRepository($db);
    }

    /**
     * @return array{
     *   blocked:bool,scopes:list<string>,retry_after_seconds:int,
     *   identifier_attempts:int,identifier_limit:int,ip_attempts:int,ip_limit:int
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

    public function pruneExpired(): int
    {
        $cutoff = (new DateTimeImmutable())
            ->modify('-' . LOGIN_ATTEMPT_RETENTION_DAYS . ' days')
            ->format('Y-m-d H:i:s');
        $deleted = 0;

        do {
            $batch = $this->attempts->repo_purgerAvant($cutoff, self::PRUNE_BATCH_SIZE);
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
        if ($value === '') {
            return ['attempts' => 0, 'blocked' => false, 'retry_after_seconds' => 0];
        }

        $row = $this->attempts->repo_etatEchecs($column, $value, $since);
        $attempts = $row['attempts'];
        $blocked = $attempts >= $limit;
        $retry = 0;

        if ($blocked && $row['first_attempt'] !== null) {
            $firstAttempt = strtotime($row['first_attempt']);
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
