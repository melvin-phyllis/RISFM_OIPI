<div class="row">
    <div class="col-md-4">
        <div class="card card-primary card-outline">
            <div class="card-body box-profile text-center">
                <?php if (!empty($user['photo'])): ?>
                    <img class="profile-user-img img-fluid img-circle" src="<?= asset('uploads/photos/' . e($user['photo'])) ?>" alt="Photo utilisateur">
                <?php else: ?>
                    <img class="profile-user-img img-fluid img-circle" src="<?= appLogoUrl(true) ?>" alt="Photo utilisateur">
                <?php endif; ?>
                <h3 class="profile-username text-center"><?= e($user['nom'] . ' ' . $user['prenoms']) ?></h3>
                <p class="text-muted text-center"><?= e(Permission::label($user['role'])) ?></p>
                <ul class="list-group list-group-unbordered mb-3">
                    <li class="list-group-item"><b>Identifiant</b> <span class="float-right"><?= e($user['identifiant']) ?></span></li>
                    <li class="list-group-item"><b>Service</b> <span class="float-right"><?= e($user['service'] ?? '-') ?></span></li>
                    <li class="list-group-item"><b>Derniere connexion</b> <span class="float-right"><?= formatDate($user['derniere_connexion']) ?></span></li>
                </ul>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Changer le mot de passe</h3></div>
            <div class="card-body">
                <form action="<?= url('profil/mot-de-passe') ?>" method="post">
                    <?= Csrf::field() ?>
                    <div class="form-group">
                        <label>Mot de passe actuel</label>
                        <input type="password" name="mot_de_passe_actuel" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Nouveau mot de passe</label>
                        <input type="password" name="mot_de_passe" class="form-control" minlength="10" required>
                    </div>
                    <div class="form-group">
                        <label>Confirmer le nouveau mot de passe</label>
                        <input type="password" name="mot_de_passe_confirmation" class="form-control" minlength="10" required>
                    </div>
                    <small class="form-text text-muted mb-3">10 caracteres minimum, avec majuscule, minuscule, chiffre et caractere special.</small>
                    <button class="btn btn-primary btn-block">Mettre a jour</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Informations personnelles</h3></div>
            <div class="card-body">
                <form action="<?= url('profil') ?>" method="post" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Nom</label>
                            <input type="text" name="nom" class="form-control" value="<?= e($user['nom']) ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Prenoms</label>
                            <input type="text" name="prenoms" class="form-control" value="<?= e($user['prenoms']) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>E-mail</label>
                            <input type="email" name="email" class="form-control" value="<?= e($user['email']) ?>" required>
                        </div>
                        <div class="form-group col-md-6">
                            <label>Telephone</label>
                            <input type="text" name="telephone" class="form-control" value="<?= e($user['telephone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Photo de profil (optionnelle)</label>
                        <input type="file" name="photo" class="form-control-file" accept=".jpg,.jpeg,.png">
                    </div>
                    <button class="btn btn-success"><i class="fas fa-save mr-1"></i>Enregistrer</button>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Mes dernieres connexions</h3></div>
                    <div class="card-body p-0 risfm-scroll-table" role="region" aria-label="Mes dernieres connexions" tabindex="0">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Date</th><th>IP</th><th>Statut</th></tr></thead>
                            <tbody>
                            <?php foreach ($connexions as $c): ?>
                                <tr>
                                    <td><?= formatDate($c['connecte_le']) ?></td>
                                    <td><?= e($c['adresse_ip']) ?></td>
                                    <?php $__connectionStatus = (string) ($c['statut_effectif'] ?? $c['statut']); ?>
                                    <td><span class="badge badge-<?= $__connectionStatus === 'actif' ? 'success' : ($__connectionStatus === 'expire' ? 'warning' : 'secondary') ?>"><?= e($__connectionStatus === 'actif' ? 'actif' : ($__connectionStatus === 'expire' ? 'expire' : 'termine')) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Mes dernieres activites</h3></div>
                    <div class="card-body p-0 risfm-scroll-table" role="region" aria-label="Mes dernieres activites" tabindex="0">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Date</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach ($activites as $a): ?>
                                <tr>
                                    <td><?= formatDate($a['cree_le']) ?></td>
                                    <td><?= e($a['description']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
