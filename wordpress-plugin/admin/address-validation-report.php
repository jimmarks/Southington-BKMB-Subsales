<?php
/**
 * Address Validation Report Page
 * Shows orders with address validation issues and approval workflow
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . 'ss_orders';

// Handle address search
$search_results = null;
$search_query = '';
if ( isset( $_POST['search_address'] ) && ! empty( $_POST['search_address'] ) ) {
    check_admin_referer( 'subsales_address_search' );
    $search_query = sanitize_text_field( $_POST['search_address'] );
    
    // Search for orders matching the address (case-insensitive, partial match)
    $search_results = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, order_id, order_data, address, address_entry_method, 
                address_validation_status, address_validation_date, address_validation_data,
                created_at, tallied, deleted
         FROM {$table}
         WHERE address LIKE %s
         ORDER BY created_at DESC",
        '%' . $wpdb->esc_like( $search_query ) . '%'
    ), ARRAY_A );
}

// Handle manual validation trigger
if ( isset( $_POST['run_validation'] ) && check_admin_referer( 'subsales_run_validation' ) ) {
    Subsales_Database::run_address_validation();
    echo '<div class="notice notice-success"><p>Address validation complete! Results updated below.</p></div>';
}

// Handle approve & add to database action
if ( isset( $_POST['approve_address'] ) && check_admin_referer( 'subsales_approve_address' ) ) {
    $order_id = intval( $_POST['order_id'] );
    
    $order = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE id = %d",
        $order_id
    ), ARRAY_A );
    
    if ( $order ) {
        $validation_data = Subsales_Order_Helper::decode_order_data( array( 'order_data' => $order['address_validation_data'] ) );
        
        if ( ! empty( $validation_data['coordinates'] ) && ! empty( $validation_data['parsed'] ) ) {
            $parsed = $validation_data['parsed'];
            $coords = $validation_data['coordinates'];
            
            // Build full address string (use Google's corrected version if available)
            $full_address = '';
            if ( ! empty( $validation_data['corrected_address'] ) ) {
                $full_address = $validation_data['corrected_address'];
            } else {
                // Fallback: construct from parsed parts
                $full_address = trim( $parsed['house_number'] . ' ' . $parsed['street'] );
                if ( ! empty( $parsed['unit'] ) ) {
                    $full_address .= ' ' . $parsed['unit'];
                }
                if ( ! empty( $parsed['city'] ) ) {
                    $full_address .= ', ' . $parsed['city'];
                }
                if ( ! empty( $parsed['state'] ) ) {
                    $full_address .= ', ' . $parsed['state'];
                }
                if ( ! empty( $parsed['zip'] ) ) {
                    $full_address .= ' ' . $parsed['zip'];
                }
            }
            
            // Insert into wp_ss_addresses
            $address_table = $wpdb->prefix . 'ss_addresses';
            $result = $wpdb->insert(
                $address_table,
                array(
                    'street' => strtoupper( $parsed['street'] ),
                    'house_number' => $parsed['house_number'],
                    'unit' => ! empty( $parsed['unit'] ) ? $parsed['unit'] : '',
                    'city' => ! empty( $parsed['city'] ) ? $parsed['city'] : 'Southington',
                    'state' => ! empty( $parsed['state'] ) ? $parsed['state'] : 'CT',
                    'zip' => ! empty( $parsed['zip'] ) ? $parsed['zip'] : '',
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                    'source' => 'parcel',
                    'confidence' => 'high',
                    'matched' => 1,
                    'type' => 'residential',
                    'full_address' => $full_address
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%s', '%s' )
            );
            
            if ( $result ) {
                // Mark order as approved
                $wpdb->update(
                    $table,
                    array( 'address_validation_status' => 'approved' ),
                    array( 'id' => $order_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                
                subsales_log( 'INFO', 'system', "Address approved and added to database: {$order['address']} (Order #{$order['order_id']})" );
                echo '<div class="notice notice-success"><p>Address approved and added to database!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Error adding address to database. May already exist.</p></div>';
            }
        }
    }
}

// Handle dismiss action
if ( isset( $_POST['dismiss_address'] ) && isset( $_POST['order_id'] ) ) {
    check_admin_referer( 'subsales_dismiss_address' );
    
    $order_dbid = intval( $_POST['order_id'] );
    $dismiss_reason = isset( $_POST['dismiss_reason'] ) ? sanitize_textarea_field( $_POST['dismiss_reason'] ) : 'Cannot geocode';
    
    $result = $wpdb->update(
        $table,
        array( 'address_validation_status' => 'dismissed' ),
        array( 'id' => $order_dbid ),
        array( '%s' ),
        array( '%d' )
    );
    
    if ( $result !== false ) {
        subsales_log( 'INFO', 'system', "Address validation dismissed for order #{$order_dbid}: {$dismiss_reason}" );
        echo '<div class="notice notice-success"><p>Address dismissed. It will no longer appear in validation reports.</p></div>';
    }
}

// Fetch orders with validation issues (exclude dismissed)
// Includes both tallied and untallied orders - use dismiss button to filter out unwanted addresses
$problem_orders = $wpdb->get_results(
    "SELECT id, order_id, order_data, address, address_entry_method, 
            address_validation_status, address_validation_date, address_validation_data,
            created_at, tallied
     FROM {$table}
     WHERE deleted = 0
     AND address_validation_status IN ('geocode_failed', 'format_invalid')
     ORDER BY address_validation_date DESC",
    ARRAY_A
);

// Fetch dismissed addresses for informational display
$dismissed_orders = $wpdb->get_results(
    "SELECT id, order_id, order_data, address, address_entry_method, 
            address_validation_status, address_validation_date, address_validation_data,
            created_at, tallied
     FROM {$table}
     WHERE deleted = 0
     AND address_validation_status = 'dismissed'
     ORDER BY address_validation_date DESC",
    ARRAY_A
);

// REDESIGNED: Check actual database state instead of relying on cached validation status
// Get all orders that have been validated with coordinates, then check if they're actually in the database
$addresses_table = $wpdb->prefix . 'ss_addresses';
$potential_approvals = $wpdb->get_results(
    "SELECT id, order_id, order_data, address, address_entry_method, 
            address_validation_status, address_validation_date, address_validation_data,
            created_at, tallied
     FROM {$table}
     WHERE deleted = 0
     AND address_validation_status != 'dismissed'
     AND address_validation_data IS NOT NULL
     AND address_validation_data != ''
     AND address_validation_data LIKE '%\"coordinates\"%'
     ORDER BY created_at DESC",
    ARRAY_A
);

// Now check each one to see if it's actually in the database
$needs_approval = array();
foreach ( $potential_approvals as $order ) {
    $validation_data = Subsales_Order_Helper::decode_order_data( array( 'order_data' => $order['address_validation_data'] ) );
    
    // Must have coordinates from geocoding
    if ( empty( $validation_data['coordinates'] ) ) {
        continue;
    }
    
    // Parse the address
    $parsed = Subsales_Address_Helper::parse_address( $order['address'] );
    if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
        continue;
    }
    
    // Check if this address is already in wp_ss_addresses
    $query = "SELECT id FROM {$addresses_table}
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
    
    $existing = $wpdb->get_row( $wpdb->prepare( $query, $params ), ARRAY_A );
    
    // If NOT in database, it needs approval
    if ( empty( $existing ) ) {
        $needs_approval[] = $order;
    }
}


$total_issues = count( $problem_orders );
$total_approvals = count( $needs_approval );
?>

<div class="wrap">
    <h1>Address Validation Report</h1>
    
    <div class="subsales-validation-summary">
        <div class="subsales-stat-card">
            <span class="stat-number"><?php echo number_format( $total_issues ); ?></span>
            <span class="stat-label">Orders with Problems</span>
        </div>
        <div class="subsales-stat-card">
            <span class="stat-number"><?php echo number_format( $total_approvals ); ?></span>
            <span class="stat-label">Ready for Approval</span>
        </div>
    </div>
    
    <!-- Address Search Section -->
    <div class="subsales-search-section">
        <h2>🔍 Search Orders by Address</h2>
        <form method="post" class="subsales-search-form">
            <?php wp_nonce_field( 'subsales_address_search' ); ?>
            <input type="text" 
                   name="search_address" 
                   class="regular-text" 
                   placeholder="Enter address to search (e.g., 6 Hillcrest Dr)" 
                   value="<?php echo esc_attr( $search_query ); ?>">
            <button type="submit" class="button button-secondary">Search</button>
            <?php if ( ! empty( $search_query ) ): ?>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-address-validation' ); ?>" class="button">Clear</a>
            <?php endif; ?>
        </form>
        
        <?php if ( $search_results !== null ): ?>
            <div class="subsales-search-results">
                <?php if ( empty( $search_results ) ): ?>
                    <div class="notice notice-warning" style="margin-top:15px;">
                        <p><strong>No orders found</strong> matching "<?php echo esc_html( $search_query ); ?>"</p>
                    </div>
                <?php else: ?>
                    <h3 style="margin-top:20px;">Found <?php echo count( $search_results ); ?> order(s) matching "<?php echo esc_html( $search_query ); ?>"</h3>
                    
                    <?php Subsales_Display_Helper::render_table_start(
                        array( 'Order ID', 'Customer', 'Address', 'Validation Status', 'Why Not in Report?' ),
                        '',
                        array(
                            'style' => 'width:100%',
                            'class' => ''
                        )
                    ); ?>
                            <?php foreach ( $search_results as $order ): 
                                $order_data = Subsales_Order_Helper::decode_order_data( $order );
                                $customer = Subsales_Order_Helper::get_customer_name( $order );
                                $validation_data = Subsales_Order_Helper::decode_order_data( array( 'order_data' => $order['address_validation_data'] ) );
                                $status = $order['address_validation_status'];
                                
                                // ACTUALLY CHECK if address is in database (don't trust validation status)
                                $in_database = false;
                                $parsed = Subsales_Address_Helper::parse_address( $order['address'] );
                                if ( $parsed && ! empty( $parsed['house_number'] ) && ! empty( $parsed['street'] ) ) {
                                    $addresses_table = $wpdb->prefix . 'ss_addresses';
                                    $query = "SELECT id FROM {$addresses_table}
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
                                    $in_database = ! empty( $address_row );
                                }
                                
                                // Determine status badge and explanation
                                $status_badge = '';
                                $explanation = '';
                                
                                if ( $status === null || $status === '' ) {
                                    $status_badge = '<span class="badge badge-secondary">⚠️ No Status</span>';
                                    $explanation = '<strong>Database Issue:</strong> Order has no validation status assigned. This is a bug - contact administrator.';
                                } elseif ( $status === 'pending' ) {
                                    $status_badge = '<span class="badge badge-info">⏳ Pending</span>';
                                    $explanation = '<strong>Not Validated Yet:</strong> Click "Run Validation Now" button above to validate this address.';
                                } elseif ( $status === 'valid' ) {
                                    // Check if needs approval flag is set
                                    $needs_approval_flag = ! empty( $validation_data['needs_approval'] ) && $validation_data['needs_approval'];
                                    
                                    // NOW CHECK REALITY vs what validation says
                                    if ( $in_database ) {
                                        $status_badge = '<span class="badge badge-success">✅ Valid</span>';
                                        $explanation = '<strong>✅ In Database:</strong> Address verified to exist in wp_ss_addresses. No approval needed.';
                                    } elseif ( $needs_approval_flag ) {
                                        $status_badge = '<span class="badge badge-success">✅ Valid</span>';
                                        $explanation = '<strong>Should Be Visible:</strong> This order SHOULD appear in the "Ready for Approval" section above.';
                                    } else {
                                        // BUG: Status says valid but address not in database AND no approval flag
                                        $status_badge = '<span class="badge badge-danger">⚠️ Valid (BUG)</span>';
                                        $explanation = '<strong>🐛 VALIDATION BUG:</strong> Status says "valid" and ready, but address is NOT actually in wp_ss_addresses database. The validation logic incorrectly marked this as not needing approval. Use "Approve & Add" button in Ready for Approval section if it appears, or click "Run Validation Now" to revalidate.';
                                    }
                                } elseif ( $status === 'geocode_failed' ) {
                                    $status_badge = '<span class="badge badge-danger">🔍 Geocode Failed</span>';
                                    $explanation = '<strong>Should Be Visible:</strong> This order SHOULD appear in the "Orders with Problems" section above.';
                                } elseif ( $status === 'format_invalid' ) {
                                    $status_badge = '<span class="badge badge-danger">❌ Invalid Format</span>';
                                    $explanation = '<strong>Should Be Visible:</strong> This order SHOULD appear in the "Orders with Problems" section above.';
                                } elseif ( $status === 'dismissed' ) {
                                    $status_badge = '<span class="badge badge-secondary">🚫 Dismissed</span>';
                                    $explanation = '<strong>Should Be Visible:</strong> This order SHOULD appear in the "Dismissed Addresses" section above.';
                                } elseif ( $status === 'approved' ) {
                                    $status_badge = '<span class="badge badge-success">✅ Approved</span>';
                                    if ( $in_database ) {
                                        $explanation = '<strong>✅ Previously Approved:</strong> Address was approved and verified in wp_ss_addresses database.';
                                    } else {
                                        $explanation = '<strong>⚠️ Approved But Missing:</strong> Status says approved but address not found in wp_ss_addresses. May have been deleted from database.';
                                    }
                                } else {
                                    $status_badge = '<span class="badge badge-secondary">❓ ' . esc_html( $status ) . '</span>';
                                    $explanation = '<strong>Unknown Status:</strong> Unexpected validation status value.';
                                }
                                
                                // Check if deleted
                                if ( $order['deleted'] == 1 ) {
                                    $status_badge = '<span class="badge badge-danger">🗑️ Deleted</span>';
                                    $explanation = '<strong>Order Deleted:</strong> This order has been soft-deleted and won\'t appear in any reports.';
                                }
                            ?>
                            <tr>
                                <td>
                                    <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . $order['id'] ); ?>" target="_blank">
                                        <?php echo esc_html( $order['order_id'] ); ?>
                                    </a>
                                    <?php if ( $order['deleted'] == 1 ): ?>
                                        <br><small style="color:#d63638;">DELETED</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $customer ); ?></td>
                                <td>
                                    <?php echo esc_html( $order['address'] ); ?>
                                    <?php if ( ! empty( $validation_data['corrected_address'] ) && $validation_data['corrected_address'] !== $order['address'] ): ?>
                                        <br><small style="color:#0073aa;">✏️ Corrected: <?php echo esc_html( $validation_data['corrected_address'] ); ?></small>
                                    <?php endif; ?>
                                    <?php if ( ! empty( $validation_data['coordinates'] ) ): ?>
                                        <br><small style="color:#28a745;">📍 <?php echo number_format( $validation_data['coordinates']['lat'], 6 ); ?>, <?php echo number_format( $validation_data['coordinates']['lng'], 6 ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $status_badge; ?>
                                    <?php if ( $order['address_validation_date'] ): ?>
                                        <br><small><?php echo date( 'M j, Y g:ia', strtotime( $order['address_validation_date'] ) ); ?></small>
                                    <?php else: ?>
                                        <br><small>Never validated</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="font-size:13px;line-height:1.5;">
                                        <?php echo $explanation; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                    <?php Subsales_Display_Helper::render_table_end(); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="subsales-actions-bar">
        <div style="background:#e7f3ff;padding:12px;border-radius:4px;margin-bottom:15px;border-left:4px solid #2271b1;">
            <strong>ℹ️ How This Report Works:</strong>
            <ul style="margin:10px 0 0 20px;font-size:13px;line-height:1.6;">
                <li><strong>Real-Time Check:</strong> "Ready for Approval" section checks the actual wp_ss_addresses database in real-time and shows addresses that have GPS coordinates but aren't in the database yet.</li>
                <li><strong>Approve:</strong> Click "Approve & Add to Database" to add the address to wp_ss_addresses so it can be used for delivery routing.</li>
                <li><strong>Dismiss:</strong> Click "Dismiss" if an address doesn't need to be in the database (e.g., duplicate order, test order, or address that doesn't need routing). Dismissed orders won't appear in this report again.</li>
                <li><strong>Geocode:</strong> The button below attempts to geocode addresses that don't have GPS coordinates yet. If an address already has coordinates, validation won't change anything.</li>
            </ul>
        </div>
        
        <form method="post" style="display:inline-block;">
            <?php wp_nonce_field( 'subsales_run_validation' ); ?>
            <button type="submit" name="run_validation" class="button button-primary">
                🔄 Geocode Addresses Without GPS
            </button>
        </form>
        <p class="description" style="display:inline-block;margin-left:15px;">
            This will attempt to geocode addresses that don't have GPS coordinates yet. Nightly validation runs automatically at 2 AM.
        </p>
    </div>
    
    <?php if ( $total_approvals > 0 ): ?>
    <div class="subsales-section">
        <h2>✅ Addresses Ready for Database Approval (<?php echo $total_approvals; ?>)</h2>
        <div class="notice notice-info" style="margin:10px 0;">
            <p><strong>Real-Time Check:</strong> This section checks wp_ss_addresses database in real-time. These addresses have GPS coordinates but are NOT in the database yet. Click "Approve & Add to Database" to add them, or "Dismiss" if they don't need to be in the database.</p>
        </div>
        
        <?php Subsales_Display_Helper::render_table_start(
            array( 'Order ID', 'Customer', 'Address', 'Entry Method', 'Validated', 'Actions' )
        ); ?>
                <?php foreach ( $needs_approval as $order ): 
                    $order_data = Subsales_Order_Helper::decode_order_data( $order );
                    $customer = Subsales_Order_Helper::get_customer_name( $order );
                    $validation_data = Subsales_Order_Helper::decode_order_data( array( 'order_data' => $order['address_validation_data'] ) );
                    $coords = ! empty( $validation_data['coordinates'] ) ? $validation_data['coordinates'] : null;
                ?>
                <tr>
                    <td>
                        <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . $order['id'] ); ?>">
                            <?php echo esc_html( $order['order_id'] ); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html( $customer ); ?></td>
                    <td>
                        <?php 
                        // Show original address (user input)
                        echo esc_html( $order['address'] );
                        
                        // If Google corrected the address, show that too
                        if ( ! empty( $validation_data['corrected_address'] ) && $validation_data['corrected_address'] !== $order['address'] ) {
                            echo '<br><small style="color:#0073aa;">✏️ Corrected: ' . esc_html( $validation_data['corrected_address'] ) . '</small>';
                        }
                        
                        // Show coordinates if available
                        if ( $coords ): ?>
                            <br><small style="color:#28a745;">📍 Geocoded: <?php echo number_format( $coords['lat'], 6 ); ?>, <?php echo number_format( $coords['lng'], 6 ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo Subsales_Display_Helper::entry_method_badge( $order['address_entry_method'] ); ?>
                    </td>
                    <td><?php echo $order['address_validation_date'] ? date( 'M j, Y g:ia', strtotime( $order['address_validation_date'] ) ) : 'Never'; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'subsales_approve_address' ); ?>
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <button type="submit" name="approve_address" class="button button-primary">
                                ✅ Approve & Add to Database
                            </button>
                        </form>
                        <form method="post" style="display:inline;margin-left:5px;">
                            <?php wp_nonce_field( 'subsales_dismiss_address' ); ?>
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <input type="hidden" name="dismiss_reason" value="Not needed in database">
                            <button type="submit" name="dismiss_address" class="button" 
                                    onclick="return confirm('Dismiss this address? It will no longer show up in this report. You can still edit the order.');"
                                    title="Dismiss if this address doesn't need to be added to the database">
                                🚫 Dismiss
                            </button>
                        </form>
                        <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . $order['id'] ); ?>" class="button">Edit Order</a>
                    </td>
                </tr>
                <?php endforeach; ?>
        <?php Subsales_Display_Helper::render_table_end(); ?>
    </div>
    <?php endif; ?>
    
    <?php if ( $total_issues > 0 ): ?>
    <div class="subsales-section">
        <h2>⚠️ Orders with Address Problems (<?php echo $total_issues; ?>)</h2>
        <p>These orders have addresses that could not be validated. Review and correct before delivery day.</p>
        
        <?php Subsales_Display_Helper::render_table_start(
            array( 'Order ID', 'Customer', 'Address', 'Entry Method', 'Problem', 'Validated', 'Actions' )
        ); ?>
                <?php foreach ( $problem_orders as $order ): 
                    $order_data = Subsales_Order_Helper::decode_order_data( $order );
                    $customer = Subsales_Order_Helper::get_customer_name( $order );
                    $validation_data = Subsales_Order_Helper::decode_order_data( array( 'order_data' => $order['address_validation_data'] ) );
                    $error = ! empty( $validation_data['error'] ) ? $validation_data['error'] : 'Unknown error';
                ?>
                <tr>
                    <td>
                        <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . $order['id'] ); ?>">
                            <?php echo esc_html( $order['order_id'] ); ?>
                        </a>
                    </td>
                    <td><?php echo esc_html( $customer ); ?></td>
                    <td>
                        <?php echo esc_html( $order['address'] ); ?>
                    </td>
                    <td>
                        <?php echo Subsales_Display_Helper::entry_method_badge( $order['address_entry_method'] ); ?>
                    </td>
                    <td>
                        <?php if ( $order['address_validation_status'] === 'geocode_failed' ): ?>
                            <span class="badge badge-danger">🔍 Address Not Found</span>
                        <?php else: ?>
                            <span class="badge badge-danger">❌ Invalid Format</span>
                        <?php endif; ?>
                        <br><small><?php echo esc_html( $error ); ?></small>
                    </td>
                    <td><?php echo $order['address_validation_date'] ? date( 'M j, Y g:ia', strtotime( $order['address_validation_date'] ) ) : 'Never'; ?></td>
                    <td>
                        <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . $order['id'] ); ?>" class="button button-primary">Edit Order</a>
                        <form method="post" style="display:inline;margin-left:5px;">
                            <?php wp_nonce_field( 'subsales_dismiss_address' ); ?>
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <input type="hidden" name="dismiss_reason" value="Cannot geocode - bad address">
                            <button type="submit" name="dismiss_address" class="button" 
                                    onclick="return confirm('Dismiss this address? It will no longer show up in validation reports.');"
                                    title="Dismiss this address if it cannot be fixed">
                                🚫 Dismiss
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
        <?php Subsales_Display_Helper::render_table_end(); ?>
    </div>
    <?php endif; ?>
    
    <?php if ( count( $dismissed_orders ) > 0 ): ?>
    <div class="subsales-section">
        <h2>🚫 Dismissed Addresses (<?php echo count( $dismissed_orders ); ?>)</h2>
        <p>These addresses were dismissed because they could not be geocoded. They will not appear in active validation reports.</p>
        
        <details>
            <summary style="cursor:pointer;padding:10px;background:#f5f5f5;border-radius:4px;">Show dismissed addresses</summary>
            <?php Subsales_Display_Helper::render_table_start(
                array( 'Order ID', 'Customer', 'Address', 'Entry Method', 'Dismissed', 'Actions' ),
                '',
                array( 'style' => 'margin-top:10px;' )
            ); ?>
                    <?php foreach ( $dismissed_orders as $order ): 
                        $order_data = Subsales_Order_Helper::decode_order_data( $order );
                        $customer = Subsales_Order_Helper::get_customer_name( $order );
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . $order['id'] ); ?>">
                                <?php echo esc_html( $order['order_id'] ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $customer ); ?></td>
                        <td><?php echo esc_html( $order['address'] ); ?></td>
                        <td>
                            <?php echo Subsales_Display_Helper::entry_method_badge( $order['address_entry_method'] ); ?>
                        </td>
                        <td><?php echo $order['address_validation_date'] ? date( 'M j, Y g:ia', strtotime( $order['address_validation_date'] ) ) : 'Never'; ?></td>
                        <td>
                            <a href="<?php echo admin_url( 'admin.php?page=subsales-orders&edit=' . $order['id'] ); ?>" class="button">Edit Order</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
            <?php Subsales_Display_Helper::render_table_end(); ?>
        </details>
    </div>
    <?php endif; ?>
    
    <?php if ( $total_issues === 0 && $total_approvals === 0 ): ?>
    <div class="notice notice-success" style="padding:20px;margin-top:20px;">
        <h2 style="margin-top:0;">✅ All Clear!</h2>
        <p>No address validation issues found. All orders have valid addresses.</p>
    </div>
    <?php endif; ?>
</div>

<style>
.subsales-validation-summary {
    display: flex;
    gap: 20px;
    margin: 20px 0;
}
.subsales-stat-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 200px;
}
.stat-number {
    font-size: 48px;
    font-weight: bold;
    color: #2271b1;
}
.stat-label {
    font-size: 14px;
    color: #666;
    margin-top: 5px;
}
.subsales-actions-bar {
    background: #f5f5f5;
    padding: 15px;
    border-radius: 4px;
    margin: 20px 0;
    display: flex;
    align-items: center;
}
.subsales-section {
    margin: 30px 0;
}
.subsales-section h2 {
    border-bottom: 2px solid #2271b1;
    padding-bottom: 10px;
}
.subsales-search-section {
    background: #fff;
    border: 2px solid #2271b1;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}
.subsales-search-section h2 {
    margin-top: 0;
    color: #2271b1;
}
.subsales-search-form {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 10px;
}
.subsales-search-form .regular-text {
    flex: 1;
    max-width: 500px;
}
.subsales-search-results {
    margin-top: 20px;
}
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
}
.badge-success { background: #d4edda; color: #155724; }
.badge-info { background: #d1ecf1; color: #0c5460; }
.badge-warning { background: #fff3cd; color: #856404; }
.badge-secondary { background: #e2e3e5; color: #383d41; }
.badge-danger { background: #f8d7da; color: #721c24; }
</style>
