<?php
/**
 * Reports Page - Points Report
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
$points_mode = get_option( 'subsales_points_mode', 'product' );
$points_denomination = floatval( get_option( 'subsales_points_denomination', 1.0 ) );
$points_distribution = get_option( 'subsales_points_distribution', 'individual' );
$donation_bonus_enabled = get_option( 'subsales_donation_bonus_enabled', 0 );
$donation_percentage = floatval( get_option( 'subsales_donation_percentage', 50.0 ) );
$donation_distribution = get_option( 'subsales_donation_distribution', 'team' );

// CSV export is now handled via admin-post action (see subsales_export_team_sales_report in main file)

// Which season to report on. Defaults to the current one; past seasons stay
// reachable, otherwise last year's standings vanish the moment a new season
// starts.
$seasons        = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}ss_seasons ORDER BY id DESC", ARRAY_A );
$current_season = Subsales_Database::current_season_id();
$season_id      = isset( $_GET['season_id'] ) ? intval( $_GET['season_id'] ) : $current_season;
$valid_ids      = array_map( 'intval', wp_list_pluck( $seasons, 'id' ) );
if ( ! in_array( $season_id, $valid_ids, true ) ) {
    $season_id = $current_season;
}

// Build report data using Points Calculator class
$report_data = Subsales_Points_Calculator::build_report(
    $points_mode,
    $points_denomination,
    $points_distribution,
    $donation_bonus_enabled,
    $donation_percentage,
    $donation_distribution,
    $season_id
);

// Season standings: every row is kept so the admin can still see why each day
// scored the way it did, and these are the per-person totals on top of it.
$standings = array();
foreach ( $report_data as $row ) {
    $uid = $row['user_id'];
    if ( ! isset( $standings[ $uid ] ) ) {
        $standings[ $uid ] = array(
            'name' => $row['person_name'], 'points' => 0.0, 'qty' => 0,
            'sales' => 0.0, 'donations' => 0.0, 'orders' => 0, 'days' => 0,
        );
    }
    $standings[ $uid ]['points']    += $row['points'];
    $standings[ $uid ]['qty']       += $row['product_quantity'];
    $standings[ $uid ]['sales']     += $row['sale_value'];
    $standings[ $uid ]['donations'] += $row['total_donations'];
    $standings[ $uid ]['orders']    += $row['order_count'];
    $standings[ $uid ]['days']++;
}
uasort( $standings, function ( $a, $b ) {
    return $b['points'] <=> $a['points'];
} );

// Check if print view
$is_print = isset( $_GET['print'] ) && $_GET['print'] === '1';

// Function is defined in main plugin file (subsales-management.php)
// Kept here with function_exists check for backwards compatibility
// OLD FUNCTION CODE REMOVED - NOW IN class-points-calculator.php
// This code has been moved to the Subsales_Points_Calculator class for better maintainability
// See /includes/class-points-calculator.php

?>

<?php if ( $is_print ): ?>
<!DOCTYPE html>
<html>
<head>
    <title>Points Report - <?php echo date( 'Y-m-d' ); ?></title>
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
    <h1>Points Report</h1>
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
    <h1>Points Report</h1>
    
    <div class="subsales-report-actions" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
        <div style="display: flex; gap: 10px; align-items: center;">
            <strong>Export Options:</strong>
            <a href="<?php echo esc_url( add_query_arg( array(
                'action'   => 'subsales_export_team_sales_report',
                'season_id' => $season_id,
                '_wpnonce' => wp_create_nonce( 'subsales_export_report' ),
            ), admin_url( 'admin-post.php' ) ) ); ?>" class="button">
                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export to CSV
            </a>
            <a href="<?php echo add_query_arg( array( 'page' => 'subsales-reports', 'print' => '1', 'season_id' => $season_id ), admin_url( 'admin.php' ) ); ?>" class="button" target="_blank">
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
    
    <form method="get" style="margin: 0 0 16px;">
        <input type="hidden" name="page" value="subsales-reports" />
        <label for="season_id"><strong>Season:</strong></label>
        <select name="season_id" id="season_id" onchange="this.form.submit()">
            <?php foreach ( $seasons as $s ) : ?>
                <option value="<?php echo esc_attr( $s['id'] ); ?>" <?php selected( $season_id, intval( $s['id'] ) ); ?>>
                    <?php echo esc_html( $s['name'] ? $s['name'] : 'Season ' . $s['id'] ); ?><?php echo intval( $s['id'] ) === $current_season ? ' (current)' : ''; ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="postbox">
        <h2 class="hndle" style="padding:10px 12px;margin:0;">Season standings</h2>
        <div class="inside">
            <p class="description">Each person's total for the season. The day-by-day rows below show how each total was reached.</p>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th style="width:90px;text-align:right;">Days</th>
                        <th style="width:90px;text-align:right;">Items</th>
                        <th style="width:110px;text-align:right;">Sales</th>
                        <th style="width:110px;text-align:right;">Donations</th>
                        <th style="width:90px;text-align:right;">Orders</th>
                        <th style="width:110px;text-align:right;">Points</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $standings as $st ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $st['name'] ); ?></strong></td>
                        <td style="text-align:right;"><?php echo intval( $st['days'] ); ?></td>
                        <td style="text-align:right;"><?php echo intval( $st['qty'] ); ?></td>
                        <td style="text-align:right;">$<?php echo number_format( $st['sales'], 2 ); ?></td>
                        <td style="text-align:right;">$<?php echo number_format( $st['donations'], 2 ); ?></td>
                        <td style="text-align:right;"><?php echo intval( $st['orders'] ); ?></td>
                        <td style="text-align:right;"><strong><?php echo number_format( $st['points'], 2 ); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
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
                        <th data-column="items" style="width: 80px; text-align: right;">Items</th>
                        <th data-column="sales" style="width: 100px; text-align: right;">Sales</th>
                        <th data-column="donations" style="width: 100px; text-align: right;">Donations</th>
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
                        <th></th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $report_data ) ): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                No sales data available. Orders must have team and user assignments.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ( $report_data as $row ): ?>
                            <tr>
                                <td><?php echo esc_html( $row['date'] ); ?></td>
                                <td><?php echo esc_html( $row['team_name'] ); ?></td>
                                <td><?php echo esc_html( $row['person_name'] ); ?></td>
                                <td style="text-align: right;"><?php echo intval( $row['product_quantity'] ); ?></td>
                                <td style="text-align: right;">$<?php echo number_format( $row['sale_value'], 2 ); ?></td>
                                <td style="text-align: right;">$<?php echo number_format( $row['total_donations'], 2 ); ?></td>
                                <td style="text-align: right; cursor: help;" title="<?php echo esc_attr( $row['points_tooltip'] ); ?>">
                                    <strong><?php echo number_format( $row['points'], 2 ); ?></strong>
                                    <?php if ( ! empty( $row['off_roster'] ) ) : ?>
                                        <span title="Sold under a team they were not signed up for that day" style="color:#d63638;font-weight:600;">&#9888;</span>
                                    <?php endif; ?>
                                </td>
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
