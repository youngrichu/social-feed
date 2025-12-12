<?php
namespace SocialFeed\API;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class RestAPI
{
    /**
     * API namespace
     */
    const API_NAMESPACE = 'social-feed/v1';

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
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
                    'playlist' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Filter videos by playlist ID',
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

        // Playlists endpoint
        register_rest_route(self::API_NAMESPACE, '/playlists', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_playlists'],
                'permission_callback' => [$this, 'check_authorization'],
                'args' => [
                    'platform' => [
                        'type' => 'string',
                        'required' => false,
                        'default' => 'youtube',
                        'description' => 'Platform to fetch playlists from',
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
    }

    /**
     * Check if request is authorized
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_authorization($request)
    {
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
    public function get_feeds($request)
    {
        try {
            $params = $request->get_params();
            $feed_service = new \SocialFeed\Services\FeedService();

            // Build args array for additional filters
            $args = [];
            if (!empty($params['playlist'])) {
                $args['playlist'] = $params['playlist'];
            }

            $result = $feed_service->get_feeds(
                $params['platform'] ?? [],
                $params['type'] ?? [],
                $params['page'],
                $params['per_page'],
                $params['sort'],
                $params['order'],
                $args
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
     * Get playlists
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_playlists($request)
    {
        try {
            $params = $request->get_params();
            $platform = $params['platform'] ?? 'youtube';

            $platform_factory = new \SocialFeed\Platforms\PlatformFactory();
            $platform_handler = $platform_factory->get_platform($platform);

            if (!$platform_handler) {
                return new WP_Error(
                    'platform_not_found',
                    'Platform not found or not enabled',
                    ['status' => 404]
                );
            }

            $playlists = $platform_handler->get_playlists();

            return new WP_REST_Response([
                'status' => 'success',
                'data' => $playlists,
            ]);
        } catch (\Exception $e) {
            error_log('Social Feed API Playlists Error: ' . $e->getMessage());
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
    public function get_live_streams($request)
    {
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
     * Register device for notifications
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function register_device($request)
    {
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
    public function unregister_device($request)
    {
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