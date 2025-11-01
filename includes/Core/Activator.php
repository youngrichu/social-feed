<?php
namespace SocialFeed\Core;

class Activator {
    /**
     * Activate the plugin
     */
    public function activate() {
        // Create database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Log activation
        error_log('Social Feed plugin activated successfully');
    }

    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = [
            'social_feed_version' => SOCIAL_FEED_VERSION,
            'cache_duration' => 3600, // 1 hour
            'items_per_page' => 12,
            'default_layout' => 'grid',
            'platforms' => [
                'youtube' => [
                    'enabled' => false,
                    'api_key' => '',
                    'channel_id' => '',
                ],
                'tiktok' => [
                    'enabled' => false,
                    'api_key' => '',
                    'access_token' => '',
                ],
                'facebook' => [
                    'enabled' => false,
                    'app_id' => '',
                    'app_secret' => '',
                    'access_token' => '',
                ],
                'instagram' => [
                    'enabled' => false,
                    'app_id' => '',
                    'app_secret' => '',
                    'access_token' => '',
                ],
            ],
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                update_option($key, $value);
            }
        }
    }

    private function create_tables() {
        global $wpdb;
        
        // Include WordPress upgrade functions
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        
        $charset_collate = $wpdb->get_charset_collate();

        // Social feed cache table
        $cache_table = $wpdb->prefix . 'social_feed_cache';
        $sql_cache = "CREATE TABLE $cache_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            platform varchar(50) NOT NULL,
            content_type varchar(50) NOT NULL,
            content_id varchar(255) NOT NULL,
            content longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY platform_type_date (platform, content_type, created_at),
            KEY content_lookup (content_id, platform),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        dbDelta($sql_cache);

        // Social feed streams table
        $streams_table = $wpdb->prefix . 'social_feed_streams';
        $sql_streams = "CREATE TABLE $streams_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            platform varchar(50) NOT NULL,
            stream_id varchar(255) NOT NULL,
            title text NOT NULL,
            description longtext,
            thumbnail_url text,
            stream_url text NOT NULL,
            status varchar(50) NOT NULL,
            viewer_count int DEFAULT 0,
            started_at datetime,
            scheduled_for datetime,
            channel_name varchar(255),
            channel_avatar text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY platform (platform),
            KEY stream_id (stream_id),
            KEY status (status),
            UNIQUE KEY unique_stream (platform, stream_id)
        ) $charset_collate;";
        
        dbDelta($sql_streams);

        // App notifications table (for push notifications integration)
        $notifications_table = $wpdb->prefix . 'app_notifications';
        $sql_notifications = "CREATE TABLE $notifications_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            title varchar(255) NOT NULL,
            body text NOT NULL,
            type varchar(50) NOT NULL,
            is_read tinyint(1) DEFAULT 0,
            reference_id varchar(255) DEFAULT NULL,
            reference_type varchar(50) NOT NULL,
            reference_url text,
            image_url text,
            details longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY reference_id (reference_id),
            KEY type (type)
        ) $charset_collate;";
        
        dbDelta($sql_notifications);

        // Push tokens table (for push notifications integration)
        $push_tokens_table = $wpdb->prefix . 'app_push_tokens';
        $sql_tokens = "CREATE TABLE $push_tokens_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            token varchar(255) NOT NULL,
            platform varchar(50) DEFAULT 'expo',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        dbDelta($sql_tokens);

        // Log successful table creation
        error_log('Social Feed: Database tables created successfully');
    }
}