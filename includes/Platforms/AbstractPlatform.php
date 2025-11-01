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
     * Make HTTP request with exponential backoff retry logic
     *
     * @param string $url
     * @param array $args
     * @param int $max_retries Maximum number of retry attempts (default: 3)
     * @return array
     * @throws \Exception
     */
    protected function make_request($url, $args = [], $max_retries = 3) {
        $attempt = 0;
        $last_exception = null;

        while ($attempt <= $max_retries) {
            try {
                // Check rate limiting before each attempt
                $rate_key = "social_feed_rate_{$this->platform}";
                $rate_limit = get_transient($rate_key);

                if ($rate_limit !== false && $attempt === 0) {
                    throw new \Exception("Rate limit exceeded for {$this->platform}");
                }

                // Make request
                $response = wp_remote_request($url, $args);

                // Handle WordPress errors
                if (is_wp_error($response)) {
                    $error_message = $response->get_error_message();
                    
                    // Check if it's a retryable error
                    if ($this->is_retryable_error($error_message) && $attempt < $max_retries) {
                        $this->log_retry_attempt($attempt + 1, $error_message, $url);
                        $this->wait_with_backoff($attempt);
                        $attempt++;
                        continue;
                    }
                    
                    throw new \Exception($error_message);
                }

                $status = wp_remote_retrieve_response_code($response);
                
                // Handle successful response
                if ($status === 200) {
                    // Parse response
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('Invalid JSON response');
                    }

                    // Log successful retry if this wasn't the first attempt
                    if ($attempt > 0) {
                        $this->log_successful_retry($attempt, $url);
                    }

                    return $data;
                }

                // Handle error status codes
                $error_message = "API request failed with status: $status";
                
                // Handle rate limiting
                if ($status === 429) {
                    $retry_after = wp_remote_retrieve_header($response, 'retry-after');
                    $wait_time = $retry_after ? (int)$retry_after : $this->calculate_backoff_delay($attempt);
                    set_transient($rate_key, true, $wait_time);
                    
                    if ($attempt < $max_retries) {
                        $this->log_retry_attempt($attempt + 1, "Rate limited (429)", $url);
                        sleep($wait_time);
                        $attempt++;
                        continue;
                    }
                }

                // Check if status code is retryable
                if ($this->is_retryable_status($status) && $attempt < $max_retries) {
                    $this->log_retry_attempt($attempt + 1, $error_message, $url);
                    $this->wait_with_backoff($attempt);
                    $attempt++;
                    continue;
                }

                throw new \Exception($error_message);

            } catch (\Exception $e) {
                $last_exception = $e;
                
                // Don't retry if it's not a retryable error or we've reached max retries
                if (!$this->is_retryable_exception($e) || $attempt >= $max_retries) {
                    break;
                }
                
                $this->log_retry_attempt($attempt + 1, $e->getMessage(), $url);
                $this->wait_with_backoff($attempt);
                $attempt++;
            }
        }

        // If we get here, all retries failed
        $this->log_error("All retry attempts failed for URL: $url", [
            'attempts' => $attempt,
            'last_error' => $last_exception ? $last_exception->getMessage() : 'Unknown error'
        ]);
        
        throw $last_exception ?: new \Exception("Request failed after $max_retries retries");
    }

    /**
     * Check if an error is retryable
     *
     * @param string $error_message
     * @return bool
     */
    protected function is_retryable_error($error_message) {
        $retryable_patterns = [
            'timeout',
            'connection',
            'network',
            'temporary',
            'server error',
            'service unavailable'
        ];

        $error_lower = strtolower($error_message);
        foreach ($retryable_patterns as $pattern) {
            if (strpos($error_lower, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if HTTP status code is retryable
     *
     * @param int $status
     * @return bool
     */
    protected function is_retryable_status($status) {
        $retryable_statuses = [429, 500, 502, 503, 504];
        return in_array($status, $retryable_statuses);
    }

    /**
     * Check if exception is retryable
     *
     * @param \Exception $exception
     * @return bool
     */
    protected function is_retryable_exception($exception) {
        return $this->is_retryable_error($exception->getMessage());
    }

    /**
     * Calculate exponential backoff delay with jitter
     *
     * @param int $attempt
     * @return int Delay in seconds
     */
    protected function calculate_backoff_delay($attempt) {
        // Base delay: 1s, 2s, 4s, 8s...
        $base_delay = pow(2, $attempt);
        
        // Add jitter (random 0-50% of base delay)
        $jitter = mt_rand(0, $base_delay * 0.5);
        
        return min($base_delay + $jitter, 30); // Cap at 30 seconds
    }

    /**
     * Wait with exponential backoff
     *
     * @param int $attempt
     */
    protected function wait_with_backoff($attempt) {
        $delay = $this->calculate_backoff_delay($attempt);
        sleep($delay);
    }

    /**
     * Log retry attempt
     *
     * @param int $attempt
     * @param string $error
     * @param string $url
     */
    protected function log_retry_attempt($attempt, $error, $url) {
        $this->log_error("Retry attempt $attempt", [
            'url' => $url,
            'error' => $error,
            'platform' => $this->platform
        ]);
    }

    /**
     * Log successful retry
     *
     * @param int $attempts
     * @param string $url
     */
    protected function log_successful_retry($attempts, $url) {
        error_log(json_encode([
            'platform' => $this->platform,
            'message' => "Request succeeded after $attempts retries",
            'url' => $url,
            'timestamp' => current_time('mysql')
        ]));
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