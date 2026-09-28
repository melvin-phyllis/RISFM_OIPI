<div class="card risfm-auth-card risfm-auth-card-login">
    <div class="card-body login-card-body">
        <div class="risfm-auth-brand">
            <img src="<?= appLogoUrl() ?>" alt="Logo <?= e(appName()) ?>" class="risfm-auth-logo">
        </div>

        <header class="risfm-auth-heading is-intro">
            <p>Connectez-vous pour accéder à votre espace</p>
        </header>

        <?php require BASE_PATH . '/views/partials/flash.php'; ?>

        <?php if (!empty($__error)): ?>
            <div class="alert alert-danger risfm-auth-alert"><i class="fas fa-exclamation-circle mr-1" aria-hidden="true"></i><?= e($__error) ?></div>
        <?php endif; ?>

        <form action="<?= url('login') ?>" method="post" class="risfm-auth-form">
            <?= Csrf::field() ?>
            <label class="sr-only" for="auth_identifiant">Identifiant ou adresse e-mail</label>
            <div class="input-group risfm-auth-input">
                <input id="auth_identifiant" type="text" name="identifiant" class="form-control" placeholder="Identifiant ou adresse e-mail" maxlength="150" autocomplete="username" required autofocus value="<?= old('identifiant') ?>">
                <div class="input-group-append"><div class="input-group-text"><span class="fas fa-user" aria-hidden="true"></span></div></div>
            </div>
            <label class="sr-only" for="auth_mot_de_passe">Mot de passe</label>
            <div class="input-group risfm-auth-input">
                <input id="auth_mot_de_passe" type="password" name="mot_de_passe" class="form-control" placeholder="Mot de passe" autocomplete="current-password" required>
                <div class="input-group-append"><div class="input-group-text"><span class="fas fa-lock" aria-hidden="true"></span></div></div>
            </div>

            <?php if (!empty($__captcha)): ?>
            <div class="form-group risfm-auth-captcha">
                <label for="auth_captcha">Vérification anti-robot : combien font <?= (int) $__captcha['a'] ?> + <?= (int) $__captcha['b'] ?> ?</label>
                <input id="auth_captcha" type="number" name="captcha" class="form-control" inputmode="numeric" required>
            </div>
            <?php endif; ?>

            <div class="risfm-auth-actions">
                <a href="<?= url('mot-de-passe-oublie') ?>" class="risfm-auth-link">Mot de passe oublié&nbsp;?</a>
                <button type="submit" class="btn btn-success risfm-auth-submit">
                    <i class="fas fa-sign-in-alt mr-1" aria-hidden="true"></i>Connexion
                </button>
            </div>
        </form>

        <p class="risfm-auth-security-note">
            <i class="fas fa-shield-alt" aria-hidden="true"></i>
            Accès réservé au personnel autorisé de l’OIPI. Toute tentative non autorisée est journalisée.
        </p>
    </div>
</div>
