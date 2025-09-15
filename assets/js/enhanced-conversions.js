jQuery(function($){
    function fetchStats(){
        $.post(hicEnhancedConversions.ajaxUrl, {
            action: 'hic_get_enhanced_conversion_stats',
            nonce: hicEnhancedConversions.nonce
        }, function(response){
            if (response.success) {
                var data = response.data || {};
                $('#enhanced-conversion-stats').text(
                    'Total: ' + (data.total_conversions || 0) +
                    ', Uploaded: ' + (data.uploaded_conversions || 0) +
                    ', Pending: ' + (data.pending_conversions || 0) +
                    ', Failed: ' + (data.failed_conversions || 0)
                );
            } else {
                $('#enhanced-conversion-stats').text('Error loading statistics');
            }
        }, 'json');
    }

    $('#test-google-ads-connection').on('click', function(){
        $.post(hicEnhancedConversions.ajaxUrl, {
            action: 'hic_test_google_ads_connection',
            nonce: hicEnhancedConversions.nonce
        }, function(response){
            alert(response.data && response.data.message ? response.data.message : 'Request completed');
        }, 'json');
    });

    $('#upload-enhanced-conversions').on('click', function(){
        $.post(hicEnhancedConversions.ajaxUrl, {
            action: 'hic_upload_enhanced_conversions',
            nonce: hicEnhancedConversions.nonce
        }, function(response){
            alert(response.data && response.data.message ? response.data.message : 'Request completed');
            fetchStats();
        }, 'json');
    });

    fetchStats();
});
