<?php
/**
 * Plugin Name: Subsales Management
 * Plugin URI: https://github.com/jimmarks/Southington-BKMB-Subsales
 * Description: A comprehensive order management system for mobile app synchronization with WordPress backend. Includes multi-team management, Google Maps integration, and professional admin interface.
 * Version: 1.1.3
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
    
    // Set activation flag for admin notice and onboarding
    set_transient( 'subsales_activated', true, 30 );
    // Use a dedicated transient to trigger the onboarding wizard once (checked by onboarding function)
    set_transient( 'subsales_show_onboarding', true, 60 );
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

// Helper: detect whether plugin is initialized (tables exist and essential options present)
function order_sync_is_initialized() {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'order_sync_orders';
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$orders_table}'" ) === $orders_table;
    if ( ! $exists ) return false;
    // check for a few core options
    $portal = get_option( 'order_sync_portal_slug', '' );
    $products = get_option( 'order_sync_products', '' );
    if ( empty( $portal ) ) return false;
    if ( empty( $products ) ) return false;
    return true;
}

// Helper to get products configuration as an array regardless of storage format (JSON string or array)
function order_sync_get_products_config() {
    $raw = get_option( 'order_sync_products', '[]' );
    if ( is_array( $raw ) ) return $raw;
    $decoded = json_decode( $raw, true );
    if ( ! is_array( $decoded ) ) return array();
    return $decoded;
}

// If plugin is not initialized, show an onboarding modal to admins to walk through setup
add_action( 'admin_notices', 'order_sync_maybe_show_onboarding' );
function order_sync_maybe_show_onboarding() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Only show the onboarding wizard immediately after activation. If the activation transient
    // is not present, don't display the setup modal anywhere else.
    if ( ! get_transient( 'subsales_show_onboarding' ) ) return;

    // If a recent import/set action asked to suppress onboarding, skip showing it briefly
    if ( get_transient( 'subsales_suppress_onboarding' ) ) { delete_transient( 'subsales_suppress_onboarding' ); delete_transient( 'subsales_show_onboarding' ); return; }

    // Remove the one-time activation transient now that we'll show the wizard
    delete_transient( 'subsales_show_onboarding' );

    // If initialized already for some reason, don't show
    if ( order_sync_is_initialized() ) return;

    // Output a lightweight modal and stepper UI (kept minimal, progressive enhancement via JS)
    $nonce = wp_create_nonce( 'subsales_init_nonce' );
    ?>
    <div id="subsales-onboarding" style="position:fixed;left:0;top:0;right:0;bottom:0;z-index:99999;display:flex;align-items:center;justify-content:center;">
        <div style="background:#fff;border:1px solid #ddd;box-shadow:0 6px 24px rgba(0,0,0,.2);width:820px;max-width:96%;padding:18px;border-radius:8px;">
            <h2 style="margin-top:0">Subsales: Quick Setup</h2>
            <p>This setup wizard will create the required database tables, a portal page, and some default options so you can get started.</p>
            <div id="subsales-steps">
                <div data-step="1">
                    <h3>Branding</h3>
                    <label>Brand name<br/><input id="onb_branding" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'subsales_branding', 'Subsales' ) ); ?>" /></label>
                </div>
                <div data-step="2" style="margin-top:10px">
                    <h3>Portal & Maps</h3>
                    <label>Portal slug<br/><input id="onb_portal_slug" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'order_sync_portal_slug', 'subsales-portal' ) ); ?>" /></label>
                    <br/>
                    <label style="margin-top:8px">Google Maps API Key<br/><input id="onb_maps_key" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'order_sync_google_maps_api_key', '' ) ); ?>" /></label>
                    <p class="description">You can <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">create a key</a> if needed.</p>
                </div>
                <div data-step="3" style="margin-top:10px">
                    <h3>Products</h3>
                    <p>Quickly add up to 3 sample products or skip and configure later.</p>
                    <div id="onb_products">
                        <div><input class="onb_prod_name regular-text" placeholder="Product name" value="Turkey"/> <input class="onb_prod_price regular-text" placeholder="Price" value="20.00"/></div>
                        <div><input class="onb_prod_name regular-text" placeholder="Product name" value="Ham"/> <input class="onb_prod_price regular-text" placeholder="Price" value="18.00"/></div>
                        <div><input class="onb_prod_name regular-text" placeholder="Product name" value="Combo"/> <input class="onb_prod_price regular-text" placeholder="Price" value="35.00"/></div>
                    </div>
                </div>
                <div data-step="4" style="margin-top:10px">
                    <h3>Teams</h3>
                    <p>Create a sample team to allow quick logins from the mobile app.</p>
                    <label>Team name<br/><input id="onb_team_name" type="text" class="regular-text" value="Default Team" /></label>
                    <label style="margin-left:8px">Access code<br/><input id="onb_team_code" type="text" class="regular-text" value="changeme" /></label>
                </div>
                <div data-step="5" style="margin-top:10px">
                    <h3>Review</h3>
                    <div id="onb_review" style="background:#fafafa;border:1px solid #eee;padding:8px"></div>
                </div>
            </div>
            <div style="margin-top:12px;text-align:right">
                <button id="onb_cancel" class="button">Dismiss</button>
                <button id="onb_prev" class="button" disabled>Back</button>
                <button id="onb_next" class="button button-primary">Next</button>
            </div>
            <div id="onb_status" style="margin-top:12px;display:none"></div>
        </div>
    </div>
    <script>
    (function(){
        var step = 1; var max = 5;
        function showStep(){
            for(var i=1;i<=max;i++){ var el=document.querySelector('[data-step="'+i+'"]'); if(el) el.style.display=(i===step?'block':'none'); }
            document.getElementById('onb_prev').disabled = (step<=1);
            document.getElementById('onb_next').textContent = (step>=max?'Apply':'Next');
            if(step===4){ // populate review
                var review = document.getElementById('onb_review'); var html='';
                html += '<strong>Brand:</strong> '+document.getElementById('onb_branding').value+'<br/>';
                html += '<strong>Portal slug:</strong> '+document.getElementById('onb_portal_slug').value+'<br/>';
                html += '<strong>Maps key:</strong> '+(document.getElementById('onb_maps_key').value? 'provided':'(empty)')+'<br/>';
                var prods = document.querySelectorAll('.onb_prod_name'); html += '<strong>Products:</strong><ul>';
                prods.forEach(function(p,i){ if(p.value) html += '<li>'+p.value+' - $'+(document.querySelectorAll('.onb_prod_price')[i].value||'0')+'</li>'; }); html += '</ul>';
                review.innerHTML = html;
            }
        }
        showStep();
        document.getElementById('onb_next').addEventListener('click', function(){
            if(step<max){ step++; showStep(); } else {
                // Apply init via AJAX
                var fd = new FormData(); fd.append('action','subsales_run_init'); fd.append('nonce','<?php echo $nonce; ?>');
                fd.append('branding', document.getElementById('onb_branding').value );
                fd.append('portal_slug', document.getElementById('onb_portal_slug').value );
                fd.append('maps_key', document.getElementById('onb_maps_key').value );
                fd.append('team_name', document.getElementById('onb_team_name').value );
                fd.append('team_code', document.getElementById('onb_team_code').value );
                // products
                var names = document.querySelectorAll('.onb_prod_name'); var prices = document.querySelectorAll('.onb_prod_price');
                for(var i=0;i<names.length;i++){ fd.append('product_name[]', names[i].value); fd.append('product_price[]', prices[i].value); fd.append('product_visible[]', '1'); }
                var status = document.getElementById('onb_status'); status.style.display='block'; status.textContent='Initializing...';
                fetch(ajaxurl, { method:'POST', body: fd }).then(function(r){ return r.json(); }).then(function(j){ if(!j || !j.success){ status.textContent='Error: '+JSON.stringify(j&&j.data?j.data:'unknown'); } else { status.textContent='Initialization complete'; setTimeout(function(){ document.getElementById('subsales-onboarding').style.display='none'; location.reload(); },800); } }).catch(function(e){ status.textContent='Fetch error: '+e.message; });
            }
        });
        document.getElementById('onb_prev').addEventListener('click', function(){ if(step>1){ step--; showStep(); } });
        document.getElementById('onb_cancel').addEventListener('click', function(){ document.getElementById('subsales-onboarding').style.display='none'; });
    })();
    </script>
    <?php
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

// Add Address Extracts admin page for ZIP-based extracts
add_action( 'admin_menu', 'order_sync_zip_extracts_menu' );
function order_sync_zip_extracts_menu() {
    add_submenu_page(
        'subsales-management',
        'Address Extracts',
        'Address Extracts',
        'manage_options',
        'subsales-zip-extracts',
        'order_sync_zip_extracts_page'
    );
}

// Enqueue admin scripts for ZIP extracts page
add_action( 'admin_enqueue_scripts', 'order_sync_zip_admin_assets' );
function order_sync_zip_admin_assets( $hook ) {
    if ( strpos( $hook, 'subsales-zip-extracts' ) === false ) return;
    wp_enqueue_script( 'subsales-zip-admin', SUBSALES_PLUGIN_URL . 'assets/js/subsales-zip-admin.js', array( 'jquery' ), SUBSALES_VERSION, true );
    wp_localize_script( 'subsales-zip-admin', 'SubsalesZipAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'subsales_zip_generate' ),
    ) );
}

// Admin page: list of served ZIPs and generator controls
function order_sync_zip_extracts_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    // Handle form save
    if ( isset( $_POST['subsales_zip_list'] ) && check_admin_referer( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ) ) {
        $raw = sanitize_text_field( wp_unslash( $_POST['subsales_zip_list'] ) );
        // Normalize to array of 5-digit strings
        $parts = preg_split( '/[\,\s]+/', $raw );
        $zips = array();
        foreach ( $parts as $p ) {
            $pz = preg_replace( '/[^0-9]/', '', $p );
            if ( strlen( $pz ) === 5 ) $zips[] = $pz;
        }
        update_option( 'subsales_served_zips', $zips );
        echo '<div class="updated"><p>ZIP list saved.</p></div>';
    }

    $saved = get_option( 'subsales_served_zips', array() );
    $zip_text = implode( ',', $saved );
    ?>
    <div class="wrap">
        <h1>Address Extracts</h1>
        <form method="POST">
            <?php wp_nonce_field( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ); ?>
            <table class="form-table"><tr><th scope="row">Served ZIP codes</th>
            <td>
                <textarea name="subsales_zip_list" rows="3" class="large-text code" placeholder="e.g. 01234,02115,10001"><?php echo esc_textarea( $zip_text ); ?></textarea>
                <p class="description">Enter comma-separated 5-digit ZIP codes the organization serves. After saving, click Generate to create per-ZIP JSON extracts.</p>
            </td></tr></table>
            <p class="submit"><button class="button button-primary" type="submit">Save ZIP list</button>
            <button id="subsales-generate-btn" class="button">Generate extracts</button></p>
        </form>

        <h2>Existing Extracts</h2>
        <div id="subsales-zip-status">
            <?php
            // list files in uploads/subsales-zipdata
            $upload = wp_upload_dir(); $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
            if ( is_dir( $base ) ) {
                $files = scandir( $base );
                echo '<ul>';
                foreach ( $files as $f ) {
                    if ( in_array( $f, array( '.', '..' ) ) ) continue;
                    $path = $base . '/' . $f; if ( is_file( $path ) ) {
                        $url = trailingslashit( $upload['baseurl'] ) . 'subsales-zipdata/' . rawurlencode( $f );
                        $size = size_format( filesize( $path ) );
                        echo '<li><a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $f ) . '</a> &nbsp;(' . esc_html( $size ) . ')</li>';
                    }
                }
                echo '</ul>';
            } else {
                echo '<p>No extracts present yet.</p>';
            }
            ?>
        </div>
        <div id="subsales-generate-log" style="margin-top:12px;white-space:pre-wrap;background:#f8f8f8;border:1px solid #eaeaea;padding:8px;display:none"></div>
    </div>
    <?php
}

// AJAX handler to generate per-ZIP extracts using Overpass (OpenStreetMap)
add_action( 'wp_ajax_subsales_generate_zip_extracts', 'subsales_generate_zip_extracts' );
function subsales_generate_zip_extracts() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );
    check_ajax_referer( 'subsales_zip_generate', 'nonce' );

    $zips = get_option( 'subsales_served_zips', array() );
    if ( ! is_array( $zips ) || empty( $zips ) ) {
        wp_send_json_error( 'No ZIPs configured' );
    }

    $upload = wp_upload_dir(); $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    if ( ! is_dir( $base ) ) wp_mkdir_p( $base );

    $results = array();
    foreach ( $zips as $zip ) {
        $res = subsales_generate_zip_from_overpass( $zip, $base );
        $results[ $zip ] = $res;
    }

    wp_send_json_success( $results );
}

// Helper: generate a single ZIP file by querying Overpass API for nodes/ways with addr:postcode and addr:housenumber
function subsales_generate_zip_from_overpass( $zip, $base_dir ) {
    $zip = preg_replace( '/[^0-9]/', '', (string) $zip );
    if ( strlen( $zip ) !== 5 ) return array( 'error' => 'invalid_zip' );

    // Overpass QL: find nodes and ways with addr:postcode=<zip> and addr:housenumber
    $ql = '[out:json][timeout:25];(node["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];way["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];relation["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];);out center;';

    $overpass_url = 'https://overpass-api.de/api/interpreter';
    $args = array(
        'body' => $ql,
        'timeout' => 60,
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ),
    );
    $resp = wp_remote_post( $overpass_url, $args );
    if ( is_wp_error( $resp ) ) return array( 'error' => 'overpass_error', 'message' => $resp->get_error_message() );
    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code !== 200 ) return array( 'error' => 'overpass_status', 'status' => $code );

    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    if ( ! $json || ! isset( $json['elements'] ) ) return array( 'error' => 'no_elements' );

    $out = array(); $seen = array();
    foreach ( $json['elements'] as $el ) {
        $tags = isset( $el['tags'] ) ? $el['tags'] : array();
        if ( empty( $tags['addr:housenumber'] ) || empty( $tags['addr:street'] ) ) continue;
        $label_parts = array();
        $label_parts[] = $tags['addr:housenumber'] . ' ' . $tags['addr:street'];
        if ( ! empty( $tags['addr:city'] ) ) $label_parts[] = $tags['addr:city'];
        if ( ! empty( $tags['addr:state'] ) ) $label_parts[] = $tags['addr:state'];
        $label_parts[] = $zip;
        $label = implode( ', ', $label_parts );

        $lat = isset( $el['lat'] ) ? $el['lat'] : ( isset( $el['center']['lat'] ) ? $el['center']['lat'] : null );
        $lon = isset( $el['lon'] ) ? $el['lon'] : ( isset( $el['center']['lon'] ) ? $el['center']['lon'] : null );

        $k = md5( $label ); if ( isset( $seen[ $k ] ) ) continue; $seen[ $k ] = true;

        $out[] = array( 'id' => 'osm-' . $el['type'] . '-' . $el['id'], 'label' => $label, 'street' => $tags['addr:street'], 'city' => (isset($tags['addr:city'])?$tags['addr:city']:''), 'state' => (isset($tags['addr:state'])?$tags['addr:state']:''), 'zip' => $zip, 'lat' => $lat, 'lng' => $lon );
    }

    $file = trailingslashit( $base_dir ) . $zip . '.json';
    $written = file_put_contents( $file, wp_json_encode( $out ) );
    if ( $written === false ) return array( 'error' => 'write_failed' );
    return array( 'count' => count( $out ), 'file' => $file );
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
    
    // Expose config publicly so the PWA shell can fetch branding/variant without authentication.
    // Sensitive items (like google_maps_api_key) will only be returned when valid team headers are present.
    register_rest_route( 'order-manager/v1', '/config', array(
        'methods' => 'GET',
        'callback' => 'get_app_config',
        'permission_callback' => '__return_true',
    ));

    // Return team members for authenticated team (reads X-Team-Name/X-Access-Code headers)
    register_rest_route( 'order-manager/v1', '/teams/members', array(
        'methods' => 'GET',
        'callback' => 'order_sync_get_team_members_endpoint',
        'permission_callback' => 'order_sync_check_permissions',
    ));
});

// AJAX endpoint for admin orders filtering/pagination
add_action( 'wp_ajax_subsales_fetch_orders', 'order_sync_fetch_orders_ajax' );
function order_sync_fetch_orders_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }

    check_ajax_referer( 'subsales_orders_nonce', 'nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';

    $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
    $end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
    $filter_team = isset( $_POST['team_id'] ) ? intval( $_POST['team_id'] ) : 0;
    $filter_member = isset( $_POST['entered_by_id'] ) ? sanitize_text_field( $_POST['entered_by_id'] ) : '';
    $payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : '';
    $page = isset( $_POST['page'] ) ? max(1,intval($_POST['page'])) : 1;
    $page_size = isset( $_POST['page_size'] ) ? intval( $_POST['page_size'] ) : 100;
    if ( $page_size <= 0 || $page_size > 100 ) $page_size = 100;

    $where = array();
    $params = array();

    if ( ! empty( $start_date ) ) {
        $where[] = "created_at >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if ( ! empty( $end_date ) ) {
        $where[] = "created_at <= %s";
        $params[] = $end_date . ' 23:59:59';
    }
    if ( $filter_team ) {
        $where[] = "team_id = %d";
        $params[] = $filter_team;
    }
    if ( ! empty( $filter_member ) ) {
        $where[] = "user_id = %s";
        $params[] = $filter_member;
    }

    // Improved payment filtering: try JSON_EXTRACT when available, otherwise fallback to LIKE
    $use_json = false;
    try {
        $res = $wpdb->get_results( "SELECT JSON_EXTRACT('{\"a\":1}','$.a') as jtest LIMIT 1" );
        if ( $res !== null ) $use_json = true;
    } catch ( Exception $e ) {
        $use_json = false;
    }

    if ( ! empty( $payment_method ) ) {
        if ( $use_json ) {
            if ( $payment_method === 'cash' ) {
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.paymentMethod')) = %s";
                $params[] = 'cash';
            } elseif ( $payment_method === 'check' ) {
                $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.paymentMethod')) = %s OR JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.checkNumber')) IS NOT NULL)";
                $params[] = 'check';
            }
        } else {
            if ( $payment_method === 'cash' ) {
                $where[] = "order_data LIKE %s";
                $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"cash"%';
            } elseif ( $payment_method === 'check' ) {
                $where[] = "(order_data LIKE %s OR order_data LIKE %s)";
                $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"check"%';
                $params[] = '%' . $wpdb->esc_like( '"checkNumber"' ) . '%';
            }
        }
    }

    $where_sql = '';
    if ( ! empty( $where ) ) $where_sql = 'WHERE ' . implode( ' AND ', $where );

    // total count for pagination - prepare if we have params
    if ( ! empty( $params ) ) {
        $count_prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( "SELECT COUNT(*) FROM {$table} {$where_sql}" ), $params ) );
        $count = intval( $wpdb->get_var( $count_prepared ) );
    } else {
        $count = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" ) );
    }

    $pages = max(1, ceil( $count / $page_size ));
    if ( $page > $pages ) $page = $pages;
    $offset = ( $page - 1 ) * $page_size;

    // fetch page
    $select_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $select_args = array_merge( array( $select_sql ), $params );
    $select_args[] = $page_size; $select_args[] = $offset;
    $prepared = call_user_func_array( array( $wpdb, 'prepare' ), $select_args );
    $rows = $wpdb->get_results( $prepared, ARRAY_A );

    $orders = array();
    $totals = array( 'cash' => 0.0, 'check' => 0.0, 'grand' => 0.0, 'product_totals' => array() );
    // initialize product totals for the page
    foreach ( $configured_products as $pconf ) {
        $totals['product_totals'][ $pconf['id'] ] = 0;
    }
    // Load configured products once for building products_map. Normalize whether option is stored as array or JSON string.
    $configured_products = order_sync_get_products_config();

    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) $od = array();

        $order_total = 0.0;
        $items_arr = array();
        // initialize products map with zeros for configured products
        $products_map = array();
        foreach ( $configured_products as $pconf ) {
            $products_map[ $pconf['id'] ] = 0;
        }

        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $price = isset( $pr['price'] ) ? floatval( $pr['price'] ) : 0.0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                $name = isset( $pr['name'] ) ? $pr['name'] : ( $pid ? $pid : 'item' );
                if ( $qty > 0 ) {
                    $items_arr[] = $name . ' × ' . $qty;
                    $order_total += $qty * $price;
                    if ( $pid ) {
                        if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                        $products_map[ $pid ] += $qty;
                    }
                }
            }
        } else {
            // fallback: legacy qty fields -> populate products_map based on configured products
            if ( is_array( $configured_products ) ) {
                foreach ( $configured_products as $p ) {
                    $pid = $p['id'];
                    $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                    foreach ( $labels as $k ) {
                        if ( isset( $od[ $k ] ) ) {
                            $q = intval( $od[ $k ] );
                            if ( $q > 0 ) {
                                $items_arr[] = $p['name'] . ' × ' . $q;
                                $price = isset( $p['price'] ) ? floatval( $p['price'] ) : 0.0;
                                $order_total += $q * $price;
                                if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                                $products_map[ $pid ] += $q;
                            }
                            break;
                        }
                    }
                }
            }
        }
        if ( isset( $od['donationAmount'] ) ) $order_total += floatval( $od['donationAmount'] );

        $payment = '';
        if ( isset( $od['paymentMethod'] ) && ! empty( $od['paymentMethod'] ) ) $payment = $od['paymentMethod'];
        else if ( ! empty( $od['checkNumber'] ) ) $payment = 'check';
        else if ( ! empty( $od['payCash'] ) || ! empty( $od['pay_cash'] ) ) $payment = 'cash';

        if ( strtolower( $payment ) === 'check' ) $totals['check'] += $order_total;
        elseif ( strtolower( $payment ) === 'cash' ) $totals['cash'] += $order_total;
        $totals['grand'] += $order_total;

            // add to page product totals
            foreach ( $products_map as $pid => $qty ) {
                if ( isset( $totals['product_totals'][ $pid ] ) ) {
                    $totals['product_totals'][ $pid ] += intval( $qty );
                } else {
                    $totals['product_totals'][ $pid ] = intval( $qty );
                }
            }

        $team_name = '';
        if ( ! empty( $r['team_id'] ) ) {
            $t = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}order_sync_teams WHERE id = %d", intval( $r['team_id'] ) ) );
            $team_name = $t ? $t->name : '';
        }

        $entered_by_name = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : '';
        $orders[] = array(
            'id' => isset( $r['id'] ) ? intval( $r['id'] ) : null,
            'order_id' => $r['order_id'],
            'created_at' => $r['created_at'],
            'created_at_formatted' => date( 'M j, Y g:i A', strtotime( $r['created_at'] ) ),
            'user_id' => $r['user_id'],
            'entered_by_name' => $entered_by_name,
            'team_name' => $team_name,
            'items' => implode( ', ', $items_arr ),
            'order_total' => round( $order_total, 2 ),
            'payment' => $payment,
            'payment_display' => $payment ? ucfirst($payment) : '',
            // map of configured product id => quantity for this order
            'products_map' => $products_map
        );
    }

    $response = array(
        'orders' => $orders,
        'totals' => $totals,
        'total_count' => $count,
        'page' => $page,
        'pages' => $pages,
        'page_size' => $page_size,
    );

    wp_send_json_success( $response );
}

// AJAX endpoint to run migration helper from the admin UI (runs under current user, requires manage_options)
/* Migration AJAX handler removed per request. Migration tools (if any) should be removed separately. */

