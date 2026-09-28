<?php
declare(strict_types=1);

$incidentId = isset($incidentId) ? (string) $incidentId : 'NON-DISPONIBLE';
$debugException = isset($debugException) && $debugException instanceof Throwable ? $debugException : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Erreur serveur - OIPI RISFM</title>
    <link rel="stylesheet" href="<?= asset('vendor/css/bootstrap.min.css') ?>">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-light">
<main class="container py-5">
    <div class="card border-0 shadow-sm mx-auto" style="max-width: 680px; border-top: 4px solid #F68B1F !important;">
        <div class="card-body p-4 p-md-5 text-center">
            <div class="display-4 font-weight-bold mb-3" style="color:#00A651;">500</div>
            <h1 class="h4 mb-3">Une erreur technique est survenue</h1>
            <p class="text-muted mb-2">
                Vous pouvez reessayer dans quelques instants. Si le probleme persiste,
                communiquez la reference ci-dessous a l'administrateur.
            </p>
            <p class="mb-4"><strong>Reference : <?= e($incidentId) ?></strong></p>
            <a class="btn text-white" style="background:#00A651;" href="<?= url('dashboard') ?>">Retour au tableau de bord</a>

            <?php if ($debugException !== null): ?>
                <details class="text-left mt-4">
                    <summary>Detail de developpement</summary>
                    <pre class="bg-dark text-white p-3 mt-2 small text-wrap"><?= e($debugException::class . ': ' . $debugException->getMessage()) ?></pre>
                </details>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
