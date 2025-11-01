<?php

/**
 * Test helper utilities for social media feed plugin testing
 */
class TestHelper
{
    private static $mockResponses = [];
    private static $testOptions = [];
    private static $errorLog = [];
    private static $performanceMetrics = [];

    /**
     * Set up test environment
     */
    public static function setUp()
    {
        self::$mockResponses = [];
        self::$testOptions = [];
        self::$errorLog = [];
        self::$performanceMetrics = [];
        
        // Set up global test variables
        global $testOptions, $testErrorLog, $mockHttpResponse;
        $testOptions = &self::$testOptions;
        $testErrorLog = &self::$errorLog;
        $mockHttpResponse = null;
        
        // Mock WordPress functions if not already defined
        self::mockWordPressFunctions();
    }

    /**
     * Clean up test environment
     */
    public static function tearDown()
    {
        // Reset global variables
        global $testOptions, $testErrorLog, $mockHttpResponse;
        $testOptions = [];
        $testErrorLog = [];
        $mockHttpResponse = null;
        
        // Force garbage collection
        gc_collect_cycles();
    }

    /**
     * Mock HTTP response for API calls
     */
    public static function mockHttpResponse($response, $url = null)
    {
        global $mockHttpResponse;
        
        if ($url) {
            self::$mockResponses[$url] = $response;
        } else {
            $mockHttpResponse = $response;
        }
    }

    /**
     * Mock WordPress option functions
     */
    public static function setOption($key, $value)
    {
        self::$testOptions[$key] = $value;
    }

    /**
     * Get WordPress option value
     */
    public static function getOption($key, $default = false)
    {
        return self::$testOptions[$key] ?? $default;
    }

    /**
     * Get logged errors
     */
    public static function getErrorLog()
    {
        return self::$errorLog;
    }

    /**
     * Clear error log
     */
    public static function clearErrorLog()
    {
        self::$errorLog = [];
        global $testErrorLog;
        $testErrorLog = [];
    }

    /**
     * Assert that an error was logged
     */
    public static function assertErrorLogged($expectedMessage, $testCase)
    {
        $found = false;
        foreach (self::$errorLog as $logEntry) {
            if (strpos($logEntry, $expectedMessage) !== false) {
                $found = true;
                break;
            }
        }
        
        $testCase->assertTrue($found, "Expected error message '$expectedMessage' was not logged");
    }

    /**
     * Assert that no errors were logged
     */
    public static function assertNoErrorsLogged($testCase)
    {
        $testCase->assertEmpty(self::$errorLog, 'Unexpected errors were logged: ' . implode(', ', self::$errorLog));
    }

    /**
     * Measure execution time of a callable
     */
    public static function measureExecutionTime($callable, $label = 'operation')
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        $result = call_user_func($callable);
        
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        
        $metrics = [
            'execution_time' => $endTime - $startTime,
            'memory_used' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage(true),
            'result' => $result
        ];
        
        self::$performanceMetrics[$label] = $metrics;
        