// AJAX endpoint to run initialization from the onboarding wizard
add_action( 'wp_ajax_subsales_run_init', 'order_sync_run_init_ajax' );
function order_sync_run_init_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'subsales_init_nonce', 'nonce' );

    global $wpdb;

    // Basic inputs
    $branding = isset( $_POST['branding'] ) ? sanitize_text_field( $_POST['branding'] ) : '';
    $portal_slug = isset( $_POST['portal_slug'] ) ? sanitize_text_field( $_POST['portal_slug'] ) : 'subsales-portal';
    $maps_key = isset( $_POST['maps_key'] ) ? sanitize_text_field( $_POST['maps_key'] ) : '';

    // products arrays
    $product_names = isset( $_POST['product_name'] ) ? (array) $_POST['product_name'] : array();
    $product_prices = isset( $_POST['product_price'] ) ? (array) $_POST['product_price'] : array();
    $product_vis = isset( $_POST['product_visible'] ) ? (array) $_POST['product_visible'] : array();

    // team
    $team_name = isset( $_POST['team_name'] ) ? sanitize_text_field( $_POST['team_name'] ) : '';
    $team_code = isset( $_POST['team_code'] ) ? sanitize_text_field( $_POST['team_code'] ) : '';

    // Create DB tables
    try {
        order_sync_create_table();
    } catch ( Exception $e ) {
        // non-fatal
    }

    // Save basic options
    if ( ! empty( $branding ) ) update_option( 'subsales_branding', $branding );
    update_option( 'order_sync_portal_slug', $portal_slug );
    if ( ! empty( $maps_key ) ) update_option( 'order_sync_google_maps_api_key', $maps_key );

    // Products
    $products = array();
    for ( $i = 0; $i < min( 10, count( $product_names ) ); $i++ ) {
        $name = sanitize_text_field( $product_names[ $i ] );
        if ( empty( $name ) ) continue;
        $price_raw = isset( $product_prices[ $i ] ) ? $product_prices[ $i ] : '0';
        $price = floatval( preg_replace( '/[^0-9.\-]/', '', $price_raw ) );
        $id = sanitize_title( $name ); if ( empty( $id ) ) $id = 'p' . time() . $i;
        $visible = in_array( (string) $i, $product_vis, true ) || in_array( $id, $product_vis, true ) || true;
        $products[] = array( 'id' => $id, 'name' => $name, 'price' => number_format( $price, 2, '.', '' ), 'visible' => $visible ? 1 : 0 );
    }
    if ( ! empty( $products ) ) update_option( 'order_sync_products', wp_json_encode( $products ) );

    // Ensure portal page
    order_sync_ensure_pwa_page( $portal_slug );

    // Create sample team if requested
    if ( ! empty( $team_name ) && ! empty( $team_code ) ) {
        // If team exists skip
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}order_sync_teams WHERE name = %s", $team_name ), ARRAY_A );
        if ( ! $existing ) {
            order_sync_add_team( $team_name, $team_code, 'Created by setup wizard' );
        }
    }

    wp_send_json_success( array( 'message' => 'Initialization completed' ) );
}

