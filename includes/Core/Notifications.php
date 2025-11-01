<?php
namespace SocialFeed\Core;

/**
 * Handle notifications for social feed events
 */
class Notifications {
    /**
     * @var bool Flag to track initialization
     */
    private static $initialized = false;

    /**
     * @var Notifications|null Singleton instance
     */
    private static $instance = null;

    /**
     * @var bool Flag to track Church App Notifications availability
     */
    private static $church_app_available = false;

    /**
     * Get singleton instance
     *
     * @return Notifications
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct() {
        // Check Church App Notifications availability
        self::$church_app_available = $this->check_church_app_available();
    }

    /**
     * Check if Church App Notifications is available and properly initialized
     *
     * @return bool
     */
    private function check_church_app_available() {
        // First check if the plugin is active
        if (!defined('SOCIAL_FEED_CHURCH_APP_AVAILABLE') || !SOCIAL_FEED_CHURCH_APP_AVAILABLE) {
            error_log('Social Feed: Church App Notifications plugin is not active');
            return false;
        }

        // Check if the plugin is actually loaded by looking for its functions or actions
        $plugin_loaded = false;
        
        // Check for common Church App Notifications functions/hooks
        if (function_exists('church_app_notifications_init') || 
            has_action('init', 'church_app_notifications_init') ||
            class_exists('Church_App_Notifications') ||
            defined('CHURCH_APP_NOTIFICATIONS_VERSION')) {
            $plugin_loaded = true;
        }

        if (!$plugin_loaded) {
            error_log('Social Feed: Church App Notifications plugin is active but not properly loaded');
            // Still return true for basic integration - we'll handle missing features gracefully
        }

        // Check for database tables (optional - create if needed)
        global $wpdb;
        $tokens_table = $wpdb->prefix . 'app_push_tokens';
        $notifications_table = $wpdb->prefix . 'app_notifications';

        $tokens_exist = $wpdb->get_var("SHOW TABLES LIKE '$tokens_table'") === $tokens_table;
        $notifications_exist = $wpdb->get_var("SHOW TABLES LIKE '$notifications_table'") === $notifications_table;

        if (!$tokens_exist || !$notifications_exist) {
            error_log('Social Feed: Church App Notifications database tables are missing - notifications will be limited');
            // Don't fail completely - we can still work with basic functionality
        }

        error_log('Social Feed: Church App Notifications integration check completed - plugin loaded: ' . ($plugin_loaded ? 'yes' : 'no'));
        return true; // Return true if plugin is active, handle missing features gracefully
    }

    /**
     * Initialize notifications system
     */
    public function init() {
        // Prevent multiple initializations
        if (self::$initialized) {
            error_log('Social Feed: Notifications system already initialized, skipping');
            return;
        }

        // Check Church App Notifications availability
        self::$church_app_available = $this->check_church_app_available();

        if (!self::$church_app_available) {
            error_log('Social Feed: Church App Notifications plugin not available or not properly initialized');
            return;
        }

        error_log('Social Feed: Initializing notifications system');
            
        // Schedule periodic checks
            if (!wp_next_scheduled('social_feed_check_notifications')) {
                wp_schedule_event(time(), 'every_minute', 'social_feed_check_notifications');
            }

        // Add action hooks for content updates
            add_action('social_feed_check_notifications', [$this, 'check_and_send_notifications']);
        add_action('social_feed_new_content', [$this, 'handle_new_content']);
        add_action('social_feed_stream_status_change', [$this, 'handle_stream_status_change']);

        self::$initialized = true;
        error_log('Social Feed: Notifications system initialized');
    }

