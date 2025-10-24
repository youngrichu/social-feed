<?php
namespace SocialFeed\Admin;

use SocialFeed\Platforms\PlatformFactory;

class Admin {
    /**
     * @var string The option name for plugin settings
     */
    const OPTION_NAME = 'social_feed_platforms';

    /**
     * @var PlatformFactory
     */
    private $platform_factory;

    /**
     * Constructor
     */
    public function __construct() {
        $this->platform_factory = new PlatformFactory();
    }

    /**
     * Initialize admin functionality
     */
    public function init() {
        add_action('admin_menu', [$this, 'add_menu_pages']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // Register AJAX handlers
        add_action('wp_ajax_social_feed_clear_cache', [$this, 'handle_clear_cache']);
        add_action('wp_ajax_social_feed_refresh', [$this, 'handle_refresh_feeds']);
        add_action('wp_ajax_social_feed_reset_quota', [$this, 'handle_reset_quota']);
    }

    /**
     * Add menu pages
     */
    public function add_menu_pages() {
        add_menu_page(
            'Social Feed',
            'Social Feed',
            'manage_options',
            'social-feed',
            [$this, 'render_main_page'],
            'dashicons-share',
            30
        );

        add_submenu_page(
            'social-feed',
            'Settings',
            'Settings',
            'manage_options',
            'social-feed-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'social_feed_settings',
            self::OPTION_NAME,
            [
                'type' => 'array',
                'description' => 'Social Feed platform settings',
                'sanitize_callback' => [$this, 'sanitize_settings'],
            ]
        );

        add_settings_section(
            'social_feed_platforms_section',
            'Platform Settings',
            [$this, 'render_platforms_section'],
            'social-feed-settings'
        );

        // Add settings fields for each platform
        $platforms = $this->platform_factory->get_available_platforms();
        foreach ($platforms as $platform_id => $platform) {
            add_settings_field(
                "social_feed_{$platform_id}",
                $platform['name'],
                [$this, 'render_platform_fields'],
                'social-feed-settings',
                'social_feed_platforms_section',
                ['platform' => $platform_id]
            );
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_assets($hook) {
        if (!in_array($hook, ['toplevel_page_social-feed', 'social-feed_page_social-feed-settings'])) {
            return;
        }

        error_log('Social Feed: Enqueuing admin assets for hook: ' . $hook);
        error_log('Social Feed: Plugin URL: ' . SOCIAL_FEED_PLUGIN_URL);

        wp_enqueue_style(
            'social-feed-admin',
            SOCIAL_FEED_PLUGIN_URL . 'assets/css/admin.css',
            [],
            SOCIAL_FEED_VERSION
        );

        wp_enqueue_script(
            'social-feed-admin',
            SOCIAL_FEED_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            SOCIAL_FEED_VERSION,
            true
        );

        wp_localize_script('social-feed-admin', 'socialFeedAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('social_feed_admin')
        ]);
    }

    /**
     * Render main admin page
     */
    public function render_main_page() {
        ?>
        <div class="wrap">
            <h1>Social Feed Dashboard</h1>
            <div class="social-feed-dashboard">
                <div class="social-feed-stats">
                    <?php $this->render_platform_stats(); ?>
                </div>
                <div class="social-feed-actions">
                    <button class="button button-primary" id="refresh-feeds">
                        Refresh Feeds
                    </button>
                    <button class="button" id="clear-cache">
                        Clear Cache
                    </button>
                </div>
                <div class="social-feed-preview">
                    <?php $this->render_feed_preview(); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Social Feed Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('social_feed_settings');
                do_settings_sections('social-feed-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render platforms section description
     */
    public function render_platforms_section() {
        echo '<p>Configure your social media platform settings below.</p>';
    }

    /**
     * Render platform fields
     *
     * @param array $args
     */
    public function render_platform_fields($args) {
        $platform_id = $args['platform'];
        $platforms = $this->platform_factory->get_available_platforms();
        $platform = $platforms[$platform_id];
        $options = get_option(self::OPTION_NAME, []);
        $config = $options[$platform_id] ?? [];

        ?>
        <div class="social-feed-platform-fields">
            <label>
                <input type="checkbox"
                    name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($platform_id); ?>][enabled]"
                    value="1"
                    <?php checked(!empty($config['enabled'])); ?>>
                Enable <?php echo esc_html($platform['name']); ?>
            </label>

            <div class="platform-fields" style="margin-top: 10px;">
                <?php $this->render_platform_specific_fields($platform_id, $config); ?>
            </div>

            <p class="description">
                <?php echo esc_html($platform['description']); ?><br>
                Supported content types: <?php echo esc_html(implode(', ', $platform['types'])); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Render platform-specific fields
     *
     * @param string $platform_id
     * @param array $config
     */
    private function render_platform_specific_fields($platform_id, $config) {
        switch ($platform_id) {
            case 'youtube':
                ?>
                <div class="field-row">
                    <label>
                        <input type="checkbox"
                            name="social_feed_platforms[youtube][enabled]"
                            value="1"
                            <?php checked(!empty($config['enabled'])); ?>>
                        Enable YouTube Integration
                    </label>
                </div>
                <div class="field-row">
                    <label>API Key</label>
                    <input type="text"
                        name="social_feed_platforms[youtube][api_key]"
                        value="<?php echo esc_attr($config['api_key'] ?? ''); ?>"
                        class="regular-text">
                    <p class="description">Enter your YouTube Data API key</p>
                </div>
                <div class="field-row">
                    <label>Channel ID</label>
                    <input type="text"
                        name="social_feed_platforms[youtube][channel_id]"
                        value="<?php echo esc_attr($config['channel_id'] ?? ''); ?>"
                        class="regular-text">
                    <p class="description">Enter your YouTube channel ID</p>
                </div>
                <div class="field-row">
                    <label>
                        <input type="checkbox"
                            name="social_feed_platforms[youtube][enable_live_check]"
                            value="1"
                            <?php checked(!empty($config['enable_live_check'])); ?>>
                        Enable Live Stream Checking
                    </label>
                    <p class="description">Warning: Live stream checking uses the YouTube Search API which costs 100 quota units per request.</p>
                </div>
                <?php
                // Display quota management section
                if (!empty($config['api_key'])) {
                    $quota_manager = new \SocialFeed\Core\QuotaManager();
                    $quota_stats = $quota_manager->get_detailed_stats();
                    $status_class = 'quota-' . $quota_stats['status'];
                    ?>
                    <div class="quota-management-section">
                        <h3>YouTube API Quota Management</h3>
                        <div class="quota-overview <?php echo esc_attr($status_class); ?>">
                            <div class="quota-status">
                                <span class="status-label">Status:</span>
                                <span class="status-value"><?php echo ucfirst($quota_stats['status']); ?></span>
                            </div>
                            <div class="quota-usage">
                                <div class="usage-bar">
                                    <div class="usage-fill" style="width: <?php echo min(100, $quota_stats['percentage']); ?>%"></div>
                                </div>
                                <div class="usage-stats">
                                    <span class="current"><?php echo number_format($quota_stats['current_usage']); ?></span>
                                    <span class="separator">/</span>
                                    <span class="limit"><?php echo number_format($quota_stats['limit']); ?></span>
                                    <span class="percentage">(<?php echo number_format($quota_stats['percentage'], 1); ?>%)</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="quota-details">
                            <h4>Operation Statistics</h4>
                            <table class="quota-operations-table">
                                <thead>
                                    <tr>
                                        <th>Operation</th>
                                        <th>Cost per Call</th>
                                        <th>Calls Today</th>
                                        <th>Total Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quota_stats['operations'] as $operation => $count): ?>
                                    <tr>
                                        <td><?php echo esc_html($operation); ?></td>
                                        <td><?php echo \SocialFeed\Core\QuotaManager::QUOTA_COSTS[$operation] ?? 1; ?></td>
                                        <td><?php echo number_format($count); ?></td>
                                        <td><?php echo number_format(($count * (\SocialFeed\Core\QuotaManager::QUOTA_COSTS[$operation] ?? 1))); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <div class="quota-actions">
                                <button type="button" class="button reset-quota" data-nonce="<?php echo wp_create_nonce('reset_quota'); ?>">
                                    Reset Quota Counter
                                </button>
                                <p class="description">Last updated: <?php echo $quota_stats['last_update'] ? date('Y-m-d H:i:s', strtotime($quota_stats['last_update'])) : 'Never'; ?></p>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                break;

            case 'tiktok':
                ?>
                <div class="field-row">
                    <label for="tiktok_api_key">API Key</label>
                    <input type="text" 
                        id="tiktok_api_key" 
                        name="social_feed_platforms[tiktok][api_key]" 
                        value="<?php echo esc_attr($config['api_key'] ?? ''); ?>"
                        class="regular-text">
                    <p class="description">Enter your TikTok API key</p>
                </div>
                <div class="field-row">
                    <label for="tiktok_access_token">Access Token</label>
                    <input type="password" 
                        id="tiktok_access_token" 
                        name="social_feed_platforms[tiktok][access_token]" 
                        value="<?php echo esc_attr($config['access_token'] ?? ''); ?>"
                        class="regular-text">
                    <p class="description">Enter your TikTok access token</p>
                </div>
                <?php
                break;

            case 'facebook':
            case 'instagram':
                ?>
                <div class="field-row">
                    <label>App ID:</label>
                    <input type="text"
                        class="regular-text"
                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($platform_id); ?>][app_id]"
                        value="<?php echo esc_attr($config['app_id'] ?? ''); ?>">
                </div>
                <div class="field-row">
                    <label>App Secret:</label>
                    <input type="password"
                        class="regular-text"
                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($platform_id); ?>][app_secret]"
                        value="<?php echo esc_attr($config['app_secret'] ?? ''); ?>">
                </div>
                <div class="field-row">
                    <label>Access Token:</label>
                    <input type="text"
                        class="regular-text"
                        name="<?php echo esc_attr(self::OPTION_NAME); ?>[<?php echo esc_attr($platform_id); ?>][access_token]"
                        value="<?php echo esc_attr($config['access_token'] ?? ''); ?>">
                </div>
                <?php
                break;
        }
    }

    /**
     * Sanitize settings
     *
     * @param array $input
     * @return array
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        $platforms = $this->platform_factory->get_available_platforms();

        foreach ($platforms as $platform_id => $platform) {
            if (isset($input[$platform_id])) {
                $sanitized[$platform_id] = $this->sanitize_platform_settings(
                    $platform_id,
                    $input[$platform_id]
                );
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize platform-specific settings
     *
     * @param string $platform_id
     * @param array $settings
     * @return array
     */
    private function sanitize_platform_settings($platform_id, $settings) {
        $sanitized = [
            'enabled' => !empty($settings['enabled']),
        ];

        switch ($platform_id) {
            case 'youtube':
                $sanitized['api_key'] = sanitize_text_field($settings['api_key'] ?? '');
                $sanitized['channel_id'] = sanitize_text_field($settings['channel_id'] ?? '');
                $sanitized['max_pages'] = sanitize_text_field($settings['max_pages'] ?? '5');
                $sanitized['enable_live_check'] = !empty($settings['enable_live_check']);
                break;

            case 'tiktok':
                $sanitized['api_key'] = sanitize_text_field($settings['api_key'] ?? '');
                $sanitized['access_token'] = sanitize_text_field($settings['access_token'] ?? '');
                break;

            case 'facebook':
            case 'instagram':
                $sanitized['app_id'] = sanitize_text_field($settings['app_id'] ?? '');
                $sanitized['app_secret'] = sanitize_text_field($settings['app_secret'] ?? '');
                $sanitized['access_token'] = sanitize_text_field($settings['access_token'] ?? '');
                break;
        }

        return $sanitized;
    }

    /**
     * Render platform stats
     */
    private function render_platform_stats() {
        $platforms = [
            'youtube' => ['name' => 'YouTube'],
            'tiktok' => ['name' => 'TikTok']
        ];

        foreach ($platforms as $platform_id => $platform) {
            global $wpdb;
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            $streams_table = $wpdb->prefix . 'social_feed_streams';

            $total_items = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $cache_table WHERE platform = %s",
                $platform_id
            ));

            $live_streams = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $streams_table WHERE platform = %s AND status = 'live'",
                $platform_id
            ));

