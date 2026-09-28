<?php
$activeAdminCount = count(array_filter(
    $users,
    static fn (array $item): bool => $item['role'] === 'administrateur' && (int) $item['actif'] === 1
));
$adminCount = count(array_filter(
    $users,
    static fn (array $item): bool => $item['role'] === 'administrateur'
));
$totalUsers = (int) ($userKpi['total'] ?? count($users));
$activeUsers = (int) ($userKpi['active'] ?? 0);
$inactiveUsers = (int) ($userKpi['inactive'] ?? 0);
$connectedUsers = (int) ($userKpi['connected'] ?? 0);
$activeMissionsCount = (int) ($userKpi['active_missions'] ?? 0);
$pendingPasswordCount = (int) ($userKpi['pending_password'] ?? 0);
$activeRate = pct($activeUsers, $totalUsers);
?>

<div class="oipi-dashboard users-workspace">
    <header class="oipi-dashboard-hero users-workspace-hero" aria-labelledby="users-page-title">
        <div class="oipi-dashboard-hero-copy">
            <span class="oipi-dashboard-eyebrow"><i class="fas fa-user-shield" aria-hidden="true"></i> Administration des accès</span>
            <h1 id="users-page-title">Utilisateurs</h1>
            <p>Gérez les comptes, les rôles et la sécurité d’accès au registre.</p>
        </div>
        <div class="oipi-dashboard-hero-actions">
            <div class="oipi-dashboard-campaign-rate" aria-label="<?= $activeRate ?> pour cent des comptes sont actifs">
                <span>Comptes actifs</span>
                <strong><?= $activeRate ?><small>%</small></strong>
                <div class="oipi-dashboard-progress"><span style="width: <?= min(100, $activeRate) ?>%"></span></div>
            </div>
            <div class="oipi-dashboard-action-row">
                <button type="button" class="btn btn-outline-secondary risfm-filter-launcher" data-toggle="modal" data-target="#users-filter-modal">
                    <i class="fas fa-filter mr-1" aria-hidden="true"></i> Filtres
                    <span class="risfm-filter-count d-none" id="users-filter-count">0</span>
                </button>
                <a href="<?= url('connexions') ?>" class="btn btn-outline-success">
                    <i class="fas fa-network-wired mr-1" aria-hidden="true"></i> Connexions
                </a>
                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#modal-ajouter-utilisateur">
                    <i class="fas fa-user-plus mr-1" aria-hidden="true"></i> Ajouter un utilisateur
                </button>
            </div>
        </div>
    </header>

    <section class="oipi-dashboard-kpis users-workspace-kpis" aria-label="Indicateurs des comptes utilisateurs">
        <div class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-users" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Comptes enregistrés</span>
            <strong><?= $totalUsers ?></strong>
            <small>Répertoire complet</small>
        </div>
        <div class="oipi-dashboard-kpi is-green">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-user-check" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Comptes actifs</span>
            <strong><?= $activeUsers ?></strong>
            <small><?= $inactiveUsers ?> désactivé<?= $inactiveUsers > 1 ? 's' : '' ?></small>
        </div>
        <div class="oipi-dashboard-kpi is-soft">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-signal" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Connectés maintenant</span>
            <strong><?= $connectedUsers ?></strong>
            <small>Activité de session récente</small>
        </div>
        <div class="oipi-dashboard-kpi <?= $activeMissionsCount > 0 ? 'is-orange' : 'is-soft' ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-search-location" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Missions actives</span>
            <strong><?= $activeMissionsCount ?></strong>
            <small>Charge actuellement affectée</small>
        </div>
        <div class="oipi-dashboard-kpi is-dark">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-user-shield" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Administrateurs</span>
            <strong><?= $adminCount ?></strong>
            <small><?= $pendingPasswordCount ?> accès à finaliser</small>
        </div>
    </section>

    <section class="oipi-dashboard-panel users-directory users-table-card" aria-labelledby="users-directory-title">
        <header class="oipi-dashboard-panel-header users-directory-header">
            <div>
                <h2 id="users-directory-title">Répertoire des utilisateurs</h2>
                <p>Consultez les rôles, l’état des comptes et les dernières connexions.</p>
            </div>
        </header>
        <div class="users-directory-body">
        <div class="table-responsive users-table-container">
        <table id="tbl-users" class="table w-100 users-directory-table">
            <thead>
            <tr>
                <th>Identifiant</th><th>Utilisateur</th><th>E-mail</th><th>Rôle</th>
                <th>Service</th><th>Statut</th><th>Dernière connexion</th><th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                    $activeMissions = (int) ($u['missions_actives'] ?? 0);
                    $relatedMissions = (int) ($u['missions_liees'] ?? 0);
                    $isSelf = (int) $u['id'] === (int) Auth::id();
                    $isLastActiveAdmin = $u['role'] === 'administrateur'
                        && (int) $u['actif'] === 1
                        && $activeAdminCount <= 1;
                    $isLastAdmin = $u['role'] === 'administrateur' && $adminCount <= 1;
                    $cannotDeactivate = $isSelf
                        || $isLastActiveAdmin
                        || ((int) $u['actif'] === 1 && $activeMissions > 0);
                    $cannotDelete = $isSelf || $isLastAdmin || $relatedMissions > 0;
                    $deactivateReason = $isSelf
                        ? 'Votre propre compte ne peut pas etre desactive.'
                        : ($isLastActiveAdmin
                            ? 'Le dernier administrateur actif ne peut pas etre desactive.'
                            : 'Reaffectez ou cloturez les missions actives avant de desactiver ce compte.');
                    $deleteReason = $isSelf
                        ? 'Votre propre compte ne peut pas etre supprime.'
                        : ($isLastAdmin
                            ? 'Le dernier administrateur ne peut pas etre supprime.'
                            : 'Ce compte appartient a l’historique des missions : desactivez-le au lieu de le supprimer.');
                ?>
                <tr>
                    <?php
                        $fullName = trim((string) $u['nom'] . ' ' . (string) $u['prenoms']);
                        $initials = mb_strtoupper(mb_substr((string) $u['nom'], 0, 1) . mb_substr((string) $u['prenoms'], 0, 1));
                    ?>
                    <td><span class="users-directory-id"><?= e($u['identifiant']) ?></span></td>
                    <td>
                        <div class="users-directory-person">
                            <span class="users-directory-avatar" aria-hidden="true"><?= e($initials !== '' ? $initials : 'U') ?></span>
                            <span><strong><?= e($fullName) ?></strong><small><?= e($u['telephone'] ?? 'Aucun téléphone') ?></small></span>
                        </div>
                    </td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="users-role-badge is-<?= e((string) $u['role']) ?>"><?= e($roles[$u['role']]['label'] ?? $u['role']) ?></span></td>
                    <td><?= e($u['service'] ?? '-') ?></td>
                    <td data-search="<?= (int) $u['actif'] === 1 ? 'Actif' : 'Désactivé' ?>">
                        <?php if ((int) $u['actif'] === 1): ?>
                            <span class="users-status-badge is-active"><i class="fas fa-check-circle" aria-hidden="true"></i> Actif</span>
                        <?php else: ?>
                            <span class="users-status-badge is-inactive"><i class="fas fa-ban" aria-hidden="true"></i> Désactivé</span>
                        <?php endif; ?>
                        <?php if (!empty($u['est_connecte'])): ?>
                            <div class="users-online-state"><span></span> En ligne</div>
                        <?php endif; ?>
                        <?php if ($activeMissions > 0): ?>
                            <div class="users-mission-state">
                                <i class="fas fa-search-location mr-1"></i><?= $activeMissions ?>
                                mission<?= $activeMissions > 1 ? 's' : '' ?> active<?= $activeMissions > 1 ? 's' : '' ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td data-order="<?= e((string) ($u['derniere_connexion'] ?? '')) ?>"><span class="users-last-login"><?= formatDate($u['derniere_connexion']) ?></span></td>
                    <td class="text-nowrap text-center">
                        <div class="dropdown d-inline-block -actions-dropdown user-actions-dropdown">
                            <button
                                type="button"
                                id="user-actions-<?= (int) $u['id'] ?>"
                                class="btn btn-sm btn-outline-secondary dropdown-toggle"
                                data-toggle="dropdown"
                                data-boundary="viewport"
                                aria-haspopup="true"
                                aria-expanded="false"
                                title="Afficher les actions"
                            >
                                <i class="fas fa-ellipsis-v" aria-hidden="true"></i>
                                <span class="sr-only">Actions pour <?= e($u['nom'] . ' ' . $u['prenoms']) ?></span>
                            </button>
                            <div class="dropdown-menu dropdown-menu-right" aria-labelledby="user-actions-<?= (int) $u['id'] ?>">
                                <a href="<?= url('utilisateurs/modifier/' . $u['id']) ?>" class="dropdown-item">
                                    <i class="fas fa-edit text-primary mr-2" aria-hidden="true"></i>Modifier
                                </a>

                                <form action="<?= url('utilisateurs/statut/' . $u['id']) ?>" method="post" class="m-0">
                                    <?= Csrf::field() ?>
                                    <button
                                        type="submit"
                                        class="dropdown-item <?= $cannotDeactivate ? 'disabled' : '' ?>"
                                        title="<?= $cannotDeactivate
                                            ? e($deactivateReason)
                                            : e((int) $u['actif'] === 1 ? 'Desactiver ce compte' : 'Activer ce compte') ?>"
                                        <?= $cannotDeactivate ? 'disabled aria-disabled="true"' : '' ?>
                                    >
                                        <i class="fas fa-power-off text-warning mr-2" aria-hidden="true"></i>
                                        <?= (int) $u['actif'] === 1 ? 'Désactiver' : 'Activer' ?>
                                    </button>
                                </form>

                                <form
                                    action="<?= url('utilisateurs/reinitialiser/' . $u['id']) ?>"
                                    method="post"
                                    class="m-0"
                                    data-confirm="Révoquer les accès actuels et envoyer un lien sécurisé à cet utilisateur ?"
                                >
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="dropdown-item">
                                        <i class="fas fa-paper-plane text-secondary mr-2" aria-hidden="true"></i>Renvoyer le lien d’accès
                                    </button>
                                </form>

                                <button
                                    type="button"
                                    class="dropdown-item btn-trigger-set-password"
                                    data-toggle="modal"
                                    data-target="#modal-mot-de-passe-utilisateur"
                                    data-user-id="<?= (int) $u['id'] ?>"
                                    data-user-name="<?= e($u['nom'] . ' ' . $u['prenoms']) ?>"
                                    data-user-identifiant="<?= e($u['identifiant']) ?>"
                                >
                                    <i class="fas fa-key text-info mr-2" aria-hidden="true"></i>Définir le mot de passe
                                </button>

                                <div class="dropdown-divider"></div>
                                <form
                                    action="<?= url('utilisateurs/supprimer/' . $u['id']) ?>"
                                    method="post"
                                    class="m-0"
                                    data-confirm="Supprimer definitivement cet utilisateur ?"
                                    data-confirm-title="Supprimer l’utilisateur"
                                    data-confirm-button="Supprimer"
                                    data-confirm-variant="danger"
                                >
                                    <?= Csrf::field() ?>
                                    <button
                                        type="submit"
                                        class="dropdown-item text-danger <?= $cannotDelete ? 'disabled' : '' ?>"
                                        title="<?= $cannotDelete
                                            ? e($deleteReason)
                                            : 'Supprimer' ?>"
                                        <?= $cannotDelete ? 'disabled aria-disabled="true"' : '' ?>
                                    >
                                        <i class="fas fa-trash mr-2" aria-hidden="true"></i>Supprimer
                                    </button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </section>
