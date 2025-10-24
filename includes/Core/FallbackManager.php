<?php

namespace SocialFeed\Core;

/**
 * Fallback Manager for missed content detection
 * 
 * Handles scenarios where intelligent scheduling might miss content
 * by implementing fallback mechanisms and recovery strategies.
 */
class FallbackManager {
    
    private $smart_quota_manager;
    private $learning_engine;
    
    public function __construct() {
        $this->smart_quota_manager = new SmartQuotaManager();
        $this->learning_engine = new LearningEngine();
    }
    
    /**
     * Initialize fallback mechanisms
     */
    public function init() {
        // Schedule fallback checks
        add_action('social_feed_fallback_check', [$this, 'run_fallback_check']);
        
        // Hook into existing cron to add fallback logic
        add_action('social_feed_check_youtube_videos', [$this, 'check_missed_content'], 5);
        
        // Schedule daily fallback analysis
        if (!wp_next_scheduled('social_feed_fallback_analysis')) {
            wp_schedule_event(time(), 'daily', 'social_feed_fallback_analysis');
        }
        add_action('social_feed_fallback_analysis', [$this, 'analyze_missed_content']);
    }
    
    /**
     * Run fallback check for all channels
     */
    public function run_fallback_check() {
        global $wpdb;
        
        try {
            // Get all active channels that haven't been checked recently
            $channels = $this->get_channels_needing_fallback();
            
            foreach ($channels as $channel) {
                $this->perform_fallback_check($channel);
            }
            
            // Log fallback check completion
            error_log('Social Feed: Fallback check completed for ' . count($channels) . ' channels');
            
        } catch (\Exception $e) {
            error_log('Social Feed Fallback Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get channels that need fallback checking
     */
    private function get_channels_needing_fallback() {
        global $wpdb;
        
        // Get channels that haven't been checked in the last 4 hours
        $cutoff_time = date('Y-m-d H:i:s', strtotime('-4 hours'));
        
        $channels = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT channel_id, platform
            FROM {$wpdb->prefix}social_feed_schedules s
            WHERE s.status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM {$wpdb->prefix}social_feed_analytics a
                WHERE a.schedule_id = s.id 
                AND a.created_at > %s
            )
            AND s.created_at < %s
        ", $cutoff_time, $cutoff_time));
        
        return $channels;
    }
    
    /**
     * Perform fallback check for a specific channel
     */
    private function perform_fallback_check($channel) {
        // Check if we have quota available
        if (!$this->smart_quota_manager->can_make_request($channel->platform, 'fallback')) {
            return false;
        }
        
        // Get the platform instance
        $platform = $this->get_platform_instance($channel->platform);
        if (!$platform) {
            return false;
        }
        
        // Perform the check
        $result = $platform->check_new_videos($channel->channel_id, true); // Force check
        
        // Record the fallback check
        $this->record_fallback_check($channel, $result);
        
        return $result;
    }
    
    /**
     * Get platform instance
     */
    private function get_platform_instance($platform_name) {
        switch (strtolower($platform_name)) {
            case 'youtube':
                return new \SocialFeed\Platforms\YouTube();
            default:
                return null;
        }
    }
    
    /**
     * Record fallback check result
     */
    private function record_fallback_check($channel, $result) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'social_feed_analytics',
            [
                'schedule_id' => null, // Fallback checks don't have schedule IDs
                'channel_id' => $channel->channel_id,
                'platform' => $channel->platform,
                'check_type' => 'fallback',
                'content_found' => $result ? 1 : 0,
                'effectiveness_score' => $result ? 100 : 0, // Fallback is either successful or not
                'quota_used' => 1,
                'created_at' => current_time('mysql')
            ],
            ['%d', '%s', '%s', '%s', '%d', '%f', '%d', '%s']
        );
        
        // Update learning engine with fallback result
        $this->learning_engine->record_fallback_check($channel->channel_id, $result);
    }
    
    /**
     * Check for missed content during regular checks
     */
    public function check_missed_content() {
        // This runs before the regular YouTube check
        // Look for patterns that might indicate missed content
        
        $missed_patterns = $this->detect_missed_content_patterns();
        
        if (!empty($missed_patterns)) {
            foreach ($missed_patterns as $pattern) {
                $this->handle_missed_content($pattern);
            }
        }
    }
    
