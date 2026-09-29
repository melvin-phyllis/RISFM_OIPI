<?php
use App\Core\Auth;
?>
<?php
$__trackedTotal = (int) $mesFormulairesTotal;
$__resolvedTotal = (int) $mesFormulairesResolus;
$__resolutionRate = pct($__resolvedTotal, $__trackedTotal);
$__activeTotal = (int) $missionsActivesTotal;
$__lateTotal = (int) $missionsEnRetard;
$__urgentTotal = (int) $missionsUrgentes;
?>

<div class="oipi-dashboard oipi-dashboard-user">
    <header class="oipi-dashboard-hero" aria-labelledby="dashboard-title">
        <div class="oipi-dashboard-hero-copy">
            <span class="oipi-dashboard-eyebrow"><i class="fas fa-user-shield" aria-hidden="true"></i> <?= e($roleLabel) ?></span>
            <h1 id="dashboard-title">Bonjour, <?= e(Auth::nom()) ?></h1>
            <p>Vos missions, priorités et échéances de recherche sont réunies ici.</p>
        </div>
        <div class="oipi-dashboard-hero-actions">
            <div class="oipi-dashboard-next-deadline <?= $__lateTotal > 0 ? 'is-alert' : '' ?>">
                <span><?= $__lateTotal > 0 ? 'Attention requise' : 'Prochaine échéance' ?></span>
                <strong>
                    <?php if ($__lateTotal > 0): ?>
                        <?= $__lateTotal ?> mission(s) en retard
                    <?php elseif (!empty($prochaineEcheance)): ?>
                        <?= formatDate($prochaineEcheance, 'd/m/Y') ?>
                    <?php else: ?>
                        Aucune échéance planifiée
                    <?php endif; ?>
                </strong>
            </div>
            <div class="oipi-dashboard-action-row">
                <a href="<?= url('formulaires') ?>" class="btn btn-success"><i class="fas fa-folder-open mr-1" aria-hidden="true"></i> Ouvrir le registre</a>
            </div>
        </div>
    </header>

    <section class="oipi-dashboard-kpis" aria-label="Indicateurs de mes missions">
        <a href="#mes-missions-actives" class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-search-location" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Missions actives</span>
            <strong><?= $__activeTotal ?></strong>
            <small>À traiter</small>
        </a>
        <a href="#mes-missions-actives" class="oipi-dashboard-kpi <?= $__lateTotal > 0 ? 'is-alert' : 'is-soft' ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-clock" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Missions en retard</span>
            <strong><?= $__lateTotal ?></strong>
            <small><?= $__lateTotal > 0 ? 'Action requise' : 'Aucun retard' ?></small>
        </a>
        <a href="#mes-missions-actives" class="oipi-dashboard-kpi is-orange">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Priorité urgente</span>
            <strong><?= $__urgentTotal ?></strong>
            <small>À prioriser</small>
        </a>
        <a href="#mes-dossiers-recents" class="oipi-dashboard-kpi is-dark">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dossiers suivis</span>
            <strong><?= $__trackedTotal ?></strong>
            <small>Actifs et terminés</small>
        </a>
        <a href="#mes-dossiers-recents" class="oipi-dashboard-kpi is-green">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-check-circle" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dossiers résolus</span>
            <strong><?= $__resolvedTotal ?></strong>
            <small><?= $__resolutionRate ?> % de mes dossiers</small>
        </a>
    </section>

    <section class="oipi-dashboard-user-grid">
        <article class="oipi-dashboard-panel" id="mes-missions-actives">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Mes missions actives</h2><p>Classées par échéance puis par priorité.</p></div>
                <span class="oipi-dashboard-count"><?= $__activeTotal ?> active(s)</span>
            </header>
            <?php if (!empty($missionsActives)): ?>
                <div class="oipi-dashboard-table-scroll is-user-work" role="region" aria-label="Mes missions actives" tabindex="0">
                    <table class="oipi-dashboard-table">
                        <thead><tr><th>Référence</th><th>Dossier</th><th>Localisation</th><th>Priorité</th><th>Échéance</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($missionsActives as $mission): ?>
                            <?php $missionPriority = (string) ($mission['priorite'] ?? 'Normale'); ?>
                            <tr class="<?= !empty($mission['est_en_retard']) ? 'is-row-alert' : '' ?>">
                                <td><strong><?= e($mission['numero_auto']) ?></strong></td>
                                <td><strong><?= e($mission['type_libelle']) ?></strong><small><?= e($mission['numero_formulaire']) ?></small></td>
                                <td><?= e($mission['localisation_libelle'] ?? 'Non définie') ?></td>
                                <td><span class="badge priority-badge <?= e(priorityBadgeClass($missionPriority)) ?>"><i class="<?= e(priorityIconClass($missionPriority)) ?> mr-1" aria-hidden="true"></i><?= e($missionPriority) ?></span></td>
                                <td class="text-nowrap"><?= formatDate($mission['date_echeance'] ?? null, 'd/m/Y') ?><?php if (!empty($mission['est_en_retard'])): ?><small class="text-danger">En retard</small><?php endif; ?></td>
                                <td><a href="<?= url('formulaires/voir/' . $mission['formulaire_id'] . '#mission-' . $mission['id']) ?>" class="btn btn-sm btn-success">Traiter <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="oipi-dashboard-empty is-roomy">
                    <i class="fas fa-check-circle" aria-hidden="true"></i>
                    <strong>Aucune mission en attente</strong>
                    <span>Votre file de travail est à jour. Les nouvelles affectations apparaîtront automatiquement ici.</span>
                </div>
            <?php endif; ?>
        </article>

        <article class="oipi-dashboard-panel" id="mes-dossiers-recents">
            <header class="oipi-dashboard-panel-header">
                <div><h2>Mes dossiers récents</h2><p>Derniers dossiers auxquels vous avez participé.</p></div>
                <a href="<?= url('formulaires') ?>">Voir le registre <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </header>
            <?php if (!empty($mesFormulaires)): ?>
                <div class="oipi-dashboard-table-scroll is-user-files" role="region" aria-label="Mes dossiers récents" tabindex="0">
                    <table class="oipi-dashboard-table">
                        <thead><tr><th>Référence</th><th>Dossier</th><th>Statut</th><th>Priorité</th><th>Mise à jour</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($mesFormulaires as $formulaire): ?>
                            <?php $formPriority = (string) ($formulaire['priorite'] ?? 'Normale'); ?>
                            <tr>
                                <td><strong><?= e($formulaire['numero_auto']) ?></strong></td>
                                <td><strong><?= e($formulaire['type_libelle']) ?></strong><small><?= e($formulaire['numero_formulaire']) ?></small></td>
                                <td><span class="badge badge-<?= e($formulaire['statut_couleur']) ?>"><?= e($formulaire['statut_libelle']) ?></span></td>
                                <td><span class="badge priority-badge <?= e(priorityBadgeClass($formPriority)) ?>"><?= e($formPriority) ?></span></td>
                                <td class="text-nowrap"><?= formatDate($formulaire['mis_a_jour_le']) ?></td>
                                <td><a href="<?= url('formulaires/voir/' . $formulaire['id']) ?>" class="btn btn-sm btn-outline-success">Consulter</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="oipi-dashboard-empty is-roomy"><i class="fas fa-folder-open" aria-hidden="true"></i><span>Aucun dossier ne vous a encore été affecté.</span></div>
            <?php endif; ?>
        </article>
    </section>
</div>
