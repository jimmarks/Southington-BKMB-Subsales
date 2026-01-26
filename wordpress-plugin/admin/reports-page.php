<?php
/**
 * Reports Page - Team Sales Report
 * 
 * Detailed sales report showing:
 * - Date → Team → Person breakdown
 * - Total Sales and Donations per person
 * - Points calculation based on settings
 * - Column filtering, print view, CSV export
 * 
 * @package Subsales_Management
 * @since 2.2.1.210
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$orders_table = $wpdb->prefix . 'ss_orders';
$teams_table = $wpdb->prefix . 'ss_teams';
$members_table = $wpdb->prefix . 'ss_team_members';

// Get points calculation settings
$points_mode = get_option( 'subsales_points_mode', 'dollar' );
$points_denomination = floatval( get_option( 'subsales_points_denomination', 1.0 ) );
$points_distribution = get_option( 'subsales_points_distribution', 'individual' );
$donation_bonus_enabled = get_option( 'subsales_donation_bonus_enabled', 0 );
$donation_percentage = floatval( get_option( 'subsales_donation_percentage', 50.0 ) );
$donation_distribution = get_option( 'subsales_donation_distribution', 'team' );

// CSV export is now handled via admin-post action (see subsales_export_team_sales_report in main file)

// Build report data
$report_data = subsales_build_team_sales_report( 
    $points_mode, 
    $points_denomination,
    $points_distribution,
    $donation_bonus_enabled,
    $donation_percentage,
    $donation_distribution
);

// Check if print view
$is_print = isset( $_GET['print'] ) && $_GET['print'] === '1';

// Function is defined in main plugin file (subsales-management.php)
// Kept here with function_exists check for backwards compatibility
if ( ! function_exists( 'subsales_build_team_sales_report' ) ) :
/**
 * Build team sales report data
 * 
 * @param string $points_mode 'dollar' or 'order'
 * @param float $points_denomination Multiplier for points calculation
 * @param string $points_distribution 'individual' or 'team'
 * @param int $donation_bonus_enabled 1 or 0
 * @param float $donation_percentage Percentage of donations to add as bonus points
 * @param string $donation_distribution 'individual' or 'team'
 */
