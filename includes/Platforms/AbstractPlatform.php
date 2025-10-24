<?php
namespace SocialFeed\Platforms;

use SocialFeed\Platforms\PlatformInterface;

abstract class AbstractPlatform implements PlatformInterface {
    /**
     * @var string Platform identifier
     */
    protected $platform;

    /**
     * @var array Platform configuration
     */
    protected $config;

    /**
     * Constructor
     */
    public function __construct() {
        $this->config = $this->get_config();
    }

    /**
     * Initialize the platform
     */
    public function init() {
        if (!$this->config) {
            $this->config = $this->get_config();
        }
    }

    /**
     * Get platform configuration
     *
     * @return array
     */
    public function get_config() {
        $platforms = get_option('social_feed_platforms', []);
        return $platforms[$this->platform] ?? [];
    }

    /**
     * Check if platform is configured and enabled
     *
     * @return bool
     */
    protected function is_configured() {
        if (empty($this->config) || empty($this->config['enabled'])) {
            return false;
        }

        return $this->validate_config($this->config);
    }

    /**
     * Format feed item
     *
     * @param array $item Raw platform item
     * @param string $type Content type
     * @return array
     */
    protected function format_feed_item($item, $type) {
        return [
            'id' => $item['id'],
            'platform' => $this->platform,
            'type' => $type,
            'content' => [
                'title' => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'media_url' => $item['media_url'] ?? '',
                'thumbnail_url' => $item['thumbnail_url'] ?? '',
                'original_url' => $item['original_url'] ?? '',
                'created_at' => $item['created_at'] ?? '',
                'engagement' => [
                    'likes' => $item['likes'] ?? 0,
                    'comments' => $item['comments'] ?? 0,
                    'shares' => $item['shares'] ?? 0,
                ],
            ],
            'author' => [
                'name' => $item['author_name'] ?? '',
                'avatar' => $item['author_avatar'] ?? '',
                'profile_url' => $item['author_profile'] ?? '',
            ],
        ];
    }

    /**
     * Format stream data
     *
     * @param array $stream Raw platform stream data
     * @return array
     */
    protected function format_stream($stream) {
        return [
            'id' => $stream['id'],
            'platform' => $this->platform,
            'title' => $stream['title'] ?? '',
            'description' => $stream['description'] ?? '',
            'thumbnail_url' => $stream['thumbnail_url'] ?? '',
            'stream_url' => $stream['stream_url'] ?? '',
            'status' => $stream['status'] ?? 'ended',
            'viewer_count' => $stream['viewer_count'] ?? 0,
            'started_at' => $stream['started_at'] ?? null,
            'scheduled_for' => $stream['scheduled_for'] ?? null,
            'channel' => [
                'name' => $stream['channel_name'] ?? '',
                'avatar' => $stream['channel_avatar'] ?? '',
            ],
        ];
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
        // Check rate limiting
        $rate_key = "social_feed_rate_{$this->platform}";
        $rate_limit = get_transient($rate_key);

        if ($rate_limit !== false) {
            throw new \Exception("Rate limit exceeded for {$this->platform}");
        }

        // Make request
        $response = wp_remote_request($url, $args);

        // Handle errors
        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            // Handle rate limiting
            if ($status === 429) {
                $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                set_transient($rate_key, true, $retry_after ?: 60);
            }
            throw new \Exception("API request failed with status: $status");
        }

        // Parse response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON response');
        }

        return $data;
    }

    /**
     * Log error with platform context
     *
     * @param string $message
     * @param mixed $context
     */
    protected function log_error($message, $context = null) {
        $error = [
            'platform' => $this->platform,
            'message' => $message,
            'timestamp' => current_time('mysql'),
        ];

        if ($context !== null) {
            $error['context'] = $context;
        }

        error_log(json_encode($error));
    }
} 