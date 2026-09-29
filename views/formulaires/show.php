<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Permission;
?>
<?php
$__priority = (string) ($formulaire['priorite'] ?? 'Normale');
$__isUrgent = $__priority === 'Urgente';
$__activeMissions = array_values(array_filter(
    $missions,
    static fn (array $mission): bool => !empty($mission['cycle_actif'])
        && in_array($mission['etat'], ['affectee', 'en_cours'], true)
));
$__historyDateLabel = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return 'Date non renseignée';
    }
    try {
        $moment = new DateTimeImmutable($value);
    } catch (Throwable) {
        return $value;
    }
    $today = new DateTimeImmutable('today');
    $day = $moment->format('Y-m-d');
    if ($day === $today->format('Y-m-d')) {
        return "Aujourd’hui, " . $moment->format('H:i');
    }
    if ($day === $today->modify('-1 day')->format('Y-m-d')) {
        return 'Hier, ' . $moment->format('H:i');
    }
    return $moment->format('d/m/Y, H:i');
};
$__statusCode = (string) ($formulaire['statut_code'] ?? '');
$__statusRanks = [
    'introuvable' => 0,
    'en_recherche' => 0,
    'a_verifier' => 0,
    'retrouve' => 1,
    'numerise' => 2,
    'saisi' => 3,
];
$__statusRank = $__statusRanks[$__statusCode] ?? 0;
$__progressRate = (int) round(($__statusRank / 3) * 100);
$__isArchived = (int) ($formulaire['est_archive'] ?? 0) === 1;
?>
<div class="oipi-dashboard formulaire-detail-workspace <?= $__isUrgent ? 'formulaire-is-urgent' : '' ?>">
    <header class="oipi-dashboard-hero formulaire-detail-hero" aria-labelledby="formulaire-detail-title">
        <div class="oipi-dashboard-hero-copy">
            <a href="<?= url('formulaires') ?>" class="formulaire-detail-back"><i class="fas fa-arrow-left" aria-hidden="true"></i> Registre des formulaires</a>
            <span class="oipi-dashboard-eyebrow"><i class="fas fa-folder-open" aria-hidden="true"></i> Dossier de suivi · Cycle <?= (int) ($formulaire['cycle_suivi'] ?? 1) ?></span>
            <h1 id="formulaire-detail-title"><?= e($formulaire['numero_formulaire']) ?></h1>
            <p><?= e($formulaire['type_libelle']) ?> · Année <?= e((string) $formulaire['annee']) ?> · Référence interne <?= e($formulaire['numero_auto']) ?></p>
            <div class="formulaire-detail-hero-badges">
                <span class="badge formulaire-status-badge badge-<?= e($formulaire['statut_couleur']) ?>">
                    <i class="fas fa-circle mr-1" aria-hidden="true"></i><?= e($formulaire['statut_libelle']) ?>
                </span>
                <span class="badge priority-badge <?= e(priorityBadgeClass($__priority)) ?>">
                    <i class="<?= e(priorityIconClass($__priority)) ?> mr-1" aria-hidden="true"></i>Priorité <?= e(strtolower($__priority)) ?>
                </span>
                <?php if ($__isArchived): ?><span class="badge badge-secondary"><i class="fas fa-archive mr-1" aria-hidden="true"></i>Archivé</span><?php endif; ?>
            </div>
        </div>
        <div class="oipi-dashboard-hero-actions formulaire-detail-hero-actions">
            <div class="formulaire-detail-progress" aria-label="<?= $__progressRate ?> pour cent du parcours de finalisation terminé">
                <span>Finalisation</span>
                <strong><?= $__statusRank ?><small>/3 étapes</small></strong>
                <div class="oipi-dashboard-progress"><span style="width: <?= $__progressRate ?>%"></span></div>
            </div>
            <div class="oipi-dashboard-action-row">
                <?php if ($canEditMetadata): ?>
                <button type="button" class="btn btn-success" data-toggle="modal" data-target="#modal-modifier-formulaire">
                    <i class="fas fa-edit mr-1" aria-hidden="true"></i> Modifier
                </button>
                <?php endif; ?>
                <div class="dropdown formulaire-detail-actions-dropdown">
                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="fas fa-ellipsis-h mr-1" aria-hidden="true"></i> Actions
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="#pieces-jointes" class="dropdown-item"><i class="fas fa-paperclip text-success mr-2" aria-hidden="true"></i>Pièces jointes</a>
                        <button type="button" class="dropdown-item" data-toggle="modal" data-target="#modal-historique-dossier"><i class="fas fa-history text-success mr-2" aria-hidden="true"></i>Voir l’historique</button>
                        <?php if ($canReopen): ?>
                        <button type="button" class="dropdown-item" data-toggle="modal" data-target="#modal-reouvrir-formulaire"><i class="fas fa-redo text-warning mr-2" aria-hidden="true"></i>Rouvrir le dossier</button>
                        <?php endif; ?>
                        <?php if (Permission::has((string) Auth::role(), 'formulaires.archive')): ?>
                            <div class="dropdown-divider"></div>
                            <?php if ($__isArchived): ?>
                            <a href="#restaurer-formulaire" class="dropdown-item text-success" data-toggle="collapse" data-target="#restaurer-formulaire"><i class="fas fa-undo mr-2" aria-hidden="true"></i>Restaurer le formulaire</a>
                            <?php else: ?>
                            <button type="button" class="dropdown-item text-danger" data-toggle="modal" data-target="#modal-archiver-formulaire"><i class="fas fa-archive mr-2" aria-hidden="true"></i>Archiver le formulaire</button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <section class="formulaire-detail-summary" aria-label="Résumé du dossier">
        <a href="#informations-formulaire-titre" class="formulaire-detail-summary-card is-status">
            <span class="formulaire-detail-summary-icon"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
            <span>Statut actuel</span><strong><?= e($formulaire['statut_libelle']) ?></strong><small>État global du formulaire</small>
        </a>
        <a href="#missions-recherche" class="formulaire-detail-summary-card <?= count($__activeMissions) > 0 ? 'is-orange' : '' ?>">
            <span class="formulaire-detail-summary-icon"><i class="fas fa-search-location" aria-hidden="true"></i></span>
            <span>Missions actives</span><strong><?= count($__activeMissions) ?></strong><small><?= count($__activeMissions) > 0 ? 'Recherche en cours' : 'Aucune affectation en cours' ?></small>
        </a>
        <a href="#compte-rendu-titre" class="formulaire-detail-summary-card">
            <span class="formulaire-detail-summary-icon"><i class="far fa-calendar-check" aria-hidden="true"></i></span>
            <span>Dernière recherche</span><strong class="is-date"><?= formatDate($formulaire['date_recherche'] ?? null, 'd/m/Y') ?></strong><small><?= e($formulaire['responsable_nom'] ?? 'Non assigné') ?></small>
        </a>
        <a href="#pieces-jointes" class="formulaire-detail-summary-card is-dark">
            <span class="formulaire-detail-summary-icon"><i class="fas fa-paperclip" aria-hidden="true"></i></span>
            <span>Pièces jointes</span><strong><?= count($pieces) ?></strong><small>Preuves et copies numériques</small>
        </a>
    </section>

