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
 * 
 * ============================================================
 * DEVELOPMENT STATE - Last Updated: 2025-11-24
 * ============================================================
 * Current Phase: Phase 6 (Settings Toggle)
 * See: DEVELOPMENT-STATE.md in repo root for full context
 * 
 * LOCKED ARCHITECTURE (DO NOT CHANGE):
 * - Phone: REQUIRED (NOT NULL, UNIQUE, 10 digits normalized)
 * - Email: OPTIONAL (can be empty string)
 * - Login: Name search + Phone entry (user-based mode)
 * - Multi-team: user_teams junction table for many-to-many
 * ============================================================
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

// Optional Composer autoload: if a vendor/autoload.php exists in the plugin directory
// require it so composer-installed libraries (for example PhpSpreadsheet) are available.
// This keeps the plugin functional when vendor/ isn't present (fallbacks remain).
$subsales_vendor_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $subsales_vendor_autoload ) ) {
    require_once $subsales_vendor_autoload;
}

// Load modular classes
require_once SUBSALES_PLUGIN_PATH . 'includes/class-database.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-rest-api.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-pwa.php';

// Initialize database
Subsales_Database::init();

// Initialize REST API
Subsales_REST_API::init();

// Initialize PWA
Subsales_PWA::init();


// Activation hook
register_activation_hook( __FILE__, 'subsales_activate' );

function subsales_activate() {
    // Check WordPress version
    global $wp_version;
    if ( version_compare( $wp_version, '5.0', '<' ) ) {
        deactivate_plugins( SUBSALES_PLUGIN_BASENAME );
        wp_die( 'Subsales Management requires WordPress 5.0 or higher.' );
    }
    
    // Create database tables using Database class
    Subsales_Database::create_tables();
    
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
    return Subsales_PWA::get_products_config();
}

// Debug mode visual indicators
add_action( 'admin_notices', 'subsales_debug_mode_notice' );
add_action( 'admin_footer', 'subsales_debug_mode_badge' );

function subsales_debug_mode_notice() {
    // Debug notice removed - using only the debug toggle box on logs page and floating badge
    return;
}

function subsales_debug_mode_badge() {
    $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
    if ( ! $debug_enabled ) {
        return;
    }
    
    ?>
    <div id="subsales-debug-badge" style="position: fixed; bottom: 20px; right: 20px; z-index: 99999; background: #dc3545; color: #fff; padding: 12px 18px; border-radius: 50px; box-shadow: 0 4px 12px rgba(220,53,69,0.4); font-weight: bold; font-size: 13px; cursor: pointer; animation: pulse 2s infinite;">
        <span style="font-size: 16px; margin-right: 5px;">🔍</span> DEBUG MODE
    </div>
    <style>
        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 4px 12px rgba(220,53,69,0.4); }
            50% { transform: scale(1.05); box-shadow: 0 6px 16px rgba(220,53,69,0.6); }
        }
        #subsales-debug-badge:hover {
            background: #bb2d3b;
            transform: scale(1.1) !important;
        }
    </style>
    <script>
    jQuery(document).ready(function($) {
        $('#subsales-debug-badge').on('click', function() {
            window.location.href = '?page=subsales-logs';
        });
    });
    </script>
    <?php
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
        <h2 style="margin-top:18px">Route preview</h2>
        <p class="description">Preview driver assignments on a map. Click Preview to geocode addresses (uses stored Google Maps API key) and render simple routes per driver. No Directions API calls are made.</p>
        <p><button id="subsales-preview-btn" class="button">Preview routes</button>
        <span id="subsales-preview-status" style="margin-left:12px"></span></p>
        <div id="subsales-preview-map" style="height:480px; width:100%; max-width:1100px; border:1px solid #ddd; margin-top:12px"></div>

        <script>
        (function(){
            const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            const nonce = '<?php echo esc_js( wp_create_nonce( 'subsales_delivery_preview' ) ); ?>';
            const previewBtn = document.getElementById('subsales-preview-btn');
            const statusEl = document.getElementById('subsales-preview-status');
            const mapEl = document.getElementById('subsales-preview-map');
            let mapInstance = null;
            let gmScriptLoaded = false;

            function loadGoogleMaps(key){
                return new Promise(function(resolve, reject){
                    if (!key) return reject(new Error('No Google Maps API key configured'));
                    if (window.google && window.google.maps) return resolve();
                    const s = document.createElement('script');
                    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key);
                    s.async = true; s.defer = true;
                    s.onload = function(){ gmScriptLoaded = true; resolve(); };
                    s.onerror = function(){ reject(new Error('Failed to load Google Maps script')); };
                    document.head.appendChild(s);
                });
            }

            function clearMap(){
                if (!mapInstance) return; // we will recreate markers per preview
                mapInstance = null; mapEl.innerHTML = '';
            }

            function renderMap(data, apiKey){
                // data: { drivers: { '1': [rows], ... }, products: [...] }
                loadGoogleMaps(apiKey).then(function(){
                    // create map centered on first point
                    const allPts = [];
                    for (const dv in data.drivers){ for (const r of data.drivers[dv]){ if (r.lat && r.lng) allPts.push({lat: parseFloat(r.lat), lng: parseFloat(r.lng)}); } }
                    if (allPts.length === 0){ statusEl.textContent = 'No geocoded addresses available for preview.'; return; }
                    const center = allPts[0];
                    mapInstance = new google.maps.Map(mapEl, { center: center, zoom: 12 });

                    const colors = ['#1f77b4','#ff7f0e','#2ca02c','#d62728','#9467bd','#8c564b','#e377c2','#7f7f7f','#bcbd22','#17becf'];
                    let driverIdx = 0;
                    for (const dv in data.drivers){
                        const rows = data.drivers[dv];
                        const path = [];
                        for (const r of rows){ if (r.lat && r.lng) path.push({lat: parseFloat(r.lat), lng: parseFloat(r.lng)}); }
                        const col = colors[driverIdx % colors.length];
                        // markers
                        for (let i=0;i<rows.length;i++){
                            const rp = rows[i];
                            if (!rp.lat || !rp.lng) continue;
                            const mk = new google.maps.Marker({ position: {lat: parseFloat(rp.lat), lng: parseFloat(rp.lng)}, map: mapInstance, label: String(dv), title: rp.address_raw, icon: { path: google.maps.SymbolPath.CIRCLE, scale: 6, fillColor: col, fillOpacity: 1, strokeWeight: 0 } });
                        }
                        if (path.length > 1){
                            const poly = new google.maps.Polyline({ path: path, geodesic: true, strokeColor: col, strokeOpacity: 0.7, strokeWeight: 3, map: mapInstance });
                        }
                        driverIdx++;
                    }
                    // fit bounds
                    const bounds = new google.maps.LatLngBounds();
                    for (const p of allPts) bounds.extend(p);
                    if (allPts.length>0) mapInstance.fitBounds(bounds);
                }).catch(function(err){ statusEl.textContent = err.message; });
            }

            previewBtn.addEventListener('click', function(){
                statusEl.textContent = 'Building preview...';
                const fd = new FormData();
                fd.append('action','subsales_delivery_preview');
                fd.append('_ajax_nonce', nonce);
                // include driver_count, delivery_date and start_address from form
                const driverCountEl = document.querySelector('input[name="driver_count"]');
                const startAddrEl = document.querySelector('input[name="start_address"]');
                const deliveryDateEl = document.querySelector('input[name="delivery_date"]');
                fd.append('driver_count', driverCountEl ? driverCountEl.value : 2);
                fd.append('start_address', startAddrEl ? startAddrEl.value : '');
                fd.append('delivery_date', deliveryDateEl ? deliveryDateEl.value : '');
                fetch(ajaxUrl, { method: 'POST', body: fd }).then(r=>r.json()).then(function(j){
                    if (!j || !j.success){ statusEl.textContent = 'Preview failed: ' + (j && j.data ? j.data : 'unknown'); return; }
                    const payload = j.data;
                    statusEl.textContent = 'Preview ready';
                    clearMap();
                    renderMap(payload, payload.api_key || '');
                }).catch(function(e){ statusEl.textContent = 'Error: '+ e.message; });
            });
        })();
        </script>
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

    add_submenu_page(
        'subsales-management',
        'Delivery',
        'Delivery',
        'manage_options',
        'subsales-delivery',
        'order_sync_delivery_page'
    );
    
    add_submenu_page(
        'subsales-management',
        'System Logs',
        'Logs',
        'manage_options',
        'subsales-logs',
        'subsales_logs_page'
    );
}

// AJAX handler to search/preview address across all sources
add_action( 'wp_ajax_subsales_search_address', 'subsales_search_address_preview' );
add_action( 'wp_ajax_subsales_extract_openaddresses_zips', 'subsales_extract_openaddresses_zips' );
add_action( 'wp_ajax_subsales_download_openaddresses', 'subsales_download_openaddresses' );
add_action( 'wp_ajax_subsales_toggle_debug', 'subsales_toggle_debug_ajax' );

// AJAX handler for debug mode toggle
function subsales_toggle_debug_ajax() {
    check_ajax_referer( 'subsales_debug_toggle', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
    
    if ( $debug_enabled ) {
        // Disable debug mode
        update_option( 'subsales_debug_logging_enabled', false );
        delete_option( 'subsales_debug_logging_started' );
        subsales_log( 'INFO', 'system', 'Debug logging manually disabled' );
        wp_send_json_success( array( 'status' => 'disabled' ) );
    } else {
        // Enable debug mode
        update_option( 'subsales_debug_logging_enabled', true );
        update_option( 'subsales_debug_logging_started', time() );
        subsales_log( 'INFO', 'system', 'Debug logging enabled (24-hour timeout)' );
        wp_send_json_success( array( 'status' => 'enabled' ) );
    }
}

function subsales_download_openaddresses() {
    check_ajax_referer( 'subsales_zip_generate', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $state = isset( $_POST['state'] ) ? strtolower( sanitize_text_field( $_POST['state'] ) ) : 'ct';
    if ( ! preg_match( '/^[a-z]{2}$/', $state ) ) {
        wp_send_json_error( 'Invalid state code. Use 2-letter code like ct, ny, ca, etc.' );
    }

    update_option( 'subsales_openaddresses_state', $state );

    // OpenAddresses.io public S3 bucket - no authentication required
    // Try multiple possible URL patterns
    $urls = array(
        'https://s3.amazonaws.com/data.openaddresses.io/openaddr-collected-us_' . $state . '.zip',
        'https://s3.amazonaws.com/data.openaddresses.io/us/' . $state . '/statewide.csv',
        'https://openaddresses.io/download/us/' . $state . '/statewide',
    );
    
    $upload = wp_upload_dir();
    $base_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    if ( ! is_dir( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }
    
    $dest_path = trailingslashit( $base_dir ) . 'openaddresses-full.csv';
    $temp_zip = trailingslashit( $base_dir ) . 'temp-openaddresses.zip';

    // Try each URL
    $downloaded = false;
    $tried_urls = array();
    
    foreach ( $urls as $url ) {
        $tried_urls[] = $url;
        
        // Determine if it's a ZIP file
        $is_zip = strpos( $url, '.zip' ) !== false;
        $target_file = $is_zip ? $temp_zip : $dest_path;
        
        $response = wp_remote_get( $url, array(
            'timeout' => 300,
            'stream' => true,
            'filename' => $target_file
        ) );

        if ( is_wp_error( $response ) ) {
            continue;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 && file_exists( $target_file ) ) {
            // If ZIP, extract it
            if ( $is_zip ) {
                WP_Filesystem();
                global $wp_filesystem;
                
                $unzip_result = unzip_file( $temp_zip, $base_dir );
                
                if ( is_wp_error( $unzip_result ) ) {
                    @unlink( $temp_zip );
                    continue;
                }
                
                // Find the CSV file in extracted contents
                $files = glob( $base_dir . '/*.csv' );
                if ( empty( $files ) ) {
                    @unlink( $temp_zip );
                    continue;
                }
                
                // Rename first CSV to our standard name
                rename( $files[0], $dest_path );
                
                // Clean up
                @unlink( $temp_zip );
                foreach ( $files as $f ) {
                    if ( $f !== $dest_path ) @unlink( $f );
                }
            }
            
            $downloaded = true;
            break;
        }
    }

    if ( ! $downloaded ) {
        wp_send_json_error( 'Could not download from OpenAddresses.io. Tried URLs: ' . implode( ', ', $tried_urls ) . '. Please use manual upload instead.' );
    }

    if ( ! file_exists( $dest_path ) ) {
        wp_send_json_error( 'Download completed but file not found at destination' );
    }

    $size = filesize( $dest_path );
    wp_send_json_success( array(
        'message' => 'Downloaded ' . strtoupper( $state ) . ' data successfully',
        'file_size' => size_format( $size ),
        'state' => strtoupper( $state ),
        'path' => $dest_path
    ) );
}

function subsales_extract_openaddresses_zips() {
    check_ajax_referer( 'subsales_zip_generate', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $served_zips = get_option( 'subsales_served_zips', array() );
    if ( empty( $served_zips ) ) {
        wp_send_json_error( 'No ZIP codes configured. Save your ZIP list first.' );
    }

    $upload = wp_upload_dir();
    $full_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses-full.csv';
    $filtered_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses.csv';

    if ( ! file_exists( $full_path ) ) {
        wp_send_json_error( 'Full state file not found at: ' . $full_path );
    }

    $handle_in = fopen( $full_path, 'r' );
    if ( $handle_in === false ) {
        wp_send_json_error( 'Could not open full Connecticut file' );
    }

    $handle_out = fopen( $filtered_path, 'w' );
    if ( $handle_out === false ) {
        fclose( $handle_in );
        wp_send_json_error( 'Could not create filtered file' );
    }

    // Read and write header
    $header = fgetcsv( $handle_in );
    if ( ! $header ) {
        fclose( $handle_in );
        fclose( $handle_out );
        wp_send_json_error( 'Invalid CSV format - no header row' );
    }
    fputcsv( $handle_out, $header );

    // Find postcode column
    $postcode_idx = array_search( 'POSTCODE', $header );
    if ( $postcode_idx === false ) {
        fclose( $handle_in );
        fclose( $handle_out );
        wp_send_json_error( 'POSTCODE column not found in CSV' );
    }

    // Filter rows by ZIP codes
    $count = 0;
    $total_rows = 0;
    while ( ( $row = fgetcsv( $handle_in ) ) !== false ) {
        $total_rows++;
        if ( ! isset( $row[ $postcode_idx ] ) ) continue;
        
        $postcode = trim( $row[ $postcode_idx ] );
        if ( in_array( $postcode, $served_zips ) ) {
            fputcsv( $handle_out, $row );
            $count++;
        }
    }

    fclose( $handle_in );
    fclose( $handle_out );

    // Save metadata
    update_option( 'subsales_oa_filter_meta', array(
        'zips' => $served_zips,
        'count' => $count,
        'total_rows' => $total_rows,
        'date' => current_time( 'mysql' )
    ) );

    wp_send_json_success( array(
        'message' => 'Extracted ' . number_format( $count ) . ' addresses for ' . count( $served_zips ) . ' ZIP code(s)',
        'count' => $count,
        'zips' => $served_zips,
        'file_size' => size_format( filesize( $filtered_path ) )
    ) );
}
function subsales_search_address_preview() {
    check_ajax_referer( 'subsales_address_search', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $address = isset( $_POST['address'] ) ? sanitize_text_field( $_POST['address'] ) : '';
    
    if ( empty( $address ) ) {
        wp_send_json_error( 'Address required' );
    }

    // Get served ZIP codes
    $served_zips = get_option( 'subsales_served_zips', array() );
    if ( empty( $served_zips ) ) {
        wp_send_json_error( 'No ZIP codes configured. Please configure served ZIP codes first.' );
    }

    $upload = wp_upload_dir();
    $base_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    $all_results = array( 'local' => array( 'count' => 0, 'results' => array() ) );

    // Search across all served ZIP codes using local JSON files
    foreach ( $served_zips as $zip ) {
        $json_path = $base_dir . '/' . $zip . '.json';
        
        if ( ! file_exists( $json_path ) ) {
            continue;
        }
        
        $json_content = file_get_contents( $json_path );
        $addresses = json_decode( $json_content, true );
        
        if ( ! is_array( $addresses ) ) {
            continue;
        }
        
        // Search for matching addresses
        $search_lower = strtolower( trim( $address ) );
        foreach ( $addresses as $addr ) {
            if ( ! isset( $addr['label'] ) ) continue;
            
            $label_lower = strtolower( $addr['label'] );
            
            // Check if search term is in the address label
            if ( strpos( $label_lower, $search_lower ) !== false ) {
                $all_results['local']['results'][] = $addr;
                $all_results['local']['count']++;
            }
        }
    }

    $total = $all_results['local']['count'];
    wp_send_json_success( array( 'sources' => $all_results, 'total' => $total, 'zips_searched' => $served_zips ) );
}

// Helper: Search OpenAddresses CSV for specific address
function subsales_search_openaddresses( $address, $zip ) {
    $upload = wp_upload_dir();
    $csv_path = get_option( 'subsales_openaddresses_path', trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/connecticut.csv' );
    
    if ( ! file_exists( $csv_path ) ) return array();
    
    // Extract house number from address
    $number = '';
    $street_search = strtolower( trim( $address ) );
    if ( preg_match( '/^(\d+)\s+(.+)$/i', $address, $matches ) ) {
        $number = $matches[1];
        $street_search = strtolower( trim( $matches[2] ) );
    }
    
    $results = array();
    $handle = fopen( $csv_path, 'r' );
    if ( $handle === false ) return array();
    
    $header = fgetcsv( $handle );
    if ( ! $header ) {
        fclose( $handle );
        return array();
    }
    
    $col_map = array();
    foreach ( array( 'LON', 'LAT', 'NUMBER', 'STREET', 'UNIT', 'CITY', 'REGION', 'POSTCODE' ) as $col ) {
        $idx = array_search( $col, $header );
        if ( $idx !== false ) $col_map[ $col ] = $idx;
    }
    
    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( ! isset( $col_map['POSTCODE'] ) || ! isset( $row[ $col_map['POSTCODE'] ] ) ) continue;
        if ( trim( $row[ $col_map['POSTCODE'] ] ) !== $zip ) continue;
        
        $row_number = isset( $col_map['NUMBER'] ) && isset( $row[ $col_map['NUMBER'] ] ) ? trim( $row[ $col_map['NUMBER'] ] ) : '';
        $row_street = isset( $col_map['STREET'] ) && isset( $row[ $col_map['STREET'] ] ) ? strtolower( trim( $row[ $col_map['STREET'] ] ) ) : '';
        
        // Match by number and partial street name
        if ( $number && $row_number !== $number ) continue;
        if ( strpos( $row_street, $street_search ) === false && strpos( $street_search, $row_street ) === false ) continue;
        
        $unit = isset( $col_map['UNIT'] ) && isset( $row[ $col_map['UNIT'] ] ) ? trim( $row[ $col_map['UNIT'] ] ) : '';
        $city = isset( $col_map['CITY'] ) && isset( $row[ $col_map['CITY'] ] ) ? trim( $row[ $col_map['CITY'] ] ) : '';
        
        $label = $row_number . ' ' . $row[ $col_map['STREET'] ];
        if ( $unit ) $label .= ' Unit ' . $unit;
        if ( $city ) $label .= ', ' . $city;
        
        $results[] = array(
            'label' => $label,
            'housenumber' => $row_number,
            'street' => $row[ $col_map['STREET'] ],
            'unit' => $unit,
            'city' => $city,
            'state' => isset( $col_map['REGION'] ) && isset( $row[ $col_map['REGION'] ] ) ? trim( $row[ $col_map['REGION'] ] ) : 'CT',
            'zip' => $zip,
            'lat' => isset( $col_map['LAT'] ) && isset( $row[ $col_map['LAT'] ] ) ? trim( $row[ $col_map['LAT'] ] ) : null,
            'lng' => isset( $col_map['LON'] ) && isset( $row[ $col_map['LON'] ] ) ? trim( $row[ $col_map['LON'] ] ) : null,
            'source' => 'openaddresses'
        );
    }
    
    fclose( $handle );
    return $results;
}

// Helper: Search OSM Overpass for specific address
function subsales_search_osm_address( $address, $zip ) {
    // Extract street name from address
    $parts = explode( ' ', trim( $address ) );
    $number = '';
    $street = $address;
    
    if ( preg_match( '/^(\d+)\s+(.+)$/', $address, $matches ) ) {
        $number = $matches[1];
        $street = $matches[2];
    }
    
    $ql = '[out:json][timeout:10];';
    if ( $number ) {
        $ql .= '(node["addr:housenumber"="' . esc_attr( $number ) . '"]["addr:street"~"' . esc_attr( $street ) . '",i];';
        $ql .= 'way["addr:housenumber"="' . esc_attr( $number ) . '"]["addr:street"~"' . esc_attr( $street ) . '",i];);';
    } else {
        $ql .= '(node["addr:street"~"' . esc_attr( $street ) . '",i]["addr:postcode"="' . esc_attr( $zip ) . '"];';
        $ql .= 'way["addr:street"~"' . esc_attr( $street ) . '",i]["addr:postcode"="' . esc_attr( $zip ) . '"];);';
    }
    $ql .= 'out center;';
    
    $resp = wp_remote_post( 'https://overpass-api.de/api/interpreter', array(
        'body' => $ql,
        'timeout' => 15
    ) );
    
    if ( is_wp_error( $resp ) ) return array();
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    
    $results = array();
    if ( isset( $json['elements'] ) ) {
        foreach ( $json['elements'] as $el ) {
            $tags = isset( $el['tags'] ) ? $el['tags'] : array();
            if ( empty( $tags['addr:street'] ) ) continue;
            
            $results[] = array(
                'label' => ( isset( $tags['addr:housenumber'] ) ? $tags['addr:housenumber'] . ' ' : '' ) . $tags['addr:street'],
                'housenumber' => isset( $tags['addr:housenumber'] ) ? $tags['addr:housenumber'] : '',
                'street' => $tags['addr:street'],
                'city' => isset( $tags['addr:city'] ) ? $tags['addr:city'] : '',
                'state' => isset( $tags['addr:state'] ) ? $tags['addr:state'] : '',
                'zip' => isset( $tags['addr:postcode'] ) ? $tags['addr:postcode'] : $zip,
                'lat' => isset( $el['lat'] ) ? $el['lat'] : ( isset( $el['center']['lat'] ) ? $el['center']['lat'] : null ),
                'lng' => isset( $el['lon'] ) ? $el['lon'] : ( isset( $el['center']['lon'] ) ? $el['center']['lon'] : null ),
                'source' => 'osm'
            );
        }
    }
    return $results;
}

// AJAX handler to delete ZIP extract files
add_action( 'wp_ajax_subsales_delete_zip_extract', 'subsales_delete_zip_extract' );
function subsales_delete_zip_extract() {
    check_ajax_referer( 'subsales_zip_delete', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $zip = isset( $_POST['zip'] ) ? sanitize_text_field( $_POST['zip'] ) : '';
    if ( ! preg_match( '/^[0-9]{5}$/', $zip ) ) {
        wp_send_json_error( 'Invalid ZIP code format' );
    }

    $upload = wp_upload_dir();
    $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    $file = $base . '/' . $zip . '.json';

    if ( ! file_exists( $file ) ) {
        wp_send_json_error( 'File does not exist' );
    }

    if ( ! unlink( $file ) ) {
        wp_send_json_error( 'Failed to delete file' );
    }

    // Update zip-index.json to reflect existing extracts
    $saved_zips = get_option( 'subsales_served_zips', array() );
    $saved_zips = array_values( array_diff( $saved_zips, array( $zip ) ) );
    update_option( 'subsales_served_zips', $saved_zips );
    subsales_update_zip_index();

    wp_send_json_success( array( 'message' => 'ZIP extract deleted successfully', 'zip' => $zip ) );
}

// AJAX handler to manually refresh zip-index.json
add_action( 'wp_ajax_subsales_refresh_zip_index', 'subsales_refresh_zip_index_ajax' );
function subsales_refresh_zip_index_ajax() {
    check_ajax_referer( 'subsales_refresh_index', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    
    $result = subsales_update_zip_index();
    
    if ( $result ) {
        // Get the updated list
        $upload = wp_upload_dir();
        $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
        $existing_zips = array();
        if ( is_dir( $zipdata_dir ) ) {
            $files = glob( $zipdata_dir . '*.json' );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    $basename = basename( $file, '.json' );
                    if ( preg_match( '/^[0-9]{5}$/', $basename ) ) {
                        $existing_zips[] = $basename;
                    }
                }
            }
        }
        sort( $existing_zips );
        wp_send_json_success( array( 'message' => 'Index refreshed successfully', 'zips' => $existing_zips ) );
    } else {
        wp_send_json_error( 'Failed to update index file' );
    }
}

// AJAX handler for sales mode toggle
add_action( 'wp_ajax_subsales_update_sales_mode', 'subsales_update_sales_mode_ajax' );
function subsales_update_sales_mode_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    check_ajax_referer( 'subsales_sales_mode', 'nonce' );
    
    $mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : '';
    
    if ( ! in_array( $mode, array( 'legacy', 'user' ), true ) ) {
        wp_send_json_error( 'Invalid mode. Must be "legacy" or "user".' );
    }
    
    update_option( 'subsales_sales_mode', $mode );
    
    wp_send_json_success( array( 'mode' => $mode ) );
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

    // Start logging
    $start_time = microtime( true );
    $user = wp_get_current_user();
    
    // Log ZIP generation start
    subsales_log( 'INFO', 'zip', 'ZIP extract generation started', array(
        'zip_count' => count( $zips ),
        'zips' => $zips
    ), 'admin', $user->ID, $user->display_name );
    
    $log_entry = array(
        'timestamp' => current_time( 'mysql' ),
        'user' => $user->display_name . ' (' . $user->user_login . ')',
        'zips_requested' => $zips,
        'results' => array(),
        'summary' => array()
    );

    $results = array();
    $total_addresses = 0;
    $success_count = 0;
    $error_count = 0;

    foreach ( $zips as $zip ) {
        $zip_start = microtime( true );
        
        // Get selected sources
        $sources = get_option( 'subsales_address_sources', array( 'osm', 'openaddresses' ) );
        $all_addresses = array();
        $source_counts = array();
        
        // Collect from each source
        foreach ( $sources as $source ) {
            if ( $source === 'osm' ) {
                $osm_data = subsales_generate_zip_from_overpass( $zip, $base );
                if ( isset( $osm_data['addresses'] ) ) {
                    $all_addresses = array_merge( $all_addresses, $osm_data['addresses'] );
                    $source_counts['osm'] = count( $osm_data['addresses'] );
                }
            } elseif ( $source === 'openaddresses' ) {
                $oa_data = subsales_fetch_openaddresses_data( $zip );
                $all_addresses = array_merge( $all_addresses, $oa_data );
                $source_counts['openaddresses'] = count( $oa_data );
            }
        }
        
        // Deduplicate by address label
        $unique = array();
        $seen = array();
        foreach ( $all_addresses as $addr ) {
            $key = md5( strtolower( isset( $addr['label'] ) ? $addr['label'] : '' ) );
            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = true;
                $unique[] = $addr;
            }
        }
        
        // Write to file
        $file = trailingslashit( $base ) . $zip . '.json';
        $written = file_put_contents( $file, wp_json_encode( $unique ) );
        
        $zip_duration = round( microtime( true ) - $zip_start, 2 );
        
        if ( $written === false ) {
            $res = array( 'error' => 'write_failed', 'message' => 'Failed to write JSON file' );
        } else {
            $res = array(
                'count' => count( $unique ),
                'file' => $file,
                'sources' => $source_counts,
                'duplicates_removed' => count( $all_addresses ) - count( $unique ),
                'message' => count( $unique ) . ' unique addresses from ' . count( $sources ) . ' source(s)'
            );
        }
        
        $res['duration'] = $zip_duration;
        $results[ $zip ] = $res;
        
        if ( isset( $res['count'] ) ) {
            $total_addresses += $res['count'];
            $success_count++;
        } else {
            $error_count++;
        }
        
        // Add a 2-second delay between requests to avoid rate limiting
        // (except for the last ZIP)
        if ( $zip !== end( $zips ) ) {
            sleep( 2 );
        }
    }

    // Update zip-index.json in the PWA directory with existing extract files
    subsales_update_zip_index();

    // Complete log entry
    $total_duration = round( microtime( true ) - $start_time, 2 );
    $log_entry['results'] = $results;
    $log_entry['summary'] = array(
        'total_zips' => count( $zips ),
        'successful' => $success_count,
        'failed' => $error_count,
        'total_addresses' => $total_addresses,
        'duration_seconds' => $total_duration,
        'source' => 'OpenStreetMap Overpass API'
    );

    // Save log to database
    subsales_save_generation_log( $log_entry );
    
    // Log ZIP generation completion
    subsales_log( 'INFO', 'zip', 'ZIP extract generation completed', array(
        'total_zips' => count( $zips ),
        'successful' => $success_count,
        'failed' => $error_count,
        'total_addresses' => $total_addresses,
        'duration_seconds' => $total_duration
    ), 'admin', $user->ID, $user->display_name );

    wp_send_json_success( $results );
}

// Helper: generate a single ZIP file by querying Overpass API for nodes/ways with addr:postcode and addr:housenumber
function subsales_generate_zip_from_overpass( $zip, $base_dir ) {
    $zip = preg_replace( '/[^0-9]/', '', (string) $zip );
    if ( strlen( $zip ) !== 5 ) return array( 'error' => 'invalid_zip' );

    // Two-phase query: First get addresses explicitly tagged with this postcode,
    // then get the bounding box of the postal code area and grab all addresses within it
    // This catches addresses that don't have addr:postcode explicitly tagged
    
    $ql = '[out:json][timeout:25];';
    // Phase 1: Get all elements tagged with this postcode
    $ql .= '(node["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];';
    $ql .= 'way["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];';
    $ql .= 'relation["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];';
    // Phase 2: Get boundary/postal_code area to find addresses within it
    $ql .= 'area["postal_code"="' . esc_attr( $zip ) . '"]->.searchArea;';
    $ql .= '(node["addr:housenumber"]["addr:street"](area.searchArea);';
    $ql .= 'way["addr:housenumber"]["addr:street"](area.searchArea););';
    $ql .= ');out center;';

    $overpass_url = 'https://overpass-api.de/api/interpreter';
    $args = array(
        'body' => $ql,
        'timeout' => 60,
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ),
    );
    $resp = wp_remote_post( $overpass_url, $args );
    if ( is_wp_error( $resp ) ) return array( 'error' => 'overpass_error', 'message' => $resp->get_error_message() );
    $code = wp_remote_retrieve_response_code( $resp );
    
    // Return detailed error for non-200 responses
    if ( $code !== 200 ) {
        $body = wp_remote_retrieve_body( $resp );
        $error_msg = '';
        
        if ( $code === 429 ) {
            $error_msg = 'Rate limited - please wait a few minutes before trying again';
        } elseif ( $code === 504 || $code === 503 ) {
            $error_msg = 'Server timeout/unavailable - try again later';
        } else {
            $error_msg = 'HTTP ' . $code . ( $body ? ' - ' . substr( $body, 0, 100 ) : '' );
        }
        
        return array( 'error' => 'overpass_status', 'status' => $code, 'message' => $error_msg );
    }

    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    if ( ! $json || ! isset( $json['elements'] ) ) return array( 'error' => 'no_elements', 'message' => 'No address data returned from API' );

    $out = array(); $seen = array();
    $total_elements = count( $json['elements'] );
    $skipped = 0;
    
    foreach ( $json['elements'] as $el ) {
        $tags = isset( $el['tags'] ) ? $el['tags'] : array();
        if ( empty( $tags['addr:housenumber'] ) || empty( $tags['addr:street'] ) ) {
            $skipped++;
            continue;
        }
        
        // Build address with unit/apartment number if present
        $address_parts = array( $tags['addr:housenumber'] );
        
        // Add unit/apartment/floor/door if available
        if ( ! empty( $tags['addr:unit'] ) ) {
            $address_parts[] = 'Unit ' . $tags['addr:unit'];
        } elseif ( ! empty( $tags['addr:flr'] ) ) {
            $address_parts[] = 'Floor ' . $tags['addr:flr'];
        }
        
        if ( ! empty( $tags['addr:door'] ) ) {
            $address_parts[] = 'Door ' . $tags['addr:door'];
        }
        
        $address_parts[] = $tags['addr:street'];
        
        $label_parts = array();
        $label_parts[] = implode( ' ', $address_parts );
        
        // Add housename/building name if present
        if ( ! empty( $tags['addr:housename'] ) ) {
            $label_parts[] = $tags['addr:housename'];
        }
        
        if ( ! empty( $tags['addr:city'] ) ) $label_parts[] = $tags['addr:city'];
        if ( ! empty( $tags['addr:state'] ) ) $label_parts[] = $tags['addr:state'];
        $label_parts[] = $zip;
        $label = implode( ', ', $label_parts );

        $lat = isset( $el['lat'] ) ? $el['lat'] : ( isset( $el['center']['lat'] ) ? $el['center']['lat'] : null );
        $lon = isset( $el['lon'] ) ? $el['lon'] : ( isset( $el['center']['lon'] ) ? $el['center']['lon'] : null );

        $k = md5( $label ); if ( isset( $seen[ $k ] ) ) continue; $seen[ $k ] = true;

        $out[] = array( 
            'id' => 'osm-' . $el['type'] . '-' . $el['id'], 
            'label' => $label, 
            'street' => $tags['addr:street'], 
            'housenumber' => $tags['addr:housenumber'],
            'unit' => ( ! empty( $tags['addr:unit'] ) ? $tags['addr:unit'] : '' ),
            'floor' => ( ! empty( $tags['addr:flr'] ) ? $tags['addr:flr'] : '' ),
            'door' => ( ! empty( $tags['addr:door'] ) ? $tags['addr:door'] : '' ),
            'housename' => ( ! empty( $tags['addr:housename'] ) ? $tags['addr:housename'] : '' ),
            'city' => (isset($tags['addr:city'])?$tags['addr:city']:''), 
            'state' => (isset($tags['addr:state'])?$tags['addr:state']:''), 
            'zip' => $zip, 
            'lat' => $lat, 
            'lng' => $lon 
        );
    }

    return array( 
        'addresses' => $out,
        'total_elements' => $total_elements,
        'skipped' => $skipped
    );
}

// Helper: Fetch addresses from Nominatim geocoding service
function subsales_fetch_openaddresses_data( $zip ) {
    // OpenAddresses.io provides bulk CSV files with comprehensive address coverage
    // The admin can download the full state file and extract only needed ZIP codes
    // to create a smaller, faster-to-read filtered file
    
    $upload = wp_upload_dir();
    // First try filtered file (created by "Extract ZIP Codes" button)
    $csv_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses.csv';
    
    // Fall back to full file if filtered doesn't exist
    if ( ! file_exists( $csv_path ) ) {
        $csv_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses-full.csv';
    }
    
    if ( ! file_exists( $csv_path ) ) {
        return array();
    }
    
    $out = array();
    $handle = fopen( $csv_path, 'r' );
    
    if ( $handle === false ) return array();
    
    // Read header row
    $header = fgetcsv( $handle );
    if ( ! $header ) {
        fclose( $handle );
        return array();
    }
    
    // Find column indices
    $col_map = array();
    foreach ( array( 'LON', 'LAT', 'NUMBER', 'STREET', 'UNIT', 'CITY', 'DISTRICT', 'REGION', 'POSTCODE' ) as $col ) {
        $idx = array_search( $col, $header );
        if ( $idx !== false ) $col_map[ $col ] = $idx;
    }
    
    // Read rows and filter by ZIP
    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( ! isset( $col_map['POSTCODE'] ) || ! isset( $row[ $col_map['POSTCODE'] ] ) ) continue;
        
        $postcode = trim( $row[ $col_map['POSTCODE'] ] );
        if ( $postcode !== $zip ) continue;
        
        $number = isset( $col_map['NUMBER'] ) && isset( $row[ $col_map['NUMBER'] ] ) ? trim( $row[ $col_map['NUMBER'] ] ) : '';
        $street = isset( $col_map['STREET'] ) && isset( $row[ $col_map['STREET'] ] ) ? trim( $row[ $col_map['STREET'] ] ) : '';
        
        if ( empty( $number ) || empty( $street ) ) continue;
        
        $unit = isset( $col_map['UNIT'] ) && isset( $row[ $col_map['UNIT'] ] ) ? trim( $row[ $col_map['UNIT'] ] ) : '';
        $city = isset( $col_map['CITY'] ) && isset( $row[ $col_map['CITY'] ] ) ? trim( $row[ $col_map['CITY'] ] ) : '';
        $state = isset( $col_map['REGION'] ) && isset( $row[ $col_map['REGION'] ] ) ? trim( $row[ $col_map['REGION'] ] ) : 'CT';
        $lat = isset( $col_map['LAT'] ) && isset( $row[ $col_map['LAT'] ] ) ? trim( $row[ $col_map['LAT'] ] ) : null;
        $lng = isset( $col_map['LON'] ) && isset( $row[ $col_map['LON'] ] ) ? trim( $row[ $col_map['LON'] ] ) : null;
        
        $label_parts = array( $number . ' ' . $street );
        if ( $unit ) $label_parts[0] .= ' Unit ' . $unit;
        if ( $city ) $label_parts[] = $city;
        if ( $state ) $label_parts[] = $state;
        $label_parts[] = $zip;
        
        $out[] = array(
            'id' => 'oa-' . md5( $number . $street . $unit . $zip ),
            'label' => implode( ', ', $label_parts ),
            'street' => $street,
            'housenumber' => $number,
            'unit' => $unit,
            'floor' => '',
            'door' => '',
            'housename' => '',
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'lat' => $lat,
            'lng' => $lng
        );
    }
    
    fclose( $handle );
    return $out;
}

// Helper: update zip-index.json in the PWA directory with the list of EXISTING extract files
function subsales_update_zip_index( $zips = null ) {
    // Get the plugin directory path
    $pwa_dir = plugin_dir_path( __FILE__ ) . 'pwa/';
    $index_file = $pwa_dir . 'zip-index.json';
    
    // Get uploads directory for the ZIP data files
    $upload = wp_upload_dir();
    $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
    
    // Use relative path to avoid mixed content issues (HTTPS/HTTP)
    // This works whether the site is on HTTP or HTTPS
    $zipdata_relative_path = '/wp-content/uploads/subsales-zipdata/';
    
    // Scan the uploads directory for existing .json files
    $existing_zips = array();
    if ( is_dir( $zipdata_dir ) ) {
        $files = glob( $zipdata_dir . '*.json' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                $basename = basename( $file, '.json' );
                // Validate it's a 5-digit ZIP code
                if ( preg_match( '/^[0-9]{5}$/', $basename ) ) {
                    $existing_zips[] = $basename;
                }
            }
        }
    }
    
    // Sort for consistent ordering
    sort( $existing_zips );
    
    // Create index with existing ZIP codes using relative path
    $index_data = array(
        'zips' => $existing_zips,
        'baseUrl' => $zipdata_relative_path
    );
    
    // Write the ZIP list as JSON
    $json = wp_json_encode( $index_data, JSON_PRETTY_PRINT );
    $written = file_put_contents( $index_file, $json );
    
    return $written !== false;
}

