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
        add_action( 'wp_ajax_subsales_run_init', array( __CLASS__, 'run_init' ) );
        
        // Address Management
        add_action( 'wp_ajax_subsales_search_address', array( __CLASS__, 'search_address' ) );
        add_action( 'wp_ajax_subsales_download_openaddresses', array( __CLASS__, 'download_openaddresses' ) );
        add_action( 'wp_ajax_subsales_extract_openaddresses_zips', array( __CLASS__, 'extract_openaddresses_zips' ) );
        add_action( 'wp_ajax_subsales_match_addresses_batch', array( __CLASS__, 'match_addresses_batch' ) );
        add_action( 'wp_ajax_subsales_upload_address_file', array( __CLASS__, 'upload_address_file' ) );
        add_action( 'wp_ajax_subsales_upload_status', array( __CLASS__, 'upload_status' ) );
        add_action( 'wp_ajax_subsales_reassign_zips', array( __CLASS__, 'reassign_zips' ) );
        add_action( 'wp_ajax_subsales_reassign_zips_legacy', array( __CLASS__, 'reassign_zips_legacy' ) );
        
        // Background Matcher
        add_action( 'wp_ajax_subsales_bg_match_start', array( __CLASS__, 'bg_match_start' ) );
        add_action( 'wp_ajax_subsales_bg_match_stop', array( __CLASS__, 'bg_match_stop' ) );
        add_action( 'wp_ajax_subsales_bg_match_resume', array( __CLASS__, 'bg_match_resume' ) );
        add_action( 'wp_ajax_subsales_bg_match_status', array( __CLASS__, 'bg_match_status' ) );
        add_action( 'wp_ajax_subsales_bg_match_reset', array( __CLASS__, 'bg_match_reset' ) );
        
        // ZIP Management
        add_action( 'wp_ajax_subsales_delete_zip_extract', array( __CLASS__, 'delete_zip_extract' ) );
        add_action( 'wp_ajax_subsales_refresh_zip_index', array( __CLASS__, 'refresh_zip_index' ) );
        add_action( 'wp_ajax_subsales_update_sales_mode', array( __CLASS__, 'update_sales_mode' ) );
        add_action( 'wp_ajax_subsales_generate_zip_extracts', array( __CLASS__, 'generate_zip_extracts' ) );
        add_action( 'wp_ajax_subsales_upload_zip_boundaries', array( __CLASS__, 'upload_zip_boundaries' ) );
        
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
     * Background matcher control - Start
     */
    public static function bg_match_start() {
        check_ajax_referer( 'subsales_bg_match', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $result = Subsales_Background_Matcher::start_job();
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Background matcher control - Stop
     */
    public static function bg_match_stop() {
        check_ajax_referer( 'subsales_bg_match', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $result = Subsales_Background_Matcher::stop_job();
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Background matcher control - Resume
     */
    public static function bg_match_resume() {
        check_ajax_referer( 'subsales_bg_match', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $result = Subsales_Background_Matcher::resume_job();
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Background matcher control - Status
     */
    public static function bg_match_status() {
        check_ajax_referer( 'subsales_bg_match', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $status = Subsales_Background_Matcher::get_complete_status();
        wp_send_json_success( $status );
    }

    /**
     * Background matcher control - Reset
     */
    public static function bg_match_reset() {
        check_ajax_referer( 'subsales_bg_match', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        $result = Subsales_Background_Matcher::reset_job();
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Match addresses batch using Overpass API
     */
    public static function match_addresses_batch() {
        check_ajax_referer( 'subsales_match_addresses', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }
        
        if ( ! class_exists( 'Subsales_Overpass_Matcher' ) ) {
            wp_send_json_error( 'Overpass Matcher class not loaded' );
        }
        
        $result = Subsales_Overpass_Matcher::match_addresses();
        
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
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

    // NOTE: The following complex handlers delegate to standalone functions in the main file
    // to avoid moving hundreds of lines of business logic:
    // - search_address() - calls subsales_search_address_preview()
    // - download_openaddresses() - complex file handling
    // - extract_openaddresses_zips() - calls subsales_extract_openaddresses_zips()
    // - upload_address_file() - calls subsales_upload_address_file_ajax()
    // - upload_status() - calls subsales_upload_status_ajax()
    // - reassign_zips() - calls subsales_reassign_zips_ajax()
    // - reassign_zips_legacy() - calls subsales_reassign_zips_legacy_ajax()
    // - delete_zip_extract() - calls subsales_delete_zip_extract()
    // - generate_zip_extracts() - calls subsales_generate_zip_extracts()
    // - upload_zip_boundaries() - calls subsales_upload_zip_boundaries_ajax()
    //
    // These will remain as standalone functions since they contain significant business
    // logic that would require extensive refactoring. The simpler handlers above
    // delegate to those standalone functions.
}
