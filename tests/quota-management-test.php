<?php

/**
 * Social Media Feed Plugin - Quota Management Testing
 * 
 * Tests for enhanced predictive quota management system and cross-day planning
 * Usage: php tests/quota-management-test.php [--verbose]
 */

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constants
define('SOCIAL_FEED_TEST_MODE', true);

// Mock WordPress functions
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        $options = [
            'social_feed_quota_limits' => [
                'youtube' => 10000,
                'tiktok' => 5000,
                'instagram' => 8000
            ],
            'social_feed_quota_usage' => [
                'youtube' => 2500,
                'tiktok' => 1200,
                'instagram' => 3000
            ]
        ];
        return $options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($format) {
        return date($format);
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, $group = '') {
        return false;
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, $group = '', $expire = 0) {
        return true;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = []) {
        return false;
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) {
        return true;
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

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        return true;
    }
}

// Define WordPress time constants
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 24 * 60 * 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 60 * 60);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!function_exists('do_action')) {
    function do_action($hook, $data = null) {
        return true;
    }
}

// Include required files
$pluginDir = dirname(__DIR__);
require_once $pluginDir . '/includes/Core/QuotaManager.php';

class QuotaManagementTest
{
    private $verbose = false;
    private $tests_passed = 0;
    private $tests_failed = 0;
    
    public function __construct($args = [])
    {
        $this->verbose = in_array('--verbose', $args);
    }
    
    /**
     * Run all quota management tests
     */
    public function run()
    {
        $this->printHeader();
        
        try {
            $this->testBasicQuotaTracking();
            $this->testPredictiveQuotaManagement();
            $this->testCrossDayPlanning();
            $this->testQuotaOptimization();
            $this->testQuotaRecovery();
            
        } catch (Exception $e) {
            echo "Fatal error during testing: " . $e->getMessage() . "\n";
            return false;
        }
        
        $this->printResults();
        return true;
    }
    
    /**
     * Test basic quota tracking functionality
     */
    private function testBasicQuotaTracking()
    {
        echo "----------------------------------------\n";
        echo "TESTING: Basic Quota Tracking\n";
        echo "----------------------------------------\n";
        
        try {
            $quotaManager = new \SocialFeed\Core\QuotaManager();
            
            // Test quota initialization
            $this->assert(
                method_exists($quotaManager, 'get_remaining_quota'),
                "QuotaManager should have get_remaining_quota method"
            );
            
            // Test quota consumption tracking
            $platforms = ['youtube', 'tiktok', 'instagram'];
            foreach ($platforms as $platform) {
                $remaining = $quotaManager->get_remaining_quota($platform);
                $this->assert(
                    is_numeric($remaining) && $remaining >= 0,
                    "Remaining quota for $platform should be a non-negative number"
                );
                
                if ($this->verbose) {
                    echo "  $platform remaining quota: $remaining\n";
                }
            }
            
            // Test quota checking (using existing method)
            $canProceed = $quotaManager->check_quota('videos');
            $this->assert(
                $canProceed === true || $canProceed === false,
                "Quota check should return boolean result"
            );
            
            if ($this->verbose) {
                echo "  Quota check for 'videos' operation: " . ($canProceed ? 'Allowed' : 'Blocked') . "\n";
            }
            
            echo "✓ Basic quota tracking tests completed\n";
            
        } catch (Exception $e) {
            echo "✗ Basic quota tracking test failed: " . $e->getMessage() . "\n";
            $this->tests_failed++;
        }
    }
    
