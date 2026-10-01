<?php
declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\ReminderRunState;
use App\Http\Requests\Administration\ElementListeFormRequest;
use App\Http\Requests\Administration\UpdateParametresGenerauxFormRequest;
use App\Repositories\Administration\ParametreRepository;
use App\Repositories\Referentiel\DirectionRepository;
use App\Repositories\Referentiel\LocalisationRepository;
use App\Repositories\Referentiel\StatutRepository;
use App\Repositories\Referentiel\ServiceRepository;
use App\Repositories\Referentiel\TypeTitreRepository;
use App\Services\Administration\ParametreService;
use DomainException;
use PDOException;

class ParametreController extends Controller
{
    public function ctrl_index(): void
    {
        $this->requirePermission('parametres.manage');
        $paramRepository = new ParametreRepository();
        $statusRepository = new StatutRepository();
        $directionRepository = new DirectionRepository();
        $serviceRepository = new ServiceRepository();

        $this->render('parametres/index', [
            '__title' => 'Configuration',
            '__active' => 'parametres',
            '__hide_page_header' => true,
            'parametres' => $paramRepository->repo_tous(),
            'types' => (new TypeTitreRepository())->repo_all('ordre', 'ASC'),
            'statuts' => $statusRepository->repo_tous(),
            'workflowStatusCodes' => array_keys(StatutRepository::WORKFLOW),
            'statusWorkflowHealth' => $statusRepository->repo_workflowHealth(),
            'localisations' => (new LocalisationRepository())->repo_all('libelle', 'ASC'),
            'directions' => $directionRepository->repo_toutes(),
            'services' => $serviceRepository->repo_tous(),
            'reminderHealth' => (new ReminderRunState())->status(),
        ]);
    }

    public function ctrl_updateGeneral(): void
    {
        $this->requirePermission('parametres.manage');
        $data = $this->validateRequest(UpdateParametresGenerauxFormRequest::class, 'parametres');
        (new ParametreService())->srv_modifierGeneraux($data, (int) Auth::id());
        setFlash('success', 'Parametres generaux mis a jour.');
        $this->redirect('parametres');
    }

    public function ctrl_addListItem(string $type): void
    {
        $this->requirePermission('parametres.manage');
        $this->listeConnueOuRetour($type);
        $data = $this->validateRequest(ElementListeFormRequest::class, 'parametres#listes-metier', ['type' => $type]);

        try {
            (new ParametreService())->srv_ajouterElement($type, $data, (int) Auth::id());
            setFlash('success', 'Element ajoute.');
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
        } catch (PDOException $e) {
            setFlash('error', (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'Ce code ou ce libelle existe deja dans cette liste.'
                : 'L’element n’a pas pu etre ajoute.');
        }
        $this->redirect('parametres#listes-metier');
    }

    public function ctrl_updateListItem(string $type, string $id): void
    {
        $this->requirePermission('parametres.manage');
        $this->listeConnueOuRetour($type);
        $data = $this->validateRequest(ElementListeFormRequest::class, 'parametres#listes-metier', ['type' => $type]);

        try {
            (new ParametreService())->srv_modifierElement($type, (int) $id, $data, (int) Auth::id());
            setFlash('success', 'Element modifie.');
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
        } catch (PDOException $e) {
            setFlash('error', (int) ($e->errorInfo[1] ?? 0) === 1062
                ? 'Ce libelle existe deja dans cette liste.'
                : 'La modification n’a pas pu etre enregistree.');
        }
        $this->redirect('parametres#listes-metier');
    }

    public function ctrl_toggleListItem(string $type, string $id): void
    {
        $this->requirePermission('parametres.manage');
        $this->listeConnueOuRetour($type);

        try {
            $actif = (new ParametreService())->srv_basculerElement($type, (int) $id, (int) Auth::id());
            setFlash('success', $actif ? 'Element active.' : 'Element desactive.');
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
        }
        $this->redirect('parametres#listes-metier');
    }

    private function listeConnueOuRetour(string $type): void
    {
        if (!in_array($type, ParametreService::LISTES, true)) {
            setFlash('error', 'Liste inconnue.');
            $this->redirect('parametres');
        }
    }
}
