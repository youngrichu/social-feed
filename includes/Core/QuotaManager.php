<?php
namespace SocialFeed\Core;

class QuotaManager
{
    /**
     * YouTube API quota limits
     */
    const DEFAULT_QUOTA_LIMIT = 10000;
    const QUOTA_COSTS = [
        'search' => 100,
        'videos' => 1,
        'channels' => 1,
        'playlistItems' => 1,
        'playlists' => 1
    ];

    /**
     * Operation priorities
     */
    const OPERATION_PRIORITY = [
        'high' => ['videos', 'playlistItems', 'playlists'],  // Essential operations
        'medium' => ['channels'],               // Important but not critical
        'low' => ['search']                     // Resource-intensive operations
    ];

    /**
     * Quota thresholds for adaptive rate limiting
     */
    const QUOTA_THRESHOLDS = [
        'critical' => 90,  // Percentage at which to enter critical mode
        'high' => 75,      // Percentage at which to enter high-restriction mode
        'moderate' => 50   // Percentage at which to enter moderate-restriction mode
    ];

    /**
     * Historical analysis constants
     */
    const HISTORICAL_DAYS = 30;
    const PREDICTION_ACCURACY_THRESHOLD = 0.8;
    const QUOTA_BUFFER_PERCENTAGE = 10; // Reserve 10% for critical operations

    /**
     * Predictive patterns cache
     */
    private $usage_patterns = null;
    private $prediction_cache = [];

    /**
     * Get current quota usage
     *
     * @return int
     */
    /**
     * Get quota limit from settings
     *
     * @return int
     */
    public function get_quota_limit()
    {
        $options = get_option('social_feed_platforms', []);
        $limit = $options['youtube']['quota_limit'] ?? self::DEFAULT_QUOTA_LIMIT;
        return (int) $limit;
    }

    /**
     * Get current quota usage
     *
     * @return int
     */
    public function get_current_usage()
    {
        $quota_key = 'youtube_quota_usage_' . date('Y-m-d');
        return get_transient($quota_key) ?: 0;
    }

    /**
     * Check if operation would exceed quota with smart prioritization
     * Simplified: Only blocks based on ACTUAL usage, not predictions
     *
     * @param string $operation
     * @return bool
     */
    public function check_quota($operation)
    {
        $today = date('Y-m-d');
        $quota_key = 'youtube_quota_usage_' . $today;
        $quota_exceeded_key = 'youtube_quota_exceeded_' . $today;
        $quota_stats_key = 'youtube_quota_stats_' . $today;

        // If quota was exceeded today, prevent all operations
        if (get_transient($quota_exceeded_key)) {
            error_log("YouTube: Quota exceeded for today ($today), operation blocked: $operation");
            return false;
        }

        // Get current quota usage and stats
        $current_usage = (int) get_transient($quota_key) ?: 0;
        $operation_cost = self::QUOTA_COSTS[$operation] ?? 1;
        $quota_stats = get_option($quota_stats_key, ['operations' => []]);

        // Get operation priority
        $priority = $this->get_operation_priority($operation);

        // Calculate quota percentage based on ACTUAL current usage
        $daily_limit = $this->get_quota_limit();
        $quota_percentage = ($current_usage / $daily_limit) * 100;

        // Apply rate limiting based on ACTUAL quota usage only
        if ($quota_percentage >= self::QUOTA_THRESHOLDS['critical']) {
            // In critical mode (90%+), only allow high-priority operations
            if ($priority !== 'high') {
                error_log("YouTube: Operation blocked - quota at {$quota_percentage}% (critical): $operation");
                return false;
            }
        } elseif ($quota_percentage >= self::QUOTA_THRESHOLDS['high']) {
            // In high mode (75%+), block low-priority operations
            if ($priority === 'low') {
                error_log("YouTube: Low-priority operation blocked - quota at {$quota_percentage}%: $operation");
                return false;
            }
        }

        // Check if operation would exceed quota
        $daily_limit = $this->get_quota_limit();
        if (($current_usage + $operation_cost) >= $daily_limit) {
            error_log("YouTube: Daily quota would be exceeded. Current: $current_usage, Operation cost: $operation_cost, Limit: $daily_limit");
            set_transient($quota_exceeded_key, true, DAY_IN_SECONDS);
            return false;
        }

        // Update quota usage and stats
        $new_usage = $current_usage + $operation_cost;
        set_transient($quota_key, $new_usage, DAY_IN_SECONDS);

        // Update operation statistics
        $quota_stats['operations'][$operation] = ($quota_stats['operations'][$operation] ?? 0) + 1;
        $quota_stats['last_update'] = current_time('mysql');
        $quota_stats['usage'] = $new_usage;
        update_option($quota_stats_key, $quota_stats);

        // Store historical data for reporting (but not for blocking)
        $this->store_historical_usage($operation, $operation_cost);

        return true;
    }

