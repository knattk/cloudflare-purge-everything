<?php
/**
 * Main plugin class for handling cache purge requests
 * 
 * @package PurgeCF
 */

if (!defined('ABSPATH')) {
    exit;
}

class purgecf_Plugin {
    private $cache_manager;

    public function __construct(purgecf_CacheManager $cache_manager) {
        $this->cache_manager = $cache_manager;
        add_action('admin_post_purgecf_purge_cloudflare_cache', [$this, 'purge_cache']);
    }

    public function purge_cache() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized user', 'cf-purge-everything'));
        }

        check_admin_referer('purgecf_purge_cloudflare_cache_action', 'purgecf_purge_cloudflare_cache_nonce');

        if (!$this->cache_manager->acquire_purge_lock()) {
            $this->cache_manager->log_audit_event('purge', 'blocked', ['reason' => 'lock_active']);
            $this->cache_manager->redirect_with_status('rate_limited');
        }

        if ($this->cache_manager->is_cooldown_active()) {
            $this->cache_manager->release_purge_lock();
            $this->cache_manager->log_audit_event('purge', 'blocked', ['reason' => 'cooldown_active']);
            $this->cache_manager->redirect_with_status('rate_limited');
        }

        $this->cache_manager->start_cooldown();

        $options = $this->cache_manager->get_settings();
        if (!$options || empty($options['api_key']) || empty($options['zone_id'])) {
            $this->cache_manager->release_purge_lock();
            $this->cache_manager->log_audit_event('purge', 'blocked', ['reason' => 'missing_config']);
            $this->cache_manager->redirect_with_status('missing_config');
        }

        $api_client = new purgecf_API();
        $is_purged = $api_client->purge_everything($options['zone_id'], $options['api_key']);

        if (!$is_purged) {
            $this->cache_manager->release_purge_lock();
            $this->cache_manager->log_audit_event('purge', 'failed', [
                'reason' => $api_client->get_last_error_code(),
                'zone_hint' => $this->cache_manager->mask_zone_id($options['zone_id']),
            ]);
            $this->cache_manager->redirect_with_status('error');
        }

        $this->cache_manager->release_purge_lock();
        $this->cache_manager->log_audit_event('purge', 'success', [
            'zone_hint' => $this->cache_manager->mask_zone_id($options['zone_id']),
        ]);

        $this->cache_manager->redirect_with_status('success');
    }
}
