<?php
namespace SocialFeed\Platforms;

class TikTok extends AbstractPlatform
{
    /**
     * @var string TikTok API base URL
     */
    const API_BASE_URL = 'https://open.tiktokapis.com/v2';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->platform = 'tiktok';
        parent::__construct();
    }

    /**
     * Get feed items from TikTok
     *
     * @param array $types Content types to fetch
     * @param array $args Optional arguments (unused for TikTok)
     * @return array
     */
    public function get_feed($types = [], $args = [])
    {
        if (!$this->is_configured()) {
            error_log('TikTok Feed: Not configured properly. Config: ' . json_encode($this->config));
            return [];
        }

        $items = [];
        $supported_types = $this->get_supported_types();

        // Filter types
        $types = empty($types) ? $supported_types : array_intersect($types, $supported_types);

        foreach ($types as $type) {
            try {
                switch ($type) {
                    case 'video':
                        $items = array_merge($items, $this->get_videos());
                        break;
                    case 'live':
                        $items = array_merge($items, $this->get_live_broadcasts());
                        break;
                }
            } catch (\Exception $e) {
                error_log("TikTok Feed Error fetching {$type}s: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString());
            }
        }

        return $items;
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
                self::API_BASE_URL . '/live/stream/info/',
                [
                    'method' => 'GET',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['access_token'],
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'stream_id' => $stream_id,
                    ],
                ]
            );

            if (empty($response['data'])) {
                return null;
            }

            $stream = $response['data'];
            return $this->format_stream([
                'id' => $stream['stream_id'] ?? '',
                'title' => $stream['title'] ?? '',
                'description' => $stream['description'] ?? '',
                'thumbnail_url' => isset($stream['cover']['url_list'][0]) ? $stream['cover']['url_list'][0] : '',
                'stream_url' => $stream['share_url'] ?? '',
                'status' => $this->get_stream_status_from_details($stream),
                'viewer_count' => $stream['viewer_count'] ?? 0,
                'started_at' => $stream['create_time'] ?? null,
                'scheduled_for' => $stream['schedule_time'] ?? null,
                'channel_name' => isset($stream['owner']['display_name']) ? $stream['owner']['display_name'] : '',
                'channel_avatar' => isset($stream['owner']['avatar_url']) ? $stream['owner']['avatar_url'] : '',
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
        return !empty($config['api_key']) && !empty($config['access_token']);
    }

    /**
     * Get supported content types
     *
     * @return array
     */
    public function get_supported_types()
    {
        return ['video', 'live'];
    }

    /**
     * Get videos with memory optimization for batch processing
     *
     * @return array
     */
    private function get_videos()
    {

        // Memory optimization: Track initial memory usage
        $initial_memory = memory_get_usage(true);
        $memory_limit = $this->get_memory_limit();


        try {
            $all_items = [];
            $cursor = null;
            $max_requests = 10; // Limit to prevent excessive API calls
            $request_count = 0;

            do {
                // Check memory usage before each request
                $current_memory = memory_get_usage(true);
                $memory_usage_percent = ($current_memory / $memory_limit) * 100;

                if ($memory_usage_percent > 80) {
                    error_log("TikTok: Memory usage at {$memory_usage_percent}% - implementing memory optimization");

                    // Force garbage collection
                    gc_collect_cycles();

                    // Check memory again after garbage collection
                    $current_memory = memory_get_usage(true);
                    $memory_usage_percent = ($current_memory / $memory_limit) * 100;

                    if ($memory_usage_percent > 85) {
                        error_log("TikTok: Memory usage still high at {$memory_usage_percent}% - stopping video fetch to prevent memory exhaustion");
                        break;
                    }
                }

                $request_body = [
                    'fields' => ['id', 'title', 'cover_image_url', 'share_url', 'video_description', 'create_time', 'statistics'],
                    'max_count' => 20, // Process in smaller batches
                ];

                if ($cursor) {
                    $request_body['cursor'] = $cursor;
                }

                $response = $this->make_request(
                    self::API_BASE_URL . '/video/list/',
                    [
                        'method' => 'GET',
                        'headers' => [
                            'Authorization' => 'Bearer ' . $this->config['access_token'],
                            'Content-Type' => 'application/json',
                        ],
                        'body' => $request_body,
                    ]
                );


                if (!empty($response['data']['videos'])) {
                    // Process videos in streaming fashion to minimize memory footprint
                    foreach ($response['data']['videos'] as $video) {
                        $formatted_item = $this->format_feed_item([
                            'id' => $video['id'],
                            'title' => $video['title'],
                            'description' => $video['video_description'],
                            'media_url' => $video['share_url'],
                            'thumbnail_url' => $video['cover_image_url'],
                            'original_url' => $video['share_url'],
                            'created_at' => $video['create_time'],
                            'likes' => $video['statistics']['like_count'] ?? 0,
                            'comments' => $video['statistics']['comment_count'] ?? 0,
                            'shares' => $video['statistics']['share_count'] ?? 0,
                            'author_name' => $video['author']['display_name'] ?? 'Unknown',
                            'author_avatar' => $video['author']['avatar_url'] ?? '',
                            'author_profile' => $video['author']['profile_url'] ?? '',
                        ], 'video');

                        if ($formatted_item) {
                            $all_items[] = $formatted_item;
                        }

                        // Clear video data from memory immediately after processing
                        unset($video, $formatted_item);
                    }

                    // Get cursor for next page if available
                    $cursor = $response['data']['cursor'] ?? null;

                    // Log progress every request
                } else {
                    break;
                }

                // Clear response data from memory
                unset($response);

                $request_count++;

                // Periodic garbage collection every 3 requests
                if ($request_count % 3 === 0) {
                    gc_collect_cycles();
                }

            } while ($cursor && $request_count < $max_requests);

            // Final memory usage report
            $final_memory = memory_get_usage(true);
            $peak_memory = memory_get_peak_usage(true);

            return $all_items;

        } catch (\Exception $e) {
            error_log('TikTok API Error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get live broadcasts
     *
     * @return array
     */
    private function get_live_broadcasts()
    {
        try {
            $response = $this->make_request(
                self::API_BASE_URL . '/live/list/',
                [
                    'method' => 'GET',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['access_token'],
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'fields' => ['id', 'title', 'cover', 'share_url', 'description', 'create_time', 'viewer_count', 'status'],
                        'status' => 'all',
                    ],
                ]
            );

            $items = [];
            if (!empty($response['data']['lives'])) {
                foreach ($response['data']['lives'] as $live) {
                    $items[] = $this->format_feed_item([
                        'id' => $live['id'],
                        'title' => $live['title'],
                        'description' => $live['description'] ?? '',
                        'media_url' => $live['share_url'],
                        'thumbnail_url' => $live['cover']['url_list'][0] ?? '',
                        'original_url' => $live['share_url'],
                        'created_at' => $live['create_time'],
                        'likes' => 0,
                        'comments' => 0,
                        'shares' => 0,
                        'author_name' => $live['owner']['display_name'],
                        'author_avatar' => $live['owner']['avatar_url'],
                        'author_profile' => $live['owner']['profile_url'],
                    ], 'live');
                }
            }

            return $items;
        } catch (\Exception $e) {
            $this->log_error("Error fetching live broadcasts: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get stream status from details
     *
     * @param array $details
     * @return string
     */
    private function get_stream_status_from_details($details)
    {
        if (empty($details['status'])) {
            return 'ended';
        }

        switch ($details['status']) {
            case 'living':
                return 'live';
            case 'scheduled':
                return 'upcoming';
            default:
                return 'ended';
        }
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
     * Make HTTP request with exponential backoff retry logic (TikTok-specific)
     *
     * @param string $url
     * @param array $args
     * @param int $max_retries Maximum number of retry attempts (default: 3)
     * @return array
     * @throws \Exception
     */
    protected function make_request($url, $args = [], $max_retries = 3)
    {
        error_log('TikTok API Request: ' . $url . ' with args: ' . json_encode($args['body'] ?? []));

        // Add TikTok-specific API key to headers before calling parent
        if (!isset($args['headers'])) {
            $args['headers'] = [];
        }
        $args['headers']['X-API-KEY'] = $this->config['api_key'];

        // Use parent class retry logic with TikTok-specific headers
        return parent::make_request($url, $args, $max_retries);
    }
}