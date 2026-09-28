<div class="card risfm-auth-card">
    <div class="card-body login-card-body">
        <div class="risfm-auth-brand is-compact">
            <img src="<?= appLogoUrl() ?>" alt="Logo <?= e(appName()) ?>" class="risfm-auth-logo">
        </div>
        <header class="risfm-auth-heading">
            <h1>Protégez votre compte</h1>
            <p>Vous devez définir un nouveau mot de passe personnel avant de continuer.</p>
        </header>
        <?php require BASE_PATH . '/views/partials/flash.php'; ?>
        <form action="<?= url('profil/mot-de-passe') ?>" method="post" class="risfm-auth-form">
            <?= Csrf::field() ?>
            <label class="sr-only" for="force_password">Nouveau mot de passe</label>
            <div class="input-group risfm-auth-input">
                <input id="force_password" type="password" name="mot_de_passe" class="form-control" placeholder="Nouveau mot de passe" minlength="10" autocomplete="new-password" required autofocus>
                <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock" aria-hidden="true"></span></div></div>
            </div>
            <label class="sr-only" for="force_password_confirmation">Confirmer le mot de passe</label>
            <div class="input-group risfm-auth-input">
                <input id="force_password_confirmation" type="password" name="mot_de_passe_confirmation" class="form-control" placeholder="Confirmer le mot de passe" minlength="10" autocomplete="new-password" required>
                <div class="input-group-append"><div class="input-group-text"><span class="fas fa-check-circle" aria-hidden="true"></span></div></div>
            </div>
            <div class="risfm-password-policy"><i class="fas fa-shield-alt" aria-hidden="true"></i><span>10 caractères minimum, avec une majuscule, une minuscule, un chiffre et un caractère spécial.</span></div>
            <button type="submit" class="btn btn-success btn-block risfm-auth-submit is-full">Enregistrer et continuer</button>
        </form>
    </div>
</div>
