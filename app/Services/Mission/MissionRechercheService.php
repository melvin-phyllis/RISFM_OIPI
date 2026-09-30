<?php
declare(strict_types=1);

namespace App\Services\Mission;

use App\Core\Database;
use App\Core\Logger;
use App\Dto\Mission\AffecterMissionDTO;
use App\Dto\Mission\AnnulerMissionDTO;
use App\Dto\Mission\DeclarerRetrouveDTO;
use App\Dto\Mission\EnregistrerResultatMissionDTO;
use App\Dto\Mission\ReaffecterMissionDTO;
use App\Exceptions\ConfirmationRequiseException;
use App\Repositories\Formulaire\FinalisationFormulaireRepository;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Formulaire\RechercheFormulaireRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Repositories\Notification\NotificationRepository;
use App\Repositories\Referentiel\StatutRepository;
use App\Repositories\Utilisateur\UserRepository;
use App\Core\Permission;
use App\Services\Formulaire\FormulaireMetierValidator;
use DomainException;
use RuntimeException;

/**
 * Regles metier du cycle des missions de recherche : affectation, annulation,
 * reaffectation et saisie du resultat.
 *
 * Chaque operation verrouille le dossier (et, si besoin, le responsable) puis
 * s'execute dans une transaction journalisee. Un refus metier est signale par
 * une DomainException dont le message peut etre presente a l'utilisateur ;
 * toute autre exception signale une panne technique.
 */
final class MissionRechercheService
{
    /** Roles autorises a conduire une recherche. */
    private const ROLES_ELIGIBLES = ['administrateur', 'responsable', 'agent'];

    /** Etats d'une mission encore en cours. */
    private const ETATS_ACTIFS = ['affectee', 'en_cours'];

    /**
     * Affecte une mission de recherche sur le dossier.
     *
     * @param array $dataValidated donnees de AffecterMissionFormRequest
     * @return int identifiant de la mission creee
     * @throws ConfirmationRequiseException localisation deja inspectee, a confirmer
     * @throws DomainException refus metier
     */
    public function srv_affecter(int $formulaireId, array $dataValidated, int $acteurId): int
    {
        $dto = AffecterMissionDTO::fromArray($dataValidated);

        $formulaire = (new FormulaireRepository())->repo_find($formulaireId);
        if (!$formulaire) {
            throw new DomainException('Formulaire introuvable.');
        }
        $statut = (new StatutRepository())->repo_find((int) $formulaire['statut_id']);
        if ((int) ($statut['resolu'] ?? 0) === 1) {
            throw new DomainException('Un dossier deja resolu ne peut pas recevoir une nouvelle affectation.');
        }
        $statutEnRecherche = (new StatutRepository())->repo_findByCode('en_recherche');
        if (!$statutEnRecherche) {
            throw new DomainException('Le statut En recherche n’est pas configure. Contactez un administrateur.');
        }
        $this->validerAffectation($dto->localisation_id, $dto->responsable_id, $dto->date_echeance, $dto->priorite);

        $dejaInspectee = (new RechercheFormulaireRepository())->repo_localisationDejaRecherchee($formulaireId, $dto->localisation_id);
        if ($dejaInspectee && !$dto->confirmer_localisation_deja_recherchee) {
            throw new ConfirmationRequiseException('Cette localisation a deja ete inspectee. Confirmez la nouvelle affectation avant de continuer.');
        }

        return $this->affecterEnTransaction($formulaireId, [
            'localisation_id' => $dto->localisation_id,
            'responsable_id' => $dto->responsable_id,
            'date_echeance_recherche' => $dto->date_echeance,
            'priorite' => $dto->priorite,
            'statut_id' => (int) $statutEnRecherche['id'],
            'date_recherche' => null,
            'resultat' => '',
            'observations' => '',
        ], $acteurId);
    }

