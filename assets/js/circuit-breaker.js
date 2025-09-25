jQuery(function ($) {
    const ajaxConfig = window.hicCircuitBreaker || {};
    const ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : (ajaxConfig.ajaxUrl || '');
    const nonce = ajaxConfig.nonce || '';
    const hasAjaxUrl = typeof ajaxUrl === 'string' && ajaxUrl.length > 0;
    const hasNonce = typeof nonce === 'string' && nonce.length > 0;

    const STATUS_MAP = {
        closed: { label: 'Operativo', css: 'status-closed' },
        open: { label: 'Sospeso', css: 'status-open' },
        half_open: { label: 'In recupero', css: 'status-half-open' }
    };

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : value).html();
    }

    function formatTimestamp(value) {
        if (!value) {
            return '—';
        }

        const date = new Date(value.replace(' ', 'T'));

        if (Number.isNaN(date.getTime())) {
            return escapeHtml(value);
        }

        return escapeHtml(date.toLocaleString());
    }

    function renderCircuitSummary(circuits) {
        const $summary = $('#circuit-status-summary');

        if ($summary.length === 0) {
            return;
        }

        if (!Array.isArray(circuits) || circuits.length === 0) {
            $summary.html(`
                <div class="hic-circuit-summary-item status-total">
                    <strong>0</strong>
                    <span>Nessun servizio monitorato</span>
                </div>
            `);
            return;
        }

        const totals = { closed: 0, open: 0, half_open: 0 };

        circuits.forEach((circuit) => {
            const state = (circuit.state || 'closed').toLowerCase();

            if (typeof totals[state] === 'number') {
                totals[state] += 1;
            }
        });

        const totalServices = circuits.length;

        const summaryHtml = `
            <div class="hic-circuit-summary-item status-total">
                <strong>${totalServices}</strong>
                <span>Servizi monitorati</span>
            </div>
            <div class="hic-circuit-summary-item status-closed">
                <strong>${totals.closed}</strong>
                <span>Operativi</span>
            </div>
            <div class="hic-circuit-summary-item status-half-open">
                <strong>${totals.half_open}</strong>
                <span>In recupero</span>
            </div>
            <div class="hic-circuit-summary-item status-open">
                <strong>${totals.open}</strong>
                <span>Sospesi</span>
            </div>
        `;

        $summary.html(summaryHtml);
    }

    function renderCircuitStatus(circuits) {
        const $grid = $('#circuit-status-grid');

        if ($grid.length === 0) {
            return;
        }

        if (!Array.isArray(circuits) || circuits.length === 0) {
            renderCircuitSummary([]);
            $grid.html('<p class="hic-circuit-empty">Nessun circuito ha registrato anomalie.</p>');
            return;
        }

        renderCircuitSummary(circuits);

        const rows = circuits.map((circuit) => {
            const stateKey = (circuit.state || 'closed').toLowerCase();
            const status = STATUS_MAP[stateKey] || STATUS_MAP.closed;

            return `
                <tr>
                    <td class="service-name">${escapeHtml(circuit.service_name)}</td>
                    <td class="service-status">
                        <span class="status-label ${status.css}">${status.label}</span>
                    </td>
                    <td class="service-metrics">
                        <span class="metric">Errori: <strong>${parseInt(circuit.failure_count, 10) || 0}</strong></span>
                        <span class="metric">Successi: <strong>${parseInt(circuit.success_count, 10) || 0}</strong></span>
                    </td>
                    <td class="service-timestamps">
                        <span>Ultimo errore: <strong>${formatTimestamp(circuit.last_failure_time)}</strong></span>
                        <span>Ultimo ripristino: <strong>${formatTimestamp(circuit.last_success_time)}</strong></span>
                    </td>
                    <td class="service-thresholds">
                        <span>Soglia errori: <strong>${parseInt(circuit.failure_threshold, 10) || 0}</strong></span>
                        <span>Timeout recupero: <strong>${parseInt(circuit.recovery_timeout, 10) || 0}s</strong></span>
                    </td>
                </tr>
            `;
        }).join('');

        const tableHtml = `
            <table class="widefat fixed striped hic-circuit-status-table">
                <thead>
                    <tr>
                        <th>Servizio</th>
                        <th>Stato</th>
                        <th>Metriche</th>
                        <th>Ultimi eventi</th>
                        <th>Configurazione</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;

        $grid.html(tableHtml);
    }

    function renderQueueSummary(data) {
        const $target = $('#retry-queue-status');

        if ($target.length === 0) {
            return;
        }

        const totals = {
            total: parseInt(data.total_items, 10) || 0,
            queued: parseInt(data.queued_items, 10) || 0,
            processing: parseInt(data.processing_items, 10) || 0,
            completed: parseInt(data.completed_items, 10) || 0,
            failed: parseInt(data.failed_items, 10) || 0
        };

        const listHtml = `
            <ul class="hic-queue-metrics">
                <li><span class="label">Totali</span><span class="value">${totals.total}</span></li>
                <li><span class="label">In coda</span><span class="value">${totals.queued}</span></li>
                <li><span class="label">In elaborazione</span><span class="value">${totals.processing}</span></li>
                <li><span class="label">Completati</span><span class="value">${totals.completed}</span></li>
            </ul>
            <div class="hic-queue-failed ${totals.failed > 0 ? 'has-failed' : ''}">
                <span class="label">Falliti</span>
                <span class="value">${totals.failed}</span>
            </div>
        `;

        $target.html(listHtml);
    }

    function showError(targetSelector, message) {
        $(targetSelector).html(`<p class="hic-circuit-error">${escapeHtml(message || 'Si è verificato un errore imprevisto.')}</p>`);
    }

    function loadCircuitStatus() {
        if (!hasAjaxUrl || !hasNonce) {
            return;
        }

        $('#circuit-status-grid').attr('aria-busy', 'true');

        $.post(ajaxUrl, {
            action: 'hic_get_circuit_status',
            nonce
        }, null, 'json')
            .done((response) => {
                if (response && response.success) {
                    renderCircuitStatus(response.data || []);
                } else {
                    showError('#circuit-status-grid', 'Impossibile caricare lo stato dei servizi.');
                }
            })
            .fail(() => {
                showError('#circuit-status-grid', 'Errore di comunicazione con il server.');
            })
            .always(() => {
                $('#circuit-status-grid').attr('aria-busy', 'false');
            });
    }

    function loadRetryQueueStatus() {
        if (!hasAjaxUrl || !hasNonce) {
            return;
        }

        $.post(ajaxUrl, {
            action: 'hic_get_retry_queue_status',
            nonce
        }, null, 'json')
            .done((response) => {
                if (response && response.success) {
                    renderQueueSummary(response.data || {});
                } else {
                    showError('#retry-queue-status', 'Impossibile caricare lo stato della coda.');
                }
            })
            .fail(() => {
                showError('#retry-queue-status', 'Errore di comunicazione con il server.');
            });
    }

    $('#process-retry-queue').on('click', function () {
        if (!hasAjaxUrl || !hasNonce) {
            return;
        }

        const $button = $(this);
        const originalText = $button.text();

        $button.prop('disabled', true).text('Elaborazione in corso...');

        $.post(ajaxUrl, {
            action: 'hic_process_retry_queue_manual',
            nonce
        }, null, 'json')
            .done((response) => {
                if (response && response.success) {
                    loadRetryQueueStatus();
                } else {
                    showError('#retry-queue-status', 'Impossibile avviare l\'elaborazione manuale.');
                }
            })
            .fail(() => {
                showError('#retry-queue-status', 'Errore di comunicazione con il server.');
            })
            .always(() => {
                $button.prop('disabled', false).text(originalText);
            });
    });

    if (!hasAjaxUrl) {
        showError('#circuit-status-grid', 'Impossibile inizializzare il monitoraggio: endpoint AJAX non disponibile. Ricaricare la pagina o verificare conflitti di plugin.');
        showError('#retry-queue-status', 'Impossibile inizializzare il monitoraggio: endpoint AJAX non disponibile.');
        $('#process-retry-queue').prop('disabled', true);
        return;
    }

    if (!hasNonce) {
        showError('#circuit-status-grid', 'Sessione scaduta: ricaricare la pagina per aggiornare la sicurezza delle richieste.');
        showError('#retry-queue-status', 'Sessione scaduta: ricaricare la pagina per aggiornare la sicurezza delle richieste.');
        $('#process-retry-queue').prop('disabled', true);
        return;
    }

    loadCircuitStatus();
    loadRetryQueueStatus();
});
