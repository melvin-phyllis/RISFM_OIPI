<?php
use App\Core\Csrf;
?>
<?php
$__header_tabs = [
    ['id' => 'configuration-tab-identite', 'panel' => 'identite-application', 'label' => 'Identité et sécurité', 'icon' => 'fas fa-palette', 'active' => true],
    ['id' => 'configuration-tab-rappels', 'panel' => 'supervision-relances', 'label' => 'Rappels automatiques', 'icon' => 'fas fa-bell'],
    ['id' => 'configuration-tab-listes', 'panel' => 'listes-metier', 'label' => 'Listes métier', 'icon' => 'fas fa-list-ul'],
];
$__activeTypes = count(array_filter($types, static fn (array $item): bool => (int) ($item['actif'] ?? 1) === 1));
$__activeLocations = count(array_filter($localisations, static fn (array $item): bool => (int) ($item['actif'] ?? 1) === 1));
$__sessionMinutes = (int) ($parametres['session_lifetime_minutes'] ?? 20);
$__reminderSummary = $reminderHealth['summary'];
$__reminderKpiClass = match ((string) ($reminderHealth['class'] ?? 'warning')) {
    'success' => 'is-green',
    'danger' => 'is-alert',
    default => 'is-orange',
};
?>

<div class="oipi-dashboard configuration-analytics-shell">
    <header class="oipi-dashboard-hero configuration-analytics-hero" aria-labelledby="configuration-page-title">
        <div class="oipi-dashboard-hero-copy">
            <span class="oipi-dashboard-eyebrow"><i class="fas fa-cogs" aria-hidden="true"></i> Administration du logiciel</span>
            <h1 id="configuration-page-title">Configuration</h1>
            <p>Personnalisez l’identité, surveillez les automatisations et gérez les listes utilisées par le registre.</p>
        </div>
        <div class="oipi-dashboard-hero-actions configuration-hero-actions">
            <nav class="configuration-nav configuration-hero-tabs" role="tablist" aria-label="Sections de configuration">
                <?php foreach ($__header_tabs as $__tab): ?>
                    <?php $__tabActive = !empty($__tab['active']); ?>
                    <button
                        type="button"
                        id="<?= e((string) $__tab['id']) ?>"
                        class="<?= $__tabActive ? 'is-active' : '' ?>"
                        role="tab"
                        aria-selected="<?= $__tabActive ? 'true' : 'false' ?>"
                        aria-controls="<?= e((string) $__tab['panel']) ?>"
                        data-configuration-panel="<?= e((string) $__tab['panel']) ?>"
                        <?= $__tabActive ? '' : 'tabindex="-1"' ?>
                    >
                        <i class="<?= e((string) $__tab['icon']) ?>" aria-hidden="true"></i>
                        <span><?= e((string) $__tab['label']) ?></span>
                    </button>
                <?php endforeach; ?>
            </nav>
        </div>
    </header>

    <section class="oipi-dashboard-kpis configuration-overview-kpis" aria-label="Résumé de la configuration">
        <div class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-signature" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Identité du logiciel</span>
            <strong class="configuration-app-name"><?= e((string) ($parametres['app_nom'] ?? APP_NAME)) ?></strong>
            <small>Nom actuellement affiché</small>
        </div>
        <div class="oipi-dashboard-kpi is-orange">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-user-clock" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Expiration de session</span>
            <strong><?= $__sessionMinutes ?><small> min</small></strong>
            <small>Après une période d’inactivité</small>
        </div>
        <div class="oipi-dashboard-kpi <?= e($__reminderKpiClass) ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Rappels automatiques</span>
            <strong class="configuration-health-kpi-value"><?= e((string) $reminderHealth['label']) ?></strong>
            <small><?= (int) ($__reminderSummary['relances'] ?? 0) ?> rappel(s) au dernier passage</small>
        </div>
        <div class="oipi-dashboard-kpi is-soft">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-certificate" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Types de titres actifs</span>
            <strong><?= $__activeTypes ?></strong>
            <small><?= count($workflowStatusCodes) ?> statuts techniques protégés</small>
        </div>
        <div class="oipi-dashboard-kpi is-dark">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-map-marker-alt" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Localisations actives</span>
            <strong><?= $__activeLocations ?></strong>
            <small>Proposées lors des affectations</small>
        </div>
    </section>

    <div class="configuration-workspace">

