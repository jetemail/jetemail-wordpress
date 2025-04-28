<?php
/**
 * Plugin Name: JetEmail for WordPress
 * Plugin URI: https://jetemail.com
 * Description: Send emails through JetEmail's transactional email service
 * Version: 1.0.1
 * Author: JetEmail
 * Author URI: https://jetemail.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jetemail-wordpress
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('JETEMAIL_WP_VERSION', '1.0.1');
define('JETEMAIL_WP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JETEMAIL_WP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JETEMAIL_WP_PLUGIN_FILE', __FILE__);

// Include required files
require_once JETEMAIL_WP_PLUGIN_DIR . 'includes/class-jetemail-wp.php';
require_once JETEMAIL_WP_PLUGIN_DIR . 'includes/class-jetemail-wp-updater.php';

// Initialize the plugin
function jetemail_wp_init() {
    $plugin = new JetEmail_WP();
    $plugin->init();

    // Initialize the updater
    if (is_admin()) {
        new JetEmail_WP_Updater(JETEMAIL_WP_PLUGIN_FILE);
    }
}
add_action('plugins_loaded', 'jetemail_wp_init');

// Activation hook
register_activation_hook(__FILE__, 'jetemail_wp_activate');
function jetemail_wp_activate() {
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'jetemail_wp_deactivate');
function jetemail_wp_deactivate() {
} 