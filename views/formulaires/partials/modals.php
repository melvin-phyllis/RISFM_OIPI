<?php if ($canAssign): ?>
<div class="modal fade" id="modal-annuler-mission" tabindex="-1" role="dialog" aria-labelledby="modal-annuler-mission-titre" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form method="post" id="form-annuler-mission">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-annuler-mission-titre"><i class="fas fa-ban text-danger mr-1"></i>Annuler la mission</h5>
                        <div id="annuler-mission-reference" class="small text-muted"></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">La mission restera visible dans l'historique et son responsable sera informé.</div>
                    <div class="form-group mb-0">
                        <label for="annulation_motif">Motif <span class="text-danger">*</span></label>
                        <textarea id="annulation_motif" name="annulation_motif" class="form-control" minlength="10" maxlength="500" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Retour</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-ban mr-1"></i>Confirmer l'annulation</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="modal-reaffecter-mission" tabindex="-1" role="dialog" aria-labelledby="modal-reaffecter-mission-titre" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" id="form-reaffecter-mission">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-reaffecter-mission-titre"><i class="fas fa-people-arrows text-primary mr-1"></i>Réaffecter la mission</h5>
                        <div id="reaffecter-mission-reference" class="small text-muted"></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border">
                        L'ancienne mission sera annulée et reliée à la nouvelle. La localisation reste identique afin de conserver la nature du travail transféré.
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label>Localisation</label>
                            <input id="reaffectation_localisation" class="form-control" readonly>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="reaffectation_responsable_id">Nouveau responsable <span class="text-danger">*</span></label>
                            <select id="reaffectation_responsable_id" name="reaffectation_responsable_id" class="form-control" required>
                                <option value="">-- Choisir --</option>
                                <?php foreach ($responsables as $__responsable): ?>
                                <option value="<?= (int) $__responsable['id'] ?>"><?= e($__responsable['nom'] . ' ' . $__responsable['prenoms']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="reaffectation_date_echeance">Nouvelle date limite</label>
                            <input id="reaffectation_date_echeance" type="date" name="reaffectation_date_echeance" class="form-control" min="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label for="reaffectation_priorite">Priorité <span class="text-danger">*</span></label>
                            <select id="reaffectation_priorite" name="reaffectation_priorite" class="form-control" required>
                                <?php foreach (['Basse', 'Normale', 'Haute', 'Urgente'] as $__priorite): ?>
                                <option value="<?= $__priorite ?>"><?= $__priorite ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-people-arrows mr-1"></i>Réaffecter et notifier</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (Permission::has((string) Auth::role(), 'formulaires.archive') && (int) ($formulaire['est_archive'] ?? 0) === 0): ?>
<div class="modal fade" id="modal-archiver-formulaire" tabindex="-1" role="dialog" aria-labelledby="modal-archiver-formulaire-titre" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-danger">
            <form action="<?= url('formulaires/archiver/' . $formulaire['id']) ?>" method="post" id="form-archiver-formulaire">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title text-danger" id="modal-archiver-formulaire-titre">
                            <i class="fas fa-exclamation-triangle mr-1" aria-hidden="true"></i>Archiver ce formulaire
                        </h5>
                        <div class="small text-muted"><?= e($formulaire['numero_auto']) ?> · <?= e($formulaire['numero_formulaire']) ?></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        Le formulaire disparaîtra du registre actif. Son historique et ses pièces jointes seront conservés, et ses missions actives seront annulées.
                    </div>
                    <div class="form-group mb-0">
                        <label for="motif_archivage">Motif de l’archivage <span class="text-danger">*</span></label>
                        <textarea id="motif_archivage" name="motif_archivage" class="form-control" rows="4" minlength="10" maxlength="500" placeholder="Indiquez la raison de l’archivage (10 caractères minimum)" required></textarea>
                        <small class="form-text text-muted">Ce motif sera conservé dans le journal d’activité.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-archive mr-1" aria-hidden="true"></i>Confirmer l’archivage</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canReopen): ?>
<div class="modal fade" id="modal-reouvrir-formulaire" tabindex="-1" role="dialog" aria-labelledby="modal-reouvrir-formulaire-titre" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="<?= url('formulaires/reouvrir/' . $formulaire['id']) ?>" method="post">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-reouvrir-formulaire-titre"><i class="fas fa-redo text-warning mr-1"></i>Rouvrir le formulaire</h5>
                        <div class="small text-muted"><?= e($formulaire['numero_auto']) ?> · statut actuel <?= e($formulaire['statut_libelle']) ?></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        Action critique : un nouveau cycle sera créé et le dossier repassera à « A vérifier ». Les anciens comptes rendus et jalons resteront conservés.
                    </div>
                    <div class="form-group mb-0">
                        <label for="reouverture_motif">Motif de la correction <span class="text-danger">*</span></label>
                        <textarea id="reouverture_motif" name="reouverture_motif" class="form-control" minlength="10" maxlength="500" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-redo mr-1"></i>Confirmer la réouverture</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canFinalize && $nextFinalizationStep !== null): ?>
<div class="modal fade" id="modal-finalisation-formulaire" tabindex="-1" role="dialog" aria-labelledby="modal-finalisation-formulaire-titre" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="<?= url('formulaires/finaliser/' . $formulaire['id']) ?>" method="post">
                <?= Csrf::field() ?>
                <input type="hidden" name="finalisation_etape" value="<?= e($nextFinalizationStep) ?>">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-finalisation-formulaire-titre">
                            <i class="fas fa-check-circle text-success mr-1"></i>Confirmer l'étape « <?= e($__nextLabel) ?> »
                        </h5>
                        <div class="small text-muted"><?= e($formulaire['numero_auto']) ?> · <?= e($formulaire['numero_formulaire']) ?></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        Cette action sera conservée dans l'historique et fera passer le formulaire au statut
                        <strong><?= e($__nextLabel) ?></strong>. Les étapes doivent être confirmées dans l'ordre.
                    </div>
                    <div class="form-group">
                        <label for="finalisation_date">Date de l'étape <span class="text-danger">*</span></label>
                        <input id="finalisation_date" type="date" name="finalisation_date" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group mb-0">
                        <label for="finalisation_commentaire">Commentaire ou référence</label>
                        <textarea id="finalisation_commentaire" name="finalisation_commentaire" class="form-control" rows="3" maxlength="500" placeholder="Ex. fichier numérisé et contrôlé, référence de saisie..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check mr-1"></i>Confirmer <?= e($__nextLabel) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canEditMetadata): ?>
