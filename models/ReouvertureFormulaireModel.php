<?php
declare(strict_types=1);

/** Historique immuable des corrections qui rouvrent un dossier resolu. */
class ReouvertureFormulaireModel extends Model
{
    protected string $table = 'reouvertures_formulaire';

    public function pourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM reouvertures_formulaire
             WHERE formulaire_id = :formulaire_id
             ORDER BY reouvert_le DESC, id DESC'
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return $stmt->fetchAll();
    }

    public function enregistrer(
        int $formulaireId,
        int $cycleAvant,
        int $cycleApres,
        int $statusId,
        string $statusLabel,
        string $reason,
        ?int $actorId
    ): int {
        $actorName = null;
        if ($actorId !== null) {
            $stmt = $this->db->prepare(
                "SELECT CONCAT_WS(' ', nom, prenoms) FROM utilisateurs WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $actorId]);
            $value = $stmt->fetchColumn();
            $actorName = $value === false ? null : trim((string) $value);
        }

        return $this->insert([
            'formulaire_id' => $formulaireId,
            'cycle_avant' => $cycleAvant,
            'cycle_apres' => $cycleApres,
            'statut_avant_id' => $statusId,
            'statut_avant_libelle' => $statusLabel,
            'motif' => $reason,
            'reouvert_par' => $actorId,
            'reouvert_par_nom' => $actorName ?: null,
        ]);
    }
}
