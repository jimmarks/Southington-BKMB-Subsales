<?php
/**
 * Delivery Distribution Breakdown
 *
 * Shows how each sale day's orders are divided across the sellers who worked
 * that team that day, and what each member ends up carrying.
 *
 * This screen and the manifest generator run the SAME code
 * (Subsales_Delivery::parse_orders_for_delivery() and ::distribute_orders()).
 * They used to be two copies of one algorithm, which meant the screen an admin
 * checked for fairness showed a split nobody actually received.
 *
 * @package Subsales_Management
 * @since 2.4.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'You do not have sufficient permissions to access this page.' );
}

global $wpdb;

$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}ss_orders WHERE deleted = 0 AND season_id = %d ORDER BY id ASC",
    Subsales_Database::current_season_id()
), ARRAY_A );

$parsed = Subsales_Delivery::parse_orders_for_delivery( $rows, order_sync_get_products_config() );
$dist   = Subsales_Delivery::distribute_orders( $parsed );

$member_orders = $dist['by_member'];
$groups        = $dist['groups'];
$unassigned    = $dist['unassigned'];

usort( $groups, function ( $a, $b ) {
    return $a['date'] === $b['date'] ? $a['team_id'] - $b['team_id'] : strcmp( $a['date'], $b['date'] );
} );

// Per-member totals, split into what came from team days and what they sold alone.
$member_stats = array();
foreach ( $member_orders as $mid => $orders ) {
    $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", $mid ) );
    $team = 0;
    $solo = 0;
    foreach ( $orders as $o ) {
        if ( $o['team_id'] > 0 ) { $team++; } else { $solo++; }
    }
    $member_stats[ $mid ] = array(
        'name'  => $name ? $name : "Member {$mid}",
        'team'  => $team,
        'solo'  => $solo,
        'total' => count( $orders ),
    );
}
uasort( $member_stats, function ( $a, $b ) { return $b['total'] - $a['total']; } );

// A team-day is uneven when anyone is more than one order away from anyone else.
// Round-robin can only ever leave a gap of 1, so anything above that is a bug.
$uneven = 0;
foreach ( $groups as $g ) {
    if ( $g['per_member'] && ( max( $g['per_member'] ) - min( $g['per_member'] ) ) > 1 ) { $uneven++; }
}
$team_day_orders = array_sum( wp_list_pluck( $groups, 'orders' ) );
?>

<div class="wrap subsales-breakdown">
    <h1>📊 Delivery Distribution Breakdown</h1>
    <p class="description">
        Exactly what the delivery manifest will produce &mdash; this page runs the same code.
        Each sale day's orders are divided across the sellers who signed up for that team that
        day; drivers are not included, since they sign up to drive rather than to take a share.
        Each seller's own individual sales are then added on top.
    </p>

    <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-delivery' ) ); ?>" class="button">&larr; Back to Delivery</a>

    <div class="bd-tiles">
        <div class="tile"><span class="n"><?php echo esc_html( count( $groups ) ); ?></span><span class="l">Team days</span></div>
        <div class="tile"><span class="n"><?php echo esc_html( number_format( $team_day_orders ) ); ?></span><span class="l">Divided across teams</span></div>
        <div class="tile"><span class="n"><?php echo esc_html( count( $member_stats ) ); ?></span><span class="l">Sellers with deliveries</span></div>
        <div class="tile <?php echo $uneven ? 'bad' : 'good'; ?>">
            <span class="n"><?php echo esc_html( $uneven ); ?></span>
            <span class="l">Uneven team days</span>
            <span class="s"><?php echo $uneven ? 'someone is 2+ orders off a teammate' : 'every team is within one order'; ?></span>
        </div>
        <div class="tile <?php echo $unassigned ? 'bad' : 'good'; ?>">
            <span class="n"><?php echo esc_html( count( $unassigned ) ); ?></span>
            <span class="l">Unassigned</span>
            <span class="s"><?php echo $unassigned ? 'nobody is delivering these' : 'every order has an owner'; ?></span>
        </div>
    </div>

    <h2>How each sale day was divided</h2>
    <p class="description">
        The split each team received. An even division is the point &mdash; with a whole number of
        orders and a whole number of sellers the gap can only ever be one.
    </p>

    <?php if ( empty( $groups ) ) : ?>
        <p><strong>No team days to divide.</strong></p>
    <?php else : ?>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th style="width:110px;">Sale day</th>
                <th style="width:70px;">Team</th>
                <th style="width:80px;">Orders</th>
                <th style="width:90px;">Sellers</th>
                <th style="width:100px;">Even share</th>
                <th>Who got what</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $groups as $g ) :
            $gap = $g['per_member'] ? max( $g['per_member'] ) - min( $g['per_member'] ) : 0;
            ?>
            <tr class="<?php echo $gap > 1 ? 'row-uneven' : ''; ?>">
                <td><?php echo esc_html( $g['date'] ); ?></td>
                <td><?php echo esc_html( $g['team_id'] ); ?></td>
                <td><?php echo esc_html( $g['orders'] ); ?></td>
                <td><?php echo esc_html( count( $g['members'] ) ); ?></td>
                <td><?php echo esc_html( number_format( $g['even_share'], 1 ) ); ?></td>
                <td class="split">
                    <?php foreach ( $g['members'] as $mid ) : ?>
                        <span class="chip">
                            <?php echo esc_html( isset( $g['names'][ $mid ] ) ? $g['names'][ $mid ] : "#{$mid}" ); ?>
                            <strong><?php echo esc_html( $g['per_member'][ $mid ] ); ?></strong>
                        </span>
                    <?php endforeach; ?>
                    <?php if ( $gap > 1 ) : ?>
                        <span class="warn">gap of <?php echo esc_html( $gap ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>What each seller ends up with</h2>
    <p class="description">
        Team deliveries are their share of the days they worked. Their own individual sales are
        added on top, so a seller with a lot of individual orders carries more &mdash; that is the
        intended behaviour, not an uneven split.
    </p>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th>Seller</th>
                <th style="width:130px;text-align:center;">From team days</th>
                <th style="width:150px;text-align:center;">Their own sales</th>
                <th style="width:100px;text-align:center;">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php if ( empty( $member_stats ) ) : ?>
            <tr><td colspan="4" style="text-align:center;padding:20px;">No orders assigned.</td></tr>
        <?php else : ?>
            <?php foreach ( $member_stats as $s ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $s['name'] ); ?></strong></td>
                    <td style="text-align:center;"><?php echo esc_html( $s['team'] ); ?></td>
                    <td style="text-align:center;"><?php echo esc_html( $s['solo'] ); ?></td>
                    <td style="text-align:center;"><strong><?php echo esc_html( $s['total'] ); ?></strong></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ( ! empty( $unassigned ) ) : ?>
        <h2>Nobody is delivering these</h2>
        <p class="description">These orders reached no manifest. Each one is a delivery that will not happen.</p>
        <table class="wp-list-table widefat striped">
            <thead><tr><th style="width:170px;">Order</th><th style="width:40%;">Address</th><th>Why</th></tr></thead>
            <tbody>
            <?php foreach ( $unassigned as $u ) : ?>
                <tr>
                    <td><code><?php echo esc_html( $u['order']['order_id'] ); ?></code></td>
                    <td><?php echo esc_html( $u['order']['address'] ); ?></td>
                    <td><?php echo esc_html( $u['reason'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<style>
.subsales-breakdown .bd-tiles {
    display: grid;
    grid-template-columns: repeat( auto-fit, minmax( 170px, 1fr ) );
    gap: 12px;
    margin: 20px 0 28px;
}
.subsales-breakdown .tile {
    display: flex;
    flex-direction: column;
    gap: 2px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-left: 4px solid #2271b1;
    border-radius: 4px;
    padding: 14px 16px;
}
.subsales-breakdown .tile.good { border-left-color: #00a32a; }
.subsales-breakdown .tile.bad  { border-left-color: #d63638; }
.subsales-breakdown .tile .n {
    font-size: 26px;
    font-weight: 600;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.subsales-breakdown .tile .l { font-weight: 600; }
.subsales-breakdown .tile .s { font-size: 12px; color: #646970; }
.subsales-breakdown h2 { margin-top: 32px; }
.subsales-breakdown .chip {
    display: inline-block;
    background: #f0f0f1;
    border-radius: 12px;
    padding: 2px 10px;
    margin: 2px 4px 2px 0;
    font-size: 12px;
}
.subsales-breakdown .chip strong { margin-left: 5px; font-variant-numeric: tabular-nums; }
.subsales-breakdown .row-uneven { background: #fcf0f1 !important; }
.subsales-breakdown .warn {
    color: #d63638;
    font-weight: 600;
    font-size: 12px;
    margin-left: 6px;
}
.subsales-breakdown td.split { line-height: 1.9; }
</style>
