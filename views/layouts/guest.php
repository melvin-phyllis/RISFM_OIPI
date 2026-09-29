<?php
use App\Core\Csrf;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= isset($__title) ? e($__title) . ' , ' : '' ?><?= e(appName()) ?></title>
    <meta name="csrf-token" content="<?= Csrf::token() ?>">
    <?php require BASE_PATH . '/views/partials/head_assets.php'; ?>
    <style>:root{--risfm-orange:<?= e(appColor('couleur_primaire', '#F68B1F')) ?>;--risfm-vert:<?= e(appColor('couleur_secondaire', '#00A651')) ?>;--risfm-noir:<?= e(appColor('couleur_accent', '#17352B')) ?>}</style>
</head>
<body class="hold-transition login-page risfm-auth-page">
<main class="login-box risfm-auth-shell" aria-label="Authentification RISFM">
    <?= $content ?>
</main>
<?php require BASE_PATH . '/views/partials/foot_assets.php'; ?>
</body>
</html>
