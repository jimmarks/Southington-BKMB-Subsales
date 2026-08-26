<?php
/**
 * AJAX Handlers
 *
 * Centralizes all AJAX callback functions for admin operations.
 *
 * @package SubsalesManagement
 * @since 2.2.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Subsales_AJAX_Handlers class
 *
 * Handles all wp_ajax_* callback functions.
 * Extracted from main plugin file to improve maintainability.
 */
class Subsales_AJAX_Handlers {

    /**
     * Register all AJAX action hooks
     */
    public static function init() {
        // Settings & System
        // Note: subsales_set_deletion_option is registered in main plugin file (deactivation flow)
        add_action( 'wp_ajax_subsales_toggle_debug', array( __CLASS__, 'toggle_debug' ) );
        add_action( 'wp_ajax_subsales_get_active_sessions_count', array( __CLASS__, 'get_active_sessions_count' ) );
        add_action( 'wp_ajax_subsales_test_maps_key', array( __CLASS__, 'test_maps_key' ) );
        add_action( 'wp_ajax_subsales_test_square_credentials', array( __CLASS__, 'test_square_credentials' ) );
        add_action( 'wp_ajax_subsales_test_twilio_credentials', array( __CLASS__, 'test_twilio_credentials' ) );
        add_action( 'wp_ajax_subsales_run_init', array( __CLASS__, 'run_init' ) );
        
        // ZIP Management
        add_action( 'wp_ajax_subsales_refresh_zip_index', array( __CLASS__, 'refresh_zip_index' ) );
        add_action( 'wp_ajax_subsales_update_sales_mode', array( __CLASS__, 'update_sales_mode' ) );
        
        // Orders
        add_action( 'wp_ajax_subsales_fetch_orders', array( __CLASS__, 'fetch_orders' ) );
        add_action( 'wp_ajax_subsales_get_order_by_db_id', array( __CLASS__, 'get_order_by_db_id' ) );
        add_action( 'wp_ajax_subsales_claim_edit_lock', array( __CLASS__, 'claim_edit_lock' ) );
        add_action( 'wp_ajax_subsales_release_edit_lock', array( __CLASS__, 'release_edit_lock' ) );
    }

