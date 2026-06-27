<?php
/**
 * Order GPS Distance Report
 * 
 * Shows how far away sellers were from the actual delivery address when they entered each order.
 * Compares order creation GPS (from phone) vs official address GPS (from address database).
 * 
 * @package Subsales_Management
 * @since 2.4.65
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// Get filter parameters
$distance_filter = isset( $_GET['distance_filter'] ) ? sanitize_text_field( $_GET['distance_filter'] ) : 'all';
$seller_filter = isset( $_GET['seller_filter'] ) ? sanitize_text_field( $_GET['seller_filter'] ) : 'all';
$has_gps_only = isset( $_GET['has_gps_only'] ) ? intval( $_GET['has_gps_only'] ) : 1;

// Fetch all orders with GPS coordinates
$query = "SELECT 
    o.id,
    o.order_id,
    o.created_at,
    o.address,
    o.order_data,
    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.geo.latitude')) as order_gps_lat,
    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.geo.longitude')) as order_gps_lng,
    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.geo.accuracy')) as gps_accuracy,
    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.customer')) as customer,
    JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.entered_by_name')) as seller
FROM {$wpdb->prefix}ss_orders o
WHERE o.deleted = 0";

if ( $has_gps_only ) {
    $query .= " AND JSON_EXTRACT(o.order_data, '$.geo.latitude') IS NOT NULL
                AND JSON_EXTRACT(o.order_data, '$.geo.longitude') IS NOT NULL";
}

$query .= " ORDER BY o.created_at DESC LIMIT 1000";

$orders = $wpdb->get_results( $query, ARRAY_A );

// Process each order to calculate distance
$processed_orders = array();
$sellers = array();

foreach ( $orders as $order ) {
    // Skip if no GPS data
    if ( empty( $order['order_gps_lat'] ) || empty( $order['order_gps_lng'] ) ) {
        continue;
    }
    
    $seller_name = ! empty( $order['seller'] ) ? $order['seller'] : 'Unknown';
    $sellers[ $seller_name ] = true;
    
    // Parse address to look up official coordinates
    $parsed_address = Subsales_Delivery::parse_address( $order['address'] );
    
    $distance_feet = null;
    $distance_miles = null;
    $official_lat = null;
    $official_lng = null;
    $address_found = false;
    
    if ( $parsed_address && ! empty( $parsed_address['house_number'] ) && ! empty( $parsed_address['street'] ) ) {
        // Look up address in database
        $address_query = "SELECT lat, lng FROM {$wpdb->prefix}ss_addresses 
                          WHERE LOWER(TRIM(street)) = %s 
                          AND LOWER(TRIM(house_number)) = %s";
        $params = array(
            strtolower( trim( $parsed_address['street'] ) ),
            strtolower( trim( $parsed_address['house_number'] ) )
        );
        
        if ( ! empty( $parsed_address['zip'] ) ) {
            $address_query .= " AND zip = %s";
            $params[] = $parsed_address['zip'];
        }
        
        $address_query .= " LIMIT 1";
        
        $address_row = $wpdb->get_row( $wpdb->prepare( $address_query, $params ), ARRAY_A );
        
        if ( $address_row ) {
            $address_found = true;
            $official_lat = floatval( $address_row['lat'] );
            $official_lng = floatval( $address_row['lng'] );
            
            // Calculate distance using Haversine formula
            $order_lat = floatval( $order['order_gps_lat'] );
            $order_lng = floatval( $order['order_gps_lng'] );
            
            $distance_miles = 3959 * acos(
                cos( deg2rad( $official_lat ) ) 
                * cos( deg2rad( $order_lat ) ) 
                * cos( deg2rad( $order_lng ) - deg2rad( $official_lng ) ) 
                + sin( deg2rad( $official_lat ) ) 
                * sin( deg2rad( $order_lat ) )
            );
            
            $distance_feet = $distance_miles * 5280;
        }
    }
    
    // Apply distance filter
    if ( $distance_filter !== 'all' && $address_found ) {
        $skip = false;
        switch ( $distance_filter ) {
            case 'onsite': // 0-100 feet
                if ( $distance_feet > 100 ) $skip = true;
                break;
            case 'nearby': // 100-500 feet
                if ( $distance_feet <= 100 || $distance_feet > 500 ) $skip = true;
                break;
            case 'remote': // 500+ feet
                if ( $distance_feet <= 500 ) $skip = true;
                break;
            case 'far': // 1+ miles
                if ( $distance_miles < 1 ) $skip = true;
                break;
            case 'no_address': // Address not in database
                $skip = true; // Already filtered out
                break;
        }
        if ( $skip ) continue;
    } elseif ( $distance_filter === 'no_address' && $address_found ) {
        continue;
    }
    
    // Apply seller filter
    if ( $seller_filter !== 'all' && $seller_name !== $seller_filter ) {
        continue;
    }
    
    $processed_orders[] = array(
        'id' => $order['id'],
        'order_id' => $order['order_id'],
        'created_at' => $order['created_at'],
        'address' => $order['address'],
        'customer' => $order['customer'],
        'seller' => $seller_name,
        'order_gps_lat' => $order['order_gps_lat'],
        'order_gps_lng' => $order['order_gps_lng'],
        'gps_accuracy' => $order['gps_accuracy'],
        'official_lat' => $official_lat,
        'official_lng' => $official_lng,
        'address_found' => $address_found,
        'distance_feet' => $distance_feet,
        'distance_miles' => $distance_miles
    );
}

// Calculate statistics
$total_with_gps = count( $processed_orders );
$onsite_count = 0;  // 0-100 ft
$nearby_count = 0;  // 100-500 ft
$remote_count = 0;  // 500+ ft
$far_count = 0;     // 1+ miles
$no_address_count = 0;

foreach ( $processed_orders as $order ) {
    if ( ! $order['address_found'] ) {
        $no_address_count++;
    } elseif ( $order['distance_feet'] <= 100 ) {
        $onsite_count++;
    } elseif ( $order['distance_feet'] <= 500 ) {
        $nearby_count++;
    } elseif ( $order['distance_miles'] < 1 ) {
        $remote_count++;
    } else {
        $far_count++;
    }
}

$sellers_array = array_keys( $sellers );
sort( $sellers_array );
?>

<div class="wrap">
    <h1>📍 Order GPS Distance Analysis</h1>
    <p class="description">
        Shows how far away each seller was from the delivery address when they entered the order. 
        Compares order creation GPS (phone location) vs official address GPS (from address database).
    </p>
    
    <a href="<?php echo admin_url( 'admin.php?page=subsales-reports' ); ?>" class="button" style="margin-bottom: 20px;">← Back to Reports</a>
    
    <!-- Summary Statistics -->
    <div class="stats-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0;">
        <div class="stat-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
            <div style="font-size: 32px; font-weight: bold; color: #2271b1;"><?php echo $total_with_gps; ?></div>
            <div style="color: #646970; margin-top: 5px;">Orders with GPS</div>
        </div>
        
        <div class="stat-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
            <div style="font-size: 32px; font-weight: bold; color: #00a32a;"><?php echo $onsite_count; ?></div>
            <div style="color: #646970; margin-top: 5px;">On-site (0-100 ft)</div>
        </div>
        
        <div class="stat-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
            <div style="font-size: 32px; font-weight: bold; color: #4169E1;"><?php echo $nearby_count; ?></div>
            <div style="color: #646970; margin-top: 5px;">Nearby (100-500 ft)</div>
        </div>
        
        <div class="stat-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
            <div style="font-size: 32px; font-weight: bold; color: #dba617;"><?php echo $remote_count; ?></div>
            <div style="color: #646970; margin-top: 5px;">Remote (500+ ft)</div>
        </div>
        
        <div class="stat-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
            <div style="font-size: 32px; font-weight: bold; color: #d63638;"><?php echo $far_count; ?></div>
            <div style="color: #646970; margin-top: 5px;">Far Away (1+ mi)</div>
        </div>
        
        <div class="stat-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px;">
            <div style="font-size: 32px; font-weight: bold; color: #999;"><?php echo $no_address_count; ?></div>
            <div style="color: #646970; margin-top: 5px;">No Address Match</div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filters-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 15px; margin: 20px 0;">
        <form method="get" action="">
            <input type="hidden" name="page" value="subsales-order-gps-distance" />
            
            <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div>
                    <label for="distance_filter" style="display: block; margin-bottom: 5px; font-weight: 600;">Distance Filter</label>
                    <select name="distance_filter" id="distance_filter" style="width: 200px;">
                        <option value="all" <?php selected( $distance_filter, 'all' ); ?>>All Orders</option>
                        <option value="onsite" <?php selected( $distance_filter, 'onsite' ); ?>>On-site (0-100 ft)</option>
                        <option value="nearby" <?php selected( $distance_filter, 'nearby' ); ?>>Nearby (100-500 ft)</option>
                        <option value="remote" <?php selected( $distance_filter, 'remote' ); ?>>Remote (500+ ft)</option>
                        <option value="far" <?php selected( $distance_filter, 'far' ); ?>>Far Away (1+ mi)</option>
                        <option value="no_address" <?php selected( $distance_filter, 'no_address' ); ?>>No Address Match</option>
                    </select>
                </div>
                
                <div>
                    <label for="seller_filter" style="display: block; margin-bottom: 5px; font-weight: 600;">Seller Filter</label>
                    <select name="seller_filter" id="seller_filter" style="width: 200px;">
                        <option value="all" <?php selected( $seller_filter, 'all' ); ?>>All Sellers</option>
                        <?php foreach ( $sellers_array as $seller ): ?>
                            <option value="<?php echo esc_attr( $seller ); ?>" <?php selected( $seller_filter, $seller ); ?>>
                                <?php echo esc_html( $seller ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label style="display: flex; align-items: center; gap: 5px;">
                        <input type="checkbox" name="has_gps_only" value="1" <?php checked( $has_gps_only, 1 ); ?> />
                        Only orders with GPS data
                    </label>
                </div>
                
                <button type="submit" class="button button-primary">Apply Filters</button>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-order-gps-distance' ); ?>" class="button">Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Results Table -->
    <div class="results-card" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2 style="margin-top: 0;">Orders (<?php echo count( $processed_orders ); ?> shown)</h2>
        
        <?php if ( empty( $processed_orders ) ): ?>
            <p style="color: #646970; text-align: center; padding: 40px;">
                No orders found matching the current filters.
            </p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 120px;">Order ID</th>
                        <th style="width: 130px;">Date</th>
                        <th style="width: 150px;">Seller</th>
                        <th>Customer / Address</th>
                        <th style="width: 120px;">Entry Distance</th>
                        <th style="width: 90px;">GPS Accuracy</th>
                        <th style="width: 80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $processed_orders as $order ): ?>
                        <?php
                        // Determine color coding
                        $distance_color = '#999';
                        $distance_label = 'Unknown';
                        $distance_class = '';
                        
                        if ( ! $order['address_found'] ) {
                            $distance_label = 'No Address';
                            $distance_color = '#999';
                        } elseif ( $order['distance_feet'] <= 100 ) {
                            $distance_label = number_format( $order['distance_feet'] ) . ' ft';
                            $distance_color = '#00a32a';
                            $distance_class = 'onsite';
                        } elseif ( $order['distance_feet'] <= 500 ) {
                            $distance_label = number_format( $order['distance_feet'] ) . ' ft';
                            $distance_color = '#4169E1';
                            $distance_class = 'nearby';
                        } elseif ( $order['distance_miles'] < 1 ) {
                            $distance_label = number_format( $order['distance_feet'] ) . ' ft';
                            $distance_color = '#dba617';
                            $distance_class = 'remote';
                        } else {
                            $distance_label = number_format( $order['distance_miles'], 2 ) . ' mi';
                            $distance_color = '#d63638';
                            $distance_class = 'far';
                        }
                        
                        $accuracy_color = '#646970';
                        if ( $order['gps_accuracy'] ) {
                            if ( $order['gps_accuracy'] < 30 ) {
                                $accuracy_color = '#00a32a';
                            } elseif ( $order['gps_accuracy'] > 100 ) {
                                $accuracy_color = '#d63638';
                            }
                        }
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . urlencode( $order['order_id'] ) ); ?>" 
                                   style="font-weight: bold; text-decoration: none;">
                                    <?php echo esc_html( $order['order_id'] ); ?>
                                </a>
                            </td>
                            <td>
                                <?php 
                                $dt = new DateTime( $order['created_at'], new DateTimeZone( 'UTC' ) );
                                $dt->setTimezone( new DateTimeZone( get_option( 'timezone_string' ) ?: 'America/New_York' ) );
                                echo $dt->format( 'M j, Y g:i A' ); 
                                ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html( $order['seller'] ); ?></strong>
                            </td>
                            <td>
                                <?php if ( ! empty( $order['customer'] ) ): ?>
                                    <strong><?php echo esc_html( $order['customer'] ); ?></strong><br>
                                <?php endif; ?>
                                <span style="color: #646970;"><?php echo esc_html( $order['address'] ); ?></span>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: <?php echo esc_attr( $distance_color ); ?>;"></div>
                                    <strong style="color: <?php echo esc_attr( $distance_color ); ?>;">
                                        <?php echo esc_html( $distance_label ); ?>
                                    </strong>
                                </div>
                                <?php if ( $order['address_found'] && $order['distance_miles'] >= 0.1 ): ?>
                                    <small style="color: #646970;">(<?php echo number_format( $order['distance_miles'], 2 ); ?> mi)</small>
                                <?php endif; ?>
                            </td>
                            <td style="color: <?php echo esc_attr( $accuracy_color ); ?>;">
                                <?php if ( $order['gps_accuracy'] ): ?>
                                    ±<?php echo number_format( $order['gps_accuracy'] ); ?>m
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $order['order_gps_lat'] && $order['official_lat'] ): ?>
                                    <a href="https://www.google.com/maps/dir/<?php 
                                        echo esc_attr( $order['order_gps_lat'] . ',' . $order['order_gps_lng'] ); 
                                    ?>/<?php 
                                        echo esc_attr( $order['official_lat'] . ',' . $order['official_lng'] ); 
                                    ?>" 
                                       target="_blank" 
                                       class="button button-small"
                                       title="View route in Google Maps">
                                        🗺️ Map
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Legend -->
    <div class="legend-card" style="background: #f0f0f1; border-radius: 4px; padding: 15px; margin: 20px 0;">
        <h3 style="margin-top: 0;">Understanding the Results</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
            <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <div style="width: 12px; height: 12px; border-radius: 50%; background: #00a32a;"></div>
                    <strong>On-site (0-100 ft)</strong>
                </div>
                <p style="margin: 0; color: #646970; font-size: 13px;">
                    Order entered at or very near the delivery address. Typical for door-to-door sales.
                </p>
            </div>
            
            <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <div style="width: 12px; height: 12px; border-radius: 50%; background: #4169E1;"></div>
                    <strong>Nearby (100-500 ft)</strong>
                </div>
                <p style="margin: 0; color: #646970; font-size: 13px;">
                    Order entered close to the address. Could be parked on street or at neighbor's house.
                </p>
            </div>
            
            <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <div style="width: 12px; height: 12px; border-radius: 50%; background: #dba617;"></div>
                    <strong>Remote (500+ ft)</strong>
                </div>
                <p style="margin: 0; color: #646970; font-size: 13px;">
                    Order entered away from the delivery address. Could indicate batch entry or phone orders.
                </p>
            </div>
            
            <div>
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                    <div style="width: 12px; height: 12px; border-radius: 50%; background: #d63638;"></div>
                    <strong>Far Away (1+ mi)</strong>
                </div>
                <p style="margin: 0; color: #646970; font-size: 13px;">
                    Order entered from a completely different location. Batch entry from home/office.
                </p>
            </div>
        </div>
        
        <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">
            <h4 style="margin-top: 0;">GPS Accuracy Colors</h4>
            <ul style="margin: 0; padding-left: 20px;">
                <li style="color: #00a32a;"><strong>Green:</strong> High precision (&lt;30m) - very reliable</li>
                <li style="color: #646970;"><strong>Gray:</strong> Medium precision (30-100m) - generally reliable</li>
                <li style="color: #d63638;"><strong>Red:</strong> Low precision (&gt;100m) - less reliable, distance may be inaccurate</li>
            </ul>
        </div>
    </div>
</div>

<style>
.stat-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
    transition: all 0.2s ease;
}
</style>
