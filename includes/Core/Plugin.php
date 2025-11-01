<?php
namespace SocialFeed\Core;

class Plugin {
    /**
     * @var Plugin|null Instance of the plugin.
     */
    private static $instance = null;

    /**
     * @var array Registered modules
     */
    private $modules = [];

    /**
     * Initialize the plugin
     */
    public function init() {
        // Add cron schedules
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);
        
        // Schedule cron jobs if not already scheduled
        $this->schedule_cron_jobs();

        // Initialize REST API
        add_action('rest_api_init', [$this, 'init_rest_api']);
        
        // Initialize admin
        if (is_admin()) {
            $this->init_admin();
        }

        // Initialize frontend
        $this->init_frontend();

        // Initialize cache system
        $this->init_cache();

        // Initialize notifications system
        $this->init_notifications();

        // Initialize social media platforms
        $this->init_platforms();
    }

    /**
     * Schedule all required cron jobs
     */
    private function schedule_cron_jobs() {
        error_log('Social Feed: Checking cron job schedules at: ' . current_time('mysql'));

        // Unschedule old cleanup task if it exists
        $old_timestamp = wp_next_scheduled('social_feed_cache_cleanup');
        if ($old_timestamp) {
            error_log('Social Feed: Unscheduling old cache cleanup task');
            wp_unschedule_event($old_timestamp, 'social_feed_cache_cleanup');
        }

        // Get YouTube platform instance for quota-aware scheduling
        $youtube = new \SocialFeed\Platforms\YouTube();
        $optimal_interval = $youtube->calculate_optimal_interval();
        error_log('Social Feed: Calculated optimal interval for YouTube checks: ' . $optimal_interval . ' seconds');

        // Create dynamic schedule for YouTube checks
        $schedule_name = 'youtube_dynamic_' . $optimal_interval;
        add_filter('cron_schedules', function($schedules) use ($optimal_interval, $schedule_name) {
            $schedules[$schedule_name] = [
                'interval' => $optimal_interval,
                'display' => 'Every ' . round($optimal_interval / 60) . ' minutes (Dynamic)'
            ];
            return $schedules;
        });

        // Schedule YouTube video check with dynamic interval
        $next_video_check = wp_next_scheduled('social_feed_youtube_check_new_videos');
        if ($next_video_check) {
            error_log('Social Feed: Unscheduling existing YouTube video check');
            wp_unschedule_event($next_video_check, 'social_feed_youtube_check_new_videos');
        }
        error_log('Social Feed: Scheduling YouTube new videos check with interval: ' . $optimal_interval . ' seconds');
        wp_schedule_event(time(), $schedule_name, 'social_feed_youtube_check_new_videos');

        // Schedule YouTube live stream check (keep at 5 minutes as it's critical)
        if (!wp_next_scheduled('social_feed_youtube_check_live_streams')) {
            error_log('Social Feed: Scheduling YouTube live streams check');
            wp_schedule_event(time(), 'five_minutes', 'social_feed_youtube_check_live_streams');
        }

        // Schedule notifications check
        if (!wp_next_scheduled('social_feed_check_notifications')) {
            error_log('Social Feed: Scheduling notifications check');
            wp_schedule_event(time(), 'every_minute', 'social_feed_check_notifications');
        }

        // Schedule cache cleanup (new task name)
        if (!wp_next_scheduled('social_feed_cleanup_cache')) {
            error_log('Social Feed: Scheduling cache cleanup');
            wp_schedule_event(strtotime('tomorrow 00:00:00'), 'daily', 'social_feed_cleanup_cache');
        }

        // Add hook to update YouTube check interval periodically
        if (!wp_next_scheduled('social_feed_update_youtube_interval')) {
            wp_schedule_event(time(), 'hourly', 'social_feed_update_youtube_interval');
        }
        add_action('social_feed_update_youtube_interval', function() {
            $this->schedule_cron_jobs();
        });

        // Log next run times
        error_log('Social Feed: Next scheduled runs:');
        error_log('- New videos check: ' . date('Y-m-d H:i:s', wp_next_scheduled('social_feed_youtube_check_new_videos')));
        error_log('- Live streams check: ' . date('Y-m-d H:i:s', wp_next_scheduled('social_feed_youtube_check_live_streams')));
        error_log('- Notifications check: ' . date('Y-m-d H:i:s', wp_next_scheduled('social_feed_check_notifications')));
        error_log('- Cache cleanup: ' . date('Y-m-d H:i:s', wp_next_scheduled('social_feed_cleanup_cache')));
        error_log('- Interval update: ' . date('Y-m-d H:i:s', wp_next_scheduled('social_feed_update_youtube_interval')));
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_schedules($schedules) {
        // Add every minute schedule
        if (!isset($schedules['every_minute'])) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display' => 'Every Minute'
            ];
        }

        // Add five minutes schedule
        if (!isset($schedules['five_minutes'])) {
            $schedules['five_minutes'] = [
                'interval' => 300,
                'display' => 'Every 5 Minutes'
            ];
        }

        // Add fifteen minutes schedule
        if (!isset($schedules['fifteen_minutes'])) {
            $schedules['fifteen_minutes'] = [
                'interval' => 900,
                'display' => 'Every 15 Minutes'
            ];
        }

        return $schedules;
    }

    /**
     * Initialize REST API endpoints
     */
    public function init_rest_api() {
        // Register REST API endpoints
        $api = new \SocialFeed\API\RestAPI();
        $api->register_routes();
    }

    /**
     * Initialize admin interface
     */
    private function init_admin() {
        $admin = new \SocialFeed\Admin\Admin();
        $admin->init();
    }

    /**
     * Initialize frontend functionality
     */
    private function init_frontend() {
        $frontend = new \SocialFeed\Frontend\Frontend();
        $frontend->init();
    }

    /**
     * Initialize cache system
     */
    private function init_cache() {
        $cache = new \SocialFeed\Core\Cache();
        $cache->init();
    }

    /**
     * Initialize notifications system
     */
    private function init_notifications() {
        // Initialize notifications
        if (defined('SOCIAL_FEED_CHURCH_APP_AVAILABLE') && SOCIAL_FEED_CHURCH_APP_AVAILABLE) {
            $notifications = Notifications::get_instance();
            $notifications->init();
        }
    }

    /**
     * Initialize social media platforms
     */
    private function init_platforms() {
        // Initialize platform handlers
        $platforms = [
            'youtube' => new \SocialFeed\Platforms\YouTube(),
            // Other platforms will be added later
            // 'tiktok' => new \SocialFeed\Platforms\TikTok(),
            // 'facebook' => new \SocialFeed\Platforms\Facebook(),
            // 'instagram' => new \SocialFeed\Platforms\Instagram(),
        ];

        foreach ($platforms as $platform) {
            $platform->init();
        }
    }

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
} 