<?php
declare(strict_types=1);

namespace App\Repositories\Utilisateur;

use App\Core\Repository;

final class DemandeReinitialisationRepository extends Repository
{
    protected string $table = 'demandes_reinitialisation';

    public function repo_enregistrer(string $emailHash, ?string $ip): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO demandes_reinitialisation (email_hash, adresse_ip, demandee_le)
             VALUES (:email_hash, :ip, NOW())'
        );
        $stmt->execute(['email_hash' => $emailHash, 'ip' => $ip]);
    }

    public function repo_compterDepuisParEmail(string $emailHash, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM demandes_reinitialisation
             WHERE email_hash = :email_hash AND demandee_le >= :since'
        );
        $stmt->execute(['email_hash' => $emailHash, 'since' => $since]);
        return (int) $stmt->fetchColumn();
    }

    public function repo_compterDepuisParIp(string $ip, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM demandes_reinitialisation
             WHERE adresse_ip = :ip AND demandee_le >= :since'
        );
        $stmt->execute(['ip' => $ip, 'since' => $since]);
        return (int) $stmt->fetchColumn();
    }

    public function repo_purgerAvant(string $cutoff, int $batchSize): int
    {
        $batchSize = max(1, min(10000, $batchSize));
        $stmt = $this->db->prepare(
            'DELETE FROM demandes_reinitialisation
             WHERE demandee_le < :cutoff
             LIMIT ' . $batchSize
        );
        $stmt->execute(['cutoff' => $cutoff]);
        return $stmt->rowCount();
    }
}
