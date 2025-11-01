<?php

use PHPUnit\Framework\TestCase;
use SocialFeed\Platforms\YouTube;
use SocialFeed\Platforms\TikTok;

/**
 * Performance tests for memory usage optimization in batch processing
 */
class MemoryOptimizationTest extends TestCase
{
    private $youtube;
    private $tiktok;
    private $initialMemory;
    private $memorySnapshots = [];

    protected function setUp(): void
    {
        $this->youtube = new YouTube();
        $this->tiktok = new TikTok();
        
        // Record initial memory usage
        $this->initialMemory = memory_get_usage(true);
        $this->memorySnapshots = [];
        
        // Mock WordPress functions
        $this->mockWordPressFunctions();
    }

    protected function tearDown(): void
    {
        // Force garbage collection
        gc_collect_cycles();
        
        // Log final memory usage
        $finalMemory = memory_get_usage(true);
        $memoryDiff = $finalMemory - $this->initialMemory;
        
        echo "\nMemory Usage Summary:\n";
        echo "Initial: " . $this->formatBytes($this->initialMemory) . "\n";
        echo "Final: " . $this->formatBytes($finalMemory) . "\n";
        echo "Difference: " . $this->formatBytes($memoryDiff) . "\n";
    }

    /**
     * Test memory usage with small batch processing
     */
    public function test_small_batch_memory_usage()
    {
        $this->takeMemorySnapshot('start_small_batch');
        
        // Mock API response for small dataset
        $this->mockYouTubeResponse($this->generateMockVideoData(10));
        
        $config = [
            'channel_id' => 'UC_small_test',
            'max_results' => 10
        ];
        
        $result = $this->youtube->fetch_content($config);
        
        $this->takeMemorySnapshot('after_small_batch');
        
        // Verify results
        $this->assertIsArray($result);
        $this->assertCount(10, $result);
        
        // Verify memory usage is reasonable for small batch
        $memoryUsed = $this->getMemoryDifference('start_small_batch', 'after_small_batch');
        $this->assertLessThan(5 * 1024 * 1024, $memoryUsed); // Less than 5MB for 10 videos
    }

    /**
     * Test memory usage with large batch processing
     */
    public function test_large_batch_memory_usage()
    {
        $this->takeMemorySnapshot('start_large_batch');
        
        // Mock API response for large dataset
        $this->mockYouTubeResponse($this->generateMockVideoData(100));
        
        $config = [
            'channel_id' => 'UC_large_test',
            'max_results' => 100
        ];
        
        $result = $this->youtube->fetch_content($config);
        
        $this->takeMemorySnapshot('after_large_batch');
        
        // Verify results
        $this->assertIsArray($result);
        $this->assertCount(100, $result);
        
        // Verify memory usage scales reasonably
        $memoryUsed = $this->getMemoryDifference('start_large_batch', 'after_large_batch');
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed); // Less than 50MB for 100 videos
        
