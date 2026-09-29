<?php
declare(strict_types=1);

namespace App\Http\Controllers\Formulaire;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Http\Requests\Formulaire\CreateFormulaireFormRequest;
use App\Http\Requests\Formulaire\UpdateFormulaireFormRequest;
use App\Repositories\Administration\ActiviteRepository;
use App\Repositories\Formulaire\FinalisationFormulaireRepository;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Formulaire\PieceJointeRepository;
use App\Repositories\Formulaire\RechercheFormulaireRepository;
use App\Repositories\Formulaire\ReouvertureFormulaireRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Repositories\Referentiel\LocalisationRepository;
use App\Repositories\Referentiel\StatutRepository;
use App\Repositories\Referentiel\TypeTitreRepository;
use App\Repositories\Utilisateur\UserRepository;
use App\Services\Formulaire\FormulaireHistoriqueBuilder;
use App\Services\Formulaire\FormulaireService;
use DomainException;
use PDOException;
use RuntimeException;
use Throwable;

class FormulaireController extends Controller
{
    /** Compatibilite des anciens favoris apres fusion de la recherche et du registre. */
    public function ctrl_redirectLegacySearch(): void
    {
        $this->requirePermission('recherche.view');
        $this->redirect('formulaires');
    }

    public function ctrl_index(): void
    {
        $this->requirePermission('formulaires.view');

        $formulaireRepository = new FormulaireRepository();

        $this->render('formulaires/list', [
            '__title' => 'Formulaires manquants',
            '__active' => 'formulaires',
            '__hide_page_header' => true,
            'types' => (new TypeTitreRepository())->repo_actifs(),
            'statuts' => (new StatutRepository())->repo_tous(),
            'localisations' => (new LocalisationRepository())->repo_actives(),
            'responsables' => (new UserRepository())->repo_activeUsers(),
            'initialStatus' => (new StatutRepository())->repo_findByCode('introuvable'),
            'annees' => range((int) date('Y'), 2006),
            'registryKpi' => $formulaireRepository->repo_kpiGlobaux(),
            'missionsKpi' => (new MissionRechercheRepository())->repo_statsGlobales(),
            'archivesCount' => Permission::has((string) Auth::role(), 'formulaires.archive')
                ? $formulaireRepository->repo_count('est_archive = 1')
                : 0,
        ]);
    }

    public function ctrl_archives(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.archive');
        $this->render('formulaires/archives', [
            '__title' => 'Formulaires archives',
            '__active' => 'formulaires',
            'formulaires' => (new FormulaireRepository())->repo_archives(),
        ]);
    }

    public function ctrl_create(): void
    {
        Auth::requireLogin();
        $role = (string) Auth::role();
        if (!Permission::has($role, 'formulaires.create')) {
            Permission::requireOrFail('formulaires.create');
        }

        $initialStatus = (new StatutRepository())->repo_findByCode('introuvable');
        if (!$initialStatus) {
            throw new RuntimeException('Le statut initial "Introuvable" est absent de la configuration.');
        }

        $this->render('formulaires/form', [
            '__title' => 'Ajouter un formulaire manquant',
            '__active' => 'formulaires',
            '__page_icon' => 'fas fa-file-medical',
            '__subtitle' => 'Enregistrer un nouveau dossier dans le registre national de suivi.',
            '__header_actions' => [[
                'label' => 'Retour au registre',
                'url' => url('formulaires'),
                'icon' => 'fas fa-arrow-left',
                'class' => 'btn-outline-secondary',
            ]],
            'formulaire' => null,
            'initialStatus' => $initialStatus,
            'types' => (new TypeTitreRepository())->repo_actifs(),
        ]);
    }

