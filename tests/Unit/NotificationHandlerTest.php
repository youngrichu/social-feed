<?php

use PHPUnit\Framework\TestCase;
use SocialFeed\Core\NotificationHandler;

/**
 * Unit tests for NotificationHandler delivery confirmation and tracking system
 */
class NotificationHandlerTest extends TestCase
{
    private $notificationHandler;
    private $testNotifications = [];
    private $testDeliveryLog = [];

    protected function setUp(): void
    {
        $this->notificationHandler = new NotificationHandler();
        
        // Mock WordPress functions
        $this->mockWordPressFunctions();
        
        // Reset test data
        $this->testNotifications = [];
        $this->testDeliveryLog = [];
    }

    /**
     * Test successful notification delivery with confirmation
     */
    public function test_successful_notification_delivery_with_confirmation()
    {
        $testContent = [
            'id' => 'test_video_123',
            'title' => 'Test Video Title',
            'platform' => 'youtube',
            'url' => 'https://youtube.com/watch?v=test123'
        ];

        // Mock successful Church App notification
        $this->mockChurchAppResponse(true, 'notification_id_456');

        $result = $this->notificationHandler->send_new_content_notification($testContent);

        // Verify successful delivery
        $this->assertTrue($result);
        
        // Verify delivery was logged
        $this->assertNotEmpty($this->testDeliveryLog);
        $lastLog = end($this->testDeliveryLog);
        
        $this->assertEquals('test_video_123', $lastLog['content_id']);
        $this->assertEquals('delivered', $lastLog['status']);
        $this->assertEquals('notification_id_456', $lastLog['notification_id']);
        $this->assertArrayHasKey('delivery_time', $lastLog);
    }

    /**
     * Test notification delivery failure and retry mechanism
     */
    public function test_notification_delivery_failure_and_retry()
    {
        $testContent = [
            'id' => 'test_video_retry',
            'title' => 'Retry Test Video',
            'platform' => 'tiktok'
        ];

        $attemptCount = 0;
        
        // Mock Church App to fail twice, then succeed
        $this->mockChurchAppResponse(function() use (&$attemptCount) {
            $attemptCount++;
            
            if ($attemptCount <= 2) {
                return ['success' => false, 'error' => 'Service temporarily unavailable'];
            }
            
            return ['success' => true, 'notification_id' => 'retry_success_789'];
        });

        $result = $this->notificationHandler->send_new_content_notification($testContent);

        // Verify eventual success
        $this->assertTrue($result);
        $this->assertEquals(3, $attemptCount);
        
        // Verify retry attempts were logged
        $retryLogs = array_filter($this->testDeliveryLog, function($log) {
            return $log['content_id'] === 'test_video_retry';
        });
        
        $this->assertCount(3, $retryLogs); // Initial attempt + 2 retries
        
        // Verify final status is delivered
        $finalLog = end($retryLogs);
        $this->assertEquals('delivered', $finalLog['status']);
    }

    /**
     * Test maximum retry attempts exceeded
     */
    public function test_max_retry_attempts_exceeded()
    {
        $testContent = [
            'id' => 'test_video_fail',
            'title' => 'Permanent Failure Test',
            'platform' => 'youtube'
        ];

        // Mock Church App to always fail
        $this->mockChurchAppResponse(['success' => false, 'error' => 'Permanent service error']);

        $result = $this->notificationHandler->send_new_content_notification($testContent);

        // Verify failure after max attempts
        $this->assertFalse($result);
        
        // Verify all attempts were logged
        $failureLogs = array_filter($this->testDeliveryLog, function($log) {
            return $log['content_id'] === 'test_video_fail';
        });
        
        $this->assertCount(3, $failureLogs); // Initial + 2 retries = 3 total attempts
        
        // Verify final status is failed
        $finalLog = end($failureLogs);
        $this->assertEquals('failed', $finalLog['status']);
    }

    /**
     * Test delivery confirmation callback processing
     */
    public function test_delivery_confirmation_callback()
    {
        $testNotificationId = 'callback_test_123';
        $testStatus = 'delivered';
        $testTimestamp = time();

        // Simulate delivery confirmation callback
        $result = $this->notificationHandler->handle_delivery_confirmation(
            $testNotificationId,
            $testStatus,
            $testTimestamp
        );

        $this->assertTrue($result);
        
        // Verify confirmation was logged
        $confirmationLogs = array_filter($this->testDeliveryLog, function($log) use ($testNotificationId) {
            return isset($log['notification_id']) && $log['notification_id'] === $testNotificationId;
        });
        
        $this->assertNotEmpty($confirmationLogs);
        
        $confirmationLog = end($confirmationLogs);
        $this->assertEquals('confirmed', $confirmationLog['status']);
        $this->assertEquals($testTimestamp, $confirmationLog['confirmed_at']);
    }

