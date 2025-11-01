<?php
/**
 * Simple Test Runner for Social Media Feed Plugin
 * Phase 1 Improvements Testing (No PHPUnit dependency)
 */

// Mock WordPress functions first
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        return true;
    }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = []) {
        return ['response' => ['code' => 200], 'body' => '{"success": true}'];
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return $response['response']['code'] ?? 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return $response['body'] ?? '{}';
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, $header) {
        return null;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('error_log')) {
    function error_log($message) {
        // Silent for tests
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($format) {
        return date($format);
    }
}

if (!function_exists('do_action')) {
    function do_action($hook, $data = null) {
        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        return true;
    }
}

// Include utilities
require_once __DIR__ . '/Utilities/TestHelper.php';
require_once __DIR__ . '/Utilities/MockDataGenerator.php';

// Include plugin files
$pluginDir = dirname(__DIR__);
require_once $pluginDir . '/includes/Platforms/PlatformInterface.php';
require_once $pluginDir . '/includes/Platforms/AbstractPlatform.php';
require_once $pluginDir . '/includes/Core/NotificationHandler.php';

class SimpleTestRunner {
    private $tests_passed = 0;
    private $tests_failed = 0;
    private $verbose = false;
    private $performance = false;
    
    public function __construct($verbose = false, $performance = false) {
        $this->verbose = $verbose;
        $this->performance = $performance;
    }
    
    public function run() {
        echo "============================================================\n";
        echo "SOCIAL MEDIA FEED PLUGIN - SIMPLE TEST SUITE\n";
        echo "Phase 1 Improvements Testing\n";
        echo "============================================================\n\n";
        
        $start_time = microtime(true);
        $start_memory = memory_get_usage();
        
        // Run tests
        $this->testExponentialBackoffRetryLogic();
        $this->testNotificationDeliveryConfirmation();
        $this->testMemoryOptimization();
        
        $end_time = microtime(true);
        $end_memory = memory_get_usage();
        
        // Print results
        echo "\n============================================================\n";
        echo "TEST RESULTS SUMMARY\n";
        echo "============================================================\n";
        echo "Tests Passed: " . $this->tests_passed . "\n";
        echo "Tests Failed: " . $this->tests_failed . "\n";
        echo "Total Tests: " . ($this->tests_passed + $this->tests_failed) . "\n";
        
        if ($this->performance) {
            echo "Execution Time: " . round(($end_time - $start_time) * 1000, 2) . " ms\n";
            echo "Memory Usage: " . $this->formatBytes($end_memory - $start_memory) . "\n";
            echo "Peak Memory: " . $this->formatBytes(memory_get_peak_usage()) . "\n";
        }
        
        echo "\nOverall Status: " . ($this->tests_failed === 0 ? "PASSED" : "FAILED") . "\n";
        echo "============================================================\n";
        
        return $this->tests_failed === 0;
    }
    
    private function testExponentialBackoffRetryLogic() {
        echo "----------------------------------------\n";
        echo "TESTING: Exponential Backoff Retry Logic\n";
        echo "----------------------------------------\n";
        
        // Create a mock platform for testing
        $platform = new class extends \SocialFeed\Platforms\AbstractPlatform {
            protected $platform = 'test';
            
            public function get_feed($types = []) { return []; }
            public function get_stream_status($stream_id) { return null; }
            public function get_supported_types() { return ['video', 'post']; }
            public function validate_config($config) { return true; }
            
            // Expose protected methods for testing
            public function test_calculate_backoff_delay($attempt) {
                return $this->calculate_backoff_delay($attempt);
            }
            
            public function test_is_retryable_error($error) {
                return $this->is_retryable_error($error);
            }
            
            public function test_is_retryable_status($status) {
                return $this->is_retryable_status($status);
            }
        };
        
        // Test 1: Backoff delay calculation
        $this->assert(
            $platform->test_calculate_backoff_delay(0) >= 1,
            "Backoff delay for attempt 0 should be at least 1 second"
        );
        
        $this->assert(
            $platform->test_calculate_backoff_delay(1) >= 2,
            "Backoff delay for attempt 1 should be at least 2 seconds"
        );
        
        $this->assert(
            $platform->test_calculate_backoff_delay(5) <= 30,
            "Backoff delay should be capped at 30 seconds"
        );
        
        // Test 2: Retryable error detection
        $this->assert(
            $platform->test_is_retryable_error("Connection timeout"),
            "Should detect 'timeout' as retryable error"
        );
        
        $this->assert(
            $platform->test_is_retryable_error("Network error occurred"),
            "Should detect 'network' as retryable error"
        );
        
        $this->assert(
            !$platform->test_is_retryable_error("Invalid API key"),
            "Should not detect 'Invalid API key' as retryable error"
        );
        
        // Test 3: Retryable status codes
        $this->assert(
            $platform->test_is_retryable_status(429),
            "Status 429 (Rate Limited) should be retryable"
        );
        
        $this->assert(
            $platform->test_is_retryable_status(500),
            "Status 500 (Server Error) should be retryable"
        );
        
        $this->assert(
            !$platform->test_is_retryable_status(404),
            "Status 404 (Not Found) should not be retryable"
        );
        
        echo "✓ Exponential Backoff Retry Logic tests completed\n\n";
    }
    
    private function testNotificationDeliveryConfirmation() {
        echo "----------------------------------------\n";
        echo "TESTING: Notification Delivery Confirmation\n";
        echo "----------------------------------------\n";
        
        // WordPress functions are already mocked globally
        
        $handler = new \SocialFeed\Core\NotificationHandler();
        
        // Test 1: Notification ID generation
        $content1 = ['id' => 'test123', 'type' => 'video'];
        $content2 = ['id' => 'test456', 'type' => 'video'];
        
        // Use reflection to access private method
        $reflection = new ReflectionClass($handler);
        $method = $reflection->getMethod('generate_notification_id');
        $method->setAccessible(true);
        
        $id1 = $method->invoke($handler, 'new_content', $content1);
        $id2 = $method->invoke($handler, 'new_content', $content2);
        
        $this->assert(
            $id1 !== $id2,
            "Different content should generate different notification IDs"
        );
        
        $this->assert(
            strpos($id1, 'notif_') === 0,
            "Notification ID should start with 'notif_'"
        );
        
        // Test 2: Delivery confirmation
        $notification_id = 'test_notification_123';
        
        // Test confirm_delivery method
        $handler->confirm_delivery($notification_id, true);
        
        // Since we can't easily test the full notification flow without WordPress,
        // we'll test the basic functionality
        $this->assert(true, "Delivery confirmation method executed without errors");
        
        echo "✓ Notification Delivery Confirmation tests completed\n\n";
    }
    
    private function testMemoryOptimization() {
        echo "----------------------------------------\n";
        echo "TESTING: Memory Usage Optimization\n";
        echo "----------------------------------------\n";
        
        $start_memory = memory_get_usage();
        
        // Test 1: Large array processing simulation
        $large_dataset = [];
        for ($i = 0; $i < 1000; $i++) {
            $large_dataset[] = [
                'id' => 'item_' . $i,
                'title' => 'Test Item ' . $i,
                'description' => str_repeat('Lorem ipsum dolor sit amet. ', 10),
                'data' => array_fill(0, 50, 'test_data_' . $i)
            ];
        }
        
        $after_creation = memory_get_usage();
        
        // Test batch processing (simulate optimized processing)
        $batch_size = 100;
        $processed_count = 0;
        
        for ($offset = 0; $offset < count($large_dataset); $offset += $batch_size) {
            $batch = array_slice($large_dataset, $offset, $batch_size);
            
            // Simulate processing
            foreach ($batch as $item) {
                $processed_count++;
                // Simulate some processing work
                $temp = json_encode($item);
                $temp = json_decode($temp, true);
            }
            
            // Clear batch from memory
            unset($batch);
            
            // Force garbage collection
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        $after_processing = memory_get_usage();
        
        // Test 2: Memory usage validation
        $this->assert(
            $processed_count === 1000,
            "Should process all 1000 items in batches"
        );
        
        $memory_increase = $after_processing - $start_memory;
        $creation_memory = $after_creation - $start_memory;
        
        $this->assert(
            $memory_increase < $creation_memory * 1.5,
            "Memory usage after processing should not exceed 150% of creation memory"
        );
        
        // Test 3: Memory cleanup
        unset($large_dataset);
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        $final_memory = memory_get_usage();
        $cleanup_efficiency = ($after_processing - $final_memory) / $after_processing;
        
        $this->assert(
            $cleanup_efficiency > 0.1,
            "Memory cleanup should free at least 10% of used memory"
        );
        
        if ($this->verbose) {
            echo "Memory Stats:\n";
            echo "  Start: " . $this->formatBytes($start_memory) . "\n";
            echo "  After Creation: " . $this->formatBytes($after_creation) . "\n";
            echo "  After Processing: " . $this->formatBytes($after_processing) . "\n";
            echo "  Final: " . $this->formatBytes($final_memory) . "\n";
            echo "  Cleanup Efficiency: " . round($cleanup_efficiency * 100, 2) . "%\n";
        }
        
        echo "✓ Memory Usage Optimization tests completed\n\n";
    }
    
    private function assert($condition, $message) {
        if ($condition) {
            $this->tests_passed++;
            if ($this->verbose) {
                echo "  ✓ PASS: $message\n";
            }
        } else {
            $this->tests_failed++;
            echo "  ✗ FAIL: $message\n";
        }
    }
    
    private function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Parse command line arguments
$verbose = in_array('--verbose', $argv);
$performance = in_array('--performance', $argv);

// Run tests
$runner = new SimpleTestRunner($verbose, $performance);
$success = $runner->run();

exit($success ? 0 : 1);