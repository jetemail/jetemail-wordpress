<?php

if (!defined('ABSPATH')) {
    exit;
}

class JetEmail_WP {
    private $api_key;
    private $api_endpoint = 'https://api.jetemail.com/email';

    public function init() {
        // Load plugin text domain
        add_action('init', array($this, 'load_textdomain'));

        // Add settings page
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Override WordPress mail function
        add_action('phpmailer_init', array($this, 'override_wordpress_mail'), 10, 1);

        // Get API key
        $this->api_key = get_option('jetemail_wp_api_key');
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
        register_setting('jetemail_wp_settings', 'jetemail_wp_api_key');

        add_settings_section(
            'jetemail_wp_settings_section',
            __('API Settings', 'jetemail-wordpress'),
            array($this, 'settings_section_callback'),
            'jetemail-settings'
        );

        add_settings_field(
            'jetemail_wp_api_key',
            __('API Key', 'jetemail-wordpress'),
            array($this, 'api_key_field_callback'),
            'jetemail-settings',
            'jetemail_wp_settings_section'
        );
    }

    public function settings_section_callback() {
        echo '<p>' . __('Enter your JetEmail API credentials below:', 'jetemail-wordpress') . '</p>';
    }

    public function api_key_field_callback() {
        $api_key = get_option('jetemail_wp_api_key');
        echo '<input type="password" id="jetemail_wp_api_key" name="jetemail_wp_api_key" value="' . esc_attr($api_key) . '" class="regular-text">';
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

    public function override_wordpress_mail($phpmailer) {
        // Clear recipients immediately to prevent WordPress from sending
        $to = $phpmailer->getToAddresses();
        $phpmailer->clearAllRecipients();
        $phpmailer->clearAttachments();

        if (empty($this->api_key)) {
            error_log('JetEmail API Error: No API key configured');
            return false;
        }

        // Get email data from PHPMailer object
        $from = $phpmailer->From;
        $from_name = $phpmailer->FromName;
        $subject = $phpmailer->Subject;
        $body = $phpmailer->Body;
        $is_html = ($phpmailer->ContentType === 'text/html');
        
        // Format recipients
        $recipients = array();
        foreach ($to as $recipient) {
            $recipients[] = $recipient[0];
        }

        // Prepare the API request
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json'
        );

        $body_data = array(
            'from' => $from_name . ' <' . $from . '>',
            'to' => implode(',', $recipients),
            'subject' => $subject
        );

        if ($is_html) {
            $body_data['html'] = $body;
        } else {
            $body_data['text'] = $body;
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
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 201) {
            error_log('JetEmail API Error: Unexpected response code ' . $response_code);
            return false;
        }

        return true;
    }
} 