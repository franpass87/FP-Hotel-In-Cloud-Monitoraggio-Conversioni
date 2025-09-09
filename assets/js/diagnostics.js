jQuery(document).ready(function($) {

    // Ensure ajaxurl is defined for AJAX calls
    if (typeof ajaxurl === 'undefined') {
        var ajaxurl = hicDiagnostics.ajax_url;
    }

    // Helper functions for monitoring endpoints with security nonce
    window.hicRunHealthCheck = function(level, callback) {
        $.post(ajaxurl, {
            action: 'hic_health_check',
            nonce: hicDiagnostics.monitor_nonce,
            level: level || 'basic'
        }, callback, 'json');
    };

    window.hicGetPerformanceMetrics = function(type, days, callback) {
        $.post(ajaxurl, {
            action: 'hic_performance_metrics',
            nonce: hicDiagnostics.monitor_nonce,
            type: type || 'summary',
            days: days || 7
        }, callback, 'json');
    };

        // Enhanced UI functionality

        // Toast notification system
        function showToast(message, type = 'info', duration = 5000) {
            var toastContainer = $('#hic-toast-container');
            if (toastContainer.length === 0) {
                $('body').append('<div id="hic-toast-container" class="hic-toast-container"></div>');
            }
            
            var icons = {
                success: '✓',
                error: '✗',
                warning: '⚠',
                info: 'ℹ'
            };
            
            var toast = $(
                '<div class="hic-toast ' + type + '">' +
                    '<div class="hic-toast-content">' +
                        '<span class="hic-toast-icon">' + (icons[type] || icons.info) + '</span>' +
                        '<span class="hic-toast-message">' + message + '</span>' +
                        '<button class="hic-toast-close">&times;</button>' +
                    '</div>' +
                '</div>'
            );
            
            $('#hic-toast-container').append(toast);
            
            // Show toast
            setTimeout(function() { toast.addClass('show'); }, 100);
            
            // Auto remove
            var autoRemove = setTimeout(function() {
                toast.removeClass('show');
                setTimeout(function() { toast.remove(); }, 300);
            }, duration);
            
            // Manual close
            toast.find('.hic-toast-close').click(function() {
                clearTimeout(autoRemove);
                toast.removeClass('show');
                setTimeout(function() { toast.remove(); }, 300);
            });
        }
        
        // Auto-refresh system status every 30 seconds
        var refreshInterval;
        function startAutoRefresh() {
            refreshInterval = setInterval(function() {
                $('#refresh-indicator').addClass('active');
                
                // Refresh connection status and key metrics
                $.post(ajaxurl, {
                    action: 'hic_get_system_status',
                    nonce: hicDiagnostics.diagnostics_nonce
                }, function(response) {
                    if (response.success) {
                        // Update polling status
                        var pollingStatus = response.data.polling_active ? 
                            '<span class="status ok">✓ Attivo</span>' : 
                            '<span class="status error">✗ Inattivo</span>';
                        
                        // Find and update the polling status
                        $('#system-overview').find('td').each(function() {
                            if ($(this).text().includes('Polling Attivo') || $(this).text().includes('Polling Inattivo')) {
                                $(this).html(pollingStatus);
                            }
                        });
                        
                        // Update last execution time if available
                        if (response.data.last_execution) {
                            $('#system-overview').find('td').each(function() {
                                if ($(this).prev().text() === 'Ultimo Polling') {
                                    $(this).text(response.data.last_execution);
                                }
                            });
                        }
                    }
                }).always(function() {
                    setTimeout(function() { $('#refresh-indicator').removeClass('active'); }, 1000);
                });
            }, 30000);
        }
        
        // Enhanced button interactions
        function enhanceButton($button, loadingText = null) {
            var originalText = $button.html();
            var originalClass = $button.attr('class');
            
            return {
                setLoading: function() {
                    $button.addClass('loading').prop('disabled', true);
                    if (loadingText) {
                        $button.text(loadingText);
                    }
                },
                setSuccess: function(message = null) {
                    $button.removeClass('loading').addClass('success');
                    if (message) {
                        showToast(message, 'success');
                    }
                    setTimeout(function() {
                        $button.removeClass('success').prop('disabled', false).html(originalText);
                    }, 2000);
                },
                setError: function(message = null) {
                    $button.removeClass('loading').addClass('error');
                    if (message) {
                        showToast(message, 'error');
                    }
                    setTimeout(function() {
                        $button.removeClass('error').prop('disabled', false).html(originalText);
                    }, 3000);
                },
                reset: function() {
                    $button.removeClass('loading success error').prop('disabled', false).html(originalText);
                }
            };
        }
        
        // Copy to clipboard functionality
        function addCopyButton(selector, textSelector = null) {
            $(selector).each(function() {
                var $element = $(this);
                var $copyBtn = $('<button class="hic-copy-button" title="Copia negli appunti">📋</button>');
                
                $copyBtn.click(function() {
                    var text = textSelector ? $element.find(textSelector).text() : $element.text();
                    navigator.clipboard.writeText(text).then(function() {
                        $copyBtn.addClass('copied').text('✓');
                        showToast('Copiato negli appunti!', 'success', 2000);
                        setTimeout(function() {
                            $copyBtn.removeClass('copied').text('📋');
                        }, 2000);
                    }).catch(function() {
                        showToast('Errore nella copia', 'error');
                    });
                });
                
                $element.append($copyBtn);
            });
        }
        
        // Initialize enhanced features
        startAutoRefresh();
        addCopyButton('.hic-log-entry');
        
        // Add progress bar to long operations
        function createProgressBar() {
            return $('<div class="hic-progress-bar"><div class="hic-progress-fill"></div></div>');
        }
        
        function updateProgress($progressBar, percent) {
            $progressBar.find('.hic-progress-fill').css('width', percent + '%');
        }
        
        // Backfill handler
        $('#start-backfill').click(function() {
            var $btn = $(this);
            var $status = $('#backfill-status');
            var $results = $('#backfill-results');
            var $resultsContent = $('#backfill-results-content');
            
            // Get form values
            var fromDate = $('#backfill-from-date').val();
            var toDate = $('#backfill-to-date').val();
            var dateType = $('#backfill-date-type').val();
            var limit = $('#backfill-limit').val();
            
            // Validate form
            if (!fromDate || !toDate) {
                alert('Inserisci entrambe le date di inizio e fine.');
                return;
            }
            
            if (new Date(fromDate) > new Date(toDate)) {
                alert('La data di inizio deve essere precedente alla data di fine.');
                return;
            }
            
            // Validate limit range if provided
            if (limit) {
                limit = parseInt(limit, 10);
                if (limit < 1 || limit > 200) {
                    alert('Il limite deve essere compreso tra 1 e 200.');
                    return;
                }
            }

            // Confirmation
            var message = 'Vuoi avviare il backfill delle prenotazioni dal ' + fromDate + ' al ' + toDate + '?';
            if (limit) {
                message += '\nLimite: ' + limit + ' prenotazioni';
            }
            message += '\nTipo data: ' + (dateType === 'checkin' ? 'Check-in' : dateType === 'checkout' ? 'Check-out' : 'Presenza');
            
            if (!confirm(message)) {
                return;
            }
            
            // Start backfill
            $btn.prop('disabled', true);
            $status.text('Avviando backfill...').css('color', '#0073aa');
            $results.hide();
            
            var postData = {
                action: 'hic_backfill_reservations',
                nonce: hicDiagnostics.diagnostics_nonce,
                from_date: fromDate,
                to_date: toDate,
                date_type: dateType
            };
            
            if (limit) {
                postData.limit = limit;
            }
            
            $.post(ajaxurl, postData, function(response) {
                if (response.success) {
                    $status.text('Backfill completato!').css('color', '#46b450');

                    var stats = response.stats;
                    var html = '<p><strong>' + response.message + '</strong></p>' +
                              '<ul>' +
                              '<li>Prenotazioni trovate: <strong>' + stats.total_found + '</strong></li>' +
                              '<li>Prenotazioni processate: <strong>' + stats.total_processed + '</strong></li>' +
                              '<li>Prenotazioni saltate: <strong>' + stats.total_skipped + '</strong></li>' +
                              '<li>Errori: <strong>' + stats.total_errors + '</strong></li>' +
                              '<li>Tempo di esecuzione: <strong>' + stats.execution_time + ' secondi</strong></li>' +
                              '<li>Intervallo date: <strong>' + stats.date_range + '</strong></li>' +
                              '<li>Tipo data: <strong>' + stats.date_type + '</strong></li>' +
                              '</ul>';

                    $resultsContent.html(html);
                    $results.show();

                } else {
                    $status.text('Errore durante il backfill').css('color', '#dc3232');

                    var html = '<p><strong>Errore:</strong> ' + response.message + '</p>';
                    if (response.stats && Object.keys(response.stats).length > 0) {
                        html += '<p><strong>Statistiche parziali:</strong></p><ul>';
                        Object.keys(response.stats).forEach(function(key) {
                            if (response.stats[key] !== null && response.stats[key] !== '') {
                                html += '<li>' + key + ': ' + response.stats[key] + '</li>';
                            }
                        });
                        html += '</ul>';
                    }

                    $resultsContent.html(html);
                    $results.show();
                }

                $btn.prop('disabled', false);

            }, 'json').fail(function() {
                $status.text('Errore di comunicazione con il server').css('color', '#dc3232');
                $btn.prop('disabled', false);
            });
        });
        
        // Download latest bookings handler (now sends to integrations)
        $('#download-latest-bookings').click(function() {
            var $btn = $(this);
            var $status = $('#download-status');
            var $results = $('#download-results');
            var $resultsContent = $('#download-results-content');
            
            // Validate API configuration
            if (!hicDiagnostics.is_api_connection) {
                alert('Questa funzione richiede la modalità API. Il sistema è configurato per webhook.');
                return;
            }

            if (!hicDiagnostics.has_basic_auth) {
                alert('Credenziali Basic Auth non configurate. Verifica le impostazioni.');
                return;
            }

            if (!hicDiagnostics.has_property_id) {
                alert('Property ID non configurato. Verifica le impostazioni.');
                return;
            }
            
            // Confirmation
            if (!confirm('Vuoi scaricare le ultime 5 prenotazioni da HIC e inviarle alle integrazioni configurate (GA4, Brevo, etc.)?')) {
                return;
            }
            
            // Start process
            $btn.prop('disabled', true);
            $status.text('Scaricando e inviando prenotazioni...').css('color', '#0073aa');
            $results.hide();
            
            $.post(ajaxurl, {
                action: 'hic_download_latest_bookings',
                nonce: hicDiagnostics.diagnostics_nonce
            }, function(response) {
                
                if (response.success) {
                    $status.text('Invio completato!').css('color', '#46b450');
                    
                    // Build results HTML
                    var html = '<p><strong>' + response.message + '</strong></p>' +
                              '<ul>' +
                              '<li>Prenotazioni elaborate: <strong>' + response.count + '</strong></li>' +
                              '<li>Invii riusciti: <strong class="status ok">' + response.success_count + '</strong></li>' +
                              '<li>Invii falliti: <strong class="status ' + (response.error_count > 0 ? 'error' : 'ok') + '">' + response.error_count + '</strong></li>' +
                              '</ul>';
                    
                    // Add integration status
                    html += '<h4>Integrazioni Attive:</h4><ul>';
                    if (response.integration_status.ga4_configured) {
                        html += '<li><span class="status ok">✓ GA4</span> - Eventi inviati</li>';
                    } else {
                        html += '<li><span class="status error">✗ GA4</span> - Non configurato</li>';
                    }
                    if (response.integration_status.brevo_configured) {
                        html += '<li><span class="status ok">✓ Brevo</span> - Contatti ed eventi inviati</li>';
                    } else {
                        html += '<li><span class="status error">✗ Brevo</span> - Non configurato</li>';
                    }
                    if (response.integration_status.facebook_configured) {
                        html += '<li><span class="status ok">✓ Facebook</span> - Eventi inviati</li>';
                    } else {
                        html += '<li><span class="status error">✗ Facebook</span> - Non configurato</li>';
                    }
                    html += '<li><span class="status ok">✓ Email</span> - Notifiche admin inviate</li>';
                    html += '</ul>';
                    
                    // Add booking details if available
                    if (response.processing_results && response.processing_results.length > 0) {
                        html += '<h4>Dettaglio Prenotazioni Elaborate:</h4>';
                        html += '<table style="width: 100%; border-collapse: collapse;">';
                        html += '<tr style="background: #f1f1f1; font-weight: bold;"><th style="padding: 8px; border: 1px solid #ddd;">ID</th><th style="padding: 8px; border: 1px solid #ddd;">Email</th><th style="padding: 8px; border: 1px solid #ddd;">Importo</th><th style="padding: 8px; border: 1px solid #ddd;">Stato</th></tr>';
                        
                        response.processing_results.forEach(function(booking) {
                            var statusIcon = booking.success ? '<span class="status ok">✓</span>' : '<span class="status error">✗</span>';
                            html += '<tr>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + booking.booking_id + '</td>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + booking.email + '</td>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + booking.amount + '</td>' +
                                   '<td style="padding: 8px; border: 1px solid #ddd;">' + statusIcon + '</td>' +
                                   '</tr>';
                        });
                        html += '</table>';
                    }
                    
                    $resultsContent.html(html);
                    $results.show();
                    
                } else {
                    $status.text('Errore durante l\'invio').css('color', '#dc3232');
                    if (response.already_downloaded) {
                        // Special handling for already downloaded message
                        alert(response.message);
                    } else {
                        alert('Errore: ' + response.message);
                    }
                }
                
                $btn.prop('disabled', false);
                
            }, 'json').fail(function() {
                $status.text('Errore di comunicazione con il server').css('color', '#dc3232');
                $btn.prop('disabled', false);
            });
        });
        
        // Reset download tracking handler (now tracks sending to integrations)
        $('#reset-download-tracking').click(function() {
            var $btn = $(this);
            var $status = $('#download-status');
            
            if (!confirm('Vuoi resettare il tracking degli invii? Dopo il reset potrai inviare nuovamente tutte le prenotazioni alle integrazioni.')) {
                return;
            }
            
            $btn.prop('disabled', true);
            $status.text('Resettando tracking...').css('color', '#0073aa');
            
            $.post(ajaxurl, {
                action: 'hic_reset_download_tracking',
                nonce: hicDiagnostics.diagnostics_nonce
            }, function(response) {
                
                if (response.success) {
                    $status.text('Tracking resettato!').css('color', '#46b450');
                    
                    // Refresh the page after 2 seconds to update the UI
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                    
                } else {
                    $status.text('Errore durante il reset').css('color', '#dc3232');
                    alert('Errore: ' + response.message);
                }
                
                $btn.prop('disabled', false);
                
            }, 'json').fail(function() {
                $status.text('Errore di comunicazione con il server').css('color', '#dc3232');
                $btn.prop('disabled', false);
            });
        });
        
        // Force Polling handler (updated for new design with enhanced UX)
        $('#force-polling').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Eseguendo...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            buttonController.setLoading();
            $status.text('Test polling in corso...').css('color', '#0073aa');
            $results.hide();
            
            // Add progress bar
            var $progressBar = createProgressBar();
            $status.after($progressBar);
            updateProgress($progressBar, 20);
            
            $.post(ajaxurl, {
                action: 'hic_force_polling',
                force: 'true',
                nonce: hicDiagnostics.diagnostics_nonce
            }, function(response) {
                updateProgress($progressBar, 80);

                if (response.success) {
                    updateProgress($progressBar, 100);
                    buttonController.setSuccess('Test completato con successo!');
                    $status.text('✓ Test completato!').css('color', '#00a32a');

                    var html = '<div class="notice notice-success inline"><p><strong>Test Polling Completato:</strong><br>';
                    html += response.data.message + '<br>';
                    if (response.data.execution_time) {
                        html += 'Tempo esecuzione: ' + response.data.execution_time + ' secondi<br>';
                    }
                    html += '</p></div>';

                    $resultsContent.html(html);
                    $results.show();

                    // Refresh page after 3 seconds
                    setTimeout(function() {
                        showToast('Aggiornamento dati...', 'info', 2000);
                        location.reload();
                    }, 3000);

                } else {
                    updateProgress($progressBar, 100);
                    buttonController.setError('Test fallito: ' + (response.data.message || 'Errore sconosciuto'));
                    $status.text('✗ Test fallito').css('color', '#d63638');

                    var html = '<div class="notice notice-error inline"><p><strong>Errore Test:</strong><br>';
                    html += response.data.message || 'Errore sconosciuto';
                    html += '</p></div>';

                    $resultsContent.html(html);
                    $results.show();
                }

                // Remove progress bar
                setTimeout(function() { $progressBar.remove(); }, 1000);

            }, 'json').fail(function() {
                updateProgress($progressBar, 100);
                buttonController.setError('Errore di comunicazione con il server');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');

                // Remove progress bar after showing completion
                setTimeout(function() { $progressBar.remove(); }, 1000);
            });
        });
        
        // Test Connectivity handler (enhanced with better UX)
        $('#test-connectivity').click(function() {
            console.log('Test connectivity button clicked'); // Debug log
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Testando...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            buttonController.setLoading();
            $status.text('Test connessione in corso...').css('color', '#0073aa');
            $results.hide();
            
            // Use the existing force polling but without force flag for normal test
            $.post(ajaxurl, {
                action: 'hic_force_polling',
                force: 'false',
                nonce: hicDiagnostics.diagnostics_nonce
            }, function(response) {
                console.log('Test connectivity response received:', response); // Debug log

                if (response.success) {
                    buttonController.setSuccess('Connessione verificata!');
                    $status.text('✓ Connessione OK').css('color', '#00a32a');

                    var html = '<div class="notice notice-success inline"><p><strong>Test Connessione Riuscito:</strong><br>';
                    html += response.data.message + '</p></div>';

                } else {
                    buttonController.setError('Connessione fallita: ' + (response.data.message || 'Errore sconosciuto'));
                    $status.text('✗ Connessione fallita').css('color', '#d63638');

                    var html = '<div class="notice notice-warning inline"><p><strong>Test Connessione Fallito:</strong><br>';
                    html += response.data.message || 'Errore sconosciuto';
                    html += '</p></div>';
                }

                $resultsContent.html(html);
                $results.show();

            }, 'json').fail(function(xhr, status, error) {
                console.error('Test connectivity AJAX failed:', status, error); // Debug log
                buttonController.setError('Errore di comunicazione');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
            });
        });
        
        // Trigger Watchdog handler (enhanced with better UX)
        $('#trigger-watchdog').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Eseguendo...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            buttonController.setLoading();
            $status.text('Watchdog in corso...').css('color', '#0073aa');
            $results.hide();
            
            $.post(ajaxurl, {
                action: 'hic_trigger_watchdog',
                nonce: hicDiagnostics.admin_nonce
            }).done(function(response) {
                if (response.success) {
                    buttonController.setSuccess('Watchdog completato con successo!');
                    $status.text('✓ Watchdog completato').css('color', '#00a32a');

                    var html = '<div class="notice notice-success inline"><p><strong>Watchdog Completato:</strong><br>';
                    html += response.data.message + '</p></div>';

                    $resultsContent.html(html);
                    $results.show();
                } else {
                    buttonController.setError('Watchdog fallito: ' + (response.data.message || 'Errore sconosciuto'));
                    $status.text('✗ Watchdog fallito').css('color', '#d63638');

                    var html = '<div class="notice notice-warning inline"><p><strong>Watchdog Fallito:</strong><br>';
                    html += response.data.message || 'Errore sconosciuto';
                    html += '</p></div>';

                    $resultsContent.html(html);
                    $results.show();
                }
                
                setTimeout(function() {
                    showToast('Aggiornamento dati...', 'info', 2000);
                    location.reload();
                }, 3000);
                
            }).fail(function() {
                buttonController.setError('Errore di comunicazione con il server');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
            });
        });
        
        // Reset Timestamps handler (enhanced with better UX and warnings)
        $('#reset-timestamps').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Resettando...');
            var $status = $('#quick-status');
            var $results = $('#quick-results');
            var $resultsContent = $('#quick-results-content');
            
            // Enhanced confirmation with more details
            var confirmMessage = 'ATTENZIONE: Reset Timestamp di Emergenza\n\n' +
                               'Questa azione resetterà TUTTI i timestamp del sistema:\n' +
                               '• Ultimo polling eseguito\n' +
                               '• Orari di scheduling\n' +
                               '• Cache delle prenotazioni\n\n' +
                               'Utilizzare SOLO se il polling è completamente bloccato.\n\n' +
                               'Sei sicuro di voler procedere?';
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Second confirmation for safety
            if (!confirm('Ultima conferma: procedere con il reset di emergenza?')) {
                return;
            }
            
            buttonController.setLoading();
            $status.text('Reset emergenza in corso...').css('color', '#d63638');
            $results.hide();
            
            showToast('Reset di emergenza avviato...', 'warning');
            
            $.post(ajaxurl, {
                action: 'hic_reset_timestamps',
                nonce: hicDiagnostics.admin_nonce
            }).done(function(response) {
                if (response.success) {
                    buttonController.setSuccess('Reset completato!');
                    $status.text('✓ Reset completato').css('color', '#00a32a');
                    
                    var html = '<div class="notice notice-success inline"><p><strong>Reset Timestamp Completato:</strong><br>';
                    html += response.message + '<br><br>';
                    html += '<em>Il sistema dovrebbe riprendere il polling normalmente.</em></p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                    
                    showToast('Sistema ripristinato! La pagina si aggiornerà automaticamente.', 'success');
                } else {
                    buttonController.setError('Reset fallito: ' + (response.message || 'Errore sconosciuto'));
                    $status.text('✗ Reset fallito').css('color', '#d63638');
                    
                    var html = '<div class="notice notice-error inline"><p><strong>Reset Fallito:</strong><br>';
                    html += response.message || 'Errore sconosciuto';
                    html += '</p></div>';
                    
                    $resultsContent.html(html);
                    $results.show();
                }
                
                setTimeout(function() {
                    showToast('Aggiornamento dati...', 'info', 2000);
                    location.reload();
                }, 4000);
                
            }).fail(function() {
                buttonController.setError('Errore di comunicazione con il server');
                $status.text('✗ Errore comunicazione').css('color', '#d63638');
                showToast('Errore di comunicazione durante il reset', 'error');
            });
        });
        
        // Advanced Reset Timestamps handler (for the second button)
        $('#reset-timestamps-advanced').click(function() {
            var $btn = $(this);
            var buttonController = enhanceButton($btn, 'Resettando...');
            
            // Simple confirmation for advanced users
            if (!confirm('Procedere con il reset dei timestamp del sistema?')) {
                return;
            }
            
            buttonController.setLoading();
            
            $.post(ajaxurl, {
                action: 'hic_reset_timestamps',
                nonce: hicDiagnostics.admin_nonce
            }).done(function(response) {
                if (response.success) {
                    buttonController.setSuccess('Reset completato!');
                    showToast('Reset timestamp completato con successo!', 'success');
                } else {
                    buttonController.setError('Reset fallito: ' + (response.message || 'Errore sconosciuto'));
                    showToast('Errore durante il reset', 'error');
                }
                
                setTimeout(function() {
                    location.reload();
                }, 2000);
                
            }).fail(function() {
                buttonController.setError('Errore di comunicazione con il server');
                showToast('Errore di comunicazione durante il reset', 'error');
            });
        });
        
        // Log download handler (same functionality)
        $('#download-error-logs').click(function() {
            var $btn = $(this);
            
            if (!confirm('Vuoi scaricare il file di log degli errori?')) {
                return;
            }
            
            // Create hidden form for download
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = ajaxurl;
            form.style.display = 'none';
            
            // Add action parameter
            var actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'hic_download_error_logs';
            form.appendChild(actionInput);
            
            // Add nonce parameter
            var nonceInput = document.createElement('input');
            nonceInput.type = 'hidden';
            nonceInput.name = 'nonce';
            nonceInput.value = hicDiagnostics.diagnostics_nonce;
            form.appendChild(nonceInput);
            
            // Submit form for download
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        
        });
        
        // Brevo connectivity test handler (same functionality, using existing structure)
        $('#test-brevo-connectivity').click(function() {
            var $btn = $(this);
            var $results = $('#brevo-test-results');
            
            $btn.prop('disabled', true).text('Testando...');
            $results.hide();
            
            $.post(ajaxurl, {
                action: 'hic_test_brevo_connectivity',
                nonce: hicDiagnostics.admin_nonce
            }).done(function(response) {
                var html = '';

                if (response.data && response.data.contact_api && response.data.event_api) {
                    var noticeClass = response.success ? 'notice-success' : 'notice-error';
                    html += '<div class="notice ' + noticeClass + ' inline">';
                    if (response.success) {
                        html += '<p><strong>Test Connettività Brevo Completato</strong></p>';
                    } else {
                        html += '<p><strong>Test Fallito:</strong> ' + response.data.message + '</p>';
                    }

                    // Contact API results
                    html += '<h4>API Contatti:</h4>';
                    if (response.data.contact_api.success) {
                        html += '<p><span class="status ok">✓ Successo</span> - HTTP ' + response.data.contact_api.http_code + '</p>';
                    } else {
                        html += '<p><span class="status error">✗ Errore</span> - ' + response.data.contact_api.message + '</p>';
                    }

                    // Event API results
                    html += '<h4>API Eventi:</h4>';
                    if (response.data.event_api.success) {
                        html += '<p><span class="status ok">✓ Successo</span> - HTTP ' + response.data.event_api.http_code + '</p>';
                    } else {
                        html += '<p><span class="status error">✗ Errore</span> - ' + response.data.event_api.message + '</p>';
                    }

                    html += '</div>';
                } else {
                    html = '<div class="notice notice-error inline"><p><strong>Test Fallito:</strong><br>' + response.data.message + '</p></div>';
                }

                $results.html(html).show();

            }).fail(function() {
                $results.html('<div class="notice notice-error inline"><p><strong>Errore di comunicazione con il server</strong></p></div>').show();
            }).always(function() {
                $btn.prop('disabled', false).text('Test API');
            });
        });
        
        // Quick Brevo connectivity test handler (for the integration card button)
        $('#test-brevo-connectivity-quick').click(function() {
            var $btn = $(this);
            
            $btn.prop('disabled', true).text('Testing...');
            
            $.post(ajaxurl, {
                action: 'hic_test_brevo_connectivity',
                nonce: hicDiagnostics.admin_nonce
            }).done(function(response) {
                if (!response.success) {
                    var message = 'Brevo API test failed:';
                    if (response.data.contact_api) {
                        if (response.data.contact_api.success) {
                            message += ' Contact API OK.';
                        } else {
                            message += ' Contact API - ' + response.data.contact_api.message + '.';
                        }
                    }
                    if (response.data.event_api) {
                        if (response.data.event_api.success) {
                            message += ' Event API OK.';
                        } else {
                            message += ' Event API - ' + response.data.event_api.message + '.';
                        }
                    }
                    if (!response.data.contact_api && !response.data.event_api && response.data.message) {
                        message += ' ' + response.data.message;
                    }
                    showToast(message, 'error');
                    return;
                }

                var contactOk = response.data.contact_api && response.data.contact_api.success;
                var eventOk   = response.data.event_api && response.data.event_api.success;

                if (contactOk && eventOk) {
                    $btn.removeClass('button-secondary').addClass('button-primary');
                    showToast('Brevo API test successful!', 'success');
                } else {
                    var message = 'Brevo API test failed:';
                    if (!contactOk) {
                        message += ' Contact API - ' + response.data.contact_api.message + '.';
                    }
                    if (!eventOk) {
                        message += ' Event API - ' + response.data.event_api.message + '.';
                    }
                    showToast(message, 'error');
                }
            }).fail(function() {
                showToast('Communication error during Brevo API test', 'error');
            }).always(function() {
                $btn.prop('disabled', false).text('Test API');
                setTimeout(function() {
                    $btn.removeClass('button-primary').addClass('button-secondary');
                }, 3000);
            });
        });
        
        // Accessibility and keyboard navigation improvements
        
        // Add ARIA labels to buttons and status indicators
        $('.hic-action-group .button').each(function() {
            var $btn = $(this);
            var text = $btn.text().trim();
            $btn.attr('aria-label', 'Azione: ' + text);
        });
        
        // Add role attributes for status indicators
        $('.status').attr('role', 'status').attr('aria-live', 'polite');
        
        // Enhanced keyboard navigation
        $(document).on('keydown', function(e) {
            // ESC to close any open details/dialogs
            if (e.key === 'Escape') {
                $('.hic-advanced-details[open]').removeAttr('open');
                $('.hic-toast').removeClass('show');
            }
            
            // Ctrl+R to refresh (prevent default and use our auto-refresh)
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                showToast('Aggiornamento automatico attivo ogni 30 secondi', 'info');
            }
        });
        
        // Add high contrast mode toggle for accessibility
        if (window.matchMedia && window.matchMedia('(prefers-contrast: high)').matches) {
            $('body').addClass('high-contrast');
        }
        
        // Add loading states for better screen reader support
        function announceToScreenReader(message) {
            var announcement = $('<div>')
                .attr('aria-live', 'assertive')
                .attr('aria-atomic', 'true')
                .addClass('sr-only')
                .text(message);
            
            $('body').append(announcement);
            setTimeout(function() { announcement.remove(); }, 1000);
        }
        
        // Enhanced error handling with better user feedback
        $(document).ajaxError(function(event, xhr, settings, thrownError) {
            if (settings.url && settings.url.includes('admin-ajax.php')) {
                var action = settings.data && settings.data.includes('action=') ? 
                    settings.data.match(/action=([^&]*)/)[1] : 'unknown';
                
                showToast('Errore durante l\'operazione ' + action + '. Riprova.', 'error');
                announceToScreenReader('Errore durante l\'operazione ' + action);
            }
        });
        
        // Add confirmation dialogs for destructive actions
        $('.button-link-delete, #reset-timestamps, #reset-timestamps-advanced').on('click', function(e) {
            var action = $(this).text().trim();
            announceToScreenReader('Azione di emergenza: ' + action + ' richiede conferma');
        });
    });