    /**
     * Test stream status change notifications
     */
    public function test_stream_status_change_notification()
    {
        $testStreamData = [
            'channel_id' => 'UC_test_stream',
            'stream_title' => 'Live Stream Test',
            'status' => 'live',
            'stream_url' => 'https://youtube.com/watch?v=live123'
        ];

        $this->mockChurchAppResponse(true, 'stream_notification_456');

        $result = $this->notificationHandler->send_stream_status_notification($testStreamData);

        $this->assertTrue($result);
        
        // Verify stream notification was logged with correct type
        $streamLogs = array_filter($this->testDeliveryLog, function($log) {
            return $log['type'] === 'stream_status';
        });
        
        $this->assertNotEmpty($streamLogs);
        
        $streamLog = end($streamLogs);
        $this->assertEquals('UC_test_stream', $streamLog['channel_id']);
        $this->assertEquals('live', $streamLog['stream_status']);
    }

    /**
     * Test notification statistics tracking
     */
    public function test_notification_statistics_tracking()
    {
        // Send multiple notifications with mixed results
        $testContents = [
            ['id' => 'success_1', 'title' => 'Success 1', 'platform' => 'youtube'],
            ['id' => 'success_2', 'title' => 'Success 2', 'platform' => 'tiktok'],
            ['id' => 'fail_1', 'title' => 'Fail 1', 'platform' => 'youtube']
        ];

        // Mock mixed responses
        $callCount = 0;
        $this->mockChurchAppResponse(function() use (&$callCount) {
            $callCount++;
            
            if ($callCount <= 2) {
                return ['success' => true, 'notification_id' => "success_$callCount"];
            }
            
            return ['success' => false, 'error' => 'Test failure'];
        });

        // Send notifications
        foreach ($testContents as $content) {
            $this->notificationHandler->send_new_content_notification($content);
        }

        // Get statistics
        $stats = $this->notificationHandler->get_delivery_statistics();

        $this->assertArrayHasKey('total_sent', $stats);
        $this->assertArrayHasKey('successful_deliveries', $stats);
        $this->assertArrayHasKey('failed_deliveries', $stats);
        $this->assertArrayHasKey('success_rate', $stats);
        
        $this->assertEquals(3, $stats['total_sent']);
        $this->assertEquals(2, $stats['successful_deliveries']);
        $this->assertEquals(1, $stats['failed_deliveries']);
        $this->assertEquals(66.67, round($stats['success_rate'], 2));
    }

    /**
     * Test notification history retrieval
     */
    public function test_notification_history_retrieval()
    {
        // Send test notifications
        $testContent = [
            'id' => 'history_test',
            'title' => 'History Test Video',
            'platform' => 'youtube'
        ];

        $this->mockChurchAppResponse(true, 'history_notification_123');
        $this->notificationHandler->send_new_content_notification($testContent);

        // Retrieve history
        $history = $this->notificationHandler->get_notification_history(10);

        $this->assertIsArray($history);
        $this->assertNotEmpty($history);
        
        $historyEntry = $history[0];
        $this->assertEquals('history_test', $historyEntry['content_id']);
        $this->assertEquals('delivered', $historyEntry['status']);
        $this->assertArrayHasKey('timestamp', $historyEntry);
    }

    /**
     * Test exponential backoff delay calculation for retries
     */
    public function test_exponential_backoff_delay_calculation()
    {
        $reflection = new ReflectionClass($this->notificationHandler);
        $method = $reflection->getMethod('calculate_retry_delay');
        $method->setAccessible(true);

        // Test delay calculation for different attempt numbers
        $delay1 = $method->invoke($this->notificationHandler, 1);
        $delay2 = $method->invoke($this->notificationHandler, 2);
        $delay3 = $method->invoke($this->notificationHandler, 3);

        // Delays should increase exponentially
        $this->assertGreaterThanOrEqual(1, $delay1); // Base delay: 1 second
        $this->assertLessThanOrEqual(3, $delay1); // With jitter: up to 3 seconds
        
        $this->assertGreaterThanOrEqual(2, $delay2); // Base delay: 2 seconds
        $this->assertLessThanOrEqual(6, $delay2); // With jitter: up to 6 seconds
        
        $this->assertGreaterThanOrEqual(4, $delay3); // Base delay: 4 seconds
        $this->assertLessThanOrEqual(12, $delay3); // With jitter: up to 12 seconds
    }

