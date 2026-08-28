<?php
/**
 * Address Coverage Report
 *
 * Pre-flight for the delivery manifest. An order IS a delivery, so the only
 * question that matters is whether we can put each order on a map. This report
 * starts from the address and sorts every order into one of five buckets, then
 * hands the admin a worklist for the one bucket that needs a human.
 *
 * It replaces a read-only version that started from "can I geocode this?",
 * counted 342 donations as coverage failures, and quoted a Google Geocoding
 * bill for addresses that were already in the parcel data.
 *
 * @package Subsales_Management
 * @since 3.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'You do not have permission to view this report.', 'subsales-management' ) );
}

global $wpdb;

$orders_table = $wpdb->prefix . 'ss_orders';
$season_id    = Subsales_Database::current_season_id();
$notice       = null;

/**
 * Rewrite an address across every order that carries it.
 *
 * Addresses repeat (two kids both typed "157 Rethal St"), so a fix is keyed on
 * the address text, not the order - fixing the same typo six times is how an
 * admin gives up on a cleanup tool.
 *
 * @return array{changed:int,error:string}
 */
if ( ! function_exists( 'subsales_coverage_rewrite_address' ) ) :
function subsales_coverage_rewrite_address( $old, $new, $season_id, $reason ) {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'ss_orders';

    $affected = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, order_id, address, order_data FROM {$orders_table}
             WHERE deleted = 0 AND season_id = %d AND address = %s",
            $season_id,
            $old
        ),
        ARRAY_A
    );

    $user    = wp_get_current_user();
    $changed = 0;

    foreach ( $affected as $row ) {
        $before = json_decode( $row['order_data'], true );
        $before = is_array( $before ) ? $before : array();
        $after  = $before;
        $after['address'] = $new;

        $ok = $wpdb->update(
            $orders_table,
            array( 'address' => $new, 'order_data' => wp_json_encode( $after ) ),
            array( 'id' => (int) $row['id'] ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        if ( false === $ok ) {
            // A silent write failure here would report a cleanup that never
            // happened, and the manifest would still be missing the stop.
            return array(
                'changed' => $changed,
                'error'   => 'Database error updating order ' . $row['order_id'] . ': ' . $wpdb->last_error,
            );
        }

        $changed++;
        Subsales_Database::log_order_change(
            (int) $row['id'],
            $row['order_id'],
            $before,
            $after,
            'update',
            $user->ID,
            $user->display_name,
            $reason,
            'admin'
        );
    }

    return array( 'changed' => $changed, 'error' => '' );
}
endif;

/* Manual correction from the worklist. */
if ( isset( $_POST['subsales_fix_address'] ) ) {
    check_admin_referer( 'subsales_fix_address' );

    $old = sanitize_text_field( wp_unslash( $_POST['old_address'] ?? '' ) );
    $new = sanitize_text_field( wp_unslash( $_POST['new_address'] ?? '' ) );

    if ( '' === $new ) {
        $notice = array( 'error', 'A replacement address is required.' );
    } elseif ( $new === $old ) {
        $notice = array( 'error', 'The replacement is identical to the current address.' );
    } else {
        $result = subsales_coverage_rewrite_address( $old, $new, $season_id, 'Address coverage cleanup' );
        $notice = $result['error']
            ? array( 'error', $result['error'] )
            : array( 'success', sprintf( '%d order%s updated to "%s".', $result['changed'], 1 === $result['changed'] ? '' : 's', $new ) );
    }
}

/*
 * Look up an out-of-town address and add it to the book.
 *
 * These will never be in the parcel import, but they are still deliveries. One
 * geocode gives both the normalized address text and coordinates; writing the
 * result into ss_addresses means the manifest and this report pick it up
 * through the same path as every other address, with no second source of
 * coordinates to keep in step.
 */
if ( isset( $_POST['subsales_geocode_address'] ) ) {
    check_admin_referer( 'subsales_geocode_address' );

    $target = sanitize_text_field( wp_unslash( $_POST['old_address'] ?? '' ) );
    $coords = Subsales_Delivery::geocode_address( $target, true );

    if ( ! $coords || empty( $coords['formatted_address'] ) ) {
        $notice = array( 'error', sprintf( 'No usable result for "%s". Correct the address text and try again.', $target ) );
    } elseif ( ! in_array( $coords['location_type'], array( 'ROOFTOP', 'RANGE_INTERPOLATED' ), true ) ) {
        // GEOMETRIC_CENTER and APPROXIMATE mean the geocoder found the street or
        // the town, not the house. Writing that into the book would send a driver
        // to the middle of the road with no way to tell it was a guess.
        $notice = array(
            'error',
            sprintf(
                'Too imprecise to add: "%s" resolved to %s (%s). Correct the address text and try again.',
                $target,
                $coords['formatted_address'],
                strtolower( str_replace( '_', ' ', $coords['location_type'] ) )
            ),
        );
    } else {
        $added = Subsales_Address_Helper::add_to_book(
            $coords['formatted_address'],
            $coords['lat'],
            $coords['lng'],
            'high'
        );

        if ( is_wp_error( $added ) ) {
            $notice = array( 'error', $added->get_error_message() );
        } else {
            $result = subsales_coverage_rewrite_address(
                $target,
                $coords['formatted_address'],
                $season_id,
                'Address coverage geocode'
            );
            $notice = $result['error']
                ? array( 'error', $result['error'] )
                : array(
                    'success',
                    sprintf(
                        'Added %s, %s %s to the address book and updated %d order%s. It will match from now on.',
                        $added['house_number'] . ' ' . $added['street'],
                        $added['city'],
                        $added['zip'],
                        $result['changed'],
                        1 === $result['changed'] ? '' : 's'
                    ),
                );
        }
    }
}

/* ---------------------------------------------------------------- classify */

$orders = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, order_id, address, order_data
         FROM {$orders_table}
         WHERE deleted = 0 AND season_id = %d
         ORDER BY created_at DESC",
        $season_id
    ),
    ARRAY_A
);

