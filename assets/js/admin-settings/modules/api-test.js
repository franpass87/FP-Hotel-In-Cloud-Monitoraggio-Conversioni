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

    const $btn = $('#hic-test-api-btn');
    const $result = $('#hic-test-result');
    const $loading = $('#hic-test-loading');

    if (!$btn.length || !ajaxUrl) { return; }

    $btn.on('click', function () {
        setFeedback($result, null, '');
        $loading.removeAttr('hidden');
        $btn.prop('disabled', true);

        const data = {
            action: 'hic_test_api_connection',
            nonce: adminSettings.api_nonce,
            prop_id: $('input[name="hic_property_id"]').val(),
            email: $('input[name="hic_api_email"]').val(),
            password: $('input[name="hic_api_password"]').val()
        };

        $.post(ajaxUrl, data)
            .done(function (response) {
                const resp = response && response.data ? response.data : {};
                let message = resp.message || '';
                if (response && response.success && typeof resp.data_count !== 'undefined') {
                    const suffix = i18n('bookings_found_suffix', 'prenotazioni trovate negli ultimi 7 giorni');
                    const countMessage = `${resp.data_count} ${suffix}`;
                    message = message ? `${message} (${countMessage})` : countMessage;
                }
                if (!message) { message = (response && response.success) ? 'OK' : 'Errore'; }
                setFeedback($result, (response && response.success) ? 'success' : 'error', message);
            })
            .fail(function (_xhr, _status, error) {
                setFeedback($result, 'error', `${i18n('api_network_error', 'Errore di comunicazione:')} ${error}`);
            })
            .always(function () { $loading.attr('hidden', true); $btn.prop('disabled', false); });
    });
});


