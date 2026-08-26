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
$current_season_id = intval( get_option( 'subsales_current_season_id' ) );
$order_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$orders_table} WHERE deleted = 0 AND season_id = %d", $current_season_id ) );
$team_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table} WHERE status = 'active'" );
$team_count_inactive = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table} WHERE status = 'inactive'" );
$member_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table} WHERE status = 'active'" );
$member_count_inactive = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table} WHERE status = 'inactive'" );

// Helper function to compute financials with optional date filter
function subsales_compute_financials( $where_clause = "deleted = 0" ) {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'ss_orders';
    $season_id = intval( get_option( 'subsales_current_season_id' ) );

    $product_sales_total = 0.0;
    $donations_total = 0.0;
    $cash_total = 0.0;
    $check_total = 0.0;
    $order_count = 0;

    // Always scoped to the current season, regardless of what the caller's
    // where_clause otherwise filters on.
    $rows_fin = $wpdb->get_results( "SELECT order_data, created_at FROM {$orders_table} WHERE ({$where_clause}) AND season_id = {$season_id}", ARRAY_A );
    if ( $rows_fin ) {
        $conf_prods_for_fin = order_sync_get_products_config();
        foreach ( $rows_fin as $rf ) {
            $od = json_decode( $rf['order_data'], true );
            if ( ! is_array( $od ) ) continue;
            $order_count++;
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
    
    return array(
        'product_sales' => $product_sales_total,
        'donations' => $donations_total,
        'cash' => $cash_total,
        'checks' => $check_total,
        'total_revenue' => $product_sales_total + $donations_total,
        'order_count' => $order_count
    );
}

// Compute overall financials
$overall = subsales_compute_financials( "deleted = 0" );

// Compute today's financials (midnight to midnight in WordPress timezone)
$today_start = current_time( 'Y-m-d 00:00:00' );
$today = subsales_compute_financials( $wpdb->prepare( "deleted = 0 AND created_at >= %s", $today_start ) );

// Compute product totals (overall and today)
$products_conf = order_sync_get_products_config();
$product_totals_overall = array();
$product_totals_today = array();
foreach ( $products_conf as $p ) {
    $product_totals_overall[ $p['id'] ] = 0;
    $product_totals_today[ $p['id'] ] = 0;
}
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_data, created_at FROM {$orders_table} WHERE deleted = 0 AND season_id = %d", $current_season_id ), ARRAY_A );
if ( $rows ) {
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        $is_today = ( strtotime( $r['created_at'] ) >= strtotime( $today_start ) );
        if ( is_array( $od ) ) {
            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                foreach ( $od['products'] as $op ) {
                    if ( isset( $op['id'] ) && isset( $op['qty'] ) ) {
                        $pid = $op['id'];
                        $qty = intval( $op['qty'] );
                        if ( isset( $product_totals_overall[ $pid ] ) ) {
                            $product_totals_overall[ $pid ] += $qty;
                            if ( $is_today ) $product_totals_today[ $pid ] += $qty;
                        }
                    }
                }
            } else {
                foreach ( $products_conf as $p ) {
                    $pid = $p['id'];
                    $k1 = $pid . 'Qty'; $k2 = $pid . '_qty';
                    $qty = 0;
                    if ( isset( $od[ $k1 ] ) ) $qty = intval( $od[ $k1 ] );
                    elseif ( isset( $od[ $k2 ] ) ) $qty = intval( $od[ $k2 ] );
                    if ( $qty > 0 ) {
                        $product_totals_overall[ $pid ] += $qty;
                        if ( $is_today ) $product_totals_today[ $pid ] += $qty;
                    }
                }
            }
        }
    }
}

// Compute leaderboards (Top 3 Teams and Top 3 Users)
$team_earnings = array();
$user_earnings = array();

