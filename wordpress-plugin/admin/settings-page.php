<?php
/**
 * Settings Page
 * 
 * Comprehensive settings page with tabbed interface for:
 * - Overall settings (API keys, sync interval, portal config)
 * - Branding and look & feel
 * - Products configuration
 * - Address management
 * - Backup and restore
 * - System information
 * 
 * @package Subsales_Management
 * @since 2.2.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
        $individual_session_duration = isset( $_POST['individual_session_duration'] ) ? intval( $_POST['individual_session_duration'] ) : 1209600000; // 14 days
        $login_mode = isset( $_POST['login_mode'] ) ? sanitize_text_field( $_POST['login_mode'] ) : 'legacy';
        $points_mode = isset( $_POST['points_mode'] ) ? sanitize_text_field( $_POST['points_mode'] ) : 'dollar';
        $points_denomination = isset( $_POST['points_denomination'] ) ? floatval( $_POST['points_denomination'] ) : 1.0;
        $points_distribution = isset( $_POST['points_distribution'] ) ? sanitize_text_field( $_POST['points_distribution'] ) : 'individual';
        $donation_bonus_enabled = isset( $_POST['donation_bonus_enabled'] ) ? 1 : 0;
        $donation_percentage = isset( $_POST['donation_percentage'] ) ? floatval( $_POST['donation_percentage'] ) : 50.0;
        $donation_distribution = isset( $_POST['donation_distribution'] ) ? sanitize_text_field( $_POST['donation_distribution'] ) : 'team';
        $sales_enabled = isset( $_POST['sales_enabled'] ) ? 1 : 0;
        $subsales_digital_payments_enabled = isset( $_POST['subsales_digital_payments_enabled'] ) ? 1 : 0;
        $subsales_square_environment = isset( $_POST['subsales_square_environment'] ) ? sanitize_text_field( $_POST['subsales_square_environment'] ) : 'sandbox';
        if ( ! in_array( $subsales_square_environment, array( 'sandbox', 'production' ), true ) ) {
            $subsales_square_environment = 'sandbox';
        }
        $subsales_square_access_token_sandbox = isset( $_POST['subsales_square_access_token_sandbox'] ) ? sanitize_text_field( $_POST['subsales_square_access_token_sandbox'] ) : '';
        $subsales_square_access_token_production = isset( $_POST['subsales_square_access_token_production'] ) ? sanitize_text_field( $_POST['subsales_square_access_token_production'] ) : '';
        $subsales_square_location_id_sandbox = isset( $_POST['subsales_square_location_id_sandbox'] ) ? sanitize_text_field( $_POST['subsales_square_location_id_sandbox'] ) : '';
        $subsales_square_location_id_production = isset( $_POST['subsales_square_location_id_production'] ) ? sanitize_text_field( $_POST['subsales_square_location_id_production'] ) : '';
        $subsales_square_webhook_signature_key_sandbox = isset( $_POST['subsales_square_webhook_signature_key_sandbox'] ) ? sanitize_text_field( $_POST['subsales_square_webhook_signature_key_sandbox'] ) : '';
        $subsales_square_webhook_signature_key_production = isset( $_POST['subsales_square_webhook_signature_key_production'] ) ? sanitize_text_field( $_POST['subsales_square_webhook_signature_key_production'] ) : '';
        $subsales_convenience_fee_enabled = isset( $_POST['subsales_convenience_fee_enabled'] ) ? 1 : 0;
        $subsales_convenience_fee_percentage = isset( $_POST['subsales_convenience_fee_percentage'] ) ? floatval( $_POST['subsales_convenience_fee_percentage'] ) : 0.0;

        $old_slug = get_option( 'order_sync_portal_slug', '' );
        update_option( 'order_sync_google_maps_api_key', $api_key );
        update_option( 'order_sync_interval', $sync_interval );
        update_option( 'order_sync_portal_slug', $portal_slug );
        update_option( 'order_sync_session_duration', $session_duration );
        update_option( 'subsales_individual_session_duration', $individual_session_duration );
        update_option( 'order_sync_login_mode', $login_mode );
        update_option( 'subsales_points_mode', $points_mode );
        update_option( 'subsales_points_denomination', $points_denomination );
        update_option( 'subsales_points_distribution', $points_distribution );
        update_option( 'subsales_donation_bonus_enabled', $donation_bonus_enabled );
        update_option( 'subsales_donation_percentage', $donation_percentage );
        update_option( 'subsales_donation_distribution', $donation_distribution );
        update_option( 'subsales_sales_enabled', $sales_enabled );
        update_option( 'subsales_digital_payments_enabled', $subsales_digital_payments_enabled );
        update_option( 'subsales_square_environment', $subsales_square_environment );
        update_option( 'subsales_square_access_token_sandbox', $subsales_square_access_token_sandbox );
        update_option( 'subsales_square_access_token_production', $subsales_square_access_token_production );
        update_option( 'subsales_square_location_id_sandbox', $subsales_square_location_id_sandbox );
        update_option( 'subsales_square_location_id_production', $subsales_square_location_id_production );
        update_option( 'subsales_square_webhook_signature_key_sandbox', $subsales_square_webhook_signature_key_sandbox );
        update_option( 'subsales_square_webhook_signature_key_production', $subsales_square_webhook_signature_key_production );
        update_option( 'subsales_convenience_fee_enabled', $subsales_convenience_fee_enabled );
        update_option( 'subsales_convenience_fee_percentage', $subsales_convenience_fee_percentage );

        if ( $portal_slug !== $old_slug ) {
            order_sync_ensure_pwa_page( $portal_slug );
            flush_rewrite_rules();
        }

        echo '<div class="notice notice-success"><p>Overall settings saved!</p></div>';
    }

    if ( isset( $_POST['save_branding'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        $branding = isset( $_POST['subsales_branding'] ) ? sanitize_text_field( $_POST['subsales_branding'] ) : '';
        $admin_email = isset( $_POST['subsales_admin_email'] ) ? sanitize_email( $_POST['subsales_admin_email'] ) : '';
        $style_variant = isset( $_POST['style_variant'] ) ? sanitize_text_field( $_POST['style_variant'] ) : 'default';
        $primary_color = isset( $_POST['primary_color'] ) ? sanitize_text_field( $_POST['primary_color'] ) : '#2d6cdf';
        $header_image = isset( $_POST['subsales_header_image'] ) ? intval( $_POST['subsales_header_image'] ) : 0;

        // PWA Icon settings
        $pwa_app_name = isset( $_POST['pwa_app_name'] ) ? sanitize_text_field( $_POST['pwa_app_name'] ) : 'Subsales';
        $pwa_icon_text = isset( $_POST['pwa_icon_text'] ) ? strtoupper( substr( sanitize_text_field( $_POST['pwa_icon_text'] ), 0, 4 ) ) : 'BK';
        $pwa_icon_style = isset( $_POST['pwa_icon_style'] ) ? sanitize_text_field( $_POST['pwa_icon_style'] ) : 'simple';
        $pwa_icon_text_color = isset( $_POST['pwa_icon_text_color'] ) ? sanitize_text_field( $_POST['pwa_icon_text_color'] ) : '#ffffff';
        $pwa_icon_use_primary = isset( $_POST['pwa_icon_use_primary'] ) ? 1 : 0;
        $pwa_icon_bg_color = isset( $_POST['pwa_icon_bg_color'] ) ? sanitize_text_field( $_POST['pwa_icon_bg_color'] ) : '#2d6cdf';

        update_option( 'subsales_branding', $branding );
        update_option( 'subsales_admin_email', $admin_email );
        update_option( 'order_sync_style_variant', $style_variant );
        update_option( 'order_sync_primary_color', $primary_color );
        update_option( 'subsales_header_image', $header_image );
        update_option( 'subsales_pwa_app_name', $pwa_app_name );
        update_option( 'subsales_pwa_icon_text', $pwa_icon_text );
        update_option( 'subsales_pwa_icon_style', $pwa_icon_style );
        update_option( 'subsales_pwa_icon_text_color', $pwa_icon_text_color );
        update_option( 'subsales_pwa_icon_use_primary', $pwa_icon_use_primary );
        update_option( 'subsales_pwa_icon_bg_color', $pwa_icon_bg_color );

        echo '<div class="notice notice-success"><p>Branding saved!</p></div>';
    }

    // Products are saved by admin/partials/products-editor.php, which owns its
    // own handler and nonce (same pattern as address-management-dashboard.php).

    if ( isset( $_POST['save_zipcodes'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        // Handle ZIP codes configuration
        if ( isset( $_POST['zipcode_list'] ) ) {
            $zipcode_input = sanitize_textarea_field( $_POST['zipcode_list'] );
            // Parse comma or newline separated ZIPs
            $zipcodes = preg_split( '/[,\s\n]+/', $zipcode_input, -1, PREG_SPLIT_NO_EMPTY );
            // Validate and clean ZIPs
            $valid_zips = array();
            foreach ( $zipcodes as $zip ) {
                $zip = trim( $zip );
                if ( preg_match( '/^\d{5}$/', $zip ) ) {
                    $valid_zips[] = $zip;
                }
            }
            update_option( 'subsales_served_zips', array_unique( $valid_zips ) );
            echo '<div class="notice notice-success"><p>ZIP codes saved! (' . count( $valid_zips ) . ' valid ZIP codes)</p></div>';
        }
    }
    
    if ( isset( $_POST['save_deletion_settings'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        $delete_on_uninstall = isset( $_POST['subsales_delete_on_uninstall'] ) ? sanitize_text_field( $_POST['subsales_delete_on_uninstall'] ) : 'yes';
        
        if ( $delete_on_uninstall !== 'yes' && $delete_on_uninstall !== 'no' ) {
            $delete_on_uninstall = 'yes'; // Default to safe option
        }
        
        update_option( 'subsales_delete_on_uninstall', $delete_on_uninstall );
        delete_transient( 'subsales_show_deletion_prompt' ); // Clear any pending prompt
        
        subsales_log( 'INFO', 'system', 'Data deletion preference updated', array( 'choice' => $delete_on_uninstall ), 'admin' );
        
        if ( $delete_on_uninstall === 'yes' ) {
            echo '<div class="notice notice-warning"><p><strong>⚠️ Data deletion enabled.</strong> All plugin data will be permanently deleted when you delete this plugin.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p><strong>✅ Data preservation enabled.</strong> Your data will be kept safe even if you delete the plugin.</p></div>';
        }
    }

    // Handle clear data action (danger zone)
    if ( isset( $_POST['clear_data'] ) ) {
        check_admin_referer( 'order_sync_clear_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>Insufficient permissions to clear data.</p></div>';
        } else {
            global $wpdb;
            $cleared = array();
            
            // Check which items were selected
            if ( isset( $_POST['clear_orders'] ) && $_POST['clear_orders'] === '1' ) {
                $table = $wpdb->prefix . 'ss_orders';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                    $wpdb->query( "TRUNCATE TABLE {$table}" );
                    $cleared[] = 'Orders';
                }
            }
            
            if ( isset( $_POST['clear_teams'] ) && $_POST['clear_teams'] === '1' ) {
                $table = $wpdb->prefix . 'ss_teams';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                    $wpdb->query( "TRUNCATE TABLE {$table}" );
                    $cleared[] = 'Teams';
                }
            }
            
            if ( isset( $_POST['clear_members'] ) && $_POST['clear_members'] === '1' ) {
                $members_table = $wpdb->prefix . 'ss_team_members';
                $user_teams_table = $wpdb->prefix . 'ss_user_teams';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$members_table}'" ) === $members_table ) {
                    $wpdb->query( "TRUNCATE TABLE {$members_table}" );
                }
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$user_teams_table}'" ) === $user_teams_table ) {
                    $wpdb->query( "TRUNCATE TABLE {$user_teams_table}" );
                }
                $cleared[] = 'Team Members';
            }
            
            if ( isset( $_POST['clear_addresses'] ) && $_POST['clear_addresses'] === '1' ) {
                $table = $wpdb->prefix . 'ss_addresses';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                    $wpdb->query( "TRUNCATE TABLE {$table}" );
                    $cleared[] = 'Addresses';
                }
            }
            
            if ( isset( $_POST['clear_settings'] ) && $_POST['clear_settings'] === '1' ) {
                order_sync_clear_settings();
                $cleared[] = 'Settings';
            }
            
            if ( ! empty( $cleared ) ) {
                $items = implode( ', ', $cleared );
                echo '<div class="notice notice-success"><p><strong>✅ Data cleared successfully:</strong> ' . esc_html( $items ) . '</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p><strong>⚠️ No items selected.</strong> Please select at least one item to clear.</p></div>';
            }
        }
    }

    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    $sync_interval = get_option( 'order_sync_interval', 300 );
    $portal_slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    $delete_on_uninstall = get_option( 'subsales_delete_on_uninstall', 0 );
    $branding = get_option( 'subsales_branding', 'Subsales' );
    $admin_email = get_option( 'subsales_admin_email', get_option( 'admin_email' ) );
    $style_variant = get_option( 'order_sync_style_variant', 'default' );
    $primary_color = get_option( 'order_sync_primary_color', '#2d6cdf' );
    $portal_url = esc_url_raw( home_url( '/' . $portal_slug . '/' ) );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    $pwa_app_name = get_option( 'subsales_pwa_app_name', 'Subsales' );
    $pwa_icon_text = get_option( 'subsales_pwa_icon_text', 'BK' );
    $pwa_icon_style = get_option( 'subsales_pwa_icon_style', 'simple' );
    $pwa_icon_text_color = get_option( 'subsales_pwa_icon_text_color', '#ffffff' );
    $pwa_icon_use_primary = get_option( 'subsales_pwa_icon_use_primary', 1 );
    $pwa_icon_bg_color = get_option( 'subsales_pwa_icon_bg_color', '#2d6cdf' );
    $sales_enabled = get_option( 'subsales_sales_enabled', 1 );
    ?>
    <div class="wrap">
        <h1>Subsales Settings</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="#tab-overall" class="nav-tab nav-tab-active" data-target="#tab-overall">Overall Settings</a>
            <a href="#tab-branding" class="nav-tab" data-target="#tab-branding">Branding / Look &amp; Feel</a>
            <a href="#tab-products" class="nav-tab" data-target="#tab-products">Products</a>
            <a href="#tab-address_extracts" class="nav-tab" data-target="#tab-address_extracts">Address Management</a>
            <a href="#tab-backup_restore" class="nav-tab" data-target="#tab-backup_restore">Backup / Restore</a>
            <a href="#tab-system_info" class="nav-tab" data-target="#tab-system_info">System Info</a>
            <a href="#tab-season_setup" class="nav-tab" data-target="#tab-season_setup">Set Up Season</a>
        </h2>
        
        <style>
        .subsales-tab-panel { display: none; margin-top: 20px; }
        .subsales-tab-panel.active { display: block; }
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
            });
        })(jQuery);
        </script>
    <?php 
        // Check for import/restore result message
        $import_message = get_transient( 'subsales_import_message' );
        if ( $import_message ) :
            delete_transient( 'subsales_import_message' );
            
            // Detect if errors occurred
            $has_errors = ( strpos( $import_message, '||ERROR_MARKER||' ) !== false );
            $import_message = str_replace( '||ERROR_MARKER||', '', $import_message );
            
            // Convert newlines to br tags, allow basic HTML
            $import_message = nl2br( $import_message, false );
            
            $notice_class = $has_errors ? 'notice-error' : 'notice-success';
            $allowed_tags = array(
                'strong' => array(),
                'br' => array(),
                'em' => array()
            );
        ?>
            <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
                <p style="line-height: 1.8; margin: 0.5em 0;"><?php echo wp_kses( $import_message, $allowed_tags ); ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ( ! empty( $_GET['subsales_import_error'] ) ) :
            $error_code = sanitize_text_field( wp_unslash( $_GET['subsales_import_error'] ) );
            $error_msg = 'Import failed';
            if ( $error_code === 'nofile' ) {
                $error_msg = 'No backup file was uploaded. Please select a file and try again.';
            } elseif ( $error_code === 'clear_failed' ) {
                $error_msg = 'Failed to clear existing data before restore. Check system logs for details.';
            }
        ?>
            <div class="notice notice-error"><p><strong>Error:</strong> <?php echo esc_html( $error_msg ); ?></p></div>
        <?php endif; ?>
        
        
        <!-- Main Settings Panels -->
            <div class="subsales-tab-panels">
                <div id="tab-overall" class="subsales-tab-panel">
                    <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
                    <input type="hidden" name="panel" value="overall" />
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Subsales</th>
                            <td>
                                <label style="display:flex;align-items:center;gap:10px;">
                                    <input type="checkbox" name="sales_enabled" value="1" <?php checked( intval( $sales_enabled ), 1 ); ?> />
                                    <span><strong>Subsales is active</strong></span>
                                </label>
                                <p class="description">When unchecked, the PWA will show a thank-you message and block logins and new orders.</p>
                            </td>
                        </tr>
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
                            <th scope="row">Team mode session duration</th>
                            <td>
                                <select name="session_duration">
                                    <option value="120000" <?php selected( 120000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 minutes (Test)</option>
                                    <option value="7200000" <?php selected( 7200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 hours</option>
                                    <option value="43200000" <?php selected( 43200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>12 hours</option>
                                    <option value="86400000" <?php selected( 86400000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>24 hours (default)</option>
                                    <option value="172800000" <?php selected( 172800000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 days</option>
                                    <option value="604800000" <?php selected( 604800000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>7 days</option>
                                </select>
                                <p class="description">Session duration for Team-based sales mode. Shorter duration recommended for shared devices.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Individual mode session duration</th>
                            <td>
                                <select name="individual_session_duration">
                                    <option value="120000" <?php selected( 120000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>2 minutes (Test)</option>
                                    <option value="86400000" <?php selected( 86400000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>24 hours</option>
                                    <option value="259200000" <?php selected( 259200000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>3 days</option>
                                    <option value="604800000" <?php selected( 604800000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>7 days</option>
                                    <option value="1209600000" <?php selected( 1209600000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>14 days (default)</option>
                                    <option value="1814400000" <?php selected( 1814400000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>21 days</option>
                                    <option value="2592000000" <?php selected( 2592000000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>30 days</option>
                                </select>
                                <p class="description">Session duration for Individual sales mode. Longer duration recommended for extended campaigns (2+ weeks).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Login Mode</th>
                            <td>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio" name="login_mode" value="legacy" <?php checked( $login_mode, 'legacy' ); ?> />
                                        <strong>Team Mode (Team + Code)</strong>
                                        <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                            Users enter a team name and access code to login.
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
                        <tr>
                            <th scope="row">Points Calculation</th>
                            <td>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: inline-block; margin-right: 10px; font-weight: 600;">Points per Product:</label>
                                    <input type="number" step="0.01" min="0" name="points_denomination" value="<?php echo esc_attr( get_option( 'subsales_points_denomination', 1.0 ) ); ?>" style="width: 100px;" />
                                    <input type="hidden" name="points_mode" value="product" />
                                </div>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: inline-block; margin-right: 10px; font-weight: 600;">Points Distribution:</label>
                                    <select name="points_distribution" style="width: 200px;">
                                        <option value="individual" <?php selected( get_option( 'subsales_points_distribution', 'individual' ), 'individual' ); ?>>Individual</option>
                                        <option value="team" <?php selected( get_option( 'subsales_points_distribution', 'individual' ), 'team' ); ?>>Team (divided by members)</option>
                                    </select>
                                </div>
                                <p class="description" style="margin-top: 10px;">
                                    <strong>Points Per Product:</strong> Each product sold earns this many points (e.g., 3.5 points per sub)<br>
                                    <strong>Individual:</strong> Each person earns points based on their own products sold<br>
                                    <strong>Team:</strong> Team's total product points are divided equally among all team members who worked that day
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Donation Bonus</th>
                            <td>
                                <div style="margin-bottom: 15px;">
                                    <label>
                                        <input type="checkbox" name="donation_bonus_enabled" value="1" <?php checked( get_option( 'subsales_donation_bonus_enabled', 0 ), 1 ); ?> />
                                        <strong>Enable Donation Bonus Points</strong>
                                    </label>
                                </div>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: inline-block; margin-right: 10px; font-weight: 600;">Donation Percentage:</label>
                                    <input type="number" step="0.1" min="0" max="100" name="donation_percentage" value="<?php echo esc_attr( get_option( 'subsales_donation_percentage', 50.0 ) ); ?>" style="width: 100px;" />
                                    <span>%</span>
                                </div>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: inline-block; margin-right: 10px; font-weight: 600;">Donation Distribution:</label>
                                    <select name="donation_distribution" style="width: 200px;">
                                        <option value="individual" <?php selected( get_option( 'subsales_donation_distribution', 'team' ), 'individual' ); ?>>Individual</option>
                                        <option value="team" <?php selected( get_option( 'subsales_donation_distribution', 'team' ), 'team' ); ?>>Team (divided by members)</option>
                                    </select>
                                </div>
                                <p class="description" style="margin-top: 10px;">
                                    Donation amount is converted to points at the specified percentage. The dollar value becomes point value.<br>
                                    <strong>Example:</strong> $735 donations × 50% = $367.50 → 367.50 points (split by team or individual)
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="2"><h3 style="margin-top:20px;margin-bottom:10px">Digital Payments (Square)</h3></th>
                        </tr>
                        <tr>
                            <th scope="row">Enable Digital Payments</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="subsales_digital_payments_enabled" value="1" <?php checked( get_option( 'subsales_digital_payments_enabled', 0 ), 1 ); ?> />
                                    <strong>Enable Square digital payments (QR code checkout)</strong>
                                </label>
                                <p class="description">Square credentials must be filled in below before enabling this — once on, sellers see a "Digital Payment" option in the PWA.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Square Environment</th>
                            <td>
                                <select name="subsales_square_environment">
                                    <option value="sandbox" <?php selected( get_option( 'subsales_square_environment', 'sandbox' ), 'sandbox' ); ?>>Sandbox (testing)</option>
                                    <option value="production" <?php selected( get_option( 'subsales_square_environment', 'sandbox' ), 'production' ); ?>>Production (live payments)</option>
                                </select>
                                <p class="description">Use Sandbox for testing with fake payments. Only switch to Production after the full flow has been verified end-to-end in Sandbox.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Square Access Token (Sandbox)</th>
                            <td>
                                <input type="password" name="subsales_square_access_token_sandbox" value="<?php echo esc_attr( get_option( 'subsales_square_access_token_sandbox', '' ) ); ?>" class="regular-text" autocomplete="off" />
                                <p class="description">Sandbox access token from the Square Developer Dashboard.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Square Access Token (Production)</th>
                            <td>
                                <input type="password" name="subsales_square_access_token_production" value="<?php echo esc_attr( get_option( 'subsales_square_access_token_production', '' ) ); ?>" class="regular-text" autocomplete="off" />
                                <p class="description">Production access token — authorizes real charges. Stored via the standard WordPress options table like every other setting here; this is a password field for screen-privacy only, not encryption at rest.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Square Location ID (Sandbox)</th>
                            <td>
                                <input type="text" name="subsales_square_location_id_sandbox" value="<?php echo esc_attr( get_option( 'subsales_square_location_id_sandbox', '' ) ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Square Location ID (Production)</th>
                            <td>
                                <input type="text" name="subsales_square_location_id_production" value="<?php echo esc_attr( get_option( 'subsales_square_location_id_production', '' ) ); ?>" class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Webhook Signature Key (Sandbox)</th>
                            <td>
                                <input type="password" name="subsales_square_webhook_signature_key_sandbox" value="<?php echo esc_attr( get_option( 'subsales_square_webhook_signature_key_sandbox', '' ) ); ?>" class="regular-text" autocomplete="off" />
                                <p class="description">Used to verify that incoming Square webhook payloads are genuine.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Webhook Signature Key (Production)</th>
                            <td>
                                <input type="password" name="subsales_square_webhook_signature_key_production" value="<?php echo esc_attr( get_option( 'subsales_square_webhook_signature_key_production', '' ) ); ?>" class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Test Square Credentials</th>
                            <td>
                                <p class="description">Tests the environment/token/location currently typed into the fields above (not necessarily what's saved yet).</p>
                                <p>
                                    <button type="button" id="test_square_credentials_btn" class="button">Test Square credentials</button>
                                    <span id="square_test_status" style="margin-left:12px; font-weight:600"></span>
                                </p>
                                <div id="square_test_output" style="white-space:pre-wrap; border:1px solid #eee; padding:8px; margin-top:8px; display:none"></div>
                                <script>
                                (function(){
                                    const ajaxUrl = ajaxurl;
                                    const nonce = <?php echo json_encode( wp_create_nonce( 'subsales_test_square_credentials' ) ); ?>;
                                    function setStatus(s){ document.getElementById('square_test_status').textContent = s; }
                                    document.getElementById('test_square_credentials_btn').addEventListener('click', function(){
                                        const envSelect = document.querySelector('select[name="subsales_square_environment"]');
                                        const environment = (envSelect && envSelect.value) || 'sandbox';
                                        const tokenField = document.querySelector('input[name="subsales_square_access_token_' + environment + '"]');
                                        const locationField = document.querySelector('input[name="subsales_square_location_id_' + environment + '"]');
                                        const fd = new FormData();
                                        fd.append('action', 'subsales_test_square_credentials');
                                        fd.append('nonce', nonce);
                                        fd.append('environment', environment);
                                        fd.append('access_token', tokenField ? tokenField.value : '');
                                        fd.append('location_id', locationField ? locationField.value : '');
                                        const out = document.getElementById('square_test_output'); out.style.display='block'; out.textContent='Testing...'; setStatus('Testing...');
                                        fetch(ajaxUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(j){
                                            if (!j) { out.textContent = 'No response'; setStatus('Error'); return; }
                                            if (!j.success) {
                                                out.textContent = 'Error: ' + (j.data && j.data.message ? j.data.message : 'Unknown'); setStatus('Invalid'); return;
                                            }
                                            out.textContent = (j.data && j.data.message) ? j.data.message : 'OK';
                                            setStatus('Valid');
                                        }).catch(function(e){ out.textContent = 'Fetch error: ' + e.message; setStatus('Error'); });
                                    });
                                })();
                                </script>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Convenience Fee</th>
                            <td>
                                <div style="margin-bottom: 15px;">
                                    <label>
                                        <input type="checkbox" name="subsales_convenience_fee_enabled" value="1" <?php checked( get_option( 'subsales_convenience_fee_enabled', 0 ), 1 ); ?> />
                                        <strong>Enable Convenience Fee</strong>
                                    </label>
                                </div>
                                <div style="margin-bottom: 15px;">
                                    <label style="display: inline-block; margin-right: 10px; font-weight: 600;">Convenience Fee Percentage:</label>
                                    <input type="number" step="0.1" min="0" max="100" name="subsales_convenience_fee_percentage" value="<?php echo esc_attr( get_option( 'subsales_convenience_fee_percentage', 0.0 ) ); ?>" style="width: 100px;" />
                                    <span>%</span>
                                </div>
                                <p class="description" style="margin-top: 10px;">Flat percentage added on top of a digital payment's total to cover processing costs.</p>
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
                            <th scope="row">Admin Contact Email</th>
                            <td>
                                <input type="email" name="subsales_admin_email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text" />
                                <p class="description">Email address for support inquiries. Shown to users when they encounter signup errors.</p>
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
                        <tr>
                            <th scope="row" colspan="2"><h3 style="margin-top:20px;margin-bottom:10px">PWA Icon Settings</h3></th>
                        </tr>
                        <tr>
                            <th scope="row">App Name (when installed)</th>
                            <td>
                                <input type="text" name="pwa_app_name" id="pwa_app_name" value="<?php echo esc_attr( $pwa_app_name ); ?>" class="regular-text" maxlength="30" />
                                <p class="description">This name appears under the icon on users' home screens when they install the PWA.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Icon Text (1-4 letters)</th>
                            <td>
                                <input type="text" name="pwa_icon_text" id="pwa_icon_text" value="<?php echo esc_attr( $pwa_icon_text ); ?>" class="regular-text" maxlength="4" style="width:80px;text-transform:uppercase" />
                                <p class="description">Short identifier shown on the icon itself (e.g., "BK", "SUB", "SALE").</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Icon Style</th>
                            <td>
                                <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
                                    <label style="display:flex;align-items:center;gap:8px">
                                        <input type="radio" name="pwa_icon_style" value="simple" <?php checked( $pwa_icon_style, 'simple' ); ?> />
                                        <strong>Simple Text</strong> - Clean text on colored background
                                    </label>
                                    <label style="display:flex;align-items:center;gap:8px">
                                        <input type="radio" name="pwa_icon_style" value="circle" <?php checked( $pwa_icon_style, 'circle' ); ?> />
                                        <strong>Circle Background</strong> - Text in a circular frame
                                    </label>
                                    <label style="display:flex;align-items:center;gap:8px">
                                        <input type="radio" name="pwa_icon_style" value="bordered" <?php checked( $pwa_icon_style, 'bordered' ); ?> />
                                        <strong>Bordered Frame</strong> - Text with decorative border
                                    </label>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Icon Colors</th>
                            <td>
                                <div style="margin-bottom:12px">
                                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                        <input type="checkbox" name="pwa_icon_use_primary" id="pwa_icon_use_primary" value="1" <?php checked( $pwa_icon_use_primary, 1 ); ?> />
                                        Use Primary Color for icon background
                                    </label>
                                </div>
                                <div id="pwa_custom_bg_color_wrapper" style="margin-bottom:12px;<?php echo $pwa_icon_use_primary ? 'display:none;' : ''; ?>">
                                    <label>Custom Background Color: <input type="color" name="pwa_icon_bg_color" id="pwa_icon_bg_color" value="<?php echo esc_attr( $pwa_icon_bg_color ); ?>" /></label>
                                </div>
                                <div style="margin-bottom:8px">
                                    <label>Text Color: <input type="color" name="pwa_icon_text_color" id="pwa_icon_text_color" value="<?php echo esc_attr( $pwa_icon_text_color ); ?>" /></label>
                                </div>
                                <p class="description">Icon colors can use the primary color above or be customized independently.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Icon Preview</th>
                            <td>
                                <div id="pwa_icon_preview_container" style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
                                    <div>
                                        <div style="margin-bottom:8px;font-weight:600">192x192</div>
                                        <div id="pwa_icon_preview_192" style="width:96px;height:96px;border:1px solid #ddd;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center"></div>
                                    </div>
                                    <div>
                                        <div style="margin-bottom:8px;font-weight:600">512x512</div>
                                        <div id="pwa_icon_preview_512" style="width:128px;height:128px;border:1px solid #ddd;border-radius:8px;overflow:hidden;display:flex;align-items:center;justify-content:center"></div>
                                    </div>
                                </div>
                                <p class="description" style="margin-top:12px">Live preview of your PWA icon. Changes update in real-time.</p>
                                <script>
                                (function(){
                                    function generateIconSVG(size, text, style, bgColor, textColor) {
                                        var cornerRadius = Math.round(size * 0.125);
                                        var fontSize = Math.round(size * 0.23);
                                        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">';
                                        
                                        // Background rect
                                        svg += '<rect width="100%" height="100%" fill="' + bgColor + '" rx="' + cornerRadius + '"/>';
                                        
                                        // Style-specific decorations
                                        if (style === 'circle') {
                                            var circleRadius = size * 0.35;
                                            var centerX = size / 2;
                                            var centerY = size / 2;
                                            svg += '<circle cx="' + centerX + '" cy="' + centerY + '" r="' + circleRadius + '" fill="rgba(255,255,255,0.2)" stroke="' + textColor + '" stroke-width="3"/>';
                                        } else if (style === 'bordered') {
                                            var borderPadding = size * 0.1;
                                            var borderRadius = Math.round(size * 0.08);
                                            svg += '<rect x="' + borderPadding + '" y="' + borderPadding + '" width="' + (size - borderPadding * 2) + '" height="' + (size - borderPadding * 2) + '" fill="none" stroke="' + textColor + '" stroke-width="3" rx="' + borderRadius + '"/>';
                                        }
                                        
                                        // Text
                                        svg += '<text x="50%" y="55%" font-size="' + fontSize + '" fill="' + textColor + '" text-anchor="middle" dominant-baseline="middle" font-family="Arial, Helvetica, sans-serif" font-weight="bold">' + text + '</text>';
                                        svg += '</svg>';
                                        return svg;
                                    }
                                    
                                    function updateIconPreview() {
                                        try {
                                            var textInput = document.getElementById('pwa_icon_text');
                                            var text = textInput ? (textInput.value || 'BK').toUpperCase().substring(0, 4) : 'BK';
                                            
                                            var styleInput = document.querySelector('input[name="pwa_icon_style"]:checked');
                                            var style = styleInput ? styleInput.value : 'simple';
                                            
                                            var usePrimaryCheckbox = document.getElementById('pwa_icon_use_primary');
                                            var usePrimary = usePrimaryCheckbox ? usePrimaryCheckbox.checked : true;
                                            
                                            var primaryColorInput = document.querySelector('input[name="primary_color"]');
                                            var customBgInput = document.getElementById('pwa_icon_bg_color');
                                            var bgColor = usePrimary && primaryColorInput ? primaryColorInput.value : (customBgInput ? customBgInput.value : '#2d6cdf');
                                            
                                            var textColorInput = document.getElementById('pwa_icon_text_color');
                                            var textColor = textColorInput ? textColorInput.value : '#ffffff';
                                            
                                            var svg192 = generateIconSVG(192, text, style, bgColor, textColor);
                                            var svg512 = generateIconSVG(512, text, style, bgColor, textColor);
                                            
                                            var preview192 = document.getElementById('pwa_icon_preview_192');
                                            var preview512 = document.getElementById('pwa_icon_preview_512');
                                            
                                            if (preview192) {
                                                preview192.innerHTML = svg192;
                                                var svg192El = preview192.querySelector('svg');
                                                if (svg192El) {
                                                    svg192El.style.width = '100%';
                                                    svg192El.style.height = '100%';
                                                }
                                            }
                                            if (preview512) {
                                                preview512.innerHTML = svg512;
                                                var svg512El = preview512.querySelector('svg');
                                                if (svg512El) {
                                                    svg512El.style.width = '100%';
                                                    svg512El.style.height = '100%';
                                                }
                                            }
                                        } catch(e) {
                                            console.error('Icon preview error:', e);
                                        }
                                    }
                                    
                                    // Wire up all inputs with null checks
                                    var textInput = document.getElementById('pwa_icon_text');
                                    if (textInput) textInput.addEventListener('input', updateIconPreview);
                                    
                                    document.querySelectorAll('input[name="pwa_icon_style"]').forEach(function(r) {
                                        r.addEventListener('change', updateIconPreview);
                                    });
                                    
                                    var textColorInput = document.getElementById('pwa_icon_text_color');
                                    if (textColorInput) textColorInput.addEventListener('input', updateIconPreview);
                                    
                                    var bgColorInput = document.getElementById('pwa_icon_bg_color');
                                    if (bgColorInput) bgColorInput.addEventListener('input', updateIconPreview);
                                    
                                    var usePrimaryCheckbox = document.getElementById('pwa_icon_use_primary');
                                    if (usePrimaryCheckbox) {
                                        usePrimaryCheckbox.addEventListener('change', function() {
                                            var wrapper = document.getElementById('pwa_custom_bg_color_wrapper');
                                            if (wrapper) wrapper.style.display = this.checked ? 'none' : 'block';
                                            updateIconPreview();
                                        });
                                    }
                                    
                                    var primaryColorInput = document.querySelector('input[name="primary_color"]');
                                    if (primaryColorInput) {
                                        primaryColorInput.addEventListener('input', function() {
                                            var usePrimaryCheckbox = document.getElementById('pwa_icon_use_primary');
                                            if (usePrimaryCheckbox && usePrimaryCheckbox.checked) {
                                                updateIconPreview();
                                            }
                                        });
                                    }
                                    
                                    // Initialize preview on page load
                                    if (document.readyState === 'loading') {
                                        document.addEventListener('DOMContentLoaded', updateIconPreview);
                                    } else {
                                        updateIconPreview();
                                    }
                                })();
                                </script>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><?php submit_button( 'Save Branding', 'primary', 'save_branding', false ); ?></p>
                    </form>
                </div>
                
                <div id="tab-products" class="subsales-tab-panel">
                    <?php
                    // Shared with the Season Setup wizard. No id prefix here: this is
                    // the canonical copy; the wizard passes its own prefix.
                    include SUBSALES_PLUGIN_PATH . 'admin/partials/products-editor.php';
                    ?>
                </div>

                <div id="tab-address_extracts" class="subsales-tab-panel">
                    <h2 style="margin-top:18px">📍 Address Management</h2>
                    <p>Manage your service area configuration, upload address data, and generate JSON extracts for the PWA.</p>
                    
                    <?php
                    // Include the modern dashboard
                    include plugin_dir_path( dirname( __FILE__ ) ) . 'admin/address-management-dashboard.php';
                    ?>
                </div>

                <div id="tab-backup_restore" class="subsales-tab-panel">
            <h2 style="margin-top:18px">💾 Backup & Restore</h2>
            <p>Export all plugin data for backup or migrate to another WordPress site. Import previously exported backups to restore data.</p>
            
            <!-- Export Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
                <h3 style="margin-top: 0;">📤 Export Data</h3>
                <?php $export_nonce = wp_create_nonce( 'subsales_export_backup' ); ?>
                
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_backup&_wpnonce=' . $export_nonce ) ); ?>">
                        📦 Export Complete Backup (ZIP)
                    </a>
                </div>
                
                <details style="margin-top: 15px;">
                    <summary style="cursor: pointer; font-weight: 600;">Individual Exports</summary>
                    <div style="padding: 15px; margin-top: 10px; background: #f6f7f7; border-radius: 4px;">
                        <p style="margin-top: 0;">Export individual components separately:</p>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_orders&_wpnonce=' . $export_nonce ) ); ?>">Orders (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_teams&_wpnonce=' . $export_nonce ) ); ?>">Teams (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_members&_wpnonce=' . $export_nonce ) ); ?>">Members (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_addresses&_wpnonce=' . $export_nonce ) ); ?>">Addresses (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_settings&_wpnonce=' . $export_nonce ) ); ?>">Settings (CSV)</a>
                        </div>
                    </div>
                </details>
                
                <div style="background: #e7f3ff; border-left: 4px solid #0071a1; padding: 12px; margin-top: 15px;">
                    <p style="margin: 0;"><strong>💡 Tip:</strong> The complete backup ZIP includes all orders, teams, members, addresses, and settings. Perfect for site migrations or scheduled backups.</p>
                </div>
            </div>
            
            <!-- Import Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
                <h3 style="margin-top: 0;">📥 Import Data</h3>
                <p style="background: #f0f0f1; padding: 10px; margin-bottom: 15px; border-radius: 3px;">
                    <strong>Upload Limits:</strong> 
                    Max file size: <code><?php echo size_format( wp_max_upload_size() ); ?></code> | 
                    Max post size: <code><?php echo ini_get( 'post_max_size' ); ?></code>
                </p>
                
                <form id="subsales-import-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'subsales_import_backup' ); ?>
                    <input type="hidden" name="action" value="subsales_import_backup" />
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Backup File</th>
                            <td>
                                <input type="file" name="backup_file" accept=".csv,.zip,text/csv,application/zip" required style="margin-bottom: 10px;" />
                                <p class="description">Upload a ZIP backup (complete) or individual CSV file</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Import Options</th>
                            <td>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" name="import_update_existing" value="1" />
                                    Update existing records (match by ID) - otherwise skip duplicates
                                </label>
                                <p class="description" style="margin: 8px 0 0 0; padding: 8px; background: #e7f7e7; border-left: 3px solid #46b450;">
                                    <strong>🗺️ Auto-Geocoding Enabled:</strong> Addresses without lat/lng coordinates will be automatically geocoded using Google Maps API. 
                                    ZIP codes will be validated against coordinates and auto-corrected if mismatched.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">Import Backup</button>
                    </p>
                </form>
            </div>
            
            <!-- Destructive Restore Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #d63638; border-radius: 4px; padding: 20px;">
                <h3 style="margin-top: 0; color: #d63638;">⚠️ Advanced: Full Restore (Destructive)</h3>
                <p><strong>Warning:</strong> This will clear existing data before importing. Only use this when restoring from a known good backup.</p>
                
                <details>
                    <summary style="cursor: pointer; font-weight: 600; padding: 10px; background: #f6f7f7; border-radius: 4px;">Show Restore Options</summary>
                    <div style="padding: 15px; margin-top: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <form id="subsales-destructive-restore" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                            <?php wp_nonce_field( 'subsales_restore_backup' ); ?>
                            <input type="hidden" name="action" value="subsales_restore_backup" />
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Backup File</th>
                                    <td><input type="file" name="backup_file" accept=".csv,.zip,text/csv,application/zip" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Clear Before Import</th>
                                    <td>
                                        <label style="display: block; margin: 8px 0;">
                                            <input type="radio" name="restore_target" value="both" checked />
                                            <strong>Everything</strong> - Clear all orders, teams, members, addresses, and settings
                                        </label>
                                        <label style="display: block; margin: 8px 0;">
                                            <input type="radio" name="restore_target" value="data" />
                                            <strong>Data Only</strong> - Clear orders, teams, members, and addresses (keep settings)
                                        </label>
                                        <label style="display: block; margin: 8px 0;">
                                            <input type="radio" name="restore_target" value="settings" />
                                            <strong>Settings Only</strong> - Clear settings (keep data)
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Confirmation</th>
                                    <td>
                                        <label style="font-weight: 600;">
                                            <input type="checkbox" id="confirm_clear_restore" />
                                            I understand this will permanently delete the selected data before importing
                                        </label>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button id="subsales-restore-btn" type="submit" class="button button-large" style="background: #d63638; border-color: #d63638; color: #fff;" disabled>⚠️ Restore from Backup</button>
                            </p>
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
                                    if ( ! chk.checked ) {
                                        e.preventDefault();
                                        alert('Please confirm the destructive restore by checking the box.');
                                        return false;
                                    }
                                    
                                    var sel = 'both';
                                    if ( radios ) {
                                        radios.forEach(function(r){ if ( r.checked ) sel = r.value; });
                                    }
                                    
                                    var msg = 'This will permanently delete the selected plugin data before importing. Are you sure?';
                                    if ( sel === 'both' ) {
                                        msg = '⚠️ PERMANENT ACTION\n\nThis will delete ALL plugin data (orders, teams, members, addresses, and settings) before importing.\n\nThis cannot be undone.\n\nAre you absolutely sure?';
                                    } else if ( sel === 'data' ) {
                                        msg = 'This will permanently delete ALL orders, teams, members, and addresses before importing. Settings will be preserved. Are you sure?';
                                    } else if ( sel === 'settings' ) {
                                        msg = 'This will permanently delete all plugin settings before importing. Data (orders, teams, etc.) will be preserved. Are you sure?';
                                    }
                                    
                                    if ( ! confirm(msg) ) {
                                        e.preventDefault();
                                        return false;
                                    }
                                });
                            }
                        })();
                        </script>
                    </div>
                </details>
            </div>
            
            <!-- Clear data form -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #d63638; border-radius: 4px; padding: 20px; margin-top: 20px;">
                <h3 style="margin-top: 0; color: #d63638;">🗑️ Clear Data (Danger Zone)</h3>
                <p><strong>Warning:</strong> Select what you want to permanently delete. This action cannot be undone.</p>
                
                <form id="subsales-clear-data-form" method="post" action="">
                    <?php wp_nonce_field( 'order_sync_clear_nonce' ); ?>
                    
                    <div style="background: #f6f7f7; border: 1px solid #c3c4c7; padding: 15px; margin: 15px 0; border-radius: 4px;">
                        <p style="margin: 0 0 10px 0; font-weight: 600;">Select data to clear:</p>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_orders" value="1" class="subsales-clear-checkbox" />
                            <strong>Orders</strong> - All order data and customer information
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_teams" value="1" class="subsales-clear-checkbox" />
                            <strong>Teams</strong> - All teams and access codes
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_members" value="1" class="subsales-clear-checkbox" />
                            <strong>Team Members</strong> - All users and team assignments
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_addresses" value="1" class="subsales-clear-checkbox" />
                            <strong>Addresses</strong> - All address data and geocoding results
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_settings" value="1" class="subsales-clear-checkbox" />
                            <strong>Settings</strong> - All plugin configuration and options
                        </label>
                        
                        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                            <label style="cursor: pointer; font-weight: 600;">
                                <input type="checkbox" id="subsales-select-all-clear" />
                                Select All
                            </label>
                        </div>
                    </div>
                    
                    <div id="subsales-clear-warning" style="background: #fcf0f1; border: 1px solid #d63638; padding: 12px; margin: 15px 0; border-radius: 4px; display: none;">
                        <p style="margin: 0; color: #d63638;"><strong>⚠️ Warning:</strong> <span id="subsales-clear-warning-text">Please select at least one item to clear.</span></p>
                    </div>
                    
                    <p style="font-size: 12px; color: #646970; margin: 10px 0;">
                        <strong>Note:</strong> This does NOT delete plugin files, ZIP extracts, or database tables (only truncates/clears their data).
                    </p>
                    
                    <p>
                        <button id="subsales-clear-data-btn" name="clear_data" type="submit" class="button button-large" style="background: #d63638; border-color: #d63638; color: #fff;" disabled>Clear Selected Data</button>
                    </p>
                </form>
                
                <script>
                (function(){
                    var form = document.getElementById('subsales-clear-data-form');
                    var checkboxes = document.querySelectorAll('.subsales-clear-checkbox');
                    var selectAllCheckbox = document.getElementById('subsales-select-all-clear');
                    var clearBtn = document.getElementById('subsales-clear-data-btn');
                    var warningBox = document.getElementById('subsales-clear-warning');
                    var warningText = document.getElementById('subsales-clear-warning-text');
                    
                    function updateWarningAndButton() {
                        var selected = [];
                        checkboxes.forEach(function(cb) {
                            if (cb.checked) {
                                var label = cb.parentElement.querySelector('strong');
                                if (label) selected.push(label.textContent);
                            }
                        });
                        
                        if (selected.length === 0) {
                            clearBtn.disabled = true;
                            warningBox.style.display = 'none';
                        } else {
                            clearBtn.disabled = false;
                            warningBox.style.display = 'block';
                            
                            var itemsList = selected.join(', ');
                            warningText.textContent = 'You are about to permanently delete: ' + itemsList + '. This cannot be undone.';
                        }
                    }
                    
                    // Wire up checkboxes
                    checkboxes.forEach(function(cb) {
                        cb.addEventListener('change', updateWarningAndButton);
                    });
                    
                    // Wire up select all
                    if (selectAllCheckbox) {
                        selectAllCheckbox.addEventListener('change', function() {
                            checkboxes.forEach(function(cb) {
                                cb.checked = selectAllCheckbox.checked;
                            });
                            updateWarningAndButton();
                        });
                    }
                    
                    // Form submission confirmation
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            var selected = [];
                            checkboxes.forEach(function(cb) {
                                if (cb.checked) {
                                    var label = cb.parentElement.querySelector('strong');
                                    if (label) selected.push(label.textContent);
                                }
                            });
                            
                            if (selected.length === 0) {
                                e.preventDefault();
                                alert('Please select at least one item to clear.');
                                return false;
                            }
                            
                            var itemsList = selected.join(', ');
                            var msg = '⚠️ PERMANENT ACTION\n\n';
                            msg += 'This will permanently delete the following:\n';
                            msg += '• ' + selected.join('\n• ') + '\n\n';
                            msg += 'This cannot be undone.\n\n';
                            msg += 'Are you absolutely sure?';
                            
                            if (!confirm(msg)) {
                                e.preventDefault();
                                return false;
                            }
                        });
                    }
                    
                    // Initial state
                    updateWarningAndButton();
                })();
                </script>
            </div>
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
                <th scope="row">Database Tables</th>
                <td>
                    <?php
                    global $wpdb;
                    $tables = array(
                        $wpdb->prefix . 'ss_orders' => 'Orders',
                        $wpdb->prefix . 'ss_teams' => 'Teams',
                        $wpdb->prefix . 'ss_team_members' => 'Team Members',
                        $wpdb->prefix . 'ss_user_teams' => 'User-Team Assignments',
                        $wpdb->prefix . 'ss_edit_history' => 'Order Edit History',
                        $wpdb->prefix . 'ss_logs' => 'System Logs',
                        $wpdb->prefix . 'ss_pwa_sessions' => 'PWA Sessions',
                        $wpdb->prefix . 'ss_pwa_heartbeats' => 'PWA Heartbeats',
                        $wpdb->prefix . 'ss_addresses' => 'Address Lookup',
                        $wpdb->prefix . 'ss_campaigns' => 'Campaigns',
                        $wpdb->prefix . 'ss_signups' => 'Campaign Signups',
                        $wpdb->prefix . 'ss_team_campaigns' => 'Team Campaign Data'
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
                <div id="tab-season_setup" class="subsales-tab-panel">
                    <?php include SUBSALES_PLUGIN_PATH . 'admin/partials/season-setup.php'; ?>
                </div>

    </div> <!-- .wrap -->

    <!-- Import/Restore Progress Modal -->
    <div id="subsales-import-modal" class="subsales-modal" style="display: none;">
        <div class="modal-backdrop"></div>
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title">Importing Data</h2>
                <button class="modal-close" disabled title="Close when complete">×</button>
            </div>
            
            <div class="modal-body">
                <!-- Progress Bar -->
                <div class="progress-container">
                    <div class="subsales-progress-bar">
                        <div class="subsales-progress-fill" style="width: 0%;"></div>
                    </div>
                    <div class="progress-text">Initializing...</div>
                </div>

                <!-- Live Log -->
                <div class="log-container">
                    <h3>Activity Log</h3>
                    <div class="import-log"></div>
                </div>

                <!-- Error Sections (Collapsible) -->
                <div class="error-sections"></div>
            </div>
        </div>
    </div>
