<?php
declare(strict_types=1);

namespace App\Http\Controllers\Formulaire;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Http\Requests\Formulaire\ArchiverFormulaireFormRequest;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Notification\NotificationRepository;
use App\Services\Formulaire\ArchivageService;
use Throwable;

/** Archivage reversible des dossiers, sans suppression de leur historique. */
class ArchivageController extends Controller
{
    public function ctrl_archive(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.archive');
        $formulaireId = (int) $id;
        $formulaire = (new FormulaireRepository())->repo_find($formulaireId);
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

        $data = $this->validateRequest(
            ArchiverFormulaireFormRequest::class,
            'formulaires/voir/' . $formulaireId . '#modal-archiver-formulaire'
        );

        try {
            $missionsActives = (new ArchivageService())->srv_archiver($formulaire, $data, (int) Auth::id());
        } catch (Throwable $e) {
            error_log('[Archivage] Echec #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'L’archivage n’a pas pu etre enregistre. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#modal-archiver-formulaire');
            return;
        }
        foreach ($missionsActives as $mission) {
            (new NotificationRepository())->repo_creer(
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

    public function ctrl_restore(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.archive');
        $formulaireId = (int) $id;
        $formulaire = (new FormulaireRepository())->repo_find($formulaireId);
        if (!$formulaire || (int) ($formulaire['est_archive'] ?? 0) !== 1) {
            setFlash('error', 'Formulaire archive introuvable.');
            $this->redirect('formulaires/archives');
            return;
        }

        try {
            (new ArchivageService())->srv_restaurer($formulaire, (int) Auth::id());
        } catch (Throwable $e) {
            error_log('[Archivage] Echec restauration #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La restauration n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/archives');
            return;
        }
        setFlash('success', 'Formulaire restaure dans le registre actif.');
        $this->redirect('formulaires/voir/' . $formulaireId);
    }
}