    /**
     * Detect patterns that might indicate missed content
     */
    private function detect_missed_content_patterns() {
        global $wpdb;
        
        $patterns = [];
        
        // Look for channels with unusual gaps in content detection
        $gap_analysis = $wpdb->get_results("
            SELECT 
                channel_id,
                platform,
                MAX(created_at) as last_check,
                COUNT(*) as check_count,
                AVG(content_found) as avg_success_rate
            FROM {$wpdb->prefix}social_feed_analytics
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY channel_id, platform
            HAVING avg_success_rate < 0.3 
            OR last_check < DATE_SUB(NOW(), INTERVAL 6 HOUR)
        ");
        
        foreach ($gap_analysis as $gap) {
            $patterns[] = [
                'type' => 'content_gap',
                'channel_id' => $gap->channel_id,
                'platform' => $gap->platform,
                'severity' => $gap->avg_success_rate < 0.1 ? 'high' : 'medium'
            ];
        }
        
        return $patterns;
    }
    
    /**
     * Handle detected missed content patterns
     */
    private function handle_missed_content($pattern) {
        switch ($pattern['type']) {
            case 'content_gap':
                $this->handle_content_gap($pattern);
                break;
        }
    }
    
    /**
     * Handle content gap pattern
     */
    private function handle_content_gap($pattern) {
        // Trigger immediate fallback check for this channel
        $channel = (object) [
            'channel_id' => $pattern['channel_id'],
            'platform' => $pattern['platform']
        ];
        
        $this->perform_fallback_check($channel);
        
        // If high severity, also adjust the schedule
        if ($pattern['severity'] === 'high') {
            $this->adjust_schedule_for_missed_content($pattern);
        }
    }
    
    /**
     * Adjust schedule to prevent future missed content
     */
    private function adjust_schedule_for_missed_content($pattern) {
        global $wpdb;
        
        // Find the schedule for this channel
        $schedule = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}social_feed_schedules
            WHERE channel_id = %s AND platform = %s AND status = 'active'
            LIMIT 1
        ", $pattern['channel_id'], $pattern['platform']));
        
        if ($schedule) {
            // Temporarily increase check frequency
            $time_slots = json_decode($schedule->time_slots, true);
            
            // Add additional check slots
            $additional_slots = $this->generate_additional_check_slots($time_slots);
            $updated_slots = array_merge($time_slots, $additional_slots);
            
            // Update the schedule temporarily (for 24 hours)
            $wpdb->update(
                $wpdb->prefix . 'social_feed_schedules',
                [
                    'time_slots' => json_encode($updated_slots),
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $schedule->id],
                ['%s', '%s'],
                ['%d']
            );
            
            // Schedule reversion after 24 hours
            wp_schedule_single_event(
                time() + (24 * 60 * 60),
                'social_feed_revert_schedule',
                [$schedule->id, $schedule->time_slots]
            );
        }
    }
    
    /**
     * Generate additional check slots for missed content recovery
     */
    private function generate_additional_check_slots($existing_slots) {
        $additional_slots = [];
        
        // Add hourly checks for the next 6 hours
        for ($i = 1; $i <= 6; $i++) {
            $check_time = date('H:i', strtotime("+{$i} hours"));
            $additional_slots[] = [
                'time' => $check_time,
                'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
                'priority' => 'high',
                'temporary' => true
            ];
        }
        
        return $additional_slots;
    }
    