    /**
     * Get operation priority
     *
     * @param string $operation
     * @return string
     */
    public function get_operation_priority($operation)
    {
        foreach (self::OPERATION_PRIORITY as $priority => $operations) {
            if (in_array($operation, $operations)) {
                return $priority;
            }
        }
        return 'low'; // Default to low priority if not explicitly set
    }

    /**
     * Reset quota usage
     *
     * @return bool
     */
    public function reset_quota()
    {
        $today = date('Y-m-d');
        $quota_key = 'youtube_quota_usage_' . $today;
        $quota_exceeded_key = 'youtube_quota_exceeded_' . $today;
        $quota_stats_key = 'youtube_quota_stats_' . $today;

        delete_transient($quota_key);
        delete_transient($quota_exceeded_key);

        $quota_stats = [
            'usage' => 0,
            'operations' => [],
            'reset_at' => current_time('mysql'),
            'last_update' => current_time('mysql')
        ];

        update_option($quota_stats_key, $quota_stats);
        error_log('YouTube: Quota usage reset for ' . $today);

        return true;
    }

    /**
     * Force reset quota usage (useful when API quota was reset on Google side)
     *
     * @param int $new_usage Optional new usage value
     * @return bool
     */
    public function force_reset_quota($new_usage = 0)
    {
        $today = date('Y-m-d');
        $quota_key = 'youtube_quota_usage_' . $today;
        $quota_exceeded_key = 'youtube_quota_exceeded_' . $today;
        $quota_stats_key = 'youtube_quota_stats_' . $today;

        set_transient($quota_key, $new_usage, DAY_IN_SECONDS);
        delete_transient($quota_exceeded_key);

        $quota_stats = [
            'usage' => $new_usage,
            'operations' => [],
            'reset_at' => current_time('mysql'),
            'last_update' => current_time('mysql')
        ];

        update_option($quota_stats_key, $quota_stats);
        error_log("YouTube: Quota usage manually reset to $new_usage for " . $today);

        return true;
    }

    /**
     * Get quota usage percentage
     *
     * @return float
     */
    public function get_quota_usage_percentage()
    {
        $current_usage = $this->get_current_usage();
        $daily_limit = $this->get_quota_limit();
        return ($current_usage / $daily_limit) * 100;
    }

    /**
     * Get remaining quota
     *
     * @return int
     */
    public function get_remaining_quota()
    {
        $current_usage = $this->get_current_usage();
        return $this->get_quota_limit() - $current_usage;
    }

    /**
     * Get detailed quota statistics with predictive insights
     *
     * @return array
     */
    public function get_detailed_stats()
    {
        $today = date('Y-m-d');
        $quota_stats_key = 'youtube_quota_stats_' . $today;
        $stats = get_option($quota_stats_key, [
            'usage' => 0,
            'operations' => [],
            'last_update' => null
        ]);

        $current_usage = $this->get_current_usage();
        $percentage = $this->get_quota_usage_percentage();
        $predictions = $this->get_usage_predictions();

        $daily_limit = $this->get_quota_limit();

        return [
            'current_usage' => $current_usage,
            'percentage' => $percentage,
            'limit' => $daily_limit,
            'remaining' => $daily_limit - $current_usage,
            'operations' => $stats['operations'],
            'last_update' => $stats['last_update'],
            'status' => $this->get_quota_status($percentage),
            'predictions' => $predictions,
            'historical_analysis' => $this->get_historical_analysis(),
            'optimization_suggestions' => $this->get_optimization_suggestions()
        ];
    }

    /**
     * Get quota status based on usage percentage
     *
     * @param float $percentage
     * @return string
     */
    private function get_quota_status($percentage)
    {
        if ($percentage >= self::QUOTA_THRESHOLDS['critical']) {
            return 'critical';
        } elseif ($percentage >= self::QUOTA_THRESHOLDS['high']) {
            return 'high';
        } elseif ($percentage >= self::QUOTA_THRESHOLDS['moderate']) {
            return 'moderate';
        }
        return 'normal';
    }

