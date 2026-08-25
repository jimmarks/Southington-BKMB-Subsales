<?php
/**
 * Delivery Distribution Breakdown Page
 * 
 * Shows detailed breakdown of how orders are distributed across team members
 * for delivery manifest generation.
 * 
 * @package Subsales_Management
 * @since 2.4.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'You do not have sufficient permissions to access this page.' );
}

// Run the distribution logic
global $wpdb;
$orders_table = $wpdb->prefix . 'ss_orders';
$configured_products = order_sync_get_products_config();

// Fetch all non-deleted orders, scoped to the current season
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$orders_table} WHERE deleted = 0 AND season_id = %d ORDER BY id ASC",
    intval( get_option( 'subsales_current_season_id' ) )
), ARRAY_A );

// Parse orders
$parsed_orders = array();
foreach ( $rows as $r ) {
    $od = json_decode( $r['order_data'], true );
    if ( ! is_array( $od ) ) continue;

    $address = ! empty( $r['address'] ) ? $r['address'] : ( ! empty( $od['address'] ) ? $od['address'] : '' );
    
    // Look up if address exists in database (same logic as manifest generation)
    $found_in_db = false;
    if ( ! empty( $address ) ) {
        $parsed = Subsales_Delivery::parse_address( $address );
        if ( $parsed && ! empty( $parsed['house_number'] ) && ! empty( $parsed['street'] ) ) {
            $query = "SELECT COUNT(*) FROM {$wpdb->prefix}ss_addresses 
                      WHERE LOWER(TRIM(street)) = %s 
                      AND LOWER(TRIM(house_number)) = %s";
            $params = array(
                strtolower( trim( $parsed['street'] ) ),
                strtolower( trim( $parsed['house_number'] ) )
            );
            
            if ( ! empty( $parsed['zip'] ) ) {
                $query .= " AND zip = %s";
                $params[] = $parsed['zip'];
            }
            
            $count = $wpdb->get_var( $wpdb->prepare( $query, $params ) );
            $found_in_db = ( $count > 0 );
        }
    }

    $order_entry = array(
        'id' => $r['id'],
        'order_id' => $r['order_id'],
        'team_id' => ! empty( $r['team_id'] ) ? intval( $r['team_id'] ) : 0,
        'entered_by_id' => ! empty( $od['entered_by_id'] ) ? intval( $od['entered_by_id'] ) : ( ! empty( $r['user_id'] ) ? intval( $r['user_id'] ) : 0 ),
        'created_at' => $r['created_at'],
        'order_date' => date( 'Y-m-d', strtotime( $r['created_at'] ) ),
        'address' => $address,
        'customer' => ! empty( $od['customer'] ) ? $od['customer'] : '',
        'found_in_db' => $found_in_db,
        'is_deliverable' => Subsales_Delivery::is_address_deliverable( $address ),
        'products' => array()
    );

    // Parse products
    if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
        foreach ( $od['products'] as $product ) {
            if ( isset( $product['id'] ) && isset( $product['qty'] ) ) {
                $pid = $product['id'];
                $qty = intval( $product['qty'] );
                if ( $qty > 0 ) {
                    $pname = $pid;
                    foreach ( $configured_products as $pconf ) {
                        if ( isset( $pconf['id'] ) && $pconf['id'] === $pid ) {
                            $pname = $pconf['name'];
                            break;
                        }
                    }
                    $order_entry['products'][ $pid ] = array( 'name' => $pname, 'qty' => $qty );
                }
            }
        }
    } else {
        foreach ( $configured_products as $pconf ) {
            if ( ! isset( $pconf['id'] ) ) continue;
            $pid = $pconf['id'];
            $qty = isset( $od[ $pid ] ) ? intval( $od[ $pid ] ) : 0;
            if ( $qty > 0 ) {
                $order_entry['products'][ $pid ] = array( 'name' => $pconf['name'], 'qty' => $qty );
            }
        }
    }

    if ( empty( $order_entry['products'] ) ) {
        continue;
    }

    $parsed_orders[] = $order_entry;
}

// Separate team vs individual orders
// Orders with bad addresses OR not found in database stay with the person who entered them
$team_orders = array();
$individual_orders = array();

foreach ( $parsed_orders as $order ) {
    // Check if address is deliverable and found in database
    $is_deliverable = ! empty( $order['is_deliverable'] );
    $found_in_db = ! empty( $order['found_in_db'] );
    
    // Orders with bad format OR not in database → assign to enterer (matches manifest logic)
    if ( ! $is_deliverable || ! $found_in_db ) {
        $individual_orders[] = $order;
    } elseif ( $order['team_id'] > 0 ) {
        // Valid team order - group by date and team for distribution
        $key = $order['order_date'] . '_' . $order['team_id'];
        if ( ! isset( $team_orders[ $key ] ) ) {
            $team_orders[ $key ] = array(
                'date' => $order['order_date'],
                'team_id' => $order['team_id'],
                'orders' => array()
            );
        }
        $team_orders[ $key ]['orders'][] = $order;
    } else {
        // Individual order (no team) - assign to enterer
        $individual_orders[] = $order;
    }
}

// Distribute team orders to campaign signups with load balancing
$distribution_details = array(); // Track assignment reasoning
$member_load = array(); // Track order count per member for load balancing

// First, count individual/problem orders per member
foreach ( $individual_orders as $order ) {
    $member_id = $order['entered_by_id'];
    if ( $member_id > 0 ) {
        if ( ! isset( $member_load[ $member_id ] ) ) {
            $member_load[ $member_id ] = 0;
        }
        $member_load[ $member_id ]++;
    }
}

foreach ( $team_orders as $key => $group ) {
    $campaign_date = $group['date'];
    $team_id = $group['team_id'];
    $orders = $group['orders'];
    
    // Get team name
    $team = $wpdb->get_row( $wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}ss_teams WHERE id = %d",
        $team_id
    ), ARRAY_A );
    $team_name = $team ? $team['name'] : "Team {$team_id}";
    
    // Find campaign for this date
    $campaign = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, campaign_name FROM {$wpdb->prefix}ss_campaigns WHERE campaign_date = %s",
        $campaign_date
    ), ARRAY_A );
    
    if ( ! $campaign ) {
        foreach ( $orders as $order ) {
            $distribution_details[] = array(
                'order' => $order,
                'assigned_to' => null,
                'reason' => "No campaign found for date {$campaign_date}",
                'source' => "Team: {$team_name}",
                'campaign_date' => $campaign_date
            );
        }
        continue;
    }
    
    $campaign_id = $campaign['id'];
    $campaign_name = $campaign['campaign_name'] ? $campaign['campaign_name'] : $campaign_date;
    
    // Get sales members for this campaign+team. Drivers are excluded — they
    // deliver the manifests and never receive distributed orders.
    $signups = Subsales_Database::get_campaign_team_members( $team_id, $campaign_id );
    
    if ( empty( $signups ) ) {
        foreach ( $orders as $order ) {
            $distribution_details[] = array(
                'order' => $order,
                'assigned_to' => null,
                'reason' => "No active signups for campaign '{$campaign_name}', team '{$team_name}'",
                'source' => "Team: {$team_name}",
                'campaign_date' => $campaign_date
            );
        }
        continue;
    }
    
    $member_ids = array_map( function( $s ) { return intval( $s['id'] ); }, $signups );
    $signup_by_id = array();
    foreach ( $signups as $s ) {
        $signup_by_id[ $s['id'] ] = $s['name'];
    }
    
    // Initialize load for members who don't have problem orders yet
    foreach ( $member_ids as $mid ) {
        if ( ! isset( $member_load[ $mid ] ) ) {
            $member_load[ $mid ] = 0;
        }
    }
    
    // Distribute orders with load balancing (matches manifest logic)
    foreach ( $orders as $order ) {
        // Find member(s) with minimum order count
        $min_load = PHP_INT_MAX;
        foreach ( $member_ids as $mid ) {
            if ( $member_load[ $mid ] < $min_load ) {
                $min_load = $member_load[ $mid ];
            }
        }
        
        $candidates = array();
        foreach ( $member_ids as $mid ) {
            if ( $member_load[ $mid ] === $min_load ) {
                $candidates[] = $mid;
            }
        }
        
        // If multiple tied, pick randomly
        $member_id = $candidates[ array_rand( $candidates ) ];
        $member_name = $signup_by_id[ $member_id ];
        
        $distribution_details[] = array(
            'order' => $order,
            'assigned_to' => $member_id,
            'assigned_name' => $member_name,
            'reason' => "Load-balanced distribution: assigned to member with {$min_load} existing orders",
            'source' => "Campaign: {$campaign_name} ({$campaign_date}), Team: {$team_name}",
            'campaign_date' => $campaign_date
        );
        
        $member_load[ $member_id ]++;
    }
}

// Add individual orders (includes problem addresses and true individual sales)
foreach ( $individual_orders as $order ) {
    $member_id = $order['entered_by_id'];
    
    if ( $member_id > 0 ) {
        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d",
            $member_id
        ), ARRAY_A );
        
        $member_name = $member ? $member['name'] : "Member {$member_id}";
        
        // Determine reason for individual assignment
        $reason = '';
        if ( ! empty( $order['is_deliverable'] ) && ! empty( $order['found_in_db'] ) ) {
            // Valid address, no team → true individual sale
            $reason = "Individual sale - entered by this member (no team)";
        } elseif ( empty( $order['is_deliverable'] ) ) {
            // Bad address format
            $reason = "Team order with bad address format - stays with enterer";
        } else {
            // Address not in database (typo or not geocoded yet)
            $reason = "Team order with address not in database - stays with enterer";
        }
        
        $source = ( $order['team_id'] > 0 ) ? "Team Order (Problem Address)" : "Individual Sale (no team)";
        
        $distribution_details[] = array(
            'order' => $order,
            'assigned_to' => $member_id,
            'assigned_name' => $member_name,
            'reason' => $reason,
            'source' => $source,
            'campaign_date' => $order['order_date']
        );
    } else {
        $distribution_details[] = array(
            'order' => $order,
            'assigned_to' => null,
            'reason' => "Order with no entered_by_id",
            'source' => "Unassignable",
            'campaign_date' => $order['order_date']
        );
    }
}

// Sort by assigned member, then date
usort( $distribution_details, function( $a, $b ) {
    if ( $a['assigned_to'] === null && $b['assigned_to'] === null ) return 0;
    if ( $a['assigned_to'] === null ) return 1;
    if ( $b['assigned_to'] === null ) return -1;
    
    if ( $a['assigned_to'] === $b['assigned_to'] ) {
        return strcmp( $a['campaign_date'], $b['campaign_date'] );
    }
    
    return strcmp( 
        isset( $a['assigned_name'] ) ? $a['assigned_name'] : '', 
        isset( $b['assigned_name'] ) ? $b['assigned_name'] : '' 
    );
} );

// Calculate summary stats
$member_stats = array();
foreach ( $distribution_details as $detail ) {
    if ( $detail['assigned_to'] ) {
        $mid = $detail['assigned_to'];
        if ( ! isset( $member_stats[ $mid ] ) ) {
            $member_stats[ $mid ] = array(
                'name' => $detail['assigned_name'],
                'total_orders' => 0,
                'campaign_orders' => 0,
                'individual_orders' => 0,
                'total_products' => 0
            );
        }
        
        $member_stats[ $mid ]['total_orders']++;
        
        if ( strpos( $detail['source'], 'Campaign' ) !== false ) {
            $member_stats[ $mid ]['campaign_orders']++;
        } else {
            $member_stats[ $mid ]['individual_orders']++;
        }
        
        foreach ( $detail['order']['products'] as $product ) {
            $member_stats[ $mid ]['total_products'] += $product['qty'];
        }
    }
}

?>

<div class="wrap">
    <h1>📊 Delivery Distribution Breakdown</h1>
    <p class="description">This shows how orders will be distributed to team members when generating delivery manifests. Distribution is based on campaign signups and individual sales.</p>
    
    <a href="<?php echo admin_url( 'admin.php?page=subsales-delivery' ); ?>" class="button" style="margin-bottom:20px;">← Back to Delivery</a>
    
    <h2>Summary by Member</h2>
    <table class="widefat" style="margin-bottom:30px;">
        <thead>
            <tr>
                <th>Member Name</th>
                <th style="text-align:center;">Campaign Orders</th>
                <th style="text-align:center;">Individual Orders</th>
                <th style="text-align:center;">Total Orders</th>
                <th style="text-align:center;">Total Products</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $member_stats ) ): ?>
                <tr><td colspan="5" style="text-align:center;padding:20px;">No orders assigned to members</td></tr>
            <?php else: ?>
                <?php foreach ( $member_stats as $mid => $stats ): ?>
                    <tr>
                        <td><strong><?php echo esc_html( $stats['name'] ); ?></strong></td>
                        <td style="text-align:center;"><?php echo intval( $stats['campaign_orders'] ); ?></td>
                        <td style="text-align:center;"><?php echo intval( $stats['individual_orders'] ); ?></td>
                        <td style="text-align:center;"><strong><?php echo intval( $stats['total_orders'] ); ?></strong></td>
                        <td style="text-align:center;"><?php echo intval( $stats['total_products'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <h2>Detailed Order Distribution</h2>
    <p class="description">Every order with its assignment reasoning</p>
    
    <table class="widefat striped">
        <thead>
            <tr>
                <th style="width:120px;">Order ID</th>
                <th style="width:100px;">Date</th>
                <th>Delivery Address</th>
                <th>Products</th>
                <th>Assigned To</th>
                <th>Source</th>
                <th>Distribution Reason</th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $distribution_details ) ): ?>
                <tr><td colspan="7" style="text-align:center;padding:20px;">No orders found</td></tr>
            <?php else: ?>
                <?php 
                $current_member = null;
                foreach ( $distribution_details as $detail ): 
                    $order = $detail['order'];
                    $products_str = array();
                    foreach ( $order['products'] as $prod ) {
                        $products_str[] = $prod['name'] . ' ×' . $prod['qty'];
                    }
                    
                    // Add separator row when changing members
                    if ( $current_member !== $detail['assigned_to'] && $current_member !== null ) {
                        echo '<tr style="background:#f0f0f0;height:8px;"><td colspan="7"></td></tr>';
                    }
                    $current_member = $detail['assigned_to'];
                ?>
                    <tr style="<?php echo $detail['assigned_to'] === null ? 'background:#fff3cd;' : ''; ?>">
                        <td><code><?php echo esc_html( $order['order_id'] ); ?></code></td>
                        <td><?php echo esc_html( $detail['campaign_date'] ); ?></td>
                        <td>
                            <?php echo esc_html( $order['address'] ); ?>
                            <?php if ( $order['customer'] ): ?>
                                <br><small style="color:#666;">Customer: <?php echo esc_html( $order['customer'] ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo esc_html( implode( ', ', $products_str ) ); ?></small></td>
                        <td>
                            <?php if ( $detail['assigned_to'] ): ?>
                                <strong><?php echo esc_html( $detail['assigned_name'] ); ?></strong>
                            <?php else: ?>
                                <span style="color:#d63638;">⚠️ UNASSIGNED</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo esc_html( $detail['source'] ); ?></small></td>
                        <td><small><?php echo esc_html( $detail['reason'] ); ?></small></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <div style="margin-top:30px; padding:15px; background:#fff; border-left:4px solid #2271b1;">
        <h3 style="margin-top:0;">Distribution Logic Explanation</h3>
        <ul style="margin-bottom:0;">
            <li><strong>Address Validation:</strong> Orders with bad addresses or addresses not in database are assigned to whoever entered them (not distributed via campaign)</li>
            <li><strong>Campaign Orders:</strong> Orders with team_id > 0 AND valid addresses are distributed across members who signed up for that campaign date</li>
            <li><strong>Load Balancing:</strong> Orders are distributed with load balancing - always assigned to the member with the fewest total orders (including their problem orders)</li>
            <li><strong>Individual Sales:</strong> Orders with no team are assigned to whoever entered them (entered_by_id)</li>
            <li><strong>Unassigned Orders:</strong> Highlighted in yellow - no campaign found or no signups for that campaign</li>
        </ul>
    </div>
    
    <p style="margin-top:20px;">
        <a href="<?php echo admin_url( 'admin.php?page=subsales-delivery' ); ?>" class="button button-primary">← Back to Delivery</a>
    </p>
</div>
