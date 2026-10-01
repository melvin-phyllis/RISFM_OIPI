<?php
declare(strict_types=1);

namespace App\Repositories\Referentiel;

use App\Core\Repository;

class ServiceRepository extends Repository
{
    protected string $table = 'services';

    /** Tous les services, dans l'ordre de l'organigramme. */
    public function repo_tous(): array
    {
        return $this->db->query(
            'SELECT s.*, d.libelle AS direction, d.code AS direction_code, d.actif AS direction_actif
             FROM services s JOIN directions d ON d.id = s.direction_id
             ORDER BY d.ordre ASC, d.libelle ASC, s.ordre ASC, s.libelle ASC'
        )->fetchAll();
    }

    public function repo_actifs(): array
    {
        return $this->db->query(
            'SELECT s.*, d.libelle AS direction, d.code AS direction_code
             FROM services s JOIN directions d ON d.id = s.direction_id
             WHERE s.actif = 1 AND d.actif = 1
             ORDER BY d.ordre ASC, d.libelle ASC, s.ordre ASC, s.libelle ASC'
        )->fetchAll();
    }

    public function repo_findActif(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT s.*, d.libelle AS direction
             FROM services s JOIN directions d ON d.id = s.direction_id
             WHERE s.id = :id AND s.actif = 1 AND d.actif = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

}
