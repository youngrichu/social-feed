<?php
/**
 * Schedule Configuration Admin Interface
 * 
 * @package SocialFeed\Admin
 */

namespace SocialFeed\Admin;

use SocialFeed\Core\SmartQuotaManager;
use SocialFeed\Core\LearningEngine;

class ScheduleAdmin {
    
    private $quota_manager;
    private $learning_engine;
    
    public function __construct() {
        $this->quota_manager = new SmartQuotaManager();
        $this->learning_engine = new LearningEngine();
    }
    
    /**
     * Initialize admin hooks
     */
    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_social_feed_save_schedule', [$this, 'ajax_save_schedule']);
        add_action('wp_ajax_social_feed_delete_schedule', [$this, 'ajax_delete_schedule']);
        add_action('wp_ajax_social_feed_get_quota_stats', [$this, 'ajax_get_quota_stats']);
        add_action('wp_ajax_social_feed_get_schedule_suggestions', [$this, 'ajax_get_schedule_suggestions']);
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menus() {
        // Main schedules page
        add_submenu_page(
            'social-feed',
            __('Intelligent Schedules', 'social-feed'),
            __('Schedules', 'social-feed'),
            'manage_options',
            'social-feed-schedules',
            [$this, 'render_schedules_page']
        );
        
        // Quota management page
        add_submenu_page(
            'social-feed',
            __('Quota Management', 'social-feed'),
            __('Quota', 'social-feed'),
            'manage_options',
            'social-feed-quota',
            [$this, 'render_quota_page']
        );
        
        // Analytics page
        add_submenu_page(
            'social-feed',
            __('Analytics & Insights', 'social-feed'),
            __('Analytics', 'social-feed'),
            'manage_options',
            'social-feed-analytics',
            [$this, 'render_analytics_page']
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'social-feed') === false) {
            return;
        }
        
        wp_enqueue_script('jquery-ui-timepicker', 'https://cdnjs.cloudflare.com/ajax/libs/jquery-timepicker/1.13.18/jquery.timepicker.min.js', ['jquery'], '1.13.18', true);
        wp_enqueue_style('jquery-ui-timepicker', 'https://cdnjs.cloudflare.com/ajax/libs/jquery-timepicker/1.13.18/jquery.timepicker.min.css', [], '1.13.18');
        
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
        
        wp_enqueue_script(
            'social-feed-schedule-admin',
            SOCIAL_FEED_PLUGIN_URL . 'assets/js/schedule-admin.js',
            ['jquery', 'jquery-ui-timepicker', 'chart-js'],
            SOCIAL_FEED_VERSION,
            true
        );
        
        wp_enqueue_style(
            'social-feed-schedule-admin',
            SOCIAL_FEED_PLUGIN_URL . 'assets/css/schedule-admin.css',
            [],
            SOCIAL_FEED_VERSION
        );
        
