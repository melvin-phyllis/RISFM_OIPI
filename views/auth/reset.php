<div class="card risfm-auth-card">
    <div class="card-body login-card-body">
        <div class="risfm-auth-brand is-compact">
            <img src="<?= appLogoUrl() ?>" alt="Logo <?= e(appName()) ?>" class="risfm-auth-logo">
        </div>
        <header class="risfm-auth-heading">
            <h1>Nouveau mot de passe</h1>
            <p>Choisissez un mot de passe personnel et difficile à deviner.</p>
        </header>
        <?php require BASE_PATH . '/views/partials/flash.php'; ?>
        <form action="<?= url('reinitialiser') ?>" method="post" class="risfm-auth-form">
            <?= Csrf::field() ?>
            <label class="sr-only" for="reset_password">Nouveau mot de passe</label>
            <div class="input-group risfm-auth-input">
                <input id="reset_password" type="password" name="mot_de_passe" class="form-control" placeholder="Nouveau mot de passe" minlength="10" autocomplete="new-password" required autofocus>
                <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock" aria-hidden="true"></span></div></div>
            </div>
            <label class="sr-only" for="reset_password_confirmation">Confirmer le mot de passe</label>
            <div class="input-group risfm-auth-input">
                <input id="reset_password_confirmation" type="password" name="mot_de_passe_confirmation" class="form-control" placeholder="Confirmer le mot de passe" minlength="10" autocomplete="new-password" required>
                <div class="input-group-append"><div class="input-group-text"><span class="fas fa-check-circle" aria-hidden="true"></span></div></div>
            </div>
            <div class="risfm-password-policy"><i class="fas fa-shield-alt" aria-hidden="true"></i><span>10 caractères minimum, avec une majuscule, une minuscule, un chiffre et un caractère spécial.</span></div>
            <button type="submit" class="btn btn-success btn-block risfm-auth-submit is-full"><i class="fas fa-key mr-1" aria-hidden="true"></i>Enregistrer le nouveau mot de passe</button>
        </form>
        <a href="<?= url('login') ?>" class="risfm-auth-back"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Retour à la connexion</a>
    </div>
</div>
