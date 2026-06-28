<?php
/**
 * Signup/Campaign functionality for Subsales Management Plugin
 * 
 * Handles:
 * - Signup registration page at /signup/ endpoint
 * - Campaign management REST endpoints
 * - Team roster and driver assignment
 * - User signup tracking and management
 * 
 * @package Subsales_Management
 * @since 2.2.1.133
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Signups {
    
    /**
     * Initialize the signup functionality
     */
    public static function init() {
        // Register hooks for signup page serving
        add_action( 'wp', array( __CLASS__, 'catch_signup_404' ), 1 );
        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 1 );
        
        // Register REST API endpoints
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }
    
    /**
     * Add rewrite rules for /signup endpoint
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule( '^signup(/(.*))?$', 'index.php?subsales_signup=1', 'top' );
        add_rewrite_tag( '%subsales_signup%', '([^&]+)' );
    }
    
    /**
     * Catch signup 404s at wp hook (earlier than template_redirect)
     */
    public static function catch_signup_404() {
        $req_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
        
        if ( $req_path === 'signup' || strpos( $req_path, 'signup/' ) === 0 ) {
            // Mark as not 404
            global $wp_query;
            $wp_query->is_404 = false;
            status_header( 200 );
            self::serve_signup_page();
            exit;
        }
    }
    
    /**
     * Serve the signup registration page at /signup/ endpoint
     */
    public static function serve_signup_page() {
        // Call the existing function in the main plugin file
        // The HTML is still in subsales-management.php for now
        if ( function_exists( 'subsales_serve_signup_page' ) ) {
            subsales_serve_signup_page();
        }
    }
    
    /**
     * Register REST API endpoints
     */
    public static function register_rest_routes() {
        // Signup settings
        register_rest_route( 'order-manager/v1', '/signup/settings', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'rest_signup_settings' ),
            'permission_callback' => '__return_true',
        ));
        
        // Submit signup
        register_rest_route( 'order-manager/v1', '/signup', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_submit_signup' ),
            'permission_callback' => '__return_true',
        ));
        
        // Get user's signups
        register_rest_route( 'order-manager/v1', '/my-signups', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_get_my_signups' ),
            'permission_callback' => '__return_true',
        ));
        
        // Update signup (team switch)
        register_rest_route( 'order-manager/v1', '/my-signups/(?P<id>\d+)', array(
            'methods' => 'PUT',
            'callback' => array( __CLASS__, 'rest_update_signup' ),
            'permission_callback' => '__return_true',
        ));
        
        // Delete signup
        register_rest_route( 'order-manager/v1', '/my-signups/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array( __CLASS__, 'rest_delete_signup' ),
            'permission_callback' => '__return_true',
        ));
        
        // Get team roster
        register_rest_route( 'order-manager/v1', '/team-roster', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'rest_get_team_roster' ),
            'permission_callback' => '__return_true',
        ));
        
        // Update team driver
        register_rest_route( 'order-manager/v1', '/team-driver', array(
            'methods' => 'PUT',
            'callback' => array( __CLASS__, 'rest_update_team_driver' ),
            'permission_callback' => '__return_true',
        ));
        
        // Get campaigns
        register_rest_route( 'order-manager/v1', '/campaigns', array(
            'methods' => 'GET',
            'callback' => array( __CLASS__, 'rest_get_campaigns' ),
            'permission_callback' => '__return_true',
        ));
        
        // Create campaign
        register_rest_route( 'order-manager/v1', '/campaigns', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_create_campaign' ),
            'permission_callback' => 'is_user_logged_in',
        ));
        
        // Delete campaign
        register_rest_route( 'order-manager/v1', '/campaigns/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array( __CLASS__, 'rest_delete_campaign' ),
            'permission_callback' => 'is_user_logged_in',
        ));
    }
    
    /**
     * GET /signup/settings - Get signup configuration
     */
    public static function rest_signup_settings( $request ) {
        $mode = get_option( 'subsales_signup_mode', 'legacy' );
        $admin_email = get_option( 'subsales_admin_email', get_option( 'admin_email' ) );
        
        return rest_ensure_response( array(
            'mode' => $mode,
            'admin_email' => $admin_email
        ) );
    }
    
    /**
     * POST /signup - Submit new signup
     */
    public static function rest_submit_signup( $request ) {
        $body = $request->get_json_params();

        // Delegate to the canonical signup writer (shared with driver signup).
        $result = Subsales_Database::register_member_signups( array(
            'name'         => isset( $body['name'] ) ? $body['name'] : '',
            'phone'        => isset( $body['phone'] ) ? $body['phone'] : '',
            'team_name'    => isset( $body['team_name'] ) ? $body['team_name'] : '',
            'campaign_ids' => isset( $body['campaign_ids'] ) ? $body['campaign_ids'] : array(),
            'is_driver'    => false,
        ) );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        subsales_log( 'INFO', 'signup', 'Signup completed', array(
            'user_id'     => $result['user_id'],
            'team_id'     => $result['team_id'],
            'new_signups' => $result['signups_created'],
            'skipped'     => $result['skipped'],
        ) );

        return rest_ensure_response( array(
            'success'         => true,
            'user_id'         => $result['user_id'],
            'team_id'         => $result['team_id'],
            'signups_created' => $result['signups_created'],
            'skipped'         => $result['skipped'],
        ) );
    }
    
    /**
     * POST /my-signups - Get user's signups by phone
     */
    public static function rest_get_my_signups( $request ) {
        // Accept phone from either POST body or GET query for backward compatibility
        $phone = $request->get_param( 'phone' );
        if ( empty( $phone ) ) {
            $body = $request->get_json_params();
            $phone = isset( $body['phone'] ) ? $body['phone'] : '';
        }

        $phone = preg_replace( '/\D/', '', $phone );

        if ( empty( $phone ) ) {
            return new WP_Error( 'missing_phone', 'Phone number is required', array( 'status' => 400 ) );
        }

        // Canonical reader (uses real campaign_name/campaign_date columns)
        $lookup = Subsales_Database::get_member_signups_by_phone( $phone );

        return rest_ensure_response( $lookup['signups'] );
    }
    
    /**
     * PUT /my-signups/{id} - Update signup (team switch)
     */
    public static function rest_update_signup( $request ) {
        global $wpdb;
        
        $signup_id = intval( $request->get_param( 'id' ) );
        $body = $request->get_json_params();
        $new_team_name = isset( $body['team_name'] ) ? sanitize_text_field( $body['team_name'] ) : '';
        
        if ( empty( $new_team_name ) ) {
            return new WP_Error( 'missing_team', 'Team name is required', array( 'status' => 400 ) );
        }
        
        $teams_table = $wpdb->prefix . 'ss_teams';
        $signups_table = $wpdb->prefix . 'ss_signups';
        
        // Get or create new team (case-insensitive lookup)
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$teams_table} WHERE LOWER(name) = LOWER(%s)",
            $new_team_name
        ) );
        
        if ( ! $team ) {
            $access_code = strtoupper( substr( md5( $new_team_name . time() ), 0, 6 ) );
            $wpdb->insert( $teams_table, array(
                'name' => $new_team_name,
                'access_code' => $access_code,
                'status' => 'active'
            ), array( '%s', '%s', '%s' ) );
            $team_id = $wpdb->insert_id;
        } else {
            $team_id = $team->id;
            // Use the existing team's name (preserves original casing)
            $new_team_name = $team->name;
        }
        
        // Update signup
        $wpdb->update(
            $signups_table,
            array( 'team_id' => $team_id ),
            array( 'id' => $signup_id ),
            array( '%d' ),
            array( '%d' )
        );
        
        return rest_ensure_response( array( 'success' => true ) );
    }
    
    /**
     * DELETE /my-signups/{id} - Delete signup
     */
    public static function rest_delete_signup( $request ) {
        global $wpdb;
        
        $signup_id = intval( $request->get_param( 'id' ) );
        $signups_table = $wpdb->prefix . 'ss_signups';
        
        $wpdb->update(
            $signups_table,
            array( 'status' => 'cancelled' ),
            array( 'id' => $signup_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        return rest_ensure_response( array( 'success' => true ) );
    }
    
    /**
     * GET /team-roster - Get team roster for a campaign
     */
    public static function rest_get_team_roster( $request ) {
        global $wpdb;

        $team_id = intval( $request->get_param( 'team_id' ) );
        $campaign_id = intval( $request->get_param( 'campaign_id' ) );

        if ( empty( $team_id ) || empty( $campaign_id ) ) {
            return new WP_Error( 'missing_params', 'Team ID and Campaign ID are required', array( 'status' => 400 ) );
        }

        // Canonical roster: members excludes the driver (single source of truth)
        $roster  = Subsales_Database::get_campaign_team_roster( $team_id, $campaign_id );
        $members = array_map( function( $m ) {
            return array( 'user_id' => $m['id'], 'name' => $m['name'], 'phone' => $m['phone'] );
        }, $roster['members'] );

        // Driver metadata (who/when) still lives on ss_team_campaigns
        $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';
        $driver_info = $wpdb->get_row( $wpdb->prepare(
            "SELECT driver_name, driver_updated_by, driver_updated_at
             FROM {$team_campaigns_table}
             WHERE team_id = %d AND campaign_id = %d",
            $team_id,
            $campaign_id
        ), ARRAY_A );

        // Prefer the live driver from signups; fall back to the recorded name
        $driver_name = $roster['driver'] ? $roster['driver']['name'] : ( $driver_info ? $driver_info['driver_name'] : '' );

        return rest_ensure_response( array(
            'members'           => $members,
            'driver_name'       => $driver_name,
            'driver_updated_by' => $driver_info ? $driver_info['driver_updated_by'] : '',
            'driver_updated_at' => $driver_info ? $driver_info['driver_updated_at'] : '',
        ) );
    }
    
    /**
     * PUT /team-driver - Update team driver
     */
    public static function rest_update_team_driver( $request ) {
        global $wpdb;
        
        $body = $request->get_json_params();
        $team_id = isset( $body['team_id'] ) ? intval( $body['team_id'] ) : 0;
        $campaign_id = isset( $body['campaign_id'] ) ? intval( $body['campaign_id'] ) : 0;
        $driver_name = isset( $body['driver_name'] ) ? sanitize_text_field( $body['driver_name'] ) : '';
        $updated_by = isset( $body['updated_by'] ) ? sanitize_text_field( $body['updated_by'] ) : '';
        
        if ( empty( $team_id ) || empty( $campaign_id ) ) {
            return new WP_Error( 'missing_params', 'Team ID and Campaign ID are required', array( 'status' => 400 ) );
        }
        
        $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';
        
        // Check if record exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$team_campaigns_table} WHERE team_id = %d AND campaign_id = %d",
            $team_id,
            $campaign_id
        ) );
        
        if ( $exists ) {
            // Update existing record
            $wpdb->update(
                $team_campaigns_table,
                array(
                    'driver_name' => $driver_name,
                    'driver_updated_by' => $updated_by,
                    'driver_updated_at' => current_time( 'mysql' )
                ),
                array( 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
                array( '%s', '%s', '%s' ),
                array( '%d', '%d' )
            );
        } else {
            // Insert new record
            $wpdb->insert(
                $team_campaigns_table,
                array(
                    'team_id' => $team_id,
                    'campaign_id' => $campaign_id,
                    'driver_name' => $driver_name,
                    'driver_updated_by' => $updated_by,
                    'driver_updated_at' => current_time( 'mysql' )
                ),
                array( '%d', '%d', '%s', '%s', '%s' )
            );
        }
        
        subsales_log( 'INFO', 'signup', 'Driver updated', array(
            'team_id' => $team_id,
            'campaign_id' => $campaign_id,
            'driver_name' => $driver_name,
            'updated_by' => $updated_by
        ) );
        
        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Driver updated successfully'
        ) );
    }
    
    /**
     * GET /campaigns - Get all campaigns
     */
    public static function rest_get_campaigns( $request ) {
        global $wpdb;
        
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        
        $campaigns = $wpdb->get_results(
            "SELECT id, name, date, status FROM {$campaigns_table} ORDER BY date ASC",
            ARRAY_A
        );
        
        return rest_ensure_response( $campaigns );
    }
    
    /**
     * POST /campaigns - Create new campaign
     */
    public static function rest_create_campaign( $request ) {
        global $wpdb;
        
        $body = $request->get_json_params();
        $name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
        $date = isset( $body['date'] ) ? sanitize_text_field( $body['date'] ) : '';
        
        if ( empty( $date ) ) {
            return new WP_Error( 'missing_date', 'Date is required', array( 'status' => 400 ) );
        }
        
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        
        $wpdb->insert( $campaigns_table, array(
            'name' => $name,
            'date' => $date,
            'status' => 'active',
            'created_at' => current_time( 'mysql' )
        ), array( '%s', '%s', '%s', '%s' ) );
        
        $campaign_id = $wpdb->insert_id;
        
        return rest_ensure_response( array(
            'success' => true,
            'id' => $campaign_id
        ) );
    }
    
    /**
     * DELETE /campaigns/{id} - Delete campaign
     */
    public static function rest_delete_campaign( $request ) {
        global $wpdb;
        
        $campaign_id = intval( $request->get_param( 'id' ) );
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        
        $wpdb->update(
            $campaigns_table,
            array( 'status' => 'deleted' ),
            array( 'id' => $campaign_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        return rest_ensure_response( array( 'success' => true ) );
    }
}
