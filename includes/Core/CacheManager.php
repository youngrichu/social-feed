<?php
namespace SocialFeed\Core;

class CacheManager {
    /**
     * Cache configuration
     */
    const CACHE_CONFIG = [
        'metadata' => [
            'ttl' => 3600,      // 1 hour
            'prefix' => 'sf_meta_'
        ],
        'content' => [
            'ttl' => 86400,     // 24 hours
            'prefix' => 'sf_content_'
        ],
        'api' => [
            'ttl' => 300,       // 5 minutes
            'prefix' => 'sf_api_'
        ],
        'search' => [
            'ttl' => 1800,      // 30 minutes
            'prefix' => 'sf_search_'
        ]
    ];

    /**
     * Cache priority levels
     */
    const CACHE_PRIORITY = [
        'critical' => 1,    // Must be cached
        'high' => 2,        // Should be cached
        'normal' => 3,      // Can be cached
        'low' => 4         // Cache if space available
    ];

    private $platform;
    private $cache_stats;
    private $memcache_available;
    private $redis_available;

    /**
     * Constructor
     */
    public function __construct($platform) {
        $this->platform = $platform;
        $this->cache_stats = [];
        
        // Check available caching systems
        $this->memcache_available = class_exists('Memcache') || class_exists('Memcached');
        $this->redis_available = class_exists('Redis');
        
        $this->init_cache_stats();
    }

    /**
     * Get cached item with multi-layer fallback
     */
    public function get($key, $type = 'content') {
        $cache_key = $this->build_cache_key($key, $type);
        
        // Try object cache first (Memcached/Redis)
        if ($this->memcache_available || $this->redis_available) {
            $data = wp_cache_get($cache_key, $this->platform);
            if ($data !== false) {
                $this->update_cache_stats('hit', $type);
                return $data;
            }
        }
        
        // Try transients
        $data = get_transient($cache_key);
        if ($data !== false) {
            $this->update_cache_stats('hit', $type);
            // Warm up object cache
            if ($this->memcache_available || $this->redis_available) {
                wp_cache_set($cache_key, $data, $this->platform, self::CACHE_CONFIG[$type]['ttl']);
            }
            return $data;
        }
        
        // Try database cache
        $data = $this->get_from_db_cache($cache_key, $type);
        if ($data !== false) {
            $this->update_cache_stats('hit', $type);
            // Warm up higher level caches
            $this->warm_up_cache($cache_key, $data, $type);
            return $data;
        }
        
        $this->update_cache_stats('miss', $type);
        return null;
    }

    /**
     * Store item in cache with intelligent layer selection
     */
    public function set($key, $data, $type = 'content', $priority = 'normal') {
        $cache_key = $this->build_cache_key($key, $type);
        $ttl = self::CACHE_CONFIG[$type]['ttl'];
        
        // Store in all available cache layers based on priority
        if ($priority === 'critical' || $priority === 'high') {
            // Store in all layers for high priority items
            if ($this->memcache_available || $this->redis_available) {
                wp_cache_set($cache_key, $data, $this->platform, $ttl);
            }
            set_transient($cache_key, $data, $ttl);
            $this->store_in_db_cache($cache_key, $data, $type, $ttl);
        } else {
            // Store selectively for normal/low priority
            if ($this->memcache_available || $this->redis_available) {
                wp_cache_set($cache_key, $data, $this->platform, $ttl);
            }
            set_transient($cache_key, $data, $ttl);
        }
        
        $this->update_cache_stats('set', $type);
        return true;
    }

    /**
     * Delete item from all cache layers
     */
    public function delete($key, $type = 'content') {
        $cache_key = $this->build_cache_key($key, $type);
        
        if ($this->memcache_available || $this->redis_available) {
            wp_cache_delete($cache_key, $this->platform);
        }
        
        delete_transient($cache_key);
        $this->delete_from_db_cache($cache_key);
        
        $this->update_cache_stats('delete', $type);
        return true;
    }

    /**
     * Flush all caches for the platform
     */
    public function flush($type = null) {
        global $wpdb;
        
        if ($type) {
            $prefix = self::CACHE_CONFIG[$type]['prefix'];
            
            // Clear object cache
            if ($this->memcache_available || $this->redis_available) {
                wp_cache_flush();
            }
            
            // Clear transients
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                '_transient_' . $prefix . '%'
            ));
            
            // Clear DB cache
            $cache_table = $wpdb->prefix . 'social_feed_cache';
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $cache_table WHERE cache_key LIKE %s",
                $prefix . '%'
            ));
        } else {
            // Flush all cache types
            foreach (self::CACHE_CONFIG as $cache_type => $config) {
                $this->flush($cache_type);
            }
        }
        
        $this->update_cache_stats('flush', $type ?? 'all');
        return true;
    }

    /**
     * Get cache statistics
     */
    public function get_stats() {
        return $this->cache_stats;
    }

    /**
     * Private helper methods
     */
    private function build_cache_key($key, $type) {
        return self::CACHE_CONFIG[$type]['prefix'] . $this->platform . '_' . md5($key);
    }

    private function init_cache_stats() {
        foreach (self::CACHE_CONFIG as $type => $config) {
            $this->cache_stats[$type] = [
                'hits' => 0,
                'misses' => 0,
                'sets' => 0,
                'deletes' => 0,
                'warms' => 0
            ];
        }
    }

    private function update_cache_stats($operation, $type) {
        switch ($operation) {
            case 'hit':
                $this->cache_stats[$type]['hits']++;
                break;
            case 'miss':
                $this->cache_stats[$type]['misses']++;
                break;
            case 'set':
                $this->cache_stats[$type]['sets']++;
                break;
            case 'delete':
                $this->cache_stats[$type]['deletes']++;
                break;
            case 'warm':
                $this->cache_stats[$type]['warms']++;
                break;
        }
    }

    private function get_from_db_cache($cache_key, $type) {
        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';
        
        $data = $wpdb->get_var($wpdb->prepare(
            "SELECT cache_data FROM $cache_table 
             WHERE cache_key = %s 
             AND cache_type = %s 
             AND expiry > %d",
            $cache_key,
            $type,
            time()
        ));
        
        return $data ? maybe_unserialize($data) : false;
    }

    private function store_in_db_cache($cache_key, $data, $type, $ttl) {
        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';
        
        $wpdb->replace(
            $cache_table,
            [
                'cache_key' => $cache_key,
                'cache_type' => $type,
                'cache_data' => maybe_serialize($data),
                'expiry' => time() + $ttl,
                'created_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s', '%d', '%s']
        );
    }

    private function delete_from_db_cache($cache_key) {
        global $wpdb;
        $cache_table = $wpdb->prefix . 'social_feed_cache';
        
        $wpdb->delete(
            $cache_table,
            ['cache_key' => $cache_key],
            ['%s']
        );
    }

    private function warm_up_cache($cache_key, $data, $type) {
        if ($this->memcache_available || $this->redis_available) {
            wp_cache_set($cache_key, $data, $this->platform, self::CACHE_CONFIG[$type]['ttl']);
        }
        set_transient($cache_key, $data, self::CACHE_CONFIG[$type]['ttl']);
    }
} 