<?php
/**
 * Admin class for managing plugin settings and UI
 * 
 * @package PurgeCF
 */

if (!defined('ABSPATH')) {
    exit;
}

class purgecf_Admin {
    private $option_name = 'purgecf_cloudflare_purge_settings';
    private $zone_id_pattern = '/^[a-f0-9]{32}$/';
    private $api_token_pattern = '/^[A-Za-z0-9_-]{20,120}$/';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_admin_menu() {
        add_options_page('Cloudflare Cache Purge', 'Cloudflare Purge', 'manage_options', 'purgecf_cf-purge-everything', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting(
            'purgecf_cloudflare_purge_group',
            $this->option_name,
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => [],
            ]
        );
    }

    public function sanitize_settings($settings) {
        if (!is_array($settings)) {
            return [];
        }

        $existing = get_option($this->option_name, []);
        $existing_zone_id = isset($existing['zone_id']) ? (string) $existing['zone_id'] : '';
        $existing_api_key = isset($existing['api_key']) ? (string) $existing['api_key'] : '';

        $zone_id = isset($settings['zone_id']) ? strtolower(trim(sanitize_text_field($settings['zone_id']))) : '';
        if (!preg_match($this->zone_id_pattern, $zone_id)) {
            add_settings_error(
                $this->option_name,
                'invalid_zone_id',
                esc_html__('Cloudflare Zone ID must be a 32-character hexadecimal string.', 'cf-purge-everything')
            );
            $zone_id = $existing_zone_id;
        }

        $api_key_input = isset($settings['api_key']) ? trim((string) $settings['api_key']) : '';
        $api_key = $existing_api_key;

        if ($api_key_input !== '') {
            if (!preg_match($this->api_token_pattern, $api_key_input)) {
                add_settings_error(
                    $this->option_name,
                    'invalid_api_key',
                    esc_html__('Cloudflare API Token format is invalid.', 'cf-purge-everything')
                );
            } else {
                $api_key = $api_key_input;
            }
        }

        return [
            'zone_id' => $zone_id,
            'api_key' => $api_key,
        ];
    }

    public function settings_page() {
        $options = get_option($this->option_name);
        $nonce_verified = isset($_GET['purgecf_cf_purge_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['purgecf_cf_purge_nonce'])), 'purgecf_cf_purge_redirect_nonce');
        ?>
        <div class="wrap">
            <h2>Cloudflare Cache Purge Everything Settings</h2>
            <?php settings_errors($this->option_name); ?>
            <?php if ($nonce_verified && isset($_GET['success'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Cloudflare cache successfully purged.</p></div>
            <?php elseif ($nonce_verified && isset($_GET['rate_limited'])) : ?>
                <div class="notice notice-warning is-dismissible"><p>Please wait before purging again.</p></div>
            <?php elseif ($nonce_verified && isset($_GET['missing_config'])) : ?>
                <div class="notice notice-warning is-dismissible"><p>Cloudflare Zone ID and API Token are required.</p></div>
            <?php elseif ($nonce_verified && isset($_GET['error'])) : ?>
                <div class="notice notice-error is-dismissible"><p>Failed to purge Cloudflare cache.</p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('purgecf_cloudflare_purge_group'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="cloudflare_zone_id">Cloudflare Zone ID</label></th>
                        <td><input type="text" name="<?php echo esc_attr($this->option_name); ?>[zone_id]" value="<?php echo esc_attr($options['zone_id'] ?? ''); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="cloudflare_api_key">Cloudflare API Key</label></th>
                        <td>
                            <input type="password" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="Leave blank to keep existing token">
                            <p class="description">Saved token is hidden for security. Enter a new one only when rotating credentials.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <h2>Purge Cache</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="purgecf_purge_cloudflare_cache">
                <?php wp_nonce_field('purgecf_purge_cloudflare_cache_action', 'purgecf_purge_cloudflare_cache_nonce'); ?>
                <?php submit_button('Purge Cloudflare Cache', 'delete'); ?>
            </form>
        </div>
        <?php
    }
}
