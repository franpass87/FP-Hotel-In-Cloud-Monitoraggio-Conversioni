/* global hicDashboard, Chart */
(function (root) {
    'use strict';
    if (!root.hicRealtime) { root.hicRealtime = {}; }
    function initHeatmapChart() {
        var ctx = document.getElementById('hic-booking-heatmap');
        if (!ctx || typeof Chart === 'undefined') { return null; }
        return new Chart(ctx, {
            type: 'scatter',
            data: { datasets: [{ label: 'Booking Intensity', data: [], backgroundColor: function (c) { var v = c.parsed.v || 0; var max = Math.max.apply(null, (c.dataset.data || []).map(function (d) { return d.v || 0; })); var intensity = max > 0 ? v / max : 0; var r = Math.round(intensity * 255); var b = Math.round((1 - intensity) * 255); return 'rgb(' + r + ', 100, ' + b + ')'; } }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { type: 'linear', position: 'bottom', min: 0, max: 23 }, y: { type: 'linear', min: 0, max: 6 } } }
        });
    }
    root.hicRealtime.charts = root.hicRealtime.charts || {};
    root.hicRealtime.charts.initHeatmap = initHeatmapChart;
})(window);


