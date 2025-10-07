/* global hicDashboard, Chart, jQuery */
(function ($, root) {
    'use strict';

    if (!root.hicRealtime) { root.hicRealtime = {}; }

    function initRevenueChart() {
        var ctx = document.getElementById('hic-revenue-chart');
        if (!ctx || typeof Chart === 'undefined') { return null; }
        return new Chart(ctx, {
            type: 'doughnut',
            data: { labels: [], datasets: [{ data: [], backgroundColor: [hicDashboard.colors.google, hicDashboard.colors.facebook, hicDashboard.colors.direct, hicDashboard.colors.organic, hicDashboard.colors.info], borderWidth: 2, borderColor: '#fff' }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { padding: 20, usePointStyle: true } } } }
        });
    }

    root.hicRealtime.charts = root.hicRealtime.charts || {};
    root.hicRealtime.charts.initRevenue = initRevenueChart;
})(jQuery, window);


