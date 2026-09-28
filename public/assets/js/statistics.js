(function () {
    'use strict';

    var data = window.RISFM_STATISTICS_DATA || {};
    var monthlyRows = Array.isArray(data.monthly) ? data.monthly : [];
    var monthlyAddedRows = Array.isArray(data.monthlyAdded) ? data.monthlyAdded : [];
    var statusRows = Array.isArray(data.statuses) ? data.statuses : [];
    var typeRows = Array.isArray(data.types) ? data.types : [];

    function normalize(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .trim();
    }

    function statusColor(row, index) {
        var value = normalize((row && row.statut_code) || (row && row.statut));

        if (value.indexOf('introuv') !== -1) return '#B71C1C';
        if (value.indexOf('recherche') !== -1) return '#F68B1F';
        if (value.indexOf('verif') !== -1) return '#B66A18';
        if (value.indexOf('retrouv') !== -1) return '#00A651';
        if (value.indexOf('numer') !== -1) return '#55C989';
        if (value.indexOf('saisi') !== -1) return '#08723D';
        if (value.indexOf('archiv') !== -1) return '#687B72';

        return ['#17352B', '#F8AF61', '#79D4A4', '#9AABA3'][index % 4];
    }

    function monthLabel(value) {
        var match = /^(\d{4})-(\d{2})$/.exec(String(value || ''));
        if (!match) return String(value || '');

        return new Intl.DateTimeFormat('fr-FR', { month: 'short', year: '2-digit' })
            .format(new Date(Number(match[1]), Number(match[2]) - 1, 1))
            .replace('.', '');
    }

    function markEmpty(canvas, empty) {
        if (canvas && canvas.parentElement) {
            canvas.parentElement.classList.toggle('is-empty', empty);
        }
    }

    function tooltipOptions() {
        return {
            backgroundColor: '#17352B',
            titleColor: '#FFFFFF',
            bodyColor: '#FFFFFF',
            displayColors: false,
            padding: 11,
            cornerRadius: 10,
            titleFont: { size: 12, weight: '700' },
            bodyFont: { size: 12, weight: '600' }
        };
    }

    function renderMonthlyChart() {
        var canvas = document.getElementById('statisticsMonthlyChart');
        if (!canvas || typeof window.Chart === 'undefined') return;

        var resolutions = {};
        var additions = {};
        monthlyRows.forEach(function (row) {
            if (row && row.mois) resolutions[row.mois] = Number(row.resolutions_dans_le_mois) || 0;
        });
        monthlyAddedRows.forEach(function (row) {
            if (row && row.mois) additions[row.mois] = Number(row.ajouts_dans_le_mois) || 0;
        });
        var months = Array.from(new Set(Object.keys(resolutions).concat(Object.keys(additions)))).sort();
        var resolvedValues = months.map(function (month) { return resolutions[month] || 0; });
        var addedValues = months.map(function (month) { return additions[month] || 0; });
        var empty = months.length === 0 || resolvedValues.concat(addedValues).every(function (value) { return value === 0; });
        markEmpty(canvas, empty);
        if (empty) return;

        var context = canvas.getContext('2d');
        var fill = context.createLinearGradient(0, 0, 0, 280);
        fill.addColorStop(0, 'rgba(0, 166, 81, .24)');
        fill.addColorStop(1, 'rgba(0, 166, 81, .015)');

        new window.Chart(context, {
            type: 'bar',
            data: {
                labels: months.map(monthLabel),
                datasets: [{
                    type: 'bar',
                    label: 'Dossiers ajoutés',
                    data: addedValues,
                    backgroundColor: 'rgba(246, 139, 31, .28)',
                    borderColor: '#F68B1F',
                    borderWidth: 1,
                    borderRadius: 7,
                    maxBarThickness: 30
                }, {
                    type: 'line',
                    label: 'Premières résolutions',
                    data: resolvedValues,
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
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: { usePointStyle: true, boxWidth: 7, padding: 14, color: '#687B72', font: { size: 10, weight: '600' } }
                    },
                    tooltip: tooltipOptions()
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: { color: '#687B72', maxRotation: 0, font: { size: 10, weight: '600' } }
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

    function renderTypeChart() {
        var canvas = document.getElementById('statisticsTypeChart');
        if (!canvas || typeof window.Chart === 'undefined') return;

        var rows = typeRows
            .filter(function (row) { return Number(row.total_formulaires) > 0; })
            .sort(function (a, b) { return Number(b.total_formulaires) - Number(a.total_formulaires); })
            .slice(0, 8);
        var empty = rows.length === 0;
        markEmpty(canvas, empty);
        if (empty) return;

        new window.Chart(canvas, {
            type: 'bar',
            data: {
                labels: rows.map(function (row) { return row.type_titre; }),
                datasets: [{
                    label: 'Taux de résolution',
                    data: rows.map(function (row) { return Number(row.taux_resolution) || 0; }),
                    backgroundColor: rows.map(function (row) {
                        return Number(row.taux_resolution) >= 50 ? 'rgba(0, 166, 81, .74)' : 'rgba(246, 139, 31, .72)';
                    }),
                    borderRadius: 7,
                    borderSkipped: false,
                    maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: Object.assign(tooltipOptions(), {
                        callbacks: {
                            label: function (context) {
                                var row = rows[context.dataIndex] || {};
                                return context.raw + ' % · ' + (Number(row.total_formulaires) || 0) + ' dossier(s) · ' + (Number(row.total_restants) || 0) + ' restant(s)';
                            }
                        }
                    })
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        border: { display: false },
                        grid: { color: 'rgba(23, 53, 43, .07)' },
                        ticks: { color: '#687B72', callback: function (value) { return value + ' %'; }, font: { size: 9 } }
                    },
                    y: {
                        border: { display: false },
                        grid: { display: false },
                        ticks: { color: '#17352B', font: { size: 9, weight: '600' } }
                    }
                }
            }
        });
    }

    function renderStatusChart() {
        var canvas = document.getElementById('statisticsStatusChart');
        if (!canvas || typeof window.Chart === 'undefined') return;

        var rows = statusRows.filter(function (row) { return Number(row.total_formulaires) > 0; });
        var colors = rows.map(statusColor);
        var empty = rows.length === 0;
        markEmpty(canvas, empty);

        document.querySelectorAll('[data-statistics-status-index]').forEach(function (dot) {
            var index = Number(dot.getAttribute('data-statistics-status-index')) || 0;
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
                plugins: { legend: { display: false }, tooltip: tooltipOptions() }
            }
        });
    }

    function updateChartsForPrint(printing) {
        if (typeof window.Chart === 'undefined') return;

        Object.values(window.Chart.instances || {}).forEach(function (chart) {
            Object.values(chart.options.scales || {}).forEach(function (scale) {
                if (!scale.ticks) return;
                scale.ticks.color = '#000000';
                scale.ticks.font = { size: printing ? 13 : 10, weight: printing ? 'bold' : 'normal' };
            });
            chart.resize();
            chart.update('none');
        });
    }

    renderMonthlyChart();
    renderTypeChart();
    renderStatusChart();
    if (data.openFilters && window.jQuery) {
        window.jQuery('#statistics-filter-modal').modal('show');
    }
    window.addEventListener('beforeprint', function () { updateChartsForPrint(true); });
    window.addEventListener('afterprint', function () { updateChartsForPrint(false); });
})();
