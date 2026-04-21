<?php
/**
 * Admin class for managing plugin settings and UI
 * 
 * @package PurgeCF
 */

if (!defined('ABSPATH')) {
    exit;
}

class PurgeCF_Admin {
    private $option_name = 'purgecf_cloudflare_purge_settings';
    private $purge_lock_transient = 'purgecf_cf_purge_everything_lock';
    private $purge_cooldown_transient = 'purgecf_cf_purge_everything_cooldown';
    private $masked_api_token_value = '********';
    private $zone_id_pattern = '/^[a-f0-9]{32}$/';
    private $api_token_pattern = '/^[A-Za-z0-9_-]{20,120}$/';
    private $settings_page_hook = '';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_purgecf_revoke_cloudflare_api_key', [$this, 'revoke_api_key']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 100);
        add_action('admin_footer', [$this, 'render_admin_bar_purge_form']);
        add_action('wp_footer', [$this, 'render_admin_bar_purge_form']);
    }

    public function add_admin_bar_menu($wp_admin_bar) {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        if (!($wp_admin_bar instanceof WP_Admin_Bar)) {
            return;
        }

        $settings_url = admin_url('options-general.php?page=purgecf_cf-purge-everything');

        $options = get_option($this->option_name, []);
        $options = is_array($options) ? $options : [];
        $has_zone_id = !empty($options['zone_id']);
        $has_api_key = $this->has_valid_api_token($options);
        $is_ready = $has_zone_id && $has_api_key;
        $is_cooldown = (bool) get_transient($this->purge_cooldown_transient);

        $wp_admin_bar->add_node([
            'id'    => 'purgecf-cloudflare',
            'title' => 'Cloudflare',
            'href'  => $settings_url,
            'meta'  => [
                'title' => 'Cloudflare Purge Settings',
            ],
        ]);

        $wp_admin_bar->add_node([
            'id'     => 'purgecf-cloudflare-purge-everything',
            'parent' => 'purgecf-cloudflare',
            'title'  => 'Purge Everything',
            'href'   => ($is_ready && !$is_cooldown) ? '#' : $settings_url,
            'meta'   => [
                'title' => ($is_ready && !$is_cooldown) ? 'Purge Cloudflare cache for the saved zone' : 'Open settings (setup required or cooldown active)',
                'class' => 'purgecf-adminbar-purge',
            ],
        ]);
    }

    public function render_admin_bar_purge_form() {
        if (!is_admin_bar_showing() || !current_user_can('manage_options')) {
            return;
        }

        $options = get_option($this->option_name, []);
        $options = is_array($options) ? $options : [];
        $has_zone_id = !empty($options['zone_id']);
        $has_api_key = $this->has_valid_api_token($options);
        $is_ready = $has_zone_id && $has_api_key;
        $is_cooldown = (bool) get_transient($this->purge_cooldown_transient);

        if (!$is_ready || $is_cooldown) {
            return;
        }

        $action_url = admin_url('admin-post.php');
        $nonce = wp_create_nonce('purgecf_purge_cloudflare_cache_action');
        ?>
        <form id="purgecf-adminbar-purge-form" method="post" action="<?php echo esc_url($action_url); ?>" style="display:none;">
            <input type="hidden" name="action" value="purgecf_purge_cloudflare_cache">
            <input type="hidden" name="purgecf_purge_cloudflare_cache_nonce" value="<?php echo esc_attr($nonce); ?>">
        </form>
        <script>
            (function () {
                var adminBarLink = document.querySelector('#wp-admin-bar-purgecf-cloudflare-purge-everything > a');
                var purgeForm = document.getElementById('purgecf-adminbar-purge-form');
                if (!adminBarLink || !purgeForm) {
                    return;
                }
                adminBarLink.addEventListener('click', function (event) {
                    event.preventDefault();
                    purgeForm.submit();
                });
            })();
        </script>
        <?php
    }

    public function add_admin_menu() {
        $this->settings_page_hook = add_options_page('Cloudflare Cache Purge', 'Cloudflare Purge', 'manage_options', 'purgecf_cf-purge-everything', [$this, 'settings_page']);
    }

    public function enqueue_admin_assets($hook_suffix) {
        if (!is_string($hook_suffix) || $hook_suffix === '') {
            return;
        }

        if ($this->settings_page_hook === '' || $hook_suffix !== $this->settings_page_hook) {
            return;
        }

        $plugin_root = dirname(__DIR__);
        $plugin_file = $plugin_root . '/cf-purge.php';
        $css_path = $plugin_root . '/assets/admin.css';
        $css_url = plugins_url('assets/admin.css', $plugin_file);
        $version = file_exists($css_path) ? (string) filemtime($css_path) : '0.1.0';

        wp_enqueue_style('purgecf-admin', $css_url, [], $version);
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
        $existing_api_key_plain = PurgeCF_Crypto::decrypt_api_key($existing_api_key);
        if (
            $existing_api_key !== ''
            && !PurgeCF_Crypto::is_encrypted_api_key($existing_api_key)
            && preg_match($this->api_token_pattern, $existing_api_key_plain)
        ) {
            $existing_api_key = PurgeCF_Crypto::encrypt_api_key($existing_api_key_plain);
        }

        $zone_id = isset($settings['zone_id']) ? strtolower(trim(sanitize_text_field($settings['zone_id']))) : '';
        $has_invalid_input = false;

        if (!preg_match($this->zone_id_pattern, $zone_id)) {
            $has_invalid_input = true;
            $zone_id = $existing_zone_id;
        }

        $api_key_input = isset($settings['api_key']) ? trim((string) $settings['api_key']) : '';
        $api_key = $existing_api_key;

        if ($api_key_input !== '') {
            if ($existing_api_key !== '' && $api_key_input === $this->masked_api_token_value) {
                $api_key = $existing_api_key;
            } elseif (!preg_match($this->api_token_pattern, $api_key_input)) {
                $has_invalid_input = true;
            } else {
                $api_key = PurgeCF_Crypto::encrypt_api_key($api_key_input);
            }
        }

        if ($has_invalid_input) {
            $this->add_unique_settings_error(
                $this->option_name,
                'invalid_api_key',
                esc_html__('Cloudflare Zone ID or API Token format is invalid.', 'cf-purge-everything')
            );
        }

        return [
            'zone_id' => $zone_id,
            'api_key' => $api_key,
        ];
    }

    public function settings_page() {
        $options = get_option($this->option_name, []);
        $options = is_array($options) ? $options : [];
        $saved_zone_id = isset($options['zone_id']) ? (string) $options['zone_id'] : '';
        $has_saved_zone_id = $saved_zone_id !== '';
        $needs_api_key_reconfiguration = $this->needs_api_key_reconfiguration($options);
        $has_saved_api_token = $this->has_valid_api_token($options);
        $is_connection_ready = $has_saved_zone_id && $has_saved_api_token;
        $api_key_display_value = $has_saved_api_token ? $this->masked_api_token_value : '';
        $nonce_verified = isset($_GET['purgecf_cf_purge_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['purgecf_cf_purge_nonce'])), 'purgecf_cf_purge_redirect_nonce');
        ?>
        <div class="wrap">
            <h1>Cloudflare Purge</h1>

            <?php settings_errors($this->option_name); ?>
            <?php if ($needs_api_key_reconfiguration) : ?>
                <div class="notice notice-warning"><p>Stored Cloudflare API key could not be decrypted. This can happen if AUTH_SALT changed. Please enter a new API key and save settings.</p></div>
            <?php endif; ?>
            <?php if ($nonce_verified && isset($_GET['success'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Cloudflare cache successfully purged.</p></div>
            <?php elseif ($nonce_verified && isset($_GET['rate_limited'])) : ?>
                <div class="notice notice-warning is-dismissible"><p>Please wait 30s before purging again.</p></div>
            <?php elseif ($nonce_verified && isset($_GET['missing_config'])) : ?>
                <div class="notice notice-warning is-dismissible"><p>Cloudflare Zone ID and API Token are required.</p></div>
            <?php elseif ($nonce_verified && isset($_GET['error'])) : ?>
                <div class="notice notice-error is-dismissible"><p>Failed to purge Cloudflare cache.</p></div>
            <?php elseif ($nonce_verified && isset($_GET['revoked'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Cloudflare API key and saved settings have been cleared.</p></div>
            <?php endif; ?>

            <div class="purgecf-settings-grid">
                <div>
                    <div class="card purgecf-card">
                        <h2>Connection</h2>
                        <p class="description">Save your Cloudflare Zone ID and API token used for purge requests.</p>

                        

                        <form id="purgecf-settings-form" method="post" action="options.php">
                            <?php settings_fields('purgecf_cloudflare_purge_group'); ?>
                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">
                                        <label for="cloudflare_zone_id">Cloudflare Zone ID</label>
                                        <span class="dashicons dashicons-editor-help" style="font-size:16px; line-height:1; width:16px; height:16px; vertical-align:middle; color:#646970;" title="Find this in the Cloudflare dashboard for your site. Open the domain, then copy the Zone ID from the Overview page."></span>
                                    </th>
                                    <td>
                                        <input type="text" id="cloudflare_zone_id" name="<?php echo esc_attr($this->option_name); ?>[zone_id]" value="<?php echo esc_attr($options['zone_id'] ?? ''); ?>" class="regular-text" required>
                                        <p class="description">Cloudflare dashboard &rarr; your domain &rarr; Overview &rarr; Zone ID.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="cloudflare_api_key">Cloudflare API Key</label>
                                        <span class="dashicons dashicons-editor-help" style="font-size:16px; line-height:1; width:16px; height:16px; vertical-align:middle; color:#646970;" title="Create an API token in Cloudflare under Account API Tokens &rarr; API Tokens. Use a token with permission to purge cache for the selected zone."></span>
                                    </th>
                                    <td>
                                        <input type="password" id="cloudflare_api_key" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="<?php echo esc_attr($api_key_display_value); ?>" class="regular-text" autocomplete="new-password" pattern="[A-Za-z0-9_-]{20,120}" title="API token must be 20 to 120 characters and may contain letters, numbers, underscores, or hyphens.">
                                        <p class="description">Cloudflare dashboard &rarr; Manage account &rarr; Account API Tokens.</p>
                                    </td>
                                </tr>
                            </table>
                        </form>

                        <?php $can_revoke = $has_saved_zone_id || $has_saved_api_token; ?>
                        <div class="purgecf-actions purgecf-actions--connection">
                            <button type="submit" form="purgecf-settings-form" class="button button-primary">Save Settings</button>

                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" <?php echo $can_revoke ? 'onsubmit="return confirm(\'This will delete the saved Zone ID, API key, and purge state. Continue?\');"' : ''; ?>>
                                <input type="hidden" name="action" value="purgecf_revoke_cloudflare_api_key">
                                <?php wp_nonce_field('purgecf_revoke_cloudflare_api_key_action', 'purgecf_revoke_cloudflare_api_key_nonce'); ?>
                                <button type="submit" class="button purgecf-button-danger" <?php disabled(!$can_revoke); ?>>
                                    <span class="dashicons dashicons-trash" aria-hidden="true"></span>
                                    <span>Revoke API Key</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="card purgecf-card" style="margin-top:20px;">
                        <h2>Quick Guide</h2>
                        <ol class="purgecf-help-list">
                            <li>Cloudflare dashboard &rarr; select your domain &rarr; Overview &rarr; copy Zone ID.</li>
                            <li>Cloudflare dashboard &rarr; Manage account &rarr; Account API Tokens &rarr; create a token with purge access.</li>
                            <li>Save settings, then use “Purge Cloudflare Cache” when needed.</li>
                        </ol>
                    </div>
                </div>

                <div>
                    <div class="card purgecf-card purgecf-purge-card">
                        <h2>Purge Cache</h2>
                        <p class="description">Purge everything from Cloudflare cache for the saved zone.</p>
                        <div class="purgecf-inline-status">
                            <?php if ($is_connection_ready) : ?>
                                <span class="dashicons dashicons-yes-alt" style="color:#1a7f37" aria-hidden="true"></span>
                                <span><strong>Status:</strong> Ready</span>
                            <?php else : ?>
                                <span class="dashicons dashicons-warning" style="color:#dba617" aria-hidden="true"></span>
                                <span><strong>Status:</strong> Needs setup</span>
                            <?php endif; ?>
                        </div>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="purgecf_purge_cloudflare_cache">
                            <?php wp_nonce_field('purgecf_purge_cloudflare_cache_action', 'purgecf_purge_cloudflare_cache_nonce'); ?>
                            <div class="purgecf-actions">
                                <?php submit_button('Purge Cloudflare Cache', 'delete', 'submit', false, $is_connection_ready ? '' : 'disabled="disabled"'); ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    try {
                        var currentUrl = new URL(window.location.href);
                        var paramsToClear = [
                            'success',
                            'rate_limited',
                            'missing_config',
                            'error',
                            'revoked',
                            'purgecf_cf_purge_nonce'
                        ];
                        var didChange = false;

                        paramsToClear.forEach(function (param) {
                            if (currentUrl.searchParams.has(param)) {
                                currentUrl.searchParams.delete(param);
                                didChange = true;
                            }
                        });

                        if (didChange) {
                            window.history.replaceState({}, document.title, currentUrl.toString());
                        }
                    } catch (e) {
                        // ignore URL parsing errors
                    }
                });
            </script>
        </div>
        <?php
    }

    public function revoke_api_key() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized user', 'cf-purge-everything'));
        }

        check_admin_referer('purgecf_revoke_cloudflare_api_key_action', 'purgecf_revoke_cloudflare_api_key_nonce');

        delete_option($this->option_name);
        delete_transient($this->purge_lock_transient);
        delete_transient($this->purge_cooldown_transient);

        $nonce = wp_create_nonce('purgecf_cf_purge_redirect_nonce');
        $url = add_query_arg('revoked', '1', admin_url('options-general.php?page=purgecf_cf-purge-everything'));
        $url = add_query_arg('purgecf_cf_purge_nonce', $nonce, $url);
        wp_safe_redirect($url);
        exit;
    }

    private function add_unique_settings_error($setting, $code, $message, $type = 'error') {
        static $seen = [];

        $key = $setting . '|' . $code;
        if (isset($seen[$key])) {
            return;
        }

        $existing_errors = get_settings_errors($setting);
        if (is_array($existing_errors)) {
            foreach ($existing_errors as $error) {
                if (!empty($error['code']) && $error['code'] === $code) {
                    $seen[$key] = true;
                    return;
                }
            }
        }

        add_settings_error($setting, $code, $message, $type);
        $seen[$key] = true;
    }

    private function has_valid_api_token($options) {
        if (!is_array($options) || empty($options['api_key'])) {
            return false;
        }

        $plain_api_key = PurgeCF_Crypto::decrypt_api_key((string) $options['api_key']);
        return $plain_api_key !== '' && (bool) preg_match($this->api_token_pattern, $plain_api_key);
    }

    private function needs_api_key_reconfiguration($options) {
        if (!is_array($options) || empty($options['api_key'])) {
            return false;
        }

        $stored_api_key = (string) $options['api_key'];
        if (!PurgeCF_Crypto::is_encrypted_api_key($stored_api_key)) {
            return false;
        }

        return PurgeCF_Crypto::decrypt_api_key($stored_api_key) === '';
    }
}
