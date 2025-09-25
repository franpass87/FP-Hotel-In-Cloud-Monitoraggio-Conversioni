(function ($) {
    'use strict';

    const settings = window.hicPerformanceDashboard || {};
    const locale = document.documentElement.lang || 'it-IT';

    const numberFormatter = new Intl.NumberFormat(locale, { maximumFractionDigits: 0 });
    const secondsFormatter = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const percentFormatter = new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 });

    const state = {
        days: 30,
        operation: 'all',
        data: null,
    };

    const charts = {
        operations: null,
        success: null,
        trend: null,
    };

    $(init);

    function init() {
        const rangeSelect = $('#hic-performance-range');
        const operationSelect = $('#hic-performance-operation');

        state.days = parseInt(rangeSelect.val(), 10) || 30;

        rangeSelect.on('change', () => {
            state.days = parseInt(rangeSelect.val(), 10) || 7;
            fetchData();
        });

        operationSelect.on('change', () => {
            state.operation = operationSelect.val() || 'all';
            renderDashboard();
        });

        fetchData();
    }

    function fetchData() {
        const body = $('[data-role="operations-body"]');
        body.html(`<tr><td colspan="6">${escapeHtml(settings.i18n?.loading || 'Loading...')}</td></tr>`);

        $.ajax({
            url: settings.ajaxUrl,
            method: 'GET',
            dataType: 'json',
            data: {
                action: 'hic_performance_metrics',
                type: 'aggregated',
                days: state.days,
                nonce: settings.monitorNonce,
            },
        })
            .done((response) => {
                state.data = response || {};
                populateOperationFilter();
                renderDashboard();
            })
            .fail(() => {
                state.data = null;
                renderNoData(settings.i18n?.noData);
            });
    }

    function populateOperationFilter() {
        const select = $('#hic-performance-operation');
        const current = state.operation;

        select.find('option').not('[value="all"]').remove();

        const operations = Object.keys(state.data?.operations || {}).sort();
        operations.forEach((op) => {
            select.append($('<option>', {
                value: op,
                text: prettifyOperation(op),
            }));
        });

        if (!operations.includes(current)) {
            state.operation = 'all';
            select.val('all');
        } else {
            select.val(current);
        }
    }

    function renderDashboard() {
        const operations = state.data?.operations || {};
        const operationKeys = Object.keys(operations);

        if (!operationKeys.length) {
            renderNoData(settings.i18n?.noData);
            return;
        }

        const selectedOperation = state.operation;
        const rangeLabel = $('[data-role="range-label"]');
        const startDate = state.data.start_date || '';
        const endDate = state.data.end_date || '';
        if (startDate && endDate) {
            rangeLabel.text(formatRange(startDate, endDate));
        } else {
            rangeLabel.text('');
        }

        const summaryOps = selectedOperation === 'all'
            ? operationKeys
            : operationKeys.filter((op) => op === selectedOperation);

        const summary = calculateSummary(summaryOps, operations);
        updateSummaryCards(summary);

        renderOperationsTable(operationKeys, operations, selectedOperation);
        renderOperationsChart(operationKeys, operations, selectedOperation);
        renderSuccessChart(operationKeys, operations, selectedOperation);
        renderTrendChart(operationKeys, operations, selectedOperation);
    }

    function renderNoData(message) {
        $('[data-summary] .hic-performance-card__value').text('—');
        $('[data-role="range-label"]').text('');

        destroyCharts();

        const body = $('[data-role="operations-body"]');
        body.html(`<tr><td colspan="6">${escapeHtml(message || 'No data available.')}</td></tr>`);
    }

    function calculateSummary(keys, operations) {
        let totalCount = 0;
        let totalDuration = 0;
        let successTotal = 0;
        let p95Max = 0;

        keys.forEach((key) => {
            const op = operations[key];
            if (!op) {
                return;
            }
            totalCount += op.total || 0;
            totalDuration += op.total_duration || 0;
            successTotal += op.success?.total || 0;
            p95Max = Math.max(p95Max, op.p95_duration || 0);
        });

        return {
            count: totalCount,
            avgDuration: totalCount ? totalDuration / totalCount : 0,
            successRate: totalCount ? (successTotal / totalCount) * 100 : 0,
            p95Duration: p95Max,
        };
    }

    function updateSummaryCards(summary) {
        const totalEl = $('[data-summary="total"] .hic-performance-card__value');
        const avgEl = $('[data-summary="avg"] .hic-performance-card__value');
        const successEl = $('[data-summary="success"] .hic-performance-card__value');
        const p95El = $('[data-summary="p95"] .hic-performance-card__value');

        totalEl.text(summary.count ? numberFormatter.format(summary.count) : '0');
        avgEl.text(secondsFormatter.format(summary.avgDuration || 0));
        successEl.text(`${percentFormatter.format(summary.successRate || 0)}%`);
        p95El.text(secondsFormatter.format(summary.p95Duration || 0));
    }

    function renderOperationsTable(operationKeys, operations, selectedOperation) {
        const body = $('[data-role="operations-body"]');
        const rows = [];

        operationKeys.forEach((key) => {
            const op = operations[key];
            if (!op) {
                return;
            }

            const successRate = percentFormatter.format(op.success_rate || 0);
            const successText = `${successRate}% (${numberFormatter.format(op.success?.total || 0)} / ${numberFormatter.format(op.total || 0)})`;
            const trend = op.trend || { count_change: 0, duration_change: 0, success_change: 0 };
            const trendSummary = formatTrendSummary(trend);
            const trendClass = determineTrendClass(trend.count_change || 0);

            rows.push(`
                <tr class="${selectedOperation !== 'all' && key === selectedOperation ? 'is-active' : ''}">
                    <td>${escapeHtml(prettifyOperation(key))}</td>
                    <td>${numberFormatter.format(op.total || 0)}</td>
                    <td>${secondsFormatter.format(op.avg_duration || 0)}</td>
                    <td>${secondsFormatter.format(op.p95_duration || 0)}</td>
                    <td>${successText}</td>
                    <td>
                        <div class="hic-performance-trend">
                            <span class="hic-performance-trend__status ${trendClass}">${formatTrendStatus(trend.count_change || 0)}</span>
                            <span class="hic-performance-trend__meta">${trendSummary}</span>
                        </div>
                    </td>
                </tr>
            `);
        });

        body.html(rows.join(''));
    }

    function renderOperationsChart(operationKeys, operations, selectedOperation) {
        const ctx = document.getElementById('hic-performance-operations');
        if (!ctx) {
            return;
        }

        const labels = [];
        const values = [];
        const colors = [];

        operationKeys.forEach((key) => {
            const op = operations[key];
            if (!op) {
                return;
            }
            labels.push(prettifyOperation(key));
            values.push(op.avg_duration || 0);
            colors.push(selectedOperation === 'all' || selectedOperation === key ? '#0073aa' : '#cbd5e0');
        });

        if (charts.operations) {
            charts.operations.data.labels = labels;
            charts.operations.data.datasets[0].data = values;
            charts.operations.data.datasets[0].backgroundColor = colors;
            charts.operations.update();
            return;
        }

        charts.operations = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: settings.i18n?.avgDuration || 'Durata media (s)',
                        data: values,
                        backgroundColor: colors,
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${secondsFormatter.format(context.parsed.y || 0)} s`,
                        },
                    },
                },
                scales: {
                    y: {
                        title: { display: true, text: settings.i18n?.avgDuration || 'Durata media (s)' },
                        beginAtZero: true,
                    },
                },
            },
        });
    }

    function renderSuccessChart(operationKeys, operations, selectedOperation) {
        const ctx = document.getElementById('hic-performance-success');
        if (!ctx) {
            return;
        }

        const labels = [];
        const values = [];
        const colors = [];

        operationKeys.forEach((key) => {
            const op = operations[key];
            if (!op) {
                return;
            }
            labels.push(prettifyOperation(key));
            values.push(op.success_rate || 0);
            colors.push(selectedOperation === 'all' || selectedOperation === key ? '#46b450' : '#d7f5dc');
        });

        if (charts.success) {
            charts.success.data.labels = labels;
            charts.success.data.datasets[0].data = values;
            charts.success.data.datasets[0].backgroundColor = colors;
            charts.success.update();
            return;
        }

        charts.success = new Chart(ctx.getContext('2d'), {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        label: settings.i18n?.successRate || 'Tasso di successo',
                        data: values,
                        backgroundColor: colors,
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (context) => `${percentFormatter.format(context.parsed.y || 0)}%`,
                        },
                    },
                },
                scales: {
                    y: {
                        title: { display: true, text: settings.i18n?.successRate || 'Tasso di successo' },
                        beginAtZero: true,
                        max: 100,
                    },
                },
            },
        });
    }

    function renderTrendChart(operationKeys, operations, selectedOperation) {
        const ctx = document.getElementById('hic-performance-trend');
        if (!ctx) {
            return;
        }

        const daily = {};

        operationKeys.forEach((key) => {
            if (selectedOperation !== 'all' && key !== selectedOperation) {
                return;
            }

            const op = operations[key];
            if (!op) {
                return;
            }

            Object.entries(op.days || {}).forEach(([date, stats]) => {
                if (!daily[date]) {
                    daily[date] = {
                        count: 0,
                        totalDuration: 0,
                        successTotal: 0,
                    };
                }

                daily[date].count += stats.count || 0;
                daily[date].totalDuration += stats.total_duration || 0;
                daily[date].successTotal += stats.success_total || 0;
            });
        });

        const labels = Object.keys(daily).sort();
        const counts = labels.map((date) => daily[date].count);
        const avgDurations = labels.map((date) => {
            const item = daily[date];
            return item.count ? item.totalDuration / item.count : 0;
        });
        const successRates = labels.map((date) => {
            const item = daily[date];
            return item.count ? (item.successTotal / item.count) * 100 : 0;
        });

        if (charts.trend) {
            charts.trend.data.labels = labels;
            charts.trend.data.datasets[0].data = counts;
            charts.trend.data.datasets[1].data = avgDurations;
            charts.trend.data.datasets[2].data = successRates;
            charts.trend.update();
            return;
        }

        charts.trend = new Chart(ctx.getContext('2d'), {
            data: {
                labels,
                datasets: [
                    {
                        type: 'bar',
                        label: settings.i18n?.totalOperations || 'Operazioni monitorate',
                        data: counts,
                        backgroundColor: 'rgba(0, 115, 170, 0.25)',
                        borderColor: '#0073aa',
                        borderWidth: 1,
                        yAxisID: 'count',
                    },
                    {
                        type: 'line',
                        label: settings.i18n?.avgDuration || 'Durata media (s)',
                        data: avgDurations,
                        borderColor: '#ffb900',
                        backgroundColor: 'rgba(255, 185, 0, 0.2)',
                        tension: 0.3,
                        fill: false,
                        yAxisID: 'duration',
                    },
                    {
                        type: 'line',
                        label: settings.i18n?.successRate || 'Tasso di successo',
                        data: successRates,
                        borderColor: '#46b450',
                        backgroundColor: 'rgba(70, 180, 80, 0.2)',
                        tension: 0.3,
                        fill: false,
                        yAxisID: 'success',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label(context) {
                                const value = context.parsed.y || 0;
                                if (context.datasetIndex === 0) {
                                    return `${settings.i18n?.totalOperations || 'Operazioni monitorate'}: ${numberFormatter.format(value)}`;
                                }
                                if (context.datasetIndex === 1) {
                                    return `${settings.i18n?.avgDuration || 'Durata media (s)'}: ${secondsFormatter.format(value)} s`;
                                }
                                return `${settings.i18n?.successRate || 'Tasso di successo'}: ${percentFormatter.format(value)}%`;
                            },
                        },
                    },
                },
                scales: {
                    count: {
                        type: 'linear',
                        position: 'left',
                        beginAtZero: true,
                        title: { display: true, text: settings.i18n?.totalOperations || 'Operazioni monitorate' },
                    },
                    duration: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: settings.i18n?.avgDuration || 'Durata media (s)' },
                    },
                    success: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        max: 100,
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: settings.i18n?.successRate || 'Tasso di successo' },
                    },
                },
            },
        });
    }

    function determineTrendClass(change) {
        if (change > 2) {
            return 'hic-performance-trend__status is-up';
        }
        if (change < -2) {
            return 'hic-performance-trend__status is-down';
        }
        return 'hic-performance-trend__status';
    }

    function formatTrendStatus(change) {
        const labelIncrease = settings.i18n?.trendIncrease || 'Trend in crescita';
        const labelDecrease = settings.i18n?.trendDecrease || 'Trend in calo';
        const labelStable = settings.i18n?.trendStable || 'Trend stabile';

        if (change > 2) {
            return `${labelIncrease} (${percentFormatter.format(change)}%)`;
        }
        if (change < -2) {
            return `${labelDecrease} (${percentFormatter.format(change)}%)`;
        }
        return `${labelStable} (${percentFormatter.format(change)}%)`;
    }

    function formatTrendSummary(trend) {
        const count = percentFormatter.format(trend.count_change || 0);
        const duration = percentFormatter.format(trend.duration_change || 0);
        const success = percentFormatter.format(trend.success_change || 0);

        const template = settings.i18n?.trendSummary || 'Volume: %1$s · Durata: %2$s · Successo: %3$s';
        return template
            .replace('%1$s', `${count}%`)
            .replace('%2$s', `${duration}%`)
            .replace('%3$s', `${success}%`);
    }

    function formatRange(start, end) {
        const template = settings.i18n?.dateRange || 'Periodo analizzato: %1$s → %2$s';
        return template
            .replace('%1$s', escapeHtml(start))
            .replace('%2$s', escapeHtml(end));
    }

    function destroyCharts() {
        Object.keys(charts).forEach((key) => {
            if (charts[key]) {
                charts[key].destroy();
                charts[key] = null;
            }
        });
    }

    function prettifyOperation(name) {
        return (name || '').replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
    }

    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }
})(jQuery);
