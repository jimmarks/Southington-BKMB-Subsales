<?php
/**
 * Settings for the Order Management System Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class OrderManagerSettings {
    private $options;

    public function __construct() {
        $this->options = get_option( 'order_manager_options' );
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    public function add_plugin_page() {
        add_options_page(
            'Order Manager Settings',
            'Order Manager',
            'manage_options',
            'order-manager-setting-admin',
            array( $this, 'create_admin_page' )
        );
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h1>Order Manager Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'order_manager_option_group' );
                do_settings_sections( 'order-manager-setting-admin' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function page_init() {
        register_setting(
            'order_manager_option_group',
            'order_manager_options',
            array( $this, 'sanitize' )
        );

        add_settings_section(
            'setting_section_id',
            'Settings',
            array( $this, 'print_section_info' ),
            'order-manager-setting-admin'
        );

        add_settings_field(
            'api_key',
            'Google Maps API Key',
            array( $this, 'api_key_callback' ),
            'order-manager-setting-admin',
            'setting_section_id'
        );

        add_settings_field(
            'sync_url',
            'Sync URL',
            array( $this, 'sync_url_callback' ),
            'order-manager-setting-admin',
            'setting_section_id'
        );
    }

    public function sanitize( $input ) {
        $new_input = array();
        if( isset( $input['api_key'] ) )
            $new_input['api_key'] = sanitize_text_field( $input['api_key'] );

        if( isset( $input['sync_url'] ) )
            $new_input['sync_url'] = sanitize_text_field( $input['sync_url'] );

        return $new_input;
    }

    public function print_section_info() {
        print 'Enter your settings below:';
    }

    public function api_key_callback() {
        printf(
            '<input type="text" id="api_key" name="order_manager_options[api_key]" value="%s" />',
            isset( $this->options['api_key'] ) ? esc_attr( $this->options['api_key']) : ''
        );
    }

    public function sync_url_callback() {
        printf(
            '<input type="text" id="sync_url" name="order_manager_options[sync_url]" value="%s" />',
            isset( $this->options['sync_url'] ) ? esc_attr( $this->options['sync_url']) : ''
        );
    }
}

if ( is_admin() )
    $order_manager_settings = new OrderManagerSettings();
?>