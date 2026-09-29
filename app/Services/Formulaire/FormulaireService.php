<?php
declare(strict_types=1);

namespace App\Services\Formulaire;

use App\Core\Database;
use App\Core\Logger;
use App\Dto\Formulaire\CreateFormulaireDTO;
use App\Dto\Formulaire\UpdateFormulaireDTO;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Referentiel\StatutRepository;
use App\Repositories\Utilisateur\UserRepository;
use DomainException;
use RuntimeException;

/**
 * Declaration et modification des informations generales d'un formulaire.
 *
 * La recherche, l'affectation et la finalisation ont leurs propres services :
 * ici, un dossier est seulement declare (statut Introuvable) puis corrige.
 * Un refus metier est signale par une DomainException dont le message peut
 * etre presente a l'utilisateur ; toute autre exception signale une panne.
 */
final class FormulaireService
{
    /**
     * Declare un nouveau formulaire manquant.
     *
     * @param array $dataValidated donnees de CreateFormulaireFormRequest
     * @return array{id:int, numero_auto:string}
     */
    public function srv_creer(array $dataValidated, int $acteurId): array
    {
        $dto = CreateFormulaireDTO::fromArray($dataValidated);
        $statutInitial = (new StatutRepository())->repo_findByCode('introuvable');
        if (!$statutInitial) {
            throw new DomainException('Le statut initial Introuvable n’est pas configure. Contactez un administrateur.');
        }

        // La creation declare uniquement l'existence du dossier manquant.
        // Affectation, recherche et resultat sont obligatoirement ajoutes
        // ensuite depuis la fiche afin de produire une ligne d'historique.
        $data = [
            'type_titre_id' => $dto->type_titre_id,
            'annee' => $dto->annee,
            'numero_formulaire' => $dto->numero_formulaire,
            'statut_id' => (int) $statutInitial['id'],
            'localisation_id' => null,
            'responsable_id' => null,
            'date_recherche' => null,
            'resultat' => '',
            'observations' => '',
            'date_depot' => null,
            'deposant' => '',
            'mandataire' => '',
            'niveau_urgence' => 'Moyen',
            'priorite' => 'Normale',
        ];
        if (($erreur = (new FormulaireMetierValidator())->validateForm($data)) !== null) {
            throw new DomainException($erreur);
        }

        $repository = new FormulaireRepository();
        if ($repository->repo_duplicateExists($data['annee'], $data['type_titre_id'], $data['numero_formulaire'])) {
            throw new DomainException('Un formulaire portant ce numero existe deja pour cette annee et ce type de titre.');
        }
        $data['cree_par'] = $acteurId;

        return Database::transaction(function () use ($repository, $data, $acteurId): array {
            $created = $repository->repo_insertWithGeneratedNumero($data);
            $apres = $repository->repo_find($created['id']) ?: $data + ['numero_auto' => $created['numero_auto']];
            if (!Logger::log(
                $acteurId,
                'ajout',
                "Ajout du formulaire manquant {$created['numero_auto']}",
                null,
                'formulaire',
                $created['id'],
                null,
                $this->auditSnapshot($apres)
            )) {
                throw new RuntimeException('La creation ne peut pas etre validee sans sa trace d’audit.');
            }
            return $created;
        });
    }

