$(function () {
    const form = $('#frm-journal');
    const filterModal = $('#journal-filter-panel');

    function filters() {
        const values = {};
        form.serializeArray().forEach(function (field) {
            values[field.name] = field.value;
        });
        return values;
    }

    function updateFilterCount() {
        const count = Object.values(filters()).filter(function (value) {
            return String(value || '').trim() !== '';
        }).length;
        const badge = $('#journal-filter-count');
        badge.text(count).toggleClass('d-none', count === 0);
    }

    const table = $('#tbl-journal').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        language: { url: window.RISFM_BASE_URL + 'assets/vendor/datatables/i18n/fr-FR.json' },
        dom: '<"journal-table-toolbar"l>rt<"journal-table-footer"ip>',
        ajax: {
            url: window.RISFM_BASE_URL + 'api/journal-datatable',
            data: function (data) { return Object.assign(data, filters()); },
            dataSrc: function (json) {
                const total = Number(json.recordsFiltered) || 0;
                $('#journal-result-count').text(total + (total > 1 ? ' événements' : ' événement'));
                return Array.isArray(json.data) ? json.data : [];
            },
        },
        columns: [
            { data: 'cree_le', className: 'text-nowrap' },
            { data: 'utilisateur_nom' },
            { data: 'type_action', orderable: false },
            { data: 'cible', orderable: false },
            { data: 'description', orderable: false },
            { data: 'adresse_ip', orderable: false, className: 'text-nowrap' },
            { data: 'details', orderable: false, searchable: false, className: 'text-center text-nowrap' },
        ],
        order: [[0, 'desc']],
        columnDefs: [
            { targets: 0, width: '145px' },
            { targets: 6, width: '80px' },
        ],
    });

    form.on('submit', function (event) {
        event.preventDefault();
        const values = filters();
        if (values.date_debut && values.date_fin && values.date_debut > values.date_fin) {
            risfmToast('warning', 'La date de début doit être antérieure ou égale à la date de fin.');
            return;
        }
        updateFilterCount();
        table.ajax.reload();
        filterModal.modal('hide');
    });

    $('#btn-reinitialiser-journal').on('click', function () {
        const nativeForm = form.get(0);
        if (nativeForm) nativeForm.reset();
        updateFilterCount();
        table.ajax.reload();
    });

    $('#btn-actualiser-journal').on('click', function () {
        const button = $(this);
        button.prop('disabled', true).find('i').addClass('fa-spin');
        table.ajax.reload(function () {
            button.prop('disabled', false).find('i').removeClass('fa-spin');
        }, false);
    });

    updateFilterCount();

    $('#tbl-journal').on('click', '.js-journal-detail', function () {
        const activityId = Number($(this).data('id')) || 0;
        if (activityId < 1) return;

        const modal = $('#modal-journal-detail');
        modal.find('.journal-detail-loading').removeClass('d-none');
        modal.find('.journal-detail-content, .journal-detail-error').addClass('d-none');
        modal.modal('show');

        $.ajax({
            url: window.RISFM_BASE_URL + 'api/journal-detail/' + encodeURIComponent(activityId),
            method: 'GET',
            dataType: 'json',
        }).done(function (response) {
            const activity = response && response.activity ? response.activity : null;
            if (!activity) {
                modal.find('.journal-detail-loading').addClass('d-none');
                modal.find('.journal-detail-error').text('Trace d’audit invalide.').removeClass('d-none');
                return;
            }

            modal.find('[data-journal-detail="date"]').text(activity.date || '—');
            modal.find('[data-journal-detail="ip"]').text(activity.ip || '—');
            modal.find('[data-journal-detail="description"]').text(activity.description || '—');
            modal.find('[data-journal-detail-html="actor"]').html(activity.actor_html || 'Systeme');
            modal.find('[data-journal-detail-html="action"]').html(activity.action_html || '—');
            modal.find('[data-journal-detail-html="target"]').html(activity.target_html || '—');
            modal.find('[data-journal-detail-html="changes"]').html(activity.details_html || '—');
            modal.find('.journal-detail-loading').addClass('d-none');
            modal.find('.journal-detail-content').removeClass('d-none');
        }).fail(function (xhr) {
            const response = xhr.responseJSON || {};
            modal.find('.journal-detail-loading').addClass('d-none');
            modal.find('.journal-detail-error')
                .text(response.message || 'Le detail de cet evenement ne peut pas etre charge.')
                .removeClass('d-none');
        });
    });
});