    /**
     * Annule une mission active.
     *
     * @param array $mission mission telle que lue avant l'operation
     * @param array $dataValidated donnees de AnnulerMissionFormRequest
     */
    public function srv_annuler(array $mission, array $dataValidated, int $acteurId): void
    {
        $dto = AnnulerMissionDTO::fromArray($dataValidated);
        $this->annulerEnTransaction((int) $mission['id'], (int) $mission['formulaire_id'], $dto->motif, $acteurId);
    }

    /**
     * Transfere une mission active a un autre responsable.
     *
     * @param array $mission mission telle que lue avant l'operation
     * @param array $dataValidated donnees de ReaffecterMissionFormRequest
     * @return int identifiant de la nouvelle mission
     */
    public function srv_reaffecter(array $mission, array $dataValidated, int $acteurId): int
    {
        $dto = ReaffecterMissionDTO::fromArray($dataValidated);
        if ($dto->responsable_id === (int) $mission['responsable_id']) {
            throw new DomainException('Selectionnez un nouveau responsable different de l’actuel.');
        }
        $this->validerAffectation((int) $mission['localisation_id'], $dto->responsable_id, $dto->date_echeance, $dto->priorite);

        return $this->reaffecterEnTransaction(
            (int) $mission['id'],
            (int) $mission['formulaire_id'],
            $dto->responsable_id,
            $dto->date_echeance,
            $dto->priorite,
            $acteurId
        );
    }

    /**
     * Cloture une mission avec son resultat.
     *
     * @param array $mission mission telle que lue avant l'operation
     * @param array $dataValidated donnees de EnregistrerResultatMissionFormRequest
     * @return array autres missions cloturees automatiquement, a prevenir
     */
    public function srv_enregistrerResultat(array $mission, array $dataValidated, int $acteurId): array
    {
        $dto = EnregistrerResultatMissionDTO::fromArray($dataValidated);
        $statut = (new StatutRepository())->repo_findByCode(match ($dto->resultat_code) {
            'retrouve' => 'retrouve',
            'a_verifier' => 'a_verifier',
            default => 'introuvable',
        });
        if (!$statut) {
            throw new DomainException('Le statut correspondant au resultat n’est pas configure.');
        }
        $erreur = (new FormulaireMetierValidator())->validateResearch([
            'localisation_id' => (string) $mission['localisation_id'],
            'responsable_id' => (string) $mission['responsable_id'],
            'statut_id' => (string) $statut['id'],
            'date_recherche' => $dto->date_recherche,
            'resultat' => $dto->resultat,
            'observations' => $dto->observations,
        ], null, $statut);
        if ($erreur !== null) {
            throw new DomainException($erreur);
        }

        return $this->enregistrerResultatEnTransaction(
            (int) $mission['id'],
            (int) $mission['formulaire_id'],
            $dto->resultat_code,
            $statut,
            $dto->date_recherche,
            $dto->resultat,
            $dto->observations,
            $acteurId
        );
    }

