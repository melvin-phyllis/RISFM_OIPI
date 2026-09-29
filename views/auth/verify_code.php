<?php
use App\Core\Csrf;
?>
<div class="card risfm-auth-card">
    <div class="card-body login-card-body">
        <div class="risfm-auth-brand is-compact">
            <img src="<?= appLogoUrl() ?>" alt="Logo <?= e(appName()) ?>" class="risfm-auth-logo">
        </div>
        <header class="risfm-auth-heading">
            <h1>Vérification de sécurité</h1>
            <p>Un code à 6 chiffres a été envoyé à <strong><?= e($maskedEmail) ?></strong>.</p>
        </header>

        <?php require BASE_PATH . '/views/partials/flash.php'; ?>

        <form action="<?= url('verification-code') ?>" method="post" autocomplete="off" class="risfm-auth-form">
            <?= Csrf::field() ?>
            <div class="form-group">
                <label for="code" class="d-block text-center">Code de sécurité</label>
                <input
                    id="code"
                    type="text"
                    name="code"
                    class="form-control risfm-otp-input"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    minlength="6"
                    maxlength="6"
                    placeholder="000000"
                    required
                    autofocus
                >
            </div>
            <button type="submit" class="btn btn-success btn-block risfm-auth-submit is-full">
                <i class="fas fa-shield-alt mr-1"></i>Vérifier et me connecter
            </button>
        </form>

        <div class="risfm-otp-actions risfm-auth-otp-actions">
            <form action="<?= url('verification-code/renvoyer') ?>" method="post">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-sm btn-outline-success" <?= $remainingSends < 1 ? 'disabled' : '' ?>>
                    <i class="fas fa-paper-plane mr-1"></i>Renvoyer le code
                </button>
            </form>
            <form action="<?= url('verification-code/annuler') ?>" method="post">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-sm btn-link text-muted">Annuler</button>
            </form>
        </div>

        <p class="risfm-auth-security-note">
            <i class="far fa-clock" aria-hidden="true"></i>
            Le code expire après 10 minutes. <?= (int) $remainingSends ?> renvoi(s) disponible(s).
            <?php if ((int) $resendIn > 0): ?>Un nouveau code pourra être demandé dans environ <?= (int) $resendIn ?> seconde(s).<?php endif; ?>
        </p>
    </div>
</div>
