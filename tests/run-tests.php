<?php

/**
 * Social Media Feed Plugin - Test Runner
 * 
 * Comprehensive test suite runner for Phase 1 improvements
 * Usage: php tests/run-tests.php [--verbose] [--performance] [--memory] [--suite=unit|integration|performance]
 */

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define constants
define('SOCIAL_FEED_TEST_MODE', true);
define('SOCIAL_FEED_TEST_START_TIME', microtime(true));
define('SOCIAL_FEED_TEST_START_MEMORY', memory_get_usage(true));

// Include required files
require_once __DIR__ . '/Utilities/TestHelper.php';
require_once __DIR__ . '/Utilities/MockDataGenerator.php';

// Include plugin files
$pluginDir = dirname(__DIR__);
require_once $pluginDir . '/includes/Platforms/PlatformInterface.php';
require_once $pluginDir . '/includes/Platforms/AbstractPlatform.php';
require_once $pluginDir . '/includes/Core/NotificationHandler.php';
require_once $pluginDir . '/includes/Platforms/YouTube.php';
require_once $pluginDir . '/includes/Platforms/TikTok.php';

class TestRunner
{
    private $verbose = false;
    private $performanceMode = false;
    private $memoryMode = false;
    private $suite = 'all';
    private $results = [];
    private $startTime;
    private $startMemory;
    
    public function __construct($args = [])
    {
        $this->parseArguments($args);
        $this->startTime = microtime(true);
        $this->startMemory = memory_get_usage(true);
        
        $this->results = [
            'total_tests' => 0,
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'performance' => [],
            'memory' => []
        ];
    }
    
    /**
     * Parse command line arguments
     */
    private function parseArguments($args)
    {
        foreach ($args as $arg) {
            switch ($arg) {
                case '--verbose':
                case '-v':
                    $this->verbose = true;
                    break;
                case '--performance':
                case '-p':
                    $this->performanceMode = true;
                    break;
                case '--memory':
                case '-m':
                    $this->memoryMode = true;
                    break;
                default:
                    if (strpos($arg, '--suite=') === 0) {
                        $this->suite = substr($arg, 8);
                    }
                    break;
            }
        }
    }
    
    /**
     * Run all tests
     */
    public function run()
    {
        $this->printHeader();
        
        try {
            // Set up test environment
            TestHelper::setUp();
            
            // Run test suites based on selection
            if ($this->suite === 'all' || $this->suite === 'unit') {
                $this->runUnitTests();
            }
            
            if ($this->suite === 'all' || $this->suite === 'integration') {
                $this->runIntegrationTests();
            }
            
            if ($this->suite === 'all' || $this->suite === 'performance') {
                $this->runPerformanceTests();
            }
            
            if ($this->memoryMode) {
                $this->runMemoryTests();
            }
            
            // Clean up
            TestHelper::tearDown();
            
        } catch (Exception $e) {
            $this->results['errors'][] = "Fatal error: " . $e->getMessage();
            $this->results['failed']++;
        }
        
        $this->printResults();
        
        return $this->results['failed'] === 0;
    }
    
    /**
     * Run unit tests
     */
    private function runUnitTests()
    {
        $this->printSection("Unit Tests");
        
        // Test exponential backoff retry logic
        $this->runTestFile('Unit/AbstractPlatformTest.php', 'AbstractPlatformTest');
        
        // Test notification delivery confirmation
        $this->runTestFile('Unit/NotificationHandlerTest.php', 'NotificationHandlerTest');
        
        // Test memory optimization
        $this->runTestFile('Unit/MemoryOptimizationTest.php', 'MemoryOptimizationTest');
    }
    
    /**
     * Run integration tests
     */
    private function runIntegrationTests()
    {
        $this->printSection("Integration Tests");
        
        // Test API failure recovery
        $this->runTestFile('Integration/APIFailureRecoveryTest.php', 'APIFailureRecoveryTest');
    }
    
