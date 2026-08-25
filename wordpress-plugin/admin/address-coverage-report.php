<?php
/**
 * Address Coverage Report
 * Shows which orders have coordinates and which need geocoding
 * 
 * @package Subsales_Management
 * @since 2.4.19
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;

// DEBUG: Check if wp_ss_addresses table exists and has data
$address_table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}ss_addresses'" );
$address_count = 0;
if ( $address_table_exists ) {
    $address_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ss_addresses" );
}
echo "<!-- DEBUG: wp_ss_addresses table " . ( $address_table_exists ? "EXISTS" : "DOES NOT EXIST" ) . ", contains {$address_count} addresses -->\n";

// DEBUG: Check what's in database for known problem addresses
if ( $address_table_exists && $address_count > 0 ) {
    $test_addresses = array(
        array( 'street' => 'PLEASANT ST', 'house' => '1283' ),
        array( 'street' => 'EMPRESS DR', 'house' => '77' ),
        array( 'street' => 'PILGRIM LN', 'house' => '253' )
    );
    
    foreach ( $test_addresses as $test ) {
        $found = $wpdb->get_row( $wpdb->prepare(
            "SELECT street, house_number, city, zip FROM {$wpdb->prefix}ss_addresses 
             WHERE LOWER(TRIM(street)) = %s AND LOWER(TRIM(house_number)) = %s LIMIT 1",
            strtolower( $test['street'] ),
            $test['house']
        ), ARRAY_A );
        
        if ( $found ) {
            echo "<!-- DEBUG: Database HAS {$test['house']} {$test['street']}: " . print_r( $found, true ) . " -->\n";
        } else {
            echo "<!-- DEBUG: Database MISSING {$test['house']} {$test['street']} -->\n";
        }
    }
}

// Get all non-deleted orders, scoped to the current season
$orders = $wpdb->get_results( $wpdb->prepare(
    "SELECT id, order_id, order_data FROM {$wpdb->prefix}ss_orders WHERE deleted = 0 AND season_id = %d ORDER BY id ASC",
    intval( get_option( 'subsales_current_season_id' ) )
), ARRAY_A );

// Get products config to filter donation-only orders
$configured_products = order_sync_get_products_config();

$total_orders = count( $orders );
$matched_in_db = 0;
$cached_geocodes = 0;
$need_geocoding = 0;
$no_address = 0;
$bad_address = 0;
$donation_only = 0;

$unmatched_addresses = array(); // Format: address => ['count' => int, 'debug' => array, 'orders' => array]
$bad_addresses = array();

foreach ( $orders as $order ) {
    $od = json_decode( $order['order_data'], true );
    if ( ! is_array( $od ) ) continue;
    
    // Reset debug info for each order
    $debug_info = null;
    
    // EXCLUDE DONATION-ONLY ORDERS (no products to deliver)
    $has_products = false;
    if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
        foreach ( $od['products'] as $product ) {
            if ( isset( $product['qty'] ) && intval( $product['qty'] ) > 0 ) {
                $has_products = true;
                break;
            }
        }
    } else {
        // Old format: check each product ID
        foreach ( $configured_products as $pconf ) {
            if ( isset( $pconf['id'] ) && isset( $od[ $pconf['id'] ] ) && intval( $od[ $pconf['id'] ] ) > 0 ) {
                $has_products = true;
                break;
            }
        }
    }
    
    if ( ! $has_products ) {
        $donation_only++;
        continue; // Skip donation-only orders
    }
    
    $address = ! empty( $od['address'] ) ? $od['address'] : '';
    
    if ( empty( $address ) ) {
        $no_address++;
        continue;
    }
    
    // Check if address is deliverable (not garbage data)
    if ( ! Subsales_Delivery::is_address_deliverable( $address ) ) {
        $bad_address++;
        if ( ! isset( $bad_addresses[ $address ] ) ) {
            $bad_addresses[ $address ] = 0;
        }
        $bad_addresses[ $address ]++;
        continue;
    }
    
    // Parse address into structured components
    $parsed = Subsales_Delivery::parse_address( $address );
    
    // Only require house_number and street (ZIP is optional)
    if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
        // Can't parse - will need geocoding
        $need_geocoding++;
        
        // Collect debug info for parse failures
        $debug_info = array(
            'parsed' => $parsed,
            'searching' => array(),
            'db_sample' => null,
            'order_id' => $order['order_id'],
            'parse_failed' => true,
            'reason' => 'Could not extract house_number and/or street from address'
        );
        
        if ( ! isset( $unmatched_addresses[ $address ] ) ) {
            $unmatched_addresses[ $address ] = array(
                'count' => 0,
                'debug' => $debug_info,
                'orders' => array()
            );
        }
        $unmatched_addresses[ $address ]['count']++;
        $unmatched_addresses[ $address ]['orders'][] = $order['order_id'];
        
        continue;
    }
    
    // Check wp_ss_addresses using structured matching (house_number + street required, ZIP IGNORED)
    $query = "SELECT COUNT(*) FROM {$wpdb->prefix}ss_addresses 
              WHERE LOWER(TRIM(street)) = %s 
              AND LOWER(TRIM(house_number)) = %s";
    $params = array(
        strtolower( trim( $parsed['street'] ) ),
        strtolower( trim( $parsed['house_number'] ) )
    );
    
    // If unit specified, match it too
    if ( ! empty( $parsed['unit'] ) ) {
        $query .= " AND LOWER(TRIM(unit)) = %s";
        $params[] = strtolower( trim( $parsed['unit'] ) );
    }
    
    $in_address_db = $wpdb->get_var( $wpdb->prepare( $query, $params ) );
    
    if ( $in_address_db > 0 ) {
        $matched_in_db++;
        continue;
    }
    
    // Check geocode cache
    $address_normalized = strtolower( trim( preg_replace( '/\s+/', ' ', $address ) ) );
    $in_cache = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}order_sync_geocodes WHERE LOWER(TRIM(address)) = %s",
        $address_normalized
    ) );
    
    if ( $in_cache > 0 ) {
        $cached_geocodes++;
        continue;
    }
    
    // Will need Google API - ALWAYS collect debug info for unmatched addresses
    $street_search = strtolower( trim( $parsed['street'] ) );
    $house_search = strtolower( trim( $parsed['house_number'] ) );
    $db_sample = $wpdb->get_row( $wpdb->prepare(
        "SELECT street, house_number, city, state, zip FROM {$wpdb->prefix}ss_addresses 
         WHERE LOWER(TRIM(street)) LIKE %s 
         AND LOWER(TRIM(house_number)) = %s 
         LIMIT 1",
        '%' . $wpdb->esc_like( $street_search ) . '%',
        $house_search
    ), ARRAY_A );
    
    $debug_info = array(
        'parsed' => $parsed,
        'searching' => array(
            'street' => $street_search,
            'house_number' => $house_search,
            'zip' => $parsed['zip'],
            'unit' => ! empty( $parsed['unit'] ) ? strtolower( trim( $parsed['unit'] ) ) : ''
        ),
        'db_sample' => $db_sample,
        'db_count' => $in_address_db,
        'order_id' => $order['order_id']
    );
    
    $need_geocoding++;
    if ( ! isset( $unmatched_addresses[ $address ] ) ) {
        $unmatched_addresses[ $address ] = array(
            'count' => 0,
            'debug' => $debug_info,
            'orders' => array()
        );
    }
    $unmatched_addresses[ $address ]['count']++;
    $unmatched_addresses[ $address ]['orders'][] = $order['order_id'];
}

// Count unique addresses that need geocoding
$unique_need_geocoding = count( $unmatched_addresses );

// Estimate cost (Google Maps Geocoding API: $5 per 1000 requests)
$estimated_cost = ( $unique_need_geocoding / 1000 ) * 5;

?>
<div class="wrap">
    <h1>Address Coverage Report <span style="font-size: 12px; color: #666;">(Plugin v<?php echo defined('SUBSALES_VERSION') ? SUBSALES_VERSION : 'unknown'; ?>)</span></h1>
    
    <?php if ( ! empty( $unmatched_addresses ) ): ?>
    <div style="background: #fff3cd; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107;">
        <h3 style="margin-top: 0;">🔍 Debug Array Dump (First 3 Addresses)</h3>
        <pre style="background: #f8f9fa; padding: 10px; overflow: auto; max-height: 400px; font-size: 11px;"><?php 
            $sample = array_slice( $unmatched_addresses, 0, 3, true );
            echo esc_html( print_r( $sample, true ) ); 
        ?></pre>
    </div>
    <?php endif; ?>
    
    <p>This report shows which orders have coordinates available and which will require Google Maps API geocoding.</p>
    
    <div class="address-coverage-summary">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Count</th>
                    <th>Percentage</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Total Orders</strong></td>
                    <td><strong><?php echo number_format( $total_orders ); ?></strong></td>
                    <td>100%</td>
                    <td>All non-deleted orders</td>
                </tr>
                <tr style="background-color: #e8f4f8;">
                    <td>→ Donation-only (no products)</td>
                    <td><?php echo number_format( $donation_only ); ?></td>
                    <td><?php echo $total_orders > 0 ? number_format( ( $donation_only / $total_orders ) * 100, 1 ) : 0; ?>%</td>
                    <td>Excluded from delivery (no physical products to deliver)</td>
                </tr>
                <tr style="background-color: #d4edda;">
                    <td>✓ Matched in wp_ss_addresses</td>
                    <td><?php echo number_format( $matched_in_db ); ?></td>
                    <td><?php echo $total_orders > 0 ? number_format( ( $matched_in_db / $total_orders ) * 100, 1 ) : 0; ?>%</td>
                    <td>Coordinates available from address database (FREE)</td>
                </tr>
                <tr style="background-color: #d1ecf1;">
                    <td>✓ Cached geocodes</td>
                    <td><?php echo number_format( $cached_geocodes ); ?></td>
                    <td><?php echo $total_orders > 0 ? number_format( ( $cached_geocodes / $total_orders ) * 100, 1 ) : 0; ?>%</td>
                    <td>Previously geocoded, cached (FREE)</td>
                </tr>
                <tr style="background-color: #fff3cd;">
                    <td>⚠ Need Google API geocoding</td>
                    <td><?php echo number_format( $need_geocoding ); ?> orders<br>(<?php echo number_format( $unique_need_geocoding ); ?> unique addresses)</td>
                    <td><?php echo $total_orders > 0 ? number_format( ( $need_geocoding / $total_orders ) * 100, 1 ) : 0; ?>%</td>
                    <td>Will call Google Maps API (COSTS MONEY)</td>
                </tr>
                <tr style="background-color: #f8d7da;">
                    <td>✗ No address</td>
                    <td><?php echo number_format( $no_address ); ?></td>
                    <td><?php echo $total_orders > 0 ? number_format( ( $no_address / $total_orders ) * 100, 1 ) : 0; ?>%</td>
                    <td>Orders missing delivery address</td>
                </tr>
                <tr style="background-color: #f8d7da;">
                    <td>✗ Invalid/undeliverable address</td>
                    <td><?php echo number_format( $bad_address ); ?></td>
                    <td><?php echo $total_orders > 0 ? number_format( ( $bad_address / $total_orders ) * 100, 1 ) : 0; ?>%</td>
                    <td>Incomplete/garbage data - assign to order enterer</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div style="margin-top: 30px; padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
        <h2 style="margin-top: 0;">💰 Estimated Google Maps API Cost</h2>
        <p style="font-size: 16px;">
            <strong><?php echo number_format( $unique_need_geocoding ); ?></strong> unique addresses need geocoding<br>
            <strong style="font-size: 24px; color: #d63384;">~$<?php echo number_format( $estimated_cost, 2 ); ?></strong> 
            <span style="color: #666;">(at $5 per 1,000 requests)</span>
        </p>
        <p><em>Note: This is a one-time cost. Results are cached for future use.</em></p>
    </div>
    
    <?php if ( count( $bad_addresses ) > 0 ): ?>
    <div style="margin-top: 30px;">
        <h2>🚫 Invalid/Undeliverable Addresses (<?php echo number_format( count( $bad_addresses ) ); ?>)</h2>
        <p>These addresses are incomplete or garbage data. <strong>These orders should be assigned to the person who entered them for manual delivery.</strong></p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 70%;">Invalid Address</th>
                    <th style="width: 30%;">Orders</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                arsort( $bad_addresses );
                foreach ( $bad_addresses as $addr => $count ): 
                ?>
                <tr>
                    <td><span style="color: #dc3545;"><?php echo esc_html( $addr ); ?></span></td>
                    <td><?php echo number_format( $count ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if ( $unique_need_geocoding > 0 ): ?>
    <div style="margin-top: 30px;">
        <h2>Addresses That Need Geocoding (<?php echo number_format( $unique_need_geocoding ); ?>)</h2>
        <p>These addresses are not in your wp_ss_addresses database or geocode cache:</p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 50%;">Address</th>
                    <th style="width: 15%;">Orders</th>
                    <th style="width: 35%;">Debug Info</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Sort by count descending
                uasort( $unmatched_addresses, function( $a, $b ) {
                    return $b['count'] - $a['count'];
                });
                foreach ( $unmatched_addresses as $addr => $data ): 
                ?>
                <tr>
                    <td><?php echo esc_html( $addr ); ?></td>
                    <td><?php echo number_format( $data['count'] ); ?> (Order IDs: <?php echo implode( ', ', array_slice( $data['orders'], 0, 3 ) ); ?><?php echo count( $data['orders'] ) > 3 ? '...' : ''; ?>)</td>
                    <td style="font-family: monospace; font-size: 11px;">
                        <?php if ( ! empty( $data['debug'] ) ): ?>
                            <?php if ( ! empty( $data['debug']['parse_failed'] ) ): ?>
                                <strong style="color: #dc3545;">PARSE FAILED</strong><br>
                                <em><?php echo esc_html( $data['debug']['reason'] ?? 'Unknown reason' ); ?></em><br>
                                <strong>Raw parsed result:</strong><br>
                                <pre style="font-size: 10px; background: #f8f9fa; padding: 5px; margin: 5px 0; max-height: 150px; overflow: auto;"><?php echo esc_html( print_r( $data['debug']['parsed'], true ) ); ?></pre>
                            <?php else: ?>
                                <strong>Parsed:</strong><br>
                                • Street: <?php echo esc_html( $data['debug']['parsed']['street'] ?? 'N/A' ); ?><br>
                                • House #: <?php echo esc_html( $data['debug']['parsed']['house_number'] ?? 'N/A' ); ?><br>
                                • Unit: <?php echo esc_html( $data['debug']['parsed']['unit'] ?? '(none)' ); ?><br>
                                • ZIP: <?php echo esc_html( $data['debug']['parsed']['zip'] ?? 'N/A' ); ?><br>
                                <strong>Searching for:</strong><br>
                                • street='<?php echo esc_html( $data['debug']['searching']['street'] ?? 'N/A' ); ?>'<br>
                                • house_number='<?php echo esc_html( $data['debug']['searching']['house_number'] ?? 'N/A' ); ?>'<br>
                                • zip='<?php echo esc_html( $data['debug']['searching']['zip'] ?? 'N/A' ); ?>'<br>
                                <?php if ( ! empty( $data['debug']['db_sample'] ) ): ?>
                                    <strong style="color: #d63384;">DB HAS:</strong><br>
                                    • street='<?php echo esc_html( $data['debug']['db_sample']['street'] ?? 'N/A' ); ?>'<br>
                                    • house_number='<?php echo esc_html( $data['debug']['db_sample']['house_number'] ?? 'N/A' ); ?>'<br>
                                    • zip='<?php echo esc_html( $data['debug']['db_sample']['zip'] ?? 'N/A' ); ?>'<br>
                                    <em style="color: #856404;">⚠️ Address exists in DB but not matching!</em>
                                <?php else: ?>
                                    <em style="color: #666;">No similar addresses found in database</em>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <em style="color: #999;">Debug info not available</em>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <div style="margin-top: 30px; padding: 20px; background: #e7f3ff; border-left: 4px solid #0d6efd;">
        <h2 style="margin-top: 0;">💡 Recommendations</h2>
        <ol style="font-size: 14px; line-height: 1.8;">
            <li><strong>Good news:</strong> <?php echo number_format( ( $matched_in_db + $cached_geocodes ) ); ?> orders (<?php echo $total_orders > 0 ? number_format( ( ( $matched_in_db + $cached_geocodes ) / $total_orders ) * 100, 1 ) : 0; ?>%) already have coordinates!</li>
            
            <?php if ( $bad_address > 0 ): ?>
            <li><strong>Data quality issue:</strong> <?php echo number_format( $bad_address ); ?> orders have invalid/incomplete addresses. 
                <ul>
                    <li>These will be automatically assigned to the person who entered them for manual delivery</li>
                    <li>Examples: just a street name, random characters, missing house number</li>
                    <li>Consider data validation in order entry to prevent this</li>
                </ul>
            </li>
            <?php endif; ?>
            
            <?php if ( $unique_need_geocoding > 50 ): ?>
            <li><strong>High cost warning:</strong> You have <?php echo number_format( $unique_need_geocoding ); ?> addresses to geocode. This will cost approximately $<?php echo number_format( $estimated_cost, 2 ); ?>.</li>
            <li><strong>Before generating manifests:</strong> Consider running the Overpass address matcher to populate more addresses in wp_ss_addresses database (Settings → Address Extracts).</li>
            <?php elseif ( $unique_need_geocoding > 0 ): ?>
            <li><strong>Moderate cost:</strong> <?php echo number_format( $unique_need_geocoding ); ?> addresses need geocoding (~$<?php echo number_format( $estimated_cost, 2 ); ?>). This is a reasonable one-time cost.</li>
            <?php else: ?>
            <li><strong>Perfect!</strong> All your deliverable orders have coordinates. No Google API calls needed.</li>
            <?php endif; ?>
            
            <li><strong>After first geocoding:</strong> All results are cached. Future manifest generation will be FREE and FAST.</li>
            
            <li><strong>Delivery manifest behavior:</strong> Orders with invalid addresses will stay with the person who entered them (not grouped by seller signup).</li>
        </ol>
    </div>
    
    <div style="margin-top: 20px;">
        <a href="<?php echo admin_url( 'admin.php?page=subsales-delivery' ); ?>" class="button button-primary">← Back to Delivery Page</a>
        <a href="<?php echo admin_url( 'admin.php?page=subsales-settings' ); ?>" class="button">Address Management Settings</a>
    </div>
</div>

<style>
.address-coverage-summary table {
    margin-top: 20px;
    font-size: 14px;
}
.address-coverage-summary th {
    font-weight: 600;
}
.address-coverage-summary td {
    padding: 10px;
}
</style>
