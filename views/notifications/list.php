<div class="card">
    <div class="card-header notifications-card-header">
        <div class="text-muted small">
            <i class="fas fa-bell mr-1" aria-hidden="true"></i>
            <?= (int) $total ?> notification<?= (int) $total > 1 ? 's' : '' ?> · <?= (int) $nonLues ?> non lue<?= (int) $nonLues > 1 ? 's' : '' ?>
        </div>
        <form action="<?= url('notifications/tout-lire') ?>" method="post">
            <?= Csrf::field() ?>
            <button class="btn btn-sm btn-outline-secondary">Tout marquer comme lu</button>
        </form>
    </div>
    <div class="card-body p-0">
        <ul class="list-group list-group-flush">
        <?php foreach ($notifications as $n): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start <?= (int) $n['lu'] === 0 ? 'notification-unread' : '' ?>">
                <div>
                    <strong><?= e($n['titre']) ?></strong>
                    <span class="badge badge-<?= $n['type'] === 'alerte' ? 'danger' : ($n['type'] === 'rappel' ? 'warning' : 'info') ?> ml-2"><?= e($n['type']) ?></span>
                    <div class="text-muted small"><?= e($n['message']) ?></div>
                    <div class="text-muted small">
                        Reçue le <?= formatDate($n['cree_le']) ?>
                        <?php if ((int) $n['lu'] === 1): ?> · Lue le <?= formatDate($n['lu_le']) ?><?php endif; ?>
                        · <?= $n['utilisateur_id'] === null ? 'Notification générale' : 'Notification personnelle' ?>
                    </div>
                    <?php if (!empty($n['lien'])): ?><a href="<?= e($n['lien']) ?>" class="small">Consulter</a><?php endif; ?>
                </div>
                <?php if ((int) $n['lu'] === 0): ?>
                <form action="<?= url('notifications/lu/' . $n['id']) ?>" method="post" class="ajax-mark-read">
                    <?= Csrf::field() ?>
                    <button class="btn btn-xs btn-outline-success"><i class="fas fa-check"></i></button>
                </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if (empty($notifications)): ?>
            <li class="list-group-item text-center text-muted py-4">Aucune notification.</li>
        <?php endif; ?>
        </ul>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex justify-content-center">
        <nav aria-label="Pagination des notifications">
            <ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('notifications?page=' . $p) ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
