<?php
/**
 * Order Entry Distance Report
 *
 * Cross-references the GPS fix the seller's phone recorded at order-entry time
 * against the address they typed, and flags the disagreements.
 *
 * The question this report exists to answer is "did the seller confidently enter
 * the WRONG street?" — Southington has 18 street names that differ only by
 * suffix (PINE ST and PINE DR are ~1.95 miles apart, in different ZIPs), so a
 * wrong-suffix order looks completely plausible on paper and sends a delivery
 * driver two miles off.
 *
 * All address lookup, distance maths and categorisation live in
 * Subsales_Address_Helper — this file renders, it does not compute.
 *
 * @package Subsales_Management
 * @since 2.4.65
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Formats a distance in feet, switching to miles once it stops being readable.
 */
if ( ! function_exists( 'subsales_oed_distance' ) ) {
    function subsales_oed_distance( $feet ) {
        if ( null === $feet ) {
            return '—';
        }

        return $feet < 5280
            ? number_format( $feet ) . ' ft'
            : number_format( $feet / 5280, 2 ) . ' mi';
    }
}

global $wpdb;
$orders_table      = $wpdb->prefix . 'ss_orders';
$current_season_id = intval( get_option( 'subsales_current_season_id' ) );

// Handle search
$search_query        = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';
$show_search_results = ! empty( $search_query );
$search_error        = '';

if ( $show_search_results ) {
    // Search by order ID or address. No GPS filter here: an order the admin
    // looked up by name should report "no GPS" rather than vanish.
    $orders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, order_id, order_data, address, address_validation_data, created_at, user_id, team_id
             FROM {$orders_table}
             WHERE deleted = 0
             AND season_id = %d
             AND (order_id LIKE %s OR address LIKE %s)
             ORDER BY created_at DESC
             LIMIT 20",
            $current_season_id,
            '%' . $wpdb->esc_like( $search_query ) . '%',
            '%' . $wpdb->esc_like( $search_query ) . '%'
        ),
        ARRAY_A
    );

    if ( empty( $orders ) ) {
        $search_error = 'No orders found matching "' . $search_query . '"';
    }
} else {
    // ponytail: no pagination and one fuzzy lookup per order, same as the report
    // this replaced. A season is a few thousand orders and this is an admin-only
    // page; add a LIMIT/offset if it ever gets slow enough to notice.
    $orders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, order_id, order_data, address, address_validation_data, created_at, user_id, team_id
             FROM {$orders_table}
             WHERE deleted = 0
             AND season_id = %d
             AND JSON_EXTRACT(order_data, '$.geo.latitude') IS NOT NULL
             AND JSON_EXTRACT(order_data, '$.geo.longitude') IS NOT NULL
             ORDER BY created_at DESC",
            $current_season_id
        ),
        ARRAY_A
    );
}

// Orders in this season that never captured GPS at all (backoffice entry, or
// location denied). They can't be analysed here, but the count is worth stating
// so the totals below aren't mistaken for the whole season.
$season_orders_without_gps = intval(
    $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$orders_table}
             WHERE deleted = 0
             AND season_id = %d
             AND (JSON_EXTRACT(order_data, '$.geo.latitude') IS NULL
                  OR JSON_EXTRACT(order_data, '$.geo.longitude') IS NULL)",
            $current_season_id
        )
    )
);

$results        = array();
$failed_orders  = array(); // Orders that cannot be analysed at all (no GPS / unparseable).
$flag_counts    = array( 'suspicious' => 0, 'ambiguous' => 0, 'not_in_db' => 0, 'clean' => 0 );
$parse_failures = 0;
$no_gps_data    = 0;

