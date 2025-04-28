<?php

if (!defined('ABSPATH')) {
    exit;
}

class JetEmail_WP_Updater {
    private $github_api_url = 'https://api.github.com/repos/jetemail/jetemail-wordpress';
    private $github_raw_url = 'https://raw.githubusercontent.com/jetemail/jetemail-wordpress';
    private $plugin_slug;
    private $plugin_file;
    private $plugin_basename;
    private $version;
    private $cache_key;
    private $cache_allowed;
    private $old_plugin_dir;

    public function __construct($plugin_file) {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug = basename(dirname(dirname($plugin_file)));
        $this->version = JETEMAIL_WP_VERSION;
        $this->cache_key = 'jetemail_wp_updater';
        $this->cache_allowed = true;

        // Debug current version and paths
        error_log('JetEmail current version: ' . $this->version);
        error_log('JetEmail plugin file: ' . $this->plugin_file);
        error_log('JetEmail plugin basename: ' . $this->plugin_basename);
        error_log('JetEmail plugin slug: ' . $this->plugin_slug);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'purge_cache'), 10, 2);

        // Add auto-update support
        add_filter('auto_update_plugin', array($this, 'auto_update_plugin'), 10, 2);
        add_filter('plugin_auto_update_setting_html', array($this, 'auto_update_setting_html'), 10, 2);
        
        // Add auto-update settings
        add_action('admin_init', array($this, 'register_auto_update_setting'));

        // Force check updates on init
        add_action('init', array($this, 'force_update_check'));

        // Add hooks for maintaining the correct directory name during updates
        add_filter('upgrader_pre_install', array($this, 'upgrader_pre_install'), 10, 2);
        add_filter('upgrader_post_install', array($this, 'upgrader_post_install'), 10, 3);
    }

    public function force_update_check() {
        // Delete the cached update data
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Debug transient data
        error_log('JetEmail checking for updates. Current transient: ' . print_r($transient, true));

        $remote_version = $this->get_remote_version();
        error_log('JetEmail remote version: ' . ($remote_version ? $remote_version : 'not found'));

        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            error_log('JetEmail new version available: ' . $remote_version);
            
            $release_info = $this->get_latest_release();
            if ($release_info) {
                $obj = new stdClass();
                $obj->slug = $this->plugin_slug;
                $obj->plugin = $this->plugin_basename;
                $obj->new_version = $remote_version;
                $obj->url = $release_info->html_url;
                
                // Use the release asset URL instead of zipball_url
                $download_url = $this->get_release_download_url($release_info);
                $obj->package = $download_url;
                
                $obj->tested = $this->get_tested_wp_version();
                $obj->requires = '5.0';
                $obj->requires_php = '7.2';
                $obj->icons = array(
                    '1x' => 'https://ps.w.org/jetemail-wordpress/assets/icon-128x128.png',
                    '2x' => 'https://ps.w.org/jetemail-wordpress/assets/icon-256x256.png'
                );

                error_log('JetEmail update object: ' . print_r($obj, true));

                $transient->response[$this->plugin_basename] = $obj;
            }
        } else {
            // Add the plugin to the no_update list to show it's up to date
            $obj = new stdClass();
            $obj->slug = $this->plugin_slug;
            $obj->plugin = $this->plugin_basename;
            $obj->new_version = $this->version;
            $obj->url = $this->github_api_url;
            $obj->package = '';
            $obj->tested = $this->get_tested_wp_version();
            $transient->no_update[$this->plugin_basename] = $obj;
            
            error_log('JetEmail no update needed. Current: ' . $this->version . ', Remote: ' . $remote_version);
        }

        return $transient;
    }

    private function get_release_download_url($release_info) {
        // First try to get the asset that ends with .zip
        if (!empty($release_info->assets)) {
            foreach ($release_info->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    return $asset->browser_download_url;
                }
            }
        }
        
        // If no .zip asset found, construct the download URL
        $tag = $release_info->tag_name;
        return "https://github.com/jetemail/jetemail-wordpress/archive/refs/tags/{$tag}.zip";
    }

    public function plugin_info($res, $action, $args) {
        // Do nothing if this is not about our plugin
        if ('plugin_information' !== $action || $this->plugin_slug !== $args->slug) {
            return $res;
        }

        $release_info = $this->get_latest_release();
        if (!$release_info) {
            return $res;
        }

        $res = new stdClass();
        $res->name = 'JetEmail for WordPress';
        $res->slug = $this->plugin_slug;
        $res->version = $this->get_remote_version();
        $res->tested = $this->get_tested_wp_version();
        $res->requires = '5.0';
        $res->requires_php = '7.2';
        $res->author = '<a href="https://jetemail.com">JetEmail</a>';
        $res->author_profile = 'https://github.com/jetemail';
        $res->download_link = $this->get_release_download_url($release_info);
        $res->trunk = $this->get_release_download_url($release_info);
        $res->last_updated = isset($release_info->published_at) ? date('Y-m-d H:i:s', strtotime($release_info->published_at)) : '';
        $res->banners = array(
            'high' => 'https://jetemail.com/wp-content/uploads/2024/04/jetemail-banner-1544x500.jpg',
            'low' => 'https://jetemail.com/wp-content/uploads/2024/04/jetemail-banner-772x250.jpg'
        );
        $res->icons = array(
            '1x' => 'https://jetemail.com/wp-content/uploads/2024/04/jetemail-icon-128x128.png',
            '2x' => 'https://jetemail.com/wp-content/uploads/2024/04/jetemail-icon-256x256.png'
        );
        
        // Get the description from the release body
        $description = $release_info->body;
        if (empty($description)) {
            $description = 'JetEmail is a WordPress plugin that allows you to send emails through JetEmail\'s transactional email service. ' .
                         'It provides a simple way to configure your email settings and ensures reliable email delivery for your WordPress site.';
        }

        // Format the changelog
        $changelog = $this->format_markdown($release_info->body);
        if (empty($changelog)) {
            $changelog = 'Visit the <a href="' . $release_info->html_url . '">release page</a> for the latest updates.';
        }

        // Set up the sections
        $res->sections = array(
            'description' => $this->format_markdown($description),
            'installation' => $this->get_installation_instructions(),
            'changelog' => $changelog,
            'faq' => $this->get_faq()
        );

        return $res;
    }

    private function get_installation_instructions() {
        return '<h4>Automatic Installation</h4>' .
               '<ol>' .
               '<li>Log in to your WordPress admin panel</li>' .
               '<li>Go to Plugins > Add New</li>' .
               '<li>Search for "JetEmail"</li>' .
               '<li>Click "Install Now" and then "Activate"</li>' .
               '</ol>' .
               '<h4>Manual Installation</h4>' .
               '<ol>' .
               '<li>Download the plugin ZIP file</li>' .
               '<li>Log in to your WordPress admin panel</li>' .
               '<li>Go to Plugins > Add New > Upload Plugin</li>' .
               '<li>Click "Choose File" and select the downloaded ZIP file</li>' .
               '<li>Click "Install Now" and then "Activate Plugin"</li>' .
               '</ol>' .
               '<h4>Configuration</h4>' .
               '<ol>' .
               '<li>Go to Settings > JetEmail</li>' .
               '<li>Enter your JetEmail API key</li>' .
               '<li>Optionally configure your default sender email and name</li>' .
               '<li>Save your settings</li>' .
               '</ol>';
    }

    private function get_faq() {
        return '<h4>How do I get a JetEmail API key?</h4>' .
               '<p>You can sign up for a JetEmail account at <a href="https://jetemail.com">jetemail.com</a>. Once logged in, you can generate an API key from your account settings.</p>' .
               '<h4>Will this plugin work with my existing WordPress emails?</h4>' .
               '<p>Yes! JetEmail automatically handles all emails sent through WordPress, including password resets, notifications, and contact form submissions.</p>' .
               '<h4>Can I customize the sender name and email?</h4>' .
               '<p>Yes, you can set a default sender name and email address in the plugin settings. If left blank, the plugin will use your WordPress defaults.</p>' .
               '<h4>Do you offer support?</h4>' .
               '<p>Yes, we provide support through our <a href="https://github.com/jetemail/jetemail-wordpress/issues">GitHub issues page</a>.</p>';
    }

    private function get_remote_version() {
        $release_info = $this->get_latest_release();
        if ($release_info && isset($release_info->tag_name)) {
            return ltrim($release_info->tag_name, 'v');
        }
        return false;
    }

    private function get_latest_release() {
        $releases_url = $this->github_api_url . '/releases/latest';
        $response = $this->github_api_request($releases_url);
        
        if ($response) {
            error_log('JetEmail GitHub release info: ' . print_r($response, true));
        } else {
            error_log('JetEmail failed to get GitHub release info');
        }
        
        return $response;
    }

    private function get_remote_info() {
        $repo_info = $this->github_api_request($this->github_api_url);
        if (!$repo_info) {
            return false;
        }

        $release_info = $this->get_latest_release();
        if ($release_info) {
            $repo_info->zipball_url = $release_info->zipball_url;
            $repo_info->published_at = $release_info->published_at;
        }

        return $repo_info;
    }

    private function get_readme_info() {
        $readme_url = $this->github_raw_url . '/main/README.md';
        $response = wp_remote_get($readme_url);
        
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return false;
        }

        $content = wp_remote_retrieve_body($response);
        return $this->parse_readme($content);
    }

    private function get_changelog() {
        $release_info = $this->get_latest_release();
        if ($release_info && !empty($release_info->body)) {
            return $this->format_markdown($release_info->body);
        }
        return 'No changelog available.';
    }

    private function get_tested_wp_version() {
        global $wp_version;
        return $wp_version;
    }

    private function github_api_request($url) {
        // Clear any existing cache
        delete_transient($this->cache_key . md5($url));

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            ),
            'timeout' => 10
        ));

        if (is_wp_error($response)) {
            error_log('JetEmail GitHub API Error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            error_log('JetEmail GitHub API Error: Unexpected response code ' . $response_code);
            error_log('JetEmail GitHub API Response: ' . wp_remote_retrieve_body($response));
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (is_object($data)) {
            set_transient($this->cache_key . md5($url), $data, 12 * HOUR_IN_SECONDS);
            return $data;
        }

        return false;
    }

    private function parse_readme($content) {
        $sections = new stdClass();
        
        // Extract description
        if (preg_match('/^#.*?\n(.*?)(?=##|$)/s', $content, $matches)) {
            $sections->description = $this->format_markdown($matches[1]);
        }

        // Extract installation
        if (preg_match('/##\s*Installation\s*(.*?)(?=##|$)/s', $content, $matches)) {
            $sections->installation = $this->format_markdown($matches[1]);
        }

        return $sections;
    }

    private function format_markdown($text) {
        // Basic Markdown formatting
        $text = trim($text);
        $text = preg_replace('/^###\s*(.*?)\s*$/m', '<h3>$1</h3>', $text);
        $text = preg_replace('/^##\s*(.*?)\s*$/m', '<h2>$1</h2>', $text);
        $text = preg_replace('/^#\s*(.*?)\s*$/m', '<h1>$1</h1>', $text);
        $text = preg_replace('/\*\*(.*?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.*?)\*/s', '<em>$1</em>', $text);
        $text = preg_replace('/`(.*?)`/s', '<code>$1</code>', $text);
        
        // Convert lists
        $text = preg_replace('/^\s*[\-\*]\s*(.*?)$/m', '<li>$1</li>', $text);
        $text = preg_replace('/(<li>.*?<\/li>)\s*(?=<li>|$)/s', '<ul>$1</ul>', $text);
        
        return $text;
    }

    public function purge_cache($upgrader, $options) {
        if ($this->cache_allowed && 
            'update' === $options['action'] && 
            'plugin' === $options['type']
        ) {
            // Clean all GitHub API caches
            global $wpdb;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                    '_transient_' . $this->cache_key . '%'
                )
            );
        }
    }

    /**
     * Register the auto-update setting
     */
    public function register_auto_update_setting() {
        register_setting(
            'jetemail_wp_settings',
            'jetemail_wp_auto_update',
            array(
                'type' => 'boolean',
                'default' => true,
                'sanitize_callback' => 'rest_sanitize_boolean'
            )
        );

        add_settings_field(
            'jetemail_wp_auto_update',
            __('Auto Updates', 'jetemail-wordpress'),
            array($this, 'auto_update_field_callback'),
            'jetemail-settings',
            'jetemail_wp_settings_section'
        );
    }

    /**
     * Render the auto-update setting field
     */
    public function auto_update_field_callback() {
        $auto_update = get_option('jetemail_wp_auto_update', true);
        ?>
        <label>
            <input type="checkbox" name="jetemail_wp_auto_update" value="1" <?php checked($auto_update); ?>>
            <?php _e('Enable automatic updates for JetEmail', 'jetemail-wordpress'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, JetEmail will automatically update to the latest version when available.', 'jetemail-wordpress'); ?>
        </p>
        <?php
    }

    /**
     * Determine if the plugin should be automatically updated
     */
    public function auto_update_plugin($update, $item) {
        if (!isset($item->slug)) {
            return $update;
        }

        // Check if this is our plugin
        if ($item->slug === $this->plugin_slug) {
            // Get the auto-update setting (defaults to true)
            return get_option('jetemail_wp_auto_update', true);
        }

        return $update;
    }

    /**
     * Customize the auto-update column text
     */
    public function auto_update_setting_html($html, $plugin_file) {
        if ($plugin_file !== $this->plugin_basename) {
            return $html;
        }

        $auto_update = get_option('jetemail_wp_auto_update', true);
        if ($auto_update) {
            return __('Auto-updates enabled', 'jetemail-wordpress');
        } else {
            return __('Auto-updates disabled', 'jetemail-wordpress');
        }
    }

    public function upgrader_pre_install($true, $args) {
        // Get the plugin directory name
        $plugin_dir = dirname($this->plugin_basename);
        
        // Save the old directory name
        $this->old_plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_dir;
        
        return $true;
    }

    public function upgrader_post_install($true, $hook_extra, $result) {
        global $wp_filesystem;
        
        // Get the expected plugin directory name
        $plugin_dir = 'jetemail-wordpress';
        $proper_destination = WP_PLUGIN_DIR . '/' . $plugin_dir;
        
        // Check if the plugin directory exists
        if (!$wp_filesystem->exists($proper_destination)) {
            // Find the extracted directory (it might have a different name)
            $extracted_dir = '';
            
            // Look for any directory that starts with jetemail-wordpress
            $dirs = glob(WP_PLUGIN_DIR . '/jetemail-wordpress*');
            foreach ($dirs as $dir) {
                if ($dir !== $proper_destination && is_dir($dir)) {
                    $extracted_dir = $dir;
                    break;
                }
            }
            
            // If we found the extracted directory and it's different from what we want
            if (!empty($extracted_dir) && $extracted_dir !== $proper_destination) {
                // If the proper destination already exists, remove it
                if ($wp_filesystem->exists($proper_destination)) {
                    $wp_filesystem->delete($proper_destination, true);
                }
                
                // Move the extracted directory to the correct location
                $wp_filesystem->move($extracted_dir, $proper_destination);
                
                // Log the directory change
                error_log('JetEmail: Moved plugin directory from ' . $extracted_dir . ' to ' . $proper_destination);
            }
        }
        
        return $true;
    }
} 