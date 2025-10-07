/* global jQuery, hicAdminSettings */
jQuery(function ($) {
    const adminSettings = typeof hicAdminSettings !== 'undefined' ? hicAdminSettings : {};
    const ajaxUrl = typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : adminSettings.ajax_url;
    const archiveConfig = adminSettings.archive || null;

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

    function i18nBlock() { return (archiveConfig && archiveConfig.i18n) || {}; }
    function fmtNum(n) { return (Number.parseInt(n, 10) || 0).toLocaleString(); }
    function formatNumbered(template, values) {
        if (!template) { return ''; }
        let unnamedIndex = 0;
        const withNumbered = template.replace(/%(\d+)\$[sd]/g, function (_m, i) {
            const pos = Number.parseInt(i, 10) - 1; if (Number.isNaN(pos) || pos < 0 || pos >= values.length) { return ''; }
            return values[pos];
        });
        return withNumbered.replace(/%[sd]/g, function () {
            if (unnamedIndex >= values.length) { return ''; }
            const r = values[unnamedIndex]; unnamedIndex += 1; return r;
        });
    }

    const $btn = $('#hic-archive-start-btn');
    const $btnLabel = $('#hic-archive-button-label');
    const $progress = $('#hic-archive-progress');
    const $fill = $('#hic-archive-progress-fill');
    const $label = $('#hic-archive-progress-label');
    const $count = $('#hic-archive-progress-count');
    const $status = $('#hic-archive-status-text');
    const $loader = $('#hic-archive-loader');
    const $feedback = $('#hic-archive-feedback');

    if (!ajaxUrl || !archiveConfig || !$btn.length || !$btnLabel.length || !$progress.length || !$fill.length) { return; }

    const strings = (function () {
        const i18n = i18nBlock();
        return {
            start_label: i18n.start_label || 'Avvia archiviazione',
            resume_label: i18n.resume_label || 'Riprendi archiviazione',
            running_label: i18n.running_label || 'Archiviazione in corso…',
            restart_label: i18n.restart_label || 'Riesegui archiviazione',
            completed_label: i18n.completed_label || 'Archiviazione completata',
            error_generic: i18n.error_generic || 'Si è verificato un errore durante l\'archiviazione.',
            records_progress: i18n.records_progress || '%1$s di %2$s record archiviati',
            records_remaining: i18n.records_remaining || '%s record rimanenti',
            last_batch: i18n.last_batch || 'Ultimo batch: %s record',
            resume_hint: i18n.resume_hint || 'Job in pausa: riprendi per completare l\'archiviazione.',
        };
    }());

    const stepInterval = Number.isFinite(Number.parseInt(archiveConfig.step_interval, 10)) ? Number.parseInt(archiveConfig.step_interval, 10) : 1500;
    const configuredBatchSize = Number.isFinite(Number.parseInt(archiveConfig.batch_size, 10)) ? Number.parseInt(archiveConfig.batch_size, 10) : null;

    let currentState = null;
    let jobDone = false;
    let running = false;
    let pollTimeout = null;
    let effectiveBatchSize = configuredBatchSize;

    function toggleLoader(v) { if (!$loader.length) { return; } if (v) { $loader.removeAttr('hidden'); } else { $loader.attr('hidden', true); } }
    function stopLoop() { if (pollTimeout) { window.clearTimeout(pollTimeout); pollTimeout = null; } }
    function setButtonState(mode) {
        const map = { running: strings.running_label, resume: strings.resume_label, restart: strings.restart_label, idle: strings.start_label };
        $btn.prop('disabled', mode === 'running');
        $btnLabel.text(map[mode] || strings.start_label);
    }
    function refreshButtonState() {
        if (running) { setButtonState('running'); return; }
        if (currentState && !jobDone) { setButtonState('resume'); return; }
        if (jobDone && currentState) { setButtonState('restart'); return; }
        setButtonState('idle');
    }
    function updateProgress(state) {
        if (!state) { $progress.attr('hidden', true); return; }
        $progress.removeAttr('hidden');
        const processed = Number.parseInt(state.processed, 10) || 0;
        const total = Number.parseInt(state.total, 10) || 0;
        const remaining = Math.max(0, total - processed);
        const percentage = Math.min(100, Math.max(0, Math.round((Number(state.progress) || 0) * 100)));
        $fill.css('width', `${percentage}%`);
        $label.text(`${percentage}%`);
        $count.text(`${processed.toLocaleString()} / ${total.toLocaleString()}`);
        const fragments = [];
        fragments.push(formatNumbered(strings.records_progress, [fmtNum(processed), fmtNum(total)]));
        if (remaining > 0) { fragments.push(formatNumbered(strings.records_remaining, [fmtNum(remaining)])); }
        if (state.last_batch) {
            const lb = Number.parseInt(state.last_batch, 10) || 0; if (lb > 0) { fragments.push(formatNumbered(strings.last_batch, [fmtNum(lb)])); }
        }
        const fallbackBatch = Number.isFinite(effectiveBatchSize) ? effectiveBatchSize : configuredBatchSize;
        if (fallbackBatch && fragments.length === 1 && !running && !jobDone) { fragments.push(formatNumbered(strings.last_batch, [fmtNum(fallbackBatch)])); }
        $status.text(fragments.filter(Boolean).join(' · '));
    }
    function assignState(data) {
        if (!data) { currentState = null; jobDone = false; effectiveBatchSize = configuredBatchSize; return; }
        currentState = data.state || null; jobDone = Boolean(data.done);
        if (data.state && Object.prototype.hasOwnProperty.call(data.state, 'batch_size')) {
            const parsed = Number.parseInt(data.state.batch_size, 10);
            if (Number.isFinite(parsed) && parsed > 0) { effectiveBatchSize = parsed; }
        }
    }
    function callEndpoint(action, nonce) {
        const params = new URLSearchParams(); params.append('action', action); params.append('nonce', nonce);
        return fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: params.toString() })
        .then((response) => response.json().catch(() => ({})).then((json) => ({ ok: response.ok, json })));
    }
    function parseResponse(result) {
        const payload = result && result.json ? result.json : {};
        if (payload && payload.success) { return payload.data || {}; }
        const data = payload ? payload.data : null;
        if (data && typeof data === 'object' && data.message) { throw new Error(data.message); }
        if (typeof data === 'string') { throw new Error(data); }
        throw new Error(strings.error_generic);
    }
    function handleError(message) { stopLoop(); running = false; toggleLoader(false); setFeedback($feedback, 'error', message || strings.error_generic); refreshButtonState(); }
    function finalizeSuccess(message) { stopLoop(); running = false; jobDone = true; toggleLoader(false); setFeedback($feedback, 'success', message || strings.completed_label); refreshButtonState(); }
    function scheduleNext() { stopLoop(); if (!running) { return; } const delay = Number.isFinite(stepInterval) && stepInterval > 0 ? stepInterval : 1500; pollTimeout = window.setTimeout(runStep, delay); }
    function beginArchive() {
        if (!archiveConfig.start_nonce) { handleError(strings.error_generic); return; }
        stopLoop(); running = true; jobDone = false; toggleLoader(true); setButtonState('running');
        callEndpoint('hic_archive_old_data_start', archiveConfig.start_nonce)
        .then(parseResponse)
        .then((data) => { assignState(data); updateProgress(currentState); setFeedback($feedback, data.done ? 'success' : 'info', data.message || strings.running_label); if (jobDone) { finalizeSuccess(data.message || strings.completed_label); return; } scheduleNext(); })
        .catch((error) => { handleError(error && error.message ? error.message : strings.error_generic); });
    }
    function runStep() {
        if (!running) { return; }
        if (!archiveConfig.step_nonce) { handleError(strings.error_generic); return; }
        callEndpoint('hic_archive_old_data_step', archiveConfig.step_nonce)
        .then(parseResponse)
        .then((data) => { assignState(data); updateProgress(currentState); if (data.message) { setFeedback($feedback, data.done ? 'success' : 'info', data.message); } if (jobDone) { finalizeSuccess(data.message || strings.completed_label); return; } scheduleNext(); })
        .catch((error) => { handleError(error && error.message ? error.message : strings.error_generic); });
    }
    function resumeArchive() { stopLoop(); running = true; toggleLoader(true); setFeedback($feedback, 'info', strings.running_label); setButtonState('running'); runStep(); }
    function fetchStatus() {
        if (!archiveConfig.status_nonce) { refreshButtonState(); return; }
        toggleLoader(true);
        callEndpoint('hic_archive_old_data_status', archiveConfig.status_nonce)
        .then(parseResponse)
        .then((data) => { if (data && data.active && data.state) { assignState({ state: data.state, done: data.done }); updateProgress(currentState); setFeedback($feedback, 'info', strings.resume_hint); } else { assignState(null); updateProgress(null); setFeedback($feedback, null, ''); } })
        .catch(() => { assignState(null); updateProgress(null); })
        .finally(() => { toggleLoader(false); refreshButtonState(); });
    }

    $btn.on('click', function () { if (running) { return; } setFeedback($feedback, null, ''); if (currentState && !jobDone) { resumeArchive(); } else { beginArchive(); } });

    updateProgress(null); refreshButtonState(); toggleLoader(false); fetchStatus();
});


