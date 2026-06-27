<?php
/**
 * Order Entry Distance Report
 * 
 * Shows how far away each order was entered from its actual delivery address.
 * Compares GPS coordinates captured during order entry with official address coordinates.
 * 
 * @package Subsales_Management
 * @since 2.4.65
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Reverse geocode GPS coordinates to get address
 * 
 * @param float $lat Latitude
 * @param float $lng Longitude
 * @return string|null Address or null if failed
 */
function subsales_reverse_geocode( $lat, $lng ) {
    // Check cache first
    $cache_key = 'reverse_geocode_' . md5( $lat . '_' . $lng );
    $cached = get_transient( $cache_key );
    if ( false !== $cached ) {
        return $cached;
    }
    
    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    if ( empty( $api_key ) ) {
        return null;
    }
    
    $url = sprintf(
        'https://maps.googleapis.com/maps/api/geocode/json?latlng=%s,%s&key=%s',
        $lat,
        $lng,
        $api_key
    );
    
    $response = wp_remote_get( $url, array( 'timeout' => 5 ) );
    
    if ( is_wp_error( $response ) ) {
        return null;
    }
    
    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );
    
    if ( empty( $data['results'][0]['formatted_address'] ) ) {
        // Cache negative result for 1 hour
        set_transient( $cache_key, '', HOUR_IN_SECONDS );
        return null;
    }
    
    $address = $data['results'][0]['formatted_address'];
    
    // Cache for 7 days
    set_transient( $cache_key, $address, 7 * DAY_IN_SECONDS );
    
    return $address;
}

global $wpdb;
$orders_table = $wpdb->prefix . 'ss_orders';
$addresses_table = $wpdb->prefix . 'ss_addresses';

// Handle search
$search_query = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
$show_search_results = ! empty( $search_query );
$search_results = array();
$search_error = '';

if ( $show_search_results ) {
    // Try to find order by order ID or address
    $search_orders = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, order_id, order_data, address, created_at, user_id, team_id
             FROM {$orders_table}
             WHERE deleted = 0
             AND (order_id LIKE %s OR address LIKE %s)
             ORDER BY created_at DESC
             LIMIT 20",
            '%' . $wpdb->esc_like( $search_query ) . '%',
            '%' . $wpdb->esc_like( $search_query ) . '%'
        ),
        ARRAY_A
    );
    
    if ( empty( $search_orders ) ) {
        $search_error = 'No orders found matching "' . esc_html( $search_query ) . '"';
    }
} else {
    // Get all orders with GPS data for full report
    $search_orders = $wpdb->get_results(
        "SELECT id, order_id, order_data, address, created_at, user_id, team_id
         FROM {$orders_table}
         WHERE deleted = 0
         AND JSON_EXTRACT(order_data, '$.geo.latitude') IS NOT NULL
         AND JSON_EXTRACT(order_data, '$.geo.longitude') IS NOT NULL
         ORDER BY created_at DESC",
        ARRAY_A
    );
}

$orders = $search_orders;

$results = array();
$no_address_match = 0;
$parse_failures = 0;
$no_gps_data = 0;
$failed_orders = array(); // Track failures with details