foreach ( $orders as $order ) {
    $order_data = json_decode( $order['order_data'], true );
    $entry_gps  = Subsales_Address_Helper::get_order_entry_gps( $order );

    $address = $order['address'];
    if ( empty( $address ) && isset( $order_data['address'] ) ) {
        $address = $order_data['address'];
    }

    if ( ! $entry_gps ) {
        $no_gps_data++;
        $failed_orders[] = array(
            'order_id'      => $order['order_id'],
            'created_at'    => $order['created_at'],
            'address'       => $address,
            'error_type'    => 'no_gps',
            'error_message' => 'No GPS coordinates were captured when this order was entered. This normally means it was created through the backoffice, or location was unavailable/denied on the device.',
        );
        continue;
    }

    $parsed = Subsales_Address_Helper::parse_address( $address );

    if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
        $parse_failures++;

        if ( empty( $address ) ) {
            $parse_reason = 'The order has no address recorded.';
        } elseif ( ! $parsed ) {
            $parse_reason = 'The address format could not be recognized by the parser.';
        } elseif ( empty( $parsed['house_number'] ) ) {
            $parse_reason = 'No house number could be extracted from the address.';
        } else {
            $parse_reason = 'No street name could be extracted from the address.';
        }

        $failed_orders[] = array(
            'order_id'      => $order['order_id'],
            'created_at'    => $order['created_at'],
            'address'       => $address,
            'error_type'    => 'parse_failure',
            'error_message' => 'Address could not be parsed: ' . $parse_reason,
            'parsed_data'   => $parsed,
            'entry_gps'     => $entry_gps,
        );
        continue;
    }

    // Tolerant lookup: every house-number match on this base street, whatever
    // suffix it carries. Two or more means the seller had a real chance to pick
    // the wrong one.
    $candidates      = Subsales_Address_Helper::lookup_in_database_fuzzy( $parsed );
    $entered_norm    = Subsales_Address_Helper::normalize_street( $parsed['street'] );
    $entered_suffix  = Subsales_Address_Helper::street_suffix( $parsed['street'] );

    // Distance from the phone to each candidate, nearest first.
    foreach ( $candidates as $i => $candidate ) {
        $candidates[ $i ]['distance_feet'] = Subsales_Address_Helper::calculate_distance(
            $entry_gps['lat'],
            $entry_gps['lng'],
            floatval( $candidate['lat'] ),
            floatval( $candidate['lng'] ),
            'ft'
        );
        $candidates[ $i ]['is_entered'] =
            Subsales_Address_Helper::normalize_street( $candidate['street'] ) === $entered_norm;
    }

    usort( $candidates, function ( $a, $b ) {
        return $a['distance_feet'] <=> $b['distance_feet'];
    } );

    // The row the seller actually typed, if it exists.
    $matched = null;
    foreach ( $candidates as $candidate ) {
        if ( $candidate['is_entered'] ) {
            $matched = $candidate;
            break;
        }
    }

    // Typed suffix isn't in the address book at all (e.g. typed "Pine Ave",
    // only PINE ST and PINE DR exist). Measure against the nearest candidate.
    $suffix_not_found = ( null === $matched && ! empty( $candidates ) );
    if ( $suffix_not_found ) {
        $matched = $candidates[0];
    }

    $flags         = array();
    $distance_feet = null;
    $target_label  = '';
    $target_source = '';

    if ( $matched ) {
        $distance_feet = $matched['distance_feet'];
        $target_label  = Subsales_Address_Helper::format_address_row( $matched );
        $target_source = 'address book';
    } else {
        // Not in wp_ss_addresses. Fall back to the geocode stored on the order
        // itself so the row still gets a distance instead of being dropped —
        // silently excluding these was the core failure of the old report.
        $flags[]   = 'not_in_db';
        $geocoded  = Subsales_Address_Helper::get_geocoded_delivery_gps( $order );

        if ( $geocoded ) {
            $distance_feet = Subsales_Address_Helper::calculate_distance(
                $entry_gps['lat'],
                $entry_gps['lng'],
                $geocoded['lat'],
                $geocoded['lng'],
                'ft'
            );
            $target_label  = trim( $parsed['house_number'] . ' ' . $parsed['street'] );
            $target_source = 'stored geocode';
        } else {
            $target_label  = trim( $parsed['house_number'] . ' ' . $parsed['street'] );
            $target_source = 'no coordinates';
        }
    }

    // Ambiguous street: the same house number exists on 2+ streets that differ
    // only by suffix.
    $distinct_streets = array();
    foreach ( $candidates as $candidate ) {
        $distinct_streets[ Subsales_Address_Helper::normalize_street( $candidate['street'] ) ] = true;
    }
    if ( count( $distinct_streets ) >= 2 ) {
        $flags[] = 'ambiguous';
    }

    $category = ( null === $distance_feet )
        ? null
        : Subsales_Address_Helper::get_distance_category( $distance_feet );

    // THE flag this report exists for. Accuracy (the device's own reported
    // precision radius) and distance (GPS-vs-address disagreement) used to sit
    // in adjacent columns and were never compared. A good fix that is still
    // remote is not noise — it means the address is wrong. Either axis alone is.
    $accuracy_good = ( null !== $entry_gps['accuracy'] )
        && ( $entry_gps['accuracy'] < Subsales_Address_Helper::ACCURACY_POOR_M );

    if ( $accuracy_good && 'remote' === $category ) {
        $flags[] = 'suspicious';
    }

    // Nearest candidate that ISN'T the one typed — the actionable "you probably
    // meant this" line.
    $nearest_other = null;
    foreach ( $candidates as $candidate ) {
        if ( ! $candidate['is_entered'] ) {
            $nearest_other = $candidate;
            break;
        }
    }

    if ( in_array( 'suspicious', $flags, true ) ) {
        $severity = 3;
    } elseif ( in_array( 'ambiguous', $flags, true ) ) {
        $severity = 2;
    } elseif ( in_array( 'not_in_db', $flags, true ) ) {
        $severity = 1;
    } else {
        $severity = 0;
    }

    foreach ( array( 'suspicious', 'ambiguous', 'not_in_db' ) as $flag ) {
        if ( in_array( $flag, $flags, true ) ) {
            $flag_counts[ $flag ]++;
        }
    }
    if ( empty( $flags ) ) {
        $flag_counts['clean']++;
    }

    $results[] = array(
        'order_id'         => $order['order_id'],
        'created_at'       => $order['created_at'],
        'entered_address'  => $address,
        'entered_suffix'   => $entered_suffix,
        'target_label'     => $target_label,
        'target_source'    => $target_source,
        'suffix_not_found' => $suffix_not_found,
        'customer'         => isset( $order_data['customer'] ) ? $order_data['customer'] : '',
        'entered_by'       => isset( $order_data['entered_by_name'] ) ? $order_data['entered_by_name'] : '',
        'entry_gps'        => $entry_gps,
        'distance_feet'    => $distance_feet,
        'category'         => $category,
        'candidates'       => $candidates,
        'nearest_other'    => $nearest_other,
        'flags'            => $flags,
        'severity'         => $severity,
    );
}