// Helper: save generation log to WordPress options (keep last 20 entries)
function subsales_save_generation_log( $log_entry ) {
    $logs = get_option( 'subsales_generation_logs', array() );
    
    // Prepend new entry to beginning of array
    array_unshift( $logs, $log_entry );
    
    // Keep only last 20 entries
    $logs = array_slice( $logs, 0, 20 );
    
    update_option( 'subsales_generation_logs', $logs );
}

// Helper: get generation logs
function subsales_get_generation_logs( $limit = 10 ) {
    $logs = get_option( 'subsales_generation_logs', array() );
    return array_slice( $logs, 0, $limit );
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
    
    // ZIP admin JS for Address Extracts tab
    wp_enqueue_script( 'subsales-zip-admin', SUBSALES_PLUGIN_URL . 'assets/js/subsales-zip-admin.js', array( 'jquery' ), SUBSALES_VERSION, true );
    wp_localize_script( 'subsales-zip-admin', 'SubsalesZipAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'subsales_zip_generate' ),
        'deleteNonce' => wp_create_nonce( 'subsales_zip_delete' ),
        'searchNonce' => wp_create_nonce( 'subsales_address_search' ),
        'refreshIndexNonce' => wp_create_nonce( 'subsales_refresh_index' ),
    ) );
}

// Teams and Orders pages, DB functions, REST routes, etc. (merged implementation)
// Implementation merged from legacy files; shortcode name is 'subsales_pwa'.

// -- Teams management, orders page, DB creation and helpers --
// ============================================================
// DATABASE WRAPPER FUNCTIONS (for backward compatibility)
// All database operations delegated to Subsales_Database class
// ============================================================

function order_sync_create_table() {
    Subsales_Database::create_tables();
}

function order_sync_add_team( $name, $access_code, $description = '' ) {
    return Subsales_Database::add_team( $name, $access_code, $description );
}

function order_sync_remove_team( $team_id ) {
    return Subsales_Database::remove_team( $team_id );
}

function order_sync_get_teams() {
    return Subsales_Database::get_teams();
}

function order_sync_get_team_by_credentials( $team_name, $access_code ) {
    return Subsales_Database::get_team_by_credentials( $team_name, $access_code );
}

function subsales_log( $level, $category, $message, $context = array(), $source = 'admin', $user_id = null, $user_name = '' ) {
    Subsales_Database::log( $level, $category, $message, $context, $source, $user_id, $user_name );
}

function subsales_log_order( $action, $order_id, $user_id = null, $user_name = '', $context = array(), $source = 'admin' ) {
    Subsales_Database::log_order( $action, $order_id, $user_id, $user_name, $context, $source );
}

function subsales_log_auth( $action, $user_id = null, $user_name = '', $context = array(), $source = 'pwa' ) {
    Subsales_Database::log_auth( $action, $user_id, $user_name, $context, $source );
}

function subsales_log_api_error( $endpoint, $error_message, $context = array(), $source = 'api' ) {
    Subsales_Database::log_api_error( $endpoint, $error_message, $context, $source );
}

function subsales_cleanup_old_logs() {
    Subsales_Database::cleanup_old_logs();
}

function subsales_check_debug_timeout() {
    Subsales_Database::check_debug_timeout();
}

function subsales_log_order_change( $order_db_id, $order_id, $before_data, $after_data, $edit_type, $user_id, $user_name, $edit_reason = '', $source = 'admin' ) {
    return Subsales_Database::log_order_change( $order_db_id, $order_id, $before_data, $after_data, $edit_type, $user_id, $user_name, $edit_reason, $source );
}

function order_sync_add_team_member( $team_id, $name, $email, $role = 'member' ) {
    return Subsales_Database::add_team_member( $team_id, $name, $email, $role );
}

function order_sync_remove_team_member( $member_id ) {
    return Subsales_Database::remove_team_member( $member_id );
}

function order_sync_get_team_members_by_team( $team_id ) {
    return Subsales_Database::get_team_members_by_team( $team_id );
}

function order_sync_verify_team_member( $email, $team_id ) {
    return Subsales_Database::verify_team_member( $email, $team_id );
}

// ============================================================
// End of Database Wrapper Functions
// ============================================================


// ============================================================
// REST API ROUTES
// Routes now registered via Subsales_REST_API class
// Handler functions remain below for compatibility
// ============================================================


// AJAX endpoint for admin orders filtering/pagination
add_action( 'wp_ajax_subsales_fetch_orders', 'order_sync_fetch_orders_ajax' );

// AJAX handler to get order by database ID
add_action( 'wp_ajax_subsales_get_order_by_db_id', 'subsales_get_order_by_db_id_ajax' );
function subsales_get_order_by_db_id_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    
    // Simple nonce check
    if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'wp_rest' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    
    $db_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    
    if ( ! $db_id ) {
        wp_send_json_error( 'Missing order ID' );
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';
    
    $order = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $db_id ),
        ARRAY_A
    );
    
    if ( ! $order ) {
        wp_send_json_error( 'Order not found' );
    }
    
    // Parse order_data JSON
    $order['order_data'] = json_decode( $order['order_data'], true );
    
    wp_send_json_success( $order );
}

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
    $tally_status = isset( $_POST['tally_status'] ) ? sanitize_text_field( $_POST['tally_status'] ) : 'all';
    $show_deleted = isset( $_POST['show_deleted'] ) && $_POST['show_deleted'] === '1';
    $page = isset( $_POST['page'] ) ? max(1,intval($_POST['page'])) : 1;
    $page_size = isset( $_POST['page_size'] ) ? intval( $_POST['page_size'] ) : 100;
    if ( $page_size <= 0 || $page_size > 100 ) $page_size = 100;

    $where = array();
    $params = array();
    
    // Exclude deleted orders by default
    if ( ! $show_deleted ) {
        $where[] = "deleted = 0";
    }
    
    // Tally filter
    if ( $tally_status === 'untallied' ) {
        $where[] = "tallied = 0";
    } elseif ( $tally_status === 'tallied' ) {
        $where[] = "tallied = 1";
    }
    // 'all' means no tally filter

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

    // Load configured products once for building products_map
    $configured_products = order_sync_get_products_config();

    $orders = array();
    $totals = array( 'cash' => 0.0, 'check' => 0.0, 'grand' => 0.0, 'donations' => 0.0, 'product_totals' => array() );
    // initialize product totals for the page
    foreach ( $configured_products as $pconf ) {
        $totals['product_totals'][ $pconf['id'] ] = 0;
    }

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
        if ( isset( $od['donationAmount'] ) ) {
            $donation = floatval( $od['donationAmount'] );
            $order_total += $donation;
            $totals['donations'] += $donation;
        }

        $payment = '';;
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

        // Get entered_by_name - try from order_data first, then lookup by user_id
        $entered_by_name = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : '';
        if ( empty( $entered_by_name ) && ! empty( $r['user_id'] ) ) {
            // Try to look up user name from team_members table
            $user_row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT name FROM {$wpdb->prefix}order_sync_team_members WHERE id = %d", 
                intval( $r['user_id'] ) 
            ) );
            if ( $user_row ) {
                $entered_by_name = $user_row->name;
            }
        }
        // Normalize created_at to site-local timezone for display and timestamp calculations.
        // Stored DB values may be in GMT/UTC; use get_date_from_gmt() to convert to site local time.
        $created_gmt = isset( $r['created_at'] ) ? $r['created_at'] : null;
        if ( $created_gmt ) {
            // get_date_from_gmt returns a formatted date string in the site's timezone
            $created_local_str = get_date_from_gmt( $created_gmt );
            $created_ts = strtotime( $created_local_str );
            $created_formatted = date_i18n( 'M j, Y g:i A', $created_ts );
        } else {
            $created_local_str = $created_gmt;
            $created_ts = $created_gmt ? strtotime( $created_gmt ) : null;
            $created_formatted = $created_ts ? date_i18n( 'M j, Y g:i A', $created_ts ) : '';
        }

        // Determine whether this order has been edited by checking sync_status
        // 'synced' = original order from PWA, 'updated' = edited by admin
        $edited = ( isset( $r['sync_status'] ) && $r['sync_status'] === 'updated' );
        
        // Get tallied info
        $tallied = isset( $r['tallied'] ) && intval( $r['tallied'] ) === 1;
        $tallied_at = isset( $r['tallied_at'] ) ? $r['tallied_at'] : null;
        $tallied_by = null;
        if ( $tallied && isset( $r['tallied_by_user_id'] ) ) {
            $tallied_user = get_userdata( intval( $r['tallied_by_user_id'] ) );
            if ( $tallied_user ) {
                $tallied_by = $tallied_user->display_name;
            }
        }

        $orders[] = array(
            'id' => isset( $r['id'] ) ? intval( $r['id'] ) : null,
            'order_id' => $r['order_id'],
            'created_at' => $created_local_str,
            'created_at_formatted' => $created_formatted,
            'created_at_ts' => $created_ts,
            'user_id' => $r['user_id'],
            'entered_by_name' => $entered_by_name,
            'team_name' => $team_name,
            'items' => implode( ', ', $items_arr ),
            'order_total' => round( $order_total, 2 ),
            'donation_amount' => isset( $od['donationAmount'] ) ? floatval( $od['donationAmount'] ) : 0.0,
            'payment' => $payment,
            'payment_display' => $payment ? ucfirst($payment) : '',
            'edited' => $edited,
            'tallied' => $tallied,
            'tallied_at' => $tallied_at,
            'tallied_by' => $tallied_by,
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

// Handle administrative CSV export (no routing) - one row per normalized address with aggregated product counts
add_action( 'admin_post_subsales_generate_admin_csv', 'order_sync_handle_generate_admin_csv' );
function order_sync_handle_generate_admin_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'subsales_generate_admin_csv' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';
    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
    }

    $configured_products = order_sync_get_products_config();

    // group by normalized address
    $by_address = array();
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) $od = array();
        $products_map = array();
        foreach ( $configured_products as $pconf ) { $products_map[ $pconf['id'] ] = 0; }

        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                if ( $qty > 0 && $pid ) {
                    if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                    $products_map[ $pid ] += $qty;
                }
            }
        } else {
            foreach ( $configured_products as $p ) {
                $pid = $p['id'];
                $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                foreach ( $labels as $k ) {
                    if ( isset( $od[ $k ] ) ) {
                        $q = intval( $od[ $k ] );
                        if ( $q > 0 ) { if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0; $products_map[ $pid ] += $q; }
                        break;
                    }
                }
            }
        }

        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue; // skip donations/empty

        $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
        $addr_norm = order_sync_normalize_address( $address_raw );
        if ( empty( $addr_norm ) ) continue;

        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : $r['user_id'] ) );
        $customer = isset( $od['customerName'] ) ? $od['customerName'] : ( isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['name'] ) ? $od['name'] : '' ) );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['cell'] ) ? $od['cell'] : ( isset( $od['phone'] ) ? $od['phone'] : '' ) );

        if ( ! isset( $by_address[ $addr_norm ] ) ) $by_address[ $addr_norm ] = array( 'address_raw' => $address_raw, 'products' => array(), 'order_ids' => array(), 'customer' => $customer, 'phone' => $phone, 'seller' => $seller );
        foreach ( $products_map as $pid => $q ) { if ( ! isset( $by_address[ $addr_norm ]['products'][ $pid ] ) ) $by_address[ $addr_norm ]['products'][ $pid ] = 0; $by_address[ $addr_norm ]['products'][ $pid ] += intval( $q ); }
        $by_address[ $addr_norm ]['order_ids'][] = $r['order_id'];
    }

    if ( empty( $by_address ) ) {
        $msg = rawurlencode( 'No valid product orders found for ' . $delivery_date );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    // Stream CSV
    $filename = 'administrative_delivery_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    $out = fopen( 'php://output', 'w' );
    $headers = array( 'Address' );
    foreach ( $configured_products as $pcol ) { $headers[] = $pcol['name']; }
    $headers = array_merge( $headers, array( 'Customer Name', 'Phone', 'Seller', 'Order IDs' ) );
    fputcsv( $out, $headers );

    foreach ( $by_address as $addr_norm => $g ) {
        $row = array();
        $row[] = $g['address_raw'];
        foreach ( $configured_products as $pcol ) { $pid = $pcol['id']; $row[] = isset( $g['products'][ $pid ] ) ? intval( $g['products'][ $pid ] ) : 0; }
        $row[] = $g['customer'];
        $row[] = $g['phone'];
        $row[] = $g['seller'];
        $row[] = implode('|', $g['order_ids']);
        fputcsv( $out, $row );
    }
    fclose( $out );
    exit;
}

