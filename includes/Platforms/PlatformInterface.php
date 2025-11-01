<?php
namespace SocialFeed\Platforms;

interface PlatformInterface {
    /**
     * Initialize the platform
     */
    public function init();

    /**
     * Get feed items from the platform
     *
     * @param array $types Content types to fetch
     * @return array
     */
    public function get_feed($types = []);

    /**
     * Get stream status
     *
     * @param string $stream_id
     * @return array|null
     */
    public function get_stream_status($stream_id);

    /**
     * Get platform configuration
     *
     * @return array
     */
    public function get_config();

    /**
     * Validate platform configuration
     *
     * @param array $config
     * @return bool
     */
    public function validate_config($config);

    /**
     * Get supported content types
     *
     * @return array
     */
    public function get_supported_types();
} 