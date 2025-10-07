/* global jQuery, hicDashboard */
(function ($, root) {
    'use strict';

    if (!root.hicRealtime) {
        root.hicRealtime = {};
    }

    var ajaxUrl = (typeof hicDashboard !== 'undefined' && hicDashboard.ajaxUrl) ? hicDashboard.ajaxUrl : null;

    function post(action, data) {
        if (!ajaxUrl) { return Promise.resolve({ success: false }); }
        var payload = $.extend({ action: action, nonce: hicDashboard && hicDashboard.nonce }, data || {});
        return $.post(ajaxUrl, payload).then(function (r) { return r; });
    }

    // Expose minimal API helpers (not auto-invoked)
    root.hicRealtime.api = {
        realtime: function () { return post('hic_get_realtime_stats'); },
        revenueByChannel: function (period) { return post('hic_get_revenue_by_channel', { period: period || '7days' }); },
        heatmap: function () { return post('hic_get_booking_heatmap'); },
        funnel: function () { return post('hic_get_conversion_funnel'); },
        heartbeat: function () { return post('hic_dashboard_heartbeat'); }
    };
})(jQuery, window);


