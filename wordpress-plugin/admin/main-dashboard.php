<?php
/**
 * Main Dashboard Page
 * 
 * Overview dashboard with:
 * - Sales mode toggle (Team vs Individual)
 * - Active user count
 * - Key statistics (teams, members, orders, address data)
 * - Financial summary (product sales, donations, cash, checks)
 * - Product quantity totals
 * 
 * @package Subsales_Management
 * @since 2.2.1.153
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get database tables
global $wpdb;
$orders_table = $wpdb->prefix . 'ss_orders';
$teams_table = $wpdb->prefix . 'ss_teams';
$members_table = $wpdb->prefix . 'ss_team_members';

// Get counts
$order_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table} WHERE deleted = 0" );
$team_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table} WHERE status = 'active'" );
$team_count_inactive = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table} WHERE status = 'inactive'" );
$member_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table} WHERE status = 'active'" );
$member_count_inactive = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table} WHERE status = 'inactive'" );

// Compute financial summary
$product_sales_total = 0.0;
$donations_total = 0.0;
$cash_total = 0.0;
$check_total = 0.0;
$rows_fin = $wpdb->get_results( "SELECT order_data FROM {$orders_table} WHERE deleted = 0", ARRAY_A );
if ( $rows_fin ) {
    $conf_prods_for_fin = order_sync_get_products_config();
    foreach ( $rows_fin as $rf ) {
        $od = json_decode( $rf['order_data'], true );
        if ( ! is_array( $od ) ) continue;
        $order_product_total = 0.0;
        $order_donation = 0.0;
        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $price = isset( $pr['price'] ) ? floatval( $pr['price'] ) : 0.0;
                if ( $qty > 0 ) $order_product_total += $qty * $price;
            }
        } else {
            if ( is_array( $conf_prods_for_fin ) ) {
                foreach ( $conf_prods_for_fin as $p ) {
                    $pid = $p['id'];
                    $price = isset( $p['price'] ) ? floatval( $p['price'] ) : 0.0;
                    $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                    foreach ( $labels as $k ) {
                        if ( isset( $od[ $k ] ) ) { $q = intval( $od[ $k ] ); if ( $q > 0 ) $order_product_total += $q * $price; break; }
                    }
                }
            }
        }
        if ( isset( $od['donationAmount'] ) ) $order_donation = floatval( $od['donationAmount'] );
        $product_sales_total += $order_product_total;
        $donations_total += $order_donation;
        $order_total = $order_product_total + $order_donation;
        $payment = '';
        if ( isset( $od['paymentMethod'] ) && ! empty( $od['paymentMethod'] ) ) $payment = strtolower( $od['paymentMethod'] );
        else if ( ! empty( $od['checkNumber'] ) ) $payment = 'check';
        else if ( ! empty( $od['payCash'] ) || ! empty( $od['pay_cash'] ) ) $payment = 'cash';
        if ( $payment === 'check' ) $check_total += $order_total;
        elseif ( $payment === 'cash' ) $cash_total += $order_total;
    }
}

// Compute product totals
$products_conf = order_sync_get_products_config();
$product_totals = array();
foreach ( $products_conf as $p ) {
    $product_totals[ $p['id'] ] = 0;
}
$rows = $wpdb->get_results( "SELECT order_data FROM {$orders_table} WHERE deleted = 0", ARRAY_A );
if ( $rows ) {
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( is_array( $od ) ) {
            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                foreach ( $od['products'] as $op ) {
                    if ( isset( $op['id'] ) && isset( $op['qty'] ) ) {
                        $pid = $op['id'];
                        $qty = intval( $op['qty'] );
                        if ( isset( $product_totals[ $pid ] ) ) $product_totals[ $pid ] += $qty;
                    }
                }
            } else {
                foreach ( $products_conf as $p ) {
                    $pid = $p['id'];
                    $k1 = $pid . 'Qty'; $k2 = $pid . '_qty';
                    if ( isset( $od[ $k1 ] ) ) $product_totals[ $pid ] += intval( $od[ $k1 ] );
                    elseif ( isset( $od[ $k2 ] ) ) $product_totals[ $pid ] += intval( $od[ $k2 ] );
                }
            }
        }
    }
}

// ZIP data status
$upload = wp_upload_dir();
$zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
$zip_files = is_dir( $zipdata_dir ) ? glob( $zipdata_dir . '*.json' ) : array();
$zip_count = is_array( $zip_files ) ? count( $zip_files ) : 0;
$newest_time = 0;
if ( is_array( $zip_files ) && ! empty( $zip_files ) ) {
    foreach ( $zip_files as $file ) {
        $mtime = filemtime( $file );
        if ( $mtime > $newest_time ) $newest_time = $mtime;
    }
}
$six_months_ago = strtotime( '-6 months' );
$needs_refresh = ( $zip_count > 0 && $newest_time > 0 && $newest_time < $six_months_ago );
$age_text = '';
if ( $newest_time > 0 ) {
    $age_days = floor( ( time() - $newest_time ) / 86400 );
    if ( $age_days < 30 ) {
        $age_text = $age_days . ' day' . ( $age_days != 1 ? 's' : '' ) . ' old';
    } else {
        $age_months = floor( $age_days / 30 );
        $age_text = $age_months . ' month' . ( $age_months != 1 ? 's' : '' ) . ' old';
    }
}

// Get configured ZIP codes
$served_zips = subsales_get_served_zips();
$zips_configured = ! empty( $served_zips );
$zip_array = $served_zips;
?>

<div class="wrap">
    <h1>Subsales Management</h1>
    
    <!-- Sales Mode Toggle and Active Users -->
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
            <span id="activeUserCount" class="subsales-chip" style="background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 13px; cursor: pointer; font-weight: 600; min-width: 24px; text-align: center;" title="Click to view app sessions">0</span>
        </div>
    </div>
    
    <script>
    (function($) {
        // Toggle switch handler
        $('#salesModeToggle').on('change', function() {
            const mode = this.checked ? 'user' : 'legacy';
            updateSalesMode(mode);
        });
        
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
                    location.reload();
                }
            });
        }
        
        // Active users - click to view App Sessions page
        $('#activeUserCount').on('click', function() {
            window.location.href = 'admin.php?page=subsales-pwa-sessions';
        });
        
        // Update active user count with real data
        function updateActiveUserCount() {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'subsales_get_active_sessions_count',
                    nonce: '<?php echo wp_create_nonce( 'subsales_active_sessions' ); ?>'
                },
                success: function(response) {
                    if (response.success && typeof response.data.count !== 'undefined') {
                        $('#activeUserCount').text(response.data.count);
                    }
                }
            });
        }
        
        updateActiveUserCount();
        setInterval(updateActiveUserCount, 30000);
    })(jQuery);
    </script>
    
    <div class="subsales-compact-toggle-wrap">
        <button id="subsales-compact-toggle" class="button">Compact view</button>
        <span class="description">Toggle compact/comfortable dashboard spacing (stored in your browser).</span>
    </div>

    <div class="dashboard-widgets-wrap">
        <div class="metabox-holder subsales-dashboard-grid">
            <script>
            (function(){
                var key = 'subsales_compact';
                function getRoot(){ return document.querySelector('.metabox-holder.subsales-dashboard-grid') || document.querySelector('.subsales-dashboard-grid'); }
                function applyState(state){ var root = getRoot(); if(!root) return; if(state){ root.classList.add('subsales-compact'); } else { root.classList.remove('subsales-compact'); } var btn = document.getElementById('subsales-compact-toggle'); if(btn) btn.textContent = state ? 'Comfortable view' : 'Compact view'; }
                try{ var stored = localStorage.getItem(key) === '1'; applyState(stored); }catch(e){}
                document.addEventListener('DOMContentLoaded', function(){ var btn = document.getElementById('subsales-compact-toggle'); if(!btn) return; btn.addEventListener('click', function(){ try{ var cur = localStorage.getItem(key) === '1'; var next = !cur; localStorage.setItem(key, next ? '1' : '0'); applyState(next); }catch(e){} }); });
            })();
            </script>
            
            <!-- Row 1: Teams, Members, Orders, Address Data -->
            <div class="subsales-top-row">
                <div class="postbox subsales-box">
                    <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-groups" aria-hidden="true"></span> Teams</h2></div>
                    <div class="inside">
                        <div class="stat-container">
                            <p class="stat-value"><?php echo intval( $team_count ); ?></p>
                            <?php if ( $team_count_inactive > 0 ): ?>
                                <p class="stat-inactive"><?php echo intval( $team_count_inactive ); ?> inactive</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="postbox subsales-box">
                    <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-admin-users" aria-hidden="true"></span> Members</h2></div>
                    <div class="inside">
                        <div class="stat-container">
                            <p class="stat-value"><?php echo intval( $member_count ); ?></p>
                            <?php if ( $member_count_inactive > 0 ): ?>
                                <p class="stat-inactive"><?php echo intval( $member_count_inactive ); ?> inactive</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="postbox subsales-box">
                    <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-cart" aria-hidden="true"></span> Orders</h2></div>
                    <div class="inside">
                        <p class="stat-value"><?php echo intval( $order_count ); ?></p>
                    </div>
                </div>
                <div class="postbox subsales-box">
                    <div class="postbox-header">
                        <h2>
                            <span class="ss-icon dashicons dashicons-location-alt" aria-hidden="true"></span> 
                            Address Data
                        </h2>
                    </div>
                    <div class="inside subsales-address-data-inside<?php echo $age_text ? ' has-age' : ''; ?>">
                        <?php if ( ! $zips_configured ) : ?>
                            <p class="subsales-address-data-warning">
                                <span class="dashicons dashicons-warning"></span>
                                No ZIP codes configured
                            </p>
                            <p class="subsales-address-data-action">
                                <a href="<?php echo admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' ); ?>" class="button button-small button-primary">
                                    Configure ZIPs
                                </a>
                            </p>
                        <?php elseif ( $zip_count === 0 ) : ?>
                            <p class="subsales-address-data-warning">
                                <span class="dashicons dashicons-info"></span>
                                <?php echo count( $zip_array ); ?> ZIP<?php echo count( $zip_array ) != 1 ? 's' : ''; ?> configured
                            </p>
                            <p class="subsales-address-data-label" style="margin: 4px 0; font-size: 12px; color: #666;">
                                No data files generated yet
                            </p>
                            <p class="subsales-address-data-action">
                                <a href="<?php echo admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' ); ?>" class="button button-small button-primary">
                                    Generate Data
                                </a>
                            </p>
                        <?php else : ?>
                            <p class="stat-value subsales-address-data-count"><?php echo intval( $zip_count ); ?></p>
                            <p class="subsales-address-data-label">
                                ZIP code<?php echo $zip_count != 1 ? 's' : ''; ?> loaded
                            </p>
                            <?php if ( $age_text ) : ?>
                                <div class="subsales-address-data-age-bar<?php echo $needs_refresh ? ' needs-refresh' : ''; ?>">
                                    <?php if ( $needs_refresh ) : ?>
                                        <span class="dashicons dashicons-warning"></span>
                                    <?php endif; ?>
                                    <?php echo esc_html( $age_text ); ?>
                                </div>
                            <?php endif; ?>
                            <p class="subsales-address-data-action">
                                <a href="<?php echo admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' ); ?>" class="button button-small">
                                    <?php echo $needs_refresh ? 'Regenerate' : 'Manage'; ?>
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Row 2: Product Sales, Donations, Cash, Checks -->
            <div class="subsales-financial-row">
                <div class="postbox subsales-box">
                    <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-cart" aria-hidden="true"></span> Product Sales</h2></div>
                    <div class="inside">
                        <p class="stat-value"><?php echo '$' . number_format( (float) $product_sales_total, 2 ); ?></p>
                    </div>
                </div>
                <div class="postbox subsales-box">
                    <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-heart" aria-hidden="true"></span> Donations</h2></div>
                    <div class="inside">
                        <p class="stat-value"><?php echo '$' . number_format( (float) $donations_total, 2 ); ?></p>
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

            <?php if ( ! empty( $products_conf ) ) : ?>
            <div class="subsales-second-row">
                <?php foreach ( $products_conf as $p ) : ?>
                    <div class="postbox">
                        <div class="postbox-header"><h2><?php echo esc_html( $p['name'] ); ?></h2></div>
                        <div class="inside">
                            <p style="font-size: 36px; font-weight: bold; margin: 0; color: #0073aa; text-align: center;">
                                <?php echo intval( $product_totals[ $p['id'] ] ?? 0 ); ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
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

<style>
/* Toggle Switch Styles */
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
</style>
