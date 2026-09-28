(function () {
    'use strict';

    var data = window.RISFM_DASHBOARD_DATA || {};
    var monthlyRows = Array.isArray(data.monthly) ? data.monthly : [];
    var statusRows = Array.isArray(data.statuses) ? data.statuses : [];

    function normalize(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function statusColor(label, index) {
        var value = normalize(label);

        if (value.indexOf('introuv') !== -1) return '#B71C1C';
        if (value.indexOf('recherche') !== -1) return '#F68B1F';
        if (value.indexOf('verif') !== -1) return '#B66A18';
        if (value.indexOf('retrouv') !== -1) return '#00A651';
        if (value.indexOf('numer') !== -1) return '#55C989';
        if (value.indexOf('saisi') !== -1) return '#08723D';
        if (value.indexOf('archiv') !== -1) return '#687B72';

        return ['#17352B', '#F8AF61', '#79D4A4', '#9AABA3'][index % 4];
    }

    function readableMonth(value) {
        var match = /^(\d{4})-(\d{2})$/.exec(String(value || ''));
        if (!match) return String(value || '');

        return new Intl.DateTimeFormat('fr-FR', { month: 'short', year: '2-digit' })
            .format(new Date(Number(match[1]), Number(match[2]) - 1, 1))
            .replace('.', '');
    }

    function markEmpty(canvas, isEmpty) {
        if (!canvas || !canvas.parentElement) return;
        canvas.parentElement.classList.toggle('is-empty', isEmpty);
    }

    function tooltipOptions() {
        return {
            backgroundColor: '#17352B',
            titleColor: '#FFFFFF',
            bodyColor: '#FFFFFF',
            padding: 11,
            cornerRadius: 10,
            displayColors: false,
            titleFont: { size: 12, weight: '700' },
            bodyFont: { size: 12, weight: '600' }
        };
    }

    function renderMonthlyChart() {
        var canvas = document.getElementById('dashboardMonthlyChart');
        if (!canvas || typeof window.Chart === 'undefined') return;

        var rows = monthlyRows.filter(function (row) {
            return row && row.mois;
        });
        var values = rows.map(function (row) {
            return Number(row.retrouves_dans_le_mois) || 0;
        });
        var empty = rows.length === 0 || values.every(function (value) { return value === 0; });
        markEmpty(canvas, empty);
        if (empty) return;

        var context = canvas.getContext('2d');
        var fill = context.createLinearGradient(0, 0, 0, 280);
        fill.addColorStop(0, 'rgba(0, 166, 81, .24)');
        fill.addColorStop(1, 'rgba(0, 166, 81, .015)');

        new window.Chart(context, {
            type: 'line',
            data: {
                labels: rows.map(function (row) { return readableMonth(row.mois); }),
                datasets: [{
                    label: 'Dossiers retrouvés',
                    data: values,
                    borderColor: '#00A651',
                    backgroundColor: fill,
                    borderWidth: 2.5,
                    fill: true,
                    tension: .38,
                    pointRadius: 3.5,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#FFFFFF',
                    pointBorderColor: '#00A651',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: tooltipOptions()
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#687B72', font: { size: 10, weight: '600' }, maxRotation: 0 }
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(23, 53, 43, .07)' },
                        ticks: { color: '#687B72', precision: 0, stepSize: 1, font: { size: 10 } }
                    }
                }
            }
        });
    }

    function renderStatusChart() {
        var canvas = document.getElementById('dashboardStatusChart');
        if (!canvas || typeof window.Chart === 'undefined') return;

        var rows = statusRows.filter(function (row) {
            return Number(row.total_formulaires) > 0;
        });
        var colors = rows.map(function (row, index) { return statusColor(row.statut, index); });
        var empty = rows.length === 0;
        markEmpty(canvas, empty);

        document.querySelectorAll('.oipi-dashboard-legend-dot[data-status-index]').forEach(function (dot) {
            var index = Number(dot.getAttribute('data-status-index')) || 0;
            dot.style.backgroundColor = colors[index] || '#9AABA3';
        });

        if (empty) return;

        new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: rows.map(function (row) { return row.statut; }),
                datasets: [{
                    data: rows.map(function (row) { return Number(row.total_formulaires) || 0; }),
                    backgroundColor: colors,
                    borderColor: '#FFFFFF',
                    borderWidth: 4,
                    hoverOffset: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { display: false },
                    tooltip: tooltipOptions()
                }
            }
        });
    }

    renderMonthlyChart();
    renderStatusChart();
})();
