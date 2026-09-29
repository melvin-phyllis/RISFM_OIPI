<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Permission;
?>
<?php $__isCreation = empty($formulaire); ?>
<div class="card">
    <div class="card-body">
        <form action="<?= $formulaire ? url('formulaires/modifier/' . $formulaire['id']) : url('formulaires/ajouter') ?>" method="post">
            <?= Csrf::field() ?>

            <p class="text-muted small mb-3">
                Les champs marques d’un <span class="text-danger font-weight-bold">*</span> sont obligatoires.
            </p>

            <?php if ($__isCreation): ?>
            <div class="d-flex align-items-center mb-3">
                <span class="badge badge-light border mr-2">Etape 1</span>
                <h5 class="mb-0">Identifier le formulaire manquant</h5>
            </div>

            <div class="form-row">
                <div class="form-group col-md-5">
                    <label for="type_titre_id">Type de titre <span class="text-danger" aria-hidden="true">*</span><span class="sr-only"> (obligatoire)</span></label>
                    <select id="type_titre_id" name="type_titre_id" class="form-control" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= (int) $t['id'] ?>"><?= e($t['libelle']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label for="annee">Annee <span class="text-danger" aria-hidden="true">*</span><span class="sr-only"> (obligatoire)</span></label>
                    <select id="annee" name="annee" class="form-control" required>
                        <?php for ($y = (int) date('Y'); $y >= 2006; $y--): ?>
                            <option value="<?= $y ?>"><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group col-md-4">
                    <label for="numero_formulaire">Numero du formulaire <span class="text-danger" aria-hidden="true">*</span><span class="sr-only"> (obligatoire)</span></label>
                    <input id="numero_formulaire" type="text" name="numero_formulaire" class="form-control" maxlength="60" required>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6 mb-2 mb-md-0">
                    <div class="border rounded bg-light h-100 p-3">
                        <small class="text-muted d-block">Statut initial</small>
                        <span class="badge badge-<?= e((string) ($initialStatus['couleur'] ?? 'danger')) ?> mt-1">
                            <?= e((string) ($initialStatus['libelle'] ?? 'Introuvable')) ?>
                        </span>
                        <small class="text-muted d-block mt-2">Le statut evoluera avec les recherches enregistrees.</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded bg-light h-100 p-3">
                        <small class="text-muted d-block">Numero automatique</small>
                        <strong class="d-block mt-1">Genere apres l’enregistrement</strong>
                        <small class="text-muted d-block mt-2">Exemple : FM-<?= date('Y') ?>-000001</small>
                    </div>
                </div>
            </div>

            <div class="alert alert-success border-0">
                <i class="fas fa-arrow-right mr-1" aria-hidden="true"></i>
                <?php if (Permission::has((string) Auth::role(), 'formulaires.assign')): ?>
                    Apres l’enregistrement, vous pourrez affecter la recherche depuis la fiche du dossier.
                <?php else: ?>
                    Apres l’enregistrement, un responsable habilite devra affecter la recherche.
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-light border">
                <i class="fas fa-info-circle text-success mr-1" aria-hidden="true"></i>
                Cette page modifie uniquement les informations generales. Pour changer le statut, le responsable ou le compte rendu, ajoutez une recherche depuis la fiche du dossier.
            </div>

            <div class="form-row">
                <div class="form-group col-md-4">
                    <label for="type_titre_id">Type de titre <span class="text-danger" aria-hidden="true">*</span><span class="sr-only"> (obligatoire)</span></label>
                    <select id="type_titre_id" name="type_titre_id" class="form-control" required>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= (int) $t['id'] ?>" <?= (int) $formulaire['type_titre_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['libelle']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group col-md-2">
                    <label for="annee_affichee">Annee</label>
                    <input id="annee_affichee" type="text" class="form-control" value="<?= e((string) $formulaire['annee']) ?>" readonly>
                </div>
                <div class="form-group col-md-3">
                    <label for="numero_formulaire">Numero du formulaire <span class="text-danger" aria-hidden="true">*</span><span class="sr-only"> (obligatoire)</span></label>
                    <input id="numero_formulaire" type="text" name="numero_formulaire" class="form-control" maxlength="60" value="<?= e($formulaire['numero_formulaire']) ?>" required>
                </div>
                <div class="form-group col-md-3">
                    <label for="numero_auto">Numero automatique</label>
                    <input id="numero_auto" type="text" class="form-control" value="<?= e($formulaire['numero_auto']) ?>" readonly>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="priorite">Priorite <span class="text-danger" aria-hidden="true">*</span><span class="sr-only"> (obligatoire)</span></label>
                    <select id="priorite" name="priorite" class="form-control" required>
                        <?php foreach (['Basse', 'Normale', 'Haute', 'Urgente'] as $prio): ?>
                            <option value="<?= $prio ?>" <?= $formulaire['priorite'] === $prio ? 'selected' : '' ?>><?= $prio ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">La priorité détermine l’ordre de traitement de la recherche.</small>
                </div>
            </div>
            <?php endif; ?>

            <button class="btn btn-success">
                <i class="fas fa-save mr-1"></i><?= $__isCreation ? 'Enregistrer et continuer' : 'Enregistrer les modifications' ?>
            </button>
            <a href="<?= url('formulaires') ?>" class="btn btn-secondary">Annuler</a>
        </form>
    </div>
</div>
