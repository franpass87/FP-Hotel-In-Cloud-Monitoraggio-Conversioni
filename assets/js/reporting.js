/**
 * Reporting JavaScript
 * FP HIC Monitor v3.0 - Enterprise Grade
 */

(function($) {
    'use strict';

    class HICReporting {
        constructor() {
            this.init();
        }
        
        init() {
            this.setupEventListeners();
            this.loadReportHistory();
        }
        
        setupEventListeners() {
            // Manual report form submission
            $('#hic-manual-report-form').on('submit', (e) => {
                e.preventDefault();
                this.generateManualReport();
            });
            
            // Quick export buttons
            window.hicExportCSV = (period) => this.exportCSV(period);
            window.hicExportExcel = (period) => this.exportExcel(period);
        }
        
        generateManualReport() {
            const formData = new FormData(document.getElementById('hic-manual-report-form'));
            formData.append('action', 'hic_generate_manual_report');
            formData.append('nonce', hicReporting.hic_reporting_nonce);
            
            const submitButton = $('#hic-manual-report-form button[type="submit"]');
            const originalText = submitButton.text();
            
            submitButton.text('Generating...').prop('disabled', true);
            
            $.ajax({
                url: hicReporting.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    if (response.success) {
                        this.showSuccess('Report generated successfully: ' + response.data.files.join(', '));
                        this.loadReportHistory();
                    } else {
                        this.showError('Failed to generate report: ' + response.data);
                    }
                },
                error: () => {
                    this.showError('Network error while generating report');
                },
                complete: () => {
                    submitButton.text(originalText).prop('disabled', false);
                }
            });
        }
        
        exportCSV(period) {
            this.exportData(period, 'csv');
        }
        
        exportExcel(period) {
            this.exportData(period, 'excel');
        }
        
        exportData(period, format) {
            const action = format === 'excel' ? 'hic_export_data_excel' : 'hic_export_data_csv';
            
            $.ajax({
                url: hicReporting.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    period: period,
                    nonce: hicReporting.hic_reporting_nonce
                },
                success: (response) => {
                    if (response.success) {
                        // Trigger download
                        const link = document.createElement('a');
                        link.href = response.data.download_url;
                        link.download = response.data.filename;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        
                        this.showSuccess('Export generated: ' + response.data.filename);
                    } else {
                        this.showError('Export failed: ' + response.data);
                    }
                },
                error: () => {
                    this.showError('Network error during export');
                }
            });
        }
        
        loadReportHistory() {
            $.ajax({
                url: hicReporting.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hic_get_report_history',
                    nonce: hicReporting.hic_reporting_nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.renderReportHistory(response.data);
                    } else {
                        $('#hic-report-history').html('<p>Failed to load report history</p>');
                    }
                },
                error: () => {
                    $('#hic-report-history').html('<p>Network error loading report history</p>');
                }
            });
        }
        
        renderReportHistory(reports) {
            if (!reports || reports.length === 0) {
                $('#hic-report-history').html('<p>No reports generated yet</p>');
                return;
            }
            
            let html = '<table class="wp-list-table widefat fixed striped">';
            html += '<thead><tr><th>Type</th><th>Period</th><th>Generated</th><th>Status</th><th>Actions</th></tr></thead>';
            html += '<tbody>';
            
            reports.forEach(report => {
                html += '<tr>';
                html += `<td>${report.report_type}</td>`;
                html += `<td>${report.report_period}</td>`;
                html += `<td>${new Date(report.generated_at).toLocaleString()}</td>`;
                html += `<td><span class="status-${report.status}">${report.status}</span></td>`;
                html += '<td>';
                
                if (report.file_path && report.status === 'completed') {
                    html += `<a href="#" onclick="downloadReport('${report.file_path}')" class="button button-small">Download</a>`;
                }
                
                html += '</td>';
                html += '</tr>';
            });
            
            html += '</tbody></table>';
            
            $('#hic-report-history').html(html);
        }
        
        showSuccess(message) {
            this.showNotice(message, 'success');
        }
        
        showError(message) {
            this.showNotice(message, 'error');
        }
        
        showNotice(message, type) {
            const noticeHtml = `
                <div class="notice notice-${type} is-dismissible">
                    <p>${message}</p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                </div>
            `;
            
            $('.wrap h1').after(noticeHtml);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                $('.notice').fadeOut();
            }, 5000);
            
            // Handle dismiss click
            $('.notice-dismiss').on('click', function() {
                $(this).parent().fadeOut();
            });
        }
    }

    // Initialize when document is ready
    $(document).ready(function() {
        if (typeof hicReporting !== 'undefined') {
            new HICReporting();
        }
    });

})(jQuery);