</div>

<div class="modal fade risfm-filter-modal users-filter-modal" id="users-filter-modal" tabindex="-1" role="dialog" aria-labelledby="users-filter-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="frm-users-filters">
                <div class="modal-header risfm-filter-modal-header">
                    <div>
                        <h5 class="modal-title" id="users-filter-title"><i class="fas fa-filter text-success mr-2" aria-hidden="true"></i>Filtrer les utilisateurs</h5>
                        <div class="small text-muted">Recherchez un compte et limitez le répertoire par rôle ou statut.</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="risfm-filter-grid">
                        <div class="form-group risfm-filter-wide">
                            <label for="users-keyword-filter">Recherche</label>
                            <div class="input-group">
                                <div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-search" aria-hidden="true"></i></span></div>
                                <input id="users-keyword-filter" type="search" class="form-control" placeholder="Nom, e-mail, identifiant, service…">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="users-role-filter">Rôle</label>
                            <select id="users-role-filter" class="form-control">
                                <option value="">Tous les rôles</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?= e((string) $role['label']) ?>"><?= e((string) $role['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="users-status-filter">Statut</label>
                            <select id="users-status-filter" class="form-control">
                                <option value="">Tous les statuts</option>
                                <option value="Actif">Actifs</option>
                                <option value="Désactivé">Désactivés</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer risfm-filter-modal-footer">
                    <button type="reset" id="btn-reset-users-filters" class="btn btn-outline-secondary"><i class="fas fa-undo mr-1" aria-hidden="true"></i>Réinitialiser</button>
                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-check mr-1" aria-hidden="true"></i>Appliquer</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade user-create-modal" id="modal-ajouter-utilisateur" tabindex="-1" role="dialog" aria-labelledby="modal-ajouter-utilisateur-titre" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <form action="<?= url('utilisateurs/ajouter') ?>" method="post" id="form-ajouter-utilisateur">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-ajouter-utilisateur-titre">
                            <i class="fas fa-user-plus text-success mr-1"></i>Ajouter un utilisateur
                        </h5>
                        <div class="small text-muted">Le système génère l’identifiant et envoie un lien sécurisé par e-mail.</div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-shield-alt mr-1"></i>
                        Aucun mot de passe n’est communiqué par l’administrateur. L’utilisateur choisira son mot de passe depuis un lien à usage unique valable 60 minutes.
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4">
                            <label for="nouvel_utilisateur_identifiant">Identifiant de connexion</label>
                            <input id="nouvel_utilisateur_identifiant" class="form-control" value="Généré à l’enregistrement" readonly>
                            <small class="form-text text-muted">Format : OIPI-RISFM-000001.</small>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="nouvel_utilisateur_nom">Nom <span class="text-danger">*</span></label>
                            <input id="nouvel_utilisateur_nom" type="text" name="nom" class="form-control" maxlength="100" value="<?= e($createOld['nom'] ?? '') ?>" required>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="nouvel_utilisateur_prenoms">Prénoms <span class="text-danger">*</span></label>
                            <input id="nouvel_utilisateur_prenoms" type="text" name="prenoms" class="form-control" maxlength="100" value="<?= e($createOld['prenoms'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="nouvel_utilisateur_email">E-mail <span class="text-danger">*</span></label>
                            <input id="nouvel_utilisateur_email" type="email" name="email" class="form-control" maxlength="190" value="<?= e($createOld['email'] ?? '') ?>" required>
                            <small class="form-text text-muted">Le lien d’activation sera envoyé à cette adresse.</small>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="nouvel_utilisateur_telephone">Téléphone</label>
                            <input id="nouvel_utilisateur_telephone" type="text" name="telephone" class="form-control" maxlength="30" value="<?= e($createOld['telephone'] ?? '') ?>">
                        </div>
                        <div class="form-group col-md-3">
                            <label for="nouvel_utilisateur_service">Service</label>
                            <input id="nouvel_utilisateur_service" type="text" name="service" class="form-control" maxlength="150" value="<?= e($createOld['service'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group mb-0">
                        <label for="nouvel_utilisateur_role">Rôle <span class="text-danger">*</span></label>
                        <select id="nouvel_utilisateur_role" name="role" class="form-control" required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($roles as $code => $role): ?>
                            <option value="<?= e($code) ?>" <?= ($createOld['role'] ?? '') === $code ? 'selected' : '' ?>><?= e($role['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-user-check mr-1"></i>Créer et envoyer l’invitation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-mot-de-passe-utilisateur" tabindex="-1" role="dialog" aria-labelledby="modal-mot-de-passe-label" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header users-password-modal-header">
                <h5 class="modal-title" id="modal-mot-de-passe-label">
                    <i class="fas fa-key mr-2"></i>Définir le mot de passe
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Fermer">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="form-mot-de-passe-utilisateur" action="" method="post">
                <?= Csrf::field() ?>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-user mr-1"></i>
                        Utilisateur : <strong id="modal-pwd-user-name">--</strong>
                        (<span id="modal-pwd-user-identifiant">--</span>)
                    </div>

                    <div class="form-group mb-3">
                        <label for="modal_nouveau_mot_de_passe">Nouveau mot de passe <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input
                                id="modal_nouveau_mot_de_passe"
                                type="password"
                                name="nouveau_mot_de_passe"
                                class="form-control"
                                required
                                minlength="10"
                                autocomplete="new-password"
                                placeholder="Saisir ou générer un mot de passe..."
                            >
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" id="btn-toggle-show-pwd" title="Afficher / Masquer">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button type="button" class="btn btn-outline-primary" id="btn-generate-pwd" title="Générer un mot de passe fort">
                                    <i class="fas fa-magic mr-1"></i>Générer
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted">
                            Min. 10 caractères (1 majuscule, 1 minuscule, 1 chiffre, 1 caractère spécial).
                        </small>
                    </div>

                    <div class="custom-control custom-checkbox mb-2">
                        <input type="checkbox" class="custom-control-input" id="modal_force_change" name="force_change" value="1" checked>
                        <label class="custom-control-label" for="modal_force_change">
                            Forcer le changement de mot de passe à la prochaine connexion
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check-circle mr-1"></i>Enregistrer le mot de passe
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $__extra_js = <<<'JS'
<script>
$(function () {
    var usersTable = $('#tbl-users').DataTable({
        language: { url: window.RISFM_BASE_URL + 'assets/vendor/datatables/i18n/fr-FR.json' },
        dom: '<"users-directory-toolbar"<"users-directory-export"B>>rt<"users-directory-footer"ip>',
        buttons: [
            { extend: 'copy', text: '<i class="fas fa-copy mr-1"></i>Copier' },
            { extend: 'excel', text: '<i class="fas fa-file-excel mr-1"></i>Excel' },
            { extend: 'csv', text: '<i class="fas fa-file-csv mr-1"></i>CSV' },
            { extend: 'print', text: '<i class="fas fa-print mr-1"></i>Imprimer' }
        ],
        order: [[6, 'desc']],
    });

    function applyUsersFilters() {
        var keyword = $('#users-keyword-filter').val() || '';
        var role = $.fn.dataTable.util.escapeRegex($('#users-role-filter').val() || '');
        var status = $.fn.dataTable.util.escapeRegex($('#users-status-filter').val() || '');
        usersTable.search(keyword);
        usersTable.column(3).search(role ? '^' + role + '$' : '', true, false);
        usersTable.column(5).search(status ? '^' + status + '$' : '', true, false);
        usersTable.draw();

        var count = [keyword, role, status].filter(function (value) { return String(value).trim() !== ''; }).length;
        $('#users-filter-count').text(count).toggleClass('d-none', count === 0);
    }

    $('#frm-users-filters').on('submit', function (event) {
        event.preventDefault();
        applyUsersFilters();
        $('#users-filter-modal').modal('hide');
    });

    $('#btn-reset-users-filters').on('click', function () {
        setTimeout(applyUsersFilters, 0);
    });

    var $createModal = $('#modal-ajouter-utilisateur');
    if (window.location.hash === '#ajouter-utilisateur') {
        $createModal.modal('show');
    }
    $createModal.on('shown.bs.modal', function () {
        $('#nouvel_utilisateur_nom').trigger('focus');
    });
    $createModal.on('hidden.bs.modal', function () {
        if (window.location.hash === '#ajouter-utilisateur' && window.history.replaceState) {
            window.history.replaceState(null, document.title, window.location.pathname + window.location.search);
        }
    });

    $(document).on('click', '.btn-trigger-set-password', function () {
        var userId = $(this).data('user-id');
        var userName = $(this).data('user-name');
        var userIdentifiant = $(this).data('user-identifiant');

        $('#modal-pwd-user-name').text(userName);
        $('#modal-pwd-user-identifiant').text(userIdentifiant);
        $('#form-mot-de-passe-utilisateur').attr('action', window.RISFM_BASE_URL + 'utilisateurs/mot-de-passe/' + userId);
        $('#modal_nouveau_mot_de_passe').val('').attr('type', 'password');
        $('#btn-toggle-show-pwd i').removeClass('fa-eye-slash').addClass('fa-eye');
    });

    $('#btn-toggle-show-pwd').on('click', function () {
        var $input = $('#modal_nouveau_mot_de_passe');
        var isPwd = $input.attr('type') === 'password';
        $input.attr('type', isPwd ? 'text' : 'password');
        $(this).find('i').toggleClass('fa-eye fa-eye-slash');
    });

    $('#btn-generate-pwd').on('click', function () {
        var uppers = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        var lowers = 'abcdefghijkmnpqrstuvwxyz';
        var nums = '23456789';
        var specials = '!@#$%';
        var pool = uppers + lowers + nums + specials;

        var pwd = [
            uppers[Math.floor(Math.random() * uppers.length)],
            lowers[Math.floor(Math.random() * lowers.length)],
            nums[Math.floor(Math.random() * nums.length)],
            specials[Math.floor(Math.random() * specials.length)]
        ];
        while (pwd.length < 12) {
            pwd.push(pool[Math.floor(Math.random() * pool.length)]);
        }
        pwd = pwd.sort(function () { return 0.5 - Math.random(); }).join('');
        var $input = $('#modal_nouveau_mot_de_passe');
        $input.attr('type', 'text').val(pwd);
        $('#btn-toggle-show-pwd i').removeClass('fa-eye').addClass('fa-eye-slash');
    });
});
</script>
JS;
?>