// Admin-post handler: export orders CSV
add_action( 'admin_post_subsales_export_orders', 'order_sync_admin_export_orders' );
function order_sync_admin_export_orders() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';
    $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=subsales-orders-' . date('Ymd_His') . '.csv' );
    $out = fopen( 'php://output', 'w' );
    // header
    fputcsv( $out, array( 'id','order_id','user_id','team_id','order_data','sync_status','created_at','updated_at' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, array( $r['id'], $r['order_id'], $r['user_id'], $r['team_id'], $r['order_data'], $r['sync_status'], $r['created_at'], $r['updated_at'] ) );
    }
    fclose( $out );
    exit;
}

// Admin-post handler: export settings CSV (key,value)
add_action( 'admin_post_subsales_export_settings', 'order_sync_admin_export_settings' );
function order_sync_admin_export_settings() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );
    $keys = array(
        'order_sync_portal_slug','order_sync_google_maps_api_key','subsales_branding','order_sync_products','order_sync_primary_color','order_sync_style_variant'
    );
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=subsales-settings-' . date('Ymd_His') . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'option_key','option_value' ) );
    foreach ( $keys as $k ) {
        $v = get_option( $k );
        if ( is_array( $v ) || is_object( $v ) ) $v = wp_json_encode( $v );
        fputcsv( $out, array( $k, $v ) );
    }
    fclose( $out );
    exit;
}

// Admin-post handler: export combined backup (orders + settings) as a ZIP
add_action( 'admin_post_subsales_export_backup_combined', 'order_sync_admin_export_backup_combined' );
function order_sync_admin_export_backup_combined() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );

    global $wpdb;
    // Build orders CSV in-memory
    $orders_table = $wpdb->prefix . 'order_sync_orders';
    $rows = $wpdb->get_results( "SELECT * FROM {$orders_table} ORDER BY created_at DESC", ARRAY_A );
    $out = fopen('php://temp', 'r+');
    fputcsv( $out, array( 'id','order_id','user_id','team_id','order_data','sync_status','created_at','updated_at' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, array( $r['id'], $r['order_id'], $r['user_id'], $r['team_id'], $r['order_data'], $r['sync_status'], $r['created_at'], $r['updated_at'] ) );
    }
    rewind( $out );
    $orders_csv = stream_get_contents( $out );
    fclose( $out );

    // Build settings CSV in-memory
    $keys = array(
        'order_sync_portal_slug','order_sync_google_maps_api_key','subsales_branding','order_sync_products','order_sync_primary_color','order_sync_style_variant'
    );
    $out2 = fopen('php://temp', 'r+');
    fputcsv( $out2, array( 'option_key','option_value' ) );
    foreach ( $keys as $k ) {
        $v = get_option( $k );
        if ( is_array( $v ) || is_object( $v ) ) $v = wp_json_encode( $v );
        fputcsv( $out2, array( $k, $v ) );
    }
    rewind( $out2 );
    $settings_csv = stream_get_contents( $out2 );
    fclose( $out2 );

    // Create ZIP in temp file
    $zipname = sys_get_temp_dir() . '/subsales-backup-' . time() . '.zip';
    $za = new ZipArchive();
    if ( $za->open( $zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        wp_die( 'Could not create zip' );
    }
    $za->addFromString( 'orders.csv', $orders_csv );
    $za->addFromString( 'settings.csv', $settings_csv );
    $za->close();

    // Send file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename=subsales-backup-' . date('Ymd_His') . '.zip');
    header('Content-Length: ' . filesize( $zipname ) );
    readfile( $zipname );
    @unlink( $zipname );
    exit;
}

// Admin-post handler: import backup CSV
add_action( 'admin_post_subsales_import_backup', 'order_sync_admin_import_backup' );
function order_sync_admin_import_backup() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_import_nonce' );
    if ( ! isset( $_FILES['backup_file'] ) || ! is_uploaded_file( $_FILES['backup_file']['tmp_name'] ) ) {
        wp_redirect( add_query_arg( 'subsales_import_error', 'nofile', wp_get_referer() ) ); exit;
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $update_existing = isset( $_POST['import_update_existing'] ) && intval( $_POST['import_update_existing'] ) === 1;

    $result = order_sync_process_import_file( $tmp, $update_existing );

    $msg = sprintf( 'Imported=%d, Updated=%d, Skipped=%d', $result['imported'], $result['updated'], $result['skipped'] );
    // Suppress onboarding modal on the immediate redirect after an import/restore
    set_transient( 'subsales_suppress_onboarding', true, 30 );
    wp_redirect( add_query_arg( 'subsales_import_result', rawurlencode($msg), wp_get_referer() ) ); exit;
}

// Reusable import processor: accepts a path to uploaded file (tmp) and returns totals/errors
function order_sync_process_import_file( $tmp, $update_existing = false ) {
    $total_imported = 0; $total_updated = 0; $total_skipped = 0; $total_errors = array();

    // Helper to process a CSV file path. Returns array(imported, updated, skipped, errors)
    $process_csv = function( $filepath, $update_existing ) {
        $imported = 0; $updated = 0; $skipped = 0; $errors = array();
        if ( ! file_exists( $filepath ) ) return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('file_missing') );
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('openfail') );
        $header = fgetcsv( $handle );
        if ( ! $header ) { fclose($handle); return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('invalid') ); }
        $lower = array_map('strtolower',$header);
        global $wpdb;
        $orders_table = $wpdb->prefix . 'order_sync_orders';

        if ( in_array( 'order_id', $lower ) ) {
            $map = array(); foreach ( $header as $i => $h ) $map[strtolower($h)] = $i;
            while ( ($row = fgetcsv( $handle )) !== false ) {
                $order_id = isset( $map['order_id'] ) ? $row[$map['order_id']] : '';
                if ( empty( $order_id ) ) { $skipped++; continue; }
                $user_id = isset( $map['user_id'] ) ? $row[$map['user_id']] : '';
                $team_id = isset( $map['team_id'] ) ? intval( $row[$map['team_id']] ) : 0;
                $order_data = isset( $map['order_data'] ) ? $row[$map['order_data']] : '{}';
                $sync_status = isset( $map['sync_status'] ) ? $row[$map['sync_status']] : 'synced';
                $created_at = isset( $map['created_at'] ) ? $row[$map['created_at']] : current_time('mysql');
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$orders_table} WHERE order_id = %s", $order_id ) );
                if ( $existing ) {
                    if ( $update_existing ) {
                        $res = $wpdb->update( $orders_table, array( 'user_id'=>$user_id,'team_id'=>$team_id,'order_data'=>$order_data,'sync_status'=>$sync_status,'updated_at'=>current_time('mysql') ), array( 'order_id'=>$order_id ), array('%s','%d','%s','%s','%s'), array('%s') );
                        if ( $res !== false ) $updated++; else $skipped++;
                    } else { $skipped++; }
                } else {
                    $ins = $wpdb->insert( $orders_table, array( 'order_id'=>$order_id,'user_id'=>$user_id,'team_id'=>$team_id,'order_data'=>$order_data,'sync_status'=>$sync_status,'created_at'=>$created_at ), array('%s','%s','%d','%s','%s','%s') );
                    if ( $ins !== false ) $imported++; else $skipped++;
                }
            }
        } else if ( in_array( 'option_key', $lower ) || in_array( 'key', $lower ) ) {
            $map = array(); foreach ( $header as $i => $h ) $map[strtolower($h)] = $i;
            while ( ($row = fgetcsv( $handle )) !== false ) {
                $key = isset( $map['option_key'] ) ? $row[$map['option_key']] : ( isset($map['key']) ? $row[$map['key']] : '' );
                $val = isset( $map['option_value'] ) ? $row[$map['option_value']] : ( isset($map['value']) ? $row[$map['value']] : '' );
                if ( empty( $key ) ) { $skipped++; continue; }
                $maybe = json_decode( $val, true );
                if ( json_last_error() === JSON_ERROR_NONE ) $val = $maybe;
                update_option( $key, $val );
                $imported++;
            }
        } else {
            fclose($handle); return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('unknownformat') );
        }

        fclose( $handle );
        return array( 'imported'=>$imported,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors );
    };

    // If the uploaded file is a zip, extract and process contained CSVs
    $is_zip = false;
    if ( class_exists( 'ZipArchive' ) ) {
        $za = new ZipArchive();
        if ( $za->open( $tmp ) === true ) { $is_zip = true; $za->close(); }
    }

    if ( $is_zip ) {
        $tmpdir = wp_tempnam();
        if ( ! $tmpdir ) $tmpdir = sys_get_temp_dir() . '/' . uniqid('subsales_');
        if ( ! file_exists( $tmpdir ) ) wp_mkdir_p( $tmpdir );
        $za = new ZipArchive();
        if ( $za->open( $tmp ) === true ) {
            for ( $i = 0; $i < $za->numFiles; $i++ ) {
                $name = $za->getNameIndex( $i );
                if ( preg_match('/\.(csv)$/i', $name) ) {
                    $outpath = $tmpdir . '/' . basename( $name );
                    copy( 'zip://' . $tmp . '#' . $name, $outpath );
                    $res = $process_csv( $outpath, $update_existing );
                    $total_imported += $res['imported'];
                    $total_updated += $res['updated'];
                    $total_skipped += $res['skipped'];
                    if ( ! empty( $res['errors'] ) ) $total_errors = array_merge( $total_errors, $res['errors'] );
                }
            }
            $za->close();
        }
    } else {
        $res = $process_csv( $tmp, $update_existing );
        $total_imported += $res['imported'];
        $total_updated += $res['updated'];
        $total_skipped += $res['skipped'];
        if ( ! empty( $res['errors'] ) ) $total_errors = array_merge( $total_errors, $res['errors'] );
    }

    return array( 'imported'=>$total_imported, 'updated'=>$total_updated, 'skipped'=>$total_skipped, 'errors'=>$total_errors );
}