    /**
     * Estimate quota cost for a set of operations
     *
     * @param array $operations Array of operations to estimate cost for
     * @return array Estimated cost and whether it's safe to proceed
     */
    public function estimate_quota_cost($operations)
    {
        $total_cost = 0;
        foreach ($operations as $operation) {
            $total_cost += self::QUOTA_COSTS[$operation] ?? 1;
        }

        $current_usage = $this->get_current_usage();
        $remaining = $this->get_quota_limit() - $current_usage;
        $is_safe = ($total_cost <= $remaining);

        return [
            'estimated_cost' => $total_cost,
            'is_safe' => $is_safe,
            'remaining_quota' => $remaining
        ];
    }

    /**
     * Store historical usage data for predictive analysis
     *
     * @param string $operation
     * @param int $cost
     */
    private function store_historical_usage($operation, $cost)
    {
        $historical_key = 'youtube_quota_historical';
        $historical_data = get_option($historical_key, []);

        $today = date('Y-m-d');
        $hour = date('H');

        if (!isset($historical_data[$today])) {
            $historical_data[$today] = [];
        }

        if (!isset($historical_data[$today][$hour])) {
            $historical_data[$today][$hour] = [
                'total_usage' => 0,
                'operations' => []
            ];
        }

        $historical_data[$today][$hour]['total_usage'] += $cost;
        $historical_data[$today][$hour]['operations'][$operation] =
            ($historical_data[$today][$hour]['operations'][$operation] ?? 0) + 1;

        // Keep only last 30 days of data
        $cutoff_date = date('Y-m-d', strtotime('-' . self::HISTORICAL_DAYS . ' days'));
        foreach ($historical_data as $date => $data) {
            if ($date < $cutoff_date) {
                unset($historical_data[$date]);
            }
        }

        update_option($historical_key, $historical_data);
    }

    /**
     * Get usage predictions based on historical patterns
     *
     * @return array
     */
    public function get_usage_predictions()
    {
        if (!empty($this->prediction_cache)) {
            return $this->prediction_cache;
        }

        $historical_data = get_option('youtube_quota_historical', []);
        $current_hour = (int) date('H');
        $current_usage = $this->get_current_usage();

        // Calculate average hourly usage patterns
        $hourly_patterns = $this->calculate_hourly_patterns($historical_data);

        // Predict end-of-day usage
        $predicted_eod_usage = $current_usage;
        for ($hour = $current_hour + 1; $hour < 24; $hour++) {
            $predicted_eod_usage += $hourly_patterns[$hour] ?? 0;
        }

        // Calculate confidence based on data availability
        $confidence = $this->calculate_prediction_confidence($historical_data);

        $predictions = [
            'end_of_day_usage' => $predicted_eod_usage,
            'end_of_day_percentage' => ($predicted_eod_usage / $this->get_quota_limit()) * 100,
            'confidence' => $confidence,
            'risk_level' => $this->assess_risk_level($predicted_eod_usage),
            'recommended_actions' => $this->get_recommended_actions($predicted_eod_usage),
            'hourly_forecast' => $this->get_hourly_forecast($current_hour, $hourly_patterns)
        ];

        $this->prediction_cache = $predictions;
        return $predictions;
    }

    /**
     * Calculate hourly usage patterns from historical data
     *
     * @param array $historical_data
     * @return array
     */
    private function calculate_hourly_patterns($historical_data)
    {
        $hourly_totals = array_fill(0, 24, 0);
        $hourly_counts = array_fill(0, 24, 0);

        foreach ($historical_data as $date => $day_data) {
            foreach ($day_data as $hour => $hour_data) {
                // Convert hour string to integer to handle leading zeros (e.g., "07" -> 7)
                $hour_int = (int) $hour;

                // Validate hour is within expected range
                if ($hour_int >= 0 && $hour_int < 24 && isset($hour_data['total_usage'])) {
                    $hourly_totals[$hour_int] += $hour_data['total_usage'];
                    $hourly_counts[$hour_int]++;
                }
            }
        }

        // Calculate averages
        $hourly_patterns = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hourly_patterns[$hour] = $hourly_counts[$hour] > 0
                ? $hourly_totals[$hour] / $hourly_counts[$hour]
                : 0;
        }

