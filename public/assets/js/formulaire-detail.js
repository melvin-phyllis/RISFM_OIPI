$(function () {
    const editModal = $('#modal-modifier-formulaire');
    if (editModal.length && window.location.hash === '#modifier-formulaire') {
        editModal.modal('show');
    }

    const archiveModal = $('#modal-archiver-formulaire');
    if (archiveModal.length) {
        if (window.location.hash === '#modal-archiver-formulaire') {
            archiveModal.modal('show');
        }
        archiveModal.on('shown.bs.modal', function () {
            $('#motif_archivage').trigger('focus');
        });
    }

    const modal = $('#modal-resultat-mission');
    const form = $('#form-resultat-mission');

    if (modal.length && form.length) {
        modal.on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const nativeForm = form.get(0);
            if (nativeForm) {
                nativeForm.reset();
            }

            form.attr('action', String(button.data('action') || ''));
            $('#modal-resultat-mission-reference').text('Mission #' + String(button.data('mission-id') || ''));
            $('#modal-resultat-localisation').text(String(button.data('localisation') || '-'));
            $('#modal-resultat-responsable').text(String(button.data('responsable') || '-'));
            $('#modal-resultat-retrouve-warning').addClass('d-none');
        });

        $('#modal_recherche_resultat_code').on('change', function () {
            $('#modal-resultat-retrouve-warning').toggleClass('d-none', this.value !== 'retrouve');
        });

        modal.on('hidden.bs.modal', function () {
            form.attr('action', '');
            $('#modal-resultat-retrouve-warning').addClass('d-none');
        });
    }

    const cancelModal = $('#modal-annuler-mission');
    const cancelForm = $('#form-annuler-mission');
    if (cancelModal.length && cancelForm.length) {
        cancelModal.on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const nativeForm = cancelForm.get(0);
            if (nativeForm) {
                nativeForm.reset();
            }
            cancelForm.attr('action', String(button.data('action') || ''));
            $('#annuler-mission-reference').text(
                'Mission #' + String(button.data('mission-id') || '')
                + ' · ' + String(button.data('localisation') || '-')
                + ' · ' + String(button.data('responsable') || '-')
            );
        });
        cancelModal.on('hidden.bs.modal', function () {
            cancelForm.attr('action', '');
        });
    }

    const reassignModal = $('#modal-reaffecter-mission');
    const reassignForm = $('#form-reaffecter-mission');
    if (reassignModal.length && reassignForm.length) {
        reassignModal.on('show.bs.modal', function (event) {
            const button = $(event.relatedTarget);
            const nativeForm = reassignForm.get(0);
            if (nativeForm) {
                nativeForm.reset();
            }
            reassignForm.attr('action', String(button.data('action') || ''));
            $('#reaffecter-mission-reference').text(
                'Mission #' + String(button.data('mission-id') || '')
                + ' actuellement confiée à ' + String(button.data('responsable') || '-')
            );
            $('#reaffectation_localisation').val(String(button.data('localisation') || '-'));
            $('#reaffectation_date_echeance').val(String(button.data('echeance') || ''));
            $('#reaffectation_priorite').val(String(button.data('priorite') || 'Normale'));
        });
        reassignModal.on('hidden.bs.modal', function () {
            reassignForm.attr('action', '');
        });
    }
});
