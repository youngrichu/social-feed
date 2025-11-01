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
        
        // Advanced feature AJAX handlers
        add_action('wp_ajax_social_feed_clear_all_cache', [$this, 'handle_clear_all_cache']);
        add_action('wp_ajax_social_feed_optimize_cache', [$this, 'handle_optimize_cache']);
        add_action('wp_ajax_social_feed_refresh_streams', [$this, 'handle_refresh_streams']);
        add_action('wp_ajax_social_feed_test_notification', [$this, 'handle_test_notification']);
        add_action('wp_ajax_social_feed_retry_failed_notifications', [$this, 'handle_retry_failed_notifications']);
        add_action('wp_ajax_social_feed_update_async_settings', [$this, 'handle_update_async_settings']);
        add_action('wp_ajax_social_feed_reset_performance_data', [$this, 'handle_reset_performance_data']);
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

        // Register shortcode settings
        register_setting(
            'social_feed_settings',
            'social_feed_shortcode_settings',
            [
                'type' => 'array',
                'description' => 'Social Feed shortcode default settings',
                'sanitize_callback' => [$this, 'sanitize_shortcode_settings'],
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
        $allowed_hooks = [
            'toplevel_page_social-feed',
            'social-feed_page_social-feed-settings'
        ];
        
        if (!in_array($hook, $allowed_hooks)) {
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
     * Render settings page with tabs
     */
    public function render_settings_page() {
        // Handle form submissions
        if (isset($_POST['submit'])) {
            if (isset($_POST['social_feed_shortcode_settings']) && wp_verify_nonce($_POST['_wpnonce'], 'social_feed_shortcode_settings')) {
                $this->save_shortcode_settings($_POST['social_feed_shortcode_settings']);
                echo '<div class="notice notice-success"><p>Shortcode settings saved successfully!</p></div>';
            }
        }

        // Get current tab
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'platforms';
        
        ?>
        <div class="wrap">
            <h1>Social Feed Settings</h1>
            
            <!-- Tab Navigation -->
            <nav class="nav-tab-wrapper">
                <a href="?page=social-feed-settings&tab=platforms" 
                   class="nav-tab <?php echo $current_tab === 'platforms' ? 'nav-tab-active' : ''; ?>">
                    Platforms
                </a>
                <a href="?page=social-feed-settings&tab=shortcodes" 
                   class="nav-tab <?php echo $current_tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">
                    Shortcodes
                </a>
                <a href="?page=social-feed-settings&tab=performance" 
                   class="nav-tab <?php echo $current_tab === 'performance' ? 'nav-tab-active' : ''; ?>">
                    Performance
                </a>
                <a href="?page=social-feed-settings&tab=cache" 
                   class="nav-tab <?php echo $current_tab === 'cache' ? 'nav-tab-active' : ''; ?>">
                    Cache
                </a>
                <a href="?page=social-feed-settings&tab=streams" 
                   class="nav-tab <?php echo $current_tab === 'streams' ? 'nav-tab-active' : ''; ?>">
                    Streams
                </a>
                <a href="?page=social-feed-settings&tab=advanced" 
                   class="nav-tab <?php echo $current_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
                    Advanced
                </a>
                <a href="?page=social-feed-settings&tab=notifications" 
                   class="nav-tab <?php echo $current_tab === 'notifications' ? 'nav-tab-active' : ''; ?>">
                    Notifications
                </a>
            </nav>

            <!-- Tab Content -->
            <div class="tab-content">
                <?php
                switch ($current_tab) {
                    case 'platforms':
                        $this->render_platforms_tab();
                        break;
                    case 'shortcodes':
                        $this->render_shortcodes_tab();
                        break;
                    case 'performance':
                        $this->render_performance_tab();
                        break;
                    case 'cache':
                        $this->render_cache_tab();
                        break;
                    case 'streams':
                        $this->render_streams_tab();
                        break;
                    case 'advanced':
                        $this->render_advanced_tab();
                        break;
                    case 'notifications':
                        $this->render_notifications_tab();
                        break;
                    default:
                        $this->render_platforms_tab();
                        break;
                }
                ?>
            </div>
        </div>
        
        <style>
        .tab-content {
            margin-top: 20px;
        }
        .settings-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .settings-section h2 {
            margin-top: 0;
        }
        .shortcode-preview {
            background: #f9f9f9;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            margin-top: 15px;
        }
        </style>
        <?php
    }

    /**
     * Render platforms tab content
     */
    private function render_platforms_tab() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('social_feed_settings');
            do_settings_sections('social-feed-settings');
            submit_button();
            ?>
        </form>
        <?php
    }

    /**
     * Render shortcodes tab content
     */
    private function render_shortcodes_tab() {
        $shortcode_settings = get_option('social_feed_shortcode_settings', []);
        $defaults = [
            'default_layout' => 'carousel',
            'default_per_page' => 12,
            'default_platforms' => ['youtube'],
            'default_types' => ['video', 'short'],
            'default_sort' => 'date',
            'default_order' => 'desc',
            'default_show_filters' => false,
            'default_autoplay' => true,
            'default_slides_to_show' => 3
        ];
        $settings = wp_parse_args($shortcode_settings, $defaults);
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('social_feed_shortcode_settings', 'shortcode_settings_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Default Layout</th>
                    <td>
                        <select name="social_feed_shortcode_settings[default_layout]">
                            <option value="carousel" <?php selected($settings['default_layout'], 'carousel'); ?>>Carousel</option>
                            <option value="grid" <?php selected($settings['default_layout'], 'grid'); ?>>Grid</option>
                            <option value="list" <?php selected($settings['default_layout'], 'list'); ?>>List</option>
                            <option value="masonry" <?php selected($settings['default_layout'], 'masonry'); ?>>Masonry</option>
                        </select>
                        <p class="description">Default layout for shortcodes when not specified</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Items Per Page</th>
                    <td>
                        <input type="number" name="social_feed_shortcode_settings[default_per_page]" 
                               value="<?php echo esc_attr($settings['default_per_page']); ?>" 
                               min="1" max="50" class="small-text">
                        <p class="description">Default number of items to display per page</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Default Platforms</th>
                    <td>
                        <?php
                        $available_platforms = ['youtube', 'tiktok', 'facebook', 'instagram'];
                        foreach ($available_platforms as $platform) {
                            $checked = in_array($platform, $settings['default_platforms']);
                            ?>
                            <label>
                                <input type="checkbox" name="social_feed_shortcode_settings[default_platforms][]" 
                                       value="<?php echo esc_attr($platform); ?>" <?php checked($checked); ?>>
                                <?php echo esc_html(ucfirst($platform)); ?>
                            </label><br>
                            <?php
                        }
                        ?>
                        <p class="description">Default platforms to show when not specified in shortcode</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Default Content Types</th>
                    <td>
                        <label>
                            <input type="checkbox" name="social_feed_shortcode_settings[default_types][]" 
                                   value="video" <?php checked(in_array('video', $settings['default_types'])); ?>>
                            Videos
                        </label><br>
                        <label>
                            <input type="checkbox" name="social_feed_shortcode_settings[default_types][]" 
                                   value="short" <?php checked(in_array('short', $settings['default_types'])); ?>>
                            Shorts
                        </label>
                        <p class="description">Default content types to display</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Default Sort Order</th>
                    <td>
                        <select name="social_feed_shortcode_settings[default_sort]">
                            <option value="date" <?php selected($settings['default_sort'], 'date'); ?>>Date</option>
                            <option value="views" <?php selected($settings['default_sort'], 'views'); ?>>Views</option>
                            <option value="likes" <?php selected($settings['default_sort'], 'likes'); ?>>Likes</option>
                        </select>
                        
                        <select name="social_feed_shortcode_settings[default_order]">
                            <option value="desc" <?php selected($settings['default_order'], 'desc'); ?>>Descending</option>
                            <option value="asc" <?php selected($settings['default_order'], 'asc'); ?>>Ascending</option>
                        </select>
                        <p class="description">Default sorting method and order</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Show Filters</th>
                    <td>
                        <label>
                            <input type="checkbox" name="social_feed_shortcode_settings[default_show_filters]" 
                                   value="1" <?php checked($settings['default_show_filters']); ?>>
                            Show filters by default
                        </label>
                        <p class="description">Enable platform and content type filters by default</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Carousel Settings</th>
                    <td>
                        <label>
                            <input type="checkbox" name="social_feed_shortcode_settings[default_autoplay]" 
                                   value="1" <?php checked($settings['default_autoplay']); ?>>
                            Enable autoplay
                        </label><br>
                        
                        <label>
                            Slides to show: 
                            <input type="number" name="social_feed_shortcode_settings[default_slides_to_show]" 
                                   value="<?php echo esc_attr($settings['default_slides_to_show']); ?>" 
                                   min="1" max="6" class="small-text">
                        </label>
                        <p class="description">Default carousel settings</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Shortcode Settings'); ?>
        </form>
        <?php
    }

    /**
     * Render notifications tab content
     */
    private function render_notifications_tab() {
        // Move the existing notification functionality here
        ?>
        <div class="social-feed-notifications">
            <h3>Test Notifications</h3>
            <p>Send test notifications to verify your notification system is working correctly.</p>
            
            <div class="notification-actions">
                <button type="button" class="button button-primary" id="send-test-notification">
                    Send Test Notification
                </button>
                <button type="button" class="button" id="retry-failed-notifications">
                    Retry Failed Notifications
                </button>
            </div>
            
            <div id="notification-result" style="margin-top: 15px;"></div>
            
            <h3>Failed Notifications</h3>
            <div id="failed-notifications-list">
                <?php $this->render_failed_notifications_list(); ?>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#send-test-notification').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Sending...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'social_feed_send_test_notification',
                        nonce: '<?php echo wp_create_nonce('social_feed_test_notification'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#notification-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        } else {
                            $('#notification-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#notification-result').html('<div class="notice notice-error"><p>Failed to send test notification</p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Send Test Notification');
                    }
                });
            });
            
            $('#retry-failed-notifications').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('Retrying...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'social_feed_retry_failed_notifications',
                        nonce: '<?php echo wp_create_nonce('social_feed_retry_notifications'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#notification-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                            $('#failed-notifications-list').load(location.href + ' #failed-notifications-list > *');
                        } else {
                            $('#notification-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $('#notification-result').html('<div class="notice notice-error"><p>Failed to retry notifications</p></div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Retry Failed Notifications');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render performance tab content
     */
    private function render_performance_tab() {
        try {
            $performance_monitor = new \SocialFeed\Core\PerformanceMonitor();
            $performance_data = $performance_monitor->get_performance_report();
            $system_status = $performance_monitor->get_system_status();
        } catch (\Exception $e) {
            $performance_data = ['error' => $e->getMessage()];
            $system_status = ['status' => 'error', 'message' => $e->getMessage()];
        }
        ?>
        <div class="social-feed-performance-dashboard">
            <div class="performance-cards">
                <div class="performance-card">
                    <h3>System Status</h3>
                    <div class="status-indicator <?php echo esc_attr($system_status['status']); ?>">
                        <?php echo esc_html(ucfirst($system_status['status'])); ?>
                    </div>
                    <?php if (isset($system_status['message'])): ?>
                        <p><?php echo esc_html($system_status['message']); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="performance-card">
                    <h3>API Response Times</h3>
                    <?php if (isset($performance_data['error'])): ?>
                        <p class="error">Error: <?php echo esc_html($performance_data['error']); ?></p>
                    <?php else: ?>
                        <div class="metric">
                            <span class="label">Average:</span>
                            <span class="value"><?php echo number_format($performance_data['avg_response_time'] ?? 0, 2); ?>ms</span>
                        </div>
                        <div class="metric">
                            <span class="label">Peak:</span>
                            <span class="value"><?php echo number_format($performance_data['peak_response_time'] ?? 0, 2); ?>ms</span>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="performance-card">
                    <h3>Cache Performance</h3>
                    <div class="metric">
                        <span class="label">Hit Rate:</span>
                        <span class="value"><?php echo number_format($performance_data['cache_hit_rate'] ?? 0, 1); ?>%</span>
                    </div>
                    <div class="metric">
                        <span class="label">Total Requests:</span>
                        <span class="value"><?php echo number_format($performance_data['total_requests'] ?? 0); ?></span>
                    </div>
                </div>
            </div>
            
            <div class="performance-actions">
                <button type="button" class="button button-primary" id="refresh-performance-data">
                    Refresh Data
                </button>
                <button type="button" class="button" id="clear-performance-logs">
                    Clear Logs
                </button>
            </div>
        </div>
        
        <style>
        .social-feed-performance-dashboard {
            margin-top: 20px;
        }
        .performance-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .performance-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
        }
        .performance-card h3 {
            margin-top: 0;
            margin-bottom: 15px;
        }
        .status-indicator {
            padding: 8px 16px;
            border-radius: 4px;
            font-weight: bold;
            text-align: center;
        }
        .status-indicator.healthy {
            background-color: #d4edda;
            color: #155724;
        }
        .status-indicator.warning {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-indicator.error {
            background-color: #f8d7da;
            color: #721c24;
        }
        .metric {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .metric .label {
            font-weight: 600;
        }
        .performance-actions {
            margin-top: 20px;
        }
        </style>
        <?php
    }

    /**
     * Render cache tab content
     */
    private function render_cache_tab() {
        try {
            $cache_manager = new \SocialFeed\Core\CacheManager('global');
            $cache_stats = $cache_manager->get_stats();
        } catch (\Exception $e) {
            $cache_stats = ['error' => $e->getMessage()];
        }
        ?>
        <div class="social-feed-cache-management">
            <div class="cache-stats">
                <h3>Cache Statistics</h3>
                <?php if (isset($cache_stats['error'])): ?>
                    <p class="error">Error: <?php echo esc_html($cache_stats['error']); ?></p>
                <?php else: ?>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <span class="stat-label">Total Items:</span>
                            <span class="stat-value"><?php echo number_format($cache_stats['total_items'] ?? 0); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Cache Size:</span>
                            <span class="stat-value"><?php echo size_format($cache_stats['total_size'] ?? 0); ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Hit Rate:</span>
                            <span class="stat-value"><?php echo number_format($cache_stats['hit_rate'] ?? 0, 1); ?>%</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Expired Items:</span>
                            <span class="stat-value"><?php echo number_format($cache_stats['expired_items'] ?? 0); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="cache-actions">
                <h3>Cache Management</h3>
                <div class="action-buttons">
                    <button type="button" class="button button-primary" id="clear-all-cache">
                        Clear All Cache
                    </button>
                    <button type="button" class="button" id="clear-expired-cache">
                        Clear Expired Items
                    </button>
                    <button type="button" class="button" id="refresh-cache-stats">
                        Refresh Statistics
                    </button>
                </div>
            </div>
        </div>
        
        <style>
        .social-feed-cache-management {
            margin-top: 20px;
        }
        .cache-stats {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .stat-label {
            font-weight: 600;
        }
        .cache-actions {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
        }
        .action-buttons {
            margin-top: 15px;
        }
        .action-buttons .button {
            margin-right: 10px;
        }
        </style>
        <?php
    }

    /**
     * Render streams tab content
     */
    private function render_streams_tab() {
        try {
            $stream_service = new \SocialFeed\Services\StreamService();
            $streams = $stream_service->get_streams(['youtube', 'tiktok'], 'live', 1, 20);
        } catch (\Exception $e) {
            $streams = ['error' => $e->getMessage()];
        }
        ?>
        <div class="social-feed-stream-management">
            <div class="stream-actions">
                <button type="button" class="button button-primary" id="refresh-streams">
                    Refresh Streams
                </button>
                <button type="button" class="button" id="check-live-status">
                    Check Live Status
                </button>
            </div>
            
            <div class="streams-list">
                <h3>Live Streams</h3>
                <?php if (isset($streams['error'])): ?>
                    <p class="error">Error: <?php echo esc_html($streams['error']); ?></p>
                <?php elseif (empty($streams['data']['items'])): ?>
                    <p>No live streams found.</p>
                <?php else: ?>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Platform</th>
                                <th>Status</th>
                                <th>Viewers</th>
                                <th>Started</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($streams['data']['items'] as $stream): ?>
                            <tr>
                                <td><?php echo esc_html($stream['title'] ?? 'Untitled'); ?></td>
                                <td><?php echo esc_html(ucfirst($stream['platform'] ?? 'Unknown')); ?></td>
                                <td>
                                    <span class="stream-status <?php echo esc_attr(strtolower($stream['status'] ?? 'unknown')); ?>">
                                        <?php echo esc_html(ucfirst($stream['status'] ?? 'Unknown')); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($stream['viewer_count'] ?? 'N/A'); ?></td>
                                <td><?php echo esc_html($stream['started_at'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .social-feed-stream-management {
            margin-top: 20px;
        }
        .stream-actions {
            margin-bottom: 20px;
        }
        .stream-actions .button {
            margin-right: 10px;
        }
        .streams-list {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
        }
        .stream-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .stream-status.live {
            background-color: #d4edda;
            color: #155724;
        }
        .stream-status.offline {
            background-color: #f8d7da;
            color: #721c24;
        }
        .stream-status.unknown {
            background-color: #e2e3e5;
            color: #383d41;
        }
        </style>
        <?php
    }

    /**
     * Render advanced tab content
     */
    private function render_advanced_tab() {
        $async_settings = get_option('social_feed_async_settings', [
            'max_concurrent_requests' => 5,
            'request_timeout' => 15,
            'enable_prefetch' => true,
            'prefetch_confidence_threshold' => 0.6,
            'max_prefetch_items' => 20
        ]);
        
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'social_feed_advanced_settings')) {
            $async_settings = [
                'max_concurrent_requests' => intval($_POST['max_concurrent_requests'] ?? 5),
                'request_timeout' => intval($_POST['request_timeout'] ?? 15),
                'enable_prefetch' => isset($_POST['enable_prefetch']),
                'prefetch_confidence_threshold' => floatval($_POST['prefetch_confidence_threshold'] ?? 0.6),
                'max_prefetch_items' => intval($_POST['max_prefetch_items'] ?? 20)
            ];
            
            update_option('social_feed_async_settings', $async_settings);
            echo '<div class="notice notice-success"><p>Advanced settings saved successfully!</p></div>';
        }
        ?>
        <form method="post" action="">
            <?php wp_nonce_field('social_feed_advanced_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Max Concurrent Requests</th>
                    <td>
                        <input type="number" name="max_concurrent_requests" 
                               value="<?php echo esc_attr($async_settings['max_concurrent_requests']); ?>" 
                               min="1" max="20" class="small-text">
                        <p class="description">Maximum number of simultaneous API requests</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Request Timeout (seconds)</th>
                    <td>
                        <input type="number" name="request_timeout" 
                               value="<?php echo esc_attr($async_settings['request_timeout']); ?>" 
                               min="5" max="60" class="small-text">
                        <p class="description">Timeout for API requests in seconds</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Enable Prefetch</th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_prefetch" 
                                   value="1" <?php checked($async_settings['enable_prefetch']); ?>>
                            Enable intelligent content prefetching
                        </label>
                        <p class="description">Automatically fetch content based on user behavior patterns</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Prefetch Confidence Threshold</th>
                    <td>
                        <input type="number" name="prefetch_confidence_threshold" 
                               value="<?php echo esc_attr($async_settings['prefetch_confidence_threshold']); ?>" 
                               min="0.1" max="1.0" step="0.1" class="small-text">
                        <p class="description">Minimum confidence score (0.1-1.0) required to trigger prefetch</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">Max Prefetch Items</th>
                    <td>
                        <input type="number" name="max_prefetch_items" 
                               value="<?php echo esc_attr($async_settings['max_prefetch_items']); ?>" 
                               min="5" max="100" class="small-text">
                        <p class="description">Maximum number of items to prefetch per session</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Advanced Settings'); ?>
        </form>
        
        <div class="advanced-tools">
            <h3>Advanced Tools</h3>
            <div class="tool-buttons">
                <button type="button" class="button" id="clear-all-data">
                    Clear All Plugin Data
                </button>
                <button type="button" class="button" id="export-settings">
                    Export Settings
                </button>
                <button type="button" class="button" id="import-settings">
                    Import Settings
                </button>
            </div>
            <p class="description">Use these tools with caution. Some actions cannot be undone.</p>
        </div>
        
        <style>
        .advanced-tools {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-top: 20px;
        }
        .tool-buttons {
            margin-top: 15px;
        }
        .tool-buttons .button {
            margin-right: 10px;
        }
        </style>
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
     * Sanitize shortcode settings
     *
     * @param array $input
     * @return array
     */
    public function sanitize_shortcode_settings($input) {
        $sanitized = [];
        
        // Default layout
        $allowed_layouts = ['carousel', 'grid', 'list', 'masonry'];
        $sanitized['default_layout'] = in_array($input['default_layout'] ?? '', $allowed_layouts) 
            ? $input['default_layout'] 
            : 'carousel';
        
        // Items per page
        $sanitized['default_per_page'] = max(1, min(50, intval($input['default_per_page'] ?? 12)));
        
        // Default platforms
        $allowed_platforms = ['youtube', 'tiktok', 'facebook', 'instagram'];
        $sanitized['default_platforms'] = [];
        if (!empty($input['default_platforms']) && is_array($input['default_platforms'])) {
            foreach ($input['default_platforms'] as $platform) {
                if (in_array($platform, $allowed_platforms)) {
                    $sanitized['default_platforms'][] = $platform;
                }
            }
        }
        if (empty($sanitized['default_platforms'])) {
            $sanitized['default_platforms'] = ['youtube'];
        }
        
        // Default content types
        $allowed_types = ['video', 'short'];
        $sanitized['default_types'] = [];
        if (!empty($input['default_types']) && is_array($input['default_types'])) {
            foreach ($input['default_types'] as $type) {
                if (in_array($type, $allowed_types)) {
                    $sanitized['default_types'][] = $type;
                }
            }
        }
        if (empty($sanitized['default_types'])) {
            $sanitized['default_types'] = ['video', 'short'];
        }
        
        // Sort options
        $allowed_sorts = ['date', 'views', 'likes'];
        $sanitized['default_sort'] = in_array($input['default_sort'] ?? '', $allowed_sorts) 
            ? $input['default_sort'] 
            : 'date';
        
        $allowed_orders = ['asc', 'desc'];
        $sanitized['default_order'] = in_array($input['default_order'] ?? '', $allowed_orders) 
            ? $input['default_order'] 
            : 'desc';
        
        // Show filters
        $sanitized['default_show_filters'] = !empty($input['default_show_filters']);
        
        // Carousel settings
        $sanitized['default_autoplay'] = !empty($input['default_autoplay']);
        $sanitized['default_slides_to_show'] = max(1, min(6, intval($input['default_slides_to_show'] ?? 3)));
        
        return $sanitized;
    }

    /**
     * Save shortcode settings
     *
     * @param array $settings
     */
    private function save_shortcode_settings($settings) {
        $sanitized_settings = $this->sanitize_shortcode_settings($settings);
        update_option('social_feed_shortcode_settings', $sanitized_settings);
    }

    /**
     * Render failed notifications list
     */
    private function render_failed_notifications_list() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'social_feed_notifications';
        
        $failed_notifications = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status = 'failed' ORDER BY created_at DESC LIMIT 20"
        );
        
        if (empty($failed_notifications)) {
            echo '<p>No failed notifications found.</p>';
            return;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Message</th>
                    <th>Attempts</th>
                    <th>Last Attempt</th>
                    <th>Error</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($failed_notifications as $notification): ?>
                <tr>
                    <td><?php echo esc_html($notification->type); ?></td>
                    <td><?php echo esc_html(wp_trim_words($notification->message, 10)); ?></td>
                    <td><?php echo esc_html($notification->attempts); ?></td>
                    <td><?php echo esc_html(date('Y-m-d H:i:s', strtotime($notification->updated_at))); ?></td>
                    <td><?php echo esc_html(wp_trim_words($notification->error_message, 8)); ?></td>
                    <td>
                        <button type="button" class="button button-small retry-notification" 
                                data-id="<?php echo esc_attr($notification->id); ?>">
                            Retry
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
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

    /**
     * Render Performance Dashboard page
     */
    public function render_performance_page() {
        try {
            $performance_monitor = new \SocialFeed\Core\PerformanceMonitor();
            $performance_data = $performance_monitor->get_performance_report();
            $system_status = $performance_monitor->get_system_status();
        } catch (\Exception $e) {
            $performance_data = ['error' => $e->getMessage()];
            $system_status = ['status' => 'error', 'message' => $e->getMessage()];
        }
        ?>
        <div class="wrap">
            <h1>Performance Dashboard</h1>
            
            <div class="social-feed-performance-dashboard">
                <div class="performance-cards">
                    <div class="performance-card">
                        <h3>System Status</h3>
                        <div class="status-indicator <?php echo esc_attr($system_status['status'] ?? 'unknown'); ?>">
                            <?php echo esc_html(ucfirst($system_status['status'] ?? 'Unknown')); ?>
                        </div>
                        <?php if (isset($system_status['message'])): ?>
                            <p><?php echo esc_html($system_status['message']); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="performance-card">
                        <h3>API Response Times</h3>
                        <?php if (isset($performance_data['api_response_times'])): ?>
                            <?php foreach ($performance_data['api_response_times'] as $platform => $time): ?>
                                <div class="metric-row">
                                    <span><?php echo esc_html(ucfirst($platform)); ?>:</span>
                                    <span><?php echo esc_html($time); ?>ms</span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p>No response time data available</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="performance-card">
                        <h3>Memory Usage</h3>
                        <div class="metric-row">
                            <span>Current:</span>
                            <span><?php echo esc_html(size_format(memory_get_usage(true))); ?></span>
                        </div>
                        <div class="metric-row">
                            <span>Peak:</span>
                            <span><?php echo esc_html(size_format(memory_get_peak_usage(true))); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="performance-actions">
                    <button type="button" class="button button-secondary" id="reset-performance-data">
                        Reset Performance Data
                    </button>
                </div>
            </div>
        </div>
        
        <style>
        .social-feed-performance-dashboard {
            margin-top: 20px;
        }
        .performance-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .performance-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
        }
        .performance-card h3 {
            margin-top: 0;
            margin-bottom: 15px;
        }
        .status-indicator {
            padding: 8px 12px;
            border-radius: 4px;
            font-weight: bold;
            text-align: center;
        }
        .status-indicator.healthy { background: #d4edda; color: #155724; }
        .status-indicator.warning { background: #fff3cd; color: #856404; }
        .status-indicator.error { background: #f8d7da; color: #721c24; }
        .metric-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#reset-performance-data').on('click', function() {
                if (confirm('Are you sure you want to reset all performance data?')) {
                    $.post(ajaxurl, {
                        action: 'social_feed_reset_performance_data',
                        nonce: '<?php echo wp_create_nonce('reset_performance_data'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render Cache Management page
     */
    public function render_cache_page() {
        try {
            $cache_manager = new \SocialFeed\Core\CacheManager('global');
            $cache_stats = $cache_manager->get_stats();
        } catch (\Exception $e) {
            $cache_stats = ['error' => $e->getMessage()];
        }
        ?>
        <div class="wrap">
            <h1>Cache Management</h1>
            
            <div class="social-feed-cache-management">
                <div class="cache-stats">
                    <h2>Cache Statistics</h2>
                    <?php if (isset($cache_stats['error'])): ?>
                        <div class="notice notice-error">
                            <p>Error loading cache stats: <?php echo esc_html($cache_stats['error']); ?></p>
                        </div>
                    <?php else: ?>
                        <table class="widefat">
                            <tbody>
                                <tr>
                                    <td><strong>Total Cached Items:</strong></td>
                                    <td><?php echo esc_html($cache_stats['total_items'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Cache Hit Rate:</strong></td>
                                    <td><?php echo esc_html(($cache_stats['hit_rate'] ?? 0) . '%'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Cache Size:</strong></td>
                                    <td><?php echo esc_html($cache_stats['cache_size'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Last Updated:</strong></td>
                                    <td><?php echo esc_html($cache_stats['last_updated'] ?? 'N/A'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <div class="cache-actions">
                    <h2>Cache Actions</h2>
                    <p>Manage your cache to optimize performance and free up storage space.</p>
                    
                    <div class="cache-buttons">
                        <button type="button" class="button button-secondary" id="clear-all-cache">
                            Clear All Cache
                        </button>
                        <button type="button" class="button button-primary" id="optimize-cache">
                            Optimize Cache
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .social-feed-cache-management {
            margin-top: 20px;
        }
        .cache-stats, .cache-actions {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .cache-buttons {
            margin-top: 15px;
        }
        .cache-buttons .button {
            margin-right: 10px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#clear-all-cache').on('click', function() {
                if (confirm('Are you sure you want to clear all cache? This may temporarily slow down your site.')) {
                    $.post(ajaxurl, {
                        action: 'social_feed_clear_all_cache',
                        nonce: '<?php echo wp_create_nonce('clear_all_cache'); ?>'
                    }, function(response) {
                        if (response.success) {
                            alert('Cache cleared successfully!');
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    });
                }
            });
            
            $('#optimize-cache').on('click', function() {
                $.post(ajaxurl, {
                    action: 'social_feed_optimize_cache',
                    nonce: '<?php echo wp_create_nonce('optimize_cache'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Cache optimized successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Stream Management page
     */
    public function render_streams_page() {
        try {
            $stream_service = new \SocialFeed\Services\StreamService();
            $streams = $stream_service->get_streams(['youtube', 'tiktok'], 'live', 1, 20);
        } catch (\Exception $e) {
            $streams = ['error' => $e->getMessage()];
        }
        ?>
        <div class="wrap">
            <h1>Stream Management</h1>
            
            <div class="social-feed-stream-management">
                <div class="stream-actions">
                    <button type="button" class="button button-primary" id="refresh-streams">
                        Refresh Stream Status
                    </button>
                </div>
                
                <div class="streams-list">
                    <h2>Live Streams</h2>
                    <?php if (isset($streams['error'])): ?>
                        <div class="notice notice-error">
                            <p>Error loading streams: <?php echo esc_html($streams['error']); ?></p>
                        </div>
                    <?php elseif (empty($streams['streams'])): ?>
                        <p>No live streams found.</p>
                    <?php else: ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Platform</th>
                                    <th>Status</th>
                                    <th>Viewers</th>
                                    <th>Started</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($streams['streams'] as $stream): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc_html($stream['title'] ?? 'Untitled'); ?></strong>
                                            <?php if (!empty($stream['stream_url'])): ?>
                                                <br><a href="<?php echo esc_url($stream['stream_url']); ?>" target="_blank">View Stream</a>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html(ucfirst($stream['platform'] ?? 'Unknown')); ?></td>
                                        <td>
                                            <span class="stream-status <?php echo esc_attr($stream['status'] ?? 'unknown'); ?>">
                                                <?php echo esc_html(ucfirst($stream['status'] ?? 'Unknown')); ?>
                                            </span>
                                        </td>
                                        <td><?php echo esc_html($stream['viewer_count'] ?? 'N/A'); ?></td>
                                        <td><?php echo esc_html($stream['started_at'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <style>
        .social-feed-stream-management {
            margin-top: 20px;
        }
        .stream-actions {
            margin-bottom: 20px;
        }
        .streams-list {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
        }
        .stream-status {
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .stream-status.live { background: #d4edda; color: #155724; }
        .stream-status.scheduled { background: #fff3cd; color: #856404; }
        .stream-status.ended { background: #f8d7da; color: #721c24; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#refresh-streams').on('click', function() {
                $.post(ajaxurl, {
                    action: 'social_feed_refresh_streams',
                    nonce: '<?php echo wp_create_nonce('refresh_streams'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Notifications page
     */
    public function render_notifications_page() {
        try {
            $notification_handler = new \SocialFeed\Core\NotificationHandler();
            $failed_notifications = get_option('social_feed_failed_notifications', []);
        } catch (\Exception $e) {
            $failed_notifications = [];
        }
        ?>
        <div class="wrap">
            <h1>Notifications</h1>
            
            <div class="social-feed-notifications">
                <div class="notification-actions">
                    <h2>Notification Actions</h2>
                    <div class="notification-buttons">
                        <button type="button" class="button button-primary" id="test-notification">
                            Send Test Notification
                        </button>
                        <button type="button" class="button button-secondary" id="retry-failed-notifications">
                            Retry Failed Notifications (<?php echo count($failed_notifications); ?>)
                        </button>
                    </div>
                </div>
                
                <div class="failed-notifications">
                    <h2>Failed Notifications</h2>
                    <?php if (empty($failed_notifications)): ?>
                        <p>No failed notifications.</p>
                    <?php else: ?>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Attempts</th>
                                    <th>First Failed</th>
                                    <th>Next Retry</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($failed_notifications as $notification): ?>
                                    <tr>
                                        <td><?php echo esc_html($notification['type'] ?? 'Unknown'); ?></td>
                                        <td><?php echo esc_html($notification['attempts'] ?? 0); ?></td>
                                        <td><?php echo esc_html($notification['first_failed_at'] ?? 'N/A'); ?></td>
                                        <td><?php echo esc_html($notification['next_retry_at'] ?? 'N/A'); ?></td>
                                        <td><?php echo esc_html($notification['error'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <style>
        .social-feed-notifications {
            margin-top: 20px;
        }
        .notification-actions, .failed-notifications {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .notification-buttons {
            margin-top: 15px;
        }
        .notification-buttons .button {
            margin-right: 10px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#test-notification').on('click', function() {
                $.post(ajaxurl, {
                    action: 'social_feed_test_notification',
                    nonce: '<?php echo wp_create_nonce('test_notification'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Test notification sent successfully!');
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
            
            $('#retry-failed-notifications').on('click', function() {
                $.post(ajaxurl, {
                    action: 'social_feed_retry_failed_notifications',
                    nonce: '<?php echo wp_create_nonce('retry_failed_notifications'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Failed notifications retry initiated!');
                        location.reload();
                    } else {
                        alert('Error: ' + response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Advanced Settings page
     */
    public function render_advanced_page() {
        $async_settings = get_option('social_feed_async_settings', [
            'max_concurrent_requests' => 5,
            'request_timeout' => 15,
            'enable_prefetch' => true,
            'prefetch_confidence_threshold' => 0.6,
            'max_prefetch_items' => 20
        ]);
        
        if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'social_feed_advanced_settings')) {
            $async_settings = [
                'max_concurrent_requests' => intval($_POST['max_concurrent_requests'] ?? 5),
                'request_timeout' => intval($_POST['request_timeout'] ?? 15),
                'enable_prefetch' => isset($_POST['enable_prefetch']),
                'prefetch_confidence_threshold' => floatval($_POST['prefetch_confidence_threshold'] ?? 0.6),
                'max_prefetch_items' => intval($_POST['max_prefetch_items'] ?? 20)
            ];
            
            update_option('social_feed_async_settings', $async_settings);
            echo '<div class="notice notice-success"><p>Advanced settings saved successfully!</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Advanced Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('social_feed_advanced_settings'); ?>
                
                <div class="social-feed-advanced-settings">
                    <div class="settings-section">
                        <h2>Async Feed Service Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Max Concurrent Requests</th>
                                <td>
                                    <input type="number" name="max_concurrent_requests" 
                                           value="<?php echo esc_attr($async_settings['max_concurrent_requests']); ?>" 
                                           min="1" max="10" />
                                    <p class="description">Maximum number of simultaneous API requests (1-10)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Request Timeout</th>
                                <td>
                                    <input type="number" name="request_timeout" 
                                           value="<?php echo esc_attr($async_settings['request_timeout']); ?>" 
                                           min="10" max="120" />
                                    <p class="description">Request timeout in seconds (10-120)</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <div class="settings-section">
                        <h2>Predictive Prefetch Settings</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Enable Prefetch</th>
                                <td>
                                    <input type="checkbox" name="enable_prefetch" 
                                           <?php checked($async_settings['enable_prefetch']); ?> />
                                    <p class="description">Enable intelligent content prefetching</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Confidence Threshold</th>
                                <td>
                                    <input type="number" name="prefetch_confidence_threshold" 
                                           value="<?php echo esc_attr($async_settings['prefetch_confidence_threshold']); ?>" 
                                           min="0.1" max="1.0" step="0.1" />
                                    <p class="description">Minimum confidence level for prefetching (0.1-1.0)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Max Prefetch Items</th>
                                <td>
                                    <input type="number" name="max_prefetch_items" 
                                           value="<?php echo esc_attr($async_settings['max_prefetch_items']); ?>" 
                                           min="5" max="50" />
                                    <p class="description">Maximum items to prefetch (5-50)</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php submit_button('Save Advanced Settings'); ?>
            </form>
        </div>
        
        <style>
        .social-feed-advanced-settings {
            margin-top: 20px;
        }
        .settings-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .settings-section h2 {
            margin-top: 0;
        }
        </style>
        <?php
    }

    // AJAX Handlers for new features

    /**
     * Handle clear all cache AJAX request
     */
    public function handle_clear_all_cache() {
        if (!check_ajax_referer('clear_all_cache', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            $cache_manager = new \SocialFeed\Core\CacheManager();
            $cache_manager->clear_all_cache();
            wp_send_json_success(['message' => 'All cache cleared successfully']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error clearing cache: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle optimize cache AJAX request
     */
    public function handle_optimize_cache() {
        if (!check_ajax_referer('optimize_cache', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            $cache_manager = new \SocialFeed\Core\CacheManager();
            $cache_manager->optimize_cache();
            wp_send_json_success(['message' => 'Cache optimized successfully']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error optimizing cache: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle refresh streams AJAX request
     */
    public function handle_refresh_streams() {
        if (!check_ajax_referer('refresh_streams', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            $stream_service = new \SocialFeed\Services\StreamService();
            $stream_service->refresh_stream_statuses();
            wp_send_json_success(['message' => 'Stream statuses refreshed successfully']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error refreshing streams: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle test notification AJAX request
     */
    public function handle_test_notification() {
        if (!check_ajax_referer('test_notification', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            // Use the Notifications class test method instead of NotificationHandler
            $notifications = \SocialFeed\Core\Notifications::get_instance();
            $notifications->init();
            
            $results = $notifications->test_notifications('video');
            
            if ($results['success']) {
                $message = 'Test notification completed successfully. Results: ' . implode('; ', $results['messages']);
                wp_send_json_success(['message' => $message]);
            } else {
                $message = 'Test notification failed: ' . implode('; ', $results['messages']);
                wp_send_json_error(['message' => $message]);
            }
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error sending test notification: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle retry failed notifications AJAX request
     */
    public function handle_retry_failed_notifications() {
        if (!check_ajax_referer('retry_failed_notifications', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            $notification_handler = new \SocialFeed\Core\NotificationHandler();
            $notification_handler->retry_failed_notifications();
            wp_send_json_success(['message' => 'Failed notifications retry initiated']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error retrying notifications: ' . $e->getMessage()]);
        }
    }

    /**
     * Handle reset performance data AJAX request
     */
    public function handle_reset_performance_data() {
        if (!check_ajax_referer('reset_performance_data', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token']);
            return;
        }

        try {
            $performance_monitor = new \SocialFeed\Core\PerformanceMonitor();
            // Reset performance data (this would need to be implemented in PerformanceMonitor)
            delete_option('social_feed_performance_data');
            wp_send_json_success(['message' => 'Performance data reset successfully']);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Error resetting performance data: ' . $e->getMessage()]);
        }
    }
}