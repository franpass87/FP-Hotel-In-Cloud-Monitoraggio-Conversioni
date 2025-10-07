/* global jQuery, hicDiagnostics */
(function ($, root) {
  'use strict';
  root.hicDiag = root.hicDiag || {};
  var ajaxUrl = (hicDiagnostics && hicDiagnostics.ajax_url) || (typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : null);
  function post(action, data) {
    if (!ajaxUrl) { return $.Deferred().reject().promise(); }
    var payload = $.extend({ action: action }, data || {});
    return $.post(ajaxUrl, payload, null, 'json');
  }
  root.hicDiag.svc = {
    health: function (level) { return post('hic_health_check', { level: level || 'basic', nonce: hicDiagnostics.monitor_nonce }); },
    performance: function (type, days) { return post('hic_performance_metrics', { type: type || 'summary', days: days || 7, nonce: hicDiagnostics.monitor_nonce }); },
    polling: function () { return post('hic_get_polling_metrics', { nonce: hicDiagnostics.polling_metrics_nonce }); },
    dbStats: function () { return post('hic_get_database_stats', { nonce: hicDiagnostics.optimize_db_nonce }); },
    recon: function () { return post('hic_run_reconciliation', { nonce: hicDiagnostics.management_nonce }); },
    status: function () { return post('hic_get_health_status', { nonce: hicDiagnostics.management_nonce }); }
  };
})(jQuery, window);