// Get orders with user_id and team_id from database columns
$leaderboard_rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_data, created_at, user_id, team_id FROM {$orders_table} WHERE deleted = 0 AND season_id = %d", $current_season_id ), ARRAY_A );
if ( $leaderboard_rows ) {
    foreach ( $leaderboard_rows as $lr ) {
        $od = json_decode( $lr['order_data'], true );
        if ( ! is_array( $od ) ) continue;
        
        // Calculate order revenue
        $order_revenue = 0.0;
        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $price = isset( $pr['price'] ) ? floatval( $pr['price'] ) : 0.0;
                if ( $qty > 0 ) $order_revenue += $qty * $price;
            }
        }
        if ( isset( $od['donationAmount'] ) ) $order_revenue += floatval( $od['donationAmount'] );
        
        // Get team name from database or order_data
        $team_name = null;
        if ( ! empty( $lr['team_id'] ) ) {
            // Look up team name from database
            $team_row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT name FROM {$wpdb->prefix}ss_teams WHERE id = %d", 
                intval( $lr['team_id'] ) 
            ) );
            if ( $team_row ) {
                $team_name = $team_row->name;
            }
        }
        
        // Fallback to order_data if not in database
        if ( ! $team_name ) {
            $team_name = isset( $od['teamName'] ) ? $od['teamName'] : ( isset( $od['team_name'] ) ? $od['team_name'] : null );
        }
        
        // Default to "Individual Orders" if still no team
        if ( ! $team_name || $team_name === 'Individual' ) {
            $team_name = 'Individual Orders';
        }
        
        if ( ! isset( $team_earnings[ $team_name ] ) ) {
            $team_earnings[ $team_name ] = array( 'overall' => 0.0, 'today' => 0.0 );
        }
        $team_earnings[ $team_name ]['overall'] += $order_revenue;
        if ( strtotime( $lr['created_at'] ) >= strtotime( $today_start ) ) {
            $team_earnings[ $team_name ]['today'] += $order_revenue;
        }
        
        // Get user name from database or order_data
        $user_name = null;
        if ( ! empty( $lr['user_id'] ) ) {
            // Look up user name from database
            $user_row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", 
                intval( $lr['user_id'] ) 
            ) );
            if ( $user_row ) {
                $user_name = $user_row->name;
            }
        }
        
        // Fallback to order_data if not in database
        if ( ! $user_name ) {
            if ( isset( $od['entered_by_name'] ) && ! empty( $od['entered_by_name'] ) ) {
                $user_name = $od['entered_by_name'];
            } elseif ( isset( $od['soldBy'] ) && ! empty( $od['soldBy'] ) ) {
                $user_name = $od['soldBy'];
            } elseif ( isset( $od['sold_by'] ) && ! empty( $od['sold_by'] ) ) {
                $user_name = $od['sold_by'];
            }
        }
        
        // Skip if no user name
        if ( ! $user_name ) continue;
        
        $user_key = $user_name . '|' . $team_name;
        if ( ! isset( $user_earnings[ $user_key ] ) ) {
            $user_earnings[ $user_key ] = array(
                'name' => $user_name,
                'team' => $team_name,
                'overall' => 0.0,
                'today' => 0.0
            );
        }
        $user_earnings[ $user_key ]['overall'] += $order_revenue;
        if ( strtotime( $lr['created_at'] ) >= strtotime( $today_start ) ) {
            $user_earnings[ $user_key ]['today'] += $order_revenue;
        }
    }
}

// Sort and get top 3
uasort( $team_earnings, function( $a, $b ) { return $b['overall'] - $a['overall']; } );
$top_teams = array_slice( $team_earnings, 0, 3, true );

