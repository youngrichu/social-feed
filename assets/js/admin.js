(function($) {
    'use strict';

    // Initialize admin functionality
    $(document).ready(function() {
        initializeActions();
        initializeSettings();
        initializeQuotaManagement();
    });

    /**
     * Initialize dashboard actions
     */
    function initializeActions() {
        // Clear cache
        $('#clear-cache').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            
            if ($button.prop('disabled')) {
                return;
            }

            if (!confirm('Are you sure you want to clear the cache?')) {
                return;
            }

            $button.prop('disabled', true)
                .text('Clearing...');

            $.ajax({
                url: socialFeedAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'social_feed_clear_cache',
                    nonce: socialFeedAdmin.nonce
                },
                success: function(response) {
                    console.log('Clear cache response:', response);
                    if (response.success) {
                        alert('Cache cleared successfully!');
                        window.location.reload();
                    } else {
                        alert(response.data?.message || 'Error clearing cache');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Clear cache error:', error);
                    alert('Error clearing cache: ' + error);
                },
                complete: function() {
                    $button.prop('disabled', false)
                        .text('Clear Cache');
                }
            });
        });

        // Refresh feeds
        $('#refresh-feeds').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            
            if ($button.prop('disabled')) {
                return;
            }

            $button.prop('disabled', true)
                .text('Refreshing...');

            $.ajax({
                url: socialFeedAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'social_feed_refresh',
                    nonce: socialFeedAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.data?.message || 'Error refreshing feeds');
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error refreshing feeds: ' + error);
                },
                complete: function() {
                    $button.prop('disabled', false)
                        .text('Refresh Feeds');
                }
            });
        });
    }

    /**
     * Initialize quota management functionality
     */
    function initializeQuotaManagement() {
        $('.reset-quota').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const $section = $button.closest('.quota-management-section');
            
            if ($button.prop('disabled')) {
                return;
            }

            if (!confirm('Are you sure you want to reset the quota counter? This should only be done if you believe the counter is incorrect.')) {
                return;
            }

            $button.prop('disabled', true);
            $section.addClass('loading');

            $.ajax({
                url: socialFeedAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'social_feed_reset_quota',
                    nonce: $button.data('nonce')
                },
                success: function(response) {
                    if (response.success) {
                        updateQuotaDisplay(response.data.new_stats);
                        alert('Quota counter reset successfully!');
                    } else {
                        alert(response.data?.message || 'Error resetting quota counter');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Reset quota error:', error);
                    alert('Error resetting quota counter: ' + error);
                },
                complete: function() {
                    $button.prop('disabled', false);
                    $section.removeClass('loading');
                }
            });
        });
    }

    /**
     * Update quota display with new statistics
     */
    function updateQuotaDisplay(stats) {
        const $section = $('.quota-management-section');
        
        // Update status class
        $section.removeClass('quota-normal quota-moderate quota-high quota-critical')
            .addClass('quota-' + stats.status);
        
        // Update status text
        $('.quota-status .status-value').text(stats.status.charAt(0).toUpperCase() + stats.status.slice(1));
        
        // Update usage bar
        $('.usage-fill').css('width', Math.min(100, stats.percentage) + '%');
        
        // Update usage stats
        $('.usage-stats .current').text(stats.current_usage.toLocaleString());
        $('.usage-stats .limit').text(stats.limit.toLocaleString());
        $('.usage-stats .percentage').text('(' + stats.percentage.toFixed(1) + '%)');
        
        // Update operations table
        const $tbody = $('.quota-operations-table tbody');
        $tbody.empty();
        
        Object.entries(stats.operations).forEach(([operation, count]) => {
            const cost = window.quotaCosts?.[operation] || 1;
            const totalCost = count * cost;
            
            $tbody.append(`
                <tr>
                    <td>${operation}</td>
                    <td>${cost}</td>
                    <td>${count.toLocaleString()}</td>
                    <td>${totalCost.toLocaleString()}</td>
                </tr>
            `);
        });
    }

    /**
     * Initialize settings page functionality
     */
    function initializeSettings() {
        // Initialize platform fields visibility
        $('.social-feed-platform-fields input[type="checkbox"]').each(function() {
            const $checkbox = $(this);
            const $fields = $checkbox.closest('.social-feed-platform-fields').find('.platform-fields');
            
            // Set initial state
            if ($checkbox.is(':checked')) {
                $fields.show();
            } else {
                $fields.hide();
            }
            
            // Handle changes
            $checkbox.on('change', function() {
                if ($(this).is(':checked')) {
                    $fields.slideDown(200);
                } else {
                    $fields.slideUp(200);
                }
            });
        });

        // Handle form submission
        $('form').on('submit', function() {
            const $form = $(this);
            const $submit = $form.find(':submit');
            
            $submit.prop('disabled', true);

            // Re-enable submit button after 2 seconds
            setTimeout(function() {
                $submit.prop('disabled', false);
            }, 2000);
        });

        // Test platform connection
        $('.test-connection').on('click', function(e) {
            e.preventDefault();
            const $button = $(this);
            const platform = $button.data('platform');
            
            if ($button.prop('disabled')) {
                return;
            }

            $button.prop('disabled', true)
                .text('Testing...');

            // Get current platform settings
            const settings = {};
            $(`[name^="social_feed_platforms[${platform}]"]`).each(function() {
                const $field = $(this);
                const key = $field.attr('name').match(/\[([^\]]+)\]$/)[1];
                settings[key] = $field.val();
            });

            $.ajax({
                url: socialFeedAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'social_feed_test_connection',
                    nonce: socialFeedAdmin.nonce,
                    platform: platform,
                    settings: settings
                },
                success: function(response) {
                    if (response.success) {
                        alert('Connection successful!');
                    } else {
                        alert(response.data.message || 'Connection failed');
                    }
                },
                error: function() {
                    alert('Connection test failed');
                },
                complete: function() {
                    $button.prop('disabled', false)
                        .text('Test Connection');
                }
            });
        });
    }
})(jQuery); 