// Worst offenders first: severity, then raw distance.
usort( $results, function ( $a, $b ) {
    if ( $a['severity'] !== $b['severity'] ) {
        return $b['severity'] <=> $a['severity'];
    }

    return ( $b['distance_feet'] ?? -1 ) <=> ( $a['distance_feet'] ?? -1 );
} );

// Statistics over the rows that actually produced a distance.
$measured      = array_filter( $results, fn( $r ) => null !== $r['distance_feet'] );
$total_measured = count( $measured );
$avg_distance  = $total_measured > 0
    ? array_sum( array_column( $measured, 'distance_feet' ) ) / $total_measured
    : 0;

$category_counts = array( 'on-site' => 0, 'very-close' => 0, 'nearby' => 0, 'local' => 0, 'remote' => 0 );
foreach ( $measured as $row ) {
    if ( isset( $category_counts[ $row['category'] ] ) ) {
        $category_counts[ $row['category'] ]++;
    }
}

$category_meta = array(
    'on-site'    => array( 'On-Site', '&lt; 50 feet', '#00a32a' ),
    'very-close' => array( 'Very Close', '50 - 200 feet', '#4169E1' ),
    'nearby'     => array( 'Nearby', '200 - 500 feet', '#72aee6' ),
    'local'      => array( 'Local', '500 ft - 0.5 miles', '#f0b849' ),
    'remote'     => array( 'Remote', '&gt; 0.5 miles', '#d63638' ),
);

$flag_meta = array(
    'suspicious' => array( '🚩 Likely wrong street', '#d63638' ),
    'ambiguous'  => array( '❓ Ambiguous street', '#bd6b00' ),
    'not_in_db'  => array( '⚠️ Not in address database', '#7a5cb0' ),
);

$site_tz = new DateTimeZone( get_option( 'timezone_string' ) ?: 'America/New_York' );
?>

