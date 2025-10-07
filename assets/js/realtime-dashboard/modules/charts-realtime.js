/* global hicDashboard, Chart, jQuery */
(function ($, root) {
    'use strict';

    if (!root.hicRealtime) { root.hicRealtime = {}; }

    function initRealtimeChart() {
        var ctx = document.getElementById('hic-realtime-chart');
        if (!ctx || typeof Chart === 'undefined') { return null; }
        return new Chart(ctx, {
            type: 'line',
            data: { labels: [], datasets: [{ label: hicDashboard.i18n.conversions, data: [], borderColor: hicDashboard.colors.primary, backgroundColor: hicDashboard.colors.primary + '20', borderWidth: 2, fill: true, tension: 0.4 }]},
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } } }
        });
    }

    root.hicRealtime.charts = root.hicRealtime.charts || {};
    root.hicRealtime.charts.initRealtime = initRealtimeChart;
})(jQuery, window);


