<?php
/**
 * Database Schema Manager for Intelligent Scheduling
 * 
 * @package SocialFeed\Core
 */

namespace SocialFeed\Core;

class DatabaseSchema {
    
    /**
     * Create all intelligent scheduling tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create schedules table
        $schedules_table = $wpdb->prefix . 'social_feed_schedules';
        $schedules_sql = "CREATE TABLE $schedules_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            channel_id varchar(255) NOT NULL,
            schedule_type enum('daily','weekly','custom') NOT NULL,
            timezone varchar(100) NOT NULL DEFAULT 'UTC',
            priority int(1) NOT NULL DEFAULT 3 CHECK (priority BETWEEN 1 AND 5),
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_channel_active (channel_id, active),
            KEY idx_schedule_type (schedule_type)
        ) $charset_collate;";
        
        // Create schedule slots table
        $slots_table = $wpdb->prefix . 'social_feed_schedule_slots';
        $slots_sql = "CREATE TABLE $slots_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            schedule_id int(11) NOT NULL,
            day_of_week enum('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
            check_time time NOT NULL,
            priority_modifier int(2) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_schedule_day_time (schedule_id, day_of_week, check_time),
            FOREIGN KEY (schedule_id) REFERENCES $schedules_table(id) ON DELETE CASCADE
        ) $charset_collate;";
        
        // Create quota usage tracking table
        $quota_table = $wpdb->prefix . 'social_feed_quota_usage';
        $quota_sql = "CREATE TABLE $quota_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            schedule_id int(11) DEFAULT NULL,
            usage_date date NOT NULL,
            api_calls_used int(11) NOT NULL DEFAULT 0,
            videos_found int(11) NOT NULL DEFAULT 0,
            efficiency_score decimal(5,2) NOT NULL DEFAULT 0.00,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_schedule_date (schedule_id, usage_date),
            KEY idx_usage_date (usage_date),
            FOREIGN KEY (schedule_id) REFERENCES $schedules_table(id) ON DELETE SET NULL
        ) $charset_collate;";
        
        // Create analytics table
        $analytics_table = $wpdb->prefix . 'social_feed_analytics';
        $analytics_sql = "CREATE TABLE $analytics_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            schedule_id int(11) DEFAULT NULL,
            check_time timestamp NOT NULL,
            content_found tinyint(1) NOT NULL DEFAULT 0,
            api_calls_made int(11) NOT NULL DEFAULT 1,
            result_type enum('scheduled','fallback','emergency') NOT NULL,
            response_time_ms int(11) DEFAULT NULL,
            created_at timestamp DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_check_time (check_time),
            KEY idx_result_type (result_type),
            FOREIGN KEY (schedule_id) REFERENCES $schedules_table(id) ON DELETE SET NULL
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($schedules_sql);
        dbDelta($slots_sql);
        dbDelta($quota_sql);
        dbDelta($analytics_sql);
        
        // Insert default schedule templates
        self::insert_default_templates();
    }
    
    /**
     * Insert default schedule templates
     */
    private static function insert_default_templates() {
        global $wpdb;
        
        $schedules_table = $wpdb->prefix . 'social_feed_schedules';
        $slots_table = $wpdb->prefix . 'social_feed_schedule_slots';
        
        // Check if templates already exist
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $schedules_table WHERE channel_id LIKE %s",
            'template_%'
        ));
        
        if ($existing > 0) {
            return; // Templates already exist
        }
        
        // Insert daily template
        $wpdb->insert($schedules_table, [
            'channel_id' => 'template_daily',
            'schedule_type' => 'daily',
            'timezone' => 'UTC',
            'priority' => 3
        ]);
        $daily_template_id = $wpdb->insert_id;
        
        // Insert weekly template
        $wpdb->insert($schedules_table, [
            'channel_id' => 'template_weekly',
            'schedule_type' => 'weekly',
            'timezone' => 'UTC',
            'priority' => 3
        ]);
        $weekly_template_id = $wpdb->insert_id;
        
        // Insert daily template slots
        $daily_slots = [
            ['day_of_week' => 'monday', 'check_time' => '09:00:00'],
            ['day_of_week' => 'tuesday', 'check_time' => '09:00:00'],
            ['day_of_week' => 'wednesday', 'check_time' => '09:00:00'],
            ['day_of_week' => 'thursday', 'check_time' => '09:00:00'],
            ['day_of_week' => 'friday', 'check_time' => '09:00:00']
        ];
        
        foreach ($daily_slots as $slot) {
            $wpdb->insert($slots_table, array_merge($slot, [
                'schedule_id' => $daily_template_id
            ]));
        }
        
        // Insert weekly template slots
        $weekly_slots = [
            ['day_of_week' => 'sunday', 'check_time' => '10:00:00'],
            ['day_of_week' => 'wednesday', 'check_time' => '19:00:00']
        ];
        
        foreach ($weekly_slots as $slot) {
            $wpdb->insert($slots_table, array_merge($slot, [
                'schedule_id' => $weekly_template_id
            ]));
        }
    }
    
    /**
     * Drop all intelligent scheduling tables
     */
    public static function drop_tables() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'social_feed_analytics',
            $wpdb->prefix . 'social_feed_quota_usage',
            $wpdb->prefix . 'social_feed_schedule_slots',
            $wpdb->prefix . 'social_feed_schedules'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
    
    /**
     * Check if all tables exist
     */
    public static function tables_exist() {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'social_feed_schedules',
            $wpdb->prefix . 'social_feed_schedule_slots',
            $wpdb->prefix . 'social_feed_quota_usage',
            $wpdb->prefix . 'social_feed_analytics'
        ];
        
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                return false;
            }
        }
        
        return true;
    }
}