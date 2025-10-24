<?php
namespace SocialFeed\Core;

class ContentProcessor {
    /**
     * Content filtering rules
     */
    const CONTENT_RULES = [
        'video' => [
            'min_duration' => 60,    // Minimum duration in seconds
            'max_duration' => 7200,  // Maximum duration (2 hours)
            'excluded_titles' => ['private video', 'deleted video'],
            'required_fields' => ['title', 'description', 'thumbnail_url']
        ],
        'short' => [
            'max_duration' => 60,
            'min_duration' => 3,
            'required_fields' => ['title', 'thumbnail_url']
        ],
        'live' => [
            'min_duration' => 300,   // 5 minutes
            'required_fields' => ['title', 'scheduled_for', 'thumbnail_url']
        ]
    ];

    /**
     * Process and validate content
     */
    public function processContent($content, $type) {
        if (!isset(self::CONTENT_RULES[$type])) {
            throw new \Exception("Unknown content type: $type");
        }

        // Basic validation
        if (!$this->validateContent($content, $type)) {
            return null;
        }

        // Clean and normalize content
        $processed = $this->normalizeContent($content, $type);

        // Apply type-specific processing
        switch ($type) {
            case 'video':
                return $this->processVideo($processed);
            case 'short':
                return $this->processShort($processed);
            case 'live':
                return $this->processLiveStream($processed);
            default:
                return $processed;
        }
    }

    /**
     * Validate content against rules
     */
    private function validateContent($content, $type) {
        $rules = self::CONTENT_RULES[$type];

        // Check required fields
        foreach ($rules['required_fields'] as $field) {
            if (empty($content[$field])) {
                error_log("YouTube: Missing required field '$field' for $type");
                return false;
            }
        }

        // Check duration if available
        if (isset($content['duration'])) {
            $duration = is_numeric($content['duration']) ? 
                       $content['duration'] : 
                       $this->parseDuration($content['duration']);

            if (isset($rules['min_duration']) && $duration < $rules['min_duration']) {
                return false;
            }
            if (isset($rules['max_duration']) && $duration > $rules['max_duration']) {
                return false;
            }
        }

        // Check for excluded titles
        if (isset($rules['excluded_titles'])) {
            foreach ($rules['excluded_titles'] as $excluded) {
                if (stripos($content['title'], $excluded) !== false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Normalize content data
     */
    private function normalizeContent($content, $type) {
        $normalized = [
            'id' => $content['id'],
            'type' => $type,
            'title' => $this->sanitizeText($content['title']),
            'description' => $this->sanitizeText($content['description'] ?? ''),
            'thumbnail_url' => $this->sanitizeUrl($content['thumbnail_url']),
            'created_at' => $this->normalizeDate($content['created_at'] ?? null),
            'updated_at' => current_time('mysql'),
            'metadata' => []
        ];

        // Add type-specific fields
        switch ($type) {
            case 'video':
            case 'short':
                $normalized['duration'] = $this->parseDuration($content['duration'] ?? 0);
                $normalized['views'] = intval($content['statistics']['viewCount'] ?? 0);
                $normalized['likes'] = intval($content['statistics']['likeCount'] ?? 0);
                break;
            
            case 'live':
                $normalized['scheduled_for'] = $this->normalizeDate($content['scheduled_for']);
                $normalized['status'] = $content['status'] ?? 'upcoming';
                $normalized['viewers'] = intval($content['concurrent_viewers'] ?? 0);
                break;
        }

        return $normalized;
    }

    /**
     * Process video content
     */
    private function processVideo($content) {
        // Add video-specific processing
        $content['metadata']['is_long_form'] = $content['duration'] > 1200; // > 20 minutes
        $content['metadata']['has_chapters'] = $this->detectChapters($content['description']);
        $content['metadata']['category'] = $this->categorizeContent($content);
        
        return $content;
    }

    /**
     * Process short content
     */
    private function processShort($content) {
        // Add shorts-specific processing
        $content['metadata']['aspect_ratio'] = $this->getAspectRatio($content['thumbnail_url']);
        $content['metadata']['is_vertical'] = $this->isVerticalVideo($content['metadata']['aspect_ratio']);
        
        return $content;
    }

    /**
     * Process live stream content
     */
    private function processLiveStream($content) {
        // Add livestream-specific processing
        $content['metadata']['stream_type'] = $this->detectStreamType($content);
        $content['metadata']['is_recurring'] = $this->isRecurringStream($content);
        
        return $content;
    }

    /**
     * Utility methods
     */
    private function sanitizeText($text) {
        return wp_strip_all_tags(trim($text));
    }

    private function sanitizeUrl($url) {
        return esc_url_raw($url);
    }

    private function normalizeDate($date) {
        if (!$date) {
            return current_time('mysql');
        }
        return get_gmt_from_date($date);
    }

    private function parseDuration($duration) {
        if (is_numeric($duration)) {
            return intval($duration);
        }

        // Parse ISO 8601 duration
        try {
            $interval = new \DateInterval($duration);
            return ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function detectChapters($description) {
        // Look for common chapter patterns in description
        $patterns = [
            '/(\d{1,2}:\d{2})\s+[-–]\s+(.+?)(?=\n|$)/',
            '/(\d{1,2}:\d{2}:\d{2})\s+[-–]\s+(.+?)(?=\n|$)/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $description, $matches)) {
                return array_map(function($time, $title) {
                    return ['time' => $time, 'title' => trim($title)];
                }, $matches[1], $matches[2]);
            }
        }

        return [];
    }

    private function categorizeContent($content) {
        // Simple categorization based on title and description
        $categories = [
            'sermon' => ['ቅዳሴ', 'መንፈሳዊ', 'sermon'],
            'news' => ['news', 'ዜና', 'breaking'],
            'music' => ['music', 'ሙዚቃ', 'song'],
            'educational' => ['tutorial', 'lesson', 'how to']
        ];

        $text = strtolower($content['title'] . ' ' . $content['description']);
        
        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($text, $keyword) !== false) {
                    return $category;
                }
            }
        }

        return 'other';
    }

    private function detectStreamType($content) {
        $title = strtolower($content['title']);
        
        if (strpos($title, 'ቅዳሴ') !== false) {
            return 'liturgy';
        }
        
        if (strpos($title, 'live') !== false) {
            return 'live_event';
        }
        
        return 'standard';
    }

    private function isRecurringStream($content) {
        // Check for patterns indicating recurring streams
        $recurring_patterns = [
            '/weekly/i',
            '/daily/i',
            '/every\s+(sun|mon|tue|wed|thu|fri|sat)/i',
            '/ሁል\s+ጊዜ/i'
        ];

        foreach ($recurring_patterns as $pattern) {
            if (preg_match($pattern, $content['title']) || 
                preg_match($pattern, $content['description'])) {
                return true;
            }
        }

        return false;
    }

    private function getAspectRatio($thumbnail_url) {
        // Default to 16:9 if can't determine
        return 1.7777;
    }

    private function isVerticalVideo($aspect_ratio) {
        return $aspect_ratio < 1.0;
    }
} 