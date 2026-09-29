<?php
declare(strict_types=1);

namespace App\Repositories\Referentiel;

use App\Core\Repository;

class LocalisationRepository extends Repository
{
    protected string $table = 'localisations';

    public function repo_actives(): array
    {
        return $this->db->query('SELECT * FROM localisations WHERE actif = 1 ORDER BY libelle ASC')->fetchAll();
    }
}
