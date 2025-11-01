<?php
namespace SocialFeed\Core;

class NotificationHandler {
    /**
     * @var array Delivery confirmation callbacks
     */
    private $delivery_callbacks = [];

    /**
     * @var array Failed notifications for retry
     */
    private $failed_notifications = [];

    /**
     * @var int Maximum retry attempts for failed notifications
     */
    private $max_retry_attempts = 3;

    /**
     * Send notification for new content with delivery confirmation
     *
     * @param array $content Formatted content data
     * @return bool Success status
     */
    public function notify_new_content($content) {
        $notification_id = $this->generate_notification_id('new_content', $content);
        
        try {
            // Send notification with delivery tracking
            $delivery_status = $this->send_notification_with_confirmation(
                'social_feed_new_content',
                [
                    'platform' => $content['platform'] ?? 'unknown',
                    'type' => $content['type'],
                    'content' => $content
                ],
                $notification_id
            );

            $this->log_notification('new_content', $content, $delivery_status, $notification_id);
            return $delivery_status['success'];
            
        } catch (\Exception $e) {
            $this->handle_notification_failure($notification_id, 'new_content', $content, $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification for stream status change with delivery confirmation
     *
     * @param array $stream Stream data
     * @param string $previous_status Previous stream status
     * @param string $current_status Current stream status
     * @return bool Success status
     */
    public function notify_stream_status_change($stream, $previous_status, $current_status) {
        $notification_id = $this->generate_notification_id('stream_status_change', $stream);
        
        try {
            $notification_data = [
                'platform' => $stream['platform'] ?? 'unknown',
                'stream_id' => $stream['id'],
                'previous_status' => $previous_status,
                'current_status' => $current_status,
                'details' => [
                    'id' => $stream['id'],
                    'title' => $stream['title'],
                    'description' => $stream['description'],
                    'thumbnail_url' => $stream['thumbnail_url'],
                    'stream_url' => $stream['stream_url'],
                    'status' => $current_status,
                    'viewer_count' => $stream['viewer_count'] ?? 0,
                    'started_at' => $stream['started_at'],
                    'scheduled_for' => $stream['scheduled_for'],
                    'channel_name' => $stream['channel_name'],
                    'channel_id' => $stream['channel_id']
                ]
            ];

            $delivery_status = $this->send_notification_with_confirmation(
                'social_feed_stream_status_change',
                $notification_data,
                $notification_id
            );

            $this->log_notification('stream_status_change', [
                'stream_id' => $stream['id'],
                'previous_status' => $previous_status,
                'current_status' => $current_status,
                'url' => $stream['stream_url']
            ], $delivery_status, $notification_id);
            
            return $delivery_status['success'];
            
        } catch (\Exception $e) {
            $this->handle_notification_failure($notification_id, 'stream_status_change', $stream, $e->getMessage());
            return false;
        }
    }

    /**
     * Generate unique notification ID
     *
     * @param string $type Notification type
     * @param array $data Notification data
     * @return string
     */
    private function generate_notification_id($type, $data) {
        $unique_data = $type . '_' . ($data['id'] ?? '') . '_' . time();
        return 'notif_' . md5($unique_data);
    }

    /**
     * Send notification with delivery confirmation tracking
     *
     * @param string $hook WordPress action hook
     * @param array $data Notification data
     * @param string $notification_id Unique notification ID
     * @return array Delivery status
     */
    private function send_notification_with_confirmation($hook, $data, $notification_id) {
        $delivery_status = [
            'success' => false,
            'delivered_at' => null,
            'attempts' => 1,
            'error' => null
        ];

        try {
            // Add notification ID to data for tracking
            $data['notification_id'] = $notification_id;
            
            // Register confirmation callback
            $this->register_delivery_callback($notification_id, $delivery_status);
            
            // Send notification
            do_action($hook, $data);
            
            // Check if delivery was confirmed within timeout
            $delivery_status = $this->wait_for_delivery_confirmation($notification_id, 5); // 5 second timeout
            
            if (!$delivery_status['success']) {
                $delivery_status['error'] = 'No delivery confirmation received within timeout';
            }
            
        } catch (\Exception $e) {
            $delivery_status['error'] = $e->getMessage();
        }

        return $delivery_status;
    }

    /**
     * Register delivery callback for notification
     *
     * @param string $notification_id
     * @param array &$delivery_status Reference to delivery status
     */
    private function register_delivery_callback($notification_id, &$delivery_status) {
        $this->delivery_callbacks[$notification_id] = &$delivery_status;
    }

    /**
     * Wait for delivery confirmation
     *
     * @param string $notification_id
     * @param int $timeout_seconds
     * @return array Delivery status
     */
    private function wait_for_delivery_confirmation($notification_id, $timeout_seconds = 5) {
        $start_time = time();
        
        while ((time() - $start_time) < $timeout_seconds) {
            if (isset($this->delivery_callbacks[$notification_id]) && 
                $this->delivery_callbacks[$notification_id]['success']) {
                return $this->delivery_callbacks[$notification_id];
            }
            usleep(100000); // 0.1 second
        }
        
        return $this->delivery_callbacks[$notification_id] ?? [
            'success' => false,
            'delivered_at' => null,
            'attempts' => 1,
            'error' => 'Delivery confirmation timeout'
        ];
    }

    /**
     * Confirm notification delivery (to be called by notification handlers)
     *
     * @param string $notification_id
     * @param bool $success
     * @param string|null $error
     */
    public function confirm_delivery($notification_id, $success = true, $error = null) {
        if (isset($this->delivery_callbacks[$notification_id])) {
            $this->delivery_callbacks[$notification_id]['success'] = $success;
            $this->delivery_callbacks[$notification_id]['delivered_at'] = current_time('mysql');
            if ($error) {
                $this->delivery_callbacks[$notification_id]['error'] = $error;
            }
        }
        
        // Store delivery confirmation in database for monitoring
        $this->store_delivery_confirmation($notification_id, $success, $error);
    }

    /**
     * Handle notification failure and schedule retry
     *
     * @param string $notification_id
     * @param string $type
     * @param array $data
     * @param string $error
     */
    private function handle_notification_failure($notification_id, $type, $data, $error) {
        $failure_record = [
            'notification_id' => $notification_id,
            'type' => $type,
            'data' => $data,
            'error' => $error,
            'attempts' => 1,
            'first_failed_at' => current_time('mysql'),
            'next_retry_at' => date('Y-m-d H:i:s', time() + 60) // Retry in 1 minute
        ];
        
        $this->failed_notifications[$notification_id] = $failure_record;
        $this->store_failed_notification($failure_record);
        
        error_log("Notification failure: {$notification_id} - {$error}");
    }

    /**
     * Retry failed notifications
     */
    public function retry_failed_notifications() {
        $failed_notifications = $this->get_failed_notifications_for_retry();
        
        foreach ($failed_notifications as $notification) {
            if ($notification['attempts'] >= $this->max_retry_attempts) {
                $this->mark_notification_as_permanently_failed($notification['notification_id']);
                continue;
            }
            
            try {
                $success = false;
                
                // Retry based on notification type
                switch ($notification['type']) {
                    case 'new_content':
                        $success = $this->notify_new_content($notification['data']);
                        break;
                    case 'stream_status_change':
                        // Extract parameters for stream status change
                        $stream = $notification['data'];
                        $success = $this->notify_stream_status_change(
                            $stream,
                            $stream['previous_status'] ?? 'unknown',
                            $stream['current_status'] ?? 'unknown'
                        );
                        break;
                }
                
                if ($success) {
                    $this->remove_failed_notification($notification['notification_id']);
                } else {
                    $this->increment_retry_attempt($notification['notification_id']);
                }
                
            } catch (\Exception $e) {
                $this->increment_retry_attempt($notification['notification_id'], $e->getMessage());
            }
        }
    }

    /**
     * Store delivery confirmation in database
     *
     * @param string $notification_id
     * @param bool $success
     * @param string|null $error
     */
    private function store_delivery_confirmation($notification_id, $success, $error = null) {
        $confirmations = get_option('social_feed_delivery_confirmations', []);
        $confirmations[$notification_id] = [
            'success' => $success,
            'confirmed_at' => current_time('mysql'),
            'error' => $error
        ];
        
        // Keep only last 1000 confirmations
        if (count($confirmations) > 1000) {
            $confirmations = array_slice($confirmations, -1000, null, true);
        }
        
        update_option('social_feed_delivery_confirmations', $confirmations);
    }

    /**
     * Store failed notification for retry
     *
     * @param array $failure_record
     */
    private function store_failed_notification($failure_record) {
        $failed_notifications = get_option('social_feed_failed_notifications', []);
        $failed_notifications[$failure_record['notification_id']] = $failure_record;
        update_option('social_feed_failed_notifications', $failed_notifications);
    }

    /**
     * Get failed notifications ready for retry
     *
     * @return array
     */
    private function get_failed_notifications_for_retry() {
        $failed_notifications = get_option('social_feed_failed_notifications', []);
        $current_time = current_time('mysql');
        
        return array_filter($failed_notifications, function($notification) use ($current_time) {
            return $notification['next_retry_at'] <= $current_time &&
                   $notification['attempts'] < $this->max_retry_attempts;
        });
    }

    /**
     * Remove failed notification after successful retry
     *
     * @param string $notification_id
     */
    private function remove_failed_notification($notification_id) {
        $failed_notifications = get_option('social_feed_failed_notifications', []);
        unset($failed_notifications[$notification_id]);
        update_option('social_feed_failed_notifications', $failed_notifications);
    }

    /**
     * Increment retry attempt for failed notification
     *
     * @param string $notification_id
     * @param string|null $error
     */
    private function increment_retry_attempt($notification_id, $error = null) {
        $failed_notifications = get_option('social_feed_failed_notifications', []);
        
        if (isset($failed_notifications[$notification_id])) {
            $failed_notifications[$notification_id]['attempts']++;
            $failed_notifications[$notification_id]['last_error'] = $error;
            
            // Exponential backoff for retry timing
            $backoff_minutes = pow(2, $failed_notifications[$notification_id]['attempts']) * 5; // 5, 10, 20, 40 minutes
            $failed_notifications[$notification_id]['next_retry_at'] = date('Y-m-d H:i:s', time() + ($backoff_minutes * 60));
            
            update_option('social_feed_failed_notifications', $failed_notifications);
        }
    }

    /**
     * Mark notification as permanently failed
     *
     * @param string $notification_id
     */
    private function mark_notification_as_permanently_failed($notification_id) {
        $failed_notifications = get_option('social_feed_failed_notifications', []);
        
        if (isset($failed_notifications[$notification_id])) {
            $failed_notifications[$notification_id]['permanently_failed'] = true;
            $failed_notifications[$notification_id]['permanently_failed_at'] = current_time('mysql');
            update_option('social_feed_failed_notifications', $failed_notifications);
        }
        
        error_log("Notification permanently failed after {$this->max_retry_attempts} attempts: {$notification_id}");
    }

    /**
     * Get delivery statistics
     *
     * @return array
     */
    public function get_delivery_statistics() {
        $confirmations = get_option('social_feed_delivery_confirmations', []);
        $failed_notifications = get_option('social_feed_failed_notifications', []);
        
        $total_sent = count($confirmations) + count($failed_notifications);
        $successful_deliveries = count(array_filter($confirmations, function($conf) {
            return $conf['success'];
        }));
        $failed_deliveries = count($failed_notifications);
        $permanently_failed = count(array_filter($failed_notifications, function($notif) {
            return isset($notif['permanently_failed']) && $notif['permanently_failed'];
        }));
        
        return [
            'total_sent' => $total_sent,
            'successful_deliveries' => $successful_deliveries,
            'failed_deliveries' => $failed_deliveries,
            'permanently_failed' => $permanently_failed,
            'success_rate' => $total_sent > 0 ? ($successful_deliveries / $total_sent) * 100 : 0,
            'pending_retries' => count($this->get_failed_notifications_for_retry())
        ];
    }

    /**
     * Log notification to history with delivery status
     *
     * @param string $type Notification type
     * @param array $data Notification data
     * @param array|null $delivery_status Delivery status information
     * @param string|null $notification_id Unique notification ID
     * @return void
     */
    private function log_notification($type, $data, $delivery_status = null, $notification_id = null) {
        $history = get_option('social_feed_notification_history', []);
        
        $log_entry = [
            'type' => $type,
            'data' => $data,
            'timestamp' => current_time('mysql')
        ];
        
        // Add delivery status and notification ID if provided
        if ($delivery_status !== null) {
            $log_entry['delivery_status'] = $delivery_status;
        }
        if ($notification_id !== null) {
            $log_entry['notification_id'] = $notification_id;
        }
        
        $history[] = $log_entry;
        
        // Keep only last 100 notifications
        if (count($history) > 100) {
            $history = array_slice($history, -100);
        }
        
        update_option('social_feed_notification_history', $history);
        
        $log_message = 'Social Feed Notification: ' . $type;
        if ($notification_id) {
            $log_message .= ' [ID: ' . $notification_id . ']';
        }
        if ($delivery_status && isset($delivery_status['success'])) {
            $log_message .= ' [Status: ' . ($delivery_status['success'] ? 'SUCCESS' : 'FAILED') . ']';
        }
        $log_message .= ' - ' . json_encode($data);
        
        error_log($log_message);
    }

    /**
     * Get notification history
     *
     * @param string|null $type Filter by notification type
     * @param int $limit Maximum number of notifications to return
     * @return array
     */
    public function get_history($type = null, $limit = 100) {
        $history = get_option('social_feed_notification_history', []);
        
        if ($type) {
            $history = array_filter($history, function($entry) use ($type) {
                return $entry['type'] === $type;
            });
        }
        
        return array_slice($history, -$limit);
    }
}