<div class="card formulaire-detail-card <?= $__isUrgent ? 'formulaire-is-urgent' : '' ?>">
    <div class="card-body">
        <?php if ((int) ($formulaire['est_archive'] ?? 0) === 1): ?>
        <div class="alert alert-secondary border-left border-warning">
            <div class="font-weight-bold"><i class="fas fa-archive mr-1"></i>Formulaire archive</div>
            <div><?= e($formulaire['motif_archivage'] ?? 'Motif non renseigne') ?></div>
            <small>
                Archive le <?= formatDate($formulaire['archive_le'] ?? null) ?>
                par <?= e($formulaire['archive_par_nom'] ?? 'Utilisateur inconnu') ?>.
                Les donnees, recherches et pieces jointes sont conservees en lecture seule.
            </small>
        </div>
        <?php endif; ?>

        <div class="row formulaire-detail-layout">
            <div class="col-lg-7 col-xl-8 formulaire-detail-main">
                <section class="detail-surface-card detail-overview-card" aria-labelledby="informations-formulaire-titre">
                    <div class="detail-card-heading">
                        <div>
                            <span class="detail-card-eyebrow">Données du registre</span>
                            <h2 id="informations-formulaire-titre">Informations du formulaire</h2>
                        </div>
                        <span class="detail-reference"><i class="fas fa-fingerprint mr-1"></i><?= e($formulaire['numero_auto']) ?></span>
                    </div>

                    <dl class="detail-facts-grid">
                        <div><dt>Type de titre</dt><dd><?= e($formulaire['type_libelle']) ?></dd></div>
                        <div><dt>Année</dt><dd><?= e((string) $formulaire['annee']) ?></dd></div>
                        <div><dt>Numéro du formulaire</dt><dd><?= e($formulaire['numero_formulaire']) ?></dd></div>
                        <div><dt>Localisation recherchée</dt><dd><?= e($formulaire['localisation_libelle'] ?? '-') ?></dd></div>
                        <div><dt>Responsable</dt><dd><?= e($formulaire['responsable_nom'] ?? 'Non assigné') ?></dd></div>
                        <div><dt>Date de recherche</dt><dd><?= formatDate($formulaire['date_recherche'] ?? null, 'd/m/Y') ?></dd></div>
                    </dl>
                </section>

                <section class="detail-surface-card detail-report-card" aria-labelledby="compte-rendu-titre">
                    <div class="detail-card-heading is-compact">
                        <div>
                            <span class="detail-card-eyebrow">Dernière recherche</span>
                            <h2 id="compte-rendu-titre">Compte rendu</h2>
                        </div>
                        <i class="fas fa-clipboard-check detail-card-heading-icon" aria-hidden="true"></i>
                    </div>
                    <div class="detail-copy-box"><?= nl2br(e($formulaire['resultat'] ?? 'Aucun compte rendu renseigné.')) ?></div>
                </section>

                <?php
                $__showFinalization = in_array($__statusCode, ['retrouve', 'numerise', 'saisi'], true);
                if ($__showFinalization):
                $__finalisationByStep = [];
                foreach ($finalisations as $__finalisation) {
                    $__finalisationByStep[(string) $__finalisation['etape']] = $__finalisation;
                }
                $__workflowSteps = [
                    'retrouve' => [
                        'short_label' => 'Retrouvé',
                    ],
                    'numerise' => [
                        'short_label' => 'Numérisé',
                    ],
                    'saisi' => [
                        'short_label' => 'Saisi',
                    ],
                ];
                $__stepRanks = ['retrouve' => 1, 'numerise' => 2, 'saisi' => 3];
                $__currentStep = $nextFinalizationStep;
                if ($__currentStep === null && $__statusRank === 0 && (int) ($formulaire['est_archive'] ?? 0) === 0) {
                    $__currentStep = 'retrouve';
                }
                $__nextLabel = $nextFinalizationStep !== null
                    ? ($__workflowSteps[$nextFinalizationStep]['short_label'] ?? 'Étape suivante')
                    : 'Étape suivante';
                ?>
                <section id="finalisation-formulaire" class="card finalization-card mt-3" aria-labelledby="finalisation-formulaire-titre">
                    <div class="card-body finalization-compact-body">
                        <div class="finalization-compact-header">
                            <h5 id="finalisation-formulaire-titre" class="mb-0">Parcours de finalisation</h5>
                            <?php if ($canFinalize): ?>
                            <button
                                type="button"
                                class="btn btn-sm btn-success finalization-compact-action"
                                data-toggle="modal"
                                data-target="#modal-finalisation-formulaire"
                            ><i class="fas fa-check-circle mr-1"></i>Confirmer : <?= e($__nextLabel) ?></button>
                            <?php endif; ?>
                        </div>
                        <ol class="finalization-track" aria-label="Étapes de finalisation du formulaire">
                            <?php foreach ($__workflowSteps as $__stepCode => $__step): ?>
                            <?php
                            $__event = $__finalisationByStep[$__stepCode] ?? null;
                            $__isComplete = $__event !== null || $__statusRank >= $__stepRanks[$__stepCode];
                            $__isCurrent = !$__isComplete && $__currentStep === $__stepCode;
                            $__stateClass = $__isComplete ? 'is-complete' : ($__isCurrent ? 'is-current' : 'is-locked');
                            ?>
                            <li class="finalization-track-step <?= $__stateClass ?>"<?= $__isCurrent ? ' aria-current="step"' : '' ?>>
                                <span class="finalization-track-marker" aria-hidden="true">
                                    <?php if ($__isComplete): ?>
                                    <i class="fas fa-check"></i>
                                    <?php else: ?>
                                    <span><?= $__stepRanks[$__stepCode] ?></span>
                                    <?php endif; ?>
                                </span>
                                <strong><?= e($__step['short_label']) ?></strong>
                            </li>
                            <?php endforeach; ?>
                        </ol>
                    </div>
                </section>
                <?php endif; ?>

                <?php if (Permission::has((string) Auth::role(), 'formulaires.archive') && (int) ($formulaire['est_archive'] ?? 0) === 1): ?>
                <div id="restaurer-formulaire" class="collapse mt-4">
                    <div class="border border-success rounded p-3">
                        <h6 class="text-success"><i class="fas fa-undo mr-1"></i>Restaurer ce formulaire</h6>
                        <p class="text-muted small">Le formulaire sera replace dans le registre actif.</p>
                        <form action="<?= url('formulaires/restaurer/' . $formulaire['id']) ?>" method="post" data-confirm="Restaurer ce formulaire dans le registre actif ?">
                            <?= Csrf::field() ?>
                            <button class="btn btn-success"><i class="fas fa-undo mr-1"></i>Confirmer la restauration</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <div class="col-lg-5 col-xl-4 mt-4 mt-lg-0 formulaire-detail-context">
                <aside class="detail-sidebar" aria-label="Contexte du formulaire">
                    <section id="missions-recherche" class="detail-surface-card detail-sidebar-card" aria-labelledby="missions-recherche-titre">
                    <div class="detail-sidebar-card-header">
                        <div>
                            <h5 id="missions-recherche-titre" class="mb-1">
                                Missions de recherche actives
                                <span class="badge badge-success ml-1"><?= count($__activeMissions) ?></span>
                            </h5>
                        </div>
                    </div>

                    <div class="detail-sidebar-card-body research-history-list">
                        <?php foreach ($__activeMissions as $mission): ?>
                        <?php
                        $__etatLabel = match ($mission['etat']) {
                            'affectee' => 'À traiter',
                            'en_cours' => 'En cours',
                            default => $mission['etat'],
                        };
                        $__missionPriority = (string) ($mission['priorite'] ?? 'Normale');
                        $__missionDeadline = trim((string) ($mission['date_echeance'] ?? ''));
                        $__missionOverdue = $__missionDeadline !== '' && $__missionDeadline < date('Y-m-d');
                        $__missionDeadlineAlert = $__missionOverdue || $__missionPriority === 'Urgente';
                        ?>
                        <article id="mission-<?= (int) $mission['id'] ?>" class="active-mission-card <?= $__missionPriority === 'Urgente' ? 'is-urgent' : '' ?>">
                            <div class="active-mission-card-head">
                                <span class="badge badge-warning"><?= e($__etatLabel) ?></span>
                                <span class="badge priority-badge <?= e(priorityBadgeClass($__missionPriority)) ?>">
                                    <i class="<?= e(priorityIconClass($__missionPriority)) ?> mr-1" aria-hidden="true"></i><?= e($__missionPriority) ?>
                                </span>
                            </div>
                            <ul class="active-mission-facts">
                                <li>
                                    <i class="far fa-user" aria-hidden="true"></i>
                                    <span><strong>Responsable :</strong> <?= e($mission['responsable_nom']) ?></span>
                                </li>
                                <li>
                                    <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                                    <span><?= e($mission['localisation_libelle']) ?></span>
                                </li>
                                <li class="<?= $__missionDeadlineAlert ? 'is-alert' : '' ?>">
                                    <i class="far fa-calendar-alt" aria-hidden="true"></i>
                                    <span>
                                        <strong>Échéance :</strong>
                                        <?= $__missionDeadline !== '' ? e(formatDate($__missionDeadline, 'd/m/Y')) : 'Non définie' ?>
                                        <?= $__missionOverdue ? ' · En retard' : '' ?>
                                    </span>
                                </li>
                            </ul>
                            <?php if (!empty($mission['mission_parent_id'])): ?>
                            <div class="active-mission-transfer"><i class="fas fa-people-arrows mr-1"></i>Mission réaffectée</div>
                            <?php endif; ?>
                            <div class="active-mission-actions">
                                <?php if (!empty($mission['peut_saisir'])): ?>
                                <button
                                    class="btn btn-sm btn-success js-saisir-resultat-mission mr-1 mb-1"
                                    type="button"
                                    data-toggle="modal"
                                    data-target="#modal-resultat-mission"
                                    data-action="<?= url('missions-recherche/resultat/' . $mission['id']) ?>"
                                    data-mission-id="<?= (int) $mission['id'] ?>"
                                    data-localisation="<?= e($mission['localisation_libelle']) ?>"
                                    data-responsable="<?= e($mission['responsable_nom']) ?>"
                                ><i class="fas fa-clipboard-check mr-1"></i>Saisir le compte rendu</button>
                                <?php endif; ?>
                                <?php if ($canAssign): ?>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary js-reaffecter-mission mr-1 mb-1"
                                    data-toggle="modal"
                                    data-target="#modal-reaffecter-mission"
                                    data-action="<?= url('missions-recherche/reaffecter/' . $mission['id']) ?>"
                                    data-mission-id="<?= (int) $mission['id'] ?>"
                                    data-localisation="<?= e($mission['localisation_libelle']) ?>"
                                    data-responsable="<?= e($mission['responsable_nom']) ?>"
                                    data-priorite="<?= e($mission['priorite']) ?>"
                                    data-echeance="<?= e($mission['date_echeance'] ?? '') ?>"
                                ><i class="fas fa-people-arrows mr-1"></i>Réaffecter</button>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-danger js-annuler-mission mb-1"
                                    data-toggle="modal"
                                    data-target="#modal-annuler-mission"
                                    data-action="<?= url('missions-recherche/annuler/' . $mission['id']) ?>"
                                    data-mission-id="<?= (int) $mission['id'] ?>"
                                    data-localisation="<?= e($mission['localisation_libelle']) ?>"
                                    data-responsable="<?= e($mission['responsable_nom']) ?>"
                                ><i class="fas fa-ban mr-1"></i>Annuler</button>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                        <?php if (empty($__activeMissions)): ?>
                        <div class="research-history-empty is-compact">
                            <i class="fas fa-check-circle mb-2"></i>
                            <p class="mb-1 font-weight-bold">Aucune mission active</p>
                            <small>Les recherches terminées sont classées ci-dessous.</small>
                        </div>
                        <?php endif; ?>
                    </div>

                    </section>

                    <section id="pieces-jointes" class="detail-surface-card detail-sidebar-card detail-attachments-card" aria-labelledby="pieces-jointes-titre">
                        <div class="detail-sidebar-card-header">
                            <div>
                                <h5 id="pieces-jointes-titre" class="mb-1">
                                    <i class="fas fa-paperclip mr-1 text-success" aria-hidden="true"></i>
                                    Pièces jointes
                                    <span class="badge badge-light ml-1"><?= count($pieces) ?></span>
                                </h5>
                                <p class="text-muted small mb-0">Preuves et copies numérisées du dossier.</p>
                            </div>
                        </div>
                        <div class="detail-sidebar-card-body">
                            <?php if (!empty($pieces)): ?>
                            <ul class="detail-attachment-list">
                                <?php foreach ($pieces as $p): ?>
                                <li>
                                    <a href="<?= url('formulaires/piece-jointe/telecharger/' . $p['id']) ?>" class="detail-attachment-link" title="Télécharger <?= e($p['nom_original']) ?>">
                                        <span class="detail-attachment-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></span>
                                        <span><?= e($p['nom_original']) ?></span>
                                        <i class="fas fa-download ml-auto" aria-hidden="true"></i>
                                    </a>
                                    <?php if (!empty($p['peut_supprimer'])): ?>
                                    <form action="<?= url('formulaires/piece-jointe/supprimer/' . $p['id']) ?>" method="post" data-confirm="Supprimer cette pièce jointe ?">
                                        <?= Csrf::field() ?>
                                        <button class="btn btn-xs btn-outline-danger" title="Supprimer"><i class="fas fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php else: ?>
                            <div class="detail-empty-state is-small"><i class="far fa-file"></i><span>Aucune pièce jointe.</span></div>
                            <?php endif; ?>

                            <?php if ($canAttach): ?>
                            <form action="<?= url('formulaires/piece-jointe/' . $formulaire['id']) ?>" method="post" enctype="multipart/form-data" class="detail-attachment-upload">
                                <?= Csrf::field() ?>
                                <label for="piece-jointe-fichier" class="sr-only">Choisir une pièce jointe</label>
                                <input id="piece-jointe-fichier" type="file" name="piece" class="form-control-file form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                                <button class="btn btn-sm btn-outline-success btn-block"><i class="fas fa-upload mr-1"></i>Ajouter une pièce</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </section>

                </aside>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade formulaire-history-modal" id="modal-historique-dossier" tabindex="-1" role="dialog" aria-labelledby="modal-historique-dossier-titre" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="formulaire-history-modal-eyebrow"><i class="fas fa-history" aria-hidden="true"></i> Traçabilité du dossier</span>
                    <h2 class="modal-title" id="modal-historique-dossier-titre">Historique du dossier</h2>
                    <p><?= count($historiqueDossier) ?> événement(s) pour le formulaire <?= e($formulaire['numero_formulaire']) ?>, du plus récent au plus ancien.</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body detail-history-scroll formulaire-history-modal-scroll">
                <?php if (!empty($historiqueDossier)): ?>
                <div class="history-timeline">
                    <?php foreach ($historiqueDossier as $__event): ?>
                    <article class="history-timeline-item is-<?= e((string) $__event['tone']) ?>">
                        <span class="history-timeline-marker" aria-hidden="true"><i class="<?= e((string) $__event['icon']) ?>"></i></span>
                        <time datetime="<?= e((string) $__event['date']) ?>"><?= e($__historyDateLabel($__event['date'] ?? null)) ?></time>
                        <p class="history-timeline-message"><?= e((string) $__event['message']) ?></p>
                        <?php if (!empty($__event['detail'])): ?>
                        <div class="history-timeline-report">
                            <strong>Compte rendu</strong>
                            <span><?= nl2br(e((string) $__event['detail'])) ?></span>
                        </div>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="research-history-empty">
                    <i class="fas fa-route mb-2" aria-hidden="true"></i>
                    <p class="mb-0">Aucune action n'a encore été historisée pour ce dossier.</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Fermer</button>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/modals.php'; ?>
