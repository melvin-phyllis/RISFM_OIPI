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
<body class="hold-transition layout-top-nav">
<div class="wrapper">

    <?php require BASE_PATH . '/views/partials/navbar.php'; ?>

    <div class="content-wrapper<?= !empty($__hide_page_header) ? ' risfm-content-without-header' : '' ?>">
        <?php if (empty($__hide_page_header)): ?>
            <?php require BASE_PATH . '/views/partials/page_header.php'; ?>
        <?php endif; ?>

        <?php require BASE_PATH . '/views/partials/flash.php'; ?>

        <section class="content">
            <div class="container-fluid">
                <?= $content ?>
            </div>
        </section>
    </div>

    <?php require BASE_PATH . '/views/partials/footer.php'; ?>
</div>

<?php require BASE_PATH . '/views/partials/mission_login_reminder.php'; ?>
<?php require BASE_PATH . '/views/partials/foot_assets.php'; ?>
<script>window.RISFM_BASE_URL = "<?= url('') ?>";</script>
<script>window.RISFM_COLORS = <?= json_encode([
    'primary' => appColor('couleur_primaire', '#F68B1F'),
    'secondary' => appColor('couleur_secondaire', '#00A651'),
    'accent' => appColor('couleur_accent', '#17352B'),
], JSON_UNESCAPED_SLASHES) ?>;</script>
<?= isset($__extra_js) ? $__extra_js : '' ?>
</body>
</html>
