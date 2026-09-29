<?php
declare(strict_types=1);

namespace App\Services\Formulaire;

use App\Core\Database;
use App\Core\Logger;
use App\Dto\Formulaire\AvancerFinalisationDTO;
use App\Dto\Formulaire\RouvrirFormulaireDTO;
use App\Repositories\Formulaire\FinalisationFormulaireRepository;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Formulaire\ReouvertureFormulaireRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Repositories\Referentiel\StatutRepository;
use DateTimeImmutable;
use DomainException;
use RuntimeException;

/**
 * Regles metier de la progression Retrouve -> Numerise -> Saisi et de la
 * reouverture d'un dossier dans un nouveau cycle.
 *
 * Chaque operation est atomique et journalisee. Un refus metier est signale
 * par une DomainException dont le message peut etre presente a l'utilisateur ;
 * toute autre exception signale une panne technique.
 */
final class FinalisationService
{
    /**
     * Valide l'etape suivante du dossier, dans l'ordre impose.
     *
     * @param array $dataValidated donnees de AvancerFinalisationFormRequest
     */
    public function srv_avancer(int $formulaireId, array $dataValidated, int $acteurId): void
    {
        $dto = AvancerFinalisationDTO::fromArray($dataValidated);
        $etapeDemandee = $dto->etape;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dto->date);
        $commentaire = $dto->commentaire;
        $formulaireRepository = new FormulaireRepository();
        $finalisationRepository = new FinalisationFormulaireRepository();
        $dateMetier = $date->format('Y-m-d');

        Database::transaction(function () use (
            $formulaireId, $etapeDemandee, $date, $dateMetier, $commentaire, $acteurId,
            $formulaireRepository, $finalisationRepository
        ): void {
            $avant = $formulaireRepository->repo_verrouiller($formulaireId);
            if (!$avant || (int) ($avant['est_archive'] ?? 0) === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }

            $etapeAttendue = $finalisationRepository->repo_nextStepForStatus((string) $avant['statut_code']);
            if ($etapeAttendue === null) {
                throw new DomainException(
                    (string) $avant['statut_code'] === 'saisi'
                        ? 'Ce formulaire est deja entierement finalise.'
                        : 'Le formulaire doit d’abord etre retrouve avant sa numerisation.'
                );
            }
            if ($etapeDemandee !== $etapeAttendue) {
                throw new DomainException('Les etapes doivent etre validees dans l’ordre : Retrouve, Numerise, puis Saisi.');
            }

            $etapePrecedente = $etapeAttendue === 'numerise' ? 'retrouve' : 'numerise';
            $precedente = $finalisationRepository->repo_findStep($formulaireId, $etapePrecedente);
            $dateMinimale = $precedente['effectue_le'] ?? $avant['date_resolution'] ?? null;
            if ($dateMinimale !== null
                && $date < new DateTimeImmutable(substr((string) $dateMinimale, 0, 10))
            ) {
                throw new DomainException('La date de cette etape ne peut pas preceder la date de l’etape precedente.');
            }

            $statutCible = (new StatutRepository())->repo_findByCode($etapeAttendue);
            if (!$statutCible || (int) ($statutCible['actif'] ?? 0) !== 1) {
                throw new RuntimeException('Le statut ' . ucfirst($etapeAttendue) . ' n’est pas configure ou actif.');
            }
            $finalisationRepository->repo_enregistrer(
                $formulaireId,
                $etapeAttendue,
                (int) $statutCible['id'],
                $acteurId,
                $dateMetier,
                $commentaire
            );
            $formulaireRepository->repo_update($formulaireId, ['statut_id' => (int) $statutCible['id']]);
            if (!Logger::log(
                $acteurId,
                'finalisation',
                "Validation de l'etape {$etapeAttendue} pour {$avant['numero_auto']}",
                null,
                'formulaire',
                $formulaireId,
                ['statut_id' => (int) $avant['statut_id'], 'statut' => $avant['statut_libelle']],
                [
                    'statut_id' => (int) $statutCible['id'],
                    'statut' => $statutCible['libelle'],
                    'etape' => $etapeAttendue,
                    'date' => $dateMetier,
                    'commentaire' => $commentaire ?: null,
                ]
            )) {
                throw new RuntimeException('La finalisation ne peut pas etre validee sans sa trace d’audit.');
            }
        });
    }

    /**
     * Rouvre un dossier resolu dans un nouveau cycle, au statut A verifier.
     *
     * @param array $dataValidated donnees de RouvrirFormulaireFormRequest
     * @return int|null dernier responsable du dossier, a prevenir
     */
    public function srv_rouvrir(int $formulaireId, array $dataValidated, int $acteurId): ?int
    {
        $motif = RouvrirFormulaireDTO::fromArray($dataValidated)->motif;
        $formulaireRepository = new FormulaireRepository();

        return Database::transaction(function () use ($formulaireId, $motif, $acteurId, $formulaireRepository): ?int {
            $avant = $formulaireRepository->repo_verrouiller($formulaireId);
            if (!$avant || (int) $avant['est_archive'] === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }
            if (!in_array((string) $avant['statut_code'], ['retrouve', 'numerise', 'saisi'], true)) {
                throw new DomainException('Seul un formulaire retrouve, numerise ou saisi peut etre rouvert.');
            }
            if ((new MissionRechercheRepository())->repo_aDesMissionsActives($formulaireId)) {
                throw new DomainException('Le dossier possede deja une mission active et ne peut pas etre rouvert.');
            }
            $statutVerification = (new StatutRepository())->repo_findByCode('a_verifier');
            if (!$statutVerification || (int) ($statutVerification['actif'] ?? 0) !== 1) {
                throw new RuntimeException('Le statut A verifier n’est pas configure ou actif.');
            }

            $cycleAvant = max(1, (int) ($avant['cycle_suivi'] ?? 1));
            $cycleApres = $cycleAvant + 1;
            $formulaireRepository->repo_update($formulaireId, [
                'statut_id' => (int) $statutVerification['id'],
                'cycle_suivi' => $cycleApres,
                'date_echeance_recherche' => null,
            ]);
            (new ReouvertureFormulaireRepository())->repo_enregistrer(
                $formulaireId,
                $cycleAvant,
                $cycleApres,
                (int) $avant['statut_id'],
                (string) $avant['statut_libelle'],
                $motif,
                $acteurId
            );
            if (!Logger::log(
                $acteurId,
                'reouverture',
                "Reouverture du formulaire {$avant['numero_auto']} , cycle {$cycleApres}",
                null,
                'formulaire',
                $formulaireId,
                ['statut_id' => (int) $avant['statut_id'], 'cycle_suivi' => $cycleAvant],
                [
                    'statut_id' => (int) $statutVerification['id'],
                    'cycle_suivi' => $cycleApres,
                    'motif' => $motif,
                ]
            )) {
                throw new RuntimeException('La reouverture ne peut pas etre validee sans sa trace d’audit.');
            }

            return !empty($avant['responsable_id']) ? (int) $avant['responsable_id'] : null;
        });
    }
}