            // Get quota status for YouTube
            $quota_status = '';
            if ($platform_id === 'youtube') {
                $quota_manager = new \SocialFeed\Core\QuotaManager();
                $current_usage = $quota_manager->get_current_usage();
                $percentage = $quota_manager->get_quota_usage_percentage();
                
                if ($percentage >= 100) {
                    $status_class = 'quota-exceeded';
                    $status_text = 'Quota Exceeded';
                } else {
                    if ($percentage >= 90) {
                        $status_class = 'quota-critical';
                    } elseif ($percentage >= 75) {
                        $status_class = 'quota-warning';
                    } else {
                        $status_class = 'quota-normal';
                    }
                    
                    $status_text = number_format($percentage, 1) . '% Used';
                }
                
                $quota_status = sprintf(
                    '<div class="quota-status %s">
                        <span class="quota-label">API Quota:</span>
                        <span class="quota-value">%s</span>
                        <span class="quota-details">(%s / %s units)</span>
                    </div>',
                    esc_attr($status_class),
                    esc_html($status_text),
                    number_format($current_usage),
                    number_format(\SocialFeed\Core\QuotaManager::QUOTA_LIMIT_PER_DAY)
                );
            }

            ?>
            <div class="platform-stats">
                <h3><?php echo esc_html($platform['name']); ?></h3>
                <div class="stats-grid">
                    <div class="stat-item">
                        <span class="stat-label">Total Items</span>
                        <span class="stat-value"><?php echo esc_html($total_items); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Live Streams</span>
                        <span class="stat-value"><?php echo esc_html($live_streams); ?></span>
                    </div>
                </div>
                <?php echo $quota_status; ?>
            </div>
            <?php
        }
    }

    /**
     * Render feed preview
     */
    private function render_feed_preview() {
        $feed_service = new \SocialFeed\Services\FeedService();
        $result = $feed_service->get_feeds([], [], 1, 5);
        $items = $result['data']['items'] ?? [];

        if (empty($items)) {
            echo '<div class="notice notice-warning"><p>No feed items found. Please check your platform configuration and try refreshing the feeds.</p></div>';
            return;
        }

        echo '<div class="feed-preview-grid">';
        foreach ($items as $item) {
            $content = $item['content'] ?? [];
            $author = $item['author'] ?? [
                'name' => '',
                'avatar' => '',
                'profile_url' => ''
            ];
            
            ?>
            <div class="feed-item">
                <div class="feed-item-header">
                    <img src="<?php echo esc_url($author['avatar'] ?? ''); ?>"
                        alt="<?php echo esc_attr($author['name'] ?? ''); ?>"
                        class="author-avatar">
                    <div class="author-info">
                        <a href="<?php echo esc_url($author['profile_url'] ?? ''); ?>"
                            target="_blank"
                            class="author-name">
                            <?php echo esc_html($author['name'] ?? ''); ?>
                        </a>
                        <span class="platform-badge">
                            <?php echo esc_html(ucfirst($item['platform'] ?? '')); ?>
                        </span>
                    </div>
                    <time datetime="<?php echo esc_attr($content['created_at'] ?? ''); ?>"
                        class="post-date">
                        <?php 
                        if (!empty($content['created_at'])) {
                            echo esc_html(human_time_diff(
                                strtotime($content['created_at']),
                                current_time('timestamp')
                            )) . ' ago';
                        }
                        ?>
                    </time>
                </div>
                <div class="feed-item-media">
                    <?php if (($item['type'] ?? '') === 'video' || ($item['type'] ?? '') === 'short'): ?>
                        <div class="video-wrapper">
                            <img src="<?php echo esc_url($content['thumbnail_url'] ?? ''); ?>"
                                alt="<?php echo esc_attr($content['title'] ?? ''); ?>">
                            <a href="<?php echo esc_url($content['media_url'] ?? ''); ?>"
                                target="_blank"
                                class="play-button">
                                <span class="dashicons dashicons-controls-play"></span>
                            </a>
                        </div>
                    <?php else: ?>
                        <img src="<?php echo esc_url($content['thumbnail_url'] ?? ''); ?>"
                            alt="<?php echo esc_attr($content['title'] ?? ''); ?>">
                    <?php endif; ?>
                </div>
                <div class="feed-item-content">
                    <h4><?php echo esc_html($content['title'] ?? ''); ?></h4>
                    <p><?php echo esc_html(wp_trim_words($content['description'] ?? '', 20)); ?></p>
                </div>
                <div class="feed-item-footer">
                    <div class="engagement">
                        <?php 
                        $engagement = $content['engagement'] ?? [];
                        if (!empty($engagement['likes'])): ?>
                            <span class="engagement-stat">
                                👍 <?php echo esc_html(number_format((int)$engagement['likes'])); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($engagement['comments'])): ?>
                            <span class="engagement-stat">
                                💬 <?php echo esc_html(number_format((int)$engagement['comments'])); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($engagement['shares'])): ?>
                            <span class="engagement-stat">
                                🔄 <?php echo esc_html(number_format($engagement['shares'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($content['original_url'])): ?>
                        <a href="<?php echo esc_url($content['original_url']); ?>"
                            target="_blank"
                            class="view-original">
                            View Original
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * Handle clear cache AJAX request
     */
    public function handle_clear_cache() {
        error_log('Social Feed: Clear cache request received');
        
        // Verify nonce
        if (!check_ajax_referer('social_feed_admin', 'nonce', false)) {
            error_log('Social Feed: Nonce verification failed');
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            global $wpdb;
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            
            error_log('Social Feed: Attempting to clear cache table: ' . $cache_table);
            
            // Delete all records instead of truncate
            $result = $wpdb->query("DELETE FROM $cache_table");
            
            if ($result !== false) {
                error_log('Social Feed: Cache cleared successfully. Rows affected: ' . $result);
                wp_send_json_success([
                    'message' => 'Cache cleared successfully',
                    'rows_affected' => $result
                ]);
            } else {
                error_log('Social Feed: Error clearing cache - wpdb error: ' . $wpdb->last_error);
                wp_send_json_error([
                    'message' => 'Database error while clearing cache',
                    'error' => $wpdb->last_error
                ]);
            }
        } catch (\Exception $e) {
            error_log('Social Feed: Error clearing cache: ' . $e->getMessage());
            wp_send_json_error([
                'message' => 'Error clearing cache: ' . $e->getMessage()
            ]);
        }
        exit;
    }

    /**
     * Handle refresh feeds AJAX request
     */
    public function handle_refresh_feeds() {
        error_log('Social Feed: Starting feed refresh');
        
        // Verify nonce
        if (!check_ajax_referer('social_feed_admin', 'nonce', false)) {
            error_log('Social Feed: Nonce verification failed');
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            $feed_service = new \SocialFeed\Services\FeedService();
            error_log('Social Feed: Initialized FeedService');
            
            $result = $feed_service->get_feeds();
            error_log('Social Feed: Feed service returned result: ' . json_encode($result));
            
            if (isset($result['status']) && $result['status'] === 'error') {
                error_log('Social Feed: Feed service returned error status');
                wp_send_json_error([
                    'message' => 'Error refreshing feeds: ' . ($result['message'] ?? 'Unknown error'),
                    'details' => $result['data'] ?? []
                ]);
                return;
            }
            
            wp_send_json_success([
                'message' => 'Feeds refreshed successfully',
                'count' => count($result['data']['items'] ?? [])
            ]);
        } catch (\Exception $e) {
            error_log('Social Feed: Error in refresh_feeds: ' . $e->getMessage());
            error_log('Social Feed: Error trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'Error refreshing feeds: ' . $e->getMessage(),
                'details' => [
                    'error_type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ]);
        }
    }

    /**
     * Handle quota reset AJAX request
     */
    public function handle_reset_quota() {
        // Verify nonce
        if (!check_ajax_referer('reset_quota', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            $quota_manager = new \SocialFeed\Core\QuotaManager();
            if ($quota_manager->reset_quota()) {
                wp_send_json_success([
                    'message' => 'Quota counter reset successfully',
                    'new_stats' => $quota_manager->get_detailed_stats()
                ]);
            } else {
                wp_send_json_error(['message' => 'Failed to reset quota counter']);
            }
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Error resetting quota: ' . $e->getMessage()
            ]);
        }
    }
} 