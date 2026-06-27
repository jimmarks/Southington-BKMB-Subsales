<?php
/**
 * GPS Proximity Report
 * 
 * Search for PWA activity near a specific address using GPS heartbeat logs.
 * Useful for finding missing orders when you know the delivery address.
 * 
 * @package Subsales_Management
 * @since 2.4.64
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// Handle form submission
$results = null;
$search_performed = false;
$target_address_info = null;

if ( isset( $_POST['search_address'] ) && check_admin_referer( 'gps_proximity_search' ) ) {
    $search_performed = true;
    
    $street = sanitize_text_field( $_POST['street'] );
    $house_number = sanitize_text_field( $_POST['house_number'] );
    $radius_miles = floatval( $_POST['radius_miles'] );
    $date_start = sanitize_text_field( $_POST['date_start'] );
    $date_end = sanitize_text_field( $_POST['date_end'] );
    
    // Validate inputs
    if ( empty( $street ) || empty( $house_number ) ) {
        $search_performed = false;
    } else {
        // Look up address in database to get coordinates
        $address_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, CONCAT(house_number, ' ', street, ', ', city, ', ', state, ' ', zip) as full_address, 
                    lat, lng, street, house_number, city, state, zip
             FROM {$wpdb->prefix}ss_addresses
             WHERE LOWER(TRIM(street)) = %s
               AND LOWER(TRIM(house_number)) = %s
             LIMIT 1",
            strtolower( trim( $street ) ),
            strtolower( trim( $house_number ) )
        ), ARRAY_A );
        
        if ( ! $address_row ) {
            $target_address_info = array( 'error' => 'Address not found in database. Please check the street name and house number.' );
        } else {
            $target_address_info = $address_row;
            
            // Search for nearby PWA heartbeats
            $query = "SELECT 
                h.id,
                h.session_id,
                h.heartbeat_at,
                h.gps_latitude,
                h.gps_longitude,
                h.gps_accuracy,
                s.user_name,
                s.team_name,
                s.user_agent,
                ROUND(
                    3959 * acos(
                        cos(radians(%f)) 
                        * cos(radians(h.gps_latitude)) 
                        * cos(radians(h.gps_longitude) - radians(%f)) 
                        + sin(radians(%f)) 
                        * sin(radians(h.gps_latitude))
                    ) * 5280
                ) AS distance_feet,
                ROUND(
                    3959 * acos(
                        cos(radians(%f)) 
                        * cos(radians(h.gps_latitude)) 
                        * cos(radians(h.gps_longitude) - radians(%f)) 
                        + sin(radians(%f)) 
                        * sin(radians(h.gps_latitude))
                    ), 4
                ) AS distance_miles
            FROM {$wpdb->prefix}ss_pwa_heartbeats h
            LEFT JOIN {$wpdb->prefix}ss_pwa_sessions s ON h.session_id = s.session_id
            WHERE h.gps_latitude IS NOT NULL
              AND h.gps_longitude IS NOT NULL
              AND h.heartbeat_at BETWEEN %s AND %s
            HAVING distance_miles <= %f
            ORDER BY distance_miles ASC, h.heartbeat_at DESC
            LIMIT 500";
            
            $results = $wpdb->get_results( $wpdb->prepare(
                $query,
                floatval( $address_row['lat'] ),
                floatval( $address_row['lng'] ),
                floatval( $address_row['lat'] ),
                floatval( $address_row['lat'] ),
                floatval( $address_row['lng'] ),
                floatval( $address_row['lat'] ),
                $date_start . ' 00:00:00',
                $date_end . ' 23:59:59',
                $radius_miles
            ), ARRAY_A );
            
            // For each result, find nearby orders
            foreach ( $results as &$result ) {
                $heartbeat_time = $result['heartbeat_at'];
                $user_name = $result['user_name'];
                
                // Find orders created by this user around this time (±10 minutes)
                $nearby_orders = $wpdb->get_results( $wpdb->prepare(
                    "SELECT o.order_id, o.created_at, 
                            JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.address')) as order_address,
                            JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.customer')) as customer
                     FROM {$wpdb->prefix}ss_orders o
                     INNER JOIN {$wpdb->prefix}ss_team_members m ON o.user_id = m.id
                     WHERE m.name = %s
                       AND o.deleted = 0
                       AND o.created_at BETWEEN DATE_SUB(%s, INTERVAL 10 MINUTE) 
                                            AND DATE_ADD(%s, INTERVAL 10 MINUTE)
                     ORDER BY o.created_at DESC",
                    $user_name,
                    $heartbeat_time,
                    $heartbeat_time
                ), ARRAY_A );
                
                $result['nearby_orders'] = $nearby_orders;
            }
        }
    }
}

// Default form values
$default_street = isset( $_POST['street'] ) ? sanitize_text_field( $_POST['street'] ) : '';
$default_house = isset( $_POST['house_number'] ) ? sanitize_text_field( $_POST['house_number'] ) : '';
$default_radius = isset( $_POST['radius_miles'] ) ? floatval( $_POST['radius_miles'] ) : 0.1;
$default_date_start = isset( $_POST['date_start'] ) ? sanitize_text_field( $_POST['date_start'] ) : date( 'Y-m-d', strtotime( '-7 days' ) );
$default_date_end = isset( $_POST['date_end'] ) ? sanitize_text_field( $_POST['date_end'] ) : date( 'Y-m-d' );
?>

<div class="wrap">
    <h1>📍 GPS Proximity Search</h1>
    <p class="description">
        Find who was near a specific address by searching PWA heartbeat GPS logs. 
        Useful when orders are missing but you know the delivery address.
    </p>
    
    <a href="<?php echo admin_url( 'admin.php?page=subsales-reports' ); ?>" class="button" style="margin-bottom: 20px;">← Back to Reports</a>
    
    <!-- Search Form -->
    <div class="search-form-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Search Parameters</h2>
        
        <form method="post" action="">
            <?php wp_nonce_field( 'gps_proximity_search' ); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="house_number">House Number *</label></th>
                    <td>
                        <input type="text" id="house_number" name="house_number" 
                               class="regular-text" 
                               value="<?php echo esc_attr( $default_house ); ?>" 
                               required
                               placeholder="123" />
                        <p class="description">Example: 1283</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="street">Street Name *</label></th>
                    <td>
                        <input type="text" id="street" name="street" 
                               class="regular-text" 
                               value="<?php echo esc_attr( $default_street ); ?>" 
                               required
                               placeholder="MAIN ST" />
                        <p class="description">Example: PLEASANT ST (uppercase, no directional prefix like N/S/E/W)</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="radius_miles">Search Radius (miles)</label></th>
                    <td>
                        <input type="number" id="radius_miles" name="radius_miles" 
                               step="0.01" min="0.01" max="1" 
                               value="<?php echo esc_attr( $default_radius ); ?>" 
                               style="width: 100px;" />
                        <p class="description">0.1 miles ≈ 528 feet. Typical GPS accuracy is 30-50 feet.</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><label for="date_start">Date Range</label></th>
                    <td>
                        <input type="date" id="date_start" name="date_start" 
                               value="<?php echo esc_attr( $default_date_start ); ?>" />
                        to
                        <input type="date" id="date_end" name="date_end" 
                               value="<?php echo esc_attr( $default_date_end ); ?>" />
                        <p class="description">When might the missing order have been placed?</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" name="search_address" class="button button-primary">
                    🔍 Search GPS Logs
                </button>
            </p>
        </form>
    </div>
    
    <?php if ( $search_performed ): ?>
        <?php if ( isset( $target_address_info['error'] ) ): ?>
            <!-- Address Not Found -->
            <div class="notice notice-error" style="margin: 20px 0; padding: 15px;">
                <p><strong>⚠️ <?php echo esc_html( $target_address_info['error'] ); ?></strong></p>
                <p>This address must exist in your <code>wp_ss_addresses</code> table to search by GPS proximity.</p>
                <p>
                    Try: 
                    <a href="<?php echo admin_url( 'admin.php?page=subsales-address-coverage' ); ?>">Address Coverage Report</a> 
                    to see if the address needs geocoding, or 
                    <a href="<?php echo admin_url( 'admin.php?page=subsales-settings&tab=address-management' ); ?>">import addresses from shapefile/CSV</a>.
                </p>
            </div>
        <?php else: ?>
            <!-- Search Results -->
            <div class="results-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; margin: 20px 0;">
                <h2 style="margin-top: 0;">Search Results</h2>
                
                <div class="target-address-info" style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <h3 style="margin-top: 0;">🎯 Target Address</h3>
                    <p style="font-size: 16px; margin: 5px 0;">
                        <strong><?php echo esc_html( $target_address_info['full_address'] ); ?></strong>
                    </p>
                    <p style="margin: 5px 0; color: #666;">
                        GPS: <?php echo number_format( $target_address_info['lat'], 6 ); ?>, 
                        <?php echo number_format( $target_address_info['lng'], 6 ); ?>
                    </p>
                </div>
                
                <?php if ( empty( $results ) ): ?>
                    <div class="notice notice-warning inline" style="margin: 0;">
                        <p><strong>No GPS activity found near this address.</strong></p>
                        <p>Try:</p>
                        <ul style="margin-left: 20px;">
                            <li>Increasing the search radius to 0.2 or 0.3 miles</li>
                            <li>Expanding the date range</li>
                            <li>Verifying the address is correct (check street spelling)</li>
                        </ul>
                    </div>
                <?php else: ?>
                    <p style="margin-bottom: 15px;">
                        <strong>Found <?php echo count( $results ); ?> GPS heartbeat<?php echo count( $results ) != 1 ? 's' : ''; ?></strong> 
                        within <?php echo esc_html( $default_radius ); ?> miles of this address.
                    </p>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Timestamp</th>
                                <th style="width: 150px;">User / Team</th>
                                <th style="width: 100px;">Distance</th>
                                <th style="width: 80px;">GPS Accuracy</th>
                                <th>Nearby Orders (±10 min)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $results as $result ): ?>
                                <?php
                                $distance_class = '';
                                if ( $result['distance_feet'] < 100 ) {
                                    $distance_class = 'style="color: #008000; font-weight: bold;"';
                                } elseif ( $result['distance_feet'] < 300 ) {
                                    $distance_class = 'style="color: #4169E1;"';
                                }
                                
                                $accuracy_class = '';
                                if ( $result['gps_accuracy'] < 30 ) {
                                    $accuracy_class = 'style="color: #008000;"';
                                } elseif ( $result['gps_accuracy'] > 100 ) {
                                    $accuracy_class = 'style="color: #d63638;"';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $dt = new DateTime( $result['heartbeat_at'], new DateTimeZone( 'UTC' ) );
                                        $dt->setTimezone( new DateTimeZone( get_option( 'timezone_string' ) ?: 'America/New_York' ) );
                                        echo $dt->format( 'M j, Y g:i A' ); 
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html( $result['user_name'] ?: 'Unknown' ); ?></strong><br>
                                        <small style="color: #666;"><?php echo esc_html( $result['team_name'] ?: 'No team' ); ?></small>
                                    </td>
                                    <td <?php echo $distance_class; ?>>
                                        <strong><?php echo number_format( $result['distance_feet'] ); ?> ft</strong><br>
                                        <small>(<?php echo number_format( $result['distance_miles'], 4 ); ?> mi)</small>
                                    </td>
                                    <td <?php echo $accuracy_class; ?>>
                                        ±<?php echo number_format( $result['gps_accuracy'] ); ?>m
                                    </td>
                                    <td>
                                        <?php if ( empty( $result['nearby_orders'] ) ): ?>
                                            <em style="color: #999;">No orders found</em>
                                        <?php else: ?>
                                            <?php foreach ( $result['nearby_orders'] as $order ): ?>
                                                <div style="margin-bottom: 8px; padding: 8px; background: #f0f6fc; border-left: 3px solid #2271b1;">
                                                    <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . urlencode( $order['order_id'] ) ); ?>" 
                                                       style="font-weight: bold; text-decoration: none;">
                                                        Order <?php echo esc_html( $order['order_id'] ); ?>
                                                    </a><br>
                                                    <small style="color: #666;">
                                                        <?php 
                                                        $order_dt = new DateTime( $order['created_at'], new DateTimeZone( 'UTC' ) );
                                                        $order_dt->setTimezone( new DateTimeZone( get_option( 'timezone_string' ) ?: 'America/New_York' ) );
                                                        echo $order_dt->format( 'g:i A' ); 
                                                        ?>
                                                    </small><br>
                                                    <?php if ( ! empty( $order['customer'] ) ): ?>
                                                        <small><strong><?php echo esc_html( $order['customer'] ); ?></strong></small><br>
                                                    <?php endif; ?>
                                                    <?php if ( ! empty( $order['order_address'] ) ): ?>
                                                        <small style="color: #666;"><?php echo esc_html( $order['order_address'] ); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="legend" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                        <h3 style="margin-top: 0;">Legend</h3>
                        <ul style="list-style: none; padding: 0; margin: 0;">
                            <li style="margin-bottom: 8px;">
                                <span style="color: #008000; font-weight: bold;">Green distance:</span> 
                                Very close (&lt;100 ft) - likely at exact address
                            </li>
                            <li style="margin-bottom: 8px;">
                                <span style="color: #4169E1; font-weight: bold;">Blue distance:</span> 
                                Nearby (100-300 ft) - possibly same property or neighbor
                            </li>
                            <li style="margin-bottom: 8px;">
                                <span style="color: #008000;">Green accuracy:</span> 
                                High precision GPS (&lt;30m)
                            </li>
                            <li>
                                <span style="color: #d63638;">Red accuracy:</span> 
                                Low precision GPS (&gt;100m) - less reliable
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<style>
.search-form-card h2 {
    font-size: 18px;
    color: #1d2327;
}

.results-card h2 {
    font-size: 20px;
    color: #1d2327;
}

.wp-list-table td {
    vertical-align: top;
}

.legend ul li {
    line-height: 1.6;
}
</style>
