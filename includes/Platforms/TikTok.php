<?php
namespace SocialFeed\Platforms;

class TikTok extends AbstractPlatform {
    /**
     * @var string TikTok API base URL
     */
    const API_BASE_URL = 'https://open.tiktokapis.com/v2';

    /**
     * Constructor
     */
    public function __construct() {
        $this->platform = 'tiktok';
    }

    /**
     * Get feed items from TikTok
     *
     * @param array $types Content types to fetch
     * @return array
     */
    public function get_feed($types = []) {
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
                error_log("TikTok Feed: Fetching {$type}s");
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

        error_log('TikTok Feed: Total items fetched: ' . count($items));
        return $items;
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
                'id' => $stream['stream_id'],
                'title' => $stream['title'],
                'description' => $stream['description'] ?? '',
                'thumbnail_url' => $stream['cover']['url_list'][0] ?? '',
                'stream_url' => $stream['share_url'],
                'status' => $this->get_stream_status_from_details($stream),
                'viewer_count' => $stream['viewer_count'] ?? 0,
                'started_at' => $stream['create_time'] ?? null,
                'scheduled_for' => $stream['schedule_time'] ?? null,
                'channel_name' => $stream['owner']['display_name'],
                'channel_avatar' => $stream['owner']['avatar_url'],
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
        return !empty($config['api_key']) && !empty($config['access_token']);
    }

    /**
     * Get supported content types
     *
     * @return array
     */
    public function get_supported_types() {
        return ['video', 'live'];
    }

    /**
     * Get videos
     *
     * @return array
     */
    private function get_videos() {
        error_log('TikTok: Making request to fetch videos');
        try {
            $response = $this->make_request(
                self::API_BASE_URL . '/video/list/',
                [
                    'method' => 'GET',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->config['access_token'],
                        'Content-Type' => 'application/json',
                    ],
                    'body' => [
                        'fields' => ['id', 'title', 'cover_image_url', 'share_url', 'video_description', 'create_time', 'statistics'],
                        'max_count' => 20,
                    ],
                ]
            );

            error_log('TikTok API Response: ' . json_encode($response));
            
            $items = [];
            if (!empty($response['data']['videos'])) {
                foreach ($response['data']['videos'] as $video) {
                    $items[] = $this->format_feed_item([
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
                        'author_name' => $video['author']['display_name'],
                        'author_avatar' => $video['author']['avatar_url'],
                        'author_profile' => $video['author']['profile_url'],
                    ], 'video');
                }
            }

            error_log('TikTok: Successfully fetched ' . count($items) . ' videos');
            return $items;
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
    private function get_live_broadcasts() {
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
    private function get_stream_status_from_details($details) {
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
     * Make HTTP request with rate limiting and error handling
     *
     * @param string $url
     * @param array $args
     * @return array
     * @throws \Exception
     */
    protected function make_request($url, $args = []) {
        error_log('TikTok API Request: ' . $url . ' with args: ' . json_encode($args['body'] ?? []));
        
        // Check rate limiting
        $rate_key = "social_feed_rate_{$this->platform}";
        $rate_limit = get_transient($rate_key);

        if ($rate_limit !== false) {
            throw new \Exception("Rate limit exceeded for {$this->platform}");
        }

        // Add API key to headers
        if (!isset($args['headers'])) {
            $args['headers'] = [];
        }
        $args['headers']['X-API-KEY'] = $this->config['api_key'];

        // Make request
        $response = wp_remote_request($url, $args);

        // Handle errors
        if (is_wp_error($response)) {
            error_log('TikTok API Error: ' . $response->get_error_message());
            throw new \Exception($response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        error_log('TikTok API Response Status: ' . $status);
        error_log('TikTok API Response Body: ' . $body);

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
} 