$counts  = array( 'matched' => 0, 'investigate' => 0, 'out_of_area' => 0, 'unusable' => 0, 'donation' => 0 );
$grouped = array( 'investigate' => array(), 'out_of_area' => array(), 'unusable' => array() );

foreach ( $orders as $order ) {
    $class  = Subsales_Address_Helper::classify_for_coverage( $order );
    $bucket = $class['bucket'];
    $counts[ $bucket ]++;

    if ( ! isset( $grouped[ $bucket ] ) ) {
        continue;
    }

    $key = $class['address'];
    if ( ! isset( $grouped[ $bucket ][ $key ] ) ) {
        $grouped[ $bucket ][ $key ] = array(
            'address'    => $key,
            'orders'     => array(),
            'sellers'    => array(),
            'suggestion' => 'investigate' === $bucket
                ? Subsales_Address_Helper::suggest_correction( $key )
                : null,
        );
    }
    $grouped[ $bucket ][ $key ]['orders'][] = array(
        'id'  => (int) $order['id'],
        'ref' => $order['order_id'],
    );
    $seller = Subsales_Order_Helper::get_entered_by_name( $order );
    if ( ! empty( $seller ) ) {
        $grouped[ $bucket ][ $key ]['sellers'][ $seller ] = true;
    }
}

$deliveries = count( $orders ) - $counts['donation'];
$routable   = $deliveries > 0 ? round( $counts['matched'] / $deliveries * 100, 1 ) : 0;

/**
 * Build the replacement address we pre-fill into the fix box.
 *
 * The suffix is written the way the parcel data writes it (AV, HOLW, XING), so
 * the corrected address matches on the next run rather than needing a second pass.
 */
if ( ! function_exists( 'subsales_coverage_suggested_text' ) ) :
function subsales_coverage_suggested_text( $address, $suggestion ) {
    if ( ! $suggestion ) {
        return $address;
    }
    $parsed = Subsales_Address_Helper::parse_address( $address );
    $house  = ! empty( $suggestion['house'] ) ? $suggestion['house'] : ( $parsed['house_number'] ?? '' );
    if ( '' === $house ) {
        return $address;
    }
    return trim( $house . ' ' . $suggestion['street'] . ', Southington, CT' );
}
endif;

$confidence_labels = array(
    'confirmed'         => array( 'This exact address is in the book', '#00a32a' ),
    'street_only'       => array( 'Street matches, house number is not in the book', '#dba617' ),
    'house_not_in_book' => array( 'Street is correct, house number is not in the book', '#dba617' ),
);
?>

