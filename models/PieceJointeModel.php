<?php
declare(strict_types=1);

class PieceJointeModel extends Model
{
    protected string $table = 'pieces_jointes';

    public function pourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM pieces_jointes WHERE formulaire_id = :fid ORDER BY televerse_le DESC');
        $stmt->execute(['fid' => $formulaireId]);
        return $stmt->fetchAll();
    }
}
