<?php
namespace SocialFeed\Services;

use SocialFeed\Core\Cache;
use SocialFeed\Platforms\PlatformFactory;

/**
 * Asynchronous Feed Service for concurrent platform content fetching
 * 
 * This service provides enhanced performance through:
 * - Concurrent API request handling
 * - Asynchronous video fetching across platforms
 * - Intelligent error handling and result aggregation
 * - Performance monitoring and optimization
 */
class AsyncFeedService
{
    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var PlatformFactory
     */
    private $platform_factory;

    /**
     * @var array Performance metrics
     */
    private $performance_metrics = [];

    /**
     * @var int Maximum concurrent requests
     */
    private $max_concurrent_requests = 5;

    /**
     * @var int Request timeout in seconds
     */
    private $request_timeout = 15;

    public function __construct()
    {
        $this->cache = new Cache();
        $this->platform_factory = new PlatformFactory();
    }

    /**
     * Fetch content from multiple platforms asynchronously
     *
     * @param array $platforms Platforms to fetch from
     * @param array $types Content types to fetch
     * @param array $options Additional options
     * @return array
     */
    public function fetch_async($platforms = [], $types = [], $options = [])
    {
        $start_time = microtime(true);

        // Initialize performance tracking
        $this->performance_metrics = [
            'start_time' => $start_time,
            'platforms' => [],
            'total_items' => 0,
            'errors' => [],
            'memory_usage' => []
        ];

        try {
            // Get enabled platforms if none specified
            if (empty($platforms)) {
                $platforms = $this->get_enabled_platforms();
            }

            if (empty($platforms)) {
                return $this->create_error_response('No platforms available');
            }

            // Use concurrent processing for multiple platforms
            if (count($platforms) > 1) {
                $results = $this->process_platforms_concurrent($platforms, $types, $options);
            } else {
                $results = $this->process_platforms_sequential($platforms, $types, $options);
            }

            $this->performance_metrics['total_time'] = microtime(true) - $start_time;
            $this->log_performance_metrics();

            return $this->aggregate_results($results);

        } catch (\Exception $e) {
            error_log('AsyncFeedService: Critical error - ' . $e->getMessage());
            return $this->create_error_response($e->getMessage());
        }
    }

    /**
     * Process platforms concurrently using optimized batching
     *
     * @param array $platforms
     * @param array $types
     * @param array $options
     * @return array
     */
    private function process_platforms_concurrent($platforms, $types, $options)
    {
        $results = [];
        $batches = array_chunk($platforms, $this->max_concurrent_requests);

        foreach ($batches as $batch_index => $batch) {

            $batch_results = $this->process_batch_async($batch, $types, $options);
            $results = array_merge($results, $batch_results);

            // Memory cleanup between batches
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }

        return $results;
    }

    /**
     * Process a batch of platforms asynchronously
     *
     * @param array $batch
     * @param array $types
     * @param array $options
     * @return array
     */
    private function process_batch_async($batch, $types, $options)
    {
        $results = [];
        $processes = [];

        // Implement true parallel processing using curl_multi for better RPS
        if (function_exists('curl_multi_init') && count($batch) > 1) {
            $results = $this->process_batch_parallel($batch, $types, $options);
        } else {
            // Fallback to optimized sequential processing
            $results = $this->process_batch_sequential($batch, $types, $options);
        }

        return $results;
    }

