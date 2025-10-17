<?php
/**
 * Plugin Name: BKMB Subsales Management
 * Plugin URI: https://github.com/jimmarks/Southington-BKMB-Subsales
 * Description: A comprehensive order management system for mobile app synchronization with WordPress backend. Includes team management, order tracking, and real-time sync capabilities.
 * Version: 1.0.0
 * Author: Jim Marks
 * Author URI: https://github.com/jimmarks
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: bkmb-subsales-management
 * Domain Path: /languages
 * Network: false
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'BKMB_SUBSALES_VERSION', '1.0.0' );
define( 'BKMB_SUBSALES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BKMB_SUBSALES_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BKMB_SUBSALES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Check for required WordPress version
if ( ! function_exists( 'bkmb_subsales_check_wp_version' ) ) {
    function bkmb_subsales_check_wp_version() {
        global $wp_version;
        if ( version_compare( $wp_version, '5.0', '<' ) ) {
            deactivate_plugins( BKMB_SUBSALES_PLUGIN_BASENAME );
            wp_die( 'BKMB Subsales Management requires WordPress 5.0 or higher.' );
        }
    }
}
register_activation_hook( __FILE__, 'bkmb_subsales_check_wp_version' );

// Hook to add admin menu
add_action( 'admin_menu', 'order_sync_admin_menu' );

// Add admin menu item
function order_sync_admin_menu() {
    add_options_page(
        'BKMB Subsales Management',
        'BKMB Subsales',
        'manage_options', // Requires administrator capability
        'bkmb-subsales-settings',
        'order_sync_settings_page'
    );
}

// Admin settings page
function order_sync_settings_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle form submission
    if ( isset( $_POST['submit'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        
        $api_key = sanitize_text_field( $_POST['api_key'] );
        $sync_interval = intval( $_POST['sync_interval'] );
        $access_code = sanitize_text_field( $_POST['access_code'] );
        $team_name = sanitize_text_field( $_POST['team_name'] );
        
        update_option( 'order_sync_api_key', $api_key );
        update_option( 'order_sync_interval', $sync_interval );
        update_option( 'order_sync_access_code', $access_code );
        update_option( 'order_sync_team_name', $team_name );
        
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    // Handle team member actions
    if ( isset( $_POST['add_team_member'] ) ) {
        check_admin_referer( 'order_sync_team_nonce' );
        
        $name = sanitize_text_field( $_POST['team_name'] );
        $email = sanitize_email( $_POST['team_email'] );
        $role = sanitize_text_field( $_POST['team_role'] );
        
        if ( ! empty( $name ) && ! empty( $email ) ) {
            order_sync_add_team_member( $name, $email, $role );
            echo '<div class="notice notice-success"><p>Team member added!</p></div>';
        }
    }

    if ( isset( $_POST['remove_team_member'] ) ) {
        check_admin_referer( 'order_sync_team_nonce' );
        
        $member_id = intval( $_POST['member_id'] );
        order_sync_remove_team_member( $member_id );
        echo '<div class="notice notice-success"><p>Team member removed!</p></div>';
    }

    $api_key = get_option( 'order_sync_api_key', '' );
    $sync_interval = get_option( 'order_sync_interval', 300 );
    $access_code = get_option( 'order_sync_access_code', '' );
    $team_name = get_option( 'order_sync_team_name', '' );
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p class="description">Version <?php echo esc_html( BKMB_SUBSALES_VERSION ); ?></p>
        
        <!-- Main Settings Form -->
        <form method="post" action="">
            <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="text" name="api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                        <p class="description">Enter your mobile app API key for authentication.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Team Name</th>
                    <td>
                        <input type="text" name="team_name" value="<?php echo esc_attr( $team_name ); ?>" class="regular-text" />
                        <p class="description">Enter your team name for mobile app authentication.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Access Code</th>
                    <td>
                        <input type="text" name="access_code" value="<?php echo esc_attr( $access_code ); ?>" class="regular-text" />
                        <p class="description">Set an access code for team members to join the system.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Sync Interval (seconds)</th>
                    <td>
                        <input type="number" name="sync_interval" value="<?php echo esc_attr( $sync_interval ); ?>" min="60" />
                        <p class="description">How often to sync orders (minimum 60 seconds).</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <!-- Team Management Section -->
        <h2>Team Management</h2>
        
        <!-- Add Team Member Form -->
        <h3>Add Team Member</h3>
        <form method="post" action="">
            <?php wp_nonce_field( 'order_sync_team_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Name</th>
                    <td><input type="text" name="team_name" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th scope="row">Email</th>
                    <td><input type="email" name="team_email" class="regular-text" required /></td>
                </tr>
                <tr>
                    <th scope="row">Role</th>
                    <td>
                        <select name="team_role">
                            <option value="member">Member</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Add Team Member', 'secondary', 'add_team_member' ); ?>
        </form>
        
        <!-- Team Members List -->
        <h3>Current Team Members</h3>
        <?php
        $team_members = order_sync_get_team_members();
        if ( ! empty( $team_members ) ) :
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Added</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $team_members as $member ) : ?>
                <tr>
                    <td><?php echo esc_html( $member['name'] ); ?></td>
                    <td><?php echo esc_html( $member['email'] ); ?></td>
                    <td><?php echo esc_html( ucfirst( $member['role'] ) ); ?></td>
                    <td><?php echo esc_html( date( 'M j, Y', strtotime( $member['created_at'] ) ) ); ?></td>
                    <td>
                        <span class="status-<?php echo esc_attr( $member['status'] ); ?>">
                            <?php echo esc_html( ucfirst( $member['status'] ) ); ?>
                        </span>
                    </td>
                    <td>
                        <form method="post" action="" style="display: inline;">
                            <?php wp_nonce_field( 'order_sync_team_nonce' ); ?>
                            <input type="hidden" name="member_id" value="<?php echo esc_attr( $member['id'] ); ?>" />
                            <input type="submit" name="remove_team_member" value="Remove" class="button button-small" 
                                   onclick="return confirm('Are you sure you want to remove this team member?')" />
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p>No team members added yet.</p>
        <?php endif; ?>
        
        <h2>Order Statistics</h2>
        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'order_sync_orders';
        $order_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
        $team_count = count( $team_members );
        ?>
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px;">
            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                <h3 style="margin: 0 0 10px 0; color: #23282d;">Team Name</h3>
                <p style="font-size: 18px; font-weight: bold; margin: 0; color: #0073aa; font-family: monospace;">
                    <?php echo ! empty( $team_name ) ? esc_html( $team_name ) : 'Not Set'; ?>
                </p>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                <h3 style="margin: 0 0 10px 0; color: #23282d;">Access Code</h3>
                <p style="font-size: 18px; font-weight: bold; margin: 0; color: #0073aa; font-family: monospace;">
                    <?php echo ! empty( $access_code ) ? esc_html( $access_code ) : 'Not Set'; ?>
                </p>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                <h3 style="margin: 0 0 10px 0; color: #23282d;">Total Orders</h3>
                <p style="font-size: 24px; font-weight: bold; margin: 0; color: #0073aa;"><?php echo intval( $order_count ); ?></p>
            </div>
            <div class="stat-card" style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                <h3 style="margin: 0 0 10px 0; color: #23282d;">Team Members</h3>
                <p style="font-size: 24px; font-weight: bold; margin: 0; color: #0073aa;"><?php echo intval( $team_count ); ?></p>
            </div>
        </div>
        
        <style>
        .status-active { color: #46b450; font-weight: bold; }
        .status-pending { color: #ffb900; font-weight: bold; }
        .status-inactive { color: #dc3232; font-weight: bold; }
        </style>
    </div>
    <?php
}

// Create database table on activation
register_activation_hook( __FILE__, 'order_sync_create_table' );

function order_sync_create_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'order_sync_orders';
    $team_table_name = $wpdb->prefix . 'order_sync_team_members';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Orders table
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id varchar(255) NOT NULL,
        user_id varchar(255) NOT NULL,
        order_data text NOT NULL,
        sync_status varchar(50) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id)
    ) $charset_collate;";
    
    // Team members table
    $team_sql = "CREATE TABLE $team_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        role varchar(50) NOT NULL DEFAULT 'member',
        status varchar(50) NOT NULL DEFAULT 'active',
        access_code varchar(255),
        team_name varchar(255),
        last_login datetime,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY email (email)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    dbDelta( $team_sql );
    
    // Set default options
    add_option( 'bkmb_subsales_version', BKMB_SUBSALES_VERSION );
}

// Update check on plugin activation
register_activation_hook( __FILE__, 'bkmb_subsales_update_check' );

function bkmb_subsales_update_check() {
    $current_version = get_option( 'bkmb_subsales_version', '0.0.0' );
    
    if ( version_compare( $current_version, BKMB_SUBSALES_VERSION, '<' ) ) {
        // Run upgrade routines if needed
        order_sync_create_table(); // Ensure tables are up to date
        update_option( 'bkmb_subsales_version', BKMB_SUBSALES_VERSION );
    }
}

// Team management functions
function order_sync_add_team_member( $name, $email, $role = 'member' ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_team_members';
    
    $access_code = get_option( 'order_sync_access_code', '' );
    $team_name = get_option( 'order_sync_team_name', '' );
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => 'active',
            'access_code' => $access_code,
            'team_name' => $team_name
        ),
        array( '%s', '%s', '%s', '%s', '%s', '%s' )
    );
    
    return $result !== false;
}

function order_sync_remove_team_member( $member_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_team_members';
    
    return $wpdb->delete(
        $table_name,
        array( 'id' => $member_id ),
        array( '%d' )
    );
}

function order_sync_get_team_members() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_team_members';
    
    return $wpdb->get_results( 
        "SELECT * FROM {$table_name} ORDER BY created_at DESC", 
        ARRAY_A 
    );
}

function order_sync_verify_team_member( $email, $access_code ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_team_members';
    
    $member = $wpdb->get_row( 
        $wpdb->prepare( 
            "SELECT * FROM {$table_name} WHERE email = %s AND access_code = %s AND status = 'active'", 
            $email, 
            $access_code 
        ),
        ARRAY_A
    );
    
    if ( $member ) {
        // Update last login
        $wpdb->update(
            $table_name,
            array( 'last_login' => current_time( 'mysql' ) ),
            array( 'id' => $member['id'] ),
            array( '%s' ),
            array( '%d' )
        );
        
        return $member;
    }
    
    return false;
}

function order_sync_verify_team_login( $team_name, $access_code ) {
    $stored_team_name = get_option( 'order_sync_team_name', '' );
    $stored_access_code = get_option( 'order_sync_access_code', '' );
    
    return ( ! empty( $stored_team_name ) && ! empty( $stored_access_code ) && 
             $team_name === $stored_team_name && $access_code === $stored_access_code );
}

// Register REST API routes
add_action( 'rest_api_init', function () {
    // Order endpoints
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
    
    // Team authentication endpoints
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
    
    register_rest_route( 'order-manager/v1', '/team/members', array(
        'methods' => 'GET',
        'callback' => 'get_team_members_api',
        'permission_callback' => 'order_sync_check_permissions',
    ));
});

// Permission callback for API endpoints
function order_sync_check_permissions( WP_REST_Request $request ) {
    // Check for API key in headers
    $api_key = $request->get_header( 'X-API-Key' );
    $stored_api_key = get_option( 'order_sync_api_key' );
    
    if ( ! empty( $stored_api_key ) && $api_key === $stored_api_key ) {
        return true;
    }
    
    // Check for team name + access code authentication (mobile app login)
    $team_name = $request->get_header( 'X-Team-Name' );
    $access_code = $request->get_header( 'X-Access-Code' );
    
    if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
        if ( order_sync_verify_team_login( $team_name, $access_code ) ) {
            return true;
        }
    }
    
    // Check for team member authentication (individual member access)
    $team_email = $request->get_header( 'X-Team-Email' );
    $member_access_code = $request->get_header( 'X-Member-Access-Code' );
    
    if ( ! empty( $team_email ) && ! empty( $member_access_code ) ) {
        $member = order_sync_verify_team_member( $team_email, $member_access_code );
        if ( $member ) {
            return true;
        }
    }
    
    // Fallback to user authentication
    return current_user_can( 'edit_posts' );
}

// Callback function to get orders
function get_orders( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
    $limit = $request->get_param( 'limit' ) ? intval( $request->get_param( 'limit' ) ) : 10;
    $offset = $request->get_param( 'offset' ) ? intval( $request->get_param( 'offset' ) ) : 0;
    
    $orders = $wpdb->get_results( 
        $wpdb->prepare( 
            "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d", 
            $limit, 
            $offset 
        ),
        ARRAY_A
    );
    
    // Decode JSON data for each order
    foreach ( $orders as &$order ) {
        $order['order_data'] = json_decode( $order['order_data'], true );
    }
    
    return new WP_REST_Response( $orders, 200 );
}

// Callback function to get a single order
function get_order_by_id( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
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

// Callback function to create an order
function create_order( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
    $data = $request->get_json_params();
    
    if ( ! isset( $data['order_id'] ) || ! isset( $data['user_id'] ) ) {
        return new WP_REST_Response( 'Missing required fields: order_id, user_id', 400 );
    }
    
    $order_id = sanitize_text_field( $data['order_id'] );
    $user_id = sanitize_text_field( $data['user_id'] );
    $order_data = wp_json_encode( $data );
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'order_id' => $order_id,
            'user_id' => $user_id,
            'order_data' => $order_data,
            'sync_status' => 'synced'
        ),
        array( '%s', '%s', '%s', '%s' )
    );
    
    if ( $result === false ) {
        return new WP_REST_Response( 'Failed to create order', 500 );
    }
    
    return new WP_REST_Response( array( 'message' => 'Order created successfully', 'id' => $wpdb->insert_id ), 201 );
}

// Callback function to update an order
function update_order( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
    $order_id = $request->get_param( 'id' );
    $data = $request->get_json_params();
    
    $order_data = wp_json_encode( $data );
    
    $result = $wpdb->update(
        $table_name,
        array(
            'order_data' => $order_data,
            'sync_status' => 'updated'
        ),
        array( 'order_id' => $order_id ),
        array( '%s', '%s' ),
        array( '%s' )
    );
    
    if ( $result === false ) {
        return new WP_REST_Response( 'Failed to update order', 500 );
    }
    
    if ( $result === 0 ) {
        return new WP_REST_Response( 'Order not found', 404 );
    }
    
    return new WP_REST_Response( 'Order updated successfully', 200 );
}

// Callback function to delete an order
function delete_order( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
    $order_id = $request->get_param( 'id' );
    
    $result = $wpdb->delete(
        $table_name,
        array( 'order_id' => $order_id ),
        array( '%s' )
    );
    
    if ( $result === false ) {
        return new WP_REST_Response( 'Failed to delete order', 500 );
    }
    
    if ( $result === 0 ) {
        return new WP_REST_Response( 'Order not found', 404 );
    }
    
    return new WP_REST_Response( 'Order deleted successfully', 200 );
}

// Team authentication API endpoints
function team_member_login( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    
    if ( ! isset( $data['team_name'] ) || ! isset( $data['access_code'] ) ) {
        return new WP_REST_Response( 'Missing team name or access code', 400 );
    }
    
    $team_name = sanitize_text_field( $data['team_name'] );
    $access_code = sanitize_text_field( $data['access_code'] );
    
    // Verify team login credentials
    if ( order_sync_verify_team_login( $team_name, $access_code ) ) {
        return new WP_REST_Response( array(
            'success' => true,
            'team' => array(
                'name' => $team_name,
                'access_code' => $access_code
            ),
            'message' => 'Team login successful'
        ), 200 );
    }
    
    return new WP_REST_Response( array(
        'success' => false,
        'message' => 'Invalid team name or access code'
    ), 401 );
}

function verify_team_access( WP_REST_Request $request ) {
    $data = $request->get_json_params();
    
    if ( ! isset( $data['team_name'] ) || ! isset( $data['access_code'] ) ) {
        return new WP_REST_Response( 'Missing team name or access code', 400 );
    }
    
    $team_name = sanitize_text_field( $data['team_name'] );
    $access_code = sanitize_text_field( $data['access_code'] );
    
    $is_valid = order_sync_verify_team_login( $team_name, $access_code );
    
    return new WP_REST_Response( array( 
        'valid' => $is_valid,
        'team_name' => $is_valid ? $team_name : null
    ), 200 );
}

function get_team_members_api( WP_REST_Request $request ) {
    $members = order_sync_get_team_members();
    
    // Remove sensitive data
    foreach ( $members as &$member ) {
        unset( $member['access_code'] );
    }
    
    return new WP_REST_Response( $members, 200 );
}

// Clean up on plugin deactivation
register_deactivation_hook( __FILE__, 'bkmb_subsales_deactivate' );

function bkmb_subsales_deactivate() {
    // Clean up any scheduled events or temporary data if needed
    // Note: We don't delete database tables on deactivation to preserve data
}

// Uninstall hook (only runs when plugin is deleted)
register_uninstall_hook( __FILE__, 'bkmb_subsales_uninstall' );

function bkmb_subsales_uninstall() {
    global $wpdb;
    
    // Delete database tables
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}order_sync_orders" );
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}order_sync_team_members" );
    
    // Delete options
    delete_option( 'order_sync_api_key' );
    delete_option( 'order_sync_interval' );
    delete_option( 'order_sync_access_code' );
    delete_option( 'order_sync_team_name' );
    delete_option( 'bkmb_subsales_version' );
}
?>