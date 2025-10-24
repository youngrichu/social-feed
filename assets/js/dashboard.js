jQuery(document).ready(function($) {
    let quotaTrendsChart, schedulePerformanceChart;
    let refreshInterval;

    // Initialize dashboard
    initializeDashboard();

    /**
     * Initialize the dashboard
     */
    function initializeDashboard() {
        loadDashboardData();
        initializeCharts();
        startRealTimeUpdates();
        bindEventHandlers();
    }

    /**
     * Load main dashboard data
     */
    function loadDashboardData() {
        $.ajax({
            url: dashboardAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_dashboard_data',
                nonce: dashboardAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateDashboardUI(response.data);
                }
            },
            error: function() {
                console.error('Failed to load dashboard data');
            }
        });
    }

    /**
     * Update dashboard UI with new data
     */
    function updateDashboardUI(data) {
        // Update quota usage
        updateQuotaUsage(data.quota_stats);
        
        // Update efficiency score
        $('#efficiency-score').text(data.efficiency_score + '%');
        
        // Update active schedules count
        $('#active-schedules').text(data.active_schedules);
        
        // Update system health
        updateSystemHealth(data.health_status);
        
        // Update activity feed
        updateActivityFeed(data.recent_activity);
        
        // Update schedule status table
        updateScheduleTable(data.schedule_status);
        
        // Update recommendations
        updateRecommendations(data.recommendations);
    }

    /**
     * Update quota usage display
     */
    function updateQuotaUsage(quotaStats) {
        const usagePercentage = quotaStats.usage_percentage || 0;
        const used = quotaStats.used || 0;
        const limit = quotaStats.limit || 10000;
        
        $('#quota-progress').css('width', usagePercentage + '%');
        $('#quota-used').text(used.toLocaleString());
        $('#quota-limit').text(limit.toLocaleString());
        
        // Update progress bar color based on usage
        const progressBar = $('#quota-progress');
        progressBar.removeClass('high-usage medium-usage low-usage');
        
        if (usagePercentage > 80) {
            progressBar.addClass('high-usage');
        } else if (usagePercentage > 60) {
            progressBar.addClass('medium-usage');
        } else {
            progressBar.addClass('low-usage');
        }
    }

    /**
     * Update system health status
     */
    function updateSystemHealth(healthStatus) {
        const statusIndicator = $('.status-indicator');
        const statusText = $('.status-text');
        
        statusIndicator.removeClass('warning error');
        
        switch (healthStatus.status) {
            case 'healthy':
                statusIndicator.removeClass('warning error');
                break;
            case 'warning':
                statusIndicator.addClass('warning');
                break;
            case 'error':
                statusIndicator.addClass('error');
                break;
        }
        
        statusText.text(healthStatus.message);
    }

    /**
     * Update activity feed
     */
    function updateActivityFeed(activities) {
        const feedContainer = $('#activity-feed');
        feedContainer.empty();
        
        if (activities.length === 0) {
            feedContainer.append('<div class="activity-item"><span class="activity-message">No recent activity</span></div>');
            return;
        }
        
        activities.forEach(function(activity) {
            const activityItem = $(`
                <div class="activity-item ${activity.type}">
                    <span class="activity-time">${activity.time}</span>
                    <span class="activity-message">${activity.message}</span>
                </div>
            `);
            feedContainer.append(activityItem);
        });
    }

    /**
     * Update schedule status table
     */
    function updateScheduleTable(schedules) {
        const tableBody = $('#schedules-table-body');
        tableBody.empty();
        
        if (schedules.length === 0) {
            tableBody.append('<tr><td colspan="7">No schedules configured</td></tr>');
            return;
        }
        
        schedules.forEach(function(schedule) {
            const statusBadge = `<span class="schedule-status-badge ${schedule.status}">${schedule.status}</span>`;
            const effectivenessMeter = createEffectivenessMeter(schedule.effectiveness);
            const actionButtons = createActionButtons(schedule.actions);
            
            const row = $(`
                <tr>
                    <td>${schedule.channel}</td>
                    <td>${schedule.platform}</td>
                    <td>${statusBadge}</td>
                    <td>${schedule.next_check}</td>
                    <td>${effectivenessMeter}</td>
                    <td>${schedule.last_content}</td>
                    <td>${actionButtons}</td>
                </tr>
            `);
            tableBody.append(row);
        });
    }

    /**
     * Create effectiveness meter HTML
     */
    function createEffectivenessMeter(effectiveness) {
        if (effectiveness === 'N/A') {
            return 'N/A';
        }
        
        const percentage = parseFloat(effectiveness.replace('%', ''));
        return `
            <div class="effectiveness-meter">
                <div class="effectiveness-fill" style="width: ${percentage}%"></div>
            </div>
            <small>${effectiveness}</small>
        `;
    }

    /**
     * Create action buttons HTML
     */
    function createActionButtons(scheduleId) {
        return `
            <div class="action-buttons">
                <a href="#" class="action-btn edit" data-schedule-id="${scheduleId}">Edit</a>
                <a href="#" class="action-btn pause" data-schedule-id="${scheduleId}">Pause</a>
                <a href="#" class="action-btn delete" data-schedule-id="${scheduleId}">Delete</a>
            </div>
        `;
    }

    /**
     * Update recommendations
     */
    function updateRecommendations(recommendations) {
        const recommendationsContainer = $('#recommendations-list');
        recommendationsContainer.empty();
        
        if (recommendations.length === 0) {
            recommendationsContainer.append(`
                <div class="recommendation">
                    <span class="recommendation-icon">✅</span>
                    <span class="recommendation-text">System is running optimally. No recommendations at this time.</span>
                </div>
            `);
            return;
        }
        
        recommendations.forEach(function(rec) {
            const priorityClass = rec.priority ? rec.priority + '-priority' : '';
            const icon = getPriorityIcon(rec.priority);
            
            const recommendation = $(`
                <div class="recommendation ${priorityClass}">
                    <span class="recommendation-icon">${icon}</span>
                    <span class="recommendation-text">${rec.message}</span>
                </div>
            `);
            recommendationsContainer.append(recommendation);
        });
    }

    /**
     * Get priority icon
     */
    function getPriorityIcon(priority) {
        switch (priority) {
            case 'high': return '🔴';
            case 'medium': return '🟡';
            case 'low': return '🟢';
            default: return '🤖';
        }
    }

    /**
     * Initialize charts
     */
    function initializeCharts() {
        initializeQuotaTrendsChart();
        initializeSchedulePerformanceChart();
    }

    /**
     * Initialize quota trends chart
     */
    function initializeQuotaTrendsChart() {
        $.ajax({
            url: dashboardAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_quota_trends',
                nonce: dashboardAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    const ctx = document.getElementById('quotaTrendsChart').getContext('2d');
                    quotaTrendsChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: response.data.labels,
                            datasets: [{
                                label: 'Quota Usage',
                                data: response.data.quota_data,
                                borderColor: '#2196F3',
                                backgroundColor: 'rgba(33, 150, 243, 0.1)',
                                tension: 0.4,
                                fill: true
                            }, {
                                label: 'API Calls',
                                data: response.data.calls_data,
                                borderColor: '#4CAF50',
                                backgroundColor: 'rgba(76, 175, 80, 0.1)',
                                tension: 0.4,
                                fill: true,
                                yAxisID: 'y1'
                            }]
                        },
                        options: {
                            responsive: true,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Quota Usage'
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'API Calls'
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'top',
                                }
                            }
                        }
                    });
                }
            }
        });
    }

    /**
     * Initialize schedule performance chart
     */
    function initializeSchedulePerformanceChart() {
        $.ajax({
            url: dashboardAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_schedule_performance',
                nonce: dashboardAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    const ctx = document.getElementById('schedulePerformanceChart').getContext('2d');
                    schedulePerformanceChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: response.data.labels,
                            datasets: [{
                                label: 'Effectiveness Score',
                                data: response.data.effectiveness_data,
                                backgroundColor: 'rgba(76, 175, 80, 0.8)',
                                borderColor: '#4CAF50',
                                borderWidth: 1
                            }, {
                                label: 'Success Rate (%)',
                                data: response.data.success_rate_data,
                                backgroundColor: 'rgba(33, 150, 243, 0.8)',
                                borderColor: '#2196F3',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 100,
                                    title: {
                                        display: true,
                                        text: 'Percentage'
                                    }
                                }
                            },
                            plugins: {
                                legend: {
                                    position: 'top',
                                }
                            }
                        }
                    });
                }
            }
        });
    }

    /**
     * Start real-time updates
     */
    function startRealTimeUpdates() {
        // Update dashboard data every 30 seconds
        refreshInterval = setInterval(function() {
            loadDashboardData();
        }, 30000);
        
        // Add real-time indicator
        if ($('.real-time-indicator').length === 0) {
            $('h1').append(`
                <span class="real-time-indicator">
                    <span class="real-time-dot"></span>
                    Live
                </span>
            `);
        }
    }

    /**
     * Bind event handlers
     */
    function bindEventHandlers() {
        // Handle action buttons
        $(document).on('click', '.action-btn', function(e) {
            e.preventDefault();
            const action = $(this).hasClass('edit') ? 'edit' : 
                          $(this).hasClass('pause') ? 'pause' : 'delete';
            const scheduleId = $(this).data('schedule-id');
            
            handleScheduleAction(action, scheduleId);
        });
        
        // Handle manual refresh
        $(document).on('click', '.refresh-dashboard', function(e) {
            e.preventDefault();
            loadDashboardData();
        });
        
        // Handle system health check
        $(document).on('click', '.health-status', function() {
            checkSystemHealth();
        });
    }

    /**
     * Handle schedule actions
     */
    function handleScheduleAction(action, scheduleId) {
        switch (action) {
            case 'edit':
                window.location.href = `admin.php?page=social-feed-schedules&action=edit&id=${scheduleId}`;
                break;
            case 'pause':
                if (confirm('Are you sure you want to pause this schedule?')) {
                    toggleSchedule(scheduleId, 'paused');
                }
                break;
            case 'delete':
                if (confirm('Are you sure you want to delete this schedule? This action cannot be undone.')) {
                    deleteSchedule(scheduleId);
                }
                break;
        }
    }

    /**
     * Toggle schedule status
     */
    function toggleSchedule(scheduleId, status) {
        $.ajax({
            url: dashboardAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'toggle_schedule',
                schedule_id: scheduleId,
                status: status,
                nonce: dashboardAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    loadDashboardData(); // Refresh data
                } else {
                    alert('Failed to update schedule status');
                }
            }
        });
    }

    /**
     * Delete schedule
     */
    function deleteSchedule(scheduleId) {
        $.ajax({
            url: dashboardAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'delete_schedule',
                schedule_id: scheduleId,
                nonce: dashboardAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    loadDashboardData(); // Refresh data
                } else {
                    alert('Failed to delete schedule');
                }
            }
        });
    }

    /**
     * Check system health manually
     */
    function checkSystemHealth() {
        $.ajax({
            url: dashboardAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_system_health',
                nonce: dashboardAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateSystemHealth(response.data);
                }
            }
        });
    }

    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
    });
});