<?php
/**
 * Plugin Name: Subsales Management
 * Plugin URI: https://github.com/jimmarks/Southington-BKMB-Subsales
 * Description: A comprehensive order management system for mobile app synchronization with WordPress backend. Includes multi-team management, Google Maps integration, and professional admin interface.
 * Version: 1.1.1
 * Author: Jim Marks
 * Author URI: https://github.com/jimmarks
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: subsales-management
 * Domain Path: /languages
 * Network: false
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---- Plugin constants ----
if ( ! defined( 'SUBSALES_VERSION' ) ) define( 'SUBSALES_VERSION', '1.1.0' );
if ( ! defined( 'SUBSALES_PLUGIN_URL' ) ) define( 'SUBSALES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_PATH' ) ) define( 'SUBSALES_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_BASENAME' ) ) define( 'SUBSALES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---- Implementation (merged from legacy bkmb file) ----

// Activation hook
register_activation_hook( __FILE__, 'subsales_activate' );

function subsales_activate() {
    // Check WordPress version
    global $wp_version;
    if ( version_compare( $wp_version, '5.0', '<' ) ) {
        deactivate_plugins( SUBSALES_PLUGIN_BASENAME );
        wp_die( 'Subsales Management requires WordPress 5.0 or higher.' );
    }
    
    // Create database tables
    order_sync_create_table();
    // Ensure PWA page exists with default slug
    $slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    order_sync_ensure_pwa_page( $slug );
    
    // Set activation flag for admin notice
    set_transient( 'subsales_activated', true, 30 );
}

// Show admin notice on activation

add_action( 'admin_notices', 'subsales_activation_notice' );

function subsales_activation_notice() {
    if ( get_transient( 'subsales_activated' ) ) {
        delete_transient( 'subsales_activated' );
        
        global $wpdb;
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    // Use proper string interpolation to check for table existence
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$teams_table}'" ) === $teams_table;
        
        if ( $table_exists ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Subsales Management activated successfully!</strong> Database tables created. You can now manage teams and orders from the <a href="' . admin_url( 'admin.php?page=subsales-management' ) . '">Subsales</a> menu.</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Subsales Management:</strong> Warning - Database tables may not have been created properly. Please deactivate and reactivate the plugin.</p>';
            echo '</div>';
        }
    }
}

// Hook to add admin menu
add_action( 'admin_menu', 'order_sync_admin_menu' );

// Add admin menu item
function order_sync_admin_menu() {
    // Add main menu item at position 26 (after Comments at 25)
    add_menu_page(
        'Subsales Management',           // Page title
        'Subsales',                     // Menu title
        'manage_options',                    // Capability
        'subsales-management',          // Menu slug
        'order_sync_main_page',              // Function
        'dashicons-clipboard',               // Icon
        26                                   // Position (after Comments)
    );
    
    // Add submenu pages
    add_submenu_page(
        'subsales-management',
        'Settings',
        'Settings',
        'manage_options',
        'subsales-settings',
        'order_sync_settings_page'
    );
    
    add_submenu_page(
        'subsales-management',
        'Teams Management',
        'Teams',
        'manage_options',
        'subsales-teams',
        'order_sync_teams_page'
    );
    
    add_submenu_page(
        'subsales-management',
        'Orders',
        'Orders',
        'manage_options',
        'subsales-orders',
        'order_sync_orders_page'
    );
}

// Enqueue admin assets for settings page (media uploader for header image)
add_action( 'admin_enqueue_scripts', 'order_sync_admin_assets' );
function order_sync_admin_assets( $hook ) {
    // Only load on our settings page
    if ( strpos( $hook, 'subsales-settings' ) === false && strpos( $hook, 'admin.php?page=subsales-settings' ) === false ) {
        return;
    }

    // WP media scripts
    wp_enqueue_media();
    wp_enqueue_script( 'subsales-admin-header', SUBSALES_PLUGIN_URL . 'assets/js/admin-header-image.js', array( 'jquery' ), SUBSALES_VERSION, true );
}

// Teams and Orders pages, DB functions, REST routes, etc. (merged implementation)
// Implementation merged from legacy files; shortcode name is 'subsales_pwa'.

// -- Teams management, orders page, DB creation and helpers --
function order_sync_create_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'order_sync_orders';
    $teams_table_name = $wpdb->prefix . 'order_sync_teams';
    $team_members_table_name = $wpdb->prefix . 'order_sync_team_members';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id varchar(255) NOT NULL,
        user_id varchar(255) NOT NULL,
        team_id mediumint(9),
        order_data text NOT NULL,
        sync_status varchar(50) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY order_id (order_id),
        KEY team_id (team_id)
    ) $charset_collate;";
    
    $teams_sql = "CREATE TABLE $teams_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        access_code varchar(255) NOT NULL,
        description text,
        status varchar(50) NOT NULL DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY name (name),
        UNIQUE KEY access_code (access_code)
    ) $charset_collate;";
    
    $team_members_sql = "CREATE TABLE $team_members_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        team_id mediumint(9) NOT NULL,
        name varchar(255) NOT NULL,
        email varchar(255) NOT NULL,
        role varchar(50) NOT NULL DEFAULT 'member',
        status varchar(50) NOT NULL DEFAULT 'active',
        last_login datetime,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY email (email),
        KEY team_id (team_id)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    dbDelta( $teams_sql );
    dbDelta( $team_members_sql );
}

function order_sync_add_team( $name, $access_code, $description = '' ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_teams';
    
    $existing = $wpdb->get_row( 
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE name = %s OR access_code = %s",
            $name,
            $access_code
        )
    );
    
    if ( $existing ) {
        error_log( 'Subsales: Team creation failed - name or access code already exists: ' . $name );
        return false;
    }
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'name' => $name,
            'access_code' => $access_code,
            'description' => $description,
            'status' => 'active'
        ),
        array( '%s', '%s', '%s', '%s' )
    );
    
    if ( $result === false ) {
        error_log( 'Subsales: Database error creating team: ' . $wpdb->last_error );
        return false;
    }
    
    return true;
}

function order_sync_remove_team( $team_id ) {
    global $wpdb;
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
    $wpdb->delete( $members_table, array( 'team_id' => $team_id ), array( '%d' ) );
    return $wpdb->delete( $teams_table, array( 'id' => $team_id ), array( '%d' ) );
}

function order_sync_get_teams() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_teams';
    
    return $wpdb->get_results( 
        "SELECT * FROM {$table_name} WHERE status = 'active' ORDER BY created_at DESC", 
        ARRAY_A 
    );
}

function order_sync_get_team_by_credentials( $team_name, $access_code ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_teams';
    
    return $wpdb->get_row( 
        $wpdb->prepare( 
            "SELECT * FROM {$table_name} WHERE name = %s AND access_code = %s AND status = 'active'", 
            $team_name, 
            $access_code 
        ),
        ARRAY_A
    );
}

function order_sync_add_team_member( $team_id, $name, $email, $role = 'member' ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_team_members';
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'team_id' => $team_id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'status' => 'active'
        ),
        array( '%d', '%s', '%s', '%s', '%s' )
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

function order_sync_get_team_members_by_team( $team_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_team_members';
    
    return $wpdb->get_results( 
        $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE team_id = %d ORDER BY created_at DESC", 
            $team_id
        ),
        ARRAY_A 
    );
}

function order_sync_verify_team_member( $email, $team_id ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_team_members';
    
    $member = $wpdb->get_row( 
        $wpdb->prepare( 
            "SELECT * FROM {$table_name} WHERE email = %s AND team_id = %d AND status = 'active'", 
            $email, 
            $team_id 
        ),
        ARRAY_A
    );
    
    if ( $member ) {
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

// Register REST API routes
add_action( 'rest_api_init', function () {
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
    
    register_rest_route( 'order-manager/v1', '/config', array(
        'methods' => 'GET',
        'callback' => 'get_app_config',
        'permission_callback' => 'order_sync_check_permissions',
    ));
});

// Permission callback
function order_sync_check_permissions( WP_REST_Request $request ) {
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
    
    $team_name = $request->get_header( 'X-Team-Name' );
    $access_code = $request->get_header( 'X-Access-Code' );
    
    if ( ! empty( $team_name ) || ! empty( $access_code ) ) {
        $team = order_sync_get_team_by_credentials( $team_name, $access_code );
        if ( $team ) {
            error_log( 'Subsales: perm_check team creds ok id=' . ( isset($team['id']) ? $team['id'] : 'unknown' ) );
            return true;
        }
        error_log( 'Subsales: perm_check invalid team credentials provided (team=' . $team_name . ')' );
        return false;
    }
    
    $team_email = $request->get_header( 'X-Team-Email' );
    $team_id = $request->get_header( 'X-Team-ID' );
    
    if ( ! empty( $team_email ) && ! empty( $team_id ) ) {
        $member = order_sync_verify_team_member( $team_email, $team_id );
        if ( $member ) {
            error_log( 'Subsales: perm_check team member ok id=' . ( isset($member['id']) ? $member['id'] : 'unknown' ) );
            return true;
        }
    }
    
    return current_user_can( 'edit_posts' );
}

// Orders REST callbacks (get_orders, get_order_by_id, create_order, update_order, delete_order)
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
    
    foreach ( $orders as &$order ) {
        $order['order_data'] = json_decode( $order['order_data'], true );
    }
    
    return new WP_REST_Response( $orders, 200 );
}

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
    
    $team = order_sync_get_team_by_credentials( $team_name, $access_code );
    
    if ( $team ) {
        return new WP_REST_Response( array(
            'success' => true,
            'team' => array(
                'id' => $team['id'],
                'name' => $team['name'],
                'access_code' => $team['access_code']
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
    
    $team = order_sync_get_team_by_credentials( $team_name, $access_code );
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

function get_app_config( WP_REST_Request $request ) {
    $google_maps_api_key = get_option( 'order_sync_google_maps_api_key', '' );
    $portal_slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    $portal_url = esc_url_raw( home_url( '/' . $portal_slug . '/' ) );

    $branding = get_option( 'subsales_branding', 'Subsales' );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

    return new WP_REST_Response( array(
        'google_maps_api_key' => $google_maps_api_key,
        'app_version' => SUBSALES_VERSION,
        'portal_url' => $portal_url,
        'brandName' => $branding,
        'brandingImage' => $header_image_url
    ), 200 );
}

/**
 * PWA shortcode and script registration (canonical names)
 */