foreach ( $orders as $order ) {
    $order_data = json_decode( $order['order_data'], true );
    
    // Check for GPS data
    if ( ! $order_data || ! isset( $order_data['geo'] ) || empty( $order_data['geo']['latitude'] ) || empty( $order_data['geo']['longitude'] ) ) {
        $no_gps_data++;
        if ( $show_search_results ) {
            $failed_orders[] = array(
                'order_id' => $order['order_id'],
                'created_at' => $order['created_at'],
                'address' => $order['address'],
                'error_type' => 'no_gps',
                'error_message' => 'No GPS coordinates were captured when this order was entered. This typically means the order was created via the backoffice interface or GPS was unavailable on the device.'
            );
        }
        continue;
    }
    
    $entry_lat = floatval( $order_data['geo']['latitude'] );
    $entry_lng = floatval( $order_data['geo']['longitude'] );
    $entry_accuracy = isset( $order_data['geo']['accuracy'] ) ? floatval( $order_data['geo']['accuracy'] ) : null;
    
    $address = $order['address'];
    if ( empty( $address ) && isset( $order_data['address'] ) ) {
        $address = $order_data['address'];
    }
    
    // Parse address to look up in database
    $parsed = Subsales_Delivery::parse_address( $address );
    if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
        $parse_failures++;
        if ( $show_search_results ) {
            $parse_reason = '';
            if ( empty( $address ) ) {
                $parse_reason = 'The order has no address recorded.';
            } elseif ( ! $parsed ) {
                $parse_reason = 'The address format could not be recognized by the parser.';
            } elseif ( empty( $parsed['house_number'] ) ) {
                $parse_reason = 'No house number could be extracted from the address.';
            } elseif ( empty( $parsed['street'] ) ) {
                $parse_reason = 'No street name could be extracted from the address.';
            }
            
            $failed_orders[] = array(
                'order_id' => $order['order_id'],
                'created_at' => $order['created_at'],
                'address' => $address,
                'error_type' => 'parse_failure',
                'error_message' => 'Address could not be parsed: ' . $parse_reason,
                'parsed_data' => $parsed
            );
        }
        continue;
    }
    
    // Look up address coordinates
    $query = "SELECT lat, lng, CONCAT(house_number, ' ', street, ', ', city, ', ', state, ' ', zip) as full_address
              FROM {$addresses_table}
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
    
    $query .= " LIMIT 1";
    
    $address_row = $wpdb->get_row( $wpdb->prepare( $query, $params ), ARRAY_A );
    
    if ( ! $address_row ) {
        $no_address_match++;
        if ( $show_search_results ) {
            $failed_orders[] = array(
                'order_id' => $order['order_id'],
                'created_at' => $order['created_at'],
                'address' => $address,
                'error_type' => 'not_in_database',
                'error_message' => 'Address is not in the database. The parsed address was: ' . 
                                   $parsed['house_number'] . ' ' . $parsed['street'] . 
                                   ( ! empty( $parsed['city'] ) ? ', ' . $parsed['city'] : '' ) .
                                   ( ! empty( $parsed['zip'] ) ? ' ' . $parsed['zip'] : '' ) .
                                   '. This address needs to be added to the address database before distance can be calculated.',
                'parsed_data' => $parsed,
                'entry_lat' => $entry_lat,
                'entry_lng' => $entry_lng,
                'entry_accuracy' => $entry_accuracy
            );
        }
        continue;
    }
    
    $address_lat = floatval( $address_row['lat'] );
    $address_lng = floatval( $address_row['lng'] );
    
    // Calculate distance using Haversine formula
    $distance_miles = acos(
        cos( deg2rad( $address_lat ) ) 
        * cos( deg2rad( $entry_lat ) ) 
        * cos( deg2rad( $entry_lng ) - deg2rad( $address_lng ) ) 
        + sin( deg2rad( $address_lat ) ) 
        * sin( deg2rad( $entry_lat ) )
    ) * 3959; // Earth radius in miles
    
    $distance_feet = $distance_miles * 5280;
    
    // Categorize distance
    $category = '';
    if ( $distance_feet < 50 ) {
        $category = 'on-site';
    } elseif ( $distance_feet < 200 ) {
        $category = 'very-close';
    } elseif ( $distance_feet < 500 ) {
        $category = 'nearby';
    } elseif ( $distance_feet < 2640 ) { // 0.5 miles
        $category = 'local';
    } else {
        $category = 'remote';
    }
    
    $results[] = array(
        'order_id' => $order['order_id'],
        'created_at' => $order['created_at'],
        'address' => $address_row['full_address'],
        'customer' => isset( $order_data['customer'] ) ? $order_data['customer'] : '',
        'entered_by' => isset( $order_data['entered_by_name'] ) ? $order_data['entered_by_name'] : '',
        'entry_lat' => $entry_lat,
        'entry_lng' => $entry_lng,
        'entry_accuracy' => $entry_accuracy,
        'address_lat' => $address_lat,
        'address_lng' => $address_lng,
        'distance_feet' => $distance_feet,
        'distance_miles' => $distance_miles,
        'category' => $category
    );
}

// Sort by distance (largest first)
usort( $results, function( $a, $b ) {
    return $b['distance_feet'] <=> $a['distance_feet'];
});

