<?php
/**
 * Learning Engine for Pattern Detection and Schedule Optimization
 * 
 * @package SocialFeed\Core
 */

namespace SocialFeed\Core;

class LearningEngine {
    
    private $min_data_points = 10;
    private $learning_window_days = 30;
    
    /**
     * Update publishing patterns based on recent data
     */
    public function update_patterns() {
        global $wpdb;
        
        $schedules = $wpdb->get_results(
            "SELECT DISTINCT schedule_id FROM {$wpdb->prefix}social_feed_analytics 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$this->learning_window_days} DAY)"
        );
        
        foreach ($schedules as $schedule) {
            $this->analyze_schedule_patterns($schedule->schedule_id);
        }
    }
    
    /**
     * Analyze patterns for a specific schedule
     */
    private function analyze_schedule_patterns($schedule_id) {
        global $wpdb;
        
        // Get successful content discoveries
        $successful_checks = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                DAYOFWEEK(check_time) as day_of_week,
                HOUR(check_time) as hour,
                COUNT(*) as success_count,
                AVG(response_time_ms) as avg_response_time
             FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND content_found = 1
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DAYOFWEEK(check_time), HOUR(check_time)
             HAVING COUNT(*) >= %d
             ORDER BY success_count DESC",
            $schedule_id,
            $this->learning_window_days,
            $this->min_data_points
        ));
        
        if (empty($successful_checks)) {
            return;
        }
        
        // Store pattern data
        $patterns = [];
        foreach ($successful_checks as $check) {
            $day_name = $this->get_day_name($check->day_of_week);
            $patterns[] = [
                'day' => $day_name,
                'hour' => $check->hour,
                'success_rate' => $check->success_count,
                'avg_response_time' => $check->avg_response_time
            ];
        }
        
        update_option("social_feed_learned_patterns_{$schedule_id}", $patterns);
        
        // Generate optimization suggestions
        $this->generate_optimization_suggestions($schedule_id, $patterns);
    }
    
    /**
     * Generate schedule optimization suggestions
     */
    public function generate_schedule_suggestion($schedule_id) {
        global $wpdb;
        
        $patterns = get_option("social_feed_learned_patterns_{$schedule_id}", []);
        
        if (empty($patterns)) {
            return null;
        }
        
        // Get current schedule
        $current_schedule = $wpdb->get_results($wpdb->prepare(
            "SELECT sl.day_of_week, sl.check_time, s.priority
             FROM {$wpdb->prefix}social_feed_schedule_slots sl
             JOIN {$wpdb->prefix}social_feed_schedules s ON sl.schedule_id = s.id
             WHERE sl.schedule_id = %d",
            $schedule_id
        ));
        
        $suggestions = [];
        
        // Find optimal time slots based on patterns
        $optimal_slots = array_slice($patterns, 0, 3); // Top 3 patterns
        
        foreach ($optimal_slots as $slot) {
            $suggested_time = sprintf('%02d:00:00', $slot['hour']);
            
            // Check if this time slot is already in the schedule
            $exists = false;
            foreach ($current_schedule as $current) {
                if ($current->day_of_week === $slot['day'] && 
                    abs(strtotime($current->check_time) - strtotime($suggested_time)) < 3600) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                $suggestions[] = [
                    'type' => 'add_slot',
                    'day' => $slot['day'],
                    'time' => $suggested_time,
                    'reason' => sprintf(
                        'High success rate (%d discoveries) at this time',
                        $slot['success_rate']
                    ),
                    'confidence' => min(100, ($slot['success_rate'] / $this->min_data_points) * 100)
                ];
            }
        }
        
        // Suggest removing ineffective slots
        foreach ($current_schedule as $current) {
            $effectiveness = $this->calculate_slot_effectiveness($schedule_id, $current->day_of_week, $current->check_time);
            
            if ($effectiveness < 10) { // Less than 10% success rate
                $suggestions[] = [
                    'type' => 'remove_slot',
                    'day' => $current->day_of_week,
                    'time' => $current->check_time,
                    'reason' => sprintf('Low effectiveness (%.1f%% success rate)', $effectiveness),
                    'confidence' => 100 - $effectiveness
                ];
            }
        }
        
        return $suggestions;
    }
    
    /**
     * Calculate effectiveness of a specific time slot
     */
    private function calculate_slot_effectiveness($schedule_id, $day, $time) {
        global $wpdb;
        
        $day_number = $this->get_day_number($day);
        $hour = date('H', strtotime($time));
        
        $total_checks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND DAYOFWEEK(check_time) = %d
             AND HOUR(check_time) = %d
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $schedule_id,
            $day_number,
            $hour,
            $this->learning_window_days
        ));
        
        if ($total_checks == 0) {
            return 0;
        }
        
        $successful_checks = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND DAYOFWEEK(check_time) = %d
             AND HOUR(check_time) = %d
             AND content_found = 1
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $schedule_id,
            $day_number,
            $hour,
            $this->learning_window_days
        ));
        
        return ($successful_checks / $total_checks) * 100;
    }
    
    /**
     * Generate optimization suggestions for a schedule
     */
    private function generate_optimization_suggestions($schedule_id, $patterns) {
        global $wpdb;
        
        $suggestions = [];
        
        // Analyze current schedule effectiveness
        $current_effectiveness = get_option("social_feed_schedule_{$schedule_id}_effectiveness", 0);
        
        if ($current_effectiveness < 30) {
            // Suggest priority adjustment
            $suggestions[] = [
                'type' => 'priority_adjustment',
                'current_priority' => $wpdb->get_var($wpdb->prepare(
                    "SELECT priority FROM {$wpdb->prefix}social_feed_schedules WHERE id = %d",
                    $schedule_id
                )),
                'suggested_priority' => min(5, ceil($current_effectiveness / 10)),
                'reason' => 'Low effectiveness suggests reducing priority to save quota'
            ];
        }
        
        // Suggest frequency adjustments based on content discovery patterns
        $content_frequency = $this->analyze_content_frequency($schedule_id);
        
        if ($content_frequency['avg_days_between_content'] > 3) {
            $suggestions[] = [
                'type' => 'frequency_reduction',
                'current_frequency' => 'Current schedule',
                'suggested_frequency' => 'Reduce to 2-3 times per week',
                'reason' => sprintf(
                    'Content published every %.1f days on average',
                    $content_frequency['avg_days_between_content']
                )
            ];
        }
        
        update_option("social_feed_schedule_{$schedule_id}_suggestions", $suggestions);
    }
    
    /**
     * Analyze content publishing frequency
     */
    private function analyze_content_frequency($schedule_id) {
        global $wpdb;
        
        $content_discoveries = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(check_time) as discovery_date
             FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND content_found = 1
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(check_time)
             ORDER BY discovery_date",
            $schedule_id,
            $this->learning_window_days
        ));
        
        if (count($content_discoveries) < 2) {
            return ['avg_days_between_content' => 7]; // Default assumption
        }
        
        $intervals = [];
        for ($i = 1; $i < count($content_discoveries); $i++) {
            $prev_date = new \DateTime($content_discoveries[$i-1]->discovery_date);
            $curr_date = new \DateTime($content_discoveries[$i]->discovery_date);
            $intervals[] = $curr_date->diff($prev_date)->days;
        }
        
        return [
            'avg_days_between_content' => array_sum($intervals) / count($intervals),
            'min_interval' => min($intervals),
            'max_interval' => max($intervals),
            'total_discoveries' => count($content_discoveries)
        ];
    }
    
    /**
     * Get predictive insights for a channel
     */
    public function get_predictive_insights($schedule_id) {
        $patterns = get_option("social_feed_learned_patterns_{$schedule_id}", []);
        $suggestions = get_option("social_feed_schedule_{$schedule_id}_suggestions", []);
        $effectiveness = get_option("social_feed_schedule_{$schedule_id}_effectiveness", 0);
        
        return [
            'effectiveness_score' => $effectiveness,
            'learned_patterns' => $patterns,
            'optimization_suggestions' => $suggestions,
            'next_likely_content' => $this->predict_next_content_time($schedule_id),
            'confidence_level' => $this->calculate_prediction_confidence($schedule_id)
        ];
    }
    
    /**
     * Predict next likely content publication time
     */
    private function predict_next_content_time($schedule_id) {
        $patterns = get_option("social_feed_learned_patterns_{$schedule_id}", []);
        
        if (empty($patterns)) {
            return null;
        }
        
        // Get the most successful pattern
        $best_pattern = $patterns[0];
        
        $current_time = current_time('timestamp');
        $current_day = date('w', $current_time); // 0 = Sunday
        
        // Convert pattern day to number
        $target_day = $this->get_day_number($best_pattern['day']) - 1; // Adjust for 0-based
        
        // Calculate days until next occurrence
        $days_ahead = ($target_day - $current_day + 7) % 7;
        if ($days_ahead == 0 && date('H', $current_time) >= $best_pattern['hour']) {
            $days_ahead = 7; // Next week if time has passed today
        }
        
        $next_time = strtotime("+{$days_ahead} days {$best_pattern['hour']}:00:00", $current_time);
        
        return [
            'timestamp' => $next_time,
            'formatted' => date('Y-m-d H:i:s', $next_time),
            'confidence' => min(100, $best_pattern['success_rate'] * 10)
        ];
    }
    
    /**
     * Calculate prediction confidence based on data quality
     */
    private function calculate_prediction_confidence($schedule_id) {
        global $wpdb;
        
        $data_points = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $schedule_id,
            $this->learning_window_days
        ));
        
        $success_rate = $wpdb->get_var($wpdb->prepare(
            "SELECT (COUNT(CASE WHEN content_found = 1 THEN 1 END) / COUNT(*)) * 100
             FROM {$wpdb->prefix}social_feed_analytics 
             WHERE schedule_id = %d 
             AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $schedule_id,
            $this->learning_window_days
        )) ?: 0;
        
        // Confidence based on data quantity and success rate
        $data_confidence = min(100, ($data_points / ($this->min_data_points * 3)) * 100);
        $success_confidence = $success_rate;
        
        return ($data_confidence + $success_confidence) / 2;
    }
    
    /**
     * Convert day number to day name
     */
    private function get_day_name($day_number) {
        $days = [
            1 => 'sunday',
            2 => 'monday', 
            3 => 'tuesday',
            4 => 'wednesday',
            5 => 'thursday',
            6 => 'friday',
            7 => 'saturday'
        ];
        
        return $days[$day_number] ?? 'monday';
    }
    
    /**
     * Convert day name to day number
     */
    private function get_day_number($day_name) {
        $days = [
            'sunday' => 1,
            'monday' => 2,
            'tuesday' => 3,
            'wednesday' => 4,
            'thursday' => 5,
            'friday' => 6,
            'saturday' => 7
        ];
        
        return $days[strtolower($day_name)] ?? 2;
    }
    
    /**
     * Initialize learning parameters for new installations
     */
    public function initialize_learning_parameters() {
        // Set default learning parameters
        update_option('social_feed_learning_min_data_points', $this->min_data_points);
        update_option('social_feed_learning_window_days', $this->learning_window_days);
        
        // Initialize global learning settings
        update_option('social_feed_learning_enabled', true);
        update_option('social_feed_learning_auto_optimize', false);
        update_option('social_feed_learning_confidence_threshold', 70);
        
        // Log initialization
        error_log('Social Feed: Learning engine parameters initialized successfully');
    }

    /**
     * Export learning data for analysis
     */
    public function export_learning_data($schedule_id = null) {
        global $wpdb;
        
        $where_clause = $schedule_id ? $wpdb->prepare("WHERE schedule_id = %d", $schedule_id) : "";
        
        $analytics_data = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}social_feed_analytics 
             {$where_clause}
             AND created_at >= DATE_SUB(NOW(), INTERVAL {$this->learning_window_days} DAY)
             ORDER BY check_time DESC"
        );
        
        return [
            'analytics' => $analytics_data,
            'patterns' => $schedule_id ? get_option("social_feed_learned_patterns_{$schedule_id}", []) : [],
            'suggestions' => $schedule_id ? get_option("social_feed_schedule_{$schedule_id}_suggestions", []) : [],
            'export_time' => current_time('mysql'),
            'data_window_days' => $this->learning_window_days
        ];
    }
}