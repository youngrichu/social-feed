<?php
// Load WordPress
require_once('wp-load.php');

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Initialize YouTube platform
try {
    error_log('Starting YouTube platform initialization...');
    $youtube = new SocialFeed\Platforms\YouTube();
    $youtube->init();
    error_log('YouTube platform initialized successfully');
} catch (Exception $e) {
    error_log('Error initializing YouTube platform: ' . $e->getMessage());
    echo "Error: Failed to initialize YouTube platform - " . $e->getMessage() . "\n";
    exit(1);
}

// Fetch content
try {
    error_log('Starting YouTube content fetch...');
    
    // Check if this is initial fetch
    global $wpdb;
    $cache_table = $wpdb->prefix . 'social_feed_cache';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$cache_table'") === $cache_table;
    if (!$table_exists) {
        error_log("Error: Cache table '$cache_table' does not exist!");
        echo "Error: Cache table does not exist. Please check plugin installation.\n";
        exit(1);
    }

    $cached_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $cache_table WHERE platform = %s",
        'youtube'
    ));

    // For initial fetch, use higher max_pages to get historical content
    $max_pages = ($cached_count == 0) ? 20 : 5; // 20 pages = 1000 videos for initial, 5 pages = 250 for updates
    error_log("YouTube: " . ($cached_count == 0 ? "Initial fetch" : "Update fetch") . " with max_pages: $max_pages");

    // Get platform configuration
    $options = get_option('social_feed_platforms', []);
    $youtube_config = $options['youtube'] ?? [];
    error_log('YouTube configuration: ' . json_encode([
        'enabled' => !empty($youtube_config['enabled']),
        'has_api_key' => !empty($youtube_config['api_key']),
        'has_channel_id' => !empty($youtube_config['channel_id']),
        'max_pages' => $max_pages
    ]));

    // Check quota before fetching
    $quota_key = 'youtube_quota_usage_' . date('Y-m-d');
    $current_quota = get_transient($quota_key) ?: 0;
    error_log("Current YouTube API quota usage: $current_quota units");

    // Get items with type logging
    error_log('Fetching YouTube content with types: video, short, live');
    $items = $youtube->get_feed(['video', 'short', 'live'], $max_pages);
    error_log('Fetched ' . count($items) . ' items from YouTube');
    
    // Log item types distribution
    $type_counts = array_count_values(array_column($items, 'type'));
    error_log('Item type distribution: ' . json_encode($type_counts));

    if ($cached_count == 0) {
        // For initial fetch, clear cache and insert all items
        $wpdb->query("TRUNCATE TABLE $cache_table");
        error_log('Cleared cache table for initial fetch');
    } else {
        error_log("Found $cached_count existing items in cache");
        
        // Log existing items distribution
        $existing_types = $wpdb->get_results("
            SELECT content_type, COUNT(*) as count 
            FROM $cache_table 
            WHERE platform = 'youtube' 
            GROUP BY content_type
        ");
        error_log('Existing items distribution: ' . json_encode($existing_types));
    }
    
    // Insert or update items
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    foreach ($items as $item) {
        try {
            error_log("Processing item: ID={$item['id']}, Type={$item['type']}, Title={$item['title']}");
            
            // Check if item exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $cache_table WHERE platform = %s AND content_id = %s",
                'youtube',
                $item['id']
            ));

            // Prepare content data
            $content_data = [
                'title' => $item['title'] ?? '',
                'description' => $item['description'] ?? '',
                'media_url' => $item['media_url'] ?? '',
                'thumbnail_url' => $item['thumbnail_url'] ?? '',
                'original_url' => $item['original_url'] ?? '',
                'created_at' => $item['created_at'] ?? current_time('mysql'),
                'engagement' => [
                    'likes' => intval($item['likes'] ?? 0),
                    'comments' => intval($item['comments'] ?? 0),
                    'shares' => intval($item['shares'] ?? 0)
                ],
                'author' => [
                    'name' => $item['author_name'] ?? '',
                    'avatar' => $item['author_avatar'] ?? '',
                    'profile' => $item['author_profile'] ?? ''
                ],
                'duration' => $item['duration'] ?? null
            ];

            if ($exists) {
                // Update existing item
                $result = $wpdb->update(
                    $cache_table,
                    [
                        'content' => json_encode($content_data),
                        'updated_at' => current_time('mysql')
                    ],
                    [
                        'platform' => 'youtube',
                        'content_id' => $item['id']
                    ],
                    ['%s', '%s'],
                    ['%s', '%s']
                );
                if ($result !== false) {
                    $updated++;
                    error_log("Updated item {$item['id']} ({$item['type']})");
                } else {
                    error_log("Failed to update item {$item['id']}: " . $wpdb->last_error);
                    $skipped++;
                }
            } else {
                // Insert new item
                $result = $wpdb->insert(
                    $cache_table,
                    [
                        'platform' => 'youtube',
                        'content_type' => $item['type'] ?? 'video',
                        'content_id' => $item['id'],
                        'content' => json_encode($content_data),
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    ['%s', '%s', '%s', '%s', '%s', '%s']
                );
                if ($result !== false) {
                    $inserted++;
                    error_log("Inserted new item {$item['id']} ({$item['type']})");
                } else {
                    error_log("Failed to insert item {$item['id']}: " . $wpdb->last_error);
                    $skipped++;
                }
            }
        } catch (Exception $e) {
            error_log("Error processing item {$item['id']}: " . $e->getMessage());
            error_log("Item data: " . json_encode($item));
            $skipped++;
        }
    }
    
    // Check final quota usage
    $final_quota = get_transient($quota_key) ?: 0;
    $quota_used = $final_quota - $current_quota;
    error_log("YouTube API quota used in this run: $quota_used units");
    error_log("Final YouTube API quota usage: $final_quota units");
    
    error_log("Successfully processed items - Inserted: $inserted, Updated: $updated, Skipped: $skipped");
    echo "Success! Processed YouTube items - Inserted: $inserted, Updated: $updated, Skipped: $skipped\n";
} catch (Exception $e) {
    error_log('Error fetching YouTube content: ' . $e->getMessage());
    error_log('Error trace: ' . $e->getTraceAsString());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
} 