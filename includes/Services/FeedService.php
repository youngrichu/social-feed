<?php
namespace SocialFeed\Services;

use SocialFeed\Core\Cache;
use SocialFeed\Platforms\PlatformFactory;

class FeedService {
    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var PlatformFactory
     */
    private $platform_factory;

    public function __construct() {
        $this->cache = new Cache();
        $this->platform_factory = new PlatformFactory();
    }

    /**
     * Get combined feeds from multiple platforms
     *
     * @param array $platforms Platforms to fetch from
     * @param array $types Content types to fetch
     * @param int $page Page number
     * @param int $per_page Items per page
     * @param string $sort Sort field
     * @param string $order Sort order
     * @return array
     */
    public function get_feeds($platforms = [], $types = [], $page = 1, $per_page = 12, $sort = 'date', $order = 'desc') {
        error_log('FeedService: Starting get_feeds with platforms: ' . json_encode($platforms) . ', types: ' . json_encode($types));
        
        try {
            // Enforce reasonable limits
            $per_page = min(max(1, $per_page), 50);
            $page = max(1, $page);

            // Get enabled platforms if none specified
            if (empty($platforms)) {
                $platforms = $this->get_enabled_platforms();
                error_log('FeedService: Using enabled platforms: ' . json_encode($platforms));
            }

            // If no platforms are enabled or specified, return error
            if (empty($platforms)) {
                error_log('FeedService: No platforms available');
                return [
                    'status' => 'error',
                    'message' => 'No social media platforms are enabled or properly configured',
                    'data' => [
                        'items' => [],
                        'pagination' => [
                            'current_page' => $page,
                            'total_pages' => 0,
                            'total_items' => 0,
                            'per_page' => $per_page
                        ]
                    ]
                ];
            }

            global $wpdb;
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            
            // Build query conditions
            $where_conditions = [];
            $query_args = [];

            if (!empty($platforms)) {
                $platform_placeholders = implode(',', array_fill(0, count($platforms), '%s'));
                $where_conditions[] = "platform IN ($platform_placeholders)";
                $query_args = array_merge($query_args, $platforms);
            }

            if (!empty($types)) {
                $type_placeholders = implode(',', array_fill(0, count($types), '%s'));
                $where_conditions[] = "content_type IN ($type_placeholders)";
                $query_args = array_merge($query_args, $types);
            }

            // Build the WHERE clause
            $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

            // Get total count
            $count_query = "SELECT COUNT(*) FROM $cache_table $where_clause";
            $prepared_count_query = $query_args ? $wpdb->prepare($count_query, $query_args) : $count_query;
            error_log('FeedService: Count query: ' . $prepared_count_query);
            
            $total_items = (int) $wpdb->get_var($prepared_count_query);
            error_log('FeedService: Total items found: ' . $total_items);

            // If no items found, try to fetch fresh content
            if ($total_items == 0) {
                error_log('FeedService: No cached items found, fetching fresh content');
                $platform_errors = [];
                
                // Use parallel processing for concurrent platform fetching
                $fetch_results = $this->fetch_platforms_parallel($platforms, $types);
                
                foreach ($fetch_results as $platform => $result) {
                    if ($result['status'] === 'error') {
                        $platform_errors[$platform] = $result['message'];
                        continue;
                    }
                    
                    $platform_items = $result['data'];
                    error_log("FeedService: Fetched " . count($platform_items) . " items from $platform");
                    
                    foreach ($platform_items as $item) {
                        // For YouTube, the content is the item itself
                        $content = $item;
                        $created_at = $content['created_at'] ?? current_time('mysql');
                        
                        // Check if item already exists
                        $existing_item = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM $cache_table WHERE platform = %s AND content_id = %s",
                            $platform,
                            $content['id']
                        ));

                        if ($existing_item) {
                            // Always update with the latest content
                            $wpdb->update(
                                $cache_table,
                                [
                                    'content' => json_encode($content),
                                    'updated_at' => current_time('mysql')
                                ],
                                [
                                    'platform' => $platform,
                                    'content_id' => $content['id']
                                ],
                                ['%s', '%s'],
                                ['%s', '%s']
                            );
                            error_log("FeedService: Updated existing item with latest content");
                            continue;
                        }

                        // Insert new item
                        $insert_data = [
                            'platform' => $platform,
                            'content_type' => $content['type'] ?? 'video',
                            'content_id' => $content['id'],
                            'content' => json_encode($content),
                            'created_at' => $created_at,
                            'updated_at' => current_time('mysql'),
                            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
                        ];

                        $wpdb->insert(
                            $cache_table,
                            $insert_data,
                            ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
                        );
                        error_log("FeedService: Inserted new item");
                    }
                }
                
                // Recount after fetching
                $total_items = (int) $wpdb->get_var($prepared_count_query);
                error_log('FeedService: Total items after fetch: ' . $total_items);

                // If we have errors but also some content, include errors in response
                if (!empty($platform_errors)) {
                    error_log('FeedService: Some platforms had errors: ' . json_encode($platform_errors));
                    if ($total_items === 0) {
                        return [
                            'status' => 'error',
                            'message' => 'Failed to fetch content from all platforms',
                            'data' => [
                                'platform_errors' => $platform_errors,
                                'items' => [],
                                'pagination' => [
                                    'current_page' => $page,
                                    'total_pages' => 0,
                                    'total_items' => 0,
                                    'per_page' => $per_page
                                ]
                            ]
                        ];
                    }
                }
            }

