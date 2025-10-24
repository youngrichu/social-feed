/**
 * Schedule Admin JavaScript
 */

(function($) {
    'use strict';
    
    let currentScheduleId = 0;
    let quotaChart = null;
    let performanceChart = null;
    let discoveryChart = null;
    
    $(document).ready(function() {
        initializeScheduleAdmin();
        initializeCharts();
        bindEvents();
        loadQuotaStats();
        loadAnalytics();
    });
    
    /**
     * Initialize schedule admin interface
     */
    function initializeScheduleAdmin() {
        // Initialize time pickers
        $('.time-picker').timepicker({
            timeFormat: 'h:mm p',
            interval: 15,
            minTime: '00:00',
            maxTime: '23:59',
            defaultTime: '09:00',
            startTime: '00:00',
            dynamic: false,
            dropdown: true,
            scrollbar: true
        });
        
        // Load AI suggestions for existing schedules
        loadAISuggestions();
    }
    
    /**
     * Initialize charts
     */
    function initializeCharts() {
        // Quota usage chart
        const quotaCtx = document.getElementById('quota-usage-chart');
        if (quotaCtx) {
            quotaChart = new Chart(quotaCtx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'API Calls',
                        data: [],
                        borderColor: '#0073aa',
                        backgroundColor: 'rgba(0, 115, 170, 0.1)',
                        tension: 0.4
                    }, {
                        label: 'Videos Found',
                        data: [],
                        borderColor: '#00a32a',
                        backgroundColor: 'rgba(0, 163, 42, 0.1)',
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }
        
        // Schedule performance chart
        const performanceCtx = document.getElementById('schedule-performance-chart');
        if (performanceCtx) {
            performanceChart = new Chart(performanceCtx, {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Effectiveness %',
                        data: [],
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 205, 86, 0.8)',
                            'rgba(75, 192, 192, 0.8)',
                            'rgba(153, 102, 255, 0.8)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100
                        }
                    }
                }
            });
        }
        
        // Discovery patterns chart
        const discoveryCtx = document.getElementById('discovery-patterns-chart');
        if (discoveryCtx) {
            discoveryChart = new Chart(discoveryCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                    datasets: [{
                        data: [],
                        backgroundColor: [
                            '#FF6384',
                            '#36A2EB',
                            '#FFCE56',
                            '#4BC0C0',
                            '#9966FF',
                            '#FF9F40',
                            '#FF6384'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    }
    
    /**
     * Bind event handlers
     */
    function bindEvents() {
        // Add new schedule button
        $('#add-new-schedule').on('click', function() {
            showScheduleForm();
        });
        
        // Cancel schedule form
        $('#cancel-schedule-form').on('click', function() {
            hideScheduleForm();
        });
        
        // Edit schedule buttons
        $(document).on('click', '.edit-schedule', function() {
            const scheduleId = $(this).data('schedule-id');
            editSchedule(scheduleId);
        });
        
        // Toggle schedule buttons
        $(document).on('click', '.toggle-schedule', function() {
            const scheduleId = $(this).data('schedule-id');
            const isActive = $(this).data('active') === '1';
            toggleSchedule(scheduleId, !isActive);
        });
        
        // Delete schedule buttons
        $(document).on('click', '.delete-schedule', function() {
            const scheduleId = $(this).data('schedule-id');
            if (confirm(socialFeedSchedule.strings.confirmDelete)) {
                deleteSchedule(scheduleId);
            }
        });
        
        // Add time slot button
        $('#add-time-slot').on('click', function() {
            addTimeSlot();
        });
        
        // Remove time slot buttons
        $(document).on('click', '.remove-time-slot', function() {
            $(this).closest('.time-slot-row').remove();
        });
        
        // Schedule form submission
        $('#schedule-form').on('submit', function(e) {
            e.preventDefault();
            saveSchedule();
        });
        
        // Channel selection change
        $('#channel-id').on('change', function() {
            const channelId = $(this).val();
            if (channelId) {
                loadChannelSuggestions(channelId);
            }
        });
        
        // Schedule type change
        $('#schedule-type').on('change', function() {
            const scheduleType = $(this).val();
            updateTimeSlotOptions(scheduleType);
        });
    }
    
    /**
     * Show schedule form
     */
    function showScheduleForm(scheduleData = null) {
        currentScheduleId = scheduleData ? scheduleData.id : 0;
        
        if (scheduleData) {
            $('#schedule-form-title').text('Edit Schedule');
            populateScheduleForm(scheduleData);
        } else {
            $('#schedule-form-title').text('Add New Schedule');
            resetScheduleForm();
        }
        
        $('#schedule-form-section').slideDown();
        $('html, body').animate({
            scrollTop: $('#schedule-form-section').offset().top - 50
        }, 500);
    }
    
    /**
     * Hide schedule form
     */
    function hideScheduleForm() {
        $('#schedule-form-section').slideUp();
        resetScheduleForm();
    }
    
    /**
     * Reset schedule form
     */
    function resetScheduleForm() {
        $('#schedule-form')[0].reset();
        $('#schedule-id').val('');
        $('#time-slots-container').empty();
        $('#schedule-suggestions').hide();
        currentScheduleId = 0;
        
        // Add one default time slot
        addTimeSlot();
    }
    
    /**
     * Populate schedule form with data
     */
    function populateScheduleForm(scheduleData) {
        $('#schedule-id').val(scheduleData.id);
        $('#channel-id').val(scheduleData.channel_id);
        $('#schedule-type').val(scheduleData.schedule_type);
        $('#timezone').val(scheduleData.timezone);
        $('#priority').val(scheduleData.priority);
        
        // Clear existing time slots
        $('#time-slots-container').empty();
        
        // Add time slots
        if (scheduleData.slots && scheduleData.slots.length > 0) {
            scheduleData.slots.forEach(function(slot) {
                addTimeSlot(slot.day_of_week, slot.check_time);
            });
        } else {
            addTimeSlot();
        }
        
        // Load suggestions for this schedule
        loadScheduleSuggestions(scheduleData.id);
    }
    
    /**
     * Add time slot row
     */
    function addTimeSlot(dayOfWeek = '', checkTime = '') {
        const template = $('#time-slot-template').html();
        const $slot = $(template);
        
        if (dayOfWeek) {
            $slot.find('select[name="day_of_week[]"]').val(dayOfWeek);
        }
        
        if (checkTime) {
            const timeFormatted = formatTimeForPicker(checkTime);
            $slot.find('input[name="check_time[]"]').val(timeFormatted);
        }
        
        $('#time-slots-container').append($slot);
        
        // Initialize time picker for new input
        $slot.find('.time-picker').timepicker({
            timeFormat: 'h:mm p',
            interval: 15,
            minTime: '00:00',
            maxTime: '23:59',
            defaultTime: '09:00',
            startTime: '00:00',
            dynamic: false,
            dropdown: true,
            scrollbar: true
        });
    }
    
    /**
     * Format time for time picker
     */
    function formatTimeForPicker(time24) {
        const [hours, minutes] = time24.split(':');
        const hour12 = hours % 12 || 12;
        const ampm = hours < 12 ? 'AM' : 'PM';
        return `${hour12}:${minutes} ${ampm}`;
    }
    
    /**
     * Update time slot options based on schedule type
     */
    function updateTimeSlotOptions(scheduleType) {
        const $container = $('#time-slots-container');
        
        if (scheduleType === 'daily') {
            // For daily schedules, show only time inputs
            $container.find('select[name="day_of_week[]"]').hide();
        } else {
            // For weekly/custom schedules, show day selectors
            $container.find('select[name="day_of_week[]"]').show();
        }
    }
    
    /**
     * Save schedule
     */
    function saveSchedule() {
        const formData = new FormData($('#schedule-form')[0]);
        formData.append('action', 'social_feed_save_schedule');
        formData.append('nonce', socialFeedSchedule.nonce);
        formData.append('schedule_id', currentScheduleId);
        
        showLoading('#schedule-form');
        
        $.ajax({
            url: socialFeedSchedule.ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                hideLoading('#schedule-form');
                
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    hideScheduleForm();
                    location.reload(); // Refresh to show updated schedule
                } else {
                    showNotice(response.data.message, 'error');
                }
            },
            error: function() {
                hideLoading('#schedule-form');
                showNotice(socialFeedSchedule.strings.saveError, 'error');
            }
        });
    }
    
    /**
     * Edit schedule
     */
    function editSchedule(scheduleId) {
        // Get schedule data from the table row
        const $row = $(`tr[data-schedule-id="${scheduleId}"]`);
        
        // This is a simplified version - in a real implementation,
        // you'd fetch the full schedule data via AJAX
        showScheduleForm({
            id: scheduleId,
            // Add other fields as needed
        });
    }
    
    /**
     * Toggle schedule active status
     */
    function toggleSchedule(scheduleId, active) {
        $.ajax({
            url: socialFeedSchedule.ajaxUrl,
            type: 'POST',
            data: {
                action: 'social_feed_toggle_schedule',
                nonce: socialFeedSchedule.nonce,
                schedule_id: scheduleId,
                active: active ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    showNotice(response.data.message, 'error');
                }
            }
        });
    }
    
    /**
     * Delete schedule
     */
    function deleteSchedule(scheduleId) {
        $.ajax({
            url: socialFeedSchedule.ajaxUrl,
            type: 'POST',
            data: {
                action: 'social_feed_delete_schedule',
                nonce: socialFeedSchedule.nonce,
                schedule_id: scheduleId
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    $(`tr[data-schedule-id="${scheduleId}"]`).fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    showNotice(response.data.message, 'error');
                }
            }
        });
    }
    
    /**
     * Load quota statistics
     */
    function loadQuotaStats() {
        $.ajax({
            url: socialFeedSchedule.ajaxUrl,
            type: 'POST',
            data: {
                action: 'social_feed_get_quota_stats',
                nonce: socialFeedSchedule.nonce
            },
            success: function(response) {
                if (response.success && quotaChart) {
                    updateQuotaChart(response.data);
                }
            }
        });
    }
    
    /**
     * Update quota chart
     */
    function updateQuotaChart(data) {
        if (!quotaChart || !data.usage_history) return;
        
        const labels = data.usage_history.map(item => item.date);
        const apiCalls = data.usage_history.map(item => item.api_calls);
        const videosFound = data.usage_history.map(item => item.videos_found);
        
        quotaChart.data.labels = labels;
        quotaChart.data.datasets[0].data = apiCalls;
        quotaChart.data.datasets[1].data = videosFound;
        quotaChart.update();
    }
    
    /**
     * Load analytics data
     */
    function loadAnalytics() {
        $.ajax({
            url: socialFeedSchedule.ajaxUrl,
            type: 'POST',
            data: {
                action: 'social_feed_get_analytics',
                nonce: socialFeedSchedule.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateAnalyticsCharts(response.data);
                    updateAIRecommendations(response.data.recommendations);
                }
            }
        });
    }
    
    /**
     * Update analytics charts
     */
    function updateAnalyticsCharts(data) {
        // Update performance chart
        if (performanceChart && data.schedule_performance) {
            const labels = data.schedule_performance.map(item => item.channel_id);
            const effectiveness = data.schedule_performance.map(item => item.effectiveness);
            
            performanceChart.data.labels = labels;
            performanceChart.data.datasets[0].data = effectiveness;
            performanceChart.update();
        }
        
        // Update discovery patterns chart
        if (discoveryChart && data.discovery_patterns) {
            discoveryChart.data.datasets[0].data = data.discovery_patterns;
            discoveryChart.update();
        }
    }
    
    /**
     * Update AI recommendations
     */
    function updateAIRecommendations(recommendations) {
        const $container = $('#ai-recommendations');
        $container.empty();
        
        if (!recommendations || recommendations.length === 0) {
            $container.html('<p>No recommendations available at this time.</p>');
            return;
        }
        
        recommendations.forEach(function(rec) {
            const $card = $(`
                <div class="recommendation-card priority-${rec.priority}">
                    <div class="recommendation-title">${rec.title}</div>
                    <div class="recommendation-description">${rec.description}</div>
                </div>
            `);
            $container.append($card);
        });
    }
    
    /**
     * Load AI suggestions for schedules
     */
    function loadAISuggestions() {
        // This would load general AI suggestions for the schedules page
    }
    
    /**
     * Load schedule-specific suggestions
     */
    function loadScheduleSuggestions(scheduleId) {
        $.ajax({
            url: socialFeedSchedule.ajaxUrl,
            type: 'POST',
            data: {
                action: 'social_feed_get_schedule_suggestions',
                nonce: socialFeedSchedule.nonce,
                schedule_id: scheduleId
            },
            success: function(response) {
                if (response.success && response.data.suggestions) {
                    displayScheduleSuggestions(response.data.suggestions);
                }
            }
        });
    }
    
    /**
     * Load channel-specific suggestions
     */
    function loadChannelSuggestions(channelId) {
        // This would load suggestions based on the selected channel
        // For now, we'll show the suggestions section
        $('#schedule-suggestions').slideDown();
    }
    
    /**
     * Display schedule suggestions
     */
    function displayScheduleSuggestions(suggestions) {
        const $container = $('#suggestions-content');
        $container.empty();
        
        if (!suggestions || suggestions.length === 0) {
            $container.html('<p>No suggestions available for this schedule.</p>');
            return;
        }
        
        suggestions.forEach(function(suggestion) {
            const $item = $(`
                <div class="suggestion-item ${suggestion.priority}-priority">
                    <strong>${suggestion.title}</strong><br>
                    ${suggestion.description}
                </div>
            `);
            $container.append($item);
        });
        
        $('#schedule-suggestions').slideDown();
    }
    
    /**
     * Show loading indicator
     */
    function showLoading(selector) {
        const $container = $(selector);
        $container.css('position', 'relative');
        
        const $overlay = $(`
            <div class="loading-overlay">
                <div class="loading-spinner"></div>
            </div>
        `);
        
        $container.append($overlay);
    }
    
    /**
     * Hide loading indicator
     */
    function hideLoading(selector) {
        $(selector).find('.loading-overlay').remove();
    }
    
    /**
     * Show admin notice
     */
    function showNotice(message, type = 'info') {
        const $notice = $(`
            <div class="notice notice-${type} is-dismissible">
                <p>${message}</p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text">Dismiss this notice.</span>
                </button>
            </div>
        `);
        
        $('.wrap h1').after($notice);
        
        // Auto-dismiss success notices
        if (type === 'success') {
            setTimeout(function() {
                $notice.fadeOut();
            }, 3000);
        }
        
        // Handle dismiss button
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut();
        });
    }
    
})(jQuery);