uasort( $user_earnings, function( $a, $b ) { return $b['overall'] - $a['overall']; } );
$top_users = array_slice( $user_earnings, 0, 3 );

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
    
    <!-- Sales Mode Toggle, Today/Overall Toggle, and Active Users -->
    <div class="subsales-mode-controls">
        <div class="subsales-mode-control-item">
            <strong>Sales Mode:</strong>
            <label class="subsales-toggle-switch">
                <input type="checkbox" id="salesModeToggle" <?php checked( get_option( 'subsales_sales_mode', 'legacy' ), 'user' ); ?> />
                <span class="subsales-toggle-slider"></span>
                <span class="subsales-toggle-label-left">Team</span>
                <span class="subsales-toggle-label-right">Individual</span>
            </label>
        </div>
        
        <div class="subsales-mode-control-item">
            <strong>View:</strong>
            <label class="subsales-toggle-switch">
                <input type="checkbox" id="timeRangeToggle" />
                <span class="subsales-toggle-slider subsales-time-slider"></span>
                <span class="subsales-toggle-label-left">Today</span>
                <span class="subsales-toggle-label-right">Overall</span>
            </label>
        </div>
        
        <div class="subsales-mode-control-item">
            <span class="dashicons dashicons-admin-users"></span>
            <strong>Active Users:</strong>
            <span id="activeUserCount" class="subsales-chip" title="Click to view app sessions">0</span>
        </div>
    </div>
    
    <script>
    (function($) {
        // Data embedded from PHP
        const dashboardData = {
            overall: {
                orders: <?php echo intval( $overall['order_count'] ); ?>,
                total_revenue: <?php echo number_format( $overall['total_revenue'], 2, '.', '' ); ?>,
                product_sales: <?php echo number_format( $overall['product_sales'], 2, '.', '' ); ?>,
                donations: <?php echo number_format( $overall['donations'], 2, '.', '' ); ?>,
                cash: <?php echo number_format( $overall['cash'], 2, '.', '' ); ?>,
                checks: <?php echo number_format( $overall['checks'], 2, '.', '' ); ?>,
                products: <?php echo json_encode( $product_totals_overall ); ?>,
                top_teams: <?php 
                    $teams_array = array();
                    foreach ( $top_teams as $name => $data ) {
                        $teams_array[] = array( 'name' => $name, 'revenue' => $data['overall'] );
                    }
                    echo json_encode( $teams_array ); 
                ?>,
                top_users: <?php echo json_encode( array_values( array_map( function( $data ) {
                    return array( 'name' => $data['name'], 'team' => $data['team'], 'revenue' => $data['overall'] );
                }, $top_users ) ) ); ?>
            },
            today: {
                orders: <?php echo intval( $today['order_count'] ); ?>,
                total_revenue: <?php echo number_format( $today['total_revenue'], 2, '.', '' ); ?>,
                product_sales: <?php echo number_format( $today['product_sales'], 2, '.', '' ); ?>,
                donations: <?php echo number_format( $today['donations'], 2, '.', '' ); ?>,
                cash: <?php echo number_format( $today['cash'], 2, '.', '' ); ?>,
                checks: <?php echo number_format( $today['checks'], 2, '.', '' ); ?>,
                products: <?php echo json_encode( $product_totals_today ); ?>,
                top_teams: <?php 
                    $teams_array = array();
                    foreach ( $top_teams as $name => $data ) {
                        $teams_array[] = array( 'name' => $name, 'revenue' => $data['today'] );
                    }
                    echo json_encode( $teams_array ); 
                ?>,
                top_users: <?php echo json_encode( array_values( array_map( function( $data ) {
                    return array( 'name' => $data['name'], 'team' => $data['team'], 'revenue' => $data['today'] );
                }, $top_users ) ) ); ?>
            }
        };
        
        // Sort leaderboards by revenue
        ['overall', 'today'].forEach(function(period) {
            dashboardData[period].top_teams.sort(function(a, b) { return b.revenue - a.revenue; });
            dashboardData[period].top_users.sort(function(a, b) { return b.revenue - a.revenue; });
        });
        
        // Time range toggle handler
        $('#timeRangeToggle').on('change', function() {
            const showOverall = this.checked;
            try {
                localStorage.setItem('subsales_time_range', showOverall ? 'overall' : 'today');
            } catch(e) {}
            updateDashboardView(showOverall);
        });
        
        // Initialize dashboard on page load
        $(document).ready(function() {
            console.log('Initializing dashboard...');
            console.log('Top teams data:', dashboardData.overall.top_teams);
            console.log('Top users data:', dashboardData.overall.top_users);
            
            // Initialize from localStorage
            try {
                const stored = localStorage.getItem('subsales_time_range');
                if (stored === 'overall') {
                    $('#timeRangeToggle').prop('checked', true);
                    updateDashboardView(true);
                } else {
                    $('#timeRangeToggle').prop('checked', false);
                    updateDashboardView(false);
                }
            } catch(e) {
                console.error('Error initializing dashboard:', e);
                updateDashboardView(false);
            }
        });
        
        // Update dashboard view
        function updateDashboardView(showOverall) {
            const period = showOverall ? 'overall' : 'today';
            const data = dashboardData[period];
            
            // Update orders count
            $('#orders-value').text(data.orders);
            
            // Update hero widget (Total Revenue)
            $('#total-revenue-value').text('$' + numberFormat(data.total_revenue));
            $('#product-sales-value').text(numberFormat(data.product_sales));
            $('#donations-value').text(numberFormat(data.donations));
            
            // Update financial widgets
            $('#cash-value').text('$' + numberFormat(data.cash));
            $('#checks-value').text('$' + numberFormat(data.checks));
            
            // Update product quantities
            $.each(data.products, function(productId, qty) {
                $('#product-' + productId + '-value').text(qty);
            });
            
            // Update leaderboards
            updateLeaderboard('#leaderboard-teams', data.top_teams, 'teams');
            updateLeaderboard('#leaderboard-users', data.top_users, 'users');
        }
        
        // Update leaderboard HTML
        function updateLeaderboard(selector, data, type) {
            console.log('Updating leaderboard:', selector, 'with data:', data);
            const $container = $(selector);
            
            if (!$container.length) {
                console.error('Leaderboard container not found:', selector);
                return;
            }
            
            if (!data || !data.length || data.length === 0) {
                console.log('No leaderboard data available for', selector);
                $container.html('<div class="leaderboard-empty">No data available</div>');
                return;
            }
            
            const medals = ['🏆', '🥈', '🥉'];
            const colors = ['#FFD700', '#C0C0C0', '#CD7F32'];
            
            let html = '';
            data.slice(0, 3).forEach(function(item, index) {
                const revenue = parseFloat(item.revenue || 0);
                const name = item.name || 'Unknown';
                
                html += '<div class="leaderboard-item rank-' + (index + 1) + '" style="border-left-color: ' + colors[index] + ';">';
                html += '<span class="leaderboard-rank">' + medals[index] + '</span>';
                html += '<span class="leaderboard-name">' + escapeHtml(name) + '</span>';
                html += '<span class="leaderboard-revenue">$' + numberFormat(revenue) + '</span>';
                html += '</div>';
            });
            
            console.log('Setting leaderboard HTML for', selector);
            $container.html(html);
        }
        
        // Helper functions
        function numberFormat(num) {
            return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        
        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        // Toggle switch handler (existing code)
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
                    nonce: '<?php echo wp_create_nonce( 'subsales_sessions_nonce' ); ?>'
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
                <div class="postbox subsales-box" data-stat="orders">
                    <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-cart" aria-hidden="true"></span> Orders</h2></div>
                    <div class="inside">
                        <p class="stat-value" id="orders-value"><?php echo intval( $order_count ); ?></p>
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
                
                <!-- Addresses Needing Review Card -->
                <?php
                // Replaces the old nightly-validation card. Nothing is blocked by
                // this queue - it is a to-do list the admin works through whenever
                // they feel like it, which is the whole point of the redesign.
                $review_pending = Subsales_Database::count_review_queue_rows( 'pending' );
                $address_page   = admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' );
                ?>
                <div class="postbox subsales-box<?php echo $review_pending > 0 ? ' subsales-box-warning' : ''; ?>">
                    <div class="postbox-header">
                        <h2>
                            <span class="ss-icon dashicons dashicons-location-alt" aria-hidden="true"></span>
                            Addresses to Review
                        </h2>
                    </div>
                    <div class="inside subsales-address-validation-inside">
                        <?php if ( $review_pending > 0 ): ?>
                            <p class="stat-value subsales-validation-count"><?php echo intval( $review_pending ); ?></p>
                            <p class="subsales-address-data-label">
                                address<?php echo $review_pending != 1 ? 'es' : ''; ?> we couldn't place automatically
                            </p>
                            <p class="subsales-address-data-action">
                                <a href="<?php echo esc_url( $address_page ); ?>" class="button button-small button-primary">
                                    Review
                                </a>
                            </p>
                        <?php else: ?>
                            <p class="stat-value subsales-validation-count" style="color:#4caf50;">✓</p>
                            <p class="subsales-address-data-label" style="color:#4caf50;">
                                Nothing waiting for review
                            </p>
                            <p class="subsales-address-data-action">
                                <a href="<?php echo esc_url( $address_page ); ?>" class="button button-small">
                                    Address Data
                                </a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Row 2: Hero Total Revenue -->
            <div class="subsales-hero-row">
                <div class="postbox subsales-box subsales-hero-box" data-stat="total-revenue">
                    <div class="inside">
                        <div class="stat-hero">
                            <span class="stat-value" id="total-revenue-value">$<?php echo number_format( $overall['total_revenue'], 2 ); ?></span>
                        </div>
                        <div class="stat-label-hero">Total Revenue</div>
                        <div class="stat-breakdown">
                            <span>Product Sales: $<span id="product-sales-value"><?php echo number_format( $overall['product_sales'], 2 ); ?></span></span>
                            <span class="breakdown-separator">|</span>
                            <span>Donations: $<span id="donations-value"><?php echo number_format( $overall['donations'], 2 ); ?></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 3: Cash and Checks -->
            <div class="subsales-financial-row">
                <div class="postbox subsales-box" data-stat="cash">
                    <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-money" aria-hidden="true"></span> Cash Collected</h2></div>
                    <div class="inside">
                        <p class="stat-value" id="cash-value">$<?php echo number_format( $overall['cash'], 2 ); ?></p>
                    </div>
                </div>
                <div class="postbox subsales-box" data-stat="checks">
                    <div class="postbox-header"><h2><span class="ss-icon subsales-checkbook-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">
                            <rect x="1" y="4" width="22" height="14" rx="2" />
                            <path d="M4 8h16" />
                            <path d="M6 14l3 3 8-8" />
                        </svg>
                    </span> Checks Collected</h2></div>
                    <div class="inside">
                        <p class="stat-value" id="checks-value">$<?php echo number_format( $overall['checks'], 2 ); ?></p>
                    </div>
                </div>
            </div>

            <!-- Row 4: Leaderboards -->
            <div class="subsales-leaderboards-row">
                <div class="postbox subsales-box subsales-leaderboard-box">
                    <div class="postbox-header"><h2><span class="ss-icon">🏆</span> Top Teams</h2></div>
                    <div class="inside">
                        <div class="leaderboard-container" id="leaderboard-teams">
                            <!-- JavaScript will populate this -->
                        </div>
                    </div>
                </div>
                <div class="postbox subsales-box subsales-leaderboard-box">
                    <div class="postbox-header"><h2><span class="ss-icon">🏆</span> Top Sellers</h2></div>
                    <div class="inside">
                        <div class="leaderboard-container" id="leaderboard-users">
                            <!-- JavaScript will populate this -->
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 5: Product Quantities -->
            <?php if ( ! empty( $products_conf ) ) : ?>
            <div class="subsales-second-row">
                <?php foreach ( $products_conf as $p ) : ?>
                    <div class="postbox" data-stat="product-<?php echo esc_attr( $p['id'] ); ?>">
                        <div class="postbox-header"><h2><?php echo esc_html( $p['name'] ); ?></h2></div>
                        <div class="inside">
                            <p class="stat-value product-quantity-value" id="product-<?php echo esc_attr( $p['id'] ); ?>-value">
                                <?php echo intval( $product_totals_overall[ $p['id'] ] ?? 0 ); ?>
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

<!-- Toggle switch styles now in admin-dashboard.css -->