        return $metrics;
    }

    /**
     * Get performance metrics
     */
    public static function getPerformanceMetrics($label = null)
    {
        if ($label) {
            return self::$performanceMetrics[$label] ?? null;
        }
        
        return self::$performanceMetrics;
    }

    /**
     * Assert execution time is within expected range
     */
    public static function assertExecutionTimeWithin($label, $maxTime, $testCase, $minTime = 0)
    {
        $metrics = self::getPerformanceMetrics($label);
        $testCase->assertNotNull($metrics, "No performance metrics found for label: $label");
        
        $executionTime = $metrics['execution_time'];
        $testCase->assertGreaterThanOrEqual($minTime, $executionTime, 
            "Execution time ($executionTime s) is less than minimum expected ($minTime s)");
        $testCase->assertLessThanOrEqual($maxTime, $executionTime, 
            "Execution time ($executionTime s) exceeds maximum expected ($maxTime s)");
    }

    /**
     * Assert memory usage is within expected range
     */
    public static function assertMemoryUsageWithin($label, $maxMemory, $testCase, $minMemory = 0)
    {
        $metrics = self::getPerformanceMetrics($label);
        $testCase->assertNotNull($metrics, "No performance metrics found for label: $label");
        
        $memoryUsed = $metrics['memory_used'];
        $testCase->assertGreaterThanOrEqual($minMemory, $memoryUsed, 
            "Memory usage (" . self::formatBytes($memoryUsed) . ") is less than minimum expected (" . self::formatBytes($minMemory) . ")");
        $testCase->assertLessThanOrEqual($maxMemory, $memoryUsed, 
            "Memory usage (" . self::formatBytes($memoryUsed) . ") exceeds maximum expected (" . self::formatBytes($maxMemory) . ")");
    }

    /**
     * Create a temporary file with content
     */
    public static function createTempFile($content, $extension = 'txt')
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'social_feed_test_') . '.' . $extension;
        file_put_contents($tempFile, $content);
        
        // Register for cleanup
        register_shutdown_function(function() use ($tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        });
        
        return $tempFile;
    }

    /**
     * Simulate network delay
     */
    public static function simulateNetworkDelay($milliseconds = 100)
    {
        usleep($milliseconds * 1000);
    }

    /**
     * Generate random string
     */
    public static function generateRandomString($length = 10)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        return $randomString;
    }

    /**
     * Format bytes in human-readable format
     */
    public static function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Create mock WordPress user
     */
    public static function createMockUser($userId = 1, $userLogin = 'testuser', $userEmail = 'test@example.com')
    {
        return (object) [
            'ID' => $userId,
            'user_login' => $userLogin,
            'user_email' => $userEmail,
            'user_nicename' => $userLogin,
            'display_name' => ucfirst($userLogin),
            'user_registered' => date('Y-m-d H:i:s'),
            'user_status' => 0,
            'spam' => 0,
            'deleted' => 0
        ];
    }

    /**
     * Mock WordPress database query results
     */
    public static function mockDatabaseQuery($query, $results)
    {
        global $mockDatabaseQueries;
        if (!isset($mockDatabaseQueries)) {
            $mockDatabaseQueries = [];
        }
        
        $mockDatabaseQueries[$query] = $results;
    }

    /**
     * Validate JSON structure
     */
    public static function validateJsonStructure($json, $expectedStructure, $testCase)
    {
        $data = is_string($json) ? json_decode($json, true) : $json;
        $testCase->assertNotNull($data, 'Invalid JSON provided');
        
        self::validateArrayStructure($data, $expectedStructure, $testCase, 'root');
    }

    /**
     * Validate array structure recursively
     */
    private static function validateArrayStructure($data, $structure, $testCase, $path = '')
    {
        foreach ($structure as $key => $expectedType) {
            $currentPath = $path ? "$path.$key" : $key;
            
            if (is_array($expectedType)) {
                $testCase->assertArrayHasKey($key, $data, "Missing key '$key' at path '$currentPath'");
                $testCase->assertIsArray($data[$key], "Expected array at path '$currentPath'");
                
                if (!empty($expectedType) && !empty($data[$key])) {
                    // Validate first item structure for arrays
                    self::validateArrayStructure($data[$key][0], $expectedType, $testCase, $currentPath . '[0]');
                }
            } else {
                $testCase->assertArrayHasKey($key, $data, "Missing key '$key' at path '$currentPath'");
                
                switch ($expectedType) {
                    case 'string':
                        $testCase->assertIsString($data[$key], "Expected string at path '$currentPath'");
                        break;
                    case 'int':
                    case 'integer':
                        $testCase->assertIsInt($data[$key], "Expected integer at path '$currentPath'");
                        break;
                    case 'float':
                    case 'double':
                        $testCase->assertIsFloat($data[$key], "Expected float at path '$currentPath'");
                        break;
                    case 'bool':
                    case 'boolean':
                        $testCase->assertIsBool($data[$key], "Expected boolean at path '$currentPath'");
                        break;
                    case 'array':
                        $testCase->assertIsArray($data[$key], "Expected array at path '$currentPath'");
                        break;
                    case 'null':
                        $testCase->assertNull($data[$key], "Expected null at path '$currentPath'");
                        break;
                }
            }
        }
    }

    /**
     * Mock WordPress functions for testing
     */
    private static function mockWordPressFunctions()
    {
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                return TestHelper::getOption($option, $default);
            }
        }
        
        if (!function_exists('update_option')) {
            function update_option($option, $value) {
                TestHelper::setOption($option, $value);
                return true;
            }
        }
        
        if (!function_exists('delete_option')) {
            function delete_option($option) {
                global $testOptions;
                unset($testOptions[$option]);
                return true;
            }
        }
        
        if (!function_exists('wp_remote_get')) {
            function wp_remote_get($url, $args = []) {
                global $mockHttpResponse;
                
                // Check for URL-specific responses
                if (isset(TestHelper::$mockResponses[$url])) {
                    $response = TestHelper::$mockResponses[$url];
                } else {
                    $response = $mockHttpResponse;
                }
                
                // Simulate network delay if configured
                if (isset($args['timeout']) && $args['timeout'] > 0) {
                    TestHelper::simulateNetworkDelay(min($args['timeout'] * 100, 1000));
                }
                
                if (is_callable($response)) {
                    return call_user_func($response);
                }
                
                return $response ?: [
                    'response' => ['code' => 200],
                    'body' => '{}'
                ];
            }
        }
        
        if (!function_exists('wp_remote_post')) {
            function wp_remote_post($url, $args = []) {
                return wp_remote_get($url, $args);
            }
        }
        
        if (!function_exists('wp_remote_retrieve_response_code')) {
            function wp_remote_retrieve_response_code($response) {
                if (is_wp_error($response)) {
                    return 0;
                }
                return $response['response']['code'] ?? 200;
            }
        }
        
        if (!function_exists('wp_remote_retrieve_body')) {
            function wp_remote_retrieve_body($response) {
                if (is_wp_error($response)) {
                    return '';
                }
                return $response['body'] ?? '{}';
            }
        }
        
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) {
                return $thing instanceof WP_Error;
            }
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {
                TestHelper::$errorLog[] = $message;
                global $testErrorLog;
                $testErrorLog[] = $message;
            }
        }
        
        if (!function_exists('wp_json_encode')) {
            function wp_json_encode($data, $options = 0, $depth = 512) {
                return json_encode($data, $options, $depth);
            }
        }
        
        if (!function_exists('sanitize_text_field')) {
            function sanitize_text_field($str) {
                return trim(strip_tags($str));
            }
        }
        
        if (!function_exists('esc_url')) {
            function esc_url($url) {
                return filter_var($url, FILTER_SANITIZE_URL);
            }
        }
        
        if (!function_exists('current_time')) {
            function current_time($type, $gmt = 0) {
                switch ($type) {
                    case 'mysql':
                        return $gmt ? gmdate('Y-m-d H:i:s') : date('Y-m-d H:i:s');
                    case 'timestamp':
                        return $gmt ? time() : time() + (get_option('gmt_offset') * HOUR_IN_SECONDS);
                    default:
                        return time();
                }
            }
        }
        
        if (!defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }
        
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }
        
        if (!defined('WEEK_IN_SECONDS')) {
            define('WEEK_IN_SECONDS', 604800);
        }
    }

    /**
     * Create a test database table
     */
    public static function createTestTable($tableName, $schema)
    {
        global $mockDatabaseTables;
        if (!isset($mockDatabaseTables)) {
            $mockDatabaseTables = [];
        }
        
        $mockDatabaseTables[$tableName] = [
            'schema' => $schema,
            'data' => []
        ];
    }

    /**
     * Insert test data into mock table
     */
    public static function insertTestData($tableName, $data)
    {
        global $mockDatabaseTables;
        if (!isset($mockDatabaseTables[$tableName])) {
            throw new Exception("Table $tableName does not exist");
        }
        
        $mockDatabaseTables[$tableName]['data'][] = $data;
    }

    /**
     * Get test data from mock table
     */
    public static function getTestData($tableName, $where = [])
    {
        global $mockDatabaseTables;
        if (!isset($mockDatabaseTables[$tableName])) {
            return [];
        }
        
        $data = $mockDatabaseTables[$tableName]['data'];
        
        if (empty($where)) {
            return $data;
        }
        
        return array_filter($data, function($row) use ($where) {
            foreach ($where as $key => $value) {
                if (!isset($row[$key]) || $row[$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }
}

/**
 * Mock WP_Error class for testing
 */
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = [];
        private $error_data = [];
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }
        
        public function get_error_codes() {
            return array_keys($this->errors);
        }
        
        public function get_error_code() {
            $codes = $this->get_error_codes();
            return empty($codes) ? '' : $codes[0];
        }
        
        public function get_error_messages($code = '') {
            if (empty($code)) {
                $all_messages = [];
                foreach ($this->errors as $messages) {
                    $all_messages = array_merge($all_messages, $messages);
                }
                return $all_messages;
            }
            
            return $this->errors[$code] ?? [];
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            
            $messages = $this->get_error_messages($code);
            return empty($messages) ? '' : $messages[0];
        }
        
        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            
            return $this->error_data[$code] ?? '';
        }
        
        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }
    }
}