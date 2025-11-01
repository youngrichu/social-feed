<?php
namespace SocialFeed\Core;

/**
 * Mock Performance Monitor for testing purposes
 */
class PerformanceMonitor {
    private $metrics = [];
    private $sessions = [];
    
    public function __construct($platform = 'global') {
        // Initialize with default metrics
        $this->metrics = [
            'cache_hits' => 0,
            'cache_misses' => 0,
            'api_calls' => 0,
            'response_times' => []
        ];
        $this->sessions = [];
    }
    
    public function start_timer($operation) {
        $this->metrics['timers'][$operation] = microtime(true);
    }
    
    public function end_timer($operation) {
        if (isset($this->metrics['timers'][$operation])) {
            $duration = microtime(true) - $this->metrics['timers'][$operation];
            $this->metrics['response_times'][$operation] = $duration;
            unset($this->metrics['timers'][$operation]);
            return $duration;
        }
        return 0;
    }
    
    public function record_cache_hit() {
        $this->metrics['cache_hits']++;
    }
    
    public function record_cache_miss() {
        $this->metrics['cache_misses']++;
    }
    
    public function record_api_call() {
        $this->metrics['api_calls']++;
    }
    
    public function get_metrics() {
        return $this->metrics;
    }
    
    public function get_cache_hit_rate() {
        $total = $this->metrics['cache_hits'] + $this->metrics['cache_misses'];
        return $total > 0 ? ($this->metrics['cache_hits'] / $total) * 100 : 0;
    }
    
    public function get_average_response_time() {
        if (empty($this->metrics['response_times'])) {
            return 0;
        }
        return array_sum($this->metrics['response_times']) / count($this->metrics['response_times']);
    }
    
    public function reset_metrics() {
        $this->metrics = [
            'cache_hits' => 0,
            'cache_misses' => 0,
            'api_calls' => 0,
            'response_times' => []
        ];
    }
    
    public function start_monitoring_session($session_id, $context = []) {
        $this->sessions[$session_id] = [
            'start_time' => microtime(true),
            'context' => $context
        ];
        return $session_id;
    }
    
    public function end_monitoring_session($session_id, $results = []) {
        if (isset($this->sessions[$session_id])) {
            $duration = microtime(true) - $this->sessions[$session_id]['start_time'];
            $this->sessions[$session_id]['duration'] = $duration;
            $this->sessions[$session_id]['results'] = $results;
            return $duration;
        }
        return 0;
    }
    
    public function record_error($type, $message, $context = []) {
        if (!isset($this->metrics['errors'])) {
            $this->metrics['errors'] = [];
        }
        $this->metrics['errors'][] = [
            'type' => $type,
            'message' => $message,
            'context' => $context,
            'timestamp' => microtime(true)
        ];
    }
    
    public function record_throughput($operation, $count, $timestamp) {
        if (!isset($this->metrics['throughput'])) {
            $this->metrics['throughput'] = [];
        }
        $this->metrics['throughput'][$operation] = [
            'count' => $count,
            'timestamp' => $timestamp
        ];
    }
}