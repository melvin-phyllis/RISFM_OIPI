<?php
use App\Core\Csrf;
use App\Services\Formulaire\FormulaireImportService;
?>
<?php
$__analysis = is_array($analysis ?? null) ? $analysis : null;
$__state = is_array($importState ?? null) ? $importState : null;
$__orderedPreviewRows = [];
if ($__analysis !== null) {
    $__invalidPreviewRows = array_values(array_filter($__analysis['rows'], static fn (array $row): bool => !$row['valid']));
    $__validPreviewRows = array_values(array_filter($__analysis['rows'], static fn (array $row): bool => $row['valid']));
    $__orderedPreviewRows = array_merge($__invalidPreviewRows, $__validPreviewRows);
}
$__previewRows = array_slice($__orderedPreviewRows, 0, (int) $previewLimit);
?>

<?php if (!empty($importError)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle mr-1"></i><?= e($importError) ?>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-upload text-success mr-1"></i>1. Sélectionner le fichier</h3>
            </div>
            <div class="card-body">
                <form action="<?= url('formulaires/importer/analyser') ?>" method="post" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <div class="form-group">
                        <label for="fichier_import">Registre CSV ou Excel <span class="text-danger">*</span></label>
                        <div class="custom-file">
                            <input
                                type="file"
                                class="custom-file-input"
                                id="fichier_import"
                                name="fichier_import"
                                accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                required
                            >
                            <label class="custom-file-label" for="fichier_import">Choisir un fichier…</label>
                        </div>
                        <small class="form-text text-muted">
                            Maximum 10 Mo et <?= FormulaireImportService::MAX_ROWS ?> lignes. Le fichier reste dans un dossier privé et est supprimé après validation ou annulation.
                        </small>
                    </div>
                    <button class="btn btn-primary">
                        <i class="fas fa-search mr-1"></i>Analyser avant l’import
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-file-download text-success mr-1"></i>Modèle officiel</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">
                    Le modèle contient les quatre colonnes reconnues : Type de titre, Année, Numéro du formulaire et Priorité.
                </p>
                <a href="<?= url('formulaires/importer/modele/xlsx') ?>" class="btn btn-outline-success mr-2 mb-2">
                    <i class="fas fa-file-excel mr-1"></i>Modèle Excel
                </a>
                <a href="<?= url('formulaires/importer/modele/csv') ?>" class="btn btn-outline-secondary mb-2">
                    <i class="fas fa-file-csv mr-1"></i>Modèle CSV
                </a>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-info-circle text-primary mr-1"></i>Règles de l’import</h3>
    </div>
    <div class="card-body py-3">
        <div class="row">
            <div class="col-lg-6">
                <ul class="mb-lg-0 pl-4">
                    <li><strong>Obligatoires :</strong> Type de titre, Année et Numéro du formulaire.</li>
                    <li><strong>Priorité</strong> facultative : Basse, Normale, Haute ou Urgente (vide = Normale).</li>
                    <li>Le type de titre accepte son code ou son libellé actif.</li>
                </ul>
            </div>
            <div class="col-lg-6">
                <ul class="mb-0 pl-4">
                    <li>Chaque formulaire est créé au statut <strong>Introuvable</strong>, sans mission : les affectations se font ensuite depuis sa fiche.</li>
                    <li><strong>Aucun import partiel :</strong> une ligne invalide bloque tout le fichier.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($__analysis !== null && $__state !== null): ?>
<section aria-labelledby="import-preview-title">
    <div class="card border-<?= $__analysis['error_count'] === 0 ? 'success' : 'warning' ?>">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between">
            <div>
                <h3 class="card-title mb-1" id="import-preview-title">
                    <i class="fas fa-clipboard-check mr-1"></i>2. Vérifier l’aperçu
                </h3>
                <div class="small text-muted">
                    <?= e((string) $__state['original_name']) ?> —
                    <?= number_format((int) $__state['size'] / 1024, 1, ',', ' ') ?> Ko —
                    en-tête détecté à la ligne <?= (int) $__analysis['header_row'] ?>
                </div>
            </div>
            <div class="mt-2 mt-md-0">
                <span class="badge badge-dark p-2"><?= (int) $__analysis['total'] ?> ligne(s)</span>
                <span class="badge badge-success p-2"><?= (int) $__analysis['valid_count'] ?> valide(s)</span>
                <span class="badge badge-danger p-2"><?= (int) $__analysis['error_count'] ?> erreur(s)</span>
                <a href="<?= url('formulaires/importer/rapport') ?>" class="btn btn-sm btn-outline-primary ml-1">
                    <i class="fas fa-download mr-1"></i>Rapport CSV
                </a>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 520px; overflow-y: auto;">
                <table class="table table-sm table-bordered table-hover mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>Ligne</th>
                        <th>État</th>
                        <th>Type</th>
                        <th>Année</th>
                        <th>Numéro</th>
                        <th>Priorité</th>
                        <th>Erreurs</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($__previewRows as $__row): ?>
                    <tr class="<?= $__row['valid'] ? '' : 'table-danger' ?>">
                        <td><?= (int) $__row['line'] ?></td>
                        <td>
                            <span class="badge badge-<?= $__row['valid'] ? 'success' : 'danger' ?>">
                                <?= $__row['valid'] ? 'Valide' : 'À corriger' ?>
                            </span>
                        </td>
                        <td><?= e((string) ($__row['display']['type_titre'] ?? '')) ?></td>
                        <td><?= e((string) ($__row['display']['annee'] ?? '')) ?></td>
                        <td><?= e((string) ($__row['display']['numero_formulaire'] ?? '')) ?></td>
                        <td><?= e((string) ($__row['display']['priorite'] ?? '')) ?></td>
                        <td style="min-width: 260px;">
                            <?php if (!$__row['valid']): ?>
                            <ul class="mb-0 pl-3">
                                <?php foreach ($__row['errors'] as $__rowError): ?>
                                <li><?= e((string) $__rowError) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ((int) $__analysis['total'] > count($__previewRows)): ?>
            <div class="alert alert-light border-top rounded-0 mb-0">
                L’aperçu affiche en priorité les erreurs, dans la limite de <?= count($__previewRows) ?> lignes
                sur <?= (int) $__analysis['total'] ?>. Le rapport CSV contient toutes les lignes contrôlées.
            </div>
            <?php endif; ?>
        </div>

        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center">
            <form action="<?= url('formulaires/importer/annuler') ?>" method="post" class="mb-2 mb-md-0">
                <?= Csrf::field() ?>
                <button class="btn btn-outline-secondary" data-confirm="Annuler cet aperçu et supprimer le fichier temporaire ?">
                    <i class="fas fa-times mr-1"></i>Annuler l’import
                </button>
            </form>

            <?php if ((int) $__analysis['error_count'] === 0): ?>
            <form action="<?= url('formulaires/importer/confirmer') ?>" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="import_token" value="<?= e((string) $__state['token']) ?>">
                <button class="btn btn-success" data-confirm="Confirmer l’import atomique de <?= (int) $__analysis['valid_count'] ?> formulaire(s) ?">
                    <i class="fas fa-file-import mr-1"></i>Importer <?= (int) $__analysis['valid_count'] ?> formulaire(s)
                </button>
            </form>
            <?php else: ?>
            <div class="text-danger font-weight-bold">
                <i class="fas fa-ban mr-1"></i>Corrigez le fichier puis analysez-le de nouveau.
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
$__extra_js = <<<'HTML'
<script>
$(function () {
    $('.custom-file-input').on('change', function () {
        var name = this.files && this.files.length ? this.files[0].name : 'Choisir un fichier…';
        $(this).next('.custom-file-label').text(name);
    });
});
</script>
HTML;
?>
