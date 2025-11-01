<?php
namespace SocialFeed\Core;

class Deactivator {
    /**
     * Deactivate the plugin
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('social_feed_check_notifications');
        wp_clear_scheduled_hook('social_feed_cleanup_cache');

        // Log deactivation
        error_log('Social Feed plugin deactivated');
    }
} 