<?php
declare(strict_types=1);

class LocalisationModel extends Model
{
    protected string $table = 'localisations';

    public function actives(): array
    {
        return $this->db->query('SELECT * FROM localisations WHERE actif = 1 ORDER BY libelle ASC')->fetchAll();
    }
}
