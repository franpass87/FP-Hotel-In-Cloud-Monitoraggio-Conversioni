/* global Chart, hicPerformanceDashboard */
(function (root) {
  'use strict';
  root.hicPerf = root.hicPerf || {};
  var CH = {};

  CH.operations = function (ctx) {
    if (!ctx || typeof Chart === 'undefined') { return null; }
    return new Chart(ctx, { type: 'bar', data: { labels: [], datasets: [{ label: hicPerformanceDashboard.i18n?.avgDuration || 'Durata media (s)', data: [], backgroundColor: [], borderRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { title: { display: true, text: hicPerformanceDashboard.i18n?.avgDuration || 'Durata media (s)' }, beginAtZero: true } } } });
  };
  CH.success = function (ctx) {
    if (!ctx || typeof Chart === 'undefined') { return null; }
    return new Chart(ctx, { type: 'bar', data: { labels: [], datasets: [{ label: hicPerformanceDashboard.i18n?.successRate || 'Tasso di successo', data: [], backgroundColor: [], borderRadius: 4 }] }, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { title: { display: true, text: hicPerformanceDashboard.i18n?.successRate || 'Tasso di successo' }, beginAtZero: true, max: 100 } } } });
  };
  CH.trend = function (ctx) {
    if (!ctx || typeof Chart === 'undefined') { return null; }
    return new Chart(ctx, { data: { labels: [], datasets: [{ type: 'bar', label: hicPerformanceDashboard.i18n?.totalOperations || 'Operazioni monitorate', data: [], backgroundColor: 'rgba(0, 115, 170, 0.25)', borderColor: '#0073aa', borderWidth: 1, yAxisID: 'count' }, { type: 'line', label: hicPerformanceDashboard.i18n?.avgDuration || 'Durata media (s)', data: [], borderColor: '#ffb900', backgroundColor: 'rgba(255, 185, 0, 0.2)', tension: 0.3, fill: false, yAxisID: 'duration' }, { type: 'line', label: hicPerformanceDashboard.i18n?.successRate || 'Tasso di successo', data: [], borderColor: '#46b450', backgroundColor: 'rgba(70, 180, 80, 0.2)', tension: 0.3, fill: false, yAxisID: 'success' }] }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom' } }, scales: { count: { type: 'linear', position: 'left', beginAtZero: true, title: { display: true, text: hicPerformanceDashboard.i18n?.totalOperations || 'Operazioni monitorate' } }, duration: { type: 'linear', position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: hicPerformanceDashboard.i18n?.avgDuration || 'Durata media (s)' } }, success: { type: 'linear', position: 'right', beginAtZero: true, max: 100, grid: { drawOnChartArea: false }, title: { display: true, text: hicPerformanceDashboard.i18n?.successRate || 'Tasso di successo' } } } } });
  };

  root.hicPerf.charts = CH;
})(window);