// XLSX (printable workbook) generation — try PhpSpreadsheet and fall back to CSV
add_action( 'admin_post_subsales_generate_delivery_xlsx', 'order_sync_handle_generate_delivery_xlsx' );
function order_sync_handle_generate_delivery_xlsx() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce_xlsx'] ) || ! wp_verify_nonce( $_POST['_wpnonce_xlsx'], 'subsales_generate_delivery_xlsx' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';

    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( $_POST['start_address'] ) : '';
    update_option( 'order_sync_delivery_start_address', $start_address );
    $driver_count = isset( $_POST['driver_count'] ) ? max(1, intval( $_POST['driver_count'] )) : 2;
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
    }

    $configured_products = order_sync_get_products_config();

    // Build orders grouped by normalized address (reusing CSV logic)
    $by_address = array();
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) $od = array();
        $products_map = array();
        foreach ( $configured_products as $pconf ) { $products_map[ $pconf['id'] ] = 0; }
        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                if ( $qty > 0 && $pid ) {
                    if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                    $products_map[ $pid ] += $qty;
                }
            }
        } else {
            foreach ( $configured_products as $p ) {
                $pid = $p['id'];
                $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                foreach ( $labels as $k ) {
                    if ( isset( $od[ $k ] ) ) {
                        $q = intval( $od[ $k ] );
                        if ( $q > 0 ) {
                            if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                            $products_map[ $pid ] += $q;
                        }
                        break;
                    }
                }
            }
        }
        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue;
        $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
        $addr_norm = order_sync_normalize_address( $address_raw );
        if ( empty( $addr_norm ) ) continue;
        $team_name = '';
        if ( ! empty( $r['team_id'] ) ) {
            $t = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}order_sync_teams WHERE id = %d", intval( $r['team_id'] ) ) );
            $team_name = $t ? $t->name : '';
        }
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : $r['user_id'] ) );
        $customer = isset( $od['customerName'] ) ? $od['customerName'] : ( isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['name'] ) ? $od['name'] : '' ) );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['cell'] ) ? $od['cell'] : ( isset( $od['phone'] ) ? $od['phone'] : '' ) );

        $entry = array(
            'order_id' => $r['order_id'],
            'team' => $team_name,
            'seller' => $seller,
            'address_raw' => $address_raw,
            'address_norm' => $addr_norm,
            'products_map' => $products_map,
            'customer' => $customer,
            'phone' => $phone,
            'total_qty' => $total_qty,
        );

        if ( ! isset( $by_address[ $addr_norm ] ) ) {
            $by_address[ $addr_norm ] = array( 'address_raw' => $address_raw, 'orders' => array() );
        }
        $by_address[ $addr_norm ]['orders'][] = $entry;
    }

    if ( empty( $by_address ) ) {
        $msg = rawurlencode( 'No valid product orders found for ' . $delivery_date );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    $manifest_rows = array();
    foreach ( $by_address as $addr_norm => $group ) {
        $combined_products = array();
        foreach ( $configured_products as $p ) { $combined_products[ $p['id'] ] = 0; }
        $order_ids = array();
        $team = '';
        $seller = '';
        $customer = '';
        $phone = '';
        foreach ( $group['orders'] as $o ) {
            $order_ids[] = $o['order_id'];
            foreach ( $o['products_map'] as $pid => $q ) { if ( ! isset( $combined_products[ $pid ] ) ) $combined_products[ $pid ] = 0; $combined_products[ $pid ] += intval( $q ); }
            if ( empty( $team ) && ! empty( $o['team'] ) ) $team = $o['team'];
            if ( empty( $seller ) && ! empty( $o['seller'] ) ) $seller = $o['seller'];
            if ( empty( $customer ) && ! empty( $o['customer'] ) ) $customer = $o['customer'];
            if ( empty( $phone ) && ! empty( $o['phone'] ) ) $phone = $o['phone'];
        }
        $manifest_rows[] = array(
            'address_raw' => $group['address_raw'],
            'address_norm' => $addr_norm,
            'products_map' => $combined_products,
            'order_ids' => $order_ids,
            'team' => $team,
            'seller' => $seller,
            'customer' => $customer,
            'phone' => $phone,
        );
    }

    // Assign drivers
    $drivers = array(); $driver_counts = array();
    for ( $i = 1; $i <= $driver_count; $i++ ) { $drivers[ $i ] = array(); $driver_counts[ $i ] = 0; }
    usort( $manifest_rows, function( $a, $b ) {
        $ca = isset( $a['order_ids'] ) ? count( $a['order_ids'] ) : 0;
        $cb = isset( $b['order_ids'] ) ? count( $b['order_ids'] ) : 0;
        return $cb - $ca;
    } );
    foreach ( $manifest_rows as $mr ) {
        $count_here = isset( $mr['order_ids'] ) ? count( $mr['order_ids'] ) : 1;
        $min_driver = null; $min_count = null;
        foreach ( $driver_counts as $dnum => $cnt ) { if ( $min_driver === null || $cnt < $min_count ) { $min_driver = $dnum; $min_count = $cnt; } }
        $drivers[ $min_driver ][] = $mr;
        $driver_counts[ $min_driver ] += $count_here;
    }

    // If PhpSpreadsheet available, generate XLSX with one sheet per driver, formatted for printing
    if ( class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheetIndex = 0;
            foreach ( $drivers as $dnum => $drows ) {
                if ( $sheetIndex > 0 ) $spreadsheet->createSheet();
                $sheet = $spreadsheet->setActiveSheetIndex( $sheetIndex );
                $title = 'Driver ' . $dnum;
                // Excel sheet title max length 31
                $sheet->setTitle( substr( $title, 0, 31 ) );

                // Build header columns: Address, <products...>, Customer Name, Phone, Seller
                $columns = array( 'Address' );
                foreach ( $configured_products as $pcol ) { $columns[] = $pcol['name']; }
                $columns = array_merge( $columns, array( 'Customer Name', 'Phone', 'Seller' ) );
                $colCount = count( $columns );

                // Merge top row for driver/title
                $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colCount );
                $sheet->mergeCells( 'A1:' . $lastColLetter . '1' );
                $sheet->setCellValue( 'A1', 'Driver ' . $dnum . ( $delivery_date ? ' — ' . $delivery_date : '' ) );

                // Header labels on row 2
                $r = 2; $c = 1;
                foreach ( $columns as $col ) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $r;
                    $sheet->setCellValue( $cell, $col );
                    $c++;
                }

                // Data rows starting at row 3
                $rowNum = 3;
                foreach ( $drows as $rdata ) {
                    $c = 1;
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['address_raw'] ); $c++;
                    foreach ( $configured_products as $pcol ) {
                        $pid = $pcol['id'];
                        $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, isset( $rdata['products_map'][ $pid ] ) ? intval( $rdata['products_map'][ $pid ] ) : 0 ); $c++;
                    }
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['customer'] ); $c++;
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['phone'] ); $c++;
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['seller'] );
                    $rowNum++;
                }

                // Totals row
                $totalsRow = $rowNum;
                $c = 1;
                $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $totalsRow, 'Totals' ); $c++;
                $startDataRow = 3;
                $endDataRow = $rowNum - 1;
                foreach ( $configured_products as $pcol ) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c );
                    if ( $endDataRow >= $startDataRow ) {
                        $sheet->setCellValue( $colLetter . $totalsRow, "=SUM({$colLetter}{$startDataRow}:{$colLetter}{$endDataRow})" );
                    } else {
                        $sheet->setCellValue( $colLetter . $totalsRow, 0 );
                    }
                    $c++;
                }

                // Column widths: Address wider, product columns moderate, others wider
                $sheet->getColumnDimension( 'A' )->setWidth( 50 );
                $colIndex = 2;
                foreach ( $configured_products as $pcol ) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex );
                    $sheet->getColumnDimension( $colLetter )->setWidth( 14 );
                    $colIndex++;
                }
                    $sheet->getColumnDimension( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex ) )->setWidth( 28 ); $colIndex++;
                $sheet->getColumnDimension( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex ) )->setWidth( 18 ); $colIndex++;
                $sheet->getColumnDimension( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex ) )->setWidth( 22 );

                // Page setup: landscape, fit to width
                $sheet->getPageSetup()->setOrientation( \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE );
                $sheet->getPageSetup()->setPaperSize( \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LETTER );
                $sheet->getPageSetup()->setFitToPage( true );
                $sheet->getPageSetup()->setFitToWidth( 1 );
                $sheet->getPageSetup()->setFitToHeight( 0 );
                $sheet->getPageSetup()->setHorizontalCentered( true );

                // Styling: bold for title and headers
                $sheet->getStyle( 'A1:' . $lastColLetter . '2' )->getFont()->setBold( true );

                $sheetIndex++;
            }

            // send workbook
            $spreadsheet->setActiveSheetIndex( 0 );
            $filename = 'delivery_manifest_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.xlsx';
            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
            $writer->save( 'php://output' );
            exit;
        } catch ( Exception $e ) {
            // fall through to CSV fallback
        }
    }

    // Fallback: stream CSV (same format as existing CSV export)
    $filename = 'delivery_manifest_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    $out = fopen( 'php://output', 'w' );
    $headers = array( 'Driver', 'Address' );
    foreach ( $configured_products as $pcol ) { $headers[] = $pcol['name']; }
    $headers = array_merge( $headers, array( 'Customer Name', 'Phone', 'Seller' ) );
    fputcsv( $out, $headers );
    foreach ( $drivers as $dnum => $drows ) {
        foreach ( $drows as $r ) {
            $row = array();
            $row[] = $dnum;
            $row[] = $r['address_raw'];
            foreach ( $configured_products as $pcol ) { $pid = $pcol['id']; $row[] = isset( $r['products_map'][ $pid ] ) ? intval( $r['products_map'][ $pid ] ) : 0; }
            $row[] = $r['customer'];
            $row[] = $r['phone'];
            $row[] = $r['seller'];
            fputcsv( $out, $row );
        }
    }
    fclose( $out );
    exit;
}

// PDF Individual Manifest Generation - group by entered_by and optimize routes
add_action( 'admin_post_subsales_generate_delivery_pdf', 'order_sync_handle_generate_delivery_pdf' );
function order_sync_handle_generate_delivery_pdf() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'subsales_generate_delivery' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';

    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( $_POST['start_address'] ) : '';
    
    if ( empty( $start_address ) ) {
        wp_die( 'Starting address (depot) is required' );
    }
    
    update_option( 'order_sync_delivery_start_address', $start_address );
    
    // Geocode starting address
    $start_coords = order_sync_geocode_address( $start_address );
    if ( ! $start_coords ) {
        wp_die( 'Could not geocode starting address. Please check your Google Maps API key and address.' );
    }

    // Fetch orders
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s AND deleted = 0 ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY id ASC", ARRAY_A );
    }

    if ( empty( $rows ) ) {
        $msg = rawurlencode( 'No orders found for ' . ( $delivery_date ?: 'all dates' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    $configured_products = order_sync_get_products_config();

    // Group orders by individual (entered_by_id or entered_by_name)
    $by_individual = array();
    
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) continue;

        // Determine individual identifier
        $individual_id = '';
        $individual_name = '';
        
        if ( ! empty( $od['entered_by_id'] ) ) {
            $individual_id = $od['entered_by_id'];
            $individual_name = ! empty( $od['entered_by_name'] ) ? $od['entered_by_name'] : $individual_id;
        } elseif ( ! empty( $od['entered_by_name'] ) ) {
            $individual_name = $od['entered_by_name'];
            $individual_id = $individual_name;
        } elseif ( ! empty( $r['user_id'] ) ) {
            $individual_id = $r['user_id'];
            $individual_name = $r['user_id'];
        } else {
            $individual_id = 'unknown';
            $individual_name = 'Unknown';
        }

        // Extract product quantities
        $products_map = array();
        foreach ( $configured_products as $pconf ) {
            $products_map[ $pconf['id'] ] = 0;
        }

        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                if ( $qty > 0 && $pid && isset( $products_map[ $pid ] ) ) {
                    $products_map[ $pid ] += $qty;
                }
            }
        }

        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue;

        $address = isset( $od['address'] ) ? $od['address'] : '';
        $unitFloorApt = isset( $od['unitFloorApt'] ) ? $od['unitFloorApt'] : '';
        if ( ! empty( $unitFloorApt ) ) {
            $address .= ', ' . $unitFloorApt;
        }
        
        if ( empty( $address ) ) continue;

        $customer = isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['customerName'] ) ? $od['customerName'] : '' );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['phone'] ) ? $od['phone'] : '' );
        $notes = isset( $od['notes'] ) ? $od['notes'] : '';

        // Geocode address
        $coords = order_sync_geocode_address( $address );

        $order_entry = array(
            'order_id' => $r['order_id'],
            'address' => $address,
            'customer' => $customer,
            'phone' => $phone,
            'notes' => $notes,
            'products_map' => $products_map,
            'lat' => $coords ? $coords['lat'] : null,
            'lng' => $coords ? $coords['lng'] : null,
        );

        if ( ! isset( $by_individual[ $individual_id ] ) ) {
            $by_individual[ $individual_id ] = array(
                'name' => $individual_name,
                'orders' => array(),
            );
        }

        $by_individual[ $individual_id ]['orders'][] = $order_entry;
    }

    if ( empty( $by_individual ) ) {
        $msg = rawurlencode( 'No valid orders with products found' );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    // Generate PDF for each individual
    $pdf_files = array();
    
    foreach ( $by_individual as $individual_id => $data ) {
        $individual_name = $data['name'];
        $orders = $data['orders'];

        // Optimize route using nearest-neighbor algorithm
        $optimized_orders = order_sync_optimize_route( $orders, $start_coords );

        // Generate PDF
        $pdf_content = order_sync_generate_manifest_pdf( $individual_name, $optimized_orders, $start_address, $configured_products );
        
        $filename = 'manifest-' . sanitize_file_name( $individual_name ) . '-' . date('Ymd') . '.pdf';
        $pdf_files[ $filename ] = $pdf_content;
    }

    // If single individual, download PDF directly
    if ( count( $pdf_files ) === 1 ) {
        $filename = array_keys( $pdf_files )[0];
        $content = array_values( $pdf_files )[0];
        
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $content ) );
        echo $content;
        exit;
    }

    // Multiple individuals - create ZIP
    $zipname = sys_get_temp_dir() . '/manifests-' . time() . '.zip';
    $za = new ZipArchive();
    if ( $za->open( $zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        wp_die( 'Could not create zip' );
    }

    foreach ( $pdf_files as $filename => $content ) {
        $za->addFromString( $filename, $content );
    }
    $za->close();

    // Send ZIP
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="delivery-manifests-' . date('Ymd_His') . '.zip"' );
    header( 'Content-Length: ' . filesize( $zipname ) );
    readfile( $zipname );
    @unlink( $zipname );
    exit;
}

// Helper: Optimize delivery route using nearest-neighbor algorithm
function order_sync_optimize_route( $orders, $start_coords ) {
    if ( empty( $orders ) ) return array();

    $optimized = array();
    $remaining = $orders;
    $current_lat = $start_coords['lat'];
    $current_lng = $start_coords['lng'];

    while ( ! empty( $remaining ) ) {
        $nearest_idx = null;
        $nearest_dist = PHP_FLOAT_MAX;

        foreach ( $remaining as $idx => $order ) {
            if ( $order['lat'] === null || $order['lng'] === null ) {
                // Handle orders without coordinates - add them at the end
                continue;
            }

            $dist = order_sync_haversine_distance( $current_lat, $current_lng, $order['lat'], $order['lng'] );
            
            if ( $dist < $nearest_dist ) {
                $nearest_dist = $dist;
                $nearest_idx = $idx;
            }
        }

        if ( $nearest_idx !== null ) {
            $optimized[] = $remaining[ $nearest_idx ];
            $current_lat = $remaining[ $nearest_idx ]['lat'];
            $current_lng = $remaining[ $nearest_idx ]['lng'];
            unset( $remaining[ $nearest_idx ] );
            $remaining = array_values( $remaining ); // Re-index
        } else {
            // All remaining orders have no coordinates - add them to the end
            foreach ( $remaining as $order ) {
                $optimized[] = $order;
            }
            break;
        }
    }

    return $optimized;
}

// Helper: Calculate distance between two lat/lng points (Haversine formula)
function order_sync_haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
    $earth_radius = 6371; // km

    $dLat = deg2rad( $lat2 - $lat1 );
    $dLon = deg2rad( $lon2 - $lon1 );

    $a = sin( $dLat / 2 ) * sin( $dLat / 2 ) +
         cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
         sin( $dLon / 2 ) * sin( $dLon / 2 );

    $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

    return $earth_radius * $c;
}