    /**
     * Toggle debug mode
     */
    public static function toggle_debug() {
        check_ajax_referer( 'subsales_toggle_debug', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $current = get_option( 'subsales_debug_logging_enabled', false );
        $new_value = ! $current;

        update_option( 'subsales_debug_logging_enabled', $new_value );

        if ( $new_value ) {
            update_option( 'subsales_debug_logging_started', time() );
        } else {
            delete_option( 'subsales_debug_logging_started' );
        }

        subsales_log( 'INFO', 'system', 'Debug mode toggled', array( 'new_value' => $new_value ) );

        wp_send_json_success( array(
            'enabled' => $new_value,
            'message' => $new_value ? 'Debug mode enabled (auto-disables in 24 hours)' : 'Debug mode disabled'
        ) );
    }

    /**
     * Get active PWA sessions count
     */
    public static function get_active_sessions_count() {
        check_ajax_referer( 'subsales_sessions_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $count = Subsales_Database::get_active_pwa_sessions_count();
        wp_send_json_success( array( 'count' => $count ) );
    }

    /**
     * Test Google Maps API key
     */
    public static function test_maps_key() {
        check_ajax_referer( 'subsales_test_maps_key', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
        
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => 'API key is required' ) );
        }

        // Test the API key with a simple geocoding request
        $test_address = '1600 Amphitheatre Parkway, Mountain View, CA';
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query( array(
            'address' => $test_address,
            'key'     => $api_key,
        ) );

        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Failed to connect to Google Maps API: ' . $response->get_error_message() ) );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! isset( $data['status'] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid response from Google Maps API' ) );
        }

        if ( $data['status'] === 'OK' ) {
            wp_send_json_success( array( 'message' => 'API key is valid!' ) );
        } elseif ( $data['status'] === 'REQUEST_DENIED' ) {
            $error_message = isset( $data['error_message'] ) ? $data['error_message'] : 'API key is invalid or restricted';
            wp_send_json_error( array( 'message' => $error_message ) );
        } else {
            wp_send_json_error( array( 'message' => 'API test failed: ' . $data['status'] ) );
        }
    }

    /**
     * Test Square API credentials.
     *
     * Mirrors test_maps_key(): tests whatever environment/token/location is
     * currently typed into the settings form (not necessarily saved yet).
     */
    public static function test_square_credentials() {
        check_ajax_referer( 'subsales_test_square_credentials', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }

        $environment = isset( $_POST['environment'] ) ? sanitize_text_field( $_POST['environment'] ) : 'sandbox';
        if ( ! in_array( $environment, array( 'sandbox', 'production' ), true ) ) {
            $environment = 'sandbox';
        }
        $access_token = isset( $_POST['access_token'] ) ? sanitize_text_field( $_POST['access_token'] ) : '';
        $location_id  = isset( $_POST['location_id'] ) ? sanitize_text_field( $_POST['location_id'] ) : '';

        if ( empty( $access_token ) || empty( $location_id ) ) {
            wp_send_json_error( array( 'message' => 'Access token and Location ID are required' ) );
        }

        $base_url = ( 'production' === $environment ) ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';

        $response = wp_remote_get( $base_url . '/v2/locations/' . rawurlencode( $location_id ), array(
            'timeout' => 10,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $access_token,
                // Same pinned API version as Subsales_Square_Payments (Phase 2).
                'Square-Version' => '2024-01-18',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Failed to connect to Square API: ' . $response->get_error_message() ) );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( 200 === $response_code && ! empty( $data['location'] ) ) {
            $name = ! empty( $data['location']['business_name'] )
                ? $data['location']['business_name']
                : ( isset( $data['location']['name'] ) ? $data['location']['name'] : 'Square location' );
            wp_send_json_success( array( 'message' => 'Credentials are valid! Connected to: ' . $name ) );
        }

        $error_message = 'Square API test failed (HTTP ' . $response_code . ')';
        if ( ! empty( $data['errors'][0]['detail'] ) ) {
            $error_message = $data['errors'][0]['detail'];
        } elseif ( ! empty( $data['errors'][0]['code'] ) ) {
            $error_message = $data['errors'][0]['code'];
        }
        wp_send_json_error( array( 'message' => $error_message ) );
    }

    /**
     * Test Twilio API credentials.
     *
     * Mirrors test_square_credentials(): tests whatever SID/token is currently
     * typed into the settings form (not necessarily saved yet). Read-only -
     * no message is sent.
     */
    public static function test_twilio_credentials() {
        check_ajax_referer( 'subsales_test_twilio_credentials', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }

        $account_sid = isset( $_POST['account_sid'] ) ? sanitize_text_field( $_POST['account_sid'] ) : '';
        $auth_token  = isset( $_POST['auth_token'] ) ? sanitize_text_field( $_POST['auth_token'] ) : '';

        if ( empty( $account_sid ) || empty( $auth_token ) ) {
            wp_send_json_error( array( 'message' => 'Account SID and Auth Token are required' ) );
        }

        $account = Subsales_Twilio_SMS::test_credentials( $account_sid, $auth_token );

        if ( ! $account ) {
            // The wrapper already logged the exact response from Twilio.
            wp_send_json_error( array( 'message' => 'Twilio did not accept those details. Check the Account SID and Auth Token, then try again. (The exact reason is in the Logs page.)' ) );
        }

        $message = 'Details are valid! Connected to: ' . $account['friendly_name'];
        if ( ! empty( $account['status'] ) && 'active' !== $account['status'] ) {
            $message .= ' — note this account is currently "' . $account['status'] . '", not active.';
        }
        wp_send_json_success( array( 'message' => $message ) );
    }

    /**
     * Run initialization wizard
     */
    public static function run_init() {
        check_ajax_referer( 'subsales_run_init_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Parse submitted data
        $step = isset( $_POST['step'] ) ? sanitize_text_field( $_POST['step'] ) : '';
        
        if ( empty( $step ) ) {
            wp_send_json_error( 'Invalid step' );
        }

        switch ( $step ) {
            case 'products':
                $products = isset( $_POST['products'] ) ? $_POST['products'] : array();
                $validated_products = array();
                
                foreach ( $products as $product ) {
                    $validated_products[] = array(
                        'id'    => sanitize_text_field( $product['id'] ),
                        'name'  => sanitize_text_field( $product['name'] ),
                        'price' => floatval( $product['price'] ),
                    );
                }
                
                update_option( 'subsales_products', $validated_products );
                subsales_log( 'INFO', 'system', 'Products configured via init wizard', array( 'products' => $validated_products ) );
                break;
                
            case 'api_keys':
                $google_key = isset( $_POST['google_key'] ) ? sanitize_text_field( $_POST['google_key'] ) : '';
                $overpass_url = isset( $_POST['overpass_url'] ) ? esc_url_raw( $_POST['overpass_url'] ) : 'https://overpass-api.de/api/interpreter';
                
                update_option( 'subsales_google_maps_key', $google_key );
                update_option( 'subsales_overpass_url', $overpass_url );
                subsales_log( 'INFO', 'system', 'API keys configured via init wizard' );
                break;
                
            case 'zips':
                $served_zips = isset( $_POST['served_zips'] ) ? sanitize_text_field( $_POST['served_zips'] ) : '';
                $zips_array = array_map( 'trim', explode( ',', $served_zips ) );
                $zips_array = array_filter( $zips_array );
                
                update_option( 'subsales_served_zips', implode( ',', $zips_array ) );
                subsales_log( 'INFO', 'system', 'Served ZIPs configured via init wizard', array( 'zips' => $zips_array ) );
                break;
                
            case 'complete':
                update_option( 'subsales_initialized', 'yes' );
                subsales_log( 'INFO', 'system', 'Plugin initialization completed' );
                break;
                
            default:
                wp_send_json_error( 'Unknown step' );
        }
        
        wp_send_json_success( array( 'message' => 'Step completed' ) );
    }







    /**
     * Refresh ZIP index file
     */
    public static function refresh_zip_index() {
        check_ajax_referer( 'subsales_refresh_index', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $result = subsales_update_zip_index();
        
        if ( $result ) {
            $upload = wp_upload_dir();
            $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
            $existing_zips = array();
            if ( is_dir( $zipdata_dir ) ) {
                $files = glob( $zipdata_dir . '*.json' );
                if ( is_array( $files ) ) {
                    foreach ( $files as $file ) {
                        $basename = basename( $file, '.json' );
                        if ( preg_match( '/^[0-9]{5}$/', $basename ) ) {
                            $existing_zips[] = $basename;
                        }
                    }
                }
            }
            sort( $existing_zips );
            wp_send_json_success( array( 'message' => 'Index refreshed successfully', 'zips' => $existing_zips ) );
        } else {
            wp_send_json_error( 'Failed to update index file' );
        }
    }

    /**
     * Update sales mode (legacy/user)
     */
    public static function update_sales_mode() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        check_ajax_referer( 'subsales_sales_mode', 'nonce' );
        
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : '';
        
        if ( ! in_array( $mode, array( 'legacy', 'user' ), true ) ) {
            wp_send_json_error( 'Invalid mode. Must be "legacy" or "user".' );
        }
        
        update_option( 'subsales_sales_mode', $mode );
        
        wp_send_json_success( array( 'mode' => $mode ) );
    }

    /**
     * Fetch orders (delegates to main function)
     */
    public static function fetch_orders() {
        order_sync_fetch_orders_ajax();
    }

    /**
     * Get order by DB ID (delegates to main function)
     */
    public static function get_order_by_db_id() {
        subsales_get_order_by_db_id_ajax();
    }

    /**
     * Claim edit lock on an order
     * Updates order_data with editing_by and editing_since metadata
     */
    public static function claim_edit_lock() {
        check_ajax_referer( 'wp_rest', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        $user_name = isset( $_POST['user'] ) ? sanitize_text_field( $_POST['user'] ) : '';
        
        if ( ! $order_id || ! $user_name ) {
            wp_send_json_error( 'Missing order_id or user' );
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';
        
        // Get current order data
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT order_data FROM {$table} WHERE id = %d", $order_id ) );
        if ( ! $row ) {
            wp_send_json_error( 'Order not found' );
        }
        
        $order_data = json_decode( $row->order_data, true );
        if ( ! is_array( $order_data ) ) {
            $order_data = array();
        }
        
        // Update editing metadata
        $order_data['editing_by'] = $user_name;
        $order_data['editing_since'] = current_time( 'mysql' );
        
        // Save back to database
        $updated = $wpdb->update(
            $table,
            array( 'order_data' => wp_json_encode( $order_data ) ),
            array( 'id' => $order_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        if ( $updated === false ) {
            wp_send_json_error( 'Failed to claim lock' );
        }
        
        subsales_log( 'INFO', 'orders', 'Edit lock claimed', array(
            'order_id' => $order_id,
            'user' => $user_name
        ) );
        
        wp_send_json_success( array(
            'message' => 'Lock claimed',
            'editing_by' => $user_name,
            'editing_since' => $order_data['editing_since']
        ) );
    }

    /**
     * Release edit lock on an order
     * Removes editing_by and editing_since metadata
     */
    public static function release_edit_lock() {
        check_ajax_referer( 'wp_rest', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }
        
        $order_id = isset( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
        
        if ( ! $order_id ) {
            wp_send_json_error( 'Missing order_id' );
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';
        
        // Get current order data
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT order_data FROM {$table} WHERE id = %d", $order_id ) );
        if ( ! $row ) {
            wp_send_json_error( 'Order not found' );
        }
        
        $order_data = json_decode( $row->order_data, true );
        if ( ! is_array( $order_data ) ) {
            $order_data = array();
        }
        
        // Remove editing metadata
        unset( $order_data['editing_by'] );
        unset( $order_data['editing_since'] );
        
        // Save back to database
        $updated = $wpdb->update(
            $table,
            array( 'order_data' => wp_json_encode( $order_data ) ),
            array( 'id' => $order_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        if ( $updated === false ) {
            wp_send_json_error( 'Failed to release lock' );
        }
        
        subsales_log( 'INFO', 'orders', 'Edit lock released', array(
            'order_id' => $order_id
        ) );
        
        wp_send_json_success( array( 'message' => 'Lock released' ) );
    }

    // NOTE: this class used to also register ~10 address-pipeline hooks pointing at
    // methods that were never written (search_address, upload_address_file,
    // reassign_zips, the bg_match_* family, ...). Because init() runs before the
    // main plugin file's own add_action() calls, those broken registrations won the
    // hook and shadowed the working standalone functions. They were removed along
    // with the shapefile/Overpass/OpenAddresses pipeline; the remaining address
    // AJAX actions are registered in subsales-management.php.
}
