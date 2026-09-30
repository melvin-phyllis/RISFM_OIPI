<?php
/**
 * Champs de la declaration directe d'un formulaire retrouve.
 *
 * Variables attendues :
 * - $__retrouvePrefix  prefixe unique des identifiants HTML
 * - $__retrouveToggle  true : bloc replie derriere la case "Deja retrouve" (creation)
 * - $__retrouveValide  true : Retrouve directement ; false : A verifier
 * - $localisations     localisations actives
 */
$__retrouvePrefix = (string) ($__retrouvePrefix ?? 'retrouve');
$__retrouveToggle = (bool) ($__retrouveToggle ?? false);
$__retrouveValide = (bool) ($__retrouveValide ?? false);
$__retrouveFieldsId = $__retrouvePrefix . '_champs';
?>
<?php if ($__retrouveToggle): ?>
<div class="custom-control custom-checkbox mb-2">
    <input type="checkbox" class="custom-control-input js-retrouve-toggle" id="<?= e($__retrouvePrefix) ?>_toggle" name="deja_retrouve" value="1" data-target="#<?= e($__retrouveFieldsId) ?>">
    <label class="custom-control-label" for="<?= e($__retrouvePrefix) ?>_toggle">
        <strong>Formulaire déjà retrouvé</strong>
        <span class="text-muted">— je l’ai sous la main et j’indique où il était</span>
    </label>
</div>
<?php endif; ?>
<div id="<?= e($__retrouveFieldsId) ?>" class="border rounded p-3 mb-3 <?= $__retrouveToggle ? 'd-none' : '' ?>">
    <div class="form-row">
        <div class="form-group col-md-6">
            <label for="<?= e($__retrouvePrefix) ?>_localisation">Où a-t-il été retrouvé ? <span class="text-danger">*</span></label>
            <select id="<?= e($__retrouvePrefix) ?>_localisation" name="retrouve_localisation_id" class="form-control" required <?= $__retrouveToggle ? 'disabled' : '' ?>>
                <option value="">-- Choisir la localisation --</option>
                <?php foreach ($localisations as $__loc): ?>
                    <option value="<?= (int) $__loc['id'] ?>"><?= e($__loc['libelle']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group col-md-6">
            <label for="<?= e($__retrouvePrefix) ?>_date">Date de la découverte <span class="text-danger">*</span></label>
            <input id="<?= e($__retrouvePrefix) ?>_date" type="date" name="retrouve_date" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required <?= $__retrouveToggle ? 'disabled' : '' ?>>
        </div>
    </div>
    <div class="form-group">
        <label for="<?= e($__retrouvePrefix) ?>_precision">Précision <span class="text-muted small">(facultatif)</span></label>
        <input id="<?= e($__retrouvePrefix) ?>_precision" type="text" name="retrouve_precision" class="form-control" maxlength="200" placeholder="Ex. carton 12, étagère B" <?= $__retrouveToggle ? 'disabled' : '' ?>>
    </div>
    <div class="small <?= $__retrouveValide ? 'text-success' : 'text-warning' ?> mb-0">
        <i class="fas <?= $__retrouveValide ? 'fa-check-circle' : 'fa-hourglass-half' ?> mr-1" aria-hidden="true"></i>
        <?= $__retrouveValide
            ? 'Le formulaire passera directement au statut Retrouvé, à votre nom. Les missions en cours seront clôturées.'
            : 'Le formulaire passera au statut À vérifier : un responsable confirmera la découverte.' ?>
    </div>
</div>
