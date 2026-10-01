<?php
use App\Core\Csrf;
?>
<?php
$__summary = $backupSummary ?? [];
$__latestAge = $backupSummary['latest_age_hours'] ?? null;
$__latestClass = $__latestAge === null ? 'is-alert' : ($__latestAge <= 24 ? 'is-green' : ($__latestAge <= 72 ? 'is-orange' : 'is-alert'));
$__integrityOk = !empty($databaseIntegrity['success']);
$__formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 1, ',', ' ') . ' Go';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
    }
    return number_format($bytes / 1024, 1, ',', ' ') . ' Ko';
};
$__latestLabel = !empty($__summary['latest_at']) ? formatDate((string) $__summary['latest_at'], 'd/m/Y') : 'Aucune';
$__latestHint = $__latestAge === null
    ? 'Aucun fichier disponible'
    : ($__latestAge < 1 ? 'Créée il y a moins d’une heure' : 'Créée il y a ' . $__latestAge . ' h');
?>

<div class="oipi-dashboard backup-workspace">
    <header class="oipi-dashboard-hero backup-workspace-hero" aria-labelledby="backup-page-title">
        <div class="oipi-dashboard-hero-copy">
            <h1 id="backup-page-title">Sauvegardes</h1>
            <p>Protégez les données du registre et restaurez une copie contrôlée en cas d’incident.</p>
        </div>
        <div class="oipi-dashboard-hero-actions">
            <div class="backup-protection-state <?= $__integrityOk ? 'is-ready' : 'is-danger' ?>">
                <span><?= $__integrityOk ? 'Base contrôlée' : 'Contrôle requis' ?></span>
                <strong><i class="fas <?= $__integrityOk ? 'fa-check-circle' : 'fa-exclamation-circle' ?>" aria-hidden="true"></i><?= $__integrityOk ? 'Protection opérationnelle' : 'Schéma incomplet' ?></strong>
            </div>
            <div class="oipi-dashboard-action-row">
                <button type="button" class="btn btn-outline-danger" data-toggle="modal" data-target="#backup-restore-modal">
                    <i class="fas fa-history mr-1" aria-hidden="true"></i> Restaurer
                </button>
                <form action="<?= url('sauvegardes/creer') ?>" method="post" class="m-0">
                    <?= Csrf::field() ?>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus mr-1" aria-hidden="true"></i> Nouvelle sauvegarde
                    </button>
                </form>
            </div>
        </div>
    </header>

    <section class="oipi-dashboard-kpis backup-overview-kpis" aria-label="État des sauvegardes">
        <div class="oipi-dashboard-kpi is-primary">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-database" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Copies enregistrées</span>
            <strong><?= (int) ($__summary['available'] ?? 0) ?></strong>
            <small><?= (int) ($__summary['total'] ?? 0) ?> entrée(s) dans l’historique</small>
        </div>
        <div class="oipi-dashboard-kpi <?= e($__latestClass) ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="far fa-clock" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Dernière sauvegarde</span>
            <strong class="backup-kpi-date"><?= e($__latestLabel) ?></strong>
            <small><?= e($__latestHint) ?></small>
        </div>
        <div class="oipi-dashboard-kpi is-soft">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-hdd" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Espace utilisé</span>
            <strong class="backup-kpi-size"><?= e($__formatBytes((int) ($__summary['total_bytes'] ?? 0))) ?></strong>
            <small>Fichiers disponibles sur le serveur</small>
        </div>
        <div class="oipi-dashboard-kpi <?= $__integrityOk ? 'is-green' : 'is-alert' ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-project-diagram" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Intégrité du schéma</span>
            <strong class="backup-kpi-status"><?= $__integrityOk ? 'Conforme' : 'À vérifier' ?></strong>
            <small><?= (int) ($__summary['manifest_objects'] ?? 0) ?> objet(s) critiques contrôlés</small>
        </div>
        <div class="oipi-dashboard-kpi <?= $execDisponible ? 'is-dark' : 'is-orange' ?>">
            <span class="oipi-dashboard-kpi-icon"><i class="fas fa-terminal" aria-hidden="true"></i></span>
            <span class="oipi-dashboard-kpi-label">Moteur de restauration</span>
            <strong class="backup-kpi-status"><?= $execDisponible ? 'Disponible' : 'Limité' ?></strong>
            <small><?= $execDisponible ? 'Test temporaire activé' : 'Export PDO uniquement' ?></small>
        </div>
    </section>

    <?php if (!$__integrityOk || !$execDisponible || (int) ($__summary['missing'] ?? 0) > 0): ?>
    <aside class="backup-system-banner <?= !$__integrityOk ? 'is-danger' : 'is-warning' ?>" role="status">
        <i class="fas <?= !$__integrityOk ? 'fa-exclamation-circle' : 'fa-info-circle' ?>" aria-hidden="true"></i>
        <div>
            <?php if (!$__integrityOk): ?>
                <strong>Le schéma actuel n’est pas conforme au manifeste de sauvegarde.</strong>
                <span><?= e((string) ($databaseIntegrity['error'] ?? 'Le contrôle du schéma n’a pas pu être effectué.')) ?></span>
            <?php elseif (!$execDisponible): ?>
                <strong>Le serveur fonctionne en mode de sauvegarde limité.</strong>
                <span><code>proc_open()</code> est indisponible : la sauvegarde PDO reste possible, mais la restauration automatique est désactivée.</span>
            <?php else: ?>
                <strong><?= (int) $__summary['missing'] ?> fichier(s) référencé(s) ne sont plus présent(s) sur le serveur.</strong>
                <span>Ces entrées restent visibles dans l’historique, mais ne peuvent plus être téléchargées.</span>
            <?php endif; ?>
        </div>
    </aside>
    <?php endif; ?>

    <div class="backup-content-grid">
        <section class="oipi-dashboard-panel backup-history-panel" aria-labelledby="backup-history-title">
            <header class="oipi-dashboard-panel-header backup-history-header">
                <div>
                    <h2 id="backup-history-title">Historique des sauvegardes</h2>
                    <p>Téléchargez les copies disponibles et identifiez les sauvegardes créées avant une restauration.</p>
                </div>
                <span class="backup-history-count"><?= count($sauvegardes) ?> fichier<?= count($sauvegardes) > 1 ? 's' : '' ?></span>
            </header>
            <div class="table-responsive backup-table-wrap">
                <table class="table backup-history-table mb-0">
                    <thead>
                    <tr><th>Sauvegarde</th><th>Créée par</th><th>Date</th><th>Taille</th><th>État</th><th class="text-center">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sauvegardes as $s): ?>
                        <?php $__available = !empty($s['fichier_disponible']); ?>
                        <tr>
                            <td>
                                <div class="backup-file-cell">
                                    <span class="backup-file-icon <?= !empty($s['sauvegarde_securite']) ? 'is-safety' : '' ?>"><i class="fas fa-file-code" aria-hidden="true"></i></span>
                                    <span>
                                        <strong><?= e((string) $s['nom_fichier']) ?></strong>
                                        <small><?= !empty($s['sauvegarde_securite']) ? 'Copie automatique de sécurité' : 'Sauvegarde du registre' ?></small>
                                    </span>
                                </div>
                            </td>
                            <td><span class="backup-creator"><i class="far fa-user" aria-hidden="true"></i><?= e((string) ($s['createur_nom'] ?? 'Système')) ?></span></td>
                            <td data-order="<?= e((string) $s['cree_le']) ?>"><strong class="backup-date"><?= formatDate((string) $s['cree_le'], 'd/m/Y') ?></strong><small class="backup-time"><?= formatDate((string) $s['cree_le'], 'H:i') ?></small></td>
                            <td><?= e($__formatBytes((int) ($s['taille_octets'] ?? 0))) ?></td>
                            <td>
                                <span class="backup-file-state <?= $__available ? 'is-available' : 'is-missing' ?>">
                                    <i class="fas <?= $__available ? 'fa-check-circle' : 'fa-times-circle' ?>" aria-hidden="true"></i><?= $__available ? 'Disponible' : 'Introuvable' ?>
                                </span>
                            </td>
                            <td class="text-center text-nowrap">
                                <div class="dropdown d-inline-block backup-actions-dropdown">
                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-toggle="dropdown" data-boundary="viewport" aria-haspopup="true" aria-expanded="false" title="Afficher les actions">
                                        <i class="fas fa-ellipsis-v" aria-hidden="true"></i><span class="sr-only">Actions pour <?= e((string) $s['nom_fichier']) ?></span>
                                    </button>
                                    <div class="dropdown-menu dropdown-menu-right">
                                        <?php if ($__available): ?>
                                        <a href="<?= url('sauvegardes/telecharger/' . urlencode((string) $s['nom_fichier'])) ?>" class="dropdown-item">
                                            <i class="fas fa-download text-success mr-2" aria-hidden="true"></i>Télécharger
                                        </a>
                                        <?php else: ?>
                                        <span class="dropdown-item disabled"><i class="fas fa-download mr-2" aria-hidden="true"></i>Téléchargement indisponible</span>
                                        <?php endif; ?>
                                        <div class="dropdown-divider"></div>
                                        <form action="<?= url('sauvegardes/supprimer/' . urlencode((string) $s['nom_fichier'])) ?>" method="post" class="m-0" data-confirm="Supprimer définitivement ce fichier de sauvegarde et son entrée d’historique ?" data-confirm-title="Supprimer la sauvegarde" data-confirm-button="Oui, supprimer" data-confirm-variant="danger">
                                            <?= Csrf::field() ?>
                                            <button type="submit" class="dropdown-item text-danger"><i class="fas fa-trash-alt mr-2" aria-hidden="true"></i>Supprimer</button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sauvegardes)): ?>
                        <tr><td colspan="6">
                            <div class="backup-empty-state"><i class="fas fa-database" aria-hidden="true"></i><strong>Aucune sauvegarde disponible</strong><span>Créez la première copie du registre pour commencer l’historique de protection.</span></div>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="oipi-dashboard-panel backup-readiness-panel" aria-labelledby="backup-readiness-title">
            <header class="oipi-dashboard-panel-header">
                <div>
                    <h2 id="backup-readiness-title">Protection de la restauration</h2>
                    <p>Contrôles appliqués avant tout remplacement de données.</p>
                </div>
            </header>
            <div class="backup-readiness-list">
                <div class="<?= $__integrityOk ? 'is-complete' : 'is-blocked' ?>"><span><i class="fas fa-sitemap" aria-hidden="true"></i></span><p><strong>Structure contrôlée</strong><small>Tables, vues, procédure et triggers requis.</small></p><i class="fas <?= $__integrityOk ? 'fa-check-circle' : 'fa-times-circle' ?>" aria-hidden="true"></i></div>
                <div class="<?= $execDisponible ? 'is-complete' : 'is-blocked' ?>"><span><i class="fas fa-vial" aria-hidden="true"></i></span><p><strong>Test en base temporaire</strong><small>Le fichier est importé dans un espace isolé.</small></p><i class="fas <?= $execDisponible ? 'fa-check-circle' : 'fa-times-circle' ?>" aria-hidden="true"></i></div>
                <div class="is-complete"><span><i class="fas fa-life-ring" aria-hidden="true"></i></span><p><strong>Copie de secours</strong><small>Une sauvegarde testée est créée avant l’import.</small></p><i class="fas fa-check-circle" aria-hidden="true"></i></div>
                <div class="is-complete"><span><i class="fas fa-user-lock" aria-hidden="true"></i></span><p><strong>Validation administrateur</strong><small>Mot de passe, phrase critique et confirmation requis.</small></p><i class="fas fa-check-circle" aria-hidden="true"></i></div>
            </div>
            <div class="backup-readiness-footer">
                <span><i class="fas fa-file-upload" aria-hidden="true"></i>Fichier SQL jusqu’à <strong><?= (int) BACKUP_MAX_MB ?> Mo</strong></span>
                <button type="button" class="btn btn-outline-danger btn-block" data-toggle="modal" data-target="#backup-restore-modal">
                    <i class="fas fa-history mr-1" aria-hidden="true"></i> Ouvrir la restauration
                </button>
            </div>
        </aside>
    </div>