    /**
     * Run performance tests
     */
    private function runPerformanceTests()
    {
        $this->printSection("Performance Tests");
        
        // Performance benchmarks
        $this->runPerformanceBenchmarks();
    }
    
    /**
     * Run memory tests
     */
    private function runMemoryTests()
    {
        $this->printSection("Memory Tests");
        
        // Memory usage benchmarks
        $this->runMemoryBenchmarks();
    }
    
    /**
     * Run a specific test file
     */
    private function runTestFile($filePath, $className)
    {
        $fullPath = __DIR__ . '/' . $filePath;
        
        if (!file_exists($fullPath)) {
            $this->results['errors'][] = "Test file not found: $filePath";
            $this->results['failed']++;
            return;
        }
        
        try {
            require_once $fullPath;
            
            if (!class_exists($className)) {
                $this->results['errors'][] = "Test class not found: $className";
                $this->results['failed']++;
                return;
            }
            
            $testInstance = new $className();
            $methods = get_class_methods($testInstance);
            
            foreach ($methods as $method) {
                if (strpos($method, 'test') === 0) {
                    $this->runSingleTest($testInstance, $method, $className);
                }
            }
            
        } catch (Exception $e) {
            $this->results['errors'][] = "Error in $className: " . $e->getMessage();
            $this->results['failed']++;
        }
    }
    
