<?php
declare(strict_types=1);

/** Actions metier du cycle des missions de recherche. */
trait MissionRechercheActions
{
    public function assign(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $this->checkAssignmentRight($formulaireId);

        $model = new FormulaireModel();
        $ancien = $model->find($formulaireId);
        if (!$ancien) {
            setFlash('error', 'Formulaire introuvable.');
            $this->redirect('formulaires');
            return;
        }

        $currentStatus = (new StatutModel())->find((int) $ancien['statut_id']);
        if ((int) ($currentStatus['resolu'] ?? 0) === 1) {
            setFlash('error', 'Un dossier deja resolu ne peut pas recevoir une nouvelle affectation.');
            $this->redirect($this->assignmentRedirectTarget($formulaireId));
            return;
        }

        $inProgressStatus = (new StatutModel())->findByCode('en_recherche');
        if (!$inProgressStatus) {
            setFlash('error', 'Le statut En recherche n’est pas configure. Contactez un administrateur.');
            $this->redirect($this->assignmentRedirectTarget($formulaireId));
            return;
        }

        $rawData = $this->collectAssignment();
        if (($error = (new FormulaireMetierValidator())->validateAssignment($rawData)) !== null) {
            setFlash('error', $error);
            $this->redirect($this->assignmentRedirectTarget($formulaireId));
            return;
        }

        $alreadySearched = (new RechercheFormulaireModel())->localisationDejaRecherchee(
            $formulaireId,
            (int) $rawData['localisation_id']
        );
        if ($alreadySearched && (string) $this->input('confirmer_affectation_localisation_deja_recherchee', '') !== '1') {
            setFlash('warning', 'Cette localisation a deja ete inspectee. Confirmez la nouvelle affectation avant de continuer.');
            $this->redirect($this->assignmentRedirectTarget($formulaireId));
            return;
        }

        $data = [
            'localisation_id' => (int) $rawData['localisation_id'],
            'responsable_id' => (int) $rawData['responsable_id'],
            'date_echeance_recherche' => $rawData['date_echeance_recherche'],
            'priorite' => $rawData['priorite'],
            'statut_id' => (int) $inProgressStatus['id'],
            'date_recherche' => null,
            'resultat' => '',
            'observations' => '',
        ];

        $missionModel = new MissionRechercheModel();
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $verrou = $db->prepare('SELECT id, statut_id, cycle_suivi, est_archive FROM formulaires_manquants WHERE id = :id FOR UPDATE');
            $verrou->execute(['id' => $formulaireId]);
            $formulaireVerrouille = $verrou->fetch();
            if (!$formulaireVerrouille || (int) $formulaireVerrouille['est_archive'] === 1) {
                throw new RuntimeException('Le dossier est introuvable ou archive.');
            }
            $statutVerrouille = (new StatutModel())->find((int) $formulaireVerrouille['statut_id']);
            if ((int) ($statutVerrouille['resolu'] ?? 0) === 1) {
                throw new DomainException('Un dossier deja resolu ne peut pas recevoir une nouvelle mission.');
            }

            // Le meme verrou est pris par la desactivation et le changement de
            // role : une affectation concurrente ne peut donc jamais viser un
            // compte devenu inactif ou ineligible entre la validation et l'INSERT.
            $responsableVerrou = $db->prepare(
                'SELECT id, actif, role FROM utilisateurs WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $responsableVerrou->execute(['id' => (int) $data['responsable_id']]);
            $responsable = $responsableVerrou->fetch();
            if (
                !$responsable
                || (int) $responsable['actif'] !== 1
                || !in_array((string) $responsable['role'], ['administrateur', 'responsable', 'agent'], true)
            ) {
                throw new DomainException('Le responsable selectionne est devenu inactif ou ne peut plus conduire une recherche.');
            }

            $missionId = $missionModel->insert([
                'formulaire_id' => $formulaireId,
                'cycle_suivi' => max(1, (int) $formulaireVerrouille['cycle_suivi']),
                'localisation_id' => (int) $data['localisation_id'],
                'responsable_id' => (int) $data['responsable_id'],
                'affecte_par' => Auth::id(),
                'etat' => 'affectee',
                'priorite' => $data['priorite'],
                'date_echeance' => $data['date_echeance_recherche'],
            ]);
            $model->update($formulaireId, $data);
            $apres = $model->find($formulaireId) ?: array_merge($ancien, $data);
            if (!Logger::log(
                Auth::id(),
                'affectation',
                "Creation de la mission #{$missionId} pour {$ancien['numero_auto']}",
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
            $db->commit();
        } catch (DomainException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', $e->getMessage());
            $this->redirect($this->assignmentRedirectTarget($formulaireId));
            return;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            error_log('[Affectation] Echec SQL pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash(
                'error',
                $driverCode === 1062
                    ? 'Cette mission est deja active pour ce responsable dans cette localisation.'
                    : 'L’affectation n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.'
            );
            $this->redirect($this->assignmentRedirectTarget($formulaireId));
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Affectation] Echec pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'L’affectation n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect($this->assignmentRedirectTarget($formulaireId));
            return;
        }

        $this->notifierMission($missionId);

        setFlash('success', 'Mission de recherche ajoutee. Le responsable a ete notifie.');
        $this->redirect($this->assignmentRedirectTarget($formulaireId, '#historique-recherches'));
    }

    public function cancel(string $id): void
    {
        Auth::requireLogin();
        $missionId = (int) $id;
        $missionModel = new MissionRechercheModel();
        $initialMission = $missionModel->findWithRelations($missionId);
        if (!$initialMission) {
            setFlash('error', 'Mission de recherche introuvable.');
            $this->redirect('formulaires');
            return;
        }
        $formulaireId = (int) $initialMission['formulaire_id'];
        $this->checkAssignmentRight($formulaireId);
        $reason = Security::cleanString($this->input('annulation_motif', ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            setFlash('error', 'Le motif d’annulation est obligatoire et doit contenir entre 10 et 500 caracteres.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $formLock = $db->prepare(
                'SELECT f.*, s.resolu AS statut_resolu
                 FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                 WHERE f.id = :id LIMIT 1 FOR UPDATE'
            );
            $formLock->execute(['id' => $formulaireId]);
            $formulaire = $formLock->fetch();
            $mission = $missionModel->findWithRelations($missionId, true);
            if (!$formulaire || (int) $formulaire['est_archive'] === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }
            if ((int) $formulaire['statut_resolu'] === 1) {
                throw new DomainException('Une mission d’un dossier resolu ne peut plus etre annulee manuellement.');
            }
            if (
                !$mission
                || (int) ($mission['cycle_suivi'] ?? 0) !== (int) ($formulaire['cycle_suivi'] ?? 1)
                || !in_array((string) $mission['etat'], ['affectee', 'en_cours'], true)
            ) {
                throw new DomainException('Cette mission est deja terminee ou annulee.');
            }
            if (!$missionModel->annulerMission($missionId, (int) Auth::id(), $reason)) {
                throw new RuntimeException('La mission n’a pas pu etre annulee.');
            }
            $this->synchronizeMissionSummary($formulaireId);
            if (!Logger::log(
                Auth::id(),
                'annulation_mission',
                "Annulation de la mission #{$missionId} pour {$formulaire['numero_auto']}",
                null,
                'mission_recherche',
                $missionId,
                ['etat' => $mission['etat'], 'responsable_id' => (int) $mission['responsable_id']],
                ['etat' => 'annulee', 'motif' => $reason]
            )) {
                throw new RuntimeException('L’annulation ne peut pas etre validee sans sa trace d’audit.');
            }
            $db->commit();
        } catch (DomainException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Mission] Echec annulation #' . $missionId . ' : ' . $e->getMessage());
            setFlash('error', 'La mission n’a pas pu etre annulee. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        (new NotificationModel())->creer(
            (int) $initialMission['responsable_id'],
            'Mission de recherche annulee',
            "La mission sur le formulaire {$initialMission['numero_auto']} a ete annulee. Motif : {$reason}",
            'info',
            url('formulaires/voir/' . $formulaireId . '#mission-' . $missionId)
        );
        setFlash('success', 'Mission annulee. Le responsable a ete informe et l’historique est conserve.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#missions-recherche');
    }

    public function reassign(string $id): void
    {
        Auth::requireLogin();
        $missionId = (int) $id;
        $missionModel = new MissionRechercheModel();
        $initialMission = $missionModel->findWithRelations($missionId);
        if (!$initialMission) {
            setFlash('error', 'Mission de recherche introuvable.');
            $this->redirect('formulaires');
            return;
        }
        $formulaireId = (int) $initialMission['formulaire_id'];
        $this->checkAssignmentRight($formulaireId);

        $newResponsibleId = (int) $this->input('reaffectation_responsable_id', 0);
        $deadline = Security::cleanString($this->input('reaffectation_date_echeance', '')) ?: null;
        $priority = Security::cleanString($this->input('reaffectation_priorite', ''));
        if ($newResponsibleId === (int) $initialMission['responsable_id']) {
            setFlash('error', 'Selectionnez un nouveau responsable different de l’actuel.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }
        $assignmentData = [
            'localisation_id' => (string) $initialMission['localisation_id'],
            'responsable_id' => (string) $newResponsibleId,
            'date_echeance_recherche' => $deadline,
            'priorite' => $priority,
        ];
        if (($error = (new FormulaireMetierValidator())->validateAssignment($assignmentData)) !== null) {
            setFlash('error', $error);
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        $db = Database::getConnection();
        $newMissionId = 0;
        try {
            $db->beginTransaction();
            $formLock = $db->prepare(
                'SELECT f.*, s.resolu AS statut_resolu
                 FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                 WHERE f.id = :id LIMIT 1 FOR UPDATE'
            );
            $formLock->execute(['id' => $formulaireId]);
            $formulaire = $formLock->fetch();
            $mission = $missionModel->findWithRelations($missionId, true);
            if (!$formulaire || (int) $formulaire['est_archive'] === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }
            if ((int) $formulaire['statut_resolu'] === 1) {
                throw new DomainException('Une mission d’un dossier resolu ne peut plus etre reaffectee.');
            }
            if (
                !$mission
                || (int) ($mission['cycle_suivi'] ?? 0) !== (int) ($formulaire['cycle_suivi'] ?? 1)
                || !in_array((string) $mission['etat'], ['affectee', 'en_cours'], true)
            ) {
                throw new DomainException('Cette mission est deja terminee ou annulee.');
            }

            $userLock = $db->prepare(
                'SELECT id, nom, prenoms, actif, role FROM utilisateurs WHERE id = :id LIMIT 1 FOR UPDATE'
            );
            $userLock->execute(['id' => $newResponsibleId]);
            $newResponsible = $userLock->fetch();
            if (!$newResponsible
                || (int) $newResponsible['actif'] !== 1
                || !in_array((string) $newResponsible['role'], ['administrateur', 'responsable', 'agent'], true)
            ) {
                throw new DomainException('Le nouveau responsable est inactif ou ne peut pas conduire une recherche.');
            }

            $oldResponsibleName = trim((string) ($mission['responsable_nom'] ?? 'Responsable précédent'));
            $newResponsibleName = trim((string) (($newResponsible['nom'] ?? '') . ' ' . ($newResponsible['prenoms'] ?? '')));
            $reason = 'Transfert de ' . $oldResponsibleName . ' vers ' . $newResponsibleName;

            if (!$missionModel->annulerMission(
                $missionId,
                (int) Auth::id(),
                'Reaffectation : ' . $reason
            )) {
                throw new RuntimeException('L’ancienne mission n’a pas pu etre cloturee.');
            }
            $newMissionId = $missionModel->insert([
                'mission_parent_id' => $missionId,
                'formulaire_id' => $formulaireId,
                'cycle_suivi' => max(1, (int) ($mission['cycle_suivi'] ?? $formulaire['cycle_suivi'] ?? 1)),
                'localisation_id' => (int) $mission['localisation_id'],
                'responsable_id' => $newResponsibleId,
                'affecte_par' => Auth::id(),
                'etat' => 'affectee',
                'priorite' => $priority,
                'date_echeance' => $deadline,
            ]);
            $inProgress = (new StatutModel())->findByCode('en_recherche');
            if (!$inProgress) {
                throw new RuntimeException('Le statut En recherche n’est pas configure.');
            }
            (new FormulaireModel())->update($formulaireId, [
                'localisation_id' => (int) $mission['localisation_id'],
                'responsable_id' => $newResponsibleId,
                'statut_id' => (int) $inProgress['id'],
                'date_echeance_recherche' => $deadline,
                'date_recherche' => null,
                'resultat' => '',
                'observations' => '',
                'priorite' => $priority,
            ]);
            if (!Logger::log(
                Auth::id(),
                'reaffectation',
                "Reaffectation de la mission #{$missionId} vers la mission #{$newMissionId}",
                null,
                'mission_recherche',
                $newMissionId,
                [
                    'mission_id' => $missionId,
                    'responsable_id' => (int) $mission['responsable_id'],
                    'etat' => $mission['etat'],
                ],
                [
                    'mission_id' => $newMissionId,
                    'mission_parent_id' => $missionId,
                    'responsable_id' => $newResponsibleId,
                    'motif' => $reason,
                ]
            )) {
                throw new RuntimeException('La reaffectation ne peut pas etre validee sans sa trace d’audit.');
            }
            $db->commit();
        } catch (DomainException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Mission] Echec reaffectation #' . $missionId . ' : ' . $e->getMessage());
            setFlash(
                'error',
                (int) ($e->errorInfo[1] ?? 0) === 1062
                    ? 'Ce responsable possede deja une mission active dans cette localisation.'
                    : 'La mission n’a pas pu etre reaffectee.'
            );
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Mission] Echec reaffectation #' . $missionId . ' : ' . $e->getMessage());
            setFlash('error', 'La mission n’a pas pu etre reaffectee. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        (new NotificationModel())->creer(
            (int) $initialMission['responsable_id'],
            'Mission reaffectee',
            "Votre mission sur le formulaire {$initialMission['numero_auto']} a ete transferee a un autre responsable.",
            'info',
            url('formulaires/voir/' . $formulaireId . '#mission-' . $missionId)
        );
        $this->notifierMission($newMissionId, true);
        setFlash('success', 'Mission reaffectee. L’ancien et le nouveau responsable ont ete informes.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $newMissionId);
    }

    public function recordLegacyResult(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $missions = (new MissionRechercheModel())->activesPourFormulaire($formulaireId);
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
        $this->recordResult((string) $accessibles[0]['id']);
    }

    public function recordResult(string $id): void
    {
        Auth::requireLogin();
        $missionId = (int) $id;
        $missionModel = new MissionRechercheModel();
        $missionInitiale = $missionModel->findWithRelations($missionId);
        if (!$missionInitiale) {
            setFlash('error', 'Mission de recherche introuvable.');
            $this->redirect('formulaires');
            return;
        }
        $formulaireId = (int) $missionInitiale['formulaire_id'];
        $this->editableFormOrRedirect($formulaireId);
        if (!$this->canRecordMissionResult($missionInitiale)) {
            Permission::requireOrFail('formulaires.record_result_any');
        }

        $resultatCode = (string) $this->input('recherche_resultat_code', '');
        if (!in_array($resultatCode, ['retrouve', 'non_retrouve', 'a_verifier'], true)) {
            setFlash('error', 'Selectionnez un resultat valide pour cette mission.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        $dateRecherche = Security::cleanString($this->input('recherche_date', ''));
        $resultat = Security::cleanString($this->input('recherche_resultat', ''));
        $observations = Security::cleanString($this->input('recherche_observations', ''));
        $statusCode = match ($resultatCode) {
            'retrouve' => 'retrouve',
            'a_verifier' => 'a_verifier',
            default => 'introuvable',
        };
        $missionStatus = (new StatutModel())->findByCode($statusCode);
        if (!$missionStatus) {
            setFlash('error', 'Le statut correspondant au resultat n’est pas configure.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        $rawData = [
            'localisation_id' => (string) $missionInitiale['localisation_id'],
            'responsable_id' => (string) $missionInitiale['responsable_id'],
            'statut_id' => (string) $missionStatus['id'],
            'date_recherche' => $dateRecherche,
            'resultat' => $resultat,
            'observations' => $observations,
        ];
        if (($error = (new FormulaireMetierValidator())->validateResearch($rawData, null, $missionStatus)) !== null) {
            setFlash('error', $error);
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        $model = new FormulaireModel();
        $historyModel = new RechercheFormulaireModel();
        $db = Database::getConnection();
        $missionsAnnulees = [];
        try {
            $db->beginTransaction();
            $verrouFormulaire = $db->prepare('SELECT * FROM formulaires_manquants WHERE id = :id FOR UPDATE');
            $verrouFormulaire->execute(['id' => $formulaireId]);
            $ancien = $verrouFormulaire->fetch();
            $mission = $missionModel->findWithRelations($missionId, true);
            if (
                !$ancien
                || !$mission
                || (int) ($mission['cycle_suivi'] ?? 0) !== (int) ($ancien['cycle_suivi'] ?? 1)
                || !in_array($mission['etat'], ['affectee', 'en_cours'], true)
            ) {
                throw new DomainException('Cette mission a deja ete cloturee ou annulee.');
            }

            $missionModel->update($missionId, [
                'etat' => 'terminee',
                'resultat_code' => $resultatCode,
                'resultat' => $resultat,
                'observations' => $observations,
                'date_recherche' => $dateRecherche,
                'date_cloture' => date('Y-m-d H:i:s'),
                'cloture_par' => Auth::id(),
            ]);

            if ($resultatCode === 'retrouve') {
                $missionsAnnulees = $missionModel->autresActives($formulaireId, $missionId);
                $missionModel->annulerAutresActives($formulaireId, $missionId, (int) Auth::id());
            }

            $globalStatus = $missionStatus;
            if ($resultatCode !== 'retrouve') {
                if ($missionModel->aDesMissionsActives($formulaireId)) {
                    $globalStatus = (new StatutModel())->findByCode('en_recherche') ?: $globalStatus;
                } elseif ($resultatCode === 'non_retrouve' && $missionModel->aUnResultatAVerifier($formulaireId)) {
                    $globalStatus = (new StatutModel())->findByCode('a_verifier') ?: $globalStatus;
                }
            }

            $snapshot = [
                'mission_id' => $missionId,
                'localisation_id' => (int) $mission['localisation_id'],
                'responsable_id' => (int) $mission['responsable_id'],
                'statut_id' => (int) $missionStatus['id'],
                'date_recherche' => $dateRecherche,
                'resultat' => $resultat,
                'observations' => $observations,
            ];
            $historiqueId = $historyModel->enregistrer(
                $formulaireId,
                $snapshot,
                Auth::id(),
                'nouvelle_recherche'
            );
            $missionModel->update($missionId, ['recherche_historique_id' => $historiqueId]);

            if ($resultatCode === 'retrouve') {
                (new FinalisationFormulaireModel())->enregistrer(
                    $formulaireId,
                    'retrouve',
                    (int) $missionStatus['id'],
                    Auth::id(),
                    $dateRecherche,
                    $resultat
                );
            }

            $currentData = [
                'localisation_id' => (int) $mission['localisation_id'],
                'responsable_id' => (int) $mission['responsable_id'],
                'statut_id' => (int) $globalStatus['id'],
                'date_recherche' => $dateRecherche,
                'resultat' => $resultat,
                'observations' => $observations,
                'date_echeance_recherche' => null,
            ];
            $model->update($formulaireId, $currentData);
            $apres = $model->find($formulaireId) ?: array_merge($ancien, $currentData);
            if (!Logger::log(
                Auth::id(), 'recherche',
                "Resultat {$resultatCode} de la mission #{$missionId} pour {$ancien['numero_auto']}",
                null, 'mission_recherche', $missionId,
                ['etat' => $mission['etat']],
                ['etat' => 'terminee', 'resultat_code' => $resultatCode, 'formulaire_statut_id' => (int) $globalStatus['id']]
            )) {
                throw new RuntimeException('Le resultat ne peut pas etre valide sans sa trace d’audit.');
            }
            $db->commit();
        } catch (DomainException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/voir/' . $formulaireId . '#missions-recherche');
            return;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Recherche] Echec SQL pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', $this->formulaireDatabaseError($e, true));
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Recherche] Echec d\'enregistrement pour formulaire #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La recherche n\'a pas pu etre enregistree. Aucune donnee n\'a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#mission-' . $missionId);
            return;
        }

        foreach ($missionsAnnulees as $missionAnnulee) {
            (new NotificationModel())->creer(
                (int) $missionAnnulee['responsable_id'],
                'Mission cloturee automatiquement',
                "Votre mission sur le formulaire {$missionInitiale['numero_auto']} a ete cloturee : le formulaire a ete retrouve par une autre mission.",
                'info',
                url('formulaires/voir/' . $formulaireId . '#mission-' . $missionAnnulee['id'])
            );
        }

        // Notifier l'assigneur (affecte_par) que le formulaire a ete retrouve
        if ($resultatCode === 'retrouve') {
            $this->notifierAssigneurResultatRetrouve($missionId, $formulaireId);
        }

        setFlash('success', $resultatCode === 'retrouve'
            ? 'Formulaire retrouve. Les autres missions actives ont ete cloturees.'
            : 'Resultat de la mission ajoute a l’historique.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#historique-recherches');
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

    /**
     * Notifie l'utilisateur qui a assigne la mission (affecte_par) que le
     * formulaire a ete retrouve. Envoie une notification interne et un e-mail.
     * L'echec de l'envoi est journalise mais ne bloque pas le workflow.
     */
    private function notifierAssigneurResultatRetrouve(int $missionId, int $formulaireId): void
    {
        $mission = (new MissionRechercheModel())->findWithRelations($missionId);
        if (!$mission || empty($mission['affecte_par'])) {
            return;
        }

        $assigneurId = (int) $mission['affecte_par'];
        $responsableId = (int) $mission['responsable_id'];

        // Ne pas notifier l'assigneur s'il est aussi celui qui a saisi le resultat
        if ($assigneurId === (int) Auth::id()) {
            return;
        }

        $assigneur = (new UserModel())->find($assigneurId);
        if (!$assigneur || (int) ($assigneur['actif'] ?? 0) !== 1) {
            return;
        }

        $responsable = (new UserModel())->find($responsableId);
        $formulaire = (new FormulaireModel())->findWithRelations($formulaireId);
        if (!$formulaire) {
            return;
        }

        $responsableNom = $responsable
            ? trim(($responsable['prenoms'] ?? '') . ' ' . ($responsable['nom'] ?? ''))
            : ($mission['responsable_nom'] ?? 'un agent');

        $urlFormulaire = url('formulaires/voir/' . $formulaireId . '#historique-recherches');

        // Notification interne
        (new NotificationModel())->creer(
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