function subsales_build_team_sales_report( 
    $points_mode = 'dollar', 
    $points_denomination = 1.0,
    $points_distribution = 'individual',
    $donation_bonus_enabled = 0,
    $donation_percentage = 50.0,
    $donation_distribution = 'team'
) {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'ss_orders';
    $teams_table = $wpdb->prefix . 'ss_teams';
    $members_table = $wpdb->prefix . 'ss_team_members';
    $signups_table = $wpdb->prefix . 'ss_signups';
    $campaigns_table = $wpdb->prefix . 'ss_campaigns';
    $products_config = order_sync_get_products_config();
    
    // Get all non-deleted orders with necessary fields
    $orders = $wpdb->get_results(
        "SELECT id, order_data, created_at, team_id, user_id FROM {$orders_table} WHERE deleted = 0 ORDER BY created_at DESC",
        ARRAY_A
    );
    
    // Get all active signups with campaign dates to ensure everyone signed up gets included
    $signups = $wpdb->get_results(
        "SELECT s.user_id, s.team_id, 
                c.campaign_date as date,
                t.name as team_name,
                u.name as person_name
         FROM {$signups_table} s
         JOIN {$campaigns_table} c ON s.campaign_id = c.id
         JOIN {$teams_table} t ON s.team_id = t.id
         JOIN {$members_table} u ON s.user_id = u.id
         WHERE s.status = 'active'
         ORDER BY c.campaign_date, t.name, u.name",
        ARRAY_A
    );
    
    // First pass: Initialize all signed-up people with zero values
    // This ensures everyone who signed up appears in the report, even if they didn't enter orders
    $aggregated_data = array();
    $team_totals = array(); // [date|team_name] = ['product_quantity' => X, 'donations' => Y, 'members' => []]
    
    foreach ( $signups as $signup ) {
        $date = $signup['date'];
        $team_name = $signup['team_name'];
        $person_name = $signup['person_name'];
        
        // Create unique key for aggregation: date + team + person
        $key = $date . '|' . $team_name . '|' . $person_name;
        
        // Initialize with zero values - will be updated if they have orders
        if ( ! isset( $aggregated_data[ $key ] ) ) {
            $aggregated_data[ $key ] = array(
                'date' => $date,
                'team_name' => $team_name,
                'person_name' => $person_name,
                'product_quantity' => 0,
                'total_donations' => 0.0,
                'order_count' => 0
            );
        }
        
        // Initialize team totals
        $team_key = $date . '|' . $team_name;
        if ( ! isset( $team_totals[ $team_key ] ) ) {
            $team_totals[ $team_key ] = array(
                'product_quantity' => 0,
                'donations' => 0.0,
                'members' => array()
            );
        }
        
        // Track all signed-up members (not just those with orders)
        if ( ! in_array( $person_name, $team_totals[ $team_key ]['members'] ) ) {
            $team_totals[ $team_key ]['members'][] = $person_name;
        }
    }
    
    // Second pass: aggregate order data for people who actually entered orders
    foreach ( $orders as $order ) {
        $order_data = json_decode( $order['order_data'], true );
        if ( ! is_array( $order_data ) ) continue;
        
        // Get date
        $date = date( 'Y-m-d', strtotime( $order['created_at'] ) );
        
        // Get team name and ID
        $team_id = intval( $order['team_id'] );
        $team_name = 'Unknown Team';
        if ( $team_id === -1 ) {
            // Individual selling mode - not part of a team
            $team_name = 'Individual';
        } elseif ( $team_id > 0 ) {
            $team_name_result = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$teams_table} WHERE id = %d",
                $team_id
            ) );
            if ( $team_name_result ) {
                $team_name = $team_name_result;
            }
        }
        
        // Get person name
        $user_id = intval( $order['user_id'] );
        $person_name = 'Unknown Person';
        if ( $user_id > 0 ) {
            $person_name_result = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$members_table} WHERE id = %d",
                $user_id
            ) );
            if ( $person_name_result ) {
                $person_name = $person_name_result;
            }
        }
        // Fallback to order_data
        if ( $person_name === 'Unknown Person' ) {
            if ( isset( $order_data['customerName'] ) && ! empty( $order_data['customerName'] ) ) {
                $person_name = $order_data['customerName'];
            } elseif ( isset( $order_data['entered_by_name'] ) && ! empty( $order_data['entered_by_name'] ) ) {
                $person_name = $order_data['entered_by_name'];
            }
        }
        
        // Create unique key for aggregation: date + team + person
        $key = $date . '|' . $team_name . '|' . $person_name;
        
        // Initialize if not exists (for people not in signup system - backwards compatibility)
        if ( ! isset( $aggregated_data[ $key ] ) ) {
            $aggregated_data[ $key ] = array(
                'date' => $date,
                'team_name' => $team_name,
                'person_name' => $person_name,
                'product_quantity' => 0,
                'total_donations' => 0.0,
                'order_count' => 0,
                'team_id' => $team_id
            );
        }
        
        // Calculate product quantity for this order
        $order_product_qty = 0;
        if ( isset( $order_data['products'] ) && is_array( $order_data['products'] ) ) {
            foreach ( $order_data['products'] as $product ) {
                $qty = isset( $product['qty'] ) ? intval( $product['qty'] ) : 0;
                $order_product_qty += $qty;
            }
        } else {
            // Legacy format
            foreach ( $products_config as $prod ) {
                $pid = $prod['id'];
                $qty = 0;
                
                if ( isset( $order_data[ $pid . 'Qty' ] ) ) {
                    $qty = intval( $order_data[ $pid . 'Qty' ] );
                } elseif ( isset( $order_data[ $pid . '_qty' ] ) ) {
                    $qty = intval( $order_data[ $pid . '_qty' ] );
                }
                
                $order_product_qty += $qty;
            }
        }
        
        // Get donations for this order
        $order_donations = isset( $order_data['donationAmount'] ) ? floatval( $order_data['donationAmount'] ) : 0.0;
        
        // Aggregate individual data
        $aggregated_data[ $key ]['product_quantity'] += $order_product_qty;
        $aggregated_data[ $key ]['total_donations'] += $order_donations;
        $aggregated_data[ $key ]['order_count']++;
        
        // Track team totals for team distribution
        $team_key = $date . '|' . $team_name;
        if ( ! isset( $team_totals[ $team_key ] ) ) {
            $team_totals[ $team_key ] = array(
                'product_quantity' => 0,
                'donations' => 0.0,
                'members' => array()
            );
        }
        $team_totals[ $team_key ]['product_quantity'] += $order_product_qty;
        $team_totals[ $team_key ]['donations'] += $order_donations;
        
        // Track member (if not already tracked from signups)
        if ( ! in_array( $person_name, $team_totals[ $team_key ]['members'] ) ) {
            $team_totals[ $team_key ]['members'][] = $person_name;
        }
    }
    
    // Third pass: calculate points based on distribution settings
    $report_rows = array();
    foreach ( $aggregated_data as $row ) {
        $date = $row['date'];
        $team_name = $row['team_name'];
        $person_name = $row['person_name'];
        $team_key = $date . '|' . $team_name;
        
        // Calculate base points (always based on product quantity)
        $points = 0.0;
        $is_individual_mode = isset( $row['team_id'] ) && $row['team_id'] === -1;
        
        if ( $is_individual_mode || $points_distribution === 'individual' ) {
            // Individual-based: each person gets points for their own products
            // This applies when: (1) team_id = -1 (individual selling), OR (2) distribution setting is individual
            $points = $row['product_quantity'] * $points_denomination;
        } else {
            // Team-based: divide team total by number of members
            $team_data = $team_totals[ $team_key ];
            $member_count = count( $team_data['members'] );
            
            if ( $member_count > 0 ) {
                // Points per product, divided by team members
                $team_points = $team_data['product_quantity'] * $points_denomination;
                $points = $team_points / $member_count;
            }
        }
        
        // Calculate donation bonus if enabled (dollar value becomes point value)
        $donation_bonus = 0.0;
        if ( $donation_bonus_enabled ) {
            if ( $is_individual_mode || $donation_distribution === 'individual' ) {
                // Individual donation bonus: dollar value at percentage becomes point value
                // This applies when: (1) team_id = -1 (individual selling), OR (2) distribution setting is individual
                $donation_bonus = $row['total_donations'] * ( $donation_percentage / 100.0 );
            } else {
                // Team-based donation bonus: split team donations by members
                $team_data = $team_totals[ $team_key ];
                $member_count = count( $team_data['members'] );
                
                if ( $member_count > 0 ) {
                    // Dollar value at percentage becomes point value
                    $donation_bonus = ( $team_data['donations'] * ( $donation_percentage / 100.0 ) ) / $member_count;
                }
            }
        }
        
        // Total points = base points + donation bonus
        $total_points = $points + $donation_bonus;
        
        // Build tooltip breakdown
        $tooltip_parts = array();
        
        // Show product calculation
        if ( $is_individual_mode || $points_distribution === 'individual' ) {
            $tooltip_parts[] = sprintf(
                'Product Points: %s products × %s = %s',
                number_format( $row['product_quantity'], 0 ),
                number_format( $points_denomination, 2 ),
                number_format( $points, 2 )
            );
        } else {
            $team_data = $team_totals[ $team_key ];
            $member_count = count( $team_data['members'] );
            $tooltip_parts[] = sprintf(
                'Product Points: %s products × %s ÷ %d members = %s',
                number_format( $team_data['product_quantity'], 0 ),
                number_format( $points_denomination, 2 ),
                $member_count,
                number_format( $points, 2 )
            );
        }
        
        // Show donation calculation if enabled
        if ( $donation_bonus_enabled && $donation_bonus > 0 ) {
            if ( $is_individual_mode || $donation_distribution === 'individual' ) {
                $tooltip_parts[] = sprintf(
                    'Donation Bonus: $%s × %s%% = %s',
                    number_format( $row['total_donations'], 2 ),
                    number_format( $donation_percentage, 1 ),
                    number_format( $donation_bonus, 2 )
                );
            } else {
                $team_data = $team_totals[ $team_key ];
                $member_count = count( $team_data['members'] );
                $tooltip_parts[] = sprintf(
                    'Donation Bonus: $%s × %s%% ÷ %d members = %s',
                    number_format( $team_data['donations'], 2 ),
                    number_format( $donation_percentage, 1 ),
                    $member_count,
                    number_format( $donation_bonus, 2 )
                );
            }
        }
        
        $tooltip_parts[] = sprintf( 'Total: %s points', number_format( $total_points, 2 ) );
        $tooltip = implode( '\n', $tooltip_parts );
        
        $report_rows[] = array(
            'date' => $row['date'],
            'team_name' => $row['team_name'],
            'person_name' => $row['person_name'],
            'product_quantity' => $row['product_quantity'],
            'total_donations' => $row['total_donations'],
            'points' => $total_points,
            'order_count' => $row['order_count'],
            'points_tooltip' => $tooltip
        );
    }
    
    // Sort by date (descending), then by team name (ascending)
    usort( $report_rows, function( $a, $b ) {
        // Primary sort: date (most recent first)
        $date_cmp = strcmp( $b['date'], $a['date'] );
        if ( $date_cmp !== 0 ) {
            return $date_cmp;
        }
        // Secondary sort: team name (alphabetical)
        return strcmp( $a['team_name'], $b['team_name'] );
    } );
    
    return $report_rows;
}
endif; // function_exists check

