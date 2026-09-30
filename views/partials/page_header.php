<?php
$__pageIcons = [
    'dashboard' => 'fas fa-tachometer-alt',
    'formulaires' => 'fas fa-file-alt',
    'statistiques' => 'fas fa-chart-pie',
    'utilisateurs' => 'fas fa-users-cog',
    'journal' => 'fas fa-history',
    'connexions' => 'fas fa-network-wired',
    'notifications' => 'far fa-bell',
    'parametres' => 'fas fa-cogs',
    'sauvegardes' => 'fas fa-database',
    'profil' => 'fas fa-id-badge',
];
$__pageIcon = $__page_icon ?? ($__pageIcons[$__active ?? ''] ?? 'fas fa-layer-group');
$__pageSubtitle = trim((string) ($__subtitle ?? ''));
$__pageActions = is_array($__header_actions ?? null) ? $__header_actions : [];
$__pageTabs = is_array($__header_tabs ?? null) ? $__header_tabs : [];
?>
<div class="content-header risfm-page-header">
    <div class="container-fluid">
        <div class="risfm-page-header-inner">
            <div class="risfm-page-heading">
               
                <div class="risfm-page-heading-text">
                    <h1><?= e($__title ?? '') ?></h1>
                    <?php if ($__pageSubtitle !== ''): ?>
                        <p><?= e($__pageSubtitle) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($__pageActions !== [] || $__pageTabs !== [] || !empty($__breadcrumb)): ?>
                <div class="risfm-page-header-side">
                    <?php if ($__pageTabs !== []): ?>
                        <nav class="configuration-nav configuration-header-tabs" role="tablist" aria-label="Sections de configuration">
                            <?php foreach ($__pageTabs as $__tab): ?>
                                <?php $__tabActive = !empty($__tab['active']); ?>
                                <button
                                    type="button"
                                    id="<?= e((string) ($__tab['id'] ?? '')) ?>"
                                    class="<?= $__tabActive ? 'is-active' : '' ?>"
                                    role="tab"
                                    aria-selected="<?= $__tabActive ? 'true' : 'false' ?>"
                                    aria-controls="<?= e((string) ($__tab['panel'] ?? '')) ?>"
                                    data-configuration-panel="<?= e((string) ($__tab['panel'] ?? '')) ?>"
                                    <?= $__tabActive ? '' : 'tabindex="-1"' ?>
                                >
                                    <i class="<?= e((string) ($__tab['icon'] ?? 'fas fa-circle')) ?>" aria-hidden="true"></i>
                                    <span><?= e((string) ($__tab['label'] ?? 'Section')) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                    <?php endif; ?>
                    <?php if ($__pageActions !== []): ?>
                        <div class="risfm-page-actions">
                            <?php foreach ($__pageActions as $__action): ?>
                                <a href="<?= e((string) ($__action['url'] ?? '#')) ?>"
                                   class="btn <?= e((string) ($__action['class'] ?? 'btn-outline-secondary')) ?>"
                                   <?php if (!empty($__action['data_toggle'])): ?>data-toggle="<?= e((string) $__action['data_toggle']) ?>"<?php endif; ?>
                                   <?php if (!empty($__action['data_target'])): ?>data-target="<?= e((string) $__action['data_target']) ?>"<?php endif; ?>>
                                    <?php if (!empty($__action['icon'])): ?>
                                        <i class="<?= e((string) $__action['icon']) ?> mr-1" aria-hidden="true"></i>
                                    <?php endif; ?>
                                    <?= e((string) ($__action['label'] ?? 'Action')) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($__breadcrumb)): ?>
                        <ol class="breadcrumb mb-0">
                            <?php foreach ($__breadcrumb as $__label => $__link): ?>
                                <?php if (is_int($__label)): ?>
                                    <li class="breadcrumb-item active"><?= e((string) $__link) ?></li>
                                <?php else: ?>
                                    <li class="breadcrumb-item"><a href="<?= e((string) $__link) ?>"><?= e((string) $__label) ?></a></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
