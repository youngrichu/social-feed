<?php
/**
 * Smart Quota Manager for Intelligent API Usage
 * 
 * @package SocialFeed\Core
 */

namespace SocialFeed\Core;

class SmartQuotaManager {
    
    private $daily_quota_limit;
    private $current_usage;
    private $priority_weights;
    
    public function __construct() {
        $this->daily_quota_limit = get_option('social_feed_daily_quota_limit', 10000);
        $this->priority_weights = [
            1 => 0.1,  // Low priority
            2 => 0.2,
            3 => 0.4,  // Medium priority (default)
            4 => 0.7,
            5 => 1.0   // High priority
        ];
        $this->update_current_usage();
    }
    
    /**
     * Calculate priority score for a check based on schedule and current time
     */
    public function calculate_priority_score($schedule_id, $current_time = null) {
        if (!$current_time) {
            $current_time = current_time('timestamp');
        }
        
        global $wpdb;
        
        // Get schedule details
        $schedule = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}social_feed_schedules WHERE id = %d AND active = 1",
            $schedule_id
        ));
        
        if (!$schedule) {
            return 0;
        }
        
        // Get current day and time in schedule timezone
        $timezone = new \DateTimeZone($schedule->timezone);
        $datetime = new \DateTime('@' . $current_time);
        $datetime->setTimezone($timezone);
        
        $current_day = strtolower($datetime->format('l'));
        $current_hour_minute = $datetime->format('H:i');
        
        // Get schedule slots for current day
        $slots = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}social_feed_schedule_slots 
             WHERE schedule_id = %d AND day_of_week = %s",
            $schedule_id,
            $current_day
        ));
        
        if (empty($slots)) {
            return $this->priority_weights[1]; // Low priority if no slots for today
        }
        
        $base_priority = $this->priority_weights[$schedule->priority] ?? $this->priority_weights[3];
        $time_multiplier = 1.0;
        $proximity_bonus = 0;
        
        // Calculate time-based multiplier
        foreach ($slots as $slot) {
            $slot_time = $slot->check_time;
            $time_diff = $this->calculate_time_difference($current_hour_minute, $slot_time);
            
            if ($time_diff <= 30) { // Within 30 minutes
                $proximity_bonus = max($proximity_bonus, 2.0 - ($time_diff / 30));
            } elseif ($time_diff <= 120) { // Within 2 hours
                $proximity_bonus = max($proximity_bonus, 0.5);
            }
        }
        
        // Apply learning-based adjustments
        $learning_multiplier = $this->get_learning_multiplier($schedule_id, $current_day, $current_hour_minute);
        
        return min(5.0, $base_priority * (1 + $proximity_bonus) * $learning_multiplier);
    }
    
    /**
     * Determine if API call should be made based on quota and priority
     */
    public function should_make_api_call($schedule_id, $force = false) {
        if ($force) {
            return true;
        }
        
        // Check if we have quota remaining
        $remaining_quota = $this->get_remaining_quota();
        if ($remaining_quota <= 0) {
            return false;
        }
        
        // Calculate priority score
        $priority_score = $this->calculate_priority_score($schedule_id);
        
        // Calculate quota utilization percentage
        $utilization = ($this->current_usage / $this->daily_quota_limit) * 100;
        
        // Dynamic threshold based on time of day and utilization
        $threshold = $this->calculate_dynamic_threshold($utilization);
        
        return $priority_score >= $threshold;
    }
    
    /**
     * Record API call usage
     */
    public function record_api_call($schedule_id, $videos_found = 0, $response_time_ms = null) {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        
        // Update quota usage
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}social_feed_quota_usage 
             (schedule_id, usage_date, api_calls_used, videos_found) 
             VALUES (%d, %s, 1, %d)
             ON DUPLICATE KEY UPDATE 
             api_calls_used = api_calls_used + 1,
             videos_found = videos_found + %d",
            $schedule_id,
            $today,
            $videos_found,
            $videos_found
        ));
        
        // Record analytics
        $wpdb->insert(
            $wpdb->prefix . 'social_feed_analytics',
            [
                'schedule_id' => $schedule_id,
                'check_time' => current_time('mysql'),
                'content_found' => $videos_found > 0 ? 1 : 0,
                'api_calls_made' => 1,
                'result_type' => 'scheduled',
                'response_time_ms' => $response_time_ms
            ]
        );
        
        // Update current usage
        $this->current_usage++;
        
        // Update efficiency scores
        $this->update_efficiency_scores();
    }
    
    /**
     * Get remaining quota for today
     */
    public function get_remaining_quota() {
        return max(0, $this->daily_quota_limit - $this->current_usage);
    }
    
    /**
     * Get quota usage statistics
     */
    public function get_quota_stats() {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(api_calls_used) as total_calls,
                SUM(videos_found) as total_videos,
                AVG(efficiency_score) as avg_efficiency
             FROM {$wpdb->prefix}social_feed_quota_usage 
             WHERE usage_date = %s",
            $today
        ));
        
        return [
            'daily_limit' => $this->daily_quota_limit,
            'used_today' => $this->current_usage,
            'remaining' => $this->get_remaining_quota(),
            'utilization_percent' => ($this->current_usage / $this->daily_quota_limit) * 100,
            'videos_found_today' => $stats->total_videos ?? 0,
            'efficiency_score' => $stats->avg_efficiency ?? 0,
            'next_reset' => $this->get_next_reset_time()
        ];
    }
    
    /**
     * Update current usage from database
     */
    private function update_current_usage() {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        
        $usage = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(api_calls_used) FROM {$wpdb->prefix}social_feed_quota_usage WHERE usage_date = %s",
            $today
        ));
        
        $this->current_usage = intval($usage);
    }
    
    /**
     * Calculate time difference in minutes
     */
    private function calculate_time_difference($time1, $time2) {
        $datetime1 = \DateTime::createFromFormat('H:i', $time1);
        $datetime2 = \DateTime::createFromFormat('H:i:s', $time2);
        
        if (!$datetime1 || !$datetime2) {
            return 999; // Large number if parsing fails
        }
        
        $diff = abs($datetime1->getTimestamp() - $datetime2->getTimestamp());
        return $diff / 60; // Convert to minutes
    }
    
    /**
     * Get learning-based multiplier for schedule optimization
     */
    private function get_learning_multiplier($schedule_id, $day, $time) {
        global $wpdb;
        
        // Get historical success rate for this time slot
        $success_rate = $wpdb->get_var($wpdb->prepare(
            "SELECT 
                (SUM(CASE WHEN content_found = 1 THEN 1 ELSE 0 END) / COUNT(*)) as success_rate
             FROM {$wpdb->prefix}social_feed_analytics a
             JOIN {$wpdb->prefix}social_feed_schedules s ON a.schedule_id = s.id
             JOIN {$wpdb->prefix}social_feed_schedule_slots sl ON s.id = sl.schedule_id
             WHERE a.schedule_id = %d 
             AND sl.day_of_week = %s
             AND TIME(a.check_time) BETWEEN 
                 SUBTIME(sl.check_time, '01:00:00') AND 
                 ADDTIME(sl.check_time, '01:00:00')
             AND a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            $schedule_id,
            $day
        ));
        
        if ($success_rate === null) {
            return 1.0; // No historical data
        }
        
        // Boost multiplier for high-success time slots
        return 1.0 + ($success_rate * 0.5);
    }
    
    /**
     * Calculate dynamic threshold based on quota utilization
     */
    private function calculate_dynamic_threshold($utilization) {
        if ($utilization < 50) {
            return 1.0; // Low threshold when quota is abundant
        } elseif ($utilization < 80) {
            return 2.0; // Medium threshold
        } else {
            return 3.5; // High threshold when quota is scarce
        }
    }
    
    /**
     * Update efficiency scores for all schedules
     */
    private function update_efficiency_scores() {
        global $wpdb;
        
        $today = current_time('Y-m-d');
        
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}social_feed_quota_usage 
             SET efficiency_score = CASE 
                 WHEN api_calls_used > 0 THEN (videos_found / api_calls_used) * 100 
                 ELSE 0 
             END
             WHERE usage_date = %s",
            $today
        ));
    }
    
    /**
     * Get next quota reset time
     */
    private function get_next_reset_time() {
        $tomorrow = new \DateTime('tomorrow', new \DateTimeZone(get_option('timezone_string', 'UTC')));
        return $tomorrow->format('Y-m-d H:i:s');
    }
    
    /**
     * Emergency quota allocation for critical checks
     */
    public function allocate_emergency_quota($amount = 1) {
        $remaining = $this->get_remaining_quota();
        
        if ($remaining >= $amount) {
            return true;
        }
        
        // Check if we can borrow from tomorrow's quota (up to 10%)
        $emergency_allowance = floor($this->daily_quota_limit * 0.1);
        $current_emergency_usage = get_transient('social_feed_emergency_quota_used') ?: 0;
        
        if (($current_emergency_usage + $amount) <= $emergency_allowance) {
            set_transient('social_feed_emergency_quota_used', $current_emergency_usage + $amount, DAY_IN_SECONDS);
            return true;
        }
        
        return false;
    }
}