?>

<?php if ( $is_print ): ?>
<!DOCTYPE html>
<html>
<head>
    <title>Team Sales Report - <?php echo date( 'Y-m-d' ); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { font-size: 24px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; font-size: 12px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .text-right { text-align: right; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="no-print" style="margin-bottom: 20px; padding: 10px 20px;">Print Report</button>
    <h1>Team Sales Report</h1>
    <p>
        Generated: <?php echo date( 'F j, Y g:i a' ); ?><br>
        <strong>Points:</strong> <?php echo number_format( $points_denomination, 2 ); ?> per <?php echo ucfirst( $points_mode ); ?> (<?php echo ucfirst( $points_distribution ); ?> distribution)
        <?php if ( $donation_bonus_enabled ): ?>
            | <strong>Donation Bonus:</strong> <?php echo number_format( $donation_percentage, 1 ); ?>% (<?php echo ucfirst( $donation_distribution ); ?> distribution)
        <?php endif; ?>
    </p>
    
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Team</th>
                <th>Person</th>
                <th class="text-right">Points</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $report_data as $row ): ?>
                <tr>
                    <td><?php echo esc_html( $row['date'] ); ?></td>
                    <td><?php echo esc_html( $row['team_name'] ); ?></td>
                    <td><?php echo esc_html( $row['person_name'] ); ?></td>
                    <td class="text-right" title="<?php echo esc_attr( $row['points_tooltip'] ); ?>"><strong><?php echo number_format( $row['points'], 2 ); ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
<?php exit; endif; ?>

<div class="wrap">
    <h1>Team Sales Report</h1>
    
    <div class="subsales-report-actions" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
        <div style="display: flex; gap: 10px; align-items: center;">
            <strong>Export Options:</strong>
            <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_team_sales_report&_wpnonce=' . wp_create_nonce( 'subsales_export_report' ) ) ); ?>" class="button">
                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export to CSV
            </a>
            <a href="<?php echo add_query_arg( array( 'page' => 'subsales-reports', 'print' => '1' ), admin_url( 'admin.php' ) ); ?>" class="button" target="_blank">
                <span class="dashicons dashicons-printer" style="margin-top: 3px;"></span> Print View
            </a>
            <span style="margin-left: auto; color: #666; font-size: 13px;">
                <strong>Points:</strong> <?php echo number_format( $points_denomination, 2 ); ?> per Product
                (<?php echo ucfirst( $points_distribution ); ?> distribution)
                <?php if ( $donation_bonus_enabled ): ?>
                    | <strong>Donation Bonus:</strong> <?php echo number_format( $donation_percentage, 1 ); ?>%
                    (<?php echo ucfirst( $donation_distribution ); ?>)
                <?php endif; ?>
                <br>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-settings' ); ?>" style="font-size: 12px;">Change in settings</a>
            </span>
        </div>
    </div>
    
    <div class="postbox">
        <div class="inside">
            <table id="subsales-report-table" class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th data-column="date" style="width: 100px;">Date</th>
                        <th data-column="team">Team</th>
                        <th data-column="person">Person</th>
                        <th data-column="points" style="width: 120px; text-align: right;">Points</th>
                    </tr>
                    <tr class="filter-row">
                        <th><input type="date" class="column-filter" data-column="date" style="width: 100%;"></th>
                        <th>
                            <select class="column-filter" data-column="team" style="width: 100%;">
                                <option value="">All Teams</option>
                                <?php 
                                $teams = array_unique( array_column( $report_data, 'team_name' ) );
                                sort( $teams );
                                foreach ( $teams as $team ): 
                                ?>
                                    <option value="<?php echo esc_attr( $team ); ?>"><?php echo esc_html( $team ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th>
                            <select class="column-filter" data-column="person" style="width: 100%;">
                                <option value="">All People</option>
                                <?php 
                                $people = array_unique( array_column( $report_data, 'person_name' ) );
                                sort( $people );
                                foreach ( $people as $person ): 
                                ?>
                                    <option value="<?php echo esc_attr( $person ); ?>"><?php echo esc_html( $person ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $report_data ) ): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px;">
                                No sales data available. Orders must have team and user assignments.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ( $report_data as $row ): ?>
                            <tr>
                                <td><?php echo esc_html( $row['date'] ); ?></td>
                                <td><?php echo esc_html( $row['team_name'] ); ?></td>
                                <td><?php echo esc_html( $row['person_name'] ); ?></td>
                                <td style="text-align: right; cursor: help;" title="<?php echo esc_attr( $row['points_tooltip'] ); ?>"><strong><?php echo number_format( $row['points'], 2 ); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.filter-row th {
    padding: 5px;
    background-color: #f9f9f9;
}
.column-filter {
    padding: 4px 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 13px;
}
#subsales-report-table tbody tr.hidden {
    display: none;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Column filtering
    function applyFilters() {
        var filters = {};
        
        // Collect all filter values
        $('.column-filter').each(function() {
            var column = $(this).data('column');
            var value = $(this).val();
            if (value) {
                filters[column] = value.toLowerCase();
            }
        });
        
        // Filter rows
        $('#subsales-report-table tbody tr').each(function() {
            var $row = $(this);
            var show = true;
            
            // Check each filter
            $.each(filters, function(column, filterValue) {
                var columnIndex = $('th[data-column="' + column + '"]').index();
                var cellText = $row.find('td').eq(columnIndex).text().toLowerCase();
                
                if (cellText.indexOf(filterValue) === -1) {
                    show = false;
                    return false; // break loop
                }
            });
            
            if (show) {
                $row.removeClass('hidden');
            } else {
                $row.addClass('hidden');
            }
        });
    }
    
    // Bind events to filters
    $('.column-filter').on('change keyup', applyFilters);
});
</script>
