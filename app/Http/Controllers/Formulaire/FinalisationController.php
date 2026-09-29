<?php
declare(strict_types=1);

namespace App\Http\Controllers\Formulaire;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Http\Requests\Formulaire\AvancerFinalisationFormRequest;
use App\Http\Requests\Formulaire\RouvrirFormulaireFormRequest;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Notification\NotificationRepository;
use App\Services\Formulaire\FinalisationService;
use DomainException;
use PDOException;
use Throwable;

/** Progression Retrouve -> Numerise -> Saisi et reouverture d'un cycle. */
class FinalisationController extends Controller
{
    public function ctrl_advance(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.finalize');
        $formulaireId = (int) $id;
        $this->editableFormOrRedirect($formulaireId);

        $data = $this->validateRequest(
            AvancerFinalisationFormRequest::class,
            'formulaires/voir/' . $formulaireId . '#finalisation-formulaire'
        );

        try {
            (new FinalisationService())->srv_avancer($formulaireId, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        } catch (PDOException $e) {
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
            error_log('[Finalisation] Echec #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'La finalisation n’a pas pu etre enregistree. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        }

        setFlash(
            'success',
            $data['finalisation_etape'] === 'numerise'
                ? 'Numerisation validee. Le formulaire peut maintenant etre marque comme saisi.'
                : 'Saisie validee. Le cycle du formulaire est maintenant complet.'
        );
        $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
    }

    public function ctrl_reopen(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.reopen');
        $formulaireId = (int) $id;
        $data = $this->validateRequest(
            RouvrirFormulaireFormRequest::class,
            'formulaires/voir/' . $formulaireId . '#finalisation-formulaire'
        );

        try {
            $lastResponsibleId = (new FinalisationService())->srv_rouvrir($formulaireId, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        } catch (Throwable $e) {
            error_log('[Finalisation] Echec reouverture #' . $formulaireId . ' : ' . $e->getMessage());
            setFlash('error', 'Le formulaire n’a pas pu etre rouvert. Aucune donnee n’a ete modifiee.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#finalisation-formulaire');
            return;
        }

        if ($lastResponsibleId !== null) {
            (new NotificationRepository())->repo_creer(
                $lastResponsibleId,
                'Formulaire rouvert',
                "Le formulaire #{$formulaireId} a ete rouvert pour verification. Motif : {$data['reouverture_motif']}",
                'alerte',
                url('formulaires/voir/' . $formulaireId)
            );
        }
        setFlash('success', 'Formulaire rouvert dans un nouveau cycle. Une nouvelle mission peut maintenant etre affectee.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#missions-recherche');
    }

    private function editableFormOrRedirect(int $formulaireId): array
    {
        $formulaire = (new FormulaireRepository())->repo_find($formulaireId);
        if (!$formulaire || (int) ($formulaire['est_archive'] ?? 0) === 1) {
            setFlash('error', 'Ce formulaire est introuvable ou archive et ne peut plus etre modifie.');
            $this->redirect('formulaires');
        }
        return $formulaire;
    }

}
