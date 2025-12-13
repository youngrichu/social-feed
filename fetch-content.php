<?php
// Load WordPress
require_once('wp-load.php');

// Initialize YouTube platform
try {
    $youtube = new SocialFeed\Platforms\YouTube();
    $youtube->init();
} catch (Exception $e) {
    error_log('Error initializing YouTube platform: ' . $e->getMessage());
    echo "Error: Failed to initialize YouTube platform - " . $e->getMessage() . "\n";
    exit(1);
}

// Fetch content
try {
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
    $max_pages = ($cached_count == 0) ? 20 : 5;

    // Get items
    $items = $youtube->get_feed(['video', 'short', 'live'], $max_pages);

    if ($cached_count == 0) {
        // For initial fetch, clear cache and insert all items
        $wpdb->query("TRUNCATE TABLE $cache_table");
    }

    // Insert or update items
    $inserted = 0;
    $updated = 0;
    $skipped = 0;
    foreach ($items as $item) {
        try {
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
                } else {
                    error_log("Failed to insert item {$item['id']}: " . $wpdb->last_error);
                    $skipped++;
                }
            }
        } catch (Exception $e) {
            error_log("Error processing item {$item['id']}: " . $e->getMessage());
            $skipped++;
        }
    }

    echo "Success! Processed YouTube items - Inserted: $inserted, Updated: $updated, Skipped: $skipped\n";
} catch (Exception $e) {
    error_log('Error fetching YouTube content: ' . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}