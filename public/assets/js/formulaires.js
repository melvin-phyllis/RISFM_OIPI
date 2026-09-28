/**
 * Initialisation DataTables (server-side) pour le registre des formulaires manquants,
 * gestion des filtres personnalises et des liens d'export avec les filtres actifs.
 */
$(function () {
    function collectFilters() {
        const data = {};
        $('#frm-filtres').serializeArray().forEach(function (f) {
            data[f.name] = f.value;
        });
        return data;
    }

    function updateActiveFiltersCount() {
        const count = Object.values(collectFilters()).filter(function (value) {
            return String(value).trim() !== '';
        }).length;
        $('#filtres-actifs-count').text(count).toggleClass('d-none', count === 0);
    }

    const table = $('#tbl-formulaires').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        dom: '<"registry-table-controls"l<"priority-legend-host">>rt<"row mt-2"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        language: { url: window.RISFM_BASE_URL + 'assets/vendor/datatables/i18n/fr-FR.json' },
        ajax: {
            url: window.RISFM_BASE_URL + 'api/formulaires-datatable',
            type: 'GET',
            data: function (d) {
                return Object.assign(d, collectFilters());
            },
        },
        columns: [
            { data: 'numero', orderable: false, searchable: false },
            { data: 'type_libelle' },
            { data: 'annee' },
            { data: 'numero_formulaire' },
            { data: 'statut_badge', orderable: false },
            { data: 'localisation' },
            { data: 'responsable_nom', orderable: false },
            { data: 'date_recherche' },
            { data: 'actions', orderable: false, searchable: false },
        ],
        order: [[2, 'desc']],
        pageLength: 25,
        initComplete: function () {
            const container = $(this.api().table().container());
            $('#registry-priority-legend').appendTo(container.find('.priority-legend-host').first());
        },
    });

    table.on('xhr.dt', function (event, settings, json) {
        if (!json || typeof json.recordsFiltered === 'undefined') return;
        const count = Number(json.recordsFiltered) || 0;
        const formatted = new Intl.NumberFormat('fr-FR').format(count);
        const label = formatted + ' dossier' + (count === 1 ? '' : 's');
        $('#registry-results-count').text(label);
        $('#export-results-count').text(formatted + ' ligne' + (count === 1 ? '' : 's') + ' à exporter');
    });

    $('#frm-filtres').on('submit', function (event) {
        event.preventDefault();
        updateActiveFiltersCount();
        table.ajax.reload();
        $('#filtres-avances').modal('hide');
    });
    $('#btn-reset').on('click', function () {
        setTimeout(function () {
            updateActiveFiltersCount();
            table.ajax.reload();
        }, 50);
    });

    $('.export-link').on('click', function (e) {
        e.preventDefault();
        const type = $(this).data('type');
        const params = new URLSearchParams(collectFilters()).toString();
        window.location.href = window.RISFM_BASE_URL + 'exports/formulaires/' + type + '?' + params;
    });

    let searchedLocationsForAssignment = [];

    function refreshAssignmentDuplicateWarning() {
        const selectedLocation = Number($('#modal_affectation_localisation_id').val() || 0);
        const alreadySearched = selectedLocation > 0 && searchedLocationsForAssignment.includes(selectedLocation);
        $('#modal-affectation-deja-recherchee').toggleClass('d-none', !alreadySearched);
        if (!alreadySearched) {
            $('#modal_confirmer_localisation_deja_recherchee').prop('checked', false);
        }
    }

    $('#tbl-formulaires').on('click', '.js-affecter-recherche', function () {
        const button = $(this);
        const modal = $('#modal-affecter-recherche');
        const form = $('#form-modal-affectation');
        const actionTemplate = form.data('action-template');
        const id = String(button.data('id') || '');

        if (!modal.length || !form.length || !id) {
            return;
        }

        form.attr('action', String(actionTemplate).replace('__ID__', encodeURIComponent(id)));
        $('#modal-affecter-reference').text(
            'Dossier ' + (button.data('reference') || '#' + id) +
            ' - Formulaire ' + (button.data('numero-formulaire') || 'non renseigne')
        );

        $('#modal_affectation_localisation_id').val('');
        $('#modal_affectation_responsable_id').val('');
        $('#modal_affectation_date_echeance').val('');
        $('#modal_affectation_priorite').val('Normale');
        $('#modal_confirmer_localisation_deja_recherchee').prop('checked', false);
        searchedLocationsForAssignment = [];
        refreshAssignmentDuplicateWarning();

        modal.modal('show');

        $.getJSON(window.RISFM_BASE_URL + 'api/formulaires/' + encodeURIComponent(id) + '/localisations-recherchees')
            .done(function (response) {
                searchedLocationsForAssignment = Array.isArray(response.localisations)
                    ? response.localisations.map(Number)
                    : [];
                refreshAssignmentDuplicateWarning();
            })
            .fail(function () {
                searchedLocationsForAssignment = [];
                refreshAssignmentDuplicateWarning();
            });
    });

    $('#modal_affectation_localisation_id').on('change', refreshAssignmentDuplicateWarning);

    $('#modal-ajouter-formulaire').on('hidden.bs.modal', function () {
        const form = $('#form-modal-ajout-formulaire')[0];
        if (form) {
            form.reset();
        }
        $('#modal-informations-complementaires').collapse('hide');
    });

    function openExportsFromHash() {
        if (window.location.hash !== '#exports') return;
        const exportsMenu = $('#exports');
        if (!exportsMenu.length) return;
        exportsMenu[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(function () { exportsMenu.find('.dropdown-toggle').dropdown('show'); }, 250);
    }

    updateActiveFiltersCount();
    openExportsFromHash();
    $(window).on('hashchange', openExportsFromHash);
});