function subsales_register_pwa_scripts() {
    wp_register_script( 'subsales-pwa-app', SUBSALES_PLUGIN_URL . 'pwa/app.js', array(), SUBSALES_VERSION, true );
    $portal_base = esc_url_raw( home_url( '/' . get_option( 'order_sync_portal_slug', 'subsales-portal' ) . '/' ) );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

    $settings = array(
        'apiBase' => esc_url_raw( rest_url( 'order-manager/v1' ) ),
        'pluginBase' => SUBSALES_PLUGIN_URL . 'pwa/',
        'portalBase' => $portal_base,
        'googleMapsApiKey' => get_option( 'order_sync_google_maps_api_key', '' ),
        'brandName' => get_option( 'subsales_branding', 'Subsales' ),
        'brandingImage' => $header_image_url
    );

    wp_localize_script( 'subsales-pwa-app', 'SUBSALES_PWA_CONFIG', $settings );
}
add_action( 'wp_enqueue_scripts', 'subsales_register_pwa_scripts' );

function subsales_pwa_shortcode( $atts = array() ) {
    wp_enqueue_script( 'subsales-pwa-app' );

    add_action( 'wp_head', function() {
        $portal_slug = get_option( 'order_sync_portal_slug', '' );
        if ( $portal_slug ) {
            echo '<link rel="manifest" href="' . esc_url( home_url( '/' . $portal_slug . '/manifest.json' ) ) . '">';
        } else {
            echo '<link rel="manifest" href="' . esc_url( SUBSALES_PLUGIN_URL . 'pwa/manifest.json' ) . '">';
        }
        echo '<meta name="theme-color" content="#2d6cdf">';
    } );

        ob_start();
        ?>
        <div id="subsales-pwa-root">
            <header style="display:flex;align-items:center;justify-content:space-between">
                <div style="display:flex;align-items:center;gap:12px">
                    <img id="brandHeaderImage" style="max-height:48px;display:none" />
                    <h1 style="margin:0"><?php echo esc_html( get_option( 'subsales_branding', 'Subsales' ) ); ?></h1>
                </div>
                <div>
                    <button id="viewOnlineBtn" style="margin-right:8px">View online orders</button>
                    <span id="installBox" style="display:none"><button id="installBtn">Install App</button></span>
                </div>
            </header>

            <section id="loginSection">
                <h2>Team Login</h2>
                <p>Sign in with your team name and access code. After login the PWA install prompt will be shown (if available).</p>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
                    <img id="brandHeaderImage" style="max-height:48px;display:none" />
                    <div style="flex:1"></div>
                </div>
                <input id="teamName" placeholder="Team name" />
                <input id="teamCode" placeholder="Access code" />
                <div id="loginError" style="color:#c00;margin-top:8px;display:none"></div>
                <button id="loginBtn">Login</button>
            </section>

            <section id="appSection" style="display:none">
                <div style="display:flex;gap:12px;flex-direction:column">
                    <div style="flex:1">
                        <h2>Create Order</h2>
                        <label>Customer name</label>
                        <input id="customerName" placeholder="Customer name" />
                        <label>Address</label>
                        <input id="address" placeholder="Address" />
                        <label>Cell number</label>
                        <input id="cellNumber" type="tel" inputmode="numeric" pattern="[0-9]*" placeholder="Cell number" />
                        <div style="display:flex;gap:8px;margin-top:6px;margin-bottom:6px">
                            <div><label>Turkey</label><input id="turkeyQty" type="number" min="0" value="0" /></div>
                            <div><label>Ham</label><input id="hamQty" type="number" min="0" value="0" /></div>
                            <div><label>Combo</label><input id="comboQty" type="number" min="0" value="0" /></div>
                        </div>
                        <label>Donation amount (USD)</label>
                        <input id="donationAmount" type="number" min="0" step="0.01" value="0" placeholder="$0.00" />
                        <div style="margin-top:6px"><strong>Order total: $<span id="orderTotal">0.00</span></strong></div>
                        <div style="margin-top:8px;display:flex;gap:12px;align-items:center">
                            <label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" id="payCheck" /> <span>Pay by check</span></label>
                            <label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" id="payCash" /> <span>Pay by cash</span></label>
                        </div>
                        <div id="checkNumberRow" style="display:none;margin-top:6px"><label>Check number</label><input id="checkNumber" placeholder="Check number" /></div>
                        <label>Notes</label>
                        <textarea id="notes" placeholder="Notes (optional)"></textarea>
                        <button id="saveOrderBtn">Save Order</button>
                    </div>
                    <h3>Local orders</h3>
                    <div id="ordersList"></div>
                    <div style="margin-top:12px; border-top:1px solid #eee; padding-top:8px">
                        <h3>Status</h3>
                        <div id="networkStatus">Offline</div>
                        <div id="syncStatus">Not synced</div>
                    </div>
                </div>
            </section>
        </div>
        <?php
        return ob_get_clean();
}
add_shortcode( 'subsales_pwa', 'subsales_pwa_shortcode' );

