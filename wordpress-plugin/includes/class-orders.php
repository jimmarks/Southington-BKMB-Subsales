<?php
/**
 * Orders Management
 *
 * Handles all order-related REST API endpoints including CRUD operations,
 * order history, soft deletion, restoration, and tally functionality.
 *
 * @package Subsales_Management
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Orders {
    
    /**
     * Initialize Orders functionality
     * No hooks needed - handlers are called directly from REST API class
     */
    public static function init() {
        // Orders class is stateless - handlers called directly via REST API routes
    }
    
    /**
     * Get orders with filtering and pagination
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with orders array
     */
    public static function get_orders( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_orders';
        
        $limit = $request->get_param( 'limit' ) ? intval( $request->get_param( 'limit' ) ) : 10;
        $offset = $request->get_param( 'offset' ) ? intval( $request->get_param( 'offset' ) ) : 0;
        
        // Filter parameters
        $user_id = $request->get_param( 'user_id' );
        $team_id = $request->get_param( 'team_id' );
        $date_from = $request->get_param( 'date_from' ); // YYYY-MM-DD
        $date_to = $request->get_param( 'date_to' );     // YYYY-MM-DD
        $today_only = $request->get_param( 'today_only' ); // boolean flag
        $show_deleted = $request->get_param( 'show_deleted' ); // boolean flag - default false
        
        // Legacy parameter support
        $entered_by_id = $request->get_param( 'entered_by_id' );
        if ( ! empty( $entered_by_id ) && empty( $user_id ) ) {
            $user_id = $entered_by_id;
        }
        
        // Build WHERE clause
        $where = array( '1=1' );
        $values = array();
        
        // Exclude deleted orders by default
        if ( $show_deleted !== 'true' && $show_deleted !== '1' && $show_deleted !== 1 && $show_deleted !== true ) {
            $where[] = 'deleted = 0';
        }

        // Always scoped to the current season - this is the admin orders
        // list, which must not mix in a prior season's already-handled orders.
        $where[] = 'season_id = %d';
        $values[] = intval( get_option( 'subsales_current_season_id' ) );

        if ( ! empty( $user_id ) ) {
            $where[] = 'user_id = %s';
            $values[] = $user_id;
        }
        
        if ( ! empty( $team_id ) ) {
            $where[] = 'team_id = %d';
            $values[] = intval( $team_id );
        }
        
        // Date filtering
        if ( $today_only === 'true' || $today_only === '1' || $today_only === 1 || $today_only === true ) {
            // Filter to today only (site timezone)
            $today = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
            $where[] = 'DATE(created_at) = %s';
            $values[] = $today;
        } else {
            // Custom date range
            if ( ! empty( $date_from ) ) {
                $where[] = 'DATE(created_at) >= %s';
                $values[] = sanitize_text_field( $date_from );
            }
            
            if ( ! empty( $date_to ) ) {
                $where[] = 'DATE(created_at) <= %s';
                $values[] = sanitize_text_field( $date_to );
            }
        }
        
        $where_sql = implode( ' AND ', $where );
        
        // Add limit and offset to values
        $values[] = $limit;
        $values[] = $offset;
        
        $query = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        
        if ( ! empty( $values ) ) {
            $orders = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );
        } else {
            $orders = $wpdb->get_results( $query, ARRAY_A );
        }
        
        foreach ( $orders as &$order ) {
            $order['order_data'] = json_decode( $order['order_data'], true );
            // Created_at coming from DB may be stored in GMT/UTC. Convert to site-local time for timestamp and "is_today" checks.
            if ( isset( $order['created_at'] ) && $order['created_at'] ) {
                $local = get_date_from_gmt( $order['created_at'] );
                $ts = strtotime( $local );
                $order['created_at_ts'] = $ts;
                $order['created_at'] = $local;
                $order['is_today'] = date_i18n( 'Y-m-d', $ts ) === date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
            } else {
                $order['created_at_ts'] = null;
                $order['is_today'] = false;
            }
        }
        
        return new WP_REST_Response( $orders, 200 );
    }

    /**
     * Permission: only the team's driver for today's campaign (or a WP admin)
     * may pull the whole-team money tally. A child gets 403.
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public static function check_team_tally_permission( $request ) {
        // WordPress admins always allowed
        if ( current_user_can( 'edit_posts' ) ) {
            return true;
        }

        $user_id = $request->get_header( 'X-User-ID' );
        $team_id = intval( $request->get_param( 'team_id' ) );
        if ( empty( $user_id ) || ! $team_id ) {
            return false;
        }

        // Allow if this user is a driver for this team on any campaign. A child
        // never has is_driver=1, so this keeps sellers out, while avoiding a
        // false 403 on a day that has no campaign yet.
        global $wpdb;
        $signups_table = $wpdb->prefix . 'ss_signups';
        $is_driver = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$signups_table} WHERE user_id = %d AND team_id = %d AND is_driver = 1",
            intval( $user_id ), $team_id
        ) );
        return ( intval( $is_driver ) > 0 );
    }

    /**
     * GET /team-tally?team_id=&date= — driver money-accountability tally.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_get_team_tally( $request ) {
        $team_id = intval( $request->get_param( 'team_id' ) );
        $date    = $request->get_param( 'date' );

        $tally = Subsales_Database::get_team_tally( $team_id, $date ? sanitize_text_field( $date ) : null );
        if ( is_wp_error( $tally ) ) {
            return $tally;
        }
        return new WP_REST_Response( $tally, 200 );
    }

    /**
     * Get single order by ID
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with order data or 404
     */
    public static function get_order_by_id( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_orders';
        
        $order_id = $request->get_param( 'id' );
        
        $order = $wpdb->get_row( 
            $wpdb->prepare( 
                "SELECT * FROM {$table_name} WHERE order_id = %s", 
                $order_id 
            ),
            ARRAY_A
        );
        
        if ( ! $order ) {
            return new WP_REST_Response( 'Order not found', 404 );
        }
        
        $order['order_data'] = json_decode( $order['order_data'], true );
        
        return new WP_REST_Response( $order, 200 );
    }
    
    /**
     * Create new order
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with success message and order ID
     */
    public static function create_order( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_orders';

        $data = $request->get_json_params();
        
        $sales_enabled = (bool) get_option( 'subsales_sales_enabled', 1 );
        if ( ! $sales_enabled ) {
            Subsales_Database::log( 'WARNING', 'orders', 'Order creation blocked - sales disabled', array(), 'api' );
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Sales are currently closed. Please check back later.'
            ), 403 );
        }
        
        // Log order creation attempt
        Subsales_Database::log( 'DEBUG', 'orders', 'Create order API called', array(
            'has_order_id' => isset( $data['order_id'] ),
            'has_user_id' => isset( $data['user_id'] ),
            'has_team_id' => isset( $data['team_id'] ),
            'data_keys' => array_keys( $data )
        ), 'api' );

        if ( ! isset( $data['order_id'] ) || ! isset( $data['user_id'] ) ) {
            Subsales_Database::log( 'DEBUG', 'orders', 'Create order failed: missing fields', array(), 'api' );
            return new WP_REST_Response( 'Missing required fields: order_id, user_id', 400 );
        }

        $order_id = sanitize_text_field( $data['order_id'] );
        $user_id = sanitize_text_field( $data['user_id'] );
        
        // Check if order already exists (avoid duplicate key error)
        $existing_order = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM $table_name WHERE order_id = %s",
            $order_id
        ) );
        
        if ( $existing_order ) {
            // Order already exists - return success (idempotent)
            Subsales_Database::log( 'DEBUG', 'orders', 'Order already exists (idempotent)', array(
                'order_id' => $order_id
            ), 'api' );
            return new WP_REST_Response( array(
                'message' => 'Order already exists',
                'order_id' => $order_id,
                'status' => 'exists'
            ), 200 );
        }

        // Resolve team id: prefer explicit payload value, otherwise fall back to team headers
        $team_id = null;
        if ( isset( $data['team_id'] ) ) {
            $team_id = intval( $data['team_id'] );
        } else {
            // Attempt to derive team from headers if provided
            try {
                $team_name = $request->get_header( 'X-Team-Name' );
                $access_code = $request->get_header( 'X-Access-Code' );
                if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
                    $team = Subsales_Database::get_team_by_credentials( sanitize_text_field( $team_name ), sanitize_text_field( $access_code ) );
                    if ( $team && isset( $team['id'] ) ) $team_id = intval( $team['id'] );
                }
            } catch ( Exception $e ) {
                // ignore and continue without team
                $team_id = null;
            }
        }

        $order_data = wp_json_encode( $data );

        $insert_row = array(
            'order_id' => $order_id,
            'user_id' => $user_id,
            'order_data' => $order_data,
            'sync_status' => 'synced',
            // Always known (a global "current season" setting, not
            // client-submitted) - unlike team_id this is never conditional.
            'season_id' => intval( get_option( 'subsales_current_season_id' ) ),
        );
        $formats = array( '%s', '%s', '%s', '%s', '%d' );
        if ( $team_id !== null ) {
            $insert_row['team_id'] = $team_id;
            $formats[] = '%d';
        }

        // Ensure created_at uses the WordPress site-local time (respects timezone settings)
        // so stored timestamps align with the site's timezone (avoid DB server UTC mismatch).
        $insert_row['created_at'] = current_time( 'mysql', true ); // Store in GMT
        $formats[] = '%s';

        $result = $wpdb->insert( $table_name, $insert_row, $formats );

        if ( $result === false ) {
            // Log order creation failure
            Subsales_Database::log( 'ERROR', 'orders', 'Failed to create order', array(
                'order_id' => $order_id,
                'user_id' => $user_id,
                'team_id' => $team_id,
                'db_error' => $wpdb->last_error
            ), 'api', null, $user_id );
            
            return new WP_REST_Response( 'Failed to create order', 500 );
        }
        
        // Get the newly created order's DB ID for history logging
        $new_order_db_id = $wpdb->insert_id;
        
        // Determine user name for logging
        $user_name_for_log = '';
        if ( ! empty( $user_id ) ) {
            $user_row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", 
                intval( $user_id ) 
            ) );
            if ( $user_row ) {
                $user_name_for_log = $user_row->name;
            }
        }
        
        // Log successful order creation with proper username
        Subsales_Database::log_order( 'created', $order_id, $user_id, $user_name_for_log, array(
            'team_id' => $team_id,
            'db_id' => $new_order_db_id
        ), 'pwa' );
        
        // Log order creation in history table
        if ( $new_order_db_id ) {
            $order_data_array = json_decode( $order_data, true );
            if ( ! is_array( $order_data_array ) ) {
                $order_data_array = array();
            }
            
            // Determine who created the order
            $creator_name = isset( $order_data_array['entered_by_name'] ) ? $order_data_array['entered_by_name'] : '';
            if ( empty( $creator_name ) && ! empty( $user_id ) ) {
                // Try to look up user name from team_members table
                $user_row = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", 
                    intval( $user_id ) 
                ) );
                if ( $user_row ) {
                    $creator_name = $user_row->name;
                }
            }
            if ( empty( $creator_name ) ) {
                $creator_name = 'Mobile User ' . $user_id;
            }
            
            // Log as 'create' action with empty before data
            Subsales_Database::log_order_change(
                $new_order_db_id,
                $order_id,
                array(), // before_data is empty for new orders
                $order_data_array, // after_data is the new order
                'create',
                intval( $user_id ),
                $creator_name,
                'Order created via mobile app',
                'pwa'
            );
        }

        return new WP_REST_Response( array( 'message' => 'Order created successfully', 'id' => $new_order_db_id ), 201 );
    }
    
    /**
     * Update existing order
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with success message
     */
    public static function update_order( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_orders';
        
        $order_id = $request->get_param( 'id' );
        $data = $request->get_json_params();
        
        // Try WordPress admin authentication first
        $current_user = wp_get_current_user();
        $is_admin = ( $current_user && $current_user->ID );
        
        $user_id = null;
        $user_name = '';
        $auth_source = 'admin';
        
        if ( $is_admin ) {
            // WordPress admin is authenticated
            $user_id = $current_user->ID;
            $user_name = $current_user->display_name;
            $auth_source = 'admin';
        } else {
            // Check for user-based authentication (X-User-ID header)
            $header_user_id = $request->get_header( 'X-User-ID' );
            
            if ( ! empty( $header_user_id ) ) {
                // User mode authentication
                $user_id = sanitize_text_field( $header_user_id );
                
                // Look up actual user name from database
                $user_row = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", 
                    intval( $user_id ) 
                ) );
                $user_name = $user_row ? $user_row->name : 'User ' . $user_id;
                $auth_source = 'pwa';
            } else {
                return new WP_REST_Response( 'Unauthorized - admin login or user credentials required', 401 );
            }
        }
        
        // Fetch existing order for history tracking
        $existing_order = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table_name} WHERE order_id = %s", $order_id ),
            ARRAY_A
        );
        
        if ( ! $existing_order ) {
            return new WP_REST_Response( 'Order not found', 404 );
        }
        
        // Parse before/after data for history
        $before_data = json_decode( $existing_order['order_data'], true );
        if ( ! is_array( $before_data ) ) {
            $before_data = array(); // Ensure it's always an array
        }
        $after_data = $data;
        
        // Get edit reason from request
        $edit_reason = isset( $data['_edit_reason'] ) ? sanitize_textarea_field( $data['_edit_reason'] ) : '';
        unset( $data['_edit_reason'] ); // Remove from order data
        
        $order_data = wp_json_encode( $data );
        
        if ( $order_data === false ) {
            Subsales_Database::log( 'ERROR', 'orders', 'Failed to encode order data as JSON', array(
                'order_id' => $order_id,
                'data_type' => gettype( $data )
            ), $auth_source, $user_id, $user_name );
            return new WP_REST_Response( 'Failed to encode order data', 500 );
        }

        $update_fields = array(
            'order_data' => $order_data,
            'sync_status' => 'updated'
        );
        $update_formats = array( '%s', '%s' );
        if ( isset( $data['team_id'] ) ) {
            $update_fields['team_id'] = intval( $data['team_id'] );
            $update_formats[] = '%d';
        }
        
        // Always sync the dedicated address column with the address in order_data
        if ( isset( $data['address'] ) ) {
            $update_fields['address'] = sanitize_text_field( $data['address'] );
            $update_formats[] = '%s';
        }
        
        // Check if address changed - if so, clear validation status to force re-validation
        $old_address = isset( $before_data['address'] ) ? $before_data['address'] : '';
        $new_address = isset( $after_data['address'] ) ? $after_data['address'] : '';
        
        if ( $old_address !== $new_address ) {
            // Address changed - clear validation data so it will be re-validated
            $update_fields['address_validation_status'] = null;
            $update_fields['address_validation_data'] = null;
            $update_fields['address_validation_date'] = null;
            $update_formats[] = '%s'; // NULL string
            $update_formats[] = '%s'; // NULL string
            $update_formats[] = '%s'; // NULL string
            
            Subsales_Database::log( 'INFO', 'orders', 'Address changed - clearing validation status', array(
                'order_id' => $order_id,
                'old_address' => $old_address,
                'new_address' => $new_address
            ), $auth_source, $user_id, $user_name );
        }

        $result = $wpdb->update(
            $table_name,
            $update_fields,
            array( 'order_id' => $order_id ),
            $update_formats,
            array( '%s' )
        );
        
        if ( $result === false ) {
            Subsales_Database::log( 'ERROR', 'orders', 'Failed to update order', array(
                'order_id' => $order_id,
                'db_error' => $wpdb->last_error
            ), $auth_source, $user_id, $user_name );
            return new WP_REST_Response( 'Failed to update order', 500 );
        }
        
        // Log change to history (even if no rows changed, user may have submitted same data)
        Subsales_Database::log_order_change(
            $existing_order['id'], // DB id
            $order_id, // Order ID from data
            $before_data,
            $after_data,
            'update',
            $user_id,
            $user_name,
            $edit_reason,
            $auth_source
        );
        
        return new WP_REST_Response( array(
            'message' => 'Order updated successfully',
            'changes_logged' => true
        ), 200 );
    }
    
    /**
     * Soft delete order
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with success message
     */
    public static function delete_order( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_orders';
        
        $order_id = $request->get_param( 'id' );
        $data = $request->get_json_params();
        
        // Support both WordPress admin auth AND PWA user auth
        $current_user = wp_get_current_user();
        $user_id = null;
        $user_name = 'Unknown User';
        $auth_type = 'admin';
        
        // Check WordPress admin authentication
        if ( $current_user && $current_user->ID ) {
            $user_id = $current_user->ID;
            $user_name = $current_user->display_name;
            $auth_type = 'admin';
        } else {
            // Check PWA user authentication via headers
            $pwa_user_id = $request->get_header( 'X-User-ID' );
            $pwa_team_id = $request->get_header( 'X-Team-ID' );
            
            if ( ! empty( $pwa_user_id ) && ! empty( $pwa_team_id ) ) {
                // Verify user exists in team_members table
                $members_table = $wpdb->prefix . 'ss_team_members';
                $user = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, name, phone FROM {$members_table} WHERE id = %d",
                    intval( $pwa_user_id )
                ), ARRAY_A );
                
                if ( $user ) {
                    $user_id = $user['id'];
                    $user_name = $user['name'] . ' (' . $user['phone'] . ')';
                    $auth_type = 'pwa';
                }
            }
        }
        
        // If neither auth method worked, reject
        if ( ! $user_id ) {
            return new WP_REST_Response( 'Unauthorized - no valid authentication', 401 );
        }
        
        // Require delete reason
        if ( ! isset( $data['delete_reason'] ) || empty( trim( $data['delete_reason'] ) ) ) {
            return new WP_REST_Response( 'Delete reason is required', 400 );
        }
        
        $delete_reason = sanitize_textarea_field( $data['delete_reason'] );
        
        // Fetch existing order for history tracking
        $existing_order = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table_name} WHERE order_id = %s AND deleted = 0", $order_id ),
            ARRAY_A
        );
        
        if ( ! $existing_order ) {
            return new WP_REST_Response( 'Order not found or already deleted', 404 );
        }
        
        // Soft delete: mark as deleted
        $result = $wpdb->update(
            $table_name,
            array(
                'deleted' => 1,
                'deleted_at' => current_time( 'mysql' ),
                'deleted_by_user_id' => $user_id,
                'delete_reason' => $delete_reason
            ),
            array( 'order_id' => $order_id ),
            array( '%d', '%s', '%d', '%s' ),
            array( '%s' )
        );
        
        if ( $result === false ) {
            Subsales_Database::log( 'ERROR', 'orders', 'Failed to delete order', array(
                'order_id' => $order_id,
                'db_error' => $wpdb->last_error
            ), $auth_type, $user_id, $user_name );
            return new WP_REST_Response( 'Failed to delete order', 500 );
        }
        
        // Parse order data for history
        $order_data = json_decode( $existing_order['order_data'], true );
        
        // Log deletion to history
        Subsales_Database::log_order_change(
            $existing_order['id'], // DB id
            $order_id, // Order ID from data
            $order_data, // before
            $order_data, // after (same, just marked deleted)
            'delete',
            $user_id,
            $user_name,
            $delete_reason,
            $auth_type
        );
        
        return new WP_REST_Response( array(
            'message' => 'Order deleted successfully',
            'soft_delete' => true
        ), 200 );
    }
    
    /**
     * Get edit history for an order
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with order history array
     */
    public static function get_order_history( $request ) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'ss_edit_history';
        $orders_table = $wpdb->prefix . 'ss_orders';
        
        $order_db_id = intval( $request->get_param( 'id' ) );
        
        // Verify order exists
        $order = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$orders_table} WHERE id = %d", $order_db_id ),
            ARRAY_A
        );
        
        if ( ! $order ) {
            return new WP_REST_Response( 'Order not found', 404 );
        }
        
        // Fetch history records
        $history = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$history_table} WHERE order_id = %d ORDER BY edited_at DESC",
                $order_db_id
            ),
            ARRAY_A
        );
        
        // Parse changes_detail JSON for each record
        foreach ( $history as &$record ) {
            if ( ! empty( $record['changes_detail'] ) ) {
                $record['changes_detail'] = json_decode( $record['changes_detail'], true );
            }
        }
        
        return new WP_REST_Response( array(
            'order_id' => $order_db_id,
            'order_reference' => $order['order_id'],
            'history' => $history,
            'total_edits' => count( $history )
        ), 200 );
    }
    
    /**
     * Restore a soft-deleted order
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with success message
     */
    public static function restore_order( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_orders';
        
        $order_db_id = intval( $request->get_param( 'id' ) );
        
        // Get current user info (must be WordPress admin)
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! $current_user->ID ) {
            return new WP_REST_Response( 'Unauthorized', 401 );
        }
        
        // Fetch existing order to verify it's deleted
        $existing_order = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d AND deleted = 1", $order_db_id ),
            ARRAY_A
        );
        
        if ( ! $existing_order ) {
            return new WP_REST_Response( 'Order not found or not deleted', 404 );
        }
        
        // Restore: clear deleted flags
        $result = $wpdb->update(
            $table_name,
            array(
                'deleted' => 0,
                'deleted_at' => null,
                'deleted_by_user_id' => null,
                'delete_reason' => null
            ),
            array( 'id' => $order_db_id ),
            array( '%d', '%s', '%s', '%s' ),
            array( '%d' )
        );
        
        if ( $result === false ) {
            Subsales_Database::log( 'ERROR', 'orders', 'Failed to restore order', array(
                'order_db_id' => $order_db_id,
                'order_id' => $existing_order['order_id'],
                'db_error' => $wpdb->last_error
            ), 'admin', $current_user->ID, $current_user->display_name );
            return new WP_REST_Response( 'Failed to restore order', 500 );
        }
        
        // Parse order data for history
        $order_data = json_decode( $existing_order['order_data'], true );
        
        // Log restoration to history
        Subsales_Database::log_order_change(
            $existing_order['id'], // DB id
            $existing_order['order_id'], // Order ID from data
            $order_data, // before
            $order_data, // after (same, just restored)
            'restore',
            $current_user->ID,
            $current_user->display_name,
            'Order restored from deleted status',
            'admin'
        );
        
        return new WP_REST_Response( array(
            'message' => 'Order restored successfully',
            'order_id' => $existing_order['order_id']
        ), 200 );
    }
    
    /**
     * Tally orders (mark as reconciled)
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with success count and errors
     */
    public static function tally_orders( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_orders';
        $current_user = wp_get_current_user();
        
        $data = $request->get_json_params();
        $order_ids = isset( $data['order_ids'] ) ? $data['order_ids'] : array();
        
        if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
            return new WP_REST_Response( array( 'error' => 'No order IDs provided' ), 400 );
        }
        
        $success_count = 0;
        $errors = array();
        
        foreach ( $order_ids as $db_id ) {
            // Get the existing order
            $existing_order = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table_name WHERE id = %d",
                $db_id
            ), ARRAY_A );
            
            if ( ! $existing_order ) {
                $errors[] = "Order ID $db_id not found";
                continue;
            }
            
            // Update tally status
            $result = $wpdb->update(
                $table_name,
                array(
                    'tallied' => 1,
                    'tallied_at' => current_time( 'mysql' ),
                    'tallied_by_user_id' => $current_user->ID
                ),
                array( 'id' => $db_id ),
                array( '%d', '%s', '%d' ),
                array( '%d' )
            );
            
            if ( $result !== false ) {
                // Log to history
                $order_data = json_decode( $existing_order['order_data'], true );
                Subsales_Database::log_order_change(
                    $existing_order['id'],
                    $existing_order['order_id'],
                    $order_data,
                    $order_data,
                    'update',
                    $current_user->ID,
                    $current_user->display_name,
                    'Order marked as tallied',
                    'admin'
                );
                $success_count++;
            } else {
                $errors[] = "Failed to tally order ID $db_id";
            }
        }
        
        return new WP_REST_Response( array(
            'message' => "$success_count order(s) tallied successfully",
            'success_count' => $success_count,
            'errors' => $errors
        ), 200 );
    }
}
