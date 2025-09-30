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

    const archiveConfig = adminSettings.archive || null;
    const $archiveButton = $('#hic-archive-start-btn');
    const $archiveButtonLabel = $('#hic-archive-button-label');
    const $archiveProgress = $('#hic-archive-progress');
    const $archiveProgressFill = $('#hic-archive-progress-fill');
    const $archiveProgressLabel = $('#hic-archive-progress-label');
    const $archiveProgressCount = $('#hic-archive-progress-count');
    const $archiveStatusText = $('#hic-archive-status-text');
    const $archiveLoader = $('#hic-archive-loader');
    const $archiveFeedback = $('#hic-archive-feedback');

    if (
        ajaxUrl &&
        archiveConfig &&
        $archiveButton.length &&
        $archiveButtonLabel.length &&
        $archiveProgress.length &&
        $archiveProgressFill.length
    ) {
        const archiveStrings = archiveConfig.i18n || {};
        const strings = {
            start_label: archiveStrings.start_label || 'Avvia archiviazione',
            resume_label: archiveStrings.resume_label || 'Riprendi archiviazione',
            running_label: archiveStrings.running_label || 'Archiviazione in corso…',
            restart_label: archiveStrings.restart_label || 'Riesegui archiviazione',
            completed_label: archiveStrings.completed_label || 'Archiviazione completata',
            error_generic: archiveStrings.error_generic || 'Si è verificato un errore durante l\'archiviazione.',
            records_progress: archiveStrings.records_progress || '%1$s di %2$s record archiviati',
            records_remaining: archiveStrings.records_remaining || '%s record rimanenti',
            last_batch: archiveStrings.last_batch || 'Ultimo batch: %s record',
            resume_hint: archiveStrings.resume_hint || 'Job in pausa: riprendi per completare l\'archiviazione.',
        };
        const stepInterval = Number.isFinite(Number.parseInt(archiveConfig.step_interval, 10))
            ? Number.parseInt(archiveConfig.step_interval, 10)
            : 1500;
        const batchSize = Number.isFinite(Number.parseInt(archiveConfig.batch_size, 10))
            ? Number.parseInt(archiveConfig.batch_size, 10)
            : null;

        let currentState = null;
        let jobDone = false;
        let running = false;
        let pollTimeout = null;

        function formatNumbered(template, values) {
            if (!template) {
                return '';
            }

            let unnamedIndex = 0;

            const withNumbered = template.replace(/%(\d+)\$[sd]/g, function (_match, index) {
                const position = Number.parseInt(index, 10) - 1;
                if (Number.isNaN(position) || position < 0 || position >= values.length) {
                    return '';
                }
                return values[position];
            });

            return withNumbered.replace(/%[sd]/g, function () {
                if (unnamedIndex >= values.length) {
                    return '';
                }

                const replacement = values[unnamedIndex];
                unnamedIndex += 1;
                return replacement;
            });
        }

        function toggleLoader(isVisible) {
            if (!$archiveLoader.length) {
                return;
            }

            if (isVisible) {
                $archiveLoader.removeAttr('hidden');
            } else {
                $archiveLoader.attr('hidden', true);
            }
        }

        function stopLoop() {
            if (pollTimeout) {
                window.clearTimeout(pollTimeout);
                pollTimeout = null;
            }
        }

        function setButtonState(mode) {
            const labelMap = {
                running: strings.running_label,
                resume: strings.resume_label,
                restart: strings.restart_label,
                idle: strings.start_label,
            };

            const label = labelMap[mode] || strings.start_label;
            $archiveButton.prop('disabled', mode === 'running');
            $archiveButtonLabel.text(label);
        }

        function refreshButtonState() {
            if (running) {
                setButtonState('running');
                return;
            }

            if (currentState && !jobDone) {
                setButtonState('resume');
                return;
            }

            if (jobDone && currentState) {
                setButtonState('restart');
                return;
            }

            setButtonState('idle');
        }

        function updateProgress(state) {
            if (!state) {
                $archiveProgress.attr('hidden', true);
                return;
            }

            $archiveProgress.removeAttr('hidden');

            const processed = Number.parseInt(state.processed, 10) || 0;
            const total = Number.parseInt(state.total, 10) || 0;
            const remaining = Math.max(0, total - processed);
            const percentage = Math.min(100, Math.max(0, Math.round((Number(state.progress) || 0) * 100)));

            $archiveProgressFill.css('width', `${percentage}%`);
            $archiveProgressLabel.text(`${percentage}%`);
            $archiveProgressCount.text(`${processed.toLocaleString()} / ${total.toLocaleString()}`);

            const fragments = [];

            fragments.push(
                formatNumbered(strings.records_progress, [
                    processed.toLocaleString(),
                    total.toLocaleString(),
                ])
            );

            if (remaining > 0) {
                fragments.push(
                    formatNumbered(strings.records_remaining, [remaining.toLocaleString()])
                );
            }

            if (state.last_batch) {
                const lastBatch = Number.parseInt(state.last_batch, 10) || 0;
                if (lastBatch > 0) {
                    fragments.push(formatNumbered(strings.last_batch, [lastBatch.toLocaleString()]));
                }
            }

            if (batchSize && fragments.length === 1 && !running && !jobDone) {
                fragments.push(formatNumbered(strings.last_batch, [batchSize.toLocaleString()]));
            }

            $archiveStatusText.text(fragments.filter(Boolean).join(' · '));
        }

        function assignState(data) {
            if (!data) {
                currentState = null;
                jobDone = false;
                return;
            }

            currentState = data.state || null;
            jobDone = Boolean(data.done);
        }

        function callArchiveEndpoint(action, nonce) {
            const params = new URLSearchParams();
            params.append('action', action);
            params.append('nonce', nonce);

            return fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: params.toString(),
            }).then((response) =>
                response
                    .json()
                    .catch(() => ({}))
                    .then((json) => ({
                        ok: response.ok,
                        json,
                    }))
            );
        }

        function parseResponse(result) {
            const payload = result && result.json ? result.json : {};

            if (payload && payload.success) {
                return payload.data || {};
            }

            const data = payload ? payload.data : null;

            if (data && typeof data === 'object' && data.message) {
                throw new Error(data.message);
            }

            if (typeof data === 'string') {
                throw new Error(data);
            }

            throw new Error(strings.error_generic);
        }

        function handleArchiveError(message) {
            stopLoop();
            running = false;
            toggleLoader(false);
            setFeedback($archiveFeedback, 'error', message || strings.error_generic);
            refreshButtonState();
        }

        function finalizeSuccess(message) {
            stopLoop();
            running = false;
            jobDone = true;
            toggleLoader(false);
            setFeedback($archiveFeedback, 'success', message || strings.completed_label);
            refreshButtonState();
        }

        function scheduleNextStep() {
            stopLoop();

            if (!running) {
                return;
            }

            const delay = Number.isFinite(stepInterval) && stepInterval > 0 ? stepInterval : 1500;
            pollTimeout = window.setTimeout(runStep, delay);
        }

        function beginArchive() {
            if (!archiveConfig.start_nonce) {
                handleArchiveError(strings.error_generic);
                return;
            }

            stopLoop();
            running = true;
            jobDone = false;
            toggleLoader(true);
            setButtonState('running');

            callArchiveEndpoint('hic_archive_old_data_start', archiveConfig.start_nonce)
                .then(parseResponse)
                .then((data) => {
                    assignState(data);
                    updateProgress(currentState);

                    if (data.message) {
                        setFeedback($archiveFeedback, data.done ? 'success' : 'info', data.message);
                    } else {
                        setFeedback($archiveFeedback, 'info', strings.running_label);
                    }

                    if (jobDone) {
                        finalizeSuccess(data.message || strings.completed_label);
                        return;
                    }

                    scheduleNextStep();
                })
                .catch((error) => {
                    handleArchiveError(error && error.message ? error.message : strings.error_generic);
                });
        }

        function runStep() {
            if (!running) {
                return;
            }

            if (!archiveConfig.step_nonce) {
                handleArchiveError(strings.error_generic);
                return;
            }

            callArchiveEndpoint('hic_archive_old_data_step', archiveConfig.step_nonce)
                .then(parseResponse)
                .then((data) => {
                    assignState(data);
                    updateProgress(currentState);

                    if (data.message) {
                        setFeedback($archiveFeedback, data.done ? 'success' : 'info', data.message);
                    }

                    if (jobDone) {
                        finalizeSuccess(data.message || strings.completed_label);
                        return;
                    }

                    scheduleNextStep();
                })
                .catch((error) => {
                    handleArchiveError(error && error.message ? error.message : strings.error_generic);
                });
        }

        function resumeArchive() {
            stopLoop();
            running = true;
            toggleLoader(true);
            setFeedback($archiveFeedback, 'info', strings.running_label);
            setButtonState('running');
            runStep();
        }

        function fetchStatus() {
            if (!archiveConfig.status_nonce) {
                refreshButtonState();
                return;
            }

            toggleLoader(true);

            callArchiveEndpoint('hic_archive_old_data_status', archiveConfig.status_nonce)
                .then(parseResponse)
                .then((data) => {
                    if (data && data.active && data.state) {
                        assignState({ state: data.state, done: data.done });
                        updateProgress(currentState);
                        setFeedback($archiveFeedback, 'info', strings.resume_hint);
                    } else {
                        assignState(null);
                        updateProgress(null);
                        setFeedback($archiveFeedback, null, '');
                    }
                })
                .catch(() => {
                    assignState(null);
                    updateProgress(null);
                })
                .finally(() => {
                    toggleLoader(false);
                    refreshButtonState();
                });
        }

        $archiveButton.on('click', function () {
            if (running) {
                return;
            }

            setFeedback($archiveFeedback, null, '');

            if (currentState && !jobDone) {
                resumeArchive();
            } else {
                beginArchive();
            }
        });

        updateProgress(null);
        refreshButtonState();
        toggleLoader(false);
        fetchStatus();
    }
});
