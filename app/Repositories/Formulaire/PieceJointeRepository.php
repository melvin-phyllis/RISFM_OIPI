<?php
declare(strict_types=1);

namespace App\Repositories\Formulaire;

use App\Core\Repository;

class PieceJointeRepository extends Repository
{
    protected string $table = 'pieces_jointes';

    public function repo_pourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM pieces_jointes WHERE formulaire_id = :fid ORDER BY televerse_le DESC');
        $stmt->execute(['fid' => $formulaireId]);
        return $stmt->fetchAll();
    }
}
