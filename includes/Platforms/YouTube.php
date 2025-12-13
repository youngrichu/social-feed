<?php
namespace SocialFeed\Platforms;

use SocialFeed\Core\ContentProcessor;
use SocialFeed\Core\CacheManager;
use SocialFeed\Core\PerformanceMonitor;
use SocialFeed\Core\RequestOptimizer;
use SocialFeed\Core\NotificationHandler;
use SocialFeed\Core\QuotaManager;

class YouTube extends AbstractPlatform
{
    /**
     * @var string YouTube Data API base URL
     */
    const API_BASE_URL = 'https://www.googleapis.com/youtube/v3';

    /**
     * Constants for rate limiting and quota management
     */
    const QUOTA_LIMIT_PER_DAY = 10000; // Default YouTube API quota limit
    const QUOTA_COSTS = [
        'search' => 100,
        'videos' => 1,
        'channels' => 1,
        'playlistItems' => 1
    ];
    const MIN_CHECK_INTERVAL = 900;    // Reduced to 15 minutes
    const MAX_CHECK_INTERVAL = 3600;   // Reduced to 1 hour
    const QUOTA_EXCEEDED_LOCKOUT = 86400; // 24 hours in seconds

    /**
     * Constants for content checking intervals
     */
    const CHECK_NEW_VIDEOS_INTERVAL = 900;    // Reduced to 15 minutes
    const CHECK_LIVE_STREAMS_INTERVAL = 300;  // Reduced to 5 minutes
    const CACHE_PREFIX = 'youtube_last_';

    /**
     * Cache duration constants
     */
    const CACHE_DURATION = [
        'historical' => MONTH_IN_SECONDS,    // 30 days for historical videos
        'recent' => DAY_IN_SECONDS,          // 24 hours for recent videos
        'live' => HOUR_IN_SECONDS,           // 1 hour for live content
    ];

    /**
     * @var ContentProcessor
     */
    private $content_processor;

    /**
     * @var CacheManager
     */
    private $cache_manager;

    /**
     * @var PerformanceMonitor
     */

    /**
     * @var RequestOptimizer
     */
    private $request_optimizer;

    /**
     * @var NotificationHandler
     */
    private $notification_handler;

    /**
     * @var QuotaManager
     */
    private $quota_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Set platform identifier first
        $this->platform = 'youtube';

        // Initialize parent class
        parent::__construct();

