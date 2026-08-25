<?php
/**
 * REST API Management Class
 * 
 * Handles all REST API endpoint registration for the Subsales Management plugin.
 * Routes are registered under /wp-json/order-manager/v1/
 * 
 * @package Subsales_Management
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_REST_API {
    
    /**
     * Initialize REST API hooks
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }
    
    /**
     * Register all REST API routes
     */
    public static function register_routes() {
        // Orders API
        register_rest_route( 'order-manager/v1', '/orders', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Orders', 'get_orders' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        register_rest_route( 'order-manager/v1', '/orders', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Orders', 'create_order' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Orders', 'get_order_by_id' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'PUT',
            'callback' => array( 'Subsales_Orders', 'update_order' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'DELETE',
            'callback' => array( 'Subsales_Orders', 'delete_order' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        // Driver money-accountability tally (team-wide + per child). Driver-only.
        register_rest_route( 'order-manager/v1', '/team-tally', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Orders', 'rest_get_team_tally' ),
            'permission_callback' => array( 'Subsales_Orders', 'check_team_tally_permission' ),
        ));

        // Order History API
        register_rest_route( 'order-manager/v1', '/orders/(?P<id>\d+)/history', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Orders', 'get_order_history' ),
            'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
        ));
        
        // Order Restore API
        register_rest_route( 'order-manager/v1', '/orders/(?P<id>\d+)/restore', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Orders', 'restore_order' ),
            'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
        ));
        
        // Order Tally API
        register_rest_route( 'order-manager/v1', '/orders/tally', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Orders', 'tally_orders' ),
            'permission_callback' => array( __CLASS__, 'check_admin_permissions' ),
        ));
        
        // Authentication API
        register_rest_route( 'order-manager/v1', '/auth/login', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Teams', 'team_member_login' ),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/auth/verify', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Teams', 'verify_team_access' ),
            'permission_callback' => '__return_true',
        ));
        
        // Configuration API
        register_rest_route( 'order-manager/v1', '/config', array(
            'methods' => 'GET',
            'callback' => 'get_app_config',
            'permission_callback' => '__return_true',
        ));

        // Server Time API
        register_rest_route( 'order-manager/v1', '/time', array(
            'methods' => 'GET',
            'callback' => 'order_manager_get_server_time',
            'permission_callback' => '__return_true',
        ));
        
        // PWA Icon API - dynamically serve icon SVGs
        register_rest_route( 'order-manager/v1', '/pwa/icon-192', array(
            'methods' => 'GET',
            'callback' => 'subsales_serve_pwa_icon_192',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/pwa/icon-512', array(
            'methods' => 'GET',
            'callback' => 'subsales_serve_pwa_icon_512',
            'permission_callback' => '__return_true',
        ));
        
        // PWA Manifest API - dynamically serve manifest.json
        register_rest_route( 'order-manager/v1', '/pwa/manifest', array(
            'methods' => 'GET',
            'callback' => 'subsales_serve_pwa_manifest',
            'permission_callback' => '__return_true',
        ));
        
        // ZIP Index API - dynamically serve current ZIP list
        register_rest_route( 'order-manager/v1', '/zip-index', array(
            'methods' => 'GET',
            'callback' => 'subsales_get_zip_index_api',
            'permission_callback' => '__return_true',
        ));

        // Teams API
        register_rest_route( 'order-manager/v1', '/teams/members', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_team_members_endpoint' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        // User Management API
        register_rest_route( 'order-manager/v1', '/users', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Teams', 'create_user' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        register_rest_route( 'order-manager/v1', '/users', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_users' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_user_by_id' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array( 'Subsales_Teams', 'update_user' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array( 'Subsales_Teams', 'delete_user' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        // Team Assignment API
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)/teams', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_user_teams' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/assign', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Teams', 'assign_user_to_team' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/users/(?P<userId>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array( 'Subsales_Teams', 'remove_user_from_team' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/users', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_team_users' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));
        
        // PWA Session Tracking API
        register_rest_route( 'order-manager/v1', '/pwa-session/start', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'start_pwa_session' ),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/pwa-session/heartbeat', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'update_pwa_heartbeat' ),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/pwa-session/end', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'end_pwa_session' ),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/pwa-session/active', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'get_active_sessions' ),
            'permission_callback' => 'order_sync_check_admin_permissions',
        ));
        
        // PWA Logging API
        register_rest_route( 'order-manager/v1', '/log', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'pwa_log' ),
            'permission_callback' => '__return_true',
        ));
        
        // Signup/Campaign API
        register_rest_route( 'order-manager/v1', '/teams', array(
            'methods' => 'GET',
            'callback' => 'subsales_rest_search_teams',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/search', array(
            'methods' => 'GET',
            'callback' => 'subsales_rest_search_users',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/signup/verify-user', array(
            'methods' => 'POST',
            'callback' => 'subsales_rest_verify_user',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/campaigns', array(
            'methods' => 'GET',
            'callback' => 'subsales_rest_get_campaigns',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/signup', array(
            'methods' => 'POST',
            'callback' => 'subsales_rest_submit_signup',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/my-signups', array(
            'methods' => 'POST',
            'callback' => 'subsales_rest_get_my_signups',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/signup/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => 'subsales_rest_delete_signup',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/signup/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => 'subsales_rest_update_signup',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/team-roster', array(
            'methods' => 'GET',
            'callback' => 'subsales_rest_get_team_roster',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/team-driver', array(
            'methods' => 'PUT',
            'callback' => 'subsales_rest_update_team_driver',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/signup/check-name', array(
            'methods' => 'GET',
            'callback' => 'subsales_rest_check_name',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/signup/settings', array(
            'methods' => 'GET',
            'callback' => 'subsales_rest_signup_settings',
            'permission_callback' => '__return_true',
        ));

        // Digital payments (Square)
        register_rest_route( 'order-manager/v1', '/digital-payments/checkout', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Payment_Attempts', 'create_attempt' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        register_rest_route( 'order-manager/v1', '/digital-payments/status/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Payment_Attempts', 'get_status' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        register_rest_route( 'order-manager/v1', '/digital-payments/cancel/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Payment_Attempts', 'cancel_attempt' ),
            'permission_callback' => array( __CLASS__, 'check_permissions' ),
        ));

        register_rest_route( 'order-manager/v1', '/digital-payments/webhook', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Payment_Attempts', 'handle_webhook' ),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * ====================================================================
     * PWA SESSION ENDPOINTS
     * ====================================================================
     */
    
    /**
     * Start PWA session endpoint
     * 
     * POST /wp-json/order-manager/v1/pwa-session/start
     * Body: { sessionId, userId, userName, teamId, teamName, metadata }
     */
    public static function start_pwa_session( $request ) {
        $params = $request->get_json_params();
        
        $session_id = isset( $params['sessionId'] ) ? sanitize_text_field( $params['sessionId'] ) : '';
        $user_id = isset( $params['userId'] ) ? intval( $params['userId'] ) : null;
        $user_name = isset( $params['userName'] ) ? sanitize_text_field( $params['userName'] ) : '';
        $team_id = isset( $params['teamId'] ) ? intval( $params['teamId'] ) : null;
        $team_name = isset( $params['teamName'] ) ? sanitize_text_field( $params['teamName'] ) : '';
        $metadata = isset( $params['metadata'] ) && is_array( $params['metadata'] ) ? $params['metadata'] : array();
        
        // Debug log the incoming request
        Subsales_Database::log( 'DEBUG', 'pwa', 'PWA session start API called', array(
            'session_id' => $session_id,
            'user_name' => $user_name,
            'team_name' => $team_name,
            'has_metadata' => ! empty( $metadata )
        ), 'api', $user_id, $user_name );
        
        if ( empty( $session_id ) ) {
            Subsales_Database::log( 'DEBUG', 'pwa', 'PWA session start failed - missing session ID', array(), 'api' );
            return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
        }
        
        $result = Subsales_Database::start_pwa_session( $session_id, $user_id, $user_name, $team_id, $team_name, $metadata );
        
        if ( $result === false ) {
            Subsales_Database::log( 'DEBUG', 'pwa', 'PWA session start failed - database error', array(
                'session_id' => $session_id
            ), 'api', $user_id, $user_name );
            return new WP_Error( 'session_start_failed', 'Failed to start PWA session', array( 'status' => 500 ) );
        }
        
        Subsales_Database::log( 'DEBUG', 'pwa', 'PWA session started successfully', array(
            'session_id' => $session_id,
            'db_id' => $result
        ), 'api', $user_id, $user_name );
        
        return rest_ensure_response( array(
            'success' => true,
            'sessionId' => $session_id,
            'message' => 'PWA session started successfully'
        ) );
    }
    
    /**
     * Update PWA heartbeat endpoint
     * 
     * POST /wp-json/order-manager/v1/pwa-session/heartbeat
     * Body: { sessionId, activity, sessionExpiry, gps }
     */
    public static function update_pwa_heartbeat( $request ) {
        $params = $request->get_json_params();
        
        $session_id = isset( $params['sessionId'] ) ? sanitize_text_field( $params['sessionId'] ) : '';
        $activity = isset( $params['activity'] ) && is_array( $params['activity'] ) ? $params['activity'] : array();
        $session_expiry = isset( $params['sessionExpiry'] ) ? sanitize_text_field( $params['sessionExpiry'] ) : null;
        $gps = isset( $params['gps'] ) && is_array( $params['gps'] ) ? $params['gps'] : null;
        
        // Debug log heartbeat (only first time to avoid spam)
        static $heartbeat_logged = array();
        if ( ! isset( $heartbeat_logged[ $session_id ] ) ) {
            Subsales_Database::log( 'DEBUG', 'pwa', 'PWA heartbeat received (first)', array(
                'session_id' => $session_id,
                'has_activity' => ! empty( $activity ),
                'has_gps' => ! empty( $gps )
            ), 'api' );
            $heartbeat_logged[ $session_id ] = true;
        }
        
        if ( empty( $session_id ) ) {
            return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
        }
        
        try {
            $result = Subsales_Database::update_pwa_heartbeat( $session_id, $activity, $session_expiry, $gps );
            
            if ( $result === false ) {
                Subsales_Database::log( 'ERROR', 'pwa', 'PWA heartbeat failed - session not found', array(
                    'session_id' => $session_id
                ), 'api' );
                return new WP_Error( 'heartbeat_failed', 'Failed to update heartbeat', array( 'status' => 500 ) );
            }
        } catch ( Exception $e ) {
            Subsales_Database::log( 'ERROR', 'pwa', 'PWA heartbeat exception', array(
                'session_id' => $session_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ), 'api' );
            return new WP_Error( 'heartbeat_error', 'Heartbeat error: ' . $e->getMessage(), array( 'status' => 500 ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Heartbeat updated',
            'debugEnabled' => (bool) get_option( 'subsales_debug_logging_enabled', false )
        ) );
    }
    
    /**
     * End PWA session endpoint
     * 
     * POST /wp-json/order-manager/v1/pwa-session/end
     * Body: { sessionId }
     */
    public static function end_pwa_session( $request ) {
        $params = $request->get_json_params();
        
        $session_id = isset( $params['sessionId'] ) ? sanitize_text_field( $params['sessionId'] ) : '';
        
        if ( empty( $session_id ) ) {
            return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
        }
        
        $result = Subsales_Database::end_pwa_session( $session_id );
        
        if ( $result === false ) {
            return new WP_Error( 'session_end_failed', 'Failed to end session', array( 'status' => 500 ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Session ended successfully'
        ) );
    }
    
    /**
     * Get active PWA sessions endpoint
     * 
     * GET /wp-json/order-manager/v1/pwa-session/active
     */
    public static function get_active_sessions( $request ) {
        $limit = $request->get_param( 'limit' ) ? intval( $request->get_param( 'limit' ) ) : 100;
        
        $sessions = Subsales_Database::get_active_pwa_sessions( $limit );
        
        return rest_ensure_response( array(
            'success' => true,
            'count' => count( $sessions ),
            'sessions' => $sessions
        ) );
    }
    
    /**
     * PWA logging endpoint
     * 
     * POST /wp-json/order-manager/v1/log
     * Body: { level, category, message, context, user_name }
     */
    public static function pwa_log( $request ) {
        $params = $request->get_json_params();
        
        $level = isset( $params['level'] ) ? strtoupper( sanitize_text_field( $params['level'] ) ) : 'INFO';
        $category = isset( $params['category'] ) ? sanitize_text_field( $params['category'] ) : 'pwa';
        $message = isset( $params['message'] ) ? sanitize_text_field( $params['message'] ) : '';
        $context = isset( $params['context'] ) && is_array( $params['context'] ) ? $params['context'] : array();
        $user_name = isset( $params['user_name'] ) ? sanitize_text_field( $params['user_name'] ) : '';
        $source = isset( $params['source'] ) ? sanitize_text_field( $params['source'] ) : 'pwa-client';
        
        // Validate log level
        $valid_levels = array( 'DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL' );
        if ( ! in_array( $level, $valid_levels ) ) {
            $level = 'INFO';
        }
        
        // Add source to context if not already present
        if ( ! isset( $context['source'] ) ) {
            $context['source'] = $source;
        }
        
        // Log to database
        Subsales_Database::log( $level, $category, $message, $context, $source, null, $user_name );
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Log recorded'
        ) );
    }
    
    /**
     * Check permissions for REST API requests
     * Supports multiple authentication methods:
     * - Team credentials (X-Team-Name + X-Access-Code)
     * - User credentials (X-User-ID + X-Team-ID)
     * - Legacy team member (X-Team-Email + X-Team-ID)
     * - WordPress admin user
     *
     * @param WP_REST_Request $request REST request object
     * @return bool True if authenticated, false otherwise
     */
    public static function check_permissions( $request ) {
        global $wpdb;
        
        // Enhanced debug logging
        $all_headers = array();
        foreach ( $_SERVER as $key => $value ) {
            if ( strpos( $key, 'HTTP_X_' ) === 0 ) {
                $header_name = str_replace( 'HTTP_X_', 'X-', $key );
                $header_name = str_replace( '_', '-', $header_name );
                $all_headers[ $header_name ] = $value;
            }
        }
        error_log( 'Subsales: perm_check called for route: ' . $request->get_route() . ' | Headers: ' . wp_json_encode( $all_headers ) );
        
        if ( strpos( $request->get_route(), '/config' ) !== false ) {
            $team_name = $request->get_header( 'X-Team-Name' );
            $access_code = $request->get_header( 'X-Access-Code' );
            
            if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
                $team = order_sync_get_team_by_credentials( $team_name, $access_code );
                if ( $team ) {
                    return true;
                }
            }
        }
        
        // Legacy auth: X-Team-Name + X-Access-Code
        $team_name = $request->get_header( 'X-Team-Name' );
        $access_code = $request->get_header( 'X-Access-Code' );
        
        // Fallback: check $_SERVER directly in case headers aren't coming through properly
        if ( empty( $team_name ) && isset( $_SERVER['HTTP_X_TEAM_NAME'] ) ) {
            $team_name = sanitize_text_field( $_SERVER['HTTP_X_TEAM_NAME'] );
        }
        if ( empty( $access_code ) && isset( $_SERVER['HTTP_X_ACCESS_CODE'] ) ) {
            $access_code = sanitize_text_field( $_SERVER['HTTP_X_ACCESS_CODE'] );
        }
        
        if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
            $team = order_sync_get_team_by_credentials( $team_name, $access_code );
            if ( $team ) {
                error_log( 'Subsales: perm_check team creds ok id=' . ( isset($team['id']) ? $team['id'] : 'unknown' ) );
                return true;
            }
            error_log( 'Subsales: perm_check invalid team credentials provided (team=' . $team_name . ', code=' . ( $access_code ? 'present' : 'missing' ) . ')' );
            return false;
        }
        
        // User-based auth: X-User-ID + X-Team-ID (Phase 4)
        // NOTE: This validates user/team relationship WITHOUT requiring an active session
        // This allows offline orders to sync even after logout, using embedded credentials
        $user_id = $request->get_header( 'X-User-ID' );
        $team_id = $request->get_header( 'X-Team-ID' );
        
        // Fallback: check $_SERVER directly in case headers aren't coming through properly
        if ( empty( $user_id ) && isset( $_SERVER['HTTP_X_USER_ID'] ) ) {
            $user_id = sanitize_text_field( $_SERVER['HTTP_X_USER_ID'] );
        }
        if ( empty( $team_id ) && isset( $_SERVER['HTTP_X_TEAM_ID'] ) ) {
            $team_id = sanitize_text_field( $_SERVER['HTTP_X_TEAM_ID'] );
        }
        
        if ( ! empty( $user_id ) && ! empty( $team_id ) ) {
            $members_table = $wpdb->prefix . 'ss_team_members';
            $user_teams_table = $wpdb->prefix . 'ss_user_teams';
            
            // Verify user exists
            $user = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$members_table} WHERE id = %d",
                intval( $user_id )
            ), ARRAY_A );
            
            if ( ! $user ) {
                error_log( 'Subsales: perm_check invalid user_id=' . $user_id );
                return false;
            }
            
            // Special case: team_id = -1 means "individual" user (no team assignment required)
            // For individual users, verify they exist and optionally check for active session
            if ( $team_id === '-1' || intval( $team_id ) === -1 ) {
                // User existence already verified above - allow access for individual users
                // This allows them to view their orders even after session timeout
                // Security: They can only see orders WHERE user_id matches (enforced by get_orders filter)
                error_log( 'Subsales: perm_check individual user auth ok user_id=' . $user_id . ' (individual mode)' );
                return true;
            }
            
            // Verify user belongs to the team (validates relationship, not session)
            // This allows post-logout sync using order's embedded credentials
            $assignment = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
                intval( $user_id ),
                intval( $team_id )
            ));
            
            if ( $assignment ) {
                error_log( 'Subsales: perm_check user-based auth ok user_id=' . $user_id . ' team_id=' . $team_id . ' (session-independent)' );
                return true;
            }
            
            error_log( 'Subsales: perm_check user not in team user_id=' . $user_id . ' team_id=' . $team_id );
            return false;
        }
        
        // Legacy: X-Team-Email + X-Team-ID
        $team_email = $request->get_header( 'X-Team-Email' );
        $team_id = $request->get_header( 'X-Team-ID' );
        
        if ( ! empty( $team_email ) && ! empty( $team_id ) ) {
            $member = order_sync_verify_team_member( $team_email, $team_id );
            if ( $member ) {
                error_log( 'Subsales: perm_check team member ok id=' . ( isset($member['id']) ? $member['id'] : 'unknown' ) );
                return true;
            }
        }
        
        error_log( 'Subsales: perm_check FAILED - no valid auth headers, falling back to WP user check' );
        return current_user_can( 'edit_posts' );
    }
    
    /**
     * Admin-only permission callback for sensitive operations
     * 
     * @param WP_REST_Request $request REST request object
     * @return bool True if admin authenticated, false otherwise
     */
    public static function check_admin_permissions( $request ) {
        // Check if user is logged into WordPress admin with edit permissions
        if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
            return true;
        }
        
        // Optionally support API key authentication for admin operations
        $api_key = $request->get_header( 'X-API-Key' );
        $stored_key = get_option( 'order_sync_api_key', '' );
        
        if ( ! empty( $api_key ) && ! empty( $stored_key ) && hash_equals( $stored_key, $api_key ) ) {
            return true;
        }
        
        return false;
    }
}
