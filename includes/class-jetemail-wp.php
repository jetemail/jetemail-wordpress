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

        // Override WordPress mail function
        add_action('phpmailer_init', array($this, 'override_wordpress_mail'), 10, 1);

        // Get settings
        $this->api_key = get_option('jetemail_wp_api_key');
        $this->sender_email = get_option('jetemail_wp_sender_email', get_option('admin_email'));
        $this->sender_name = get_option('jetemail_wp_sender_name', get_bloginfo('name'));

        // Add filter for wp_mail_from and wp_mail_from_name
        add_filter('wp_mail_from', array($this, 'set_mail_from'));
        add_filter('wp_mail_from_name', array($this, 'set_mail_from_name'));
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
        $sender_email = get_option('jetemail_wp_sender_email', get_option('admin_email'));
        echo '<input type="email" id="jetemail_wp_sender_email" name="jetemail_wp_sender_email" value="' . esc_attr($sender_email) . '" class="regular-text">';
        echo '<p class="description">' . __('The email address that emails will be sent from. Must be a verified sender in your JetEmail account.', 'jetemail-wordpress') . '</p>';
    }

    public function sender_name_field_callback() {
        $sender_name = get_option('jetemail_wp_sender_name', get_bloginfo('name'));
        echo '<input type="text" id="jetemail_wp_sender_name" name="jetemail_wp_sender_name" value="' . esc_attr($sender_name) . '" class="regular-text">';
        echo '<p class="description">' . __('The name that will appear as the sender of emails.', 'jetemail-wordpress') . '</p>';
    }

    public function sanitize_email_field($email) {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            add_settings_error(
                'jetemail_wp_sender_email',
                'invalid_email',
                __('Please enter a valid email address.', 'jetemail-wordpress')
            );
            return get_option('jetemail_wp_sender_email', get_option('admin_email'));
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