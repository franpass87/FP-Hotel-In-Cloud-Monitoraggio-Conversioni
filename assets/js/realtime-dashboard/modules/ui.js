/* global jQuery */
(function ($, root) {
    'use strict';
    if (!root.hicRealtime) { root.hicRealtime = {}; }

    function setEmptyState(elementId, emptyKey, isEmpty) {
        var $element = $('#' + elementId);
        if ($element.length === 0) { return; }
        var $cardBody = $element.closest('.hic-card__body');
        var $container = $element.closest('.hic-widget, .hic-chart-container, .hic-analysis-container');
        if ($cardBody.length) {
            var $card = $cardBody.closest('.hic-card');
            $container = $card.length ? $card : $cardBody;
        }
        if ($container.length === 0) { $container = $element.parent(); }
        $container.toggleClass('hic-empty', !!isEmpty);
        if (typeof emptyKey === 'string' && emptyKey !== '') {
            var $emptyState = $container.find('[data-empty-for="' + emptyKey + '"]');
            if ($emptyState.length) { $emptyState.toggleClass('is-visible', !!isEmpty); }
        }
    }

    function refreshIndicator(show) {
        $('.hic-refresh-indicator').toggleClass('hic-refreshing', !!show);
        $('#hic-refresh-dashboard').prop('disabled', !!show).text(show ? 'Aggiornamento...' : 'Aggiorna Ora');
    }

    root.hicRealtime.ui = { setEmptyState: setEmptyState, refreshIndicator: refreshIndicator };
})(jQuery, window);