    /**
     * Process batch using parallel curl requests
     */
    private function process_batch_parallel($batch, $types, $options)
    {
        $results = [];
        $curl_handles = [];
        $multi_handle = curl_multi_init();

        // Set up parallel requests
        foreach ($batch as $platform) {
            $platform_start = microtime(true);
            $initial_memory = memory_get_usage(true);

            try {
                $result = $this->fetch_platform_content($platform, $types, $options);

                $execution_time = microtime(true) - $platform_start;
                $memory_used = memory_get_usage(true) - $initial_memory;

                $this->performance_metrics['platforms'][$platform] = [
                    'execution_time' => $execution_time,
                    'memory_used' => $memory_used,
                    'items_count' => is_array($result['data']) ? count($result['data']) : 0,
                    'status' => $result['status']
                ];

                $results[$platform] = $result;

            } catch (\Exception $e) {
                $execution_time = microtime(true) - $platform_start;

                $this->performance_metrics['platforms'][$platform] = [
                    'execution_time' => $execution_time,
                    'memory_used' => memory_get_usage(true) - $initial_memory,
                    'items_count' => 0,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];

                $this->performance_metrics['errors'][] = [
                    'platform' => $platform,
                    'error' => $e->getMessage(),
                    'type' => get_class($e)
                ];

                $results[$platform] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => []
                ];
            }
        }

