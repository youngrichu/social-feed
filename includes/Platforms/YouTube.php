<?php
namespace SocialFeed\Platforms;

use SocialFeed\Core\ContentProcessor;
use SocialFeed\Core\CacheManager;
use SocialFeed\Core\PerformanceMonitor;
use SocialFeed\Core\RequestOptimizer;
use SocialFeed\Core\NotificationHandler;
use SocialFeed\Core\QuotaManager;

class YouTube extends AbstractPlatform {
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
    private $performance_monitor;

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
    public function __construct() {
        // Set platform identifier first
        $this->platform = 'youtube';
        
        // Initialize parent class
        parent::__construct();
        
        // Initialize components
        try {
            $this->content_processor = new ContentProcessor();
            $this->cache_manager = new CacheManager('youtube');
            $this->performance_monitor = new PerformanceMonitor();
            $this->request_optimizer = new RequestOptimizer();
            $this->notification_handler = new NotificationHandler();
            $this->quota_manager = new QuotaManager();
        } catch (\Exception $e) {
            error_log('YouTube: Error initializing components - ' . $e->getMessage());
        }
    }

    /**
     * Initialize the platform
     */
    public function init() {
        static $initialized = false;
        
        if ($initialized) {
            return;
        }
        
        if (!$this->is_configured()) {
            error_log('YouTube: Platform not configured properly');
            return;
        }

        error_log('YouTube: Initializing platform');
        
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
    public function is_configured() {
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
    public function add_cron_schedules($schedules) {
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
    public function check_new_videos() {
        echo "YouTube: Starting check_new_videos()\n";

        // Check if platform is configured
        if (!$this->is_configured()) {
            error_log('YouTube: Platform not configured for video check');
            return false;
        }

        // Check if we're in quota exceeded state
        $quota_exceeded_key = 'youtube_quota_exceeded_' . date('Y-m-d');
        if (get_transient($quota_exceeded_key)) {
            error_log('YouTube: Skipping video check due to exceeded quota');
            return false;
        }

        try {
            // Get videos with retry logic
            $videos = $this->get_videos(1);
            
            if (empty($videos)) {
                error_log('YouTube: No videos found');
                return false;
            }

            error_log('YouTube: Found ' . count($videos) . ' videos');

            // Store videos in cache with notification triggering
            global $wpdb;
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            $new_videos_count = 0;
            
            foreach ($videos as $video) {
                error_log('YouTube: Processing video: ' . $video['id']);
                
                // Check if video already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $cache_table WHERE platform = %s AND content_id = %s",
                    'youtube',
                    $video['id']
                ));

                if ($exists) {
                    error_log('YouTube: Video already exists in cache: ' . $video['id']);
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

                error_log('YouTube: Inserting video with data for ID: ' . $video['id']);

                $result = $wpdb->insert(
                    $cache_table,
                    $insert_data,
                    ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
                );

                if ($result === false) {
                    error_log('YouTube: Failed to insert video: ' . $wpdb->last_error);
                } else {
                    error_log('YouTube: Successfully inserted video with ID: ' . $wpdb->insert_id);
                    $new_videos_count++;
                    
                    // Trigger notification for new video
                    $this->notification_handler->notify_new_content([
                        'platform' => 'youtube',
                        'type' => $video['type'],
                        'content' => $video
                    ]);
                }
            }

            error_log("YouTube: Processed {$new_videos_count} new videos");
            return true;
        } catch (\Exception $e) {
            error_log('YouTube: Error in check_new_videos: ' . $e->getMessage());
            error_log('YouTube: Error trace: ' . $e->getTraceAsString());
            
            // Implement exponential backoff for retries
            $retry_count = get_transient('youtube_retry_count_check_new_videos') ?: 0;
            $retry_delay = min(300 * pow(2, $retry_count), 3600); // Max 1 hour delay
            set_transient('youtube_retry_count_check_new_videos', $retry_count + 1, 3600);
            wp_schedule_single_event(time() + $retry_delay, 'social_feed_youtube_check_new_videos');
            return false;
        }
    }

    /**
     * Get details for a single video
     */
    private function get_video_details($video_id) {
        $params = [
            'part' => 'snippet,statistics,contentDetails,liveStreamingDetails',
            'id' => $video_id,
            'key' => $this->config['api_key']
        ];
        
        $data = $this->make_api_request(self::API_BASE_URL . '/videos', $params, 'videos');
        return !empty($data['items'][0]) ? $data['items'][0] : null;
    }

    /**
     * Check for live streams and update cache
     */
    public function check_live_streams() {
        // Check if live stream checking is enabled
        if (empty($this->config['enable_live_check'])) {
            error_log('YouTube: Live stream checking is disabled in settings');
            return;
        }

        if (!$this->is_configured()) {
            error_log('YouTube: Platform not configured for live stream check');
            return;
        }

        // Check if we're in quota exceeded state
        $quota_exceeded_key = 'youtube_quota_exceeded_' . date('Y-m-d');
        if (get_transient($quota_exceeded_key)) {
            error_log('YouTube: Skipping live stream check due to exceeded quota');
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

            // If we have active streams, prioritize checking their status
            if (!empty($active_streams)) {
                foreach ($active_streams as $stream) {
                    if (!$this->check_quota('videos')) {
                        error_log('YouTube: Insufficient quota for checking active stream status');
                        return;
                    }

                    $video_data = $this->get_video_details($stream['stream_id']);
                    if ($video_data) {
                        $this->update_stream_status($stream, $video_data);
                    }
                }
            }

            // Only search for new streams if we haven't exceeded our quota
            if ($this->check_quota('search')) {
                // Search for live streams
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
                    $video_id = $item['id']['videoId'];
                        $this->process_live_stream($video_id);
                    }
                }

                // Check for upcoming streams
                if ($this->check_quota('search')) {
                    $params['eventType'] = 'upcoming';
                    $upcoming_data = $this->make_api_request(self::API_BASE_URL . '/search', $params, 'search');
                    
                    if (!empty($upcoming_data['items'])) {
                        foreach ($upcoming_data['items'] as $item) {
                            $video_id = $item['id']['videoId'];
                            $this->process_live_stream($video_id);
                        }
                    }
                }

                // Check for completed streams from the last 7 days
                if ($this->check_quota('search')) {
                    $params['eventType'] = 'completed';
                    $params['publishedAfter'] = date('c', strtotime('-7 days'));
                    $completed_data = $this->make_api_request(self::API_BASE_URL . '/search', $params, 'search');
                    
                    if (!empty($completed_data['items'])) {
                        foreach ($completed_data['items'] as $item) {
                            $video_id = $item['id']['videoId'];
                            $this->process_live_stream($video_id);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('YouTube: Error checking live streams: ' . $e->getMessage());
            error_log('YouTube: Error trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Process a live stream and update its status
     */
    private function process_live_stream($video_id) {
        if (!$this->check_quota('videos')) {
            return;
        }

        try {
            $video_data = $this->get_video_details($video_id);
            if (!$video_data) {
                return;
            }

            // Only process if it has live streaming details or is a completed live stream
            $streaming_details = $video_data['liveStreamingDetails'] ?? [];
            if (empty($streaming_details)) {
                // Check if this was a live stream by looking at the video title and liveBroadcastContent
                if (strpos($video_data['snippet']['title'], 'ቅዳሴ') === false &&
                    strpos($video_data['snippet']['title'], 'የካቲት') === false &&
                    $video_data['snippet']['liveBroadcastContent'] === 'none') {
                    error_log("YouTube: Video {$video_id} is not a live stream");
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
                error_log("YouTube: Inferred streaming details for liturgical video {$video_id}: " . json_encode($streaming_details));
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

            error_log("YouTube: Processed stream {$video_id} with start time: " . 
                     ($streaming_details['actualStartTime'] ?? 'none') . 
                     " and scheduled time: " . 
                     ($streaming_details['scheduledStartTime'] ?? 'none'));

        } catch (\Exception $e) {
            error_log('YouTube: Error processing live stream ' . $video_id . ': ' . $e->getMessage());
        }
    }



    /**
     * Enhanced API request with optimization and retry logic
     */
    private function make_api_request($endpoint, $params = [], $operation = 'videos', $retry_count = 0) {
        $max_retries = 3;
        
        // Check if we're in quota exceeded state
        $quota_exceeded_key = 'youtube_quota_exceeded_' . date('Y-m-d');
        if (get_transient($quota_exceeded_key)) {
            error_log("YouTube API: Quota exceeded for today, operation blocked: {$operation}");
            return false;
        }
        
        // Check quota before making request
        if (!$this->check_quota($operation)) {
            error_log("YouTube API: Insufficient quota for operation: {$operation}");
            return false;
        }
        
        try {
            // Ensure we have the API key
            if (!isset($params['key']) && isset($this->config['api_key'])) {
                $params['key'] = $this->config['api_key'];
            }

            $url = (strpos($endpoint, 'http') === 0) ? $endpoint : self::API_BASE_URL . '/' . $endpoint;
            $full_url = add_query_arg($params, $url);
            
            // Use output buffering to prevent debug output
            ob_start();
            $response = wp_remote_get($full_url, [
                'timeout' => 30,
                'user-agent' => 'Social Feed Plugin/1.0'
            ]);
            ob_end_clean();

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log("YouTube API Error: " . $error_message);
                
                // Retry on network errors
                if ($retry_count < $max_retries && (
                    strpos($error_message, 'timeout') !== false ||
                    strpos($error_message, 'connection') !== false
                )) {
                    sleep(pow(2, $retry_count)); // Exponential backoff
                    return $this->make_api_request($endpoint, $params, $operation, $retry_count + 1);
                }
                
                return false;
            }

            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);

            if ($status !== 200) {
                if ($status === 429) {
                    $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                    $wait_time = $retry_after ?: 60;
                    set_transient("social_feed_rate_youtube", true, $wait_time);
                    error_log("YouTube API: Rate limit hit, retry after {$wait_time} seconds");
                    
                    // Retry after rate limit if we haven't exceeded max retries
                    if ($retry_count < $max_retries) {
                        sleep($wait_time);
                        return $this->make_api_request($endpoint, $params, $operation, $retry_count + 1);
                    }
                } elseif ($status === 403) {
                    // Check if it's a quota exceeded error
                    $error_data = json_decode($body, true);
                    if (isset($error_data['error']['errors'][0]['reason']) && 
                        $error_data['error']['errors'][0]['reason'] === 'quotaExceeded') {
                        set_transient($quota_exceeded_key, true, 86400); // 24 hours
                        error_log("YouTube API: Daily quota exceeded");
                    }
                } elseif ($status >= 500 && $retry_count < $max_retries) {
                    // Retry on server errors
                    sleep(pow(2, $retry_count));
                    return $this->make_api_request($endpoint, $params, $operation, $retry_count + 1);
                }
                
                error_log("YouTube API: Request failed with status {$status}, Body: " . substr($body, 0, 500));
                return false;
            }

            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("YouTube API: Invalid JSON response");
                return false;
            }

            if (!empty($data['error'])) {
                error_log("YouTube API Error: " . json_encode($data['error']));
                return false;
            }

            // Clear retry count on successful request
            delete_transient('youtube_retry_count_' . $operation);

            return $data;

        } catch (\Exception $e) {
            error_log("YouTube API Exception: " . $e->getMessage());
            
            // Retry on exceptions if we haven't exceeded max retries
            if ($retry_count < $max_retries) {
                sleep(pow(2, $retry_count));
                return $this->make_api_request($endpoint, $params, $operation, $retry_count + 1);
            }
            
            return false;
        }
    }

    /**
     * Make HTTP request with rate limiting and error handling
     *
     * @param string $url
     * @param array $args
     * @return array
     * @throws \Exception
     */
    protected function make_request($url, $args = []) {
        error_log('YouTube API Request: ' . $url . ' with args: ' . json_encode($args['body'] ?? []));
        
        // Check rate limiting
        $rate_key = "social_feed_rate_{$this->platform}";
        $rate_limit = get_transient($rate_key);

        if ($rate_limit !== false) {
            throw new \Exception("Rate limit exceeded for {$this->platform}");
        }

        // Make request
        $response = wp_remote_request($url . '?' . http_build_query($args['body'] ?? []));

        // Handle errors
        if (is_wp_error($response)) {
            error_log('YouTube API Error: ' . $response->get_error_message());
            throw new \Exception($response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('YouTube API Response Status: ' . $status);
        error_log('YouTube API Response Body: ' . $body);

        if ($status !== 200) {
            // Handle rate limiting
            if ($status === 429) {
                $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                set_transient($rate_key, true, $retry_after ?: 60);
            }
            throw new \Exception("API request failed with status: $status, Response: $body");
        }

        // Parse response
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response');
        }

        return $data;
    }

    /**
     * Enhanced content fetching with performance monitoring
     */
    public function get_feed($types = [], $max_pages = 5) {
        try {
            $start_time = microtime(true);
            $feed_items = [];
            $fetched_types = [];

            // Determine which content types to fetch
            $fetch_types = empty($types) ? $this->get_supported_types() : array_intersect($types, $this->get_supported_types());
            
            error_log('YouTube: Fetching feed for types: ' . implode(', ', $fetch_types));
            
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

            error_log('YouTube: Fetched ' . count($feed_items) . ' items in ' . 
                     (microtime(true) - $start_time) . ' seconds');
                     
            return $feed_items;
        } catch (\Exception $e) {
            error_log('YouTube: Error fetching feed - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Standardize all cached videos to ensure consistent structure
     */
    private function standardize_cached_videos() {
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
        
        error_log("YouTube: Standardizing " . count($videos) . " cached videos");
        
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
                error_log("YouTube: Added missing URLs for video {$content['id']}");
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
                error_log("YouTube: Added missing engagement structure for video {$content['id']}");
            }
            
            // Ensure individual stats
            if (!isset($content['views']) || !isset($content['likes']) || 
                !isset($content['comments']) || !isset($content['shares'])) {
                $content['views'] = $content['engagement']['views'] ?? 0;
                $content['likes'] = $content['engagement']['likes'] ?? 0;
                $content['comments'] = $content['engagement']['comments'] ?? 0;
                $content['shares'] = $content['engagement']['shares'] ?? 0;
                $updated = true;
                error_log("YouTube: Added missing individual stats for video {$content['id']}");
            }
            
            // Ensure author info
            if (empty($content['author_name'])) {
                $content['author_name'] = 'Dubai Debre Mewi';
                $updated = true;
                error_log("YouTube: Added missing author name for video {$content['id']}");
            }
            
            if (empty($content['author_profile'])) {
                $content['author_profile'] = 'https://www.youtube.com/channel/UC5D_jWcqHBVu18iDtxxx_mQ';
                $updated = true;
                error_log("YouTube: Added missing author profile for video {$content['id']}");
            }
            
            if (!isset($content['author_avatar'])) {
                $content['author_avatar'] = '';
                $updated = true;
                error_log("YouTube: Added missing author avatar for video {$content['id']}");
            }
            
            // Ensure metadata
            if (empty($content['metadata'])) {
                $content['metadata'] = [
                    'is_long_form' => $content['type'] === 'video',
                    'has_chapters' => [],
                    'category' => 'other'
                ];
                $updated = true;
                error_log("YouTube: Added missing metadata for video {$content['id']}");
            }
            
            // Ensure updated_at field
            if (empty($content['updated_at'])) {
                $content['updated_at'] = current_time('mysql');
                $updated = true;
                error_log("YouTube: Added missing updated_at for video {$content['id']}");
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
                error_log("YouTube: Updated cached video {$content['id']} with standardized structure");
            }
        }
    }

    /**
     * Check if operation is essential
     */
    private function is_essential_operation($operation) {
        return in_array($operation, ['playlistItems', 'videos']);
    }

    /**
     * Get stream status
     *
     * @param string $stream_id
     * @return array|null
     */
    public function get_stream_status($stream_id) {
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
    public function validate_config($config) {
        return !empty($config['api_key']) && !empty($config['channel_id']);
    }

    /**
     * Get supported content types
     *
     * @return array
     */
    public function get_supported_types() {
        return ['video', 'short', 'live'];
    }

    /**
     * Calculate optimal check interval based on quota usage
     *
     * @return int Interval in seconds
     */
    public function calculate_optimal_interval() {
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
    public function get_quota_usage_percentage() {
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
    private function check_quota($operation) {
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
    public function get_quota_stats() {
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
    private function get_current_quota_usage() {
        $quota_key = 'youtube_quota_usage_' . date('Y-m-d');
        return get_transient($quota_key) ?: 0;
    }

    /**
     * Test if the API key is valid
     *
     * @return bool
     */
    private function test_api_key() {
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
            
            error_log('YouTube: API key test ' . ($success ? 'passed' : 'failed') . 
                     ' - Response: ' . json_encode($data));
            
            return $success;
        } catch (\Exception $e) {
            error_log('YouTube: API key test failed - ' . $e->getMessage());
            error_log('YouTube: Error trace - ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Reset quota usage
     *
     * @return bool
     */
    public function reset_quota() {
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
        error_log('YouTube: Quota usage reset');
        
        return true;
    }

    /**
     * Get videos from YouTube
     *
     * @param int $max_pages Maximum number of pages to fetch
     * @return array
     */
    public function get_videos($max_pages = 5) {
        $all_items = [];
        $next_page_token = null;
        $page_count = 0;
        $video_ids = [];

        try {
            error_log("YouTube: Starting video fetch with max_pages: $max_pages");
            
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
                    error_log('YouTube: Could not find uploads playlist for channel');
                    return [];
                }
            }

            if (!$uploads_playlist_id) {
                error_log('YouTube: No uploads playlist ID available');
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

            error_log("YouTube: Found $cached_count cached videos");

            // For initial fetch, get all videos. For updates, only get recent ones
            $is_initial_fetch = ($cached_count == 0);
            error_log("YouTube: " . ($is_initial_fetch ? "Performing initial fetch" : "Performing update fetch"));

            // For initial fetch, increase max_pages to get more historical content
            if ($is_initial_fetch) {
                $max_pages = 20; // Fetch up to 1000 videos (20 pages × 50 videos per page)
                error_log("YouTube: Initial fetch - increased max_pages to $max_pages to get historical content");
            }

            // STEP 1: Get all video IDs from the playlist
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

                error_log("YouTube: Fetching page " . ($page_count + 1) . " from uploads playlist");
                $data = $this->make_api_request(self::API_BASE_URL . '/playlistItems', $params, 'playlistItems');

                if (!empty($data['items'])) {
                    error_log("YouTube: Found " . count($data['items']) . " items on page " . ($page_count + 1));
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
                    error_log("YouTube: No recent videos found, stopping fetch");
                    break;
                }

                if ($page_count >= $max_pages || !$next_page_token) {
                    error_log("YouTube: Reached " . ($page_count >= $max_pages ? "max pages" : "end of results"));
                    break;
                }

            } while (true);

            // If no video IDs found, return empty array
            if (empty($video_ids)) {
                error_log("YouTube: No video IDs found to fetch details for");
                return [];
            }

            error_log("YouTube: Total unique video IDs found: " . count(array_unique($video_ids)));

            // STEP 2: Get complete video details in batches of 50
                $unique_ids = array_unique($video_ids);
                $video_batches = array_chunk($unique_ids, 50);
                
                foreach ($video_batches as $batch) {
                    if (!$this->check_quota('videos')) {
                        error_log('YouTube: Insufficient quota for video details');
                        break;
                    }

                // IMPORTANT: Request ALL required parts to get complete data
                    $params = [
                    'part' => 'snippet,statistics,contentDetails,status',
                        'id' => implode(',', $batch),
                        'key' => $this->config['api_key']
                    ];

                error_log("YouTube: Fetching complete details for " . count($batch) . " videos");
                    $batch_data = $this->make_api_request(self::API_BASE_URL . '/videos', $params, 'videos');
                
                    if (!empty($batch_data['items'])) {
                        foreach ($batch_data['items'] as $video) {
                        // Skip processing if critical data is missing
                        if (empty($video['snippet'])) {
                            error_log("YouTube: Skipping video with missing snippet: " . $video['id']);
                            continue;
                        }
                        
                        // Verify we have statistics
                        if (empty($video['statistics'])) {
                            error_log("YouTube: Warning - Missing statistics for video: " . $video['id']);
                        }
                        
                        // Format the video data with ALL required fields
                            $formatted_item = $this->format_feed_item($video, 'video');
                            if ($formatted_item) {
                            // Add to return array
                                $all_items[] = $formatted_item;
                            
                            // Store in cache with complete data
                                $this->store_video_in_cache($formatted_item);
                            
                            // Log the stats we received
                            error_log("YouTube: Processed video {$video['id']} with stats: views=" . 
                                    ($video['statistics']['viewCount'] ?? 'N/A') . 
                                    ", likes=" . ($video['statistics']['likeCount'] ?? 'N/A'));
                        }
                    }
                } else {
                    error_log("YouTube: No items returned for batch of " . count($batch) . " videos");
                }
            }

            error_log("YouTube: Successfully formatted " . count($all_items) . " videos");
            return $all_items;

        } catch (\Exception $e) {
            error_log('YouTube API Error in get_videos: ' . $e->getMessage());
            error_log('YouTube API Error trace: ' . $e->getTraceAsString());
            return [];
        }
    }

    /**
     * Store video in cache with proper engagement data
     */
    private function store_video_in_cache($video_data) {
        if (empty($video_data['id'])) {
            return false;
        }

        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';

        // Ensure all required fields are present
        $required_fields = [
            'id', 'type', 'title', 'description', 'media_url', 'thumbnail_url',
            'original_url', 'created_at', 'updated_at', 'engagement', 'views',
            'likes', 'comments', 'shares', 'author_name', 'author_avatar',
            'author_profile', 'duration', 'metadata'
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
            error_log("YouTube: Updated cache for video {$video_data['id']}");
        } else {
            // Insert new entry
            $wpdb->insert(
                $cache_table,
                $data,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            error_log("YouTube: Inserted new cache entry for video {$video_data['id']}");
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
    protected function format_feed_item($video, $type = 'video') {
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
    private function get_video_category($video) {
        // Check if title contains ቅዳሴ or የካቲት
        if (!empty($video['snippet']['title']) &&
            (strpos($video['snippet']['title'], 'ቅዳሴ') !== false ||
             strpos($video['snippet']['title'], 'የካቲት') !== false)) {
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
    private function get_channel_avatar($channel_id) {
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
    private function is_short($video) {
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
    private function get_stream_status_from_details($details) {
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
    private function insert_new_stream($video_data) {
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
    private function update_stream_status($existing_stream, $video_data) {
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
    private function get_shorts($max_pages = 5) {
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
    private function get_live_broadcasts() {
        $items = [];
        $video_ids = [];

        try {
            // Cache key for live broadcasts to prevent frequent API calls
            $cache_key = 'youtube_live_' . $this->config['channel_id'];
            $cached_live = get_transient($cache_key);

            if ($cached_live !== false) {
                return $cached_live;
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
                $videos = $this->get_videos_batch($video_ids);
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

    private function determine_cache_duration($video_data) {
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
}