// Admin-post handler: destructive restore (clear plugin data then import uploaded CSV/ZIP)
add_action( 'admin_post_subsales_restore_and_import', 'order_sync_admin_restore_and_import' );
function order_sync_admin_restore_and_import() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_restore_nonce' );
    if ( ! isset( $_FILES['backup_file'] ) || ! is_uploaded_file( $_FILES['backup_file']['tmp_name'] ) ) {
        wp_redirect( add_query_arg( 'subsales_import_error', 'nofile', wp_get_referer() ) ); exit;
    }

    // Determine clear scope: orders, settings, or both (default: both)
    $restore_target = isset( $_POST['restore_target'] ) ? sanitize_text_field( $_POST['restore_target'] ) : 'both';
    if ( $restore_target === 'both' ) {
        if ( function_exists( 'order_sync_clear_data' ) ) order_sync_clear_data();
    } else if ( $restore_target === 'orders' ) {
        if ( function_exists( 'order_sync_clear_orders' ) ) order_sync_clear_orders();
    } else if ( $restore_target === 'settings' ) {
        if ( function_exists( 'order_sync_clear_settings' ) ) order_sync_clear_settings();
    } else {
        // Fallback to full clear when unknown value provided
        if ( function_exists( 'order_sync_clear_data' ) ) order_sync_clear_data();
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    // For restore we always want to import everything; update_existing doesn't make sense after clear
    $result = order_sync_process_import_file( $tmp, true );

    $msg = sprintf( 'Restored: Imported=%d, Updated=%d, Skipped=%d', $result['imported'], $result['updated'], $result['skipped'] );
    // Suppress onboarding modal on the immediate redirect after a restore
    set_transient( 'subsales_suppress_onboarding', true, 30 );
    wp_redirect( add_query_arg( 'subsales_import_result', rawurlencode($msg), wp_get_referer() ) ); exit;
}

// AJAX endpoint to validate a Google Maps API key from admin UI
add_action( 'wp_ajax_subsales_test_maps_key', 'order_sync_test_maps_key_ajax' );
function order_sync_test_maps_key_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'subsales_test_maps_key', 'nonce' );

    $key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';
    if ( empty( $key ) ) {
        // fall back to stored option
        $key = get_option( 'order_sync_google_maps_api_key', '' );
    }
    if ( empty( $key ) ) {
        wp_send_json_error( 'No API key provided' );
    }

    // Perform a lightweight Geocoding API request to validate the key
    $test_address = '1600 Amphitheatre Parkway, Mountain View, CA';
    $url = add_query_arg( array( 'address' => urlencode( $test_address ), 'key' => $key ), 'https://maps.googleapis.com/maps/api/geocode/json' );
    // Use wp_remote_get with a short timeout
    $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( 'Request error: ' . $resp->get_error_message() );
    }
    $code = wp_remote_retrieve_response_code( $resp );
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    if ( ! is_array( $json ) ) {
        wp_send_json_error( 'Invalid response from Google Maps API (HTTP ' . $code . ')' );
    }
    // Interpret response
    $status = isset( $json['status'] ) ? $json['status'] : 'UNKNOWN';
    $message = '';
    if ( isset( $json['error_message'] ) ) $message = $json['error_message'];
    if ( $status === 'OK' || $status === 'ZERO_RESULTS' ) {
        $first = isset( $json['results'][0]['formatted_address'] ) ? $json['results'][0]['formatted_address'] : '';
        wp_send_json_success( array( 'status' => $status, 'message' => $message, 'first_result' => $first, 'raw' => $json ) );
    }
    // Common errors: REQUEST_DENIED (invalid key/permissions), OVER_QUERY_LIMIT, etc.
    wp_send_json_error( array( 'status' => $status, 'message' => $message, 'raw' => $json ) );
}
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
    $entered_by_id = $request->get_param( 'entered_by_id' );
    
    if ( ! empty( $entered_by_id ) ) {
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE user_id = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $entered_by_id,
                $limit,
                $offset
            ),
            ARRAY_A
        );
    } else {
        $orders = $wpdb->get_results( 
            $wpdb->prepare( 
                "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d OFFSET %d", 
                $limit, 
                $offset 
            ),
            ARRAY_A
        );
    }
    
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
                $team = order_sync_get_team_by_credentials( sanitize_text_field( $team_name ), sanitize_text_field( $access_code ) );
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
        'sync_status' => 'synced'
    );
    $formats = array( '%s', '%s', '%s', '%s' );
    if ( $team_id !== null ) {
        $insert_row['team_id'] = $team_id;
        $formats[] = '%d';
    }

    $result = $wpdb->insert( $table_name, $insert_row, $formats );

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

    $update_fields = array(
        'order_data' => $order_data,
        'sync_status' => 'updated'
    );
    $update_formats = array( '%s', '%s' );
    if ( isset( $data['team_id'] ) ) {
        $update_fields['team_id'] = intval( $data['team_id'] );
        $update_formats[] = '%d';
    }

    $result = $wpdb->update(
        $table_name,
        $update_fields,
        array( 'order_id' => $order_id ),
        $update_formats,
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
    // Only expose sensitive values (like Google Maps key) when the request includes valid team headers
    $is_authenticated = false;
    try{
        $is_authenticated = order_sync_check_permissions( $request ) === true;
    }catch(Exception $e){ $is_authenticated = false; }

    $google_maps_api_key = $is_authenticated ? get_option( 'order_sync_google_maps_api_key', '' ) : '';
    $portal_slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    $portal_url = esc_url_raw( home_url( '/' . $portal_slug . '/' ) );

    $branding = get_option( 'subsales_branding', 'Subsales' );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

    $products = order_sync_get_products_config();

    return new WP_REST_Response( array(
        'google_maps_api_key' => $google_maps_api_key,
        'app_version' => SUBSALES_VERSION,
        'portal_url' => $portal_url,
        'brandName' => $branding,
        'brandingImage' => $header_image_url,
        'styleVariant' => get_option( 'order_sync_style_variant', 'default' ),
        'primaryColor' => get_option( 'order_sync_primary_color', '#2d6cdf' ),
        'authenticated' => $is_authenticated,
        'products' => $products
    ), 200 );
}

// REST callback to return team members for the authenticated team
function order_sync_get_team_members_endpoint( WP_REST_Request $request ) {
    $team_name = $request->get_header( 'X-Team-Name' );
    $access_code = $request->get_header( 'X-Access-Code' );
    if ( empty( $team_name ) || empty( $access_code ) ) {
        return new WP_REST_Response( array( 'error' => 'Missing team headers' ), 400 );
    }
    $team = order_sync_get_team_by_credentials( $team_name, $access_code );
    if ( ! $team ) {
        return new WP_REST_Response( array( 'error' => 'Invalid credentials' ), 401 );
    }
    $members = order_sync_get_team_members_by_team( $team['id'] );
    if ( ! $members ) $members = array();
    return new WP_REST_Response( $members, 200 );
}

/**
 * PWA shortcode and script registration (canonical names)
 */
function subsales_register_pwa_scripts() {
    wp_register_script( 'subsales-pwa-app', SUBSALES_PLUGIN_URL . 'pwa/app.js', array(), SUBSALES_VERSION, true );
    wp_register_style( 'subsales-pwa-style', SUBSALES_PLUGIN_URL . 'pwa/styles.css', array(), SUBSALES_VERSION );
    $portal_base = esc_url_raw( home_url( '/' . get_option( 'order_sync_portal_slug', 'subsales-portal' ) . '/' ) );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

    $settings = array(
        'apiBase' => esc_url_raw( rest_url( 'order-manager/v1' ) ),
        'pluginBase' => SUBSALES_PLUGIN_URL . 'pwa/',
        'portalBase' => $portal_base,
        'googleMapsApiKey' => get_option( 'order_sync_google_maps_api_key', '' ),
        'sessionDuration' => intval( get_option( 'order_sync_session_duration', 86400000 ) ),
        'styleVariant' => get_option( 'order_sync_style_variant', 'default' ),
        'primaryColor' => get_option( 'order_sync_primary_color', '#2d6cdf' ),
        'brandName' => get_option( 'subsales_branding', 'Subsales' ),
        'brandingImage' => $header_image_url
    );
    // Include configured products (global, not per-team). Stored as option 'order_sync_products'.
    $settings['products'] = order_sync_get_products_config();

    wp_localize_script( 'subsales-pwa-app', 'SUBSALES_PWA_CONFIG', $settings );
    // Enqueue style for PWA UI (also useful for shortcode pages)
    wp_enqueue_style( 'subsales-pwa-style' );
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
    <?php $subsales_primary = esc_attr( get_option( 'order_sync_primary_color', '#2d6cdf' ) ); $subsales_variant = esc_attr( get_option( 'order_sync_style_variant', 'default' ) ); ?>
    <div id="subsales-pwa-root" class="sm-variant-<?php echo $subsales_variant; ?>" style="--sm-primary: <?php echo $subsales_primary; ?>;">
            <style>/* Ensure auth-only controls are hidden server-side until client reveals them */
                .sm-auth-hidden{display:none!important;visibility:hidden!important}
                /* Force branding image visible in case theme defines a generic .hidden */
                .sm-brand-image.hidden, #brandHeaderImage.hidden { display:block!important; visibility:visible!important; opacity:1!important; max-width:40%!important; height:auto!important; }
            </style>
                .sm-auth-hidden{display:none!important;visibility:hidden!important}
            </style>
            <header class="sm-header" role="banner">
                <div class="sm-header-left"></div>
                <div class="sm-header-center">
                    <?php $header_image_id = intval( get_option( 'subsales_header_image', 0 ) ); $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : ''; ?>
                    <img id="brandHeaderImage" class="sm-brand-image" src="<?php echo esc_url( $header_image_url ); ?>" alt="<?php echo esc_attr( get_option( 'subsales_branding', 'Subsales' ) ); ?>" />
                    <h1 class="sm-brand-name"><?php echo esc_html( get_option( 'subsales_branding', 'Subsales' ) ); ?></h1>
                </div>
                <div class="sm-header-right">
                    <div id="headerStatus" class="sm-auth-hidden" title="Network status" aria-live="polite" aria-label="Network status"><span id="headerDot" class="sm-header-dot"></span><span id="headerStatusText">Offline</span></div>
                    <button id="forceSyncBtn" class="sm-btn sm-auth-hidden" title="Force sync offline orders">Sync now</button>
                    <button id="viewOnlineBtn" class="sm-btn sm-auth-hidden" style="margin-right:8px">View online orders</button>
                    <span id="installBox" class="hidden sm-auth-hidden"><button id="installBtn" class="sm-btn">Install App</button></span>
                    <button id="myOrdersBtn" class="sm-btn sm-auth-hidden">My orders</button>
                    <button id="eodBtn" class="sm-btn sm-auth-hidden">End of Day Tally</button>
                    <button id="logoutBtn" class="sm-btn sm-auth-hidden" title="Log out">Log out</button>
                    <button id="openInlayBtn" class="sm-btn hidden sm-auth-hidden">Queued Orders</button>
                </div>
            </header>

            <section id="loginSection" class="sm-login-section">
                <h2>Team Login</h2>
                <p>Sign in with your team name and access code. After login the PWA install prompt will be shown (if available).</p>
                <div class="row" style="margin-bottom:8px">
                    <img id="brandHeaderImage" class="sm-brand-image hidden" src="<?php echo esc_url( $header_image_url ); ?>" alt="<?php echo esc_attr( get_option( 'subsales_branding', 'Subsales' ) ); ?>" />
                    <div style="flex:1"></div>
                </div>
                <input id="teamName" placeholder="Team name" />
                <input id="teamCode" placeholder="Access code" />
                <!-- Team member selection and session duration are handled by the PWA client after login -->
                <div id="loginError" class="hidden" style="color:#c00;margin-top:8px"></div>
                <div class="btn-row"><button id="loginBtn" class="sm-btn">Login</button></div>
            </section>

            <section id="appSection" class="hidden sm-app-section" style="display:none">
                <div class="row">
                    <div class="col-2">
                        <h2>Create Order</h2>
                        <label>Customer name</label>
                        <input id="customerName" placeholder="Customer name" />
                        <label>Address</label>
                        <input id="address" placeholder="Address" />
                        <label>Cell number</label>
                        <input id="cellNumber" type="tel" inputmode="numeric" pattern="[0-9]*" placeholder="Cell number" />
                        <div class="row row-spaced">
                            <div class="col-2"><label>Turkey</label><input id="turkeyQty" type="number" min="0" placeholder="0" /></div>
                            <div class="col-2"><label>Ham</label><input id="hamQty" type="number" min="0" placeholder="0" /></div>
                            <div class="col-2"><label>Combo</label><input id="comboQty" type="number" min="0" placeholder="0" /></div>
                        </div>
                        <label>Donation amount (USD)</label>
                        <input id="donationAmount" type="number" min="0" step="0.01" placeholder="$0.00" />
                        <div class="order-total"><strong>Order total: $<span id="orderTotal">0.00</span></strong></div>
                        <div class="pay-options">
                            <label><input type="checkbox" id="payCheck" /> <span>Pay by check</span></label>
                            <label><input type="checkbox" id="payCash" /> <span>Pay by cash</span></label>
                        </div>
                        <div id="checkNumberRow" class="check-number-row hidden"><label>Check number</label><input id="checkNumber" placeholder="Check number" /></div>
                        <label>Notes</label>
                        <textarea id="notes" placeholder="Notes (optional)"></textarea>
                        <div class="btn-row"><button id="saveOrderBtn" class="sm-btn">Save Order</button></div>
                    </div>
                </div>
                <!-- Local orders removed from main view (displayed via My Orders modal/inlay) -->
                <div style="margin-top:12px; border-top:1px solid #eee; padding-top:8px">
                    <h3>Status</h3>
                    <div id="networkStatus">Offline</div>
                    <div id="syncStatus">Not synced</div>
                </div>
            </section>
        </div>
        <?php
    // Add a small script to set the page title and show branding if available in the localized config
    // Also add a robust inline fallback to hide the app section until the PWA client explicitly shows it
    echo "<script>try{const cfg=window.SUBSALES_PWA_CONFIG||{}; if(cfg.brandName){document.title=cfg.brandName+' — PWA'; const h=document.querySelector('#subsales-pwa-root h1'); if(h) h.textContent=cfg.brandName;} if(cfg.brandingImage){const img=document.getElementById('brandHeaderImage'); if(img){img.src=cfg.brandingImage; img.classList.remove('hidden');}} // robust fallback: hide app section until client shows it\n    (function(){ try{ var app=document.getElementById('appSection'); if(app){ app.style.display='none'; app.dataset.smFallbackHidden='1'; } var login=document.getElementById('loginSection'); if(login){ login.style.display='block'; } }catch(e){} })(); }catch(e){};</script>";
    return ob_get_clean();
}
add_shortcode( 'subsales_pwa', 'subsales_pwa_shortcode' );