    /**
     * Declare un formulaire retrouve sans mission prealable (decouverte sur
     * place). Une mission au nom du declarant est creee puis cloturee comme une
     * recherche ordinaire : historique, finalisation et statistiques restent
     * complets.
     *
     * @param array $dataValidated donnees de DeclarerRetrouveFormRequest
     * @param bool $valide true : Retrouve ; false : A verifier, a confirmer par un responsable
     * @return array autres missions cloturees automatiquement, a prevenir
     * @throws DomainException refus metier
     */
    public function srv_declarerRetrouve(int $formulaireId, array $dataValidated, int $acteurId, bool $valide): array
    {
        $dto = DeclarerRetrouveDTO::fromArray($dataValidated);
        $resultatCode = $valide ? 'retrouve' : 'a_verifier';
        $statut = (new StatutRepository())->repo_findByCode($resultatCode);
        if (!$statut) {
            throw new DomainException('Le statut correspondant a la declaration n’est pas configure.');
        }
        $resultat = $valide ? 'Retrouve (declaration directe)' : 'Signale retrouve, a verifier par un responsable';
        if ($dto->precision !== '') {
            $resultat = mb_substr($resultat . ' : ' . $dto->precision, 0, 255);
        }
        $erreur = (new FormulaireMetierValidator())->validateResearch([
            'localisation_id' => (string) $dto->localisation_id,
            'responsable_id' => (string) $acteurId,
            'statut_id' => (string) $statut['id'],
            'date_recherche' => $dto->date,
            'resultat' => $resultat,
            'observations' => '',
        ], null, $statut);
        if ($erreur !== null) {
            throw new DomainException($erreur);
        }

        return Database::transaction(function () use ($formulaireId, $dto, $acteurId, $resultatCode, $statut, $resultat): array {
            $formulaire = (new FormulaireRepository())->repo_verrouiller($formulaireId);
            if (!$formulaire || (int) $formulaire['est_archive'] === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }
            if ($formulaire['statut_resolu'] === 1) {
                throw new DomainException('Ce formulaire est deja retrouve.');
            }
            $this->responsableEligible($acteurId, 'Votre compte ne peut pas declarer une recherche.');

            // Une mission deja active du declarant sur ce lieu est reprise :
            // une seule mission active par formulaire, lieu et responsable.
            $missionRepository = new MissionRechercheRepository();
            $missionId = null;
            foreach ($missionRepository->repo_activesPourFormulaire($formulaireId) as $active) {
                if ((int) $active['responsable_id'] === $acteurId && (int) $active['localisation_id'] === $dto->localisation_id) {
                    $missionId = (int) $active['id'];
                }
            }
            if ($missionId === null) {
                $missionId = $missionRepository->repo_insert([
                    'formulaire_id' => $formulaireId,
                    'cycle_suivi' => max(1, (int) $formulaire['cycle_suivi']),
                    'localisation_id' => $dto->localisation_id,
                    'responsable_id' => $acteurId,
                    'affecte_par' => $acteurId,
                    'etat' => 'en_cours',
                    'priorite' => (string) ($formulaire['priorite'] ?? 'Normale'),
                ]);
                if (!Logger::log(
                    $acteurId,
                    'affectation',
                    "Declaration directe : mission #{$missionId} ouverte pour {$formulaire['numero_auto']}",
                    null,
                    'mission_recherche',
                    $missionId,
                    null,
                    ['formulaire_id' => $formulaireId, 'localisation_id' => $dto->localisation_id, 'responsable_id' => $acteurId, 'etat' => 'en_cours']
                )) {
                    throw new RuntimeException('La declaration ne peut pas etre validee sans sa trace d’audit.');
                }
            }

            return $this->enregistrerResultatEnTransaction(
                $missionId,
                $formulaireId,
                $resultatCode,
                $statut,
                $dto->date,
                $resultat,
                '',
                $acteurId
            );
        });
    }

    /**
     * Notifications d'une declaration : les responsables des missions closes
     * automatiquement, et les valideurs quand une confirmation est attendue.
     */
    public function srv_notifierDeclaration(int $formulaireId, string $reference, bool $valide, array $missionsAnnulees, int $acteurId): void
    {
        $notifications = new NotificationRepository();
        $lien = url('formulaires/voir/' . $formulaireId . '#historique-recherches');
        foreach ($missionsAnnulees as $mission) {
            $notifications->repo_creer(
                (int) $mission['responsable_id'],
                'Mission cloturee automatiquement',
                "Votre mission sur le formulaire {$reference} a ete cloturee : le formulaire a ete declare retrouve.",
                'info',
                $lien
            );
        }
        if ($valide) {
            return;
        }
        $declarant = (new UserRepository())->repo_find($acteurId);
        $nom = trim((string) (($declarant['nom'] ?? '') . ' ' . ($declarant['prenoms'] ?? '')));
        foreach ((new UserRepository())->repo_activeUsers() as $user) {
            if ((int) $user['id'] !== $acteurId
                && Permission::has((string) $user['role'], 'formulaires.declare_found_validated')
            ) {
                $notifications->repo_creer(
                    (int) $user['id'],
                    'Formulaire signale retrouve',
                    "{$nom} signale avoir retrouve le formulaire {$reference}. Verifiez puis confirmez depuis sa fiche.",
                    'alerte',
                    $lien
                );
            }
        }
    }

