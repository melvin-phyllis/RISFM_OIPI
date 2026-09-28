<?php
declare(strict_types=1);

class FormulaireController extends Controller
{
    /** Compatibilite des anciens favoris apres fusion de la recherche et du registre. */
    public function redirectLegacySearch(): void
    {
        $this->requirePermission('recherche.view');
        $this->redirect('formulaires');
    }

    public function index(): void
    {
        $this->requirePermission('formulaires.view');

        $formulaireModel = new FormulaireModel();

        $this->render('formulaires/list', [
            '__title' => 'Formulaires manquants',
            '__active' => 'formulaires',
            '__hide_page_header' => true,
            'types' => (new TypeTitreModel())->actifs(),
            'statuts' => (new StatutModel())->tous(),
            'localisations' => (new LocalisationModel())->actives(),
            'responsables' => (new UserModel())->activeUsers(),
            'initialStatus' => (new StatutModel())->findByCode('introuvable'),
            'annees' => range((int) date('Y'), 2006),
            'registryKpi' => $formulaireModel->kpiGlobaux(),
            'missionsKpi' => (new MissionRechercheModel())->statsGlobales(),
            'archivesCount' => Permission::has((string) Auth::role(), 'formulaires.archive')
                ? $formulaireModel->count('est_archive = 1')
                : 0,
        ]);
    }

    public function archives(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.archive');
        $this->render('formulaires/archives', [
            '__title' => 'Formulaires archives',
            '__active' => 'formulaires',
            'formulaires' => (new FormulaireModel())->archives(),
        ]);
    }

