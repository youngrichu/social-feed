<?php
namespace SocialFeed\Admin;

use SocialFeed\Core\SmartQuotaManager;
use SocialFeed\Core\LearningEngine;

class MonitoringDashboard {
    private $smart_quota_manager;
    private $learning_engine;

    public function __construct() {
        $this->smart_quota_manager = new SmartQuotaManager();
        $this->learning_engine = new LearningEngine();
    }

    /**
     * Initialize the monitoring dashboard
     */
    public function init() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_get_dashboard_data', [$this, 'get_dashboard_data']);
        add_action('wp_ajax_get_quota_trends', [$this, 'get_quota_trends']);
        add_action('wp_ajax_get_schedule_performance', [$this, 'get_schedule_performance']);
        add_action('wp_ajax_get_system_health', [$this, 'get_system_health']);
    }

    /**
     * Add admin menu for monitoring dashboard
     */
    public function add_admin_menu() {
        add_submenu_page(
            'social-feed',
            'Monitoring Dashboard',
            'Dashboard',
            'manage_options',
            'social-feed-dashboard',
            [$this, 'render_dashboard_page']
        );
    }

    /**
     * Enqueue scripts and styles for the dashboard
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'social-feed_page_social-feed-dashboard') {
            return;
        }

        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '3.9.1', true);
        wp_enqueue_script('dashboard-js', SOCIAL_FEED_PLUGIN_URL . 'assets/js/dashboard.js', ['jquery', 'chart-js'], SOCIAL_FEED_VERSION, true);
        wp_enqueue_style('dashboard-css', SOCIAL_FEED_PLUGIN_URL . 'assets/css/dashboard.css', [], SOCIAL_FEED_VERSION);

        wp_localize_script('dashboard-js', 'dashboardAjax', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dashboard_nonce')
        ]);
    }

    /**
     * Render the main dashboard page
     */
    public function render_dashboard_page() {
        ?>
        <div class="wrap">
            <h1>Social Feed Monitoring Dashboard</h1>
            
            <!-- Dashboard Overview Cards -->
            <div class="dashboard-overview">
                <div class="overview-card quota-card">
                    <h3>Daily Quota Usage</h3>
                    <div class="quota-meter">
                        <div class="quota-progress" id="quota-progress"></div>
                    </div>
                    <div class="quota-stats">
                        <span id="quota-used">0</span> / <span id="quota-limit">10,000</span>
                        <small>API calls today</small>
                    </div>
                </div>

                <div class="overview-card efficiency-card">
                    <h3>Efficiency Score</h3>
                    <div class="efficiency-score" id="efficiency-score">0%</div>
                    <small>Quota optimization</small>
                </div>

                <div class="overview-card schedules-card">
                    <h3>Active Schedules</h3>
                    <div class="schedule-count" id="active-schedules">0</div>
                    <small>Currently running</small>
                </div>

                <div class="overview-card alerts-card">
                    <h3>System Health</h3>
                    <div class="health-status" id="health-status">
                        <span class="status-indicator"></span>
                        <span class="status-text">Checking...</span>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="dashboard-charts">
                <div class="chart-container">
                    <h3>Quota Usage Trends (Last 7 Days)</h3>
                    <canvas id="quotaTrendsChart" width="400" height="200"></canvas>
                </div>

                <div class="chart-container">
                    <h3>Schedule Performance</h3>
                    <canvas id="schedulePerformanceChart" width="400" height="200"></canvas>
                </div>
            </div>

            <!-- Real-time Activity Feed -->
            <div class="activity-section">
                <h3>Recent Activity</h3>
                <div class="activity-feed" id="activity-feed">
                    <div class="activity-item loading">
                        <span class="activity-time">Loading...</span>
                        <span class="activity-message">Fetching recent activity...</span>
                    </div>
                </div>
            </div>

            <!-- Schedule Status Table -->
            <div class="schedules-section">
                <h3>Schedule Status Overview</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Channel</th>
                            <th>Platform</th>
                            <th>Status</th>
                            <th>Next Check</th>
                            <th>Effectiveness</th>
                            <th>Last Content</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="schedules-table-body">
                        <tr>
                            <td colspan="7" class="loading">Loading schedule data...</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- System Recommendations -->
            <div class="recommendations-section">
                <h3>AI Recommendations</h3>
                <div class="recommendations-list" id="recommendations-list">
                    <div class="recommendation loading">
                        <span class="recommendation-icon">🤖</span>
                        <span class="recommendation-text">Analyzing system performance...</span>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get dashboard data via AJAX
     */
    public function get_dashboard_data() {
        check_ajax_referer('dashboard_nonce', 'nonce');

        global $wpdb;

        // Get quota usage
        $quota_stats = $this->smart_quota_manager->get_quota_stats();
        
        // Get active schedules count
        $active_schedules = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}social_feed_schedules 
            WHERE status = 'active'
        ");

        // Calculate efficiency score
        $efficiency_score = $this->calculate_efficiency_score();

        // Get system health
        $health_status = $this->get_system_health_status();

        // Get recent activity
        $recent_activity = $this->get_recent_activity();

        // Get schedule status
        $schedule_status = $this->get_schedule_status();

        // Get AI recommendations
        $recommendations = $this->learning_engine->get_optimization_suggestions();

        wp_send_json_success([
            'quota_stats' => $quota_stats,
            'active_schedules' => intval($active_schedules),
            'efficiency_score' => $efficiency_score,
            'health_status' => $health_status,
            'recent_activity' => $recent_activity,
            'schedule_status' => $schedule_status,
            'recommendations' => $recommendations
        ]);
    }

    /**
     * Get quota trends data for charts
     */
    public function get_quota_trends() {
        check_ajax_referer('dashboard_nonce', 'nonce');

        global $wpdb;

        $trends = $wpdb->get_results("
            SELECT 
                DATE(created_at) as date,
                SUM(quota_used) as total_quota,
                COUNT(*) as api_calls
            FROM {$wpdb->prefix}social_feed_quota_usage 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");

        $labels = [];
        $quota_data = [];
        $calls_data = [];

        foreach ($trends as $trend) {
            $labels[] = date('M j', strtotime($trend->date));
            $quota_data[] = intval($trend->total_quota);
            $calls_data[] = intval($trend->api_calls);
        }

        wp_send_json_success([
            'labels' => $labels,
            'quota_data' => $quota_data,
            'calls_data' => $calls_data
        ]);
    }

    /**
     * Get schedule performance data
     */
    public function get_schedule_performance() {
        check_ajax_referer('dashboard_nonce', 'nonce');

        global $wpdb;

        $performance = $wpdb->get_results("
            SELECT 
                s.channel_id,
                s.platform,
                AVG(a.effectiveness_score) as avg_effectiveness,
                COUNT(a.id) as total_checks,
                SUM(CASE WHEN a.content_found > 0 THEN 1 ELSE 0 END) as successful_checks
            FROM {$wpdb->prefix}social_feed_schedules s
            LEFT JOIN {$wpdb->prefix}social_feed_analytics a ON s.id = a.schedule_id
            WHERE s.status = 'active' AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY s.id
            ORDER BY avg_effectiveness DESC
        ");

        $labels = [];
        $effectiveness_data = [];
        $success_rate_data = [];

        foreach ($performance as $perf) {
            $labels[] = substr($perf->channel_id, 0, 20) . '...';
            $effectiveness_data[] = round($perf->avg_effectiveness, 2);
            $success_rate = $perf->total_checks > 0 ? ($perf->successful_checks / $perf->total_checks) * 100 : 0;
            $success_rate_data[] = round($success_rate, 2);
        }

        wp_send_json_success([
            'labels' => $labels,
            'effectiveness_data' => $effectiveness_data,
            'success_rate_data' => $success_rate_data
        ]);
    }

    /**
     * Get system health status
     */
    public function get_system_health() {
        check_ajax_referer('dashboard_nonce', 'nonce');

        $health_status = $this->get_system_health_status();
        wp_send_json_success($health_status);
    }

    /**
     * Calculate efficiency score based on quota usage vs content found
     */
    private function calculate_efficiency_score() {
        global $wpdb;

        $today = date('Y-m-d');
        
        $stats = $wpdb->get_row("
            SELECT 
                SUM(quota_used) as total_quota,
                SUM(content_found) as total_content
            FROM {$wpdb->prefix}social_feed_analytics 
            WHERE DATE(created_at) = '$today'
        ");

        if (!$stats || $stats->total_quota == 0) {
            return 0;
        }

        // Calculate efficiency as content found per quota unit used
        $efficiency = ($stats->total_content / $stats->total_quota) * 100;
        return min(100, round($efficiency, 1));
    }

    /**
     * Get system health status
     */
    private function get_system_health_status() {
        $issues = [];
        $status = 'healthy';

        // Check quota usage
        $quota_stats = $this->smart_quota_manager->get_quota_stats();
        if ($quota_stats['usage_percentage'] > 90) {
            $issues[] = 'High quota usage detected';
            $status = 'warning';
        }

        // Check for failed schedules
        global $wpdb;
        $failed_schedules = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}social_feed_schedules 
            WHERE status = 'error' OR last_error IS NOT NULL
        ");

        if ($failed_schedules > 0) {
            $issues[] = "$failed_schedules schedule(s) have errors";
            $status = 'error';
        }

        // Check cron jobs
        if (!wp_next_scheduled('social_feed_intelligent_check')) {
            $issues[] = 'Intelligent scheduling cron not active';
            $status = 'error';
        }

        return [
            'status' => $status,
            'issues' => $issues,
            'message' => empty($issues) ? 'All systems operational' : implode(', ', $issues)
        ];
    }

    /**
     * Get recent activity for the activity feed
     */
    private function get_recent_activity() {
        global $wpdb;

        $activities = $wpdb->get_results("
            SELECT 
                a.*,
                s.channel_id,
                s.platform
            FROM {$wpdb->prefix}social_feed_analytics a
            LEFT JOIN {$wpdb->prefix}social_feed_schedules s ON a.schedule_id = s.id
            ORDER BY a.created_at DESC
            LIMIT 10
        ");

        $formatted_activities = [];
        foreach ($activities as $activity) {
            $formatted_activities[] = [
                'time' => human_time_diff(strtotime($activity->created_at), current_time('timestamp')) . ' ago',
                'message' => $this->format_activity_message($activity),
                'type' => $activity->content_found > 0 ? 'success' : 'info'
            ];
        }

        return $formatted_activities;
    }

    /**
     * Format activity message
     */
    private function format_activity_message($activity) {
        $channel = substr($activity->channel_id, 0, 20) . '...';
        
        if ($activity->content_found > 0) {
            return "Found {$activity->content_found} new content on {$activity->platform} channel {$channel}";
        } else {
            return "Checked {$activity->platform} channel {$channel} - no new content";
        }
    }

    /**
     * Get schedule status for the table
     */
    private function get_schedule_status() {
        global $wpdb;

        $schedules = $wpdb->get_results("
            SELECT 
                s.*,
                AVG(a.effectiveness_score) as avg_effectiveness,
                MAX(a.created_at) as last_check,
                SUM(CASE WHEN a.content_found > 0 THEN 1 ELSE 0 END) as content_found_count
            FROM {$wpdb->prefix}social_feed_schedules s
            LEFT JOIN {$wpdb->prefix}social_feed_analytics a ON s.id = a.schedule_id
            WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) OR a.id IS NULL
            GROUP BY s.id
            ORDER BY s.created_at DESC
        ");

        $formatted_schedules = [];
        foreach ($schedules as $schedule) {
            $next_check = $this->calculate_next_check_time($schedule);
            
            $formatted_schedules[] = [
                'channel' => substr($schedule->channel_id, 0, 30) . '...',
                'platform' => ucfirst($schedule->platform),
                'status' => $schedule->status,
                'next_check' => $next_check,
                'effectiveness' => $schedule->avg_effectiveness ? round($schedule->avg_effectiveness, 1) . '%' : 'N/A',
                'last_content' => $schedule->last_check ? human_time_diff(strtotime($schedule->last_check), current_time('timestamp')) . ' ago' : 'Never',
                'actions' => $schedule->id
            ];
        }

        return $formatted_schedules;
    }

    /**
     * Calculate next check time for a schedule
     */
    private function calculate_next_check_time($schedule) {
        // This would integrate with the IntelligentScheduler to get the actual next check time
        // For now, we'll provide a simplified calculation
        $slots = json_decode($schedule->time_slots, true);
        if (empty($slots)) {
            return 'Not scheduled';
        }

        $now = current_time('H:i');
        $today = current_time('w'); // Day of week

        foreach ($slots as $slot) {
            if (in_array($today, $slot['days']) && $slot['time'] > $now) {
                return 'Today at ' . $slot['time'];
            }
        }

        return 'Next available slot';
    }
}