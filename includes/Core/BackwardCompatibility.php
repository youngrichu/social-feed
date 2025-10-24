<?php

namespace SocialFeed\Core;

/**
 * Backward Compatibility Manager
 * 
 * Ensures smooth upgrades and maintains compatibility with existing installations
 */
class BackwardCompatibility {
    
    private $current_version;
    private $previous_version;
    
    public function __construct() {
        $this->current_version = SOCIAL_FEED_VERSION;
        $this->previous_version = get_option('social_feed_version', '1.0');
    }
    
    /**
     * Initialize backward compatibility checks
     */
    public function init() {
        // Run compatibility checks on admin init
        add_action('admin_init', [$this, 'check_version_upgrade']);
        
        // Handle database migrations
        add_action('social_feed_upgrade_database', [$this, 'upgrade_database']);
        
        // Maintain old option names for compatibility
        add_filter('option_social_feed_settings', [$this, 'migrate_old_settings']);
    }
    
    /**
     * Check if version upgrade is needed
     */
    public function check_version_upgrade() {
        if (version_compare($this->previous_version, $this->current_version, '<')) {
            $this->perform_upgrade();
        }
    }
    
    /**
     * Perform version upgrade
     */
    private function perform_upgrade() {
        // Upgrade from 1.0 to 1.1
        if (version_compare($this->previous_version, '1.1', '<')) {
            $this->upgrade_to_1_1();
        }
        
        // Upgrade from 1.1 to 1.2
        if (version_compare($this->previous_version, '1.2', '<')) {
            $this->upgrade_to_1_2();
        }
        
        // Update version in database
        update_option('social_feed_version', $this->current_version);
        
        // Clear any caches
        $this->clear_upgrade_caches();
    }
    
    /**
     * Upgrade to version 1.1
     */
    private function upgrade_to_1_1() {
        // Migrate old notification settings
        $old_notifications = get_option('social_feed_notifications', []);
        if (!empty($old_notifications)) {
            update_option('social_feed_notification_settings', $old_notifications);
        }
        
        // Migrate old cache settings
        $old_cache_duration = get_option('social_feed_cache_duration', 300);
        update_option('social_feed_cache_settings', [
            'duration' => $old_cache_duration,
            'enabled' => true
        ]);
    }
    
    /**
     * Upgrade to version 1.2 (Intelligent Scheduling)
     */
    private function upgrade_to_1_2() {
        // Create new database tables for intelligent scheduling
        $database_schema = new DatabaseSchema();
        $database_schema->create_tables();
        
        // Migrate existing YouTube channels to intelligent scheduling
        $this->migrate_youtube_channels_to_scheduling();
        
        // Set default intelligent scheduling settings
        $this->set_default_intelligent_settings();
        
        // Initialize learning engine with historical data
        $this->initialize_learning_engine();
    }
    
