<?php
namespace SocialFeed\Core;

class CacheManager
{
    /**
     * Cache configuration
     */
    const CACHE_CONFIG = [
        'metadata' => [
            'ttl' => 3600,      // 1 hour
            'prefix' => 'sf_meta_'
        ],
        'content' => [
            'ttl' => 86400,     // 24 hours
            'prefix' => 'sf_content_'
        ],
        'api' => [
            'ttl' => 300,       // 5 minutes
            'prefix' => 'sf_api_'
        ],
        'search' => [
            'ttl' => 1800,      // 30 minutes
            'prefix' => 'sf_search_'
        ]
    ];

    /**
     * Cache priority levels
     */
    const CACHE_PRIORITY = [
        'critical' => 1,    // Must be cached
        'high' => 2,        // Should be cached
        'normal' => 3,      // Can be cached
        'low' => 4         // Cache if space available
    ];

    /**
     * Advanced caching constants
     */
    const CACHE_WARMING_BATCH_SIZE = 10;
    const PREFETCH_THRESHOLD = 0.7; // Prefetch when 70% of TTL has passed
    const CACHE_ANALYTICS_RETENTION = 7; // Keep analytics for 7 days
    const INTELLIGENT_WARMING_INTERVAL = 300; // 5 minutes

    private $platform;
    private $cache_stats;
    private $memcache_available;
    private $redis_available;
    private $cache_analytics;
    private $prefetch_queue;
    private $warming_patterns;

    /**
     * Constructor
     */
    public function __construct($platform)
    {
        $this->platform = $platform;
        $this->cache_stats = [];
        $this->cache_analytics = [];
        $this->prefetch_queue = [];
        $this->warming_patterns = null;

        // Check available caching systems
        $this->memcache_available = class_exists('Memcache') || class_exists('Memcached');
        $this->redis_available = class_exists('Redis');

        $this->init_cache_stats();
        $this->init_cache_analytics();
        $this->schedule_intelligent_warming();
    }