    /** Regles communes a l'affectation et a la reaffectation (references verifiees en base). */
    private function validerAffectation(int $localisationId, int $responsableId, ?string $echeance, string $priorite): void
    {
        $erreur = (new FormulaireMetierValidator())->validateAssignment([
            'localisation_id' => $localisationId,
            'responsable_id' => $responsableId,
            'date_echeance_recherche' => $echeance,
            'priorite' => $priorite,
        ]);
        if ($erreur !== null) {
            throw new DomainException($erreur);
        }
    }

    /**
     * Cree une mission et met a jour le resume de recherche du dossier.
     *
     * @param array $data localisation_id, responsable_id, date_echeance_recherche,
     *                    priorite, statut_id et champs de recherche a reinitialiser
     * @return int identifiant de la mission creee
     */
    private function affecterEnTransaction(int $formulaireId, array $data, int $acteurId): int
    {
        $formulaireRepository = new FormulaireRepository();

        return Database::transaction(function () use ($formulaireId, $data, $acteurId, $formulaireRepository): int {
            $formulaire = $formulaireRepository->repo_verrouiller($formulaireId);
            if (!$formulaire || (int) $formulaire['est_archive'] === 1) {
                throw new RuntimeException('Le dossier est introuvable ou archive.');
            }
            if ($formulaire['statut_resolu'] === 1) {
                throw new DomainException('Un dossier deja resolu ne peut pas recevoir une nouvelle mission.');
            }
            $this->responsableEligible(
                (int) $data['responsable_id'],
                'Le responsable selectionne est devenu inactif ou ne peut plus conduire une recherche.'
            );

            $missionId = (new MissionRechercheRepository())->repo_insert([
                'formulaire_id' => $formulaireId,
                'cycle_suivi' => max(1, (int) $formulaire['cycle_suivi']),
                'localisation_id' => (int) $data['localisation_id'],
                'responsable_id' => (int) $data['responsable_id'],
                'affecte_par' => $acteurId,
                'etat' => 'affectee',
                'priorite' => $data['priorite'],
                'date_echeance' => $data['date_echeance_recherche'],
            ]);
            $formulaireRepository->repo_update($formulaireId, $data);

            if (!Logger::log(
                $acteurId,
                'affectation',
                "Creation de la mission #{$missionId} pour {$formulaire['numero_auto']}",
                null,
                'mission_recherche',
                $missionId,
                null,
                [
                    'formulaire_id' => $formulaireId,
                    'localisation_id' => (int) $data['localisation_id'],
                    'responsable_id' => (int) $data['responsable_id'],
                    'priorite' => $data['priorite'],
                    'date_echeance' => $data['date_echeance_recherche'],
                    'etat' => 'affectee',
                ]
            )) {
                throw new RuntimeException('La mission ne peut pas etre validee sans sa trace d’audit.');
            }
            return $missionId;
        });
    }

    /** Annule une mission active et recalcule le resume du dossier. */
    private function annulerEnTransaction(int $missionId, int $formulaireId, string $motif, int $acteurId): void
    {
        $missionRepository = new MissionRechercheRepository();

        Database::transaction(function () use ($missionId, $formulaireId, $motif, $acteurId, $missionRepository): void {
            $formulaire = (new FormulaireRepository())->repo_verrouiller($formulaireId);
            $mission = $missionRepository->repo_findWithRelations($missionId, true);
            if (!$formulaire || (int) $formulaire['est_archive'] === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }
            if ($formulaire['statut_resolu'] === 1) {
                throw new DomainException('Une mission d’un dossier resolu ne peut plus etre annulee manuellement.');
            }
            if (!$this->missionActiveDuCycle($mission, $formulaire)) {
                throw new DomainException('Cette mission est deja terminee ou annulee.');
            }
            if (!$missionRepository->repo_annulerMission($missionId, $acteurId, $motif)) {
                throw new RuntimeException('La mission n’a pas pu etre annulee.');
            }
            $this->synchroniserResumeMissions($formulaireId);
            if (!Logger::log(
                $acteurId,
                'annulation_mission',
                "Annulation de la mission #{$missionId} pour {$formulaire['numero_auto']}",
                null,
                'mission_recherche',
                $missionId,
                ['etat' => $mission['etat'], 'responsable_id' => (int) $mission['responsable_id']],
                ['etat' => 'annulee', 'motif' => $motif]
            )) {
                throw new RuntimeException('L’annulation ne peut pas etre validee sans sa trace d’audit.');
            }
        });
    }

