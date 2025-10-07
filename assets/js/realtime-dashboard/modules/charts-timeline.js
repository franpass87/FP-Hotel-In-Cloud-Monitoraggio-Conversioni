/* global hicDashboard, Chart */
(function (root) {
    'use strict';
    if (!root.hicRealtime) { root.hicRealtime = {}; }
    function initTimelineChart() {
        var ctx = document.getElementById('hic-conversions-timeline');
        if (!ctx || typeof Chart === 'undefined') { return null; }
        return new Chart(ctx, { type: 'line', data: { labels: [], datasets: [{ label: 'Conversioni', data: [], borderColor: hicDashboard.colors.primary, backgroundColor: hicDashboard.colors.primary + '20', borderWidth: 2, fill: true, tension: 0.3 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, interaction: { intersect: false, mode: 'index' }, scales: { y: { beginAtZero: true, grid: { color: '#f0f0f0' } }, x: { grid: { display: false } } } } });
    }
    root.hicRealtime.charts = root.hicRealtime.charts || {};
    root.hicRealtime.charts.initTimeline = initTimelineChart;
})(window);