<section id="identite-application" class="card configuration-card configuration-general-card configuration-panel is-active" role="tabpanel" aria-labelledby="configuration-tab-identite">
    <div class="card-header configuration-card-header">
        <div>
            <h3 class="card-title"><i class="fas fa-palette text-success mr-2" aria-hidden="true"></i>Identité et sécurité</h3>
            <span class="card-subtitle">Personnalisez l’apparence du logiciel et la durée des sessions.</span>
        </div>
        <button type="submit" form="form-parametres-generaux" class="btn btn-success">
            <i class="fas fa-save mr-1" aria-hidden="true"></i>Enregistrer les modifications
        </button>
    </div>
    <form id="form-parametres-generaux" action="<?= url('parametres/general') ?>" method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <div class="card-body">
            <div class="row">
                <div class="col-lg-8">
                    <div class="configuration-section-label">Informations affichées</div>
                    <div class="form-group">
                        <label for="configuration_app_nom">Nom de l’application <span class="text-danger">*</span></label>
                        <input id="configuration_app_nom" type="text" name="app_nom" class="form-control" maxlength="100" required value="<?= e($parametres['app_nom'] ?? APP_NAME) ?>">
                        <small class="form-text text-muted">Ce nom apparait dans la barre de navigation et le titre des pages.</small>
                    </div>

                    <div class="configuration-section-label mt-4">Couleurs de l’application</div>
                    <div class="configuration-color-grid">
                        <?php foreach ([
                            ['couleur_primaire', 'Orange principal', appColor('couleur_primaire', '#F68B1F')],
                            ['couleur_secondaire', 'Vert principal', appColor('couleur_secondaire', '#00A651')],
                            ['couleur_accent', 'Texte et accent foncé', appColor('couleur_accent', '#17352B')],
                        ] as $__color): ?>
                        <label class="configuration-color-control">
                            <input type="color" name="<?= e($__color[0]) ?>" value="<?= e($__color[2]) ?>" class="js-configuration-color">
                            <span><strong><?= e($__color[1]) ?></strong><code class="js-configuration-color-code"><?= e(strtoupper($__color[2])) ?></code></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="configuration-session-box">
                        <span class="configuration-session-icon"><i class="fas fa-user-clock" aria-hidden="true"></i></span>
                        <div>
                            <label for="configuration_session_lifetime">Expiration automatique d’une session</label>
                            <small>Un utilisateur inactif devra se reconnecter après cette durée.</small>
                        </div>
                        <div class="input-group configuration-session-input">
                            <input id="configuration_session_lifetime" type="number" min="5" max="120" name="session_lifetime_minutes" class="form-control" required value="<?= e($parametres['session_lifetime_minutes'] ?? '20') ?>">
                            <div class="input-group-append"><span class="input-group-text">minutes</span></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4 mt-4 mt-lg-0">
                    <div class="configuration-logo-panel">
                        <div class="configuration-section-label">Logo de l’application</div>
                        <div class="configuration-logo-preview">
                            <img src="<?= appLogoUrl() ?>" class="risfm-settings-logo js-configuration-logo-preview" alt="Logo actuellement utilise">
                        </div>
                        <label for="configuration_logo" class="btn btn-outline-secondary btn-sm mb-2">
                            <i class="fas fa-upload mr-1" aria-hidden="true"></i>Choisir un nouveau logo
                        </label>
                        <input id="configuration_logo" type="file" name="logo" class="sr-only js-configuration-logo-input" accept=".png,.jpg,.jpeg">
                        <span class="configuration-logo-filename js-configuration-logo-filename">Aucun nouveau fichier sélectionné</span>
                        <small>PNG ou JPG. Le logo actuel reste utilise tant que vous n’enregistrez pas un nouveau fichier.</small>
                    </div>
                </div>
            </div>
        </div>
    </form>
