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

    const $btn = $('#hic-test-email-btn');
    const $result = $('#hic_email_test_result');

    if (!$btn.length || !ajaxUrl) { return; }

    $btn.on('click', function () {
        const emailField = $('#hic_admin_email');
        const email = emailField.val();
        if (!email) {
            setFeedback($result, 'error', i18n('email_missing', 'Inserisci un indirizzo email per il test.'));
            return;
        }

        setFeedback($result, 'info', i18n('email_sending', 'Invio email di test in corso...'));

        const data = { action: 'hic_test_email_ajax', email, nonce: adminSettings.email_nonce };

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then((response) => response.json())
        .then((result) => {
            const resp = result && result.data ? result.data : {};
            const type = result && result.success ? 'success' : 'error';
            const message = resp.message || (type === 'success' ? 'OK' : 'Errore');
            setFeedback($result, type, message);
        })
        .catch((error) => {
            setFeedback($result, 'error', `${i18n('api_network_error', 'Errore di comunicazione:')} ${error}`);
        });
    });
});


