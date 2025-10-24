<?php
/**
 * Intelligent Scheduler for Smart Content Checking
 * 
 * @package SocialFeed\Core
 */

namespace SocialFeed\Core;

class IntelligentScheduler {
    
    private $quota_manager;
    private $learning_engine;
    
    public function __construct() {
        $this->quota_manager = new SmartQuotaManager();
        $this->learning_engine = new LearningEngine();
    }
    
    /**
     * Initialize scheduler hooks
     */
    public function init() {
        // Hook into WordPress cron
        add_action('social_feed_intelligent_check', [$this, 'execute_intelligent_check']);
        
        // Schedule the main cron job if not already scheduled
        if (!wp_next_scheduled('social_feed_intelligent_check')) {
            wp_schedule_event(time(), 'every_15_minutes', 'social_feed_intelligent_check');
        }
        
        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);
    }
    
    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules) {
        $schedules['every_15_minutes'] = [
            'interval' => 15 * 60,
            'display' => __('Every 15 Minutes', 'social-feed')
        ];
        
        $schedules['every_5_minutes'] = [
            'interval' => 5 * 60,
            'display' => __('Every 5 Minutes', 'social-feed')
        ];
        
        return $schedules;
    }
    
    /**
     * Execute intelligent content checking
     */
    public function execute_intelligent_check() {
        global $wpdb;
        
        $current_time = current_time('timestamp');
        
        // Get all active schedules
        $schedules = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}social_feed_schedules WHERE active = 1"
        );
        
        if (empty($schedules)) {
            return;
        }
        
        // Calculate priorities for all schedules
        $schedule_priorities = [];
        foreach ($schedules as $schedule) {
            $priority = $this->quota_manager->calculate_priority_score($schedule->id, $current_time);
            $schedule_priorities[] = [
                'schedule' => $schedule,
                'priority' => $priority
            ];
        }
        
        // Sort by priority (highest first)
        usort($schedule_priorities, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        // Process schedules in priority order
        foreach ($schedule_priorities as $item) {
            $schedule = $item['schedule'];
            
            // Check if we should make API call for this schedule
            if ($this->quota_manager->should_make_api_call($schedule->id)) {
                $this->process_schedule_check($schedule);
            }
            
            // Stop if quota is exhausted
            if ($this->quota_manager->get_remaining_quota() <= 0) {
                break;
            }
        }
        
        // Run fallback checks if quota allows
        $this->run_fallback_checks();
        
        // Update learning data
        $this->learning_engine->update_patterns();
    }
    
    /**
     * Process content check for a specific schedule
     */
    private function process_schedule_check($schedule) {
        $start_time = microtime(true);
        
        try {
            // Get platform instance
            $platform = $this->get_platform_instance($schedule->channel_id);
            
            if (!$platform) {
                error_log("Social Feed: No platform found for channel {$schedule->channel_id}");
                return;
            }
            
            // Fetch new content
            $new_content = $platform->fetch_recent_content($schedule->channel_id);
            $videos_found = is_array($new_content) ? count($new_content) : 0;
            
            // Calculate response time
            $response_time = (microtime(true) - $start_time) * 1000;
            
            // Record the API call
            $this->quota_manager->record_api_call($schedule->id, $videos_found, $response_time);
            
            // Process new content if found
            if ($videos_found > 0) {
                $this->process_new_content($new_content, $schedule);
            }
            
            // Update schedule effectiveness
            $this->update_schedule_effectiveness($schedule->id, $videos_found > 0);
            
        } catch (\Exception $e) {
            error_log("Social Feed: Error processing schedule {$schedule->id}: " . $e->getMessage());
            
            // Record failed attempt
            $response_time = (microtime(true) - $start_time) * 1000;
            $this->quota_manager->record_api_call($schedule->id, 0, $response_time);
        }
    }
    
    /**
     * Run fallback checks for missed content
     */
    private function run_fallback_checks() {
        global $wpdb;
        
        // Only run fallback if we have sufficient quota (at least 20% remaining)
        $remaining_quota = $this->quota_manager->get_remaining_quota();
        $quota_threshold = $this->quota_manager->daily_quota_limit * 0.2;
        
        if ($remaining_quota < $quota_threshold) {
            return;
        }
        
        // Find channels that haven't been checked recently
        $stale_channels = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT s.channel_id, s.id as schedule_id
             FROM {$wpdb->prefix}social_feed_schedules s
             LEFT JOIN {$wpdb->prefix}social_feed_analytics a ON s.id = a.schedule_id
             WHERE s.active = 1
             AND (a.check_time IS NULL OR a.check_time < DATE_SUB(NOW(), INTERVAL 4 HOUR))
             GROUP BY s.channel_id, s.id
             LIMIT %d",
            min(5, floor($remaining_quota / 2))
        ));
        
        foreach ($stale_channels as $channel) {
            if ($this->quota_manager->allocate_emergency_quota()) {
                $this->process_fallback_check($channel);
            }
        }
    }
    
    /**
     * Process fallback check for stale channel
     */
    private function process_fallback_check($channel) {
        global $wpdb;
        
        $start_time = microtime(true);
        
        try {
            $platform = $this->get_platform_instance($channel->channel_id);
            
            if (!$platform) {
                return;
            }
            
            $new_content = $platform->fetch_recent_content($channel->channel_id);
            $videos_found = is_array($new_content) ? count($new_content) : 0;
            $response_time = (microtime(true) - $start_time) * 1000;
            
            // Record fallback check
            $wpdb->insert(
                $wpdb->prefix . 'social_feed_analytics',
                [
                    'schedule_id' => $channel->schedule_id,
                    'check_time' => current_time('mysql'),
                    'content_found' => $videos_found > 0 ? 1 : 0,
                    'api_calls_made' => 1,
                    'result_type' => 'fallback',
                    'response_time_ms' => $response_time
                ]
            );
            
            if ($videos_found > 0) {
                $schedule = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}social_feed_schedules WHERE id = %d",
                    $channel->schedule_id
                ));
                
                $this->process_new_content($new_content, $schedule);
            }
            
        } catch (\Exception $e) {
            error_log("Social Feed: Error in fallback check for channel {$channel->channel_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Process new content found
     */
    private function process_new_content($content, $schedule) {
        // Cache the content
        $cache = new Cache();
        foreach ($content as $item) {
            $cache->set(
                $this->get_platform_from_channel($schedule->channel_id),
                'video',
                $item['id'] ?? uniqid(),
                $item,
                3600 // 1 hour cache
            );
        }
        
        // Send notifications if enabled
        if (class_exists('SocialFeed\\Core\\Notifications')) {
            $notifications = new Notifications();
            foreach ($content as $item) {
                $notifications->send_new_video_notification($item, $schedule->channel_id);
            }
        }
    }
    
    /**
     * Update schedule effectiveness metrics
     */
    private function update_schedule_effectiveness($schedule_id, $found_content) {
        global $wpdb;
        
        // Get recent effectiveness data
        $recent_checks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND check_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $schedule_id
        ));
        
        $successful_checks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND content_found = 1
             AND check_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $schedule_id
        ));
        
        $effectiveness = $recent_checks > 0 ? ($successful_checks / $recent_checks) * 100 : 0;
        
        // Store effectiveness score
        update_option("social_feed_schedule_{$schedule_id}_effectiveness", $effectiveness);
        
        // Suggest schedule adjustments if effectiveness is low
        if ($effectiveness < 20 && $recent_checks >= 10) {
            $this->suggest_schedule_optimization($schedule_id);
        }
    }
    
    /**
     * Suggest schedule optimization
     */
    private function suggest_schedule_optimization($schedule_id) {
        // This could trigger admin notifications or automatic adjustments
        $suggestion = $this->learning_engine->generate_schedule_suggestion($schedule_id);
        
        if ($suggestion) {
            update_option("social_feed_schedule_{$schedule_id}_suggestion", $suggestion);
        }
    }
    
    /**
     * Get platform instance for channel
     */
    private function get_platform_instance($channel_id) {
        // Determine platform from channel ID format
        if (strpos($channel_id, 'UC') === 0 || strpos($channel_id, '@') === 0) {
            return new \SocialFeed\Platforms\YouTube();
        }
        
        // Add other platform detection logic here
        return null;
    }
    
    /**
     * Get platform name from channel ID
     */
    private function get_platform_from_channel($channel_id) {
        if (strpos($channel_id, 'UC') === 0 || strpos($channel_id, '@') === 0) {
            return 'youtube';
        }
        
        return 'unknown';
    }
    
    /**
     * Manual trigger for immediate check
     */
    public function trigger_immediate_check($channel_id = null) {
        if ($channel_id) {
            // Check specific channel
            global $wpdb;
            $schedule = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}social_feed_schedules WHERE channel_id = %s AND active = 1",
                $channel_id
            ));
            
            if ($schedule && $this->quota_manager->allocate_emergency_quota()) {
                $this->process_schedule_check($schedule);
            }
        } else {
            // Trigger full check
            $this->execute_intelligent_check();
        }
    }
    
    /**
     * Get next scheduled check times
     */
    public function get_next_check_times($limit = 10) {
        global $wpdb;
        
        $current_time = current_time('timestamp');
        $next_checks = [];
        
        $schedules = $wpdb->get_results(
            "SELECT s.*, sl.day_of_week, sl.check_time 
             FROM {$wpdb->prefix}social_feed_schedules s
             JOIN {$wpdb->prefix}social_feed_schedule_slots sl ON s.id = sl.schedule_id
             WHERE s.active = 1
             ORDER BY s.priority DESC"
        );
        
        foreach ($schedules as $schedule) {
            $next_time = $this->calculate_next_check_time($schedule, $current_time);
            if ($next_time) {
                $next_checks[] = [
                    'schedule_id' => $schedule->id,
                    'channel_id' => $schedule->channel_id,
                    'next_check' => $next_time,
                    'priority' => $schedule->priority
                ];
            }
        }
        
        // Sort by next check time
        usort($next_checks, function($a, $b) {
            return $a['next_check'] <=> $b['next_check'];
        });
        
        return array_slice($next_checks, 0, $limit);
    }
    
    /**
     * Calculate next check time for a schedule
     */
    private function calculate_next_check_time($schedule, $current_time) {
        $timezone = new \DateTimeZone($schedule->timezone);
        $now = new \DateTime('@' . $current_time);
        $now->setTimezone($timezone);
        
        $current_day = strtolower($now->format('l'));
        $current_time_str = $now->format('H:i:s');
        
        // If there's a slot today after current time, use it
        if ($schedule->day_of_week === $current_day && $schedule->check_time > $current_time_str) {
            $next = clone $now;
            $next->setTime(...explode(':', $schedule->check_time));
            return $next->getTimestamp();
        }
        
        // Find next occurrence
        $days_ahead = 0;
        $target_day = $schedule->day_of_week;
        
        do {
            $days_ahead++;
            $test_date = clone $now;
            $test_date->add(new \DateInterval("P{$days_ahead}D"));
            $test_day = strtolower($test_date->format('l'));
        } while ($test_day !== $target_day && $days_ahead < 7);
        
        if ($days_ahead < 7) {
            $next = clone $now;
            $next->add(new \DateInterval("P{$days_ahead}D"));
            $next->setTime(...explode(':', $schedule->check_time));
            return $next->getTimestamp();
        }
        
        return null;
    }
}