        return $hourly_patterns;
    }

    /**
     * Calculate prediction confidence based on historical data availability
     *
     * @param array $historical_data
     * @return float
     */
    private function calculate_prediction_confidence($historical_data)
    {
        $total_days = count($historical_data);
        $max_confidence_days = 14; // Need at least 2 weeks for high confidence

        if ($total_days >= $max_confidence_days) {
            return 0.9;
        } elseif ($total_days >= 7) {
            return 0.7;
        } elseif ($total_days >= 3) {
            return 0.5;
        } else {
            return 0.3;
        }
    }

    /**
     * Assess risk level based on predicted usage
     *
     * @param int $predicted_usage
     * @return string
     */
    private function assess_risk_level($predicted_usage)
    {
        $percentage = ($predicted_usage / $this->get_quota_limit()) * 100;

        if ($percentage >= 95) {
            return 'critical';
        } elseif ($percentage >= 85) {
            return 'high';
        } elseif ($percentage >= 70) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Get recommended actions based on predicted usage
     *
     * @param int $predicted_usage
     * @return array
     */
    private function get_recommended_actions($predicted_usage)
    {
        $percentage = ($predicted_usage / $this->get_quota_limit()) * 100;
        $actions = [];

        if ($percentage >= 95) {
            $actions[] = 'Immediately restrict all non-essential operations';
            $actions[] = 'Enable emergency quota conservation mode';
            $actions[] = 'Consider postponing scheduled content updates';
        } elseif ($percentage >= 85) {
            $actions[] = 'Reduce frequency of search operations';
            $actions[] = 'Prioritize high-value content fetching';
            $actions[] = 'Enable quota conservation mode';
        } elseif ($percentage >= 70) {
            $actions[] = 'Monitor usage closely';
            $actions[] = 'Consider optimizing batch sizes';
            $actions[] = 'Prepare quota conservation strategies';
        } else {
            $actions[] = 'Continue normal operations';
            $actions[] = 'Monitor for unusual usage spikes';
        }

        return $actions;
    }

    /**
     * Get hourly forecast for remaining day
     *
     * @param int $current_hour
     * @param array $hourly_patterns
     * @return array
     */
    private function get_hourly_forecast($current_hour, $hourly_patterns)
    {
        $forecast = [];
        $cumulative_usage = $this->get_current_usage();

        for ($hour = $current_hour + 1; $hour < 24; $hour++) {
            $predicted_usage = $hourly_patterns[$hour] ?? 0;
            $cumulative_usage += $predicted_usage;

            $forecast[] = [
                'hour' => $hour,
                'predicted_usage' => $predicted_usage,
                'cumulative_usage' => $cumulative_usage,
                'percentage' => ($cumulative_usage / $this->get_quota_limit()) * 100
            ];
        }

        return $forecast;
    }

    /**
     * Check if operation is within predicted budget
     *
     * @param string $operation
     * @param int $current_usage
     * @return bool
     */
    private function is_operation_within_predicted_budget($operation, $current_usage)
    {
        $predictions = $this->get_usage_predictions();
        $operation_cost = self::QUOTA_COSTS[$operation] ?? 1;

        // If confidence is low, allow operation
        if ($predictions['confidence'] < self::PREDICTION_ACCURACY_THRESHOLD) {
            return true;
        }

        // Check if adding this operation would push us over the safe threshold
        $safe_threshold = $this->get_quota_limit() * (1 - self::QUOTA_BUFFER_PERCENTAGE / 100);
        $predicted_with_operation = $predictions['end_of_day_usage'] + $operation_cost;

        return $predicted_with_operation <= $safe_threshold;
    }

    /**
     * Get historical analysis insights
     *
     * @return array
     */
    public function get_historical_analysis()
    {
        $historical_data = get_option('youtube_quota_historical', []);

        if (empty($historical_data)) {
            return ['message' => 'Insufficient historical data for analysis'];
        }

        $daily_totals = [];
        $peak_hours = [];
        $operation_trends = [];

        foreach ($historical_data as $date => $day_data) {
            $daily_total = 0;
            foreach ($day_data as $hour => $hour_data) {
                // Ensure hour_data has the expected structure
                if (!is_array($hour_data) || !isset($hour_data['total_usage'])) {
                    continue;
                }

                $daily_total += $hour_data['total_usage'];

                // Track peak hours
                if (!isset($peak_hours[$hour])) {
                    $peak_hours[$hour] = 0;
                }
                $peak_hours[$hour] += $hour_data['total_usage'];

                // Track operation trends
                if (isset($hour_data['operations']) && is_array($hour_data['operations'])) {
                    foreach ($hour_data['operations'] as $operation => $count) {
                        if (!isset($operation_trends[$operation])) {
                            $operation_trends[$operation] = 0;
                        }
                        $operation_trends[$operation] += $count;
                    }
                }
            }
            $daily_totals[] = $daily_total;
        }

        return [
            'average_daily_usage' => !empty($daily_totals) ? array_sum($daily_totals) / count($daily_totals) : 0,
            'peak_usage_day' => !empty($daily_totals) ? max($daily_totals) : 0,
            'lowest_usage_day' => !empty($daily_totals) ? min($daily_totals) : 0,
            'peak_hours' => !empty($peak_hours) ? array_keys($peak_hours, max($peak_hours)) : [],
            'most_used_operations' => !empty($operation_trends) ? array_keys($operation_trends, max($operation_trends)) : [],
            'usage_trend' => $this->calculate_usage_trend($daily_totals),
            'efficiency_score' => $this->calculate_efficiency_score($daily_totals)
        ];
    }

    /**
     * Calculate usage trend
     *
     * @param array $daily_totals
     * @return string
     */
    private function calculate_usage_trend($daily_totals)
    {
        if (count($daily_totals) < 7) {
            return 'insufficient_data';
        }

        $recent_avg = array_sum(array_slice($daily_totals, -7)) / 7;
        $older_avg = array_sum(array_slice($daily_totals, 0, 7)) / 7;

        $change_percentage = (($recent_avg - $older_avg) / $older_avg) * 100;

        if ($change_percentage > 10) {
            return 'increasing';
        } elseif ($change_percentage < -10) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Calculate efficiency score based on quota utilization
     *
     * @param array $daily_totals
     * @return float
     */
    private function calculate_efficiency_score($daily_totals)
    {
        $avg_usage = array_sum($daily_totals) / count($daily_totals);
        $utilization_rate = ($avg_usage / $this->get_quota_limit()) * 100;

        // Optimal utilization is around 60-80%
        if ($utilization_rate >= 60 && $utilization_rate <= 80) {
            return 1.0; // Perfect efficiency
        } elseif ($utilization_rate < 60) {
            return $utilization_rate / 60; // Under-utilization penalty
        } else {
            return max(0.1, 1 - (($utilization_rate - 80) / 20)); // Over-utilization penalty
        }
    }

    /**
     * Get optimization suggestions based on analysis
     *
     * @return array
     */
    public function get_optimization_suggestions()
    {
        $analysis = $this->get_historical_analysis();
        $predictions = $this->get_usage_predictions();
        $suggestions = [];

        if (isset($analysis['efficiency_score']) && $analysis['efficiency_score'] < 0.7) {
            $suggestions[] = 'Consider optimizing API request patterns to improve efficiency';
        }

        if (isset($analysis['usage_trend']) && $analysis['usage_trend'] === 'increasing') {
            $suggestions[] = 'Usage trend is increasing - consider implementing more aggressive caching';
        }

        if ($predictions['risk_level'] === 'high' || $predictions['risk_level'] === 'critical') {
            $suggestions[] = 'High quota risk detected - enable predictive quota conservation';
        }

        if (isset($analysis['peak_hours']) && count($analysis['peak_hours']) <= 2) {
            $suggestions[] = 'Consider distributing API requests more evenly throughout the day';
        }

        return $suggestions;
    }

    /**
     * Enable predictive quota conservation mode
     *
     * @return bool
     */
    public function enable_quota_conservation()
    {
        $conservation_key = 'youtube_quota_conservation_' . date('Y-m-d');
        set_transient($conservation_key, true, DAY_IN_SECONDS);

        error_log('YouTube: Predictive quota conservation mode enabled');
        return true;
    }

    /**
     * Check if quota conservation mode is active
     *
     * @return bool
     */
    public function is_quota_conservation_active()
    {
        $conservation_key = 'youtube_quota_conservation_' . date('Y-m-d');
        return (bool) get_transient($conservation_key);
    }
}