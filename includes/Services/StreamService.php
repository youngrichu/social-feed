<?php
namespace SocialFeed\Services;

use SocialFeed\Core\Cache;
use SocialFeed\Platforms\PlatformFactory;

class StreamService {
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

        // Schedule stream status updates
        if (!wp_next_scheduled('social_feed_refresh_streams')) {
            wp_schedule_event(time(), 'every_minute', 'social_feed_refresh_streams');
        }
        add_action('social_feed_refresh_streams', [$this, 'refresh_stream_statuses']);
    }

    /**
     * Get streams from multiple platforms
     *
     * @param array $platforms Platforms to fetch from
     * @param string|null $status Stream status filter
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array
     */
    public function get_streams($platforms = [], $status = null, $page = 1, $per_page = 12) {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_streams';

        // Build query
        $query = "SELECT * FROM $table WHERE 1=1";
        $query_args = [];

        if (!empty($platforms)) {
            $placeholders = implode(',', array_fill(0, count($platforms), '%s'));
            $query .= " AND platform IN ($placeholders)";
            $query_args = array_merge($query_args, $platforms);
        }

        if ($status) {
            $query .= " AND status = %s";
            $query_args[] = $status;
        }

        // Add sorting
        $query .= " ORDER BY CASE 
            WHEN status = 'live' THEN 1 
            WHEN status = 'upcoming' THEN 2 
            ELSE 3 
        END, started_at DESC, scheduled_for ASC";

        // Get total count
        $total_query = "SELECT COUNT(*) FROM ($query) as t";
        $total_items = $wpdb->get_var($wpdb->prepare($total_query, $query_args));

        // Add pagination
        $offset = ($page - 1) * $per_page;
        $query .= " LIMIT %d OFFSET %d";
        $query_args[] = $per_page;
        $query_args[] = $offset;

        // Execute query
        $streams = $wpdb->get_results(
            $wpdb->prepare($query, $query_args),
            ARRAY_A
        );

        return [
            'streams' => array_map([$this, 'format_stream'], $streams),
            'pagination' => [
                'current_page' => $page,
                'total_pages' => ceil($total_items / $per_page),
                'total_items' => $total_items,
            ],
        ];
    }

    /**
     * Refresh stream statuses
     */
    public function refresh_stream_statuses() {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_streams';

        // Get active streams (live or upcoming)
        $streams = $wpdb->get_results(
            "SELECT * FROM $table WHERE status IN ('live', 'upcoming')",
            ARRAY_A
        );

        foreach ($streams as $stream) {
            $platform_handler = $this->platform_factory->get_platform($stream['platform']);
            if (!$platform_handler) {
                continue;
            }

            try {
                $updated_stream = $platform_handler->get_stream_status($stream['stream_id']);
                
                if ($updated_stream) {
                    $wpdb->update(
                        $table,
                        [
                            'status' => $updated_stream['status'],
                            'viewer_count' => $updated_stream['viewer_count'],
                            'updated_at' => current_time('mysql'),
                        ],
                        ['id' => $stream['id']],
                        ['%s', '%d', '%s'],
                        ['%d']
                    );
                }
            } catch (\Exception $e) {
                error_log("Error updating stream status: " . $e->getMessage());
            }
        }
    }

    /**
     * Format stream data for API response
     *
     * @param array $stream
     * @return array
     */
    private function format_stream($stream) {
        return [
            'id' => $stream['stream_id'],
            'platform' => $stream['platform'],
            'title' => $stream['title'],
            'description' => $stream['description'],
            'thumbnail_url' => $stream['thumbnail_url'],
            'stream_url' => $stream['stream_url'],
            'status' => $stream['status'],
            'viewer_count' => (int) $stream['viewer_count'],
            'started_at' => $stream['started_at'],
            'scheduled_for' => $stream['scheduled_for'],
            'channel' => [
                'name' => $stream['channel_name'],
                'avatar' => $stream['channel_avatar'],
            ],
        ];
    }
} 