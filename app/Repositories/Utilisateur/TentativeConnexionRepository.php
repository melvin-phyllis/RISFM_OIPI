<?php
declare(strict_types=1);

namespace App\Repositories\Utilisateur;

use App\Core\Repository;
use InvalidArgumentException;

final class TentativeConnexionRepository extends Repository
{
    private const ALLOWED_SCOPE_COLUMNS = ['identifiant', 'adresse_ip'];

    protected string $table = 'tentatives_connexion';

    public function repo_enregistrer(
        string $identifiant,
        bool $succes,
        ?string $ip,
        ?string $agent
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO tentatives_connexion
                (identifiant, succes, adresse_ip, navigateur, tentee_le)
             VALUES (:identifiant, :succes, :ip, :agent, NOW())'
        );
        $stmt->execute([
            'identifiant' => mb_substr($identifiant, 0, 50),
            'succes' => $succes ? 1 : 0,
            'ip' => $ip,
            'agent' => mb_substr((string) $agent, 0, 255) ?: null,
        ]);
    }

    public function repo_effacerEchecs(string $identifiant): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tentatives_connexion
             WHERE identifiant = :identifiant AND succes = 0'
        );
        $stmt->execute(['identifiant' => mb_substr($identifiant, 0, 50)]);
        return $stmt->rowCount();
    }

    public function repo_purgerAvant(string $cutoff, int $batchSize): int
    {
        $batchSize = max(1, min(10000, $batchSize));
        $stmt = $this->db->prepare(
            'DELETE FROM tentatives_connexion
             WHERE tentee_le < :cutoff
             LIMIT ' . $batchSize
        );
        $stmt->execute(['cutoff' => $cutoff]);
        return $stmt->rowCount();
    }

    /** @return array{attempts:int,first_attempt:?string} */
    public function repo_etatEchecs(string $column, string $value, string $since): array
    {
        if (!in_array($column, self::ALLOWED_SCOPE_COLUMNS, true)) {
            throw new InvalidArgumentException('Colonne de limitation de connexion invalide.');
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

        return [
            'attempts' => (int) ($row['attempts'] ?? 0),
            'first_attempt' => empty($row['first_attempt']) ? null : (string) $row['first_attempt'],
        ];
    }
}
