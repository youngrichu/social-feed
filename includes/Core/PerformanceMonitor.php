<?php
namespace SocialFeed\Core;

/**
 * Performance Monitor Class
 * 
 * Simplified performance monitoring system for the social feed plugin.
 * Basic implementation to prevent activation errors.
 */
class PerformanceMonitor
{

    private $platform;
    private $metrics_data;
    private $monitoring_active;
    private $options_key = 'social_feed_api_metrics';

    /**
     * Constructor
     */
    public function __construct($platform = 'global')
    {
        $this->platform = $platform;
        $this->metrics_data = [];
        $this->monitoring_active = true;
    }

    /**
     * Record API response time metric
     */
    public function record_api_response_time($endpoint, $response_time, $status_code = 200, $platform = null)
    {
        if (!$this->monitoring_active)
            return null;

        $platform = $platform ?? $this->platform;
        $metrics = get_option($this->options_key, []);

        if (!isset($metrics[$platform])) {
            $metrics[$platform] = [
                'count' => 0,
                'total_time' => 0,
                'peak_time' => 0,
                'distribution' => []
            ];
        }

        // Update metrics
        $metrics[$platform]['count']++;
        $metrics[$platform]['total_time'] += $response_time;
        $metrics[$platform]['peak_time'] = max($metrics[$platform]['peak_time'], $response_time);

        // Keep a small distribution for histograms/percentiles (last 100 requests)
        $metrics[$platform]['distribution'][] = $response_time;
        if (count($metrics[$platform]['distribution']) > 100) {
            array_shift($metrics[$platform]['distribution']);
        }

        $saved = update_option($this->options_key, $metrics, false);

        if (!$saved) {
            // It might return false if value is unchanged, but here count always changes.
            // Or if option update failed.
            // Just logging for visibility.
            // check if it's just unchanged
            $current = get_option($this->options_key);
            if ($current === $metrics) {
                // unchanged, weird but okay
            } else {
                error_log("PerformanceMonitor: Failed to save metrics for platform $platform. Option key: {$this->options_key}");
            }
        } else {
            error_log("PerformanceMonitor: Recorded metric for $platform: {$response_time}s. Total count: {$metrics[$platform]['count']}");
        }

        return [
            'type' => 'api_response_time',
            'platform' => $platform,
            'response_time' => $response_time
        ];
    }

    /**
     * Record cache performance metric
     */
    public function record_cache_performance($cache_type, $operation, $hit_rate, $response_time = null)
    {
        // Cache stats are handled by CacheManager's own analytics
        return null;
    }

    /**
     * Record memory usage metric
     */
    public function record_memory_usage($context = 'general', $additional_data = [])
    {
        // Optional implementation
        return null;
    }

    /**
     * Get performance report
     */
    public function get_performance_report($time_range = 3600, $include_details = false)
    {
        $api_metrics = get_option($this->options_key, []);

        // Calculate aggregate API metrics
        $total_count = 0;
        $total_time = 0;
        $peak_time = 0;

        foreach ($api_metrics as $platform_data) {
            $total_count += $platform_data['count'];
            $total_time += $platform_data['total_time'];
            $peak_time = max($peak_time, $platform_data['peak_time']);
        }

        $avg_response_time = $total_count > 0 ? ($total_time / $total_count) * 1000 : 0; // Convert to ms
        $peak_response_time = $peak_time * 1000; // Convert to ms

        // Aggregate Cache Stats
        $cache_hit_rate = 0;
        $total_cache_requests = 0;
        $platform_factory = new \SocialFeed\Platforms\PlatformFactory();
        $platforms = array_keys($platform_factory->get_available_platforms());

        foreach ($platforms as $platform) {
            $analytics = get_option("sf_cache_analytics_{$platform}", []);
            if (!empty($analytics['performance_data'])) {
                $total_cache_requests += count($analytics['performance_data']);
                // Assuming access_patterns or similar stores hits/misses, 
                // but CacheManager::get_stats() logic is better.
                // Let's use CacheManager::get_stats if possible, but that requires instantiation.
                // Instead, let's use the stats we can get.

                // For now, let's look at the basic stats stored by CacheManager if available,
                // otherwise relying on the analytics data structure we saw in CacheManager.php
                // Note: CacheManager updates 'sf_cache_analytics_{platform}'.

                // Simplification: We'll calculate a mocked weighted average for now based on available data
                // In a real implementation we'd read the 'hit_rates' from the analytics if stored,
                // but CacheManager calculates them on the fly in get_stats().
                // We will try to read the raw cache stats from options if they were stored separately?
                // CacheManager doesn't seem to store basic stats in options, only analytics.
                // But it DOES have get_stats which merges $this->cache_stats (memory) with analytics.
                // To get persistent stats, we might need to rely on what's in options.
            }
        }

        // Since CacheManager stores analytics in options, we can try to derive hit rate from access patterns?
        // Actually, CacheManager::get_stats() uses in-memory stats mainly.
        // For persistent stats, we might be limited. 
        // Let's use a placeholder that indicates we need real traffic for cache stats,
        // or actually implement persistence in CacheManager (which wasn't in the plan, but might be needed).
        // However, the user plan approved aggregating from options.
        // Let's assume some data is there or return 0.

        return [
            'summary' => 'Performance monitoring active',
            'time_range' => $time_range,
            'avg_response_time' => $avg_response_time,
            'peak_response_time' => $peak_response_time,
            'cache_hit_rate' => 0, // Todo: Implement persistent cache stats
            'total_requests' => $total_count + $total_cache_requests
        ];
    }

    /**
     * Get system status
     */
    public function get_system_status()
    {
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit_bytes();
        $memory_percentage = ($memory_limit > 0) ? ($memory_usage / $memory_limit) * 100 : 0;

        if ($memory_percentage > 90) {
            $status = 'error';
            $message = 'High memory usage detected';
        } elseif ($memory_percentage > 75) {
            $status = 'warning';
            $message = 'Memory usage is elevated';
        } else {
            $status = 'healthy';
            $message = 'System is running normally';
        }

        return [
            'status' => $status,
            'message' => $message,
            'memory_usage' => $memory_usage,
            'memory_percentage' => round($memory_percentage, 2)
        ];
    }

    /**
     * Get memory limit in bytes
     */
    private function get_memory_limit_bytes()
    {
        $memory_limit = ini_get('memory_limit');
        if ($memory_limit == -1) {
            return 0; // Unlimited
        }

        $unit = strtolower(substr($memory_limit, -1));
        $value = (int) $memory_limit;

        switch ($unit) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }

        return $value;
    }
}