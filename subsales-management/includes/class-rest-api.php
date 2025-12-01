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
            'callback' => 'get_orders',
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders', array(
            'methods' => 'POST',
            'callback' => 'create_order',
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'GET',
            'callback' => 'get_order_by_id',
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'PUT',
            'callback' => 'update_order',
            'permission_callback' => 'order_sync_check_permissions',
        ));

        register_rest_route( 'order-manager/v1', '/orders/(?P<id>[a-zA-Z0-9-]+)', array(
            'methods' => 'DELETE',
            'callback' => 'delete_order',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        // Order History API
        register_rest_route( 'order-manager/v1', '/orders/(?P<id>\d+)/history', array(
            'methods' => 'GET',
            'callback' => 'get_order_history',
            'permission_callback' => 'order_sync_check_admin_permissions',
        ));
        
        // Order Restore API
        register_rest_route( 'order-manager/v1', '/orders/(?P<id>\d+)/restore', array(
            'methods' => 'POST',
            'callback' => 'restore_order',
            'permission_callback' => 'order_sync_check_admin_permissions',
        ));
        
        // Order Tally API
        register_rest_route( 'order-manager/v1', '/orders/tally', array(
            'methods' => 'POST',
            'callback' => 'tally_orders',
            'permission_callback' => 'order_sync_check_admin_permissions',
        ));
        
        // Authentication API
        register_rest_route( 'order-manager/v1', '/auth/login', array(
            'methods' => 'POST',
            'callback' => 'team_member_login',
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route( 'order-manager/v1', '/auth/verify', array(
            'methods' => 'POST',
            'callback' => 'verify_team_access',
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
            'callback' => 'order_sync_get_team_members_endpoint',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        // User Management API
        register_rest_route( 'order-manager/v1', '/users', array(
            'methods' => 'POST',
            'callback' => 'order_sync_create_user',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users', array(
            'methods' => 'GET',
            'callback' => 'order_sync_get_users',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => 'order_sync_get_user_by_id',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => 'order_sync_update_user',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => 'order_sync_delete_user',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/users/search', array(
            'methods' => 'GET',
            'callback' => 'order_sync_search_users',
            'permission_callback' => '__return_true', // Public for PWA login
        ));
        
        // Team Assignment API
        register_rest_route( 'order-manager/v1', '/users/(?P<id>\d+)/teams', array(
            'methods' => 'GET',
            'callback' => 'order_sync_get_user_teams',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/assign', array(
            'methods' => 'POST',
            'callback' => 'order_sync_assign_user_to_team',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/users/(?P<userId>\d+)', array(
            'methods' => 'DELETE',
            'callback' => 'order_sync_remove_user_from_team',
            'permission_callback' => 'order_sync_check_permissions',
        ));
        
        register_rest_route( 'order-manager/v1', '/teams/(?P<id>\d+)/users', array(
            'methods' => 'GET',
            'callback' => 'order_sync_get_team_users',
            'permission_callback' => 'order_sync_check_permissions',
        ));
    }
}