</section>

<section id="supervision-relances" class="card configuration-card configuration-reminder-card configuration-panel border-<?= e($reminderHealth['class']) ?>" role="tabpanel" aria-labelledby="configuration-tab-rappels">
    <div class="card-header configuration-card-header">
        <div>
            <h3 class="card-title"><i class="fas fa-bell text-<?= e($reminderHealth['class']) ?> mr-2"></i>Rappels automatiques</h3>
            <span class="card-subtitle">État de la vérification quotidienne des missions et de leurs échéances.</span>
        </div>
        <span class="configuration-health-status is-<?= e($reminderHealth['class']) ?>"><i class="fas fa-circle" aria-hidden="true"></i><?= e($reminderHealth['label']) ?></span>
    </div>
    <div class="card-body">
        <div class="configuration-health-grid">
            <div><span><i class="fas fa-play" aria-hidden="true"></i>Dernier démarrage</span><strong><?= $reminderHealth['last_started'] ? formatDate($reminderHealth['last_started']) : 'Jamais exécuté' ?></strong></div>
            <div><span><i class="fas fa-check" aria-hidden="true"></i>Dernière fin</span><strong><?= $reminderHealth['last_finished'] ? formatDate($reminderHealth['last_finished']) : 'Aucune exécution' ?></strong></div>
            <div><span><i class="fas fa-search-location" aria-hidden="true"></i>Missions analysées</span><strong><?= (int) ($__reminderSummary['missions'] ?? 0) ?></strong></div>
            <div><span><i class="fas fa-envelope" aria-hidden="true"></i>Rappels envoyés</span><strong><?= (int) ($__reminderSummary['relances'] ?? 0) ?> <small>· <?= (int) ($__reminderSummary['emails'] ?? 0) ?> e-mail(s)</small></strong></div>
        </div>
        <?php if (!empty($reminderHealth['error'])): ?>
        <div class="alert alert-danger mt-3 mb-0"><?= e($reminderHealth['error']) ?></div>
        <?php endif; ?>
        <?php if ($reminderHealth['state'] === 'never'): ?>
        <div class="configuration-health-alert is-warning mt-3">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            <div><strong>Le planificateur n’a encore jamais ete execute.</strong><span>Les rappels ne partiront pas automatiquement tant que la tache quotidienne n’est pas installee.</span></div>
        </div>
        <?php elseif ($reminderHealth['state'] === 'stale'): ?>
        <div class="configuration-health-alert is-danger mt-3">
            <i class="fas fa-times-circle" aria-hidden="true"></i>
            <div><strong>Le planificateur ne repond plus.</strong><span>La derniere execution est trop ancienne. Un diagnostic technique est necessaire.</span></div>
        </div>
        <?php endif; ?>

        <details class="configuration-technical-details mt-3">
            <summary><i class="fas fa-terminal mr-1" aria-hidden="true"></i>Diagnostic et installation technique</summary>
            <div>
                <p>Depuis le dossier du projet, verifiez d’abord l’etat de la tache :</p>
                <code>php scripts/reminder_scheduler.php status</code>
                <p class="mt-2">Si elle n’est pas installee :</p>
                <code>php scripts/reminder_scheduler.php install</code>
                <p class="mt-2 mb-0">Journal technique : <code>storage/logs/relances-cron.log</code></p>
            </div>
        </details>
    </div>
</section>

