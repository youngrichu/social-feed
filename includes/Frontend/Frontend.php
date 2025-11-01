<?php
namespace SocialFeed\Frontend;

use SocialFeed\Services\FeedService;
use SocialFeed\Services\StreamService;

class Frontend {
    /**
     * Initialize frontend functionality
     */
    public function init() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_shortcode('social_feed', [$this, 'render_feed_shortcode']);
        add_shortcode('social_streams', [$this, 'render_streams_shortcode']);
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        wp_enqueue_style(
            'social-feed',
            SOCIAL_FEED_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            SOCIAL_FEED_VERSION
        );

        wp_enqueue_script(
            'social-feed',
            SOCIAL_FEED_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            SOCIAL_FEED_VERSION,
            true
        );

        wp_localize_script('social-feed', 'socialFeed', [
            'ajaxUrl' => rest_url('social-feed/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Render social feed shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function render_feed_shortcode($atts) {
        $atts = shortcode_atts([
            'platforms' => '',
            'types' => '',
            'layout' => 'carousel',
            'per_page' => 12,
            'sort' => 'date',
            'order' => 'desc',
            'show_filters' => 'false',
            'autoplay' => 'true',
            'slides_to_show' => '3',
        ], $atts);

        // Parse platforms and types
        $platforms = array_filter(array_map('trim', explode(',', $atts['platforms'])));
        $types = array_filter(array_map('trim', explode(',', $atts['types'])));
        
        // Default to videos if no types specified (for all layouts)
        if (empty($types)) {
            $types = ['video', 'short'];
        }
        
        // Get enabled platforms if none specified (for all layouts)
        if (empty($platforms)) {
            $enabled_platforms = $this->get_enabled_platforms_for_carousel();
            if (!empty($enabled_platforms)) {
                $platforms = $enabled_platforms;
            }
        }

        // Get feed items
        $feed_service = new FeedService();
        $result = $feed_service->get_feeds($platforms, $types, 1, $atts['per_page'], $atts['sort'], $atts['order']);

        // Handle FeedService response structure and errors
        $items = [];
        $pagination = [
            'current_page' => 1,
            'total_pages' => 1,
            'per_page' => $atts['per_page'],
            'total_items' => 0
        ];

        if (is_array($result)) {
            if (isset($result['status']) && $result['status'] === 'success' && isset($result['data'])) {
                // Success response - extract data
                if (isset($result['data']['items']) && is_array($result['data']['items'])) {
                    $items = $result['data']['items'];
                }
                if (isset($result['data']['pagination']) && is_array($result['data']['pagination'])) {
                    $pagination = $result['data']['pagination'];
                }
            } elseif (isset($result['status']) && $result['status'] === 'error') {
                // Error response - log the error but continue with empty items
                error_log('Frontend: FeedService returned error: ' . ($result['message'] ?? 'Unknown error'));
                if (isset($result['data']['items']) && is_array($result['data']['items'])) {
                    $items = $result['data']['items'];
                }
                if (isset($result['data']['pagination']) && is_array($result['data']['pagination'])) {
                    $pagination = $result['data']['pagination'];
                }
            }
        }

        // Create result array with proper structure for template
        $result = [
            'items' => $items,
            'pagination' => $pagination
        ];

        ob_start();
        ?>
        <div class="social-feed" data-settings="<?php echo esc_attr(json_encode($atts)); ?>">
            <?php if ($atts['show_filters'] === 'true' && $atts['layout'] !== 'carousel'): ?>
                <div class="social-feed-filters">
                    <?php $this->render_filters($platforms, $types); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($atts['layout'] !== 'carousel'): ?>
                <div class="social-feed-layout-toggle">
                    <button type="button" class="layout-button active" data-layout="grid">
                        <span class="dashicons dashicons-grid-view"></span>
                    </button>
                    <button type="button" class="layout-button" data-layout="list">
                        <span class="dashicons dashicons-list-view"></span>
                    </button>
                    <button type="button" class="layout-button" data-layout="masonry">
                        <span class="dashicons dashicons-schedule"></span>
                    </button>
                    <button type="button" class="layout-button" data-layout="carousel">
                        <span class="dashicons dashicons-slides"></span>
                    </button>
                </div>
            <?php endif; ?>
            
            <?php if ($atts['layout'] === 'carousel'): ?>
                <div class="social-feed-carousel" 
                     data-autoplay="<?php echo esc_attr($atts['autoplay']); ?>"
                     data-slides-to-show="<?php echo esc_attr($atts['slides_to_show']); ?>">
                    <div class="carousel-container">
                        <div class="carousel-inner">
                            <div class="carousel-track">
                                <?php $this->render_carousel_items($result['items']); ?>
                            </div>
                        </div>
                    </div>
                    <button class="carousel-nav carousel-prev" aria-label="Previous">
                        <span class="dashicons dashicons-arrow-left-alt2"></span>
                    </button>
                    <button class="carousel-nav carousel-next" aria-label="Next">
                        <span class="dashicons dashicons-arrow-right-alt2"></span>
                    </button>
                    <div class="carousel-dots"></div>
                </div>
            <?php else: ?>
                <div class="social-feed-items <?php echo esc_attr($atts['layout']); ?>">
                    <?php $this->render_feed_items($result['items']); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($result['pagination']['total_pages'] > 1 && $atts['layout'] !== 'carousel'): ?>
                <div class="social-feed-pagination">
                    <button type="button" class="load-more">Load More</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render social streams shortcode
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function render_streams_shortcode($atts) {
        $atts = shortcode_atts([
            'platforms' => '',
            'status' => '',
            'per_page' => 12,
        ], $atts);

        // Parse platforms
        $platforms = array_filter(array_map('trim', explode(',', $atts['platforms'])));

        // Get streams
        $stream_service = new StreamService();
        $result = $stream_service->get_streams($platforms, $atts['status'], 1, $atts['per_page']);

        ob_start();
        ?>
        <div class="social-streams" data-settings="<?php echo esc_attr(json_encode($atts)); ?>">
            <div class="stream-filters">
                <?php $this->render_stream_filters($platforms); ?>
            </div>
            <div class="stream-items">
                <?php $this->render_stream_items($result['streams']); ?>
            </div>
            <?php if ($result['pagination']['total_pages'] > 1): ?>
                <div class="stream-pagination">
                    <button type="button" class="load-more">Load More</button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render feed filters
     *
     * @param array $platforms
     * @param array $types
     */
    private function render_filters($platforms, $types) {
        ?>
        <div class="filter-group">
            <label>Platforms:</label>
            <div class="filter-options">
                <?php foreach ($this->get_available_platforms() as $platform => $label): ?>
                    <label class="filter-option">
                        <input type="checkbox" name="platform[]" value="<?php echo esc_attr($platform); ?>"
                            <?php checked(empty($platforms) || in_array($platform, $platforms)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="filter-group">
            <label>Content Types:</label>
            <div class="filter-options">
                <?php foreach ($this->get_available_types() as $type => $label): ?>
                    <label class="filter-option">
                        <input type="checkbox" name="type[]" value="<?php echo esc_attr($type); ?>"
                            <?php checked(empty($types) || in_array($type, $types)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render stream filters
     *
     * @param array $platforms
     */
    private function render_stream_filters($platforms) {
        ?>
        <div class="filter-group">
            <label>Platforms:</label>
            <div class="filter-options">
                <?php foreach ($this->get_available_platforms() as $platform => $label): ?>
                    <label class="filter-option">
                        <input type="checkbox" name="platform[]" value="<?php echo esc_attr($platform); ?>"
                            <?php checked(empty($platforms) || in_array($platform, $platforms)); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="filter-group">
            <label>Status:</label>
            <div class="filter-options">
                <label class="filter-option">
                    <input type="radio" name="status" value="" checked>
                    All
                </label>
                <label class="filter-option">
                    <input type="radio" name="status" value="live">
                    Live
                </label>
                <label class="filter-option">
                    <input type="radio" name="status" value="upcoming">
                    Upcoming
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * Render feed items
     *
     * @param array $items
     */
    private function render_feed_items($items) {
        if (!is_array($items) || empty($items)) {
            echo '<div class="feed-item no-items"><p>No content available at the moment.</p></div>';
            return;
        }
        
        foreach ($items as $item) {
            ?>
            <div class="feed-item" data-platform="<?php echo esc_attr($item['platform']); ?>"
                data-type="<?php echo esc_attr($item['type']); ?>">
                <div class="feed-item-header">
                    <div class="author-info">
                        <span class="platform-badge">
                            <?php echo esc_html(ucfirst($item['platform'])); ?>
                        </span>
                    </div>
                    <time datetime="<?php echo esc_attr($item['content']['created_at']); ?>"
                        class="post-date">
                        <?php echo esc_html(human_time_diff(
                            strtotime($item['content']['created_at']),
                            current_time('timestamp')
                        )); ?> ago
                    </time>
                </div>
                <div class="feed-item-media">
                    <?php if ($item['type'] === 'video' || $item['type'] === 'short'): ?>
                        <div class="video-wrapper">
                            <img src="<?php echo esc_url($item['content']['thumbnail_url']); ?>"
                                alt="<?php echo esc_attr($item['content']['title']); ?>">
                            <a href="<?php echo esc_url($item['content']['media_url']); ?>"
                                target="_blank"
                                class="play-button">
                                <span class="dashicons dashicons-controls-play"></span>
                            </a>
                        </div>
                    <?php else: ?>
                        <img src="<?php echo esc_url($item['content']['thumbnail_url']); ?>"
                            alt="<?php echo esc_attr($item['content']['title']); ?>">
                    <?php endif; ?>
                </div>
                <div class="feed-item-content">
                    <h4><?php echo esc_html($item['content']['title']); ?></h4>
                    <p><?php echo wp_trim_words($item['content']['description'], 20); ?></p>
                </div>
                <div class="feed-item-footer">
                    <div class="engagement">
                        <span class="engagement-stat">
                            👍 <?php echo esc_html(number_format($item['content']['engagement']['likes'])); ?>
                        </span>
                        <span class="engagement-stat">
                            💬 <?php echo esc_html(number_format($item['content']['engagement']['comments'])); ?>
                        </span>
                        <?php if ($item['content']['engagement']['shares']): ?>
                            <span class="engagement-stat">
                                🔄 <?php echo esc_html(number_format($item['content']['engagement']['shares'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <a href="<?php echo esc_url($item['content']['original_url']); ?>"
                        target="_blank"
                        class="view-original">
                        View Original
                    </a>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Render stream items
     *
     * @param array $streams
     */
    private function render_stream_items($streams) {
        foreach ($streams as $stream) {
            ?>
            <div class="stream-item" data-platform="<?php echo esc_attr($stream['platform']); ?>"
                data-status="<?php echo esc_attr($stream['status']); ?>">
                <div class="stream-thumbnail">
                    <img src="<?php echo esc_url($stream['thumbnail_url']); ?>"
                        alt="<?php echo esc_attr($stream['title']); ?>">
                    <?php if ($stream['status'] === 'live'): ?>
                        <span class="live-badge">LIVE</span>
                        <span class="viewer-count">
                            👥 <?php echo esc_html(number_format($stream['viewer_count'])); ?>
                        </span>
                    <?php elseif ($stream['status'] === 'upcoming'): ?>
                        <span class="upcoming-badge">
                            <?php echo esc_html(human_time_diff(
                                current_time('timestamp'),
                                strtotime($stream['scheduled_for'])
                            )); ?> until live
                        </span>
                    <?php endif; ?>
                </div>
                <div class="stream-info">
                    <div class="channel-info">
                        <img src="<?php echo esc_url($stream['channel']['avatar']); ?>"
                            alt="<?php echo esc_attr($stream['channel']['name']); ?>"
                            class="channel-avatar">
                        <span class="channel-name">
                            <?php echo esc_html($stream['channel']['name']); ?>
                        </span>
                        <span class="platform-badge">
                            <?php echo esc_html(ucfirst($stream['platform'])); ?>
                        </span>
                    </div>
                    <h4 class="stream-title">
                        <a href="<?php echo esc_url($stream['stream_url']); ?>"
                            target="_blank">
                            <?php echo esc_html($stream['title']); ?>
                        </a>
                    </h4>
                    <p class="stream-description">
                        <?php echo wp_trim_words($stream['description'], 30); ?>
                    </p>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Get available platforms
     *
     * @return array
     */
    private function get_available_platforms() {
        return [
            'youtube' => 'YouTube',
            'tiktok' => 'TikTok',
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
        ];
    }

    /**
     * Get available content types
     *
     * @return array
     */
    private function get_available_types() {
        return [
            'video' => 'Videos',
            'short' => 'Shorts',
            'post' => 'Posts',
            'reel' => 'Reels',
            'live' => 'Live Streams',
        ];
    }

    /**
     * Render carousel items
     *
     * @param array $items
     */
    private function render_carousel_items($items) {
        if (!is_array($items) || empty($items)) {
            echo '<div class="carousel-slide no-items"><p>No content available at the moment.</p></div>';
            return;
        }
        
        foreach ($items as $item) {
            ?>
            <div class="carousel-slide" data-platform="<?php echo esc_attr($item['platform']); ?>"
                data-type="<?php echo esc_attr($item['type']); ?>">
                <div class="carousel-item-media">
                    <?php if ($item['type'] === 'video' || $item['type'] === 'short'): ?>
                        <div class="video-wrapper">
                            <img src="<?php echo esc_url($item['content']['thumbnail_url']); ?>"
                                alt="<?php echo esc_attr($item['content']['title']); ?>"
                                class="video-thumbnail">
                            <div class="video-overlay">
                                <a href="<?php echo esc_url($item['content']['media_url']); ?>"
                                    target="_blank"
                                    class="play-button">
                                    <span class="dashicons dashicons-controls-play"></span>
                                </a>
                                <div class="video-info">
                                    <h4 class="video-title"><?php echo esc_html($item['content']['title']); ?></h4>
                                    <div class="video-meta">
                                        <span class="platform-badge">
                                            <?php echo esc_html(ucfirst($item['platform'])); ?>
                                        </span>
                                        <time datetime="<?php echo esc_attr($item['content']['created_at']); ?>"
                                            class="post-date">
                                            <?php echo esc_html(human_time_diff(
                                                strtotime($item['content']['created_at']),
                                                current_time('timestamp')
                                            )); ?> ago
                                        </time>
                                    </div>
                                    <div class="engagement-stats">
                                        <span class="engagement-stat">
                                            👍 <?php echo esc_html(number_format($item['content']['engagement']['likes'])); ?>
                                        </span>
                                        <span class="engagement-stat">
                                            💬 <?php echo esc_html(number_format($item['content']['engagement']['comments'])); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <img src="<?php echo esc_url($item['content']['thumbnail_url']); ?>"
                            alt="<?php echo esc_attr($item['content']['title']); ?>"
                            class="carousel-image">
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
    }

    /**
     * Get enabled platforms for carousel (fallback to YouTube if none configured)
     *
     * @return array
     */
    private function get_enabled_platforms_for_carousel() {
        $platforms = get_option('social_feed_platforms', []);
        
        $enabled = array_keys(array_filter($platforms, function($platform, $platform_name) {
            $is_enabled = !empty($platform['enabled']);
            $has_required = true;
            
            if ($is_enabled) {
                switch ($platform_name) {
                    case 'youtube':
                        $has_required = !empty($platform['api_key']) && !empty($platform['channel_id']);
                        break;
                    case 'tiktok':
                        $has_required = !empty($platform['api_key']) && !empty($platform['access_token']);
                        break;
                    case 'instagram':
                        $has_required = !empty($platform['access_token']);
                        break;
                    case 'facebook':
                        $has_required = !empty($platform['access_token']);
                        break;
                }
            }
            
            return $is_enabled && $has_required;
        }, ARRAY_FILTER_USE_BOTH));
        
        // If no platforms are configured, default to YouTube for demo purposes
        if (empty($enabled)) {
            $enabled = ['youtube'];
        }
        
        return $enabled;
    }
}