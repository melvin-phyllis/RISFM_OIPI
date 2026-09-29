<?php
declare(strict_types=1);

namespace App\Repositories\Referentiel;

use App\Core\Repository;

class TypeTitreRepository extends Repository
{
    protected string $table = 'types_titres';

    public function repo_actifs(): array
    {
        return $this->db->query('SELECT * FROM types_titres WHERE actif = 1 ORDER BY ordre ASC')->fetchAll();
    }
}
