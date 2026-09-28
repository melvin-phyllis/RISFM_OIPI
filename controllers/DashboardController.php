<?php
declare(strict_types=1);

class DashboardController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('dashboard.view');

        $role = (string) Auth::role();
        $formulaireModel = new FormulaireModel();

        if ($role === 'administrateur') {
            $this->renderAdmin($formulaireModel);
            return;
        }

        if ($role === 'consultation') {
            $this->renderConsultation($formulaireModel);
            return;
        }

        $this->renderUser($formulaireModel);
    }

    private function renderAdmin(FormulaireModel $formulaireModel): void
    {
        $userModel = new UserModel();
        $activiteModel = new ActiviteModel();
        $connexionModel = new ConnexionModel();
        $missionModel = new MissionRechercheModel();

        $kpi = $formulaireModel->kpiGlobaux();
        // La procedure est egalement corrigee dans schema.sql et sa migration,
        // mais le modele des connexions reste la source de verite du dashboard.
        // Cela garantit le bon KPI meme avant migration d'une base existante.
        $utilisateursConnectes = $userModel->connectedCount();
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
            'statsType' => $formulaireModel->statsParType(),
            'statsStatut' => $formulaireModel->statsParStatut(),
            'statsMensuelles' => $formulaireModel->statsMensuelles(),
            'missionsKpi' => $missionModel->statsGlobales(),
            'activitesRecentes' => $activiteModel->recentes(10),
            'connexionsRecentes' => $connexionModel->dernieresConnexions(8),
            'utilisateursConnectes' => $utilisateursConnectes,
            'utilisateursActifs' => $userModel->activeCount(),
        ]);
    }

    private function renderUser(FormulaireModel $formulaireModel): void
    {
        $userId = (int) Auth::id();
        $missionModel = new MissionRechercheModel();

        $mesFormulaires = $formulaireModel->search(['mes_dossiers' => 1], ['id' => $userId], 'f.mis_a_jour_le', 'DESC', 10, 0);
        $mesStats = $missionModel->statsDossiersPourResponsable($userId);
        $missionsActives = $missionModel->activesPourResponsable($userId, 10);
        $missionsStats = $missionModel->statsActives($userId);
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

    private function renderConsultation(FormulaireModel $formulaireModel): void
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
            'kpi' => $formulaireModel->kpiGlobaux(),
            'formulairesRecents' => $formulaireModel->search([], null, 'f.mis_a_jour_le', 'DESC', 8, 0),
            'statsStatut' => $formulaireModel->statsParStatut(),
        ]);
    }
}