// Helper: Generate PDF manifest using DomPDF
function order_sync_generate_manifest_pdf( $individual_name, $orders, $start_address, $configured_products ) {
    // Load DomPDF
    $dompdf_autoload = plugin_dir_path( __FILE__ ) . 'vendor/dompdf/autoload.inc.php';
    if ( ! file_exists( $dompdf_autoload ) ) {
        wp_die( 'DomPDF library not found. Please reinstall the plugin.' );
    }
    
    require_once $dompdf_autoload;
    
    // Calculate product totals for packing list
    $product_totals = array();
    foreach ( $configured_products as $p ) {
        $product_totals[ $p['id'] ] = array( 'name' => $p['name'], 'qty' => 0 );
    }

    foreach ( $orders as $order ) {
        foreach ( $order['products_map'] as $pid => $qty ) {
            if ( isset( $product_totals[ $pid ] ) ) {
                $product_totals[ $pid ]['qty'] += $qty;
            }
        }
    }

    // Calculate total pages: 1 for packing list + number of delivery stops
    $total_pages = 1 + count( $orders );
    
    // Build HTML content
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 0.5in; }
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 0; font-size: 12pt; }
        h1 { font-size: 24pt; margin: 0 0 10px 0; }
        h2 { font-size: 18pt; margin: 20px 0 10px 0; }
        .depot { font-size: 11pt; margin-bottom: 20px; color: #666; }
        .page-number { font-size: 14pt; color: #666; margin-top: 10px; text-align: center; }
        .delivery-stop { margin-bottom: 15px; padding: 12px; border: 2px solid #ddd; page-break-inside: avoid; background: #f9f9f9; }
        .stop-number { font-size: 16pt; font-weight: bold; color: #0073aa; margin-bottom: 5px; }
        .address { font-size: 13pt; font-weight: bold; margin: 5px 0; }
        .customer { font-size: 11pt; margin: 3px 0; }
        .products { margin: 8px 0; }
        .products-table { width: 100%; border-collapse: collapse; margin: 5px 0; }
        .products-table th, .products-table td { border: 1px solid #999; padding: 6px; text-align: left; font-size: 10pt; }
        .products-table th { background: #e0e0e0; font-weight: bold; }
        .notes { font-size: 9pt; font-style: italic; color: #666; margin-top: 5px; padding: 5px; background: #fff3cd; border-left: 3px solid #ffc107; }
        .packing-list { margin-top: 40px; }
        .manifest-section { page-break-before: always; }
        .packing-table { width: 100%; border-collapse: collapse; font-size: 22pt; margin-top: 20px; }
        .packing-table th, .packing-table td { border: 3px solid #000; padding: 15px; text-align: left; }
        .packing-table th { background: #f0f0f0; font-weight: bold; }
        .packing-table .total-row { font-weight: bold; background: #d0d0d0; font-size: 24pt; }
    </style>
</head>
<body>';

    // Packing List (FIRST PAGE)
    $html .= '<div class="packing-list">';
    $html .= '<h1>Packing List: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
    $html .= '<table class="packing-table">';
    $html .= '<thead><tr><th>Product</th><th style="width:150px;text-align:center;">Quantity</th></tr></thead>';
    $html .= '<tbody>';
    
    $grand_total = 0;
    foreach ( $product_totals as $pid => $data ) {
        if ( $data['qty'] > 0 ) {
            $html .= '<tr><td>' . htmlspecialchars( $data['name'], ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $data['qty'] ) . '</td></tr>';
            $grand_total += $data['qty'];
        }
    }
    
    $html .= '<tr class="total-row"><td><strong>TOTAL ITEMS</strong></td><td style="text-align:center;"><strong>' . $grand_total . '</strong></td></tr>';
    $html .= '</tbody></table>';
    $html .= '<div class="page-number">Page 1 of ' . $total_pages . '</div>';
    $html .= '</div>';

    // Delivery Manifest (SUBSEQUENT PAGES)
    $html .= '<div class="manifest-section">';
    $html .= '<h1>Delivery Manifest: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
    $html .= '<div class="depot"><strong>Starting Point:</strong> ' . htmlspecialchars( $start_address, ENT_QUOTES, 'UTF-8' ) . '</div>';
    $html .= '<div class="depot"><strong>Total Stops:</strong> ' . count( $orders ) . ' | <strong>Date:</strong> ' . date('F j, Y') . '</div>';

    // Delivery stops
    $stop_num = 1;
    $page_num = 2; // Start at page 2 (packing list is page 1)
    foreach ( $orders as $order ) {
        $html .= '<div class="delivery-stop">';
        $html .= '<div class="stop-number">Stop #' . $stop_num . '</div>';
        $html .= '<div class="address">' . htmlspecialchars( $order['address'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        
        if ( ! empty( $order['customer'] ) ) {
            $html .= '<div class="customer"><strong>Customer:</strong> ' . htmlspecialchars( $order['customer'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        }
        
        if ( ! empty( $order['phone'] ) ) {
            $html .= '<div class="customer"><strong>Phone:</strong> ' . htmlspecialchars( $order['phone'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        }

        // Products for this stop
        $html .= '<div class="products"><strong>Products:</strong>';
        $html .= '<table class="products-table">';
        $html .= '<thead><tr><th>Product</th><th style="width:80px;text-align:center;">Qty</th></tr></thead><tbody>';
        $has_products = false;
        foreach ( $order['products_map'] as $pid => $qty ) {
            if ( $qty > 0 ) {
                $product_name = '';
                foreach ( $configured_products as $p ) {
                    if ( $p['id'] === $pid ) {
                        $product_name = $p['name'];
                        break;
                    }
                }
                $html .= '<tr><td>' . htmlspecialchars( $product_name, ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $qty ) . '</td></tr>';
                $has_products = true;
            }
        }
        if ( ! $has_products ) {
            $html .= '<tr><td colspan="2">No products</td></tr>';
        }
        $html .= '</tbody></table></div>';

        if ( ! empty( $order['notes'] ) ) {
            $html .= '<div class="notes"><strong>Delivery Notes:</strong> ' . htmlspecialchars( $order['notes'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        }

        $html .= '<div class="page-number">Page ' . $page_num . ' of ' . $total_pages . '</div>';
        $html .= '</div>';
        $stop_num++;
        $page_num++;
    }
    $html .= '</div>';

    $html .= '</body></html>';

    // Generate PDF using DomPDF
    try {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml( $html );
        $dompdf->setPaper( 'Letter', 'portrait' );
        $dompdf->render();
        
        return $dompdf->output();
    } catch ( Exception $e ) {
        wp_die( 'PDF generation failed: ' . $e->getMessage() );
    }
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
    global $wpdb;
    
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
    
    if ( ! empty( $team_name ) || ! empty( $access_code ) ) {
        $team = order_sync_get_team_by_credentials( $team_name, $access_code );
        if ( $team ) {
            error_log( 'Subsales: perm_check team creds ok id=' . ( isset($team['id']) ? $team['id'] : 'unknown' ) );
            return true;
        }
        error_log( 'Subsales: perm_check invalid team credentials provided (team=' . $team_name . ')' );
        return false;
    }
    
    // User-based auth: X-User-ID + X-Team-ID (Phase 4)
    $user_id = $request->get_header( 'X-User-ID' );
    $team_id = $request->get_header( 'X-Team-ID' );
    
    if ( ! empty( $user_id ) && ! empty( $team_id ) ) {
        $members_table = $wpdb->prefix . 'order_sync_team_members';
        $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
        
        // Verify user exists
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            intval( $user_id )
        ), ARRAY_A );
        
        if ( ! $user ) {
            error_log( 'Subsales: perm_check invalid user_id=' . $user_id );
            return false;
        }
        
        // Verify user belongs to the team
        $assignment = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
            intval( $user_id ),
            intval( $team_id )
        ));
        
        if ( $assignment ) {
            error_log( 'Subsales: perm_check user-based auth ok user_id=' . $user_id . ' team_id=' . $team_id );
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
    
    return current_user_can( 'edit_posts' );
}

// Admin-only permission callback for sensitive operations (edit, delete, restore, history)
function order_sync_check_admin_permissions( WP_REST_Request $request ) {
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

// Orders REST callbacks (get_orders, get_order_by_id, create_order, update_order, delete_order)
function get_orders( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
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

// Server time endpoint: returns current date and timestamp in site timezone plus GMT offset
function order_manager_get_server_time( WP_REST_Request $request ) {
    $ts = current_time( 'timestamp' );
    $date = date( 'Y-m-d', $ts );
    $gmt_offset = floatval( get_option( 'gmt_offset', 0 ) );
    return new WP_REST_Response( array( 'server_date' => $date, 'server_timestamp' => $ts, 'gmt_offset' => $gmt_offset ), 200 );
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
    
    // Check if order already exists (avoid duplicate key error)
    $existing_order = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM $table_name WHERE order_id = %s",
        $order_id
    ) );
    
    if ( $existing_order ) {
        // Order already exists - return success (idempotent)
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

    // Ensure created_at uses the WordPress site-local time (respects timezone settings)
    // so stored timestamps align with the site's timezone (avoid DB server UTC mismatch).
    $insert_row['created_at'] = current_time( 'mysql' );
    $formats[] = '%s';

    $result = $wpdb->insert( $table_name, $insert_row, $formats );

    if ( $result === false ) {
        // Log order creation failure
        subsales_log( 'ERROR', 'orders', 'Failed to create order', array(
            'order_id' => $order_id,
            'user_id' => $user_id,
            'team_id' => $team_id,
            'db_error' => $wpdb->last_error
        ), 'api', null, $user_id );
        
        return new WP_REST_Response( 'Failed to create order', 500 );
    }
    
    // Get the newly created order's DB ID for history logging
    $new_order_db_id = $wpdb->insert_id;
    
    // Log successful order creation
    subsales_log_order( 'created', $order_id, null, $user_id, array(
        'team_id' => $team_id,
        'db_id' => $new_order_db_id
    ), 'pwa' );
    
    // Log order creation in history table
    if ( $new_order_db_id && function_exists( 'subsales_log_order_change' ) ) {
        $order_data_array = json_decode( $order_data, true );
        if ( ! is_array( $order_data_array ) ) {
            $order_data_array = array();
        }
        
        // Determine who created the order
        $creator_name = isset( $order_data_array['entered_by_name'] ) ? $order_data_array['entered_by_name'] : '';
        if ( empty( $creator_name ) && ! empty( $user_id ) ) {
            // Try to look up user name from team_members table
            $user_row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT name FROM {$wpdb->prefix}order_sync_team_members WHERE id = %d", 
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
        subsales_log_order_change(
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

function update_order( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
    $order_id = $request->get_param( 'id' );
    $data = $request->get_json_params();
    
    // Get current user info (must be WordPress admin)
    $current_user = wp_get_current_user();
    if ( ! $current_user || ! $current_user->ID ) {
        return new WP_REST_Response( 'Unauthorized', 401 );
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
    $after_data = $data;
    
    // Get edit reason from request
    $edit_reason = isset( $data['_edit_reason'] ) ? sanitize_textarea_field( $data['_edit_reason'] ) : '';
    unset( $data['_edit_reason'] ); // Remove from order data
    
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
        subsales_log( 'ERROR', 'orders', 'Failed to update order', array(
            'order_id' => $order_id,
            'db_error' => $wpdb->last_error
        ), 'admin', $current_user->ID, $current_user->display_name );
        return new WP_REST_Response( 'Failed to update order', 500 );
    }
    
    // Log change to history (even if no rows changed, user may have submitted same data)
    subsales_log_order_change(
        $existing_order['id'], // DB id
        $order_id, // Order ID from data
        $before_data,
        $after_data,
        'update',
        $current_user->ID,
        $current_user->display_name,
        $edit_reason,
        'admin'
    );
    
    return new WP_REST_Response( array(
        'message' => 'Order updated successfully',
        'changes_logged' => true
    ), 200 );
}

function delete_order( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
    $order_id = $request->get_param( 'id' );
    $data = $request->get_json_params();
    
    // Get current user info (must be WordPress admin)
    $current_user = wp_get_current_user();
    if ( ! $current_user || ! $current_user->ID ) {
        return new WP_REST_Response( 'Unauthorized', 401 );
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
            'deleted_by_user_id' => $current_user->ID,
            'delete_reason' => $delete_reason
        ),
        array( 'order_id' => $order_id ),
        array( '%d', '%s', '%d', '%s' ),
        array( '%s' )
    );
    
    if ( $result === false ) {
        subsales_log( 'ERROR', 'orders', 'Failed to delete order', array(
            'order_id' => $order_id,
            'db_error' => $wpdb->last_error
        ), 'admin', $current_user->ID, $current_user->display_name );
        return new WP_REST_Response( 'Failed to delete order', 500 );
    }
    
    // Parse order data for history
    $order_data = json_decode( $existing_order['order_data'], true );
    
    // Log deletion to history
    subsales_log_order_change(
        $existing_order['id'], // DB id
        $order_id, // Order ID from data
        $order_data, // before
        $order_data, // after (same, just marked deleted)
        'delete',
        $current_user->ID,
        $current_user->display_name,
        $delete_reason,
        'admin'
    );
    
    return new WP_REST_Response( array(
        'message' => 'Order deleted successfully',
        'soft_delete' => true
    ), 200 );
}

// Get edit history for an order
function get_order_history( WP_REST_Request $request ) {
    global $wpdb;
    $history_table = $wpdb->prefix . 'order_edit_history';
    $orders_table = $wpdb->prefix . 'order_sync_orders';
    
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

// Restore a soft-deleted order
function restore_order( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
    
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
        subsales_log( 'ERROR', 'orders', 'Failed to restore order', array(
            'order_db_id' => $order_db_id,
            'order_id' => $existing_order['order_id'],
            'db_error' => $wpdb->last_error
        ), 'admin', $current_user->ID, $current_user->display_name );
        return new WP_REST_Response( 'Failed to restore order', 500 );
    }
    
    // Parse order data for history
    $order_data = json_decode( $existing_order['order_data'], true );
    
    // Log restoration to history
    subsales_log_order_change(
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

// Tally orders (mark as reconciled)
function tally_orders( WP_REST_Request $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'order_sync_orders';
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
            subsales_log_order_change(
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

// Team authentication API endpoints
function team_member_login( WP_REST_Request $request ) {
    global $wpdb;
    $data = $request->get_json_params();
    
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    
    // Legacy mode: Team + Access Code
    if ( $login_mode === 'legacy' ) {
        if ( ! isset( $data['team_name'] ) || ! isset( $data['access_code'] ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Missing team name or access code'
            ), 400 );
        }
        
        $team_name = sanitize_text_field( $data['team_name'] );
        $access_code = sanitize_text_field( $data['access_code'] );
        
        $team = order_sync_get_team_by_credentials( $team_name, $access_code );
        
        if ( $team ) {
            // Log successful legacy login
            subsales_log_auth( 'login', null, $team_name, array(
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
        subsales_log_auth( 'failed', null, $team_name, array(
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
        
        $name = sanitize_text_field( $data['name'] );
        $phone = preg_replace( '/[^0-9]/', '', sanitize_text_field( $data['phone'] ) );
        $team_id = isset( $data['team_id'] ) ? intval( $data['team_id'] ) : 0;
        
        if ( ! preg_match( '/^[0-9]{10}$/', $phone ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Phone number must be 10 digits'
            ), 400 );
        }
        
        // Find user by phone
        $members_table = $wpdb->prefix . 'order_sync_team_members';
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE phone = %s",
            $phone
        ), ARRAY_A );
        
        if ( ! $user ) {
            // Log failed user login - phone not found
            subsales_log_auth( 'failed', null, '', array(
                'mode' => 'user',
                'phone' => substr( $phone, 0, 3 ) . 'XXXXXXX', // Partial phone for privacy
                'reason' => 'invalid_phone'
            ), 'pwa' );
            
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid phone number'
            ), 401 );
        }
        
        // Verify name matches (case-insensitive partial match)
        if ( stripos( $user['name'], $name ) === false && stripos( $name, $user['name'] ) === false ) {
            // Log failed user login - name mismatch
            subsales_log_auth( 'failed', $user['id'], $user['name'], array(
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
        $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
        $teams_table = $wpdb->prefix . 'order_sync_teams';
        
        $team_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
            $user['id']
        ));
        
        if ( empty( $team_ids ) ) {
            // Log failed user login - no teams
            subsales_log_auth( 'failed', $user['id'], $user['name'], array(
                'mode' => 'user',
                'reason' => 'no_teams_assigned'
            ), 'pwa' );
            
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'User is not assigned to any teams'
            ), 403 );
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
                subsales_log_auth( 'failed', $user['id'], $user['name'], array(
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
        subsales_log_auth( 'login', $user['id'], $user['name'], array(
            'mode' => 'user',
            'team_count' => count( $teams ),
            'selected_team_id' => $selected_team ? $selected_team['id'] : null
        ), 'pwa' );
        
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
    
    // Get login mode (Phase 4)
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    
    // Get sales mode (Team vs Individual)
    $sales_mode = get_option( 'subsales_sales_mode', 'legacy' );
    
    // Get debug logging status (only expose when authenticated)
    $debug_logging_enabled = $is_authenticated ? get_option( 'subsales_debug_logging_enabled', false ) : false;

    return new WP_REST_Response( array(
        'google_maps_api_key' => $google_maps_api_key,
        'app_version' => SUBSALES_VERSION,
        'portal_url' => $portal_url,
        'brandName' => $branding,
        'brandingImage' => $header_image_url,
        'styleVariant' => get_option( 'order_sync_style_variant', 'default' ),
        'primaryColor' => get_option( 'order_sync_primary_color', '#2d6cdf' ),
        'sessionDuration' => intval( get_option( 'order_sync_session_duration', 86400000 ) ),
        'authenticated' => $is_authenticated,
        'products' => $products,
        'loginMode' => $login_mode,
        'salesMode' => $sales_mode,
        'debugLoggingEnabled' => $debug_logging_enabled
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

// ============================================================================
// User Management REST API (Phase 2)
// ============================================================================

/**
 * POST /wp-json/order-manager/v1/users
 * Create a new user
 */
function order_sync_create_user( WP_REST_Request $request ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
    $params = $request->get_json_params();
    $name = sanitize_text_field( $params['name'] ?? '' );
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
 * GET /wp-json/order-manager/v1/users
 * Get all users or filter by query params
 */
function order_sync_get_users( WP_REST_Request $request ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
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
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    
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
 * GET /wp-json/order-manager/v1/users/{id}
 * Get a single user by ID
 */
function order_sync_get_user_by_id( WP_REST_Request $request ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    
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
 * PUT /wp-json/order-manager/v1/users/{id}
 * Update a user
 */
function order_sync_update_user( WP_REST_Request $request ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
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
        $name = sanitize_text_field( $params['name'] );
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
 * DELETE /wp-json/order-manager/v1/users/{id}
 * Delete a user
 */
function order_sync_delete_user( WP_REST_Request $request ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    
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
 * GET /wp-json/order-manager/v1/users/search?q={query}
 * Search users by name or phone
 */
function order_sync_search_users( WP_REST_Request $request ) {
    global $wpdb;
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    
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

// ============================================================================
// Team Assignment REST API (Phase 3)
// ============================================================================

/**
 * GET /wp-json/order-manager/v1/users/{id}/teams
 * Get all teams for a specific user
 */
function order_sync_get_user_teams( WP_REST_Request $request ) {
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
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
 * POST /wp-json/order-manager/v1/teams/{id}/assign
 * Assign a user to a team
 * Body: { "user_id": 123 }
 */
function order_sync_assign_user_to_team( WP_REST_Request $request ) {
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
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
 * DELETE /wp-json/order-manager/v1/teams/{id}/users/{userId}
 * Remove a user from a team
 */
function order_sync_remove_user_from_team( WP_REST_Request $request ) {
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
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
 * GET /wp-json/order-manager/v1/teams/{id}/users
 * Get all users assigned to a specific team
 */
function order_sync_get_team_users( WP_REST_Request $request ) {
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    $members_table = $wpdb->prefix . 'order_sync_team_members';
    
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

/**
 * PWA Functions (Backward Compatibility Wrappers)
 * All PWA functionality now handled by Subsales_PWA class
 */

// Wrapper functions maintained for backward compatibility
// These are intentionally left empty as the PWA class registers hooks directly
// during Subsales_PWA::init() which is called early in plugin bootstrap.
// The add_action and add_shortcode calls below are also removed since they're
// now handled by the class.

function subsales_register_pwa_scripts() {
    // Deprecated: Now handled by Subsales_PWA::register_pwa_scripts() via class init hook
    // This wrapper kept for backward compatibility in case external code calls it directly
    Subsales_PWA::register_pwa_scripts();
}
// Note: add_action() removed - now handled by Subsales_PWA::init()

function subsales_pwa_shortcode( $atts = array() ) {
    // Deprecated: Now handled by Subsales_PWA::pwa_shortcode() via class init shortcode
    // This wrapper kept for backward compatibility in case external code calls it directly
    return Subsales_PWA::pwa_shortcode( $atts );
}
// Note: add_shortcode() removed - now handled by Subsales_PWA::init()

function order_sync_ensure_pwa_page( $slug = 'subsales-portal' ) {
    return Subsales_PWA::ensure_pwa_page( $slug );
}

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

/**
 * Get plugin version from plugin header (preferred) or fallback to SUBSALES_VERSION.
 * Uses get_file_data when available; falls back to parsing the file header.
 */
function order_sync_get_plugin_version() {
    $file = __FILE__;
    // Try WP helper if available
    if ( ! function_exists( 'get_file_data' ) ) {
        if ( file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
    if ( function_exists( 'get_file_data' ) ) {
        $data = get_file_data( $file, array( 'Version' => 'Version' ) );
        if ( ! empty( $data['Version'] ) ) return $data['Version'];
    }

    // Fallback: parse header comment for Version: line
    $contents = @file_get_contents( $file );
    if ( $contents ) {
        if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $m ) ) {
            return trim( $m[1] );
        }
    }

    if ( defined( 'SUBSALES_VERSION' ) ) return SUBSALES_VERSION;
    return '';
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

// Ensure geocode cache table exists (lightweight, called on demand)
function order_sync_ensure_geocode_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_geocodes';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        address_hash varchar(64) NOT NULL,
        address_normalized text NOT NULL,
        lat double DEFAULT NULL,
        lng double DEFAULT NULL,
        status varchar(32) DEFAULT 'unknown',
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY address_hash (address_hash(64))
    ) {$charset};";
    $wpdb->query( $sql );
}

function order_sync_normalize_address( $addr ) {
    if ( ! $addr ) return '';
    $s = trim( preg_replace('/\s+/', ' ', str_replace( array("\n","\r"), ' ', $addr ) ) );
    $s = strtolower( $s );
    return $s;
}

function order_sync_geocode_address( $address ) {
    global $wpdb;
    $address_norm = order_sync_normalize_address( $address );
    $hash = md5( $address_norm );
    $table = $wpdb->prefix . 'order_sync_geocodes';

    // ensure table exists
    order_sync_ensure_geocode_table();

    // check cache
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE address_hash = %s LIMIT 1", $hash ), ARRAY_A );
    if ( $row && ! empty( $row['lat'] ) && ! empty( $row['lng'] ) && isset( $row['status'] ) && $row['status'] === 'OK' ) {
        return array( 'lat' => floatval( $row['lat'] ), 'lng' => floatval( $row['lng'] ), 'cached' => true );
    }

    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    if ( empty( $api_key ) ) {
        // store unknown status and return null
        $now = current_time( 'mysql' );
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'status' => 'no_key', 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'status' => 'no_key', 'updated_at' => $now ), array( '%s', '%s', '%s', '%s' ) );
        }
        return null;
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode( $address ) . '&key=' . rawurlencode( $api_key );
    $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $resp ) ) return null;
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    $now = current_time( 'mysql' );
    if ( ! is_array( $json ) || ! isset( $json['status'] ) ) {
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'status' => 'error', 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'status' => 'error', 'updated_at' => $now ), array( '%s', '%s', '%s', '%s' ) );
        }
        return null;
    }
    if ( isset( $json['status'] ) && $json['status'] === 'OK' && ! empty( $json['results'][0]['geometry']['location'] ) ) {
        $loc = $json['results'][0]['geometry']['location'];
        $lat = floatval( $loc['lat'] );
        $lng = floatval( $loc['lng'] );
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'lat' => $lat, 'lng' => $lng, 'status' => 'OK', 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%f', '%f', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'lat' => $lat, 'lng' => $lng, 'status' => 'OK', 'updated_at' => $now ), array( '%s', '%s', '%f', '%f', '%s', '%s' ) );
        }
        return array( 'lat' => $lat, 'lng' => $lng, 'cached' => false );
    } else {
        $status = isset( $json['status'] ) ? $json['status'] : 'error';
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'status' => $status, 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'status' => $status, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s' ) );
        }
        return null;
    }
}

// Admin Delivery page: single-day delivery exports and preview
function order_sync_delivery_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $start_addr = esc_attr( get_option( 'order_sync_delivery_start_address', '' ) );
    // Preflight summary: compute total product orders and unique addresses
    global $wpdb;
    $orders_table = $wpdb->prefix . 'order_sync_orders';
    $configured_products = order_sync_get_products_config();
    $product_totals = array();
    foreach ( $configured_products as $p ) { $product_totals[ $p['id'] ] = 0; }
    $rows_all = $wpdb->get_results( "SELECT * FROM {$orders_table} ORDER BY id ASC", ARRAY_A );
    $pre_total_orders = 0;
    $by_address_pf = array();
    if ( $rows_all ) {
        foreach ( $rows_all as $rr ) {
            $od = json_decode( $rr['order_data'], true );
            if ( ! is_array( $od ) ) $od = array();
            $products_map = array();
            foreach ( $configured_products as $pconf ) { $products_map[ $pconf['id'] ] = 0; }
            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                foreach ( $od['products'] as $pr ) {
                    $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                    $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                    if ( $qty > 0 && $pid ) {
                        if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                        $products_map[ $pid ] += $qty;
                    }
                }
            } else {
                foreach ( $configured_products as $p ) {
                    $pid = $p['id'];
                    $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                    foreach ( $labels as $k ) {
                        if ( isset( $od[ $k ] ) ) {
                            $q = intval( $od[ $k ] );
                            if ( $q > 0 ) {
                                if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                                $products_map[ $pid ] += $q;
                            }
                            break;
                        }
                    }
                }
            }
            $total_qty = array_sum( array_values( $products_map ) );
            if ( $total_qty <= 0 ) continue;
            $pre_total_orders++;
            $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
            $addr_norm = order_sync_normalize_address( $address_raw );
            if ( empty( $addr_norm ) ) continue;
            if ( ! isset( $by_address_pf[ $addr_norm ] ) ) $by_address_pf[ $addr_norm ] = array();
            $by_address_pf[ $addr_norm ][] = $rr['order_id'];
            // accumulate product totals
            foreach ( $products_map as $pid => $q ) { if ( $q > 0 ) $product_totals[ $pid ] += $q; }
        }
    }
    $pre_unique_addresses = count( $by_address_pf );
    ?>
    <div class="wrap">
        <h1>Delivery</h1>
        <p class="description">Delivery exports and driver manifest workflows. Donations are excluded. Addresses will be combined by normalized address. By default exports include all orders unless a delivery date is specified.</p>
        <div style="margin:12px 0; padding:12px; background:#fff; border:1px solid #e5e5e5;">
            <strong>Preflight summary</strong>
            <p style="margin:6px 0">Total product orders: <strong><?php echo intval( $pre_total_orders ); ?></strong></p>
            <p style="margin:6px 0">Unique delivery addresses: <strong><?php echo intval( $pre_unique_addresses ); ?></strong></p>
            <?php if ( ! empty( $configured_products ) ): ?>
                <table class="widefat" style="max-width:800px; margin-top:8px;">
                    <thead><tr><th>Product</th><th style="text-align:right">Total Qty</th></tr></thead>
                    <tbody>
                    <?php foreach ( $configured_products as $p ) : ?>
                        <tr>
                            <td><?php echo esc_html( $p['name'] ); ?></td>
                            <td style="text-align:right"><?php echo intval( $product_totals[ $p['id'] ] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <!-- Administrative CSV: admin creates their own routes -> simple CSV export (no driver assignment) -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'subsales_generate_admin_csv' ); ?>
            <input type="hidden" name="action" value="subsales_generate_admin_csv" />
            <table class="form-table">
                <tr>
                    <th scope="row">Administrative CSV (no routing)</th>
                    <td>
                        <p class="description">Create a CSV export you can open in a spreadsheet to design your own routes. This export contains one row per normalized address and per-product columns.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Delivery date (optional)</th>
                    <td><input type="date" name="delivery_date" value="" /></td>
                </tr>
            </table>
            <p class="submit"><button class="button">Generate Administrative CSV</button></p>
        </form>

        <!-- Driver manifests workflow: individual-based routing and PDF generation -->
        <h2 style="margin-top:18px">Generate Individual Delivery Manifests</h2>
        <p class="description">Generate optimized delivery routes for each team member based on their orders. Creates individual PDF manifests with packing lists.</p>
        <form id="subsales-driver-manifests" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'subsales_generate_delivery' ); ?>
            <input type="hidden" name="action" value="subsales_generate_delivery_pdf" />
            <table class="form-table">
                <tr>
                    <th scope="row">Starting address (depot)</th>
                    <td><input type="text" name="start_address" id="sdm_start_address" class="regular-text" value="<?php echo $start_addr; ?>" placeholder="Street, City, ZIP" required />
                    <p class="description">All routes will start from this location</p></td>
                </tr>
                <tr>
                    <th scope="row">Delivery date (optional)</th>
                    <td><input type="date" name="delivery_date" id="sdm_delivery_date" value="" />
                    <p class="description">Leave blank to include all orders</p></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Generate Individual Manifests (PDF)</button>
            </p>
        </form>

        <div id="subsales_preview_modal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:99999;">
            <div style="position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); width:90%; max-width:1000px; height:80%; background:#fff; border-radius:6px; overflow:hidden;">
                <div style="padding:8px; background:#f5f5f5; border-bottom:1px solid #e5e5e5; display:flex; align-items:center; justify-content:space-between;">
                    <strong>Route preview</strong>
                    <div>
                        <button id="subsales_preview_close" class="button">Close</button>
                    </div>
                </div>
                <div id="subsales_preview_map" style="width:100%; height:calc(100% - 44px);"></div>
            </div>
        </div>

        <script>
        (function(){
            const ajaxUrl = ajaxurl;
            const previewNonce = <?php echo json_encode( wp_create_nonce( 'subsales_delivery_preview' ) ); ?>;
            const previewBtn = document.getElementById('sdm_preview_btn');
            const modal = document.getElementById('subsales_preview_modal');
            const closeBtn = document.getElementById('subsales_preview_close');
            let mapInstance = null;
            let googleApiLoaded = false;

            function loadGoogleMaps(key){
                return new Promise(function(resolve,reject){
                    if ( window.google && window.google.maps ) return resolve(window.google.maps);
                    const s = document.createElement('script');
                    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key);
                    s.async = true; s.defer = true;
                    s.onload = function(){ googleApiLoaded = true; resolve(window.google.maps); };
                    s.onerror = function(){ reject(new Error('Failed to load Google Maps')); };
                    document.head.appendChild(s);
                });
            }

            function buildColor(i){
                const palette = ['#1E90FF','#FF4500','#32CD32','#FFD700','#8A2BE2','#FF1493','#00CED1','#FF8C00','#00BFFF','#228B22'];
                return palette[i % palette.length];
            }

            function renderPreview(data){
                const products = data.products || [];
                const drivers = data.drivers || {};
                const apiKey = data.api_key || '';
                if ( ! apiKey ) { alert('No Google Maps API key configured in Settings → Overall.'); return; }
                loadGoogleMaps(apiKey).then(function(gmaps){
                    modal.style.display = 'block';
                    // create map
                    if ( mapInstance ) { /* reuse center */ }
                    const mapDiv = document.getElementById('subsales_preview_map');
                    mapDiv.innerHTML = '';
                    mapInstance = new gmaps.Map(mapDiv, { zoom: 12, center: { lat: 41.0, lng: -73.0 } });
                    const bounds = new gmaps.LatLngBounds();
                    Object.keys(drivers).forEach(function(dk, idx){
                        const rows = drivers[dk];
                        const path = [];
                        rows.forEach(function(r, ridx){
                            if ( r.lat && r.lng ) {
                                const pos = { lat: parseFloat(r.lat), lng: parseFloat(r.lng) };
                                path.push(pos);
                                const marker = new gmaps.Marker({ position: pos, map: mapInstance, title: r.address_raw, label: String(idx+1) });
                                bounds.extend(pos);
                            }
                        });
                        if ( path.length > 0 ) {
                            const poly = new gmaps.Polyline({ path: path, geodesic: true, strokeColor: buildColor(idx), strokeOpacity: 0.8, strokeWeight: 3 });
                            poly.setMap(mapInstance);
                        }
                    });
                    if ( ! bounds.isEmpty() ) mapInstance.fitBounds(bounds);
                }).catch(function(err){ alert('Map load error: ' + err.message); });
            }

            previewBtn && previewBtn.addEventListener('click', function(){
                const fd = new FormData();
                fd.append('action','subsales_delivery_preview');
                fd.append('_ajax_nonce', previewNonce);
                fd.append('start_address', document.getElementById('sdm_start_address').value || '');
                fd.append('delivery_date', document.getElementById('sdm_delivery_date').value || '');
                fd.append('driver_count', document.getElementById('sdm_driver_count').value || '2');
                fetch(ajaxUrl, { method:'POST', body: fd }).then(r=>r.json()).then(function(j){
                    if ( ! j || ! j.success ) { alert('Preview error: ' + (j && j.data ? j.data : 'Unknown')); return; }
                    renderPreview(j.data);
                }).catch(function(e){ alert('Fetch error: ' + e.message); });
            });
            closeBtn && closeBtn.addEventListener('click', function(){ modal.style.display = 'none'; });
        })();
        </script>

        <h2 style="margin-top:24px">Geocoding & limits</h2>
        <p class="description">Geocoding uses the configured Google Maps API key (Settings &rarr; Overall). Results are cached to speed repeated exports. For very large exports this may run slowly due to API rate limits—consider pre-caching addresses.</p>
    </div>
    <?php
}

// Handle generate delivery export (admin POST)
add_action( 'admin_post_subsales_generate_delivery', 'order_sync_handle_generate_delivery' );
function order_sync_handle_generate_delivery() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'subsales_generate_delivery' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_orders';

    // Delivery date is optional; when omitted export ALL orders.
    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( $_POST['start_address'] ) : '';
    update_option( 'order_sync_delivery_start_address', $start_address );
    $driver_count = isset( $_POST['driver_count'] ) ? max(1, intval( $_POST['driver_count'] )) : 2;
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        // No date supplied: export all orders
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
    }

    $configured_products = order_sync_get_products_config();

    // Build orders list, group by normalized address
    $by_address = array();
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) $od = array();

        // build products_map like other code
        $products_map = array();
        foreach ( $configured_products as $pconf ) { $products_map[ $pconf['id'] ] = 0; }

        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                if ( $qty > 0 && $pid ) {
                    if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                    $products_map[ $pid ] += $qty;
                }
            }
        } else {
            // fallback legacy fields
            foreach ( $configured_products as $p ) {
                $pid = $p['id'];
                $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                foreach ( $labels as $k ) {
                    if ( isset( $od[ $k ] ) ) {
                        $q = intval( $od[ $k ] );
                        if ( $q > 0 ) {
                            if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                            $products_map[ $pid ] += $q;
                        }
                        break;
                    }
                }
            }
        }

        // skip donations / orders with no product quantities
        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue;

        // extract fields
        $team_name = '';
        if ( ! empty( $r['team_id'] ) ) {
            $t = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}order_sync_teams WHERE id = %d", intval( $r['team_id'] ) ) );
            $team_name = $t ? $t->name : '';
        }
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : $r['user_id'] ) );
        $customer = isset( $od['customerName'] ) ? $od['customerName'] : ( isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['name'] ) ? $od['name'] : '' ) );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['cell'] ) ? $od['cell'] : ( isset( $od['phone'] ) ? $od['phone'] : '' ) );
        $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
        $addr_norm = order_sync_normalize_address( $address_raw );
        if ( empty( $addr_norm ) ) continue; // skip if no address

        // products string
        $prod_strs = array();
        foreach ( $products_map as $pid => $q ) {
            if ( $q > 0 ) {
                // find product name
                $pname = $pid;
                foreach ( $configured_products as $pconf ) { if ( $pconf['id'] === $pid ) { $pname = $pconf['name']; break; } }
                $prod_strs[] = $pname . ' × ' . intval( $q );
            }
        }

        $entry = array(
            'order_id' => $r['order_id'],
            'team' => $team_name,
            'seller' => $seller,
            'address_raw' => $address_raw,
            'address_norm' => $addr_norm,
            'products_map' => $products_map,
            'products_str' => implode('; ', $prod_strs),
            'customer' => $customer,
            'phone' => $phone,
            'total_qty' => $total_qty,
        );

        // combine by normalized address
        if ( ! isset( $by_address[ $addr_norm ] ) ) {
            $by_address[ $addr_norm ] = array( 'address_raw' => $address_raw, 'orders' => array() );
        }
        $by_address[ $addr_norm ]['orders'][] = $entry;
    }

    // build flattened list of manifest rows: combine duplicate addresses into single row with aggregated product counts and order ids
    $manifest_rows = array();
    foreach ( $by_address as $addr_norm => $group ) {
        $combined_products = array();
        foreach ( $configured_products as $p ) { $combined_products[ $p['id'] ] = 0; }
        $order_ids = array();
        $team = '';
        $seller = '';
        $customer = '';
        $phone = '';
        foreach ( $group['orders'] as $o ) {
            $order_ids[] = $o['order_id'];
            foreach ( $o['products_map'] as $pid => $q ) { if ( ! isset( $combined_products[ $pid ] ) ) $combined_products[ $pid ] = 0; $combined_products[ $pid ] += intval( $q ); }
            // keep first non-empty team/seller/customer/phone as representative (driver manifest will include order ids)
            if ( empty( $team ) && ! empty( $o['team'] ) ) $team = $o['team'];
            if ( empty( $seller ) && ! empty( $o['seller'] ) ) $seller = $o['seller'];
            if ( empty( $customer ) && ! empty( $o['customer'] ) ) $customer = $o['customer'];
            if ( empty( $phone ) && ! empty( $o['phone'] ) ) $phone = $o['phone'];
        }
        // build products string
        $prod_list = array();
        foreach ( $combined_products as $pid => $q ) {
            if ( $q > 0 ) {
                $pname = $pid;
                foreach ( $configured_products as $pconf ) { if ( $pconf['id'] === $pid ) { $pname = $pconf['name']; break; } }
                $prod_list[] = $pname . ' × ' . intval( $q );
            }
        }

        $manifest_rows[] = array(
            'address_raw' => $group['address_raw'],
            'address_norm' => $addr_norm,
            'products_map' => $combined_products,
            'products_str' => implode('; ', $prod_list),
            'order_ids' => $order_ids,
            'team' => $team,
            'seller' => $seller,
            'customer' => $customer,
            'phone' => $phone,
        );
    }

    // Assign to drivers evenly by order count (user requested even distribution by orders).
    // Use a greedy assignment: sort manifest rows by descending order count and assign each row
    // to the driver that currently has the fewest assigned orders. This balances number of
    // orders per driver even when some addresses represent multiple orders.
    $drivers = array();
    $driver_counts = array();
    for ( $i = 1; $i <= $driver_count; $i++ ) { $drivers[ $i ] = array(); $driver_counts[ $i ] = 0; }

    // sort manifest rows by descending number of orders at that address
    usort( $manifest_rows, function( $a, $b ) {
        $ca = isset( $a['order_ids'] ) ? count( $a['order_ids'] ) : 0;
        $cb = isset( $b['order_ids'] ) ? count( $b['order_ids'] ) : 0;
        return $cb - $ca;
    } );

    foreach ( $manifest_rows as $mr ) {
        $count_here = isset( $mr['order_ids'] ) ? count( $mr['order_ids'] ) : 1;
        // find driver with minimum assigned orders
        $min_driver = null;
        $min_count = null;
        foreach ( $driver_counts as $dnum => $cnt ) {
            if ( $min_driver === null || $cnt < $min_count ) { $min_driver = $dnum; $min_count = $cnt; }
        }
        $drivers[ $min_driver ][] = $mr;
        $driver_counts[ $min_driver ] += $count_here;
    }

    // If no orders were found or no manifest rows after filtering, redirect back with a notice
    if ( empty( $rows ) ) {
        $msg = rawurlencode( 'No orders found for ' . $delivery_date );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }
    if ( empty( $manifest_rows ) ) {
        $msg = rawurlencode( 'No valid product orders found for ' . $delivery_date . '. Check products configuration and order data.' );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    // Stream CSV: single CSV with Driver column and per-product columns
    $filename = 'delivery_manifest_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    $out = fopen( 'php://output', 'w' );
    // header row: Driver, Address, <product columns...>, Customer, Phone, Seller
    $headers = array( 'Driver', 'Address' );
    foreach ( $configured_products as $pcol ) { $headers[] = $pcol['name']; }
    $headers = array_merge( $headers, array( 'Customer Name', 'Phone', 'Seller' ) );
    fputcsv( $out, $headers );

    foreach ( $drivers as $dnum => $drows ) {
        foreach ( $drows as $r ) {
            $row = array();
            $row[] = $dnum;
            $row[] = $r['address_raw'];
            // product columns
            foreach ( $configured_products as $pcol ) {
                $pid = $pcol['id'];
                $row[] = isset( $r['products_map'][ $pid ] ) ? intval( $r['products_map'][ $pid ] ) : 0;
            }
            $row[] = $r['customer'];
            $row[] = $r['phone'];
            $row[] = $r['seller'];
            fputcsv( $out, $row );
        }
    }
    fclose( $out );
    exit;
}

// System Logs page - view all logging activity with filters
function subsales_logs_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    
    global $wpdb;
    $logs_table = $wpdb->prefix . 'subsales_logs';
    
    // Handle debug mode toggle via AJAX (handled separately)
    $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
    $debug_started = get_option( 'subsales_debug_logging_started', 0 );
    $debug_remaining = 0;
    if ( $debug_enabled && $debug_started ) {
        $elapsed = time() - $debug_started;
        $debug_remaining = max( 0, ( 24 * 3600 ) - $elapsed );
    }
    
    // Get filter parameters
    $level_filter = isset( $_GET['level'] ) ? sanitize_text_field( $_GET['level'] ) : 'all';
    $category_filter = isset( $_GET['category'] ) ? sanitize_text_field( $_GET['category'] ) : 'all';
    $source_filter = isset( $_GET['source'] ) ? sanitize_text_field( $_GET['source'] ) : 'all';
    $date_filter = isset( $_GET['date_range'] ) ? sanitize_text_field( $_GET['date_range'] ) : 'today';
    $search_query = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
    $show_debug = isset( $_GET['show_debug'] ) && $_GET['show_debug'] === '1';
    
    // Build WHERE clause
    $where = array( '1=1' );
    
    if ( $level_filter !== 'all' ) {
        $where[] = $wpdb->prepare( 'log_level = %s', strtoupper( $level_filter ) );
    }
    
    if ( $category_filter !== 'all' ) {
        $where[] = $wpdb->prepare( 'category = %s', $category_filter );
    }
    
    if ( $source_filter !== 'all' ) {
        $where[] = $wpdb->prepare( 'source = %s', $source_filter );
    }
    
    // Date range filter
    switch ( $date_filter ) {
        case 'hour':
            $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            break;
        case 'today':
            $where[] = "DATE(created_at) = CURDATE()";
            break;
        case 'week':
            $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
    }
    
    // Debug filter
    if ( ! $show_debug ) {
        $where[] = "is_debug = 0";
    }
    
    // Search filter
    if ( ! empty( $search_query ) ) {
        $where[] = $wpdb->prepare( 'message LIKE %s', '%' . $wpdb->esc_like( $search_query ) . '%' );
    }
    
    $where_sql = implode( ' AND ', $where );
    
    // Pagination
    $per_page = 500;
    $page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $offset = ( $page - 1 ) * $per_page;
    
    // Get total count
    $total_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table} WHERE {$where_sql}" );
    
    // Get logs
    $logs = $wpdb->get_results(
        "SELECT * FROM {$logs_table} 
         WHERE {$where_sql} 
         ORDER BY created_at DESC 
         LIMIT {$per_page} OFFSET {$offset}",
        ARRAY_A
    );
    
    $total_pages = ceil( $total_count / $per_page );
    
    ?>
    <div class="wrap subsales-logs-page">
        <h1>System Logs</h1>
        
        <!-- Debug Mode Toggle -->
        <div class="subsales-debug-toggle" style="background: #fff; border-left: 4px solid <?php echo $debug_enabled ? '#ffc107' : '#ddd'; ?>; border: 1px solid #ddd; border-left: 4px solid <?php echo $debug_enabled ? '#ffc107' : '#ddd'; ?>; padding: 15px; margin: 20px 0; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Debug Logging:</strong> 
                    <span id="debug-status"><?php echo $debug_enabled ? 'ACTIVE' : 'Disabled'; ?></span>
                    <?php if ( $debug_enabled && $debug_remaining > 0 ): ?>
                        <span id="debug-timer" style="margin-left: 10px; font-family: monospace;">
                            (<?php echo gmdate( 'H:i:s', $debug_remaining ); ?> remaining)
                        </span>
                    <?php endif; ?>
                </div>
                <button id="toggle-debug-btn" class="button button-<?php echo $debug_enabled ? 'secondary' : 'primary'; ?>">
                    <?php echo $debug_enabled ? 'Disable Debug Mode' : 'Enable Debug Mode'; ?>
                </button>
            </div>
            <?php if ( $debug_enabled ): ?>
                <p style="margin: 10px 0 0 0; font-size: 12px; color: #856404;">
                    ⚠️ Debug mode generates high volume logs. It will automatically disable after 24 hours.
                </p>
            <?php endif; ?>
        </div>
        
        <!-- Filters -->
        <form method="get" action="" style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
            <input type="hidden" name="page" value="subsales-logs">
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 10px;">
                <div>
                    <label>Log Level:</label>
                    <select name="level">
                        <option value="all" <?php selected( $level_filter, 'all' ); ?>>All Levels</option>
                        <option value="debug" <?php selected( $level_filter, 'debug' ); ?>>DEBUG</option>
                        <option value="info" <?php selected( $level_filter, 'info' ); ?>>INFO</option>
                        <option value="warning" <?php selected( $level_filter, 'warning' ); ?>>WARNING</option>
                        <option value="error" <?php selected( $level_filter, 'error' ); ?>>ERROR</option>
                        <option value="critical" <?php selected( $level_filter, 'critical' ); ?>>CRITICAL</option>
                    </select>
                </div>
                
                <div>
                    <label>Category:</label>
                    <select name="category">
                        <option value="all" <?php selected( $category_filter, 'all' ); ?>>All Categories</option>
                        <option value="auth" <?php selected( $category_filter, 'auth' ); ?>>Authentication</option>
                        <option value="orders" <?php selected( $category_filter, 'orders' ); ?>>Orders</option>
                        <option value="sync" <?php selected( $category_filter, 'sync' ); ?>>Sync</option>
                        <option value="api" <?php selected( $category_filter, 'api' ); ?>>API</option>
                        <option value="zip" <?php selected( $category_filter, 'zip' ); ?>>ZIP Extracts</option>
                        <option value="system" <?php selected( $category_filter, 'system' ); ?>>System</option>
                    </select>
                </div>
                
                <div>
                    <label>Source:</label>
                    <select name="source">
                        <option value="all" <?php selected( $source_filter, 'all' ); ?>>All Sources</option>
                        <option value="admin" <?php selected( $source_filter, 'admin' ); ?>>Admin</option>
                        <option value="pwa" <?php selected( $source_filter, 'pwa' ); ?>>PWA</option>
                        <option value="api" <?php selected( $source_filter, 'api' ); ?>>API</option>
                        <option value="cron" <?php selected( $source_filter, 'cron' ); ?>>Cron</option>
                    </select>
                </div>
                
                <div>
                    <label>Time Range:</label>
                    <select name="date_range">
                        <option value="hour" <?php selected( $date_filter, 'hour' ); ?>>Last Hour</option>
                        <option value="today" <?php selected( $date_filter, 'today' ); ?>>Today</option>
                        <option value="week" <?php selected( $date_filter, 'week' ); ?>>Last 7 Days</option>
                    </select>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label>Search Message:</label>
                    <input type="text" name="search" value="<?php echo esc_attr( $search_query ); ?>" style="width: 100%;" placeholder="Search log messages...">
                </div>
                
                <?php if ( $debug_enabled ): ?>
                <div>
                    <label>
                        <input type="checkbox" name="show_debug" value="1" <?php checked( $show_debug ); ?>>
                        Show Debug Logs
                    </label>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="button button-primary">Apply Filters</button>
                <a href="?page=subsales-logs" class="button">Reset</a>
                <button type="button" id="download-logs-btn" class="button">Download Current View</button>
                <label style="margin-left: 15px;">
                    <input type="checkbox" id="auto-refresh-toggle" checked> Auto-refresh (5s)
                </label>
            </div>
        </form>
        
        <!-- Logs Table -->
        <div class="logs-container">
            <p><strong><?php echo number_format( $total_count ); ?></strong> log entries found</p>
            
            <table class="wp-list-table widefat fixed striped" id="logs-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Time</th>
                        <th style="width: 80px;">Level</th>
                        <th style="width: 100px;">Category</th>
                        <th style="width: 80px;">Source</th>
                        <th style="width: 120px;">User</th>
                        <th>Message</th>
                        <th style="width: 60px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px;">No logs found matching your filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ( $logs as $log ): 
                            $level_class = 'log-level-' . strtolower( $log['log_level'] );
                            $has_context = ! empty( $log['context_json'] );
                        ?>
                            <tr class="<?php echo esc_attr( $level_class ); ?>">
                                <td><?php echo esc_html( $log['created_at'] ); ?></td>
                                <td><span class="log-badge log-badge-<?php echo esc_attr( strtolower( $log['log_level'] ) ); ?>"><?php echo esc_html( $log['log_level'] ); ?></span></td>
                                <td><?php echo esc_html( $log['category'] ); ?></td>
                                <td><?php echo esc_html( $log['source'] ); ?></td>
                                <td><?php echo $log['user_name'] ? esc_html( $log['user_name'] ) : '-'; ?></td>
                                <td><?php echo esc_html( $log['message'] ); ?></td>
                                <td>
                                    <?php if ( $has_context ): ?>
                                        <button class="button button-small view-context-btn" data-context="<?php echo esc_attr( $log['context_json'] ); ?>">View</button>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ( $total_pages > 1 ): ?>
                <div class="tablenav" style="margin-top: 20px;">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links( array(
                            'base' => add_query_arg( 'paged', '%#%' ),
                            'format' => '',
                            'prev_text' => '&laquo; Previous',
                            'next_text' => 'Next &raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ) );
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Context Modal -->
        <div id="context-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; align-items: center; justify-content: center;">
            <div style="background: #fff; padding: 20px; border-radius: 4px; max-width: 800px; max-height: 80vh; overflow: auto;">
                <h2>Log Context Details</h2>
                <pre id="context-content" style="background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto;"></pre>
                <button class="button" id="close-context-modal">Close</button>
            </div>
        </div>
    </div>
    
    <style>
        .log-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .log-badge-debug { background: #6c757d; color: #fff; }
        .log-badge-info { background: #0d6efd; color: #fff; }
        .log-badge-warning { background: #ffc107; color: #000; }
        .log-badge-error { background: #dc3545; color: #fff; }
        .log-badge-critical { background: #6f2232; color: #fff; }
        
        .log-level-error td { background-color: #fff5f5; }
        .log-level-critical td { background-color: #ffe6e6; }
        .log-level-warning td { background-color: #fffbf0; }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Debug toggle
        $('#toggle-debug-btn').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).text('Processing...');
            
            $.post(ajaxurl, {
                action: 'subsales_toggle_debug',
                nonce: '<?php echo wp_create_nonce( 'subsales_debug_toggle' ); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    btn.prop('disabled', false);
                }
            });
        });
        
        // View context modal
        $('.view-context-btn').on('click', function() {
            const context = $(this).data('context');
            try {
                const formatted = JSON.stringify(JSON.parse(context), null, 2);
                $('#context-content').text(formatted);
            } catch(e) {
                $('#context-content').text(context);
            }
            $('#context-modal').css('display', 'flex');
        });
        
        $('#close-context-modal, #context-modal').on('click', function(e) {
            if (e.target === this) {
                $('#context-modal').hide();
            }
        });
        
        // Download logs
        $('#download-logs-btn').on('click', function() {
            const params = new URLSearchParams(window.location.search);
            params.set('download', '1');
            window.location.href = '?' + params.toString();
        });
        
        // Auto-refresh
        let refreshInterval = null;
        let refreshCountdown = 5;
        
        function startAutoRefresh() {
            if (refreshInterval) return;
            refreshInterval = setInterval(function() {
                if (refreshCountdown <= 0) {
                    location.reload();
                } else {
                    refreshCountdown--;
                }
            }, 1000);
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
                refreshCountdown = 5;
            }
        }
        
        $('#auto-refresh-toggle').on('change', function() {
            if ($(this).is(':checked')) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
        
        // Start auto-refresh by default
        if ($('#auto-refresh-toggle').is(':checked')) {
            startAutoRefresh();
        }
        
        // Update debug timer
        <?php if ( $debug_enabled && $debug_remaining > 0 ): ?>
        let remaining = <?php echo $debug_remaining; ?>;
        setInterval(function() {
            if (remaining > 0) {
                remaining--;
                const hours = Math.floor(remaining / 3600);
                const mins = Math.floor((remaining % 3600) / 60);
                const secs = remaining % 60;
                $('#debug-timer').text('(' + 
                    String(hours).padStart(2, '0') + ':' + 
                    String(mins).padStart(2, '0') + ':' + 
                    String(secs).padStart(2, '0') + ' remaining)');
            }
        }, 1000);
        <?php endif; ?>
    });
    </script>
    <?php
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
        
        <!-- Sales Mode Toggle and Active Users -->
        <!-- OPTION 1: Compact Inline Toggle (DEFAULT - UNCOMMENT TO USE) -->
        <div class="subsales-mode-controls subsales-option-1" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; display: flex; align-items: center; gap: 30px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <strong style="font-size: 14px;">Sales Mode:</strong>
                <label class="subsales-toggle-switch">
                    <input type="checkbox" id="salesModeToggle" <?php checked( get_option( 'subsales_sales_mode', 'legacy' ), 'user' ); ?> />
                    <span class="subsales-toggle-slider"></span>
                    <span class="subsales-toggle-label-left">Team</span>
                    <span class="subsales-toggle-label-right">Individual</span>
                </label>
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-admin-users" style="color: #2271b1; font-size: 16px;"></span>
                <strong style="font-size: 14px;">Active Users:</strong>
                <span id="activeUserCount" class="subsales-chip" style="background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 13px; cursor: pointer; font-weight: 600; min-width: 24px; text-align: center;" title="Click to view active users">0</span>
            </div>
        </div>
        
        <!-- OPTION 2: Segmented Control Style (COMMENT OUT OPTION 1 AND UNCOMMENT TO USE)
        <div class="subsales-mode-controls subsales-option-2" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; display: flex; align-items: center; gap: 30px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <strong style="font-size: 14px;">Sales Mode:</strong>
                <div class="subsales-segmented-control">
                    <input type="radio" name="salesModeRadio" id="salesModeTeam" value="legacy" <?php checked( get_option( 'subsales_sales_mode', 'legacy' ), 'legacy' ); ?> />
                    <label for="salesModeTeam">Team</label>
                    <input type="radio" name="salesModeRadio" id="salesModeIndividual" value="user" <?php checked( get_option( 'subsales_sales_mode', 'legacy' ), 'user' ); ?> />
                    <label for="salesModeIndividual">Individual</label>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-admin-users" style="color: #2271b1; font-size: 16px;"></span>
                <strong style="font-size: 14px;">Active Users:</strong>
                <span id="activeUserCount" class="subsales-chip" style="background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 13px; cursor: pointer; font-weight: 600; min-width: 24px; text-align: center;" title="Click to view active users">0</span>
            </div>
        </div>
        -->
        
        <!-- OPTION 3: Minimal Badge Style (COMMENT OUT OPTION 1 AND UNCOMMENT TO USE)
        <div class="subsales-mode-controls subsales-option-3" style="background: #f0f6fc; padding: 12px 15px; border-left: 4px solid #2271b1; margin-bottom: 20px; display: flex; align-items: center; gap: 25px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <strong style="font-size: 13px; color: #1d2327;">Sales Mode:</strong>
                <div class="subsales-badge-toggle">
                    <button type="button" class="subsales-badge-btn" data-value="legacy">Team</button>
                    <button type="button" class="subsales-badge-btn active" data-value="user">Individual</button>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-admin-users" style="color: #2271b1; font-size: 16px;"></span>
                <span style="font-size: 13px; color: #1d2327; font-weight: 500;">Active Users:</span>
                <span id="activeUserCount" class="subsales-chip" style="background: #2271b1; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 12px; cursor: pointer; font-weight: 600; min-width: 20px; text-align: center;" title="Click to view active users">0</span>
            </div>
        </div>
        -->
        
        <style>
        /* OPTION 1: Compact Inline Toggle Styles */
        .subsales-option-1 .subsales-toggle-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            width: 140px;
            height: 24px;
        }
        .subsales-option-1 .subsales-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .subsales-option-1 .subsales-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 38px;
            right: 60px;
            bottom: 0;
            background-color: #ddd;
            transition: .3s;
            border-radius: 12px;
        }
        .subsales-option-1 .subsales-toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .subsales-option-1 input:checked + .subsales-toggle-slider {
            background-color: #2271b1;
        }
        .subsales-option-1 input:checked + .subsales-toggle-slider:before {
            transform: translateX(17px);
        }
        .subsales-option-1 .subsales-toggle-label-left,
        .subsales-option-1 .subsales-toggle-label-right {
            position: absolute;
            font-size: 12px;
            font-weight: 500;
            z-index: 1;
            user-select: none;
        }
        .subsales-option-1 .subsales-toggle-label-left {
            left: 0;
            color: #2c3338;
        }
        .subsales-option-1 .subsales-toggle-label-right {
            right: 0;
            color: #787c82;
        }
        .subsales-option-1 input:checked ~ .subsales-toggle-label-left {
            color: #787c82;
        }
        .subsales-option-1 input:checked ~ .subsales-toggle-label-right {
            color: #2c3338;
        }
        
        /* OPTION 2: Segmented Control Styles */
        .subsales-segmented-control {
            display: inline-flex;
            background: #f0f0f0;
            border-radius: 6px;
            padding: 2px;
        }
        .subsales-segmented-control input[type="radio"] {
            display: none;
        }
        .subsales-segmented-control label {
            padding: 4px 14px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s;
            color: #50575e;
            margin: 0;
        }
        .subsales-segmented-control input[type="radio"]:checked + label {
            background: #2271b1;
            color: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .subsales-segmented-control label:hover {
            color: #2271b1;
        }
        .subsales-segmented-control input[type="radio"]:checked + label:hover {
            color: #fff;
        }
        
        /* OPTION 3: Badge Toggle Styles */
        .subsales-badge-toggle {
            display: inline-flex;
            gap: 6px;
        }
        .subsales-badge-btn {
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid #c3c4c7;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            color: #2c3338;
        }
        .subsales-badge-btn:hover {
            border-color: #2271b1;
            color: #2271b1;
        }
        .subsales-badge-btn.active {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
            box-shadow: 0 1px 3px rgba(34, 113, 177, 0.3);
        }
        
        /* Common styles */
        .subsales-chip {
            display: inline-block;
        }
        #activeUsersPopup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 999999;
        }
        #activeUsersOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999998;
        }
        </style>
        
        <script>
        (function($) {
            // OPTION 1: Toggle switch handler
            $('#salesModeToggle').on('change', function() {
                const mode = this.checked ? 'user' : 'legacy';
                updateSalesMode(mode);
            });
            
            // OPTION 2: Segmented control handler
            $('input[name="salesModeRadio"]').on('change', function() {
                const mode = this.value;
                updateSalesMode(mode);
            });
            
            // OPTION 3: Badge toggle handler
            $('.subsales-badge-btn').on('click', function() {
                const mode = $(this).data('value');
                $('.subsales-badge-btn').removeClass('active');
                $(this).addClass('active');
                updateSalesMode(mode);
            });
            
            // Initialize option 3 active state
            const currentMode = <?php echo wp_json_encode( get_option( 'subsales_sales_mode', 'legacy' ) ); ?>;
            $('.subsales-badge-btn[data-value="' + currentMode + '"]').addClass('active');
            
            // Common update function
            function updateSalesMode(mode) {
                const modeName = mode === 'user' ? 'Individual' : 'Team';
                
                $.post(ajaxurl, {
                    action: 'subsales_update_sales_mode',
                    mode: mode,
                    nonce: '<?php echo wp_create_nonce( 'subsales_sales_mode' ); ?>'
                }, function(response) {
                    if (response.success) {
                        $('<div class="notice notice-success is-dismissible"><p>Sales mode changed to <strong>' + modeName + '</strong></p></div>')
                            .insertAfter('.wrap h1')
                            .delay(3000)
                            .fadeOut(function() { $(this).remove(); });
                    } else {
                        alert('Failed to update sales mode: ' + (response.data || 'Unknown error'));
                        // Revert to previous state
                        location.reload();
                    }
                });
            }
            
            // Active users popup
            $('#activeUserCount').on('click', function() {
                const overlay = $('<div id="activeUsersOverlay"></div>');
                const popup = $('<div id="activeUsersPopup"><h2>Active Users</h2><p>Feature coming soon: Track active user sessions</p><button class="button">Close</button></div>');
                
                $('body').append(overlay).append(popup);
                
                overlay.on('click', function() {
                    overlay.remove();
                    popup.remove();
                });
                
                popup.find('button').on('click', function() {
                    overlay.remove();
                    popup.remove();
                });
            });
            
            // Update active user count
            function updateActiveUserCount() {
                $('#activeUserCount').text('0');
            }
            
            updateActiveUserCount();
        })(jQuery);
        </script>
        
        <div class="subsales-compact-toggle-wrap">
            <button id="subsales-compact-toggle" class="button">Compact view</button>
            <span class="description">Toggle compact/comfortable dashboard spacing (stored in your browser).</span>
        </div>
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
                <script>
                (function(){
                    var key = 'subsales_compact';
                    function getRoot(){ return document.querySelector('.metabox-holder.subsales-dashboard-grid') || document.querySelector('.subsales-dashboard-grid'); }
                    function applyState(state){ var root = getRoot(); if(!root) return; if(state){ root.classList.add('subsales-compact'); } else { root.classList.remove('subsales-compact'); } var btn = document.getElementById('subsales-compact-toggle'); if(btn) btn.textContent = state ? 'Comfortable view' : 'Compact view'; }
                    // init from storage
                    try{ var stored = localStorage.getItem(key) === '1'; applyState(stored); }catch(e){}
                    document.addEventListener('DOMContentLoaded', function(){ var btn = document.getElementById('subsales-compact-toggle'); if(!btn) return; btn.addEventListener('click', function(){ try{ var cur = localStorage.getItem(key) === '1'; var next = !cur; localStorage.setItem(key, next ? '1' : '0'); applyState(next); }catch(e){} }); });
                })();
                </script>
                <?php
                // Compute financial summary (sales, cash, checks) by scanning existing orders
                $sales_total = 0.0;
                $cash_total = 0.0;
                $check_total = 0.0;
                $rows_fin = $wpdb->get_results( "SELECT order_data FROM {$orders_table}", ARRAY_A );
                if ( $rows_fin ) {
                    $conf_prods_for_fin = order_sync_get_products_config();
                    foreach ( $rows_fin as $rf ) {
                        $od = json_decode( $rf['order_data'], true );
                        if ( ! is_array( $od ) ) continue;
                        $order_total = 0.0;
                        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                            foreach ( $od['products'] as $pr ) {
                                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                                $price = isset( $pr['price'] ) ? floatval( $pr['price'] ) : 0.0;
                                if ( $qty > 0 ) $order_total += $qty * $price;
                            }
                        } else {
                            if ( is_array( $conf_prods_for_fin ) ) {
                                foreach ( $conf_prods_for_fin as $p ) {
                                    $pid = $p['id'];
                                    $price = isset( $p['price'] ) ? floatval( $p['price'] ) : 0.0;
                                    $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                                    foreach ( $labels as $k ) {
                                        if ( isset( $od[ $k ] ) ) { $q = intval( $od[ $k ] ); if ( $q > 0 ) $order_total += $q * $price; break; }
                                    }
                                }
                            }
                        }
                        if ( isset( $od['donationAmount'] ) ) $order_total += floatval( $od['donationAmount'] );
                        $sales_total += $order_total;
                        $payment = '';
                        if ( isset( $od['paymentMethod'] ) && ! empty( $od['paymentMethod'] ) ) $payment = strtolower( $od['paymentMethod'] );
                        else if ( ! empty( $od['checkNumber'] ) ) $payment = 'check';
                        else if ( ! empty( $od['payCash'] ) || ! empty( $od['pay_cash'] ) ) $payment = 'cash';
                        if ( $payment === 'check' ) $check_total += $order_total;
                        elseif ( $payment === 'cash' ) $cash_total += $order_total;
                    }
                }
                ?>
                <div class="subsales-financial-row" style="margin-bottom:12px; display:grid; gap:20px; grid-template-columns: repeat(3, 1fr);">
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-chart-line" aria-hidden="true"></span> Sales</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo '$' . number_format( (float) $sales_total, 2 ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-money" aria-hidden="true"></span> Cash</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo '$' . number_format( (float) $cash_total, 2 ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon subsales-checkbook-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">
                                <rect x="1" y="4" width="22" height="14" rx="2" />
                                <path d="M4 8h16" />
                                <path d="M6 14l3 3 8-8" />
                            </svg>
                        </span> Checks</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo '$' . number_format( (float) $check_total, 2 ); ?></p>
                        </div>
                    </div>
                </div>
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

// AJAX preview for delivery routes (admin only)
add_action( 'wp_ajax_subsales_delivery_preview', 'order_sync_ajax_delivery_preview' );
function order_sync_ajax_delivery_preview() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'subsales_delivery_preview', '_ajax_nonce' );

    global $wpdb;
    $orders_table = $wpdb->prefix . 'order_sync_orders';
    $driver_count = isset( $_POST['driver_count'] ) ? max(1, intval( $_POST['driver_count'] ) ) : 2;
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( wp_unslash( $_POST['start_address'] ) ) : '';

    // allow optional delivery_date filter (YYYY-MM-DD)
    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_date'] ) ) : '';
    if ( $delivery_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$orders_table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$orders_table} ORDER BY id ASC", ARRAY_A );
    }
    if ( ! $rows ) {
        wp_send_json_error( 'No orders found' );
    }

    $configured_products = order_sync_get_products_config();

    // group by normalized address
    $by_address = array();
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) $od = array();

        $products_map = array();
        foreach ( $configured_products as $pconf ) { $products_map[ $pconf['id'] ] = 0; }

        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                if ( $qty > 0 && $pid ) {
                    if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                    $products_map[ $pid ] += $qty;
                }
            }
        } else {
            foreach ( $configured_products as $p ) {
                $pid = $p['id'];
                $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                foreach ( $labels as $k ) {
                    if ( isset( $od[ $k ] ) ) {
                        $q = intval( $od[ $k ] );
                        if ( $q > 0 ) {
                            if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                            $products_map[ $pid ] += $q;
                        }
                        break;
                    }
                }
            }
        }

        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue;

        $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
        $addr_norm = order_sync_normalize_address( $address_raw );
        if ( empty( $addr_norm ) ) continue;

        $team_name = '';
        if ( ! empty( $r['team_id'] ) ) {
            $t = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}order_sync_teams WHERE id = %d", intval( $r['team_id'] ) ) );
            $team_name = $t ? $t->name : '';
        }
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : $r['user_id'] ) );
        $customer = isset( $od['customerName'] ) ? $od['customerName'] : ( isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['name'] ) ? $od['name'] : '' ) );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['cell'] ) ? $od['cell'] : ( isset( $od['phone'] ) ? $od['phone'] : '' ) );

        $entry = array(
            'order_id' => $r['order_id'],
            'team' => $team_name,
            'seller' => $seller,
            'address_raw' => $address_raw,
            'address_norm' => $addr_norm,
            'products_map' => $products_map,
            'customer' => $customer,
            'phone' => $phone,
            'total_qty' => $total_qty,
        );

        if ( ! isset( $by_address[ $addr_norm ] ) ) {
            $by_address[ $addr_norm ] = array( 'address_raw' => $address_raw, 'orders' => array() );
        }
        $by_address[ $addr_norm ]['orders'][] = $entry;
    }

    if ( empty( $by_address ) ) wp_send_json_error( 'No valid product orders found' );

    // Build manifest rows aggregated per address
    $manifest_rows = array();
    foreach ( $by_address as $addr_norm => $group ) {
        $combined_products = array();
        foreach ( $configured_products as $p ) { $combined_products[ $p['id'] ] = 0; }
        $order_ids = array();
        $team = '';
        $seller = '';
        $customer = '';
        $phone = '';
        foreach ( $group['orders'] as $o ) {
            $order_ids[] = $o['order_id'];
            foreach ( $o['products_map'] as $pid => $q ) { if ( ! isset( $combined_products[ $pid ] ) ) $combined_products[ $pid ] = 0; $combined_products[ $pid ] += intval( $q ); }
            if ( empty( $team ) && ! empty( $o['team'] ) ) $team = $o['team'];
            if ( empty( $seller ) && ! empty( $o['seller'] ) ) $seller = $o['seller'];
            if ( empty( $customer ) && ! empty( $o['customer'] ) ) $customer = $o['customer'];
            if ( empty( $phone ) && ! empty( $o['phone'] ) ) $phone = $o['phone'];
        }

        $manifest_rows[] = array(
            'address_raw' => $group['address_raw'],
            'address_norm' => $addr_norm,
            'products_map' => $combined_products,
            'order_ids' => $order_ids,
            'team' => $team,
            'seller' => $seller,
            'customer' => $customer,
            'phone' => $phone,
        );
    }

    // Assign to drivers using same greedy algorithm
    $drivers = array(); $driver_counts = array();
    for ( $i = 1; $i <= $driver_count; $i++ ) { $drivers[ $i ] = array(); $driver_counts[ $i ] = 0; }
    usort( $manifest_rows, function( $a, $b ) {
        $ca = isset( $a['order_ids'] ) ? count( $a['order_ids'] ) : 0;
        $cb = isset( $b['order_ids'] ) ? count( $b['order_ids'] ) : 0;
        return $cb - $ca;
    } );
    foreach ( $manifest_rows as $mr ) {
        $count_here = isset( $mr['order_ids'] ) ? count( $mr['order_ids'] ) : 1;
        $min_driver = null; $min_count = null;
        foreach ( $driver_counts as $dnum => $cnt ) { if ( $min_driver === null || $cnt < $min_count ) { $min_driver = $dnum; $min_count = $cnt; } }
        $drivers[ $min_driver ][] = $mr;
        $driver_counts[ $min_driver ] += $count_here;
    }

    // Geocode addresses (use cache helper) and prepare payload
    $payload_drivers = array();
    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    foreach ( $drivers as $dnum => $rows_driver ) {
        $payload_drivers[ $dnum ] = array();
        foreach ( $rows_driver as $r ) {
            $geo = order_sync_geocode_address( $r['address_raw'] );
            $lat = $geo && isset( $geo['lat'] ) ? $geo['lat'] : null;
            $lng = $geo && isset( $geo['lng'] ) ? $geo['lng'] : null;
            $payload_drivers[ $dnum ][] = array_merge( $r, array( 'lat' => $lat, 'lng' => $lng ) );
        }
    }

    $result = array( 'drivers' => $payload_drivers, 'api_key' => $api_key, 'products' => $configured_products, 'start_address' => $start_address );
    wp_send_json_success( $result );
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
        $login_mode = isset( $_POST['login_mode'] ) ? sanitize_text_field( $_POST['login_mode'] ) : 'legacy';

        $old_slug = get_option( 'order_sync_portal_slug', '' );
        update_option( 'order_sync_google_maps_api_key', $api_key );
        update_option( 'order_sync_interval', $sync_interval );
        update_option( 'order_sync_portal_slug', $portal_slug );
        update_option( 'order_sync_session_duration', $session_duration );
        update_option( 'order_sync_login_mode', $login_mode );

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
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    ?>
    <div class="wrap">
        <h1>Subsales Settings</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="#tab-overall" class="nav-tab nav-tab-active" data-target="#tab-overall">Overall Settings</a>
            <a href="#tab-branding" class="nav-tab" data-target="#tab-branding">Branding / Look &amp; Feel</a>
            <a href="#tab-products" class="nav-tab" data-target="#tab-products">Products</a>
            <a href="#tab-address_extracts" class="nav-tab" data-target="#tab-address_extracts">Address Extracts</a>
            <a href="#tab-backup_restore" class="nav-tab" data-target="#tab-backup_restore">Backup / Restore</a>
            <a href="#tab-system_info" class="nav-tab" data-target="#tab-system_info">System Info</a>
        </h2>
        
        <style>
        .subsales-tab-panel { display: none; margin-top: 20px; }
        .subsales-tab-panel.active { display: block; }
        
        /* Sub-tab navigation */
        .subsales-subtab-nav {
            display: flex;
            gap: 0;
            border-bottom: 1px solid #ccc;
            margin-bottom: 20px;
        }
        .subsales-subtab-btn {
            background: #f0f0f1;
            border: 1px solid #c3c4c7;
            border-bottom: none;
            padding: 10px 16px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #2c3338;
            margin: 0;
            border-radius: 4px 4px 0 0;
            position: relative;
            top: 1px;
        }
        .subsales-subtab-btn:hover {
            background: #f6f7f7;
        }
        .subsales-subtab-btn.active {
            background: #fff;
            color: #2271b1;
            border-bottom: 2px solid #fff;
            z-index: 1;
        }
        .subsales-subtab-content {
            display: none;
        }
        .subsales-subtab-content.active {
            display: block;
        }
        </style>
        
        <script>
        (function($) {
            $(document).ready(function() {
                function showPanel(target) {
                    $('.subsales-tab-panel').removeClass('active');
                    $(target).addClass('active');
                    $('.nav-tab').removeClass('nav-tab-active');
                    $('.nav-tab[data-target="' + target + '"]').addClass('nav-tab-active');
                }
                
                $('.nav-tab').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).data('target');
                    showPanel(target);
                    if (history && history.replaceState) {
                        history.replaceState(null, null, target);
                    }
                });
                
                // Initialize from hash or show first panel
                var hash = window.location.hash;
                if (hash && $(hash).length) {
                    showPanel(hash);
                } else {
                    showPanel('#tab-overall');
                }
                
                // Sub-tab navigation
                $('.subsales-subtab-btn').on('click', function(e) {
                    e.preventDefault();
                    var subtab = $(this).data('subtab');
                    
                    // Update buttons
                    $(this).siblings('.subsales-subtab-btn').removeClass('active');
                    $(this).addClass('active');
                    
                    // Update content
                    $(this).closest('.subsales-subtabs').find('.subsales-subtab-content').removeClass('active');
                    $('#subtab-' + subtab).addClass('active');
                });
            });
        })(jQuery);
        </script>
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
                                    <option value="120000" <?php selected( 120000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 minutes (Test)</option>
                                    <option value="7200000" <?php selected( 7200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 hours</option>
                                    <option value="43200000" <?php selected( 43200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>12 hours</option>
                                    <option value="86400000" <?php selected( 86400000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>24 hours</option>
                                </select>
                                <p class="description">Choose how long a session should be remembered for mobile clients when they login.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Login Mode</th>
                            <td>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio" name="login_mode" value="legacy" <?php checked( $login_mode, 'legacy' ); ?> />
                                        <strong>Legacy Login (Team + Code)</strong>
                                        <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                            Users enter a team name and access code to login. Original authentication method.
                                        </p>
                                    </label>
                                    <label style="display: block; margin-top: 10px;">
                                        <input type="radio" name="login_mode" value="user" <?php checked( $login_mode, 'user' ); ?> />
                                        <strong>User-Based Login (Name + Phone)</strong>
                                        <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                            Users search by name and verify with phone number, then select their team. Requires users to be created in the Teams → Users tab.
                                        </p>
                                    </label>
                                </fieldset>
                                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-top: 12px;">
                                    <strong>⚠️ Important:</strong> Changing the login mode will affect how users authenticate in the PWA.
                                    Make sure your users know which method to use after switching.
                                </div>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><?php submit_button( 'Save Overall Settings', 'primary', 'save_overall', false ); ?></p>
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
                                <div id="branding_samples" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                                    <div class="branding-sample-box" style="padding:8px;border:1px solid #eee;border-radius:6px;min-width:160px">
                                        <div style="margin-bottom:6px;font-weight:600">Button sample</div>
                                        <button id="branding_button_sample" class="subsales-branding-button" style="background:<?php echo esc_attr( $primary_color ); ?>;color:#fff;border:none;padding:8px 12px;border-radius:6px">Primary</button>
                                    </div>
                                    <div class="branding-sample-box" style="padding:8px;border:1px solid #eee;border-radius:6px;min-width:160px">
                                        <div style="margin-bottom:6px;font-weight:600">Header sample</div>
                                        <div id="branding_header_sample" class="subsales-branding-header" style="background:<?php echo esc_attr( $primary_color ); ?>;color:#fff;padding:8px;border-radius:4px;text-align:center"><?php echo esc_html( $branding ); ?></div>
                                    </div>
                                </div>
                                <p style="margin-top:8px">Primary color: <input type="color" name="primary_color" value="<?php echo esc_attr( $primary_color ); ?>" /></p>
                                <p class="description">These options control how the embedded PWA will style buttons and header on mobile clients.</p>
                                <script>
                                (function(){
                                    function applyBrandingVariant(variant){
                                        var btn = document.getElementById('branding_button_sample');
                                        var hdr = document.getElementById('branding_header_sample');
                                        if(!btn || !hdr) return;
                                        // remove existing variant classes
                                        btn.classList.remove('branding-variant-default','branding-variant-flat','branding-variant-rounded','branding-variant-dark');
                                        hdr.classList.remove('branding-variant-default','branding-variant-flat','branding-variant-rounded','branding-variant-dark');
                                        var cls = 'branding-variant-' + (variant || 'default');
                                        btn.classList.add(cls);
                                        hdr.classList.add(cls);
                                    }
                                    function applyPrimaryColor(color){
                                        var btn = document.getElementById('branding_button_sample');
                                        var hdr = document.getElementById('branding_header_sample');
                                        if(btn) btn.style.background = color;
                                        if(hdr) hdr.style.background = color;
                                    }
                                    // wire radio buttons
                                    var radios = document.querySelectorAll('input[name="style_variant"]');
                                    radios.forEach(function(r){ r.addEventListener('change', function(e){ applyBrandingVariant(e.target.value); }); });
                                    // wire color input
                                    var colorInput = document.querySelector('input[name="primary_color"]');
                                    if(colorInput){ colorInput.addEventListener('input', function(e){ applyPrimaryColor(e.target.value); }); }
                                    // initialize on load
                                    var sel = document.querySelector('input[name="style_variant"]:checked');
                                    if(sel) applyBrandingVariant(sel.value);
                                    if(colorInput) applyPrimaryColor(colorInput.value);
                                })();
                                </script>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><?php submit_button( 'Save Branding', 'primary', 'save_branding', false ); ?></p>
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
                            <td>
                                <div id="products_repeatable">
                                    <table id="products_table" class="widefat subsales-products-table">
                                <thead><tr><th class="col-name">Name</th><th class="col-price">Price (USD)</th><th class="col-visible">Visible</th><th class="col-actions">Actions</th></tr></thead>
                                <tbody>
                                <?php if ( ! empty( $configured_products ) ) : ?>
                                    <?php foreach ( $configured_products as $idx => $p ) : ?>
                                        <tr data-index="<?php echo intval( $idx ); ?>">
                                            <td>
                                                <input type="text" name="product_name[]" class="regular-text product-name" value="<?php echo esc_attr( $p['name'] ?? '' ); ?>" />
                                                <input type="hidden" name="product_id[]" class="product-id" value="<?php echo esc_attr( $p['id'] ?? '' ); ?>" />
                                            </td>
                                            <td><input type="text" name="product_price[]" class="regular-text product-price" value="<?php echo esc_attr( $p['price'] ?? '0.00' ); ?>" /></td>
                                            <td class="col-center"><input type="checkbox" name="product_visible[]" value="<?php echo esc_attr( $p['id'] ?? $idx ); ?>" <?php checked( 1, intval( $p['visible'] ?? 0 ) ); ?> /></td>
                                            <td class="col-center"><button type="button" class="button button-link remove-product">Remove</button></td>
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

                                var tdVis = document.createElement('td'); tdVis.className='col-center';
                                var visInput = document.createElement('input'); visInput.type='checkbox'; visInput.name='product_visible[]'; visInput.checked = !!visible; visInput.value = id || '';
                                tdVis.appendChild(visInput);

                                var tdAct = document.createElement('td'); tdAct.className='col-center';
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
                    <p class="submit"><?php submit_button( 'Save Products', 'primary', 'save_products', false ); ?></p>
                    </form>
                </div>

                <div id="tab-address_extracts" class="subsales-tab-panel">
                    <h2 style="margin-top:18px">Address Extracts</h2>
                    <p>Configure which ZIP codes to serve and generate offline address extracts.</p>
                    
                    <?php
                    // Handle ZIP list save
                    if ( isset( $_POST['subsales_zip_list'] ) && check_admin_referer( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ) ) {
                        $raw = sanitize_text_field( wp_unslash( $_POST['subsales_zip_list'] ) );
                        $parts = preg_split( '/[\,\s]+/', $raw );
                        $zips = array();
                        foreach ( $parts as $p ) {
                            $pz = preg_replace( '/[^0-9]/', '', $p );
                            if ( strlen( $pz ) === 5 ) $zips[] = $pz;
                        }
                        update_option( 'subsales_served_zips', $zips );
                        subsales_update_zip_index();
                        echo '<div class="updated"><p>ZIP codes saved.</p></div>';
                    }

                    $saved = get_option( 'subsales_served_zips', array() );
                    $zip_text = implode( ',', $saved );
                    ?>
                    
                    <!-- ZIP Code Configuration (always visible) -->
                    <form method="POST" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                        <?php wp_nonce_field( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ); ?>
                        <table class="form-table" style="margin: 0;">
                            <tr>
                                <th scope="row" style="width: 200px;">Served ZIP codes</th>
                                <td>
                                    <input type="text" name="subsales_zip_list" class="large-text" value="<?php echo esc_attr( $zip_text ); ?>" placeholder="e.g. 06479,06489,06444" />
                                    <p class="description">Enter comma-separated 5-digit ZIP codes. These will be used for both data extraction and generation.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit" style="margin: 12px 0 0 0; padding: 0;">
                            <button class="button button-primary" type="submit">Save ZIP Codes</button>
                        </p>
                    </form>

                    <!-- Sub-tabs for workflow steps -->
                    <div class="subsales-subtabs" style="margin-top: 20px;">
                        <div class="subsales-subtab-nav" style="border-bottom: 1px solid #ccc; margin-bottom: 20px;">
                            <button type="button" class="subsales-subtab-btn active" data-subtab="data-sources">1. Data Sources</button>
                            <button type="button" class="subsales-subtab-btn" data-subtab="generate">2. Generate Extracts</button>
                        </div>

                        <!-- Tab 1: Data Sources -->
                        <div id="subtab-data-sources" class="subsales-subtab-content active">
                            <h3 style="margin-top: 0;">Step 1: Upload State Address Data</h3>
                            <p>Upload a CSV file containing address data for your state, then extract only the ZIP codes you configured above.</p>
                            
                            <?php
                            $upload = wp_upload_dir();
                            $oa_full_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses-full.csv';
                            $oa_filtered_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses.csv';
                            $oa_full_exists = file_exists( $oa_full_path );
                            $oa_filtered_exists = file_exists( $oa_filtered_path );
                            
                            // Handle file upload
                            if ( isset( $_FILES['openaddresses_csv'] ) && isset( $_POST['subsales_upload_oa_nonce'] ) && 
                                 check_admin_referer( 'subsales_upload_oa', 'subsales_upload_oa_nonce' ) ) {
                                
                                if ( ! function_exists( 'wp_handle_upload' ) ) {
                                    require_once( ABSPATH . 'wp-admin/includes/file.php' );
                                }
                                
                                $uploadedfile = $_FILES['openaddresses_csv'];
                                $upload_overrides = array(
                                    'test_form' => false,
                                    'test_type' => false,
                                    'mimes' => array( 'csv' => 'text/csv', 'txt' => 'text/plain' )
                                );
                                
                                $base_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
                                if ( ! is_dir( $base_dir ) ) {
                                    wp_mkdir_p( $base_dir );
                                }
                                
                                $movefile = wp_handle_upload( $uploadedfile, $upload_overrides );
                                
                                if ( $movefile && ! isset( $movefile['error'] ) ) {
                                    rename( $movefile['file'], $oa_full_path );
                                    echo '<div class="notice notice-success"><p><strong>Success!</strong> Uploaded ' . size_format( filesize( $oa_full_path ) ) . ' CSV file. Now click "Extract ZIP Codes" below.</p></div>';
                                    $oa_full_exists = true;
                                } else {
                                    echo '<div class="notice notice-error"><p><strong>Upload failed:</strong> ' . esc_html( $movefile['error'] ) . '</p></div>';
                                }
                            }
                            ?>
                            
                            <div style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                                <h4 style="margin-top: 0;">Upload CSV File</h4>
                                <form method="post" enctype="multipart/form-data">
                                    <?php wp_nonce_field( 'subsales_upload_oa', 'subsales_upload_oa_nonce' ); ?>
                                    <table class="form-table">
                                        <tr>
                                            <th scope="row">CSV File</th>
                                            <td>
                                                <input type="file" name="openaddresses_csv" accept=".csv" required />
                                                <p class="description">
                                                    Get address data from <a href="https://results.openaddresses.io/" target="_blank">OpenAddresses.io</a>, your state GIS portal, or county website.<br/>
                                                    Required columns: <code>NUMBER</code>, <code>STREET</code>, <code>POSTCODE</code><br/>
                                                    Optional: <code>UNIT</code>, <code>CITY</code>, <code>REGION</code>, <code>LAT</code>, <code>LON</code>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                    <p class="submit" style="padding: 0;">
                                        <button type="submit" class="button button-primary">Upload CSV</button>
                                    </p>
                                </form>
                            </div>

                            <div style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                                <h4 style="margin-top: 0;">File Status</h4>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Full state file</th>
                                        <td>
                                            <?php if ( $oa_full_exists ): ?>
                                                <span style="color: green;">✓ Uploaded</span> (<?php echo size_format( filesize( $oa_full_path ) ); ?>)
                                            <?php else: ?>
                                                <span style="color: #d63638;">✗ Not uploaded</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Filtered file</th>
                                        <td>
                                            <?php if ( $oa_filtered_exists ): ?>
                                                <span style="color: green;">✓ Extracted</span> (<?php echo size_format( filesize( $oa_filtered_path ) ); ?>)
                                                <?php
                                                $filter_meta = get_option( 'subsales_oa_filter_meta', array() );
                                                if ( isset( $filter_meta['zips'] ) ) {
                                                    echo ' - ZIPs: ' . esc_html( implode( ', ', $filter_meta['zips'] ) );
                                                    if ( isset( $filter_meta['count'] ) ) {
                                                        echo ' (' . number_format( $filter_meta['count'] ) . ' addresses)';
                                                    }
                                                }
                                                ?>
                                            <?php else: ?>
                                                <span style="color: orange;">✗ Not yet extracted</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                                <p class="submit" style="margin: 0; padding: 0;">
                                    <button id="subsales-extract-zips-btn" class="button button-primary" type="button" <?php disabled( ! $oa_full_exists ); ?>>Extract ZIP Codes</button>
                                    <?php if ( ! $oa_full_exists ): ?>
                                        <span style="color: #856404; margin-left: 10px;">← Upload CSV file first</span>
                                    <?php elseif ( empty( $saved ) ): ?>
                                        <span style="color: #856404; margin-left: 10px;">← Configure ZIP codes first</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>

                        <!-- Tab 2: Generate Extracts -->
                        <div id="subtab-generate" class="subsales-subtab-content">
                            <h3 style="margin-top: 0;">Step 2: Generate Address Extracts</h3>
                            <p>Combine data from OpenStreetMap and your uploaded OpenAddresses file to create comprehensive per-ZIP JSON files.</p>
                            
                            <div style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                                <h4 style="margin-top: 0;">Data Sources</h4>
                                <?php $sources = get_option( 'subsales_address_sources', array( 'osm', 'openaddresses' ) ); ?>
                                <p>
                                    <label><input type="checkbox" checked disabled /> <strong>OpenStreetMap</strong> - Live API with structured address data (~3,000+ per ZIP)</label><br/>
                                    <label><input type="checkbox" <?php checked( $oa_filtered_exists ); ?> disabled /> <strong>OpenAddresses.io</strong> - <?php echo $oa_filtered_exists ? 'Enabled' : 'Not available (upload and extract first)'; ?></label>
                                </p>
                                <p class="description">
                                    Both sources will be combined and deduplicated automatically. OpenStreetMap is always queried; OpenAddresses is used if available.
                                </p>
                            </div>

                            <div style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
                                <h4 style="margin-top: 0;">Actions</h4>
                                <p>
                                    <button id="subsales-generate-btn" class="button button-large button-primary" type="button">Generate Extracts</button>
                                    <button id="subsales-search-address-btn" class="button" type="button">Search Address</button>
                                    <button id="subsales-refresh-index-btn" class="button" type="button">Refresh ZIP Index</button>
                                </p>
                                <p class="description">
                                    <strong>Generate Extracts:</strong> Creates JSON files for each ZIP code containing all addresses.<br/>
                                    <strong>Search Address:</strong> Test if a specific address exists in the data sources before generating.<br/>
                                    <strong>Refresh ZIP Index:</strong> Scans the uploads directory and updates the PWA's ZIP index file.
                                </p>
                                <div id="subsales-generate-log" style="margin-top:12px;white-space:pre-wrap;background:#f8f8f8;border:1px solid #eaeaea;padding:8px;display:none"></div>
                            </div>

                            <h4>Existing Extracts</h4>
                            <div id="subsales-zip-status">
                                <?php
                                $upload = wp_upload_dir(); 
                                $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
                                if ( is_dir( $base ) ) {
                                    $files = scandir( $base );
                                    $json_files = array_filter( $files, function( $f ) use ( $base ) {
                                        return is_file( $base . '/' . $f ) && substr( $f, -5 ) === '.json' && preg_match( '/^\d{5}\.json$/', $f );
                                    } );
                                    
                                    if ( ! empty( $json_files ) ) {
                                        echo '<table class="widefat" style="margin-top:12px">';
                                        echo '<thead><tr><th>ZIP Code</th><th>File Size</th><th>Actions</th></tr></thead>';
                                        echo '<tbody>';
                                        foreach ( $json_files as $f ) {
                                            $path = $base . '/' . $f;
                                            $url = trailingslashit( $upload['baseurl'] ) . 'subsales-zipdata/' . rawurlencode( $f );
                                            $size = size_format( filesize( $path ) );
                                            $zip_code = basename( $f, '.json' );
                                            echo '<tr>';
                                            echo '<td><strong>' . esc_html( $zip_code ) . '</strong></td>';
                                            echo '<td>' . esc_html( $size ) . ' <a href="' . esc_url( $url ) . '" target="_blank" style="text-decoration:none">📄</a></td>';
                                            echo '<td><button type="button" class="button button-small subsales-delete-zip" data-zip="' . esc_attr( $zip_code ) . '">Delete</button></td>';
                                            echo '</tr>';
                                        }
                                        echo '</tbody></table>';
                                    } else {
                                        echo '<p>No extracts generated yet. Click "Generate Extracts" above.</p>';
                                    }
                                } else {
                                    echo '<p>No extracts generated yet. Click "Generate Extracts" above.</p>';
                                }
                                ?>
                            </div>

                            <h4 style="margin-top: 30px;">Generation History</h4>
                            <?php
                            $logs = subsales_get_generation_logs( 10 );
                            if ( empty( $logs ) ) {
                                echo '<p>No generation history yet.</p>';
                            } else {
                                foreach ( $logs as $log ) {
                                    $summary = isset( $log['summary'] ) ? $log['summary'] : array();
                                    $timestamp = isset( $log['timestamp'] ) ? $log['timestamp'] : 'Unknown';
                                    $user = isset( $log['user'] ) ? $log['user'] : 'Unknown';
                                    $total_zips = isset( $summary['total_zips'] ) ? $summary['total_zips'] : 0;
                                    $successful = isset( $summary['successful'] ) ? $summary['successful'] : 0;
                                    $failed = isset( $summary['failed'] ) ? $summary['failed'] : 0;
                                    $total_addresses = isset( $summary['total_addresses'] ) ? $summary['total_addresses'] : 0;
                                    $duration = isset( $summary['duration_seconds'] ) ? $summary['duration_seconds'] : 0;
                                    
                                    echo '<details style="margin-bottom:12px;border:1px solid #ddd;padding:12px;background:#fff;border-radius:4px">';
                                    echo '<summary style="cursor:pointer;font-weight:600">';
                                    echo '<span style="color:#2271b1">📅 ' . esc_html( $timestamp ) . '</span> ';
                                    echo '— ' . number_format( $total_addresses ) . ' addresses from ' . esc_html( $total_zips ) . ' ZIP(s) ';
                                    echo '(' . esc_html( $duration ) . 's)';
                                    if ( $failed > 0 ) {
                                        echo ' <span style="color:#d63638">⚠️ ' . esc_html( $failed ) . ' failed</span>';
                                    }
                                    echo '</summary>';
                                    
                                    echo '<div style="margin-top:12px;padding-left:12px">';
                                    echo '<p><strong>User:</strong> ' . esc_html( $user ) . '</p>';
                                    
                                    if ( isset( $log['results'] ) && is_array( $log['results'] ) ) {
                                        echo '<table class="widefat" style="margin-top:8px">';
                                        echo '<thead><tr><th>ZIP</th><th>Addresses</th><th>Sources</th><th>Duplicates</th><th>Duration</th><th>Status</th></tr></thead>';
                                        echo '<tbody>';
                                        
                                        foreach ( $log['results'] as $zip => $result ) {
                                            $count = isset( $result['count'] ) ? $result['count'] : 0;
                                            $zip_duration = isset( $result['duration'] ) ? $result['duration'] : 0;
                                            $has_error = isset( $result['error'] );
                                            $sources_info = isset( $result['sources'] ) ? $result['sources'] : array();
                                            $duplicates = isset( $result['duplicates_removed'] ) ? $result['duplicates_removed'] : 0;
                                            
                                            echo '<tr>';
                                            echo '<td><strong>' . esc_html( $zip ) . '</strong></td>';
                                            echo '<td>' . number_format( $count ) . '</td>';
                                            echo '<td>';
                                            if ( ! empty( $sources_info ) ) {
                                                $parts = array();
                                                foreach ( $sources_info as $src => $cnt ) {
                                                    $parts[] = strtoupper( $src ) . ': ' . number_format( $cnt );
                                                }
                                                echo esc_html( implode( ', ', $parts ) );
                                            } else {
                                                echo '—';
                                            }
                                            echo '</td>';
                                            echo '<td>' . number_format( $duplicates ) . '</td>';
                                            echo '<td>' . esc_html( $zip_duration ) . 's</td>';
                                            echo '<td style="color:' . ( $has_error ? '#d63638' : '#00a32a' ) . '">';
                                            echo $has_error ? '❌' : '✅';
                                            echo '</td>';
                                            echo '</tr>';
                                        }
                                        
                                        echo '</tbody></table>';
                                    }
                                    
                                    echo '</div>';
                                    echo '</details>';
                                }
                            }
                            ?>
                        </div>
                    </div>
                </div>

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
                <td><?php echo esc_html( order_sync_get_plugin_version() ); ?></td>
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
                <th scope="row">PhpSpreadsheet</th>
                <td>
                    <?php
                    $ps_version = null;
                    // Try Composer InstalledVersions first (if available)
                    if ( class_exists( '\\Composer\\InstalledVersions' ) ) {
                        try {
                            if ( method_exists( '\\Composer\\InstalledVersions', 'getPrettyVersion' ) ) {
                                $ps_version = \Composer\InstalledVersions::getPrettyVersion( 'phpoffice/phpspreadsheet' );
                            }
                        } catch ( Exception $e ) {
                            $ps_version = null;
                        }
                    }
                    // Fallback: detect presence of the main class
                    if ( ! $ps_version && class_exists( 'PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
                        $ps_version = 'installed';
                    }

                    if ( $ps_version ) {
                        echo esc_html( $ps_version );
                    } else {
                        echo '<span style="color:red">Missing</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">DomPDF</th>
                <td>
                    <?php
                    $dompdf_path = plugin_dir_path( __FILE__ ) . 'vendor/dompdf/autoload.inc.php';
                    if ( file_exists( $dompdf_path ) ) {
                        echo '<span style="color:green">Installed</span>';
                        // Try to get version
                        require_once $dompdf_path;
                        if ( defined( 'Dompdf\\VERSION' ) ) {
                            echo ' (v' . esc_html( Dompdf\VERSION ) . ')';
                        }
                    } else {
                        echo '<span style="color:red">Missing</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Database Tables</th>
                <td>
                    <?php
                    global $wpdb;
                    $tables = array(
                        $wpdb->prefix . 'order_sync_orders' => 'Orders',
                        $wpdb->prefix . 'order_sync_teams' => 'Teams',
                        $wpdb->prefix . 'order_sync_team_members' => 'Team Members',
                        $wpdb->prefix . 'order_sync_user_teams' => 'User-Team Assignments'
                    );
                    
                    foreach ( $tables as $table => $name ) {
                        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
                        echo '<span style="color: ' . ( $exists ? 'green' : 'red' ) . ';">● ' . $name . '</span><br>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Database Schema</th>
                <td>
                    <p>Run this to update database constraints and fix legacy user data:</p>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'subsales_migrate_db' ); ?>
                        <button type="submit" name="run_db_migration" class="button button-primary">Run Database Migration</button>
                    </form>
                    <p class="description">This will: 1) Set default phones for users with NULL/empty phones, 2) Add NOT NULL constraint to phone, 3) Add UNIQUE constraint to phone, 4) Remove UNIQUE constraint from email.</p>
                    <?php
                    if ( isset( $_POST['run_db_migration'] ) ) {
                        check_admin_referer( 'subsales_migrate_db' );
                        if ( current_user_can( 'manage_options' ) ) {
                            // Run the table creation function which includes migration logic
                            order_sync_create_table();
                            echo '<div class="notice notice-success inline" style="margin-top: 10px;"><p>Database migration completed successfully!</p></div>';
                        }
                    }
                    ?>
                </td>
            </tr>
        </table>
                </div>
    </div> <!-- .wrap -->
    <?php
}

// Teams management admin page with tabbed interface
function order_sync_teams_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $teams_table = $wpdb->prefix . 'order_sync_teams';
    $members_table = $wpdb->prefix . 'order_sync_team_members';

    // Determine active tab
    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'users';

    // Handle user/member creation
    if ( isset( $_POST['add_user'] ) ) {
        check_admin_referer( 'order_sync_add_user' );
        $name = sanitize_text_field( $_POST['user_name'] ?? '' );
        $email = sanitize_email( $_POST['user_email'] ?? '' );
        $phone = sanitize_text_field( $_POST['user_phone'] ?? '' );
        $role = sanitize_text_field( $_POST['user_role'] ?? 'member' );
        
        if ( empty( $name ) ) {
            echo '<div class="notice notice-error"><p>User name is required.</p></div>';
        } elseif ( empty( $phone ) ) {
            echo '<div class="notice notice-error"><p>Phone number is required.</p></div>';
        } elseif ( ! preg_match( '/^[0-9]{10}$/', preg_replace( '/[^0-9]/', '', $phone ) ) ) {
            echo '<div class="notice notice-error"><p>Phone number must be 10 digits.</p></div>';
        } else {
            // Normalize phone to 10 digits only
            $phone = preg_replace( '/[^0-9]/', '', $phone );
            $email = $email ?: '';
            // Add user without team assignment initially
            $result = $wpdb->insert(
                $members_table,
                array(
                    'team_id' => 0,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                    'status' => 'active'
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            
            if ( $result ) {
                echo '<div class="notice notice-success"><p>User created successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create user.</p></div>';
            }
        }
    }

    // Handle user update
    if ( isset( $_POST['edit_user'] ) ) {
        check_admin_referer( 'order_sync_edit_user' );
        $user_id = intval( $_POST['user_id'] ?? 0 );
        $name = sanitize_text_field( $_POST['user_name'] ?? '' );
        $email = sanitize_email( $_POST['user_email'] ?? '' );
        $phone = sanitize_text_field( $_POST['user_phone'] ?? '' );
        $role = sanitize_text_field( $_POST['user_role'] ?? 'member' );
        
        if ( empty( $name ) ) {
            echo '<div class="notice notice-error"><p>User name is required.</p></div>';
        } elseif ( empty( $phone ) ) {
            echo '<div class="notice notice-error"><p>Phone number is required.</p></div>';
        } elseif ( ! preg_match( '/^[0-9]{10}$/', preg_replace( '/[^0-9]/', '', $phone ) ) ) {
            echo '<div class="notice notice-error"><p>Phone number must be 10 digits.</p></div>';
        } elseif ( $user_id && ! empty( $name ) ) {
            // Normalize phone to 10 digits only
            $phone = preg_replace( '/[^0-9]/', '', $phone );
            $updated = $wpdb->update(
                $members_table,
                array( 'name' => $name, 'email' => $email ?: '', 'phone' => $phone, 'role' => $role ),
                array( 'id' => $user_id ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            
            if ( $updated !== false ) {
                echo '<div class="notice notice-success"><p>User updated successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to update user.</p></div>';
            }
        }
    }

    // Handle user deletion
    if ( isset( $_POST['delete_user'] ) ) {
        check_admin_referer( 'order_sync_delete_user' );
        $user_id = intval( $_POST['user_id'] ?? 0 );
        if ( $user_id ) {
            order_sync_remove_team_member( $user_id );
            echo '<div class="notice notice-success"><p>User deleted successfully.</p></div>';
        }
    }

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
            $updated = $wpdb->update( $teams_table, array( 'name' => $name, 'access_code' => $code, 'description' => $desc ), array( 'id' => $tid ), array( '%s', '%s', '%s' ), array( '%d' ) );
            if ( $updated !== false ) {
                echo '<div class="notice notice-success"><p>Team updated.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to update team.</p></div>';
            }
        }
    }

    // Handle team deletion
    if ( isset( $_POST['delete_team'] ) ) {
        check_admin_referer( 'order_sync_delete_team' );
        $tid = intval( $_POST['team_id'] ?? 0 );
        if ( $tid ) {
            order_sync_remove_team( $tid );
            echo '<div class="notice notice-success"><p>Team removed.</p></div>';
        }
    }

    // Handle AJAX team assignment
    if ( isset( $_POST['assign_user_to_team'] ) ) {
        check_admin_referer( 'order_sync_team_assignment' );
        $user_id = intval( $_POST['user_id'] ?? 0 );
        $team_id = intval( $_POST['team_id'] ?? 0 );
        
        if ( $user_id ) {
            $updated = $wpdb->update(
                $members_table,
                array( 'team_id' => $team_id ),
                array( 'id' => $user_id ),
                array( '%d' ),
                array( '%d' )
            );
            
            if ( $updated !== false ) {
                wp_send_json_success( array( 'message' => 'User assigned to team successfully' ) );
            } else {
                wp_send_json_error( array( 'message' => 'Failed to assign user to team' ) );
            }
        }
        wp_send_json_error( array( 'message' => 'Invalid user ID' ) );
    }

    // Get all users and teams
    $all_users = $wpdb->get_results( "SELECT * FROM {$members_table} ORDER BY name ASC", ARRAY_A );
    $teams = order_sync_get_teams();
    
    // Check if editing a user
    $editing_user = false;
    $edit_user = array( 'id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'role' => 'member' );
    if ( isset( $_GET['edit_user'] ) ) {
        $uid = intval( $_GET['edit_user'] );
        if ( $uid ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$members_table} WHERE id = %d", $uid ), ARRAY_A );
            if ( $row ) {
                $editing_user = true;
                $edit_user = $row;
            }
        }
    }
    
    // Check if editing a team
    $editing_team = false;
    $edit_team = array( 'id' => 0, 'name' => '', 'access_code' => '', 'description' => '' );
    if ( isset( $_GET['edit_team'] ) ) {
        $tid = intval( $_GET['edit_team'] );
        if ( $tid ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$teams_table} WHERE id = %d", $tid ), ARRAY_A );
            if ( $row ) {
                $editing_team = true;
                $edit_team = $row;
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>User &amp; Team Management</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=subsales-teams&tab=users" class="nav-tab <?php echo $active_tab === 'users' ? 'nav-tab-active' : ''; ?>">Users</a>
            <a href="?page=subsales-teams&tab=teams" class="nav-tab <?php echo $active_tab === 'teams' ? 'nav-tab-active' : ''; ?>">Teams</a>
        </h2>

        <?php if ( $active_tab === 'users' ) : ?>
            <!-- USERS TAB -->
            <div class="subsales-tab-content" style="margin-top: 20px;">
                <h2><?php echo $editing_user ? 'Edit User' : 'Add New User'; ?></h2>
                <form method="post" action="?page=subsales-teams&tab=users">
                    <?php if ( $editing_user ): wp_nonce_field( 'order_sync_edit_user' ); else: wp_nonce_field( 'order_sync_add_user' ); endif; ?>
                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $edit_user['id'] ); ?>" />
                    <table class="form-table">
                        <tr>
                            <th><label for="user_name">Name *</label></th>
                            <td><input type="text" id="user_name" name="user_name" class="regular-text" required value="<?php echo esc_attr( $edit_user['name'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="user_phone">Phone *</label></th>
                            <td>
                                <input type="tel" id="user_phone" name="user_phone" class="regular-text" required value="<?php echo esc_attr( $edit_user['phone'] ?? '' ); ?>" pattern="[0-9]{3}[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}" placeholder="555-123-4567" />
                                <p class="description">Required. 10-digit phone number (unique per user).</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="user_email">Email</label></th>
                            <td><input type="email" id="user_email" name="user_email" class="regular-text" value="<?php echo esc_attr( $edit_user['email'] ); ?>" placeholder="(optional)" /></td>
                        </tr>
                        <tr>
                            <th><label for="user_role">Role</label></th>
                            <td>
                                <select id="user_role" name="user_role">
                                    <option value="member" <?php selected( $edit_user['role'], 'member' ); ?>>Member</option>
                                    <option value="manager" <?php selected( $edit_user['role'], 'manager' ); ?>>Manager</option>
                                    <option value="admin" <?php selected( $edit_user['role'], 'admin' ); ?>>Admin</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php if ( $editing_user ): ?>
                        <p>
                            <button name="edit_user" class="button button-primary">Update User</button>
                            <a href="?page=subsales-teams&tab=users" class="button">Cancel</a>
                        </p>
                    <?php else: ?>
                        <p><button name="add_user" class="button button-primary">Add User</button></p>
                    <?php endif; ?>
                </form>

                <h2 style="margin-top: 30px;">All Users</h2>
                <?php if ( ! empty( $all_users ) ) : ?>
                <input type="text" id="allUsersSearchBox" placeholder="Search users by name, phone, or email..." style="width: 100%; max-width: 400px; padding: 8px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 4px;" />
                <table class="wp-list-table widefat fixed striped" id="allUsersTable">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Name - Phone</th>
                            <th style="width: 20%;">Email</th>
                            <th style="width: 30%;">Team(s)</th>
                            <th style="width: 10%;">Role</th>
                            <th style="width: 15%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_users as $user ) : ?>
                        <?php
                            // Get all teams for this user from junction table
                            $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
                            $user_team_ids = $wpdb->get_col( $wpdb->prepare(
                                "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
                                $user['id']
                            ));
                            
                            $team_names = array();
                            if ( ! empty( $user_team_ids ) ) {
                                $team_names = $wpdb->get_col(
                                    "SELECT name FROM {$teams_table} WHERE id IN (" . implode( ',', array_map( 'intval', $user_team_ids ) ) . ")"
                                );
                            }
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $user['name'] ); ?></strong><br>
                                <span style="color: #666; font-size: 13px;">📞 <?php echo esc_html( $user['phone'] ?? 'No phone' ); ?></span>
                            </td>
                            <td><?php echo esc_html( $user['email'] ?: '—' ); ?></td>
                            <td>
                                <?php if ( ! empty( $team_names ) ) : ?>
                                    <?php echo esc_html( implode( ', ', $team_names ) ); ?>
                                <?php else : ?>
                                    <em style="color: #999;">No teams</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( ucfirst( $user['role'] ) ); ?></td>
                            <td>
                                <a href="?page=subsales-teams&tab=users&edit_user=<?php echo intval( $user['id'] ); ?>" class="button button-small">Edit</a>
                                <form method="post" action="?page=subsales-teams&tab=users" style="display: inline;">
                                    <?php wp_nonce_field( 'order_sync_delete_user' ); ?>
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $user['id'] ); ?>" />
                                    <input type="submit" name="delete_user" value="Delete" class="button button-small button-link-delete" 
                                           onclick="return confirm('Are you sure you want to delete this user?')" />
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p>No users yet. Add your first user above.</p>
                <?php endif; ?>
            </div>

        <?php else : ?>
            <!-- TEAMS TAB -->
            <div class="subsales-tab-content" style="margin-top: 20px;">
                <h2><?php echo $editing_team ? 'Edit Team' : 'Add New Team'; ?></h2>
                <form method="post" action="?page=subsales-teams&tab=teams">
                    <?php if ( $editing_team ): wp_nonce_field( 'order_sync_edit_team' ); else: wp_nonce_field( 'order_sync_add_team' ); endif; ?>
                    <input type="hidden" name="team_id" value="<?php echo esc_attr( $edit_team['id'] ); ?>" />
                    <table class="form-table">
                        <tr>
                            <th><label for="team_name">Team Name</label></th>
                            <td><input type="text" id="team_name" name="team_name" class="regular-text" required value="<?php echo esc_attr( $edit_team['name'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="team_code">Access Code</label></th>
                            <td><input type="text" id="team_code" name="team_code" class="regular-text" required value="<?php echo esc_attr( $edit_team['access_code'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="team_description">Description</label></th>
                            <td><textarea id="team_description" name="team_description" class="large-text" rows="3"><?php echo esc_textarea( $edit_team['description'] ); ?></textarea></td>
                        </tr>
                    </table>
                    <?php if ( $editing_team ): ?>
                        <p>
                            <button name="edit_team" class="button button-primary">Update Team</button>
                            <a href="?page=subsales-teams&tab=teams" class="button">Cancel</a>
                        </p>
                    <?php else: ?>
                        <p><button name="add_team" class="button button-primary">Add Team</button></p>
                    <?php endif; ?>
                </form>

                <h2 style="margin-top: 30px;">Team Management</h2>
                <p class="description">Drag users from the available users box into team boxes to assign them. Users can belong to multiple teams.</p>
                
                <style>
                    .subsales-team-grid-wrapper {
                        display: grid;
                        gap: 20px;
                        margin-top: 20px;
                    }
                    
                    /* Large screens: 2 equal columns */
                    @media (min-width: 900px) {
                        .subsales-team-grid-wrapper {
                            grid-template-columns: 1fr 1fr;
                        }
                    }
                    
                    /* Small screens: single column stacked */
                    @media (max-width: 899px) {
                        .subsales-team-grid-wrapper {
                            grid-template-columns: 1fr;
                        }
                    }
                    
                    .available-users-column {
                        background: #f9f9f9;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        padding: 15px;
                    }
                    
                    .available-teams-column {
                        background: #f9f9f9;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        padding: 15px;
                    }
                    
                    .teams-list {
                        display: grid;
                        gap: 15px;
                    }
                    
                    /* Large screens: 3 columns inside teams container */
                    @media (min-width: 1600px) {
                        .teams-list {
                            grid-template-columns: repeat(3, 1fr);
                        }
                    }
                    
                    /* Medium-large screens: 2 columns inside teams container */
                    @media (min-width: 1200px) and (max-width: 1599px) {
                        .teams-list {
                            grid-template-columns: repeat(2, 1fr);
                        }
                    }
                    
                    /* Medium and small screens: 1 column inside teams container */
                    @media (max-width: 1199px) {
                        .teams-list {
                            grid-template-columns: 1fr;
                        }
                    }
                    
                    .team-box {
                        height: fit-content;
                        margin-bottom: 0 !important;
                    }
                </style>
                
                <div class="subsales-team-grid-wrapper">
                    <!-- Available Users -->
                    <div class="available-users-column">
                        <h3 style="margin-top: 0;">Available Users</h3>
                        <input type="text" id="userSearchBox" placeholder="Search by name or phone..." style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 4px;" />
                        <div class="available-users-list" id="availableUsersList" style="min-height: 200px;">
                            <?php
                            // Show all users in the available list (they can be in multiple teams)
                            if ( ! empty( $all_users ) ) :
                                foreach ( $all_users as $user ) :
                            ?>
                                <div class="user-card draggable" draggable="true" data-user-id="<?php echo intval( $user['id'] ); ?>" 
                                     style="background: #fff; border: 1px solid #ccc; border-radius: 4px; padding: 10px; margin-bottom: 8px; cursor: move;">
                                    <strong><?php echo esc_html( $user['name'] ); ?></strong><br>
                                    <small style="color: #666;"><?php echo esc_html( $user['email'] ?: 'No email' ); ?></small>
                                    <?php if ( ! empty( $user['phone'] ) ) : ?>
                                        <br><small style="color: #666;">📞 <?php echo esc_html( $user['phone'] ); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php
                                endforeach;
                            else :
                            ?>
                                <p style="color: #666; font-style: italic;">No users available. Create users in the Users tab.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Available Teams -->
                    <div class="available-teams-column">
                        <h3 style="margin-top: 0;">Available Teams</h3>
                        <input type="text" id="teamSearchBox" placeholder="Search teams by name, code, or member..." 
                               style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 4px;" />
                        <div class="teams-list" id="teamsList" style="min-height: 200px;">
                            <?php if ( ! empty( $teams ) ) : ?>
                                <?php foreach ( $teams as $team ) : ?>
                                    <div class="team-box postbox" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                         style="margin-bottom: 20px; border: 2px solid #ccc; border-radius: 6px;">
                                        <div class="postbox-header" style="background: #f0f0f0; padding: 12px 15px; border-bottom: 1px solid #ccc; display: flex; justify-content: space-between; align-items: center;">
                                            <h3 style="margin: 0;">
                                                <?php echo esc_html( $team['name'] ); ?>
                                                <span style="font-weight: normal; color: #666; font-size: 14px;">
                                                    (Code: <?php echo esc_html( $team['access_code'] ); ?>)
                                                </span>
                                            </h3>
                                            <div>
                                                <a href="?page=subsales-teams&tab=teams&edit_team=<?php echo intval( $team['id'] ); ?>" class="button button-small">Edit</a>
                                                <form method="post" action="?page=subsales-teams&tab=teams" style="display: inline;">
                                                    <?php wp_nonce_field( 'order_sync_delete_team' ); ?>
                                                    <input type="hidden" name="team_id" value="<?php echo esc_attr( $team['id'] ); ?>" />
                                                    <input type="submit" name="delete_team" value="Delete" class="button button-small button-link-delete" 
                                                           onclick="return confirm('Are you sure you want to delete this team?')" />
                                                </form>
                                            </div>
                                        </div>
                                        <div class="inside team-dropzone" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                             style="padding: 15px; min-height: 100px; background: #fafafa;">
                                            <?php if ( ! empty( $team['description'] ) ) : ?>
                                                <p style="margin: 0 0 10px 0; font-style: italic; color: #666;"><?php echo esc_html( $team['description'] ); ?></p>
                                            <?php endif; ?>
                                            
                                            <h4 style="margin: 10px 0;">Team Members</h4>
                                            <div class="team-members-list">
                                                <?php
                                                // Get team members via junction table
                                                $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
                                                $team_member_ids = $wpdb->get_col( $wpdb->prepare(
                                                    "SELECT user_id FROM {$user_teams_table} WHERE team_id = %d",
                                                    $team['id']
                                                ));
                                                
                                                $team_members_new = array();
                                                if ( ! empty( $team_member_ids ) ) {
                                                $team_members_new = $wpdb->get_results(
                                                    "SELECT * FROM {$members_table} WHERE id IN (" . implode( ',', array_map( 'intval', $team_member_ids ) ) . ")",
                                                    ARRAY_A
                                                );
                                            }
                                            
                                            if ( ! empty( $team_members_new ) ) : ?>
                                                <?php foreach ( $team_members_new as $member ) : ?>
                                                    <div class="user-card team-member-card" data-user-id="<?php echo intval( $member['id'] ); ?>" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                                         style="background: #fff; border: 1px solid #4CAF50; border-radius: 4px; padding: 10px; margin-bottom: 8px; position: relative;">
                                                        <button type="button" class="remove-from-team" data-user-id="<?php echo intval( $member['id'] ); ?>" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                                                style="position: absolute; top: 5px; right: 5px; background: #dc3232; color: #fff; border: none; border-radius: 3px; cursor: pointer; padding: 2px 6px; font-size: 11px;"
                                                                title="Remove from team">×</button>
                                                        <strong><?php echo esc_html( $member['name'] ); ?></strong><br>
                                                        <small style="color: #666;"><?php echo esc_html( $member['email'] ?: 'No email' ); ?></small>
                                                        <?php if ( ! empty( $member['phone'] ) ) : ?>
                                                            <br><small style="color: #666;">📞 <?php echo esc_html( $member['phone'] ); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <p style="color: #999; font-style: italic;">No members assigned yet. Drag users here to assign.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p style="color: #666; font-style: italic;">No teams created yet. Add your first team above.</p>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Drag and Drop JavaScript -->
                <script>
                (function($) {
                    let draggedElement = null;

                    // User search box - filters available users
                    $('#userSearchBox').on('keyup', function() {
                        const searchTerm = $(this).val().toLowerCase();
                        $('#availableUsersList .user-card').each(function() {
                            const text = $(this).text().toLowerCase();
                            if (text.indexOf(searchTerm) > -1) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    });
                    
                    // Team search box - filters team boxes
                    $('#teamSearchBox').on('keyup', function() {
                        const searchTerm = $(this).val().toLowerCase();
                        $('.team-box').each(function() {
                            const teamText = $(this).find('.postbox-header h3').text().toLowerCase();
                            const membersText = $(this).find('.team-members-list').text().toLowerCase();
                            const combinedText = teamText + ' ' + membersText;
                            
                            if (combinedText.indexOf(searchTerm) > -1) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    });

                    // Make user cards draggable
                    $(document).on('dragstart', '.user-card', function(e) {
                        draggedElement = this;
                        $(this).css('opacity', '0.5');
                        e.originalEvent.dataTransfer.effectAllowed = 'move';
                        e.originalEvent.dataTransfer.setData('text/html', this.innerHTML);
                    });

                    $(document).on('dragend', '.user-card', function(e) {
                        $(this).css('opacity', '1');
                    });

                    // Handle drag over team dropzones and available users list
                    $(document).on('dragover', '.team-dropzone, #availableUsersList', function(e) {
                        if (e.preventDefault) {
                            e.preventDefault();
                        }
                        e.originalEvent.dataTransfer.dropEffect = 'move';
                        $(this).css('background', '#e8f5e9');
                        return false;
                    });

                    $(document).on('dragleave', '.team-dropzone, #availableUsersList', function(e) {
                        $(this).css('background', '');
                    });

                    // Handle drop
                    $(document).on('drop', '.team-dropzone', function(e) {
                        if (e.stopPropagation) {
                            e.stopPropagation();
                        }
                        $(this).css('background', '');

                        if (draggedElement) {
                            const userId = $(draggedElement).data('user-id');
                            const teamId = $(this).data('team-id');
                            
                            // Add to team via AJAX (don't move visually from available list)
                            $.post(ajaxurl, {
                                action: 'subsales_add_user_to_team',
                                user_id: userId,
                                team_id: teamId,
                                nonce: '<?php echo wp_create_nonce( 'subsales_team_assign' ); ?>'
                            }, function(response) {
                                if (response.success) {
                                    // Reload to show updated team membership
                                    location.reload();
                                } else {
                                    alert(response.data.message || 'Failed to assign user to team.');
                                }
                            });
                        }
                        
                        return false;
                    });

                    // Handle remove button click
                    $(document).on('click', '.remove-from-team', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const userId = $(this).data('user-id');
                        const teamId = $(this).data('team-id');
                        
                        if (!confirm('Remove this user from the team?')) {
                            return;
                        }
                        
                        // Remove from team via AJAX
                        $.post(ajaxurl, {
                            action: 'subsales_remove_user_from_team',
                            user_id: userId,
                            team_id: teamId,
                            nonce: '<?php echo wp_create_nonce( 'subsales_team_assign' ); ?>'
                        }, function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Failed to remove user from team.');
                            }
                        });
                    });
                })(jQuery);
                </script>
            </div>
        <?php endif; ?>
        
        <style>
        .nav-tab-wrapper {
            border-bottom: 1px solid #ccc;
            margin: 20px 0 0 0;
            padding: 0;
        }
        .nav-tab {
            border: 1px solid #ccc;
            border-bottom: none;
            background: #f1f1f1;
            color: #555;
        }
        .nav-tab-active {
            background: #fff;
            border-bottom: 1px solid #fff;
            color: #000;
            margin-bottom: -1px;
        }
        .user-card {
            transition: all 0.2s ease;
        }
        .user-card:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }
        .team-dropzone {
            transition: background 0.2s ease;
        }
        .status-active { color: #46b450; font-weight: bold; }
        .status-pending { color: #ffb900; font-weight: bold; }
        .status-inactive { color: #dc3232; font-weight: bold; }
        </style>
        
        <script>
        (function($) {
            $(document).ready(function() {
                // Search functionality for All Users table
                $('#allUsersSearchBox').on('keyup', function() {
                    const searchTerm = $(this).val().toLowerCase();
                    $('#allUsersTable tbody tr').each(function() {
                        const text = $(this).text().toLowerCase();
                        if (text.indexOf(searchTerm) > -1) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                });
            });
        })(jQuery);
        </script>
    </div>
    <?php
}

// AJAX handler for adding user to team (many-to-many)
add_action( 'wp_ajax_subsales_add_user_to_team', 'subsales_ajax_add_user_to_team' );
function subsales_ajax_add_user_to_team() {
    check_ajax_referer( 'subsales_team_assign', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    
    $user_id = intval( $_POST['user_id'] ?? 0 );
    $team_id = intval( $_POST['team_id'] ?? 0 );
    
    if ( $user_id && $team_id ) {
        // Check if already assigned
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id,
            $team_id
        ));
        
        if ( $exists ) {
            wp_send_json_error( array( 'message' => 'User is already assigned to this team' ) );
        }
        
        $result = $wpdb->insert(
            $user_teams_table,
            array( 'user_id' => $user_id, 'team_id' => $team_id ),
            array( '%d', '%d' )
        );
        
        if ( $result ) {
            wp_send_json_success( array( 'message' => 'User added to team successfully' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Database insert failed' ) );
        }
    }
    
    wp_send_json_error( array( 'message' => 'Invalid user or team ID' ) );
}

// AJAX handler for removing user from team
add_action( 'wp_ajax_subsales_remove_user_from_team', 'subsales_ajax_remove_user_from_team' );
function subsales_ajax_remove_user_from_team() {
    check_ajax_referer( 'subsales_team_assign', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'order_sync_user_teams';
    
    $user_id = intval( $_POST['user_id'] ?? 0 );
    $team_id = intval( $_POST['team_id'] ?? 0 );
    
    if ( $user_id && $team_id ) {
        $deleted = $wpdb->delete(
            $user_teams_table,
            array( 'user_id' => $user_id, 'team_id' => $team_id ),
            array( '%d', '%d' )
        );
        
        if ( $deleted ) {
            wp_send_json_success( array( 'message' => 'User removed from team successfully' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Database delete failed' ) );
        }
    }
    
    wp_send_json_error( array( 'message' => 'Invalid user or team ID' ) );
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
                    <th>Tally Status</th>
                    <td>
                        <select name="tally_status">
                            <option value="untallied">Untallied Only</option>
                            <option value="tallied">Tallied Only</option>
                            <option value="all" selected>All Orders</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Show Deleted</th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_deleted" value="1" />
                            Include deleted orders
                        </label>
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

        <div style="margin-bottom: 15px;">
            <button id="subsales-bulk-tally-btn" class="button button-secondary" disabled>
                Mark Selected as Tallied
            </button>
            <span id="subsales-selected-count" style="margin-left: 10px; color: #666;"></span>
        </div>

        <div id="subsales-orders-results">
            <p id="subsales-orders-meta" style="margin-bottom:8px"></p>
            <div style="overflow-x: auto; max-width: 100%;">
            <table id="subsales-orders-table" class="widefat striped" cellspacing="0" style="table-layout: auto; min-width: 100%; width: max-content;">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="subsales-select-all" title="Select all" /></th>
                        <th style="white-space: nowrap;">Order ID</th>
                        <th style="white-space: nowrap;">Date</th>
                        <th style="white-space: nowrap;">Member</th>
                        <th style="white-space: nowrap;">Team</th>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <th style="text-align:center; white-space: nowrap; padding: 8px 4px;" title="<?php echo esc_attr( $pcol['name'] ); ?>"><?php echo esc_html( substr( $pcol['name'], 0, 6 ) ); ?></th>
                        <?php endforeach; ?>
                        <th style="text-align:right; white-space: nowrap;">Donate</th>
                        <th style="white-space: nowrap;">Pay</th>
                        <th style="text-align:right; white-space: nowrap;">Total</th>
                        <th style="white-space: nowrap;">Tallied</th>
                        <th style="white-space: nowrap;">Actions</th>
                    </tr>
                </thead>
                <tbody id="subsales-orders-tbody">
                    <tr><td colspan="<?php echo 8 + count( $products_conf ); ?>">Use the filters above and click Filter to load orders via AJAX.</td></tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align:right">Page totals:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td id="subsales-page-prod-<?php echo esc_attr( $pcol['id'] ); ?>" style="text-align:center">0</td>
                        <?php endforeach; ?>
                        <td id="subsales-page-donation" style="text-align:right">$0.00</td>
                        <td></td>
                        <td id="subsales-page-total" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="4" style="text-align:right">Cash:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td></td>
                        <td id="subsales-page-cash" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="4" style="text-align:right">Check:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td></td>
                        <td id="subsales-page-check" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>

            <div id="subsales-pagination" style="margin-top:12px"></div>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div id="subsales-edit-modal" class="subsales-modal" style="display:none">
        <div class="subsales-modal-backdrop"></div>
        <div class="subsales-modal-content">
            <div class="subsales-modal-header">
                <h2>Edit Order</h2>
                <button class="subsales-modal-close" onclick="SubsalesOrderEdit.closeEditModal()">&times;</button>
            </div>
            <div class="subsales-modal-body">
                <form id="subsales-edit-form">
                    <input type="hidden" name="order_db_id" />
                    <input type="hidden" name="order_id" />
                    
                    <table class="form-table">
                        <tr>
                            <th><label>Customer Name</label></th>
                            <td><input type="text" name="customer" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th><label>Address</label></th>
                            <td><input type="text" name="address" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th><label>Unit / Floor / Apt</label></th>
                            <td><input type="text" name="unitFloorApt" class="regular-text" placeholder="Optional" /></td>
                        </tr>
                        <tr>
                            <th><label>Cell Number</label></th>
                            <td><input type="tel" name="cellNumber" class="regular-text" placeholder="Optional" /></td>
                        </tr>
                    </table>
                    
                    <h3 style="border: 2px solid #0073aa; padding: 10px; background: #f0f6fc; margin: 15px 0 5px 0; border-radius: 4px;">Products</h3>
                    <table class="form-table" style="border: 2px solid #0073aa; margin-bottom: 15px; border-radius: 4px;">
                        <tbody id="subsales-edit-products"></tbody>
                    </table>
                    
                    <table class="form-table">
                        <tr>
                            <th><label>Donation Amount (USD)</label></th>
                            <td><input type="number" name="donationAmount" class="regular-text" min="0" step="0.01" placeholder="$0.00" /></td>
                        </tr>
                        <tr>
                            <th><label>Payment Method</label></th>
                            <td>
                                <select name="paymentMethod">
                                    <option value="cash">Cash</option>
                                    <option value="check">Check</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Check Number</label></th>
                            <td><input type="text" name="checkNumber" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label>Delivery Instructions</label></th>
                            <td><textarea name="notes" class="large-text" rows="3" placeholder="House color, long driveway, etc."></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Edit Reason</label> <span style="color:red">*</span></th>
                            <td><textarea name="_edit_reason" class="large-text" rows="2" placeholder="Explain why this order is being edited..." required></textarea></td>
                        </tr>
                    </table>
                </form>
            </div>
            <div class="subsales-modal-footer">
                <button class="button button-large" onclick="SubsalesOrderEdit.closeEditModal()">Cancel</button>
                <button class="button button-primary button-large" onclick="SubsalesOrderEdit.saveOrder()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="subsales-delete-modal" class="subsales-modal" style="display:none">
        <div class="subsales-modal-backdrop"></div>
        <div class="subsales-modal-content" style="max-width:500px">
            <div class="subsales-modal-header">
                <h2>Delete Order</h2>
                <button class="subsales-modal-close" onclick="SubsalesOrderEdit.closeDeleteModal()">&times;</button>
            </div>
            <div class="subsales-modal-body">
                <p><strong>Are you sure you want to delete this order?</strong></p>
                <p id="subsales-delete-order-info"></p>
                <form id="subsales-delete-form">
                    <input type="hidden" name="order_db_id" />
                    <input type="hidden" name="order_id" />
                    <table class="form-table">
                        <tr>
                            <th><label>Delete Reason</label> <span style="color:red">*</span></th>
                            <td><textarea name="delete_reason" class="large-text" rows="3" placeholder="Explain why this order is being deleted..." required></textarea></td>
                        </tr>
                    </table>
                </form>
            </div>
            <div class="subsales-modal-footer">
                <button class="button button-large" onclick="SubsalesOrderEdit.closeDeleteModal()">Cancel</button>
                <button class="button button-primary button-large" style="background:red;border-color:darkred" onclick="SubsalesOrderEdit.confirmDelete()">Delete Order</button>
            </div>
        </div>
    </div>

    <!-- History Panel -->
    <div id="subsales-history-panel" class="subsales-history-panel" style="display:none">
        <div class="subsales-history-header">
            <h3>Order Edit History</h3>
            <button class="button" onclick="SubsalesOrderEdit.closeHistoryPanel()">&times; Close</button>
        </div>
        <div id="subsales-history-content" class="subsales-history-content">
            <p>Loading...</p>
        </div>
    </div>

    <style>
        .subsales-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 100000; }
        .subsales-modal-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); }
        .subsales-modal-content { position: relative; max-width: 700px; margin: 40px auto; background: white; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; display: flex; flex-direction: column; }
        .subsales-modal-header { padding: 20px 24px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .subsales-modal-header h2 { margin: 0; }
        .subsales-modal-close { background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; line-height: 1; color: #666; }
        .subsales-modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .subsales-modal-footer { padding: 16px 24px; border-top: 1px solid #ddd; text-align: right; }
        .subsales-modal-footer button { margin-left: 8px; }
        
        .subsales-history-panel { position: fixed; top: 0; right: 0; width: 500px; height: 100%; background: white; box-shadow: -2px 0 10px rgba(0,0,0,0.3); z-index: 100001; overflow-y: auto; }
        .subsales-history-header { padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: white; }
        .subsales-history-content { padding: 20px; }
        .subsales-history-item { border: 1px solid #ddd; border-radius: 4px; padding: 16px; margin-bottom: 16px; background: #f9f9f9; }
        .subsales-history-item h4 { margin: 0 0 8px 0; color: #2271b1; }
        .subsales-history-item .meta { color: #666; font-size: 0.9em; margin-bottom: 8px; }
        .subsales-history-item .summary { margin-bottom: 12px; }
        .subsales-history-changes { background: white; border: 1px solid #ddd; padding: 12px; border-radius: 3px; font-size: 0.9em; }
        .subsales-history-change { margin-bottom: 8px; padding: 8px; background: #f5f5f5; border-left: 3px solid #2271b1; }
        .subsales-history-change strong { display: inline-block; min-width: 120px; }
        .subsales-change-before { color: #d63638; text-decoration: line-through; }
        .subsales-change-after { color: #00a32a; font-weight: 600; }
        
        .subsales-orders-meta-note { float: right; color: #666; font-size: 0.9em; }
        .subsales-edited-star { color: red; font-weight: bold; }
        .subsales-action-btn { 
            padding: 6px 12px; 
            font-size: 12px; 
            margin-right: 4px; 
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid #ddd;
            background: white;
        }
        .subsales-action-btn:hover { 
            background: #f0f0f1;
            border-color: #2271b1;
            color: #2271b1;
        }
        .subsales-action-btn-edit { 
            background: #2271b1;
            color: white;
            border-color: #2271b1;
        }
        .subsales-action-btn-edit:hover { 
            background: #135e96;
        }
        .subsales-action-btn-delete { 
            background: #d63638;
            color: white;
            border-color: #d63638;
        }
        .subsales-action-btn-delete:hover { 
            background: #b32d2e;
        }
        .subsales-action-btn-history {
            background: #dba617;
            color: white;
            border-color: #dba617;
        }
        .subsales-action-btn-history:hover {
            background: #c29500;
        }
    </style>

    <script>
    (function(){
        const ajaxUrl = <?php echo json_encode( $ajax_url ); ?>;
        const nonce = <?php echo json_encode( $nonce ); ?>;
        const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
        const restUrl = <?php echo json_encode( rest_url( 'order-manager/v1/orders/tally' ) ); ?>;
        const restNonce = <?php echo json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        
        let selectedOrderIds = new Set();

        function serializeForm(form){
            const fd = new FormData();
            fd.append('action','subsales_fetch_orders');
            fd.append('nonce', nonce);
            const f = new FormData(form);
            for (const [k,v] of f.entries()){ if (v !== null) fd.append(k,v); }
            return fd;
        }
        
        function updateTallyButton(){
            const btn = document.getElementById('subsales-bulk-tally-btn');
            const countSpan = document.getElementById('subsales-selected-count');
            const count = selectedOrderIds.size;
            
            if (count > 0) {
                btn.disabled = false;
                countSpan.textContent = count;
            } else {
                btn.disabled = true;
                countSpan.textContent = '0';
            }
        }
        
        function handleCheckboxChange(orderId, checked){
            if (checked) {
                selectedOrderIds.add(orderId);
            } else {
                selectedOrderIds.delete(orderId);
            }
            updateTallyButton();
        }
        
        function handleSelectAllChange(checked){
            const checkboxes = document.querySelectorAll('.subsales-order-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checked;
                const orderId = parseInt(cb.dataset.orderId);
                if (checked) {
                    selectedOrderIds.add(orderId);
                } else {
                    selectedOrderIds.delete(orderId);
                }
            });
            updateTallyButton();
        }
        
        async function bulkTallyOrders(){
            if (selectedOrderIds.size === 0) {
                alert('Please select at least one order to tally.');
                return;
            }
            
            if (!confirm('Mark ' + selectedOrderIds.size + ' order(s) as tallied?')) {
                return;
            }
            
            const orderIdsArray = Array.from(selectedOrderIds);
            
            try {
                const resp = await fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce
                    },
                    body: JSON.stringify({ order_ids: orderIdsArray })
                });
                
                const data = await resp.json();
                
                if (data.success_count > 0) {
                    alert('Successfully tallied ' + data.success_count + ' order(s)');
                    selectedOrderIds.clear();
                    document.getElementById('subsales-select-all').checked = false;
                    updateTallyButton();
                    fetchPage(1); // Refresh the table
                } else {
                    alert('Failed to tally orders: ' + (data.errors ? data.errors.join(', ') : 'Unknown error'));
                }
            } catch (error) {
                console.error('Tally error:', error);
                alert('Error tallying orders: ' + error.message);
            }
        }

        function renderRows(orders){
            const tbody = document.getElementById('subsales-orders-tbody');
            tbody.innerHTML = '';
                if (!orders || orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="' + (10 + configuredProducts.length) + '">No orders found for the selected filters.</td></tr>';
                return;
            }
            for (const o of orders){
                const tr = document.createElement('tr');
                let html = '';
                
                // Checkbox column
                html += '<td style="text-align:center; width:30px;">';
                html += '<input type="checkbox" class="subsales-order-checkbox" data-order-id="' + o.id + '" onchange="handleCheckboxChange(' + o.id + ', this.checked)">';
                html += '</td>';
                
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.order_id) + (o.edited ? ' <span class="subsales-edited-star">*</span>' : '') + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.created_at_formatted) + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.entered_by_name || o.user_id || '') + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.team_name || '') + '</td>';
                // per-configured-product columns
                for (const p of configuredProducts) {
                    const pid = p.id;
                    const qty = (o.products_map && typeof o.products_map[pid] !== 'undefined') ? Number(o.products_map[pid]) : 0;
                    html += '<td style="text-align:center; white-space: nowrap; padding: 8px 4px;">' + escapeHtml(qty) + '</td>';
                }
                // Donation column
                const donationAmt = Number(o.donation_amount || 0);
                html += '<td style="text-align:right; white-space: nowrap;">' + (donationAmt > 0 ? '$' + donationAmt.toFixed(2) : '') + '</td>';
                // Items column removed; individual product columns are shown above.
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.payment_display || '') + '</td>';
                html += '<td style="text-align:right; white-space: nowrap;">$' + Number(o.order_total).toFixed(2) + '</td>';
                
                // Tallied column
                html += '<td style="text-align:center; white-space: nowrap;">';
                if (o.tallied) {
                    const tallyDate = o.tallied_at ? new Date(o.tallied_at).toLocaleDateString() : '';
                    const tallyBy = o.tallied_by ? ' by ' + escapeHtml(o.tallied_by) : '';
                    html += '<span title="Tallied ' + tallyDate + tallyBy + '">✓</span>';
                } else {
                    html += '';
                }
                html += '</td>';
                
                // Actions column
                html += '<td style="white-space: nowrap;">';
                html += '<button class="subsales-action-btn subsales-action-btn-edit" onclick="SubsalesOrderEdit.editOrder(' + o.id + ',\'' + escapeHtml(o.order_id).replace(/'/g, "\\'") + '\')" title="Edit order">✏️ Edit</button>';
                html += '<button class="subsales-action-btn subsales-action-btn-delete" onclick="SubsalesOrderEdit.deleteOrder(' + o.id + ',\'' + escapeHtml(o.order_id).replace(/'/g, "\\'") + '\')" title="Delete order">🗑️ Delete</button>';
                html += '<button class="subsales-action-btn subsales-action-btn-history" onclick="SubsalesOrderEdit.viewHistory(' + o.id + ')" title="View history">📋 History</button>';
                html += '</td>';
                
                tr.innerHTML = html;
                tbody.appendChild(tr);
            }
        }

        function renderMeta(total_count, page, pages){
            const meta = document.getElementById('subsales-orders-meta');
            // Put the page meta on the left and an explanatory note on the right
            meta.innerHTML = 'Showing page ' + page + ' of ' + pages + ' — ' + total_count + ' matching orders' +
                '<span class="subsales-orders-meta-note"><span style="color: red;">*</span> indicates edited order</span>';
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
            document.getElementById('subsales-page-donation').textContent = '$' + Number(totals.donations || 0).toFixed(2);
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
            try {
                const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
                const data = await resp.json();
                console.log('AJAX Response:', data);
                if (!data || !data.success){
                    console.error('AJAX Error:', data);
                    alert('Failed to fetch orders: ' + (data && data.data ? data.data : 'Unknown error'));
                    return;
                }
                const payload = data.data;
                renderRows(payload.orders);
                renderMeta(payload.total_count, payload.page, payload.pages);
                renderTotals(payload.totals);
                renderPagination(payload.page, payload.pages);
            } catch (error) {
                console.error('Fetch error:', error);
                alert('Error loading orders: ' + error.message);
            }
        }

        document.getElementById('subsales-filter-btn').addEventListener('click', function(){ fetchPage(1); });
        document.getElementById('subsales-reset-btn').addEventListener('click', function(){
            document.getElementById('subsales-orders-filter').reset(); fetchPage(1);
        });
        
        // Select all checkbox handler
        document.getElementById('subsales-select-all').addEventListener('change', function(){
            handleSelectAllChange(this.checked);
        });
        
        // Bulk tally button handler
        document.getElementById('subsales-bulk-tally-btn').addEventListener('click', function(){
            bulkTallyOrders();
        });
        
        // Make functions globally available
        window.handleCheckboxChange = handleCheckboxChange;
        window.handleSelectAllChange = handleSelectAllChange;

        // Load first page on open
        fetchPage(1);
        
        // Make fetchPage available globally for refresh after edit/delete
        window.SubsalesRefreshOrders = function(){ fetchPage(1); };
    })();
    
    // Order Edit/Delete/History Manager
    window.SubsalesOrderEdit = {
        currentOrder: null,
        
        async editOrder(orderDbId, orderId) {
            try {
                // Fetch full order data using database ID
                const resp = await fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>?action=subsales_get_order_by_db_id&id=' + orderDbId + '&nonce=<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                const result = await resp.json();
                
                console.log('Edit order response:', result);
                
                if (!result || !result.success || !result.data) {
                    alert('Failed to load order: ' + (result && result.data ? result.data : 'Unknown error'));
                    return;
                }
                
                const order = result.data;
                console.log('Order object:', order);
                console.log('Order data:', order.order_data);
                
                if (!order || !order.order_data) {
                    alert('Failed to load order: Invalid data structure');
                    return;
                }
                
                this.currentOrder = order;
                const data = order.order_data;
                
                console.log('Parsed data:', data);
                console.log('customer:', data.customer);
                
                // Populate form - match PWA field structure
                const form = document.getElementById('subsales-edit-form');
                form.elements['order_db_id'].value = order.id || '';
                form.elements['order_id'].value = order.order_id || '';
                form.elements['customer'].value = data.customer || data.customerName || '';
                form.elements['address'].value = data.address || '';
                form.elements['unitFloorApt'].value = data.unitFloorApt || '';
                form.elements['cellNumber'].value = data.cellNumber || data.phone || '';
                form.elements['donationAmount'].value = data.donationAmount || '';
                form.elements['paymentMethod'].value = data.paymentMethod || 'cash';
                form.elements['checkNumber'].value = data.checkNumber || '';
                form.elements['notes'].value = data.notes || '';
                form.elements['_edit_reason'].value = '';
                
                // Populate products
                const productsContainer = document.getElementById('subsales-edit-products');
                productsContainer.innerHTML = '';
                const products = data.products || [];
                const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
                
                console.log('Products from order:', products);
                console.log('Configured products:', configuredProducts);
                
                for (const p of configuredProducts) {
                    const existing = products.find(pr => pr.id === p.id);
                    const qty = existing ? existing.qty : 0;
                    
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<th><label>' + p.name + '</label></th>' +
                        '<td><input type="number" name="product_' + p.id + '" min="0" value="' + qty + '" /></td>';
                    productsContainer.appendChild(tr);
                }
                
                // Show modal
                document.getElementById('subsales-edit-modal').style.display = 'block';
            } catch (error) {
                console.error('Edit order error:', error);
                alert('Failed to load order: ' + error.message);
            }
        },
        
        closeEditModal() {
            document.getElementById('subsales-edit-modal').style.display = 'none';
            this.currentOrder = null;
        },
        
        async saveOrder() {
            const form = document.getElementById('subsales-edit-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            const orderDbId = form.elements['order_db_id'].value;
            const orderId = form.elements['order_id'].value;
            
            // Build updated order data - preserve existing metadata from original order
            const originalData = this.currentOrder.order_data || {};
            const data = {
                order_id: orderId,
                user_id: this.currentOrder.user_id,
                team_id: this.currentOrder.team_id,
                customer: form.elements['customer'].value,
                address: form.elements['address'].value,
                unitFloorApt: form.elements['unitFloorApt'].value,
                cellNumber: form.elements['cellNumber'].value,
                donationAmount: parseFloat(form.elements['donationAmount'].value) || 0,
                paymentMethod: form.elements['paymentMethod'].value,
                checkNumber: form.elements['checkNumber'].value,
                notes: form.elements['notes'].value,
                _edit_reason: form.elements['_edit_reason'].value,
                products: [],
                // Preserve metadata from original order
                createdAt: originalData.createdAt || new Date().toISOString(),
                entered_by_id: originalData.entered_by_id || '',
                entered_by_name: originalData.entered_by_name || '',
                team_name: originalData.team_name || '',
                team_code: originalData.team_code || '',
                geo: originalData.geo || null
            };
            
            // Collect products
            const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
            for (const p of configuredProducts) {
                const qty = parseInt(form.elements['product_' + p.id].value) || 0;
                data.products.push({
                    id: p.id,
                    name: p.name,
                    price: p.price,
                    qty: qty
                });
            }
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderId, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await resp.json();
                
                if (resp.ok) {
                    alert('Order updated successfully!');
                    this.closeEditModal();
                    window.SubsalesRefreshOrders();
                } else {
                    alert('Failed to update order: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Save order error:', error);
                alert('Failed to save order: ' + error.message);
            }
        },
        
        deleteOrder(orderDbId, orderId) {
            this.currentOrder = { id: orderDbId, order_id: orderId };
            document.getElementById('subsales-delete-form').elements['order_db_id'].value = orderDbId;
            document.getElementById('subsales-delete-form').elements['order_id'].value = orderId;
            document.getElementById('subsales-delete-order-info').textContent = 'Order: ' + orderId;
            document.getElementById('subsales-delete-form').elements['delete_reason'].value = '';
            document.getElementById('subsales-delete-modal').style.display = 'block';
        },
        
        closeDeleteModal() {
            document.getElementById('subsales-delete-modal').style.display = 'none';
            this.currentOrder = null;
        },
        
        async confirmDelete() {
            const form = document.getElementById('subsales-delete-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            const orderId = form.elements['order_id'].value;
            const deleteReason = form.elements['delete_reason'].value;
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderId, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    },
                    body: JSON.stringify({ delete_reason: deleteReason })
                });
                
                const result = await resp.json();
                
                if (resp.ok) {
                    alert('Order deleted successfully!');
                    this.closeDeleteModal();
                    window.SubsalesRefreshOrders();
                } else {
                    alert('Failed to delete order: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Delete order error:', error);
                alert('Failed to delete order: ' + error.message);
            }
        },
        
        async viewHistory(orderDbId) {
            document.getElementById('subsales-history-panel').style.display = 'block';
            document.getElementById('subsales-history-content').innerHTML = '<p>Loading history...</p>';
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderDbId + '/history', {
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    }
                });
                const data = await resp.json();
                
                if (!resp.ok || !data.history) {
                    document.getElementById('subsales-history-content').innerHTML = '<p>Failed to load history</p>';
                    return;
                }
                
                if (data.history.length === 0) {
                    document.getElementById('subsales-history-content').innerHTML = '<p>No edit history for this order.</p>';
                    return;
                }
                
                // Render history
                let html = '<div class="subsales-history-items">';
                for (const item of data.history) {
                    html += '<div class="subsales-history-item">';
                    html += '<h4>' + this.escapeHtml(item.edit_type.toUpperCase()) + '</h4>';
                    html += '<div class="meta">';
                    html += 'By: ' + this.escapeHtml(item.edited_by_name) + ' | ';
                    html += 'Date: ' + this.escapeHtml(item.edited_at) + '</div>';
                    html += '<div class="summary"><strong>Summary:</strong> ' + this.escapeHtml(item.changes_summary) + '</div>';
                    
                    if (item.edit_reason) {
                        html += '<div><strong>Reason:</strong> ' + this.escapeHtml(item.edit_reason) + '</div>';
                    }
                    
                    // Show detailed changes
                    if (item.changes_detail && item.changes_detail.changes) {
                        html += '<details style="margin-top:12px"><summary style="cursor:pointer;color:#2271b1"><strong>View Detailed Changes</strong></summary>';
                        html += '<div class="subsales-history-changes">';
                        for (const change of item.changes_detail.changes) {
                            html += '<div class="subsales-history-change">';
                            html += '<strong>' + this.escapeHtml(change.label) + ':</strong> ';
                            
                            if (change.field === 'products') {
                                html += '<br/><span class="subsales-change-before">' + this.renderProducts(change.before) + '</span>';
                                html += '<br/><span class="subsales-change-after">' + this.renderProducts(change.after) + '</span>';
                            } else {
                                html += '<span class="subsales-change-before">' + this.escapeHtml(change.before) + '</span> → ';
                                html += '<span class="subsales-change-after">' + this.escapeHtml(change.after) + '</span>';
                            }
                            html += '</div>';
                        }
                        html += '</div></details>';
                    }
                    
                    html += '</div>';
                }
                html += '</div>';
                
                document.getElementById('subsales-history-content').innerHTML = html;
            } catch (error) {
                console.error('View history error:', error);
                document.getElementById('subsales-history-content').innerHTML = '<p>Error loading history: ' + error.message + '</p>';
            }
        },
        
        closeHistoryPanel() {
            document.getElementById('subsales-history-panel').style.display = 'none';
        },
        
        escapeHtml(s) {
            if (!s && s !== 0) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        },
        
        renderProducts(products) {
            if (!products || products.length === 0) return '(none)';
            return products.map(p => p.name + ' ×' + p.qty).join(', ');
        }
    };
    </script>
    <?php
}

