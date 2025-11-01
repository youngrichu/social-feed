<?php foreach ($items as $item): ?>
    <div class="feed-item">
        <div class="feed-item-header">
            <div class="author-info">
                <span class="platform-badge">
                    <?php echo esc_html($item['platform']); ?>
                </span>
                <span class="post-date">
                    <?php echo esc_html($item['created_at']); ?>
                </span>
            </div>
        </div>
        <div class="feed-item-media">
            // ... existing code ...
        </div>
    </div>
<?php endforeach; ?> 