function order_sync_clear_data() {
    global $wpdb;
    $tables = array(
        $wpdb->prefix . 'order_sync_orders',
        $wpdb->prefix . 'order_sync_teams',
        $wpdb->prefix . 'order_sync_team_members'
    );

    foreach ( $tables as $table ) {
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
        if ( $exists ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }
    }

    delete_option( 'order_sync_pwa_page_id' );
}

function subsales_uninstall() {
    $delete = get_option( 'subsales_delete_on_uninstall', 0 );
    if ( ! $delete ) return;

    global $wpdb;
    $tables = array(
        $wpdb->prefix . 'order_sync_orders',
        $wpdb->prefix . 'order_sync_teams',
        $wpdb->prefix . 'order_sync_team_members'
    );

    foreach ( $tables as $table ) {
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
        if ( $exists ) $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }

    delete_option( 'order_sync_google_maps_api_key' );
    delete_option( 'order_sync_interval' );
    delete_option( 'order_sync_portal_slug' );
    delete_option( 'order_sync_pwa_page_id' );
    delete_option( 'subsales_delete_on_uninstall' );
}
register_uninstall_hook( __FILE__, 'subsales_uninstall' );

function order_sync_ensure_pwa_page( $slug = 'subsales-portal' ) {
    $slug = sanitize_title( $slug );
    $page_id = get_option( 'order_sync_pwa_page_id', 0 );
    if ( $page_id ) {
        $page = get_post( $page_id );
        if ( $page && 'publish' === $page->post_status ) {
            if ( $page->post_name !== $slug ) wp_update_post( array( 'ID' => $page_id, 'post_name' => $slug ) );
            return $page_id;
        }
    }

    $existing = get_page_by_path( $slug );
    if ( $existing ) { update_option( 'order_sync_pwa_page_id', $existing->ID ); return $existing->ID; }

    $postarr = array(
        'post_title'   => 'Subsales Portal',
        'post_name'    => $slug,
        'post_content' => '[subsales_pwa]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    );

    $new_id = wp_insert_post( $postarr );
    if ( $new_id && ! is_wp_error( $new_id ) ) { update_option( 'order_sync_pwa_page_id', $new_id ); return $new_id; }
    return false;
}

// Serve portal assets from plugin folder at portal path
add_action( 'template_redirect', 'subsales_serve_portal_assets' );
function subsales_serve_portal_assets() {
    $portal_slug = get_option( 'order_sync_portal_slug', '' ); if ( empty( $portal_slug ) ) return;
    $req_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    $portal_base = trim( parse_url( home_url( '/' . $portal_slug . '/' ), PHP_URL_PATH ), '/' );

    if ( $req_path === $portal_base . '/service-worker.js' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/service-worker.js'; if ( file_exists( $file ) ) { header( 'Content-Type: application/javascript' ); readfile( $file ); exit; }
    }

    if ( $req_path === $portal_base || $req_path === $portal_base . '/index.html' || $req_path === $portal_base . '/' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/index.html'; if ( file_exists( $file ) ) {
            $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
            $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

            $settings = array(
                'apiBase' => esc_url_raw( rest_url( 'order-manager/v1' ) ),
                'pluginBase' => SUBSALES_PLUGIN_URL . 'pwa/',
                'portalBase' => esc_url_raw( home_url( '/' . get_option( 'order_sync_portal_slug', 'subsales-portal' ) . '/' ) ),
                'googleMapsApiKey' => get_option( 'order_sync_google_maps_api_key', '' ),
                'brandName' => get_option( 'subsales_branding', 'Subsales' ),
                'brandingImage' => $header_image_url
            );

            $html = file_get_contents( $file );
            $inject = "<script>window.SUBSALES_PWA_CONFIG = " . wp_json_encode( $settings ) . ";</script>";
            $app_src = esc_url( $settings['pluginBase'] . 'app.js' );
            $html = str_replace( '<script src="app.js"></script>', $inject . "\n<script src=\"" . $app_src . "\"></script>", $html );
            header( 'Content-Type: text/html; charset=utf-8' ); echo $html; exit;
        }
    }

    if ( $req_path === $portal_base . '/manifest.json' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/manifest.json'; if ( file_exists( $file ) ) { header( 'Content-Type: application/json' ); readfile( $file ); exit; }
    }
}

