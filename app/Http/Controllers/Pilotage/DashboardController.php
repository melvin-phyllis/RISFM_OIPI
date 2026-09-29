<?php
declare(strict_types=1);

namespace App\Http\Controllers\Pilotage;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Repositories\Administration\ActiviteRepository;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Repositories\Utilisateur\ConnexionRepository;
use App\Repositories\Utilisateur\UserRepository;

class DashboardController extends Controller
{
    public function ctrl_index(): void
    {
        $this->requirePermission('dashboard.view');

        $role = (string) Auth::role();
        $formulaireRepository = new FormulaireRepository();

        if ($role === 'administrateur') {
            $this->renderAdmin($formulaireRepository);
            return;
        }

        if ($role === 'consultation') {
            $this->renderConsultation($formulaireRepository);
            return;
        }

        $this->renderUser($formulaireRepository);
    }

    private function renderAdmin(FormulaireRepository $formulaireRepository): void
    {
        $userRepository = new UserRepository();
        $activiteRepository = new ActiviteRepository();
        $connexionRepository = new ConnexionRepository();
        $missionRepository = new MissionRechercheRepository();

        $kpi = $formulaireRepository->repo_kpiGlobaux();
        // La procedure est egalement corrigee dans schema.sql et sa migration,
        // mais le modele des connexions reste la source de verite du dashboard.
        // Cela garantit le bon KPI meme avant migration d'une base existante.
        $utilisateursConnectes = $userRepository->repo_connectedCount();
        $kpi['total_connectes'] = $utilisateursConnectes;

        $this->render('dashboard/admin', [
            '__title' => 'Tableau de bord',
            '__active' => 'dashboard',
            '__hide_page_header' => true,
            '__subtitle' => 'Vue de pilotage de la campagne nationale de recherche et de finalisation.',
            '__header_actions' => [
                [
                    'label' => 'Ouvrir le registre',
                    'url' => url('formulaires'),
                    'icon' => 'fas fa-folder-open',
                    'class' => 'btn-outline-secondary',
                ],
                [
                    'label' => 'Analyse detaillee',
                    'url' => url('statistiques'),
                    'icon' => 'fas fa-chart-line',
                    'class' => 'btn-success',
                ],
            ],
            'kpi' => $kpi,
            'statsType' => $formulaireRepository->repo_statsParType(),
            'statsStatut' => $formulaireRepository->repo_statsParStatut(),
            'statsMensuelles' => $formulaireRepository->repo_statsMensuelles(),
            'missionsKpi' => $missionRepository->repo_statsGlobales(),
            'activitesRecentes' => $activiteRepository->repo_recentes(10),
            'connexionsRecentes' => $connexionRepository->repo_dernieresConnexions(8),
            'utilisateursConnectes' => $utilisateursConnectes,
            'utilisateursActifs' => $userRepository->repo_activeCount(),
        ]);
    }

    private function renderUser(FormulaireRepository $formulaireRepository): void
    {
        $userId = (int) Auth::id();
        $missionRepository = new MissionRechercheRepository();

        $mesFormulaires = $formulaireRepository->repo_search(['mes_dossiers' => 1], ['id' => $userId], 'f.mis_a_jour_le', 'DESC', 10, 0);
        $mesStats = $missionRepository->repo_statsDossiersPourResponsable($userId);
        $missionsActives = $missionRepository->repo_activesPourResponsable($userId, 10);
        $missionsStats = $missionRepository->repo_statsActives($userId);
        $role = (string) Auth::role();

        $this->render('dashboard/user', [
            '__title' => 'Mon tableau de bord',
            '__active' => 'dashboard',
            '__hide_page_header' => true,
            '__subtitle' => 'Vos missions, priorites et echeances de recherche en un coup d’oeil.',
            '__header_actions' => [[
                'label' => 'Ouvrir le registre',
                'url' => url('formulaires'),
                'icon' => 'fas fa-folder-open',
                'class' => 'btn-success',
            ]],
            'roleLabel' => Permission::label($role),
            'mesFormulaires' => $mesFormulaires,
            'mesFormulairesTotal' => $mesStats['total_dossiers'],
            'mesFormulairesResolus' => $mesStats['total_resolus'],
            'missionsActives' => $missionsActives,
            'missionsActivesTotal' => $missionsStats['total_actives'],
            'missionsEnRetard' => $missionsStats['total_en_retard'],
            'missionsUrgentes' => $missionsStats['total_urgentes'],
            'prochaineEcheance' => $missionsStats['prochaine_echeance'],
        ]);
    }

    private function renderConsultation(FormulaireRepository $formulaireRepository): void
    {
        $this->render('dashboard/consultation', [
            '__title' => 'Tableau de bord',
            '__active' => 'dashboard',
            '__hide_page_header' => true,
            '__subtitle' => 'Consultation en lecture seule du registre national.',
            '__header_actions' => [[
                'label' => 'Consulter le registre',
                'url' => url('formulaires'),
                'icon' => 'fas fa-book-open',
                'class' => 'btn-success',
            ]],
            'kpi' => $formulaireRepository->repo_kpiGlobaux(),
            'formulairesRecents' => $formulaireRepository->repo_search([], null, 'f.mis_a_jour_le', 'DESC', 8, 0),
            'statsStatut' => $formulaireRepository->repo_statsParStatut(),
        ]);
    }
}
