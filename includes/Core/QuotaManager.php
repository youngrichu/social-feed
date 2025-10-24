<?php
namespace SocialFeed\Core;

class QuotaManager {
    /**
     * YouTube API quota limits
     */
    const QUOTA_LIMIT_PER_DAY = 10000;
    const QUOTA_COSTS = [
        'search' => 100,
        'videos' => 1,
        'channels' => 1,
        'playlistItems' => 1
    ];

    /**
     * Operation priorities
     */
    const OPERATION_PRIORITY = [
        'high' => ['videos', 'playlistItems'],  // Essential operations
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
     * Get current quota usage
     *
     * @return int
     */
    public function get_current_usage() {
        $quota_key = 'youtube_quota_usage_' . date('Y-m-d');
        return get_transient($quota_key) ?: 0;
    }

    /**
     * Check if operation would exceed quota with smart prioritization
     *
     * @param string $operation
     * @return bool
     */
    public function check_quota($operation) {
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
        $current_usage = (int)get_transient($quota_key) ?: 0;
        $operation_cost = self::QUOTA_COSTS[$operation] ?? 1;
        $quota_stats = get_option($quota_stats_key, ['operations' => []]);

        // Get operation priority
        $priority = $this->get_operation_priority($operation);
        
        // Calculate quota percentage
        $quota_percentage = ($current_usage / self::QUOTA_LIMIT_PER_DAY) * 100;

        // Apply adaptive rate limiting based on quota usage
        if ($quota_percentage >= self::QUOTA_THRESHOLDS['critical']) {
            // In critical mode, only allow high-priority operations
            if ($priority !== 'high') {
                error_log("YouTube: Operation blocked due to critical quota usage: $operation (Priority: $priority)");
                return false;
            }
        } elseif ($quota_percentage >= self::QUOTA_THRESHOLDS['high']) {
            // In high-restriction mode, block low-priority operations
            if ($priority === 'low') {
                error_log("YouTube: Low-priority operation blocked due to high quota usage: $operation");
                return false;
            }
        }

        // Check if operation would exceed quota
        if (($current_usage + $operation_cost) >= self::QUOTA_LIMIT_PER_DAY) {
            error_log("YouTube: Daily quota would be exceeded. Current: $current_usage, Operation cost: $operation_cost");
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

        error_log("YouTube: Updated quota usage to $new_usage units for $today (Operation: $operation, Priority: $priority)");
        
        return true;
    }

    /**
     * Get operation priority
     *
     * @param string $operation
     * @return string
     */
    private function get_operation_priority($operation) {
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
    public function reset_quota() {
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
     * Get quota usage percentage
     *
     * @return float
     */
    public function get_quota_usage_percentage() {
        $current_usage = $this->get_current_usage();
        return ($current_usage / self::QUOTA_LIMIT_PER_DAY) * 100;
    }

    /**
     * Get detailed quota statistics
     *
     * @return array
     */
    public function get_detailed_stats() {
        $today = date('Y-m-d');
        $quota_stats_key = 'youtube_quota_stats_' . $today;
        $stats = get_option($quota_stats_key, [
            'usage' => 0,
            'operations' => [],
            'last_update' => null
        ]);

        $current_usage = $this->get_current_usage();
        $percentage = $this->get_quota_usage_percentage();

        return [
            'current_usage' => $current_usage,
            'percentage' => $percentage,
            'limit' => self::QUOTA_LIMIT_PER_DAY,
            'remaining' => self::QUOTA_LIMIT_PER_DAY - $current_usage,
            'operations' => $stats['operations'],
            'last_update' => $stats['last_update'],
            'status' => $this->get_quota_status($percentage)
        ];
    }

    /**
     * Get quota status based on usage percentage
     *
     * @param float $percentage
     * @return string
     */
    private function get_quota_status($percentage) {
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
    public function estimate_quota_cost($operations) {
        $total_cost = 0;
        foreach ($operations as $operation) {
            $total_cost += self::QUOTA_COSTS[$operation] ?? 1;
        }

        $current_usage = $this->get_current_usage();
        $remaining = self::QUOTA_LIMIT_PER_DAY - $current_usage;
        $is_safe = ($total_cost <= $remaining);

        return [
            'estimated_cost' => $total_cost,
            'is_safe' => $is_safe,
            'remaining_quota' => $remaining
        ];
    }
} 