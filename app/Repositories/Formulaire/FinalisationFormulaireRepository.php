<?php
declare(strict_types=1);

namespace App\Repositories\Formulaire;

use App\Core\Repository;
use InvalidArgumentException;
use RuntimeException;

/**
 * Historique append-only des etapes posterieures a la recherche.
 *
 * Le statut du formulaire donne l'etape courante. Cette table conserve la date
 * metier, l'acteur et le commentaire de chaque validation, y compris apres un
 * renommage ou une suppression autorisee du compte.
 */
class FinalisationFormulaireRepository extends Repository
{
    protected string $table = 'finalisations_formulaire';

    public const STEPS = ['retrouve', 'numerise', 'saisi'];

    public function repo_pourFormulaire(int $formulaireId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ff.*, s.libelle AS statut_libelle
             FROM finalisations_formulaire ff
             JOIN formulaires_manquants f
               ON f.id = ff.formulaire_id AND f.cycle_suivi = ff.cycle_suivi
             LEFT JOIN statuts s ON s.id = ff.statut_id
             WHERE ff.formulaire_id = :formulaire_id
             ORDER BY FIELD(ff.etape, 'retrouve','numerise','saisi'), ff.id"
        );
        $stmt->execute(['formulaire_id' => $formulaireId]);
        return $stmt->fetchAll();
    }

    public function repo_findStep(int $formulaireId, string $step): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ff.* FROM finalisations_formulaire ff
             JOIN formulaires_manquants f
               ON f.id = ff.formulaire_id AND f.cycle_suivi = ff.cycle_suivi
             WHERE ff.formulaire_id = :formulaire_id AND ff.etape = :etape
             LIMIT 1'
        );
        $stmt->execute(['formulaire_id' => $formulaireId, 'etape' => $step]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function repo_nextStepForStatus(string $statusCode): ?string
    {
        return match ($statusCode) {
            'retrouve' => 'numerise',
            'numerise' => 'saisi',
            default => null,
        };
    }

    public function repo_enregistrer(
        int $formulaireId,
        string $step,
        int $statusId,
        ?int $actorId,
        string $businessDate,
        ?string $comment = null
    ): int {
        if (!in_array($step, self::STEPS, true)) {
            throw new InvalidArgumentException('Etape de finalisation invalide.');
        }

        $actorName = null;
        if ($actorId !== null) {
            $stmt = $this->db->prepare(
                "SELECT CONCAT_WS(' ', nom, prenoms) FROM utilisateurs WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $actorId]);
            $value = $stmt->fetchColumn();
            $actorName = $value === false ? null : trim((string) $value);
        }

        $cycleStmt = $this->db->prepare(
            'SELECT cycle_suivi FROM formulaires_manquants WHERE id = :id LIMIT 1'
        );
        $cycleStmt->execute(['id' => $formulaireId]);
        $cycle = (int) $cycleStmt->fetchColumn();
        if ($cycle < 1) {
            throw new RuntimeException('Cycle de suivi du formulaire introuvable.');
        }

        return $this->repo_insert([
            'formulaire_id' => $formulaireId,
            'cycle_suivi' => $cycle,
            'etape' => $step,
            'statut_id' => $statusId,
            'effectue_par' => $actorId,
            'effectue_par_nom' => $actorName ?: null,
            'commentaire' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'effectue_le' => $businessDate . ' 00:00:00',
        ]);
    }
}