    /**
     * Run a single test method
     */
    private function runSingleTest($testInstance, $method, $className)
    {
        $this->results['total_tests']++;
        
        try {
            // Set up for each test
            if (method_exists($testInstance, 'setUp')) {
                $testInstance->setUp();
            }
            
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);
            
            // Run the test
            $testInstance->$method();
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage(true);
            
            // Record performance metrics
            $this->results['performance'][$className . '::' . $method] = [
                'execution_time' => $endTime - $startTime,
                'memory_used' => $endMemory - $startMemory
            ];
            
            $this->results['passed']++;
            
            if ($this->verbose) {
                $this->printSuccess("✓ $className::$method");
            }
            
            // Tear down for each test
            if (method_exists($testInstance, 'tearDown')) {
                $testInstance->tearDown();
            }
            
        } catch (Exception $e) {
            $this->results['failed']++;
            $this->results['errors'][] = "$className::$method - " . $e->getMessage();
            
            if ($this->verbose) {
                $this->printError("✗ $className::$method - " . $e->getMessage());
            }
        }
    }
    
    /**
     * Run performance benchmarks
     */
    private function runPerformanceBenchmarks()
    {
        $this->printInfo("Running performance benchmarks...");
        
        // Benchmark retry logic performance
        $this->benchmarkRetryLogic();
        
        // Benchmark notification delivery performance
        $this->benchmarkNotificationDelivery();
        
        // Benchmark memory optimization performance
        $this->benchmarkMemoryOptimization();
    }
    
    /**
     * Benchmark retry logic performance
     */
    private function benchmarkRetryLogic()
    {
        $this->printInfo("Benchmarking retry logic...");
        
        // Create mock platform
        $platform = new class extends AbstractPlatform {
            protected $platformName = 'test';
            protected $apiBaseUrl = 'https://api.test.com';
            
            public function fetchPosts($count = 10) {
                return [];
            }
            
            public function testRetryLogic() {
                // Simulate API call with retries
                return $this->makeApiCall('/test', []);
            }
        };
        
        // Test successful request (no retries)
        TestHelper::mockHttpResponse([
            'response' => ['code' => 200],
            'body' => '{"success": true}'
        ]);
        
        $metrics = TestHelper::measureExecutionTime(function() use ($platform) {
            return $platform->testRetryLogic();
        }, 'retry_success');
        
        $this->results['performance']['retry_success'] = $metrics;
        
        // Test with rate limit (triggers retries)
        TestHelper::mockHttpResponse([
            'response' => ['code' => 429],
            'body' => '{"error": "Rate limit exceeded"}'
        ]);
        
        $metrics = TestHelper::measureExecutionTime(function() use ($platform) {
            try {
                return $platform->testRetryLogic();
            } catch (Exception $e) {
                return null;
            }
        }, 'retry_rate_limit');
        
        $this->results['performance']['retry_rate_limit'] = $metrics;
        
        if ($this->verbose) {
            $successTime = $this->results['performance']['retry_success']['execution_time'];
            $retryTime = $this->results['performance']['retry_rate_limit']['execution_time'];
            $this->printInfo("  Success request: " . number_format($successTime * 1000, 2) . "ms");
            $this->printInfo("  Rate limit retry: " . number_format($retryTime * 1000, 2) . "ms");
        }
    }
    
    /**
     * Benchmark notification delivery performance
     */
    private function benchmarkNotificationDelivery()
    {
        $this->printInfo("Benchmarking notification delivery...");
        
        $notificationHandler = new NotificationHandler();
        
        // Test single notification
        $metrics = TestHelper::measureExecutionTime(function() use ($notificationHandler) {
            return $notificationHandler->sendNotification([
                'title' => 'Test Notification',
                'message' => 'This is a test message',
                'type' => 'stream_status_change'
            ]);
        }, 'notification_single');
        
        $this->results['performance']['notification_single'] = $metrics;
        
        // Test batch notifications
        $metrics = TestHelper::measureExecutionTime(function() use ($notificationHandler) {
            $notifications = [];
            for ($i = 0; $i < 10; $i++) {
                $notifications[] = [
                    'title' => "Test Notification $i",
                    'message' => "This is test message $i",
                    'type' => 'stream_status_change'
                ];
            }
            
            foreach ($notifications as $notification) {
                $notificationHandler->sendNotification($notification);
            }
            
            return count($notifications);
        }, 'notification_batch');
        
        $this->results['performance']['notification_batch'] = $metrics;
        
        if ($this->verbose) {
            $singleTime = $this->results['performance']['notification_single']['execution_time'];
            $batchTime = $this->results['performance']['notification_batch']['execution_time'];
            $this->printInfo("  Single notification: " . number_format($singleTime * 1000, 2) . "ms");
            $this->printInfo("  Batch notifications (10): " . number_format($batchTime * 1000, 2) . "ms");
            $this->printInfo("  Average per notification: " . number_format(($batchTime / 10) * 1000, 2) . "ms");
        }
    }
    
    /**
     * Benchmark memory optimization performance
     */
    private function benchmarkMemoryOptimization()
    {
        $this->printInfo("Benchmarking memory optimization...");
        
        // Test YouTube batch processing
        $youtube = new YouTube();
        
        // Small batch
        $smallBatch = MockDataGenerator::generateYouTubeVideos(10);
        $metrics = TestHelper::measureExecutionTime(function() use ($youtube, $smallBatch) {
            return $youtube->processBatch($smallBatch);
        }, 'youtube_small_batch');
        
        $this->results['performance']['youtube_small_batch'] = $metrics;
        
        // Large batch
        $largeBatch = MockDataGenerator::generateYouTubeVideos(100);
        $metrics = TestHelper::measureExecutionTime(function() use ($youtube, $largeBatch) {
            return $youtube->processBatch($largeBatch);
        }, 'youtube_large_batch');
        
        $this->results['performance']['youtube_large_batch'] = $metrics;
        
        if ($this->verbose) {
            $smallTime = $this->results['performance']['youtube_small_batch']['execution_time'];
            $smallMemory = $this->results['performance']['youtube_small_batch']['memory_used'];
            $largeTime = $this->results['performance']['youtube_large_batch']['execution_time'];
            $largeMemory = $this->results['performance']['youtube_large_batch']['memory_used'];
            
            $this->printInfo("  Small batch (10 items): " . number_format($smallTime * 1000, 2) . "ms, " . TestHelper::formatBytes($smallMemory));
            $this->printInfo("  Large batch (100 items): " . number_format($largeTime * 1000, 2) . "ms, " . TestHelper::formatBytes($largeMemory));
        }
    }
    
    /**
     * Run memory benchmarks
     */
    private function runMemoryBenchmarks()
    {
        $this->printInfo("Running memory benchmarks...");
        
        // Test memory usage patterns
        $this->benchmarkMemoryUsage();
        
        // Test memory leak detection
        $this->benchmarkMemoryLeaks();
    }
    
    /**
     * Benchmark memory usage patterns
     */
    private function benchmarkMemoryUsage()
    {
        $this->printInfo("Testing memory usage patterns...");
        
        $initialMemory = memory_get_usage(true);
        
        // Test large data processing
        $largeDataset = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeDataset[] = MockDataGenerator::generateYouTubeVideo();
        }
        
        $afterCreation = memory_get_usage(true);
        
        // Process the data
        $processedData = array_map(function($item) {
            return array_merge($item, ['processed' => true]);
        }, $largeDataset);
        
        $afterProcessing = memory_get_usage(true);
        
        // Clean up
        unset($largeDataset);
        unset($processedData);
        gc_collect_cycles();
        
        $afterCleanup = memory_get_usage(true);
        
        $this->results['memory']['large_dataset'] = [
            'initial' => $initialMemory,
            'after_creation' => $afterCreation,
            'after_processing' => $afterProcessing,
            'after_cleanup' => $afterCleanup,
            'creation_usage' => $afterCreation - $initialMemory,
            'processing_usage' => $afterProcessing - $afterCreation,
            'cleanup_efficiency' => $afterProcessing - $afterCleanup
        ];
        
        if ($this->verbose) {
            $this->printInfo("  Data creation: " . TestHelper::formatBytes($afterCreation - $initialMemory));
            $this->printInfo("  Data processing: " . TestHelper::formatBytes($afterProcessing - $afterCreation));
            $this->printInfo("  Memory freed: " . TestHelper::formatBytes($afterProcessing - $afterCleanup));
        }
    }
    
    /**
     * Benchmark memory leak detection
     */
    private function benchmarkMemoryLeaks()
    {
        $this->printInfo("Testing for memory leaks...");
        
        $initialMemory = memory_get_usage(true);
        $memoryReadings = [];
        
        // Run multiple iterations to detect leaks
        for ($i = 0; $i < 10; $i++) {
            // Create and process data
            $data = MockDataGenerator::generateYouTubeVideos(50);
            
            // Simulate processing
            foreach ($data as &$item) {
                $item['processed_at'] = time();
                $item['iteration'] = $i;
            }
            
            // Clean up
            unset($data);
            gc_collect_cycles();
            
            $memoryReadings[] = memory_get_usage(true);
        }
        
        $finalMemory = memory_get_usage(true);
        
        // Analyze memory trend
        $memoryGrowth = $finalMemory - $initialMemory;
        $averageGrowthPerIteration = $memoryGrowth / 10;
        
        $this->results['memory']['leak_detection'] = [
            'initial_memory' => $initialMemory,
            'final_memory' => $finalMemory,
            'total_growth' => $memoryGrowth,
            'average_growth_per_iteration' => $averageGrowthPerIteration,
            'readings' => $memoryReadings,
            'has_potential_leak' => $averageGrowthPerIteration > 1024 * 1024 // 1MB per iteration
        ];
        
        if ($this->verbose) {
            $this->printInfo("  Total memory growth: " . TestHelper::formatBytes($memoryGrowth));
            $this->printInfo("  Average per iteration: " . TestHelper::formatBytes($averageGrowthPerIteration));
            
            if ($this->results['memory']['leak_detection']['has_potential_leak']) {
                $this->printWarning("  ⚠ Potential memory leak detected!");
            } else {
                $this->printSuccess("  ✓ No significant memory leaks detected");
            }
        }
    }
    
    /**
     * Print test results
     */
    private function printResults()
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $totalTime = $endTime - $this->startTime;
        $totalMemory = $endMemory - $this->startMemory;
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "TEST RESULTS\n";
        echo str_repeat("=", 60) . "\n";
        
        echo "Total Tests: " . $this->results['total_tests'] . "\n";
        echo "Passed: " . $this->results['passed'] . "\n";
        echo "Failed: " . $this->results['failed'] . "\n";
        echo "Skipped: " . $this->results['skipped'] . "\n";
        
        if (!empty($this->results['errors'])) {
            echo "\nERRORS:\n";
            foreach ($this->results['errors'] as $error) {
                echo "  • $error\n";
            }
        }
        
        echo "\nExecution Time: " . number_format($totalTime, 3) . " seconds\n";
        echo "Memory Usage: " . TestHelper::formatBytes($totalMemory) . "\n";
        echo "Peak Memory: " . TestHelper::formatBytes(memory_get_peak_usage(true)) . "\n";
        
        if ($this->performanceMode && !empty($this->results['performance'])) {
            echo "\nPERFORMANCE METRICS:\n";
            foreach ($this->results['performance'] as $test => $metrics) {
                echo "  $test:\n";
                echo "    Time: " . number_format($metrics['execution_time'] * 1000, 2) . "ms\n";
                if (isset($metrics['memory_used'])) {
                    echo "    Memory: " . TestHelper::formatBytes($metrics['memory_used']) . "\n";
                }
            }
        }
        
        if ($this->memoryMode && !empty($this->results['memory'])) {
            echo "\nMEMORY ANALYSIS:\n";
            foreach ($this->results['memory'] as $test => $metrics) {
                echo "  $test:\n";
                foreach ($metrics as $key => $value) {
                    if (is_numeric($value)) {
                        if (strpos($key, 'memory') !== false || strpos($key, 'usage') !== false) {
                            echo "    $key: " . TestHelper::formatBytes($value) . "\n";
                        } else {
                            echo "    $key: $value\n";
                        }
                    } elseif (is_bool($value)) {
                        echo "    $key: " . ($value ? 'true' : 'false') . "\n";
                    } elseif (!is_array($value)) {
                        echo "    $key: $value\n";
                    }
                }
            }
        }
        
        echo "\n" . str_repeat("=", 60) . "\n";
        
        if ($this->results['failed'] === 0) {
            $this->printSuccess("ALL TESTS PASSED! ✓");
        } else {
            $this->printError("SOME TESTS FAILED! ✗");
        }
        
        echo "\n";
    }
    
    /**
     * Print methods for formatted output
     */
    private function printHeader()
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "SOCIAL MEDIA FEED PLUGIN - TEST SUITE\n";
        echo "Phase 1 Improvements Testing\n";
        echo str_repeat("=", 60) . "\n";
        echo "Suite: " . ucfirst($this->suite) . "\n";
        echo "Verbose: " . ($this->verbose ? 'Yes' : 'No') . "\n";
        echo "Performance: " . ($this->performanceMode ? 'Yes' : 'No') . "\n";
        echo "Memory: " . ($this->memoryMode ? 'Yes' : 'No') . "\n";
        echo str_repeat("-", 60) . "\n\n";
    }
    
    private function printSection($title)
    {
        echo "\n" . str_repeat("-", 40) . "\n";
        echo strtoupper($title) . "\n";
        echo str_repeat("-", 40) . "\n";
    }
    
    private function printSuccess($message)
    {
        echo "\033[32m$message\033[0m\n";
    }
    
    private function printError($message)
    {
        echo "\033[31m$message\033[0m\n";
    }
    
    private function printWarning($message)
    {
        echo "\033[33m$message\033[0m\n";
    }
    
    private function printInfo($message)
    {
        echo "\033[36m$message\033[0m\n";
    }
}

// Run tests if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    $args = array_slice($argv, 1);
    $runner = new TestRunner($args);
    $success = $runner->run();
    
    exit($success ? 0 : 1);
}