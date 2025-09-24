jQuery(function($){
    // Ensure ajaxurl is defined
    if (typeof ajaxurl === 'undefined') {
        var ajaxurl = hicAdminSettings.ajax_url;
    }

    $('#hic-test-api-btn').on('click', function(){
        var $btn = $(this);
        var $result = $('#hic-test-result');
        var $loading = $('#hic-test-loading');

        // Show loading state
        $btn.prop('disabled', true);
        $result.hide();
        $loading.show();

        var data = {
            action: 'hic_test_api_connection',
            nonce: hicAdminSettings.api_nonce,
            prop_id: $('input[name="hic_property_id"]').val(),
            email: $('input[name="hic_api_email"]').val(),
            password: $('input[name="hic_api_password"]').val()
        };

        $.post(ajaxurl, data, function(response) {
            $loading.hide();
            $btn.prop('disabled', false);

            var resp = response.data || {};
            var messageClass = response.success ? 'notice-success' : 'notice-error';
            var icon = response.success ? 'dashicons-yes-alt' : 'dashicons-dismiss';
            var html = '<div class="notice ' + messageClass + ' inline">' +
                       '<p><span class="dashicons ' + icon + '"></span> ' + resp.message;

            if (response.success && resp.data_count !== undefined) {
                html += ' (' + resp.data_count + ' prenotazioni trovate negli ultimi 7 giorni)';
            }
            html += '</p></div>';
            $result.html(html).show();
        }, 'json').fail(function(xhr, status, error) {
            $loading.hide();
            $btn.prop('disabled', false);
            $result.html('<div class="notice notice-error inline">' +
                         '<p><span class="dashicons dashicons-dismiss"></span> Errore di comunicazione: ' + error + '</p>' +
                         '</div>').show();
        });
    });

    $('#hic-test-email-btn').on('click', function(){
        var email = $('#hic_admin_email_field').val();
        var resultDiv = $('#hic_email_test_result');

        if (!email) {
            resultDiv.html('<div style="color: red;">Inserisci un indirizzo email per il test.</div>');
            return;
        }

        resultDiv.html('<div style="color: blue;">Invio email di test in corso...</div>');

        var data = {
            action: 'hic_test_email_ajax',
            email: email,
            nonce: hicAdminSettings.email_nonce
        };

        fetch(ajaxurl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data)
        })
        .then(response => response.json())
        .then(result => {
            var resp = result.data || {};
            if (result.success) {
                resultDiv.html('<div style="color: green;">✓ ' + resp.message + '</div>');
            } else {
                resultDiv.html('<div style="color: red;">✗ ' + resp.message + '</div>');
            }
        })
        .catch(error => {
            resultDiv.html('<div style="color: red;">Errore nella richiesta: ' + error + '</div>');
        });
    });

    $('#hic-generate-health-token').on('click', function(){
        var $button = $(this);
        var $input = $('#hic_health_token');
        var $status = $('#hic-health-token-status');

        $button.prop('disabled', true);
        $status.text('Generazione token in corso...');

        var data = {
            action: 'hic_generate_health_token',
            nonce: hicAdminSettings.health_nonce
        };

        $.post(ajaxurl, data, function(response){
            var message = 'Impossibile generare un nuovo token.';
            if (response && response.success && response.data) {
                if (response.data.token) {
                    $input.val(response.data.token);
                }
                message = response.data.message || 'Nuovo token generato. Ricorda di salvare le impostazioni.';
            } else if (response && response.data && response.data.message) {
                message = response.data.message;
            }

            $status.text(message);
        }, 'json').fail(function(){
            $status.text('Errore durante la generazione del token. Riprova.');
        }).always(function(){
            $button.prop('disabled', false);
        });
    });
});