<div class="wrap subsales-coverage">
    <h1>📍 Address Coverage</h1>
    <p class="description">
        Every order is a delivery. This checks each one against the town parcel data
        and lists the ones a person needs to look at &mdash; run it, clear the worklist,
        then build the delivery manifest.
    </p>

    <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-reports' ) ); ?>" class="button">&larr; Back to Reports</a>

    <?php if ( $notice ) : ?>
        <div class="notice notice-<?php echo esc_attr( $notice[0] ); ?> is-dismissible" style="margin-top:16px;">
            <p><?php echo esc_html( $notice[1] ); ?></p>
        </div>
    <?php endif; ?>

    <div class="coverage-tiles">
        <div class="tile tile-good">
            <span class="tile-num"><?php echo esc_html( number_format( $counts['matched'] ) ); ?></span>
            <span class="tile-label">Matched</span>
            <span class="tile-sub"><?php echo esc_html( $routable ); ?>% of deliveries &middot; ready to route</span>
        </div>
        <div class="tile tile-work">
            <span class="tile-num"><?php echo esc_html( number_format( $counts['investigate'] ) ); ?></span>
            <span class="tile-label">Needs a look</span>
            <span class="tile-sub"><?php echo esc_html( count( $grouped['investigate'] ) ); ?> distinct addresses</span>
        </div>
        <div class="tile tile-away">
            <span class="tile-num"><?php echo esc_html( number_format( $counts['out_of_area'] ) ); ?></span>
            <span class="tile-label">Outside Southington</span>
            <span class="tile-sub">Deliverable, but never in the parcel data</span>
        </div>
        <div class="tile tile-bad">
            <span class="tile-num"><?php echo esc_html( number_format( $counts['unusable'] ) ); ?></span>
            <span class="tile-label">Not an address</span>
            <span class="tile-sub">Needs the seller, not a lookup</span>
        </div>
        <div class="tile tile-mute">
            <span class="tile-num"><?php echo esc_html( number_format( $counts['donation'] ) ); ?></span>
            <span class="tile-label">Donations</span>
            <span class="tile-sub">No delivery &middot; excluded from the percentage</span>
        </div>
    </div>

    <h2>The worklist &mdash; in Southington, no match</h2>
    <p class="description">
        A fix is applied to every order sharing that address. Suffixes are saved the way
        the parcel data writes them (AV, HOLW, XING) so the corrected address matches next run.
    </p>

    <?php if ( empty( $grouped['investigate'] ) ) : ?>
        <p><strong>Nothing to clean up.</strong> Every in-town address matched.</p>
    <?php else : ?>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th style="width:26%;">Address as entered</th>
                <th style="width:8%;">Orders</th>
                <th style="width:16%;">Sellers</th>
                <th style="width:22%;">What we found</th>
                <th style="width:28%;">Fix</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $grouped['investigate'] as $row ) :
            $suggestion = $row['suggestion'];
            $label      = $suggestion && isset( $confidence_labels[ $suggestion['confidence'] ] )
                ? $confidence_labels[ $suggestion['confidence'] ]
                : array( 'No close street in the parcel data', '#d63638' );
            ?>
            <tr>
                <td><code><?php echo esc_html( $row['address'] ); ?></code></td>
                <td><?php echo esc_html( count( $row['orders'] ) ); ?></td>
                <td class="sellers"><?php echo esc_html( implode( ', ', array_keys( $row['sellers'] ) ) ); ?></td>
                <td>
                    <span class="dot" style="background:<?php echo esc_attr( $label[1] ); ?>"></span>
                    <?php echo esc_html( $label[0] ); ?>
                    <?php if ( $suggestion ) : ?>
                        <div class="street-hint"><?php echo esc_html( $suggestion['street'] ); ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <form method="post" class="fix-form">
                        <?php wp_nonce_field( 'subsales_fix_address' ); ?>
                        <input type="hidden" name="old_address" value="<?php echo esc_attr( $row['address'] ); ?>" />
                        <input type="text" name="new_address" class="regular-text"
                               value="<?php echo esc_attr( subsales_coverage_suggested_text( $row['address'], $suggestion ) ); ?>" />
                        <button type="submit" name="subsales_fix_address" value="1" class="button button-primary">Apply</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Outside Southington</h2>
    <p class="description">
        Real addresses in other towns. They are still deliveries, but they will never be in
        the parcel import &mdash; and their doorstep GPS is not usable either, because an
        out-of-town order gets typed in later, from home. <strong>Look up</strong> geocodes the
        address and adds it to the book, after which it matches and routes like any other stop.
    </p>
    <?php if ( empty( $grouped['out_of_area'] ) ) : ?>
        <p>None.</p>
    <?php else : ?>
    <table class="wp-list-table widefat striped">
        <thead>
            <tr>
                <th style="width:30%;">Address as entered</th>
                <th style="width:8%;">Orders</th>
                <th style="width:18%;">Sellers</th>
                <th style="width:44%;">Fix</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $grouped['out_of_area'] as $row ) : ?>
            <tr>
                <td><code><?php echo esc_html( $row['address'] ); ?></code></td>
                <td><?php echo esc_html( count( $row['orders'] ) ); ?></td>
                <td class="sellers"><?php echo esc_html( implode( ', ', array_keys( $row['sellers'] ) ) ); ?></td>
                <td>
                    <form method="post" class="fix-form">
                        <?php wp_nonce_field( 'subsales_geocode_address' ); ?>
                        <input type="hidden" name="old_address" value="<?php echo esc_attr( $row['address'] ); ?>" />
                        <button type="submit" name="subsales_geocode_address" value="1" class="button button-primary">Look&nbsp;up</button>
                    </form>
                    <form method="post" class="fix-form">
                        <?php wp_nonce_field( 'subsales_fix_address' ); ?>
                        <input type="hidden" name="old_address" value="<?php echo esc_attr( $row['address'] ); ?>" />
                        <input type="text" name="new_address" class="regular-text"
                               value="<?php echo esc_attr( $row['address'] ); ?>" />
                        <button type="submit" name="subsales_fix_address" value="1" class="button">Save&nbsp;text</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <h2>Not an address</h2>
    <p class="description">
        Nothing here can be looked up &mdash; <code>N/A</code>, <code>?</code>, a street with no
        number. These need the seller who took the order, and are worth raising at the next
        sale day rather than guessing at. Click an order ID to open it for editing.
    </p>
    <?php if ( empty( $grouped['unusable'] ) ) : ?>
        <p>None.</p>
    <?php else : ?>
    <table class="wp-list-table widefat striped">
        <thead><tr><th style="width:36%;">What was entered</th><th style="width:10%;">Orders</th><th style="width:24%;">Sellers</th><th>Order IDs</th></tr></thead>
        <tbody>
        <?php foreach ( $grouped['unusable'] as $row ) : ?>
            <tr>
                <td><code><?php echo esc_html( '' === $row['address'] ? '(blank)' : $row['address'] ); ?></code></td>
                <td><?php echo esc_html( count( $row['orders'] ) ); ?></td>
                <td class="sellers"><?php echo esc_html( implode( ', ', array_keys( $row['sellers'] ) ) ); ?></td>
                <td class="order-ids">
                    <?php foreach ( $row['orders'] as $i => $o ) : ?>
                        <?php echo $i ? ', ' : ''; ?><a href="<?php
                            // ?edit= takes the database id and opens the order's
                            // edit modal straight away - the address is going to
                            // need retyping, so land where it can be retyped.
                            echo esc_url( add_query_arg(
                                array( 'page' => 'subsales-orders', 'edit' => $o['id'] ),
                                admin_url( 'admin.php' )
                            ) );
                        ?>"><?php echo esc_html( $o['ref'] ); ?></a>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<style>
