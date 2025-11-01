<?php

use PHPUnit\Framework\TestCase;
use SocialFeed\Platforms\YouTube;
use SocialFeed\Platforms\TikTok;
use SocialFeed\Core\QuotaManager;
use SocialFeed\Core\PerformanceMonitor;

/**
 * Integration tests for API failure scenarios and recovery mechanisms
 */
class APIFailureRecoveryTest extends TestCase
{
    private $youtube;
    private $tiktok;
    private $quotaManager;
    private $performanceMonitor;
    private $originalFunctions = [];

    protected function setUp(): void
    {
        // Initialize platform instances
        $this->quotaManager = new QuotaManager();
        $this->performanceMonitor = new PerformanceMonitor();
        
        $this->youtube = new YouTube();
        $this->tiktok = new TikTok();
        
        // Set up dependencies
        $this->setPrivateProperty($this->youtube, 'quota_manager', $this->quotaManager);
        $this->setPrivateProperty($this->youtube, 'performance_monitor', $this->performanceMonitor);
        $this->setPrivateProperty($this->tiktok, 'quota_manager', $this->quotaManager);
        $this->setPrivateProperty($this->tiktok, 'performance_monitor', $this->performanceMonitor);
        
        // Mock WordPress functions
        $this->mockWordPressFunctions();
    }

    protected function tearDown(): void
    {
        // Restore original functions if needed
        foreach ($this->originalFunctions as $function => $original) {
            if ($original !== null) {
                // In a real environment, you'd restore the original function
            }
        }
    }

