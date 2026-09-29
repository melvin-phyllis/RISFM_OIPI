<?php
use App\Core\Csrf;
?>
<div class="card risfm-auth-card">
    <div class="card-body login-card-body">
        <div class="risfm-auth-brand is-compact">
            <img src="<?= appLogoUrl() ?>" alt="Logo <?= e(appName()) ?>" class="risfm-auth-logo">
        </div>
        <header class="risfm-auth-heading">
            <h1>Mot de passe oublié&nbsp;?</h1>
            <p>Recevez un lien sécurisé pour récupérer votre accès.</p>
        </header>
        <?php require BASE_PATH . '/views/partials/flash.php'; ?>
        <div class="risfm-auth-info">
            <i class="far fa-envelope" aria-hidden="true"></i>
            <p>Saisissez votre adresse e-mail professionnelle. Le lien envoyé restera valable pendant 60 minutes.</p>
        </div>
        <form action="<?= url('mot-de-passe-oublie') ?>" method="post" class="risfm-auth-form">
            <?= Csrf::field() ?>
            <label class="sr-only" for="forgot_email">Adresse e-mail professionnelle</label>
            <div class="input-group risfm-auth-input">
                <input id="forgot_email" type="email" name="email" class="form-control" placeholder="Adresse e-mail professionnelle" autocomplete="email" required autofocus>
                <div class="input-group-append"><div class="input-group-text"><span class="fas fa-envelope" aria-hidden="true"></span></div></div>
            </div>
            <button type="submit" class="btn btn-success btn-block risfm-auth-submit is-full"><i class="fas fa-paper-plane mr-1" aria-hidden="true"></i>Envoyer le lien sécurisé</button>
        </form>
        <a href="<?= url('login') ?>" class="risfm-auth-back"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Retour à la connexion</a>
    </div>
</div>
