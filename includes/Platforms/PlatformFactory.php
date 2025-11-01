<?php
namespace SocialFeed\Platforms;

class PlatformFactory {
    /**
     * @var array Platform instances
     */
    private $instances = [];

    /**
     * Get platform instance
     *
     * @param string $platform Platform identifier
     * @return PlatformInterface|null
     */
    public function get_platform($platform) {
        if (isset($this->instances[$platform])) {
            return $this->instances[$platform];
        }

        $class_name = $this->get_platform_class($platform);
        if (!$class_name || !class_exists($class_name)) {
            return null;
        }

        $instance = new $class_name();
        $instance->init();
        $this->instances[$platform] = $instance;

        return $instance;
    }

    /**
     * Get platform class name
     *
     * @param string $platform
     * @return string|null
     */
    private function get_platform_class($platform) {
        $platforms = [
            'youtube' => YouTube::class,
            'tiktok' => TikTok::class,
            'facebook' => Facebook::class,
            'instagram' => Instagram::class,
        ];

        return $platforms[$platform] ?? null;
    }

    /**
     * Get all available platforms
     *
     * @return array
     */
    public function get_available_platforms() {
        return [
            'youtube' => [
                'name' => 'YouTube',
                'description' => 'YouTube videos, shorts, and live streams',
                'types' => ['video', 'short', 'live'],
            ],
            'tiktok' => [
                'name' => 'TikTok',
                'description' => 'TikTok videos and live streams',
                'types' => ['video', 'live'],
            ],
            'facebook' => [
                'name' => 'Facebook',
                'description' => 'Facebook posts, videos, and live streams',
                'types' => ['post', 'video', 'live'],
            ],
            'instagram' => [
                'name' => 'Instagram',
                'description' => 'Instagram posts, reels, and live streams',
                'types' => ['post', 'reel', 'live'],
            ],
        ];
    }
} 