    /**
     * Test YouTube API recovery from rate limiting
     */
    public function test_youtube_rate_limit_recovery()
    {
        $callCount = 0;
        $testChannelId = 'UC_test_channel';
        
        // Mock API responses: rate limit -> rate limit -> success
        $this->mockHttpResponse(function() use (&$callCount) {
            $callCount++;
            
            if ($callCount <= 2) {
                return [
                    'response' => ['code' => 429],
                    'headers' => ['retry-after' => '60'],
                    'body' => json_encode([
                        'error' => [
                            'code' => 403,
                            'message' => 'quotaExceeded',
                            'errors' => [['reason' => 'quotaExceeded']]
                        ]
                    ])
                ];
            }
            
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'items' => [
                        [
                            'id' => $testChannelId,
                            'snippet' => [
                                'title' => 'Test Channel',
                                'description' => 'Test Description',
                                'thumbnails' => [
                                    'default' => ['url' => 'https://example.com/thumb.jpg']
                                ]
                            ],
                            'statistics' => [
                                'subscriberCount' => '1000',
                                'videoCount' => '50'
                            ]
                        ]
                    ]
                ])
            ];
        });
        
        // Test channel info retrieval with retry
        $startTime = microtime(true);
        $result = $this->youtube->get_channel_info($testChannelId);
        $endTime = microtime(true);
        
        // Verify successful recovery
        $this->assertIsArray($result);
        $this->assertEquals('Test Channel', $result['title']);
        $this->assertEquals(3, $callCount); // Should have made 3 attempts
        
        // Verify retry delays were applied (should take at least 3 seconds for 2 retries)
        $this->assertGreaterThan(3, $endTime - $startTime);
    }

    /**
     * Test TikTok API recovery from server errors
     */
    public function test_tiktok_server_error_recovery()
    {
        $callCount = 0;
        $testConfig = [
            'username' => 'testuser',
            'count' => 5
        ];
        
        // Mock API responses: 500 error -> 502 error -> success
        $this->mockHttpResponse(function() use (&$callCount) {
            $callCount++;
            
            if ($callCount === 1) {
                return [
                    'response' => ['code' => 500],
                    'body' => json_encode(['error' => 'Internal server error'])
                ];
            }
            
            if ($callCount === 2) {
                return [
                    'response' => ['code' => 502],
                    'body' => json_encode(['error' => 'Bad gateway'])
                ];
            }
            
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'data' => [
                        [
                            'id' => '123456789',
                            'desc' => 'Test video description',
                            'createTime' => time(),
                            'video' => ['playAddr' => 'https://example.com/video.mp4'],
                            'author' => ['nickname' => 'testuser'],
                            'stats' => ['playCount' => 1000, 'shareCount' => 50]
                        ]
                    ]
                ])
            ];
        });
        
        // Test content fetching with retry
        $result = $this->tiktok->fetch_content($testConfig);
        
        // Verify successful recovery
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals('123456789', $result[0]['id']);
        $this->assertEquals(3, $callCount); // Should have made 3 attempts
    }

    /**
     * Test network timeout recovery
     */
    public function test_network_timeout_recovery()
    {
        $callCount = 0;
        $testChannelId = 'UC_timeout_test';
        
        // Mock network timeout followed by success
        $this->mockHttpResponse(function() use (&$callCount) {
            $callCount++;
            
            if ($callCount === 1) {
                return new WP_Error('http_request_timeout', 'Request timeout after 30 seconds');
            }
            
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'items' => [
                        [
                            'id' => $testChannelId,
                            'snippet' => [
                                'title' => 'Timeout Recovery Channel',
                                'description' => 'Successfully recovered from timeout'
                            ]
                        ]
                    ]
                ])
            ];
        });
        
        $result = $this->youtube->get_channel_info($testChannelId);
        
        $this->assertIsArray($result);
        $this->assertEquals('Timeout Recovery Channel', $result['title']);
        $this->assertEquals(2, $callCount); // Initial timeout + successful retry
    }

    /**
     * Test quota management integration during failures
     */
    public function test_quota_management_during_failures()
    {
        $testConfig = ['username' => 'quotatest', 'count' => 10];
        
        // Set up quota manager to track operations
        $initialUsage = $this->quotaManager->get_current_usage();
        
        // Mock consistent failures (should not consume quota after failures)
        $this->mockHttpResponse([
            'response' => ['code' => 400],
            'body' => json_encode(['error' => 'Bad request'])
        ]);
        
        $result = $this->tiktok->fetch_content($testConfig);
        
        // Verify quota wasn't consumed for failed requests
        $finalUsage = $this->quotaManager->get_current_usage();
        $this->assertEquals($initialUsage, $finalUsage);
        
        // Verify failure was properly handled
        $this->assertFalse($result);
    }

    /**
     * Test performance monitoring during recovery scenarios
     */
    public function test_performance_monitoring_during_recovery()
    {
        $callCount = 0;
        
        // Mock slow responses followed by fast response
        $this->mockHttpResponse(function() use (&$callCount) {
            $callCount++;
            
            if ($callCount <= 2) {
                // Simulate slow response with eventual timeout
                sleep(2); // Simulate 2-second delay
                return new WP_Error('http_request_timeout', 'Timeout');
            }
            
            return [
                'response' => ['code' => 200],
                'body' => json_encode([
                    'items' => [
                        ['id' => 'test', 'snippet' => ['title' => 'Fast Response']]
                    ]
                ])
            ];
        });
        
        $startTime = microtime(true);
        $result = $this->youtube->get_channel_info('UC_perf_test');
        $endTime = microtime(true);
        
        // Verify performance was tracked
        $report = $this->performanceMonitor->get_report();
        
        $this->assertArrayHasKey('api_calls', $report);
        $this->assertGreaterThan(0, $report['api_calls']);
        
        // Verify total time includes retry delays
        $this->assertGreaterThan(4, $endTime - $startTime); // At least 4 seconds for retries
    }

    /**
     * Test graceful degradation when all retries fail
     */
    public function test_graceful_degradation_on_complete_failure()
    {
        // Mock consistent failures
        $this->mockHttpResponse([
            'response' => ['code' => 503],
            'body' => json_encode(['error' => 'Service unavailable'])
        ]);
        
        $result = $this->youtube->fetch_content(['channel_id' => 'UC_fail_test']);
        
        // Should return empty array instead of throwing exception
        $this->assertIsArray($result);
        $this->assertEmpty($result);
        
        // Verify error was logged
        $this->assertErrorWasLogged('API request failed after all retry attempts');
    }

    /**
     * Test concurrent request handling during failures
     */
    public function test_concurrent_request_failure_handling()
    {
        $results = [];
        $configs = [
            ['channel_id' => 'UC_concurrent_1'],
            ['channel_id' => 'UC_concurrent_2'],
            ['channel_id' => 'UC_concurrent_3']
        ];
        
        // Mock mixed responses (some fail, some succeed)
        $callCount = 0;
        $this->mockHttpResponse(function() use (&$callCount) {
            $callCount++;
            
            // Every third request succeeds
            if ($callCount % 3 === 0) {
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode([
                        'items' => [
                            ['id' => "success_$callCount", 'snippet' => ['title' => "Success $callCount"]]
                        ]
                    ])
                ];
            }
            
            return [
                'response' => ['code' => 500],
                'body' => json_encode(['error' => 'Server error'])
            ];
        });
        
        // Simulate concurrent requests
        foreach ($configs as $config) {
            $results[] = $this->youtube->fetch_content($config);
        }
        
        // Verify mixed results (some success, some failure)
        $successCount = 0;
        $failureCount = 0;
        
        foreach ($results as $result) {
            if (is_array($result) && !empty($result)) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }
        
        $this->assertGreaterThan(0, $successCount);
        $this->assertGreaterThan(0, $failureCount);
    }

    /**
     * Helper method to set private properties
     */
    private function setPrivateProperty($object, $property, $value)
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }

    /**
     * Helper method to mock HTTP responses
     */
    private function mockHttpResponse($response)
    {
        // In a real test environment, you would use a proper HTTP mocking library
        // This is a simplified approach for demonstration
        global $mockHttpResponse;
        $mockHttpResponse = $response;
    }

    /**
     * Helper method to mock WordPress functions
     */
    private function mockWordPressFunctions()
    {
        // Mock essential WordPress functions for testing
        if (!function_exists('wp_remote_get')) {
            function wp_remote_get($url, $args = []) {
                global $mockHttpResponse;
                if (is_callable($mockHttpResponse)) {
                    return call_user_func($mockHttpResponse);
                }
                return $mockHttpResponse;
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
                return $response['body'] ?? '';
            }
        }
        
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) {
                return $thing instanceof WP_Error;
            }
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {
                // Store logged errors for verification
                global $testErrorLog;
                if (!isset($testErrorLog)) {
                    $testErrorLog = [];
                }
                $testErrorLog[] = $message;
            }
        }
    }

    /**
     * Helper method to verify error logging
     */
    private function assertErrorWasLogged($expectedMessage)
    {
        global $testErrorLog;
        $this->assertNotEmpty($testErrorLog);
        
        $found = false;
        foreach ($testErrorLog as $logEntry) {
            if (strpos($logEntry, $expectedMessage) !== false) {
                $found = true;
                break;
            }
        }
        
        $this->assertTrue($found, "Expected error message '$expectedMessage' was not logged");
    }
}

/**
 * Mock WP_Error class for testing
 */
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        
        public function __construct($code, $message) {
            $this->code = $code;
            $this->message = $message;
        }
        
        public function get_error_code() {
            return $this->code;
        }
        
        public function get_error_message() {
            return $this->message;
        }
    }
}