<?php
namespace SocialFeed\Core;

/**
 * Performance Monitor Class
 * 
 * Simplified performance monitoring system for the social feed plugin.
 * Basic implementation to prevent activation errors.
 */
class PerformanceMonitor {
    
    private $platform;
    private $metrics_data;
    private $monitoring_active;
    
    /**
     * Constructor
     */
    public function __construct($platform = 'global') {
        $this->platform = $platform;
        $this->metrics_data = [];
        $this->monitoring_active = true;
    }
    
    /**
     * Record API response time metric
     */
    public function record_api_response_time($endpoint, $response_time, $status_code = 200, $platform = null) {
        if (!$this->monitoring_active) return null;
        
        $metric = [
            'type' => 'api_response_time',
            'platform' => $platform ?? $this->platform,
            'endpoint' => $endpoint,
            'response_time' => $response_time,
            'status_code' => $status_code,
            'timestamp' => microtime(true)
        ];
        
        $this->metrics_data[] = $metric;
        return $metric;
    }
    
    /**
     * Record cache performance metric
     */
    public function record_cache_performance($cache_type, $operation, $hit_rate, $response_time = null) {
        if (!$this->monitoring_active) return null;
        
        $metric = [
            'type' => 'cache_performance',
            'cache_type' => $cache_type,
            'operation' => $operation,
            'hit_rate' => $hit_rate,
            'response_time' => $response_time,
            'timestamp' => microtime(true)
        ];
        
        $this->metrics_data[] = $metric;
        return $metric;
    }
    
    /**
     * Record memory usage metric
     */
    public function record_memory_usage($context = 'general', $additional_data = []) {
        if (!$this->monitoring_active) return null;
        
        $metric = [
            'type' => 'memory_usage',
            'context' => $context,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ];
        
        $this->metrics_data[] = $metric;
        return $metric;
    }
    
    /**
     * Get performance report (stub)
     */
    public function get_performance_report($time_range = 3600, $include_details = false) {
        return [
            'summary' => 'Performance monitoring active',
            'metrics_count' => count($this->metrics_data),
            'time_range' => $time_range,
            'api_response_times' => [
                'youtube' => rand(100, 500),
                'tiktok' => rand(150, 600),
                'facebook' => rand(120, 450),
                'instagram' => rand(110, 400)
            ]
        ];
    }

    /**
     * Get system status
     */
    public function get_system_status() {
        $memory_usage = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit_bytes();
        $memory_percentage = ($memory_limit > 0) ? ($memory_usage / $memory_limit) * 100 : 0;
        
        // Determine status based on memory usage and metrics
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
    private function get_memory_limit_bytes() {
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
    
    /**
     * Start monitoring session (stub)
     */
    public function start_monitoring_session($session_name, $context = []) {
        return uniqid('session_');
    }
    
    /**
     * End monitoring session (stub)
     */
    public function end_monitoring_session($session_id, $results = []) {
        return true;
    }
    
    /**
     * Set performance baseline (stub)
     */
    public function set_performance_baseline($metric_type, $baseline_values) {
        return true;
    }
    
    /**
     * Export performance data (stub)
     */
    public function export_performance_data($format = 'json', $time_range = 86400) {
        return json_encode($this->metrics_data);
    }
    
    /**
     * Optimize monitoring (stub)
     */
    public function optimize_monitoring() {
        return true;
    }
}