    /**
     * Get item from cache with intelligent fallback and warming
     */
    public function get($key, $type = 'content')
    {
        try {
            $start_time = microtime(true);
            $cache_key = $this->build_cache_key($key, $type);

            // Try memory cache first (fastest)
            if ($this->memcache_available || $this->redis_available) {
                $data = wp_cache_get($cache_key, $this->platform);
                if ($data !== false) {
                    // Validate cache data integrity
                    if ($this->validate_cache_integrity($data)) {
                        $this->update_cache_stats('hit', $type);
                        $this->record_cache_performance($key, $type, 'memory', microtime(true) - $start_time);

                        $this->check_prefetch_opportunity($key, $type, $data);
                        return $data['data'] ?? $data;
                    } else {
                        // Remove corrupted data
                        wp_cache_delete($cache_key, $this->platform);
                    }
                }
            }

            // Try WordPress transients (medium speed)
            $data = \get_transient($cache_key);
            if ($data !== false) {
                // Validate cache data integrity
                if ($this->validate_cache_integrity($data)) {
                    $this->update_cache_stats('hit', $type);
                    $this->record_cache_performance($key, $type, 'transient', microtime(true) - $start_time);

                    // Warm up memory cache
                    if ($this->memcache_available || $this->redis_available) {
                        wp_cache_set($cache_key, $data, $this->platform, self::CACHE_CONFIG[$type]['ttl']);
                    }

                    $this->check_prefetch_opportunity($key, $type, $data);
                    return $data['data'] ?? $data;
                } else {
                    // Remove corrupted data
                    \delete_transient($cache_key);
                }
            }

            // Try database cache
            $data = $this->get_from_db_cache($cache_key, $type);
            if ($data !== false) {
                // Validate cache data integrity
                if ($this->validate_cache_integrity($data)) {
                    $this->update_cache_stats('hit', $type);
                    $this->record_cache_performance($key, $type, 'database', microtime(true) - $start_time);

                    // Warm up higher level caches
                    $this->warm_up_cache($cache_key, $data, $type);
                    $this->check_prefetch_opportunity($key, $type, $data);
                    return $data['data'] ?? $data;
                } else {
                    // Remove corrupted data
                    $this->delete_from_db_cache($cache_key);
                }
            }

            $this->update_cache_stats('miss', $type);
            $this->record_cache_performance($key, $type, 'miss', microtime(true) - $start_time);

            // Add to prefetch queue for future warming
            $this->add_to_prefetch_queue($key, $type);

            return null;

        } catch (\Exception $e) {
            error_log("CacheManager get error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Store item in cache with intelligent layer selection and warming
     */
    public function set($key, $data, $type = 'content', $priority = 'normal')
    {
        try {
            $cache_key = $this->build_cache_key($key, $type);
            $ttl = self::CACHE_CONFIG[$type]['ttl'];

            // Add data validation
            if (!$this->validate_cache_data($data)) {
                error_log("CacheManager: Invalid data format for key: {$cache_key}");
                return false;
            }

            // Add metadata for reliability tracking
            $cache_data = [
                'data' => $data,
                'timestamp' => time(),
                'version' => '1.0',
                'checksum' => md5(serialize($data)),
                'priority' => $priority
            ];

            $success = false;

            // Store in all available cache layers based on priority
            if ($priority === 'critical' || $priority === 'high') {
                // Store in all layers for high priority items
                if ($this->memcache_available || $this->redis_available) {
                    wp_cache_set($cache_key, $cache_data, $this->platform, $ttl);
                }
                if (\set_transient($cache_key, $cache_data, $ttl)) {
                    $success = true;
                }
                $this->store_in_db_cache($cache_key, $cache_data, $type, $ttl);
            } else {
                // Store selectively for normal/low priority
                if ($this->memcache_available || $this->redis_available) {
                    wp_cache_set($cache_key, $cache_data, $this->platform, $ttl);
                }
                if (\set_transient($cache_key, $cache_data, $ttl)) {
                    $success = true;
                }
            }

            // Fallback to database if primary methods fail
            if (!$success) {
                $success = $this->store_in_db_cache($cache_key, $cache_data, $type, $ttl);
            }

            if ($success) {
                // Record cache set operation
                $this->record_cache_set($key, $type, $priority);
                $this->update_cache_stats('set', $type);

                // Schedule intelligent warming for related content
                $this->schedule_related_warming($key, $type, $data);
            } else {
                error_log("CacheManager: Failed to store cache key: {$cache_key}");
            }

            return $success;

        } catch (Exception $e) {
            error_log("CacheManager set error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete item from all cache layers
     */
    public function delete($key, $type = 'content')
    {
        $cache_key = $this->build_cache_key($key, $type);

        if ($this->memcache_available || $this->redis_available) {
            wp_cache_delete($cache_key, $this->platform);
        }

        delete_transient($cache_key);
        $this->delete_from_db_cache($cache_key);

        // Remove from prefetch queue
        $this->remove_from_prefetch_queue($key, $type);

        $this->update_cache_stats('delete', $type);
        return true;
    }

    /**
     * Flush all caches for the platform
     */
    public function flush($type = null)
    {
        global $wpdb;

        if ($type) {
            $prefix = self::CACHE_CONFIG[$type]['prefix'];

            // Clear object cache
            if ($this->memcache_available || $this->redis_available) {
                wp_cache_flush();
            }

            // Clear transients
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                '_transient_' . $prefix . '%'
            ));

            // Clear DB cache
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $cache_table WHERE cache_key LIKE %s",
                $prefix . '%'
            ));
        } else {
            // Flush all cache types
            foreach (self::CACHE_CONFIG as $cache_type => $config) {
                $this->flush($cache_type);
            }
        }

        // Clear analytics and prefetch queue
        $this->clear_cache_analytics($type);
        $this->clear_prefetch_queue($type);

        $this->update_cache_stats('flush', $type ?? 'all');
        return true;
    }

    /**
     * Get cache statistics with advanced analytics
     */
    public function get_stats()
    {
        $basic_stats = $this->cache_stats;
        $analytics = $this->get_cache_analytics();

        // Agregate stats for Admin UI
        $total_hits = 0;
        $total_misses = 0;
        foreach ($basic_stats as $type_stats) {
            $total_hits += $type_stats['hits'] ?? 0;
            $total_misses += $type_stats['misses'] ?? 0;
        }
        $total_requests = $total_hits + $total_misses;
        $hit_rate = $total_requests > 0 ? ($total_hits / $total_requests) * 100 : 0;

        // Get DB stats
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_cache';
        $db_stats = $wpdb->get_row("SELECT COUNT(*) as count, SUM(LENGTH(cache_data)) as size, SUM(CASE WHEN expiry < UNIX_TIMESTAMP() THEN 1 ELSE 0 END) as expired FROM $table", ARRAY_A);

        return array_merge($basic_stats, [
            'total_items' => $db_stats['count'] ?? 0,
            'total_size' => $db_stats['size'] ?? 0,
            'hit_rate' => $hit_rate,
            'expired_items' => $db_stats['expired'] ?? 0,
            'analytics' => $analytics,
            'prefetch_queue_size' => count($this->prefetch_queue),
            'warming_patterns' => $this->get_warming_patterns(),
            'performance_metrics' => $this->get_performance_metrics()
        ]);
    }

    /**
     * Intelligent cache warming based on usage patterns
     */
    public function intelligent_cache_warming()
    {
        $patterns = $this->analyze_usage_patterns();
        $warming_candidates = $this->identify_warming_candidates($patterns);

        foreach ($warming_candidates as $candidate) {
            $this->warm_cache_item($candidate);
        }

        // Process prefetch queue
        $this->process_prefetch_queue();

        error_log("CacheManager: Intelligent warming completed for {$this->platform}. " .
            "Warmed " . count($warming_candidates) . " items.");
    }

    /**
     * Predictive content prefetching based on user behavior
     */
    public function predictive_prefetch($user_behavior_data = [])
    {
        $predictions = $this->analyze_user_behavior($user_behavior_data);

        foreach ($predictions as $prediction) {
            if ($prediction['confidence'] > 0.7) {
                $this->prefetch_content($prediction);
            }
        }

        return count($predictions);
    }

    /**
     * Smart cache invalidation with dependency tracking
     */
    public function smart_invalidate($key, $type = 'content', $cascade = true)
    {
        // Invalidate the primary item
        $this->delete($key, $type);

        if ($cascade) {
            // Find and invalidate dependent items
            $dependencies = $this->find_cache_dependencies($key, $type);
            foreach ($dependencies as $dep_key => $dep_type) {
                $this->delete($dep_key, $dep_type);
            }
        }

        // Update invalidation analytics
        $this->record_invalidation($key, $type, $cascade);

        return true;
    }

    /**
     * Cache performance optimization recommendations
     */
    public function get_optimization_recommendations()
    {
        $analytics = $this->get_cache_analytics();
        $recommendations = [];

        // Analyze hit rates
        foreach ($analytics['hit_rates'] as $type => $hit_rate) {
            if ($hit_rate < 0.7) {
                $recommendations[] = [
                    'type' => 'low_hit_rate',
                    'cache_type' => $type,
                    'current_rate' => $hit_rate,
                    'suggestion' => "Consider increasing TTL for {$type} cache or implementing more aggressive prefetching"
                ];
            }
        }

        // Analyze memory usage
        if ($analytics['memory_efficiency'] < 0.8) {
            $recommendations[] = [
                'type' => 'memory_efficiency',
                'current_efficiency' => $analytics['memory_efficiency'],
                'suggestion' => 'Consider implementing cache compression or reducing cache size'
            ];
        }

        // Analyze warming effectiveness
        if ($analytics['warming_effectiveness'] < 0.6) {
            $recommendations[] = [
                'type' => 'warming_effectiveness',
                'current_effectiveness' => $analytics['warming_effectiveness'],
                'suggestion' => 'Optimize cache warming patterns based on access frequency'
            ];
        }

        return $recommendations;
    }

    /**
     * Private helper methods
     */
    private function build_cache_key($key, $type)
    {
        return self::CACHE_CONFIG[$type]['prefix'] . $this->platform . '_' . md5($key);
    }

    private function init_cache_stats()
    {
        foreach (self::CACHE_CONFIG as $type => $config) {
            $this->cache_stats[$type] = [
                'hits' => 0,
                'misses' => 0,
                'sets' => 0,
                'deletes' => 0,
                'warms' => 0
            ];
        }
    }

    private function init_cache_analytics()
    {
        $this->cache_analytics = get_option("sf_cache_analytics_{$this->platform}", [
            'access_patterns' => [],
            'performance_data' => [],
            'warming_history' => [],
            'invalidation_history' => []
        ]);
    }

    private function schedule_intelligent_warming()
    {
        $hook_name = "sf_intelligent_warming_{$this->platform}";

        if (!wp_next_scheduled($hook_name)) {
            wp_schedule_event(time(), 'sf_every_5_minutes', $hook_name);
        }

        add_action($hook_name, [$this, 'intelligent_cache_warming']);
    }

    private function record_access_pattern($key, $type)
    {
        $pattern_key = md5($key . $type);
        $current_time = time();

        if (!isset($this->cache_analytics['access_patterns'][$pattern_key])) {
            $this->cache_analytics['access_patterns'][$pattern_key] = [
                'key' => $key,
                'type' => $type,
                'access_count' => 0,
                'last_access' => 0,
                'access_frequency' => []
            ];
        }

        $pattern = &$this->cache_analytics['access_patterns'][$pattern_key];
        $pattern['access_count']++;
        $pattern['access_frequency'][] = $current_time;
        $pattern['last_access'] = $current_time;

        // Keep only last 100 access times
        if (count($pattern['access_frequency']) > 100) {
            $pattern['access_frequency'] = array_slice($pattern['access_frequency'], -100);
        }

        $this->save_cache_analytics();
    }

    private function record_cache_performance($key, $type, $source, $response_time)
    {
        $this->cache_analytics['performance_data'][] = [
            'key' => md5($key),
            'type' => $type,
            'source' => $source,
            'response_time' => $response_time,
            'timestamp' => time()
        ];

        // Keep only last 1000 performance records
        if (count($this->cache_analytics['performance_data']) > 1000) {
            $this->cache_analytics['performance_data'] = array_slice(
                $this->cache_analytics['performance_data'],
                -1000
            );
        }

        $this->save_cache_analytics();
    }

    private function check_prefetch_opportunity($key, $type, $data)
    {
        // Check if item is approaching expiration
        $cache_key = $this->build_cache_key($key, $type);
        $ttl_remaining = $this->get_ttl_remaining($cache_key, $type);
        $total_ttl = self::CACHE_CONFIG[$type]['ttl'];

        if ($ttl_remaining / $total_ttl < self::PREFETCH_THRESHOLD) {
            $this->add_to_prefetch_queue($key, $type, 'refresh');
        }

        // Check for related content prefetching
        $related_keys = $this->identify_related_content($key, $type, $data);
        foreach ($related_keys as $related_key) {
            $this->add_to_prefetch_queue($related_key, $type, 'related');
        }
    }

    private function add_to_prefetch_queue($key, $type, $reason = 'miss')
    {
        $queue_item = [
            'key' => $key,
            'type' => $type,
            'reason' => $reason,
            'priority' => $this->calculate_prefetch_priority($key, $type, $reason),
            'added_at' => time()
        ];

        $this->prefetch_queue[] = $queue_item;

        // Sort by priority
        usort($this->prefetch_queue, function ($a, $b) {
            return $b['priority'] - $a['priority'];
        });

        // Limit queue size
        if (count($this->prefetch_queue) > 100) {
            $this->prefetch_queue = array_slice($this->prefetch_queue, 0, 100);
        }
    }

    private function process_prefetch_queue()
    {
        $processed = 0;
        $max_process = self::CACHE_WARMING_BATCH_SIZE;

        while ($processed < $max_process && !empty($this->prefetch_queue)) {
            $item = array_shift($this->prefetch_queue);

            if ($this->should_prefetch_item($item)) {
                $this->prefetch_cache_item($item);
                $processed++;
            }
        }

        return $processed;
    }

    private function analyze_usage_patterns()
    {
        $patterns = [];
        $current_time = time();

        foreach ($this->cache_analytics['access_patterns'] as $pattern_key => $pattern) {
            // Calculate access frequency
            $recent_accesses = array_filter($pattern['access_frequency'], function ($time) use ($current_time) {
                return ($current_time - $time) < 3600; // Last hour
            });

            $hourly_frequency = count($recent_accesses);

            // Calculate predictability score
            $predictability = $this->calculate_predictability($pattern['access_frequency']);

            $patterns[$pattern_key] = [
                'key' => $pattern['key'],
                'type' => $pattern['type'],
                'frequency' => $hourly_frequency,
                'predictability' => $predictability,
                'last_access' => $pattern['last_access'],
                'warming_score' => $hourly_frequency * $predictability
            ];
        }

        // Sort by warming score
        uasort($patterns, function ($a, $b) {
            return $b['warming_score'] - $a['warming_score'];
        });

        return $patterns;
    }

    private function calculate_predictability($access_times)
    {
        if (count($access_times) < 3) {
            return 0.1;
        }

        // Calculate intervals between accesses
        $intervals = [];
        for ($i = 1; $i < count($access_times); $i++) {
            $intervals[] = $access_times[$i] - $access_times[$i - 1];
        }

        // Calculate coefficient of variation (lower = more predictable)
        $mean = array_sum($intervals) / count($intervals);
        $variance = array_sum(array_map(function ($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $intervals)) / count($intervals);

        $cv = sqrt($variance) / $mean;

        // Convert to predictability score (0-1, higher = more predictable)
        return max(0.1, min(1.0, 1 / (1 + $cv)));
    }

    private function identify_warming_candidates($patterns)
    {
        $candidates = [];

        foreach ($patterns as $pattern) {
            if ($pattern['warming_score'] > 1.0) { // Threshold for warming
                $candidates[] = [
                    'key' => $pattern['key'],
                    'type' => $pattern['type'],
                    'score' => $pattern['warming_score']
                ];
            }
        }

        return array_slice($candidates, 0, self::CACHE_WARMING_BATCH_SIZE);
    }

    private function warm_cache_item($candidate)
    {
        // This would typically involve calling the original data source
        // For now, we'll just log the warming attempt
        error_log("CacheManager: Warming cache for key: {$candidate['key']}, type: {$candidate['type']}");

        $this->cache_analytics['warming_history'][] = [
            'key' => $candidate['key'],
            'type' => $candidate['type'],
            'score' => $candidate['score'],
            'timestamp' => time()
        ];

        $this->update_cache_stats('warm', $candidate['type']);
    }

    private function analyze_user_behavior($behavior_data)
    {
        // Analyze user behavior patterns to predict content needs
        $predictions = [];

        // This is a simplified implementation
        // In practice, this would use machine learning algorithms
        foreach ($behavior_data as $behavior) {
            if ($behavior['type'] === 'content_view') {
                $predictions[] = [
                    'key' => $behavior['related_content'],
                    'type' => 'content',
                    'confidence' => 0.8,
                    'reason' => 'user_behavior_pattern'
                ];
            }
        }

        return $predictions;
    }

    private function prefetch_content($prediction)
    {
        // Implement actual prefetching logic
        error_log("CacheManager: Prefetching content based on prediction: {$prediction['key']}");
    }

    private function find_cache_dependencies($key, $type)
    {
        // Implement dependency tracking logic
        // This would return related cache keys that should be invalidated
        return [];
    }

    private function record_invalidation($key, $type, $cascade)
    {
        $this->cache_analytics['invalidation_history'][] = [
            'key' => md5($key),
            'type' => $type,
            'cascade' => $cascade,
            'timestamp' => time()
        ];

        $this->save_cache_analytics();
    }

    private function get_cache_analytics()
    {
        $analytics = $this->cache_analytics;

        // Calculate hit rates
        $hit_rates = [];
        foreach ($this->cache_stats as $type => $stats) {
            $total_requests = $stats['hits'] + $stats['misses'];
            $hit_rates[$type] = $total_requests > 0 ? $stats['hits'] / $total_requests : 0;
        }

        // Calculate average response times
        $avg_response_times = [];
        foreach (self::CACHE_CONFIG as $type => $config) {
            $type_performance = array_filter($analytics['performance_data'], function ($record) use ($type) {
                return $record['type'] === $type;
            });

            if (!empty($type_performance)) {
                $avg_response_times[$type] = array_sum(array_column($type_performance, 'response_time')) / count($type_performance);
            } else {
                $avg_response_times[$type] = 0;
            }
        }

        return [
            'hit_rates' => $hit_rates,
            'avg_response_times' => $avg_response_times,
            'memory_efficiency' => $this->calculate_memory_efficiency(),
            'warming_effectiveness' => $this->calculate_warming_effectiveness()
        ];
    }

    private function calculate_memory_efficiency()
    {
        // Simplified memory efficiency calculation
        // In practice, this would analyze actual memory usage
        return 0.85; // Placeholder
    }

    private function calculate_warming_effectiveness()
    {
        // Calculate how effective cache warming has been
        $warming_history = $this->cache_analytics['warming_history'];
        if (empty($warming_history)) {
            return 0.5;
        }

        // Simplified calculation - in practice, would compare warming success rates
        return 0.75; // Placeholder
    }

    private function get_warming_patterns()
    {
        if ($this->warming_patterns === null) {
            $this->warming_patterns = $this->analyze_usage_patterns();
        }

        return array_slice($this->warming_patterns, 0, 10); // Top 10 patterns
    }

    private function get_performance_metrics()
    {
        $recent_performance = array_filter($this->cache_analytics['performance_data'], function ($record) {
            return (time() - $record['timestamp']) < 3600; // Last hour
        });

        if (empty($recent_performance)) {
            return ['message' => 'No recent performance data available'];
        }

        $response_times = array_column($recent_performance, 'response_time');

        return [
            'avg_response_time' => array_sum($response_times) / count($response_times),
            'min_response_time' => min($response_times),
            'max_response_time' => max($response_times),
            'total_requests' => count($recent_performance)
        ];
    }

    private function save_cache_analytics()
    {
        // Clean old data before saving
        $this->cleanup_old_analytics();
        update_option("sf_cache_analytics_{$this->platform}", $this->cache_analytics);
    }

    private function cleanup_old_analytics()
    {
        $cutoff_time = time() - (self::CACHE_ANALYTICS_RETENTION * DAY_IN_SECONDS);

        // Clean performance data
        $this->cache_analytics['performance_data'] = array_filter(
            $this->cache_analytics['performance_data'],
            function ($record) use ($cutoff_time) {
                return $record['timestamp'] > $cutoff_time;
            }
        );

        // Clean warming history
        $this->cache_analytics['warming_history'] = array_filter(
            $this->cache_analytics['warming_history'],
            function ($record) use ($cutoff_time) {
                return $record['timestamp'] > $cutoff_time;
            }
        );

        // Clean invalidation history
        $this->cache_analytics['invalidation_history'] = array_filter(
            $this->cache_analytics['invalidation_history'],
            function ($record) use ($cutoff_time) {
                return $record['timestamp'] > $cutoff_time;
            }
        );
    }

    private function clear_cache_analytics($type = null)
    {
        if ($type) {
            // Clear analytics for specific type
            $this->cache_analytics['access_patterns'] = array_filter(
                $this->cache_analytics['access_patterns'],
                function ($pattern) use ($type) {
                    return $pattern['type'] !== $type;
                }
            );
        } else {
            // Clear all analytics
            $this->cache_analytics = [
                'access_patterns' => [],
                'performance_data' => [],
                'warming_history' => [],
                'invalidation_history' => []
            ];
        }

        $this->save_cache_analytics();
    }

    private function clear_prefetch_queue($type = null)
    {
        if ($type) {
            $this->prefetch_queue = array_filter($this->prefetch_queue, function ($item) use ($type) {
                return $item['type'] !== $type;
            });
        } else {
            $this->prefetch_queue = [];
        }
    }

    private function update_cache_stats($operation, $type)
    {
        switch ($operation) {
            case 'hit':
                $this->cache_stats[$type]['hits']++;
                break;
            case 'miss':
                $this->cache_stats[$type]['misses']++;
                break;
            case 'set':
                $this->cache_stats[$type]['sets']++;
                break;
            case 'delete':
                $this->cache_stats[$type]['deletes']++;
                break;
            case 'warm':
                $this->cache_stats[$type]['warms']++;
                break;
        }
    }

    private function get_from_db_cache($cache_key, $type)
    {
        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';

        $data = $wpdb->get_var($wpdb->prepare(
            "SELECT cache_data FROM $cache_table 
             WHERE cache_key = %s 
             AND cache_type = %s 
             AND expiry > %d",
            $cache_key,
            $type,
            time()
        ));

        return $data ? maybe_unserialize($data) : false;
    }

    private function store_in_db_cache($cache_key, $data, $type, $ttl)
    {
        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';

        return $wpdb->replace(
            $cache_table,
            [
                'cache_key' => $cache_key,
                'cache_type' => $type,
                'cache_data' => maybe_serialize($data),
                'expiry' => time() + $ttl,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );
    }

    private function delete_from_db_cache($cache_key)
    {
        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';

        $wpdb->delete(
            $cache_table,
            ['cache_key' => $cache_key],
            ['%s']
        );
    }

    private function warm_up_cache($cache_key, $data, $type)
    {
        if ($this->memcache_available || $this->redis_available) {
            wp_cache_set($cache_key, $data, $this->platform, self::CACHE_CONFIG[$type]['ttl']);
        }
        set_transient($cache_key, $data, self::CACHE_CONFIG[$type]['ttl']);
        $this->update_cache_stats('warm', $type);
    }

    private function record_cache_set($key, $type, $priority)
    {
        // Record cache set operation for analytics
        $this->cache_analytics['performance_data'][] = [
            'key' => md5($key),
            'type' => $type,
            'operation' => 'set',
            'priority' => $priority,
            'timestamp' => time()
        ];
    }

    private function schedule_related_warming($key, $type, $data)
    {
        // Identify and schedule warming for related content
        $related_keys = $this->identify_related_content($key, $type, $data);
        foreach ($related_keys as $related_key) {
            $this->add_to_prefetch_queue($related_key, $type, 'related');
        }
    }

    private function identify_related_content($key, $type, $data)
    {
        // Implement logic to identify related content
        // This is a simplified implementation
        $related = [];

        if ($type === 'content' && isset($data['tags'])) {
            // For content with tags, related items might share tags
            foreach ($data['tags'] as $tag) {
                $related[] = "tag_{$tag}";
            }
        }

        return array_slice($related, 0, 5); // Limit to 5 related items
    }

    private function remove_from_prefetch_queue($key, $type)
    {
        $this->prefetch_queue = array_filter($this->prefetch_queue, function ($item) use ($key, $type) {
            return !($item['key'] === $key && $item['type'] === $type);
        });
    }

    private function calculate_prefetch_priority($key, $type, $reason)
    {
        $base_priority = 50;

        // Adjust based on reason
        switch ($reason) {
            case 'refresh':
                $base_priority += 30;
                break;
            case 'related':
                $base_priority += 20;
                break;
            case 'miss':
                $base_priority += 10;
                break;
        }

        // Adjust based on access patterns
        $pattern_key = md5($key . $type);
        if (isset($this->cache_analytics['access_patterns'][$pattern_key])) {
            $pattern = $this->cache_analytics['access_patterns'][$pattern_key];
            $base_priority += min(30, $pattern['access_count']);
        }

        return $base_priority;
    }

    private function should_prefetch_item($item)
    {
        // Check if item is still relevant for prefetching
        $age = time() - $item['added_at'];

        // Don't prefetch items older than 1 hour
        if ($age > 3600) {
            return false;
        }

        // Check if item is already cached
        $cached = $this->get($item['key'], $item['type']);
        return $cached === null;
    }

    private function prefetch_cache_item($item)
    {
        // This would typically involve calling the original data source
        // For now, we'll just log the prefetch attempt
        error_log("CacheManager: Prefetching cache item: {$item['key']}, type: {$item['type']}, reason: {$item['reason']}");

        // In a real implementation, you would:
        // 1. Determine the data source for this key/type
        // 2. Fetch the data
        // 3. Store it in cache

        $this->update_cache_stats('warm', $item['type']);
    }

    private function get_ttl_remaining($cache_key, $type)
    {
        // Get remaining TTL for a cache item
        // This is a simplified implementation
        return self::CACHE_CONFIG[$type]['ttl'] * 0.5; // Assume 50% remaining
    }

    /**
     * Validate cache data integrity
     */
    private function validate_cache_data($data)
    {
        if (is_null($data)) {
            return false;
        }

        // Check for basic data corruption
        if (is_string($data) && strlen($data) === 0) {
            return false;
        }

        // Check for serialization issues
        if (is_string($data) && strpos($data, 'O:') === 0) {
            // Looks like serialized object, try to unserialize
            $test = @unserialize($data);
            return $test !== false;
        }

        return true;
    }

    /**
     * Validate cache integrity with metadata
     */
    private function validate_cache_integrity($data)
    {
        if (!is_array($data)) {
            return $this->validate_cache_data($data);
        }

        // Check if it has metadata structure
        if (!isset($data['data'], $data['timestamp'], $data['checksum'])) {
            return $this->validate_cache_data($data);
        }

        // Validate checksum
        $expected_checksum = md5(serialize($data['data']));
        if ($data['checksum'] !== $expected_checksum) {
            error_log("CacheManager: Checksum mismatch detected");
            return false;
        }

        // Check if data is too old (beyond reasonable limits)
        $age = time() - $data['timestamp'];
        if ($age > 86400 * 7) { // 7 days max
            return false;
        }

        return $this->validate_cache_data($data['data']);
    }

    /**
     * Get cache reliability metrics
     */
    public function get_reliability_metrics()
    {
        $total_gets = 0;
        $total_hits = 0;
        $total_sets = 0;

        foreach (self::CACHE_CONFIG as $type => $config) {
            $stats = $this->cache_stats[$type] ?? [];
            $total_gets += ($stats['hits'] ?? 0) + ($stats['misses'] ?? 0);
            $total_hits += $stats['hits'] ?? 0;
            $total_sets += $stats['sets'] ?? 0;
        }

        $hit_rate = $total_gets > 0 ? ($total_hits / $total_gets) * 100 : 0;
        $set_success_rate = $total_sets > 0 ? 100 : 0; // Assume all sets succeed for now

        return [
            'hit_rate' => $hit_rate,
            'set_success_rate' => $set_success_rate,
            'error_rate' => 0, // No error tracking in current implementation
            'total_operations' => $total_gets + $total_sets,
            'reliability_score' => min(100, ($hit_rate + $set_success_rate) / 2)
        ];
    }

    /**
     * Clear all cache data
     */
    public function clear_all_cache()
    {
        try {
            // Clear WordPress transients
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sf_%' OR option_name LIKE '_transient_timeout_sf_%'");

            // Clear object cache if available
            if ($this->memcache_available || $this->redis_available) {
                wp_cache_flush();
            }

            // Clear database cache
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$cache_table}'") === $cache_table) {
                $wpdb->query("DELETE FROM {$cache_table}");
            }

            // Reset cache stats
            $this->cache_stats = [
                'hits' => 0,
                'misses' => 0,
                'sets' => 0,
                'deletes' => 0
            ];

            error_log("CacheManager: All cache cleared successfully for platform: {$this->platform}");
            return true;

        } catch (\Exception $e) {
            error_log("CacheManager: Error clearing all cache: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Optimize cache performance
     */
    public function optimize_cache()
    {
        try {
            // Clean up expired cache entries
            global $wpdb;

            // Remove expired transients
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sf_%' AND option_value < UNIX_TIMESTAMP()");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sf_%' AND option_name NOT IN (SELECT REPLACE(option_name, '_transient_timeout_', '_transient_') FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sf_%')");

            // Clean up database cache
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$cache_table}'") === $cache_table) {
                $wpdb->query("DELETE FROM {$cache_table} WHERE expires_at < NOW()");
            }

            // Trigger intelligent cache warming
            $this->intelligent_cache_warming();

            // Optimize cache analytics
            $this->cleanup_old_analytics();

            error_log("CacheManager: Cache optimization completed for platform: {$this->platform}");
            return true;

        } catch (\Exception $e) {
            error_log("CacheManager: Error optimizing cache: " . $e->getMessage());
            throw $e;
        }
    }
}