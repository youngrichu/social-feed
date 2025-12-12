<?php
/**
 * Plugin Name: Social Feed
 * Plugin URI: https://github.com/youngrichu/social-feed
 * Description: A comprehensive social media feed aggregator optimized for production environments.
 * Version: 1.1.2
 * Author: Habtamu
 * License: GPL v2 or later
 * Text Domain: social-feed
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check minimum PHP version
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo __('Social Feed plugin requires PHP 7.4 or higher. You are running PHP ' . PHP_VERSION, 'social-feed');
        echo '</p></div>';
    });
    return;
}

// Check minimum WordPress version
global $wp_version;
if (version_compare($wp_version, '5.0', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo __('Social Feed plugin requires WordPress 5.0 or higher.', 'social-feed');
        echo '</p></div>';
    });
    return;
}

// Define plugin constants with fallbacks
if (!defined('SOCIAL_FEED_VERSION')) {
    define('SOCIAL_FEED_VERSION', '1.1.2');
}

// Ensure WordPress functions are available
if (!function_exists('plugin_dir_path') || !function_exists('plugin_dir_url')) {
    return;
}

if (!defined('SOCIAL_FEED_PLUGIN_DIR')) {
    define('SOCIAL_FEED_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('SOCIAL_FEED_PLUGIN_URL')) {
    define('SOCIAL_FEED_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// Check for Church App Notifications plugin with proper function check
if (function_exists('is_plugin_active')) {
    define('SOCIAL_FEED_CHURCH_APP_AVAILABLE', is_plugin_active('church-app-notifications/church-app-notifications.php'));
} else {
    define('SOCIAL_FEED_CHURCH_APP_AVAILABLE', false);
}

// Define Church App Notifications paths if plugin is available
if (SOCIAL_FEED_CHURCH_APP_AVAILABLE) {
    // Define the expected path for Expo Push class
    if (!defined('SOCIAL_FEED_EXPO_PUSH_PATH')) {
        define('SOCIAL_FEED_EXPO_PUSH_PATH', WP_PLUGIN_DIR . '/church-app-notifications/includes/class-expo-push.php');
    }
} else {
    // Define as empty if not available
    if (!defined('SOCIAL_FEED_EXPO_PUSH_PATH')) {
        define('SOCIAL_FEED_EXPO_PUSH_PATH', '');
    }
}

// Enhanced file inclusion with error handling
function social_feed_include_file($file_path)
{
    $full_path = SOCIAL_FEED_PLUGIN_DIR . $file_path;
    if (file_exists($full_path) && is_readable($full_path)) {
        require_once $full_path;
        return true;
    }
    return false;
}

// Include core files in dependency order
$core_files = [
    // Base classes first
    'includes/Core/Cache.php',
    'includes/Core/CacheManager.php',

    // Platform interfaces
    'includes/Platforms/PlatformInterface.php',
    'includes/Platforms/AbstractPlatform.php',
    'includes/Platforms/PlatformFactory.php',

    // Core functionality
    'includes/Core/ContentProcessor.php',
    'includes/Core/RequestOptimizer.php',
    'includes/Core/PerformanceMonitor.php',
    'includes/Core/QuotaManager.php',
    'includes/Core/NotificationHandler.php',
    'includes/Core/Notifications.php',

    // Services
    'includes/Services/FeedService.php',
    'includes/Services/AsyncFeedService.php',
    'includes/Services/PredictivePrefetchService.php',
    'includes/Services/StreamService.php',

    // Main classes
    'includes/Core/Plugin.php',
    'includes/Core/Activator.php',
    'includes/Core/Deactivator.php',

    // Components
    'includes/Frontend/Frontend.php',
    'includes/Admin/Admin.php',
    'includes/API/RestAPI.php',

    // Platforms
    'includes/Platforms/YouTube.php',
    'includes/Platforms/TikTok.php',
];

// Include files with error tracking
$missing_files = [];
foreach ($core_files as $file) {
    if (!social_feed_include_file($file)) {
        $missing_files[] = $file;
    }
}

// Show admin notice for missing files
if (!empty($missing_files) && is_admin()) {
    add_action('admin_notices', function () use ($missing_files) {
        echo '<div class="notice notice-error"><p>';
        echo __('Social Feed plugin: Missing required files: ', 'social-feed') . implode(', ', $missing_files);
        echo '</p></div>';
    });
}

// Enhanced autoloader with better error handling
if (!function_exists('social_feed_autoloader')) {
    function social_feed_autoloader($class)
    {
        $prefix = 'SocialFeed\\';
        $base_dir = SOCIAL_FEED_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file) && is_readable($file)) {
            require_once $file;
        }
    }

    spl_autoload_register('social_feed_autoloader');
}

// Production-safe activation hook
register_activation_hook(__FILE__, 'social_feed_activate');

function social_feed_activate()
{
    // Ensure we're in a WordPress context
    if (!function_exists('get_option') || !function_exists('update_option')) {
        wp_die(__('WordPress functions not available during activation.', 'social-feed'));
    }

    // Check if required WordPress functions exist
    if (!function_exists('dbDelta')) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    }

    // Verify dbDelta is now available
    if (!function_exists('dbDelta')) {
        wp_die(__('Database upgrade functions not available.', 'social-feed'));
    }

    // Check if Activator class exists
    if (!class_exists('SocialFeed\\Core\\Activator')) {
        wp_die(__('Plugin activation class not found. Please check file permissions and plugin integrity.', 'social-feed'));
    }

    try {
        $activator = new SocialFeed\Core\Activator();
        $activator->activate();

        // Set activation flag
        update_option('social_feed_activated', true);

    } catch (Exception $e) {
        wp_die(__('Plugin activation failed: ', 'social-feed') . $e->getMessage());
    } catch (Error $e) {
        wp_die(__('Plugin activation error: ', 'social-feed') . $e->getMessage());
    }
}

// Initialize the plugin with comprehensive error handling
function social_feed_init()
{
    // Check if plugin was properly activated
    if (!get_option('social_feed_activated', false)) {
        return;
    }

    try {
        // Load text domain for internationalization
        load_plugin_textdomain('social-feed', false, dirname(plugin_basename(__FILE__)) . '/languages');

        // Initialize plugin components only if class exists
        if (class_exists('SocialFeed\\Core\\Plugin')) {
            $plugin = new SocialFeed\Core\Plugin();
            $plugin->init();
        } else {
            // Log error but don't break the site
            error_log('Social Feed: Plugin class not found during initialization');

            if (is_admin()) {
                add_action('admin_notices', function () {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Social Feed plugin: Core plugin class not found. Please reinstall the plugin.', 'social-feed');
                    echo '</p></div>';
                });
            }
        }
    } catch (Exception $e) {
        error_log('Social Feed initialization error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo __('Social Feed plugin error: ', 'social-feed') . esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    } catch (Error $e) {
        error_log('Social Feed fatal error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p>';
                echo __('Social Feed plugin encountered a fatal error. Please check the error logs.', 'social-feed');
                echo '</p></div>';
            });
        }
    }
}

// Hook into WordPress with proper timing - use init instead of plugins_loaded
add_action('init', 'social_feed_init', 10);