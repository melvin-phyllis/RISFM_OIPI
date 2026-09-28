<?php
declare(strict_types=1);

class StatistiqueController extends Controller
{
    public function index(): void
    {
        $this->requirePermission('statistiques.view');
        $model = new FormulaireModel();
        $normalized = FormulaireModel::normaliserFiltresStatistiques($_GET);
        $filters = $normalized['filters'];
        $filterError = $normalized['error'];
        $statistics = $model->statistiquesDetaillees($filters);

        $this->render('statistiques/index', [
            '__title' => 'Statistiques du registre',
            '__active' => 'statistiques',
            '__hide_page_header' => true,
            '__subtitle' => 'Analysez les formulaires manquants, leur répartition, leur résolution et leur finalisation.',
            'filters' => $filters,
            'filterError' => $filterError,
            'filterOptions' => $model->optionsStatistiques(),
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
