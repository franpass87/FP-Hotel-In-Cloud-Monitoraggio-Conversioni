/* global jQuery, hicPerformanceDashboard */
(function ($, root) {
  'use strict';
  root.hicPerf = root.hicPerf || {};
  var ajaxUrl = (hicPerformanceDashboard && hicPerformanceDashboard.ajaxUrl) || null;
  function fetchAggregated(days) {
    if (!ajaxUrl) { return $.Deferred().reject().promise(); }
    return $.ajax({ url: ajaxUrl, method: 'GET', dataType: 'json', data: { action: 'hic_performance_metrics', type: 'aggregated', days: days || 30, nonce: hicPerformanceDashboard.monitorNonce } });
  }
  root.hicPerf.api = { aggregated: fetchAggregated };
})(jQuery, window);


