<?php
$__totalConnections = (int) ($connexionStats['total'] ?? 0);
$__activeConnections = (int) ($connexionStats['actives'] ?? 0);
$__todayConnections = (int) ($connexionStats['aujourdhui'] ?? 0);
$__todayUsers = (int) ($connexionStats['utilisateurs_aujourdhui'] ?? 0);
$__expiredConnections = (int) ($connexionStats['expirees'] ?? 0);
$__closedConnections = max(0, $__totalConnections - $__activeConnections - $__expiredConnections);
$__percent = static fn (int $value): int => $__totalConnections > 0
    ? (int) round(($value / $__totalConnections) * 100)
    : 0;
$__connectionAnalytics = [
    'trend' => $connexionTrend,
    'statuses' => [
        'active' => $__activeConnections,
        'closed' => $__closedConnections,
        'expired' => $__expiredConnections,
    ],
    'total' => $__totalConnections,
];
$__connectionAnalyticsJson = json_encode(
    $__connectionAnalytics,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>

<div class="connection-analytics-shell">
    <header class="connection-analytics-hero">
        <div>
            <h1>Analyse des connexions</h1>
            <p>Suivez l’activité des utilisateurs, les sessions ouvertes et les expirations liées à l’inactivité.</p>
        </div>
        <div class="connection-analytics-hero-actions">
            <span class="connection-session-policy">
                <i class="fas fa-user-clock" aria-hidden="true"></i>
                Inactivité maximale&nbsp;: <strong><?= (int) $sessionLifetimeMinutes ?> min</strong>
            </span>
            <button type="button" class="btn btn-sm btn-outline-secondary risfm-filter-launcher" data-toggle="modal" data-target="#connection-filter-panel">
                <i class="fas fa-filter mr-1" aria-hidden="true"></i>Filtres
                <span class="risfm-filter-count d-none" id="connection-filter-count">0</span>
            </button>
            <a href="<?= url('journal') ?>" class="btn btn-sm btn-outline-dark">
                <i class="fas fa-history mr-1"></i>Journal d’activité
            </a>
        </div>
    </header>

    <section class="connection-analytics-kpis" aria-label="Indicateurs des connexions">
        <a href="#connexions-table-card" class="connection-analytics-kpi is-total js-connection-reset-filter">
            <span>Total des sessions</span>
            <strong><?= $__totalConnections ?></strong>
            <small>Historique enregistré</small>
            <i class="fas fa-layer-group" aria-hidden="true"></i>
        </a>
        <a href="#connexions-table-card" class="connection-analytics-kpi is-active js-connection-quick-filter" data-status="actif">
            <span>Sessions actives</span>
            <strong><?= $__activeConnections ?></strong>
            <small><?= $__percent($__activeConnections) ?> % du total</small>
            <i class="fas fa-wifi" aria-hidden="true"></i>
        </a>
        <a href="#connexions-table-card" class="connection-analytics-kpi is-today js-connection-today-filter">
            <span>Connexions aujourd’hui</span>
            <strong><?= $__todayConnections ?></strong>
            <small><?= $__percent($__todayConnections) ?> % du total</small>
            <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
        </a>
        <a href="#connexions-table-card" class="connection-analytics-kpi is-users js-connection-today-filter">
            <span>Utilisateurs aujourd’hui</span>
            <strong><?= $__todayUsers ?></strong>
            <small>Comptes distincts</small>
            <i class="fas fa-users" aria-hidden="true"></i>
        </a>
        <a href="#connexions-table-card" class="connection-analytics-kpi <?= $__expiredConnections > 0 ? 'is-expired' : 'is-clear' ?> js-connection-quick-filter" data-status="expire">
            <span>Sessions expirées</span>
            <strong><?= $__expiredConnections ?></strong>
            <small><?= $__expiredConnections > 0 ? 'À contrôler' : 'Aucune anomalie' ?></small>
            <i class="fas fa-hourglass-end" aria-hidden="true"></i>
        </a>
    </section>

    <section class="connection-analytics-insights" aria-label="Graphiques des connexions">
        <article class="connection-analytics-card connection-trend-card">
            <div class="connection-analytics-card-header">
                <div>
                    <h2>Activité récente</h2>
                    <p>Connexions et utilisateurs distincts par jour.</p>
                </div>
                <span class="connection-period-pill"><i class="far fa-calendar-alt mr-1"></i>7 derniers jours</span>
            </div>
            <div class="connection-chart-container is-trend">
                <canvas id="chart-connection-trend" aria-label="Évolution des connexions sur sept jours" role="img"></canvas>
            </div>
        </article>

        <article class="connection-analytics-card connection-status-card">
            <div class="connection-analytics-card-header">
                <div>
                    <h2>État des sessions</h2>
                    <p>Répartition de l’historique enregistré.</p>
                </div>
            </div>
            <div class="connection-status-visual">
                <div class="connection-doughnut-container">
                    <canvas id="chart-connection-status" aria-label="Répartition des états de session" role="img"></canvas>
                    <span class="connection-doughnut-total"><strong><?= $__totalConnections ?></strong><small>sessions</small></span>
                </div>
                <ul class="connection-status-legend">
                    <li class="is-active"><span>Actives</span><strong><?= $__activeConnections ?></strong></li>
                    <li class="is-closed"><span>Terminées</span><strong><?= $__closedConnections ?></strong></li>
                    <li class="is-expired"><span>Expirées</span><strong><?= $__expiredConnections ?></strong></li>
                </ul>
            </div>
        </article>
    </section>

    <section class="card connections-table-card" id="connexions-table-card">
        <div class="card-header connection-table-heading">
            <div>
                <h2><i class="fas fa-network-wired mr-2" aria-hidden="true"></i>Chronologie des connexions</h2>
                <p>Le statut est calculé d’après la dernière activité réelle de chaque session.</p>
            </div>
            <span class="connection-result-pill" id="connection-result-count">Chargement…</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive connection-table-wrap">
                <table id="tbl-connexions" class="table table-hover w-100 connections-table">
                    <thead>
                        <tr><th>Utilisateur</th><th>Connexion</th><th>Dernière activité</th><th>Durée</th><th>Adresse IP</th><th>Environnement</th><th>Statut</th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<div class="modal fade risfm-filter-modal connection-filter-modal" id="connection-filter-panel" tabindex="-1" role="dialog" aria-labelledby="connection-filter-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="frm-connexions">
                <div class="modal-header risfm-filter-modal-header">
                    <div>
                        <h5 class="modal-title" id="connection-filter-title"><i class="fas fa-filter text-success mr-2" aria-hidden="true"></i>Filtrer les connexions</h5>
                        <div class="small text-muted">Affinez l’analyse par utilisateur, état de session ou période.</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="risfm-filter-grid connection-filter-grid">
                        <div class="form-group risfm-filter-wide">
                            <label for="connexion_mot_cle">Recherche</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search" aria-hidden="true"></i></span></div>
                                <input id="connexion_mot_cle" type="search" class="form-control" name="mot_cle" maxlength="100" placeholder="Nom, identifiant, IP, navigateur…">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="connexion_utilisateur">Utilisateur</label>
                            <select id="connexion_utilisateur" class="form-control" name="utilisateur_id">
                                <option value="">Tous les utilisateurs</option>
                                <?php foreach ($utilisateurs as $utilisateur): ?>
                                    <option value="<?= (int) $utilisateur['id'] ?>"><?= e(trim($utilisateur['nom'] . ' ' . $utilisateur['prenoms'])) ?> · <?= e($utilisateur['identifiant']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="connexion_statut">État de la session</label>
                            <select id="connexion_statut" class="form-control" name="statut">
                                <option value="">Tous les états</option>
                                <option value="actif">Active maintenant</option>
                                <option value="expire">Expirée par inactivité</option>
                                <option value="termine">Terminée normalement</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="connexion_date_debut">Connecté du</label>
                            <input id="connexion_date_debut" type="date" class="form-control" name="date_debut">
                        </div>
                        <div class="form-group">
                            <label for="connexion_date_fin">Connecté au</label>
                            <input id="connexion_date_fin" type="date" class="form-control" name="date_fin">
                        </div>
                    </div>
                </div>
                <div class="modal-footer risfm-filter-modal-footer">
                    <button type="button" id="btn-reinitialiser-connexions" class="btn btn-outline-secondary"><i class="fas fa-undo mr-1" aria-hidden="true"></i>Réinitialiser</button>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check mr-1" aria-hidden="true"></i>Appliquer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$__extra_js = '<script>window.RISFM_CONNECTION_ANALYTICS = ' . ($__connectionAnalyticsJson ?: '{}') . ';</script>'
    . '<script src="' . asset('js/connexions.js') . '"></script>';
?>