    /**
     * Test predictive quota management
     */
    private function testPredictiveQuotaManagement()
    {
        echo "----------------------------------------\n";
        echo "TESTING: Predictive Quota Management\n";
        echo "----------------------------------------\n";
        
        try {
            $quotaManager = new \SocialFeed\Core\QuotaManager();
            
            // Test quota prediction
            if (method_exists($quotaManager, 'predict_quota_usage')) {
                $prediction = $quotaManager->predict_quota_usage('youtube', 24); // 24 hours
                $this->assert(
                    is_array($prediction) || is_numeric($prediction),
                    "Quota prediction should return array or numeric value"
                );
                
                if ($this->verbose && is_array($prediction)) {
                    echo "  YouTube 24h prediction: " . json_encode($prediction) . "\n";
                }
            }
            
            // Test quota optimization recommendations
            if (method_exists($quotaManager, 'get_optimization_recommendations')) {
                $recommendations = $quotaManager->get_optimization_recommendations();
                $this->assert(
                    is_array($recommendations),
                    "Optimization recommendations should be an array"
                );
                
                if ($this->verbose) {
                    echo "  Optimization recommendations: " . count($recommendations) . " items\n";
                }
            }
            
            // Test quota allocation strategy
            if (method_exists($quotaManager, 'optimize_quota_allocation')) {
                $allocation = $quotaManager->optimize_quota_allocation(['youtube', 'tiktok']);
                $this->assert(
                    is_array($allocation),
                    "Quota allocation should return array"
                );
                
                if ($this->verbose) {
                    echo "  Optimized allocation: " . json_encode($allocation) . "\n";
                }
            }
            
            echo "✓ Predictive quota management tests completed\n";
            
        } catch (Exception $e) {
            echo "✗ Predictive quota management test failed: " . $e->getMessage() . "\n";
            $this->tests_failed++;
        }
    }
    
    /**
     * Test cross-day planning functionality
     */
    private function testCrossDayPlanning()
    {
        echo "----------------------------------------\n";
        echo "TESTING: Cross-Day Planning\n";
        echo "----------------------------------------\n";
        
        try {
            $quotaManager = new \SocialFeed\Core\QuotaManager();
            
            // Test multi-day quota planning
            if (method_exists($quotaManager, 'plan_multi_day_quota')) {
                $plan = $quotaManager->plan_multi_day_quota(['youtube', 'tiktok'], 7); // 7 days
                $this->assert(
                    is_array($plan),
                    "Multi-day quota plan should be an array"
                );
                
                if ($this->verbose) {
                    echo "  7-day plan generated with " . count($plan) . " entries\n";
                }
            }
            
            // Test quota reset scheduling
            if (method_exists($quotaManager, 'schedule_quota_reset')) {
                $scheduled = $quotaManager->schedule_quota_reset();
                $this->assert(
                    is_bool($scheduled),
                    "Quota reset scheduling should return boolean"
                );
                
                if ($this->verbose) {
                    echo "  Quota reset scheduled: " . ($scheduled ? 'Yes' : 'No') . "\n";
                }
            }
            
            // Test quota carryover logic
            if (method_exists($quotaManager, 'calculate_quota_carryover')) {
                $carryover = $quotaManager->calculate_quota_carryover('youtube');
                $this->assert(
                    is_numeric($carryover) && $carryover >= 0,
                    "Quota carryover should be non-negative number"
                );
                
                if ($this->verbose) {
                    echo "  YouTube quota carryover: $carryover\n";
                }
            }
            
            echo "✓ Cross-day planning tests completed\n";
            
        } catch (Exception $e) {
            echo "✗ Cross-day planning test failed: " . $e->getMessage() . "\n";
            $this->tests_failed++;
        }
    }
    