    /**
     * Cloture la mission et la transfere a un nouveau responsable.
     *
     * @return int identifiant de la nouvelle mission
     */
    private function reaffecterEnTransaction(
        int $missionId,
        int $formulaireId,
        int $nouveauResponsableId,
        ?string $echeance,
        string $priorite,
        int $acteurId
    ): int {
        $missionRepository = new MissionRechercheRepository();

        return Database::transaction(function () use (
            $missionId, $formulaireId, $nouveauResponsableId, $echeance, $priorite, $acteurId, $missionRepository
        ): int {
            $formulaire = (new FormulaireRepository())->repo_verrouiller($formulaireId);
            $mission = $missionRepository->repo_findWithRelations($missionId, true);
            if (!$formulaire || (int) $formulaire['est_archive'] === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }
            if ($formulaire['statut_resolu'] === 1) {
                throw new DomainException('Une mission d’un dossier resolu ne peut plus etre reaffectee.');
            }
            if (!$this->missionActiveDuCycle($mission, $formulaire)) {
                throw new DomainException('Cette mission est deja terminee ou annulee.');
            }
            $nouveauResponsable = $this->responsableEligible(
                $nouveauResponsableId,
                'Le nouveau responsable est inactif ou ne peut pas conduire une recherche.'
            );

            $ancienNom = trim((string) ($mission['responsable_nom'] ?? 'Responsable précédent'));
            $nouveauNom = trim((string) (($nouveauResponsable['nom'] ?? '') . ' ' . ($nouveauResponsable['prenoms'] ?? '')));
            $motif = 'Transfert de ' . $ancienNom . ' vers ' . $nouveauNom;

            if (!$missionRepository->repo_annulerMission($missionId, $acteurId, 'Reaffectation : ' . $motif)) {
                throw new RuntimeException('L’ancienne mission n’a pas pu etre cloturee.');
            }
            $nouvelleMissionId = $missionRepository->repo_insert([
                'mission_parent_id' => $missionId,
                'formulaire_id' => $formulaireId,
                'cycle_suivi' => max(1, (int) ($mission['cycle_suivi'] ?? $formulaire['cycle_suivi'] ?? 1)),
                'localisation_id' => (int) $mission['localisation_id'],
                'responsable_id' => $nouveauResponsableId,
                'affecte_par' => $acteurId,
                'etat' => 'affectee',
                'priorite' => $priorite,
                'date_echeance' => $echeance,
            ]);
            $statutEnRecherche = $this->statutObligatoire('en_recherche', 'Le statut En recherche n’est pas configure.');
            (new FormulaireRepository())->repo_update($formulaireId, [
                'localisation_id' => (int) $mission['localisation_id'],
                'responsable_id' => $nouveauResponsableId,
                'statut_id' => (int) $statutEnRecherche['id'],
                'date_echeance_recherche' => $echeance,
                'date_recherche' => null,
                'resultat' => '',
                'observations' => '',
                'priorite' => $priorite,
            ]);
            if (!Logger::log(
                $acteurId,
                'reaffectation',
                "Reaffectation de la mission #{$missionId} vers la mission #{$nouvelleMissionId}",
                null,
                'mission_recherche',
                $nouvelleMissionId,
                [
                    'mission_id' => $missionId,
                    'responsable_id' => (int) $mission['responsable_id'],
                    'etat' => $mission['etat'],
                ],
                [
                    'mission_id' => $nouvelleMissionId,
                    'mission_parent_id' => $missionId,
                    'responsable_id' => $nouveauResponsableId,
                    'motif' => $motif,
                ]
            )) {
                throw new RuntimeException('La reaffectation ne peut pas etre validee sans sa trace d’audit.');
            }
            return $nouvelleMissionId;
        });
    }

