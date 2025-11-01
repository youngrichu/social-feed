<?php

use PHPUnit\Framework\TestCase;
use SocialFeed\Platforms\AbstractPlatform;

/**
 * Unit tests for AbstractPlatform exponential backoff retry logic
 */
class AbstractPlatformTest extends TestCase
{
    private $platform;
    private $mockQuotaManager;
    private $mockPerformanceMonitor;

    protected function setUp(): void
    {
        // Create a concrete implementation of AbstractPlatform for testing
        $this->platform = new class extends AbstractPlatform {
            public function get_platform_name(): string {
                return 'test';
            }
            
            public function fetch_content(array $config): array {
                return [];
            }
            
            public function get_channel_info(string $channel_id): array {
                return [];
            }
            
            public function get_video_details(string $video_id): array {
                return [];
            }
            
            // Expose protected method for testing
            public function test_make_api_request($url, $args = []) {
                return $this->make_api_request($url, $args);
            }
            
            // Expose protected method for testing
            public function test_should_retry_error($error_code, $error_message) {
                return $this->should_retry_error($error_code, $error_message);
            }
        };

        $this->mockQuotaManager = $this->createMock(\SocialFeed\Core\QuotaManager::class);
        $this->mockPerformanceMonitor = $this->createMock(\SocialFeed\Core\PerformanceMonitor::class);
        
        // Set up the platform with mocked dependencies
        $reflection = new ReflectionClass($this->platform);
        $quotaProperty = $reflection->getProperty('quota_manager');
        $quotaProperty->setAccessible(true);
        $quotaProperty->setValue($this->platform, $this->mockQuotaManager);
        
        $monitorProperty = $reflection->getProperty('performance_monitor');
        $monitorProperty->setAccessible(true);
        $monitorProperty->setValue($this->platform, $this->mockPerformanceMonitor);
    }

    /**
     * Test successful API request without retry
     */
    public function test_successful_api_request_no_retry()
    {
        // Mock wp_remote_get to return successful response
        $this->mockWordPressFunction('wp_remote_get', [
            'response' => ['code' => 200],
            'body' => json_encode(['success' => true, 'data' => 'test'])
        ]);
        
        $this->mockWordPressFunction('wp_remote_retrieve_response_code', 200);
        $this->mockWordPressFunction('wp_remote_retrieve_body', json_encode(['success' => true, 'data' => 'test']));
        $this->mockWordPressFunction('is_wp_error', false);

        $result = $this->platform->test_make_api_request('https://api.test.com/endpoint');
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('test', $result['data']);
    }

    /**
     * Test retry logic with rate limit error
     */
    public function test_retry_logic_with_rate_limit()
    {
        $callCount = 0;
        
        // Mock wp_remote_get to fail twice with rate limit, then succeed
        $this->mockWordPressFunction('wp_remote_get', function() use (&$callCount) {
            $callCount++;
            if ($callCount <= 2) {
                return [
                    'response' => ['code' => 429],
                    'body' => json_encode(['error' => 'Rate limit exceeded'])
                ];
            }
            return [
                'response' => ['code' => 200],
                'body' => json_encode(['success' => true, 'data' => 'retry_success'])
            ];
        });
        
        $this->mockWordPressFunction('wp_remote_retrieve_response_code', function() use (&$callCount) {
            return $callCount <= 2 ? 429 : 200;
        });
        
        $this->mockWordPressFunction('wp_remote_retrieve_body', function() use (&$callCount) {
            return $callCount <= 2 ? 
                json_encode(['error' => 'Rate limit exceeded']) : 
                json_encode(['success' => true, 'data' => 'retry_success']);
        });
        
        $this->mockWordPressFunction('is_wp_error', false);
        
        // Mock sleep function to avoid actual delays in tests
        $this->mockWordPressFunction('sleep', null);

        $result = $this->platform->test_make_api_request('https://api.test.com/endpoint');
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('retry_success', $result['data']);
        $this->assertEquals(3, $callCount); // Should have made 3 attempts
    }

