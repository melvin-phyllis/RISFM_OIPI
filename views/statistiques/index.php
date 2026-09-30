<?php
use App\Core\Auth;
use App\Core\Permission;
?>
<?php
$totalFormulaires = (int) ($kpi['total_formulaires'] ?? 0);
$totalResolus = (int) ($kpi['total_resolus'] ?? 0);
$totalRestants = (int) ($kpi['total_restants'] ?? 0);
$totalRetrouves = (int) ($kpi['total_retrouves'] ?? 0);
$totalNumerises = (int) ($kpi['total_numerises'] ?? 0);
$totalSaisis = (int) ($kpi['total_saisis'] ?? 0);
$missionsActives = (int) ($kpi['missions_actives'] ?? 0);
$missionsEnRetard = (int) ($kpi['missions_en_retard'] ?? 0);
$dossiersUrgents = (int) ($kpi['dossiers_urgents'] ?? 0);
$dossiersNonAssignes = (int) ($kpi['dossiers_non_assignes'] ?? 0);
$delaiMoyen = $kpi['delai_moyen_resolution'] ?? null;
$tauxResolution = pct($totalResolus, $totalFormulaires);
$missionsTerminees = (int) ($statsMissions['total_terminees'] ?? 0);
$missionsReussies = (int) ($statsMissions['total_reussies'] ?? 0);
$missionsInfructueuses = (int) ($statsMissions['total_infructueuses'] ?? 0);
$missionsAVerifier = (int) ($statsMissions['total_a_verifier'] ?? 0);
$tauxSuccesMissions = $statsMissions['taux_succes'] !== null ? (float) $statsMissions['taux_succes'] : null;
$delaiMoyenMission = $statsMissions['delai_moyen_jours'] !== null ? (float) $statsMissions['delai_moyen_jours'] : null;
$activeFilterCount = count(array_filter($filters, static fn ($value): bool => $value !== '' && $value !== 0));
$options = $filterOptions;

$statisticsExportFilters = array_filter($filters, static fn ($value): bool => $value !== '' && $value !== 0);
$statisticsExportUrl = url('exports/statistiques/excel')
    . ($statisticsExportFilters !== [] ? '?' . http_build_query($statisticsExportFilters, '', '&', PHP_QUERY_RFC3986) : '');

$statusRows = array_values(array_filter(
    $statsStatut,
    static fn (array $row): bool => (int) ($row['total_formulaires'] ?? 0) > 0
));

$maxBacklogAge = max(1, ...array_map(
    static fn (array $row): int => (int) ($row['total_ouverts'] ?? 0),
    $statsAnciennete ?: [['total_ouverts' => 0]]
));
?>

