<?php
declare(strict_types=1);

namespace App\Http\Controllers\Pilotage;

use App\Core\Controller;
use App\Repositories\Formulaire\FormulaireRepository;

class StatistiqueController extends Controller
{
    public function ctrl_index(): void
    {
        $this->requirePermission('statistiques.view');
        $model = new FormulaireRepository();
        $normalized = FormulaireRepository::repo_normaliserFiltresStatistiques($_GET);
        $filters = $normalized['filters'];
        $filterError = $normalized['error'];
        $statistics = $model->repo_statistiquesDetaillees($filters);

        $this->render('statistiques/index', [
            '__title' => 'Statistiques du registre',
            '__active' => 'statistiques',
            '__hide_page_header' => true,
            '__subtitle' => 'Analysez les formulaires manquants, leur répartition, leur résolution et leur finalisation.',
            'filters' => $filters,
            'filterError' => $filterError,
            'filterOptions' => $model->repo_optionsStatistiques(),
            'kpi' => $statistics['kpi'],
            'statsAnnee' => $statistics['par_annee'],
            'statsType' => $statistics['par_type'],
            'statsStatut' => $statistics['par_statut'],
            'statsMensuelles' => $statistics['mensuelles'],
            'statsAjoutsMensuels' => $statistics['ajouts_mensuels'],
            'statsAnciennete' => $statistics['anciennete_stock'],
            'statsMissions' => $statistics['missions'],
            'statsResponsables' => $statistics['par_responsable'],
            'statsLocalisations' => $statistics['par_localisation'],
        ]);
    }
}