        wp_localize_script('social-feed-schedule-admin', 'socialFeedSchedule', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('social_feed_schedule_nonce'),
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this schedule?', 'social-feed'),
                'saveSuccess' => __('Schedule saved successfully!', 'social-feed'),
                'saveError' => __('Error saving schedule. Please try again.', 'social-feed'),
                'loading' => __('Loading...', 'social-feed')
            ]
        ]);
    }
    
    /**
     * Render schedules configuration page
     */
    public function render_schedules_page() {
        global $wpdb;
        
        // Get all schedules
        $schedules = $wpdb->get_results(
            "SELECT s.*, COUNT(sl.id) as slot_count
             FROM {$wpdb->prefix}social_feed_schedules s
             LEFT JOIN {$wpdb->prefix}social_feed_schedule_slots sl ON s.id = sl.schedule_id
             WHERE s.channel_id NOT LIKE 'template_%'
             GROUP BY s.id
             ORDER BY s.created_at DESC"
        );
        
        // Get available channels (from existing platform configurations)
        $available_channels = $this->get_available_channels();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Intelligent Schedules', 'social-feed'); ?></h1>
            
            <div class="schedule-admin-container">
                <div class="schedule-list-section">
                    <div class="schedule-header">
                        <h2><?php _e('Content Checking Schedules', 'social-feed'); ?></h2>
                        <button type="button" class="button button-primary" id="add-new-schedule">
                            <?php _e('Add New Schedule', 'social-feed'); ?>
                        </button>
                    </div>
                    
                    <?php if (empty($schedules)): ?>
                        <div class="no-schedules">
                            <p><?php _e('No schedules configured yet. Create your first intelligent schedule to optimize API usage.', 'social-feed'); ?></p>
                        </div>
                    <?php else: ?>
                        <div class="schedules-table-container">
                            <table class="wp-list-table widefat fixed striped">
                                <thead>
                                    <tr>
                                        <th><?php _e('Channel', 'social-feed'); ?></th>
                                        <th><?php _e('Type', 'social-feed'); ?></th>
                                        <th><?php _e('Time Slots', 'social-feed'); ?></th>
                                        <th><?php _e('Priority', 'social-feed'); ?></th>
                                        <th><?php _e('Status', 'social-feed'); ?></th>
                                        <th><?php _e('Effectiveness', 'social-feed'); ?></th>
                                        <th><?php _e('Actions', 'social-feed'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <?php
                                        $effectiveness = get_option("social_feed_schedule_{$schedule->id}_effectiveness", 0);
                                        $slots = $wpdb->get_results($wpdb->prepare(
                                            "SELECT * FROM {$wpdb->prefix}social_feed_schedule_slots WHERE schedule_id = %d ORDER BY FIELD(day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'), check_time",
                                            $schedule->id
                                        ));
                                        ?>
                                        <tr data-schedule-id="<?php echo esc_attr($schedule->id); ?>">
                                            <td>
                                                <strong><?php echo esc_html($schedule->channel_id); ?></strong>
                                                <div class="schedule-timezone">
                                                    <?php echo esc_html($schedule->timezone); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="schedule-type-badge schedule-type-<?php echo esc_attr($schedule->schedule_type); ?>">
                                                    <?php echo esc_html(ucfirst($schedule->schedule_type)); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="time-slots">
                                                    <?php foreach ($slots as $slot): ?>
                                                        <span class="time-slot">
                                                            <?php echo esc_html(ucfirst($slot->day_of_week)); ?> 
                                                            <?php echo esc_html(date('g:i A', strtotime($slot->check_time))); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="priority-indicator priority-<?php echo esc_attr($schedule->priority); ?>">
                                                    <?php echo str_repeat('★', $schedule->priority); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $schedule->active ? 'active' : 'inactive'; ?>">
                                                    <?php echo $schedule->active ? __('Active', 'social-feed') : __('Inactive', 'social-feed'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="effectiveness-meter">
                                                    <div class="effectiveness-bar">
                                                        <div class="effectiveness-fill" style="width: <?php echo esc_attr($effectiveness); ?>%"></div>
                                                    </div>
                                                    <span class="effectiveness-text"><?php echo number_format($effectiveness, 1); ?>%</span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="schedule-actions">
                                                    <button type="button" class="button button-small edit-schedule" data-schedule-id="<?php echo esc_attr($schedule->id); ?>">
                                                        <?php _e('Edit', 'social-feed'); ?>
                                                    </button>
                                                    <button type="button" class="button button-small toggle-schedule" data-schedule-id="<?php echo esc_attr($schedule->id); ?>" data-active="<?php echo esc_attr($schedule->active); ?>">
                                                        <?php echo $schedule->active ? __('Disable', 'social-feed') : __('Enable', 'social-feed'); ?>
                                                    </button>
                                                    <button type="button" class="button button-small button-link-delete delete-schedule" data-schedule-id="<?php echo esc_attr($schedule->id); ?>">
                                                        <?php _e('Delete', 'social-feed'); ?>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="schedule-form-section" id="schedule-form-section" style="display: none;">
                    <div class="schedule-form-header">
                        <h3 id="schedule-form-title"><?php _e('Add New Schedule', 'social-feed'); ?></h3>
                        <button type="button" class="button" id="cancel-schedule-form">
                            <?php _e('Cancel', 'social-feed'); ?>
                        </button>
                    </div>
                    
                    <form id="schedule-form">
                        <input type="hidden" id="schedule-id" name="schedule_id" value="">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="channel-id"><?php _e('Channel', 'social-feed'); ?></label>
                                </th>
                                <td>
                                    <select id="channel-id" name="channel_id" required>
                                        <option value=""><?php _e('Select Channel', 'social-feed'); ?></option>
                                        <?php foreach ($available_channels as $channel): ?>
                                            <option value="<?php echo esc_attr($channel['id']); ?>">
                                                <?php echo esc_html($channel['name']); ?> (<?php echo esc_html($channel['platform']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description"><?php _e('Select the channel to create a schedule for.', 'social-feed'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="schedule-type"><?php _e('Schedule Type', 'social-feed'); ?></label>
                                </th>
                                <td>
                                    <select id="schedule-type" name="schedule_type" required>
                                        <option value="weekly"><?php _e('Weekly', 'social-feed'); ?></option>
                                        <option value="daily"><?php _e('Daily', 'social-feed'); ?></option>
                                        <option value="custom"><?php _e('Custom', 'social-feed'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="timezone"><?php _e('Timezone', 'social-feed'); ?></label>
                                </th>
                                <td>
                                    <select id="timezone" name="timezone" required>
                                        <?php echo $this->get_timezone_options(); ?>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="priority"><?php _e('Priority', 'social-feed'); ?></label>
                                </th>
                                <td>
                                    <select id="priority" name="priority">
                                        <option value="1"><?php _e('1 - Very Low', 'social-feed'); ?></option>
                                        <option value="2"><?php _e('2 - Low', 'social-feed'); ?></option>
                                        <option value="3" selected><?php _e('3 - Medium', 'social-feed'); ?></option>
                                        <option value="4"><?php _e('4 - High', 'social-feed'); ?></option>
                                        <option value="5"><?php _e('5 - Very High', 'social-feed'); ?></option>
                                    </select>
                                    <p class="description"><?php _e('Higher priority schedules get preference when quota is limited.', 'social-feed'); ?></p>
                                </td>
                            </tr>
                        </table>
                        
                        <div class="time-slots-section">
                            <h4><?php _e('Time Slots', 'social-feed'); ?></h4>
                            <div id="time-slots-container">
                                <!-- Time slots will be added dynamically -->
                            </div>
                            <button type="button" class="button" id="add-time-slot">
                                <?php _e('Add Time Slot', 'social-feed'); ?>
                            </button>
                        </div>
                        
                        <div class="schedule-suggestions" id="schedule-suggestions" style="display: none;">
                            <h4><?php _e('AI Suggestions', 'social-feed'); ?></h4>
                            <div id="suggestions-content"></div>
                        </div>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary">
                                <?php _e('Save Schedule', 'social-feed'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Time Slot Template -->
        <script type="text/template" id="time-slot-template">
            <div class="time-slot-row">
                <select name="day_of_week[]" required>
                    <option value=""><?php _e('Select Day', 'social-feed'); ?></option>
                    <option value="monday"><?php _e('Monday', 'social-feed'); ?></option>
                    <option value="tuesday"><?php _e('Tuesday', 'social-feed'); ?></option>
                    <option value="wednesday"><?php _e('Wednesday', 'social-feed'); ?></option>
                    <option value="thursday"><?php _e('Thursday', 'social-feed'); ?></option>
                    <option value="friday"><?php _e('Friday', 'social-feed'); ?></option>
                    <option value="saturday"><?php _e('Saturday', 'social-feed'); ?></option>
                    <option value="sunday"><?php _e('Sunday', 'social-feed'); ?></option>
                </select>
                <input type="text" name="check_time[]" class="time-picker" placeholder="<?php _e('Select Time', 'social-feed'); ?>" required>
                <button type="button" class="button button-small remove-time-slot"><?php _e('Remove', 'social-feed'); ?></button>
            </div>
        </script>
        <?php
    }
    
    /**
     * Get available channels from platform configurations
     */
    private function get_available_channels() {
        $channels = [];
        
        // Get YouTube channels
        $youtube_options = get_option('platforms', []);
        if (!empty($youtube_options['youtube']['enabled']) && !empty($youtube_options['youtube']['channel_id'])) {
            $channels[] = [
                'id' => $youtube_options['youtube']['channel_id'],
                'name' => $youtube_options['youtube']['channel_id'],
                'platform' => 'YouTube'
            ];
        }
        
        // Add other platforms as needed
        
        return $channels;
    }
    
    /**
     * Get timezone options HTML
     */
    private function get_timezone_options() {
        $current_timezone = get_option('timezone_string', 'UTC');
        $timezones = timezone_identifiers_list();
        
        $options = '';
        foreach ($timezones as $timezone) {
            $selected = ($timezone === $current_timezone) ? 'selected' : '';
            $options .= sprintf(
                '<option value="%s" %s>%s</option>',
                esc_attr($timezone),
                $selected,
                esc_html($timezone)
            );
        }
        
        return $options;
    }
    
    /**
     * AJAX: Save schedule
     */
    public function ajax_save_schedule() {
        check_ajax_referer('social_feed_schedule_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'social-feed'));
        }
        
        global $wpdb;
        
        $schedule_id = intval($_POST['schedule_id']);
        $channel_id = sanitize_text_field($_POST['channel_id']);
        $schedule_type = sanitize_text_field($_POST['schedule_type']);
        $timezone = sanitize_text_field($_POST['timezone']);
        $priority = intval($_POST['priority']);
        $days = array_map('sanitize_text_field', $_POST['day_of_week']);
        $times = array_map('sanitize_text_field', $_POST['check_time']);
        
        try {
            $wpdb->query('START TRANSACTION');
            
            if ($schedule_id > 0) {
                // Update existing schedule
                $wpdb->update(
                    $wpdb->prefix . 'social_feed_schedules',
                    [
                        'channel_id' => $channel_id,
                        'schedule_type' => $schedule_type,
                        'timezone' => $timezone,
                        'priority' => $priority,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $schedule_id]
                );
                
                // Delete existing slots
                $wpdb->delete(
                    $wpdb->prefix . 'social_feed_schedule_slots',
                    ['schedule_id' => $schedule_id]
                );
            } else {
                // Create new schedule
                $wpdb->insert(
                    $wpdb->prefix . 'social_feed_schedules',
                    [
                        'channel_id' => $channel_id,
                        'schedule_type' => $schedule_type,
                        'timezone' => $timezone,
                        'priority' => $priority,
                        'active' => 1
                    ]
                );
                $schedule_id = $wpdb->insert_id;
            }
            
            // Insert time slots
            for ($i = 0; $i < count($days); $i++) {
                if (!empty($days[$i]) && !empty($times[$i])) {
                    $wpdb->insert(
                        $wpdb->prefix . 'social_feed_schedule_slots',
                        [
                            'schedule_id' => $schedule_id,
                            'day_of_week' => $days[$i],
                            'check_time' => date('H:i:s', strtotime($times[$i]))
                        ]
                    );
                }
            }
            
            $wpdb->query('COMMIT');
            
            wp_send_json_success([
                'message' => __('Schedule saved successfully!', 'social-feed'),
                'schedule_id' => $schedule_id
            ]);
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error([
                'message' => __('Error saving schedule: ', 'social-feed') . $e->getMessage()
            ]);
        }
    }
    
    /**
     * AJAX: Delete schedule
     */
    public function ajax_delete_schedule() {
        check_ajax_referer('social_feed_schedule_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'social-feed'));
        }
        
        global $wpdb;
        
        $schedule_id = intval($_POST['schedule_id']);
        
        $deleted = $wpdb->delete(
            $wpdb->prefix . 'social_feed_schedules',
            ['id' => $schedule_id]
        );
        
        if ($deleted) {
            wp_send_json_success(['message' => __('Schedule deleted successfully!', 'social-feed')]);
        } else {
            wp_send_json_error(['message' => __('Error deleting schedule.', 'social-feed')]);
        }
    }
    
    /**
     * AJAX: Get quota statistics
     */
    public function ajax_get_quota_stats() {
        check_ajax_referer('social_feed_schedule_nonce', 'nonce');
        
        $stats = $this->quota_manager->get_quota_stats();
        wp_send_json_success($stats);
    }
    
    /**
     * AJAX: Get schedule suggestions
     */
    public function ajax_get_schedule_suggestions() {
        check_ajax_referer('social_feed_schedule_nonce', 'nonce');
        
        $schedule_id = intval($_POST['schedule_id']);
        $suggestions = $this->learning_engine->generate_schedule_suggestion($schedule_id);
        
        wp_send_json_success($suggestions);
    }
    
    /**
     * Render quota management page
     */
    public function render_quota_page() {
        $stats = $this->quota_manager->get_quota_stats();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Quota Management', 'social-feed'); ?></h1>
            
            <div class="quota-dashboard">
                <div class="quota-stats-grid">
                    <div class="quota-stat-card">
                        <h3><?php _e('Daily Usage', 'social-feed'); ?></h3>
                        <div class="quota-meter">
                            <div class="quota-meter-bar">
                                <div class="quota-meter-fill" style="width: <?php echo esc_attr($stats['utilization_percent']); ?>%"></div>
                            </div>
                            <div class="quota-meter-text">
                                <?php echo esc_html($stats['used_today']); ?> / <?php echo esc_html($stats['daily_limit']); ?>
                                (<?php echo number_format($stats['utilization_percent'], 1); ?>%)
                            </div>
                        </div>
                    </div>
                    
                    <div class="quota-stat-card">
                        <h3><?php _e('Remaining Quota', 'social-feed'); ?></h3>
                        <div class="quota-number">
                            <?php echo esc_html($stats['remaining']); ?>
                        </div>
                    </div>
                    
                    <div class="quota-stat-card">
                        <h3><?php _e('Efficiency Score', 'social-feed'); ?></h3>
                        <div class="quota-number">
                            <?php echo number_format($stats['efficiency_score'], 1); ?>%
                        </div>
                    </div>
                    
                    <div class="quota-stat-card">
                        <h3><?php _e('Videos Found Today', 'social-feed'); ?></h3>
                        <div class="quota-number">
                            <?php echo esc_html($stats['videos_found_today']); ?>
                        </div>
                    </div>
                </div>
                
                <div class="quota-chart-section">
                    <h3><?php _e('Usage Trends', 'social-feed'); ?></h3>
                    <canvas id="quota-usage-chart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render analytics page
     */
    public function render_analytics_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Analytics & Insights', 'social-feed'); ?></h1>
            
            <div class="analytics-dashboard">
                <div class="analytics-section">
                    <h3><?php _e('Schedule Performance', 'social-feed'); ?></h3>
                    <canvas id="schedule-performance-chart" width="400" height="200"></canvas>
                </div>
                
                <div class="analytics-section">
                    <h3><?php _e('Content Discovery Patterns', 'social-feed'); ?></h3>
                    <canvas id="discovery-patterns-chart" width="400" height="200"></canvas>
                </div>
                
                <div class="analytics-section">
                    <h3><?php _e('AI Recommendations', 'social-feed'); ?></h3>
                    <div id="ai-recommendations"></div>
                </div>
            </div>
        </div>
        <?php
    }
}