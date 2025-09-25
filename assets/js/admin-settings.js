jQuery(function ($) {
    const adminSettings = typeof hicAdminSettings !== 'undefined' ? hicAdminSettings : {};
    const ajaxUrl = typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : adminSettings.ajax_url;
    const feedbackClassMap = {
        success: 'is-success',
        error: 'is-error',
        info: 'is-info'
    };
    const feedbackClasses = Object.values(feedbackClassMap);

    function setFeedback($target, type, message) {
        if (!$target || !$target.length) {
            return;
        }

        $target.removeClass(feedbackClasses.join(' '));

        if (!message) {
            $target.text('');
            $target.attr('hidden', true);
            return;
        }

        if (type && feedbackClassMap[type]) {
            $target.addClass(feedbackClassMap[type]);
        }

        $target.text(message);
        $target.removeAttr('hidden');
    }

    function getI18n(key, fallback) {
        if (adminSettings.i18n && adminSettings.i18n[key]) {
            return adminSettings.i18n[key];
        }
        return fallback;
    }

    const tabButtons = $('.hic-tab');
    const tabPanels = $('.hic-tab-panel');

    function activateTab(tabId, focusButton) {
        if (!tabId) {
            return;
        }

        tabButtons.each(function () {
            const $button = $(this);
            const isActive = $button.data('tab') === tabId;
            $button.toggleClass('is-active', isActive);
            $button.attr('aria-selected', isActive ? 'true' : 'false');
            if (isActive && focusButton) {
                $button.trigger('focus');
            }
        });

        tabPanels.each(function () {
            const $panel = $(this);
            const isActive = $panel.data('tab') === tabId;
            $panel.toggleClass('is-active', isActive);
            if (isActive) {
                $panel.removeAttr('hidden');
            } else {
                $panel.attr('hidden', true);
            }
        });

        try {
            window.localStorage.setItem('hicSettingsActiveTab', tabId);
        } catch (e) {
            // Ignore storage issues (private mode, etc.)
        }
    }

    if (tabButtons.length) {
        let initialTab = tabButtons.filter('.is-active').data('tab') || tabButtons.first().data('tab');

        try {
            const storedTab = window.localStorage.getItem('hicSettingsActiveTab');
            if (storedTab && tabButtons.filter(`[data-tab="${storedTab}"]`).length) {
                initialTab = storedTab;
            }
        } catch (e) {
            // Ignore storage issues
        }

        activateTab(initialTab, false);

        tabButtons.on('click', function () {
            activateTab($(this).data('tab'), false);
        });

        tabButtons.on('keydown', function (event) {
            const key = event.key;
            if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(key)) {
                return;
            }

            event.preventDefault();

            const index = tabButtons.index(this);
            let targetIndex = index;

            if (key === 'ArrowRight' || key === 'ArrowDown') {
                targetIndex = (index + 1) % tabButtons.length;
            } else if (key === 'ArrowLeft' || key === 'ArrowUp') {
                targetIndex = (index - 1 + tabButtons.length) % tabButtons.length;
            } else if (key === 'Home') {
                targetIndex = 0;
            } else if (key === 'End') {
                targetIndex = tabButtons.length - 1;
            }

            const $target = tabButtons.eq(targetIndex);
            activateTab($target.data('tab'), true);
        });
    }

    const $apiBtn = $('#hic-test-api-btn');
    const $apiResult = $('#hic-test-result');
    const $apiLoading = $('#hic-test-loading');

    if ($apiBtn.length) {
        $apiBtn.on('click', function () {
            if (!ajaxUrl) {
                return;
            }

            setFeedback($apiResult, null, '');
            $apiLoading.removeAttr('hidden');
            $apiBtn.prop('disabled', true);

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
                        const suffix = getI18n('bookings_found_suffix', 'prenotazioni trovate negli ultimi 7 giorni');
                        const countMessage = `${resp.data_count} ${suffix}`;
                        message = message ? `${message} (${countMessage})` : countMessage;
                    }

                    if (!message) {
                        message = response && response.success ? 'OK' : 'Errore';
                    }

                    setFeedback($apiResult, response && response.success ? 'success' : 'error', message);
                })
                .fail(function (_xhr, _status, error) {
                    const label = getI18n('api_network_error', 'Errore di comunicazione:');
                    setFeedback($apiResult, 'error', `${label} ${error}`);
                })
                .always(function () {
                    $apiLoading.attr('hidden', true);
                    $apiBtn.prop('disabled', false);
                });
        });
    }

    const $emailButton = $('#hic-test-email-btn');
    const $emailResult = $('#hic_email_test_result');

    if ($emailButton.length) {
        $emailButton.on('click', function () {
            if (!ajaxUrl) {
                return;
            }

            const emailField = $('#hic_admin_email');
            const email = emailField.val();

            if (!email) {
                setFeedback($emailResult, 'error', getI18n('email_missing', 'Inserisci un indirizzo email per il test.'));
                return;
            }

            setFeedback($emailResult, 'info', getI18n('email_sending', 'Invio email di test in corso...'));

            const data = {
                action: 'hic_test_email_ajax',
                email,
                nonce: adminSettings.email_nonce
            };

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
                    setFeedback($emailResult, type, message);
                })
                .catch((error) => {
                    const label = getI18n('api_network_error', 'Errore di comunicazione:');
                    setFeedback($emailResult, 'error', `${label} ${error}`);
                });
        });
    }

    const $tokenButton = $('#hic-generate-health-token');
    const $tokenStatus = $('#hic-health-token-status');

    if ($tokenButton.length) {
        $tokenButton.on('click', function () {
            if (!ajaxUrl) {
                return;
            }

            $tokenButton.prop('disabled', true);
            setFeedback($tokenStatus, 'info', getI18n('token_generating', 'Generazione token in corso...'));

            $.post(ajaxUrl, {
                action: 'hic_generate_health_token',
                nonce: adminSettings.health_nonce
            }, function (response) {
                let message = '';
                let type = 'error';

                if (response && response.success && response.data) {
                    type = 'success';
                    if (response.data.token) {
                        $('#hic_health_token').val(response.data.token);
                    }
                    message = response.data.message || '';
                } else if (response && response.data && response.data.message) {
                    message = response.data.message;
                }

                if (!message) {
                    message = type === 'success' ? 'OK' : 'Impossibile generare un nuovo token.';
                }

                setFeedback($tokenStatus, type, message);
            }, 'json')
                .fail(function () {
                    const label = getI18n('api_network_error', 'Errore di comunicazione:');
                    setFeedback($tokenStatus, 'error', label);
                })
                .always(function () {
                    $tokenButton.prop('disabled', false);
                });
        });
    }
});
