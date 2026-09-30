<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Permission;
?>
<?php
$__role = (string) Auth::role();
$__total = (int) ($registryKpi['total_formulaires'] ?? 0);
$__remaining = (int) ($registryKpi['total_restants'] ?? 0);
$__found = (int) ($registryKpi['total_retrouves'] ?? 0);
$__unassigned = (int) ($registryKpi['total_non_assignes'] ?? 0);
$__activeMissions = (int) ($missionsKpi['total_actives'] ?? 0);
$__lateMissions = (int) ($missionsKpi['total_en_retard'] ?? 0);
$__resolutionRate = pct($__found, $__total);
?>

<div class="registry-workspace">
    <header class="registry-hero" aria-labelledby="registry-page-title">
        <div class="registry-hero-copy">
            <h1 id="registry-page-title">Formulaires manquants</h1>
            <p>Centralisez les dossiers, organisez les recherches et suivez leur finalisation.</p>
        </div>

        <div class="registry-hero-actions">
            <button type="button" id="btn-recherche-avancee" class="btn btn-outline-secondary risfm-filter-launcher" data-toggle="modal" data-target="#filtres-avances">
                <i class="fas fa-filter mr-1" aria-hidden="true"></i>Filtres
                <span id="filtres-actifs-count" class="risfm-filter-count d-none">0</span>
            </button>

            <?php if (Permission::has($__role, 'formulaires.archive')): ?>
            <a href="<?= url('formulaires/archives') ?>" class="btn btn-outline-secondary">
                <i class="fas fa-archive mr-1" aria-hidden="true"></i> Archives
                <?php if ((int) $archivesCount > 0): ?><span class="badge badge-secondary ml-1"><?= (int) $archivesCount ?></span><?php endif; ?>
            </a>
            <?php endif; ?>

            <?php if (Permission::has($__role, 'formulaires.import')): ?>
            <a href="<?= url('formulaires/importer') ?>" class="btn btn-outline-success"><i class="fas fa-file-import mr-1" aria-hidden="true"></i> Importer</a>
            <?php endif; ?>

            <?php if (Permission::has($__role, 'formulaires.export')): ?>
            <div class="btn-group" id="exports">
                <button type="button" class="btn btn-outline-dark dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fas fa-file-export mr-1" aria-hidden="true"></i> Exporter
                </button>
                <div class="dropdown-menu dropdown-menu-right registry-export-menu">
                    <h6 class="dropdown-header">Choisir un format</h6>
                    <a class="dropdown-item export-link" data-type="excel" href="#"><i class="fas fa-file-excel text-success mr-2"></i>Excel (.xlsx)</a>
                    <a class="dropdown-item export-link" data-type="pdf" href="#"><i class="fas fa-file-pdf text-danger mr-2"></i>PDF</a>
                    <a class="dropdown-item export-link" data-type="word" href="#"><i class="fas fa-file-word text-primary mr-2"></i>Word (.docx)</a>
                    <a class="dropdown-item export-link" data-type="csv" href="#"><i class="fas fa-file-csv text-secondary mr-2"></i>CSV</a>
                    <div class="dropdown-divider"></div>
                    <span class="dropdown-item-text registry-export-count" id="export-results-count">Calcul du nombre de lignes…</span>
                    <span class="dropdown-item-text small text-muted">Les filtres actifs seront appliqués au fichier.</span>
                </div>
            </div>
            <?php endif; ?>

            <?php if (Permission::has($__role, 'formulaires.create')): ?>
            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#modal-ajouter-formulaire">
                <i class="fas fa-plus mr-1" aria-hidden="true"></i> Ajouter un formulaire
            </button>
            <?php endif; ?>
        </div>
    </header>

    <section class="registry-kpis" aria-label="Résumé du registre">
        <a href="#registre-tableau" class="registry-kpi is-primary">
            <span><i class="fas fa-folder-open" aria-hidden="true"></i>Total des dossiers</span>
            <strong><?= $__total ?></strong><small>Registre actif</small>
        </a>
        <a href="#registre-tableau" class="registry-kpi is-orange">
            <span><i class="fas fa-search" aria-hidden="true"></i>Restant à retrouver</span>
            <strong><?= $__remaining ?></strong><small><?= pct($__remaining, $__total) ?> % du registre</small>
        </a>
        <a href="#registre-tableau" class="registry-kpi is-green">
            <span><i class="fas fa-check-circle" aria-hidden="true"></i>Dossiers retrouvés</span>
            <strong><?= $__found ?></strong><small><?= $__resolutionRate ?> % résolus</small>
        </a>
        <a href="#registre-tableau" class="registry-kpi <?= $__lateMissions > 0 ? 'is-alert' : 'is-neutral' ?>">
            <span><i class="fas fa-search-location" aria-hidden="true"></i>Missions actives</span>
            <strong><?= $__activeMissions ?></strong><small><?= $__lateMissions ?> en retard</small>
        </a>
        <a href="#registre-tableau" class="registry-kpi is-dark">
            <span><i class="fas fa-user-slash" aria-hidden="true"></i>Sans mission</span>
            <strong><?= $__unassigned ?></strong><small>À affecter</small>
        </a>
    </section>

    <section class="registry-table-panel" id="registre-tableau">
        <header class="registry-table-heading">
            <div><h2>Registre des formulaires</h2><p>Liste officielle des dossiers manquants et état de leur recherche.</p></div>
            <span id="registry-results-count" class="registry-results-count">Chargement…</span>
        </header>
        <div class="registry-table-body">
        <div id="registry-priority-legend" class="priority-legend" aria-label="Légende des priorités">
            <span class="priority-legend-title">Priorités :</span>
            <span class="priority-legend-item"><span class="priority-dot priority-urgent" aria-hidden="true"></span>Urgente</span>
            <span class="priority-legend-item"><span class="priority-dot priority-high" aria-hidden="true"></span>Haute</span>
            <span class="priority-legend-item"><span class="priority-dot priority-normal" aria-hidden="true"></span>Normale</span>
            <span class="priority-legend-item"><span class="priority-dot priority-low" aria-hidden="true"></span>Basse</span>
        </div>
        <div class="table-responsive">
        <table id="tbl-formulaires" class="table table-bordered table-hover w-100">
            <thead>
            <tr>
                <th>N°</th><th>Type de titre</th><th>Année</th><th>Numéro du formulaire</th>
                <th>Statut</th><th>Localisation recherchée</th><th>Responsable</th>
                <th>Date de recherche</th><th>Actions</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
        </div>
        </div>
    </section>
