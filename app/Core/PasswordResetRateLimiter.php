<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\Utilisateur\DemandeReinitialisationRepository;
use DateTimeImmutable;
use PDO;

/**
 * Limite les demandes "mot de passe oublie" par adresse e-mail et par IP.
 * L'adresse n'est conservee que sous forme d'empreinte SHA-256. Les compteurs
 * sont distincts de ceux de la connexion (LoginRateLimiter).
 */
final class PasswordResetRateLimiter
{
    private const PRUNE_BATCH_SIZE = 5000;

    private DemandeReinitialisationRepository $requests;

    public function __construct(?PDO $db = null)
    {
        $this->requests = new DemandeReinitialisationRepository($db);
    }

    /**
     * Enregistre la demande si les deux quotas le permettent. Retourne false
     * quand la demande doit etre ignoree ; elle n'est alors pas comptee.
     */
    public function attempt(string $email, ?string $ip): bool
    {
        $emailHash = self::emailHash($email);
        $ip = LoginRateLimiter::normalizeIp($ip);
        $since = (new DateTimeImmutable())
            ->modify('-' . PASSWORD_RESET_WINDOW_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        if ($this->requests->repo_compterDepuisParEmail($emailHash, $since) >= PASSWORD_RESET_EMAIL_MAX_REQUESTS) {
            return false;
        }
        if ($ip !== null && $this->requests->repo_compterDepuisParIp($ip, $since) >= PASSWORD_RESET_IP_MAX_REQUESTS) {
            return false;
        }

        $this->requests->repo_enregistrer($emailHash, $ip);
        return true;
    }

    public function pruneExpired(): int
    {
        // Seule la fenetre courante est utile ; un jour de marge suffit.
        $cutoff = (new DateTimeImmutable())
            ->modify('-' . max(1440, PASSWORD_RESET_WINDOW_MINUTES) . ' minutes')
            ->format('Y-m-d H:i:s');
        $deleted = 0;

        do {
            $batch = $this->requests->repo_purgerAvant($cutoff, self::PRUNE_BATCH_SIZE);
            $deleted += $batch;
        } while ($batch === self::PRUNE_BATCH_SIZE);

        return $deleted;
    }

    private static function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