            // Calculate offset
            $offset = ($page - 1) * $per_page;

            // Build the main query with pagination
            $query = "SELECT * FROM $cache_table $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
            
            // Add pagination parameters to query args
            $query_args[] = $per_page;
            $query_args[] = $offset;
            
            $prepared_query = $wpdb->prepare($query, $query_args);
            error_log('FeedService: Main query: ' . $prepared_query);
            
            $items = $wpdb->get_results($prepared_query, \ARRAY_A);
            if (!is_array($items)) {
                $items = [];
            }
            error_log('FeedService: Retrieved ' . count($items) . ' items');

            $formatted_items = $this->format_items($items);

            return [
                'status' => 'success',
                'data' => [
                    'items' => $formatted_items,
                    'pagination' => [
                        'current_page' => (int)$page,
                        'total_pages' => (int)ceil($total_items / $per_page),
                        'total_items' => (int)$total_items,
                        'per_page' => (int)$per_page
                    ]
                ]
            ];
        } catch (\Exception $e) {
            error_log('FeedService: Critical error in get_feeds: ' . $e->getMessage());
            error_log('FeedService: Error trace: ' . $e->getTraceAsString());
            return [
                'status' => 'error',
                'message' => 'Internal error while fetching feeds: ' . $e->getMessage(),
                'data' => [
                    'error_type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ];
        }
    }

    /**
     * Get enabled platforms from settings
     *
     * @return array
     */
    private function get_enabled_platforms() {
        $platforms = get_option('social_feed_platforms', []);
        error_log('FeedService: Retrieved platform settings: ' . json_encode($platforms));
        
        $enabled = array_keys(array_filter($platforms, function($platform, $platform_name) {
            $is_enabled = !empty($platform['enabled']);
            $has_required = true;
            
            if ($is_enabled) {
                switch ($platform_name) {
                    case 'youtube':
                        $has_required = !empty($platform['api_key']) && !empty($platform['channel_id']);
                        break;
                    case 'tiktok':
                        $has_required = !empty($platform['api_key']) && !empty($platform['access_token']);
                        break;
                    // Add other platforms as needed
                }
            }
            
            return $is_enabled && $has_required;
        }, \ARRAY_FILTER_USE_BOTH));
        
        error_log('FeedService: Enabled platforms with valid configuration: ' . json_encode($enabled));
        return $enabled;
    }

    /**
     * Generate cache key for platform and content types
     *
     * @param string $platform
     * @param array $types
     * @return string
     */
    private function generate_cache_key($platform, $types) {
        return $platform . '_' . md5(json_encode($types));
    }

    /**
     * Sort items by specified field and order
     *
     * @param array $items
     * @param string $sort
     * @param string $order
     * @return array
     */
    private function sort_items($items, $sort, $order) {
        usort($items, function($a, $b) use ($sort, $order) {
            $value_a = $this->get_sort_value($a, $sort);
            $value_b = $this->get_sort_value($b, $sort);

            if ($order === 'desc') {
                return $value_b <=> $value_a;
            }

            return $value_a <=> $value_b;
        });

        return $items;
    }

    /**
     * Get sort value from item
     *
     * @param array $item
     * @param string $sort
     * @return mixed
     */
    private function get_sort_value($item, $sort) {
        switch ($sort) {
            case 'date':
                return strtotime($item['content']['created_at']);
            case 'popularity':
                $engagement = $item['content']['engagement'];
                return $engagement['likes'] + $engagement['comments'] + $engagement['shares'];
            default:
                return 0;
        }
    }

    /**
     * Fetch content from multiple platforms in parallel using async processing
     *
     * @param array $platforms List of platforms to fetch from
     * @param array $types Content types to fetch
     * @return array Results indexed by platform name
     */
    private function fetch_platforms_parallel($platforms, $types) {
        $results = [];
        $start_time = microtime(true);
        
        // For PHP environments without true async support, we'll use optimized sequential processing
        // with performance monitoring and error isolation
        foreach ($platforms as $platform) {
            $platform_start = microtime(true);
            
            try {
                $platform_handler = $this->platform_factory->get_platform($platform);
                if (!$platform_handler) {
                    error_log("FeedService: Platform handler not found for $platform");
                    $results[$platform] = [
                        'status' => 'error',
                        'message' => 'Platform handler not found',
                        'data' => []
                    ];
                    continue;
                }

                if (!method_exists($platform_handler, 'is_configured') || !$platform_handler->is_configured()) {
                    error_log("FeedService: Platform $platform is not properly configured");
                    $results[$platform] = [
                        'status' => 'error',
                        'message' => 'Platform is not properly configured',
                        'data' => []
                    ];
                    continue;
                }

                // Set timeout for individual platform requests
                $original_timeout = ini_get('max_execution_time');
                set_time_limit(30); // 30 seconds per platform
                
                $platform_items = $platform_handler->get_feed($types);
                
                // Restore original timeout
                set_time_limit($original_timeout);
                
                if (!is_array($platform_items)) {
                    error_log("FeedService: Invalid response from $platform platform");
                    $results[$platform] = [
                        'status' => 'error',
                        'message' => 'Invalid platform response',
                        'data' => []
                    ];
                    continue;
                }

                $platform_time = microtime(true) - $platform_start;
                error_log("FeedService: Platform $platform completed in {$platform_time}s with " . count($platform_items) . " items");
                
                $results[$platform] = [
                    'status' => 'success',
                    'message' => 'Content fetched successfully',
                    'data' => $platform_items,
                    'performance' => [
                        'execution_time' => $platform_time,
                        'items_count' => count($platform_items),
                        'items_per_second' => count($platform_items) / max($platform_time, 0.001)
                    ]
                ];
                
            } catch (\Exception $e) {
                $platform_time = microtime(true) - $platform_start;
                error_log("FeedService: Error fetching from $platform: " . $e->getMessage());
                error_log("FeedService: Error trace: " . $e->getTraceAsString());
                
                $results[$platform] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'data' => [],
                    'performance' => [
                        'execution_time' => $platform_time,
                        'error_type' => get_class($e)
                    ]
                ];
            }
        }
        
        $total_time = microtime(true) - $start_time;
        $successful_platforms = array_filter($results, function($result) {
            return $result['status'] === 'success';
        });
        
        error_log("FeedService: Parallel fetch completed in {$total_time}s. Success: " . 
                 count($successful_platforms) . "/" . count($platforms) . " platforms");
        
        return $results;
    }

    /**
     * Process items
     */
    private function format_items($items) {
        return array_map(function($item) {
            $content = json_decode($item['content'], true);
            return [
                'id' => $item['content_id'],
                'platform' => $item['platform'],
                'type' => $item['content_type'],
                'content' => [
                    'title' => $content['title'] ?? '',
                    'description' => $content['description'] ?? '',
                    'media_url' => $content['media_url'] ?? '',
                    'thumbnail_url' => $content['thumbnail_url'] ?? '',
                    'original_url' => $content['original_url'] ?? '',
                    'created_at' => $content['created_at'] ?? '',
                    'engagement' => [
                        'likes' => !empty($content['likes']) ? (string)$content['likes'] : '0',
                        'comments' => !empty($content['comments']) ? (string)$content['comments'] : '0',
                        'shares' => !empty($content['shares']) ? (int)$content['shares'] : 0
                    ]
                ],
                'author' => [
                    'name' => $content['author_name'] ?? '',
                    'avatar' => $content['author_avatar'] ?? '',
                    'profile_url' => $content['author_profile'] ?? ''
                ]
            ];
        }, $items);
    }
}