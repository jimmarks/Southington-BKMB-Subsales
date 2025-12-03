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
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Orders', 'create_order' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Orders', 'get_order_by_id' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'PUT',
            'callback' => array( 'Subsales_Orders', 'update_order' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'DELETE',
            'callback' => array( 'Subsales_Orders', 'delete_order' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        // Order History API
        register_rest_route( 'order-manager/v1', '/orders/(?P<id>\d+)/history', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Orders', 'get_order_history' ),
            'permission_callback' => 'order_sync_check_admin_permissions',
        ));
        
        // Order Restore API
        register_rest_route( 'order-manager/v1', '/orders/(?P<id>\d+)/restore', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Orders', 'restore_order' ),
            'permission_callback' => 'order_sync_check_admin_permissions',
        ));
        
        // Order Tally API
        register_rest_route( 'order-manager/v1', '/orders/tally', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Orders', 'tally_orders' ),
            'permission_callback' => 'order_sync_check_admin_permissions',
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

        // Teams API
        register_rest_route( 'order-manager/v1', '/teams/members', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_team_members_endpoint' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        // User Management API
        register_rest_route( 'order-manager/v1', '/users', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Teams', 'create_user' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_users' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_user_by_id' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array( 'Subsales_Teams', 'update_user' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array( 'Subsales_Teams', 'delete_user' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/search', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'search_users' ),
            'permission_callback' => '__return_true', // Public for PWA login
        ));
        
        // Team Assignment API
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)/teams', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_user_teams' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/assign', array(
            'methods' => 'POST',
            'callback' => array( 'Subsales_Teams', 'assign_user_to_team' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/users/(?P<userId>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array( 'Subsales_Teams', 'remove_user_from_team' ),
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/users', array(
            'methods' => 'GET',
            'callback' => array( 'Subsales_Teams', 'get_team_users' ),
            'permission_callback' => 'order_sync_check_permissions',
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
     * Body: { sessionId, activity }
     */
    public static function update_pwa_heartbeat( $request ) {
        $params = $request->get_json_params();
        
        $session_id = isset( $params['sessionId'] ) ? sanitize_text_field( $params['sessionId'] ) : '';
        $activity = isset( $params['activity'] ) && is_array( $params['activity'] ) ? $params['activity'] : array();
        
        // Debug log heartbeat (only first time to avoid spam)
        static $heartbeat_logged = array();
        if ( ! isset( $heartbeat_logged[ $session_id ] ) ) {
            Subsales_Database::log( 'DEBUG', 'pwa', 'PWA heartbeat received (first)', array(
                'session_id' => $session_id,
                'has_activity' => ! empty( $activity )
            ), 'api' );
            $heartbeat_logged[ $session_id ] = true;
        }
        
        if ( empty( $session_id ) ) {
            return new WP_Error( 'missing_session_id', 'Session ID is required', array( 'status' => 400 ) );
        }
        
        $result = Subsales_Database::update_pwa_heartbeat( $session_id, $activity );
        
        if ( $result === false ) {
            Subsales_Database::log( 'DEBUG', 'pwa', 'PWA heartbeat failed - session not found', array(
                'session_id' => $session_id
            ), 'api' );
            return new WP_Error( 'heartbeat_failed', 'Failed to update heartbeat', array( 'status' => 500 ) );
        }
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Heartbeat updated'
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
}