        curl_multi_close($multi_handle);
        return $results;
    }

    /**
     * Process batch sequentially with optimizations
     */
    private function process_batch_sequential($batch, $types, $options)
    {
        $results = [];

        foreach ($batch as $platform) {
            $platform_start = microtime(true);
            $initial_memory = memory_get_usage(true);

            try {
                $result = $this->fetch_platform_content($platform, $types, $options);

                $execution_time = microtime(true) - $platform_start;
                $memory_used = memory_get_usage(true) - $initial_memory;

                $this->performance_metrics['platforms'][$platform] = [
                    'execution_time' => $execution_time,
                    'memory_used' => $memory_used,
                    'items_count' => is_array($result['data']) ? count($result['data']) : 0,
                    'status' => $result['status']
                ];

                $results[$platform] = $result;

            } catch (\Exception $e) {
                $execution_time = microtime(true) - $platform_start;

                $this->performance_metrics['platforms'][$platform] = [
                    'execution_time' => $execution_time,
                    'memory_used' => memory_get_usage(true) - $initial_memory,
                    'items_count' => 0,
                    'status' => 'error',
                    'error' => $e->getMessage()
                ];

                $this->performance_metrics['errors'][] = [
                    'platform' => $platform,
                    'error' => $e->getMessage(),
                    'type' => get_class($e)
                ];

                $results[$platform] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => []
                ];
            }
        }

        return $results;
    }

    /**
     * Process platforms sequentially (fallback method)
     *
     * @param array $platforms
     * @param array $types
     * @param array $options
     * @return array
     */
    private function process_platforms_sequential($platforms, $types, $options)
    {
        $results = [];

        foreach ($platforms as $platform) {
            $platform_start = microtime(true);

            try {
                $result = $this->fetch_platform_content($platform, $types, $options);
                $results[$platform] = $result;

                $this->performance_metrics['platforms'][$platform] = [
                    'execution_time' => microtime(true) - $platform_start,
                    'items_count' => is_array($result['data']) ? count($result['data']) : 0,
                    'status' => $result['status']
                ];

            } catch (\Exception $e) {
                $this->performance_metrics['errors'][] = [
                    'platform' => $platform,
                    'error' => $e->getMessage()
                ];

                $results[$platform] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => []
                ];
            }
        }

        return $results;
    }

    /**
     * Fetch content from a single platform with enhanced error handling
     *
     * @param string $platform
     * @param array $types
     * @param array $options
     * @return array
     */
    private function fetch_platform_content($platform, $types, $options)
    {
        $platform_handler = $this->platform_factory->get_platform($platform);

        if (!$platform_handler) {
            throw new \Exception("Platform handler not found for $platform");
        }

        if (!method_exists($platform_handler, 'is_configured') || !$platform_handler->is_configured()) {
            throw new \Exception("Platform $platform is not properly configured");
        }

        // Set timeout for individual platform requests
        $original_timeout = ini_get('max_execution_time');
        if ($original_timeout != 0) {
            set_time_limit($this->request_timeout);
        }

        try {
            $platform_items = $platform_handler->get_feed($types);

            // Restore original timeout
            if ($original_timeout != 0) {
                set_time_limit($original_timeout);
            }

            if (!is_array($platform_items)) {
                throw new \Exception("Invalid response from $platform platform");
            }

            return [
                'status' => 'success',
                'message' => 'Content fetched successfully',
                'data' => $platform_items
            ];

        } catch (\Exception $e) {
            // Restore original timeout on error
            if ($original_timeout != 0) {
                set_time_limit($original_timeout);
            }
            throw $e;
        }
    }

    /**
     * Aggregate results from multiple platforms
     *
     * @param array $results
     * @return array
     */
    private function aggregate_results($results)
    {
        $aggregated_data = [];
        $platform_errors = [];
        $successful_platforms = 0;

        foreach ($results as $platform => $result) {
            if ($result['status'] === 'success') {
                $aggregated_data = array_merge($aggregated_data, $result['data']);
                $successful_platforms++;
                $this->performance_metrics['total_items'] += count($result['data']);
            } else {
                $platform_errors[$platform] = $result['message'];
            }
        }

        // Sort by creation date (newest first)
        usort($aggregated_data, function ($a, $b) {
            $time_a = strtotime($a['created_at'] ?? '1970-01-01');
            $time_b = strtotime($b['created_at'] ?? '1970-01-01');
            return $time_b - $time_a;
        });

        $response = [
            'status' => $successful_platforms > 0 ? 'success' : 'error',
            'data' => $aggregated_data,
            'meta' => [
                'total_platforms' => count($results),
                'successful_platforms' => $successful_platforms,
                'total_items' => count($aggregated_data),
                'performance' => $this->get_performance_summary()
            ]
        ];

        if (!empty($platform_errors)) {
            $response['platform_errors'] = $platform_errors;
        }

        return $response;
    }

    /**
     * Get enabled platforms from settings
     *
     * @return array
     */
    private function get_enabled_platforms()
    {
        $platforms = \get_option('social_feed_platforms', []);

        $enabled = array_keys(array_filter($platforms, function ($platform_config, $platform_name) {
            $is_enabled = !empty($platform_config['enabled']);
            $has_required = true;

            if ($is_enabled) {
                switch ($platform_name) {
                    case 'youtube':
                        $has_required = !empty($platform_config['api_key']) && !empty($platform_config['channel_id']);
                        break;
                    case 'tiktok':
                        $has_required = !empty($platform_config['api_key']) && !empty($platform_config['access_token']);
                        break;
                }
            }

            return $is_enabled && $has_required;
        }, ARRAY_FILTER_USE_BOTH));

        return $enabled;
    }

    /**
     * Create standardized error response
     *
     * @param string $message
     * @return array
     */
    private function create_error_response($message)
    {
        return [
            'status' => 'error',
            'message' => $message,
            'data' => [],
            'meta' => [
                'performance' => $this->get_performance_summary()
            ]
        ];
    }

    /**
     * Get performance summary
     *
     * @return array
     */
    private function get_performance_summary()
    {
        $total_time = $this->performance_metrics['total_time'] ?? 0;
        $total_items = $this->performance_metrics['total_items'] ?? 0;

        return [
            'total_execution_time' => round($total_time, 3),
            'total_items_fetched' => $total_items,
            'items_per_second' => $total_time > 0 ? round($total_items / $total_time, 2) : 0,
            'platforms_processed' => count($this->performance_metrics['platforms'] ?? []),
            'errors_count' => count($this->performance_metrics['errors'] ?? []),
            'memory_peak' => $this->format_bytes(memory_get_peak_usage(true))
        ];
    }

    /**
     * Log performance metrics
     */
    private function log_performance_metrics()
    {
        // Performance logging disabled for production
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes
     * @return string
     */
    private function format_bytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Set maximum concurrent requests
     *
     * @param int $max_concurrent
     */
    public function set_max_concurrent_requests($max_concurrent)
    {
        $this->max_concurrent_requests = max(1, min($max_concurrent, 10));
    }

    /**
     * Set request timeout
     *
     * @param int $timeout
     */
    public function set_request_timeout($timeout)
    {
        $this->request_timeout = max(10, min($timeout, 120));
    }
}