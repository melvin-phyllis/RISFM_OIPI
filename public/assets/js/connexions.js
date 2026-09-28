$(function () {
    const form = $('#frm-connexions');
    const filterModal = $('#connection-filter-panel');
    const analytics = window.RISFM_CONNECTION_ANALYTICS || {};

    function formatTrendDate(value) {
        const date = new Date(String(value || '') + 'T00:00:00');
        if (Number.isNaN(date.getTime())) return String(value || '');
        return new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: '2-digit' }).format(date);
    }

    function initConnectionCharts() {
        if (typeof window.Chart !== 'function') return;

        const trendCanvas = document.getElementById('chart-connection-trend');
        const trend = Array.isArray(analytics.trend) ? analytics.trend : [];
        if (trendCanvas) {
            const context = trendCanvas.getContext('2d');
            const gradient = context.createLinearGradient(0, 0, 0, 250);
            gradient.addColorStop(0, 'rgba(0, 166, 81, .24)');
            gradient.addColorStop(1, 'rgba(0, 166, 81, 0)');
            new Chart(context, {
                type: 'line',
                data: {
                    labels: trend.map(function (row) { return formatTrendDate(row.date); }),
                    datasets: [
                        {
                            label: 'Connexions',
                            data: trend.map(function (row) { return Number(row.connexions) || 0; }),
                            borderColor: (window.RISFM_COLORS || {}).secondary || '#00A651',
                            backgroundColor: gradient,
                            borderWidth: 2.5,
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: '#ffffff',
                            pointBorderWidth: 2,
                            fill: true,
                            tension: .36,
                        },
                        {
                            label: 'Utilisateurs distincts',
                            data: trend.map(function (row) { return Number(row.utilisateurs) || 0; }),
                            borderColor: (window.RISFM_COLORS || {}).primary || '#F68B1F',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            borderDash: [5, 5],
                            pointRadius: 2,
                            pointHoverRadius: 4,
                            tension: .32,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            align: 'start',
                            labels: { usePointStyle: true, boxWidth: 8, padding: 16 },
                        },
                        tooltip: { padding: 10, displayColors: true },
                    },
                    scales: {
                        x: { grid: { display: false }, border: { display: false } },
                        y: {
                            beginAtZero: true,
                            ticks: { precision: 0, stepSize: 1 },
                            grid: { color: 'rgba(23, 53, 43, .08)' },
                            border: { display: false },
                        },
                    },
                },
            });
        }

        const statusCanvas = document.getElementById('chart-connection-status');
        if (statusCanvas) {
            const statuses = analytics.statuses || {};
            const total = Number(analytics.total) || 0;
            const values = total > 0
                ? [Number(statuses.active) || 0, Number(statuses.closed) || 0, Number(statuses.expired) || 0]
                : [1];
            new Chart(statusCanvas, {
                type: 'doughnut',
                data: {
                    labels: total > 0 ? ['Actives', 'Terminées', 'Expirées'] : ['Aucune session'],
                    datasets: [{
                        data: values,
                        backgroundColor: total > 0
                            ? [
                                (window.RISFM_COLORS || {}).secondary || '#00A651',
                                (window.RISFM_COLORS || {}).accent || '#17352B',
                                (window.RISFM_COLORS || {}).primary || '#F68B1F',
                            ]
                            : ['#DDE6E1'],
                        borderColor: '#ffffff',
                        borderWidth: 4,
                        borderRadius: 6,
                        spacing: 2,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            enabled: total > 0,
                            callbacks: {
                                label: function (context) {
                                    const value = Number(context.raw) || 0;
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return context.label + ' : ' + value + ' (' + percentage + ' %)';
                                },
                            },
                        },
                    },
                },
            });
        }
    }

    initConnectionCharts();

    function filters() {
        const values = {};
        form.serializeArray().forEach(function (field) {
            values[field.name] = field.value;
        });
        return values;
    }

    function updateFilterCount() {
        const currentFilters = filters();
        const count = Object.values(currentFilters).filter(function (value) {
            return String(value || '').trim() !== '';
        }).length;
        $('#connection-filter-count').text(count).toggleClass('d-none', count === 0);
        $('.connection-analytics-kpi').removeClass('is-selected');
        if (count === 0) {
            $('.connection-analytics-kpi.is-total').addClass('is-selected');
        } else if (currentFilters.statut) {
            $('.connection-analytics-kpi[data-status="' + currentFilters.statut + '"]').addClass('is-selected');
        }
    }

    function resetFilterForm() {
        const nativeForm = form.get(0);
        if (nativeForm) nativeForm.reset();
    }

    function scrollToConnections() {
        const target = document.getElementById('connexions-table-card');
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const table = $('#tbl-connexions').DataTable({
        processing: true,
        serverSide: true,
        searching: false,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],
        language: { url: window.RISFM_BASE_URL + 'assets/vendor/datatables/i18n/fr-FR.json' },
        ajax: {
            url: window.RISFM_BASE_URL + 'api/connexions-datatable',
            data: function (data) { return Object.assign(data, filters()); },
            dataSrc: function (json) {
                const total = Number(json.recordsFiltered) || 0;
                $('#connection-result-count').text(total + (total > 1 ? ' connexions' : ' connexion'));
                return Array.isArray(json.data) ? json.data : [];
            },
        },
        columns: [
            { data: 'utilisateur_nom', orderable: false },
            { data: 'connecte_le', className: 'text-nowrap' },
            { data: 'derniere_activite', orderable: false, className: 'text-nowrap' },
            { data: 'duree', orderable: false, className: 'text-nowrap' },
            { data: 'adresse_ip', orderable: false, className: 'text-nowrap' },
            { data: 'environnement', orderable: false },
            { data: 'statut', orderable: false, className: 'text-nowrap' },
        ],
        order: [[1, 'desc']],
        createdRow: function (row, data) {
            if (data.statut_code === 'actif') row.classList.add('connection-row-active');
            if (data.statut_code === 'expire') row.classList.add('connection-row-expired');
        },
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

    $('#btn-reinitialiser-connexions').on('click', function () {
        resetFilterForm();
        updateFilterCount();
        table.ajax.reload();
    });

    $('.js-connection-quick-filter').on('click', function (event) {
        event.preventDefault();
        resetFilterForm();
        $('#connexion_statut').val(String($(this).data('status') || ''));
        updateFilterCount();
        table.ajax.reload();
        scrollToConnections();
    });

    $('.js-connection-today-filter').on('click', function (event) {
        event.preventDefault();
        resetFilterForm();
        const today = new Date();
        const localDate = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        $('#connexion_date_debut, #connexion_date_fin').val(localDate);
        updateFilterCount();
        table.ajax.reload();
        scrollToConnections();
    });

    $('.js-connection-reset-filter').on('click', function (event) {
        event.preventDefault();
        resetFilterForm();
        updateFilterCount();
        table.ajax.reload();
        scrollToConnections();
    });

    updateFilterCount();
});