// Clear only orders/teams/members tables (preserves options)
function order_sync_clear_orders() {
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
}

// Clear only plugin options/settings (preserves orders/tables)
function order_sync_clear_settings() {
    // Remove persisted plugin options so the plugin is fully reset
    $option_keys = array(
        'order_sync_pwa_page_id',
        'order_sync_google_maps_api_key',
        'order_sync_interval',
        'order_sync_portal_slug',
        'order_sync_products',
        'order_sync_session_duration',
        'order_sync_style_variant',
        'order_sync_primary_color',
        'subsales_branding',
        'subsales_delete_on_uninstall',
        'subsales_header_image'
    );

    foreach ( $option_keys as $ok ) {
        delete_option( $ok );
    }
}

// Backwards-compatible helper that clears both orders and settings
function order_sync_clear_data() {
    order_sync_clear_orders();
    order_sync_clear_settings();
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
add_action( 'template_redirect', 'subsales_serve_portal_assets', 1 );

// REST endpoint: nearby addresses by lat/lng + radius (meters)
add_action( 'rest_api_init', function(){
    register_rest_route( 'subsales/v1', '/nearby', array(
        'methods' => 'GET',
        'callback' => 'subsales_rest_nearby',
        'permission_callback' => '__return_true',
    ) );
} );

function subsales_rest_nearby( WP_REST_Request $req ){
    $lat = floatval( $req->get_param('lat') );
    $lng = floatval( $req->get_param('lng') );
    $radius = intval( $req->get_param('r') ?: $req->get_param('radius') ?: 500 ); // meters
    $max = intval( $req->get_param('max') ?: 50 );

    if( !$lat || !$lng ){
        return new WP_REST_Response( array('error'=>'missing lat/lng'), 400 );
    }

    $upload = wp_upload_dir();
    $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    if ( ! is_dir( $base ) ) return rest_ensure_response( array('results'=>array()) );

    // bounding box approximation
    $degLat = $radius / 111320.0; // ~ meters per degree lat
    $degLng = $radius / (111320.0 * max(0.000001, cos( deg2rad( $lat ) )) );
    $minLat = $lat - $degLat; $maxLat = $lat + $degLat;
    $minLng = $lng - $degLng; $maxLng = $lng + $degLng;

    $files = glob( $base . '/*.json' );
    $out = array();

    foreach( $files as $f ){
        if( !is_readable($f) ) continue;
        $json = @file_get_contents($f);
        if( !$json ) continue;
        $arr = json_decode($json, true);
        if( !is_array($arr) ) continue;
        foreach( $arr as $rec ){
            if( !isset($rec['lat']) || !isset($rec['lng']) ) continue;
            $rlat = floatval($rec['lat']); $rlng = floatval($rec['lng']);
            if( $rlat < $minLat || $rlat > $maxLat || $rlng < $minLng || $rlng > $maxLng ) continue;
            // compute haversine distance
            $d = subsales_haversine_distance( $lat, $lng, $rlat, $rlng );
            if( $d <= $radius ){
                $rec['_distance_m'] = round($d);
                $out[] = $rec;
                if( count($out) >= $max ) break 2;
            }
        }
    }

    // sort by distance
    usort( $out, function($a,$b){ return ($a['_distance_m'] ?? 0) - ($b['_distance_m'] ?? 0); } );

    return rest_ensure_response( array('results'=>$out) );
}

function subsales_haversine_distance( $lat1, $lon1, $lat2, $lon2 ){
    // returns distance in meters
    $R = 6371000; // earth radius meters
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlambda = deg2rad($lon2 - $lon1);
    $a = sin($dphi/2) * sin($dphi/2) + cos($phi1) * cos($phi2) * sin($dlambda/2) * sin($dlambda/2);
    $c = 2 * atan2( sqrt($a), sqrt(1-$a) );
    return $R * $c;
}
function subsales_serve_portal_assets() {
    $portal_slug = get_option( 'order_sync_portal_slug', '' ); if ( empty( $portal_slug ) ) return;
    $req_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    $portal_base = trim( parse_url( home_url( '/' . $portal_slug . '/' ), PHP_URL_PATH ), '/' );

    // Also serve manifest at the site root (/manifest.json) to handle cases where the browser requests it from /
    if ( $req_path === 'manifest.json' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/manifest.json';
        if ( file_exists( $file ) ) {
            $manifest_raw = file_get_contents( $file );
            $manifest = json_decode( $manifest_raw, true );
            if ( is_array( $manifest ) && isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ) {
                $plugin_base = SUBSALES_PLUGIN_URL . 'pwa/';
                foreach ( $manifest['icons'] as $i => $icon ) {
                    if ( isset( $icon['src'] ) ) {
                        if ( strpos( $icon['src'], '//' ) === false && strpos( $icon['src'], 'http' ) !== 0 ) {
                            $manifest['icons'][ $i ]['src'] = $plugin_base . ltrim( $icon['src'], '/' );
                        }
                    }
                }
            }
            header( 'Content-Type: application/json' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            http_response_code(200);
            echo wp_json_encode( $manifest );
            exit;
        }
    }

    if ( $req_path === $portal_base . '/service-worker.js' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/service-worker.js';
        if ( file_exists( $file ) ) {
            // Serve publicly with permissive caching and CORS so browsers can register the SW without auth issues
            header( 'Content-Type: application/javascript' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            readfile( $file );
            exit;
        }
    }

    if ( $req_path === $portal_base || $req_path === $portal_base . '/index.html' || $req_path === $portal_base . '/' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/index.html';
        if ( file_exists( $file ) ) {
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
            // Include configured products so portal bootstraps with current product list
            $settings['products'] = order_sync_get_products_config();

            $html = file_get_contents( $file );
            $inject = "<script>window.SUBSALES_PWA_CONFIG = " . wp_json_encode( $settings ) . ";</script>";
            $app_src = esc_url( $settings['pluginBase'] . 'app.js' );
            // Rewrite relative stylesheet hrefs to absolute plugin path to avoid portal-relative 404s
            $html = str_replace( 'href="styles.css"', 'href="' . esc_url( $settings['pluginBase'] . 'styles.css' ) . '"', $html );
            $new_html = str_replace( '<script src="app.js"></script>', $inject . "\n<script src=\"" . $app_src . "\"></script>", $html );
            // If replacement didn't find the exact marker (different spacing/paths), inject config before </head>
            if ( $new_html === $html ) {
                $pos = stripos( $html, '</head>' );
                if ( $pos !== false ) {
                    $new_html = substr_replace( $html, $inject . "\n<script src=\"" . $app_src . "\"></script>\n", $pos, 0 );
                } else {
                    // fallback: prepend to document
                    $new_html = $inject . "\n<script src=\"" . $app_src . "\"></script>\n" . $html;
                }
            }
            $html = $new_html;
            // Serve index publicly so the portal can be loaded without authentication
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Cache-Control: public, max-age=300' );
            header( 'Access-Control-Allow-Origin: *' );
            echo $html; exit;
        }
    }

    if ( $req_path === $portal_base . '/manifest.json' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/manifest.json';
        if ( file_exists( $file ) ) {
            // Read and rewrite icon URLs to absolute plugin paths so browsers fetch icons directly
            $manifest_raw = file_get_contents( $file );
            $manifest = json_decode( $manifest_raw, true );
            if ( is_array( $manifest ) && isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ) {
                $plugin_base = SUBSALES_PLUGIN_URL . 'pwa/';
                foreach ( $manifest['icons'] as $i => $icon ) {
                    if ( isset( $icon['src'] ) ) {
                        // If src is already absolute, leave it alone
                        if ( strpos( $icon['src'], '//' ) === false && strpos( $icon['src'], 'http' ) !== 0 ) {
                            $manifest['icons'][ $i ]['src'] = $plugin_base . ltrim( $icon['src'], '/' );
                        }
                    }
                }
            }
            header( 'Content-Type: application/json' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            // Explicit 200 in case other hooks attempted auth handling
            http_response_code(200);
            echo wp_json_encode( $manifest );
            exit;
        }
    }
    
    // Serve any other portal assets (icons, styles, images, etc.) from the pwa/ folder
    // More permissive: match any request containing '/icons/...'
    if ( preg_match('#/icons/(.+)$#', '/' . $req_path, $m) ) {
        $rel = 'icons/' . $m[1];
        $file = SUBSALES_PLUGIN_PATH . 'pwa/' . $rel;
        if ( file_exists( $file ) ) {
            $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
            switch ( $ext ) {
                case 'svg': $ct = 'image/svg+xml'; break;
                case 'png': $ct = 'image/png'; break;
                case 'jpg':
                case 'jpeg': $ct = 'image/jpeg'; break;
                case 'webp': $ct = 'image/webp'; break;
                case 'ico': $ct = 'image/x-icon'; break;
                case 'css': $ct = 'text/css'; break;
                case 'js': $ct = 'application/javascript'; break;
                case 'json': $ct = 'application/json'; break;
                default: $ct = 'application/octet-stream';
            }
            header( 'Content-Type: ' . $ct );
            header( 'Cache-Control: public, max-age=86400' );
            header( 'Access-Control-Allow-Origin: *' );
            readfile( $file );
            exit;
        }
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
        
        <?php
        // Enqueue admin dashboard CSS
        // The stylesheet is registered/enqueued via admin hook; ensure it's available on this page.
        ?>

        <div class="dashboard-widgets-wrap">
            <div class="metabox-holder subsales-dashboard-grid">
                <div class="subsales-top-row">
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-cart" aria-hidden="true"></span> Orders</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo intval( $order_count ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-groups" aria-hidden="true"></span> Teams</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo intval( $team_count ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-admin-users" aria-hidden="true"></span> Members</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo intval( $member_count ); ?></p>
                        </div>
                    </div>
                </div>
                
                <?php
                // Compute aggregate totals from configured products. New orders include a `products` array in order_data.
                $products_conf = order_sync_get_products_config();
                // initialize totals
                $product_totals = array();
                foreach ( $products_conf as $p ) {
                    $product_totals[ $p['id'] ] = 0;
                }
                // Scan orders and sum quantities from order_data.products if present
                $rows = $wpdb->get_results( "SELECT order_data FROM {$orders_table}", ARRAY_A );
                if ( $rows ) {
                    foreach ( $rows as $r ) {
                        $od = json_decode( $r['order_data'], true );
                        if ( is_array( $od ) ) {
                            // If new structured `products` array exists, prefer it
                            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                                foreach ( $od['products'] as $op ) {
                                    if ( isset( $op['id'] ) && isset( $op['qty'] ) ) {
                                        $pid = $op['id'];
                                        $qty = intval( $op['qty'] );
                                        if ( isset( $product_totals[ $pid ] ) ) $product_totals[ $pid ] += $qty;
                                    }
                                }
                            } else {
                                // fallback: try to match legacy qty fields (e.g., turkeyQty -> turkey) when product id equals the legacy key minus 'Qty'
                                foreach ( $products_conf as $p ) {
                                    $pid = $p['id'];
                                    // try pid + 'Qty' or pid + '_qty' variants
                                    $k1 = $pid . 'Qty'; $k2 = $pid . '_qty';
                                    if ( isset( $od[ $k1 ] ) ) $product_totals[ $pid ] += intval( $od[ $k1 ] );
                                    elseif ( isset( $od[ $k2 ] ) ) $product_totals[ $pid ] += intval( $od[ $k2 ] );
                                }
                            }
                        }
                    }
                }

                // Render a postbox for each configured product (visible or not)
                if ( ! empty( $products_conf ) ) {
                    echo '<div class="subsales-second-row">';
                    foreach ( $products_conf as $p ) {
                        $title = esc_html( $p['name'] );
                        $total = isset( $product_totals[ $p['id'] ] ) ? intval( $product_totals[ $p['id'] ] ) : 0;
                        ?>
                        <div class="postbox">
                            <div class="postbox-header"><h2><?php echo $title; ?></h2></div>
                            <div class="inside">
                                <p style="font-size: 36px; font-weight: bold; margin: 0; color: #0073aa; text-align: center;">
                                    <?php echo $total; ?>
                                </p>
                            </div>
                        </div>
                        <?php
                    }
                    echo '</div>';
                }
                ?>
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
// Enqueue admin styles for the plugin dashboard (register at global scope)
add_action('admin_enqueue_scripts', 'subsales_enqueue_admin_assets');
function subsales_enqueue_admin_assets($hook){
    // Only load on our plugin pages (basic guard: check for our page slug in $_GET)
    $load = false;
    if ( isset($_GET['page']) && strpos($_GET['page'], 'subsales') === 0 ) $load = true;
    if ( ! $load ) return;
    $css_path = plugin_dir_path(__FILE__) . 'assets/css/admin-dashboard.css';
    $css_url = plugin_dir_url( __FILE__ ) . 'assets/css/admin-dashboard.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'subsales-admin-dashboard', $css_url, array('dashicons'), filemtime( $css_path ) );
    }
}

// Admin settings page
function order_sync_settings_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle per-panel form submission
    if ( isset( $_POST['save_overall'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
        $sync_interval = isset( $_POST['sync_interval'] ) ? intval( $_POST['sync_interval'] ) : 300;
        $portal_slug = isset( $_POST['portal_slug'] ) ? sanitize_title( $_POST['portal_slug'] ) : 'subsales-portal';
        $session_duration = isset( $_POST['session_duration'] ) ? intval( $_POST['session_duration'] ) : 86400000;

        $old_slug = get_option( 'order_sync_portal_slug', '' );
        update_option( 'order_sync_google_maps_api_key', $api_key );
        update_option( 'order_sync_interval', $sync_interval );
        update_option( 'order_sync_portal_slug', $portal_slug );
        update_option( 'order_sync_session_duration', $session_duration );

        if ( $portal_slug !== $old_slug ) {
            order_sync_ensure_pwa_page( $portal_slug );
            flush_rewrite_rules();
        }

        echo '<div class="notice notice-success"><p>Overall settings saved!</p></div>';
    }

    if ( isset( $_POST['save_branding'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        $branding = isset( $_POST['subsales_branding'] ) ? sanitize_text_field( $_POST['subsales_branding'] ) : '';
        $style_variant = isset( $_POST['style_variant'] ) ? sanitize_text_field( $_POST['style_variant'] ) : 'default';
        $primary_color = isset( $_POST['primary_color'] ) ? sanitize_text_field( $_POST['primary_color'] ) : '#2d6cdf';
        $header_image = isset( $_POST['subsales_header_image'] ) ? intval( $_POST['subsales_header_image'] ) : 0;

        update_option( 'subsales_branding', $branding );
        update_option( 'order_sync_style_variant', $style_variant );
        update_option( 'order_sync_primary_color', $primary_color );
        update_option( 'subsales_header_image', $header_image );

        echo '<div class="notice notice-success"><p>Branding saved!</p></div>';
    }

    if ( isset( $_POST['save_products'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        // Handle products: repeatable fields product_name[], product_price[], product_visible[], product_id[]
        if ( isset( $_POST['product_name'] ) && is_array( $_POST['product_name'] ) ) {
            $names = $_POST['product_name'];
            $prices = isset( $_POST['product_price'] ) && is_array( $_POST['product_price'] ) ? $_POST['product_price'] : array();
            $visibles = isset( $_POST['product_visible'] ) && is_array( $_POST['product_visible'] ) ? $_POST['product_visible'] : array();
            $ids = isset( $_POST['product_id'] ) && is_array( $_POST['product_id'] ) ? $_POST['product_id'] : array();
            $products = array();
            $count = 0;
            for ( $i = 0; $i < count( $names ) && $count < 10; $i++ ) {
                $name = sanitize_text_field( $names[ $i ] );
                if ( empty( $name ) ) continue;
                $price_raw = isset( $prices[ $i ] ) ? $prices[ $i ] : '0';
                $price = floatval( preg_replace( '/[^0-9.\\-]/', '', $price_raw ) );
                $price = round( $price, 2 );
                $id = isset( $ids[ $i ] ) ? sanitize_title( $ids[ $i ] ) : sanitize_title( $name );
                if ( empty( $id ) ) $id = 'p' . time() . $i;
                $suffix = 1;
                $base_id = $id;
                while ( in_array( $id, array_column( $products, 'id' ) ) ) { $id = $base_id . '-' . $suffix; $suffix++; }
                $visible = in_array( (string)$i, $visibles, true ) || in_array( $id, $visibles, true ) || ( isset( $visibles[ $i ] ) && $visibles[ $i ] );
                $products[] = array( 'id' => $id, 'name' => $name, 'price' => number_format( $price, 2, '.', '' ), 'visible' => $visible ? 1 : 0 );
                $count++;
            }
            update_option( 'order_sync_products', wp_json_encode( $products ) );
            echo '<div class="notice notice-success"><p>Products saved!</p></div>';
        }
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
    $style_variant = get_option( 'order_sync_style_variant', 'default' );
    $primary_color = get_option( 'order_sync_primary_color', '#2d6cdf' );
    $portal_url = esc_url_raw( home_url( '/' . $portal_slug . '/' ) );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';
    ?>
    <div class="wrap">
        <h1>Subsales Settings</h1>
        <!-- Tabbed navigation -->
        <div class="subsales-tabs-wrap" style="margin-bottom:12px">
            <style>
            .subsales-tabs { list-style:none; padding:0; margin:0 0 8px 0; display:flex; gap:8px; flex-wrap:wrap }
            .subsales-tabs li { background:#f1f1f1; padding:6px 10px; border-radius:6px; cursor:pointer }
            .subsales-tabs li.active { background:#2d6cdf; color:#fff }
            .subsales-tab-link { text-decoration:none; color:inherit; display:inline-block }
            /* Panels: hide by default, show when active */
            .subsales-tab-panel { display: none; margin-top: 12px; }
            .subsales-tab-panel.active { display: block; }
            </style>
            <ul class="subsales-tabs" role="tablist" aria-label="Subsales settings tabs">
                <li class="active" data-target="#tab-overall"><a class="subsales-tab-link" href="javascript:void(0);">Overall Settings</a></li>
                <li data-target="#tab-branding"><a class="subsales-tab-link" href="javascript:void(0);">Branding / Look &amp; Feel</a></li>
                <li data-target="#tab-products"><a class="subsales-tab-link" href="javascript:void(0);">Products</a></li>
                <li data-target="#tab-backup_restore"><a class="subsales-tab-link" href="javascript:void(0);">Backup / Restore</a></li>
                <li data-target="#tab-system_info"><a class="subsales-tab-link" href="javascript:void(0);">System Info</a></li>
            </ul>
            <script>
            (function(){
                // Initialize tab show/hide behaviour after DOM ready so panels exist
                function initSubsalesTabs(){
                    var tabs = document.querySelectorAll('.subsales-tabs li');
                    var panels = document.querySelectorAll('.subsales-tab-panel');
                    function showPanel(id){
                        // remove active class from all panels
                        panels.forEach(function(p){ p.classList.remove('active'); });
                        var el = document.querySelector(id);
                        if ( el ) el.classList.add('active');
                    }
                    tabs.forEach(function(t){ t.addEventListener('click', function(e){
                        e.preventDefault();
                        tabs.forEach(function(x){ x.classList.remove('active'); });
                        t.classList.add('active');
                        var target = t.getAttribute('data-target');
                        showPanel(target);
                        // update hash without jumping
                        if ( history && history.replaceState ) history.replaceState(null, null, target);
                    }); });
                    // Initialize panels: ensure first panel visible
                    if ( panels.length ) {
                        var found = document.querySelector('.subsales-tabs li.active');
                        var start = found ? found.getAttribute('data-target') : (panels[0].id ? ('#'+panels[0].id) : null);
                        if ( start ) showPanel(start);
                    }
                }
                if ( document.readyState === 'loading' ) {
                    document.addEventListener('DOMContentLoaded', initSubsalesTabs);
                } else {
                    initSubsalesTabs();
                }
            })();
            </script>
        </div>
    <?php if ( ! empty( $_GET['subsales_import_result'] ) ) :
            $raw = sanitize_text_field( wp_unslash( $_GET['subsales_import_result'] ) );
            // decode if it was rawurlencoded
            $raw = rawurldecode( $raw );
        ?>
            <div class="notice notice-success"><p><strong>Import result:</strong> <?php echo esc_html( $raw ); ?></p></div>
        <?php endif; ?>
        
        
        <!-- Main Settings Panels -->
            <div class="subsales-tab-panels">
                <div id="tab-overall" class="subsales-tab-panel">
                    <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
                    <input type="hidden" name="panel" value="overall" />
                    <table class="form-table">
                        <tr>
                            <th scope="row">Google Maps API Key</th>
                            <td>
                                <input type="text" id="mapsApiKey" name="api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                                <p class="description">Enter your Google Maps API key. This will be shared with mobile clients after login for map functionality.</p>
                                <p class="description">Need a key? <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Create a Google Maps API key</a> in Google Cloud Console (enable Maps JavaScript / Geocoding APIs).</p>
                                <p>
                                    <button type="button" id="test_maps_key_btn" class="button">Test key</button>
                                    <span id="maps_test_status" style="margin-left:12px; font-weight:600"></span>
                                </p>
                                <div id="maps_test_output" style="white-space:pre-wrap; border:1px solid #eee; padding:8px; margin-top:8px; display:none"></div>
                                <script>
                                (function(){
                                    const ajaxUrl = ajaxurl;
                                    const nonce = <?php echo json_encode( wp_create_nonce( 'subsales_test_maps_key' ) ); ?>;
                                    function setStatus(s){ document.getElementById('maps_test_status').textContent = s; }
                                    document.getElementById('test_maps_key_btn').addEventListener('click', function(){
                                        const key = document.getElementById('mapsApiKey').value || '';
                                        const fd = new FormData();
                                        fd.append('action','subsales_test_maps_key');
                                        fd.append('nonce', nonce);
                                        fd.append('key', key);
                                        const out = document.getElementById('maps_test_output'); out.style.display='block'; out.textContent='Testing...'; setStatus('Testing...');
                                        fetch(ajaxUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(j){
                                            if (!j) { out.textContent = 'No response'; setStatus('Error'); return; }
                                            if (!j.success) {
                                                out.textContent = 'Error: ' + (j.data || 'Unknown'); setStatus('Invalid'); return;
                                            }
                                            const d = j.data;
                                            out.textContent = JSON.stringify(d, null, 2);
                                            setStatus(d.status || 'OK');
                                        }).catch(function(e){ out.textContent = 'Fetch error: ' + e.message; setStatus('Error'); });
                                    });
                                })();
                                </script>
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
                            <th scope="row">Default session duration</th>
                            <td>
                                <select name="session_duration">
                                    <option value="86400000" <?php selected( 86400000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>24 hours</option>
                                    <option value="43200000" <?php selected( 43200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>12 hours</option>
                                    <option value="7200000" <?php selected( 7200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 hours</option>
                                </select>
                                <p class="description">Choose how long a session should be remembered for mobile clients when they login.</p>
                            </td>
                        </tr>
                    </table>
                    <p><?php submit_button( 'Save Overall Settings', 'primary', 'save_overall' ); ?></p>
                    </form>
                </div>

                <div id="tab-branding" class="subsales-tab-panel">
                    <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
                    <input type="hidden" name="panel" value="branding" />
                    <table class="form-table">
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
                            <th scope="row">Style options</th>
                            <td>
                                <p class="description">Choose a visual style for the PWA. Samples are shown below; primary color can be customized.</p>
                                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="default" <?php checked( $style_variant, 'default' ); ?> /> Default</label>
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="flat" <?php checked( $style_variant, 'flat' ); ?> /> Flat</label>
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="rounded" <?php checked( $style_variant, 'rounded' ); ?> /> Rounded</label>
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="dark" <?php checked( $style_variant, 'dark' ); ?> /> Dark</label>
                                </div>
                                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                                    <div style="padding:8px;border:1px solid #eee;border-radius:6px;min-width:160px">
                                        <div style="margin-bottom:6px;font-weight:600">Button sample</div>
                                        <button class="button" style="background:<?php echo esc_attr( $primary_color ); ?>;color:#fff;border:none;padding:8px 12px;border-radius:6px">Primary</button>
                                    </div>
                                    <div style="padding:8px;border:1px solid #eee;border-radius:6px;min-width:160px">
                                        <div style="margin-bottom:6px;font-weight:600">Header sample</div>
                                        <div style="background:<?php echo esc_attr( $primary_color ); ?>;color:#fff;padding:8px;border-radius:4px;text-align:center"><?php echo esc_html( $branding ); ?></div>
                                    </div>
                                </div>
                                <p style="margin-top:8px">Primary color: <input type="color" name="primary_color" value="<?php echo esc_attr( $primary_color ); ?>" /></p>
                                <p class="description">These options control how the embedded PWA will style buttons and header on mobile clients.</p>
                            </td>
                        </tr>
                    </table>
                    <p><?php submit_button( 'Save Branding', 'primary', 'save_branding' ); ?></p>
                    </form>
                </div>
                <?php
                // Products configuration (repeatable control)
                $configured_products = order_sync_get_products_config();
                ?>
                <div id="tab-products" class="subsales-tab-panel">
                    <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
                    <input type="hidden" name="panel" value="products" />
                    <table class="form-table">
                        <tr>
                            <th scope="row">Products</th>
                            <td>
                                <div id="products_repeatable">
                                    <table id="products_table" class="widefat" style="max-width:700px;margin-bottom:8px">
                                <thead><tr><th style="width:40%">Name</th><th style="width:20%">Price (USD)</th><th style="width:10%">Visible</th><th style="width:10%">Actions</th></tr></thead>
                                <tbody>
                                <?php if ( ! empty( $configured_products ) ) : ?>
                                    <?php foreach ( $configured_products as $idx => $p ) : ?>
                                        <tr data-index="<?php echo intval( $idx ); ?>">
                                            <td>
                                                <input type="text" name="product_name[]" class="regular-text product-name" value="<?php echo esc_attr( $p['name'] ?? '' ); ?>" />
                                                <input type="hidden" name="product_id[]" class="product-id" value="<?php echo esc_attr( $p['id'] ?? '' ); ?>" />
                                            </td>
                                            <td><input type="text" name="product_price[]" class="regular-text product-price" value="<?php echo esc_attr( $p['price'] ?? '0.00' ); ?>" /></td>
                                            <td style="text-align:center"><input type="checkbox" name="product_visible[]" value="<?php echo esc_attr( $p['id'] ?? $idx ); ?>" <?php checked( 1, intval( $p['visible'] ?? 0 ) ); ?> /></td>
                                            <td style="text-align:center"><button type="button" class="button button-link remove-product">Remove</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                            <p><button type="button" id="add_product_btn" class="button">Add product</button> <span class="description">Max 10 products.</span></p>
                        </div>
                        <script>
                        (function(){
                            var maxProducts = 10;
                            function slugify(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'').substr(0,60); }
                            function createRow(name, price, id, visible){
                                var tbody = document.querySelector('#products_table tbody');
                                if (!tbody) return null;
                                var current = tbody.querySelectorAll('tr').length;
                                if (current >= maxProducts) { alert('Maximum products reached ('+maxProducts+')'); return null; }
                                var tr = document.createElement('tr');
                                var tdName = document.createElement('td');
                                var nameInput = document.createElement('input'); nameInput.type='text'; nameInput.name='product_name[]'; nameInput.className='regular-text product-name'; nameInput.value = name || '';
                                var idInput = document.createElement('input'); idInput.type='hidden'; idInput.name='product_id[]'; idInput.className='product-id'; idInput.value = id || '';
                                tdName.appendChild(nameInput); tdName.appendChild(idInput);

                                var tdPrice = document.createElement('td');
                                var priceInput = document.createElement('input'); priceInput.type='text'; priceInput.name='product_price[]'; priceInput.className='regular-text product-price'; priceInput.value = price || '0.00';
                                tdPrice.appendChild(priceInput);

                                var tdVis = document.createElement('td'); tdVis.style.textAlign='center';
                                var visInput = document.createElement('input'); visInput.type='checkbox'; visInput.name='product_visible[]'; visInput.checked = !!visible; visInput.value = id || '';
                                tdVis.appendChild(visInput);

                                var tdAct = document.createElement('td'); tdAct.style.textAlign='center';
                                var remBtn = document.createElement('button'); remBtn.type='button'; remBtn.className='button button-link remove-product'; remBtn.textContent='Remove';
                                tdAct.appendChild(remBtn);

                                tr.appendChild(tdName); tr.appendChild(tdPrice); tr.appendChild(tdVis); tr.appendChild(tdAct);
                                // wire events
                                nameInput.addEventListener('input', function(){
                                    var slug = slugify(nameInput.value) || ('p'+Date.now());
                                    idInput.value = slug;
                                    // keep the visible checkbox value in sync with id
                                    try{ visInput.value = slug; }catch(e){}
                                });
                                remBtn.addEventListener('click', function(){ tr.parentNode.removeChild(tr); });
                                tbody.appendChild(tr);
                                return tr;
                            }
                            document.getElementById('add_product_btn').addEventListener('click', function(){ createRow('', '0.00', 'p' + Date.now(), true); });
                            document.querySelectorAll('#products_table .remove-product').forEach(function(b){ b.addEventListener('click', function(){ var tr = b.closest('tr'); tr && tr.parentNode.removeChild(tr); }); });
                        })();
                        </script>
                            </td>
                        </tr>
                    </table>
                    <p><?php submit_button( 'Save Products', 'primary', 'save_products' ); ?></p>
                    </form>
                </div>
            </div> <!-- .subsales-tab-panels -->

        <div id="tab-backup_restore" class="subsales-tab-panel">
            <h2 style="margin-top:18px">Backup / Restore</h2>
            <p>Export orders or site settings for backup, and import CSV backups here to restore.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Export</th>
                    <td>
                        <?php $export_nonce = wp_create_nonce( 'subsales_export_nonce' ); ?>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_orders&_wpnonce=' . $export_nonce ) ); ?>">Export Orders (CSV)</a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_settings&_wpnonce=' . $export_nonce ) ); ?>">Export Settings (CSV)</a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_backup_combined&_wpnonce=' . $export_nonce ) ); ?>">Export Combined Backup (ZIP)</a>
                        <p class="description">Orders CSV includes order_data (JSON) column. Settings CSV is key,value pairs.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Import</th>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-bottom:12px">
                            <?php wp_nonce_field( 'subsales_import_nonce' ); ?>
                            <input type="hidden" name="action" value="subsales_import_backup" />
                            <label>Backup file <input type="file" name="backup_file" accept="text/csv,text/plain,application/zip,.zip" required /></label>
                            <p style="margin-top:8px">
                                <label><input type="checkbox" name="import_update_existing" value="1" /> Update existing orders by order_id when present (otherwise skip)</label>
                            </p>
                            <p><button type="submit" class="button button-primary">Upload and Import</button></p>
                        </form>

                        <!-- Destructive restore: clear plugin data then import (hidden in Advanced details) -->
                        <details style="margin-top:12px">
                            <summary style="font-weight:700; cursor:pointer">Advanced: Restore (clear then import)</summary>
                            <div style="padding:12px;border:1px solid #eee;margin-top:8px;background:#fff">
                                <form id="subsales-destructive-restore" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                                    <?php wp_nonce_field( 'subsales_restore_nonce' ); ?>
                                    <input type="hidden" name="action" value="subsales_restore_and_import" />
                                    <label>Backup file <input type="file" name="backup_file" accept="application/zip,.zip,text/csv,text/plain" required /></label>
                                    <p style="margin-top:8px">Choose what you want to <strong>clear before importing</strong>. You may still import both orders and settings if your ZIP contains both files — the clear step only affects the parts you select.</p>
                                    <p style="margin-top:6px">
                                        <label style="margin-right:12px"><input type="radio" name="restore_target" value="both" checked /> Clear orders and settings</label>
                                        <label style="margin-right:12px"><input type="radio" name="restore_target" value="orders" /> Clear orders only</label>
                                        <label style="margin-right:12px"><input type="radio" name="restore_target" value="settings" /> Clear settings only</label>
                                    </p>
                                    <p style="margin-top:8px"><label style="font-weight:600"><input type="checkbox" id="confirm_clear_restore" /> I understand this will permanently delete the selected plugin data before importing.</label></p>
                                    <p><button id="subsales-restore-btn" type="submit" class="button button-large button-danger" disabled>Restore (clear then import)</button></p>
                                </form>
                                <script>
                                (function(){
                                    var chk = document.getElementById('confirm_clear_restore');
                                    var btn = document.getElementById('subsales-restore-btn');
                                    var form = document.getElementById('subsales-destructive-restore');
                                    var radios = form ? form.querySelectorAll('input[name="restore_target"]') : null;
                                    if ( chk && btn ) {
                                        chk.addEventListener('change', function(){ btn.disabled = !chk.checked; });
                                    }
                                    if ( form ) {
                                        form.addEventListener('submit', function(e){
                                            if ( ! chk.checked ) { e.preventDefault(); alert('Please confirm the destructive restore by checking the box.'); return false; }
                                            // determine selected target to customize confirmation
                                            var sel = 'both';
                                            if ( radios ) {
                                                radios.forEach(function(r){ if ( r.checked ) sel = r.value; });
                                            }
                                            var msg = 'This will permanently delete the selected plugin data before importing. Are you sure?';
                                            if ( sel === 'both' ) msg = 'This will permanently delete ALL plugin data (orders, teams, members, and plugin settings) before importing. Are you sure?';
                                            if ( sel === 'orders' ) msg = 'This will permanently delete ALL orders, teams, and team members before importing. It will NOT remove settings. Are you sure?';
                                            if ( sel === 'settings' ) msg = 'This will permanently delete plugin settings (options) before importing. It will NOT remove orders or teams. Are you sure?';
                                            if ( ! confirm(msg) ) { e.preventDefault(); return false; }
                                        });
                                    }
                                })();
                                </script>
                            </div>
                        </details>
                        <p class="description">Imported settings will call update_option(key,value). Imported orders will be inserted into the orders table; existing order_id values are skipped unless 'Update existing' is checked. You may upload a single combined ZIP (created by "Export Combined Backup (ZIP)") which contains both <code>orders.csv</code> and <code>settings.csv</code>.</p>
                    </td>
                </tr>
            </table>
        
        
        <!-- Clear data form -->
            <a id="remove_migration"></a>
            <form method="post" action="" style="margin-top:18px">
                <?php wp_nonce_field( 'order_sync_clear_nonce' ); ?>
                <p><strong>Danger zone:</strong> Use the button below to permanently remove all plugin data (orders, teams, team members). This will TRUNCATE those tables but leave plugin files intact.</p>
                <p><button name="clear_data" class="button button-large button-secondary" onclick="return confirm('Permanently clear all plugin data? This cannot be undone.');">Clear plugin data now</button></p>
            </form>
        </div>

        <div id="tab-system_info" class="subsales-tab-panel">
            <h2>System Information</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Plugin Version</th>
                <td><?php echo esc_html( SUBSALES_VERSION ); ?></td>
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
        // Allow adding a team member without an email address. Only name and team id are required.
        if ( $member_team_id && ! empty( $member_name ) ) {
            // Normalize empty emails to empty string (DB field accepts empty value)
            $member_email = $member_email ?: '';
            $ok = order_sync_add_team_member( $member_team_id, $member_name, $member_email, $member_role );
            if ( $ok ) echo '<div class="notice notice-success"><p>Team member added.</p></div>';
            else echo '<div class="notice notice-error"><p>Failed to add team member (possible duplicate email or DB error).</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Name is required to add a team member.</p></div>';
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
                                <td><input type="email" name="member_email" class="regular-text" placeholder="(optional)" /></td>
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
    <?php
}

// Orders admin page
function order_sync_orders_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';
    // Initial page renders minimal markup; actual data is fetched via AJAX.
    $nonce = wp_create_nonce( 'subsales_orders_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    // preload teams, members and configured products for filter UI and table columns
    $teams = order_sync_get_teams();
    global $wpdb;
    $members = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}order_sync_team_members ORDER BY name ASC", ARRAY_A );
    $products_conf = order_sync_get_products_config();

    // Build WHERE clauses safely
    $where = array();
    $params = array();

    if ( ! empty( $start_date ) ) {
        // start of day
        $where[] = "created_at >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if ( ! empty( $end_date ) ) {
        // end of day
        $where[] = "created_at <= %s";
        $params[] = $end_date . ' 23:59:59';
    }
    if ( $filter_team ) {
        $where[] = "team_id = %d";
        $params[] = $filter_team;
    }
    if ( ! empty( $filter_member ) ) {
        // user_id column stores the entered_by identifier
        $where[] = "user_id = %s";
        $params[] = $filter_member;
    }
    if ( ! empty( $payment_method ) ) {
        // best-effort JSON match in order_data -> search for paymentMethod or checkNumber
        if ( $payment_method === 'cash' ) {
            $where[] = "order_data LIKE %s";
            $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"cash"%';
        } elseif ( $payment_method === 'check' ) {
            $where[] = "(order_data LIKE %s OR order_data LIKE %s)";
            $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"check"%';
            $params[] = '%' . $wpdb->esc_like( '"checkNumber"' ) . '%';
        }
    }

    // Normalize stored products option (may be JSON string or array)
    $products_conf = order_sync_get_products_config();

    // Note: initial Orders page renders an empty table; data is fetched via AJAX. Do not run server-side queries here.
    $orders = array();

    ?>
    <div class="wrap">
        <h1>Orders</h1>

        <form id="subsales-orders-filter" class="subsales-orders-filter" onsubmit="return false;">
            <input type="hidden" name="action" value="subsales_fetch_orders" />
            <?php wp_nonce_field( 'subsales_orders_nonce', 'subsales_orders_nonce_field' ); ?>
            <table class="form-table" style="max-width: 960px;">
                <tr>
                    <th style="width:110px">Start date</th>
                    <td><input type="date" name="start_date" /></td>
                    <th>End date</th>
                    <td><input type="date" name="end_date" /></td>
                </tr>
                <tr>
                    <th>Team</th>
                    <td>
                        <select name="team_id">
                            <option value="">All teams</option>
                            <?php foreach ( $teams as $t ) : ?>
                                <option value="<?php echo intval( $t['id'] ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <th>Team member</th>
                    <td>
                        <select name="entered_by_id">
                            <option value="">All members</option>
                            <?php foreach ( $members as $m ) : ?>
                                <?php $label = esc_html( $m['name'] ); $email = trim( strval( $m['email'] ?? '' ) ); if ( $email ) { $label .= ' (' . esc_html( $email ) . ')'; } ?>
                                <option value="<?php echo esc_attr( $m['id'] ); ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Payment</th>
                    <td>
                        <select name="payment_method">
                            <option value="">Any</option>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                        </select>
                    </td>
                    <th>Page size</th>
                    <td>
                        <select name="page_size">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100" selected>100</option>
                        </select>
                        <span class="description">(max 100)</span>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button id="subsales-filter-btn" class="button button-primary">Filter</button>
                <button id="subsales-reset-btn" type="button" class="button">Reset</button>
            </p>
        </form>

        <div id="subsales-orders-results">
            <p id="subsales-orders-meta" style="margin-bottom:8px"></p>
            <table id="subsales-orders-table" class="widefat fixed striped" cellspacing="0">
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Date Entered</th>
                        <th>Team Member</th>
                        <th>Team</th>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <th style="text-align:center"><?php echo esc_html( $pcol['name'] ); ?></th>
                        <?php endforeach; ?>
                        <th>Payment</th>
                        <th style="text-align:right">Order Total (USD)</th>
                    </tr>
                </thead>
                <tbody id="subsales-orders-tbody">
                    <tr><td colspan="<?php echo 6 + count( $products_conf ); ?>">Use the filters above and click Filter to load orders via AJAX.</td></tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align:right">Page totals:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td id="subsales-page-prod-<?php echo esc_attr( $pcol['id'] ); ?>" style="text-align:center">0</td>
                        <?php endforeach; ?>
                        <td></td>
                        <td id="subsales-page-total" style="text-align:right">$0.00</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="text-align:right">Cash:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td id="subsales-page-cash" style="text-align:right">$0.00</td>
                    </tr>
                    <tr>
                        <td colspan="4" style="text-align:right">Check:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td id="subsales-page-check" style="text-align:right">$0.00</td>
                    </tr>
                </tfoot>
            </table>

            <div id="subsales-pagination" style="margin-top:12px"></div>
        </div>
    </div>

        <script>
    (function(){
        const ajaxUrl = <?php echo json_encode( $ajax_url ); ?>;
        const nonce = <?php echo json_encode( $nonce ); ?>;
        const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;

        function serializeForm(form){
            const fd = new FormData();
            fd.append('action','subsales_fetch_orders');
            fd.append('nonce', nonce);
            const f = new FormData(form);
            for (const [k,v] of f.entries()){ if (v !== null) fd.append(k,v); }
            return fd;
        }

        function renderRows(orders){
            const tbody = document.getElementById('subsales-orders-tbody');
            tbody.innerHTML = '';
                if (!orders || orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="' + (6 + configuredProducts.length) + '">No orders found for the selected filters.</td></tr>';
                return;
            }
            for (const o of orders){
                const tr = document.createElement('tr');
                let html = '';
                html += '<td>' + escapeHtml(o.order_id) + '</td>';
                html += '<td>' + escapeHtml(o.created_at_formatted) + '</td>';
                html += '<td>' + escapeHtml(o.entered_by_name || o.user_id || '') + '</td>';
                html += '<td>' + escapeHtml(o.team_name || '') + '</td>';
                // per-configured-product columns
                for (const p of configuredProducts) {
                    const pid = p.id;
                    const qty = (o.products_map && typeof o.products_map[pid] !== 'undefined') ? Number(o.products_map[pid]) : 0;
                    html += '<td style="text-align:center">' + escapeHtml(qty) + '</td>';
                }
                // Items column removed; individual product columns are shown above.
                html += '<td>' + escapeHtml(o.payment_display || '') + '</td>';
                html += '<td style="text-align:right">$' + Number(o.order_total).toFixed(2) + '</td>';
                tr.innerHTML = html;
                tbody.appendChild(tr);
            }
        }

        function renderMeta(total_count, page, pages){
            const meta = document.getElementById('subsales-orders-meta');
            meta.textContent = 'Showing page ' + page + ' of ' + pages + ' — ' + total_count + ' matching orders';
        }

        function renderTotals(totals){
            // totals.product_totals is expected to be a map of productId => qty for the current page
            try{
                if (totals && totals.product_totals){
                    for (const p of configuredProducts){
                        const pid = p.id;
                        const el = document.getElementById('subsales-page-prod-' + pid);
                        if (el) el.textContent = (totals.product_totals[pid] !== undefined) ? String(totals.product_totals[pid]) : '0';
                    }
                } else {
                    // clear product totals
                    for (const p of configuredProducts){ const el = document.getElementById('subsales-page-prod-' + p.id); if (el) el.textContent = '0'; }
                }
            }catch(e){ console.warn('renderTotals product totals error', e); }
            document.getElementById('subsales-page-total').textContent = '$' + Number(totals.grand || 0).toFixed(2);
            document.getElementById('subsales-page-cash').textContent = '$' + Number(totals.cash || 0).toFixed(2);
            document.getElementById('subsales-page-check').textContent = '$' + Number(totals.check || 0).toFixed(2);
        }

        function renderPagination(page, pages){
            const el = document.getElementById('subsales-pagination');
            el.innerHTML = '';
            if (pages <= 1) return;
            for (let p=1;p<=pages;p++){
                const btn = document.createElement('button');
                btn.className = 'button';
                btn.style.marginRight = '6px';
                btn.textContent = p;
                if (p === page) btn.disabled = true;
                btn.addEventListener('click', function(){ fetchPage(p); });
                el.appendChild(btn);
            }
        }

        function escapeHtml(s){ if (!s && s !== 0) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        async function fetchPage(page){
            const form = document.getElementById('subsales-orders-filter');
            const fd = serializeForm(form);
            fd.append('page', page);
            // page_size is included from form
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data || !data.success){
                alert('Failed to fetch orders'); return;
            }
            const payload = data.data;
            renderRows(payload.orders);
            renderMeta(payload.total_count, payload.page, payload.pages);
            renderTotals(payload.totals);
            renderPagination(payload.page, payload.pages);
        }

        document.getElementById('subsales-filter-btn').addEventListener('click', function(){ fetchPage(1); });
        document.getElementById('subsales-reset-btn').addEventListener('click', function(){
            document.getElementById('subsales-orders-filter').reset(); fetchPage(1);
        });

        // Load first page on open
        fetchPage(1);
    })();
    </script>
    <?php
}