<div class="modal fade" id="modal-modifier-formulaire" tabindex="-1" role="dialog" aria-labelledby="modal-modifier-formulaire-titre" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="<?= url('formulaires/modifier/' . $formulaire['id']) ?>" method="post" id="form-modifier-formulaire">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-modifier-formulaire-titre">
                            <i class="fas fa-edit text-primary mr-1"></i>Modifier le formulaire
                        </h5>
                        <div class="small text-muted"><?= e($formulaire['numero_auto']) ?> · <?= e($formulaire['numero_formulaire']) ?></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-light border">
                        <i class="fas fa-info-circle text-success mr-1"></i>
                        Seules les informations generales sont modifiees ici. Les missions, responsables, statuts et resultats ne sont pas affectes.
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-5">
                            <label for="modal_modifier_type_titre_id">Type de titre <span class="text-danger">*</span></label>
                            <select id="modal_modifier_type_titre_id" name="type_titre_id" class="form-control" required>
                                <?php foreach ($types as $type): ?>
                                <option value="<?= (int) $type['id'] ?>" <?= (int) $formulaire['type_titre_id'] === (int) $type['id'] ? 'selected' : '' ?>><?= e($type['libelle']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3">
                            <label for="modal_modifier_annee">Annee</label>
                            <input id="modal_modifier_annee" class="form-control" value="<?= (int) $formulaire['annee'] ?>" readonly>
                        </div>
                        <div class="form-group col-md-4">
                            <label for="modal_modifier_numero_formulaire">Numero du formulaire <span class="text-danger">*</span></label>
                            <input id="modal_modifier_numero_formulaire" name="numero_formulaire" class="form-control" maxlength="60" value="<?= e($formulaire['numero_formulaire']) ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="modal_modifier_priorite">Priorite <span class="text-danger">*</span></label>
                            <select id="modal_modifier_priorite" name="priorite" class="form-control" required>
                                <?php foreach (['Basse', 'Normale', 'Haute', 'Urgente'] as $priorite): ?>
                                <option value="<?= $priorite ?>" <?= $formulaire['priorite'] === $priorite ? 'selected' : '' ?>><?= $priorite ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">La priorité détermine l’ordre de traitement de la recherche.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i>Enregistrer les modifications</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canRecordResult): ?>
<div class="modal fade" id="modal-resultat-mission" tabindex="-1" role="dialog" aria-labelledby="modal-resultat-mission-titre" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post" id="form-resultat-mission">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="modal-resultat-mission-titre">
                            <i class="fas fa-clipboard-check text-success mr-1"></i>Saisir le compte rendu
                        </h5>
                        <div id="modal-resultat-mission-reference" class="small text-muted"></div>
                    </div>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Fermer"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="small text-muted">Localisation inspectee</div>
                            <strong id="modal-resultat-localisation">-</strong>
                        </div>
                        <div class="col-md-6 mt-2 mt-md-0">
                            <div class="small text-muted">Responsable affecte</div>
                            <strong id="modal-resultat-responsable">-</strong>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label for="modal_recherche_resultat_code">Conclusion <span class="text-danger">*</span></label>
                            <select id="modal_recherche_resultat_code" name="recherche_resultat_code" class="form-control" required>
                                <option value="">-- Choisir --</option>
                                <option value="retrouve">Retrouvé</option>
                                <option value="non_retrouve">Non retrouvé</option>
                                <option value="a_verifier">À vérifier</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6">
                            <label for="modal_recherche_date">Date de recherche <span class="text-danger">*</span></label>
                            <input id="modal_recherche_date" type="date" name="recherche_date" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    <div id="modal-resultat-retrouve-warning" class="alert alert-warning d-none">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        Si vous confirmez que le formulaire est retrouvé, les autres missions actives seront automatiquement clôturées.
                    </div>
                    <div class="form-group mb-0">
                        <label for="modal_recherche_resultat">Compte rendu détaillé <span class="text-danger">*</span></label>
                        <input id="modal_recherche_resultat" name="recherche_resultat" class="form-control" maxlength="255" placeholder="Ex. Boite 12 inspectee, formulaire retrouve..." required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save mr-1"></i>Valider le compte rendu</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php $__extra_js = '<script src="' . asset('js/formulaire-detail.js') . '"></script>'; ?>