    /**
     * Test concurrent notification handling
     */
    public function test_concurrent_notification_handling()
    {
        $testContents = [
            ['id' => 'concurrent_1', 'title' => 'Concurrent 1', 'platform' => 'youtube'],
            ['id' => 'concurrent_2', 'title' => 'Concurrent 2', 'platform' => 'tiktok'],
            ['id' => 'concurrent_3', 'title' => 'Concurrent 3', 'platform' => 'youtube']
        ];

        $this->mockChurchAppResponse(true, 'concurrent_notification');

        $results = [];
        
        // Simulate concurrent notifications
        foreach ($testContents as $content) {
            $results[] = $this->notificationHandler->send_new_content_notification($content);
        }

        // Verify all notifications were processed
        $this->assertCount(3, $results);
        $this->assertTrue($results[0]);
        $this->assertTrue($results[1]);
        $this->assertTrue($results[2]);

        // Verify no race conditions in logging
        $concurrentLogs = array_filter($this->testDeliveryLog, function($log) {
            return strpos($log['content_id'], 'concurrent_') === 0;
        });
        
        $this->assertCount(3, $concurrentLogs);
        
        // Verify each notification has unique log entry
        $contentIds = array_column($concurrentLogs, 'content_id');
        $this->assertEquals(3, count(array_unique($contentIds)));
    }

    /**
     * Test notification payload validation
     */
    public function test_notification_payload_validation()
    {
        // Test with invalid content (missing required fields)
        $invalidContent = [
            'title' => 'Missing ID'
            // Missing 'id' field
        ];

        $result = $this->notificationHandler->send_new_content_notification($invalidContent);
        
        $this->assertFalse($result);
        
        // Verify validation error was logged
        $validationLogs = array_filter($this->testDeliveryLog, function($log) {
            return isset($log['error']) && strpos($log['error'], 'validation') !== false;
        });
        
        $this->assertNotEmpty($validationLogs);
    }

    /**
     * Helper method to mock WordPress functions
     */
    private function mockWordPressFunctions()
    {
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                global $testOptions;
                return $testOptions[$option] ?? $default;
            }
        }
        
        if (!function_exists('update_option')) {
            function update_option($option, $value) {
                global $testOptions;
                $testOptions[$option] = $value;
                return true;
            }
        }
        
        if (!function_exists('wp_remote_post')) {
            function wp_remote_post($url, $args) {
                global $mockChurchAppResponse;
                if (is_callable($mockChurchAppResponse)) {
                    return call_user_func($mockChurchAppResponse);
                }
                return $mockChurchAppResponse;
            }
        }
        
        if (!function_exists('wp_remote_retrieve_body')) {
            function wp_remote_retrieve_body($response) {
                if (is_array($response) && isset($response['body'])) {
                    return $response['body'];
                }
                return json_encode($response);
            }
        }
        
        if (!function_exists('error_log')) {
            function error_log($message) {
                global $testErrorLog;
                $testErrorLog[] = $message;
            }
        }
    }

    /**
     * Helper method to mock Church App notification responses
     */
    private function mockChurchAppResponse($response, $notificationId = null)
    {
        global $mockChurchAppResponse;
        
        if (is_bool($response)) {
            $mockChurchAppResponse = [
                'body' => json_encode([
                    'success' => $response,
                    'notification_id' => $notificationId ?? 'mock_notification_' . uniqid()
                ])
            ];
        } else {
            $mockChurchAppResponse = [
                'body' => json_encode($response)
            ];
        }
        
        // Also update delivery log for testing
        $this->testDeliveryLog[] = [
            'content_id' => 'mock_content',
            'status' => $response === true || (is_array($response) && $response['success']) ? 'delivered' : 'failed',
            'notification_id' => $notificationId,
            'delivery_time' => time(),
            'type' => 'new_content'
        ];
    }
}