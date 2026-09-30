<?php
$journalTotal = (int) ($journalStats['total'] ?? 0);
$journalToday = (int) ($journalStats['aujourdhui'] ?? 0);
$journalActorsToday = (int) ($journalStats['acteurs_aujourdhui'] ?? 0);
$journalFailures = (int) ($journalStats['echecs_24h'] ?? 0);
$journalSensitive = (int) ($journalStats['sensibles_24h'] ?? 0);
?>

<div class="oipi-dashboard journal-workspace">
    <header class="oipi-dashboard-hero journal-workspace-hero" aria-labelledby="journal-page-title">
        <div class="oipi-dashboard-hero-copy">
            <h1 id="journal-page-title">Journal d’activité</h1>
            <p>Retrouvez chaque opération métier, administrative et de sécurité enregistrée dans l’application.</p>
        </div>
        <div class="oipi-dashboard-hero-actions">
            <div class="oipi-dashboard-action-row journal-hero-actions">
                <button type="button" class="btn btn-outline-secondary risfm-filter-launcher journal-filter-launcher" data-toggle="modal" data-target="#journal-filter-panel">
                    <i class="fas fa-filter mr-1" aria-hidden="true"></i> Filtres
                    <span class="risfm-filter-count journal-filter-count d-none" id="journal-filter-count">0</span>
                </button>
                <a href="<?= url('connexions') ?>" class="btn btn-success">
                    <i class="fas fa-network-wired mr-1" aria-hidden="true"></i> Historique des connexions
                </a>
            </div>
        </div>
    </header>

    <section class="oipi-dashboard-kpis journal-workspace-kpis" aria-label="Indicateurs du journal d’activité">
        <a href="#journal-table-card" class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-database" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Traces conservées</span>
            <strong><?= $journalTotal ?></strong>
            <small>Historique complet</small>
        </a>
        <a href="#journal-table-card" class="oipi-dashboard-kpi is-green">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-calendar-day" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Événements aujourd’hui</span>
            <strong><?= $journalToday ?></strong>
            <small>Depuis 00:00</small>
        </a>
        <a href="#journal-table-card" class="oipi-dashboard-kpi is-soft">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-user-clock" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Acteurs aujourd’hui</span>
            <strong><?= $journalActorsToday ?></strong>
            <small>Utilisateurs distincts</small>
        </a>
        <a href="#journal-table-card" class="oipi-dashboard-kpi <?= $journalFailures > 0 ? 'is-alert' : 'is-soft' ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-user-times" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Échecs d’accès</span>
            <strong><?= $journalFailures ?></strong>
            <small>Au cours des dernières 24 h</small>
        </a>
        <a href="#journal-table-card" class="oipi-dashboard-kpi is-dark">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Actions sensibles</span>
            <strong><?= $journalSensitive ?></strong>
            <small>Au cours des dernières 24 h</small>
        </a>
    </section>

    <section class="oipi-dashboard-panel journal-ledger journal-table-card" id="journal-table-card" aria-labelledby="journal-ledger-title">
        <header class="oipi-dashboard-panel-header journal-ledger-header">
            <div>
                <h2 id="journal-ledger-title">Chronologie des événements</h2>
                <p>L’identité de l’acteur et les valeurs avant/après restent consultables en lecture seule.</p>
            </div>
            <div class="journal-ledger-actions">
                <span class="oipi-dashboard-count" id="journal-result-count">Chargement…</span>
                <button type="button" class="btn btn-sm btn-outline-success" id="btn-actualiser-journal" title="Actualiser le journal">
                    <i class="fas fa-sync-alt" aria-hidden="true"></i><span> Actualiser</span>
                </button>
            </div>
        </header>
        <div class="journal-ledger-body">
            <div class="table-responsive journal-table-container">
                <table id="tbl-journal" class="table w-100 journal-table">
                    <thead>
                        <tr><th>Date</th><th>Acteur</th><th>Action</th><th>Cible</th><th>Description</th><th>IP</th><th class="text-center">Détail</th></tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<div class="modal fade risfm-filter-modal journal-filter-modal" id="journal-filter-panel" tabindex="-1" role="dialog" aria-labelledby="journal-filter-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="frm-journal">
                <div class="modal-header risfm-filter-modal-header journal-filter-modal-header">
                    <div>
                        <h5 class="modal-title" id="journal-filter-title"><i class="fas fa-filter text-success mr-2" aria-hidden="true"></i>Filtrer le journal</h5>
                        <div class="small text-muted">Affinez la chronologie sans modifier les traces enregistrées.</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="risfm-filter-grid journal-filter-grid">
                        <div class="form-group risfm-filter-wide journal-filter-keyword">
                            <label for="journal_mot_cle">Mot-clé</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search" aria-hidden="true"></i></span></div>
                                <input id="journal_mot_cle" type="search" class="form-control" name="mot_cle" maxlength="100" placeholder="Action, description, acteur, cible…">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="journal_type_action">Type d’action</label>
                            <select id="journal_type_action" class="form-control" name="type_action">
                                <option value="">Tous les types</option>
                                <?php foreach ($typesActions as $__action): ?>
                                    <option value="<?= e($__action['type_action']) ?>"><?= e($__action['label']) ?> (<?= (int) $__action['total'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="journal_utilisateur">Acteur</label>
                            <select id="journal_utilisateur" class="form-control" name="utilisateur_id">
                                <option value="">Tous les acteurs</option>
                                <?php foreach ($utilisateurs as $u): ?>
                                    <option value="<?= (int) $u['id'] ?>"><?= e(trim($u['nom'] . ' ' . $u['prenoms'])) ?> · <?= e($u['identifiant']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="journal_date_debut">Du</label>
                            <input id="journal_date_debut" type="date" class="form-control" name="date_debut">
                        </div>
                        <div class="form-group">
                            <label for="journal_date_fin">Au</label>
                            <input id="journal_date_fin" type="date" class="form-control" name="date_fin">
                        </div>
                    </div>
                </div>
                <div class="modal-footer risfm-filter-modal-footer journal-filter-modal-footer">
                    <button type="button" id="btn-reinitialiser-journal" class="btn btn-outline-secondary"><i class="fas fa-undo mr-1" aria-hidden="true"></i> Réinitialiser</button>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                        <button type="submit" id="btn-filtrer-journal" class="btn btn-success"><i class="fas fa-check mr-1" aria-hidden="true"></i> Appliquer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-journal-detail" tabindex="-1" role="dialog" aria-labelledby="modal-journal-detail-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="modal-journal-detail-title"><i class="fas fa-fingerprint text-success mr-2"></i>Détail de l’événement</h5>
                    <div class="small text-muted">Trace d’audit en lecture seule</div>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div class="journal-detail-loading text-center py-5">
                    <i class="fas fa-circle-notch fa-spin fa-2x text-success"></i>
                    <p class="text-muted mt-2 mb-0">Chargement de la trace…</p>
                </div>
                <div class="journal-detail-content d-none">
                    <div class="journal-detail-meta">
                        <div><span>Date et heure</span><strong data-journal-detail="date"></strong></div>
                        <div><span>Acteur</span><div data-journal-detail-html="actor"></div></div>
                        <div><span>Type d’action</span><div data-journal-detail-html="action"></div></div>
                        <div><span>Adresse IP</span><strong data-journal-detail="ip"></strong></div>
                    </div>
                    <div class="journal-detail-section">
                        <span class="journal-detail-label">Cible concernée</span>
                        <div data-journal-detail-html="target"></div>
                    </div>
                    <div class="journal-detail-section">
                        <span class="journal-detail-label">Description</span>
                        <p data-journal-detail="description"></p>
                    </div>
                    <div class="journal-detail-section mb-0">
                        <span class="journal-detail-label">Valeurs auditées</span>
                        <div data-journal-detail-html="changes"></div>
                    </div>
                </div>
                <div class="journal-detail-error alert alert-danger d-none mb-0"></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Fermer</button></div>
        </div>
    </div>
</div>

<?php $__extra_js = '<script src="' . asset('js/journal.js') . '"></script>'; ?>