<section id="listes-metier" class="configuration-panel" role="tabpanel" aria-labelledby="configuration-tab-listes">
    <div class="configuration-section-heading">
        <div>
            <h2 id="listes-metier-titre"><i class="fas fa-list-ul text-success mr-2" aria-hidden="true"></i>Listes metier</h2>
            <p class="text-muted mb-0">Les elements desactives restent visibles dans les anciens dossiers mais ne sont plus proposes pour les nouvelles operations.</p>
        </div>
    </div>

    <details class="configuration-workflow-info mb-3">
        <summary><i class="fas fa-shield-alt mr-2" aria-hidden="true"></i><strong>Workflow des statuts protege</strong><span>Voir les regles</span></summary>
        <div>
            Les sept etapes techniques sont fixes :
            <strong>Introuvable → En recherche → A verifier → Retrouve → Numerise → Saisi → Archive</strong>.
            Seuls le libelle affiche et la couleur peuvent etre personnalises.
        </div>
    </details>
    <?php if (empty($statusWorkflowHealth['ok'])): ?>
    <div class="alert alert-danger">
        <strong>Configuration des statuts incohérente.</strong>
        La migration de sécurisation du workflow doit être appliquée.
        <ul class="mb-0 mt-2">
            <?php foreach (($statusWorkflowHealth['issues'] ?? []) as $__issue): ?>
            <li><?= e($__issue) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php elseif ((int) ($statusWorkflowHealth['legacy_count'] ?? 0) > 0): ?>
    <div class="alert alert-light border">
        <?= (int) $statusWorkflowHealth['legacy_count'] ?> ancien(s) statut(s) hors workflow sont conservés
        en lecture seule pour ne pas altérer l’historique.
    </div>
    <?php endif; ?>

    <div class="row">
        <?php
        $__renderListCard = function (string $title, string $type, array $items, string $icon) use ($workflowStatusCodes): void {
            $__isStatusList = $type === 'statuts';
        ?>
        <div class="col-xl-4 col-lg-6 mb-3">
            <div class="card h-100 parameter-list-card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h3 class="card-title mb-0"><i class="<?= e($icon) ?> text-success mr-1"></i><?= e($title) ?> <span class="badge badge-light ml-1"><?= count($items) ?></span></h3>
                    <?php if (!$__isStatusList): ?>
                    <button
                        type="button"
                        class="btn btn-sm btn-success js-parametre-liste"
                        data-toggle="modal"
                        data-target="#modal-parametre-liste"
                        data-mode="add"
                        data-type="<?= e($type) ?>"
                        data-title="<?= e($title) ?>"
                    ><i class="fas fa-plus mr-1"></i>Ajouter</button>
                    <?php else: ?>
                    <span class="badge badge-success"><i class="fas fa-shield-alt mr-1"></i>Workflow fixe</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0 parameter-list-scroll">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($items as $item): ?>
                        <?php
                        $__active = (int) ($item['actif'] ?? 1) === 1;
                        $__isSystemStatus = $__isStatusList
                            && in_array((string) ($item['code'] ?? ''), $workflowStatusCodes, true);
                        ?>
                        <li class="list-group-item parameter-list-item <?= $__active ? '' : 'is-inactive' ?>">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="pr-2">
                                    <div class="font-weight-bold"><?= e($item['libelle']) ?></div>
                                    <div class="small text-muted mt-1">
                                        <?php if ($type !== 'localisations'): ?>
                                        <code><?= e($item['code']) ?></code>
                                        <span class="mx-1">·</span>ordre <?= (int) $item['ordre'] ?>
                                        <?php endif; ?>
                                        <?php if ($type === 'statuts'): ?>
                                        <span class="badge badge-<?= e($item['couleur']) ?> ml-1"><?= e($item['couleur']) ?></span>
                                        <?php if ($__isSystemStatus): ?>
                                        <span class="badge badge-light border ml-1">Système</span>
                                        <span class="badge badge-<?= (int) $item['resolu'] === 1 ? 'success' : 'warning' ?> ml-1">
                                            <?= (int) $item['resolu'] === 1 ? 'Dossier résolu' : 'Dossier ouvert' ?>
                                        </span>
                                        <?php else: ?>
                                        <span class="badge badge-secondary ml-1">Hors workflow</span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge badge-<?= $__active ? 'success' : 'secondary' ?>"><?= $__active ? 'Actif' : 'Inactif' ?></span>
                            </div>
                            <div class="d-flex justify-content-end align-items-center mt-2">
                                <?php if (!$__isStatusList || $__isSystemStatus): ?>
                                <button
                                    type="button"
                                    class="btn btn-xs btn-outline-primary js-parametre-liste mr-1"
                                    title="Modifier"
                                    data-toggle="modal"
                                    data-target="#modal-parametre-liste"
                                    data-mode="edit"
                                    data-type="<?= e($type) ?>"
                                    data-title="<?= e($title) ?>"
                                    data-id="<?= (int) $item['id'] ?>"
                                    data-libelle="<?= e($item['libelle']) ?>"
                                    data-ordre="<?= (int) ($item['ordre'] ?? 0) ?>"
                                    data-couleur="<?= e($item['couleur'] ?? 'secondary') ?>"
                                    data-resolu="<?= (int) ($item['resolu'] ?? 0) ?>"
                                ><i class="fas fa-edit"></i></button>
                                <?php endif; ?>

                                <?php if ($__isSystemStatus): ?>
                                <button type="button" class="btn btn-xs btn-outline-secondary" title="Statut système toujours actif" disabled><i class="fas fa-lock"></i></button>
                                <?php elseif ($__isStatusList && !$__active): ?>
                                <button type="button" class="btn btn-xs btn-outline-secondary" title="Ancien statut conservé en lecture seule" disabled><i class="fas fa-history"></i></button>
                                <?php else: ?>
                                <form action="<?= url('parametres/liste/' . $type . '/statut/' . $item['id']) ?>" method="post" data-confirm="<?= $__active ? 'Desactiver cet element ? Il ne sera plus propose dans les nouvelles operations.' : 'Reactiver cet element ?' ?>">
                                    <?= Csrf::field() ?>
                                    <button class="btn btn-xs btn-outline-<?= $__active ? 'warning' : 'success' ?>" title="<?= $__active ? 'Desactiver' : 'Activer' ?>">
                                        <i class="fas fa-<?= $__active ? 'power-off' : 'check' ?>"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php
        };
        $__renderListCard('Types de titres', 'types_titres', $types, 'fas fa-certificate');
        $__renderListCard('Statuts du workflow', 'statuts', $statuts, 'fas fa-tags');
        $__renderListCard('Localisations', 'localisations', $localisations, 'fas fa-map-marker-alt');
        ?>
    </div>