    /**
     * Register device token for notifications
     *
     * @param int $user_id
     * @param string $device_token
     * @param string $platform
     * @param array $notification_types
     * @return bool|WP_Error
     */
    public function register_device($user_id, $device_token, $platform, $notification_types = ['video', 'live']) {
        if (!defined('SOCIAL_FEED_CHURCH_APP_AVAILABLE') || !SOCIAL_FEED_CHURCH_APP_AVAILABLE) {
            return new \WP_Error(
                'notifications_disabled',
                'Push notifications are currently disabled because the Church App Notifications plugin is not available.',
                ['status' => 503]
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'app_push_tokens';
        
        // Check if device token already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE token = %s",
            $device_token
        ));

        if ($existing) {
            // Update existing record
            $result = $wpdb->update(
                $table,
                [
                    'user_id' => $user_id,
                    'updated_at' => current_time('mysql')
                ],
                ['token' => $device_token],
                ['%d', '%s'],
                ['%s']
            );
            return $result !== false;
        }

        // Insert new record
        $result = $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'token' => $device_token,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s']
        );
        return $result !== false;
    }

    /**
     * Unregister device token
     *
     * @param int $user_id
     * @param string $device_token
     * @return bool|WP_Error
     */
    public function unregister_device($user_id, $device_token) {
        if (!defined('SOCIAL_FEED_CHURCH_APP_AVAILABLE') || !SOCIAL_FEED_CHURCH_APP_AVAILABLE) {
            return new \WP_Error(
                'notifications_disabled',
                'Push notifications are currently disabled because the Church App Notifications plugin is not available.',
                ['status' => 503]
            );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'app_push_tokens';
        
        return $wpdb->delete(
            $table,
            [
                'user_id' => $user_id,
                'token' => $device_token
            ],
            ['%d', '%s']
        ) !== false;
    }

    /**
     * Get the appropriate notification channel for the type
     *
     * @param string $type Notification type
     * @return string Channel ID
     */
    private function get_notification_channel($type) {
        // Remove social_ prefix if present
        $type = str_replace('social_', '', $type);
        
        switch ($type) {
            case 'video':
                return 'videos';
            case 'live':
                return 'live';
            default:
                return 'social';
        }
    }

    /**
     * Format notification data
     *
     * @param array $data Raw notification data
     * @return array
     */
    private function format_notification($data) {
        // Clean up the type
        $type = str_replace('social_', '', $data['type']);
        
        // Ensure URL is properly formatted for deep linking
        $url = !empty($data['url']) ? $data['url'] : '';
        $youtube_url = $url; // Store original YouTube URL
        
        // Extract video ID and create deep link for both videos and shorts
        if (strpos($url, 'youtube.com/watch?v=') !== false || strpos($url, 'youtu.be/') !== false) {
            preg_match('/(?:watch\?v=|youtu\.be\/)([^&]+)/', $url, $matches);
            $video_id = $matches[1] ?? '';
            if ($video_id) {
                $url = 'dubaidebremewi://watch/' . $video_id;
            }
        }

        return [
            'data' => [
                'type' => $type,
                'title' => $data['title'] ?? '',
                'body' => $data['message'] ?? '',
                'url' => $url, // Deep link URL
                'youtube_url' => $youtube_url, // Original YouTube URL
                'image' => $data['image'] ?? '',
                'timestamp' => current_time('mysql'),
                'reference_id' => (string)($data['reference_id'] ?? ''),
                'reference_type' => $data['reference_type'] ?? 'social_feed',
                'reference_url' => $url, // Use deep link URL here as well
                'click_action' => $url
            ]
        ];
    }

    /**
     * Send notification
     *
     * @param array $notification
     * @return bool
     */
    private function send_notification($notification) {
        if (!self::$church_app_available) {
            error_log('Social Feed: Cannot send notification - Church App Notifications not available');
            return false;
        }

        try {
            error_log('Social Feed: Starting send_notification process with data: ' . json_encode($notification));

            // Clean up the type for database storage
            $db_type = str_replace('social_', '', $notification['type']);
            
            // Format notification first to get proper channel and data
            $formatted_notification = $this->format_notification([
                'type' => $db_type,
                'title' => $notification['title'],
                'message' => $notification['message'] ?? $notification['title'],
                'url' => $notification['url'],
                'image' => $notification['image'] ?? '',
                'reference_id' => $notification['reference_id'],
                'reference_type' => $notification['reference_type']
            ]);
            
            error_log('Social Feed: Formatted notification: ' . json_encode($formatted_notification));

            // Get the channel for this notification
            $channel = $this->get_notification_channel($db_type);

            // Prepare notification details
            $details = [
                'channel' => $channel,
                'notification_data' => $formatted_notification['data'],
                'android' => [
                    'channelId' => $channel,
                    'priority' => 'high',
                    'notification' => [
                        'color' => '#2196F3',
                        'click_action' => $formatted_notification['data']['url']
                    ]
                ],
                'ios' => [
                    'sound' => true,
                    'priority' => 10,
                    'category' => $db_type
                ],
                'data' => [
                    'url' => $formatted_notification['data']['url'],
                    'youtube_url' => $formatted_notification['data']['youtube_url'],
                    'type' => $db_type
                ]
            ];

            // Add image if available
            if (!empty($notification['image'])) {
                $details['android']['notification']['image'] = $notification['image'];
                $details['ios']['attachments'] = ['url' => $notification['image']];
            }

            // Store in database
            global $wpdb;
            $notifications_table = $wpdb->prefix . 'app_notifications';
            
            // Check for duplicate before proceeding
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $notifications_table 
                WHERE reference_id = %s AND type = %s",
                $notification['reference_id'],
                $db_type
            ));

            if ($existing > 0) {
                error_log('Social Feed: Duplicate notification detected for ID: ' . $notification['reference_id']);
                return false;
            }

            // Insert notification
            $insert_data = [
                'user_id' => 0, // 0 means for all users
                'title' => $notification['title'],
                'body' => $notification['message'] ?? $notification['title'],
                'type' => $db_type,
                'is_read' => '0',
                'created_at' => current_time('mysql'),
                'reference_id' => $notification['reference_id'],
                'reference_type' => $notification['reference_type'],
                'reference_url' => $formatted_notification['data']['url'], // Use the formatted deep link URL
                'image_url' => $notification['image'] ?? '',
                'details' => json_encode($details)
            ];

            $result = $wpdb->insert(
                $notifications_table,
                $insert_data,
                [
                    '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
                ]
            );

            if ($result === false) {
                error_log('Social Feed: Failed to store notification in database: ' . $wpdb->last_error);
                return false;
            }

            $notification_id = $wpdb->insert_id;

            // Get all active device tokens
            $tokens_table = $wpdb->prefix . 'app_push_tokens';
            $device_tokens = $wpdb->get_col("SELECT token FROM $tokens_table WHERE is_active = 1");

            if (empty($device_tokens)) {
                error_log('Social Feed: No active device tokens found');
                return false;
            }

            // Prepare push notification payload
            $push_payload = [
                'notification' => [
                    'title' => $notification['title'],
                    'body' => $notification['message'] ?? $notification['title'],
                    'image' => $notification['image'] ?? null,
                    'channelId' => $channel,
                    'click_action' => $notification['url']
                ],
                'data' => [
                    'type' => $db_type,
                    'reference_id' => $notification['reference_id'],
                    'reference_type' => $notification['reference_type'],
                    'url' => $notification['url'],
                    'notification_id' => $notification_id,
                    'click_action' => $notification['url']
                ],
                'android' => [
                    'channelId' => $channel,
                    'priority' => 'high',
                    'notification' => [
                        'color' => '#2196F3',
                        'sound' => 'default',
                        'click_action' => $notification['url']
                    ]
                ],
                'ios' => [
                    'sound' => 'default',
                    'priority' => 10,
                    'badge' => 1,
                    'click_action' => $notification['url'],
                    'category' => $db_type
                ]
            ];

            if (!empty($notification['image'])) {
                $push_payload['notification']['image'] = $notification['image'];
                $push_payload['android']['notification']['image'] = $notification['image'];
                $push_payload['ios']['attachments'] = ['url' => $notification['image']];
            }

            // Send push notification through Church App Notifications
            if (class_exists('Church_App_Notifications_Expo_Push')) {
                try {
                    $expo_push = new \Church_App_Notifications_Expo_Push();
                    $sent = $expo_push->send_notification($notification_id);
                    error_log('Social Feed: Push notification sent through Expo Push: ' . ($sent ? 'success' : 'failed'));
                } catch (\Exception $e) {
                    error_log('Social Feed: Error sending through Expo Push - ' . $e->getMessage());
                    // Fall back to action hook
                    do_action('church_app_send_push_notification', $device_tokens, $push_payload);
                }
            } else {
                // Fallback to action hook
                do_action('church_app_send_push_notification', $device_tokens, $push_payload);
            }
            
            return true;
            
        } catch (\Exception $e) {
            error_log('Social Feed: Error in send_notification - ' . $e->getMessage());
            error_log('Social Feed: Error trace - ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * Handle new content notification
     *
     * @param array $data Content data
     */
    public function handle_new_content($data) {
        if (!self::$church_app_available || !self::$initialized) {
            error_log('Social Feed: Notifications system not available or not initialized');
            return;
        }

        if (empty($data['platform']) || empty($data['type']) || empty($data['content'])) {
            error_log('Social Feed: Invalid content data for notification: ' . json_encode($data));
            return;
        }

        try {
            global $wpdb;
            $notifications_table = $wpdb->prefix . 'app_notifications';

            // Strict duplicate check - check for ANY existing notification with this reference_id
            $existing_notification = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $notifications_table 
                WHERE reference_id = %s",
                $data['content']['id']
            ));
            
            if ($existing_notification > 0) {
                error_log("Social Feed: Notification already exists for content ID: {$data['content']['id']}, skipping");
                return;
            }

            // Add a transient lock
            $lock_key = 'social_feed_notification_lock_' . $data['content']['id'];
            if (get_transient($lock_key)) {
                error_log("Social Feed: Notification is currently being processed for content ID: {$data['content']['id']}");
                return;
            }

            // Set lock before processing
            set_transient($lock_key, true, MINUTE_IN_SECONDS);

            try {
                // Double-check duplicates one more time after acquiring lock
                $double_check = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $notifications_table 
                    WHERE reference_id = %s",
                    $data['content']['id']
                ));

                if ($double_check > 0) {
                    error_log("Social Feed: Duplicate detected after lock for content ID: {$data['content']['id']}");
                    return;
                }

            $notification = [
                'type' => 'social_' . $data['type'],
                'title' => 'New ' . ucfirst($data['type']) . ' Available',
                'message' => $data['content']['title'],
                    'url' => $data['content']['original_url'],
                'image' => $data['content']['thumbnail_url'],
                'reference_id' => $data['content']['id'],
                    'reference_type' => $data['type']
            ];
            
            if ($this->send_notification($notification)) {
                error_log("Social Feed: Successfully sent notification for content ID: {$data['content']['id']}");
                    
                    // Set a longer transient to prevent re-processing for 24 hours
                    set_transient(
                        'social_feed_notification_sent_' . $data['content']['id'],
                        true,
                        24 * HOUR_IN_SECONDS
                    );
            } else {
                error_log("Social Feed: Failed to send notification for content ID: {$data['content']['id']}");
                }
            } finally {
                delete_transient($lock_key);
            }

        } catch (\Exception $e) {
            error_log("Social Feed: Error processing new content: " . $e->getMessage());
            error_log("Social Feed: Error trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Handle stream status change notification
     *
     * @param array $data Stream data
     */
    public function handle_stream_status_change($data) {
        if (!self::$church_app_available || !self::$initialized) {
            error_log('Social Feed: Notifications system not available or not initialized');
            return;
        }

        if (empty($data['platform']) || empty($data['stream_id']) || empty($data['current_status'])) {
            return;
        }

        // Log the status change
        error_log(sprintf(
            'Social Feed: Stream %s status changed from %s to %s',
            $data['stream_id'],
            $data['previous_status'] ?? 'unknown',
            $data['current_status']
        ));

        // Update database
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_streams';
        
        $wpdb->replace(
            $table,
            [
                'platform' => $data['platform'],
                'stream_id' => $data['stream_id'],
                'status' => $data['current_status'],
                'details' => json_encode($data['details']),
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        // Send notification when stream goes live
        if ($data['current_status'] === 'live' && 
            ($data['previous_status'] !== 'live')) {
            
            // Check if we've sent a notification for this stream recently
            $notification_key = 'social_feed_live_notification_' . $data['stream_id'];
            if (get_transient($notification_key)) {
                error_log('Social Feed: Skipping duplicate notification for stream ' . $data['stream_id']);
                return;
            }

            // Check for existing notification in database
            $notifications_table = $wpdb->prefix . 'app_notifications';
            $existing_notification = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $notifications_table 
                WHERE type = 'social_live' 
                AND reference_id = %s 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
                $data['stream_id']
            ));

            if ($existing_notification > 0) {
                error_log('Social Feed: Recent notification exists for stream ' . $data['stream_id'] . ', skipping');
                return;
            }

            // Format stream title and platform info
            $platform_name = ucfirst($data['platform']);
            $stream_title = !empty($data['details']['title']) ? $data['details']['title'] : 'Live Stream';
            
            $notification = [
                'type' => 'social_live',
                'title' => $platform_name . ' Live Stream Started',
                'message' => $stream_title,
                'url' => $data['details']['stream_url'],
                'image' => $data['details']['thumbnail_url'],
                'reference_id' => $data['stream_id'],
                'reference_type' => 'live_stream'
            ];
            
            if ($this->send_notification($notification)) {
                // Set a transient to prevent duplicate notifications
                set_transient($notification_key, true, 5 * MINUTE_IN_SECONDS);
            }
        }
    }

    /**
     * Check for new content and send notifications
     * This method is called by the WordPress cron
     */
    public function check_and_send_notifications() {
        if (!self::$church_app_available || !self::$initialized) {
            error_log('Social Feed: Notifications system not available or not initialized');
            return;
        }

        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';
        $streams_table = $wpdb->prefix . 'social_feed_streams';

        // Get last check time, default to 1 hour ago if not set
        $last_check = get_option('social_feed_last_notification_check', date('Y-m-d H:i:s', strtotime('-1 hour')));

        // Check for new videos only - live streams are handled by handle_stream_status_change
        $new_videos = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $cache_table 
            WHERE content_type IN ('video', 'short') 
            AND created_at > %s 
            ORDER BY created_at DESC",
            $last_check
        ));

        foreach ($new_videos as $video) {
            $content = json_decode($video->content);
            if (!$content) continue;

            // Check if notification was already sent
            $notification_sent = get_transient('social_feed_notification_sent_' . $video->content_id);
            if ($notification_sent) continue;

            $notification = [
                'type' => 'social_' . $video->content_type,
                'title' => 'New ' . ucfirst($video->content_type) . ' Available',
                'message' => $content->title,
                'url' => $content->original_url,
                'image' => $content->thumbnail_url,
                'reference_id' => $video->content_id,
                'reference_type' => $video->content_type
            ];
            
            if ($this->send_notification($notification)) {
                // Set a transient to prevent duplicate notifications
                set_transient(
                    'social_feed_notification_sent_' . $video->content_id,
                    true,
                    24 * HOUR_IN_SECONDS
                );
            }
        }

        // Update last check time
        update_option('social_feed_last_notification_check', current_time('mysql'));

        // Trigger refresh action
        do_action('social_feed_content_refreshed');
    }

    /**
     * Test notifications system
     * 
     * @param string $type Type of notification to test (video, live, or both)
     * @return array Test results
     */
    public function test_notifications($type = 'both') {
        // Check if we're in production
        if (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production') {
            return [
                'success' => false,
                'messages' => ['Test notifications are disabled in production environment']
            ];
        }

        error_log('Social Feed: Starting comprehensive notification test');
        $results = ['success' => true, 'messages' => []];

        try {
            // Check if notifications system is initialized
            if (!self::$initialized) {
                throw new \Exception('Notifications system not initialized');
            }

            if (!self::$church_app_available) {
                throw new \Exception('Church App Notifications plugin not available or not properly configured');
            }

            // Check for active device tokens (gracefully handle missing table)
            global $wpdb;
            $tokens_table = $wpdb->prefix . 'app_push_tokens';
            
            // Check if table exists first
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$tokens_table'") === $tokens_table;
            
            if ($table_exists) {
                $active_tokens = $wpdb->get_var("SELECT COUNT(*) FROM $tokens_table WHERE is_active = 1");
                $results['messages'][] = "Found {$active_tokens} active device tokens";
            } else {
                $results['messages'][] = "Push tokens table not found - notifications may not work properly";
                // Continue with test but note the limitation
            }

            if ($type === 'video' || $type === 'both') {
                // Test video notification
                $video_id = 'test_video_' . time();
                $video_url = 'https://www.youtube.com/watch?v=' . $video_id;
                
                $notification = [
                    'type' => 'video',
                    'title' => 'New Video Available',
                    'message' => 'Test Video Notification ' . date('Y-m-d H:i:s'),
                    'url' => $video_url,
                    'image' => 'https://example.com/thumbnail.jpg',
                    'reference_id' => $video_id,
                    'reference_type' => 'video'
                ];

                // First attempt should succeed
                $sent = $this->send_notification($notification);
                
                // Verify notification was stored
                $notification_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}app_notifications 
                    WHERE reference_id = %s AND type = 'video'",
                    $video_id
                ));

                $results['messages'][] = ($notification_exists && $sent)
                    ? "✓ Video notification stored successfully" 
                    : "✗ Failed to store video notification";

                // Test duplicate prevention
                $this->send_notification($notification);
                $duplicate_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}app_notifications 
                    WHERE reference_id = %s AND type = 'video'",
                    $video_id
                ));

                $results['messages'][] = $duplicate_count === '1' 
                    ? "✓ Duplicate prevention working for video" 
                    : "✗ Duplicate prevention failed for video";
            }

            if ($type === 'live' || $type === 'both') {
                // Test live stream notification
                $stream_id = 'test_stream_' . time();
                $stream_url = 'https://www.youtube.com/watch?v=' . $stream_id;
                
                $notification = [
                    'type' => 'live',
                    'title' => 'YouTube Live Stream Started',
                    'message' => 'Test Live Stream ' . date('Y-m-d H:i:s'),
                    'url' => $stream_url,
                    'image' => 'https://example.com/thumbnail.jpg',
                    'reference_id' => $stream_id,
                    'reference_type' => 'live_stream'
                ];

                // First attempt should succeed
                $sent = $this->send_notification($notification);
                
                // Verify notification was stored
                $notification_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}app_notifications 
                    WHERE reference_id = %s AND type = 'live'",
                    $stream_id
                ));

                $results['messages'][] = ($notification_exists && $sent)
                    ? "✓ Live stream notification stored successfully" 
                    : "✗ Failed to store live stream notification";

                // Test duplicate prevention
                $this->send_notification($notification);
                $duplicate_count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}app_notifications 
                    WHERE reference_id = %s AND type = 'live'",
                    $stream_id
                ));

                $results['messages'][] = $duplicate_count === '1' 
                    ? "✓ Duplicate prevention working for live stream" 
                    : "✗ Duplicate prevention failed for live stream";
            }

            // Verify URL format in latest notification
            $latest_notification = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}app_notifications 
                ORDER BY created_at DESC LIMIT 1"
            );

            if ($latest_notification) {
                $has_youtube_url = strpos($latest_notification->reference_url, 'youtube.com/watch?v=') !== false;
                $results['messages'][] = $has_youtube_url 
                    ? "✓ YouTube URLs are properly formatted" 
                    : "✗ YouTube URLs not properly formatted";
            }

            error_log('Social Feed: Notification test completed successfully');
            return $results;

        } catch (\Exception $e) {
            error_log('Social Feed: Test failed - ' . $e->getMessage());
            $results['success'] = false;
            $results['messages'][] = "Error: " . $e->getMessage();
            return $results;
        }
    }
}