<div class="wrap">
    <h1>📏 Order Entry Distance Analysis</h1>
    <p class="description">
        Compares the GPS fix recorded on the seller's phone at order-entry time against the address they typed.
        The goal is catching orders where the address is <em>wrong but plausible</em> — Southington has 18 street
        names that differ only by their suffix, and PINE ST is nearly two miles from PINE DR.
    </p>

    <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-reports' ) ); ?>" class="button" style="margin-bottom: 20px;">← Back to Reports</a>

    <!-- Search Form -->
    <div class="search-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">🔍 Search Orders</h2>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display: flex; gap: 10px; align-items: flex-end;">
            <input type="hidden" name="page" value="subsales-order-entry-distance">
            <div style="flex: 1;">
                <label for="search" style="display: block; margin-bottom: 5px; font-weight: 600;">Order ID or Address</label>
                <input type="text"
                       id="search"
                       name="search"
                       value="<?php echo esc_attr( $search_query ); ?>"
                       placeholder="e.g., ORD-1234 or 123 Main Street"
                       style="width: 100%; padding: 8px; border: 1px solid #8c8f94; border-radius: 4px;">
            </div>
            <button type="submit" class="button button-primary" style="padding: 8px 20px; height: 38px;">Search</button>
            <?php if ( $show_search_results ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-order-entry-distance' ) ); ?>"
                   class="button" style="padding: 8px 20px; height: 38px;">Clear</a>
            <?php endif; ?>
        </form>
        <?php if ( $show_search_results ) : ?>
            <p style="margin-top: 10px; margin-bottom: 0; color: #666;">
                <strong>Showing results for:</strong> "<?php echo esc_html( $search_query ); ?>"
            </p>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $search_error ) ) : ?>
        <div class="notice notice-warning" style="margin: 20px 0;">
            <p><?php echo esc_html( $search_error ); ?></p>
        </div>
    <?php endif; ?>

    <!-- Flag Summary -->
    <div class="statistics-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Summary</h2>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div style="padding: 15px; background: #fcf0f1; border-left: 4px solid #d63638; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #d63638;"><?php echo number_format( $flag_counts['suspicious'] ); ?></div>
                <div style="color: #666; margin-top: 5px;">🚩 Likely wrong street</div>
                <div style="color: #999; font-size: 11px; margin-top: 3px;">Accurate GPS, still &gt; 0.5 mi away</div>
            </div>

            <div style="padding: 15px; background: #fdf6e9; border-left: 4px solid #bd6b00; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #bd6b00;"><?php echo number_format( $flag_counts['ambiguous'] ); ?></div>
                <div style="color: #666; margin-top: 5px;">❓ Ambiguous street</div>
                <div style="color: #999; font-size: 11px; margin-top: 3px;">Same house number on 2+ suffixes</div>
            </div>

            <div style="padding: 15px; background: #f6f2fb; border-left: 4px solid #7a5cb0; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #7a5cb0;"><?php echo number_format( $flag_counts['not_in_db'] ); ?></div>
                <div style="color: #666; margin-top: 5px;">⚠️ Not in address database</div>
                <div style="color: #999; font-size: 11px; margin-top: 3px;">Flagged, never hidden</div>
            </div>

            <div style="padding: 15px; background: #f0f9f4; border-left: 4px solid #00a32a; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #00a32a;"><?php echo number_format( $flag_counts['clean'] ); ?></div>
                <div style="color: #666; margin-top: 5px;">✅ No flags</div>
            </div>

            <div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #2271b1;"><?php echo number_format( $avg_distance ); ?> ft</div>
                <div style="color: #666; margin-top: 5px;">Average distance</div>
                <div style="color: #999; font-size: 11px; margin-top: 3px;">Across <?php echo number_format( $total_measured ); ?> measurable orders</div>
            </div>
        </div>

        <?php if ( $parse_failures > 0 || $no_gps_data > 0 || $season_orders_without_gps > 0 ) : ?>
            <div class="notice notice-info inline" style="margin: 0 0 20px 0;">
                <p style="margin: 0.5em 0;">
                    <?php if ( $parse_failures > 0 ) : ?>
                        <strong><?php echo number_format( $parse_failures ); ?></strong> order(s) have an address the parser cannot read — listed under "Orders That Cannot Be Analysed" below.<br>
                    <?php endif; ?>
                    <?php if ( ! $show_search_results && $season_orders_without_gps > 0 ) : ?>
                        <strong><?php echo number_format( $season_orders_without_gps ); ?></strong> order(s) this season recorded no GPS at all (backoffice entry or location denied) and cannot be checked here.
                    <?php elseif ( $no_gps_data > 0 ) : ?>
                        <strong><?php echo number_format( $no_gps_data ); ?></strong> matched order(s) recorded no GPS.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <h3 style="margin-top: 20px; margin-bottom: 10px;">Distance Categories</h3>
        <table class="widefat striped" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Distance Range</th>
                    <th style="text-align: center;">Count</th>
                    <th style="text-align: center;">Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $category_meta as $key => $meta ) : ?>
                    <tr>
                        <td>
                            <span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: <?php echo esc_attr( $meta[2] ); ?>; margin-right: 8px;"></span>
                            <strong><?php echo esc_html( $meta[0] ); ?></strong>
                        </td>
                        <td><?php echo $meta[1]; // phpcs:ignore -- static markup entity ?></td>
                        <td style="text-align: center;"><?php echo number_format( $category_counts[ $key ] ); ?></td>
                        <td style="text-align: center;"><?php echo $total_measured > 0 ? round( $category_counts[ $key ] / $total_measured * 100, 1 ) : 0; ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Detailed Order List -->
    <div class="results-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Order Details</h2>
        <p style="color: #666; margin-top: 0;">Sorted worst-first: flagged orders before clean ones, largest distance first within each group.</p>

        <?php if ( empty( $results ) ) : ?>
            <p>No orders with GPS data to analyse.</p>
        <?php else : ?>
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th style="width: 190px;">Flag</th>
                        <th style="width: 110px;">Order ID</th>
                        <th style="width: 140px;">Date</th>
                        <th style="width: 90px;">Distance</th>
                        <th style="width: 90px;">GPS Accuracy</th>
                        <th style="width: 140px;">Entered By</th>
                        <th>Address / Finding</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $results as $result ) : ?>
                        <?php
                        $row_style       = '';
                        $distance_color  = '#000';

                        switch ( $result['category'] ) {
                            case 'on-site':
                                $distance_color = '#00a32a';
                                break;
                            case 'very-close':
                                $distance_color = '#4169E1';
                                break;
                            case 'nearby':
                                $distance_color = '#72aee6';
                                break;
                            case 'local':
                                $distance_color = '#f0b849';
                                break;
                            case 'remote':
                                $distance_color = '#d63638';
                                break;
                        }

                        if ( in_array( 'suspicious', $result['flags'], true ) ) {
                            $row_style = 'background-color: #fcf0f1;';
                        } elseif ( in_array( 'ambiguous', $result['flags'], true ) ) {
                            $row_style = 'background-color: #fdf6e9;';
                        } elseif ( in_array( 'not_in_db', $result['flags'], true ) ) {
                            $row_style = 'background-color: #f6f2fb;';
                        } elseif ( 'on-site' === $result['category'] ) {
                            $row_style = 'background-color: #f0f9f4;';
                        }

                        $accuracy       = $result['entry_gps']['accuracy'];
                        $accuracy_color = '#000';
                        if ( null !== $accuracy && $accuracy < 30 ) {
                            $accuracy_color = '#00a32a';
                        } elseif ( null !== $accuracy && $accuracy > Subsales_Address_Helper::ACCURACY_POOR_M ) {
                            $accuracy_color = '#d63638';
                        }
                        ?>
                        <tr style="<?php echo esc_attr( $row_style ); ?>">
                            <td>
                                <?php if ( empty( $result['flags'] ) ) : ?>
                                    <span style="color: #00a32a;">✅ OK</span>
                                <?php else : ?>
                                    <?php foreach ( array( 'suspicious', 'ambiguous', 'not_in_db' ) as $flag ) : ?>
                                        <?php if ( in_array( $flag, $result['flags'], true ) ) : ?>
                                            <div style="color: <?php echo esc_attr( $flag_meta[ $flag ][1] ); ?>; font-weight: 600; font-size: 12px;">
                                                <?php echo esc_html( $flag_meta[ $flag ][0] ); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-orders&edit=' . urlencode( $result['order_id'] ) ) ); ?>"
                                   style="font-weight: bold; text-decoration: none;">
                                    <?php echo esc_html( $result['order_id'] ); ?>
                                </a>
                            </td>
                            <td>
                                <?php
                                $dt = new DateTime( $result['created_at'], new DateTimeZone( 'UTC' ) );
                                $dt->setTimezone( $site_tz );
                                echo esc_html( $dt->format( 'M j, Y g:i A' ) );
                                ?>
                            </td>
                            <td style="color: <?php echo esc_attr( $distance_color ); ?>; font-weight: bold;">
                                <?php echo esc_html( subsales_oed_distance( $result['distance_feet'] ) ); ?>
                                <?php if ( 'stored geocode' === $result['target_source'] ) : ?>
                                    <br><small style="color: #999; font-weight: normal;">(vs stored geocode)</small>
                                <?php endif; ?>
                            </td>
                            <td style="color: <?php echo esc_attr( $accuracy_color ); ?>;">
                                <?php if ( null !== $accuracy ) : ?>
                                    ±<?php echo esc_html( number_format( $accuracy ) ); ?>m
                                <?php else : ?>
                                    <em>N/A</em>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $result['entered_by'] ?: 'Unknown' ); ?></td>
                            <td>
                                <strong><?php echo esc_html( $result['entered_address'] ); ?></strong>
                                <?php if ( ! empty( $result['customer'] ) ) : ?>
                                    <br><small style="color: #666;">Customer: <?php echo esc_html( $result['customer'] ); ?></small>
                                <?php endif; ?>

                                <?php if ( in_array( 'suspicious', $result['flags'], true ) ) : ?>
                                    <div style="margin-top: 8px; padding: 8px; background: #fff; border-left: 3px solid #d63638;">
                                        <strong style="color: #d63638;">GPS was accurate (±<?php echo esc_html( number_format( $accuracy ) ); ?>m) and still
                                        <?php echo esc_html( subsales_oed_distance( $result['distance_feet'] ) ); ?> from
                                        <?php echo esc_html( $result['target_label'] ); ?>.</strong>
                                        The address is more likely wrong than the phone.
                                    </div>
                                <?php endif; ?>

                                <?php if ( in_array( 'ambiguous', $result['flags'], true ) ) : ?>
                                    <div style="margin-top: 8px; padding: 8px; background: #fff; border-left: 3px solid #bd6b00;">
                                        <strong style="color: #bd6b00;">
                                            <?php echo count( $result['candidates'] ); ?> streets share this house number, differing only by suffix.
                                        </strong>
                                        <?php if ( $result['nearest_other'] && $result['nearest_other']['distance_feet'] < ( $result['distance_feet'] ?? INF ) ) : ?>
                                            <br>
                                            Entered <strong><?php echo esc_html( $result['entered_suffix'] ?: $result['entered_address'] ); ?></strong>,
                                            but the phone was
                                            <strong><?php echo esc_html( subsales_oed_distance( $result['nearest_other']['distance_feet'] ) ); ?></strong>
                                            from <strong><?php echo esc_html( Subsales_Address_Helper::format_address_row( $result['nearest_other'] ) ); ?></strong>.
                                        <?php endif; ?>
                                        <ul style="margin: 6px 0 0 18px;">
                                            <?php foreach ( $result['candidates'] as $candidate ) : ?>
                                                <li>
                                                    <?php echo esc_html( Subsales_Address_Helper::format_address_row( $candidate ) ); ?>
                                                    — <?php echo esc_html( subsales_oed_distance( $candidate['distance_feet'] ) ); ?> from phone
                                                    <?php if ( $candidate['is_entered'] ) : ?>
                                                        <em>(as entered)</em>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <?php if ( $result['suffix_not_found'] ) : ?>
                                    <div style="margin-top: 8px; color: #bd6b00; font-size: 12px;">
                                        No "<?php echo esc_html( $result['entered_suffix'] ?: $result['entered_address'] ); ?>" row exists for this house number;
                                        measured against <?php echo esc_html( $result['target_label'] ); ?>.
                                    </div>
                                <?php endif; ?>

                                <?php if ( in_array( 'not_in_db', $result['flags'], true ) ) : ?>
                                    <?php $nearest = Subsales_Address_Helper::nearest_address_to_point( $result['entry_gps']['lat'], $result['entry_gps']['lng'] ); ?>
                                    <div style="margin-top: 8px; padding: 8px; background: #fff; border-left: 3px solid #7a5cb0;">
                                        <strong style="color: #7a5cb0;">This address is not in the address database.</strong>
                                        <?php if ( 'stored geocode' === $result['target_source'] ) : ?>
                                            Distance above is measured against the geocode stored on the order.
                                        <?php else : ?>
                                            No coordinates are available for it, so no distance can be measured.
                                        <?php endif; ?>
                                        <?php if ( $nearest ) : ?>
                                            <br>Nearest known address to where the phone was:
                                            <strong><?php echo esc_html( Subsales_Address_Helper::format_address_row( $nearest ) ); ?></strong>
                                            (<?php echo esc_html( subsales_oed_distance( $nearest['distance_feet'] ) ); ?> away).
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <details style="margin-top: 6px;">
                                    <summary style="cursor: pointer; color: #2271b1;">Coordinates</summary>
                                    <div style="margin-top: 5px; padding: 5px; background: #f0f0f1; border-radius: 3px; font-family: monospace; font-size: 11px;">
                                        Phone: <?php echo esc_html( number_format( $result['entry_gps']['lat'], 6 ) ); ?>,
                                        <?php echo esc_html( number_format( $result['entry_gps']['lng'], 6 ) ); ?><br>
                                        Compared against: <?php echo esc_html( $result['target_label'] ); ?>
                                        (<?php echo esc_html( $result['target_source'] ); ?>)
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ( ! empty( $failed_orders ) ) : ?>
        <div class="results-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
            <h2 style="margin-top: 0; color: #d63638;">⚠️ Orders That Cannot Be Analysed (<?php echo count( $failed_orders ); ?>)</h2>
            <p style="color: #666;">These have no usable GPS fix or no readable address, so there is nothing to cross-reference.</p>

            <?php foreach ( $failed_orders as $failed ) : ?>
                <div style="border: 1px solid #d63638; border-radius: 4px; padding: 15px; margin: 15px 0; background: #fcf0f1;">
                    <h3 style="margin-top: 0; color: #d63638;">
                        Order:
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-orders&edit=' . urlencode( $failed['order_id'] ) ) ); ?>"
                           style="text-decoration: none; color: #d63638;">
                            <?php echo esc_html( $failed['order_id'] ); ?>
                        </a>
                    </h3>
                    <p style="margin: 5px 0;"><strong>Date:</strong>
                        <?php
                        $dt = new DateTime( $failed['created_at'], new DateTimeZone( 'UTC' ) );
                        $dt->setTimezone( $site_tz );
                        echo esc_html( $dt->format( 'M j, Y g:i A' ) );
                        ?>
                    </p>
                    <p style="margin: 5px 0;"><strong>Address:</strong> <?php echo esc_html( $failed['address'] ?: '(empty)' ); ?></p>

                    <div style="background: #fff; border-left: 4px solid #d63638; padding: 10px; margin-top: 10px;">
                        <strong>Issue:</strong>
                        <?php if ( 'no_gps' === $failed['error_type'] ) : ?>
                            <span style="color: #d63638;">⚠️ No GPS Data</span>
                        <?php else : ?>
                            <span style="color: #d63638;">⚠️ Address Parse Failure</span>
                        <?php endif; ?>
                        <br>
                        <span style="color: #666;"><?php echo esc_html( $failed['error_message'] ); ?></span>
                    </div>

                    <?php if ( ! empty( $failed['parsed_data'] ) ) : ?>
                        <details style="margin-top: 10px;">
                            <summary style="cursor: pointer; color: #2271b1; font-weight: 600;">Show Parsed Address Details</summary>
                            <div style="background: #f0f0f1; padding: 10px; margin-top: 5px; border-radius: 3px; font-family: monospace; font-size: 12px;">
                                <pre><?php echo esc_html( print_r( $failed['parsed_data'], true ) ); ?></pre>
                            </div>
                        </details>
                    <?php endif; ?>

                    <?php if ( ! empty( $failed['entry_gps'] ) ) : ?>
                        <?php $nearest = Subsales_Address_Helper::nearest_address_to_point( $failed['entry_gps']['lat'], $failed['entry_gps']['lng'] ); ?>
                        <div style="margin-top: 10px; padding: 10px; background: #f0f6fc; border-radius: 3px;">
                            <strong>📍 Order Entered At:</strong><br>
                            <?php if ( $nearest ) : ?>
                                <span style="font-size: 13px; color: #1d2327;">
                                    near <?php echo esc_html( Subsales_Address_Helper::format_address_row( $nearest ) ); ?>
                                    (<?php echo esc_html( subsales_oed_distance( $nearest['distance_feet'] ) ); ?> away)
                                </span><br>
                            <?php endif; ?>
                            <span style="font-family: monospace; font-size: 11px; color: #666;">
                                <?php echo esc_html( number_format( $failed['entry_gps']['lat'], 6 ) ); ?>,
                                <?php echo esc_html( number_format( $failed['entry_gps']['lng'], 6 ) ); ?>
                                <?php if ( null !== $failed['entry_gps']['accuracy'] ) : ?>
                                    (±<?php echo esc_html( number_format( $failed['entry_gps']['accuracy'] ) ); ?>m)
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Interpretation Guide -->
    <div class="guide-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Interpretation Guide</h2>

        <h3>Flags</h3>
        <ul style="line-height: 1.8;">
            <li><strong style="color: #d63638;">🚩 Likely wrong street:</strong> the phone's own reported accuracy was better than
                <?php echo esc_html( Subsales_Address_Helper::ACCURACY_POOR_M ); ?>m <em>and</em> the entered address is still more than half a mile away.
                A precise fix that disagrees with the address by that much usually means the address is wrong, not the GPS.
                Check this against the suffix-collision list first.</li>
            <li><strong style="color: #bd6b00;">❓ Ambiguous street:</strong> that house number exists on two or more streets whose
                names differ only by suffix (PINE ST vs PINE DR). Where the phone's position picks a clear winner, it is named.</li>
            <li><strong style="color: #7a5cb0;">⚠️ Not in address database:</strong> the address does not exist in wp_ss_addresses.
                These are shown, never hidden — an address the book has never heard of is more likely to be wrong, not less.
                Where the order carries its own stored geocode, the distance is still measured against that.</li>
        </ul>

        <h3>Accuracy is not distance</h3>
        <p style="line-height: 1.8;">
            <strong>GPS accuracy</strong> is the radius the device itself reports around its fix — a property of the phone and the sky.
            <strong>Distance</strong> is how far that fix sits from the address the seller typed — a property of the data.
            Neither column means much on its own: a sloppy fix a mile out is just a sloppy fix, and a tight fix at the doorstep is fine.
            It is the <em>combination</em> — tight fix, long distance — that this report flags.
        </p>

        <h3>Distance Categories</h3>
        <ul style="line-height: 1.8;">
            <li><strong style="color: #00a32a;">On-Site (&lt;50 ft):</strong> entered at the door. Normal door-to-door sale.</li>
            <li><strong style="color: #4169E1;">Very Close (50-200 ft):</strong> on the street or a neighbour's step.</li>
            <li><strong style="color: #72aee6;">Nearby (200-500 ft):</strong> same block.</li>
            <li><strong style="color: #f0b849;">Local (500 ft - 0.5 mi):</strong> in the area but not at the address.</li>
            <li><strong style="color: #d63638;">Remote (&gt;0.5 mi):</strong> somewhere else entirely. Either batch-entered later, or the address is wrong.</li>
        </ul>
        <p style="line-height: 1.8; color: #666;">
            Remote on its own is <em>not</em> proof of an error — sellers legitimately batch-enter orders after the fact from home.
            That is exactly why the accuracy cross-reference exists.
        </p>
    </div>
</div>

<style>
.statistics-card h2,
.results-card h2,
.guide-card h2 {
    font-size: 18px;
    color: #1d2327;
}

.wp-list-table td {
    vertical-align: top;
}

details summary {
    font-size: 11px;
}

details[open] summary {
    margin-bottom: 5px;
}
</style>
