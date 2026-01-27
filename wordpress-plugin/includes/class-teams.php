<?php
/**
 * Teams & User Management
 *
 * Handles authentication, user CRUD operations, team assignments,
 * and all team-related REST API endpoints.
 *
 * @package Subsales_Management
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Teams {
    
    /**
     * Initialize Teams functionality
     * No hooks needed - handlers are called directly from REST API class
     */
    public static function init() {
        // Teams class is stateless - handlers called directly via REST API routes
    }
    
    /**
     * Team/User login handler
     * Supports both legacy (team+code) and user-based (name+phone) authentication
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with auth result
     */
    public static function team_member_login( $request ) {
        global $wpdb;
        $data = $request->get_json_params();
        
        $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
        $sales_enabled = (bool) get_option( 'subsales_sales_enabled', 1 );
        if ( ! $sales_enabled ) {
            Subsales_Database::log_auth( 'failed', null, '', array(
                'mode' => $login_mode,
                'reason' => 'sales_disabled'
            ), 'pwa' );
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Sales are currently closed. Please check back later.'
            ), 403 );
        }
        
        // DEBUG log: Login attempt started
        Subsales_Database::log( 'DEBUG', 'auth', 'Login attempt started', array(
            'mode' => $login_mode,
            'has_team_name' => isset( $data['team_name'] ),
            'has_access_code' => isset( $data['access_code'] ),
            'has_name' => isset( $data['name'] ),
            'has_phone' => isset( $data['phone'] ),
            'has_team_id' => isset( $data['team_id'] )
        ), 'pwa' );
        
        // Legacy mode: Team + Access Code
        if ( $login_mode === 'legacy' ) {
            if ( ! isset( $data['team_name'] ) || ! isset( $data['access_code'] ) ) {
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Missing team name or access code'
                ), 400 );
            }
            
            $team_name = subsales_sanitize_team_name( $data['team_name'] );
            $access_code = subsales_sanitize_team_code( $data['access_code'] );
            
            $team = Subsales_Database::get_team_by_credentials( $team_name, $access_code );
            
            if ( $team ) {
                // Log successful legacy login
                Subsales_Database::log_auth( 'login', null, $team_name, array(
                    'mode' => 'legacy',
                    'team_id' => $team['id']
                ), 'pwa' );
                
                return new WP_REST_Response( array(
                    'success' => true,
                    'mode' => 'legacy',
                    'team' => array(
                        'id' => $team['id'],
                        'name' => $team['name'],
                        'access_code' => $team['access_code']
                    ),
                    'message' => 'Team login successful'
                ), 200 );
            }
            
            // Log failed legacy login
            Subsales_Database::log_auth( 'failed', null, $team_name, array(
                'mode' => 'legacy',
                'reason' => 'invalid_credentials'
            ), 'pwa' );
            
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid team name or access code'
            ), 401 );
        }
        
        // User-based mode: Name + Phone + optional Team ID
        if ( $login_mode === 'user' ) {
            if ( ! isset( $data['name'] ) || ! isset( $data['phone'] ) ) {
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Missing name or phone number'
                ), 400 );
            }
            
            $name = subsales_sanitize_user_name( $data['name'] );
            $phone = preg_replace( '/[^0-9]/', '', sanitize_text_field( $data['phone'] ) );
            $team_id = isset( $data['team_id'] ) ? intval( $data['team_id'] ) : 0;
            
            if ( ! preg_match( '/^[0-9]{10}$/', $phone ) ) {
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Phone number must be 10 digits'
                ), 400 );
            }
            
            // Find user by phone
            $members_table = $wpdb->prefix . 'ss_team_members';
            $user = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$members_table} WHERE phone = %s",
                $phone
            ), ARRAY_A );
            
            if ( ! $user ) {
                // Log failed user login - phone not found
                Subsales_Database::log_auth( 'failed', null, '', array(
                    'mode' => 'user',
                    'phone' => substr( $phone, 0, 3 ) . 'XXXXXXX', // Partial phone for privacy
                    'reason' => 'invalid_phone'
                ), 'pwa' );
                
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Invalid phone number'
                ), 401 );
            }
            
            // Check if user is active
            if ( ( $user['status'] ?? 'active' ) !== 'active' ) {
                // Log failed user login - user inactive
                Subsales_Database::log_auth( 'failed', $user['id'], $user['name'], array(
                    'mode' => 'user',
                    'reason' => 'user_inactive'
                ), 'pwa' );
                
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Your account has been deactivated. Please contact your administrator.'
                ), 403 );
            }
            
            // Verify name matches (case-insensitive partial match)
            if ( stripos( $user['name'], $name ) === false && stripos( $name, $user['name'] ) === false ) {
                // Log failed user login - name mismatch
                Subsales_Database::log_auth( 'failed', $user['id'], $user['name'], array(
                    'mode' => 'user',
                    'reason' => 'name_mismatch',
                    'provided_name' => $name
                ), 'pwa' );
                
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Name does not match'
                ), 401 );
            }
            
            // Get user's teams
            $user_teams_table = $wpdb->prefix . 'ss_user_teams';
            $teams_table = $wpdb->prefix . 'ss_teams';
            
            $team_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
                $user['id']
            ));
            
            // In individual mode (team_id = -1 or 0 requested), user doesn't need team assignment
            // Only enforce team requirement in team mode
            if ( empty( $team_ids ) && $team_id != -1 && $team_id != 0 ) {
                // Log failed user login - no teams (only in team mode)
                Subsales_Database::log_auth( 'failed', $user['id'], $user['name'], array(
                    'mode' => 'user',
                    'reason' => 'no_teams_assigned'
                ), 'pwa' );
                
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'User is not assigned to any teams'
                ), 403 );
            }
            
            // For individual mode, allow login even without team assignments
            if ( empty( $team_ids ) ) {
                // Individual mode login - no teams needed
                Subsales_Database::log_auth( 'login', $user['id'], $user['name'], array(
                    'mode' => 'user',
                    'team_id' => -1,
                    'individual_mode' => true
                ), 'pwa' );
                
                return new WP_REST_Response( array(
                    'success' => true,
                    'mode' => 'user',
                    'user' => array(
                        'id' => $user['id'],
                        'name' => $user['name'],
                        'phone' => $user['phone'],
                        'email' => $user['email'] ?? ''
                    ),
                    'teams' => array(), // Empty teams array for individual mode
                    'selected_team' => array(
                        'id' => -1,
                        'name' => 'Individual',
                        'access_code' => ''
                    ),
                    'message' => 'Individual login successful'
                ), 200 );
            }
            
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $teams = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, access_code FROM {$teams_table} WHERE id IN ({$placeholders})",
                    $team_ids
                ),
                ARRAY_A
            );
            
            // If team_id provided, verify user belongs to it
            $selected_team = null;
            if ( $team_id > 0 ) {
                foreach ( $teams as $team ) {
                    if ( $team['id'] == $team_id ) {
                        $selected_team = $team;
                        break;
                    }
                }
                
                if ( ! $selected_team ) {
                    // Log failed user login - wrong team
                    Subsales_Database::log_auth( 'failed', $user['id'], $user['name'], array(
                        'mode' => 'user',
                        'reason' => 'invalid_team',
                        'requested_team_id' => $team_id
                    ), 'pwa' );
                    
                    return new WP_REST_Response( array(
                        'success' => false,
                        'message' => 'User does not belong to the selected team'
                    ), 403 );
                }
            }
            
            // Log successful user login
            Subsales_Database::log_auth( 'login', $user['id'], $user['name'], array(
                'mode' => 'user',
                'team_count' => count( $teams ),
                'selected_team_id' => $selected_team ? $selected_team['id'] : null
            ), 'pwa' );
            
            // Debug log the full response being sent
            Subsales_Database::log( 'DEBUG', 'auth', 'User login response prepared', array(
                'user_id' => $user['id'],
                'user_name' => $user['name'],
                'team_count' => count( $teams ),
                'has_selected_team' => $selected_team !== null
            ), 'pwa', $user['id'], $user['name'] );
            
            return new WP_REST_Response( array(
                'success' => true,
                'mode' => 'user',
                'user' => array(
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'phone' => $user['phone'],
                    'role' => $user['role']
                ),
                'teams' => $teams,
                'selected_team' => $selected_team,
                'message' => 'User login successful'
            ), 200 );
        }
        
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Invalid login mode configuration'
        ), 500 );
    }
    
    /**
     * Verify team access credentials
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with validation result
     */
    public static function verify_team_access( $request ) {
        $data = $request->get_json_params();
        $sales_enabled = (bool) get_option( 'subsales_sales_enabled', 1 );
        if ( ! $sales_enabled ) {
            return new WP_REST_Response( array(
                'valid' => false,
                'message' => 'Sales are currently closed. Please check back later.'
            ), 403 );
        }
        
        if ( ! isset( $data['team_name'] ) || ! isset( $data['access_code'] ) ) {
            return new WP_REST_Response( 'Missing team name or access code', 400 );
        }
        
        $team_name = sanitize_text_field( $data['team_name'] );
        $access_code = sanitize_text_field( $data['access_code'] );
        
        $team = Subsales_Database::get_team_by_credentials( $team_name, $access_code );
        if ( $team ) {
            return new WP_REST_Response( array( 
                'valid' => true,
                'team' => array(
                    'id' => $team['id'],
                    'name' => $team['name']
                )
            ), 200 );
        }

        return new WP_REST_Response( array(
            'valid' => false,
            'message' => 'Invalid team name or access code'
        ), 401 );
    }
    
    /**
     * Get team members for authenticated team
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with team members array
     */
    public static function get_team_members_endpoint( $request ) {
        $team_name = $request->get_header( 'X-Team-Name' );
        $access_code = $request->get_header( 'X-Access-Code' );
        if ( empty( $team_name ) || empty( $access_code ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing team headers' ), 400 );
        }
        $team = Subsales_Database::get_team_by_credentials( $team_name, $access_code );
        if ( ! $team ) {
            return new WP_REST_Response( array( 'error' => 'Invalid credentials' ), 401 );
        }
        $members = Subsales_Database::get_team_members_by_team( $team['id'] );
        if ( ! $members ) $members = array();
        return new WP_REST_Response( $members, 200 );
    }
    
    /**
     * Create a new user
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with created user data
     */
    public static function create_user( $request ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $params = $request->get_json_params();
        $name = subsales_sanitize_user_name( $params['name'] ?? '' );
        $email = sanitize_email( $params['email'] ?? '' );
        $phone = sanitize_text_field( $params['phone'] ?? '' );
        $role = sanitize_text_field( $params['role'] ?? 'member' );
        
        // Validation
        if ( empty( $name ) ) {
            return new WP_REST_Response( array( 'error' => 'Name is required' ), 400 );
        }
        if ( empty( $phone ) ) {
            return new WP_REST_Response( array( 'error' => 'Phone number is required' ), 400 );
        }
        
        // Normalize phone to 10 digits
        $phone = preg_replace( '/[^0-9]/', '', $phone );
        if ( ! preg_match( '/^[0-9]{10}$/', $phone ) ) {
            return new WP_REST_Response( array( 'error' => 'Phone number must be 10 digits' ), 400 );
        }
        
        // Check if phone already exists
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$members_table} WHERE phone = %s",
            $phone
        ));
        if ( $existing ) {
            return new WP_REST_Response( array( 'error' => 'Phone number already exists' ), 409 );
        }
        
        $email = $email ?: '';
        
        // Insert user
        $result = $wpdb->insert(
            $members_table,
            array(
                'team_id' => 0, // No team assignment initially
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'role' => $role,
                'status' => 'active'
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );
        
        if ( ! $result ) {
            return new WP_REST_Response( array( 'error' => 'Failed to create user' ), 500 );
        }
        
        $user_id = $wpdb->insert_id;
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            $user_id
        ), ARRAY_A );
        
        return new WP_REST_Response( $user, 201 );
    }
    
    /**
     * Get all users with optional filtering
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with users array
     */
    public static function get_users( $request ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $limit = intval( $request->get_param( 'limit' ) ?: 100 );
        $offset = intval( $request->get_param( 'offset' ) ?: 0 );
        $status = sanitize_text_field( $request->get_param( 'status' ) ?: '' );
        
        $where = array();
        $params = array();
        
        if ( ! empty( $status ) ) {
            $where[] = "status = %s";
            $params[] = $status;
        }
        
        $where_sql = ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '';
        
        if ( ! empty( $params ) ) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$members_table} {$where_sql} ORDER BY name ASC LIMIT %d OFFSET %d",
                array_merge( $params, array( $limit, $offset ) )
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$members_table} {$where_sql} ORDER BY name ASC LIMIT %d OFFSET %d",
                $limit,
                $offset
            );
        }
        
        $users = $wpdb->get_results( $sql, ARRAY_A );
        
        // For each user, get their teams
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        
        foreach ( $users as &$user ) {
            $team_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
                $user['id']
            ));
            
            $user['teams'] = array();
            if ( ! empty( $team_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
                $teams = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, name, access_code FROM {$teams_table} WHERE id IN ({$placeholders})",
                        $team_ids
                    ),
                    ARRAY_A
                );
                $user['teams'] = $teams ?: array();
            }
        }
        
        return new WP_REST_Response( $users, 200 );
    }
    
    /**
     * Get single user by ID
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with user data
     */
    public static function get_user_by_id( $request ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        
        $user_id = intval( $request->get_param( 'id' ) );
        
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            $user_id
        ), ARRAY_A );
        
        if ( ! $user ) {
            return new WP_REST_Response( array( 'error' => 'User not found' ), 404 );
        }
        
        // Get user's teams
        $team_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
            $user_id
        ));
        
        $user['teams'] = array();
        if ( ! empty( $team_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $teams = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, access_code FROM {$teams_table} WHERE id IN ({$placeholders})",
                    $team_ids
                ),
                ARRAY_A
            );
            $user['teams'] = $teams ?: array();
        }
        
        return new WP_REST_Response( $user, 200 );
    }
    
    /**
     * Update user information
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with updated user data
     */
    public static function update_user( $request ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $user_id = intval( $request->get_param( 'id' ) );
        $params = $request->get_json_params();
        
        // Check if user exists
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            $user_id
        ), ARRAY_A );
        
        if ( ! $existing ) {
            return new WP_REST_Response( array( 'error' => 'User not found' ), 404 );
        }
        
        $updates = array();
        $formats = array();
        
        if ( isset( $params['name'] ) ) {
            $name = subsales_sanitize_user_name( $params['name'] );
            if ( empty( $name ) ) {
                return new WP_REST_Response( array( 'error' => 'Name cannot be empty' ), 400 );
            }
            $updates['name'] = $name;
            $formats[] = '%s';
        }
        
        if ( isset( $params['email'] ) ) {
            $updates['email'] = sanitize_email( $params['email'] ) ?: '';
            $formats[] = '%s';
        }
        
        if ( isset( $params['phone'] ) ) {
            $phone = preg_replace( '/[^0-9]/', '', $params['phone'] );
            if ( ! preg_match( '/^[0-9]{10}$/', $phone ) ) {
                return new WP_REST_Response( array( 'error' => 'Phone number must be 10 digits' ), 400 );
            }
            
            // Check if phone exists for another user
            $conflict = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$members_table} WHERE phone = %s AND id != %d",
                $phone,
                $user_id
            ));
            if ( $conflict ) {
                return new WP_REST_Response( array( 'error' => 'Phone number already exists' ), 409 );
            }
            
            $updates['phone'] = $phone;
            $formats[] = '%s';
        }
        
        if ( isset( $params['role'] ) ) {
            $updates['role'] = sanitize_text_field( $params['role'] );
            $formats[] = '%s';
        }
        
        if ( isset( $params['status'] ) ) {
            $updates['status'] = sanitize_text_field( $params['status'] );
            $formats[] = '%s';
        }
        
        if ( empty( $updates ) ) {
            return new WP_REST_Response( array( 'error' => 'No fields to update' ), 400 );
        }
        
        $result = $wpdb->update(
            $members_table,
            $updates,
            array( 'id' => $user_id ),
            $formats,
            array( '%d' )
        );
        
        if ( $result === false ) {
            return new WP_REST_Response( array( 'error' => 'Failed to update user' ), 500 );
        }
        
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            $user_id
        ), ARRAY_A );
        
        return new WP_REST_Response( $user, 200 );
    }
    
    /**
     * Delete user
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with deletion result
     */
    public static function delete_user( $request ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        
        $user_id = intval( $request->get_param( 'id' ) );
        
        // Check if user exists
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            $user_id
        ), ARRAY_A );
        
        if ( ! $existing ) {
            return new WP_REST_Response( array( 'error' => 'User not found' ), 404 );
        }
        
        // Delete user-team associations first
        $wpdb->delete( $user_teams_table, array( 'user_id' => $user_id ), array( '%d' ) );
        
        // Delete user
        $result = $wpdb->delete( $members_table, array( 'id' => $user_id ), array( '%d' ) );
        
        if ( ! $result ) {
            return new WP_REST_Response( array( 'error' => 'Failed to delete user' ), 500 );
        }
        
        return new WP_REST_Response( array( 'success' => true, 'message' => 'User deleted' ), 200 );
    }
    
    /**
     * Search users by name or phone
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with matching users
     */
    public static function search_users( $request ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        
        $query = sanitize_text_field( $request->get_param( 'q' ) ?: '' );
        $limit = intval( $request->get_param( 'limit' ) ?: 20 );
        
        if ( empty( $query ) ) {
            return new WP_REST_Response( array(), 200 );
        }
        
        // Search by name or phone (partial match)
        $search_term = '%' . $wpdb->esc_like( $query ) . '%';
        $phone_search = preg_replace( '/[^0-9]/', '', $query );
        
        if ( ! empty( $phone_search ) ) {
            // Search by both name and phone
            $users = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$members_table} 
                 WHERE name LIKE %s OR phone LIKE %s 
                 ORDER BY name ASC LIMIT %d",
                $search_term,
                '%' . $wpdb->esc_like( $phone_search ) . '%',
                $limit
            ), ARRAY_A );
        } else {
            // Search by name only
            $users = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$members_table} 
                 WHERE name LIKE %s 
                 ORDER BY name ASC LIMIT %d",
                $search_term,
                $limit
            ), ARRAY_A );
        }
        
        // Get teams for each user
        foreach ( $users as &$user ) {
            $team_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
                $user['id']
            ));
            
            $user['teams'] = array();
            if ( ! empty( $team_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
                $teams = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT id, name, access_code FROM {$teams_table} WHERE id IN ({$placeholders})",
                        $team_ids
                    ),
                    ARRAY_A
                );
                $user['teams'] = $teams ?: array();
            }
        }
        
        return new WP_REST_Response( $users, 200 );
    }
    
    /**
     * Get all teams for a specific user
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with user's teams
     */
    public static function get_user_teams( $request ) {
        global $wpdb;
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $user_id = intval( $request->get_param( 'id' ) );
        
        // Verify user exists
        $user_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$members_table} WHERE id = %d",
            $user_id
        ));
        
        if ( ! $user_exists ) {
            return new WP_REST_Response( array( 'error' => 'User not found' ), 404 );
        }
        
        // Get team IDs for this user
        $team_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
            $user_id
        ));
        
        $teams = array();
        if ( ! empty( $team_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            $teams = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, access_code, description FROM {$teams_table} WHERE id IN ({$placeholders})",
                    $team_ids
                ),
                ARRAY_A
            );
        }
        
        return new WP_REST_Response( $teams ?: array(), 200 );
    }
    
    /**
     * Assign a user to a team
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with assignment result
     */
    public static function assign_user_to_team( $request ) {
        global $wpdb;
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $team_id = intval( $request->get_param( 'id' ) );
        $params = $request->get_json_params();
        $user_id = intval( $params['user_id'] ?? 0 );
        
        if ( ! $user_id ) {
            return new WP_REST_Response( array( 'error' => 'user_id is required' ), 400 );
        }
        
        // Verify team exists
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$teams_table} WHERE id = %d",
            $team_id
        ), ARRAY_A );
        
        if ( ! $team ) {
            return new WP_REST_Response( array( 'error' => 'Team not found' ), 404 );
        }
        
        // Verify user exists
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            $user_id
        ), ARRAY_A );
        
        if ( ! $user ) {
            return new WP_REST_Response( array( 'error' => 'User not found' ), 404 );
        }
        
        // Check if already assigned
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id,
            $team_id
        ));
        
        if ( $exists ) {
            return new WP_REST_Response( array( 
                'success' => true,
                'message' => 'User already assigned to this team'
            ), 200 );
        }
        
        // Create assignment
        $result = $wpdb->insert(
            $user_teams_table,
            array(
                'user_id' => $user_id,
                'team_id' => $team_id
            ),
            array( '%d', '%d' )
        );
        
        if ( ! $result ) {
            return new WP_REST_Response( array( 'error' => 'Failed to assign user to team' ), 500 );
        }
        
        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'User assigned to team successfully',
            'assignment' => array(
                'user_id' => $user_id,
                'team_id' => $team_id,
                'user_name' => $user['name'],
                'team_name' => $team['name']
            )
        ), 201 );
    }
    
    /**
     * Remove a user from a team
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with removal result
     */
    public static function remove_user_from_team( $request ) {
        global $wpdb;
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $team_id = intval( $request->get_param( 'id' ) );
        $user_id = intval( $request->get_param( 'userId' ) );
        
        // Verify team exists
        $team_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$teams_table} WHERE id = %d",
            $team_id
        ));
        
        if ( ! $team_exists ) {
            return new WP_REST_Response( array( 'error' => 'Team not found' ), 404 );
        }
        
        // Verify user exists
        $user_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$members_table} WHERE id = %d",
            $user_id
        ));
        
        if ( ! $user_exists ) {
            return new WP_REST_Response( array( 'error' => 'User not found' ), 404 );
        }
        
        // Check if assignment exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id,
            $team_id
        ));
        
        if ( ! $exists ) {
            return new WP_REST_Response( array( 
                'error' => 'User is not assigned to this team'
            ), 404 );
        }
        
        // Remove assignment
        $result = $wpdb->delete(
            $user_teams_table,
            array(
                'user_id' => $user_id,
                'team_id' => $team_id
            ),
            array( '%d', '%d' )
        );
        
        if ( ! $result ) {
            return new WP_REST_Response( array( 'error' => 'Failed to remove user from team' ), 500 );
        }
        
        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'User removed from team successfully'
        ), 200 );
    }
    
    /**
     * Get all users assigned to a specific team
     *
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response Response with team users
     */
    public static function get_team_users( $request ) {
        global $wpdb;
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $team_id = intval( $request->get_param( 'id' ) );
        
        // Verify team exists
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$teams_table} WHERE id = %d",
            $team_id
        ), ARRAY_A );
        
        if ( ! $team ) {
            return new WP_REST_Response( array( 'error' => 'Team not found' ), 404 );
        }
        
        // Get user IDs for this team
        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT user_id FROM {$user_teams_table} WHERE team_id = %d",
            $team_id
        ));
        
        $users = array();
        if ( ! empty( $user_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
            $users = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, name, email, phone, role, status FROM {$members_table} WHERE id IN ({$placeholders})",
                    $user_ids
                ),
                ARRAY_A
            );
        }
        
        return new WP_REST_Response( array(
            'team' => array(
                'id' => $team['id'],
                'name' => $team['name'],
                'access_code' => $team['access_code']
            ),
            'users' => $users ?: array()
        ), 200 );
    }
}