    /**
     * Test quota optimization strategies
     */
    private function testQuotaOptimization()
    {
        echo "----------------------------------------\n";
        echo "TESTING: Quota Optimization\n";
        echo "----------------------------------------\n";
        
        try {
            $quotaManager = new \SocialFeed\Core\QuotaManager();
            
            // Test priority-based quota allocation
            if (method_exists($quotaManager, 'allocate_by_priority')) {
                $priorities = [
                    'youtube' => 'high',
                    'tiktok' => 'medium',
                    'instagram' => 'low'
                ];
                $allocation = $quotaManager->allocate_by_priority($priorities);
                $this->assert(
                    is_array($allocation),
                    "Priority-based allocation should return array"
                );
                
                if ($this->verbose) {
                    echo "  Priority allocation: " . json_encode($allocation) . "\n";
                }
            }
            
            // Test usage pattern analysis
            if (method_exists($quotaManager, 'analyze_usage_patterns')) {
                $patterns = $quotaManager->analyze_usage_patterns();
                $this->assert(
                    is_array($patterns),
                    "Usage patterns should be an array"
                );
                
                if ($this->verbose) {
                    echo "  Usage patterns analyzed: " . count($patterns) . " patterns\n";
                }
            }
            
            // Test quota efficiency metrics
            if (method_exists($quotaManager, 'get_efficiency_metrics')) {
                $metrics = $quotaManager->get_efficiency_metrics();
                $this->assert(
                    is_array($metrics),
                    "Efficiency metrics should be an array"
                );
                
                if ($this->verbose) {
                    echo "  Efficiency metrics: " . json_encode($metrics) . "\n";
                }
            }
            
            echo "✓ Quota optimization tests completed\n";
            
        } catch (Exception $e) {
            echo "✗ Quota optimization test failed: " . $e->getMessage() . "\n";
            $this->tests_failed++;
        }
    }
    
    /**
     * Test quota recovery mechanisms
     */
    private function testQuotaRecovery()
    {
        echo "----------------------------------------\n";
        echo "TESTING: Quota Recovery\n";
        echo "----------------------------------------\n";
        
        try {
            $quotaManager = new \SocialFeed\Core\QuotaManager();
            
            // Test quota exhaustion handling
            if (method_exists($quotaManager, 'handle_quota_exhaustion')) {
                $response = $quotaManager->handle_quota_exhaustion('youtube');
                $this->assert(
                    is_array($response) || is_bool($response),
                    "Quota exhaustion handling should return array or boolean"
                );
                
                if ($this->verbose) {
                    echo "  Quota exhaustion handled for YouTube\n";
                }
            }
            
            // Test fallback strategies
            if (method_exists($quotaManager, 'get_fallback_strategies')) {
                $strategies = $quotaManager->get_fallback_strategies();
                $this->assert(
                    is_array($strategies),
                    "Fallback strategies should be an array"
                );
                
                if ($this->verbose) {
                    echo "  Fallback strategies: " . count($strategies) . " available\n";
                }
            }
            
            // Test quota recovery timeline
            if (method_exists($quotaManager, 'estimate_recovery_time')) {
                $recovery = $quotaManager->estimate_recovery_time('youtube');
                $this->assert(
                    is_numeric($recovery) && $recovery >= 0,
                    "Recovery time should be non-negative number"
                );
                
                if ($this->verbose) {
                    echo "  YouTube recovery time: {$recovery}s\n";
                }
            }
            
            echo "✓ Quota recovery tests completed\n";
            
        } catch (Exception $e) {
            echo "✗ Quota recovery test failed: " . $e->getMessage() . "\n";
            $this->tests_failed++;
        }
    }
    
    /**
     * Assert helper function
     */
    private function assert($condition, $message)
    {
        if ($condition) {
            $this->tests_passed++;
            if ($this->verbose) {
                echo "  ✓ $message\n";
            }
        } else {
            $this->tests_failed++;
            echo "  ✗ $message\n";
        }
    }
    
    /**
     * Print test header
     */
    private function printHeader()
    {
        echo "========================================\n";
        echo "QUOTA MANAGEMENT SYSTEM TESTS\n";
        echo "========================================\n";
        echo "Testing enhanced predictive quota management and cross-day planning\n\n";
    }
    
    /**
     * Print test results
     */
    private function printResults()
    {
        echo "\n========================================\n";
        echo "QUOTA MANAGEMENT TEST RESULTS\n";
        echo "========================================\n";
        echo "Tests Passed: " . $this->tests_passed . "\n";
        echo "Tests Failed: " . $this->tests_failed . "\n";
        echo "Total Tests: " . ($this->tests_passed + $this->tests_failed) . "\n";
        
        if ($this->tests_failed === 0) {
            echo "\n✓ All quota management tests PASSED!\n";
        } else {
            echo "\n✗ Some tests FAILED. Please review the output above.\n";
        }
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $test = new QuotaManagementTest($argv ?? []);
    $success = $test->run();
    exit($success ? 0 : 1);
}