<?php
$__total = (int) ($kpi['total_formulaires'] ?? 0);
$__retrouves = (int) ($kpi['total_retrouves'] ?? 0);
$__restants = (int) ($kpi['total_restants'] ?? 0);
$__numerises = (int) ($kpi['total_numerises'] ?? 0);
$__saisis = (int) ($kpi['total_saisis'] ?? 0);
$__resolutionRate = pct($__retrouves, $__total);
$__digitizationRate = pct($__numerises, $__total);
$__finalizationRate = pct($__saisis, $__total);
$__activeMissions = (int) ($missionsKpi['total_actives'] ?? 0);
$__lateMissions = (int) ($missionsKpi['total_en_retard'] ?? 0);
$__urgentMissions = (int) ($missionsKpi['total_urgentes'] ?? 0);
$__dueSoonMissions = (int) ($missionsKpi['total_a_7_jours'] ?? 0);
$__unassigned = (int) ($kpi['total_non_assignes'] ?? 0);

$__statusRows = array_values(array_filter(
    $statsStatut,
    static fn (array $row): bool => (int) ($row['total_formulaires'] ?? 0) > 0
));

$__categoryAlerts = array_values(array_filter(
    $statsType,
    static fn (array $row): bool => (int) ($row['total_formulaires'] ?? 0) > 0
));
usort(
    $__categoryAlerts,
    static function (array $a, array $b): int {
        $remaining = (int) ($b['total_restants'] ?? 0) <=> (int) ($a['total_restants'] ?? 0);
        return $remaining !== 0
            ? $remaining
            : (int) ($b['total_formulaires'] ?? 0) <=> (int) ($a['total_formulaires'] ?? 0);
    }
);
$__categoryAlerts = array_slice($__categoryAlerts, 0, 5);
$__maxCategoryRemaining = max(1, ...array_map(
    static fn (array $row): int => (int) ($row['total_restants'] ?? 0),
    $__categoryAlerts ?: [['total_restants' => 0]]
));
?>

