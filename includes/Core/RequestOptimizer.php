<?php
namespace SocialFeed\Core;

class RequestOptimizer
{
    /**
     * Request batching configuration
     */
    const BATCH_CONFIG = [
        'max_ids_per_request' => 50,    // YouTube API limit
        'min_batch_size' => 5,          // Minimum items to batch
        'max_batch_delay' => 2,         // Maximum seconds to wait for batching
    ];

    private $pending_requests = [];
    private $last_batch_time;

    /**
     * Add request to batch
     */
    public function add_request($type, $id, $callback)
    {
        $this->pending_requests[$type][] = [
            'id' => $id,
            'callback' => $callback,
            'timestamp' => microtime(true)
        ];

        // Process batch if it's full or enough time has passed
        if ($this->should_process_batch($type)) {
            return $this->process_batch($type);
        }

        return null;
    }

    /**
     * Check if batch should be processed
     */
    private function should_process_batch($type)
    {
        if (!isset($this->pending_requests[$type])) {
            return false;
        }

        $batch_size = count($this->pending_requests[$type]);
        $oldest_request = min(array_column($this->pending_requests[$type], 'timestamp'));
        $time_waiting = microtime(true) - $oldest_request;

        return $batch_size >= self::BATCH_CONFIG['max_ids_per_request'] ||
            ($batch_size >= self::BATCH_CONFIG['min_batch_size'] &&
                $time_waiting >= self::BATCH_CONFIG['max_batch_delay']);
    }

    /**
     * Process batch of requests
     */
    private function process_batch($type)
    {
        if (empty($this->pending_requests[$type])) {
            return null;
        }

        $batch = $this->pending_requests[$type];
        $this->pending_requests[$type] = [];
        $this->last_batch_time = microtime(true);

        return $batch;
    }

    /**
     * Optimize API parameters
     */
    public function optimize_params($params, $operation)
    {
        switch ($operation) {
            case 'videos':
                return $this->optimize_video_params($params);
            case 'search':
                return $this->optimize_search_params($params);
            case 'playlistItems':
                return $this->optimize_playlist_params($params);
            default:
                return $params;
        }
    }

    /**
     * Optimize video request parameters
     */
    private function optimize_video_params($params)
    {
        // Only request necessary fields
        $part_mapping = [
            'snippet' => ['title', 'description', 'thumbnails/high', 'publishedAt', 'channelTitle'],
            'statistics' => ['viewCount', 'likeCount', 'commentCount'],
            'contentDetails' => ['duration'],
            'status' => ['privacyStatus']
        ];

        $parts = explode(',', $params['part']);
        $fields = 'items(id';

        foreach ($parts as $part) {
            if (isset($part_mapping[$part])) {
                $fields .= ',' . $part . '(' . implode(',', $part_mapping[$part]) . ')';
            }
        }
        $fields .= ')';

        $params['fields'] = $fields;
        return $params;
    }

    /**
     * Optimize search request parameters
     */
    private function optimize_search_params($params)
    {
        // Minimize search response data
        $params['fields'] = 'items(id/videoId)';

        // Add efficient filters
        if (!isset($params['type'])) {
            $params['type'] = 'video';
        }

        return $params;
    }

    /**
     * Optimize playlist request parameters
     */
    private function optimize_playlist_params($params)
    {
        // Only get necessary playlist item fields
        $params['fields'] = 'items(snippet(resourceId/videoId,publishedAt)),nextPageToken';
        return $params;
    }

    /**
     * Get optimal request timing
     */
    public function get_optimal_timing($operation)
    {
        $quota_manager = new QuotaManager();
        $current_usage = $quota_manager->get_current_usage();
        $daily_limit = $quota_manager->get_quota_limit();

        // Calculate remaining quota percentage
        if ($daily_limit > 0) {
            $remaining_percentage = (($daily_limit - $current_usage) / $daily_limit) * 100;
        } else {
            $remaining_percentage = 0;
        }

        // Adjust timing based on remaining quota
        if ($remaining_percentage < 20) {
            return 'high_restriction'; // Minimal essential requests only
        } elseif ($remaining_percentage < 50) {
            return 'medium_restriction'; // Reduced frequency
        } else {
            return 'normal'; // Regular operation
        }
    }
}