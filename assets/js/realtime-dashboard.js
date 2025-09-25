/**
 * Real-Time Dashboard JavaScript
 * FP HIC Monitor v3.0 - Enterprise Grade
 */

(function($) {
    'use strict';

    class HICRealtimeDashboard {
        constructor() {
            this.charts = {};
            this.refreshInterval = null;
            this.isAutoRefreshEnabled = true;
            
            this.init();
        }
        
        init() {
            this.setupEventListeners();
            this.initializeCharts();
            this.loadInitialData();
            this.startAutoRefresh();
        }

        setupEventListeners() {
            // Auto-refresh toggle
            $('#hic-auto-refresh').on('change', (e) => {
                this.isAutoRefreshEnabled = e.target.checked;
                if (this.isAutoRefreshEnabled) {
                    this.startAutoRefresh();
                } else {
                    this.stopAutoRefresh();
                }
            });
            
            // Manual refresh button
            $('#hic-refresh-dashboard').on('click', () => {
                this.refreshAllData();
            });
            
            // Period selector changes
            $('#hic-dashboard-period, #hic-revenue-period').on('change', (e) => {
                const period = e.target.value;
                this.updateDataForPeriod(period);
            });
            
            // Widget-specific refresh indicators
            this.setupRefreshIndicators();
        }
        
        setupRefreshIndicators() {
            // Create refresh status indicator
            const indicator = $('#hic-refresh-indicator');
            if (indicator.length) {
                setInterval(() => {
                    if (this.isAutoRefreshEnabled) {
                        indicator.toggleClass('hic-refresh-active');
                    } else {
                        indicator.removeClass('hic-refresh-active');
                    }
                }, 1000);
            }
        }
        
        initializeCharts() {
            this.initRealtimeChart();
            this.initRevenueChart();
            this.initHeatmapChart();
            this.initConversionFunnelChart();
            this.initTimelineChart();
        }

        setEmptyState(elementId, emptyKey, isEmpty) {
            const $element = $('#' + elementId);

            if ($element.length === 0) {
                return;
            }

            const $cardBody = $element.closest('.hic-card__body');
            let $container = $element.closest('.hic-widget, .hic-chart-container, .hic-analysis-container');

            if ($cardBody.length) {
                const $card = $cardBody.closest('.hic-card');
                if ($card.length) {
                    $container = $card;
                } else {
                    $container = $cardBody;
                }
            }

            if ($container.length === 0) {
                $container = $element.parent();
            }

            $container.toggleClass('hic-empty', !!isEmpty);

            if (typeof emptyKey === 'string' && emptyKey !== '') {
                const $emptyState = $container.find(`[data-empty-for="${emptyKey}"]`);

                if ($emptyState.length) {
                    $emptyState.toggleClass('is-visible', !!isEmpty);
                }
            }
        }

        clearChartData(chart) {
            if (!chart || !chart.data) {
                return;
            }

            if (Array.isArray(chart.data.datasets)) {
                chart.data.datasets.forEach((dataset) => {
                    dataset.data = [];
                });
            }

            if (Array.isArray(chart.data.labels)) {
                chart.data.labels = [];
            }

            chart.update();
        }

        initRealtimeChart() {
            const ctx = document.getElementById('hic-realtime-chart');
            if (!ctx) return;

            this.charts.realtime = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: hicDashboard.i18n.conversions,
                        data: [],
                        borderColor: hicDashboard.colors.primary,
                        backgroundColor: hicDashboard.colors.primary + '20',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f0f0f0'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
        
        initRevenueChart() {
            const ctx = document.getElementById('hic-revenue-chart');
            if (!ctx) return;
            
            this.charts.revenue = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            hicDashboard.colors.google,
                            hicDashboard.colors.facebook,
                            hicDashboard.colors.direct,
                            hicDashboard.colors.organic,
                            hicDashboard.colors.info
                        ],
                        borderWidth: 2,
                        borderColor: '#fff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        }
                    }
                }
            });
        }
        
        initHeatmapChart() {
            const ctx = document.getElementById('hic-booking-heatmap');
            if (!ctx) return;
            
            // Custom heatmap implementation using Chart.js scatter plot
            this.charts.heatmap = new Chart(ctx, {
                type: 'scatter',
                data: {
                    datasets: [{
                        label: 'Booking Intensity',
                        data: [],
                        backgroundColor: function(context) {
                            const value = context.parsed.v || 0;
                            const maxValue = Math.max(...context.dataset.data.map(d => d.v || 0));
                            const intensity = maxValue > 0 ? value / maxValue : 0;
                            
                            // Color gradient from blue to red
                            const r = Math.round(intensity * 255);
                            const b = Math.round((1 - intensity) * 255);
                            return `rgb(${r}, 100, ${b})`;
                        }
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const point = context[0];
                                    const days = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
                                    return `${days[point.parsed.y]} ${point.parsed.x}:00`;
                                },
                                label: function(context) {
                                    return `Prenotazioni: ${context.parsed.v}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            type: 'linear',
                            position: 'bottom',
                            min: 0,
                            max: 23,
                            ticks: {
                                stepSize: 2,
                                callback: function(value) {
                                    return value + ':00';
                                }
                            },
                            title: {
                                display: true,
                                text: 'Ora del giorno'
                            }
                        },
                        y: {
                            type: 'linear',
                            min: 0,
                            max: 6,
                            ticks: {
                                stepSize: 1,
                                callback: function(value) {
                                    const days = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
                                    return days[value] || '';
                                }
                            },
                            title: {
                                display: true,
                                text: 'Giorno settimana'
                            }
                        }
                    }
                }
            });
        }
        
        initConversionFunnelChart() {
            const ctx = document.getElementById('hic-conversion-funnel');
            if (!ctx) return;
            
            this.charts.funnel = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Sessioni', 'Google Ads', 'Facebook Ads', 'Conversioni'],
                    datasets: [{
                        label: 'Count',
                        data: [],
                        backgroundColor: [
                            hicDashboard.colors.info,
                            hicDashboard.colors.google,
                            hicDashboard.colors.facebook,
                            hicDashboard.colors.success
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        initTimelineChart() {
            const ctx = document.getElementById('hic-conversions-timeline');
            if (!ctx) return;
            
            this.charts.timeline = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Conversioni',
                        data: [],
                        borderColor: hicDashboard.colors.primary,
                        backgroundColor: hicDashboard.colors.primary + '20',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: '#f0f0f0'
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        }
        
        loadInitialData() {
            this.loadRealtimeStats();
            this.loadRevenueByChannel();
            this.loadBookingHeatmap();
            this.loadConversionFunnel();
            this.loadPerformanceMetrics();
        }
        
        loadRealtimeStats() {
            $.ajax({
                url: hicDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hic_get_realtime_stats',
                    nonce: hicDashboard.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateRealtimeStats(response.data);
                    }
                },
                error: () => {
                    this.showError('Errore nel caricamento statistiche real-time');
                }
            });
        }
        
        loadRevenueByChannel() {
            const period = $('#hic-revenue-period').val() || '7days';

            $.ajax({
                url: hicDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hic_get_revenue_by_channel',
                    period: period,
                    nonce: hicDashboard.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateRevenueChart(response.data);
                    }
                },
                error: () => {
                    this.setEmptyState('hic-revenue-chart', 'channel-stats', true);
                    $('.hic-channel-stats').empty();
                    this.showError('Errore nel caricamento revenue per canale');
                }
            });
        }

        loadBookingHeatmap() {
            $.ajax({
                url: hicDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hic_get_booking_heatmap',
                    nonce: hicDashboard.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateHeatmapChart(response.data);
                    }
                },
                error: () => {
                    this.setEmptyState('hic-booking-heatmap', 'heatmap', true);
                    this.showError('Errore nel caricamento heatmap prenotazioni');
                }
            });
        }

        loadConversionFunnel() {
            $.ajax({
                url: hicDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hic_get_conversion_funnel',
                    nonce: hicDashboard.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updateConversionFunnel(response.data);
                    }
                },
                error: () => {
                    this.setEmptyState('hic-conversion-funnel', 'funnel', true);
                    this.showError('Errore nel caricamento funnel conversioni');
                }
            });
        }

        loadPerformanceMetrics() {
            $.ajax({
                url: hicDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hic_get_performance_metrics',
                    nonce: hicDashboard.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.updatePerformanceMetrics(response.data);
                    }
                },
                error: () => {
                    this.showError('Errore nel caricamento metriche performance');
                }
            });
        }
        
        updateRealtimeStats(data) {
            // Update widget stats
            $('#hic-conversions-today').text(data.today || 0);
            $('#hic-conversions-hour').text(data.last_hour || 0);
            $('#hic-conversion-rate').text((data.conversion_rate || 0) + '%');
            
            // Update full dashboard metrics
            $('#hic-total-conversions').text(data.last_24h || 0);
            $('#hic-conversion-rate-full').text((data.conversion_rate || 0) + '%');
            
            // Update timestamp
            const lastUpdate = new Date(data.last_updated * 1000).toLocaleTimeString();
            $('#hic-last-update').text(lastUpdate);
            
            // Update hourly chart
            if (this.charts.realtime && data.hourly_data) {
                const labels = [];
                const chartData = [];
                
                for (let hour = 0; hour < 24; hour++) {
                    labels.push(hour + ':00');
                    const hourData = data.hourly_data.find(h => h.hour == hour);
                    chartData.push(hourData ? hourData.conversions : 0);
                }
                
                this.charts.realtime.data.labels = labels;
                this.charts.realtime.data.datasets[0].data = chartData;
                this.charts.realtime.update('none'); // Smooth update
            }
            
            // Update timeline chart
            if (this.charts.timeline && data.hourly_data) {
                this.charts.timeline.data.labels = data.hourly_data.map(h => h.hour + ':00');
                this.charts.timeline.data.datasets[0].data = data.hourly_data.map(h => h.conversions);
                this.charts.timeline.update('none');
            }
        }
        
        updateRevenueChart(data) {
            if (!this.charts.revenue) {
                return;
            }

            const isEmpty = !Array.isArray(data) || data.length === 0;

            this.setEmptyState('hic-revenue-chart', 'channel-stats', isEmpty);

            if (isEmpty) {
                this.clearChartData(this.charts.revenue);
                $('.hic-channel-stats').empty();
                return;
            }

            const labels = data.map(item => item.channel);
            const values = data.map(item => parseFloat(item.estimated_revenue) || 0);

            this.charts.revenue.data.labels = labels;
            this.charts.revenue.data.datasets[0].data = values;
            this.charts.revenue.update();

            const statsHtml = data.map(item => `
                ${(() => {
                    const revenueValue = parseFloat(item.estimated_revenue) || 0;
                    const formattedRevenue = revenueValue.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
                    const bookings = parseInt(item.bookings, 10) || 0;

                    return `
                <div class="hic-channel-stat">
                    <span class="hic-channel-name">${item.channel}</span>
                    <span class="hic-channel-value">€${formattedRevenue}</span>
                    <span class="hic-channel-bookings">${bookings} prenotazioni</span>
                </div>`;
                })()}
            `).join('');

            $('.hic-channel-stats').html(statsHtml);
        }

        updateHeatmapChart(data) {
            if (!this.charts.heatmap) {
                return;
            }

            if (!Array.isArray(data)) {
                this.setEmptyState('hic-booking-heatmap', 'heatmap', true);
                this.clearChartData(this.charts.heatmap);
                return;
            }

            const heatmapData = data.map(item => ({
                x: parseInt(item.hour_of_day),
                y: parseInt(item.day_of_week) - 1,
                v: parseInt(item.booking_count) || 0
            }));

            const hasActivity = heatmapData.some(point => point.v > 0);

            this.setEmptyState('hic-booking-heatmap', 'heatmap', !hasActivity);

            if (!hasActivity) {
                this.clearChartData(this.charts.heatmap);
                return;
            }

            this.charts.heatmap.data.datasets[0].data = heatmapData;
            this.charts.heatmap.update();
        }

        updateConversionFunnel(data) {
            if (!this.charts.funnel) {
                return;
            }

            const funnelData = data && data['7days'] ? data['7days'] : null;

            const chartData = funnelData ? [
                parseInt(funnelData.total_sessions) || 0,
                parseInt(funnelData.google_conversions) || 0,
                parseInt(funnelData.facebook_conversions) || 0,
                parseInt(funnelData.total_conversions) || 0
            ] : [];

            const hasData = Array.isArray(chartData) && chartData.some(value => value > 0);

            this.setEmptyState('hic-conversion-funnel', 'funnel', !hasData);

            if (!hasData) {
                this.clearChartData(this.charts.funnel);
                return;
            }

            this.charts.funnel.data.datasets[0].data = chartData;
            this.charts.funnel.update();
        }
        
        updatePerformanceMetrics(data) {
            if (!data || data.length === 0) {
                $('#hic-performance-metrics').html('<div class="hic-no-data">Nessuna metrica disponibile</div>');
                return;
            }
            
            const metricsHtml = data.map(metric => `
                <div class="hic-performance-metric">
                    <h4>${metric.metric_type}</h4>
                    <div class="hic-metric-values">
                        <span class="hic-metric-avg">Media: ${parseFloat(metric.avg_value).toFixed(2)}</span>
                        <span class="hic-metric-max">Max: ${parseFloat(metric.max_value).toFixed(2)}</span>
                        <span class="hic-metric-samples">Campioni: ${metric.sample_count}</span>
                    </div>
                </div>
            `).join('');
            
            $('#hic-performance-metrics').html(metricsHtml);
        }
        
        updateDataForPeriod(period) {
            // Update revenue chart for new period
            this.loadRevenueByChannel();
            
            // Update other time-sensitive data
            this.loadConversionFunnel();
        }
        
        refreshAllData() {
            this.showRefreshIndicator();
            
            // Trigger dashboard heartbeat to refresh cache
            $.ajax({
                url: hicDashboard.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'hic_dashboard_heartbeat',
                    nonce: hicDashboard.nonce
                },
                success: () => {
                    // Reload all data after cache refresh
                    setTimeout(() => {
                        this.loadInitialData();
                        this.hideRefreshIndicator();
                    }, 1000);
                },
                error: () => {
                    this.hideRefreshIndicator();
                    this.showError('Errore durante l\'aggiornamento');
                }
            });
        }
        
        startAutoRefresh() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
            }
            
            this.refreshInterval = setInterval(() => {
                if (this.isAutoRefreshEnabled) {
                    this.loadRealtimeStats(); // Only refresh real-time stats automatically
                }
            }, hicDashboard.refreshInterval);
        }
        
        stopAutoRefresh() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        }
        
        showRefreshIndicator() {
            $('.hic-refresh-indicator').addClass('hic-refreshing');
            $('#hic-refresh-dashboard').prop('disabled', true).text('Aggiornamento...');
        }
        
        hideRefreshIndicator() {
            $('.hic-refresh-indicator').removeClass('hic-refreshing');
            $('#hic-refresh-dashboard').prop('disabled', false).text('Aggiorna Ora');
        }
        
        showError(message) {
            // Create or update error notification
            let errorDiv = $('.hic-dashboard-error');
            if (errorDiv.length === 0) {
                errorDiv = $('<div class="hic-dashboard-error notice notice-error"></div>');
                $('.hic-dashboard').prepend(errorDiv);
            }
            
            errorDiv.html(`<p>${message}</p>`).show();
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                errorDiv.fadeOut();
            }, 5000);
        }
    }

    // Initialize dashboard when DOM is ready
    $(document).ready(function() {
        if (typeof hicDashboard !== 'undefined') {
            new HICRealtimeDashboard();
        }
    });

})(jQuery);