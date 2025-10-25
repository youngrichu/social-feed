<?php
namespace SocialFeed\API;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class RestAPI {
    /**
     * API namespace
     */
    const API_NAMESPACE = 'social-feed/v1';

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Combined feed endpoint
        register_rest_route(self::API_NAMESPACE, '/feeds', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_feeds'],
                'permission_callback' => [$this, 'check_authorization'],
                'args' => [
                    'platform' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'required' => false,
                    ],
                    'type' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'required' => false,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'default' => 1,
                        'minimum' => 1,
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'default' => 12,
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                    'sort' => [
                        'type' => 'string',
                        'default' => 'date',
                        'enum' => ['date', 'popularity'],
                    ],
                    'order' => [
                        'type' => 'string',
                        'default' => 'desc',
                        'enum' => ['asc', 'desc'],
                    ],
                ],
            ],
        ]);

        // Live streams endpoint
        register_rest_route(self::API_NAMESPACE, '/live-streams', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_live_streams'],
                'permission_callback' => [$this, 'check_authorization'],
                'args' => [
                    'platform' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'required' => false,
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['live', 'upcoming', 'ended'],
                        'required' => false,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'default' => 1,
                        'minimum' => 1,
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'default' => 12,
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                ],
            ],
        ]);

        // Register device for notifications
        register_rest_route(self::API_NAMESPACE, '/notifications/register', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'register_device'],
                'permission_callback' => [$this, 'check_authorization'],
                'args' => [
                    'device_token' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                    'platform' => [
                        'type' => 'string',
                        'required' => true,
                        'enum' => ['youtube', 'tiktok', 'facebook', 'instagram'],
                    ],
                    'notification_types' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string',
                            'enum' => ['video', 'live'],
                        ],
                        'default' => ['video', 'live'],
                    ],
                ],
            ],
        ]);

        // Unregister device from notifications
        register_rest_route(self::API_NAMESPACE, '/notifications/unregister', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'unregister_device'],
                'permission_callback' => [$this, 'check_authorization'],
                'args' => [
                    'device_token' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        // Intelligent Scheduling API endpoints
        $this->register_scheduling_routes();
    }

    /**
     * Register intelligent scheduling API routes
     */
    private function register_scheduling_routes() {
        // Get all schedules
        register_rest_route(self::API_NAMESPACE, '/schedules', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_schedules'],
                'permission_callback' => [$this, 'check_admin_authorization'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_schedule'],
                'permission_callback' => [$this, 'check_admin_authorization'],
                'args' => [
                    'channel_id' => [
                        'type' => 'string',
                        'required' => true,
                    ],
                    'platform' => [
                        'type' => 'string',
                        'required' => true,
                        'enum' => ['youtube', 'tiktok', 'facebook', 'instagram'],
                    ],
                    'time_slots' => [
                        'type' => 'array',
                        'required' => true,
                    ],
                    'timezone' => [
                        'type' => 'string',
                        'default' => 'UTC',
                    ],
                    'priority' => [
                        'type' => 'integer',
                        'default' => 5,
                        'minimum' => 1,
                        'maximum' => 10,
                    ],
                ],
            ],
        ]);

        // Individual schedule operations
        register_rest_route(self::API_NAMESPACE, '/schedules/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_schedule'],
                'permission_callback' => [$this, 'check_admin_authorization'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_schedule'],
                'permission_callback' => [$this, 'check_admin_authorization'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_schedule'],
                'permission_callback' => [$this, 'check_admin_authorization'],
            ],
        ]);

        // Schedule status operations
        register_rest_route(self::API_NAMESPACE, '/schedules/(?P<id>\d+)/toggle', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'toggle_schedule'],
                'permission_callback' => [$this, 'check_admin_authorization'],
                'args' => [
                    'status' => [
                        'type' => 'string',
                        'required' => true,
                        'enum' => ['active', 'paused', 'error'],
                    ],
                ],
            ],
        ]);

        // Quota management endpoints
        register_rest_route(self::API_NAMESPACE, '/quota/stats', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_quota_stats'],
                'permission_callback' => [$this, 'check_admin_authorization'],
            ],
        ]);

        register_rest_route(self::API_NAMESPACE, '/quota/usage', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_quota_usage'],
                'permission_callback' => [$this, 'check_admin_authorization'],
                'args' => [
                    'days' => [
                        'type' => 'integer',
                        'default' => 7,
                        'minimum' => 1,
                        'maximum' => 30,
                    ],
                ],
            ],
        ]);

        // Analytics endpoints
        register_rest_route(self::API_NAMESPACE, '/analytics/performance', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_performance_analytics'],
                'permission_callback' => [$this, 'check_admin_authorization'],
                'args' => [
                    'schedule_id' => [
                        'type' => 'integer',
                        'required' => false,
                    ],
                    'days' => [
                        'type' => 'integer',
                        'default' => 7,
                        'minimum' => 1,
                        'maximum' => 30,
                    ],
                ],
            ],
        ]);

        // AI recommendations endpoint
        register_rest_route(self::API_NAMESPACE, '/recommendations', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_recommendations'],
                'permission_callback' => [$this, 'check_admin_authorization'],
            ],
        ]);

        // Manual trigger endpoint
        register_rest_route(self::API_NAMESPACE, '/schedules/(?P<id>\d+)/trigger', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'trigger_schedule'],
                'permission_callback' => [$this, 'check_admin_authorization'],
            ],
        ]);
    }

    /**
     * Check if request is authorized
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_authorization($request) {
        // Check for JWT token
        $auth_header = $request->get_header('Authorization');
        if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
            return new WP_Error(
                'rest_forbidden',
                'Missing or invalid authorization header',
                ['status' => 401]
            );
        }

        $token = substr($auth_header, 7);

        try {
            // Extract token parts
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return new WP_Error(
                    'rest_forbidden',
                    'Invalid token format',
                    ['status' => 401]
                );
            }

            // Decode payload
            $payload = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1]));
            $payload_data = json_decode($payload);

            if (!$payload_data || !isset($payload_data->id)) {
                return new WP_Error(
                    'rest_forbidden',
                    'Invalid token payload',
                    ['status' => 401]
                );
            }

            // Get user by ID
            $user = get_user_by('id', $payload_data->id);
            if (!$user) {
                return new WP_Error(
                    'rest_forbidden',
                    'User not found',
                    ['status' => 401]
                );
            }

            return true;

        } catch (\Exception $e) {
            return new WP_Error(
                'rest_forbidden',
                $e->getMessage(),
                ['status' => 401]
            );
        }
    }

    /**
     * Get combined feeds
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_feeds($request) {
        try {
            $params = $request->get_params();
            $feed_service = new \SocialFeed\Services\FeedService();
            
            $result = $feed_service->get_feeds(
                $params['platform'] ?? [],
                $params['type'] ?? [],
                $params['page'],
                $params['per_page'],
                $params['sort'],
                $params['order']
            );

            return new WP_REST_Response($result);
        } catch (\Exception $e) {
            error_log('Social Feed API Error: ' . $e->getMessage());
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get live streams
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_live_streams($request) {
        try {
            $params = $request->get_params();
            $stream_service = new \SocialFeed\Services\StreamService();
            
            $streams = $stream_service->get_streams(
                $params['platform'] ?? [],
                $params['status'] ?? null,
                $params['page'],
                $params['per_page']
            );

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $streams,
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Check admin authorization
     */
    public function check_admin_authorization($request) {
        return current_user_can('manage_options');
    }

    // Intelligent Scheduling API Methods

    /**
     * Get all schedules
     */
    public function get_schedules($request) {
        global $wpdb;
        
        try {
            $schedules = $wpdb->get_results("
                SELECT s.*, 
                       AVG(a.effectiveness_score) as avg_effectiveness,
                       COUNT(a.id) as total_checks,
                       MAX(a.created_at) as last_check
                FROM {$wpdb->prefix}social_feed_schedules s
                LEFT JOIN {$wpdb->prefix}social_feed_analytics a ON s.id = a.schedule_id
                GROUP BY s.id
                ORDER BY s.created_at DESC
            ");

            foreach ($schedules as &$schedule) {
                $schedule->time_slots = json_decode($schedule->time_slots, true);
                $schedule->avg_effectiveness = $schedule->avg_effectiveness ? round($schedule->avg_effectiveness, 2) : null;
            }

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $schedules
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Create a new schedule
     */
    public function create_schedule($request) {
        global $wpdb;
        
        try {
            $params = $request->get_params();
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'social_feed_schedules',
                [
                    'channel_id' => $params['channel_id'],
                    'platform' => $params['platform'],
                    'time_slots' => json_encode($params['time_slots']),
                    'timezone' => $params['timezone'],
                    'priority' => $params['priority'],
                    'status' => 'active',
                    'created_at' => current_time('mysql')
                ],
                ['%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );

            if ($result === false) {
                return new WP_Error(
                    'creation_failed',
                    'Failed to create schedule',
                    ['status' => 500]
                );
            }

            $schedule_id = $wpdb->insert_id;
            
            return new WP_REST_Response([
                'status' => 'success',
                'message' => 'Schedule created successfully',
                'data' => ['id' => $schedule_id]
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get a specific schedule
     */
    public function get_schedule($request) {
        global $wpdb;
        
        try {
            $schedule_id = $request['id'];
            
            $schedule = $wpdb->get_row($wpdb->prepare("
                SELECT s.*, 
                       AVG(a.effectiveness_score) as avg_effectiveness,
                       COUNT(a.id) as total_checks
                FROM {$wpdb->prefix}social_feed_schedules s
                LEFT JOIN {$wpdb->prefix}social_feed_analytics a ON s.id = a.schedule_id
                WHERE s.id = %d
                GROUP BY s.id
            ", $schedule_id));

            if (!$schedule) {
                return new WP_Error(
                    'schedule_not_found',
                    'Schedule not found',
                    ['status' => 404]
                );
            }

            $schedule->time_slots = json_decode($schedule->time_slots, true);
            $schedule->avg_effectiveness = $schedule->avg_effectiveness ? round($schedule->avg_effectiveness, 2) : null;

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $schedule
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Update a schedule
     */
    public function update_schedule($request) {
        global $wpdb;
        
        try {
            $schedule_id = $request['id'];
            $params = $request->get_params();
            
            $update_data = [];
            $update_format = [];
            
            if (isset($params['time_slots'])) {
                $update_data['time_slots'] = json_encode($params['time_slots']);
                $update_format[] = '%s';
            }
            
            if (isset($params['timezone'])) {
                $update_data['timezone'] = $params['timezone'];
                $update_format[] = '%s';
            }
            
            if (isset($params['priority'])) {
                $update_data['priority'] = $params['priority'];
                $update_format[] = '%d';
            }
            
            if (isset($params['status'])) {
                $update_data['status'] = $params['status'];
                $update_format[] = '%s';
            }

            if (empty($update_data)) {
                return new WP_Error(
                    'no_data',
                    'No data to update',
                    ['status' => 400]
                );
            }

            $update_data['updated_at'] = current_time('mysql');
            $update_format[] = '%s';

            $result = $wpdb->update(
                $wpdb->prefix . 'social_feed_schedules',
                $update_data,
                ['id' => $schedule_id],
                $update_format,
                ['%d']
            );

            if ($result === false) {
                return new WP_Error(
                    'update_failed',
                    'Failed to update schedule',
                    ['status' => 500]
                );
            }

            return new WP_REST_Response([
                'status' => 'success',
                'message' => 'Schedule updated successfully'
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Delete a schedule
     */
    public function delete_schedule($request) {
        global $wpdb;
        
        try {
            $schedule_id = $request['id'];
            
            // Delete related analytics first
            $wpdb->delete(
                $wpdb->prefix . 'social_feed_analytics',
                ['schedule_id' => $schedule_id],
                ['%d']
            );
            
            // Delete the schedule
            $result = $wpdb->delete(
                $wpdb->prefix . 'social_feed_schedules',
                ['id' => $schedule_id],
                ['%d']
            );

            if ($result === false) {
                return new WP_Error(
                    'deletion_failed',
                    'Failed to delete schedule',
                    ['status' => 500]
                );
            }

            return new WP_REST_Response([
                'status' => 'success',
                'message' => 'Schedule deleted successfully'
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Toggle schedule status
     */
    public function toggle_schedule($request) {
        global $wpdb;
        
        try {
            $schedule_id = $request['id'];
            $status = $request->get_param('status');
            
            $result = $wpdb->update(
                $wpdb->prefix . 'social_feed_schedules',
                [
                    'status' => $status,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $schedule_id],
                ['%s', '%s'],
                ['%d']
            );

            if ($result === false) {
                return new WP_Error(
                    'toggle_failed',
                    'Failed to toggle schedule status',
                    ['status' => 500]
                );
            }

            return new WP_REST_Response([
                'status' => 'success',
                'message' => 'Schedule status updated successfully'
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get quota statistics
     */
    public function get_quota_stats($request) {
        try {
            $quota_manager = new \SocialFeed\Core\SmartQuotaManager();
            $stats = $quota_manager->get_quota_stats();

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get quota usage over time
     */
    public function get_quota_usage($request) {
        global $wpdb;
        
        try {
            $days = $request->get_param('days');
            
            $usage = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    DATE(created_at) as date,
                    SUM(quota_used) as total_quota,
                    COUNT(*) as api_calls,
                    platform
                FROM {$wpdb->prefix}social_feed_quota_usage 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY DATE(created_at), platform
                ORDER BY date ASC, platform
            ", $days));

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $usage
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get performance analytics
     */
    public function get_performance_analytics($request) {
        global $wpdb;
        
        try {
            $schedule_id = $request->get_param('schedule_id');
            $days = $request->get_param('days');
            
            $where_clause = "WHERE a.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)";
            $params = [$days];
            
            if ($schedule_id) {
                $where_clause .= " AND a.schedule_id = %d";
                $params[] = $schedule_id;
            }
            
            $analytics = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    a.*,
                    s.channel_id,
                    s.platform,
                    s.priority
                FROM {$wpdb->prefix}social_feed_analytics a
                LEFT JOIN {$wpdb->prefix}social_feed_schedules s ON a.schedule_id = s.id
                $where_clause
                ORDER BY a.created_at DESC
            ", ...$params));

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $analytics
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Get AI recommendations
     */
    public function get_recommendations($request) {
        try {
            $learning_engine = new \SocialFeed\Core\LearningEngine();
            $recommendations = $learning_engine->get_optimization_suggestions();

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $recommendations
            ]);
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Trigger a schedule manually
     */
    public function trigger_schedule($request) {
        try {
            $schedule_id = $request['id'];
            
            $scheduler = new \SocialFeed\Core\IntelligentScheduler();
            $result = $scheduler->trigger_immediate_check($schedule_id);

            if ($result) {
                return new WP_REST_Response([
                    'status' => 'success',
                    'message' => 'Schedule triggered successfully'
                ]);
            } else {
                return new WP_Error(
                    'trigger_failed',
                    'Failed to trigger schedule',
                    ['status' => 500]
                );
            }
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Register device for notifications
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function register_device($request) {
        try {
            $params = $request->get_params();
            $user_id = get_current_user_id();
            
            $notifications = new \SocialFeed\Core\Notifications();
            $result = $notifications->register_device(
                $user_id,
                $params['device_token'],
                $params['platform'],
                $params['notification_types']
            );

            if ($result) {
                return new WP_REST_Response([
                    'status' => 'success',
                    'message' => 'Device registered successfully',
                ]);
            } else {
                return new WP_Error(
                    'registration_failed',
                    'Failed to register device',
                    ['status' => 500]
                );
            }
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Unregister device from notifications
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function unregister_device($request) {
        try {
            $params = $request->get_params();
            $user_id = get_current_user_id();
            
            $notifications = new \SocialFeed\Core\Notifications();
            $result = $notifications->unregister_device(
                $user_id,
                $params['device_token']
            );

            if ($result) {
                return new WP_REST_Response([
                    'status' => 'success',
                    'message' => 'Device unregistered successfully',
                ]);
            } else {
                return new WP_Error(
                    'unregistration_failed',
                    'Failed to unregister device',
                    ['status' => 500]
                );
            }
        } catch (\Exception $e) {
            return new WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }
}