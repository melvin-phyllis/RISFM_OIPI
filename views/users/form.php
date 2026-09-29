<?php
use App\Core\Csrf;
?>
<div class="card">
    <div class="card-body">
        <form action="<?= $user ? url('utilisateurs/modifier/' . $user['id']) : url('utilisateurs/ajouter') ?>" method="post">
            <?= Csrf::field() ?>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>Identifiant de connexion</label>
                    <input
                        type="text"
                        class="form-control"
                        value="<?= e($user['identifiant'] ?? 'Généré automatiquement à l’enregistrement') ?>"
                        readonly
                        aria-describedby="identifiant-aide"
                    >
                    <small id="identifiant-aide" class="text-muted">
                        <?= $user
                            ? 'Cet identifiant unique n’est pas modifiable.'
                            : 'Format attribué par le système : OIPI-RISFM-000001.' ?>
                    </small>
                </div>
                <div class="form-group col-md-4">
                    <label>Nom</label>
                    <input type="text" name="nom" class="form-control" value="<?= e($user['nom'] ?? '') ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Prenoms</label>
                    <input type="text" name="prenoms" class="form-control" value="<?= e($user['prenoms'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>E-mail</label>
                    <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Telephone</label>
                    <input type="text" name="telephone" class="form-control" value="<?= e($user['telephone'] ?? '') ?>">
                </div>
                <div class="form-group col-md-4">
                    <label>Service</label>
                    <input type="text" name="service" class="form-control" value="<?= e($user['service'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group col-md-4 pl-0">
                <label>Role</label>
                <select name="role" class="form-control" required>
                    <option value="">-- Choisir --</option>
                    <?php foreach ($roles as $code => $r): ?>
                        <option value="<?= e($code) ?>" <?= ($user['role'] ?? '') === $code ? 'selected' : '' ?>><?= e($r['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($user && (int) ($user['missions_actives'] ?? 0) > 0): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Ce compte possede <?= (int) $user['missions_actives'] ?>
                    mission<?= (int) $user['missions_actives'] > 1 ? 's' : '' ?> active<?= (int) $user['missions_actives'] > 1 ? 's' : '' ?>.
                    Il doit conserver un role Administrateur, Responsable ou Agent jusqu’a leur reaffectation ou leur cloture.
                </div>
            <?php endif; ?>

            <?php if (!$user): ?>
                <div class="alert alert-info"><i class="fas fa-info-circle mr-1"></i>L’identifiant sera généré automatiquement. L’utilisateur recevra par e-mail un lien à usage unique pour choisir lui-même son mot de passe.</div>
            <?php endif; ?>

            <button class="btn btn-success"><i class="fas fa-save mr-1"></i>Enregistrer</button>
            <a href="<?= url('utilisateurs') ?>" class="btn btn-secondary">Annuler</a>
        </form>
    </div>
</div>