    /**
     * Cloture la mission avec son resultat, l'ajoute a l'historique et met a
     * jour le statut du dossier. Un formulaire retrouve cloture aussi les
     * autres missions actives.
     *
     * @param array $statutResultat statut correspondant au resultat saisi
     * @return array autres missions cloturees automatiquement, a prevenir
     */
    private function enregistrerResultatEnTransaction(
        int $missionId,
        int $formulaireId,
        string $resultatCode,
        array $statutResultat,
        string $dateRecherche,
        string $resultat,
        string $observations,
        int $acteurId
    ): array {
        $formulaireRepository = new FormulaireRepository();
        $missionRepository = new MissionRechercheRepository();

        return Database::transaction(function () use (
            $missionId, $formulaireId, $resultatCode, $statutResultat, $dateRecherche,
            $resultat, $observations, $acteurId, $formulaireRepository, $missionRepository
        ): array {
            $formulaire = $formulaireRepository->repo_verrouiller($formulaireId);
            $mission = $missionRepository->repo_findWithRelations($missionId, true);
            if (!$formulaire || !$this->missionActiveDuCycle($mission, $formulaire)) {
                throw new DomainException('Cette mission a deja ete cloturee ou annulee.');
            }

            $missionRepository->repo_update($missionId, [
                'etat' => 'terminee',
                'resultat_code' => $resultatCode,
                'resultat' => $resultat,
                'observations' => $observations,
                'date_recherche' => $dateRecherche,
                'date_cloture' => date('Y-m-d H:i:s'),
                'cloture_par' => $acteurId,
            ]);

            $missionsAnnulees = [];
            if ($resultatCode === 'retrouve') {
                $missionsAnnulees = $missionRepository->repo_autresActives($formulaireId, $missionId);
                $missionRepository->repo_annulerAutresActives($formulaireId, $missionId, $acteurId);
            }

            $statutGlobal = $statutResultat;
            if ($resultatCode !== 'retrouve') {
                if ($missionRepository->repo_aDesMissionsActives($formulaireId)) {
                    $statutGlobal = (new StatutRepository())->repo_findByCode('en_recherche') ?: $statutGlobal;
                } elseif ($resultatCode === 'non_retrouve' && $missionRepository->repo_aUnResultatAVerifier($formulaireId)) {
                    $statutGlobal = (new StatutRepository())->repo_findByCode('a_verifier') ?: $statutGlobal;
                }
            }

            $historiqueId = (new RechercheFormulaireRepository())->repo_enregistrer(
                $formulaireId,
                [
                    'mission_id' => $missionId,
                    'localisation_id' => (int) $mission['localisation_id'],
                    'responsable_id' => (int) $mission['responsable_id'],
                    'statut_id' => (int) $statutResultat['id'],
                    'date_recherche' => $dateRecherche,
                    'resultat' => $resultat,
                    'observations' => $observations,
                ],
                $acteurId,
                'nouvelle_recherche'
            );
            $missionRepository->repo_update($missionId, ['recherche_historique_id' => $historiqueId]);

            if ($resultatCode === 'retrouve') {
                (new FinalisationFormulaireRepository())->repo_enregistrer(
                    $formulaireId,
                    'retrouve',
                    (int) $statutResultat['id'],
                    $acteurId,
                    $dateRecherche,
                    $resultat
                );
            }

            $formulaireRepository->repo_update($formulaireId, [
                'localisation_id' => (int) $mission['localisation_id'],
                'responsable_id' => (int) $mission['responsable_id'],
                'statut_id' => (int) $statutGlobal['id'],
                'date_recherche' => $dateRecherche,
                'resultat' => $resultat,
                'observations' => $observations,
                'date_echeance_recherche' => null,
            ]);
            if (!Logger::log(
                $acteurId, 'recherche',
                "Resultat {$resultatCode} de la mission #{$missionId} pour {$formulaire['numero_auto']}",
                null, 'mission_recherche', $missionId,
                ['etat' => $mission['etat']],
                ['etat' => 'terminee', 'resultat_code' => $resultatCode, 'formulaire_statut_id' => (int) $statutGlobal['id']]
            )) {
                throw new RuntimeException('Le resultat ne peut pas etre valide sans sa trace d’audit.');
            }
            return $missionsAnnulees;
        });
    }

