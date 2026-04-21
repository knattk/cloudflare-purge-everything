<?php
/**
 * Plugin Name: Purge Everything - Cloudflare
 * Description: Purge Everything from Cloudflare cache with a single click from WordPress admin.
 * Version: 1.0.0
 * Author: Mind Edge
 * Author URI: https://github.com/mindedgedevel
 * License: GPLv2
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once plugin_dir_path(__FILE__) . 'includes/class-cf-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-crypto.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-cache-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-plugin.php';

function purgecf_init_plugin() {
    $cache_manager = new PurgeCF_CacheManager();
    new PurgeCF_Admin();
    new PurgeCF_Plugin($cache_manager);
}
add_action('plugins_loaded', 'purgecf_init_plugin');
 