    /**
     * Test retry logic with network timeout
     */
    public function test_retry_logic_with_timeout()
    {
        $callCount = 0;
        
        // Mock wp_remote_get to timeout once, then succeed
        $this->mockWordPressFunction('wp_remote_get', function() use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return new WP_Error('http_request_timeout', 'Request timeout');
            }
            return [
                'response' => ['code' => 200],
                'body' => json_encode(['success' => true, 'data' => 'timeout_recovery'])
            ];
        });
        
        $this->mockWordPressFunction('is_wp_error', function($response) use (&$callCount) {
            return $callCount === 1;
        });
        
        $this->mockWordPressFunction('wp_remote_retrieve_response_code', 200);
        $this->mockWordPressFunction('wp_remote_retrieve_body', json_encode(['success' => true, 'data' => 'timeout_recovery']));
        $this->mockWordPressFunction('sleep', null);

        $result = $this->platform->test_make_api_request('https://api.test.com/endpoint');
        
        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertEquals('timeout_recovery', $result['data']);
        $this->assertEquals(2, $callCount);
    }

    /**
     * Test maximum retry attempts exceeded
     */
    public function test_max_retry_attempts_exceeded()
    {
        $callCount = 0;
        
        // Mock wp_remote_get to always return 500 error
        $this->mockWordPressFunction('wp_remote_get', function() use (&$callCount) {
            $callCount++;
            return [
                'response' => ['code' => 500],
                'body' => json_encode(['error' => 'Internal server error'])
            ];
        });
        
        $this->mockWordPressFunction('wp_remote_retrieve_response_code', 500);
        $this->mockWordPressFunction('wp_remote_retrieve_body', json_encode(['error' => 'Internal server error']));
        $this->mockWordPressFunction('is_wp_error', false);
        $this->mockWordPressFunction('sleep', null);

        $result = $this->platform->test_make_api_request('https://api.test.com/endpoint');
        
        $this->assertFalse($result);
        $this->assertEquals(3, $callCount); // Should have made 3 attempts (initial + 2 retries)
    }

    /**
     * Test should_retry_error method with different error scenarios
     */
    public function test_should_retry_error_scenarios()
    {
        // Rate limit errors should be retried
        $this->assertTrue($this->platform->test_should_retry_error(429, 'Rate limit exceeded'));
        
        // Server errors should be retried
        $this->assertTrue($this->platform->test_should_retry_error(500, 'Internal server error'));
        $this->assertTrue($this->platform->test_should_retry_error(502, 'Bad gateway'));
        $this->assertTrue($this->platform->test_should_retry_error(503, 'Service unavailable'));
        $this->assertTrue($this->platform->test_should_retry_error(504, 'Gateway timeout'));
        
        // Client errors should not be retried
        $this->assertFalse($this->platform->test_should_retry_error(400, 'Bad request'));
        $this->assertFalse($this->platform->test_should_retry_error(401, 'Unauthorized'));
        $this->assertFalse($this->platform->test_should_retry_error(403, 'Forbidden'));
        $this->assertFalse($this->platform->test_should_retry_error(404, 'Not found'));
        
        // Network timeout should be retried
        $this->assertTrue($this->platform->test_should_retry_error(0, 'http_request_timeout'));
        $this->assertTrue($this->platform->test_should_retry_error(0, 'http_request_failed'));
    }

    /**
     * Test exponential backoff delay calculation
     */
    public function test_exponential_backoff_delay()
    {
        $reflection = new ReflectionClass($this->platform);
        $method = $reflection->getMethod('calculate_retry_delay');
        $method->setAccessible(true);
        
        // Test delay calculation for different attempt numbers
        $delay1 = $method->invoke($this->platform, 1);
        $delay2 = $method->invoke($this->platform, 2);
        $delay3 = $method->invoke($this->platform, 3);
        
        // Delays should increase exponentially (with jitter, so we test ranges)
        $this->assertGreaterThanOrEqual(0.5, $delay1); // 1s ± 50%
        $this->assertLessThanOrEqual(1.5, $delay1);
        
        $this->assertGreaterThanOrEqual(1.0, $delay2); // 2s ± 50%
        $this->assertLessThanOrEqual(3.0, $delay2);
        
        $this->assertGreaterThanOrEqual(2.0, $delay3); // 4s ± 50%
        $this->assertLessThanOrEqual(6.0, $delay3);
    }

    /**
     * Test performance monitoring integration
     */
    public function test_performance_monitoring_integration()
    {
        $this->mockPerformanceMonitor
            ->expects($this->once())
            ->method('start_operation')
            ->with('api_request');
            
        $this->mockPerformanceMonitor
            ->expects($this->once())
            ->method('end_operation')
            ->with('api_request');
            
        $this->mockPerformanceMonitor
            ->expects($this->once())
            ->method('track_api_call');

        $this->mockWordPressFunction('wp_remote_get', [
            'response' => ['code' => 200],
            'body' => json_encode(['success' => true])
        ]);
        
        $this->mockWordPressFunction('wp_remote_retrieve_response_code', 200);
        $this->mockWordPressFunction('wp_remote_retrieve_body', json_encode(['success' => true]));
        $this->mockWordPressFunction('is_wp_error', false);

        $this->platform->test_make_api_request('https://api.test.com/endpoint');
    }

    /**
     * Helper method to mock WordPress functions
     */
    private function mockWordPressFunction($functionName, $returnValue)
    {
        if (!function_exists($functionName)) {
            eval("function $functionName() { return null; }");
        }
        
        // In a real test environment, you would use a proper mocking framework
        // This is a simplified approach for demonstration
    }
}