    /**
     * Verrouille le responsable et verifie qu'il peut conduire une recherche.
     * Voir UserRepository::lockUser() pour la garantie apportee par ce verrou.
     */
    private function responsableEligible(int $userId, string $messageRefus): array
    {
        $responsable = (new UserRepository())->repo_lockUser($userId);
        if (
            !$responsable
            || (int) $responsable['actif'] !== 1
            || !in_array((string) $responsable['role'], self::ROLES_ELIGIBLES, true)
        ) {
            throw new DomainException($messageRefus);
        }
        return $responsable;
    }

    /** La mission existe, appartient au cycle en cours du dossier et n'est pas terminee. */
    private function missionActiveDuCycle(?array $mission, array $formulaire): bool
    {
        return $mission !== null
            && (int) ($mission['cycle_suivi'] ?? 0) === (int) ($formulaire['cycle_suivi'] ?? 1)
            && in_array((string) $mission['etat'], self::ETATS_ACTIFS, true);
    }

    private function statutObligatoire(string $code, string $messageAbsent): array
    {
        $statut = (new StatutRepository())->repo_findByCode($code);
        if (!$statut) {
            throw new RuntimeException($messageAbsent);
        }
        return $statut;
    }

    /**
     * Recopie dans le dossier la premiere mission encore active ou, a defaut,
     * le resultat de la derniere mission terminee.
     */
    private function synchroniserResumeMissions(int $formulaireId): void
    {
        $missionRepository = new MissionRechercheRepository();
        $formulaireRepository = new FormulaireRepository();
        $active = $missionRepository->repo_premiereActive($formulaireId);
        if ($active) {
            $statut = $this->statutObligatoire('en_recherche', 'Le statut En recherche n’est pas configure.');
            $formulaireRepository->repo_update($formulaireId, [
                'statut_id' => (int) $statut['id'],
                'localisation_id' => (int) $active['localisation_id'],
                'responsable_id' => (int) $active['responsable_id'],
                'date_echeance_recherche' => $active['date_echeance'],
                'date_recherche' => null,
                'resultat' => '',
                'observations' => '',
                'priorite' => $active['priorite'],
            ]);
            return;
        }

        $derniere = $missionRepository->repo_derniereTerminee($formulaireId);
        $formulaire = $formulaireRepository->repo_find($formulaireId);
        $codeStatut = $derniere
            ? match ((string) ($derniere['resultat_code'] ?? '')) {
                'retrouve' => 'retrouve',
                'a_verifier' => 'a_verifier',
                default => 'introuvable',
            }
            : ((int) ($formulaire['cycle_suivi'] ?? 1) > 1 ? 'a_verifier' : 'introuvable');
        $statut = $this->statutObligatoire($codeStatut, 'Le statut de reprise du dossier n’est pas configure.');
        $formulaireRepository->repo_update($formulaireId, [
            'statut_id' => (int) $statut['id'],
            'localisation_id' => $derniere ? (int) $derniere['localisation_id'] : null,
            'responsable_id' => $derniere ? (int) $derniere['responsable_id'] : null,
            'date_echeance_recherche' => null,
            'date_recherche' => $derniere['date_recherche'] ?? null,
            'resultat' => $derniere['resultat'] ?? '',
            'observations' => $derniere['observations'] ?? '',
            'priorite' => $derniere['priorite'] ?? 'Normale',
        ]);
    }
}
