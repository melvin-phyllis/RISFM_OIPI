<?php
declare(strict_types=1);

namespace App\Services\Formulaire;

use App\Core\Database;
use App\Core\Logger;
use App\Dto\Formulaire\ArchiverFormulaireDTO;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use RuntimeException;

/**
 * Archivage reversible d'un dossier : ses missions actives sont annulees mais
 * rien n'est supprime (historique, pieces jointes, journal).
 */
final class ArchivageService
{
    /**
     * Archive le dossier et annule ses missions actives.
     *
     * @param array $dataValidated donnees de ArchiverFormulaireFormRequest
     * @return array missions actives au moment de l'archivage, a prevenir
     */
    public function srv_archiver(array $formulaire, array $dataValidated, int $acteurId): array
    {
        $motif = ArchiverFormulaireDTO::fromArray($dataValidated)->motif;
        $formulaireId = (int) $formulaire['id'];
        $formulaireRepository = new FormulaireRepository();
        $missionRepository = new MissionRechercheRepository();
        $missionsActives = $missionRepository->repo_activesPourFormulaire($formulaireId);

        Database::transaction(function () use ($formulaire, $formulaireId, $motif, $acteurId, $formulaireRepository, $missionRepository): void {
            $formulaireRepository->repo_update($formulaireId, [
                'est_archive' => 1,
                'motif_archivage' => $motif,
                'archive_par' => $acteurId,
                'archive_le' => date('Y-m-d H:i:s'),
            ]);
            $missionRepository->repo_annulerToutesActives($formulaireId, $acteurId, 'Dossier archive : ' . $motif);
            $apres = $formulaireRepository->repo_find($formulaireId)
                ?: array_merge($formulaire, ['est_archive' => 1, 'motif_archivage' => $motif]);
            if (!Logger::log(
                $acteurId,
                'archivage',
                "Archivage du formulaire {$formulaire['numero_auto']} , motif : {$motif}",
                null,
                'formulaire',
                $formulaireId,
                $this->auditSnapshot($formulaire),
                $this->auditSnapshot($apres)
            )) {
                throw new RuntimeException('L’archivage ne peut pas etre valide sans sa trace d’audit.');
            }
        });

        return $missionsActives;
    }

    /** Remet le dossier dans le registre actif. */
    public function srv_restaurer(array $formulaire, int $acteurId): void
    {
        $formulaireId = (int) $formulaire['id'];
        $formulaireRepository = new FormulaireRepository();

        Database::transaction(function () use ($formulaire, $formulaireId, $acteurId, $formulaireRepository): void {
            $formulaireRepository->repo_update($formulaireId, [
                'est_archive' => 0,
                'motif_archivage' => null,
                'archive_par' => null,
                'archive_le' => null,
            ]);
            $apres = $formulaireRepository->repo_find($formulaireId) ?: array_merge($formulaire, ['est_archive' => 0]);
            if (!Logger::log(
                $acteurId,
                'restauration',
                "Restauration du formulaire {$formulaire['numero_auto']}",
                null,
                'formulaire',
                $formulaireId,
                $this->auditSnapshot($formulaire),
                $this->auditSnapshot($apres)
            )) {
                throw new RuntimeException('La restauration ne peut pas etre validee sans sa trace d’audit.');
            }
        });
    }

    private function auditSnapshot(array $formulaire): array
    {
        $snapshot = [];
        foreach ([
            'numero_auto', 'type_titre_id', 'annee', 'numero_formulaire',
            'statut_id', 'localisation_id', 'responsable_id', 'date_recherche',
            'date_resolution', 'resultat', 'priorite', 'est_archive',
            'motif_archivage', 'archive_par', 'archive_le',
        ] as $field) {
            if (array_key_exists($field, $formulaire)) {
                $snapshot[$field] = $formulaire[$field];
            }
        }
        return $snapshot;
    }
}
