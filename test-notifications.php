<?php
/**
 * Test script for YouTube notification system
 * Run this script to test the notification improvements
 */

// Include WordPress
require_once dirname(__FILE__) . '/../../../wp-config.php';

// Test the notification system
function test_youtube_notifications() {
    echo "Testing YouTube Notification System\n";
    echo "==================================\n\n";
    
    // Check if the plugin is active
    if (!class_exists('SocialFeed\Core\Notifications')) {
        echo "❌ Social Feed plugin not found or not active\n";
        return;
    }
    
    // Initialize notification handler
    $notifications = new SocialFeed\Core\Notifications();
    
    // Check current notification stats
    $stats = get_option('social_feed_notification_stats', []);
    if (!empty($stats)) {
        echo "📊 Recent Notification Stats:\n";
        $recent_stats = array_slice($stats, -5, null, true);
        foreach ($recent_stats as $time => $stat) {
            echo "  {$time}: Found {$stat['videos_found']} videos, sent {$stat['notifications_sent']}, failed {$stat['notifications_failed']}\n";
        }
        echo "\n";
    }
    
    // Check last notification check time
    $last_check = get_option('social_feed_last_notification_check', 'Never');
    echo "🕒 Last notification check: {$last_check}\n";
    
    // Check for any retry counts
    $retry_count = get_transient('social_feed_notification_retry_count');
    if ($retry_count) {
        echo "🔄 Current retry count: {$retry_count}\n";
    }
    
    // Check YouTube quota status
    $quota_exceeded = get_transient('youtube_quota_exceeded_' . date('Y-m-d'));
    if ($quota_exceeded) {
        echo "⚠️  YouTube quota exceeded for today\n";
    } else {
        echo "✅ YouTube quota available\n";
    }
    
    // Check rate limiting
    $rate_limited = get_transient('social_feed_rate_youtube');
    if ($rate_limited) {
        echo "⚠️  YouTube API rate limited\n";
    } else {
        echo "✅ YouTube API not rate limited\n";
    }
    
    echo "\n";
    
    // Test notification check (dry run)
    echo "🧪 Running notification check test...\n";
    try {
        // Get recent videos from cache
        global $wpdb;
        $table_name = $wpdb->prefix . 'social_feed_cache';
        
        $recent_videos = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$table_name} 
            WHERE platform = 'youtube' 
            AND type != 'live_stream'
            ORDER BY created_at DESC
            LIMIT 5
        "), ARRAY_A);
        
        if (!empty($recent_videos)) {
            echo "📹 Found " . count($recent_videos) . " recent YouTube videos in cache:\n";
            foreach ($recent_videos as $video) {
                $notification_key = 'social_feed_notification_sent_' . md5($video['external_id']);
                $already_sent = get_transient($notification_key) ? '✅ Sent' : '❌ Not sent';
                echo "  - {$video['title']} ({$video['created_at']}) - {$already_sent}\n";
            }
        } else {
            echo "❌ No YouTube videos found in cache\n";
        }
        
        echo "\n✅ Test completed successfully\n";
        
    } catch (Exception $e) {
        echo "❌ Test failed: " . $e->getMessage() . "\n";
    }
    
    // Recommendations
    echo "\n💡 Recommendations:\n";
    echo "  1. Check WordPress cron is working: wp cron event list\n";
    echo "  2. Monitor notification stats in wp_options table\n";
    echo "  3. Check error logs for detailed debugging info\n";
    echo "  4. Verify YouTube API credentials are valid\n";
}

// Run the test
test_youtube_notifications();