// Main dashboard page
function order_sync_main_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    ?>
    <div class="wrap">
        <h1>Subsales Management</h1>
        <p class="description">Comprehensive order management system for mobile app synchronization.</p>
        
        <?php
        global $wpdb;
        $orders_table = $wpdb->prefix . 'order_sync_orders';
        $teams_table = $wpdb->prefix . 'order_sync_teams';
        $members_table = $wpdb->prefix . 'order_sync_team_members';
        
        $order_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table}" );
        $team_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table}" );
        $member_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table}" );
        ?>
        
        <div class="dashboard-widgets-wrap">
            <div class="metabox-holder" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                <div class="postbox">
                    <div class="postbox-header"><h2>Total Orders</h2></div>
                    <div class="inside">
                        <p style="font-size: 36px; font-weight: bold; margin: 0; color: #0073aa; text-align: center;">
                            <?php echo intval( $order_count ); ?>
                        </p>
                    </div>
                </div>
                
                <div class="postbox">
                    <div class="postbox-header"><h2>Active Teams</h2></div>
                    <div class="inside">
                        <p style="font-size: 36px; font-weight: bold; margin: 0; color: #0073aa; text-align: center;">
                            <?php echo intval( $team_count ); ?>
                        </p>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header"><h2>Team Members</h2></div>
                    <div class="inside">
                        <p style="font-size: 36px; font-weight: bold; margin: 0; color: #0073aa; text-align: center;">
                            <?php echo intval( $member_count ); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="margin-top: 30px;">
            <h2>Quick Actions</h2>
            <p>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-settings' ); ?>" class="button button-primary">Configure Settings</a>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-teams' ); ?>" class="button">Manage Teams</a>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-orders' ); ?>" class="button">View Orders</a>
            </p>
        </div>
    </div>
    <?php
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
        $portal_slug = sanitize_title( $_POST['portal_slug'] ?? '' );
    $branding = sanitize_text_field( $_POST['subsales_branding'] ?? '' );
        $delete_on_uninstall = isset( $_POST['delete_on_uninstall'] ) ? 1 : 0;
        $header_image = isset( $_POST['subsales_header_image'] ) ? intval( $_POST['subsales_header_image'] ) : 0;

        if ( empty( $portal_slug ) ) {
            $portal_slug = 'subsales-portal';
        }

        // If slug changed, ensure page exists for new slug
        $old_slug = get_option( 'order_sync_portal_slug', '' );
        update_option( 'order_sync_google_maps_api_key', $api_key );
        update_option( 'order_sync_interval', $sync_interval );
        update_option( 'order_sync_portal_slug', $portal_slug );
    update_option( 'subsales_branding', $branding );
    update_option( 'subsales_delete_on_uninstall', $delete_on_uninstall );
    update_option( 'subsales_header_image', $header_image );

        if ( $portal_slug !== $old_slug ) {
            order_sync_ensure_pwa_page( $portal_slug );
            // flush rewrite rules as a safety (rarely needed here)
            flush_rewrite_rules();
        }

        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    // Handle clear data action (danger zone)
    if ( isset( $_POST['clear_data'] ) ) {
        check_admin_referer( 'order_sync_clear_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>Insufficient permissions to clear data.</p></div>';
        } else {
            order_sync_clear_data();
            echo '<div class="notice notice-success"><p>All plugin data has been cleared.</p></div>';
        }
    }

    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    $sync_interval = get_option( 'order_sync_interval', 300 );
    $portal_slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    $delete_on_uninstall = get_option( 'subsales_delete_on_uninstall', 0 );
    $branding = get_option( 'subsales_branding', 'Subsales' );
    $portal_url = esc_url_raw( home_url( '/' . $portal_slug . '/' ) );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';
    ?>
    <div class="wrap">
        <h1>Subsales Settings</h1>
        <?php if ( ! empty( $branding ) ) : ?>
            <div style="margin-bottom:12px; padding:10px; background:#f7f7f7; border-left:4px solid #2d6cdf">Branding banner: <strong><?php echo esc_html( $branding ); ?></strong></div>
        <?php endif; ?>
        
        <!-- Main Settings Form -->
        <form method="post" action="">
            <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Google Maps API Key</th>
                    <td>
                        <input type="text" name="api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                        <p class="description">Enter your Google Maps API key. This will be shared with mobile clients after login for map functionality.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Sync Interval (seconds)</th>
                    <td>
                        <input type="number" name="sync_interval" value="<?php echo esc_attr( $sync_interval ); ?>" min="60" />
                        <p class="description">How often to sync orders (minimum 60 seconds).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Portal Slug</th>
                    <td>
                        <input type="text" name="portal_slug" value="<?php echo esc_attr( $portal_slug ); ?>" class="regular-text" />
                        <p class="description">The public slug (URL path) where the PWA will be available. Default: <code>subsales-portal</code>.</p>
                        <p class="description">Portal URL: <strong><?php echo esc_url( $portal_url ); ?></strong></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Branding / Group name</th>
                    <td>
                        <input type="text" name="subsales_branding" value="<?php echo esc_attr( $branding ); ?>" class="regular-text" />
                        <p class="description">Optional branding string that will be shown in the PWA header and admin pages.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Header image</th>
                    <td>
                        <input type="hidden" id="subsales_header_image" name="subsales_header_image" value="<?php echo esc_attr( $header_image_id ); ?>" />
                        <div id="subsales_header_preview" style="margin-bottom:8px;<?php echo $header_image_url ? '' : 'display:none;'; ?>">
                            <?php if ( $header_image_url ): ?>
                                <img src="<?php echo esc_url( $header_image_url ); ?>" style="max-width:200px;height:auto;border:1px solid #ddd;padding:4px;background:#fff" />
                            <?php endif; ?>
                        </div>
                        <button type="button" class="button" id="subsales_header_select">Select image</button>
                        <button type="button" class="button" id="subsales_header_remove" <?php echo $header_image_url ? '' : 'style="display:none;"'; ?>>Remove image</button>
                        <p class="description">Optional header image that will be shown in the PWA header on mobile clients.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Delete plugin data on uninstall</th>
                    <td>
                        <label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( 1, intval( $delete_on_uninstall ) ); ?> /> Delete all plugin tables and options when the plugin is uninstalled.</label>
                        <p class="description">When enabled, the plugin will DROP its custom tables when uninstalled from the WordPress Plugins screen.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <!-- Clear data form -->
        <form method="post" action="" style="margin-top:18px">
            <?php wp_nonce_field( 'order_sync_clear_nonce' ); ?>
            <p><strong>Danger zone:</strong> Use the button below to permanently remove all plugin data (orders, teams, team members). This will TRUNCATE those tables but leave plugin files intact.</p>
            <p><button name="clear_data" class="button button-large button-secondary" onclick="return confirm('Permanently clear all plugin data? This cannot be undone.');">Clear plugin data now</button></p>
        </form>
        
        <h2>System Information</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Plugin Version</th>
                <td>1.0.0</td>
            </tr>
            <tr>
                <th scope="row">WordPress Version</th>
                <td><?php echo get_bloginfo( 'version' ); ?></td>
            </tr>
            <tr>
                <th scope="row">PHP Version</th>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr>
                <th scope="row">Database Tables</th>
                <td>
                    <?php
                    global $wpdb;
                    $tables = array(
                        $wpdb->prefix . 'order_sync_orders' => 'Orders',
                        $wpdb->prefix . 'order_sync_teams' => 'Teams',
                        $wpdb->prefix . 'order_sync_team_members' => 'Team Members'
                    );
                    
                    foreach ( $tables as $table => $name ) {
                        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
                        echo '<span style="color: ' . ( $exists ? 'green' : 'red' ) . ';">● ' . $name . '</span><br>';
                    }
                    ?>
                </td>
            </tr>
        </table>
    </div>
    <?php
}

