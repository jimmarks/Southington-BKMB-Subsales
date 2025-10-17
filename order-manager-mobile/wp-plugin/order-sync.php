<?php
/**
 * Plugin Name: Order Sync
 * Description: A plugin to synchronize orders between the mobile app and WordPress backend.
 * Version: 1.1.0
 * Author: Your Name
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Hook to add admin menu
add_action( 'admin_menu', 'order_sync_admin_menu' );

// Add admin menu item
function order_sync_admin_menu() {
    global $menu;
    
    // Find the position after Comments (25)
    $position = 26;
    
    // Add separator before our menu
    $menu[$position - 0.5] = array( '', 'read', 'separator-before-order-sync', '', 'wp-menu-separator' );
    
    // Add main menu item
    add_menu_page(
        'BKMB Subsales Management',           // Page title
        'BKMB Subsales',                     // Menu title
        'manage_options',                    // Capability
        'bkmb-subsales-management',          // Menu slug
        'order_sync_main_page',              // Function
        'dashicons-clipboard',               // Icon
        $position                            // Position
    );
    
    // Add submenu pages
    add_submenu_page(
        'bkmb-subsales-management',
        'Settings',
        'Settings',
        'manage_options',
        'bkmb-subsales-settings',
        'order_sync_settings_page'
    );
    
    add_submenu_page(
        'bkmb-subsales-management',
        'Teams Management',
        'Teams',
        'manage_options',
        'bkmb-subsales-teams',
        'order_sync_teams_page'
    );
    
    add_submenu_page(
        'bkmb-subsales-management',
        'Orders',
        'Orders',
        'manage_options',
        'bkmb-subsales-orders',
        'order_sync_orders_page'
    );
    
    // Add separator after our menu
    $menu[$position + 0.5] = array( '', 'read', 'separator-after-order-sync', '', 'wp-menu-separator' );
}

// Main dashboard page
function order_sync_main_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    ?>
    <div class="wrap">
        <h1>BKMB Subsales Management</h1>
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
                <a href="<?php echo admin_url( 'admin.php?page=bkmb-subsales-settings' ); ?>" class="button button-primary">Configure Settings</a>
                <a href="<?php echo admin_url( 'admin.php?page=bkmb-subsales-teams' ); ?>" class="button">Manage Teams</a>
                <a href="<?php echo admin_url( 'admin.php?page=bkmb-subsales-orders' ); ?>" class="button">View Orders</a>
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
        
        update_option( 'order_sync_google_maps_api_key', $api_key );
        update_option( 'order_sync_interval', $sync_interval );
        
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    $sync_interval = get_option( 'order_sync_interval', 300 );
    
    ?>
    <div class="wrap">
        <h1>BKMB Subsales Settings</h1>
        
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
            </table>
            <?php submit_button(); ?>
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

// Teams management page
function order_sync_teams_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle team actions
    if ( isset( $_POST['add_team'] ) ) {
        check_admin_referer( 'order_sync_teams_nonce' );
        
        $team_name = sanitize_text_field( $_POST['team_name'] );
        $access_code = sanitize_text_field( $_POST['access_code'] );
        $description = sanitize_text_field( $_POST['description'] );
        
        if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
            if ( order_sync_add_team( $team_name, $access_code, $description ) ) {
                echo '<div class="notice notice-success"><p>Team added successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error adding team. Team name or access code may already exist.</p></div>';
            }
        }
    }

    if ( isset( $_POST['remove_team'] ) ) {
        check_admin_referer( 'order_sync_teams_nonce' );
        
        $team_id = intval( $_POST['team_id'] );
        if ( order_sync_remove_team( $team_id ) ) {
            echo '<div class="notice notice-success"><p>Team removed successfully!</p></div>';
        }
    }

    if ( isset( $_POST['add_team_member'] ) ) {
        check_admin_referer( 'order_sync_team_member_nonce' );
        
        $team_id = intval( $_POST['member_team_id'] );
        $name = sanitize_text_field( $_POST['member_name'] );
        $email = sanitize_email( $_POST['member_email'] );
        $role = sanitize_text_field( $_POST['member_role'] );
        
        if ( ! empty( $name ) && ! empty( $email ) && $team_id > 0 ) {
            if ( order_sync_add_team_member( $team_id, $name, $email, $role ) ) {
                echo '<div class="notice notice-success"><p>Team member added!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error adding team member. Email may already exist.</p></div>';
            }
        }
    }

    if ( isset( $_POST['remove_team_member'] ) ) {
        check_admin_referer( 'order_sync_team_member_nonce' );
        
        $member_id = intval( $_POST['member_id'] );
        order_sync_remove_team_member( $member_id );
        echo '<div class="notice notice-success"><p>Team member removed!</p></div>';
    }

    $teams = order_sync_get_teams();
    
    ?>
    <div class="wrap">
        <h1>Teams Management</h1>
        
        <!-- Add Team Form -->
        <div class="postbox" style="margin-top: 20px;">
            <div class="postbox-header"><h2>Add New Team</h2></div>
            <div class="inside">
                <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_teams_nonce' ); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Team Name</th>
                            <td><input type="text" name="team_name" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th scope="row">Access Code</th>
                            <td>
                                <input type="text" name="access_code" class="regular-text" required />
                                <p class="description">Unique access code for mobile app authentication</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Description</th>
                            <td><input type="text" name="description" class="regular-text" /></td>
                        </tr>
                    </table>
                    <?php submit_button( 'Add Team', 'primary', 'add_team' ); ?>
                </form>
            </div>
        </div>
        
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
                        <?php wp_nonce_field( 'order_sync_teams_nonce' ); ?>
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
    <?php
}

// Orders page
function order_sync_orders_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
    // Get pagination parameters
    $page = isset( $_GET['paged'] ) ? intval( $_GET['paged'] ) : 1;
    $per_page = 20;
    $offset = ( $page - 1 ) * $per_page;
    
    // Get orders
    $orders = $wpdb->get_results( 
        $wpdb->prepare( 
            "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d", 
            $per_page, 
            $offset 
        ),
        ARRAY_A
    );
    
    $total_orders = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
    $total_pages = ceil( $total_orders / $per_page );
    
    ?>
    <div class="wrap">
        <h1>Orders Management</h1>
        
        <div class="tablenav top">
            <div class="alignleft actions">
                <span class="displaying-num"><?php echo $total_orders; ?> items</span>
            </div>
            <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav-pages">
                <?php
                $page_links = paginate_links( array(
                    'base' => add_query_arg( 'paged', '%#%' ),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $page
                ));
                echo $page_links;
                ?>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ( ! empty( $orders ) ) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>User ID</th>
                    <th>Status</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $orders as $order ) : ?>
                <tr>
                    <td><?php echo esc_html( $order['order_id'] ); ?></td>
                    <td><?php echo esc_html( $order['user_id'] ); ?></td>
                    <td>
                        <span class="status-<?php echo esc_attr( $order['sync_status'] ); ?>">
                            <?php echo esc_html( ucfirst( $order['sync_status'] ) ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( date( 'M j, Y g:i A', strtotime( $order['created_at'] ) ) ); ?></td>
                    <td><?php echo esc_html( date( 'M j, Y g:i A', strtotime( $order['updated_at'] ) ) ); ?></td>
                    <td>
                        <button type="button" class="button button-small view-order-details" 
                                data-order-id="<?php echo esc_attr( $order['id'] ); ?>">View Details</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
        <p>No orders found.</p>
        <?php endif; ?>
        
        <!-- Order Details Modal -->
        <div id="order-details-modal" style="display: none;">
            <div id="order-details-content"></div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.view-order-details').click(function() {
                var orderId = $(this).data('order-id');
                // Add AJAX call to load order details
                alert('Order details for ID: ' + orderId + '\n(AJAX implementation needed)');
            });
        });
        </script>
    </div>
    <?php
}
    
    ?>
    <div class="wrap">
        <h1>Order Sync Settings</h1>
        
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
        


// Create database table on activation
register_activation_hook( __FILE__, 'order_sync_create_table' );

function order_sync_create_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'order_sync_orders';
    $teams_table_name = $wpdb->prefix . 'order_sync_teams';
    $team_members_table_name = $wpdb->prefix . 'order_sync_team_members';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Orders table
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id varchar(255) NOT NULL,
        user_id varchar(255) NOT NULL,
        team_id mediumint(9),
        order_data text NOT NULL,
        sync_status varchar(50) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY order_id (order_id),
        KEY team_id (team_id)
    ) $charset_collate;";
    
    // Teams table
    $teams_sql = "CREATE TABLE $teams_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        access_code varchar(255) NOT NULL,
        description text,
        status varchar(50) NOT NULL DEFAULT 'active',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name),
        UNIQUE KEY access_code (access_code)
    ) $charset_collate;";
    
    // Team members table
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
        PRIMARY KEY (id),
        UNIQUE KEY email (email),
        KEY team_id (team_id)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    dbDelta( $teams_sql );
    dbDelta( $team_members_sql );
}

// Team management functions
function order_sync_add_team( $name, $access_code, $description = '' ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_teams';
    
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
    
    return $result !== false;
}

function order_sync_remove_team( $team_id ) {
    global $wpdb;
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
    // First remove all team members
    $wpdb->delete( $members_table, array( 'team_id' => $team_id ), array( '%d' ) );
    
    // Then remove the team
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
    
    register_rest_route( 'order-manager/v1', '/config', array(
        'methods' => 'GET',
        'callback' => 'get_app_config',
        'permission_callback' => 'order_sync_check_permissions',
    ));
});
// Permission callback for API endpoints
function order_sync_check_permissions( WP_REST_Request $request ) {
    // Check for Google Maps API access - always allow config endpoint for authenticated teams
    if ( strpos( $request->get_route(), '/config' ) !== false ) {
        // Allow if team credentials are valid
        $team_name = $request->get_header( 'X-Team-Name' );
        $access_code = $request->get_header( 'X-Access-Code' );
        
        if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
            $team = order_sync_get_team_by_credentials( $team_name, $access_code );
            if ( $team ) {
                return true;
            }
        }
    }
    
    // Check for team name + access code authentication (mobile app login)
    $team_name = $request->get_header( 'X-Team-Name' );
    $access_code = $request->get_header( 'X-Access-Code' );
    
    if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
        $team = order_sync_get_team_by_credentials( $team_name, $access_code );
        if ( $team ) {
            return true;
        }
    }
    
    // Check for team member authentication
    $team_email = $request->get_header( 'X-Team-Email' );
    $team_id = $request->get_header( 'X-Team-ID' );
    
    if ( ! empty( $team_email ) && ! empty( $team_id ) ) {
        $member = order_sync_verify_team_member( $team_email, $team_id );
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
    
    // Verify team login credentials with new multi-team system
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
    
    return new WP_REST_Response( array( 
        'valid' => ! empty( $team ),
        'team' => $team ? array(
            'id' => $team['id'],
            'name' => $team['name']
        ) : null
    ), 200 );
}

function get_app_config( WP_REST_Request $request ) {
    // Get Google Maps API key for authenticated teams
    $google_maps_api_key = get_option( 'order_sync_google_maps_api_key', '' );
    
    return new WP_REST_Response( array(
        'google_maps_api_key' => $google_maps_api_key,
        'app_version' => '1.1.0'
    ), 200 );
}
?>