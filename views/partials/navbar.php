<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Permission;
?>
<?php
$__notifCount = (int) ($__notifCount ?? 0);
$__role = (string) Auth::role();
$__active = $__active ?? '';
$__userName = trim((string) Auth::nom());
$__nameParts = preg_split('/\s+/u', $__userName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
$__userInitials = '';
foreach (array_slice($__nameParts, 0, 2) as $__namePart) {
    $__userInitials .= mb_strtoupper(mb_substr($__namePart, 0, 1));
}
$__userInitials = $__userInitials !== '' ? $__userInitials : 'U';
$__roleLabel = Permission::label($__role);
?>
<nav class="main-header navbar navbar-expand-xl navbar-white navbar-light risfm-navbar">
    <div class="container-fluid risfm-navbar-inner">
        <a href="<?= url('dashboard') ?>" class="navbar-brand risfm-navbar-brand">
            <img src="<?= appLogoUrl(true) ?>" alt="Logo RISFM" class="risfm-navbar-logo">
        </a>

        <button class="navbar-toggler risfm-navbar-toggler" type="button" data-toggle="collapse" data-target="#risfmNavbarMenu" aria-controls="risfmNavbarMenu" aria-expanded="false" aria-label="Afficher ou masquer la navigation">
            <span></span><span></span><span></span>
        </button>

        <div class="collapse navbar-collapse" id="risfmNavbarMenu">
            <ul class="navbar-nav risfm-menu" aria-label="Navigation principale">
                <li class="nav-item">
                    <a href="<?= url('dashboard') ?>" class="nav-link <?= $__active === 'dashboard' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i><span>Tableau de bord</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?= url('formulaires') ?>" class="nav-link <?= $__active === 'formulaires' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-file-alt"></i><span>Formulaires</span>
                    </a>
                </li>
                <?php if (Permission::has($__role, 'statistiques.view')): ?>
                <li class="nav-item">
                    <a href="<?= url('statistiques') ?>" class="nav-link <?= $__active === 'statistiques' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-chart-pie"></i><span>Statistiques</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($__role === 'administrateur'): ?>
                <li class="nav-item">
                    <a href="<?= url('utilisateurs') ?>" class="nav-link <?= $__active === 'utilisateurs' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-users-cog"></i><span>Utilisateurs</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (Permission::has($__role, 'journal.view')): ?>
                <li class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?= in_array($__active, ['journal', 'connexions'], true) ? 'active' : '' ?>" data-toggle="dropdown">
                        <i class="nav-icon fas fa-history"></i><span>Journal</span>
                    </a>
                    <div class="dropdown-menu">
                        <a href="<?= url('journal') ?>" class="dropdown-item"><i class="fas fa-history mr-2"></i>Journal d'activite</a>
                        <a href="<?= url('connexions') ?>" class="dropdown-item"><i class="fas fa-network-wired mr-2"></i>Historique connexions</a>
                    </div>
                </li>
                <?php endif; ?>
                <?php if (Permission::has($__role, 'formulaires.export')): ?>
                <li class="nav-item">
                    <a href="<?= url('formulaires') ?>#exports" class="nav-link <?= $__active === 'exports' ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-file-export"></i><span>Exports</span>
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($__role === 'administrateur'): ?>
                <li class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle <?= in_array($__active, ['parametres', 'sauvegardes'], true) ? 'active' : '' ?>" data-toggle="dropdown">
                        <i class="nav-icon fas fa-cogs"></i><span>Administration</span>
                    </a>
                    <div class="dropdown-menu">
                        <a href="<?= url('parametres') ?>" class="dropdown-item"><i class="fas fa-cogs mr-2"></i>Configuration</a>
                        <a href="<?= url('sauvegardes') ?>" class="dropdown-item"><i class="fas fa-database mr-2"></i>Sauvegardes</a>
                    </div>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav align-items-lg-center risfm-menu-actions">
                <li class="nav-item dropdown">
                    <a class="nav-link risfm-notification-trigger" data-toggle="dropdown" href="#" aria-label="Notifications : <?= (int) $__notifCount ?> non lue(s)" aria-haspopup="true" aria-expanded="false">
                        <i class="far fa-bell" aria-hidden="true"></i>
                        <span class="risfm-notification-label">Notifications</span>
                        <?php if ($__notifCount > 0): ?>
                            <span class="risfm-notification-count"><?= $__notifCount > 99 ? '99+' : (int) $__notifCount ?></span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right risfm-navbar-dropdown">
                        <span class="dropdown-item dropdown-header"><i class="far fa-bell mr-2" aria-hidden="true"></i><?= (int) $__notifCount ?> notification(s) non lue(s)</span>
                        <div class="dropdown-divider"></div>
                        <a href="<?= url('notifications') ?>" class="dropdown-item dropdown-footer">Voir toutes les notifications <i class="fas fa-arrow-right ml-1" aria-hidden="true"></i></a>
                    </div>
                </li>
                <li class="nav-item dropdown risfm-profile-menu">
                    <a class="nav-link risfm-profile-trigger" data-toggle="dropdown" href="#" aria-haspopup="true" aria-expanded="false">
                        <span class="risfm-profile-avatar" aria-hidden="true"><?= e($__userInitials) ?></span>
                        <span class="risfm-profile-copy">
                            <strong><?= e($__userName) ?></strong>
                            <small><?= e($__roleLabel) ?></small>
                        </span>
                        <i class="fas fa-chevron-down risfm-profile-chevron" aria-hidden="true"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right risfm-navbar-dropdown risfm-profile-dropdown">
                        <div class="risfm-profile-dropdown-header">
                            <span class="risfm-profile-avatar" aria-hidden="true"><?= e($__userInitials) ?></span>
                            <span><strong><?= e($__userName) ?></strong><small><?= e($__roleLabel) ?></small></span>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="<?= url('profil') ?>" class="dropdown-item <?= $__active === 'profil' ? 'active' : '' ?>"><i class="fas fa-id-badge mr-2"></i>Mon profil</a>
                        <div class="dropdown-divider"></div>
                        <form
                            action="<?= url('logout') ?>"
                            method="post"
                            class="m-0"
                            data-confirm="Voulez-vous vraiment vous deconnecter de l'application ?"
                            data-confirm-title="Deconnexion"
                            data-confirm-button="Oui, me deconnecter"
                            data-confirm-variant="danger"
                        >
                            <?= Csrf::field() ?>
                            <button type="submit" class="dropdown-item text-danger"><i class="fas fa-sign-out-alt mr-2"></i>Déconnexion</button>
                        </form>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>
