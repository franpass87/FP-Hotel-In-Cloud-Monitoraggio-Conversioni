/* global jQuery, hicAdminSettings */
jQuery(function ($) {
    const adminSettings = typeof hicAdminSettings !== 'undefined' ? hicAdminSettings : {};
    const ajaxUrl = typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : adminSettings.ajax_url;

    function setFeedback($target, type, message) {
        const map = { success: 'is-success', error: 'is-error', info: 'is-info' };
        const all = Object.values(map);
        if (!$target || !$target.length) { return; }
        $target.removeClass(all.join(' '));
        if (!message) { $target.text(''); $target.attr('hidden', true); return; }
        if (type && map[type]) { $target.addClass(map[type]); }
        $target.text(message);
        $target.removeAttr('hidden');
    }

    function i18n(key, fallback) { return (adminSettings.i18n && adminSettings.i18n[key]) || fallback; }

    const $btn = $('#hic-generate-health-token');
    const $status = $('#hic-health-token-status');

    if (!$btn.length || !ajaxUrl) { return; }

    $btn.on('click', function () {
        $btn.prop('disabled', true);
        setFeedback($status, 'info', i18n('token_generating', 'Generazione token in corso...'));

        jQuery.post(ajaxUrl, { action: 'hic_generate_health_token', nonce: adminSettings.health_nonce }, function (response) {
            let message = '';
            let type = 'error';
            if (response && response.success && response.data) {
                type = 'success';
                if (response.data.token) { $('#hic_health_token').val(response.data.token); }
                message = response.data.message || '';
            } else if (response && response.data && response.data.message) {
                message = response.data.message;
            }
            if (!message) { message = type === 'success' ? 'OK' : 'Impossibile generare un nuovo token.'; }
            setFeedback($status, type, message);
        }, 'json')
        .fail(function () {
            setFeedback($status, 'error', i18n('api_network_error', 'Errore di comunicazione:'));
        })
        .always(function () { $btn.prop('disabled', false); });
    });
});


