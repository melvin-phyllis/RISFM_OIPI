<?php
declare(strict_types=1);

namespace App\Repositories\Referentiel;

use App\Core\Repository;

class DirectionRepository extends Repository
{
    protected string $table = 'directions';

    public function repo_toutes(): array
    {
        return $this->db->query('SELECT * FROM directions ORDER BY ordre ASC, libelle ASC')->fetchAll();
    }

    public function repo_actives(): array
    {
        return $this->db->query(
            'SELECT * FROM directions WHERE actif = 1 ORDER BY ordre ASC, libelle ASC'
        )->fetchAll();
    }
}
