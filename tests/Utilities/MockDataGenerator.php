<?php

/**
 * Mock data generator for testing social media feed plugin
 */
class MockDataGenerator
{
    /**
     * Generate mock YouTube video data
     */
    public static function generateYouTubeVideos($count = 10, $options = [])
    {
        $videos = [];
        $baseTime = time();
        
        for ($i = 0; $i < $count; $i++) {
            $videoId = $options['video_id_prefix'] ?? 'test_video_' . $i;
            $channelId = $options['channel_id'] ?? 'UC_test_channel';
            
            $videos[] = [
                'kind' => 'youtube#video',
                'etag' => 'mock_etag_' . $i,
                'id' => $videoId,
                'snippet' => [
                    'publishedAt' => date('c', $baseTime - ($i * 3600)),
                    'channelId' => $channelId,
                    'title' => $options['title_prefix'] ?? 'Test Video ' . ($i + 1),
                    'description' => self::generateDescription($i, $options['description_length'] ?? 'medium'),
                    'thumbnails' => self::generateThumbnails($videoId),
                    'channelTitle' => $options['channel_title'] ?? 'Test Channel',
                    'tags' => self::generateTags($i, $options['tag_count'] ?? 5),
                    'categoryId' => '22', // People & Blogs
                    'liveBroadcastContent' => 'none',
                    'localized' => [
                        'title' => $options['title_prefix'] ?? 'Test Video ' . ($i + 1),
                        'description' => 'Localized description for video ' . ($i + 1)
                    ]
                ],
                'statistics' => [
                    'viewCount' => (string) rand(100, 1000000),
                    'likeCount' => (string) rand(10, 50000),
                    'dislikeCount' => (string) rand(1, 5000),
                    'favoriteCount' => '0',
                    'commentCount' => (string) rand(5, 10000)
                ],
                'contentDetails' => [
                    'duration' => self::generateDuration($options['duration_type'] ?? 'random'),
                    'dimension' => '2d',
                    'definition' => 'hd',
                    'caption' => 'false',
                    'licensedContent' => true,
                    'contentRating' => [],
                    'projection' => 'rectangular'
                ]
            ];
        }
        
        return [
            'kind' => 'youtube#videoListResponse',
            'etag' => 'mock_response_etag',
            'items' => $videos,
            'pageInfo' => [
                'totalResults' => $count,
                'resultsPerPage' => $count
            ]
        ];
    }

    /**
     * Generate mock TikTok video data
     */
    public static function generateTikTokVideos($count = 10, $options = [])
    {
        $videos = [];
        $baseTime = time();
        
        for ($i = 0; $i < $count; $i++) {
            $videoId = $options['video_id_prefix'] ?? 'tiktok_' . $i;
            $username = $options['username'] ?? 'testuser';
            
            $videos[] = [
                'id' => $videoId,
                'desc' => self::generateTikTokDescription($i, $options['description_length'] ?? 'short'),
                'createTime' => $baseTime - ($i * 3600),
                'video' => [
                    'id' => $videoId,
                    'height' => 1024,
                    'width' => 576,
                    'duration' => rand(15, 60),
                    'ratio' => '576x1024',
                    'cover' => "https://mock-tiktok.com/covers/{$videoId}.jpg",
                    'originCover' => "https://mock-tiktok.com/covers/origin_{$videoId}.jpg",
                    'dynamicCover' => "https://mock-tiktok.com/covers/dynamic_{$videoId}.gif",
                    'playAddr' => "https://mock-tiktok.com/videos/{$videoId}.mp4",
                    'downloadAddr' => "https://mock-tiktok.com/downloads/{$videoId}.mp4"
                ],
                'author' => [
                    'id' => 'user_' . $username,
                    'uniqueId' => $username,
                    'nickname' => ucfirst($username),
                    'avatarThumb' => "https://mock-tiktok.com/avatars/{$username}_thumb.jpg",
                    'avatarMedium' => "https://mock-tiktok.com/avatars/{$username}_medium.jpg",
                    'avatarLarger' => "https://mock-tiktok.com/avatars/{$username}_large.jpg",
                    'signature' => "Mock signature for {$username}",
                    'verified' => $i % 10 === 0, // Every 10th user is verified
                    'secUid' => 'mock_sec_uid_' . $username,
                    'secret' => false,
                    'ftc' => false,
                    'relation' => 0,
                    'openFavorite' => false
                ],
                'music' => [
                    'id' => 'music_' . $i,
                    'title' => "Mock Music Track " . ($i + 1),
                    'playUrl' => "https://mock-tiktok.com/music/track_{$i}.mp3",
                    'coverThumb' => "https://mock-tiktok.com/music/covers/thumb_{$i}.jpg",
                    'coverMedium' => "https://mock-tiktok.com/music/covers/medium_{$i}.jpg",
                    'coverLarge' => "https://mock-tiktok.com/music/covers/large_{$i}.jpg",
                    'authorName' => "Artist " . ($i + 1),
                    'original' => $i % 5 === 0,
                    'duration' => rand(15, 180),
                    'album' => "Mock Album " . ceil(($i + 1) / 5)
                ],
                'challenges' => self::generateTikTokChallenges($i),
                'stats' => [
                    'diggCount' => rand(10, 100000),
                    'shareCount' => rand(5, 50000),
                    'commentCount' => rand(1, 10000),
                    'playCount' => rand(100, 1000000)
                ],
                'duetInfo' => [
                    'duetFromId' => $i % 7 === 0 ? 'original_' . ($i - 1) : '0'
                ],
                'originalItem' => $i % 7 === 0,
                'officalItem' => false,
                'textExtra' => self::generateTikTokTextExtra($i),
                'secret' => false,
                'forFriend' => false,
                'digged' => false,
                'itemCommentStatus' => 0,
                'showNotPass' => false,
                'vl1' => false,
                'itemMute' => false,
                'effectStickers' => [],
                'authorStats' => [
                    'followingCount' => rand(10, 1000),
                    'followerCount' => rand(100, 100000),
                    'heartCount' => rand(1000, 1000000),
                    'videoCount' => rand(50, 5000),
                    'diggCount' => rand(500, 500000)
                ]
            ];
        }
        
        return [
            'statusCode' => 0,
            'data' => $videos,
            'hasMore' => $count >= 20
        ];
    }

