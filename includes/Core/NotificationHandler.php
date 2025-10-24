<?php
namespace SocialFeed\Core;

class NotificationHandler {
    /**
     * Send notification for new content
     *
     * @param array $content Formatted content data
     * @return void
     */
    public function notify_new_content($content) {
        do_action('social_feed_new_content', [
            'platform' => 'youtube',
            'type' => $content['type'],
            'content' => $content
        ]);

        $this->log_notification('new_content', $content);
    }

    /**
     * Send notification for stream status change
     *
     * @param array $stream Stream data
     * @param string $previous_status Previous stream status
     * @param string $current_status Current stream status
     * @return void
     */
    public function notify_stream_status_change($stream, $previous_status, $current_status) {
        do_action('social_feed_stream_status_change', [
            'platform' => 'youtube',
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
        ]);

        $this->log_notification('stream_status_change', [
            'stream_id' => $stream['id'],
            'previous_status' => $previous_status,
            'current_status' => $current_status,
            'url' => $stream['stream_url']
        ]);
    }

    /**
     * Log notification for monitoring
     *
     * @param string $type Notification type
     * @param array $data Notification data
     * @return void
     */
    private function log_notification($type, $data) {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'type' => $type,
            'data' => $data
        ];

        // Keep notification history for monitoring
        $history = get_option('social_feed_notification_history', []);
        $history = array_filter($history, function($entry) {
            return strtotime($entry['timestamp']) > strtotime('-7 days');
        });
        
        $history[] = $log_entry;
        update_option('social_feed_notification_history', array_slice($history, -1000));

        error_log("Social Feed Notification: $type - " . json_encode($data));
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