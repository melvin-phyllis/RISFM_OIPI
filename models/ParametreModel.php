<?php
declare(strict_types=1);

class ParametreModel extends Model
{
    protected string $table = 'parametres';

    public function get(string $cle, ?string $default = null): ?string
    {
        $stmt = $this->db->prepare('SELECT valeur FROM parametres WHERE cle = :cle LIMIT 1');
        $stmt->execute(['cle' => $cle]);
        $row = $stmt->fetch();
        return $row === false ? $default : $row['valeur'];
    }

    public function set(string $cle, string $valeur): bool
    {
        $stmt = $this->db->prepare(
            'INSERT INTO parametres (cle, valeur) VALUES (:cle, :valeur)
             ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)'
        );
        return $stmt->execute(['cle' => $cle, 'valeur' => $valeur]);
    }

    public function tous(): array
    {
        $rows = $this->db->query('SELECT cle, valeur FROM parametres')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['cle']] = $row['valeur'];
        }
        return $result;
    }
}
