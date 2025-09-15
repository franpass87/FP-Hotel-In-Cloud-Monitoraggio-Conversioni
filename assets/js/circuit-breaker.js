jQuery(function($){
    if (typeof ajaxurl === 'undefined') {
        var ajaxurl = hicCircuitBreaker.ajaxUrl;
    }

    function loadCircuitStatus() {
        $.post(ajaxurl, {
            action: 'hic_get_circuit_status',
            nonce: hicCircuitBreaker.nonce
        }, function(response){
            if (response.success) {
                var html = '<pre>' + JSON.stringify(response.data, null, 2) + '</pre>';
                $('#circuit-status-grid').html(html);
            } else {
                $('#circuit-status-grid').text('Error loading circuit status');
            }
        }, 'json');
    }

    function loadRetryQueueStatus() {
        $.post(ajaxurl, {
            action: 'hic_get_retry_queue_status',
            nonce: hicCircuitBreaker.nonce
        }, function(response){
            if (response.success) {
                var data = response.data || {};
                var html = 'Total: ' + (data.total_items || 0) +
                    ', Queued: ' + (data.queued_items || 0) +
                    ', Processing: ' + (data.processing_items || 0) +
                    ', Completed: ' + (data.completed_items || 0) +
                    ', Failed: ' + (data.failed_items || 0);
                $('#retry-queue-status').text(html);
            } else {
                $('#retry-queue-status').text('Error loading retry queue status');
            }
        }, 'json');
    }

    $('#process-retry-queue').on('click', function(){
        $.post(ajaxurl, {
            action: 'hic_process_retry_queue_manual',
            nonce: hicCircuitBreaker.nonce
        }, function(response){
            if (response.success) {
                loadRetryQueueStatus();
            }
        }, 'json');
    });

    loadCircuitStatus();
    loadRetryQueueStatus();
});