    /**
     * Generate mock YouTube channel data
     */
    public static function generateYouTubeChannel($channelId = 'UC_test_channel', $options = [])
    {
        return [
            'kind' => 'youtube#channelListResponse',
            'etag' => 'mock_channel_etag',
            'pageInfo' => [
                'totalResults' => 1,
                'resultsPerPage' => 1
            ],
            'items' => [
                [
                    'kind' => 'youtube#channel',
                    'etag' => 'mock_channel_item_etag',
                    'id' => $channelId,
                    'snippet' => [
                        'title' => $options['title'] ?? 'Test Channel',
                        'description' => $options['description'] ?? 'This is a test channel for mock data generation.',
                        'customUrl' => '@' . strtolower($options['title'] ?? 'testchannel'),
                        'publishedAt' => $options['published_at'] ?? '2020-01-01T00:00:00Z',
                        'thumbnails' => self::generateThumbnails($channelId, 'channel'),
                        'defaultLanguage' => 'en',
                        'localized' => [
                            'title' => $options['title'] ?? 'Test Channel',
                            'description' => $options['description'] ?? 'This is a test channel for mock data generation.'
                        ],
                        'country' => $options['country'] ?? 'US'
                    ],
                    'statistics' => [
                        'viewCount' => (string) ($options['view_count'] ?? rand(10000, 10000000)),
                        'subscriberCount' => (string) ($options['subscriber_count'] ?? rand(1000, 1000000)),
                        'hiddenSubscriberCount' => false,
                        'videoCount' => (string) ($options['video_count'] ?? rand(50, 5000))
                    ],
                    'contentDetails' => [
                        'relatedPlaylists' => [
                            'likes' => '',
                            'uploads' => 'UU' . substr($channelId, 2)
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Generate mock API error responses
     */
    public static function generateAPIError($type = 'rate_limit', $options = [])
    {
        switch ($type) {
            case 'rate_limit':
                return [
                    'response' => ['code' => 429],
                    'body' => json_encode([
                        'error' => [
                            'code' => 403,
                            'message' => 'The request cannot be completed because you have exceeded your quota.',
                            'errors' => [
                                [
                                    'message' => 'The request cannot be completed because you have exceeded your quota.',
                                    'domain' => 'youtube.quota',
                                    'reason' => 'quotaExceeded'
                                ]
                            ]
                        ]
                    ])
                ];
                
            case 'not_found':
                return [
                    'response' => ['code' => 404],
                    'body' => json_encode([
                        'error' => [
                            'code' => 404,
                            'message' => 'The requested resource was not found.',
                            'errors' => [
                                [
                                    'message' => 'The requested resource was not found.',
                                    'domain' => 'global',
                                    'reason' => 'notFound'
                                ]
                            ]
                        ]
                    ])
                ];
                
            case 'server_error':
                return [
                    'response' => ['code' => 500],
                    'body' => json_encode([
                        'error' => [
                            'code' => 500,
                            'message' => 'Internal server error occurred.',
                            'errors' => [
                                [
                                    'message' => 'Internal server error occurred.',
                                    'domain' => 'global',
                                    'reason' => 'internalError'
                                ]
                            ]
                        ]
                    ])
                ];
                
            case 'timeout':
                return new WP_Error('http_request_timeout', 'Request timeout after 30 seconds');
                
            case 'network_error':
                return new WP_Error('http_request_failed', 'Network connection failed');
                
            default:
                return [
                    'response' => ['code' => 400],
                    'body' => json_encode([
                        'error' => [
                            'code' => 400,
                            'message' => 'Bad request',
                            'errors' => [
                                [
                                    'message' => 'Bad request',
                                    'domain' => 'global',
                                    'reason' => 'badRequest'
                                ]
                            ]
                        ]
                    ])
                ];
        }
    }

    /**
     * Generate mock notification data
     */
    public static function generateNotificationData($type = 'new_content', $options = [])
    {
        $baseData = [
            'timestamp' => time(),
            'plugin_version' => '1.0.0',
            'site_url' => 'https://test-site.com'
        ];
        
        switch ($type) {
            case 'new_content':
                return array_merge($baseData, [
                    'type' => 'new_content',
                    'content' => [
                        'id' => $options['content_id'] ?? 'test_video_123',
                        'title' => $options['title'] ?? 'Test Video Title',
                        'platform' => $options['platform'] ?? 'youtube',
                        'url' => $options['url'] ?? 'https://youtube.com/watch?v=test123',
                        'thumbnail' => $options['thumbnail'] ?? 'https://img.youtube.com/vi/test123/maxresdefault.jpg',
                        'published_at' => $options['published_at'] ?? date('c'),
                        'channel' => [
                            'id' => $options['channel_id'] ?? 'UC_test_channel',
                            'name' => $options['channel_name'] ?? 'Test Channel'
                        ]
                    ]
                ]);
                
            case 'stream_status':
                return array_merge($baseData, [
                    'type' => 'stream_status',
                    'stream' => [
                        'channel_id' => $options['channel_id'] ?? 'UC_test_stream',
                        'channel_name' => $options['channel_name'] ?? 'Test Stream Channel',
                        'stream_title' => $options['stream_title'] ?? 'Live Stream Test',
                        'status' => $options['status'] ?? 'live',
                        'stream_url' => $options['stream_url'] ?? 'https://youtube.com/watch?v=live123',
                        'thumbnail' => $options['thumbnail'] ?? 'https://img.youtube.com/vi/live123/maxresdefault.jpg',
                        'started_at' => $options['started_at'] ?? date('c')
                    ]
                ]);
                
            default:
                return $baseData;
        }
    }

    /**
     * Generate performance test data
     */
    public static function generatePerformanceTestData($scenario = 'normal_load')
    {
        switch ($scenario) {
            case 'high_load':
                return [
                    'video_count' => 200,
                    'concurrent_requests' => 10,
                    'memory_limit' => '64M',
                    'expected_memory_usage' => 45 * 1024 * 1024, // 45MB
                    'expected_execution_time' => 30, // 30 seconds
                    'error_rate_threshold' => 5 // 5%
                ];
                
            case 'memory_stress':
                return [
                    'video_count' => 500,
                    'concurrent_requests' => 5,
                    'memory_limit' => '32M',
                    'expected_memory_usage' => 28 * 1024 * 1024, // 28MB
                    'expected_execution_time' => 60, // 60 seconds
                    'error_rate_threshold' => 10 // 10%
                ];
                
            case 'api_failure':
                return [
                    'video_count' => 50,
                    'failure_rate' => 30, // 30% failure rate
                    'retry_attempts' => 3,
                    'expected_success_rate' => 85, // 85% after retries
                    'expected_execution_time' => 45 // 45 seconds with retries
                ];
                
            default: // normal_load
                return [
                    'video_count' => 50,
                    'concurrent_requests' => 3,
                    'memory_limit' => '128M',
                    'expected_memory_usage' => 15 * 1024 * 1024, // 15MB
                    'expected_execution_time' => 10, // 10 seconds
                    'error_rate_threshold' => 2 // 2%
                ];
        }
    }

    /**
     * Generate description text of varying lengths
     */
    private static function generateDescription($index, $length = 'medium')
    {
        $baseText = "This is a test video description for video number " . ($index + 1) . ". ";
        
        switch ($length) {
            case 'short':
                return $baseText . "Short description.";
                
            case 'long':
                return $baseText . str_repeat("This is additional content to make the description longer. ", 20) . 
                       "This video covers various topics and provides detailed information about the subject matter.";
                
            case 'very_long':
                return $baseText . str_repeat("This is extensive content for a very long description. ", 50) . 
                       "The video includes comprehensive coverage of multiple topics with in-depth analysis and examples.";
                
            default: // medium
                return $baseText . str_repeat("Additional description content. ", 5) . 
                       "This video provides useful information on the topic.";
        }
    }

    /**
     * Generate TikTok description with hashtags
     */
    private static function generateTikTokDescription($index, $length = 'short')
    {
        $baseText = "Test TikTok video " . ($index + 1);
        $hashtags = ['#test', '#mock', '#video', '#content', '#social'];
        
        switch ($length) {
            case 'medium':
                return $baseText . " with some additional content! " . implode(' ', array_slice($hashtags, 0, 3));
                
            case 'long':
                return $baseText . " featuring extended content with multiple elements and detailed information! " . 
                       implode(' ', $hashtags) . ' #extended #detailed';
                
            default: // short
                return $baseText . "! " . implode(' ', array_slice($hashtags, 0, 2));
        }
    }

    /**
     * Generate thumbnail URLs
     */
    private static function generateThumbnails($id, $type = 'video')
    {
        $baseUrl = $type === 'channel' ? 'https://mock-api.com/channels' : 'https://mock-api.com/videos';
        
        return [
            'default' => [
                'url' => "{$baseUrl}/{$id}/default.jpg",
                'width' => 120,
                'height' => 90
            ],
            'medium' => [
                'url' => "{$baseUrl}/{$id}/medium.jpg",
                'width' => 320,
                'height' => 180
            ],
            'high' => [
                'url' => "{$baseUrl}/{$id}/high.jpg",
                'width' => 480,
                'height' => 360
            ],
            'standard' => [
                'url' => "{$baseUrl}/{$id}/standard.jpg",
                'width' => 640,
                'height' => 480
            ],
            'maxres' => [
                'url' => "{$baseUrl}/{$id}/maxres.jpg",
                'width' => 1280,
                'height' => 720
            ]
        ];
    }

    /**
     * Generate video tags
     */
    private static function generateTags($index, $count = 5)
    {
        $allTags = [
            'test', 'mock', 'video', 'content', 'social', 'media', 'feed', 'plugin',
            'wordpress', 'youtube', 'tiktok', 'entertainment', 'tutorial', 'review',
            'comedy', 'music', 'gaming', 'technology', 'education', 'lifestyle'
        ];
        
        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $tagIndex = ($index + $i) % count($allTags);
            $tags[] = $allTags[$tagIndex];
        }
        
        return $tags;
    }

    /**
     * Generate video duration
     */
    private static function generateDuration($type = 'random')
    {
        switch ($type) {
            case 'short':
                return 'PT' . rand(30, 180) . 'S'; // 30 seconds to 3 minutes
                
            case 'medium':
                return 'PT' . rand(300, 900) . 'S'; // 5 to 15 minutes
                
            case 'long':
                return 'PT' . rand(1800, 3600) . 'S'; // 30 minutes to 1 hour
                
            default: // random
                return 'PT' . rand(60, 1800) . 'S'; // 1 minute to 30 minutes
        }
    }

    /**
     * Generate TikTok challenges
     */
    private static function generateTikTokChallenges($index)
    {
        $challenges = [
            ['id' => 'challenge_1', 'title' => 'Mock Challenge 1', 'desc' => 'Test challenge description'],
            ['id' => 'challenge_2', 'title' => 'Mock Challenge 2', 'desc' => 'Another test challenge'],
            ['id' => 'challenge_3', 'title' => 'Mock Challenge 3', 'desc' => 'Third test challenge']
        ];
        
        // Return 0-2 challenges randomly
        $challengeCount = $index % 3;
        return array_slice($challenges, 0, $challengeCount);
    }

    /**
     * Generate TikTok text extra (mentions, hashtags)
     */
    private static function generateTikTokTextExtra($index)
    {
        $extras = [];
        
        // Add hashtag
        if ($index % 2 === 0) {
            $extras[] = [
                'awemeId' => '',
                'start' => 20,
                'end' => 25,
                'hashtagName' => 'test',
                'hashtagId' => 'hashtag_test',
                'type' => 1,
                'userId' => '',
                'isCommerce' => false
            ];
        }
        
        // Add mention
        if ($index % 3 === 0) {
            $extras[] = [
                'awemeId' => '',
                'start' => 30,
                'end' => 40,
                'hashtagName' => '',
                'hashtagId' => '',
                'type' => 0,
                'userId' => 'mentioned_user',
                'isCommerce' => false
            ];
        }
        
        return $extras;
    }
}