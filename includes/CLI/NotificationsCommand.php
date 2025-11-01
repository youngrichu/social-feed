<?php
namespace SocialFeed\CLI;

use WP_CLI;
use SocialFeed\Core\Notifications;

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Manages Social Feed notifications
 */
class NotificationsCommand {
    /**
     * Test the notifications system
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : Type of notification to test (video, live, or both)
     * ---
     * default: both
     * options:
     *   - video
     *   - live
     *   - both
     * ---
     *
     * ## EXAMPLES
     *
     *     # Test both video and live stream notifications
     *     $ wp social-feed test-notifications
     *
     *     # Test only video notifications
     *     $ wp social-feed test-notifications --type=video
     *
     *     # Test only live stream notifications
     *     $ wp social-feed test-notifications --type=live
     */
    public function test_notifications($args, $assoc_args) {
        $type = $assoc_args['type'] ?? 'both';
        
        WP_CLI::log('Starting comprehensive notification test...');
        
        try {
            // Initialize notifications system
            $notifications = Notifications::get_instance();
            $notifications->init();
            
            $results = $notifications->test_notifications($type);
            
            if ($results['success']) {
                WP_CLI::log("\nTest Results:");
                foreach ($results['messages'] as $message) {
                    if (strpos($message, '✓') === 0) {
                        WP_CLI::success($message);
                    } elseif (strpos($message, '✗') === 0) {
                        WP_CLI::warning($message);
                    } else {
                        WP_CLI::log($message);
                    }
                }
                WP_CLI::success('All tests completed.');
            } else {
                foreach ($results['messages'] as $message) {
                    WP_CLI::error($message);
                }
            }
        } catch (\Exception $e) {
            WP_CLI::error('Error during notification test: ' . $e->getMessage());
        }
    }
}

// Register the command
WP_CLI::add_command('social-feed notifications test', [new NotificationsCommand(), 'test_notifications']);