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
        
        try {
            $table = $wpdb->prefix . 'social_feed_cache';
            
            // Validate inputs
            if (empty($platform) || empty($content_type) || empty($content_id)) {
                return false;
            }
            
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

            if ($result && !empty($result->content)) {
                $decoded = json_decode($result->content, true);
                return $decoded !== null ? $decoded : false;
            }
            
            return false;
            
        } catch (\Exception $e) {
            error_log('Cache get error: ' . $e->getMessage());
            return false;
        }
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
        
        try {
            $table = $wpdb->prefix . 'social_feed_cache';
            
            // Validate inputs
            if (empty($platform) || empty($content_type) || empty($content_id) || $content === null) {
                return false;
            }
            
            // Validate expiration
            $expiration = max(60, min($expiration, 86400 * 7)); // Between 1 minute and 7 days
            
            // Delete existing cache for this content
            $this->delete($platform, $content_type, $content_id);
            
            // Encode content with error handling
            $encoded_content = json_encode($content);
            if ($encoded_content === false) {
                error_log('Cache set error: Failed to encode content for ' . $platform . '/' . $content_type . '/' . $content_id);
                return false;
            }
            
            // Insert new cache
            $result = $wpdb->insert(
                $table,
                [
                    'platform' => $platform,
                    'content_type' => $content_type,
                    'content_id' => $content_id,
                    'content' => $encoded_content,
                    'created_at' => current_time('mysql'),
                    'expires_at' => date('Y-m-d H:i:s', time() + $expiration)
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s']
            );

            return $result !== false;
            
        } catch (\Exception $e) {
            error_log('Cache set error: ' . $e->getMessage());
            return false;
        }
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
        
        try {
            $table = $wpdb->prefix . 'social_feed_cache';
            
            // Validate inputs
            if (empty($platform) || empty($content_type) || empty($content_id)) {
                return false;
            }
            
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
            
        } catch (\Exception $e) {
            error_log('Cache delete error: ' . $e->getMessage());
            return false;
        }
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