</div>

<div class="modal fade backup-restore-modal" id="backup-restore-modal" tabindex="-1" role="dialog" aria-labelledby="backup-restore-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <span class="backup-modal-eyebrow"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Action critique</span>
                    <h2 class="modal-title" id="backup-restore-title">Restaurer une sauvegarde</h2>
                    <p>Cette opération peut remplacer toutes les données actuelles du registre.</p>
                </div>
                <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
            </div>
            <form action="<?= url('sauvegardes/restaurer') ?>" method="post" enctype="multipart/form-data" data-confirm="Dernière confirmation : tester le fichier puis remplacer la base actuelle ?" data-confirm-title="Confirmer la restauration" data-confirm-button="Tester et restaurer" data-confirm-variant="danger">
                <?= Csrf::field() ?>
                <div class="modal-body">
                    <div class="backup-restore-flow" aria-label="Étapes de sécurité">
                        <span><b>1</b>Valider le fichier</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                        <span><b>2</b>Tester la copie</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                        <span><b>3</b>Sauvegarder l’existant</span><i class="fas fa-chevron-right" aria-hidden="true"></i>
                        <span><b>4</b>Restaurer</span>
                    </div>
                    <?php if (!$execDisponible): ?>
                    <div class="backup-modal-blocker"><i class="fas fa-ban" aria-hidden="true"></i><div><strong>Restauration automatique indisponible</strong><span><code>proc_open()</code> doit être activé sur le serveur pour exécuter le test temporaire.</span></div></div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="backup_sql_file">Fichier SQL <span class="text-danger">*</span></label>
                        <label class="backup-upload-zone" for="backup_sql_file">
                            <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
                            <strong>Sélectionner une sauvegarde SQL</strong>
                            <span class="js-backup-file-name">Fichier .sql · <?= (int) BACKUP_MAX_MB ?> Mo maximum</span>
                        </label>
                        <input id="backup_sql_file" type="file" name="fichier_sql" class="sr-only js-backup-file-input" accept=".sql,text/plain,application/sql" required <?= $execDisponible ? '' : 'disabled' ?>>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label for="confirmation_critique">Phrase de confirmation <span class="text-danger">*</span></label>
                            <input id="confirmation_critique" type="text" name="confirmation_critique" class="form-control" placeholder="RESTAURER OIPI" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="confirmation_critique_aide" required <?= $execDisponible ? '' : 'disabled' ?>>
                            <small id="confirmation_critique_aide">Saisissez manuellement et exactement <code>RESTAURER OIPI</code>. Le copier-coller est désactivé.</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label for="mot_de_passe_restauration">Votre mot de passe actuel <span class="text-danger">*</span></label>
                            <input id="mot_de_passe_restauration" type="password" name="mot_de_passe_actuel" class="form-control" autocomplete="current-password" required <?= $execDisponible ? '' : 'disabled' ?>>
                            <small>Une nouvelle authentification est exigée.</small>
                        </div>
                    </div>
                    <div class="custom-control custom-checkbox backup-critical-check">
                        <input type="checkbox" class="custom-control-input" id="confirmer_perte_donnees" name="confirmer_perte_donnees" value="1" required <?= $execDisponible ? '' : 'disabled' ?>>
                        <label class="custom-control-label" for="confirmer_perte_donnees">Je comprends que cette action est critique et peut remplacer toutes les données.</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger" <?= $execDisponible ? '' : 'disabled' ?>><i class="fas fa-shield-alt mr-1" aria-hidden="true"></i>Tester, sécuriser puis restaurer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var input = document.querySelector('.js-backup-file-input');
    var label = document.querySelector('.js-backup-file-name');
    if (input && label) {
        input.addEventListener('change', function () {
            label.textContent = input.files && input.files[0] ? input.files[0].name : 'Fichier .sql · <?= (int) BACKUP_MAX_MB ?> Mo maximum';
        });
    }

    var confirmation = document.getElementById('confirmation_critique');
    if (!confirmation) return;

    var refuserInsertionAutomatique = function (event) {
        event.preventDefault();
        confirmation.setCustomValidity('Le copier-coller est désactivé. Saisissez RESTAURER OIPI manuellement.');
        confirmation.reportValidity();
    };

    ['paste', 'drop', 'copy', 'cut'].forEach(function (eventName) {
        confirmation.addEventListener(eventName, refuserInsertionAutomatique);
    });
    confirmation.addEventListener('beforeinput', function (event) {
        if (event.inputType === 'insertFromPaste' || event.inputType === 'insertFromDrop') {
            refuserInsertionAutomatique(event);
        }
    });
    confirmation.addEventListener('keydown', function (event) {
        var raccourciColler = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'v';
        if (raccourciColler || (event.shiftKey && event.key === 'Insert')) {
            refuserInsertionAutomatique(event);
        }
    });
    confirmation.addEventListener('input', function () {
        confirmation.setCustomValidity('');
    });
});
</script>