    public function create(): void
    {
        Auth::requireLogin();
        $role = (string) Auth::role();
        if (!Permission::has($role, 'formulaires.create')) {
            Permission::requireOrFail('formulaires.create');
        }

        $initialStatus = (new StatutModel())->findByCode('introuvable');
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
            'types' => (new TypeTitreModel())->actifs(),
        ]);
    }

    public function store(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.create');

        $rawData = $this->collect();
        $initialStatus = (new StatutModel())->findByCode('introuvable');
        if (!$initialStatus) {
            setFlash('error', 'Le statut initial Introuvable n’est pas configure. Contactez un administrateur.');
            $this->redirect('formulaires/ajouter');
            return;
        }

        // La creation declare uniquement l'existence du dossier manquant.
        // Affectation, recherche et resultat sont obligatoirement ajoutes
        // ensuite depuis la fiche afin de produire une ligne d'historique.
        $rawData['statut_id'] = (string) $initialStatus['id'];
        $rawData['localisation_id'] = null;
        $rawData['responsable_id'] = null;
        $rawData['date_recherche'] = null;
        $rawData['resultat'] = '';
        $rawData['observations'] = '';
        $rawData['date_depot'] = null;
        $rawData['deposant'] = '';
        $rawData['mandataire'] = '';
        $rawData['niveau_urgence'] = 'Moyen';
        $rawData['priorite'] = 'Normale';
        $metierValidator = new FormulaireMetierValidator();
        if (($error = $metierValidator->validateForm($rawData)) !== null) {
            setFlash('error', $error);
            $this->redirect('formulaires/ajouter');
            return;
        }

        $data = $this->normalizeFormData($rawData);

        $model = new FormulaireModel();
        if ($model->duplicateExists(
            $data['annee'],
            $data['type_titre_id'],
            $data['numero_formulaire']
        )) {
            setFlash('error', 'Un formulaire portant ce numero existe deja pour cette annee et ce type de titre.');
            $this->redirect('formulaires/ajouter');
            return;
        }
        $data['cree_par'] = Auth::id();

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $created = $model->insertWithGeneratedNumero($data);
            $id = $created['id'];
            $data['numero_auto'] = $created['numero_auto'];
            $apres = $model->find($id) ?: $data;
            if (!Logger::log(
                Auth::id(),
                'ajout',
                "Ajout du formulaire manquant {$data['numero_auto']}",
                null,
                'formulaire',
                $id,
                null,
                $this->auditSnapshot($apres)
            )) {
                throw new RuntimeException('La creation ne peut pas etre validee sans sa trace d’audit.');
            }
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Formulaire] Echec SQL de creation : ' . $e->getMessage());
            setFlash('error', $this->formulaireDatabaseError($e));
            $this->redirect('formulaires/ajouter');
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Formulaire] Echec de generation de reference : ' . $e->getMessage());
            setFlash('error', 'Impossible de generer la reference du formulaire. Merci de reessayer.');
            $this->redirect('formulaires/ajouter');
            return;
        }

        setFlash('success', "Formulaire enregistre sous la reference {$data['numero_auto']}.");
        $this->redirect($this->creationRedirectTarget($id));
    }

    public function show(string $id): void
    {
        $this->requirePermission('formulaires.view');
        $model = new FormulaireModel();
        $formulaire = $model->findWithRelations((int) $id);
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
        $missions = (new MissionRechercheModel())->pourFormulaire((int) $id);
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
        $finalisationModel = new FinalisationFormulaireModel();
        $nextFinalizationStep = $finalisationModel->nextStepForStatus(
            (string) ($formulaire['statut_code'] ?? '')
        );
        $canFinalize = (int) ($formulaire['est_archive'] ?? 0) === 0
            && $nextFinalizationStep !== null
            && Permission::has((string) Auth::role(), 'formulaires.finalize');
        $canReopen = (int) ($formulaire['est_archive'] ?? 0) === 0
            && in_array((string) ($formulaire['statut_code'] ?? ''), ['retrouve', 'numerise', 'saisi'], true)
            && Permission::has((string) Auth::role(), 'formulaires.reopen');
        $pieces = (new PieceJointeModel())->pourFormulaire((int) $id);
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

        $recherches = (new RechercheFormulaireModel())->pourFormulaire((int) $id);
        $historiqueDossier = (new FormulaireHistoriqueBuilder())->construire(
            (new ActiviteModel())->pourFormulaire((int) $id),
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
            'finalisations' => $finalisationModel->pourFormulaire((int) $id),
            'reouvertures' => (new ReouvertureFormulaireModel())->pourFormulaire((int) $id),
            'missions' => $missions,
            'localisationsRecherchees' => (new RechercheFormulaireModel())->localisationsDejaRecherchees((int) $id),
            'localisations' => (new LocalisationModel())->actives(),
            'responsables' => (new UserModel())->activeUsers(),
            'types' => (new TypeTitreModel())->actifs(),
            'statuts' => (new StatutModel())->tous(),
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

    public function update(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $this->checkMetadataRight($formulaireId);

        $model = new FormulaireModel();
        $ancien = $model->find($formulaireId);
        if (!$ancien) {
            setFlash('error', 'Formulaire introuvable.');
            $this->redirect('formulaires');
            return;
        }

        $rawData = $this->collect();
        // Les donnees de recherche ne se modifient pas depuis l'ecran des
        // informations generales. Elles sont conservees telles quelles et
        // evoluent uniquement via storeResearch(), qui cree l'historique.
        $rawData['statut_id'] = $ancien['statut_id'];
        $rawData['localisation_id'] = null;
        $rawData['responsable_id'] = null;
        $rawData['date_recherche'] = null;
        $rawData['resultat'] = '';
        $rawData['observations'] = '';
        $metierValidator = new FormulaireMetierValidator();
        if (($error = $metierValidator->validateForm($rawData, $ancien, false)) !== null) {
            setFlash('error', $error);
            $this->redirect('formulaires/voir/' . $formulaireId . '#modifier-formulaire');
            return;
        }

        $data = $this->normalizeFormData($rawData);
        unset($data['annee']); // l'annee ne se modifie pas (impacte la numerotation automatique)
        unset(
            $data['statut_id'],
            $data['localisation_id'],
            $data['responsable_id'],
            $data['date_recherche'],
            $data['resultat'],
            $data['observations'],
            $data['date_depot'],
            $data['deposant'],
            $data['mandataire']
        );

        if ($model->duplicateExists(
            (int) $ancien['annee'],
            $data['type_titre_id'],
            $data['numero_formulaire'],
            $formulaireId
        )) {
            setFlash('error', 'La modification creerait un doublon : ce numero existe deja pour cette annee et ce type de titre.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#modifier-formulaire');
            return;
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $model->update($formulaireId, $data);
            $apres = $model->find($formulaireId) ?: array_merge($ancien, $data);
            $this->journaliserChangements(
                $ancien,
                $apres,
                $formulaireId,
                'modification',
                "Modification du formulaire {$ancien['numero_auto']}"
            );
            $db->commit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Formulaire] Echec SQL de mise a jour #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', $this->formulaireDatabaseError($e, true));
            $this->redirect('formulaires/voir/' . $formulaireId . '#modifier-formulaire');
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Formulaire] Echec de mise a jour historisee #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La mise a jour n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#modifier-formulaire');
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

    private function checkAssignmentRight(int $formulaireId): void
    {
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        if ($this->canAssignResearch($formulaire)) {
            return;
        }
        Permission::requireOrFail('formulaires.assign');
    }

    private function checkResultRight(int $formulaireId): void
    {
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        if ($this->canRecordResearchResult($formulaire)) {
            return;
        }
        Permission::requireOrFail('formulaires.record_result_any');
    }

    private function checkAttachmentRight(int $formulaireId): void
    {
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        if ($this->canAttachToForm($formulaire)) {
            return;
        }
        Permission::requireOrFail('formulaires.attach_any');
    }

    private function editableFormOrRedirect(int $formulaireId): array
    {
        $formulaire = (new FormulaireModel())->find($formulaireId);
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

    private function canRecordResearchResult(array $formulaire): bool
    {
        if ((int) ($formulaire['est_archive'] ?? 0) === 1) {
            return false;
        }
        $role = (string) Auth::role();
        return Permission::has($role, 'formulaires.record_result_any')
            || (Permission::has($role, 'formulaires.record_result_own')
                && (int) ($formulaire['responsable_id'] ?? 0) === (int) Auth::id());
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
                    || (new MissionRechercheModel())->estResponsableDuFormulaire(
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
                    || (new MissionRechercheModel())->estResponsableDuFormulaire(
                        (int) $formulaire['id'],
                        (int) Auth::id()
                    )
                )
                && (int) ($piece['televerse_par'] ?? 0) === (int) Auth::id());
    }

    private function collect(): array
    {
        return [
            // Les nombres restent volontairement sous leur forme brute jusqu'a
            // la validation stricte ("1abc" ne doit jamais devenir l'id 1).
            'type_titre_id'     => trim((string) $this->input('type_titre_id', '')),
            'annee'             => trim((string) $this->input('annee', '')),
            'numero_formulaire' => Security::cleanString($this->input('numero_formulaire', '')),
            'date_depot'        => Security::cleanString($this->input('date_depot', '')) ?: null,
            'deposant'          => Security::cleanString($this->input('deposant', '')),
            'mandataire'        => Security::cleanString($this->input('mandataire', '')),
            'statut_id'         => trim((string) $this->input('statut_id', '')),
            'localisation_id'   => trim((string) $this->input('localisation_id', '')) ?: null,
            'responsable_id'    => trim((string) $this->input('responsable_id', '')) ?: null,
            'date_recherche'    => Security::cleanString($this->input('date_recherche', '')) ?: null,
            'resultat'          => Security::cleanString($this->input('resultat', '')),
            'observations'      => Security::cleanString($this->input('observations', '')),
            'priorite'          => Security::cleanString($this->input('priorite', 'Normale')),
        ];
    }

    private function collectResearch(): array
    {
        return [
            'localisation_id' => trim((string) $this->input('recherche_localisation_id', '')),
            'responsable_id' => trim((string) $this->input('recherche_responsable_id', '')),
            'statut_id' => trim((string) $this->input('recherche_statut_id', '')),
            'date_recherche' => Security::cleanString($this->input('recherche_date', '')),
            'resultat' => Security::cleanString($this->input('recherche_resultat', '')),
            'observations' => Security::cleanString($this->input('recherche_observations', '')),
        ];
    }

    private function collectAssignment(): array
    {
        return [
            'localisation_id' => trim((string) $this->input('affectation_localisation_id', '')),
            'responsable_id' => trim((string) $this->input('affectation_responsable_id', '')),
            'date_echeance_recherche' => Security::cleanString($this->input('affectation_date_echeance', '')) ?: null,
            'priorite' => Security::cleanString($this->input('affectation_priorite', 'Normale')),
        ];
    }

    private function assignmentRedirectTarget(int $formulaireId, string $detailAnchor = ''): string
    {
        return (string) $this->input('_redirect_after_assignment', '') === 'list'
            ? 'formulaires'
            : 'formulaires/voir/' . $formulaireId . $detailAnchor;
    }

    private function creationRedirectTarget(int $formulaireId): string
    {
        return (string) $this->input('_redirect_after_create', '') === 'list'
            ? 'formulaires'
            : 'formulaires/voir/' . $formulaireId;
    }

    private function normalizeFormData(array $data): array
    {
        $data['type_titre_id'] = (int) $data['type_titre_id'];
        $data['annee'] = (int) $data['annee'];
        $data['statut_id'] = (int) $data['statut_id'];
        $data['localisation_id'] = !empty($data['localisation_id'])
            ? (int) $data['localisation_id']
            : null;
        $data['responsable_id'] = !empty($data['responsable_id'])
            ? (int) $data['responsable_id']
            : null;
        return $data;
    }

    private function normalizeResearchData(array $data): array
    {
        $data['localisation_id'] = (int) $data['localisation_id'];
        $data['responsable_id'] = (int) $data['responsable_id'];
        $data['statut_id'] = (int) $data['statut_id'];
        return $data;
    }

    private function researchSnapshot(array $data): array
    {
        return [
            'localisation_id' => !empty($data['localisation_id']) ? (int) $data['localisation_id'] : null,
            'responsable_id' => !empty($data['responsable_id']) ? (int) $data['responsable_id'] : null,
            'statut_id' => (int) ($data['statut_id'] ?? 0),
            'date_recherche' => (string) ($data['date_recherche'] ?? ''),
            'resultat' => Security::cleanString($data['resultat'] ?? ''),
            'observations' => Security::cleanString($data['observations'] ?? ''),
        ];
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

    private function rememberResearchInput(array $data): void
    {
        $_SESSION['_research_old'] = $data;
    }

    private function pullResearchInput(): array
    {
        $data = $_SESSION['_research_old'] ?? [];
        unset($_SESSION['_research_old']);
        return is_array($data) ? $data : [];
    }

    private function notifierChangementResponsable(array $ancien, array $data, int $formulaireId): void
    {
        if ((int) ($ancien['responsable_id'] ?? 0) === (int) ($data['responsable_id'] ?? 0)) {
            return;
        }
        if (!empty($ancien['responsable_id'])) {
            (new NotificationModel())->creer(
                (int) $ancien['responsable_id'],
                'Affectation retiree',
                "Le formulaire {$ancien['numero_auto']} ne vous est plus affecte.",
                'info',
                url('formulaires/voir/' . $formulaireId)
            );
        }
        $this->notifierResponsable($data, $formulaireId, !empty($ancien['responsable_id']));
    }

    private function journaliserChangements(
        array $avant,
        array $apres,
        int $formulaireId,
        string $type,
        string $description
    ): void {
        if (!Logger::log(
            Auth::id(),
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
                Auth::id(),
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
                Auth::id(),
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

    private function strictDate(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Recalcule le resume du registre apres l'annulation d'une mission sans
     * effacer les missions ni les resultats historiques.
     */
    private function synchronizeMissionSummary(int $formulaireId): void
    {
        $missionModel = new MissionRechercheModel();
        $formModel = new FormulaireModel();
        $active = $missionModel->premiereActive($formulaireId);
        if ($active) {
            $status = (new StatutModel())->findByCode('en_recherche');
            if (!$status) {
                throw new RuntimeException('Le statut En recherche n’est pas configure.');
            }
            $formModel->update($formulaireId, [
                'statut_id' => (int) $status['id'],
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

        $last = $missionModel->derniereTerminee($formulaireId);
        $formulaire = $formModel->find($formulaireId);
        $statusCode = $last
            ? match ((string) ($last['resultat_code'] ?? '')) {
                'retrouve' => 'retrouve',
                'a_verifier' => 'a_verifier',
                default => 'introuvable',
            }
            : ((int) ($formulaire['cycle_suivi'] ?? 1) > 1 ? 'a_verifier' : 'introuvable');
        $status = (new StatutModel())->findByCode($statusCode);
        if (!$status) {
            throw new RuntimeException('Le statut de reprise du dossier n’est pas configure.');
        }
        $formModel->update($formulaireId, [
            'statut_id' => (int) $status['id'],
            'localisation_id' => $last ? (int) $last['localisation_id'] : null,
            'responsable_id' => $last ? (int) $last['responsable_id'] : null,
            'date_echeance_recherche' => null,
            'date_recherche' => $last['date_recherche'] ?? null,
            'resultat' => $last['resultat'] ?? '',
            'observations' => $last['observations'] ?? '',
            'priorite' => $last['priorite'] ?? 'Normale',
        ]);
    }

    private function statutLabel(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }
        $row = (new StatutModel())->find($id);
        return $row['libelle'] ?? null;
    }

    private function userLabel(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }
        $row = (new UserModel())->find($id);
        return $row ? trim($row['nom'] . ' ' . $row['prenoms']) : null;
    }

    private function notifierResponsable(array $data, int $formulaireId, bool $reassignment = false): void
    {
        if (empty($data['responsable_id'])) {
            return;
        }
        $formulaire = (new FormulaireModel())->findWithRelations($formulaireId);
        $user = (new UserModel())->find((int) $data['responsable_id']);
        if (!$formulaire || !$user || (int) $user['actif'] !== 1) {
            return;
        }

        $notif = new NotificationModel();
        $localisation = trim((string) ($formulaire['localisation_libelle'] ?? ''));
        $echeance = trim((string) ($formulaire['date_echeance_recherche'] ?? ''));
        $details = $localisation !== '' ? " dans {$localisation}" : '';
        if ($echeance !== '') {
            $details .= ' avant le ' . (new DateTimeImmutable($echeance))->format('d/m/Y');
        }
        $notif->creer(
            (int) $data['responsable_id'],
            $reassignment ? 'Recherche réaffectée' : 'Nouvelle recherche affectée',
            "Le formulaire {$formulaire['numero_auto']} vous a été affecté pour recherche{$details}.",
            'rappel',
            url('formulaires/voir/' . $formulaireId)
        );

        try {
            (new AppMailer())->sendAssignment(
                $user,
                $formulaire,
                url('formulaires/voir/' . $formulaireId),
                $reassignment
            );
            Logger::log(Auth::id(), 'email', "E-mail d'affectation envoye a l'utilisateur #{$user['id']} pour le formulaire #{$formulaireId}");
        } catch (Throwable $e) {
            // L'affectation metier reste valide meme si SMTP est temporairement
            // indisponible ; la notification interne est deja enregistree.
            error_log("[RISFM Mail] Affectation formulaire #{$formulaireId} : " . $e->getMessage());
            Logger::log(Auth::id(), 'email', "Echec de l'e-mail d'affectation a l'utilisateur #{$user['id']} pour le formulaire #{$formulaireId}");
        }
    }

    private function notifierMission(int $missionId, bool $reassignment = false): void
    {
        $mission = (new MissionRechercheModel())->findWithRelations($missionId);
        if (!$mission || empty($mission['responsable_id'])) {
            return;
        }
        $user = (new UserModel())->find((int) $mission['responsable_id']);
        $formulaire = (new FormulaireModel())->findWithRelations((int) $mission['formulaire_id']);
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
        (new NotificationModel())->creer(
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
}