    public function ctrl_store(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.create');
        $data = $this->validateRequest(CreateFormulaireFormRequest::class, 'formulaires/ajouter');

        try {
            $created = (new FormulaireService())->srv_creer($data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/ajouter');
            return;
        } catch (PDOException $e) {
            error_log('[Formulaire] Echec SQL de creation : ' . $e->getMessage());
            setFlash('error', $this->formulaireDatabaseError($e));
            $this->redirect('formulaires/ajouter');
            return;
        } catch (Throwable $e) {
            error_log('[Formulaire] Echec de generation de reference : ' . $e->getMessage());
            setFlash('error', 'Impossible de generer la reference du formulaire. Merci de reessayer.');
            $this->redirect('formulaires/ajouter');
            return;
        }

        setFlash('success', "Formulaire enregistre sous la reference {$created['numero_auto']}.");
        $this->redirect($this->creationRedirectTarget($created['id']));
    }

    public function ctrl_show(string $id): void
    {
        $this->requirePermission('formulaires.view');
        $model = new FormulaireRepository();
        $formulaire = $model->repo_findWithRelations((int) $id);
        if (!$formulaire) {
            setFlash('error', 'Formulaire introuvable.');
            $this->redirect('formulaires');
            return;
        }
        if ((int) ($formulaire['est_archive'] ?? 0) === 1
            && !Permission::has((string) Auth::role(), 'formulaires.archive')
        ) {
            setFlash('error', 'Ce formulaire est archive et accessible uniquement aux administrateurs.');
            $this->redirect('formulaires');
            return;
        }

        $canEditMetadata = $this->canEditMetadata($formulaire);
        $canAssign = $this->canAssignResearch($formulaire);
        $missions = (new MissionRechercheRepository())->repo_pourFormulaire((int) $id);
        $hasActiveAssignment = false;
        $canRecordResult = false;
        foreach ($missions as &$mission) {
            $mission['cycle_actif'] = (int) ($mission['cycle_suivi'] ?? 1)
                === (int) ($formulaire['cycle_suivi'] ?? 1);
            $mission['peut_saisir'] = $this->canRecordMissionResult($mission);
            if ($mission['cycle_actif']
                && in_array((string) $mission['etat'], ['affectee', 'en_cours'], true)
            ) {
                $hasActiveAssignment = true;
            }
            if ($mission['peut_saisir']) {
                $canRecordResult = true;
            }
        }
        unset($mission);
        $canAttach = $this->canAttachToForm($formulaire);
        $finalisationRepository = new FinalisationFormulaireRepository();
        $nextFinalizationStep = $finalisationRepository->repo_nextStepForStatus(
            (string) ($formulaire['statut_code'] ?? '')
        );
        $canFinalize = (int) ($formulaire['est_archive'] ?? 0) === 0
            && $nextFinalizationStep !== null
            && Permission::has((string) Auth::role(), 'formulaires.finalize');
        $canReopen = (int) ($formulaire['est_archive'] ?? 0) === 0
            && in_array((string) ($formulaire['statut_code'] ?? ''), ['retrouve', 'numerise', 'saisi'], true)
            && Permission::has((string) Auth::role(), 'formulaires.reopen');
        $pieces = (new PieceJointeRepository())->repo_pourFormulaire((int) $id);
        foreach ($pieces as &$piece) {
            $piece['peut_supprimer'] = $this->canDeleteAttachment($piece, $formulaire);
        }
        unset($piece);
        $headerActions = [];
        if ($canEditMetadata) {
            $headerActions[] = [
                'label' => 'Modifier',
                'url' => '#modifier-formulaire',
                'icon' => 'fas fa-edit',
                'class' => 'btn-primary',
                'data_toggle' => 'modal',
                'data_target' => '#modal-modifier-formulaire',
            ];
        }
        if ($canReopen) {
            $headerActions[] = [
                'label' => 'Rouvrir',
                'url' => '#reouvrir-formulaire',
                'icon' => 'fas fa-redo',
                'class' => 'btn-warning',
                'data_toggle' => 'modal',
                'data_target' => '#modal-reouvrir-formulaire',
            ];
        }
        $headerActions[] = [
            'label' => 'Retour a la liste',
            'url' => url('formulaires'),
            'icon' => 'fas fa-arrow-left',
            'class' => 'btn-secondary',
        ];
        if (Permission::has((string) Auth::role(), 'formulaires.archive')) {
            $headerActions[] = (int) ($formulaire['est_archive'] ?? 0) === 1
                ? [
                    'label' => 'Restaurer',
                    'url' => '#restaurer-formulaire',
                    'icon' => 'fas fa-undo',
                    'class' => 'btn-success',
                    'data_toggle' => 'collapse',
                    'data_target' => '#restaurer-formulaire',
                ]
                : [
                    'label' => 'Archiver',
                    'url' => '#modal-archiver-formulaire',
                    'icon' => 'fas fa-archive',
                    'class' => 'btn-danger',
                    'data_toggle' => 'modal',
                    'data_target' => '#modal-archiver-formulaire',
                ];
        }

        $recherches = (new RechercheFormulaireRepository())->repo_pourFormulaire((int) $id);
        $historiqueDossier = (new FormulaireHistoriqueBuilder())->construire(
            (new ActiviteRepository())->repo_pourFormulaire((int) $id),
            $recherches
        );

        $this->render('formulaires/show', [
            '__title' => 'Formulaire ' . $formulaire['numero_auto'],
            '__active' => 'formulaires',
            '__hide_page_header' => true,
            '__page_icon' => 'fas fa-folder-open',
            '__subtitle' => 'Consulter le dossier et enregistrer chaque tentative de recherche.',
            '__header_actions' => $headerActions,
            'formulaire' => $formulaire,
            'pieces' => $pieces,
            'recherches' => $recherches,
            'historiqueDossier' => $historiqueDossier,
            'finalisations' => $finalisationRepository->repo_pourFormulaire((int) $id),
            'reouvertures' => (new ReouvertureFormulaireRepository())->repo_pourFormulaire((int) $id),
            'missions' => $missions,
            'localisationsRecherchees' => (new RechercheFormulaireRepository())->repo_localisationsDejaRecherchees((int) $id),
            'localisations' => (new LocalisationRepository())->repo_actives(),
            'responsables' => (new UserRepository())->repo_activeUsers(),
            'types' => (new TypeTitreRepository())->repo_actifs(),
            'statuts' => (new StatutRepository())->repo_tous(),
            'canEditMetadata' => $canEditMetadata,
            'canAssign' => $canAssign,
            'canRecordResult' => $canRecordResult,
            'canAttach' => $canAttach,
            'canFinalize' => $canFinalize,
            'canReopen' => $canReopen,
            'nextFinalizationStep' => $nextFinalizationStep,
            'hasActiveAssignment' => $hasActiveAssignment,
            'researchOld' => $this->pullResearchInput(),
        ]);
    }

    public function ctrl_update(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $this->checkMetadataRight($formulaireId);
        $retour = 'formulaires/voir/' . $formulaireId . '#modifier-formulaire';
        $data = $this->validateRequest(UpdateFormulaireFormRequest::class, $retour);

        try {
            (new FormulaireService())->srv_modifier($formulaireId, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (PDOException $e) {
            error_log('[Formulaire] Echec SQL de mise a jour #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', $this->formulaireDatabaseError($e, true));
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Formulaire] Echec de mise a jour historisee #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La mise a jour n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect($retour);
            return;
        }
        setFlash('success', 'Formulaire mis a jour.');
        $this->redirect('formulaires/voir/' . $formulaireId);
    }

    private function checkMetadataRight(int $formulaireId): void
    {
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        if ($this->canEditMetadata($formulaire)) {
            return;
        }
        Permission::requireOrFail('formulaires.update_metadata');
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

    private function canEditMetadata(array $formulaire): bool
    {
        return (int) ($formulaire['est_archive'] ?? 0) === 0
            && Permission::has((string) Auth::role(), 'formulaires.update_metadata');
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

    private function canAttachToForm(array $formulaire): bool
    {
        if ((int) ($formulaire['est_archive'] ?? 0) === 1) {
            return false;
        }
        $role = (string) Auth::role();
        return Permission::has($role, 'formulaires.attach_any')
            || (Permission::has($role, 'formulaires.attach_own')
                && (
                    (int) ($formulaire['responsable_id'] ?? 0) === (int) Auth::id()
                    || (new MissionRechercheRepository())->repo_estResponsableDuFormulaire(
                        (int) $formulaire['id'],
                        (int) Auth::id()
                    )
                ));
    }

    private function canDeleteAttachment(array $piece, array $formulaire): bool
    {
        if ((int) ($formulaire['est_archive'] ?? 0) === 1) {
            return false;
        }
        $role = (string) Auth::role();
        return Permission::has($role, 'formulaires.delete_attachment_any')
            || (Permission::has($role, 'formulaires.delete_attachment_own')
                && (
                    (int) ($formulaire['responsable_id'] ?? 0) === (int) Auth::id()
                    || (new MissionRechercheRepository())->repo_estResponsableDuFormulaire(
                        (int) $formulaire['id'],
                        (int) Auth::id()
                    )
                )
                && (int) ($piece['televerse_par'] ?? 0) === (int) Auth::id());
    }

    private function creationRedirectTarget(int $formulaireId): string
    {
        return (string) $this->input('_redirect_after_create', '') === 'list'
            ? 'formulaires'
            : 'formulaires/voir/' . $formulaireId;
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

    private function pullResearchInput(): array
    {
        $data = $_SESSION['_research_old'] ?? [];
        unset($_SESSION['_research_old']);
        return is_array($data) ? $data : [];
    }
}
