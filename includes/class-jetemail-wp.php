<?php

if (!defined('ABSPATH')) {
    exit;
}

class JetEmail_WP {
    private $api_key;
    private $api_endpoint = 'https://api.jetemail.com/email';
    private $sender_email;
    private $sender_name;

    public function init() {
        // Load plugin text domain
        add_action('init', array($this, 'load_textdomain'));

        // Add settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Get settings
        $this->api_key = get_option('jetemail_wp_api_key');
        $this->sender_email = get_option('jetemail_wp_sender_email', '');
        $this->sender_name = get_option('jetemail_wp_sender_name', '');

        // Add filter for wp_mail_from and wp_mail_from_name
        add_filter('wp_mail_from', array($this, 'set_mail_from'));
        add_filter('wp_mail_from_name', array($this, 'set_mail_from_name'));

        // Override WordPress mail function
        add_filter('wp_mail', array($this, 'send_via_jetemail'), 10, 1);
        add_filter('pre_wp_mail', array($this, 'prevent_default_sending'), 10, 2);
    }

    public function load_textdomain() {
        load_plugin_textdomain('jetemail-wordpress', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function add_admin_menu() {
        add_options_page(
            __('JetEmail Settings', 'jetemail-wordpress'),
            __('JetEmail', 'jetemail-wordpress'),
            'manage_options',
            'jetemail-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        // API Key setting
        register_setting('jetemail_wp_settings', 'jetemail_wp_api_key');

        // Sender Email settings
        register_setting('jetemail_wp_settings', 'jetemail_wp_sender_email', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_email_field')
        ));
        register_setting('jetemail_wp_settings', 'jetemail_wp_sender_name');

        // Settings section
        add_settings_section(
            'jetemail_wp_settings_section',
            __('API Settings', 'jetemail-wordpress'),
            array($this, 'settings_section_callback'),
            'jetemail-settings'
        );

        // API Key field
        add_settings_field(
            'jetemail_wp_api_key',
            __('API Key', 'jetemail-wordpress'),
            array($this, 'api_key_field_callback'),
            'jetemail-settings',
            'jetemail_wp_settings_section'
        );

        // Sender Email field
        add_settings_field(
            'jetemail_wp_sender_email',
            __('Default Sender Email', 'jetemail-wordpress'),
            array($this, 'sender_email_field_callback'),
            'jetemail-settings',
            'jetemail_wp_settings_section'
        );

        // Sender Name field
        add_settings_field(
            'jetemail_wp_sender_name',
            __('Default Sender Name', 'jetemail-wordpress'),
            array($this, 'sender_name_field_callback'),
            'jetemail-settings',
            'jetemail_wp_settings_section'
        );
    }

    public function settings_section_callback() {
        echo '<p>' . __('Configure your JetEmail settings below:', 'jetemail-wordpress') . '</p>';
        
        // Add a note about sender email
        echo '<p><strong>' . __('Default Sender:', 'jetemail-wordpress') . '</strong> ';
        echo __('Set the default "From" email address and name for all outgoing emails. If left empty, the admin email and site name will be used.', 'jetemail-wordpress') . '</p>';
        
        // Add a note about auto-updates
        echo '<p><strong>' . __('Auto-Updates:', 'jetemail-wordpress') . '</strong> ';
        echo __('You can control automatic updates for JetEmail below. We recommend keeping auto-updates enabled to ensure you have the latest features and security updates.', 'jetemail-wordpress') . '</p>';
    }

    public function api_key_field_callback() {
        $api_key = get_option('jetemail_wp_api_key');
        echo '<input type="password" id="jetemail_wp_api_key" name="jetemail_wp_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
    }

    public function sender_email_field_callback() {
        $sender_email = get_option('jetemail_wp_sender_email', '');
        echo '<input type="email" id="jetemail_wp_sender_email" name="jetemail_wp_sender_email" value="' . esc_attr($sender_email) . '" class="regular-text">';
        echo '<p class="description">' . __('The email address that emails will be sent from. Leave blank to use the WordPress default (admin email).', 'jetemail-wordpress') . '</p>';
    }

    public function sender_name_field_callback() {
        $sender_name = get_option('jetemail_wp_sender_name', '');
        echo '<input type="text" id="jetemail_wp_sender_name" name="jetemail_wp_sender_name" value="' . esc_attr($sender_name) . '" class="regular-text">';
        echo '<p class="description">' . __('The name that will appear as the sender of emails. Leave blank to use the WordPress default (site name).', 'jetemail-wordpress') . '</p>';
    }

    public function sanitize_email_field($email) {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            add_settings_error(
                'jetemail_wp_sender_email',
                'invalid_email',
                __('Please enter a valid email address.', 'jetemail-wordpress')
            );
            return get_option('jetemail_wp_sender_email', '');
        }
        return $email;
    }

    public function set_mail_from($email) {
        // Only override if we have a custom sender email set
        if (!empty($this->sender_email)) {
            return $this->sender_email;
        }
        return $email;
    }

    public function set_mail_from_name($name) {
        // Only override if we have a custom sender name set
        if (!empty($this->sender_name)) {
            return $this->sender_name;
        }
        return $name;
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('jetemail_wp_settings');
                do_settings_sections('jetemail-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function prevent_default_sending($pre, $atts) {
        // Return true to prevent WordPress from sending the email through its default method
        return true;
    }

    public function send_via_jetemail($atts) {
        if (empty($this->api_key)) {
            error_log('JetEmail API Error: No API key configured');
            return $atts;
        }

        // Get email data
        $to = is_array($atts['to']) ? $atts['to'] : array($atts['to']);
        $subject = $atts['subject'];
        $message = $atts['message'];
        $headers = $atts['headers'];
        
        // Use WordPress defaults if custom values are empty
        $from_email = !empty($this->sender_email) ? $this->sender_email : get_option('admin_email');
        $from_name = !empty($this->sender_name) ? $this->sender_name : get_bloginfo('name');

        // Parse headers for content type and additional recipients
        $is_html = false;
        foreach ((array)$headers as $header) {
            if (stripos($header, 'content-type') !== false && stripos($header, 'text/html') !== false) {
                $is_html = true;
            }
        }

        // Prepare the API request
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json'
        );

        $body_data = array(
            'from' => $from_name . ' <' . $from_email . '>',
            'to' => implode(',', $to),
            'subject' => $subject
        );

        if ($is_html) {
            $body_data['html'] = $message;
        } else {
            $body_data['text'] = $message;
        }

        // Send request to JetEmail API
        $response = wp_remote_post($this->api_endpoint, array(
            'headers' => $headers,
            'body' => json_encode($body_data),
            'timeout' => 30
        ));

        // Check for errors
        if (is_wp_error($response)) {
            error_log('JetEmail API Error: ' . $response->get_error_message());
            return $atts;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 201) {
            error_log('JetEmail API Error: Unexpected response code ' . $response_code);
            error_log('JetEmail API Response: ' . wp_remote_retrieve_body($response));
            return $atts;
        }

        return $atts;
    }
} 