    /**
     * Analyze missed content patterns daily
     */
    public function analyze_missed_content() {
        global $wpdb;
        
        try {
            // Analyze patterns over the last 7 days
            $analysis = $wpdb->get_results("
                SELECT 
                    channel_id,
                    platform,
                    DATE(created_at) as check_date,
                    COUNT(*) as total_checks,
                    SUM(content_found) as successful_checks,
                    AVG(effectiveness_score) as avg_effectiveness
                FROM {$wpdb->prefix}social_feed_analytics
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY channel_id, platform, DATE(created_at)
                ORDER BY check_date DESC
            ");
            
            $insights = $this->generate_missed_content_insights($analysis);
            
            // Store insights for dashboard
            update_option('social_feed_missed_content_insights', $insights);
            
            // Send notifications for critical issues
            $this->notify_critical_missed_content($insights);
            
        } catch (\Exception $e) {
            error_log('Social Feed Missed Content Analysis Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate insights from missed content analysis
     */
    private function generate_missed_content_insights($analysis) {
        $insights = [
            'total_channels_analyzed' => 0,
            'channels_with_issues' => 0,
            'critical_issues' => [],
            'recommendations' => []
        ];
        
        $channel_stats = [];
        
        foreach ($analysis as $record) {
            $key = $record->channel_id . '_' . $record->platform;
            
            if (!isset($channel_stats[$key])) {
                $channel_stats[$key] = [
                    'channel_id' => $record->channel_id,
                    'platform' => $record->platform,
                    'total_checks' => 0,
                    'successful_checks' => 0,
                    'avg_effectiveness' => 0,
                    'days_analyzed' => 0
                ];
            }
            
            $channel_stats[$key]['total_checks'] += $record->total_checks;
            $channel_stats[$key]['successful_checks'] += $record->successful_checks;
            $channel_stats[$key]['avg_effectiveness'] += $record->avg_effectiveness;
            $channel_stats[$key]['days_analyzed']++;
        }
        
        $insights['total_channels_analyzed'] = count($channel_stats);
        
        foreach ($channel_stats as $stats) {
            $success_rate = $stats['total_checks'] > 0 ? 
                ($stats['successful_checks'] / $stats['total_checks']) * 100 : 0;
            
            $avg_effectiveness = $stats['days_analyzed'] > 0 ? 
                $stats['avg_effectiveness'] / $stats['days_analyzed'] : 0;
            
            if ($success_rate < 50 || $avg_effectiveness < 30) {
                $insights['channels_with_issues']++;
                
                if ($success_rate < 20) {
                    $insights['critical_issues'][] = [
                        'channel_id' => $stats['channel_id'],
                        'platform' => $stats['platform'],
                        'issue' => 'Very low success rate',
                        'success_rate' => round($success_rate, 2),
                        'recommendation' => 'Consider adjusting schedule or checking channel configuration'
                    ];
                }
            }
        }
        
        // Generate general recommendations
        if ($insights['channels_with_issues'] > 0) {
            $insights['recommendations'][] = 'Review and optimize schedules for underperforming channels';
        }
        
        if (count($insights['critical_issues']) > 0) {
            $insights['recommendations'][] = 'Immediate attention required for channels with critical issues';
        }
        
        return $insights;
    }
    
    /**
     * Notify about critical missed content issues
     */
    private function notify_critical_missed_content($insights) {
        if (count($insights['critical_issues']) > 0) {
            $message = 'Social Feed Alert: ' . count($insights['critical_issues']) . ' channels have critical content detection issues.';
            
            // Log the critical issues
            error_log('Social Feed Critical Issues: ' . json_encode($insights['critical_issues']));
            
            // Could also send email notifications here if configured
        }
    }
    
    /**
     * Revert temporary schedule changes
     */
    public function revert_schedule($schedule_id, $original_time_slots) {
        global $wpdb;
        
        $wpdb->update(
            $wpdb->prefix . 'social_feed_schedules',
            [
                'time_slots' => $original_time_slots,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $schedule_id],
            ['%s', '%s'],
            ['%d']
        );
        
        error_log("Social Feed: Reverted temporary schedule changes for schedule ID {$schedule_id}");
    }
    
    /**
     * Get fallback statistics for dashboard
     */
    public function get_fallback_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_fallback_checks,
                SUM(content_found) as successful_fallbacks,
                AVG(effectiveness_score) as avg_effectiveness
            FROM {$wpdb->prefix}social_feed_analytics
            WHERE check_type = 'fallback'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        $insights = get_option('social_feed_missed_content_insights', []);
        
        return [
            'fallback_checks' => $stats->total_fallback_checks ?: 0,
            'successful_fallbacks' => $stats->successful_fallbacks ?: 0,
            'fallback_success_rate' => $stats->total_fallback_checks > 0 ? 
                round(($stats->successful_fallbacks / $stats->total_fallback_checks) * 100, 2) : 0,
            'avg_effectiveness' => round($stats->avg_effectiveness ?: 0, 2),
            'channels_with_issues' => $insights['channels_with_issues'] ?? 0,
            'critical_issues' => count($insights['critical_issues'] ?? [])
        ];
    }
}