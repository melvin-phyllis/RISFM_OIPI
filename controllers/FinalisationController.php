<?php
declare(strict_types=1);

/** Progression Retrouve -> Numerise -> Saisi et reouverture d'un cycle. */
class FinalisationController extends Controller
{
    public function advance(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.finalize');
        $formulaireId = (int) $id;
        $this->editableFormOrRedirect($formulaireId);

        $requestedStep = Security::cleanString($this->input('finalisation_etape', ''));
        $businessDate = Security::cleanString($this->input('finalisation_date', ''));
        $comment = Security::cleanString($this->input('finalisation_commentaire', ''));
        $parsedDate = $this->strictDate($businessDate);
        if ($parsedDate === null || $parsedDate > new DateTimeImmutable('today')) {
            setFlash('error', 'La date de finalisation est invalide ou situee dans le futur.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        }
        if (mb_strlen($comment) > 500) {
            setFlash('error', 'Le commentaire de finalisation ne doit pas depasser 500 caracteres.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        }

        $db = Database::getConnection();
        $model = new FormulaireModel();
        $finalisationModel = new FinalisationFormulaireModel();
        try {
            $db->beginTransaction();
            $lock = $db->prepare(
                'SELECT f.*, s.code AS statut_code, s.libelle AS statut_libelle
                 FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                 WHERE f.id = :id LIMIT 1 FOR UPDATE'
            );
            $lock->execute(['id' => $formulaireId]);
            $before = $lock->fetch();
            if (!$before || (int) ($before['est_archive'] ?? 0) === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }

            $expectedStep = $finalisationModel->nextStepForStatus((string) $before['statut_code']);
            if ($expectedStep === null) {
                throw new DomainException(
                    (string) $before['statut_code'] === 'saisi'
                        ? 'Ce formulaire est deja entierement finalise.'
                        : 'Le formulaire doit d’abord etre retrouve avant sa numerisation.'
                );
            }
            if ($requestedStep !== $expectedStep) {
                throw new DomainException('Les etapes doivent etre validees dans l’ordre : Retrouve, Numerise, puis Saisi.');
            }

            $previousStep = $expectedStep === 'numerise' ? 'retrouve' : 'numerise';
            $previous = $finalisationModel->findStep($formulaireId, $previousStep);
            $minimumDate = $previous['effectue_le'] ?? $before['date_resolution'] ?? null;
            if ($minimumDate !== null
                && $parsedDate < new DateTimeImmutable(substr((string) $minimumDate, 0, 10))
            ) {
                throw new DomainException('La date de cette etape ne peut pas preceder la date de l’etape precedente.');
            }

            $targetStatus = (new StatutModel())->findByCode($expectedStep);
            if (!$targetStatus || (int) ($targetStatus['actif'] ?? 0) !== 1) {
                throw new RuntimeException('Le statut ' . ucfirst($expectedStep) . ' n’est pas configure ou actif.');
            }
            $finalisationModel->enregistrer(
                $formulaireId,
                $expectedStep,
                (int) $targetStatus['id'],
                Auth::id(),
                $businessDate,
                $comment
            );
            $model->update($formulaireId, ['statut_id' => (int) $targetStatus['id']]);
            if (!Logger::log(
                Auth::id(),
                'finalisation',
                "Validation de l'etape {$expectedStep} pour {$before['numero_auto']}",
                null,
                'formulaire',
                $formulaireId,
                ['statut_id' => (int) $before['statut_id'], 'statut' => $before['statut_libelle']],
                [
                    'statut_id' => (int) $targetStatus['id'],
                    'statut' => $targetStatus['libelle'],
                    'etape' => $expectedStep,
                    'date' => $businessDate,
                    'commentaire' => $comment ?: null,
                ]
            )) {
                throw new RuntimeException('La finalisation ne peut pas etre validee sans sa trace d’audit.');
            }
            $db->commit();
        } catch (DomainException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Finalisation] Echec SQL #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash(
                'error',
                (int) ($e->errorInfo[1] ?? 0) === 1062
                    ? 'Cette etape a deja ete validee.'
                    : 'La finalisation n’a pas pu etre enregistree.'
            );
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Finalisation] Echec #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La finalisation n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        }

        setFlash(
            'success',
            $requestedStep === 'numerise'
                ? 'Numerisation validee. Le formulaire peut maintenant etre marque comme saisi.'
                : 'Saisie validee. Le cycle du formulaire est maintenant complet.'
        );
        $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
    }

    public function reopen(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.reopen');
        $formulaireId = (int) $id;
        $reason = Security::cleanString($this->input('reouverture_motif', ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            setFlash('error', 'Le motif de réouverture est obligatoire et doit contenir entre 10 et 500 caracteres.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        }

        $db = Database::getConnection();
        $lastResponsibleId = null;
        try {
            $db->beginTransaction();
            $lock = $db->prepare(
                'SELECT f.*, s.code AS statut_code, s.libelle AS statut_libelle
                 FROM formulaires_manquants f JOIN statuts s ON s.id = f.statut_id
                 WHERE f.id = :id LIMIT 1 FOR UPDATE'
            );
            $lock->execute(['id' => $formulaireId]);
            $before = $lock->fetch();
            if (!$before || (int) $before['est_archive'] === 1) {
                throw new DomainException('Le formulaire est introuvable ou archive.');
            }
            if (!in_array((string) $before['statut_code'], ['retrouve', 'numerise', 'saisi'], true)) {
                throw new DomainException('Seul un formulaire retrouve, numerise ou saisi peut etre rouvert.');
            }
            if ((new MissionRechercheModel())->aDesMissionsActives($formulaireId)) {
                throw new DomainException('Le dossier possede deja une mission active et ne peut pas etre rouvert.');
            }
            $verificationStatus = (new StatutModel())->findByCode('a_verifier');
            if (!$verificationStatus || (int) ($verificationStatus['actif'] ?? 0) !== 1) {
                throw new RuntimeException('Le statut A verifier n’est pas configure ou actif.');
            }

            $cycleBefore = max(1, (int) ($before['cycle_suivi'] ?? 1));
            $cycleAfter = $cycleBefore + 1;
            $update = $db->prepare(
                'UPDATE formulaires_manquants
                 SET statut_id = :statut_id, cycle_suivi = :cycle_apres,
                     date_echeance_recherche = NULL
                 WHERE id = :id'
            );
            $update->execute([
                'statut_id' => (int) $verificationStatus['id'],
                'cycle_apres' => $cycleAfter,
                'id' => $formulaireId,
            ]);
            (new ReouvertureFormulaireModel())->enregistrer(
                $formulaireId,
                $cycleBefore,
                $cycleAfter,
                (int) $before['statut_id'],
                (string) $before['statut_libelle'],
                $reason,
                Auth::id()
            );
            if (!Logger::log(
                Auth::id(),
                'reouverture',
                "Reouverture du formulaire {$before['numero_auto']} , cycle {$cycleAfter}",
                null,
                'formulaire',
                $formulaireId,
                ['statut_id' => (int) $before['statut_id'], 'cycle_suivi' => $cycleBefore],
                [
                    'statut_id' => (int) $verificationStatus['id'],
                    'cycle_suivi' => $cycleAfter,
                    'motif' => $reason,
                ]
            )) {
                throw new RuntimeException('La reouverture ne peut pas etre validee sans sa trace d’audit.');
            }
            $lastResponsibleId = !empty($before['responsable_id']) ? (int) $before['responsable_id'] : null;
            $db->commit();
        } catch (DomainException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Finalisation] Echec reouverture #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'Le formulaire n’a pas pu etre rouvert. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        }

        if ($lastResponsibleId !== null) {
            (new NotificationModel())->creer(
                $lastResponsibleId,
                'Formulaire rouvert',
                "Le formulaire #{$formulaireId} a ete rouvert pour verification. Motif : {$reason}",
                'alerte',
                url('formulaires/voir/' . $formulaireId)
            );
        }
        setFlash('success', 'Formulaire rouvert dans un nouveau cycle. Une nouvelle mission peut maintenant etre affectee.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#missions-recherche');
    }

    private function editableFormOrRedirect(int $formulaireId): array
    {
        $formulaire = (new FormulaireModel())->find($formulaireId);
        if (!$formulaire || (int) ($formulaire['est_archive'] ?? 0) === 1) {
            setFlash('error', 'Ce formulaire est introuvable ou archive et ne peut plus etre modifie.');
            $this->redirect('formulaires');
        }
        return $formulaire;
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
}