// Teams management admin page
function order_sync_teams_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_teams';

    // Handle team creation
    if ( isset( $_POST['add_team'] ) ) {
        check_admin_referer( 'order_sync_add_team' );
        $name = sanitize_text_field( $_POST['team_name'] ?? '' );
        $code = sanitize_text_field( $_POST['team_code'] ?? '' );
        $desc = sanitize_textarea_field( $_POST['team_description'] ?? '' );
        if ( empty( $name ) || empty( $code ) ) {
            echo '<div class="notice notice-error"><p>Team name and access code are required.</p></div>';
        } else {
            $ok = order_sync_add_team( $name, $code, $desc );
            if ( $ok ) echo '<div class="notice notice-success"><p>Team created.</p></div>';
            else echo '<div class="notice notice-error"><p>Failed to create team (duplicate name or code?).</p></div>';
        }
    }

    // Handle team update
    if ( isset( $_POST['edit_team'] ) ) {
        check_admin_referer( 'order_sync_edit_team' );
        $tid = intval( $_POST['team_id'] ?? 0 );
        $name = sanitize_text_field( $_POST['team_name'] ?? '' );
        $code = sanitize_text_field( $_POST['team_code'] ?? '' );
        $desc = sanitize_textarea_field( $_POST['team_description'] ?? '' );
        if ( $tid && ( ! empty( $name ) && ! empty( $code ) ) ) {
            $updated = $wpdb->update( $table, array( 'name' => $name, 'access_code' => $code, 'description' => $desc ), array( 'id' => $tid ), array( '%s', '%s', '%s' ), array( '%d' ) );
            if ( $updated !== false ) {
                echo '<div class="notice notice-success"><p>Team updated.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to update team.</p></div>';
            }
        }
    }

    // Handle deletion via GET action
    if ( isset( $_GET['delete_team'] ) && isset( $_GET['_wpnonce'] ) ) {
        if ( wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ), 'order_sync_delete_team' ) ) {
            $tid = intval( $_GET['delete_team'] );
            order_sync_remove_team( $tid );
            echo '<div class="notice notice-success"><p>Team removed.</p></div>';
        }
    }

    // Handle add/remove team member via POST
    if ( isset( $_POST['add_team_member'] ) ) {
        check_admin_referer( 'order_sync_team_member_nonce' );
        $member_team_id = intval( $_POST['member_team_id'] ?? 0 );
        $member_name = sanitize_text_field( $_POST['member_name'] ?? '' );
        $member_email = sanitize_email( $_POST['member_email'] ?? '' );
        $member_role = sanitize_text_field( $_POST['member_role'] ?? 'member' );
        if ( $member_team_id && ! empty( $member_name ) && ! empty( $member_email ) ) {
            $ok = order_sync_add_team_member( $member_team_id, $member_name, $member_email, $member_role );
            if ( $ok ) echo '<div class="notice notice-success"><p>Team member added.</p></div>';
            else echo '<div class="notice notice-error"><p>Failed to add team member (possible duplicate email?).</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Name and email are required to add a team member.</p></div>';
        }
    }

    if ( isset( $_POST['remove_team_member'] ) ) {
        check_admin_referer( 'order_sync_team_member_nonce' );
        $member_id = intval( $_POST['member_id'] ?? 0 );
        if ( $member_id ) {
            order_sync_remove_team_member( $member_id );
            echo '<div class="notice notice-success"><p>Team member removed.</p></div>';
        }
    }

    $teams = order_sync_get_teams();
    // If editing, load the team for prefilling form
    $editing = false;
    $edit_team = array( 'id' => 0, 'name' => '', 'access_code' => '', 'description' => '' );
    if ( isset( $_GET['edit_team'] ) ) {
        $tid = intval( $_GET['edit_team'] );
        if ( $tid ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}order_sync_teams WHERE id = %d", $tid ), ARRAY_A );
            if ( $row ) {
                $editing = true;
                $edit_team = $row;
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Teams</h1>
        <form method="post" action="">
            <?php if ( $editing ): wp_nonce_field( 'order_sync_edit_team' ); else: wp_nonce_field( 'order_sync_add_team' ); endif; ?>
            <input type="hidden" name="team_id" value="<?php echo esc_attr( $edit_team['id'] ); ?>" />
            <table class="form-table">
                <tr>
                    <th><label for="team_name">Team name</label></th>
                    <td><input id="team_name" name="team_name" class="regular-text" required value="<?php echo esc_attr( $edit_team['name'] ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="team_code">Access code</label></th>
                    <td><input id="team_code" name="team_code" class="regular-text" required value="<?php echo esc_attr( $edit_team['access_code'] ); ?>"></td>
                </tr>
                <tr>
                    <th><label for="team_description">Description</label></th>
                    <td><textarea id="team_description" name="team_description" class="large-text" rows="3"><?php echo esc_textarea( $edit_team['description'] ); ?></textarea></td>
                </tr>
            </table>
            <?php if ( $editing ): ?>
                <p><button name="edit_team" class="button button-primary">Update Team</button> <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-teams' ) ); ?>" class="button">Cancel</a></p>
            <?php else: ?>
                <p><button name="add_team" class="button button-primary">Add Team</button></p>
            <?php endif; ?>
        </form>

        <!-- Teams List -->
        <h2>Current Teams</h2>
        <?php if ( ! empty( $teams ) ) : ?>
        <?php foreach ( $teams as $team ) : ?>
        <div class="postbox" style="margin-bottom: 20px;">
            <div class="postbox-header">
                <h2><?php echo esc_html( $team['name'] ); ?>
                    <span style="font-weight: normal; color: #666; font-size: 14px;">
                        (Code: <?php echo esc_html( $team['access_code'] ); ?>)
                    </span>
                </h2>
            </div>
            <div class="inside">
                <?php if ( ! empty( $team['description'] ) ) : ?>
                <p><strong>Description:</strong> <?php echo esc_html( $team['description'] ); ?></p>
                <?php endif; ?>
                
                <p><strong>Created:</strong> <?php echo esc_html( date( 'M j, Y g:i A', strtotime( $team['created_at'] ) ) ); ?></p>
                
                <!-- Team Members -->
                <h3>Team Members</h3>
                <?php
                $team_members = order_sync_get_team_members_by_team( $team['id'] );
                if ( ! empty( $team_members ) ) :
                ?>
                <table class="wp-list-table widefat fixed striped" style="margin-bottom: 15px;">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $team_members as $member ) : ?>
                        <tr>
                            <td><?php echo esc_html( $member['name'] ); ?></td>
                            <td><?php echo esc_html( $member['email'] ); ?></td>
                            <td><?php echo esc_html( ucfirst( $member['role'] ) ); ?></td>
                            <td>
                                <span class="status-<?php echo esc_attr( $member['status'] ); ?>">
                                    <?php echo esc_html( ucfirst( $member['status'] ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $member['last_login'] ? esc_html( date( 'M j, Y g:i A', strtotime( $member['last_login'] ) ) ) : 'Never'; ?></td>
                            <td>
                                <form method="post" action="" style="display: inline;">
                                    <?php wp_nonce_field( 'order_sync_team_member_nonce' ); ?>
                                    <input type="hidden" name="member_id" value="<?php echo esc_attr( $member['id'] ); ?>" />
                                    <input type="submit" name="remove_team_member" value="Remove" class="button button-small button-link-delete" 
                                           onclick="return confirm('Are you sure you want to remove this team member?')" />
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p>No team members yet.</p>
                <?php endif; ?>
                
                <!-- Add Team Member Form -->
                <details>
                    <summary style="cursor: pointer; font-weight: bold;">Add Team Member</summary>
                    <form method="post" action="" style="margin-top: 15px;">
                        <?php wp_nonce_field( 'order_sync_team_member_nonce' ); ?>
                        <input type="hidden" name="member_team_id" value="<?php echo esc_attr( $team['id'] ); ?>" />
                        <table class="form-table">
                            <tr>
                                <th scope="row" style="width: 100px;">Name</th>
                                <td><input type="text" name="member_name" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row">Email</th>
                                <td><input type="email" name="member_email" class="regular-text" required /></td>
                            </tr>
                            <tr>
                                <th scope="row">Role</th>
                                <td>
                                    <select name="member_role">
                                        <option value="member">Member</option>
                                        <option value="manager">Manager</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button( 'Add Member', 'secondary', 'add_team_member' ); ?>
                    </form>
                </details>
                
                <!-- Remove Team -->
                <div style="margin-top: 20px; border-top: 1px solid #ddd; padding-top: 15px;">
                    <form method="post" action="" style="display: inline;">
                        <?php wp_nonce_field( 'order_sync_delete_team' ); ?>
                        <input type="hidden" name="team_id" value="<?php echo esc_attr( $team['id'] ); ?>" />
                        <input type="submit" name="remove_team" value="Delete Team" class="button button-link-delete" 
                               onclick="return confirm('Are you sure you want to delete this team and all its members? This action cannot be undone.')" />
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else : ?>
        <p>No teams created yet. Add your first team above.</p>
        <?php endif; ?>
        
        <style>
        .status-active { color: #46b450; font-weight: bold; }
        .status-pending { color: #ffb900; font-weight: bold; }
        .status-inactive { color: #dc3232; font-weight: bold; }
        </style>
    </div>
    </div>
    <?php
}

// Orders admin page
function order_sync_orders_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';

    $orders = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 200", ARRAY_A );
    ?>
    <div class="wrap">
        <h1>Orders</h1>
        <p>Showing last <?php echo count( $orders ); ?> orders (most recent first).</p>
        <table class="widefat fixed" cellspacing="0">
            <thead><tr><th>Order ID</th><th>User ID</th><th>Team</th><th>Created</th><th>Details</th></tr></thead>
            <tbody>
            <?php if ( ! empty( $orders ) ) : foreach ( $orders as $o ) :
                $team_name = '';
                if ( ! empty( $o['team_id'] ) ) {
                    $team = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}order_sync_teams WHERE id = %d", intval( $o['team_id'] ) ) );
                    $team_name = $team ? $team->name : '';
                }
                ?>
                <tr>
                    <td><?php echo esc_html( $o['order_id'] ); ?></td>
                    <td><?php echo esc_html( $o['user_id'] ); ?></td>
                    <td><?php echo esc_html( $team_name ); ?></td>
                    <td><?php echo esc_html( $o['created_at'] ); ?></td>
                    <td><pre style="white-space:pre-wrap;max-width:600px;"><?php echo esc_html( wp_json_encode( json_decode( $o['order_data'], true ), JSON_PRETTY_PRINT ) ); ?></pre></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5">No orders found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

