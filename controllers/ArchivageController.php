<?php
declare(strict_types=1);

/** Archivage reversible des dossiers, sans suppression de leur historique. */
class ArchivageController extends Controller
{
    public function archive(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.archive');
        $formulaireId = (int) $id;
        $model = new FormulaireModel();
        $formulaire = $model->find($formulaireId);
        if (!$formulaire) {
            setFlash('error', 'Formulaire introuvable.');
            $this->redirect('formulaires');
            return;
        }
        if ((int) ($formulaire['est_archive'] ?? 0) === 1) {
            setFlash('warning', 'Ce formulaire est deja archive.');
            $this->redirect('formulaires/archives');
            return;
        }

        $motif = Security::cleanString($this->input('motif_archivage', ''));
        if (mb_strlen($motif) < 10 || mb_strlen($motif) > 500) {
            setFlash('error', 'Le motif d’archivage doit contenir entre 10 et 500 caracteres.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#modal-archiver-formulaire');
            return;
        }

        $missionsActives = (new MissionRechercheModel())->activesPourFormulaire($formulaireId);
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $model->update($formulaireId, [
                'est_archive' => 1,
                'motif_archivage' => $motif,
                'archive_par' => Auth::id(),
                'archive_le' => date('Y-m-d H:i:s'),
            ]);
            (new MissionRechercheModel())->annulerToutesActives(
                $formulaireId,
                (int) Auth::id(),
                'Dossier archive : ' . $motif
            );
            $apres = $model->find($formulaireId)
                ?: array_merge($formulaire, ['est_archive' => 1, 'motif_archivage' => $motif]);
            if (!Logger::log(
                Auth::id(),
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
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Archivage] Echec #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'L’archivage n’a pas pu etre enregistre. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#modal-archiver-formulaire');
            return;
        }
        foreach ($missionsActives as $mission) {
            (new NotificationModel())->creer(
                (int) $mission['responsable_id'],
                'Mission annulee',
                "Votre mission sur le formulaire {$formulaire['numero_auto']} a ete annulee car le dossier a ete archive.",
                'info',
                url('formulaires/voir/' . $formulaireId)
            );
        }
        setFlash('success', 'Formulaire archive sans suppression de son historique ni de ses pieces jointes.');
        $this->redirect('formulaires');
    }

    public function restore(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.archive');
        $formulaireId = (int) $id;
        $model = new FormulaireModel();
        $formulaire = $model->find($formulaireId);
        if (!$formulaire || (int) ($formulaire['est_archive'] ?? 0) !== 1) {
            setFlash('error', 'Formulaire archive introuvable.');
            $this->redirect('formulaires/archives');
            return;
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $model->update($formulaireId, [
                'est_archive' => 0,
                'motif_archivage' => null,
                'archive_par' => null,
                'archive_le' => null,
            ]);
            $apres = $model->find($formulaireId) ?: array_merge($formulaire, ['est_archive' => 0]);
            if (!Logger::log(
                Auth::id(),
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
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[Archivage] Echec restauration #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La restauration n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/archives');
            return;
        }
        setFlash('success', 'Formulaire restaure dans le registre actif.');
        $this->redirect('formulaires/voir/' . $formulaireId);
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
