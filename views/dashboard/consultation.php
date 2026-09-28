<?php
$__total = (int) ($kpi['total_formulaires'] ?? 0);
$__retrouves = (int) ($kpi['total_retrouves'] ?? 0);
$__restants = (int) ($kpi['total_restants'] ?? 0);
$__numerises = (int) ($kpi['total_numerises'] ?? 0);
$__saisis = (int) ($kpi['total_saisis'] ?? 0);
$__resolutionRate = pct($__retrouves, $__total);
$__statusRows = array_values(array_filter(
    $statsStatut,
    static fn (array $row): bool => (int) ($row['total_formulaires'] ?? 0) > 0
));
?>

<div class="oipi-dashboard oipi-dashboard-consultation">
    <header class="oipi-dashboard-hero" aria-labelledby="dashboard-title">
        <div class="oipi-dashboard-hero-copy">
            <span class="oipi-dashboard-eyebrow"><i class="fas fa-eye" aria-hidden="true"></i> Consultation en lecture seule</span>
            <h1 id="dashboard-title">Situation du registre</h1>
            <p>Consultez l’avancement de la campagne nationale sans modifier les données.</p>
        </div>
        <div class="oipi-dashboard-hero-actions">
            <div class="oipi-dashboard-campaign-rate" aria-label="Taux de résolution global : <?= $__resolutionRate ?> pour cent">
                <span>Résolution globale</span>
                <strong><?= $__resolutionRate ?><small>%</small></strong>
                <div class="oipi-dashboard-progress"><span style="width: <?= min(100, $__resolutionRate) ?>%"></span></div>
            </div>
            <div class="oipi-dashboard-action-row">
                <a href="<?= url('formulaires') ?>" class="btn btn-success"><i class="fas fa-book-open mr-1" aria-hidden="true"></i> Consulter le registre</a>
            </div>
        </div>
    </header>

    <section class="oipi-dashboard-kpis" aria-label="Indicateurs principaux">
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Total des dossiers</span><strong><?= $__total ?></strong><small>Registre actif</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-orange">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-search" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Restant à retrouver</span><strong><?= $__restants ?></strong><small><?= pct($__restants, $__total) ?> % du registre</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-green">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-check-circle" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dossiers retrouvés</span><strong><?= $__retrouves ?></strong><small><?= $__resolutionRate ?> % résolus</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-soft">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-file-pdf" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dossiers numérisés</span><strong><?= $__numerises ?></strong><small><?= pct($__numerises, $__total) ?> % du registre</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-dark">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-keyboard" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dossiers saisis</span><strong><?= $__saisis ?></strong><small><?= pct($__saisis, $__total) ?> % finalisés</small>
        </a>
    </section>

    <section class="oipi-dashboard-consultation-grid">
        <article class="oipi-dashboard-panel">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Derniers dossiers mis à jour</h2><p>Aperçu des changements récents dans le registre.</p></div>
                <a href="<?= url('formulaires') ?>">Voir tout <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </header>
            <?php if (!empty($formulairesRecents)): ?>
                <div class="oipi-dashboard-table-scroll is-consultation" role="region" aria-label="Derniers dossiers mis à jour" tabindex="0">
                    <table class="oipi-dashboard-table">
                        <thead><tr><th>Référence</th><th>Dossier</th><th>Statut</th><th>Mise à jour</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($formulairesRecents as $formulaire): ?>
                            <tr>
                                <td><strong><?= e($formulaire['numero_auto']) ?></strong></td>
                                <td><strong><?= e($formulaire['type_libelle']) ?></strong><small><?= e($formulaire['numero_formulaire']) ?></small></td>
                                <td><span class="badge badge-<?= e($formulaire['statut_couleur']) ?>"><?= e($formulaire['statut_libelle']) ?></span></td>
                                <td class="text-nowrap"><?= formatDate($formulaire['mis_a_jour_le']) ?></td>
                                <td><a href="<?= url('formulaires/voir/' . $formulaire['id']) ?>" class="btn btn-sm btn-outline-success">Consulter</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="oipi-dashboard-empty is-roomy"><i class="fas fa-folder-open" aria-hidden="true"></i><span>Le registre ne contient encore aucun dossier.</span></div>
            <?php endif; ?>
        </article>

        <article class="oipi-dashboard-panel">
            <header class="oipi-dashboard-panel-header"><div><h2>Situation actuelle</h2><p>Répartition des dossiers par statut.</p></div></header>
            <div class="oipi-dashboard-status-layout">
                <div class="oipi-dashboard-chart oipi-dashboard-chart-donut">
                    <canvas id="dashboardStatusChart" aria-label="Répartition des dossiers par statut"></canvas>
                    <div class="oipi-dashboard-donut-total"><strong><?= $__total ?></strong><span>dossiers</span></div>
                    <div class="oipi-dashboard-chart-empty" data-empty-for="dashboardStatusChart"><i class="fas fa-chart-pie" aria-hidden="true"></i><span>Aucune donnée disponible.</span></div>
                </div>
                <div class="oipi-dashboard-status-legend" aria-label="Légende des statuts">
                    <?php foreach ($__statusRows as $__index => $__status): ?>
                        <div><span class="oipi-dashboard-legend-dot" data-status-index="<?= $__index ?>"></span><span><?= e((string) ($__status['statut'] ?? 'Statut')) ?></span><strong><?= (int) ($__status['total_formulaires'] ?? 0) ?></strong></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>
    </section>
</div>

<?php
$__dashboardData = json_encode([
    'monthly' => [],
    'statuses' => $__statusRows,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$__extra_js = '<script>window.RISFM_DASHBOARD_DATA = ' . ($__dashboardData ?: '{}') . ';</script>'
    . '<script src="' . asset('js/dashboard.js') . '"></script>';
?>