.subsales-coverage .coverage-tiles {
    display: grid;
    grid-template-columns: repeat( auto-fit, minmax( 180px, 1fr ) );
    gap: 12px;
    margin: 20px 0 28px;
}
.subsales-coverage .tile {
    display: flex;
    flex-direction: column;
    gap: 2px;
    background: #fff;
    border: 1px solid #ccd0d4;
    border-left-width: 4px;
    border-radius: 4px;
    padding: 14px 16px;
}
.subsales-coverage .tile-good { border-left-color: #00a32a; }
.subsales-coverage .tile-work { border-left-color: #dba617; }
.subsales-coverage .tile-away { border-left-color: #2271b1; }
.subsales-coverage .tile-bad  { border-left-color: #d63638; }
.subsales-coverage .tile-mute { border-left-color: #c3c4c7; }
.subsales-coverage .tile-num {
    font-size: 26px;
    font-weight: 600;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}
.subsales-coverage .tile-label { font-weight: 600; }
.subsales-coverage .tile-sub { font-size: 12px; color: #646970; }
.subsales-coverage h2 { margin-top: 32px; }
.subsales-coverage .dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-right: 6px;
    vertical-align: baseline;
}
.subsales-coverage .street-hint {
    font-family: monospace;
    font-size: 12px;
    color: #646970;
    margin-left: 14px;
}
.subsales-coverage .fix-form {
    display: flex;
    gap: 6px;
    align-items: center;
}
/* Out-of-area rows stack a Look up button above the free-text fallback. */
.subsales-coverage .fix-form + .fix-form { margin-top: 6px; }
.subsales-coverage .fix-form input[type="text"] { flex: 1 1 auto; min-width: 0; }
.subsales-coverage .sellers,
.subsales-coverage .order-ids { font-size: 12px; color: #646970; }
</style>
