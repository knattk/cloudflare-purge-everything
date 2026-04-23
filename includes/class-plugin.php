<?php
/**
 * Main plugin class for handling cache purge requests
 * 
 * @package PurgeCF
 */

if (!defined('ABSPATH')) {
    exit;
}

class PurgeCF_Plugin {
    private $cache_manager;

    public function __construct(PurgeCF_CacheManager $cache_manager) {
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

        $options = $this->cache_manager->get_settings();
        $zone_id = isset($options['zone_id']) ? (string) $options['zone_id'] : '';
        $api_key = isset($options['api_key']) ? PurgeCF_Crypto::decrypt_api_key((string) $options['api_key']) : '';
        $enforce_https = !array_key_exists('enforce_https', $options) ? true : (bool) $options['enforce_https'];

        if ($zone_id === '' || $api_key === '') {
            $this->cache_manager->release_purge_lock();
            $this->cache_manager->log_audit_event('purge', 'blocked', ['reason' => 'missing_config']);
            $this->cache_manager->redirect_with_status('missing_config');
        }

        $this->cache_manager->start_cooldown();

        $api_client = new PurgeCF_API();
        $is_purged = $api_client->purge_everything($zone_id, $api_key, $enforce_https);

        if (!$is_purged) {
            $this->cache_manager->release_purge_lock();
            $this->cache_manager->log_audit_event('purge', 'failed', [
                'reason' => $api_client->get_last_error_code(),
                'zone_hint' => $this->cache_manager->mask_zone_id($zone_id),
            ]);
            $this->cache_manager->redirect_with_status('error');
        }

        $this->cache_manager->release_purge_lock();
        $this->cache_manager->log_audit_event('purge', 'success', [
            'zone_hint' => $this->cache_manager->mask_zone_id($zone_id),
        ]);

        $this->cache_manager->redirect_with_status('success');
    }
}
