<?php
use App\Core\Csrf;
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div class="text-muted small">
            <i class="fas fa-archive mr-1"></i><?= count($formulaires) ?> formulaire(s) conserve(s) hors du registre actif
        </div>
        <a href="<?= url('formulaires') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-arrow-left mr-1"></i>Registre actif</a>
    </div>
    <div class="card-body table-responsive">
        <table class="table table-bordered table-hover table-sm mb-0">
            <thead class="thead-light">
            <tr>
                <th>Reference</th>
                <th>Type</th>
                <th>Annee</th>
                <th>Numero du formulaire</th>
                <th>Motif</th>
                <th>Archive le</th>
                <th>Par</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($formulaires as $f): ?>
                <tr>
                    <td><?= e($f['numero_auto']) ?></td>
                    <td><?= e($f['type_libelle']) ?></td>
                    <td><?= (int) $f['annee'] ?></td>
                    <td><?= e($f['numero_formulaire']) ?></td>
                    <td><?= e($f['motif_archivage'] ?? '-') ?></td>
                    <td><?= formatDate($f['archive_le'] ?? null) ?></td>
                    <td><?= e($f['archive_par_nom'] ?? 'Utilisateur inconnu') ?></td>
                    <td class="text-nowrap">
                        <a href="<?= url('formulaires/voir/' . $f['id']) ?>" class="btn btn-xs btn-outline-info" title="Consulter"><i class="fas fa-eye"></i></a>
                        <form action="<?= url('formulaires/restaurer/' . $f['id']) ?>" method="post" class="d-inline" data-confirm="Restaurer ce formulaire dans le registre actif ?">
                            <?= Csrf::field() ?>
                            <button class="btn btn-xs btn-outline-success" title="Restaurer"><i class="fas fa-undo"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($formulaires)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Aucun formulaire archive.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
