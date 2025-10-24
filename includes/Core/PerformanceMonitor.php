<?php
namespace SocialFeed\Core;

class PerformanceMonitor {
    /**
     * Performance thresholds
     */
    const THRESHOLDS = [
        'api_response_time' => 2.0,    // seconds
        'memory_usage' => 64,          // MB
        'error_rate' => 5.0,           // percentage
        'cache_hit_rate' => 80.0       // percentage
    ];

    /**
     * Monitoring metrics
     */
    private $metrics = [
        'api_calls' => [],
        'errors' => [],
        'memory_usage' => [],
        'execution_times' => [],
        'cache_stats' => []
    ];

    private $start_time;
    private $last_checkpoint;
    private $enabled;

    /**
     * Constructor
     */
    public function __construct() {
        $this->start_time = microtime(true);
        $this->last_checkpoint = $this->start_time;
        $this->enabled = true;
        
        // Register shutdown function to save metrics
        register_shutdown_function([$this, 'save_metrics']);
    }

    /**
     * Start monitoring an operation
     */
    public function start_operation($operation) {
        if (!$this->enabled) return null;

        $operation_id = uniqid($operation . '_');
        $this->metrics['execution_times'][$operation_id] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ];

        return $operation_id;
    }

    /**
     * End monitoring an operation
     */
    public function end_operation($operation_id) {
        if (!$this->enabled || empty($operation_id)) return;

        if (isset($this->metrics['execution_times'][$operation_id])) {
            $end_time = microtime(true);
            $memory_end = memory_get_usage(true);
            
            $this->metrics['execution_times'][$operation_id] += [
                'end_time' => $end_time,
                'duration' => $end_time - $this->metrics['execution_times'][$operation_id]['start_time'],
                'memory_used' => $memory_end - $this->metrics['execution_times'][$operation_id]['memory_start']
            ];

            // Check for performance issues
            $this->check_performance_issues($operation_id);
        }
    }

    /**
     * Track API call
     */
    public function track_api_call($endpoint, $response_time, $success) {
        if (!$this->enabled) return;

        $this->metrics['api_calls'][] = [
            'endpoint' => $endpoint,
            'response_time' => $response_time,
            'success' => $success,
            'timestamp' => microtime(true)
        ];

        // Alert if response time is too high
        if ($response_time > self::THRESHOLDS['api_response_time']) {
            $this->log_issue('api_slow_response', [
                'endpoint' => $endpoint,
                'response_time' => $response_time
            ]);
        }
    }

    /**
     * Track error
     */
    public function track_error($type, $message, $context = []) {
        if (!$this->enabled) return;

        $this->metrics['errors'][] = [
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        // Calculate error rate
        $total_operations = count($this->metrics['execution_times']);
        $error_rate = ($total_operations > 0) 
            ? (count($this->metrics['errors']) / $total_operations) * 100 
            : 0;

        if ($error_rate > self::THRESHOLDS['error_rate']) {
            $this->log_issue('high_error_rate', [
                'rate' => $error_rate,
                'threshold' => self::THRESHOLDS['error_rate']
            ]);
        }
    }

    /**
     * Track memory usage
     */
    public function track_memory() {
        if (!$this->enabled) return;

        $memory_usage = memory_get_usage(true) / 1024 / 1024; // Convert to MB
        $this->metrics['memory_usage'][] = [
            'usage' => $memory_usage,
            'timestamp' => microtime(true)
        ];

        if ($memory_usage > self::THRESHOLDS['memory_usage']) {
            $this->log_issue('high_memory_usage', [
                'usage' => $memory_usage,
                'threshold' => self::THRESHOLDS['memory_usage']
            ]);
        }
    }

    /**
     * Track cache statistics
     */
    public function track_cache_stats($hits, $misses) {
        if (!$this->enabled) return;

        $total = $hits + $misses;
        $hit_rate = ($total > 0) ? ($hits / $total) * 100 : 0;

        $this->metrics['cache_stats'][] = [
            'hits' => $hits,
            'misses' => $misses,
            'hit_rate' => $hit_rate,
            'timestamp' => microtime(true)
        ];

        if ($hit_rate < self::THRESHOLDS['cache_hit_rate']) {
            $this->log_issue('low_cache_hit_rate', [
                'rate' => $hit_rate,
                'threshold' => self::THRESHOLDS['cache_hit_rate']
            ]);
        }
    }

    /**
     * Get performance report
     */
    public function get_report() {
        if (!$this->enabled) return null;

        return [
            'duration' => microtime(true) - $this->start_time,
            'memory_peak' => memory_get_peak_usage(true) / 1024 / 1024,
            'api_calls' => count($this->metrics['api_calls']),
            'errors' => count($this->metrics['errors']),
            'operations' => $this->summarize_operations(),
            'issues' => $this->get_issues()
        ];
    }

    /**
     * Private helper methods
     */
    private function check_performance_issues($operation_id) {
        $operation = $this->metrics['execution_times'][$operation_id];
        
        // Check execution time
        if ($operation['duration'] > self::THRESHOLDS['api_response_time']) {
            $this->log_issue('slow_operation', [
                'operation' => $operation['operation'],
                'duration' => $operation['duration']
            ]);
        }

        // Check memory usage
        $memory_mb = $operation['memory_used'] / 1024 / 1024;
        if ($memory_mb > self::THRESHOLDS['memory_usage']) {
            $this->log_issue('high_operation_memory', [
                'operation' => $operation['operation'],
                'memory_mb' => $memory_mb
            ]);
        }
    }

    private function log_issue($type, $data) {
        $issue = [
            'type' => $type,
            'data' => $data,
            'timestamp' => microtime(true)
        ];

        // Store issue
        update_option('social_feed_performance_issues', 
            array_merge(
                get_option('social_feed_performance_issues', []),
                [$issue]
            )
        );

        // Log issue
        error_log("Performance Issue: $type - " . json_encode($data));
    }

    private function summarize_operations() {
        $summary = [];
        foreach ($this->metrics['execution_times'] as $operation) {
            $name = $operation['operation'];
            if (!isset($summary[$name])) {
                $summary[$name] = [
                    'count' => 0,
                    'total_time' => 0,
                    'total_memory' => 0
                ];
            }
            $summary[$name]['count']++;
            $summary[$name]['total_time'] += $operation['duration'];
            $summary[$name]['total_memory'] += $operation['memory_used'];
        }
        return $summary;
    }

    private function get_issues() {
        return get_option('social_feed_performance_issues', []);
    }

    /**
     * Save metrics on shutdown
     */
    public function save_metrics() {
        if (!$this->enabled) return;

        $metrics = [
            'timestamp' => time(),
            'data' => $this->metrics
        ];

        // Keep last 24 hours of metrics
        $history = get_option('social_feed_performance_metrics', []);
        $history = array_filter($history, function($entry) {
            return $entry['timestamp'] > (time() - 86400);
        });
        $history[] = $metrics;

        update_option('social_feed_performance_metrics', $history);
    }
} 