</div>

<div class="modal fade risfm-filter-modal registry-filter-modal" id="filtres-avances" tabindex="-1" role="dialog" aria-labelledby="registry-filter-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="frm-filtres">
                <div class="modal-header risfm-filter-modal-header">
                    <div>
                        <h5 class="modal-title" id="registry-filter-title"><i class="fas fa-filter text-success mr-2" aria-hidden="true"></i>Filtrer le registre</h5>
                        <div class="small text-muted">Affinez les dossiers par titre, statut, responsable ou période de recherche.</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="risfm-filter-grid registry-filter-modal-grid">
                        <div class="form-group">
                            <label for="registry_filter_annee">Année</label>
                            <select id="registry_filter_annee" class="form-control" name="annee">
                                <option value="">Toutes</option>
                                <?php foreach ($annees as $a): ?><option value="<?= $a ?>"><?= $a ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="registry_filter_type">Type de titre</label>
                            <select id="registry_filter_type" class="form-control" name="type_titre_id">
                                <option value="">Tous</option>
                                <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['libelle']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="registry_filter_statut">Statut</label>
                            <select id="registry_filter_statut" class="form-control" name="statut_id">
                                <option value="">Tous</option>
                                <?php foreach ($statuts as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['libelle']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="registry_filter_responsable">Responsable</label>
                            <select id="registry_filter_responsable" class="form-control" name="responsable_id">
                                <option value="">Tous</option>
                                <?php foreach ($responsables as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['nom'] . ' ' . $r['prenoms']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="registry_filter_numero">Numéro du formulaire</label>
                            <input id="registry_filter_numero" type="text" class="form-control" name="numero_formulaire">
                        </div>
                        <div class="form-group risfm-filter-wide">
                            <label for="registry_filter_keyword">Mot-clé</label>
                            <input id="registry_filter_keyword" type="text" class="form-control" name="mot_cle" placeholder="Numéro, compte rendu, localisation…">
                        </div>
                        <div class="form-group">
                            <label for="registry_filter_date_start">Recherche du</label>
                            <input id="registry_filter_date_start" type="date" class="form-control" name="date_debut">
                        </div>
                        <div class="form-group">
                            <label for="registry_filter_date_end">Recherche au</label>
                            <input id="registry_filter_date_end" type="date" class="form-control" name="date_fin">
                        </div>
                        <?php if ($__role === 'agent'): ?>
                        <div class="form-group risfm-filter-checkbox">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="mesDossiers" name="mes_dossiers" value="1">
                                <label class="custom-control-label" for="mesDossiers">Uniquement mes dossiers</label>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer risfm-filter-modal-footer">
                    <button type="reset" id="btn-reset" class="btn btn-outline-secondary"><i class="fas fa-undo mr-1" aria-hidden="true"></i>Réinitialiser</button>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                        <button type="submit" id="btn-filtrer" class="btn btn-success"><i class="fas fa-check mr-1" aria-hidden="true"></i>Appliquer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (Permission::has($__role, 'formulaires.create')): ?>
<div class="modal fade" id="modal-ajouter-formulaire" tabindex="-1" role="dialog" aria-labelledby="modal-ajouter-formulaire-title" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="<?= url('formulaires/ajouter') ?>" method="post" id="form-modal-ajout-formulaire">
                <?= Csrf::field() ?>
                <input type="hidden" name="_redirect_after_create" value="list">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1" id="modal-ajouter-formulaire-title">
                            <i class="fas fa-file-medical text-success mr-1"></i>Ajouter un formulaire manquant
                        </h5>
                        <div class="small text-muted">Enregistrer d'abord le dossier, puis l'affecter depuis les actions.</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <p class="text-muted small mb-3">
                        Les champs marques d'un <span class="text-danger font-weight-bold">*</span> sont obligatoires.
                    </p>

                    <div class="form-row">
                        <div class="form-group col-md-5">
                            <label for="modal_type_titre_id">Type de titre <span class="text-danger">*</span></label>
                            <select id="modal_type_titre_id" name="type_titre_id" class="form-control" required>
                                <option value="">-- Choisir --</option>
                                <?php foreach ($types as $t): ?>
                                    <option value="<?= (int) $t['id'] ?>"><?= e($t['libelle']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="modal_annee">Annee <span class="text-danger">*</span></label>
                            <select id="modal_annee" name="annee" class="form-control" required>
                                <?php foreach ($annees as $a): ?>
                                    <option value="<?= (int) $a ?>"><?= (int) $a ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="modal_numero_formulaire">Numero du formulaire <span class="text-danger">*</span></label>
                            <input id="modal_numero_formulaire" type="text" name="numero_formulaire" class="form-control" maxlength="60" required>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6 mb-2 mb-md-0">
                            <div class="border rounded bg-light h-100 p-3">
                                <small class="text-muted d-block">Statut initial</small>
                                <span class="badge badge-<?= e((string) ($initialStatus['couleur'] ?? 'danger')) ?> mt-1">
                                    <?= e((string) ($initialStatus['libelle'] ?? 'Introuvable')) ?>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded bg-light h-100 p-3">
                                <small class="text-muted d-block">Numero automatique</small>
                                <strong class="d-block mt-1">Genere apres l'enregistrement</strong>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save mr-1"></i>Enregistrer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (Permission::has($__role, 'formulaires.assign')): ?>
<div class="modal fade" id="modal-affecter-recherche" tabindex="-1" role="dialog" aria-labelledby="modal-affecter-recherche-title" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" id="form-modal-affectation" data-action-template="<?= url('formulaires/affecter/__ID__') ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="_redirect_after_assignment" value="list">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title mb-1" id="modal-affecter-recherche-title">
                            <i class="fas fa-search-location text-success mr-1"></i>Affecter une recherche
                        </h5>
                        <div class="small text-muted" id="modal-affecter-reference">Selectionnez un dossier dans le registre.</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-light border d-flex align-items-start">
                        <i class="fas fa-info-circle text-primary mr-2 mt-1"></i>
                        <div>Cette action ajoute une <strong>nouvelle mission</strong>. Les missions deja affectees restent actives et le nouveau responsable est notifie.</div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="modal_affectation_localisation_id">Localisation a inspecter <span class="text-danger">*</span></label>
                            <select id="modal_affectation_localisation_id" name="affectation_localisation_id" class="form-control" required>
                                <option value="">-- Choisir --</option>
                                <?php foreach ($localisations as $l): ?>
                                    <option value="<?= (int) $l['id'] ?>"><?= e($l['libelle']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="modal_affectation_responsable_id">Responsable <span class="text-danger">*</span></label>
                            <select id="modal_affectation_responsable_id" name="affectation_responsable_id" class="form-control" required>
                                <option value="">-- Choisir --</option>
                                <?php foreach ($responsables as $r): ?>
                                    <option value="<?= (int) $r['id'] ?>"><?= e($r['nom'] . ' ' . $r['prenoms']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="modal_affectation_date_echeance">Date limite</label>
                            <input id="modal_affectation_date_echeance" type="date" name="affectation_date_echeance" class="form-control" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="modal_affectation_priorite">Priorite <span class="text-danger">*</span></label>
                            <select id="modal_affectation_priorite" name="affectation_priorite" class="form-control" required>
                                <?php foreach (['Basse', 'Normale', 'Haute', 'Urgente'] as $__priorite): ?>
                                    <option value="<?= $__priorite ?>"><?= $__priorite ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div id="modal-affectation-deja-recherchee" class="alert alert-warning d-none" role="alert">
                        <div class="font-weight-bold mb-1">
                            <i class="fas fa-exclamation-triangle mr-1"></i>Localisation deja recherchee
                        </div>
                        <p class="mb-2">Cette localisation figure deja dans l'historique du dossier. Confirmez seulement si une nouvelle verification est necessaire.</p>
                        <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="modal_confirmer_localisation_deja_recherchee" name="confirmer_affectation_localisation_deja_recherchee" value="1">
                        <label class="custom-control-label" for="modal_confirmer_localisation_deja_recherchee">
                            Confirmer cette affectation malgre la recherche deja effectuee.
                        </label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane mr-1"></i>Affecter et notifier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$__extra_js = '<script>
window.RISFM_FILTERS_DEFAULT = {};
</script>
<script src="' . asset('js/formulaires.js') . '"></script>';
?>