    /**
     * Migrate existing YouTube channels to intelligent scheduling
     */
    private function migrate_youtube_channels_to_scheduling() {
        global $wpdb;
        
        // Get existing YouTube channel configurations
        $youtube_channels = get_option('social_feed_youtube_channels', []);
        
        if (empty($youtube_channels)) {
            return;
        }
        
        foreach ($youtube_channels as $channel_id => $config) {
            // Create default schedule for existing channels
            $default_schedule = $this->create_default_schedule($channel_id, $config);
            
            $wpdb->insert(
                $wpdb->prefix . 'social_feed_schedules',
                [
                    'channel_id' => $channel_id,
                    'platform' => 'youtube',
                    'time_slots' => json_encode($default_schedule['time_slots']),
                    'timezone' => $default_schedule['timezone'],
                    'priority' => $default_schedule['priority'],
                    'status' => 'active',
                    'created_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );
        }
        
        // Backup old settings
        update_option('social_feed_youtube_channels_backup_v1_1', $youtube_channels);
    }
    
    /**
     * Create default schedule for migrated channels
     */
    private function create_default_schedule($channel_id, $config) {
        // Analyze historical check patterns if available
        $historical_checks = $this->get_historical_check_patterns($channel_id);
        
        if (!empty($historical_checks)) {
            return $this->create_schedule_from_history($historical_checks);
        }
        
        // Create conservative default schedule
        return [
            'time_slots' => [
                ['time' => '09:00', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']],
                ['time' => '15:00', 'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday']],
                ['time' => '12:00', 'days' => ['saturday', 'sunday']]
            ],
            'timezone' => get_option('timezone_string', 'UTC'),
            'priority' => isset($config['priority']) ? $config['priority'] : 2
        ];
    }
    
    /**
     * Get historical check patterns for a channel
     */
    private function get_historical_check_patterns($channel_id) {
        // Look for WordPress cron logs or transients that might indicate check patterns
        $last_checks = [];
        
        // Check for existing transients
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $transient_key = "social_feed_last_check_{$channel_id}_{$date}";
            $check_time = get_transient($transient_key);
            
            if ($check_time) {
                $last_checks[] = [
                    'date' => $date,
                    'time' => date('H:i', $check_time)
                ];
            }
        }
        
        return $last_checks;
    }
    
    /**
     * Create schedule from historical patterns
     */
    private function create_schedule_from_history($historical_checks) {
        $time_patterns = [];
        
        foreach ($historical_checks as $check) {
            $hour = date('H', strtotime($check['time']));
            $time_patterns[$hour] = ($time_patterns[$hour] ?? 0) + 1;
        }
        
        // Find most common check times
        arsort($time_patterns);
        $common_times = array_slice(array_keys($time_patterns), 0, 3);
        
        $time_slots = [];
        foreach ($common_times as $hour) {
            $time_slots[] = [
                'time' => sprintf('%02d:00', $hour),
                'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
            ];
        }
        
        return [
            'time_slots' => $time_slots,
            'timezone' => get_option('timezone_string', 'UTC'),
            'priority' => 2
        ];
    }
    
    /**
     * Set default intelligent scheduling settings
     */
    private function set_default_intelligent_settings() {
        $default_settings = [
            'intelligent_scheduling_enabled' => true,
            'learning_enabled' => true,
            'fallback_enabled' => true,
            'quota_optimization' => true,
            'min_check_interval' => 30, // minutes
            'max_daily_checks' => 100,
            'effectiveness_threshold' => 0.3
        ];
        
        update_option('social_feed_intelligent_settings', $default_settings);
    }
    
    /**
     * Initialize learning engine with historical data
     */
    private function initialize_learning_engine() {
        try {
            $learning_engine = new LearningEngine();
            
            // Import any available historical data
            $this->import_historical_analytics();
            
            // Set initial learning parameters
            $learning_engine->initialize_learning_parameters();
            
        } catch (\Exception $e) {
            error_log('Social Feed: Failed to initialize learning engine during upgrade: ' . $e->getMessage());
        }
    }
    
    /**
     * Import historical analytics data
     */
    private function import_historical_analytics() {
        global $wpdb;
        
        // Look for any existing analytics or log data
        $existing_logs = get_option('social_feed_check_logs', []);
        
        if (empty($existing_logs)) {
            return;
        }
        
        foreach ($existing_logs as $log) {
            if (isset($log['channel_id'], $log['platform'], $log['timestamp'])) {
                $wpdb->insert(
                    $wpdb->prefix . 'social_feed_analytics',
                    [
                        'schedule_id' => null,
                        'channel_id' => $log['channel_id'],
                        'platform' => $log['platform'],
                        'check_type' => 'migrated',
                        'content_found' => $log['content_found'] ?? 0,
                        'effectiveness_score' => $log['effectiveness_score'] ?? 50,
                        'quota_used' => 1,
                        'created_at' => date('Y-m-d H:i:s', $log['timestamp'])
                    ],
                    ['%d', '%s', '%s', '%s', '%d', '%f', '%d', '%s']
                );
            }
        }
        
        // Backup and clear old logs
        update_option('social_feed_check_logs_backup_v1_1', $existing_logs);
        delete_option('social_feed_check_logs');
    }
    
    /**
     * Upgrade database schema
     */
    public function upgrade_database() {
        $database_schema = new DatabaseSchema();
        $database_schema->create_tables();
    }
    
    /**
     * Migrate old settings format
     */
    public function migrate_old_settings($settings) {
        // Handle old setting names and formats
        if (isset($settings['youtube_api_key']) && !isset($settings['platforms']['youtube']['api_key'])) {
            $settings['platforms']['youtube']['api_key'] = $settings['youtube_api_key'];
            unset($settings['youtube_api_key']);
        }
        
        if (isset($settings['cache_duration']) && !isset($settings['cache']['duration'])) {
            $settings['cache']['duration'] = $settings['cache_duration'];
            unset($settings['cache_duration']);
        }
        
        return $settings;
    }
    
    /**
     * Clear upgrade caches
     */
    private function clear_upgrade_caches() {
        // Clear WordPress object cache
        wp_cache_flush();
        
        // Clear plugin-specific caches
        $cache_manager = new CacheManager();
        $cache_manager->clear_all_cache();
        
        // Clear any transients
        $this->clear_upgrade_transients();
    }
    
    /**
     * Clear upgrade-related transients
     */
    private function clear_upgrade_transients() {
        global $wpdb;
        
        // Delete all social feed transients
        $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_social_feed_%' 
            OR option_name LIKE '_transient_timeout_social_feed_%'
        ");
    }
    
    /**
     * Check if feature is available in current version
     */
    public function is_feature_available($feature) {
        $feature_versions = [
            'intelligent_scheduling' => '1.2',
            'learning_engine' => '1.2',
            'fallback_manager' => '1.2',
            'smart_quota_manager' => '1.2',
            'monitoring_dashboard' => '1.2',
            'rest_api_scheduling' => '1.2'
        ];
        
        if (!isset($feature_versions[$feature])) {
            return false;
        }
        
        return version_compare($this->current_version, $feature_versions[$feature], '>=');
    }
    
    /**
     * Get upgrade notices for admin
     */
    public function get_upgrade_notices() {
        $notices = [];
        
        if (version_compare($this->previous_version, '1.2', '<') && 
            version_compare($this->current_version, '1.2', '>=')) {
            
            $notices[] = [
                'type' => 'success',
                'message' => 'Social Feed has been upgraded to version 1.2 with Intelligent Scheduling! Your existing channels have been migrated automatically.'
            ];
        }
        
        return $notices;
    }
    
    /**
     * Handle deprecated functions
     */
    public function handle_deprecated_functions() {
        // Maintain old function names for backward compatibility
        if (!function_exists('social_feed_get_youtube_videos')) {
            function social_feed_get_youtube_videos($channel_id, $limit = 10) {
                _deprecated_function(__FUNCTION__, '1.2', 'SocialFeed\Platforms\YouTube::get_videos()');
                
                $youtube = new \SocialFeed\Platforms\YouTube();
                return $youtube->get_videos($channel_id, $limit);
            }
        }
        
        if (!function_exists('social_feed_clear_cache')) {
            function social_feed_clear_cache() {
                _deprecated_function(__FUNCTION__, '1.2', 'SocialFeed\Core\CacheManager::clear_all_cache()');
                
                $cache_manager = new \SocialFeed\Core\CacheManager();
                return $cache_manager->clear_all_cache();
            }
        }
    }
}