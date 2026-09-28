<?php
declare(strict_types=1);

$__flashMessages = [];
foreach (['success', 'error', 'warning', 'info'] as $__flashType) {
    $__flashMessage = getFlash($__flashType);
    if ($__flashMessage !== null) {
        $__flashMessages[] = [
            'type' => $__flashType,
            'message' => $__flashMessage,
        ];
    }
}
$__flashJson = json_encode(
    $__flashMessages,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<?php if ($__flashMessages !== []): ?>
<div
    id="risfm-flash-messages"
    hidden
    data-messages="<?= e(is_string($__flashJson) ? $__flashJson : '[]') ?>"
></div>
<noscript>
    <?php foreach ($__flashMessages as $__flash): ?>
    <?php $__alertClass = match ($__flash['type']) {
        'success' => 'success',
        'error' => 'danger',
        'warning' => 'warning',
        default => 'info',
    }; ?>
    <div class="alert alert-<?= $__alertClass ?> m-3" role="alert">
        <?= e($__flash['message']) ?>
    </div>
    <?php endforeach; ?>
</noscript>
<?php endif; ?>
