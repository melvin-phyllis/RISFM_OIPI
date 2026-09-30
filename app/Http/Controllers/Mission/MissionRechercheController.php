<?php
declare(strict_types=1);

namespace App\Http\Controllers\Mission;

use App\Core\AppMailer;
use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\Permission;
use App\Exceptions\ConfirmationRequiseException;
use App\Http\Requests\Mission\AffecterMissionFormRequest;
use App\Http\Requests\Mission\AnnulerMissionFormRequest;
use App\Http\Requests\Mission\DeclarerRetrouveFormRequest;
use App\Http\Requests\Mission\EnregistrerResultatMissionFormRequest;
use App\Http\Requests\Mission\ReaffecterMissionFormRequest;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Repositories\Notification\NotificationRepository;
use App\Repositories\Utilisateur\UserRepository;
use App\Services\Mission\MissionRechercheService;
use DateTimeImmutable;
use DomainException;
use PDOException;
use Throwable;

/**
 * Cycle complet des missions : affectation, transfert, annulation et resultat.
 * Les regles metier sont portees par MissionRechercheService ; ce controleur
 * lit la requete, controle les droits, puis traduit le resultat en message.
 */
class MissionRechercheController extends Controller
{
    public function ctrl_assign(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $this->checkAssignmentRight($formulaireId);
        $retour = $this->assignmentRedirectTarget($formulaireId);
        $data = $this->validateRequest(AffecterMissionFormRequest::class, $retour);

        try {
            $missionId = (new MissionRechercheService())->srv_affecter($formulaireId, $data, (int) Auth::id());
        } catch (ConfirmationRequiseException $e) {
            setFlash('warning', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (PDOException $e) {
            error_log('[Affectation] Echec SQL pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash(
                'error',
                (int) ($e->errorInfo[1] ?? 0) === 1062
                    ? 'Cette mission est deja active pour ce responsable dans cette localisation.'
                    : 'L’affectation n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.'
            );
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Affectation] Echec pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'L’affectation n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect($retour);
            return;
        }

        $this->notifierMission($missionId);

        setFlash('success', 'Mission de recherche ajoutee. Le responsable a ete notifie.');
        $this->redirect($this->assignmentRedirectTarget($formulaireId, '#historique-recherches'));
    }

    public function ctrl_cancel(string $id): void
    {
        Auth::requireLogin();
        $mission = $this->missionOrRedirect((int) $id);
        $formulaireId = (int) $mission['formulaire_id'];
        $this->checkAssignmentRight($formulaireId);
        $retour = 'formulaires/voir/' . $formulaireId . '#mission-' . $mission['id'];
        $data = $this->validateRequest(AnnulerMissionFormRequest::class, $retour);

        try {
            (new MissionRechercheService())->srv_annuler($mission, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Mission] Echec annulation #' . $mission['id'] . ' : ' . $e->getMessage());
            setFlash('error', 'La mission n’a pas pu etre annulee. Aucune donnee n’a ete modifiee.');
            $this->redirect($retour);
            return;
        }

        (new NotificationRepository())->repo_creer(
            (int) $mission['responsable_id'],
            'Mission de recherche annulee',
            "La mission sur le formulaire {$mission['numero_auto']} a ete annulee. Motif : {$data['annulation_motif']}",
            'info',
            url('formulaires/voir/' . $formulaireId . '#mission-' . $mission['id'])
        );
        setFlash('success', 'Mission annulee. Le responsable a ete informe et l’historique est conserve.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#missions-recherche');
    }

    public function ctrl_reassign(string $id): void
    {
        Auth::requireLogin();
        $mission = $this->missionOrRedirect((int) $id);
        $formulaireId = (int) $mission['formulaire_id'];
        $this->checkAssignmentRight($formulaireId);
        $retour = 'formulaires/voir/' . $formulaireId . '#mission-' . $mission['id'];
        $data = $this->validateRequest(ReaffecterMissionFormRequest::class, $retour);

        try {
            $newMissionId = (new MissionRechercheService())->srv_reaffecter($mission, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (PDOException $e) {
            error_log('[Mission] Echec reaffectation #' . $mission['id'] . ' : ' . $e->getMessage());
            setFlash(
                'error',
                (int) ($e->errorInfo[1] ?? 0) === 1062
                    ? 'Ce responsable possede deja une mission active dans cette localisation.'
                    : 'La mission n’a pas pu etre reaffectee.'
            );
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Mission] Echec reaffectation #' . $mission['id'] . ' : ' . $e->getMessage());
            setFlash('error', 'La mission n’a pas pu etre reaffectee. Aucune donnee n’a ete modifiee.');
            $this->redirect($retour);
            return;
        }

        (new NotificationRepository())->repo_creer(
            (int) $mission['responsable_id'],
            'Mission reaffectee',
            "Votre mission sur le formulaire {$mission['numero_auto']} a ete transferee a un autre responsable.",
            'info',
            url('formulaires/voir/' . $formulaireId . '#mission-' . $mission['id'])
        );
        $this->notifierMission($newMissionId, true);
        setFlash('success', 'Mission reaffectee. L’ancien et le nouveau responsable ont ete informes.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $newMissionId);
    }

    public function ctrl_recordLegacyResult(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $missions = (new MissionRechercheRepository())->repo_activesPourFormulaire($formulaireId);
        $accessibles = array_values(array_filter(
            $missions,
            fn (array $mission): bool => $this->canRecordMissionResult($mission)
        ));
        if (count($accessibles) !== 1) {
            setFlash('error', count($accessibles) > 1
                ? 'Plusieurs missions sont actives. Selectionnez la mission dont vous voulez saisir le resultat.'
                : 'Aucune mission active ne peut etre traitee avec votre compte.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#missions-recherche');
            return;
        }
        $this->ctrl_recordResult((string) $accessibles[0]['id']);
    }

    public function ctrl_recordResult(string $id): void
    {
        Auth::requireLogin();
        $mission = $this->missionOrRedirect((int) $id);
        $formulaireId = (int) $mission['formulaire_id'];
        $this->editableFormOrRedirect($formulaireId);
        if (!$this->canRecordMissionResult($mission)) {
            Permission::requireOrFail('formulaires.record_result_any');
        }
        $retour = 'formulaires/voir/' . $formulaireId . '#mission-' . $mission['id'];
        $data = $this->validateRequest(EnregistrerResultatMissionFormRequest::class, $retour);

        try {
            $missionsAnnulees = (new MissionRechercheService())->srv_enregistrerResultat($mission, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (PDOException $e) {
            error_log('[Recherche] Echec SQL pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', $this->formulaireDatabaseError($e, true));
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Recherche] Echec d\'enregistrement pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La recherche n\'a pas pu etre enregistree. Aucune donnee n\'a ete modifiee.');
            $this->redirect($retour);
            return;
        }

        foreach ($missionsAnnulees as $missionAnnulee) {
            (new NotificationRepository())->repo_creer(
                (int) $missionAnnulee['responsable_id'],
                'Mission cloturee automatiquement',
                "Votre mission sur le formulaire {$mission['numero_auto']} a ete cloturee : le formulaire a ete retrouve par une autre mission.",
                'info',
                url('formulaires/voir/' . $formulaireId . '#mission-' . $missionAnnulee['id'])
            );
        }

        // Notifier l'assigneur (affecte_par) que le formulaire a ete retrouve
        if ($data['recherche_resultat_code'] === 'retrouve') {
            $this->notifierAssigneurResultatRetrouve((int) $mission['id'], $formulaireId);
        }

        setFlash('success', $data['recherche_resultat_code'] === 'retrouve'
            ? 'Formulaire retrouve. Les autres missions actives ont ete cloturees.'
            : 'Resultat de la mission ajoute a l’historique.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#historique-recherches');
    }

    /**
     * Declaration directe : le formulaire a ete trouve sans mission prealable.
     * Un valideur le passe a Retrouve ; sinon il passe a A verifier.
     */
    public function ctrl_declareFound(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        Permission::requireOrFail('formulaires.declare_found');
        $retour = 'formulaires/voir/' . $formulaireId;
        $data = $this->validateRequest(DeclarerRetrouveFormRequest::class, $retour);
        $valide = Permission::has((string) Auth::role(), 'formulaires.declare_found_validated');
        $service = new MissionRechercheService();

        try {
            $missionsAnnulees = $service->srv_declarerRetrouve($formulaireId, $data, (int) Auth::id(), $valide);
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Declaration] Echec pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La declaration n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect($retour);
            return;
        }

        $service->srv_notifierDeclaration($formulaireId, (string) $formulaire['numero_auto'], $valide, $missionsAnnulees, (int) Auth::id());
        setFlash('success', $valide
            ? 'Formulaire declare retrouve. Les missions en cours ont ete cloturees.'
            : 'Decouverte signalee : le formulaire est a verifier par un responsable.');
        $this->redirect($retour . '#historique-recherches');
    }

    /** Mission demandee, ou retour au registre si elle n'existe pas. */
    private function missionOrRedirect(int $missionId): array
    {
        $mission = (new MissionRechercheRepository())->repo_findWithRelations($missionId);
        if (!$mission) {
            setFlash('error', 'Mission de recherche introuvable.');
            $this->redirect('formulaires');
        }
        return $mission;
    }

    private function checkAssignmentRight(int $formulaireId): void
    {
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        if ($this->canAssignResearch($formulaire)) {
            return;
        }
        Permission::requireOrFail('formulaires.assign');
    }

    private function editableFormOrRedirect(int $formulaireId): array
    {
        $formulaire = (new FormulaireRepository())->repo_find($formulaireId);
        if (!$formulaire) {
            setFlash('error', 'Formulaire introuvable.');
            $this->redirect('formulaires');
        }
        if ((int) ($formulaire['est_archive'] ?? 0) === 1) {
            setFlash('error', 'Un formulaire archive est conserve en lecture seule. Restaurez-le avant de le modifier.');
            $this->redirect('formulaires/archives');
        }
        return $formulaire;
    }

    private function canAssignResearch(array $formulaire): bool
    {
        return (int) ($formulaire['est_archive'] ?? 0) === 0
            && Permission::has((string) Auth::role(), 'formulaires.assign');
    }

    private function canRecordMissionResult(array $mission): bool
    {
        if ((int) ($mission['est_archive'] ?? 0) === 1
            || (array_key_exists('cycle_actif', $mission) && !$mission['cycle_actif'])
            || (isset($mission['formulaire_cycle_suivi'])
                && (int) ($mission['cycle_suivi'] ?? 0) !== (int) $mission['formulaire_cycle_suivi'])
            || !in_array((string) ($mission['etat'] ?? ''), ['affectee', 'en_cours'], true)
        ) {
            return false;
        }
        $role = (string) Auth::role();
        return Permission::has($role, 'formulaires.record_result_any')
            || (Permission::has($role, 'formulaires.record_result_own')
                && (int) ($mission['responsable_id'] ?? 0) === (int) Auth::id());
    }


    private function assignmentRedirectTarget(int $formulaireId, string $detailAnchor = ''): string
    {
        return (string) $this->input('_redirect_after_assignment', '') === 'list'
            ? 'formulaires'
            : 'formulaires/voir/' . $formulaireId . $detailAnchor;
    }

    private function formulaireDatabaseError(PDOException $exception, bool $updating = false): string
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $driverMessage = (string) ($exception->errorInfo[2] ?? $exception->getMessage());

        if ($driverCode === 1062) {
            if (str_contains($driverMessage, 'uk_fm_annee_type_numero')) {
                return $updating
                    ? 'La modification creerait un doublon : ce numero existe deja pour cette annee et ce type de titre.'
                    : 'Un formulaire portant ce numero existe deja pour cette annee et ce type de titre.';
            }
            if (str_contains($driverMessage, 'numero_auto')) {
                return 'La reference automatique vient d’etre utilisee par une autre operation. Reessayez l’enregistrement.';
            }
            return 'Une valeur devant etre unique existe deja dans le registre.';
        }

        if ($driverCode === 1452) {
            $constraints = [
                'fk_fm_type' => 'Le type de titre selectionne n’existe plus.',
                'fk_fm_statut' => 'Le statut selectionne n’existe plus.',
                'fk_fm_localisation' => 'La localisation selectionnee n’existe plus.',
                'fk_fm_responsable' => 'Le responsable selectionne n’existe plus.',
                'fk_rf_localisation' => 'La localisation de recherche selectionnee n’existe plus.',
                'fk_rf_responsable' => 'Le responsable de la recherche n’existe plus.',
                'fk_rf_statut' => 'Le statut de la recherche n’existe plus.',
            ];
            foreach ($constraints as $constraint => $message) {
                if (str_contains($driverMessage, $constraint)) {
                    return $message . ' Rechargez la page puis choisissez une valeur valide.';
                }
            }
            return 'Une donnee de reference selectionnee n’existe plus. Rechargez la page puis reessayez.';
        }

        if (in_array($driverCode, [1265, 1366], true)) {
            return 'Une valeur envoyee n’est pas autorisee pour ce formulaire.';
        }
        if ($driverCode === 1406) {
            return 'Une des valeurs saisies depasse la longueur maximale autorisee.';
        }

        return $updating
            ? 'La mise a jour n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.'
            : 'Le formulaire n’a pas pu etre enregistre. Aucune donnee n’a ete ajoutee.';
    }

    private function notifierMission(int $missionId, bool $reassignment = false): void
    {
        $mission = (new MissionRechercheRepository())->repo_findWithRelations($missionId);
        if (!$mission || empty($mission['responsable_id'])) {
            return;
        }
        $user = (new UserRepository())->repo_find((int) $mission['responsable_id']);
        $formulaire = (new FormulaireRepository())->repo_findWithRelations((int) $mission['formulaire_id']);
        if (!$user || !$formulaire || (int) ($user['actif'] ?? 0) !== 1) {
            return;
        }

        $formulaire['localisation_libelle'] = $mission['localisation_libelle'];
        $formulaire['date_echeance_recherche'] = $mission['date_echeance'];
        $details = ' dans ' . $mission['localisation_libelle'];
        if (!empty($mission['date_echeance'])) {
            $details .= ' avant le ' . (new DateTimeImmutable($mission['date_echeance']))->format('d/m/Y');
        }
        $urlMission = url('formulaires/voir/' . $mission['formulaire_id'] . '#mission-' . $missionId);
        (new NotificationRepository())->repo_creer(
            (int) $mission['responsable_id'],
            $reassignment ? 'Mission de recherche reaffectee' : 'Nouvelle mission de recherche',
            "Le formulaire {$formulaire['numero_auto']} vous a ete "
                . ($reassignment ? 'reaffecte' : 'affecte')
                . " pour recherche{$details}.",
            'rappel',
            $urlMission
        );

        try {
            (new AppMailer())->sendAssignment($user, $formulaire, $urlMission, $reassignment);
            Logger::log(Auth::id(), 'email', "E-mail de la mission #{$missionId} envoye a l'utilisateur #{$user['id']}");
        } catch (Throwable $e) {
            error_log("[RISFM Mail] Mission #{$missionId} : " . $e->getMessage());
            Logger::log(Auth::id(), 'email', "Echec de l'e-mail de la mission #{$missionId} a l'utilisateur #{$user['id']}");
        }
    }

    /**
     * Notifie l'utilisateur qui a assigne la mission (affecte_par) que le
     * formulaire a ete retrouve. Envoie une notification interne et un e-mail.
     * L'echec de l'envoi est journalise mais ne bloque pas le workflow.
     */
    private function notifierAssigneurResultatRetrouve(int $missionId, int $formulaireId): void
    {
        $mission = (new MissionRechercheRepository())->repo_findWithRelations($missionId);
        if (!$mission || empty($mission['affecte_par'])) {
            return;
        }

        $assigneurId = (int) $mission['affecte_par'];
        $responsableId = (int) $mission['responsable_id'];

        // Ne pas notifier l'assigneur s'il est aussi celui qui a saisi le resultat
        if ($assigneurId === (int) Auth::id()) {
            return;
        }

        $assigneur = (new UserRepository())->repo_find($assigneurId);
        if (!$assigneur || (int) ($assigneur['actif'] ?? 0) !== 1) {
            return;
        }

        $responsable = (new UserRepository())->repo_find($responsableId);
        $formulaire = (new FormulaireRepository())->repo_findWithRelations($formulaireId);
        if (!$formulaire) {
            return;
        }

        $responsableNom = $responsable
            ? trim(($responsable['prenoms'] ?? '') . ' ' . ($responsable['nom'] ?? ''))
            : ($mission['responsable_nom'] ?? 'un agent');

        $urlFormulaire = url('formulaires/voir/' . $formulaireId . '#historique-recherches');

        // Notification interne
        (new NotificationRepository())->repo_creer(
            $assigneurId,
            'Formulaire retrouve',
            "Le formulaire {$formulaire['numero_auto']} que vous aviez affecte a {$responsableNom} a ete retrouve dans {$mission['localisation_libelle']}.",
            'info',
            $urlFormulaire
        );

        // Notification par e-mail
        try {
            (new AppMailer())->sendResultFound(
                $assigneur,
                $responsable ?? ['nom' => $responsableNom, 'prenoms' => ''],
                $formulaire,
                $mission,
                $urlFormulaire
            );
            Logger::log(Auth::id(), 'email', "E-mail 'formulaire retrouve' pour la mission #{$missionId} envoye a l'assigneur #{$assigneurId}");
        } catch (Throwable $e) {
            error_log("[RISFM Mail] Notification retrouve mission #{$missionId} : " . $e->getMessage());
            Logger::log(Auth::id(), 'email', "Echec de l'e-mail 'formulaire retrouve' pour la mission #{$missionId} a l'assigneur #{$assigneurId}");
        }
    }
}