<div class="oipi-dashboard statistics-analytics-shell">
    <header class="oipi-dashboard-hero statistics-analytics-hero" aria-labelledby="statistics-page-title">
        <div class="oipi-dashboard-hero-copy">
            <h1 id="statistics-page-title">Statistiques</h1>
            <p>Comparez les tendances, les délais et la performance réelle des recherches.</p>
        </div>
        <div class="oipi-dashboard-hero-actions no-print">
            <div class="oipi-dashboard-campaign-rate" aria-label="Taux de résolution : <?= $tauxResolution ?> pour cent">
                <span><?= $activeFilterCount > 0 ? 'Périmètre filtré' : 'Résolution globale' ?></span>
                <strong><?= $tauxResolution ?><small>%</small></strong>
                <div class="oipi-dashboard-progress"><span style="width: <?= min(100, $tauxResolution) ?>%"></span></div>
            </div>
            <div class="oipi-dashboard-action-row statistics-hero-actions">
                <button type="button" class="btn btn-outline-secondary risfm-filter-launcher statistics-filter-launcher" data-toggle="modal" data-target="#statistics-filter-modal">
                    <i class="fas fa-filter mr-1" aria-hidden="true"></i> Filtres
                    <?php if ($activeFilterCount > 0): ?><span class="risfm-filter-count statistics-filter-count"><?= $activeFilterCount ?></span><?php endif; ?>
                </button>
                <?php if (Permission::has((string) Auth::role(), 'formulaires.export')): ?>
                    <a href="<?= e($statisticsExportUrl) ?>" class="btn btn-outline-success"><i class="fas fa-file-excel mr-1" aria-hidden="true"></i> Excel</a>
                <?php endif; ?>
                <button type="button" onclick="window.print()" class="btn btn-success"><i class="fas fa-print mr-1" aria-hidden="true"></i> Imprimer / PDF</button>
            </div>
        </div>
    </header>

    <?php if ($filterError): ?>
        <div class="alert alert-warning no-print statistics-filter-error"><i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i><?= e($filterError) ?></div>
    <?php endif; ?>

    <section class="oipi-dashboard-kpis statistics-analytics-kpis" aria-label="Indicateurs statistiques principaux">
        <div class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-bullseye" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Taux de résolution</span><strong><?= $tauxResolution ?> %</strong><small><?= $totalResolus ?> sur <?= $totalFormulaires ?> dossiers</small>
        </div>
        <div class="oipi-dashboard-kpi is-orange">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-hourglass-half" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Délai moyen de résolution</span><strong><?= $delaiMoyen !== null ? e((string) $delaiMoyen) : '—' ?></strong><small><?= $delaiMoyen !== null ? 'jours par dossier résolu' : 'Aucun délai calculable' ?></small>
        </div>
        <div class="oipi-dashboard-kpi is-green">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-crosshairs" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Efficacité des recherches</span><strong><?= $tauxSuccesMissions !== null ? e((string) $tauxSuccesMissions) . ' %' : '—' ?></strong><small title="<?= $missionsInfructueuses ?> infructueuse(s), <?= $missionsAVerifier ?> à vérifier"><?= $missionsReussies ?> positive(s) sur <?= $missionsTerminees ?> terminée(s)</small>
        </div>
        <div class="oipi-dashboard-kpi is-soft">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Missions terminées</span><strong><?= $missionsTerminees ?></strong><small><?= $delaiMoyenMission !== null ? e((string) $delaiMoyenMission) . ' jours en moyenne' : 'Aucune durée disponible' ?></small>
        </div>
        <div class="oipi-dashboard-kpi is-dark">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-layer-group" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Stock encore ouvert</span><strong><?= $totalRestants ?></strong><small><?= $dossiersUrgents ?> urgent(s) · <?= $dossiersNonAssignes ?> sans mission</small>
        </div>
    </section>

    <section class="oipi-dashboard-chart-grid statistics-main-charts" aria-label="Graphiques principaux">
        <article class="oipi-dashboard-panel oipi-dashboard-panel-wide">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Flux mensuels du registre</h2><p>Comparez les dossiers ajoutés aux premières résolutions.</p></div>
                <span class="oipi-dashboard-period"><i class="far fa-calendar-alt" aria-hidden="true"></i> Périmètre courant</span>
            </header>
            <div class="oipi-dashboard-chart oipi-dashboard-chart-line">
                <canvas id="statisticsMonthlyChart" aria-label="Comparaison mensuelle des ajouts et résolutions"></canvas>
                <div class="oipi-dashboard-chart-empty"><i class="fas fa-chart-line" aria-hidden="true"></i><span>Aucun flux mensuel sur ce périmètre.</span></div>
            </div>
        </article>

        <article class="oipi-dashboard-panel">
            <header class="oipi-dashboard-panel-header"><div><h2>Efficacité par type de titre</h2><p>Taux de résolution et volume analysé par catégorie.</p></div></header>
            <div class="oipi-dashboard-chart statistics-type-chart">
                <canvas id="statisticsTypeChart" aria-label="Taux de résolution par type de titre"></canvas>
                <div class="oipi-dashboard-chart-empty"><i class="fas fa-chart-bar" aria-hidden="true"></i><span>Aucune catégorie à comparer.</span></div>
            </div>
        </article>
    </section>

    <section class="oipi-dashboard-decision-grid statistics-decision-grid" aria-label="Analyse opérationnelle">
        <article class="oipi-dashboard-panel oipi-dashboard-decision-card">
            <header class="oipi-dashboard-panel-header"><div><h2>Parcours de finalisation</h2><p>Retrouvé → Numérisé → Saisi.</p></div><span class="statistics-delay"><?= $delaiMoyen !== null ? e((string) $delaiMoyen) . ' j moy.' : 'Délai indisponible' ?></span></header>
            <div class="oipi-dashboard-bars">
                <?php foreach ([
                    ['Retrouvés', $totalRetrouves, pct($totalRetrouves, $totalFormulaires), 'is-orange'],
                    ['Numérisés', $totalNumerises, pct($totalNumerises, $totalFormulaires), 'is-green-soft'],
                    ['Saisis', $totalSaisis, pct($totalSaisis, $totalFormulaires), 'is-green'],
                ] as [$label, $value, $percentage, $tone]): ?>
                    <div class="oipi-dashboard-bar-row">
                        <div><span><?= e($label) ?></span><strong><?= $value ?> <small><?= $percentage ?>%</small></strong></div>
                        <div class="oipi-dashboard-bar-track"><span class="<?= e($tone) ?>" style="width: <?= min(100, $percentage) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="oipi-dashboard-panel oipi-dashboard-decision-card">
            <header class="oipi-dashboard-panel-header"><div><h2>Ancienneté du stock ouvert</h2><p>Âge des titres encore non résolus.</p></div></header>
            <?php if ($statsAnciennete !== []): ?>
                <div class="statistics-age-list">
                    <?php foreach ($statsAnciennete as $ageRow): ?>
                        <?php $ageTotal = (int) $ageRow['total_ouverts']; ?>
                        <div class="statistics-age-row">
                            <div><span><?= e((string) $ageRow['tranche']) ?></span><strong><?= $ageTotal ?><small><?= (int) $ageRow['total_urgents'] ?> urgent(s)</small></strong></div>
                            <div><span style="width: <?= pct($ageTotal, $maxBacklogAge) ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="oipi-dashboard-empty"><i class="fas fa-check-circle" aria-hidden="true"></i><span>Aucun dossier ouvert dans ce périmètre.</span></div>
            <?php endif; ?>
        </article>

        <article class="oipi-dashboard-panel oipi-dashboard-decision-card">
            <header class="oipi-dashboard-panel-header"><div><h2>Priorités opérationnelles</h2><p>Indicateurs expliquant les dossiers ouverts.</p></div></header>
            <div class="oipi-dashboard-priorities">
                <?php foreach ([
                    ['Missions actives', $missionsActives, 'fas fa-search-location', 'is-green'],
                    ['Échéances dépassées', $missionsEnRetard, 'fas fa-clock', 'is-critical'],
                    ['Dossiers urgents', $dossiersUrgents, 'fas fa-exclamation-triangle', 'is-orange'],
                    ['Dossiers sans mission', $dossiersNonAssignes, 'fas fa-user-slash', 'is-neutral'],
                ] as [$label, $value, $icon, $tone]): ?>
                    <a href="<?= url('formulaires') ?>" class="oipi-dashboard-priority <?= e($tone) ?>"><span><i class="<?= e($icon) ?>" aria-hidden="true"></i><?= e($label) ?></span><strong><?= $value ?></strong></a>
                <?php endforeach; ?>
            </div>
        </article>
    </section>

    <section class="statistics-performance-grid" aria-label="Performance des recherches">
        <article class="oipi-dashboard-panel statistics-performance-panel">
            <header class="oipi-dashboard-panel-header"><div><h2>Performance des responsables</h2><p>Charge actuelle, missions terminées et taux de succès.</p></div></header>
            <?php if ($statsResponsables !== []): ?>
                <div class="statistics-performance-list">
                    <?php foreach ($statsResponsables as $responsableRow): ?>
                        <?php $successRate = $responsableRow['taux_succes'] !== null ? (float) $responsableRow['taux_succes'] : 0; ?>
                        <div class="statistics-performance-row">
                            <div class="statistics-performance-heading"><span><?= e((string) $responsableRow['responsable']) ?></span><strong><?= $responsableRow['taux_succes'] !== null ? e((string) $responsableRow['taux_succes']) . ' %' : '—' ?></strong></div>
                            <div class="statistics-performance-meta"><span><?= (int) $responsableRow['missions_actives'] ?> active(s)</span><span><?= (int) $responsableRow['missions_terminees'] ?> terminée(s)</span><span><?= (int) $responsableRow['missions_reussies'] ?> positive(s)</span></div>
                            <div class="statistics-performance-track"><span style="width: <?= min(100, $successRate) ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="oipi-dashboard-empty"><i class="fas fa-users" aria-hidden="true"></i><span>Aucune mission à analyser par responsable.</span></div>
            <?php endif; ?>
        </article>

        <article class="oipi-dashboard-panel statistics-performance-panel">
            <header class="oipi-dashboard-panel-header"><div><h2>Efficacité des localisations</h2><p>Endroits inspectés et formulaires effectivement retrouvés.</p></div></header>
            <?php if ($statsLocalisations !== []): ?>
                <div class="statistics-performance-list">
                    <?php foreach ($statsLocalisations as $locationRow): ?>
                        <?php $locationRate = $locationRow['taux_succes'] !== null ? (float) $locationRow['taux_succes'] : 0; ?>
                        <div class="statistics-performance-row">
                            <div class="statistics-performance-heading"><span><?= e((string) $locationRow['localisation']) ?></span><strong><?= $locationRow['taux_succes'] !== null ? e((string) $locationRow['taux_succes']) . ' %' : '—' ?></strong></div>
                            <div class="statistics-performance-meta"><span><?= (int) $locationRow['missions_terminees'] ?> inspection(s)</span><span><?= (int) $locationRow['formulaires_retrouves'] ?> retrouvée(s)</span><span><?= (int) $locationRow['missions_actives'] ?> active(s)</span></div>
                            <div class="statistics-performance-track is-orange"><span style="width: <?= min(100, $locationRate) ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="oipi-dashboard-empty"><i class="fas fa-map-marker-alt" aria-hidden="true"></i><span>Aucune localisation inspectée dans ce périmètre.</span></div>
            <?php endif; ?>
        </article>

        <article class="oipi-dashboard-panel statistics-status-panel">
            <header class="oipi-dashboard-panel-header"><div><h2>Répartition par statut</h2><p>Photographie actuelle du périmètre.</p></div></header>
            <div class="statistics-status-analysis">
                <div class="oipi-dashboard-chart oipi-dashboard-chart-donut">
                    <canvas id="statisticsStatusChart" aria-label="Répartition des dossiers par statut"></canvas>
                    <div class="oipi-dashboard-donut-total"><strong><?= $totalFormulaires ?></strong><span>dossiers</span></div>
                    <div class="oipi-dashboard-chart-empty"><i class="fas fa-chart-pie" aria-hidden="true"></i><span>Aucune donnée disponible.</span></div>
                </div>
                <div class="oipi-dashboard-status-legend" aria-label="Légende des statuts">
                    <?php foreach ($statusRows as $index => $status): ?>
                        <div><span class="oipi-dashboard-legend-dot" data-statistics-status-index="<?= $index ?>"></span><span><?= e((string) $status['statut']) ?></span><strong><?= (int) $status['total_formulaires'] ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>
    </section>

    <section class="oipi-dashboard-panel statistics-details-panel">
        <button type="button" class="statistics-details-toggle" data-toggle="collapse" data-target="#statistics-details" aria-expanded="false" aria-controls="statistics-details">
            <span><i class="fas fa-table" aria-hidden="true"></i><strong>Données détaillées</strong><small>Consultez les résultats par année et par type de titre.</small></span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
        </button>
        <div id="statistics-details" class="collapse">
            <div class="statistics-details-grid">
                <div class="statistics-detail-table">
                    <header><h2>Par année du titre</h2><p>Année du titre, pas année de traitement.</p></header>
                    <div class="oipi-dashboard-table-scroll">
                        <table class="oipi-dashboard-table">
                            <thead><tr><th>Année</th><th>Total</th><th>Résolus</th><th>À traiter</th><th>Taux</th></tr></thead>
                            <tbody>
                            <?php foreach ($statsAnnee as $row): ?><tr><td><?= (int) $row['annee'] ?></td><td><?= (int) $row['total_formulaires'] ?></td><td><?= (int) $row['total_resolus'] ?></td><td><?= (int) $row['total_restants'] ?></td><td><?= e((string) $row['taux_resolution']) ?> %</td></tr><?php endforeach; ?>
                            <?php if ($statsAnnee === []): ?><tr><td colspan="5" class="oipi-dashboard-table-empty">Aucune donnée.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="statistics-detail-table">
                    <header><h2>Par type de titre</h2><p>Volume et résolution par catégorie.</p></header>
                    <div class="oipi-dashboard-table-scroll">
                        <table class="oipi-dashboard-table">
                            <thead><tr><th>Type</th><th>Total</th><th>Résolus</th><th>À traiter</th><th>Taux</th></tr></thead>
                            <tbody>
                            <?php foreach ($statsType as $row): ?><tr><td><?= e((string) $row['type_titre']) ?></td><td><?= (int) $row['total_formulaires'] ?></td><td><?= (int) $row['total_resolus'] ?></td><td><?= (int) $row['total_restants'] ?></td><td><?= e((string) $row['taux_resolution']) ?> %</td></tr><?php endforeach; ?>
                            <?php if ($statsType === []): ?><tr><td colspan="5" class="oipi-dashboard-table-empty">Aucune donnée.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<div class="modal fade risfm-filter-modal statistics-filter-modal no-print" id="statistics-filter-modal" tabindex="-1" role="dialog" aria-labelledby="statistics-filter-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="get" action="<?= url('statistiques') ?>">
                <div class="modal-header risfm-filter-modal-header statistics-filter-modal-header">
                    <div>
                        <span class="statistics-filter-modal-icon"><i class="fas fa-filter" aria-hidden="true"></i></span>
                        <div>
                            <h2 class="modal-title" id="statistics-filter-modal-title">Filtres d’analyse</h2>
                            <p>Définissez le périmètre à comparer dans tous les indicateurs et graphiques.</p>
                        </div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <?php if ($activeFilterCount > 0): ?>
                        <div class="statistics-active-filter-note"><i class="fas fa-info-circle" aria-hidden="true"></i><span><?= $activeFilterCount ?> filtre(s) actuellement appliqué(s).</span></div>
                    <?php endif; ?>
                    <div class="risfm-filter-grid statistics-filter-modal-grid">
                        <div class="form-group">
                            <label for="stats-annee">Année du titre</label>
                            <select id="stats-annee" name="annee" class="form-control">
                                <option value="">Toutes les années</option>
                                <?php foreach ($options['annees'] as $option): ?><option value="<?= (int) $option['annee'] ?>" <?= (int) $filters['annee'] === (int) $option['annee'] ? 'selected' : '' ?>><?= (int) $option['annee'] ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="stats-type">Type de titre</label>
                            <select id="stats-type" name="type_titre_id" class="form-control">
                                <option value="">Tous les types</option>
                                <?php foreach ($options['types'] as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (int) $filters['type_titre_id'] === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['libelle']) ?><?= (int) $option['actif'] !== 1 ? ' (inactif)' : '' ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="stats-statut">Statut actuel</label>
                            <select id="stats-statut" name="statut_id" class="form-control">
                                <option value="">Tous les statuts</option>
                                <?php foreach ($options['statuts'] as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (int) $filters['statut_id'] === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['libelle']) ?><?= (int) $option['actif'] !== 1 ? ' (inactif)' : '' ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="stats-priorite">Priorité du dossier</label>
                            <select id="stats-priorite" name="priorite" class="form-control">
                                <option value="">Toutes les priorités</option>
                                <?php foreach (['Urgente', 'Haute', 'Normale', 'Basse'] as $priority): ?><option value="<?= e($priority) ?>" <?= $filters['priorite'] === $priority ? 'selected' : '' ?>><?= e($priority) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="stats-responsable">Responsable intervenu</label>
                            <select id="stats-responsable" name="responsable_id" class="form-control">
                                <option value="">Tous les responsables</option>
                                <?php foreach ($options['responsables'] as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (int) $filters['responsable_id'] === (int) $option['id'] ? 'selected' : '' ?>><?= e(trim($option['nom'] . ' ' . $option['prenoms'])) ?><?= (int) $option['actif'] !== 1 ? ' (inactif)' : '' ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="stats-localisation">Localisation inspectée</label>
                            <select id="stats-localisation" name="localisation_id" class="form-control">
                                <option value="">Toutes les localisations</option>
                                <?php foreach ($options['localisations'] as $option): ?><option value="<?= (int) $option['id'] ?>" <?= (int) $filters['localisation_id'] === (int) $option['id'] ? 'selected' : '' ?>><?= e($option['libelle']) ?><?= (int) $option['actif'] !== 1 ? ' (inactive)' : '' ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="stats-date-debut">Ajouté au registre du</label>
                            <input id="stats-date-debut" type="date" name="date_debut" value="<?= e((string) $filters['date_debut']) ?>" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="stats-date-fin">Ajouté au registre au</label>
                            <input id="stats-date-fin" type="date" name="date_fin" value="<?= e((string) $filters['date_fin']) ?>" class="form-control">
                        </div>
                    </div>
                    <p class="statistics-filter-modal-help"><i class="fas fa-info-circle" aria-hidden="true"></i>La période utilise la date d’ajout au registre. L’année du titre est indépendante.</p>
                </div>
                <div class="modal-footer risfm-filter-modal-footer statistics-filter-modal-footer">
                    <a href="<?= url('statistiques') ?>" class="btn btn-outline-secondary"><i class="fas fa-undo mr-1" aria-hidden="true"></i>Réinitialiser</a>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-chart-line mr-1" aria-hidden="true"></i>Appliquer les filtres</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$statisticsData = json_encode([
    'monthly' => array_values($statsMensuelles),
    'monthlyAdded' => array_values($statsAjoutsMensuels),
    'statuses' => $statusRows,
    'types' => array_values($statsType),
    'openFilters' => (bool) $filterError,
], $jsonFlags);
$__extra_js = '<script>window.RISFM_STATISTICS_DATA = ' . ($statisticsData ?: '{}') . ';</script>'
    . '<script src="' . asset('js/statistics.js') . '"></script>';
?>
