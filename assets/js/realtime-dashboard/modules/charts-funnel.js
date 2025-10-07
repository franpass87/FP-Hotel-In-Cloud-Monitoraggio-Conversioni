/* global hicDashboard, Chart */
(function (root) {
    'use strict';
    if (!root.hicRealtime) { root.hicRealtime = {}; }
    function initFunnelChart() {
        var ctx = document.getElementById('hic-conversion-funnel');
        if (!ctx || typeof Chart === 'undefined') { return null; }
        return new Chart(ctx, { type: 'bar', data: { labels: ['Sessioni', 'Google Ads', 'Facebook Ads', 'Conversioni'], datasets: [{ label: 'Count', data: [], backgroundColor: [hicDashboard.colors.info, hicDashboard.colors.google, hicDashboard.colors.facebook, hicDashboard.colors.success], borderWidth: 1 }] }, options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } } });
    }
    root.hicRealtime.charts = root.hicRealtime.charts || {};
    root.hicRealtime.charts.initFunnel = initFunnelChart;
})(window);


