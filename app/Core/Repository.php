<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Classe de base pour tous les modeles.
 * Fournit des helpers CRUD generiques bases sur PDO + requetes preparees.
 */
abstract class Repository
{
    protected PDO $db;
    protected string $table = '';
    protected string $primaryKey = 'id';

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    public function repo_find(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function repo_all(string $orderBy = '', string $direction = 'ASC'): array
    {
        $sql = "SELECT * FROM {$this->table}";
        if ($orderBy !== '') {
            $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
            $sql .= " ORDER BY " . $this->safeColumn($orderBy) . " {$direction}";
        }
        return $this->db->query($sql)->fetchAll();
    }

    public function repo_insert(array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn ($c) => ':' . $c, $columns);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
        return (int) $this->db->lastInsertId();
    }

    public function repo_update(int $id, array $data): bool
    {
        $sets = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($data)));
        $sql = "UPDATE {$this->table} SET {$sets} WHERE {$this->primaryKey} = :__id";
        $data['__id'] = $id;
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($data);
    }

    public function repo_delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function repo_count(string $whereSql = '', array $params = []): int
    {
        $sql = "SELECT COUNT(*) AS n FROM {$this->table}";
        if ($whereSql !== '') {
            $sql .= " WHERE {$whereSql}";
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetch()['n'];
    }

    /**
     * Empeche l'injection via un nom de colonne utilise dans un ORDER BY dynamique.
     */
    protected function safeColumn(string $column): string
    {
        return preg_replace('/[^a-zA-Z0-9_.]/', '', $column);
    }
}