    /**
     * Modifie le type, le numero et la priorite d'un formulaire. L'annee (qui
     * fixe la reference automatique) et l'etat de la recherche ne changent pas.
     *
     * @param array $dataValidated donnees de UpdateFormulaireFormRequest
     */
    public function srv_modifier(int $formulaireId, array $dataValidated, int $acteurId): void
    {
        $dto = UpdateFormulaireDTO::fromArray($dataValidated);
        $repository = new FormulaireRepository();
        $ancien = $repository->repo_find($formulaireId);
        if (!$ancien) {
            throw new DomainException('Formulaire introuvable.');
        }

        $controle = [
            'type_titre_id' => $dto->type_titre_id,
            'numero_formulaire' => $dto->numero_formulaire,
            'priorite' => $dto->priorite,
            'statut_id' => $ancien['statut_id'],
            'localisation_id' => null,
            'responsable_id' => null,
            'date_recherche' => null,
            'resultat' => '',
            'observations' => '',
            'date_depot' => null,
            'deposant' => '',
            'mandataire' => '',
        ];
        if (($erreur = (new FormulaireMetierValidator())->validateForm($controle, $ancien, false)) !== null) {
            throw new DomainException($erreur);
        }
        if ($repository->repo_duplicateExists((int) $ancien['annee'], $dto->type_titre_id, $dto->numero_formulaire, $formulaireId)) {
            throw new DomainException('La modification creerait un doublon : ce numero existe deja pour cette annee et ce type de titre.');
        }

        $data = $dto->toArray();
        Database::transaction(function () use ($repository, $formulaireId, $data, $ancien, $acteurId): void {
            $repository->repo_update($formulaireId, $data);
            $apres = $repository->repo_find($formulaireId) ?: array_merge($ancien, $data);
            $this->journaliserChangements(
                $ancien,
                $apres,
                $formulaireId,
                'modification',
                "Modification du formulaire {$ancien['numero_auto']}",
                $acteurId
            );
        });
    }

    /**
     * Journalise la modification, et separement un changement de statut ou de
     * responsable. Leve une exception si une trace obligatoire echoue.
     */
    private function journaliserChangements(
        array $avant,
        array $apres,
        int $formulaireId,
        string $type,
        string $description,
        int $acteurId
    ): void {
        if (!Logger::log(
            $acteurId,
            $type,
            $description,
            null,
            'formulaire',
            $formulaireId,
            $this->auditSnapshot($avant),
            $this->auditSnapshot($apres)
        )) {
            throw new RuntimeException('La modification ne peut pas etre validee sans sa trace d’audit.');
        }

        if ((int) ($avant['statut_id'] ?? 0) !== (int) ($apres['statut_id'] ?? 0)) {
            if (!Logger::log(
                $acteurId,
                'changement_statut',
                "Changement de statut du formulaire {$avant['numero_auto']}",
                null,
                'formulaire',
                $formulaireId,
                ['statut_id' => (int) ($avant['statut_id'] ?? 0), 'statut' => $this->statutLabel((int) ($avant['statut_id'] ?? 0))],
                ['statut_id' => (int) ($apres['statut_id'] ?? 0), 'statut' => $this->statutLabel((int) ($apres['statut_id'] ?? 0))]
            )) {
                throw new RuntimeException('Le changement de statut ne peut pas etre valide sans sa trace d’audit.');
            }
        }

        if ((int) ($avant['responsable_id'] ?? 0) !== (int) ($apres['responsable_id'] ?? 0)) {
            if (!Logger::log(
                $acteurId,
                'reaffectation',
                "Reaffectation du formulaire {$avant['numero_auto']}",
                null,
                'formulaire',
                $formulaireId,
                ['responsable_id' => $avant['responsable_id'] ?? null, 'responsable' => $this->userLabel((int) ($avant['responsable_id'] ?? 0))],
                ['responsable_id' => $apres['responsable_id'] ?? null, 'responsable' => $this->userLabel((int) ($apres['responsable_id'] ?? 0))]
            )) {
                throw new RuntimeException('La reaffectation ne peut pas etre validee sans sa trace d’audit.');
            }
        }
    }

    private function auditSnapshot(array $formulaire): array
    {
        $fields = [
            'numero_auto', 'type_titre_id', 'annee', 'numero_formulaire',
            'date_depot', 'deposant', 'mandataire', 'statut_id',
            'localisation_id', 'responsable_id', 'date_recherche', 'resultat',
            'date_echeance_recherche',
            'date_resolution', 'observations', 'priorite', 'est_archive',
            'motif_archivage', 'archive_par', 'archive_le',
        ];
        $snapshot = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $formulaire)) {
                $snapshot[$field] = $formulaire[$field];
            }
        }
        return $snapshot;
    }

    private function statutLabel(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }
        $row = (new StatutRepository())->repo_find($id);
        return $row['libelle'] ?? null;
    }

    private function userLabel(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }
        $row = (new UserRepository())->repo_find($id);
        return $row ? trim($row['nom'] . ' ' . $row['prenoms']) : null;
    }
}
