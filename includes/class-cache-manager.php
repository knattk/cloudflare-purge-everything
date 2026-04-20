<?php
/**
 * Cache manager class for handling purge operations and lock management
 * 
 * @package PurgeCF
 */

if (!defined('ABSPATH')) {
    exit;
}

class purgecf_CacheManager {
    private $purge_lock_transient = 'purgecf_cf_purge_everything_lock';
    private $purge_cooldown_transient = 'purgecf_cf_purge_everything_cooldown';
    private $purge_lock_ttl = 30;
    private $purge_cooldown_ttl = 30;
    private $option_name = 'purgecf_cloudflare_purge_settings';

    public function acquire_purge_lock() {
        if (get_transient($this->purge_lock_transient)) {
            return false;
        }
        return set_transient($this->purge_lock_transient, time(), $this->purge_lock_ttl);
    }

    public function release_purge_lock() {
        delete_transient($this->purge_lock_transient);
    }

    public function is_cooldown_active() {
        return (bool) get_transient($this->purge_cooldown_transient);
    }

    public function start_cooldown() {
        set_transient($this->purge_cooldown_transient, time(), $this->purge_cooldown_ttl);
    }

    public function get_settings() {
        $settings = get_option($this->option_name, []);
        return is_array($settings) ? $settings : [];
    }

    public function mask_zone_id($zone_id) {
        $zone_id = is_string($zone_id) ? strtolower($zone_id) : '';
        if ($zone_id === '' || strlen($zone_id) < 8) {
            return 'unknown';
        }
        return substr($zone_id, 0, 4) . '...' . substr($zone_id, -4);
    }

    public function log_audit_event($event, $status, $context = []) {
        $user = wp_get_current_user();
        $payload = array_merge([
            'ts' => current_time('mysql', true),
            'event' => sanitize_text_field($event),
            'status' => sanitize_text_field($status),
            'user_id' => get_current_user_id(),
            'user_login' => $user instanceof WP_User ? $user->user_login : '',
            'ip' => $this->get_request_ip(),
        ], $context);
    }

    private function get_request_ip() {
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return 'unknown';
    }

    public function redirect_with_status($status) {
        $nonce = wp_create_nonce('purgecf_cf_purge_redirect_nonce');
        $url = add_query_arg($status, '1', admin_url('options-general.php?page=purgecf_cf-purge-everything'));
        $url = add_query_arg('purgecf_cf_purge_nonce', $nonce, $url);
        wp_safe_redirect($url);
        exit;
    }
}