// Calculate statistics
$total_with_gps = count( $results );
$on_site = count( array_filter( $results, fn($r) => $r['category'] === 'on-site' ) );
$very_close = count( array_filter( $results, fn($r) => $r['category'] === 'very-close' ) );
$nearby = count( array_filter( $results, fn($r) => $r['category'] === 'nearby' ) );
$local = count( array_filter( $results, fn($r) => $r['category'] === 'local' ) );
$remote = count( array_filter( $results, fn($r) => $r['category'] === 'remote' ) );

$avg_distance = $total_with_gps > 0 ? array_sum( array_column( $results, 'distance_feet' ) ) / $total_with_gps : 0;
?>

<div class="wrap">
    <h1>📏 Order Entry Distance Analysis</h1>
    <p class="description">
        Shows how far away each order was entered from its actual delivery address. 
        Compares GPS location captured during order entry with official address coordinates.
    </p>
    
    <a href="<?php echo admin_url( 'admin.php?page=subsales-reports' ); ?>" class="button" style="margin-bottom: 20px;">← Back to Reports</a>
    
    <!-- Search Form -->
    <div class="search-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">🔍 Search Orders</h2>
        <form method="get" action="<?php echo admin_url( 'admin.php' ); ?>" style="display: flex; gap: 10px; align-items: flex-end;">
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
            <?php if ( $show_search_results ): ?>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-order-entry-distance' ); ?>" 
                   class="button" style="padding: 8px 20px; height: 38px;">Clear</a>
            <?php endif; ?>
        </form>
        <?php if ( $show_search_results && ! empty( $search_query ) ): ?>
            <p style="margin-top: 10px; margin-bottom: 0; color: #666;">
                <strong>Showing results for:</strong> "<?php echo esc_html( $search_query ); ?>"
            </p>
        <?php endif; ?>
    </div>
    
    <?php if ( $show_search_results ): ?>
        <!-- Search Results -->
        <?php if ( ! empty( $search_error ) ): ?>
            <div class="notice notice-warning" style="margin: 20px 0;">
                <p><?php echo esc_html( $search_error ); ?></p>
            </div>
        <?php elseif ( ! empty( $failed_orders ) && empty( $results ) ): ?>
            <!-- All searched orders have issues -->
            <div class="error-results-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0; color: #d63638;">❌ Issues Found</h2>
                <p>The following orders could not be analyzed:</p>
                
                <?php foreach ( $failed_orders as $failed ): ?>
                    <div style="border: 1px solid #d63638; border-radius: 4px; padding: 15px; margin: 15px 0; background: #fcf0f1;">
                        <h3 style="margin-top: 0; color: #d63638;">
                            Order: <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . urlencode( $failed['order_id'] ) ); ?>" 
                                     style="text-decoration: none; color: #d63638;">
                                <?php echo esc_html( $failed['order_id'] ); ?>
                            </a>
                        </h3>
                        <p style="margin: 5px 0;"><strong>Date:</strong> 
                            <?php 
                            $dt = new DateTime( $failed['created_at'], new DateTimeZone( 'UTC' ) );
                            $dt->setTimezone( new DateTimeZone( get_option( 'timezone_string' ) ?: 'America/New_York' ) );
                            echo $dt->format( 'M j, Y g:i A' ); 
                            ?>
                        </p>
                        <p style="margin: 5px 0;"><strong>Address:</strong> <?php echo esc_html( $failed['address'] ?: '(empty)' ); ?></p>
                        
                        <div style="background: #fff; border-left: 4px solid #d63638; padding: 10px; margin-top: 10px;">
                            <strong>Issue:</strong> 
                            <?php if ( $failed['error_type'] === 'no_gps' ): ?>
                                <span style="color: #d63638;">⚠️ No GPS Data</span>
                            <?php elseif ( $failed['error_type'] === 'parse_failure' ): ?>
                                <span style="color: #d63638;">⚠️ Address Parse Failure</span>
                            <?php elseif ( $failed['error_type'] === 'not_in_database' ): ?>
                                <span style="color: #d63638;">⚠️ Address Not in Database</span>
                            <?php endif; ?>
                            <br>
                            <span style="color: #666;"><?php echo esc_html( $failed['error_message'] ); ?></span>
                        </div>
                        
                        <?php if ( isset( $failed['parsed_data'] ) && ! empty( $failed['parsed_data'] ) ): ?>
                            <details style="margin-top: 10px;">
                                <summary style="cursor: pointer; color: #2271b1; font-weight: 600;">Show Parsed Address Details</summary>
                                <div style="background: #f0f0f1; padding: 10px; margin-top: 5px; border-radius: 3px; font-family: monospace; font-size: 12px;">
                                    <?php echo '<pre>' . esc_html( print_r( $failed['parsed_data'], true ) ) . '</pre>'; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                        
                        <?php if ( isset( $failed['entry_lat'] ) && isset( $failed['entry_lng'] ) ): ?>
                            <?php
                            $entry_address = subsales_reverse_geocode( $failed['entry_lat'], $failed['entry_lng'] );
                            ?>
                            <div style="margin-top: 10px; padding: 10px; background: #f0f6fc; border-radius: 3px;">
                                <strong>📍 Order Entered At:</strong><br>
                                <?php if ( $entry_address ): ?>
                                    <span style="font-size: 13px; color: #1d2327;"><?php echo esc_html( $entry_address ); ?></span><br>
                                    <span style="font-family: monospace; font-size: 11px; color: #666;">
                                        <?php echo number_format( $failed['entry_lat'], 6 ); ?>, <?php echo number_format( $failed['entry_lng'], 6 ); ?>
                                        <?php if ( isset( $failed['entry_accuracy'] ) && $failed['entry_accuracy'] ): ?>
                                            (±<?php echo number_format( $failed['entry_accuracy'] ); ?>m)
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-family: monospace; font-size: 12px;">
                                        Lat: <?php echo number_format( $failed['entry_lat'], 6 ); ?>, 
                                        Lng: <?php echo number_format( $failed['entry_lng'], 6 ); ?>
                                        <?php if ( isset( $failed['entry_accuracy'] ) && $failed['entry_accuracy'] ): ?>
                                            (±<?php echo number_format( $failed['entry_accuracy'] ); ?>m)
                                        <?php endif; ?>
                                    </span>
                                    <br><small style="color: #999;">Unable to reverse geocode location</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
    
    <?php if ( ! $show_search_results || ! empty( $results ) ): ?>
    <!-- Statistics Summary -->
    <div class="statistics-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Summary Statistics</h2>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #2271b1;"><?php echo number_format( $total_with_gps ); ?></div>
                <div style="color: #666; margin-top: 5px;">Orders with GPS Data</div>
            </div>
            
            <div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #2271b1;"><?php echo number_format( $avg_distance ); ?> ft</div>
                <div style="color: #666; margin-top: 5px;">Average Distance</div>
            </div>
            
            <div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">
                <div style="font-size: 28px; font-weight: bold; color: #2271b1;"><?php echo number_format( $no_address_match ); ?></div>
                <div style="color: #666; margin-top: 5px;">No Address Match</div>
            </div>
        </div>
        
        <h3 style="margin-top: 20px; margin-bottom: 10px;">Distance Categories</h3>
        <table class="widefat" style="max-width: 600px;">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Distance Range</th>
                    <th style="text-align: center;">Count</th>
                    <th style="text-align: center;">Percentage</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #00a32a; margin-right: 8px;"></span><strong>On-Site</strong></td>
                    <td>&lt; 50 feet</td>
                    <td style="text-align: center;"><?php echo number_format( $on_site ); ?></td>
                    <td style="text-align: center;"><?php echo $total_with_gps > 0 ? round( $on_site / $total_with_gps * 100, 1 ) : 0; ?>%</td>
                </tr>
                <tr>
                    <td><span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #4169E1; margin-right: 8px;"></span><strong>Very Close</strong></td>
                    <td>50 - 200 feet</td>
                    <td style="text-align: center;"><?php echo number_format( $very_close ); ?></td>
                    <td style="text-align: center;"><?php echo $total_with_gps > 0 ? round( $very_close / $total_with_gps * 100, 1 ) : 0; ?>%</td>
                </tr>
                <tr>
                    <td><span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #72aee6; margin-right: 8px;"></span><strong>Nearby</strong></td>
                    <td>200 - 500 feet</td>
                    <td style="text-align: center;"><?php echo number_format( $nearby ); ?></td>
                    <td style="text-align: center;"><?php echo $total_with_gps > 0 ? round( $nearby / $total_with_gps * 100, 1 ) : 0; ?>%</td>
                </tr>
                <tr>
                    <td><span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #f0b849; margin-right: 8px;"></span><strong>Local</strong></td>
                    <td>500 ft - 0.5 miles</td>
                    <td style="text-align: center;"><?php echo number_format( $local ); ?></td>
                    <td style="text-align: center;"><?php echo $total_with_gps > 0 ? round( $local / $total_with_gps * 100, 1 ) : 0; ?>%</td>
                </tr>
                <tr>
                    <td><span style="display: inline-block; width: 12px; height: 12px; border-radius: 50%; background: #d63638; margin-right: 8px;"></span><strong>Remote</strong></td>
                    <td>&gt; 0.5 miles</td>
                    <td style="text-align: center;"><?php echo number_format( $remote ); ?></td>
                    <td style="text-align: center;"><?php echo $total_with_gps > 0 ? round( $remote / $total_with_gps * 100, 1 ) : 0; ?>%</td>
                </tr>
            </tbody>
        </table>
        
        <?php if ( $no_address_match > 0 || $parse_failures > 0 ): ?>
            <div class="notice notice-info inline" style="margin-top: 20px;">
                <p>
                    <?php if ( $no_address_match > 0 ): ?>
                        <strong><?php echo $no_address_match; ?> orders</strong> could not be matched - addresses not in database.<br>
                    <?php endif; ?>
                    <?php if ( $parse_failures > 0 ): ?>
                        <strong><?php echo $parse_failures; ?> orders</strong> had unparseable addresses.<br>
                    <?php endif; ?>
                    These orders are excluded from the analysis below.
                </p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Detailed Order List -->
    <div class="results-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Order Details</h2>
        
        <?php if ( empty( $results ) ): ?>
            <p>No orders found with GPS data and matching addresses.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 120px;">Order ID</th>
                        <th style="width: 140px;">Date</th>
                        <th style="width: 100px;">Distance</th>
                        <th style="width: 80px;">GPS Accuracy</th>
                        <th style="width: 150px;">Entered By</th>
                        <th>Delivery Address</th>
                        <th style="width: 100px;">GPS Coordinates</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $results as $result ): ?>
                        <?php
                        // Color coding based on distance category
                        $row_style = '';
                        $distance_color = '#000';
                        switch ( $result['category'] ) {
                            case 'on-site':
                                $row_style = 'background-color: #f0f9f4;';
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
                                $row_style = 'background-color: #fcf0f1;';
                                $distance_color = '#d63638';
                                break;
                        }
                        
                        $accuracy_color = '#000';
                        if ( $result['entry_accuracy'] && $result['entry_accuracy'] < 30 ) {
                            $accuracy_color = '#00a32a';
                        } elseif ( $result['entry_accuracy'] && $result['entry_accuracy'] > 100 ) {
                            $accuracy_color = '#d63638';
                        }
                        ?>
                        <tr style="<?php echo $row_style; ?>">
                            <td>
                                <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . urlencode( $result['order_id'] ) ); ?>" 
                                   style="font-weight: bold; text-decoration: none;">
                                    <?php echo esc_html( $result['order_id'] ); ?>
                                </a>
                            </td>
                            <td>
                                <?php 
                                $dt = new DateTime( $result['created_at'], new DateTimeZone( 'UTC' ) );
                                $dt->setTimezone( new DateTimeZone( get_option( 'timezone_string' ) ?: 'America/New_York' ) );
                                echo $dt->format( 'M j, Y g:i A' ); 
                                ?>
                            </td>
                            <td style="color: <?php echo $distance_color; ?>; font-weight: bold;">
                                <?php if ( $result['distance_feet'] < 5280 ): ?>
                                    <?php echo number_format( $result['distance_feet'] ); ?> ft
                                <?php else: ?>
                                    <?php echo number_format( $result['distance_miles'], 2 ); ?> mi
                                <?php endif; ?>
                            </td>
                            <td style="color: <?php echo $accuracy_color; ?>;">
                                <?php if ( $result['entry_accuracy'] ): ?>
                                    ±<?php echo number_format( $result['entry_accuracy'] ); ?>m
                                <?php else: ?>
                                    <em>N/A</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html( $result['entered_by'] ?: 'Unknown' ); ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $result['address'] ); ?></strong>
                                <?php if ( ! empty( $result['customer'] ) ): ?>
                                    <br><small style="color: #666;">Customer: <?php echo esc_html( $result['customer'] ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 11px; font-family: monospace;">
                                <details>
                                    <summary style="cursor: pointer; color: #2271b1;">View</summary>
                                    <?php
                                    $entry_address = subsales_reverse_geocode( $result['entry_lat'], $result['entry_lng'] );
                                    ?>
                                    <div style="margin-top: 5px; padding: 5px; background: #f0f0f1; border-radius: 3px;">
                                        <strong>Entry Location:</strong><br>
                                        <?php if ( $entry_address ): ?>
                                            <span style="font-size: 11px; font-family: sans-serif; color: #1d2327;"><?php echo esc_html( $entry_address ); ?></span><br>
                                        <?php endif; ?>
                                        <span style="font-size: 10px; color: #666;">
                                            <?php echo number_format( $result['entry_lat'], 6 ); ?>,
                                            <?php echo number_format( $result['entry_lng'], 6 ); ?>
                                        </span>
                                        <hr style="margin: 5px 0;">
                                        <strong>Delivery Address:</strong><br>
                                        <?php echo number_format( $result['address_lat'], 6 ); ?>,<br>
                                        <?php echo number_format( $result['address_lng'], 6 ); ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        
        <?php if ( $show_search_results && ! empty( $failed_orders ) ): ?>
            <!-- Show failed orders in search results -->
            <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd;">
                <h3 style="color: #d63638;">⚠️ Orders with Issues (<?php echo count( $failed_orders ); ?>)</h3>
                <p style="color: #666; margin-bottom: 15px;">The following orders from your search could not be analyzed:</p>
                
                <?php foreach ( $failed_orders as $failed ): ?>
                    <div style="border: 1px solid #d63638; border-radius: 4px; padding: 15px; margin: 15px 0; background: #fcf0f1;">
                        <h4 style="margin-top: 0; color: #d63638;">
                            Order: <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . urlencode( $failed['order_id'] ) ); ?>" 
                                     style="text-decoration: none; color: #d63638;">
                                <?php echo esc_html( $failed['order_id'] ); ?>
                            </a>
                        </h4>
                        <p style="margin: 5px 0;"><strong>Date:</strong> 
                            <?php 
                            $dt = new DateTime( $failed['created_at'], new DateTimeZone( 'UTC' ) );
                            $dt->setTimezone( new DateTimeZone( get_option( 'timezone_string' ) ?: 'America/New_York' ) );
                            echo $dt->format( 'M j, Y g:i A' ); 
                            ?>
                        </p>
                        <p style="margin: 5px 0;"><strong>Address:</strong> <?php echo esc_html( $failed['address'] ?: '(empty)' ); ?></p>
                        
                        <div style="background: #fff; border-left: 4px solid #d63638; padding: 10px; margin-top: 10px;">
                            <strong>Issue:</strong> 
                            <?php if ( $failed['error_type'] === 'no_gps' ): ?>
                                <span style="color: #d63638;">⚠️ No GPS Data</span>
                            <?php elseif ( $failed['error_type'] === 'parse_failure' ): ?>
                                <span style="color: #d63638;">⚠️ Address Parse Failure</span>
                            <?php elseif ( $failed['error_type'] === 'not_in_database' ): ?>
                                <span style="color: #d63638;">⚠️ Address Not in Database</span>
                            <?php endif; ?>
                            <br>
                            <span style="color: #666;"><?php echo esc_html( $failed['error_message'] ); ?></span>
                        </div>
                        
                        <?php if ( isset( $failed['parsed_data'] ) && ! empty( $failed['parsed_data'] ) ): ?>
                            <details style="margin-top: 10px;">
                                <summary style="cursor: pointer; color: #2271b1; font-weight: 600;">Show Parsed Address Details</summary>
                                <div style="background: #f0f0f1; padding: 10px; margin-top: 5px; border-radius: 3px; font-family: monospace; font-size: 12px;">
                                    <?php echo '<pre>' . esc_html( print_r( $failed['parsed_data'], true ) ) . '</pre>'; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                        
                        <?php if ( isset( $failed['entry_lat'] ) && isset( $failed['entry_lng'] ) ): ?>
                            <?php
                            $entry_address_dup = subsales_reverse_geocode( $failed['entry_lat'], $failed['entry_lng'] );
                            ?>
                            <div style="margin-top: 10px; padding: 10px; background: #f0f6fc; border-radius: 3px;">
                                <strong>📍 Order Entered At:</strong><br>
                                <?php if ( $entry_address_dup ): ?>
                                    <span style="font-size: 13px; color: #1d2327;"><?php echo esc_html( $entry_address_dup ); ?></span><br>
                                    <span style="font-family: monospace; font-size: 11px; color: #666;">
                                        <?php echo number_format( $failed['entry_lat'], 6 ); ?>, <?php echo number_format( $failed['entry_lng'], 6 ); ?>
                                        <?php if ( isset( $failed['entry_accuracy'] ) && $failed['entry_accuracy'] ): ?>
                                            (±<?php echo number_format( $failed['entry_accuracy'] ); ?>m)
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-family: monospace; font-size: 12px;">
                                        Lat: <?php echo number_format( $failed['entry_lat'], 6 ); ?>, 
                                        Lng: <?php echo number_format( $failed['entry_lng'], 6 ); ?>
                                        <?php if ( isset( $failed['entry_accuracy'] ) && $failed['entry_accuracy'] ): ?>
                                            (±<?php echo number_format( $failed['entry_accuracy'] ); ?>m)
                                        <?php endif; ?>
                                    </span>
                                    <br><small style="color: #999;">Unable to reverse geocode location</small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php endif; // End of $show_search_results conditional ?>
    
    <!-- Interpretation Guide -->
    <div class="guide-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Interpretation Guide</h2>
        
        <h3>Search Functionality</h3>
        <p style="line-height: 1.8;">
            Use the search box above to look up specific orders by Order ID or address. The search will show:
        </p>
        <ul style="line-height: 1.8;">
            <li><strong>Distance calculation:</strong> If the order has GPS data and the address is in the database.</li>
            <li><strong>No GPS data:</strong> Orders created via backoffice or when GPS was unavailable.</li>
            <li><strong>Parse failures:</strong> Detailed explanation of why the address couldn't be parsed (missing house number, street, etc.).</li>
            <li><strong>Not in database:</strong> Shows the parsed address components and explains it needs to be added to the address database.</li>
        </ul>
        
        <h3>Distance Categories</h3>
        <ul style="line-height: 1.8;">
            <li><strong style="color: #00a32a;">On-Site (&lt;50 ft):</strong> Order was entered at or very near the delivery address. Most likely a door-to-door sale.</li>
            <li><strong style="color: #4169E1;">Very Close (50-200 ft):</strong> Entered from nearby location, possibly parked on the street or at a neighbor's house.</li>
            <li><strong style="color: #72aee6;">Nearby (200-500 ft):</strong> Entered from the same block or nearby area.</li>
            <li><strong style="color: #f0b849;">Local (0.5 miles):</strong> Entered from within the local area but not at the address.</li>
            <li><strong style="color: #d63638;">Remote (&gt;0.5 miles):</strong> Order entered from a different location - possibly batched at home or office.</li>
        </ul>
        
        <h3>GPS Accuracy</h3>
        <ul style="line-height: 1.8;">
            <li><strong style="color: #00a32a;">High (&lt;30m):</strong> Very reliable GPS signal. Distance calculation is accurate.</li>
            <li><strong>Medium (30-100m):</strong> Typical GPS accuracy. Distance is reasonably accurate.</li>
            <li><strong style="color: #d63638;">Low (&gt;100m):</strong> Poor GPS signal. Distance may be inaccurate - take with caution.</li>
        </ul>
        
        <h3>Use Cases</h3>
        <ul style="line-height: 1.8;">
            <li><strong>Sales Training:</strong> Identify which sellers are doing true door-to-door vs. remote order entry.</li>
            <li><strong>Quality Control:</strong> Large distances might indicate address typos or wrong customer info.</li>
            <li><strong>Fraud Detection:</strong> Orders entered remotely with suspicious patterns.</li>
            <li><strong>Performance Analysis:</strong> Understand seller workflows and efficiency.</li>
        </ul>
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
