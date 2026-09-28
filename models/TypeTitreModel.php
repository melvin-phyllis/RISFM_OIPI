<?php
declare(strict_types=1);

class TypeTitreModel extends Model
{
    protected string $table = 'types_titres';

    public function actifs(): array
    {
        return $this->db->query('SELECT * FROM types_titres WHERE actif = 1 ORDER BY ordre ASC')->fetchAll();
    }
}
