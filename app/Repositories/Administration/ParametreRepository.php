<?php
declare(strict_types=1);

namespace App\Repositories\Administration;

use App\Core\Repository;

class ParametreRepository extends Repository
{
    protected string $table = 'parametres';

    public function repo_get(string $cle, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT valeur FROM parametres WHERE cle = :cle LIMIT 1');
        $stmt->execute(['cle' => $cle]);
        $row = $stmt->fetch();
        return $row === false ? $default : $row['valeur'];
    }

    public function repo_set(string $cle, string $valeur): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO parametres (cle, valeur) VALUES (:cle, :valeur)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
        );
        return $stmt->execute(['cle' => $cle, 'valeur' => $valeur]);
    }

    public function repo_tous(): array
    {
        $rows = $this->db->query('SELECT cle, valeur FROM parametres')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['cle']] = $row['valeur'];
        }
        return $result;
    }

    /** @param array<string,string> $values */
    public function repo_setMany(array $values): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO parametres (cle, valeur) VALUES (:cle, :valeur)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
        );
        foreach ($values as $key => $value) {
            $statement->execute(['cle' => $key, 'valeur' => $value]);
        }
    }

    /** @return array<string,string> */
    public function repo_parPrefixe(string $prefix): array
    {
        $statement = $this->db->prepare(
            'SELECT cle, valeur FROM parametres WHERE cle LIKE :prefix'
        );
        $statement->execute(['prefix' => $prefix . '%']);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(string) $row['cle']] = (string) ($row['valeur'] ?? '');
        }
        return $result;
    }
}