<div class="oipi-dashboard oipi-dashboard-admin">
    <header class="oipi-dashboard-hero" aria-labelledby="dashboard-title">
        <div class="oipi-dashboard-hero-copy">
            <h1 id="dashboard-title">Tableau de bord</h1>
            <p>Suivez la recherche, la numérisation et la saisie des formulaires manquants.</p>
        </div>
        <div class="oipi-dashboard-hero-actions">
            <div class="oipi-dashboard-campaign-rate" aria-label="Taux de résolution global : <?= $__resolutionRate ?> pour cent">
                <span>Résolution globale</span>
                <strong><?= $__resolutionRate ?><small>%</small></strong>
                <div class="oipi-dashboard-progress"><span style="width: <?= min(100, $__resolutionRate) ?>%"></span></div>
            </div>
            <div class="oipi-dashboard-action-row">
                <a href="<?= url('formulaires') ?>" class="btn btn-outline-success"><i class="fas fa-folder-open mr-1" aria-hidden="true"></i> Registre</a>
                <a href="<?= url('statistiques') ?>" class="btn btn-success"><i class="fas fa-chart-pie mr-1" aria-hidden="true"></i> Analyse détaillée</a>
            </div>
        </div>
    </header>

    <section class="oipi-dashboard-kpis" aria-label="Indicateurs principaux">
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Total des dossiers</span>
            <strong><?= $__total ?></strong>
            <small>Registre actif</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-orange">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-search" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Restant à retrouver</span>
            <strong><?= $__restants ?></strong>
            <small><?= pct($__restants, $__total) ?> % du registre</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-green">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-check-circle" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dossiers retrouvés</span>
            <strong><?= $__retrouves ?></strong>
            <small><?= $__resolutionRate ?> % résolus</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi <?= $__lateMissions > 0 ? 'is-alert' : 'is-soft' ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-search-location" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Missions actives</span>
            <strong><?= $__activeMissions ?></strong>
            <small><?= $__lateMissions ?> en retard</small>
        </a>
        <a href="<?= url('formulaires') ?>" class="oipi-dashboard-kpi is-dark">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-keyboard" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dossiers saisis</span>
            <strong><?= $__saisis ?></strong>
            <small><?= $__finalizationRate ?> % finalisés</small>
        </a>
    </section>

    <section class="oipi-dashboard-chart-grid" aria-label="Analyse graphique">
        <article class="oipi-dashboard-panel oipi-dashboard-panel-wide">
            <header class="oipi-dashboard-panel-header">
                <div>
                    <h2>Résolutions mensuelles</h2>
                    <p>Première résolution enregistrée par mois.</p>
                </div>
                <span class="oipi-dashboard-period"><i class="far fa-calendar-alt" aria-hidden="true"></i> Historique disponible</span>
            </header>
            <div class="oipi-dashboard-chart oipi-dashboard-chart-line">
                <canvas id="dashboardMonthlyChart" aria-label="Évolution mensuelle des dossiers retrouvés"></canvas>
                <div class="oipi-dashboard-chart-empty" data-empty-for="dashboardMonthlyChart"><i class="fas fa-chart-line" aria-hidden="true"></i><span>Aucune résolution datée pour le moment.</span></div>
            </div>
        </article>

        <article class="oipi-dashboard-panel">
            <header class="oipi-dashboard-panel-header">
                <div>
                    <h2>Situation du registre</h2>
                    <p>Répartition par statut actuel.</p>
                </div>
            </header>
            <div class="oipi-dashboard-status-layout">
                <div class="oipi-dashboard-chart oipi-dashboard-chart-donut">
                    <canvas id="dashboardStatusChart" aria-label="Répartition des dossiers par statut"></canvas>
                    <div class="oipi-dashboard-donut-total"><strong><?= $__total ?></strong><span>dossiers</span></div>
                    <div class="oipi-dashboard-chart-empty" data-empty-for="dashboardStatusChart"><i class="fas fa-chart-pie" aria-hidden="true"></i><span>Aucune donnée disponible.</span></div>
                </div>
                <div class="oipi-dashboard-status-legend" aria-label="Légende des statuts">
                    <?php foreach ($__statusRows as $__index => $__status): ?>
                        <div>
                            <span class="oipi-dashboard-legend-dot" data-status-index="<?= $__index ?>"></span>
                            <span><?= e($statusLabel = (string) ($__status['statut'] ?? 'Statut')) ?></span>
                            <strong><?= (int) ($__status['total_formulaires'] ?? 0) ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </article>
    </section>

    <section class="oipi-dashboard-decision-grid" aria-label="Aide à la décision">
        <article class="oipi-dashboard-panel oipi-dashboard-decision-card">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Progression documentaire</h2><p>Avancement du cycle de finalisation.</p></div>
                <a href="<?= url('statistiques') ?>" aria-label="Voir les statistiques détaillées">Voir tout <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </header>
            <div class="oipi-dashboard-bars">
                <?php foreach ([
                    ['Retrouvés', $__retrouves, $__resolutionRate, 'is-orange'],
                    ['Numérisés', $__numerises, $__digitizationRate, 'is-green-soft'],
                    ['Saisis', $__saisis, $__finalizationRate, 'is-green'],
                ] as $__step): ?>
                    <div class="oipi-dashboard-bar-row">
                        <div><span><?= e($__step[0]) ?></span><strong><?= (int) $__step[1] ?> <small><?= (int) $__step[2] ?>%</small></strong></div>
                        <div class="oipi-dashboard-bar-track"><span class="<?= e($__step[3]) ?>" style="width: <?= min(100, (int) $__step[2]) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="oipi-dashboard-panel oipi-dashboard-decision-card">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Priorités opérationnelles</h2><p>Points qui demandent une action.</p></div>
                <a href="<?= url('formulaires') ?>" aria-label="Ouvrir le registre">Agir <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </header>
            <div class="oipi-dashboard-priorities">
                <?php foreach ([
                    ['Missions en retard', $__lateMissions, 'fas fa-clock', 'is-critical'],
                    ['Missions urgentes', $__urgentMissions, 'fas fa-exclamation-triangle', 'is-orange'],
                    ['Échéances sous 7 jours', $__dueSoonMissions, 'far fa-calendar-alt', 'is-green'],
                    ['Dossiers sans mission', $__unassigned, 'fas fa-user-slash', 'is-neutral'],
                ] as $__priority): ?>
                    <a href="<?= url('formulaires') ?>" class="oipi-dashboard-priority <?= e($__priority[3]) ?>">
                        <span><i class="<?= e($__priority[2]) ?>" aria-hidden="true"></i><?= e($__priority[0]) ?></span>
                        <strong><?= (int) $__priority[1] ?></strong>
                    </a>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="oipi-dashboard-panel oipi-dashboard-decision-card">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Types à traiter</h2><p>Catégories avec le plus de dossiers restants.</p></div>
                <a href="<?= url('statistiques') ?>" aria-label="Voir les statistiques par type">Détails <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </header>
            <?php if ($__categoryAlerts !== []): ?>
                <div class="oipi-dashboard-category-list">
                    <?php foreach ($__categoryAlerts as $__category): ?>
                        <?php $__categoryRemaining = (int) ($__category['total_restants'] ?? 0); ?>
                        <div class="oipi-dashboard-category-row">
                            <div><span><?= e($categoryLabel = (string) ($__category['type_titre'] ?? 'Type de titre')) ?></span><strong><?= $__categoryRemaining ?></strong></div>
                            <div class="oipi-dashboard-category-track"><span style="width: <?= pct($__categoryRemaining, $__maxCategoryRemaining) ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="oipi-dashboard-empty"><i class="fas fa-check-circle" aria-hidden="true"></i><span>Aucun dossier restant à traiter.</span></div>
            <?php endif; ?>
        </article>
    </section>

    <section class="oipi-dashboard-history-grid" aria-label="Activité du logiciel">
        <article class="oipi-dashboard-panel">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Activités récentes</h2><p>Dernières opérations réalisées.</p></div>
                <a href="<?= url('journal') ?>">Journal complet <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </header>
            <div class="oipi-dashboard-table-scroll" role="region" aria-label="Activités récentes" tabindex="0">
                <table class="oipi-dashboard-table">
                    <thead><tr><th>Date</th><th>Utilisateur</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($activitesRecentes as $activity): ?>
                        <tr>
                            <td class="text-nowrap"><?= formatDate($activity['cree_le']) ?></td>
                            <td><?= e($activity['utilisateur_nom'] ?? 'Système') ?></td>
                            <td><?= e($activity['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($activitesRecentes)): ?><tr><td colspan="3" class="oipi-dashboard-table-empty">Aucune activité récente.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>

        <article class="oipi-dashboard-panel">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Connexions récentes</h2><p><?= (int) $utilisateursConnectes ?> utilisateur(s) connecté(s) sur <?= (int) $utilisateursActifs ?> actif(s).</p></div>
                <a href="<?= url('connexions') ?>">Historique <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </header>
            <div class="oipi-dashboard-table-scroll" role="region" aria-label="Connexions récentes" tabindex="0">
                <table class="oipi-dashboard-table">
                    <thead><tr><th>Date</th><th>Utilisateur</th><th>Statut</th></tr></thead>
                    <tbody>
                    <?php foreach ($connexionsRecentes as $connection): ?>
                        <?php $connectionStatus = (string) ($connection['statut_effectif'] ?? $connection['statut']); ?>
                        <tr>
                            <td class="text-nowrap"><?= formatDate($connection['connecte_le']) ?></td>
                            <td><?= e($connection['utilisateur_nom']) ?><small><?= e($connection['adresse_ip']) ?></small></td>
                            <td><span class="oipi-dashboard-connection-status is-<?= e($connectionStatus) ?>"><?= e($connectionStatus === 'actif' ? 'Actif' : ($connectionStatus === 'expire' ? 'Expiré' : 'Terminé')) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($connexionsRecentes)): ?><tr><td colspan="3" class="oipi-dashboard-table-empty">Aucune connexion récente.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</div>

<?php
$__dashboardData = json_encode([
    'monthly' => array_values($statsMensuelles),
    'statuses' => $__statusRows,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$__extra_js = '<script>window.RISFM_DASHBOARD_DATA = ' . ($__dashboardData ?: '{}') . ';</script>'
    . '<script src="' . asset('js/dashboard.js') . '"></script>';
?>