</section>
</div>
</div>

<div class="modal fade" id="modal-parametre-liste" tabindex="-1" role="dialog" aria-labelledby="modal-parametre-liste-titre" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post" id="form-parametre-liste">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-parametre-liste-titre"><i class="fas fa-cog text-success mr-1"></i><span>Element de parametrage</span></h5>
                        <div class="small text-muted" id="modal-parametre-liste-sous-titre"></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="parametre_liste_libelle">Libelle <span class="text-danger">*</span></label>
                        <input id="parametre_liste_libelle" name="libelle" class="form-control" maxlength="150" required>
                    </div>
                    <div class="form-group js-order-field d-none">
                        <label for="parametre_liste_ordre">Ordre d’affichage</label>
                        <input id="parametre_liste_ordre" type="number" name="ordre" class="form-control" min="-32768" max="32767" value="0">
                        <small class="form-text text-muted" id="parametre-ordre-aide">Le code technique sera genere automatiquement et ne pourra plus etre modifie.</small>
                    </div>
                    <div class="form-row js-status-fields d-none">
                        <div class="form-group col-12">
                            <label for="parametre_liste_couleur">Couleur</label>
                            <select id="parametre_liste_couleur" name="couleur" class="form-control">
                                <?php foreach (['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark', 'light'] as $color): ?>
                                <option value="<?= $color ?>"><?= ucfirst($color) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="alert alert-info d-none mb-0" id="parametre-statut-warning">
                        <div class="font-weight-bold mb-1" id="parametre-statut-nature"></div>
                        Seuls le libellé affiché et la couleur sont personnalisables.
                        Le code, l’ordre, l’activation et les règles statistiques de cette étape sont protégés.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i><span id="parametre-submit-label">Enregistrer</span></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $__extra_js = '<script src="' . asset('js/parametres.js') . '"></script>'; ?>
