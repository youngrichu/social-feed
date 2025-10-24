<?php
namespace SocialFeed\Core;

class Cache {
    /**
     * Initialize cache system
     */
    public function init() {
        // Schedule cache cleanup
        if (!wp_next_scheduled('social_feed_cache_cleanup')) {
            wp_schedule_event(time(), 'daily', 'social_feed_cache_cleanup');
        }
        add_action('social_feed_cache_cleanup', [$this, 'cleanup_expired_cache']);
    }

    /**
     * Get cached item
     *
     * @param string $platform Platform identifier
     * @param string $content_type Content type
     * @param string $content_id Content ID
     * @return mixed|false Cached data or false if not found/expired
     */
    public function get($platform, $content_type, $content_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_cache';
        
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT content FROM $table 
                WHERE platform = %s 
                AND content_type = %s 
                AND content_id = %s 
                AND expires_at > NOW()",
                $platform,
                $content_type,
                $content_id
            )
        );

        return $result ? json_decode($result->content, true) : false;
    }

    /**
     * Set cache item
     *
     * @param string $platform Platform identifier
     * @param string $content_type Content type
     * @param string $content_id Content ID
     * @param mixed $content Content to cache
     * @param int $expiration Expiration time in seconds (default 1 hour)
     * @return bool Success status
     */
    public function set($platform, $content_type, $content_id, $content, $expiration = 3600) {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_cache';
        
        // Delete existing cache for this content
        $this->delete($platform, $content_type, $content_id);
        
        // Insert new cache
        $result = $wpdb->insert(
            $table,
            [
                'platform' => $platform,
                'content_type' => $content_type,
                'content_id' => $content_id,
                'content' => json_encode($content),
                'created_at' => current_time('mysql'),
                'expires_at' => date('Y-m-d H:i:s', time() + $expiration)
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Delete cached item
     *
     * @param string $platform Platform identifier
     * @param string $content_type Content type
     * @param string $content_id Content ID
     * @return bool Success status
     */
    public function delete($platform, $content_type, $content_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_cache';
        
        $result = $wpdb->delete(
            $table,
            [
                'platform' => $platform,
                'content_type' => $content_type,
                'content_id' => $content_id
            ],
            ['%s', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Clean up expired cache entries
     */
    public function cleanup_expired_cache() {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_cache';
        
        // Only clean up entries that have actually expired
        $wpdb->query(
            "DELETE FROM $table 
            WHERE expires_at < NOW() 
            AND (
                -- For videos, only delete if they're recent or live
                (content_type = 'video' AND 
                 JSON_EXTRACT(content, '$.duration') = 'P0D' OR 
                 created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR))
                
                -- For other content types, delete as normal
                OR content_type != 'video'
            )"
        );

        // Log cleanup results
        $affected = $wpdb->rows_affected;
        error_log("Social Feed: Cache cleanup completed. Removed $affected expired entries.");
    }

    /**
     * Clear all cache for a specific platform
     *
     * @param string $platform Platform identifier
     * @return bool Success status
     */
    public function clear_platform_cache($platform) {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_cache';
        
        $result = $wpdb->delete(
            $table,
            ['platform' => $platform],
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Clear all cache
     *
     * @return bool Success status
     */
    public function clear_all_cache() {
        global $wpdb;
        $table = $wpdb->prefix . 'social_feed_cache';
        
        $result = $wpdb->query("TRUNCATE TABLE $table");

        return $result !== false;
    }
} 