        // Memory per video should be reasonable
        $memoryPerVideo = $memoryUsed / 100;
        $this->assertLessThan(512 * 1024, $memoryPerVideo); // Less than 512KB per video
    }

    /**
     * Test dynamic batch sizing based on available memory
     */
    public function test_dynamic_batch_sizing()
    {
        $this->takeMemorySnapshot('start_dynamic_batch');
        
        // Simulate low memory condition
        $this->setMemoryLimit('32M');
        
        // Mock large dataset
        $this->mockYouTubeResponse($this->generateMockVideoData(200));
        
        $config = [
            'channel_id' => 'UC_dynamic_test',
            'max_results' => 200
        ];
        
        $result = $this->youtube->fetch_content($config);
        
        $this->takeMemorySnapshot('after_dynamic_batch');
        
        // Verify system adapted to memory constraints
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result)); // Should process some videos
        
        // Memory usage should stay within limits
        $memoryUsed = $this->getMemoryDifference('start_dynamic_batch', 'after_dynamic_batch');
        $this->assertLessThan(25 * 1024 * 1024, $memoryUsed); // Should stay under 25MB
    }

    /**
     * Test memory optimization with TikTok batch processing
     */
    public function test_tiktok_batch_memory_optimization()
    {
        $this->takeMemorySnapshot('start_tiktok_batch');
        
        // Mock TikTok API response
        $this->mockTikTokResponse($this->generateMockTikTokData(50));
        
        $config = [
            'username' => 'test_user',
            'count' => 50
        ];
        
        $result = $this->tiktok->fetch_content($config);
        
        $this->takeMemorySnapshot('after_tiktok_batch');
        
        // Verify results
        $this->assertIsArray($result);
        $this->assertCount(50, $result);
        
        // Verify memory usage
        $memoryUsed = $this->getMemoryDifference('start_tiktok_batch', 'after_tiktok_batch');
        $this->assertLessThan(25 * 1024 * 1024, $memoryUsed); // Less than 25MB for 50 videos
    }

    /**
     * Test memory cleanup after batch processing
     */
    public function test_memory_cleanup_after_processing()
    {
        $this->takeMemorySnapshot('before_cleanup_test');
        
        // Process multiple batches
        for ($i = 0; $i < 5; $i++) {
            $this->mockYouTubeResponse($this->generateMockVideoData(20));
            
            $config = [
                'channel_id' => "UC_cleanup_test_$i",
                'max_results' => 20
            ];
            
            $result = $this->youtube->fetch_content($config);
            $this->assertIsArray($result);
            
            // Force cleanup between batches
            unset($result);
            gc_collect_cycles();
        }
        
        $this->takeMemorySnapshot('after_cleanup_test');
        
        // Memory should not accumulate significantly across batches
        $memoryUsed = $this->getMemoryDifference('before_cleanup_test', 'after_cleanup_test');
        $this->assertLessThan(10 * 1024 * 1024, $memoryUsed); // Less than 10MB total accumulation
    }

    /**
     * Test memory usage under stress conditions
     */
    public function test_memory_usage_under_stress()
    {
        $this->takeMemorySnapshot('start_stress_test');
        
        // Simulate concurrent processing of multiple channels
        $channels = [
            'UC_stress_1' => 30,
            'UC_stress_2' => 25,
            'UC_stress_3' => 35,
            'UC_stress_4' => 20
        ];
        
        $totalResults = [];
        
        foreach ($channels as $channelId => $videoCount) {
            $this->mockYouTubeResponse($this->generateMockVideoData($videoCount));
            
            $config = [
                'channel_id' => $channelId,
                'max_results' => $videoCount
            ];
            
            $result = $this->youtube->fetch_content($config);
            $totalResults = array_merge($totalResults, $result);
            
            // Take memory snapshot after each channel
            $this->takeMemorySnapshot("after_channel_$channelId");
        }
        
        $this->takeMemorySnapshot('end_stress_test');
        
        // Verify all content was processed
        $this->assertCount(110, $totalResults); // 30+25+35+20 = 110
        
        // Verify memory usage remained reasonable
        $totalMemoryUsed = $this->getMemoryDifference('start_stress_test', 'end_stress_test');
        $this->assertLessThan(75 * 1024 * 1024, $totalMemoryUsed); // Less than 75MB total
    }

    /**
     * Test memory monitoring and automatic garbage collection
     */
    public function test_automatic_memory_monitoring()
    {
        $this->takeMemorySnapshot('start_monitoring_test');
        
        // Mock large dataset that would trigger memory monitoring
        $this->mockYouTubeResponse($this->generateMockVideoData(150));
        
        $config = [
            'channel_id' => 'UC_monitoring_test',
            'max_results' => 150
        ];
        
        // Enable memory monitoring (this would be done in the actual implementation)
        $this->enableMemoryMonitoring();
        
        $result = $this->youtube->fetch_content($config);
        
        $this->takeMemorySnapshot('after_monitoring_test');
        
        // Verify automatic cleanup occurred
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
        
        // Verify memory monitoring was triggered
        $this->assertTrue($this->wasMemoryMonitoringTriggered());
        
        // Memory usage should be optimized
        $memoryUsed = $this->getMemoryDifference('start_monitoring_test', 'after_monitoring_test');
        $this->assertLessThan(60 * 1024 * 1024, $memoryUsed); // Should be optimized
    }

    /**
     * Test memory usage comparison before and after optimization
     */
    public function test_memory_optimization_comparison()
    {
        // Test without optimization (simulated)
        $this->takeMemorySnapshot('start_unoptimized');
        $unoptimizedResult = $this->simulateUnoptimizedProcessing(50);
        $this->takeMemorySnapshot('after_unoptimized');
        
        $unoptimizedMemory = $this->getMemoryDifference('start_unoptimized', 'after_unoptimized');
        
        // Test with optimization
        $this->takeMemorySnapshot('start_optimized');
        
        $this->mockYouTubeResponse($this->generateMockVideoData(50));
        $config = ['channel_id' => 'UC_comparison_test', 'max_results' => 50];
        $optimizedResult = $this->youtube->fetch_content($config);
        
        $this->takeMemorySnapshot('after_optimized');
        
        $optimizedMemory = $this->getMemoryDifference('start_optimized', 'after_optimized');
        
        // Verify optimization provides memory savings
        $memorySavings = $unoptimizedMemory - $optimizedMemory;
        $savingsPercentage = ($memorySavings / $unoptimizedMemory) * 100;
        
        $this->assertGreaterThan(0, $memorySavings);
        $this->assertGreaterThan(20, $savingsPercentage); // At least 20% memory savings
        
        echo "\nMemory Optimization Results:\n";
        echo "Unoptimized: " . $this->formatBytes($unoptimizedMemory) . "\n";
        echo "Optimized: " . $this->formatBytes($optimizedMemory) . "\n";
        echo "Savings: " . $this->formatBytes($memorySavings) . " (" . round($savingsPercentage, 2) . "%)\n";
    }

    /**
     * Test memory leak detection
     */
    public function test_memory_leak_detection()
    {
        $baselineMemory = memory_get_usage(true);
        $memoryReadings = [];
        
        // Process multiple batches and monitor memory
        for ($i = 0; $i < 10; $i++) {
            $this->mockYouTubeResponse($this->generateMockVideoData(10));
            
            $config = [
                'channel_id' => "UC_leak_test_$i",
                'max_results' => 10
            ];
            
            $result = $this->youtube->fetch_content($config);
            unset($result); // Explicitly unset to test cleanup
            
            gc_collect_cycles();
            $memoryReadings[] = memory_get_usage(true) - $baselineMemory;
        }
        
        // Analyze memory trend
        $firstHalf = array_slice($memoryReadings, 0, 5);
        $secondHalf = array_slice($memoryReadings, 5, 5);
        
        $firstHalfAvg = array_sum($firstHalf) / count($firstHalf);
        $secondHalfAvg = array_sum($secondHalf) / count($secondHalf);
        
        $memoryGrowth = $secondHalfAvg - $firstHalfAvg;
        
        // Memory growth should be minimal (less than 1MB)
        $this->assertLessThan(1024 * 1024, $memoryGrowth);
        
        echo "\nMemory Leak Analysis:\n";
        echo "First half average: " . $this->formatBytes($firstHalfAvg) . "\n";
        echo "Second half average: " . $this->formatBytes($secondHalfAvg) . "\n";
        echo "Growth: " . $this->formatBytes($memoryGrowth) . "\n";
    }

    /**
     * Helper method to take memory snapshots
     */
    private function takeMemorySnapshot($label)
    {
        $this->memorySnapshots[$label] = [
            'usage' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Helper method to calculate memory difference between snapshots
     */
    private function getMemoryDifference($startLabel, $endLabel)
    {
        if (!isset($this->memorySnapshots[$startLabel]) || !isset($this->memorySnapshots[$endLabel])) {
            return 0;
        }
        
        return $this->memorySnapshots[$endLabel]['usage'] - $this->memorySnapshots[$startLabel]['usage'];
    }

    /**
     * Helper method to format bytes in human-readable format
     */
    private function formatBytes($bytes)
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
     * Helper method to generate mock video data
     */
    private function generateMockVideoData($count)
    {
        $videos = [];
        
        for ($i = 0; $i < $count; $i++) {
            $videos[] = [
                'id' => "video_id_$i",
                'snippet' => [
                    'title' => "Test Video $i",
                    'description' => str_repeat("Description content for video $i. ", 50), // ~2KB description
                    'publishedAt' => date('c', time() - ($i * 3600)),
                    'thumbnails' => [
                        'default' => ['url' => "https://example.com/thumb_$i.jpg"],
                        'medium' => ['url' => "https://example.com/thumb_medium_$i.jpg"],
                        'high' => ['url' => "https://example.com/thumb_high_$i.jpg"]
                    ],
                    'tags' => array_fill(0, 10, "tag$i")
                ],
                'statistics' => [
                    'viewCount' => rand(1000, 1000000),
                    'likeCount' => rand(10, 10000),
                    'commentCount' => rand(5, 1000)
                ],
                'contentDetails' => [
                    'duration' => 'PT' . rand(60, 3600) . 'S'
                ]
            ];
        }
        
        return ['items' => $videos];
    }

    /**
     * Helper method to generate mock TikTok data
     */
    private function generateMockTikTokData($count)
    {
        $videos = [];
        
        for ($i = 0; $i < $count; $i++) {
            $videos[] = [
                'id' => "tiktok_id_$i",
                'desc' => str_repeat("TikTok description $i. ", 30), // ~1KB description
                'createTime' => time() - ($i * 3600),
                'video' => [
                    'playAddr' => "https://example.com/tiktok_$i.mp4",
                    'cover' => "https://example.com/tiktok_cover_$i.jpg"
                ],
                'author' => [
                    'nickname' => "user$i",
                    'avatarThumb' => "https://example.com/avatar_$i.jpg"
                ],
                'stats' => [
                    'playCount' => rand(1000, 1000000),
                    'shareCount' => rand(10, 10000),
                    'commentCount' => rand(5, 1000)
                ]
            ];
        }
        
        return ['data' => $videos];
    }

    /**
     * Helper method to simulate unoptimized processing
     */
    private function simulateUnoptimizedProcessing($count)
    {
        // Simulate memory-inefficient processing
        $largeArray = [];
        
        for ($i = 0; $i < $count; $i++) {
            // Simulate loading entire video data into memory without optimization
            $videoData = $this->generateMockVideoData(1)['items'][0];
            
            // Add unnecessary data duplication (simulating unoptimized code)
            $videoData['duplicate1'] = $videoData;
            $videoData['duplicate2'] = $videoData;
            $videoData['large_buffer'] = str_repeat('x', 100000); // 100KB buffer per video
            
            $largeArray[] = $videoData;
        }
        
        return $largeArray;
    }

    /**
     * Helper method to mock YouTube API responses
     */
    private function mockYouTubeResponse($data)
    {
        global $mockYouTubeResponse;
        $mockYouTubeResponse = [
            'response' => ['code' => 200],
            'body' => json_encode($data)
        ];
    }

    /**
     * Helper method to mock TikTok API responses
     */
    private function mockTikTokResponse($data)
    {
        global $mockTikTokResponse;
        $mockTikTokResponse = [
            'response' => ['code' => 200],
            'body' => json_encode($data)
        ];
    }

    /**
     * Helper method to set memory limit for testing
     */
    private function setMemoryLimit($limit)
    {
        ini_set('memory_limit', $limit);
    }

    /**
     * Helper method to enable memory monitoring
     */
    private function enableMemoryMonitoring()
    {
        global $memoryMonitoringEnabled;
        $memoryMonitoringEnabled = true;
    }

    /**
     * Helper method to check if memory monitoring was triggered
     */
    private function wasMemoryMonitoringTriggered()
    {
        global $memoryMonitoringTriggered;
        return $memoryMonitoringTriggered ?? false;
    }

    /**
     * Helper method to mock WordPress functions
     */
    private function mockWordPressFunctions()
    {
        if (!function_exists('wp_remote_get')) {
            function wp_remote_get($url, $args = []) {
                global $mockYouTubeResponse, $mockTikTokResponse;
                
                if (strpos($url, 'youtube') !== false) {
                    return $mockYouTubeResponse;
                } elseif (strpos($url, 'tiktok') !== false) {
                    return $mockTikTokResponse;
                }
                
                return ['response' => ['code' => 200], 'body' => '{}'];
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
        
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) {
                return false;
            }
        }
    }
}