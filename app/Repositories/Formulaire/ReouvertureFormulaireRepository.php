<?php
declare(strict_types=1);

namespace App\Repositories\Formulaire;

use App\Core\Repository;

/** Historique immuable des corrections qui rouvrent un dossier resolu. */
class ReouvertureFormulaireRepository extends Repository
{
    protected string $table = 'reouvertures_formulaire';

    public function repo_pourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM reouvertures_formulaire
             WHERE formulaire_id = :formulaire_id
             ORDER BY reouvert_le DESC, id DESC'
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return $stmt->fetchAll();
    }

    public function repo_enregistrer(
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

        return $this->repo_insert([
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
