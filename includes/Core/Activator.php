<?php
namespace SocialFeed\Core;

class Activator {
    /**
     * Activate the plugin
     */
    public function activate() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();

        // Create cache table
        $cache_table = $wpdb->prefix . 'social_feed_cache';
        $sql_cache = "CREATE TABLE IF NOT EXISTS $cache_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            platform varchar(50) NOT NULL,
            content_type varchar(50) NOT NULL,
            content_id varchar(255) NOT NULL,
            content longtext NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY platform_type_date (platform, content_type, created_at),
            KEY content_lookup (content_id, platform),
            KEY created_at (created_at),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        dbDelta($sql_cache);

        // Create streams table
        $streams_table = $wpdb->prefix . 'social_feed_streams';
        $sql_streams = "CREATE TABLE IF NOT EXISTS $streams_table (
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
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY platform (platform),
            KEY stream_id (stream_id),
            KEY status (status)
        ) $charset_collate;";
        dbDelta($sql_streams);

        // Log the results
        error_log('Social Feed tables created:');
        error_log('Cache table: ' . $cache_table);
        error_log('Streams table: ' . $streams_table);
    }

    /**
     * Set default plugin options
     */
    private static function set_default_options() {
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
        $charset_collate = $wpdb->get_charset_collate();

        // Push tokens table
        $push_tokens_table = $wpdb->prefix . 'app_push_tokens';
        $sql = "CREATE TABLE IF NOT EXISTS $push_tokens_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            token varchar(255) NOT NULL,
            platform varchar(50) DEFAULT 'expo',
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token (token)
        ) $charset_collate;";

        // Social feed streams table
        $streams_table = $wpdb->prefix . 'social_feed_streams';
        $sql .= "CREATE TABLE IF NOT EXISTS $streams_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            platform varchar(50) NOT NULL,
            stream_id varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            details longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY stream_id (platform, stream_id)
        ) $charset_collate;";

        // Social feed cache table
        $cache_table = $wpdb->prefix . 'social_feed_cache';
        $sql .= "CREATE TABLE IF NOT EXISTS $cache_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            platform varchar(50) NOT NULL,
            content_type varchar(50) NOT NULL,
            content_id varchar(255) NOT NULL,
            content longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY content_id (platform, content_id)
        ) $charset_collate;";

        // App notifications table
        $notifications_table = $wpdb->prefix . 'app_notifications';
        $sql .= "CREATE TABLE IF NOT EXISTS $notifications_table (
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
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY reference_id (reference_id),
            KEY type (type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Add details column if it doesn't exist
        $check_column = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'details'",
            DB_NAME,
            $notifications_table
        ));

        if (empty($check_column)) {
            $wpdb->query("ALTER TABLE $notifications_table ADD COLUMN details longtext DEFAULT NULL AFTER image_url");
            error_log('Social Feed: Added details column to notifications table');
        }
    }
} 