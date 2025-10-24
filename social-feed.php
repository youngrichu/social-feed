<?php
/**
 * Plugin Name: Social Media Feed
 * Plugin URI: https://example.com/social-feed
 * Description: A WordPress plugin that aggregates and displays social media content from multiple platforms with REST API support.
 * Version: 1.1
 * Author: Habtamu
 * Author URI: https://github.com/youngrichu
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: social-feed
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SOCIAL_FEED_VERSION', '1.1');
define('SOCIAL_FEED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SOCIAL_FEED_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check if Church App Notifications plugin is active
if (!function_exists('is_plugin_active')) {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

// Define possible paths for the Church App Notifications plugin
$possible_plugin_paths = [
    'church-app-notifications/church-app-notifications.php',
    'church-app-notifications/church-app-notifications/church-app-notifications.php'
];

$church_app_plugin_file = null;
$church_app_plugin_path = null;
$expo_push_class_path = null;

// Find the correct plugin path
foreach ($possible_plugin_paths as $plugin_path) {
    if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_path)) {
        $church_app_plugin_file = $plugin_path;
        $church_app_plugin_path = WP_PLUGIN_DIR . '/' . $plugin_path;
        
        // Check possible locations for the Expo Push class
        $possible_class_paths = [
            dirname($church_app_plugin_path) . '/includes/class-expo-push.php',
            dirname(dirname($church_app_plugin_path)) . '/includes/class-expo-push.php'
        ];
        
        foreach ($possible_class_paths as $class_path) {
            if (file_exists($class_path)) {
                $expo_push_class_path = $class_path;
                break;
            }
        }
        break;
    }
}

// Store the paths as constants for use throughout the plugin
define('SOCIAL_FEED_CHURCH_APP_AVAILABLE', ($church_app_plugin_file && is_plugin_active($church_app_plugin_file)));
if (SOCIAL_FEED_CHURCH_APP_AVAILABLE) {
    define('SOCIAL_FEED_CHURCH_APP_PATH', $church_app_plugin_path);
    define('SOCIAL_FEED_EXPO_PUSH_PATH', $expo_push_class_path);
}

// Include required files - Core first
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Core/Plugin.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Core/Cache.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Core/Notifications.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Core/Activator.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Core/Deactivator.php';

// Include API
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/API/RestAPI.php';

// Include Frontend and Admin
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Admin/Admin.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Frontend/Frontend.php';

// Include Platform files in correct order - Interface first
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Platforms/PlatformInterface.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Platforms/AbstractPlatform.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Platforms/PlatformFactory.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Platforms/YouTube.php';
require_once SOCIAL_FEED_PLUGIN_DIR . 'includes/Platforms/TikTok.php';

// Update Church App Notifications path
$church_app_base = WP_PLUGIN_DIR . '/church-app-notifications';
$expo_push_path = $church_app_base . '/includes/class-expo-push.php';

// Include Church App Notifications plugin files if available
if (SOCIAL_FEED_CHURCH_APP_AVAILABLE && file_exists($expo_push_path)) {
    require_once SOCIAL_FEED_CHURCH_APP_PATH;
    require_once $expo_push_path;
} else {
    // Show admin notice only if we're in the admin area
    if (is_admin()) {
        add_action('admin_notices', function() {
            ?>
            <div class="notice notice-warning">
                <p><?php _e('Social Feed plugin: Push notifications are disabled because the Church App Notifications plugin is not installed or activated.', 'social-feed'); ?></p>
            </div>
            <?php
        });
    }
}

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'SocialFeed\\';
    $base_dir = SOCIAL_FEED_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
function social_feed_init() {
    // Load text domain for internationalization
    load_plugin_textdomain('social-feed', false, dirname(plugin_basename(__FILE__)) . '/languages');
    
    // Initialize plugin components
    if (class_exists('SocialFeed\\Core\\Plugin')) {
        $plugin = new SocialFeed\Core\Plugin();
        $plugin->init();
    }
}
add_action('plugins_loaded', 'social_feed_init');

// Activation hook
register_activation_hook(__FILE__, function() {
    $activator = new SocialFeed\Core\Activator();
    $activator->activate();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    $deactivator = new SocialFeed\Core\Deactivator();
    $deactivator->deactivate();
});

// Register CLI commands if WP-CLI is available
if (defined('WP_CLI') && WP_CLI) {
    require_once plugin_dir_path(__FILE__) . 'includes/CLI/NotificationsCommand.php';
}