        // Initialize components
        try {
            $this->content_processor = new ContentProcessor();
            $this->cache_manager = new CacheManager('youtube');
            $this->request_optimizer = new RequestOptimizer();
            $this->notification_handler = new NotificationHandler();
            $this->quota_manager = new QuotaManager();
        } catch (\Exception $e) {
            // Silently fail component initialization - will retry on next use
        }
    }

    /**
     * Initialize the platform
     */
    public function init()
    {
        static $initialized = false;

        if ($initialized) {
            return;
        }

        if (!$this->is_configured()) {
            return;
        }



        // Add action hooks for scheduled events
        add_action('social_feed_youtube_check_new_videos', [$this, 'check_new_videos']);
        add_action('social_feed_youtube_check_live_streams', [$this, 'check_live_streams']);

        $initialized = true;
    }

    /**
     * Check if platform is configured properly
     *
     * @return bool
     */
    public function is_configured()
    {
        $options = get_option('social_feed_platforms', []);
        $config = $options['youtube'] ?? [];

        if (empty($config['enabled']) || empty($config['api_key']) || empty($config['channel_id'])) {
            return false;
        }

        $this->config = $config;
        return true;
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules
     * @return array
     */
    public function add_cron_schedules($schedules)
    {
        $schedules['five_minutes'] = [
            'interval' => 300,
            'display' => 'Every 5 Minutes'
        ];
        $schedules['fifteen_minutes'] = [
            'interval' => 900,
            'display' => 'Every 15 Minutes'
        ];
        return $schedules;
    }

    /**
     * Check for new videos and update cache
     */
    public function check_new_videos()
    {

        // Check if platform is configured
        if (!$this->is_configured()) {
            return false;
        }

        try {
            // Get videos
            $videos = $this->get_videos(1); // Start with just 1 page for testing

            if (empty($videos)) {
                return false;
            }


            // Store videos in cache
            global $wpdb;
            $cache_table = $wpdb->prefix . 'social_feed_cache';

            foreach ($videos as $video) {

                // Check if video already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $cache_table WHERE platform = %s AND content_id = %s",
                    'youtube',
                    $video['id']
                ));

                if ($exists) {
                    continue;
                }

                $insert_data = [
                    'platform' => 'youtube',
                    'content_type' => 'video',
                    'content_id' => $video['id'],
                    'content' => json_encode($video),
                    'created_at' => $video['created_at'],
                    'updated_at' => current_time('mysql'),
                    'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
                ];



                $result = $wpdb->insert(
                    $cache_table,
                    $insert_data,
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
                );

                if ($result === false) {
                    error_log('YouTube: Failed to insert video: ' . $wpdb->last_error);
                }
            }

            return true;
        } catch (\Exception $e) {
            error_log('YouTube: Error in check_new_videos: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get details for a single video
     */
    /**
     * Get details for multiple videos in a single request
     *
     * @param array $video_ids Array of video IDs
     * @return array
     */
    private function get_batch_video_details($video_ids)
    {
        if (empty($video_ids)) {
            return [];
        }

        // Chunk IDs into groups of 50 (YouTube API limit)
        $chunks = array_chunk($video_ids, 50);
        $all_items = [];

        foreach ($chunks as $chunk) {
            $params = [
                'part' => 'snippet,statistics,contentDetails,liveStreamingDetails',
                'id' => implode(',', $chunk),
                'key' => $this->config['api_key']
            ];

            $data = $this->make_api_request(self::API_BASE_URL . '/videos', $params, 'videos');
            if (!empty($data['items'])) {
                $all_items = array_merge($all_items, $data['items']);
            }
        }

        return $all_items;
    }

    /**
     * Get details for a single video
     */
    private function get_video_details($video_id)
    {
        $items = $this->get_batch_video_details([$video_id]);
        return !empty($items[0]) ? $items[0] : null;
    }

    /**
     * Check for live streams and update cache
     */
    public function check_live_streams()
    {
        // Check if live stream checking is enabled
        if (empty($this->config['enable_live_check'])) {
            return;
        }

        if (!$this->is_configured()) {
            return;
        }

        // Check if we're in quota exceeded state
        $quota_exceeded_key = 'youtube_quota_exceeded_' . date('Y-m-d');
        if (get_transient($quota_exceeded_key)) {
            return;
        }

        try {
            // First check if we have any known live or upcoming streams in cache
            global $wpdb;
            $table = $wpdb->prefix . 'social_feed_streams';
            $active_streams = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE platform = %s AND status IN ('live', 'upcoming') ORDER BY scheduled_for ASC",
                    'youtube'
                ),
                ARRAY_A
            );

            // If we have active streams, check their status in batches
            if (!empty($active_streams)) {
                $stream_ids = array_column($active_streams, 'stream_id');
                // Check quota for the number of requests we need (1 request per 50 items)
                $required_requests = ceil(count($stream_ids) / 50);

                // We treat batch updates as 'videos' operation cost (1 unit per page of 50)
                // This is a massive saving compared to N * 1
                for ($i = 0; $i < $required_requests; $i++) {
                    if (!$this->check_quota('videos')) {
                        return;
                    }
                }

                $videos_data = $this->get_batch_video_details($stream_ids);

                // Map results by ID for easy lookup
                $videos_map = [];
                foreach ($videos_data as $video) {
                    $videos_map[$video['id']] = $video;
                }

                foreach ($active_streams as $stream) {
                    if (isset($videos_map[$stream['stream_id']])) {
                        $this->update_stream_status($stream, $videos_map[$stream['stream_id']]);
                    }
                }
            }

            // Only search for new streams if we haven't exceeded our quota
            if ($this->check_quota('search')) {
                // Collect all video IDs from searches to process in one batch
                $video_ids_to_process = [];

                // 1. Search for LIVE
                $params = [
                    'part' => 'snippet',
                    'channelId' => $this->config['channel_id'],
                    'eventType' => 'live',
                    'type' => 'video',
                    'key' => $this->config['api_key'],
                    'maxResults' => 50
                ];
                $live_data = $this->make_api_request(self::API_BASE_URL . '/search', $params, 'search');
                if (!empty($live_data['items'])) {
                    foreach ($live_data['items'] as $item) {
                        $video_ids_to_process[] = $item['id']['videoId'];
                    }
                }

                // 2. Search for UPCOMING (if quota allows)
                if ($this->check_quota('search')) {
                    $params['eventType'] = 'upcoming';
                    $upcoming_data = $this->make_api_request(self::API_BASE_URL . '/search', $params, 'search');
                    if (!empty($upcoming_data['items'])) {
                        foreach ($upcoming_data['items'] as $item) {
                            $video_ids_to_process[] = $item['id']['videoId'];
                        }
                    }
                }

                // 3. Search for COMPLETED (if quota allows)
                if ($this->check_quota('search')) {
                    $params['eventType'] = 'completed';
                    $params['publishedAfter'] = date('c', strtotime('-7 days'));
                    $completed_data = $this->make_api_request(self::API_BASE_URL . '/search', $params, 'search');
                    if (!empty($completed_data['items'])) {
                        foreach ($completed_data['items'] as $item) {
                            $video_ids_to_process[] = $item['id']['videoId'];
                        }
                    }
                }

                // Process all found videos in batches
                if (!empty($video_ids_to_process)) {
                    $unique_ids = array_unique($video_ids_to_process);

                    // Check quota for details fetch
                    $details_requests = ceil(count($unique_ids) / 50);
                    for ($i = 0; $i < $details_requests; $i++) {
                        if (!$this->check_quota('videos')) {
                            break; // Stop if we run out of quota mid-batch
                        }
                    }

                    $videos_details = $this->get_batch_video_details($unique_ids);
                    foreach ($videos_details as $video_data) {
                        $this->process_stream_data($video_data);
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('YouTube: Error checking live streams: ' . $e->getMessage());
        }
    }

    /**
     * Process stream data and update status
     * 
     * @param array $video_data Video details from API
     */
    private function process_stream_data($video_data)
    {
        try {
            $video_id = $video_data['id'];

            // Only process if it has live streaming details or is a completed live stream
            $streaming_details = $video_data['liveStreamingDetails'] ?? [];
            if (empty($streaming_details)) {
                // Check if this was a live stream by looking at the video title and liveBroadcastContent
                if (
                    strpos($video_data['snippet']['title'], 'ቅዳሴ') === false &&
                    strpos($video_data['snippet']['title'], 'የካቲት') === false &&
                    $video_data['snippet']['liveBroadcastContent'] === 'none'
                ) {
                    return;
                }

                // If it's a liturgical video but missing streaming details, try to infer them
                $published_time = new \DateTime($video_data['snippet']['publishedAt']);
                $scheduled_time = clone $published_time;
                $end_time = clone $published_time;
                $end_time->modify('+3 hours');

                // For liturgical services, we assume they start at the scheduled time
                $streaming_details = [
                    'scheduledStartTime' => $scheduled_time->format('c'),
                    'actualStartTime' => $scheduled_time->format('c'),
                    'actualEndTime' => $end_time->format('c'),
                    'concurrentViewers' => $video_data['statistics']['viewCount'] ?? 0
                ];
            }

            global $wpdb;
            $table = $wpdb->prefix . 'social_feed_streams';

            // Check if stream already exists
            $existing_stream = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $table WHERE platform = %s AND stream_id = %s",
                    'youtube',
                    $video_id
                ),
                ARRAY_A
            );

            // Add streaming details to video data
            $video_data['liveStreamingDetails'] = $streaming_details;

            if ($existing_stream) {
                $this->update_stream_status($existing_stream, $video_data);
            } else {
                $this->insert_new_stream($video_data);
            }

        } catch (\Exception $e) {
            error_log('YouTube: Error processing stream data ' . ($video_data['id'] ?? 'unknown') . ': ' . $e->getMessage());
        }
    }

    /**
     * Legacy method wrapper for backward compatibility
     */
    private function process_live_stream($video_id)
    {
        $video_data = $this->get_video_details($video_id);
        if ($video_data) {
            $this->process_stream_data($video_data);
        }
    }

    /**
     * Enhanced API request with optimization
     */
    private function make_api_request($endpoint, $params = [], $operation = 'videos')
    {
        // Check optimal timing through RequestOptimizer
        $timing = $this->request_optimizer->get_optimal_timing($operation);

        if ($timing === 'quota_exceeded') {
            return false;
        }

        // Estimate quota cost and check if safe to proceed
        $quota_estimate = $this->quota_manager->estimate_quota_cost([$operation]);
        if (!$quota_estimate['is_safe']) {
            return false;
        }

        // Get current quota status
        $quota_stats = $this->quota_manager->get_detailed_stats();
        $quota_status = $quota_stats['status'];

        // Apply timing restrictions based on quota status
        switch ($quota_status) {
            case 'critical':
                if ($this->quota_manager->get_operation_priority($operation) !== 'high') {
                    return false;
                }
                sleep(10); // Maximum delay for critical status
                break;
            case 'high':
                if ($this->quota_manager->get_operation_priority($operation) === 'low') {
                    return false;
                }
                sleep(5); // High restriction delay
                break;
            case 'moderate':
                sleep(2); // Moderate restriction delay
                break;
        }

        // Optimize request parameters
        $params = $this->request_optimizer->optimize_params($params, $operation);

        try {
            // Ensure we have the API key
            if (!isset($params['key']) && isset($this->config['api_key'])) {
                $params['key'] = $this->config['api_key'];
            }

            $url = (strpos($endpoint, 'http') === 0) ? $endpoint : self::API_BASE_URL . '/' . $endpoint;
            $full_url = add_query_arg($params, $url);

            // Use output buffering to prevent debug output
            ob_start();
            $start_time = microtime(true);
            $response = wp_remote_get($full_url);
            $duration = microtime(true) - $start_time;
            ob_end_clean();

            // Record performance metric
            $this->performance_monitor->record_api_response_time(
                $full_url,
                $duration,
                is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response)
            );

            if (is_wp_error($response)) {
                error_log("YouTube API Error: " . $response->get_error_message());
                return false;
            }

            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($status !== 200) {
                if ($status === 429) {
                    $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                    set_transient("social_feed_rate_{$this->platform}", true, $retry_after ?: 60);

                }

                return false;
            }

            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {

                return false;
            }

            if (!empty($data['error'])) {
                error_log("YouTube API Error: " . json_encode($data['error']));
                return false;
            }

            // Update quota usage only on successful request
            $this->quota_manager->check_quota($operation);

            return $data;

        } catch (\Exception $e) {
            error_log("YouTube API Exception: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Make HTTP request with exponential backoff retry logic (YouTube-specific)
     *
     * @param string $url
     * @param array $args
     * @param int $max_retries Maximum number of retry attempts (default: 3)
     * @return array
     * @throws \Exception
     */
    protected function make_request($url, $args = [], $max_retries = 3)
    {


        // Convert YouTube-specific args format to standard format for parent method
        $standard_args = [];
        if (isset($args['body'])) {
            // For YouTube API, we need to append query parameters to URL
            $url_with_params = $url . '?' . http_build_query($args['body']);
            $standard_args = array_merge($args, ['method' => 'GET']);
            unset($standard_args['body']);

            // Use parent class retry logic with YouTube-specific URL handling
            return parent::make_request($url_with_params, $standard_args, $max_retries);
        }

        // Fallback to parent method for standard requests
        return parent::make_request($url, $args, $max_retries);
    }

    /**
     * Enhanced content fetching with performance monitoring
     */
    public function get_feed($types = [], $args = [])
    {
        try {
            $start_time = microtime(true);
            $feed_items = [];
            $fetched_types = [];

            // Extract args
            $max_pages = $args['max_pages'] ?? 5;
            $playlist_id = $args['playlist'] ?? null;

            // If a specific playlist is requested, fetch from that playlist
            if ($playlist_id) {

                $feed_items = $this->get_playlist_items($playlist_id, $max_pages);

                return $feed_items;
            }

            // Determine which content types to fetch
            $fetch_types = empty($types) ? $this->get_supported_types() : array_intersect($types, $this->get_supported_types());



            // Get videos
            if (in_array('video', $fetch_types) || in_array('short', $fetch_types)) {
                $videos = $this->get_videos($max_pages);
                $feed_items = array_merge($feed_items, $videos);
                $fetched_types[] = 'video';
                $fetched_types[] = 'short';
            }

            // Get live streams (and upcoming streams)
            if (in_array('live', $fetch_types)) {
                $live_streams = $this->get_live_broadcasts();
                $feed_items = array_merge($feed_items, $live_streams);
                $fetched_types[] = 'live';
            }

            // Standardize all existing videos in cache even if not fetched
            $this->standardize_cached_videos();



            return $feed_items;
        } catch (\Exception $e) {
            error_log('YouTube: Error fetching feed - ' . $e->getMessage());
            return [];
        }
    }


    /**
     * Standardize all cached videos to ensure consistent structure
     */
    private function standardize_cached_videos()
    {
        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';

        // Get all cached videos
        $videos = $wpdb->get_results($wpdb->prepare(
            "SELECT id, content_id, content FROM $cache_table WHERE platform = %s",
            'youtube'
        ));

        if (empty($videos)) {
            return;
        }



        foreach ($videos as $video) {
            $content = json_decode($video->content, true);
            if (empty($content)) {
                continue;
            }

            // Check for missing fields
            $updated = false;

            // Add media_url and original_url if missing
            if (empty($content['media_url']) || empty($content['original_url'])) {
                $content['media_url'] = "https://www.youtube.com/watch?v={$content['id']}";
                $content['original_url'] = "https://www.youtube.com/watch?v={$content['id']}";
                $updated = true;

            }

            // Ensure engagement data structure
            if (empty($content['engagement'])) {
                $content['engagement'] = [
                    'views' => $content['views'] ?? 0,
                    'likes' => $content['likes'] ?? 0,
                    'comments' => $content['comments'] ?? 0,
                    'shares' => $content['shares'] ?? 0
                ];
                $updated = true;

            }

            // Ensure individual stats
            if (
                !isset($content['views']) || !isset($content['likes']) ||
                !isset($content['comments']) || !isset($content['shares'])
            ) {
                $content['views'] = $content['engagement']['views'] ?? 0;
                $content['likes'] = $content['engagement']['likes'] ?? 0;
                $content['comments'] = $content['engagement']['comments'] ?? 0;
                $content['shares'] = $content['engagement']['shares'] ?? 0;
                $updated = true;

            }

            // Ensure author info
            if (empty($content['author_name'])) {
                $content['author_name'] = 'Dubai Debre Mewi';
                $updated = true;

            }

            if (empty($content['author_profile'])) {
                $content['author_profile'] = 'https://www.youtube.com/channel/UC5D_jWcqHBVu18iDtxxx_mQ';
                $updated = true;

            }

            if (!isset($content['author_avatar'])) {
                $content['author_avatar'] = '';
                $updated = true;

            }

            // Ensure metadata
            if (empty($content['metadata'])) {
                $content['metadata'] = [
                    'is_long_form' => $content['type'] === 'video',
                    'has_chapters' => [],
                    'category' => 'other'
                ];
                $updated = true;

            }

            // Ensure updated_at field
            if (empty($content['updated_at'])) {
                $content['updated_at'] = current_time('mysql');
                $updated = true;

            }

            // If any fields were updated, save the changes
            if ($updated) {
                $wpdb->update(
                    $cache_table,
                    ['content' => json_encode($content)],
                    ['id' => $video->id],
                    ['%s'],
                    ['%d']
                );

            }
        }
    }

    /**
     * Check if operation is essential
     */
    private function is_essential_operation($operation)
    {
        return in_array($operation, ['playlistItems', 'videos']);
    }

    /**
     * Get stream status
     *
     * @param string $stream_id
     * @return array|null
     */
    public function get_stream_status($stream_id)
    {
        if (!$this->is_configured()) {
            return null;
        }

        try {
            $response = $this->make_request(
                self::API_BASE_URL . '/videos',
                [
                    'method' => 'GET',
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                    'body' => [
                        'part' => 'snippet,liveStreamingDetails',
                        'id' => $stream_id,
                        'key' => $this->config['api_key'],
                    ],
                ]
            );

            if (empty($response['items'])) {
                return null;
            }

            $video = $response['items'][0];
            $streaming_details = $video['liveStreamingDetails'] ?? [];

            return $this->format_stream([
                'id' => $video['id'],
                'title' => $video['snippet']['title'],
                'description' => $video['snippet']['description'],
                'thumbnail_url' => $video['snippet']['thumbnails']['high']['url'],
                'stream_url' => "https://www.youtube.com/watch?v={$video['id']}",
                'status' => $this->get_stream_status_from_details($streaming_details),
                'viewer_count' => $streaming_details['concurrentViewers'] ?? 0,
                'started_at' => $streaming_details['actualStartTime'] ?? null,
                'scheduled_for' => $streaming_details['scheduledStartTime'] ?? null,
                'channel_name' => $video['snippet']['channelTitle'],
                'channel_avatar' => $this->get_channel_avatar($video['snippet']['channelId']),
            ]);
        } catch (\Exception $e) {
            $this->log_error("Error fetching stream status: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate platform configuration
     *
     * @param array $config
     * @return bool
     */
    public function validate_config($config)
    {
        return !empty($config['api_key']) && !empty($config['channel_id']);
    }

    /**
     * Get supported content types
     *
     * @return array
     */
    public function get_supported_types()
    {
        return ['video', 'short', 'live'];
    }

    /**
     * Get memory limit in bytes
     *
     * @return int
     */
    private function get_memory_limit()
    {
        $memory_limit = ini_get('memory_limit');

        if ($memory_limit === '-1') {
            // No memory limit
            return PHP_INT_MAX;
        }

        // Convert memory limit to bytes
        $unit = strtolower(substr($memory_limit, -1));
        $value = (int) substr($memory_limit, 0, -1);

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
     * Calculate optimal check interval based on quota usage
     *
     * @return int Interval in seconds
     */
    public function calculate_optimal_interval()
    {
        $quota_stats = $this->get_quota_stats();
        $today = date('Y-m-d');
        $current_usage = isset($quota_stats[$today]) ? ($quota_stats[$today]['usage'] ?? 0) : 0;

        // Calculate percentage of quota used
        $quota_percentage = ($current_usage / self::QUOTA_LIMIT_PER_DAY) * 100;

        // Get remaining hours in the day
        $now = new \DateTime();
        $end_of_day = new \DateTime('tomorrow midnight');
        $hours_remaining = ($end_of_day->getTimestamp() - $now->getTimestamp()) / 3600;

        // Calculate remaining quota
        $remaining_quota = self::QUOTA_LIMIT_PER_DAY - $current_usage;

        // Cost per check (search + average video details)
        $cost_per_check = self::QUOTA_COSTS['search'] + (self::QUOTA_COSTS['videos'] * 5);

        // Calculate how many checks we can still do
        $possible_checks = floor($remaining_quota / $cost_per_check);

        // Calculate optimal interval
        if ($possible_checks <= 0 || $hours_remaining <= 0) {
            return self::MAX_CHECK_INTERVAL;
        }

        $optimal_interval = ceil(($hours_remaining * 3600) / $possible_checks);

        // Adjust interval based on quota usage percentage
        if ($quota_percentage >= 90) {
            $optimal_interval *= 2; // Double the interval if quota usage is critical
        } elseif ($quota_percentage >= 75) {
            $optimal_interval *= 1.5; // Increase interval by 50% if quota usage is high
        }

        // Ensure interval stays within bounds
        return max(self::MIN_CHECK_INTERVAL, min(self::MAX_CHECK_INTERVAL, $optimal_interval));
    }

    /**
     * Get current quota usage percentage
     *
     * @return float
     */
    public function get_quota_usage_percentage()
    {
        $quota_stats = $this->get_quota_stats();
        $today = date('Y-m-d');
        $current_usage = isset($quota_stats[$today]) ? ($quota_stats[$today]['usage'] ?? 0) : 0;
        return ($current_usage / self::QUOTA_LIMIT_PER_DAY) * 100;
    }

    /**
     * Check if we have enough quota for an operation
     *
     * @param string $operation
     * @return bool
     */
    private function check_quota($operation)
    {
        try {
            return $this->quota_manager->check_quota($operation);
        } catch (\Exception $e) {
            error_log('YouTube Quota Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get quota usage statistics
     *
     * @return array
     */
    public function get_quota_stats()
    {
        $quota_stats = get_option('youtube_quota_stats', []);
        $today = date('Y-m-d');

        if (!isset($quota_stats[$today])) {
            $quota_stats[$today] = [
                'usage' => $this->get_current_quota_usage(),
                'operations' => [],
                'last_update' => current_time('mysql')
            ];
            update_option('youtube_quota_stats', $quota_stats);
        }

        return $quota_stats;
    }

    /**
     * Get current quota usage
     *
     * @return int
     */
    private function get_current_quota_usage()
    {
        $quota_key = 'youtube_quota_usage_' . date('Y-m-d');
        return get_transient($quota_key) ?: 0;
    }

    /**
     * Test if the API key is valid
     *
     * @return bool
     */
    private function test_api_key()
    {
        try {
            error_log('YouTube: Testing API key with minimal request');

            // Use a minimal API call to test the key
            $params = [
                'part' => 'contentDetails',
                'id' => $this->config['channel_id'],
                'key' => $this->config['api_key']
            ];

            $data = $this->make_api_request(self::API_BASE_URL . '/channels', $params, 'channels');
            $success = !empty($data['items'][0]['contentDetails']);

            return $success;
        } catch (\Exception $e) {
            error_log('YouTube: API key test failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset quota usage
     *
     * @return bool
     */
    public function reset_quota()
    {
        $quota_key = 'youtube_quota_usage_' . date('Y-m-d');
        delete_transient($quota_key);

        $quota_stats = get_option('youtube_quota_stats', []);
        $today = date('Y-m-d');

        $quota_stats[$today] = [
            'usage' => 0,
            'operations' => [],
            'reset_at' => current_time('mysql'),
            'last_update' => current_time('mysql')
        ];

        update_option('youtube_quota_stats', $quota_stats);

        return true;
    }

    /**
     * Get videos from YouTube
     *
     * @param int $max_pages Maximum number of pages to fetch
     * @return array
     */
    public function get_videos($max_pages = 5)
    {
        $all_items = [];
        $next_page_token = null;
        $page_count = 0;
        $video_ids = [];

        try {

            // Get uploads playlist ID with longer cache duration
            $uploads_playlist_id = get_transient('youtube_uploads_playlist_' . $this->config['channel_id']);

            if (!$uploads_playlist_id && $this->check_quota('channels')) {
                $channel_params = [
                    'part' => 'contentDetails',
                    'id' => $this->config['channel_id'],
                    'key' => $this->config['api_key']
                ];

                $channel_data = $this->make_api_request(self::API_BASE_URL . '/channels', $channel_params, 'channels');
                if (!empty($channel_data['items'][0]['contentDetails']['relatedPlaylists']['uploads'])) {
                    $uploads_playlist_id = $channel_data['items'][0]['contentDetails']['relatedPlaylists']['uploads'];
                    set_transient('youtube_uploads_playlist_' . $this->config['channel_id'], $uploads_playlist_id, WEEK_IN_SECONDS);
                } else {
                    return [];
                }
            }

            if (!$uploads_playlist_id) {
                return [];
            }

            // Check if we have any cached videos
            global $wpdb;
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            $cached_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $cache_table WHERE platform = %s AND content_type = %s",
                'youtube',
                'video'
            ));


            // For initial fetch, get all videos. For updates, only get recent ones
            $is_initial_fetch = ($cached_count == 0);

            // For initial fetch, increase max_pages to get more historical content
            if ($is_initial_fetch) {
                $max_pages = 20; // Fetch up to 1000 videos (20 pages × 50 videos per page)
            }

            // STEP 1: Get all video IDs from the playlist
            do {
                if (!$this->check_quota('playlistItems')) {
                    break;
                }

                $params = [
                    'part' => 'snippet',
                    'playlistId' => $uploads_playlist_id,
                    'maxResults' => 50,
                    'key' => $this->config['api_key']
                ];

                if ($next_page_token) {
                    $params['pageToken'] = $next_page_token;
                }

                $data = $this->make_api_request(self::API_BASE_URL . '/playlistItems', $params, 'playlistItems');

                if (!empty($data['items'])) {
                    foreach ($data['items'] as $item) {
                        if (!empty($item['snippet']['resourceId']['videoId'])) {
                            $published_at = strtotime($item['snippet']['publishedAt']);

                            // For initial fetch, get all videos
                            // For updates, only get videos from last 24 hours
                            if ($is_initial_fetch || $published_at >= strtotime('-24 hours')) {
                                $video_ids[] = $item['snippet']['resourceId']['videoId'];
                            }
                        }
                    }
                }

                $next_page_token = $data['nextPageToken'] ?? null;
                $page_count++;

                // For initial fetch, continue until max_pages or no more results
                // For updates, stop after first page if no recent videos
                if (!$is_initial_fetch && empty($video_ids) && $page_count >= 1) {
                    break;
                }

                if ($page_count >= $max_pages || !$next_page_token) {
                    break;
                }

            } while (true);

            // If no video IDs found, return empty array
            if (empty($video_ids)) {
                return [];
            }


            // STEP 2: Get complete video details in batches of 50 with memory optimization
            $unique_ids = array_unique($video_ids);
            $video_batches = array_chunk($unique_ids, 50);

            // Memory optimization: Track memory usage and implement streaming processing
            $initial_memory = memory_get_usage(true);
            $memory_limit = $this->get_memory_limit();
            $processed_count = 0;


            foreach ($video_batches as $batch_index => $batch) {
                // Check memory usage before processing each batch
                $current_memory = memory_get_usage(true);
                $memory_usage_percent = ($current_memory / $memory_limit) * 100;

                if ($memory_usage_percent > 80) {

                    // Force garbage collection
                    gc_collect_cycles();

                    // If still high memory usage, reduce batch size or stop processing
                    $current_memory = memory_get_usage(true);
                    $memory_usage_percent = ($current_memory / $memory_limit) * 100;

                    if ($memory_usage_percent > 85) {
                        break;
                    }
                }

                if (!$this->check_quota('videos')) {
                    break;
                }

                // IMPORTANT: Request ALL required parts to get complete data
                $params = [
                    'part' => 'snippet,statistics,contentDetails,status',
                    'id' => implode(',', $batch),
                    'key' => $this->config['api_key']
                ];


                $batch_data = $this->make_api_request(self::API_BASE_URL . '/videos', $params, 'videos');

                if (!empty($batch_data['items'])) {
                    // Process videos in streaming fashion to minimize memory footprint
                    foreach ($batch_data['items'] as $video) {
                        // Skip processing if critical data is missing
                        if (empty($video['snippet'])) {
                            continue;
                        }

                        // Statistics might be missing for some videos, ignore silently
                        // if (empty($video['statistics'])) { }

                        // Format the video data with ALL required fields
                        $formatted_item = $this->format_feed_item($video, 'video');
                        if ($formatted_item) {
                            // Add to return array
                            $all_items[] = $formatted_item;

                            // Store in cache with complete data
                            $this->store_video_in_cache($formatted_item);

                            $processed_count++;

                            // Log progress every 50 videos
                            if ($processed_count % 50 === 0) {
                                $current_memory = memory_get_usage(true);
                            }
                        }

                        // Clear video data from memory immediately after processing
                        unset($video, $formatted_item);
                    }

                    // Clear batch data from memory
                    unset($batch_data);

                } else {
                }

                // Clear batch from memory
                unset($batch);

                // Periodic garbage collection every 5 batches
                if (($batch_index + 1) % 5 === 0) {
                    gc_collect_cycles();
                }
            }

            // Final memory usage report
            $final_memory = memory_get_usage(true);
            $peak_memory = memory_get_peak_usage(true);

            return $all_items;

        } catch (\Exception $e) {
            error_log('YouTube API Error in get_videos: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Store video in cache with proper engagement data
     */
    private function store_video_in_cache($video_data)
    {
        if (empty($video_data['id'])) {
            return false;
        }

        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';

        // Ensure all required fields are present
        $required_fields = [
            'id',
            'type',
            'title',
            'description',
            'media_url',
            'thumbnail_url',
            'original_url',
            'created_at',
            'updated_at',
            'engagement',
            'views',
            'likes',
            'comments',
            'shares',
            'author_name',
            'author_avatar',
            'author_profile',
            'duration',
            'metadata'
        ];

        foreach ($required_fields as $field) {
            if (!isset($video_data[$field])) {
                error_log("YouTube: Missing required field {$field} for video {$video_data['id']}");

                // Set default values for missing fields
                switch ($field) {
                    case 'engagement':
                        $video_data[$field] = [
                            'views' => $video_data['views'] ?? 0,
                            'likes' => $video_data['likes'] ?? 0,
                            'comments' => $video_data['comments'] ?? 0,
                            'shares' => $video_data['shares'] ?? 0
                        ];
                        break;
                    case 'metadata':
                        $video_data[$field] = [
                            'is_long_form' => $video_data['type'] === 'video',
                            'has_chapters' => [],
                            'category' => 'other'
                        ];
                        break;
                    case 'updated_at':
                        $video_data[$field] = current_time('mysql');
                        break;
                    default:
                        $video_data[$field] = '';
                }
            }
        }

        // Determine cache duration
        $cache_duration = $this->determine_cache_duration($video_data);

        $data = [
            'platform' => 'youtube',
            'content_type' => $video_data['type'],
            'content_id' => $video_data['id'],
            'content' => json_encode($video_data),
            'created_at' => $video_data['created_at'],
            'updated_at' => current_time('mysql'),
            'expires_at' => date('Y-m-d H:i:s', time() + $cache_duration)
        ];

        // Check if video already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, content FROM $cache_table WHERE platform = %s AND content_id = %s",
            'youtube',
            $video_data['id']
        ));

        if ($existing) {
            // Update existing entry
            $wpdb->update(
                $cache_table,
                [
                    'content' => json_encode($video_data),
                    'updated_at' => current_time('mysql'),
                    'expires_at' => $data['expires_at']
                ],
                ['id' => $existing->id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Insert new entry
            $result = $wpdb->insert(
                $cache_table,
                $data,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            if ($result !== false) {

                // CRITICAL FIX: Trigger notification for new video
                try {
                    // Prepare content data for notification
                    $notification_content = [
                        'id' => $video_data['id'],
                        'platform' => 'youtube',
                        'type' => $video_data['type'] ?? 'video',
                        'title' => $video_data['title'] ?? '',
                        'description' => $video_data['description'] ?? '',
                        'media_url' => $video_data['media_url'] ?? '',
                        'thumbnail_url' => $video_data['thumbnail_url'] ?? '',
                        'original_url' => $video_data['original_url'] ?? '',
                        'author_name' => $video_data['author_name'] ?? '',
                        'created_at' => $video_data['created_at'] ?? current_time('mysql')
                    ];

                    // Send notification using the notification handler
                    $notification_sent = $this->notification_handler->notify_new_content($notification_content);

                    if ($notification_sent) {
                        error_log("YouTube: Successfully sent notification for new video {$video_data['id']}");
                    } else {
                        error_log("YouTube: Failed to send notification for new video {$video_data['id']}");
                    }

                } catch (\Exception $e) {
                    error_log("YouTube: Error sending notification for new video {$video_data['id']}: " . $e->getMessage());
                    error_log("YouTube: Notification error trace: " . $e->getTraceAsString());
                }
            } else {
                error_log("YouTube: Failed to insert new cache entry for video {$video_data['id']}: " . $wpdb->last_error);
            }
        }

        return true;
    }

    /**
     * Format video data for feed
     *
     * @param array $video Raw video data
     * @param string $type Content type (video/short)
     * @return array|null
     */
    protected function format_feed_item($video, $type = 'video')
    {
        if (empty($video['snippet'])) {
            error_log('YouTube: Cannot format feed item - missing snippet data');
            return null;
        }

        $snippet = $video['snippet'];
        $statistics = $video['statistics'] ?? [];
        $channel_id = $snippet['channelId'] ?? null;

        // Get channel avatar only if we have a channel ID
        $author_avatar = null;
        if ($channel_id) {
            $author_avatar = $this->get_channel_avatar($channel_id);
            error_log("YouTube: Got avatar for channel {$channel_id}: {$author_avatar}");
        }

        // Ensure we have valid URLs
        $video_url = "https://www.youtube.com/watch?v={$video['id']}";

        // Create a single formatted item with all available data
        $formatted = [
            'id' => $video['id'],
            'type' => $type,
            'title' => $snippet['title'] ?? '',
            'description' => $snippet['description'] ?? '',
            'media_url' => $video_url,
            'thumbnail_url' => $snippet['thumbnails']['high']['url'] ??
                ($snippet['thumbnails']['default']['url'] ?? ''),
            'original_url' => $video_url,
            'created_at' => $snippet['publishedAt'] ?? current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'engagement' => [
                'views' => intval($statistics['viewCount'] ?? 0),
                'likes' => intval($statistics['likeCount'] ?? 0),
                'comments' => intval($statistics['commentCount'] ?? 0),
                'shares' => 0
            ],
            'author_name' => $snippet['channelTitle'] ?? '',
            'author_avatar' => $author_avatar,
            'author_profile' => $channel_id ? "https://www.youtube.com/channel/{$channel_id}" : '',
            'duration' => $video['contentDetails']['duration'] ?? null,
            'metadata' => [
                'is_long_form' => $type === 'video',
                'has_chapters' => [],
                'category' => $this->get_video_category($video)
            ]
        ];

        // Also add individual stats for backward compatibility
        $formatted['views'] = $formatted['engagement']['views'];
        $formatted['likes'] = $formatted['engagement']['likes'];
        $formatted['comments'] = $formatted['engagement']['comments'];
        $formatted['shares'] = $formatted['engagement']['shares'];

        // Log the formatted data for debugging
        error_log("YouTube: Formatted video {$video['id']} with data: " . json_encode([
            'title' => $formatted['title'],
            'stats' => $formatted['engagement']
        ]));

        return $formatted;
    }

    /**
     * Get video category
     *
     * @param array $video Video data
     * @return string Category name
     */
    private function get_video_category($video)
    {
        // Check if title contains ቅዳሴ or የካቲት
        if (
            !empty($video['snippet']['title']) &&
            (strpos($video['snippet']['title'], 'ቅዳሴ') !== false ||
                strpos($video['snippet']['title'], 'የካቲት') !== false)
        ) {
            return 'liturgical';
        }
        return 'other';
    }

    /**
     * Get channel avatar with caching
     *
     * @param string $channel_id
     * @return string|null
     */
    private function get_channel_avatar($channel_id)
    {
        try {
            if (empty($channel_id)) {
                error_log("YouTube: Cannot fetch avatar - empty channel ID");
                return null;
            }

            // Check cache first with a longer duration (1 week)
            $cache_key = 'youtube_channel_avatar_' . $channel_id;
            $cached_data = get_transient($cache_key);

            if ($cached_data !== false) {
                error_log("YouTube: Using cached avatar for channel {$channel_id}: {$cached_data}");
                return $cached_data;
            }

            // Check if we have enough quota before making the request
            if (!$this->check_quota('channels')) {
                error_log("YouTube: Insufficient quota for fetching channel avatar");

                // If we're out of quota but have a stale cache, extend it
                $stale_cache = get_transient('stale_' . $cache_key);
                if ($stale_cache) {
                    set_transient($cache_key, $stale_cache, 24 * HOUR_IN_SECONDS);
                    error_log("YouTube: Using stale cache for avatar due to quota limit");
                    return $stale_cache;
                }

                return null;
            }

            error_log("YouTube: Fetching avatar for channel {$channel_id}");

            $response = $this->make_api_request(
                self::API_BASE_URL . '/channels',
                [
                    'part' => 'snippet',
                    'id' => $channel_id,
                    'key' => $this->config['api_key']
                ],
                'channels'
            );

            if (empty($response['items'][0]['snippet']['thumbnails'])) {
                error_log("YouTube: No thumbnails found in channel response for {$channel_id}");
                return null;
            }

            // Get the highest quality avatar available
            $thumbnails = $response['items'][0]['snippet']['thumbnails'];
            $avatar_url = $thumbnails['high']['url'] ??
                $thumbnails['medium']['url'] ??
                $thumbnails['default']['url'];

            if (empty($avatar_url)) {
                error_log("YouTube: No valid avatar URL found for channel {$channel_id}");
                return null;
            }

            error_log("YouTube: Got avatar URL for channel {$channel_id}: {$avatar_url}");

            // Cache for 1 week since channel avatars rarely change
            set_transient($cache_key, $avatar_url, WEEK_IN_SECONDS);

            // Also store in stale cache for fallback
            set_transient('stale_' . $cache_key, $avatar_url, 2 * WEEK_IN_SECONDS);

            error_log("YouTube: Cached avatar URL for channel {$channel_id}");

            return $avatar_url;

        } catch (\Exception $e) {
            error_log("YouTube: Error fetching channel avatar: " . $e->getMessage());
            error_log("YouTube: Error trace: " . $e->getTraceAsString());

            // Try to use stale cache in case of error
            $stale_cache = get_transient('stale_' . $cache_key);
            if ($stale_cache) {
                error_log("YouTube: Using stale cache for avatar due to error");
                return $stale_cache;
            }

            return null;
        }
    }

    /**
     * Check if a video is a short based on its duration
     *
     * @param array $video Video data
     * @return bool
     */
    private function is_short($video)
    {
        if (empty($video['contentDetails']['duration'])) {
            return false;
        }

        // Parse duration string (e.g., "PT2M10S")
        try {
            $duration = new \DateInterval($video['contentDetails']['duration']);
            $seconds = ($duration->h * 3600) + ($duration->i * 60) + $duration->s;

            // YouTube Shorts are 60 seconds or less
            return $seconds <= 60;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get stream status from streaming details
     *
     * @param array $details
     * @return string
     */
    private function get_stream_status_from_details($details)
    {
        if (empty($details)) {
            return 'ended';
        }

        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        // If we have an actual end time, it's definitely ended
        if (!empty($details['actualEndTime'])) {
            return 'ended';
        }

        // For ቅዳሴ videos, if the scheduled time has passed more than 3 hours ago, mark as ended
        if (!empty($details['scheduledStartTime'])) {
            $scheduled_time = new \DateTime($details['scheduledStartTime']);
            $three_hours_after = clone $scheduled_time;
            $three_hours_after->modify('+3 hours');

            if ($now > $three_hours_after) {
                return 'ended';
            }
        }

        // If we have an actual start time but no end time, check if it's still live
        if (!empty($details['actualStartTime'])) {
            $start_time = new \DateTime($details['actualStartTime']);
            $three_hours_after = clone $start_time;
            $three_hours_after->modify('+3 hours');

            if ($now > $three_hours_after) {
                return 'ended';
            }
            return 'live';
        }

        // Handle upcoming streams
        if (!empty($details['scheduledStartTime'])) {
            $scheduled_time = new \DateTime($details['scheduledStartTime']);

            // If scheduled time is in the future, it's upcoming
            if ($scheduled_time > $now) {
                return 'upcoming';
            }

            // If scheduled time has passed but we don't have an actualStartTime,
            // check if it's within the last 3 hours
            $three_hours_ago = clone $now;
            $three_hours_ago->modify('-3 hours');

            if ($scheduled_time > $three_hours_ago) {
                return 'live';
            }
        }

        return 'ended';
    }

    /**
     * Insert a new stream into the database
     *
     * @param array $video_data Video data from YouTube API
     * @return bool|int False on failure, stream ID on success
     */
    private function insert_new_stream($video_data)
    {
        if (empty($video_data['id']) || empty($video_data['snippet'])) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_streams';

        $streaming_details = $video_data['liveStreamingDetails'] ?? [];
        $status = $this->get_stream_status_from_details($streaming_details);

        // Ensure we have both timestamps for liturgical videos
        if (empty($streaming_details['actualStartTime']) && !empty($streaming_details['scheduledStartTime'])) {
            $streaming_details['actualStartTime'] = $streaming_details['scheduledStartTime'];
        }

        $data = [
            'platform' => 'youtube',
            'stream_id' => $video_data['id'],
            'title' => $video_data['snippet']['title'],
            'description' => $video_data['snippet']['description'],
            'thumbnail_url' => $video_data['snippet']['thumbnails']['high']['url'] ?? '',
            'stream_url' => "https://www.youtube.com/watch?v={$video_data['id']}",
            'status' => $status,
            'viewer_count' => $streaming_details['concurrentViewers'] ?? 0,
            'started_at' => $streaming_details['actualStartTime'] ?? $video_data['snippet']['publishedAt'],
            'scheduled_for' => $streaming_details['scheduledStartTime'] ?? $video_data['snippet']['publishedAt'],
            'channel_name' => $video_data['snippet']['channelTitle'],
            'channel_id' => $video_data['snippet']['channelId'],
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        error_log("YouTube: Inserting stream with data: " . json_encode([
            'id' => $video_data['id'],
            'title' => $data['title'],
            'status' => $status,
            'started_at' => $data['started_at'],
            'scheduled_for' => $data['scheduled_for']
        ]));

        $format = [
            '%s', // platform
            '%s', // stream_id
            '%s', // title
            '%s', // description
            '%s', // thumbnail_url
            '%s', // stream_url
            '%s', // status
            '%d', // viewer_count
            '%s', // started_at
            '%s', // scheduled_for
            '%s', // channel_name
            '%s', // channel_id
            '%s', // created_at
            '%s'  // updated_at
        ];

        $result = $wpdb->insert($table, $data, $format);

        if ($result === false) {
            error_log("YouTube: Failed to insert stream {$video_data['id']}: " . $wpdb->last_error);
            return false;
        }

        error_log("YouTube: Successfully inserted new stream {$video_data['id']}");
        return $wpdb->insert_id;
    }

    /**
     * Update stream status in the database
     *
     * @param array $existing_stream Existing stream data from database
     * @param array $video_data New video data from YouTube API
     * @return bool
     */
    private function update_stream_status($existing_stream, $video_data)
    {
        if (empty($existing_stream['id']) || empty($video_data['id'])) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_streams';

        $streaming_details = $video_data['liveStreamingDetails'] ?? [];
        $status = $this->get_stream_status_from_details($streaming_details);

        $data = [
            'title' => $video_data['snippet']['title'],
            'description' => $video_data['snippet']['description'],
            'thumbnail_url' => $video_data['snippet']['thumbnails']['high']['url'] ?? $existing_stream['thumbnail_url'],
            'status' => $status,
            'viewer_count' => $streaming_details['concurrentViewers'] ?? 0,
            'started_at' => $streaming_details['actualStartTime'] ?? $existing_stream['started_at'],
            'scheduled_for' => $streaming_details['scheduledStartTime'] ?? $existing_stream['scheduled_for'],
            'updated_at' => current_time('mysql')
        ];

        $where = ['id' => $existing_stream['id']];

        $format = [
            '%s', // title
            '%s', // description
            '%s', // thumbnail_url
            '%s', // status
            '%d', // viewer_count
            '%s', // started_at
            '%s', // scheduled_for
            '%s'  // updated_at
        ];

        $where_format = ['%d'];

        $result = $wpdb->update($table, $data, $where, $format, $where_format);

        if ($result === false) {
            error_log("YouTube: Failed to update stream {$existing_stream['id']}: " . $wpdb->last_error);
            return false;
        }

        error_log("YouTube: Successfully updated stream {$existing_stream['id']} status to {$status}");

        // If status changed, trigger notification
        if ($existing_stream['status'] !== $status) {
            $stream_data = array_merge($data, [
                'id' => $video_data['id'],
                'stream_url' => $existing_stream['stream_url'],
                'channel_name' => $existing_stream['channel_name'],
                'channel_id' => $existing_stream['channel_id']
            ]);

            $this->notification_handler->notify_stream_status_change(
                $stream_data,
                $existing_stream['status'],
                $status
            );
        }

        return true;
    }

    /**
     * Get YouTube Shorts
     *
     * @param int $max_pages Maximum number of pages to fetch (each page has 50 items)
     * @return array
     */
    private function get_shorts($max_pages = 5)
    {
        $all_items = [];
        $next_page_token = null;
        $page_count = 0;
        $video_ids = [];

        try {
            error_log("YouTube: Starting shorts fetch with max_pages: $max_pages");

            // Use the same uploads playlist to find shorts
            $uploads_playlist_id = get_transient('youtube_uploads_playlist_' . $this->config['channel_id']);

            if (!$uploads_playlist_id) {
                error_log('YouTube: No uploads playlist ID available for shorts');
                return [];
            }

            // Check if we have any cached shorts
            global $wpdb;
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            $cached_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $cache_table WHERE platform = %s AND content_type = %s",
                'youtube',
                'short'
            ));

            error_log("YouTube: Found $cached_count cached shorts");

            // For initial fetch, get all shorts. For updates, only get recent ones
            $is_initial_fetch = ($cached_count == 0);
            error_log("YouTube: " . ($is_initial_fetch ? "Performing initial shorts fetch" : "Performing shorts update fetch"));

            do {
                if (!$this->check_quota('playlistItems')) {
                    error_log('YouTube: Insufficient quota for playlist items');
                    break;
                }

                $params = [
                    'part' => 'snippet',
                    'playlistId' => $uploads_playlist_id,
                    'maxResults' => 50,
                    'key' => $this->config['api_key']
                ];

                if ($next_page_token) {
                    $params['pageToken'] = $next_page_token;
                }

                error_log("YouTube: Fetching shorts page " . ($page_count + 1));
                $data = $this->make_api_request(self::API_BASE_URL . '/playlistItems', $params, 'playlistItems');

                if (!empty($data['items'])) {
                    error_log("YouTube: Found " . count($data['items']) . " potential shorts on page " . ($page_count + 1));
                    foreach ($data['items'] as $item) {
                        if (!empty($item['snippet']['resourceId']['videoId'])) {
                            $published_at = strtotime($item['snippet']['publishedAt']);

                            // For initial fetch, get all videos
                            // For updates, only get videos from last 24 hours
                            if ($is_initial_fetch || $published_at >= strtotime('-24 hours')) {
                                $video_ids[] = $item['snippet']['resourceId']['videoId'];
                                error_log("YouTube: Added potential short ID: " . $item['snippet']['resourceId']['videoId'] .
                                    " published at " . $item['snippet']['publishedAt']);
                            }
                        }
                    }
                } else {
                    error_log("YouTube: No items found on shorts page " . ($page_count + 1));
                }

                $next_page_token = $data['nextPageToken'] ?? null;
                $page_count++;

                // For initial fetch, continue until max_pages or no more results
                // For updates, stop after first page if no recent videos
                if (!$is_initial_fetch && empty($video_ids) && $page_count >= 1) {
                    error_log("YouTube: No recent shorts found, stopping fetch");
                    break;
                }

                if ($page_count >= $max_pages || !$next_page_token) {
                    error_log("YouTube: Reached " . ($page_count >= $max_pages ? "max pages" : "end of results"));
                    break;
                }

            } while (true);

            error_log("YouTube: Total potential shorts found: " . count(array_unique($video_ids)));

            // Get video details in batches and filter for shorts
            if (!empty($video_ids)) {
                $unique_ids = array_unique($video_ids);
                $video_batches = array_chunk($unique_ids, 50);

                foreach ($video_batches as $batch) {
                    if (!$this->check_quota('videos')) {
                        error_log('YouTube: Insufficient quota for video details');
                        break;
                    }

                    $params = [
                        'part' => 'snippet,statistics,contentDetails',
                        'id' => implode(',', $batch),
                        'key' => $this->config['api_key']
                    ];

                    $batch_data = $this->make_api_request(self::API_BASE_URL . '/videos', $params, 'videos');
                    if (!empty($batch_data['items'])) {
                        foreach ($batch_data['items'] as $video) {
                            if ($this->is_short($video)) {
                                $formatted_item = $this->format_feed_item($video, 'short');
                                if ($formatted_item) {
                                    $all_items[] = $formatted_item;
                                    error_log("YouTube: Added formatted short: {$video['id']} - {$video['snippet']['title']}");
                                }
                            }
                        }
                    }
                }
            }

            error_log("YouTube: Successfully formatted " . count($all_items) . " shorts");
            return $all_items;

        } catch (\Exception $e) {
            error_log('YouTube API Error in get_shorts: ' . $e->getMessage());
            error_log('YouTube API Error trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Get live broadcasts
     * Note: For live streams, we still need to use search API as it's the only way to filter by eventType
     *
     * @return array
     */
    private function get_live_broadcasts()
    {
        $items = [];
        $video_ids = [];

        try {
            $items = [];
            $video_ids = [];
            // Cache key for live broadcasts to prevent frequent API calls
            $cache_key = 'youtube_live_' . $this->config['channel_id'];
            $cached_live = get_transient($cache_key);

            if ($cached_live !== false) {
                return (array) $cached_live;
            }

            // We have to use search for live streams as it's the only way to filter by eventType
            $params = [
                'part' => 'snippet',
                'channelId' => $this->config['channel_id'],
                'eventType' => 'live',
                'type' => 'video',
                'maxResults' => 5, // Limit to 5 as we rarely have more live streams
                'key' => $this->config['api_key']
            ];

            $data = $this->make_api_request(self::API_BASE_URL . '/search', $params, 'search');

            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    if (!empty($item['id']['videoId'])) {
                        $video_ids[] = $item['id']['videoId'];
                    }
                }
            }

            // Get video details in batch
            if (!empty($video_ids)) {
                $videos = $this->get_batch_video_details($video_ids);
                foreach ($videos as $video) {
                    $formatted_item = $this->format_feed_item($video, 'live');
                    if ($formatted_item) {
                        $items[] = $formatted_item;
                    }
                }
            }

            // Cache live broadcasts for 5 minutes
            set_transient($cache_key, $items, 5 * MINUTE_IN_SECONDS);

            return $items;

        } catch (\Exception $e) {
            error_log('YouTube API Error in get_live_broadcasts: ' . $e->getMessage());
            return [];
        }
    }

    private function determine_cache_duration($video_data)
    {
        // If it's a live video
        if (!empty($video_data['duration']) && $video_data['duration'] === 'P0D') {
            return self::CACHE_DURATION['live'];
        }

        // If video is less than 24 hours old
        $published_at = strtotime($video_data['created_at']);
        if ($published_at >= strtotime('-24 hours')) {
            return self::CACHE_DURATION['recent'];
        }

        // Otherwise it's a historical video
        return self::CACHE_DURATION['historical'];
    }

    /**
     * Get available playlists from the YouTube channel
     *
     * @return array List of playlists with id, title, and thumbnail
     */
    public function get_playlists()
    {
        if (!$this->is_configured()) {
            error_log('YouTube: Platform not configured for playlists');
            return [];
        }

        try {
            $playlists = [];
            $next_page_token = null;

            do {
                if (!$this->check_quota('playlists')) {
                    error_log('YouTube: Insufficient quota for playlists');
                    break;
                }

                $params = [
                    'part' => 'snippet,contentDetails',
                    'channelId' => $this->config['channel_id'],
                    'maxResults' => 50,
                    'key' => $this->config['api_key']
                ];

                if ($next_page_token) {
                    $params['pageToken'] = $next_page_token;
                }

                $data = $this->make_api_request(self::API_BASE_URL . '/playlists', $params, 'playlists');

                if (!empty($data['items'])) {
                    foreach ($data['items'] as $item) {
                        $thumbnails = $item['snippet']['thumbnails'] ?? [];
                        $thumbnail = $thumbnails['high']['url']
                            ?? $thumbnails['medium']['url']
                            ?? $thumbnails['default']['url']
                            ?? '';

                        $playlists[] = [
                            'id' => $item['id'],
                            'title' => $item['snippet']['title'],
                            'thumbnail' => $thumbnail,
                            'video_count' => $item['contentDetails']['itemCount'] ?? 0,
                            'description' => $item['snippet']['description'] ?? ''
                        ];
                    }
                }

                $next_page_token = $data['nextPageToken'] ?? null;
            } while ($next_page_token);

            error_log('YouTube: Fetched ' . count($playlists) . ' playlists');
            return $playlists;

        } catch (\Exception $e) {
            error_log('YouTube: Error fetching playlists - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get videos from a specific playlist
     *
     * @param string $playlist_id The playlist ID to fetch videos from
     * @param int $max_pages Maximum number of pages to fetch
     * @return array List of formatted video items
     */
    public function get_playlist_items($playlist_id, $max_pages = 5)
    {
        if (!$this->is_configured()) {
            error_log('YouTube: Platform not configured for playlist items');
            return [];
        }

        try {
            $all_items = [];
            $next_page_token = null;
            $page_count = 0;
            $video_ids = [];

            // STEP 1: Get all video IDs from the playlist
            do {
                if (!$this->check_quota('playlistItems')) {
                    error_log('YouTube: Insufficient quota for playlist items');
                    break;
                }

                $params = [
                    'part' => 'snippet',
                    'playlistId' => $playlist_id,
                    'maxResults' => 50,
                    'key' => $this->config['api_key']
                ];

                if ($next_page_token) {
                    $params['pageToken'] = $next_page_token;
                }

                $data = $this->make_api_request(self::API_BASE_URL . '/playlistItems', $params, 'playlistItems');

                if (!empty($data['items'])) {
                    foreach ($data['items'] as $item) {
                        if (!empty($item['snippet']['resourceId']['videoId'])) {
                            $video_ids[] = $item['snippet']['resourceId']['videoId'];
                        }
                    }
                }

                $next_page_token = $data['nextPageToken'] ?? null;
                $page_count++;
            } while ($next_page_token && $page_count < $max_pages);

            error_log('YouTube: Found ' . count($video_ids) . ' video IDs in playlist ' . $playlist_id);

            if (empty($video_ids)) {
                return [];
            }

            // STEP 2: Get full video details in batches of 50
            $video_chunks = array_chunk($video_ids, 50);

            foreach ($video_chunks as $chunk) {
                if (!$this->check_quota('videos')) {
                    error_log('YouTube: Insufficient quota for video details');
                    break;
                }

                $params = [
                    'part' => 'snippet,statistics,contentDetails',
                    'id' => implode(',', $chunk),
                    'key' => $this->config['api_key']
                ];

                $video_data = $this->make_api_request(self::API_BASE_URL . '/videos', $params, 'videos');

                if (!empty($video_data['items'])) {
                    foreach ($video_data['items'] as $video) {
                        $formatted = $this->format_feed_item($video, 'video');
                        if ($formatted) {
                            // Add playlist_id to the item for reference
                            $formatted['playlist_id'] = $playlist_id;
                            $all_items[] = $formatted;
                        }
                    }
                }
            }

            error_log('YouTube: Fetched ' . count($all_items) . ' videos from playlist ' . $playlist_id);
            return $all_items;

        } catch (\Exception $e) {
            error_log('YouTube: Error fetching playlist items - ' . $e->getMessage());
            return [];
        }
    }
}