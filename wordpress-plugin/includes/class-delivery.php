<?php
/**
 * Subsales Delivery Management
 * 
 * Handles delivery manifest generation, QR codes, and route optimization.
 * Isolates delivery functionality from core plugin to prevent bugs from
 * affecting critical operations.
 *
 * @package Subsales_Management
 * @since 2.2.1.230
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Delivery {
    
    /**
     * Initialize the delivery class
     */
    public static function init() {
        // Hook must be registered during WordPress 'init' or later
        add_action( 'init', array( __CLASS__, 'register_hooks' ) );
    }
    
    /**
     * Register admin_post hooks
     * 
     * @since 2.2.1.234
     */
    public static function register_hooks() {
        add_action( 'admin_post_subsales_generate_delivery_pdf', array( __CLASS__, 'handle_generate_manifest' ) );
    }
    
    /**
     * Handle delivery manifest generation form submission
     * 
     * @since 2.2.1.230
     */
    public static function handle_generate_manifest() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'subsales_generate_delivery' ) ) {
            wp_die( 'Invalid nonce' );
        }

        // Increase execution time for geocoding operations
        @set_time_limit( 300 ); // 5 minutes
        @ini_set( 'max_execution_time', '300' );

        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';

        $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
        $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( $_POST['start_address'] ) : '';
        
        if ( empty( $start_address ) ) {
            wp_die( 'Starting address (depot) is required' );
        }
        
        update_option( 'order_sync_delivery_start_address', $start_address );
        
        // Geocode starting address
        $start_coords = self::geocode_address( $start_address );
        if ( ! $start_coords ) {
            wp_die( 'Could not geocode starting address. Please check your Google Maps API key and address.' );
        }

        // Fetch orders (delivery date is for display only, not filtering)
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY id ASC", ARRAY_A );

        if ( empty( $rows ) ) {
            $msg = rawurlencode( 'No orders found in database' );
            wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
            exit;
        }
        
        subsales_log( 'INFO', 'delivery', 'Generating manifests for ' . count( $rows ) . ' total orders' );

        $configured_products = order_sync_get_products_config();

        // NEW LOGIC: Distribute orders based on campaign signups
        // Step 1: Parse all orders into standardized format
        $parsed_orders = array();
        foreach ( $rows as $r ) {
            $od = json_decode( $r['order_data'], true );
            if ( ! is_array( $od ) ) continue;

            // Parse order details
            $address = ! empty( $r['address'] ) ? $r['address'] : ( ! empty( $od['address'] ) ? $od['address'] : '' );
            
            // Look up coordinates from address database using structured matching
            $lat = null;
            $lng = null;
            if ( ! empty( $address ) ) {
                $parsed = self::parse_address( $address );
                
                // Only require house_number and street for matching (ZIP is optional)
                if ( $parsed && ! empty( $parsed['house_number'] ) && ! empty( $parsed['street'] ) ) {
                    // Match against structured fields: house_number + street required, ZIP optional
                    // Unit is optional (many addresses don't have units)
                    $query = "SELECT lat, lng FROM {$wpdb->prefix}ss_addresses 
                              WHERE LOWER(TRIM(street)) = %s 
                              AND LOWER(TRIM(house_number)) = %s";
                    $params = array(
                        strtolower( trim( $parsed['street'] ) ),
                        strtolower( trim( $parsed['house_number'] ) )
                    );
                    
                    // If ZIP is present in address, use it to narrow results
                    if ( ! empty( $parsed['zip'] ) ) {
                        $query .= " AND zip = %s";
                        $params[] = $parsed['zip'];
                    }
                    
                    // If unit is specified in order, match it too
                    if ( ! empty( $parsed['unit'] ) ) {
                        $query .= " AND LOWER(TRIM(unit)) = %s";
                        $params[] = strtolower( trim( $parsed['unit'] ) );
                    }
                    
                    $query .= " LIMIT 1";
                    
                    $address_row = $wpdb->get_row( $wpdb->prepare( $query, $params ), ARRAY_A );
                    
                    if ( $address_row && ! empty( $address_row['lat'] ) && ! empty( $address_row['lng'] ) ) {
                        $lat = floatval( $address_row['lat'] );
                        $lng = floatval( $address_row['lng'] );
                    } else {
                        // Not in address database - output console message for quick debugging
                        $zip_info = ! empty( $parsed['zip'] ) ? ", ZIP: {$parsed['zip']}" : " (no ZIP)";
                        echo "<!-- Address not in database: Order {$r['order_id']}, Address: {$address} (Parsed: {$parsed['house_number']} {$parsed['street']}{$zip_info}) -->\n";
                        flush();
                    }
                } else {
                    echo "<!-- Could not parse address: Order {$r['order_id']}, Address: {$address} -->\n";
                    flush();
                }
            }
            
            $order_entry = array(
                'id' => $r['id'],
                'order_id' => $r['order_id'],
                'team_id' => ! empty( $r['team_id'] ) ? intval( $r['team_id'] ) : 0,
                'entered_by_id' => ! empty( $od['entered_by_id'] ) ? intval( $od['entered_by_id'] ) : ( ! empty( $r['user_id'] ) ? intval( $r['user_id'] ) : 0 ),
                'created_at' => $r['created_at'],
                'order_date' => date( 'Y-m-d', strtotime( $r['created_at'] ) ),
                'found_in_db' => ( $lat !== null && $lng !== null ), // Track if address was found in database
                'address' => $address,
                'customer' => ! empty( $od['customer'] ) ? $od['customer'] : '',
                'phone' => ! empty( $od['cellNumber'] ) ? $od['cellNumber'] : '',
                'lat' => $lat,
                'lng' => $lng,
                'products' => array()
            );

            // Handle products
            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                foreach ( $od['products'] as $product ) {
                    if ( isset( $product['id'] ) && isset( $product['qty'] ) ) {
                        $pid = $product['id'];
                        $qty = intval( $product['qty'] );
                        if ( $qty > 0 ) {
                            $pname = $pid;
                            foreach ( $configured_products as $pconf ) {
                                if ( isset( $pconf['id'] ) && $pconf['id'] === $pid ) {
                                    $pname = $pconf['name'];
                                    break;
                                }
                            }
                            $order_entry['products'][ $pid ] = array( 'name' => $pname, 'qty' => $qty );
                        }
                    }
                }
            } else {
                foreach ( $configured_products as $pconf ) {
                    if ( ! isset( $pconf['id'] ) ) continue;
                    $pid = $pconf['id'];
                    $qty = isset( $od[ $pid ] ) ? intval( $od[ $pid ] ) : 0;
                    if ( $qty > 0 ) {
                        $order_entry['products'][ $pid ] = array( 'name' => $pconf['name'], 'qty' => $qty );
                    }
                }
            }

            // Skip donation-only orders (no physical products to deliver)
            if ( empty( $order_entry['products'] ) ) {
                subsales_log( 'INFO', 'delivery', "Skipping donation-only order #{$r['order_id']} (no products to deliver)" );
                continue;
            }

            $parsed_orders[] = $order_entry;
        }

        subsales_log( 'INFO', 'delivery', 'Parsed ' . count( $parsed_orders ) . ' orders with products' );

        // Step 2: Separate team orders from individual orders
        // Orders with bad addresses OR not found in database stay with the person who entered them
        $team_orders = array();
        $individual_orders = array();
        
        foreach ( $parsed_orders as $order ) {
            // Check if address is deliverable (basic format validation)
            $is_deliverable = ! empty( $order['address'] ) && self::is_address_deliverable( $order['address'] );
            
            // Check if address was found in database (no typos)
            $found_in_db = ! empty( $order['found_in_db'] );
            
            // Orders with bad format OR not in database → assign to enterer
            if ( ! $is_deliverable || ! $found_in_db ) {
                if ( ! $is_deliverable ) {
                    subsales_log( 'WARNING', 'delivery', "Order {$order['order_id']} has undeliverable address format, assigning to enterer: {$order['address']}" );
                } elseif ( ! $found_in_db ) {
                    subsales_log( 'WARNING', 'delivery', "Order {$order['order_id']} address not found in database (typo?), assigning to enterer: {$order['address']}" );
                }
                $individual_orders[] = $order;
            } elseif ( $order['team_id'] > 0 ) {
                // Valid team order - group by date and team for distribution
                $key = $order['order_date'] . '_' . $order['team_id'];
                if ( ! isset( $team_orders[ $key ] ) ) {
                    $team_orders[ $key ] = array(
                        'date' => $order['order_date'],
                        'team_id' => $order['team_id'],
                        'orders' => array()
                    );
                }
                $team_orders[ $key ]['orders'][] = $order;
            } else {
                // Individual order (no team) - assign directly to entered_by_id
                $individual_orders[] = $order;
            }
        }

        subsales_log( 'INFO', 'delivery', 'Separated into ' . count( $team_orders ) . ' team groups and ' . count( $individual_orders ) . ' individual/problem orders' );

        // Step 3: Initialize member order counts with individual/problem orders
        $member_orders = array(); // Will hold orders per member_id
        $member_counts = array(); // Track order counts for load balancing
        
        // First, assign individual/problem orders to their respective members
        foreach ( $individual_orders as $order ) {
            $member_id = $order['entered_by_id'];
            if ( $member_id > 0 ) {
                if ( ! isset( $member_orders[ $member_id ] ) ) {
                    $member_orders[ $member_id ] = array();
                    $member_counts[ $member_id ] = 0;
                }
                $member_orders[ $member_id ][] = $order;
                $member_counts[ $member_id ]++;
            } else {
                subsales_log( 'WARNING', 'delivery', "Order {$order['order_id']} has no entered_by_id, skipping" );
            }
        }
        
        subsales_log( 'INFO', 'delivery', 'Assigned ' . count( $individual_orders ) . ' problem orders to their enterers' );
        
        // Step 4: Distribute team orders with load balancing
        // For each team/date group, distribute orders evenly while accounting for problem orders each member already has
        foreach ( $team_orders as $group ) {
            $campaign_date = $group['date'];
            $team_id = $group['team_id'];
            $orders = $group['orders'];
            
            // Find campaign for this date
            $campaign = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ss_campaigns WHERE campaign_date = %s",
                $campaign_date
            ), ARRAY_A );
            
            if ( ! $campaign ) {
                subsales_log( 'WARNING', 'delivery', "No campaign found for date {$campaign_date}, skipping " . count( $orders ) . " orders" );
                continue;
            }
            
            $campaign_id = $campaign['id'];
            
            // Get signups for this campaign and team
            $signups = $wpdb->get_results( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}ss_signups 
                 WHERE campaign_id = %d AND team_id = %d AND status = 'active'",
                $campaign_id,
                $team_id
            ), ARRAY_A );
            
            if ( empty( $signups ) ) {
                subsales_log( 'WARNING', 'delivery', "No signups found for campaign {$campaign_id}, team {$team_id}, skipping " . count( $orders ) . " orders" );
                continue;
            }
            
            $member_ids = array_map( function( $s ) { return intval( $s['user_id'] ); }, $signups );
            
            // Initialize counts for members who haven't received problem orders yet
            foreach ( $member_ids as $mid ) {
                if ( ! isset( $member_counts[ $mid ] ) ) {
                    $member_counts[ $mid ] = 0;
                    $member_orders[ $mid ] = array();
                }
            }
            
            subsales_log( 'INFO', 'delivery', "Distributing " . count( $orders ) . " orders across " . count( $member_ids ) . " members for {$campaign_date}, team {$team_id} (with load balancing)" );
            
            // Distribute orders with load balancing - always assign to member with fewest total orders
            foreach ( $orders as $order ) {
                // Find member(s) with minimum order count among this team's members
                $min_count = PHP_INT_MAX;
                foreach ( $member_ids as $mid ) {
                    if ( $member_counts[ $mid ] < $min_count ) {
                        $min_count = $member_counts[ $mid ];
                    }
                }
                
                $candidates = array();
                foreach ( $member_ids as $mid ) {
                    if ( $member_counts[ $mid ] === $min_count ) {
                        $candidates[] = $mid;
                    }
                }
                
                // If multiple members tied, pick randomly for fairness
                $member_id = $candidates[ array_rand( $candidates ) ];
                
                $member_orders[ $member_id ][] = $order;
                $member_counts[ $member_id ]++;
            }
        }

        subsales_log( 'INFO', 'delivery', 'Final distribution: ' . count( $member_orders ) . ' members with orders' );

        // Step 5: Build by_individual array for manifest generation (convert to expected format)
        $by_individual = array();
        foreach ( $member_orders as $member_id => $orders ) {
            // Get member name from database
            $user_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d",
                $member_id
            ), ARRAY_A );
            
            $member_name = ! empty( $user_row['name'] ) ? $user_row['name'] : "Member {$member_id}";
            
            $by_individual[ $member_id ] = array(
                'individual_id' => $member_id,
                'individual_name' => $member_name,
                'orders' => $orders
            );
        }

        subsales_log( 'INFO', 'delivery', 'Orders grouped into ' . count( $by_individual ) . ' individual manifests' );

        // Step 6: Combine multiple orders to the same address (preserving apt/unit numbers)
        foreach ( $by_individual as &$group ) {
            $orders = $group['orders'];
            $by_address = array();
            
            foreach ( $orders as $order ) {
                // Normalize address for grouping (retains apt/unit info)
                $addr_norm = strtolower( trim( $order['address'] ) );
                
                if ( ! isset( $by_address[ $addr_norm ] ) ) {
                    // First order for this address
                    $by_address[ $addr_norm ] = array(
                        'id' => $order['id'],
                        'order_id' => $order['order_id'], // Will show first order ID
                        'order_ids' => array( $order['order_id'] ), // Track all order IDs
                        'team_id' => $order['team_id'],
                        'entered_by_id' => $order['entered_by_id'],
                        'created_at' => $order['created_at'],
                        'order_date' => $order['order_date'],
                        'address' => $order['address'], // Use original address format
                        'customer' => array( $order['customer'] ), // Array to track multiple customers
                        'phone' => array( $order['phone'] ), // Array to track multiple phones
                        'lat' => $order['lat'],
                        'lng' => $order['lng'],
                        'products' => $order['products']
                    );
                } else {
                    // Combine with existing order for this address
                    $by_address[ $addr_norm ]['order_ids'][] = $order['order_id'];
                    
                    // Add customer name if different
                    if ( ! empty( $order['customer'] ) && ! in_array( $order['customer'], $by_address[ $addr_norm ]['customer'] ) ) {
                        $by_address[ $addr_norm ]['customer'][] = $order['customer'];
                    }
                    
                    // Add phone if different
                    if ( ! empty( $order['phone'] ) && ! in_array( $order['phone'], $by_address[ $addr_norm ]['phone'] ) ) {
                        $by_address[ $addr_norm ]['phone'][] = $order['phone'];
                    }
                    
                    // Combine products
                    foreach ( $order['products'] as $pid => $data ) {
                        if ( isset( $by_address[ $addr_norm ]['products'][ $pid ] ) ) {
                            $by_address[ $addr_norm ]['products'][ $pid ]['qty'] += $data['qty'];
                        } else {
                            $by_address[ $addr_norm ]['products'][ $pid ] = $data;
                        }
                    }
                }
            }
            
            // Convert back to indexed array and format customer/phone for display
            $combined_orders = array();
            foreach ( $by_address as $addr_data ) {
                // Format customer names (join multiple customers with " & ")
                $addr_data['customer'] = implode( ' & ', array_filter( $addr_data['customer'] ) );
                // Format phone numbers (join multiple with " / ")
                $addr_data['phone'] = implode( ' / ', array_filter( $addr_data['phone'] ) );
                $combined_orders[] = $addr_data;
            }
            
            $original_count = count( $orders );
            $combined_count = count( $combined_orders );
            if ( $original_count > $combined_count ) {
                subsales_log( 'INFO', 'delivery', "Combined {$original_count} orders into {$combined_count} addresses for " . $group['individual_name'] );
            }
            
            $group['orders'] = $combined_orders;
        }
        unset( $group );

        // Step 7: Geocode any orders missing coordinates before optimization
        $geocoded_count = 0;
        $failed_count = 0;
        $total_missing = 0;
        
        // Count how many need geocoding
        foreach ( $by_individual as $group ) {
            foreach ( $group['orders'] as $order ) {
                if ( $order['lat'] === null || $order['lng'] === null ) {
                    $total_missing++;
                }
            }
        }
        
        if ( $total_missing > 0 ) {
            echo "<!-- Geocoding {$total_missing} addresses (this may take a minute)... -->\n";
            flush();
        }
        
        $geocoding_progress = 0;
        foreach ( $by_individual as &$group ) {
            foreach ( $group['orders'] as &$order ) {
                if ( $order['lat'] === null || $order['lng'] === null ) {
                    if ( ! empty( $order['address'] ) ) {
                        $geocoding_progress++;
                        echo "<!-- Geocoding {$geocoding_progress}/{$total_missing}: {$order['address']} -->\n";
                        flush();
                        
                        $coords = self::geocode_address( $order['address'] );
                        if ( $coords ) {
                            $order['lat'] = $coords['lat'];
                            $order['lng'] = $coords['lng'];
                            $geocoded_count++;
                            
                            // Note: Not adding to wp_ss_addresses as it requires structured data (street, city, zip)
                            // Coordinates are cached in wp_order_sync_geocodes table by geocode_address() function
                        } else {
                            $failed_count++;
                            $order['geocode_failed'] = true;
                            $order['geocode_error'] = 'Could not locate address';
                            subsales_log( 'WARNING', 'delivery', "Failed to geocode address: " . $order['address'] . " (Order ID: " . $order['order_id'] . ")" );
                        }
                    } else {
                        $failed_count++;
                        $order['geocode_failed'] = true;
                        $order['geocode_error'] = 'Address missing or empty';
                        subsales_log( 'WARNING', 'delivery', "Order missing address, cannot geocode (Order ID: " . $order['order_id'] . ")" );
                    }
                }
            }
            unset( $order );
        }
        unset( $group );
        
        if ( $geocoded_count > 0 ) {
            subsales_log( 'INFO', 'delivery', "Geocoded {$geocoded_count} addresses before route optimization" );
        }
        if ( $failed_count > 0 ) {
            subsales_log( 'WARNING', 'delivery', "{$failed_count} orders could not be geocoded and may not be optimally routed" );
        }

        // Step 8: Optimize routes for each individual
        foreach ( $by_individual as &$group ) {
            $group['orders'] = self::optimize_route( $group['orders'], $start_coords );
        }
        unset( $group );

        // Build routes (10 stops per route, depot first)
        $all_routes = array();
        foreach ( $by_individual as $key => $group ) {
            $orders = $group['orders'];
            $individual_name = $group['individual_name'];
            
            // Split into routes of 10 orders each
            $total_orders = count( $orders );
            $routes_needed = ceil( $total_orders / 10 );
            $last_address = null;
            
            for ( $i = 0; $i < $routes_needed; $i++ ) {
                $route_orders = array_slice( $orders, $i * 10, 10 );
                
                // Build route URL - first route starts from depot, subsequent routes start from last address of previous route
                $waypoints = array();
                if ( $i === 0 ) {
                    // First route: start from depot
                    $waypoints[] = $start_address;
                } else if ( $last_address !== null ) {
                    // Subsequent routes: start from last address of previous route
                    $waypoints[] = $last_address;
                }
                
                foreach ( $route_orders as $order ) {
                    $waypoints[] = $order['address'];
                }
                
                // Remember last address for next route
                if ( ! empty( $route_orders ) ) {
                    $last_address = end( $route_orders )['address'];
                }
                
                $route_url = 'https://www.google.com/maps/dir/' . implode( '/', array_map( 'rawurlencode', $waypoints ) );
                
                // Log URL length for debugging QR code scanning issues
                $url_length = strlen( $route_url );
                if ( $url_length > 2000 ) {
                    subsales_log( 'WARNING', 'delivery', 'Route URL is very long (' . $url_length . ' chars) for ' . $individual_name . ' route ' . ($i + 1) . ' - QR code may be difficult to scan' );
                }
                
                $all_routes[] = array(
                    'individual' => $individual_name,
                    'route_number' => $i + 1,
                    'url' => $route_url,
                    'orders' => $route_orders
                );
            }
        }

        subsales_log( 'INFO', 'delivery', 'Built ' . count( $all_routes ) . ' total routes' );

        // Generate combined HTML for all individuals with page breaks
        $combined_html = '';
        $individual_count = count( $by_individual );
        $current_idx = 0;
        
        foreach ( $by_individual as $key => $group ) {
            $current_idx++;
            $is_last = ( $current_idx === $individual_count );
            
            $routes_for_individual = array_filter( $all_routes, function( $r ) use ( $group ) {
                return $r['individual'] === $group['individual_name'];
            });
            
            $html = self::generate_combined_manifest_html(
                $group['orders'],
                $group['individual_name'],
                $start_address,
                $delivery_date,
                $configured_products,
                $routes_for_individual
            );
            
            // Extract body content from HTML and add page break
            if ( preg_match( '/<body[^>]*>(.*)<\/body>/is', $html, $matches ) ) {
                $body_content = $matches[1];
                
                // Add page break wrapper if not the last manifest
                if ( ! $is_last ) {
                    $combined_html .= '<div class="manifest-section" style="page-break-after: always;">' . $body_content . '</div>';
                } else {
                    $combined_html .= '<div class="manifest-section">' . $body_content . '</div>';
                }
            }
        }
        
        // Wrap combined content in full HTML document
        $final_html = self::generate_combined_manifest_wrapper( $combined_html, $delivery_date );
        
        header( 'Content-Type: text/html; charset=UTF-8' );
        echo $final_html;
        exit;
    }
    
    /**
     * Generate combined manifest HTML (packing list + delivery stops)
     * 
     * @param array $orders Optimized orders for individual
     * @param string $individual_name Seller name
     * @param string $start_address Depot address
     * @param string $delivery_date Display date
     * @param array $configured_products Product configuration
     * @param array $all_routes Routes with QR code URLs (not currently used)
     * @return string HTML output
     * @since 2.2.1.230
     */
    public static function generate_combined_manifest_html( $orders, $individual_name, $start_address, $delivery_date, $configured_products, $all_routes ) {
        $display_date = ! empty( $delivery_date ) ? date( 'm/d/Y', strtotime( $delivery_date ) ) : date( 'm/d/Y' );
        
        // Calculate page count: 2 packing lists + delivery stops (QR codes removed)
        $total_pages = 2 + count( $orders );
        
        // Calculate product totals
        $product_totals = array();
        foreach ( $configured_products as $pconf ) {
            if ( isset( $pconf['id'] ) && isset( $pconf['name'] ) ) {
                $product_totals[ $pconf['id'] ] = array( 'name' => $pconf['name'], 'qty' => 0 );
            }
        }
        
        $grand_total = 0;
        foreach ( $orders as $order ) {
            foreach ( $order['products'] as $pid => $data ) {
                if ( isset( $product_totals[ $pid ] ) ) {
                    $product_totals[ $pid ]['qty'] += $data['qty'];
                    $grand_total += $data['qty'];
                }
            }
        }

        // Start HTML - Note: CSS is NOT included here because this HTML fragment
        // will be wrapped by generate_combined_manifest_wrapper() which provides the CSS
        // Wrap in <body> so wrapper regex can extract the section
        $html = '<body>';
        
        // First Packing List (PAGE 1)
        $html .= '<div class="manifest-page packing-list">';
        $html .= '<div class="page-header"><div class="seller-name">' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div></div>';
        $html .= '<h1>Packing List</h1>';
        $html .= '<table class="packing-table">';
        $html .= '<thead><tr><th>Product</th><th style="width:150px;text-align:center;">Quantity</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ( $product_totals as $pid => $data ) {
            if ( $data['qty'] > 0 ) {
                $html .= '<tr><td>' . htmlspecialchars( $data['name'], ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $data['qty'] ) . '</td></tr>';
            }
        }
        
        $html .= '<tr class="total-row"><td><strong>TOTAL ITEMS</strong></td><td style="text-align:center;"><strong>' . $grand_total . '</strong></td></tr>';
        $html .= '</tbody></table>';
        $html .= '</div>';
        
        // Second Packing List (PAGE 2)
        $html .= '<div class="manifest-page packing-list">';
        $html .= '<div class="page-header"><div class="seller-name">' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div></div>';
        $html .= '<h1>Packing List</h1>';
        $html .= '<table class="packing-table">';
        $html .= '<thead><tr><th>Product</th><th style="width:150px;text-align:center;">Quantity</th></tr></thead>';
        $html .= '<tbody>';
        
        foreach ( $product_totals as $pid => $data ) {
            if ( $data['qty'] > 0 ) {
                $html .= '<tr><td>' . htmlspecialchars( $data['name'], ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $data['qty'] ) . '</td></tr>';
            }
        }
        
        $html .= '<tr class="total-row"><td><strong>TOTAL ITEMS</strong></td><td style="text-align:center;"><strong>' . $grand_total . '</strong></td></tr>';
        $html .= '</tbody></table>';
        $html .= '</div>';
        
        // QR Code Page removed - QR codes were not scanning reliably
        // $html .= self::generate_route_qr_page( $all_routes, $delivery_date, $individual_name );

        // Delivery stops - compact horizontal layout, multiple per page
        $html .= '<div class="manifest-page">';
        $html .= '<div class="page-header"><div class="seller-name">' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div></div>';
        $html .= '<h1>Delivery Stops</h1>';
        
        // Separate orders into bad addresses and good addresses
        // Bad = invalid format OR geocoding failed
        $bad_address_orders = array();
        $good_address_orders = array();
        
        foreach ( $orders as $order ) {
            // Check if address is deliverable format
            $is_deliverable = ! empty( $order['address'] ) && self::is_address_deliverable( $order['address'] );
            
            // Check if geocoding failed
            $geocode_failed = ! empty( $order['geocode_failed'] );
            
            // Bad address if invalid format OR geocoding failed
            if ( ! $is_deliverable || $geocode_failed ) {
                // Tag the type of failure for display
                if ( ! $is_deliverable ) {
                    $order['failure_type'] = 'format';
                    $order['failure_message'] = 'Invalid or incomplete address format';
                } elseif ( $geocode_failed ) {
                    $order['failure_type'] = 'geocode';
                    $order['failure_message'] = ! empty( $order['geocode_error'] ) ? $order['geocode_error'] : 'Address could not be located';
                }
                $bad_address_orders[] = $order;
            } else {
                $good_address_orders[] = $order;
            }
        }
        
        $total_stops = count( $orders );
        $stop_num = 1;
        
        // Show bad address orders FIRST with warning banner
        if ( count( $bad_address_orders ) > 0 ) {
            $html .= '<div class="bad-address-warning">';
            $html .= '<h2 style="color:#dc3545;text-align:center;border:3px solid #dc3545;padding:15px;background:#fff3cd;margin:20px 0;">⚠️ ADDRESS PROBLEMS - REVIEW BEFORE DEPARTURE ⚠️</h2>';
            $html .= '<p style="text-align:center;font-size:16px;margin-bottom:30px;">The following ' . count( $bad_address_orders ) . ' order(s) have address issues. Contact customers to verify addresses before leaving.</p>';
            $html .= '</div>';
            
            foreach ( $bad_address_orders as $order ) {
                // Determine warning color/text based on failure type
                $failure_type = ! empty( $order['failure_type'] ) ? $order['failure_type'] : 'unknown';
                $failure_message = ! empty( $order['failure_message'] ) ? $order['failure_message'] : 'Address problem';
                
                if ( $failure_type === 'geocode' ) {
                    // Orange for geocoding failures (address looks OK but can't be found)
                    $border_color = '#fd7e14';
                    $bg_color = '#fff3cd';
                    $header_bg = '#fd7e14';
                    $header_text = '*** ADDRESS NOT FOUND - VERIFY WITH CUSTOMER ***';
                } else {
                    // Red for format invalids (missing data, can't parse)
                    $border_color = '#dc3545';
                    $bg_color = '#fff3cd';
                    $header_bg = '#dc3545';
                    $header_text = '*** INVALID ADDRESS FORMAT ***';
                }
                
                $html .= '<div class="delivery-stop bad-address-stop" style="border:3px solid ' . $border_color . ';background:' . $bg_color . ';">';
                
                // Warning header
                $html .= '<div style="background:' . $header_bg . ';color:white;padding:10px;text-align:center;font-weight:bold;font-size:16px;margin:-15px -15px 15px -15px;">' . $header_text . '</div>';
                
                // Show failure details
                $html .= '<div style="color:#dc3545;font-weight:bold;text-align:center;margin-bottom:10px;">' . htmlspecialchars( $failure_message, ENT_QUOTES, 'UTF-8' ) . '</div>';
                
                // Stop header with number and phone
                $html .= '<div class="stop-header">';
                $html .= '<div class="stop-number">Stop: ' . $stop_num . ' of ' . $total_stops . '</div>';
                if ( ! empty( $order['phone'] ) ) {
                    $html .= '<div class="stop-info"><strong>Phone:</strong> ' . htmlspecialchars( $order['phone'], ENT_QUOTES, 'UTF-8' ) . '</div>';
                }
                $html .= '</div>';
                
                // Customer name
                if ( ! empty( $order['customer'] ) ) {
                    $html .= '<div class="stop-info"><strong>Name:</strong> ' . htmlspecialchars( $order['customer'], ENT_QUOTES, 'UTF-8' ) . '</div>';
                }
                
                // Address (show even if bad so they can see what the problem is)
                $html .= '<div class="address"><strong>Address:</strong> <span style="color:#dc3545;font-weight:bold;">' . htmlspecialchars( $order['address'], ENT_QUOTES, 'UTF-8' ) . '</span></div>';
                
                // Products in horizontal table
                $html .= '<div class="products-horizontal">';
                $html .= '<table>';
                $html .= '<thead><tr><th>Products:</th>';
                
                // Product names as column headers
                foreach ( $order['products'] as $pid => $data ) {
                    $html .= '<th>' . htmlspecialchars( $data['name'], ENT_QUOTES, 'UTF-8' ) . '</th>';
                }
                $html .= '</tr></thead>';
                $html .= '<tbody><tr><td><strong>Quantity:</strong></td>';
                
                // Product quantities
                foreach ( $order['products'] as $pid => $data ) {
                    $html .= '<td>' . intval( $data['qty'] ) . '</td>';
                }
                
                $html .= '</tr></tbody></table>';
                $html .= '</div>'; // Close products-horizontal
                $html .= '</div>'; // Close delivery-stop
                
                $stop_num++;
            }
            
            // Add separator between bad and good addresses
            if ( count( $good_address_orders ) > 0 ) {
                $html .= '<div style="page-break-before:always;"></div>';
                $html .= '<div class="manifest-page">';
                $html .= '<div class="page-header"><div class="seller-name">' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div></div>';
                $html .= '<h1>Delivery Stops (Continued)</h1>';
            }
        }
        
        // Now show good address orders
        foreach ( $good_address_orders as $order ) {
            $html .= '<div class="delivery-stop">';
            
            // Stop header with number and phone
            $html .= '<div class="stop-header">';
            $html .= '<div class="stop-number">Stop: ' . $stop_num . ' of ' . $total_stops . '</div>';
            if ( ! empty( $order['phone'] ) ) {
                $html .= '<div class="stop-info"><strong>Phone:</strong> ' . htmlspecialchars( $order['phone'], ENT_QUOTES, 'UTF-8' ) . '</div>';
            }
            $html .= '</div>';
            
            // Customer name
            if ( ! empty( $order['customer'] ) ) {
                $html .= '<div class="stop-info"><strong>Name:</strong> ' . htmlspecialchars( $order['customer'], ENT_QUOTES, 'UTF-8' ) . '</div>';
            }
            
            // Address
            $html .= '<div class="address"><strong>Address:</strong> ' . htmlspecialchars( $order['address'], ENT_QUOTES, 'UTF-8' ) . '</div>';
            
            // Products in horizontal table
            $html .= '<div class="products-horizontal">';
            $html .= '<table>';
            $html .= '<thead><tr><th>Products:</th>';
            
            // Product names as column headers
            foreach ( $order['products'] as $pid => $data ) {
                $html .= '<th>' . htmlspecialchars( $data['name'], ENT_QUOTES, 'UTF-8' ) . '</th>';
            }
            $html .= '</tr></thead>';
            $html .= '<tbody><tr><td><strong>Quantity:</strong></td>';
            
            // Product quantities
            foreach ( $order['products'] as $pid => $data ) {
                $html .= '<td>' . intval( $data['qty'] ) . '</td>';
            }
            
            $html .= '</tr></tbody></table>';
            $html .= '</div>'; // Close products-horizontal
            $html .= '</div>'; // Close delivery-stop
            
            $stop_num++;
        }
        
        $html .= '</div>'; // Close page

        $html .= '</body>';
        
        return $html;
    }
    
    /**
     * Generate QR code page for routes
     * 
     * @param array $all_routes Array of route data with URLs
     * @param string $delivery_date Display date
     * @param string $individual_name Seller name for footer
     * @return string HTML for QR page
     * @since 2.2.1.230
     */
    public static function generate_route_qr_page( $all_routes, $delivery_date, $individual_name = '' ) {
        $display_date = ! empty( $delivery_date ) ? date( 'm/d/Y', strtotime( $delivery_date ) ) : date( 'm/d/Y' );
        
        $html = '<div class="qr-page">';
        $html .= '<h1>Delivery Routes - Scan to Navigate</h1>';
        $html .= '<p style="font-size:18px;margin-bottom:30px;">Scan QR codes with your phone to open route in Google Maps</p>';
        
        foreach ( $all_routes as $route ) {
            $qr_svg = self::generate_qr_code( $route['url'], 1000 );
            if ( ! empty( $qr_svg ) ) {
                $html .= '<div class="qr-container">';
                $html .= '<h2>' . htmlspecialchars( $route['individual'], ENT_QUOTES, 'UTF-8' );
                if ( isset( $route['route_number'] ) ) {
                    $html .= ' - Route ' . $route['route_number'];
                }
                $html .= '</h2>';
                $html .= $qr_svg;
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Generate QR code for URL
     * 
     * @param string $url URL to encode
     * @param int $size QR code size in pixels
     * @return string SVG data as string, or empty on failure
     * @since 2.2.1.230
     */
    public static function generate_qr_code( $url, $size = 1000 ) {
        // Check if endroid QR code library is available
        if ( ! class_exists( 'Endroid\QrCode\QrCode' ) ) {
            subsales_log( 'ERROR', 'delivery', 'QR code library not loaded' );
            return '';
        }
        
        try {
            // For long URLs (like Google Maps directions), use lower error correction
            // This creates a less dense, more scannable QR code
            $qrCode = \Endroid\QrCode\QrCode::create( $url )
                ->setSize( $size )
                ->setMargin( 20 )
                ->setErrorCorrectionLevel( new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow() );
            
            $writer = new \Endroid\QrCode\Writer\PngWriter();
            $result = $writer->write( $qrCode );
            
            // Return as base64 data URI
            return '<img src="' . $result->getDataUri() . '" alt="QR Code" />';
        } catch ( Exception $e ) {
            subsales_log( 'ERROR', 'delivery', 'QR code generation failed: ' . $e->getMessage() );
            return '';
        }
    }
    
    /**
     * Generate manifest CSS styles
     * 
     * Centralized CSS generation to avoid duplication
     * 
     * @return string CSS styles for manifest pages
     * @since 2.2.1.247
     */
    private static function get_manifest_css() {
        $css = '';
        $css .= 'body { font-family: Arial, sans-serif; margin: 0; padding: 0; }';
        $css .= '.print-instructions { background: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin: 20px; border-radius: 8px; }';
        $css .= '.print-instructions h2 { margin-top: 0; color: #856404; }';
        $css .= '.print-instructions ul { margin: 10px 0; padding-left: 20px; }';
        $css .= '.print-instructions li { margin: 8px 0; color: #856404; }';
        $css .= '.manifest-section { position: relative; }';
        $css .= '.manifest-page { page-break-after: always; padding: 30px 40px 20px 40px; position: relative; box-sizing: border-box; }';
        $css .= 'h1 { font-size: 28px; margin: 0 0 15px 0; border-bottom: 3px solid #333; padding-bottom: 8px; }';
        $css .= '.packing-list table { width: 100%; border-collapse: collapse; margin-top: 15px; }';
        $css .= '.packing-list th, .packing-list td { border: 1px solid #ccc; padding: 10px; text-align: left; font-size: 22pt; }';
        $css .= '.packing-list th { background: #f5f5f5; font-weight: bold; }';
        $css .= '.packing-list .total-row { background: #e0e0e0; font-weight: bold; }';
        $css .= '.depot { font-size: 18px; margin: 8px 0; }';
        $css .= '.delivery-stop { margin: 12px 0; padding: 12px; border-bottom: 2px solid #333; page-break-inside: avoid; }';
        $css .= '.bad-address-stop { border: 3px solid #dc3545 !important; background: #fff3cd !important; padding: 15px !important; }';
        $css .= '.bad-address-warning { margin: 20px 0; page-break-inside: avoid; }';
        $css .= '.stop-header { display: flex; justify-content: space-between; margin-bottom: 6px; }';
        $css .= '.stop-number { font-size: 18px; font-weight: bold; }';
        $css .= '.stop-info { font-size: 16px; margin: 4px 0; }';
        $css .= '.address { font-size: 16px; margin: 4px 0; }';
        $css .= '.products-horizontal { margin-top: 8px; }';
        $css .= '.products-horizontal table { width: 100%; border-collapse: collapse; }';
        $css .= '.products-horizontal th { background: #f0f0f0; padding: 6px; text-align: center; font-size: 14px; border: 1px solid #ccc; }';
        $css .= '.products-horizontal td { padding: 6px; text-align: center; font-size: 14px; border: 1px solid #ccc; }';
        $css .= '.page-header { position: absolute; top: 15px; right: 40px; font-size: 18px; font-weight: bold; color: #333; }';
        $css .= '.seller-name { text-align: right; }';
        $css .= '.qr-page { text-align: center; padding: 20px; page-break-after: always; }';
        $css .= '.qr-container { display: inline-block; width: 45%; margin: 10px 2%; vertical-align: top; text-align: center; page-break-inside: avoid; }';
        $css .= '.qr-container h2 { font-size: 16px; margin-bottom: 10px; }';
        $css .= '.qr-container img { width: 300px; height: 300px; display: block; margin: 0 auto; }';
        $css .= '.route-label { font-size: 24px; font-weight: bold; margin: 20px 0; }';
        $css .= '@media print {';
        $css .= '  @page { size: letter; margin: 0.4in 0.5in 0.4in 0.5in; }';
        $css .= '  body { margin: 0; padding: 0; }';
        $css .= '  .print-instructions { display: none; }';
        $css .= '  .manifest-page { page-break-after: always; padding: 20px 30px 10px 30px; margin: 0; }';
        $css .= '  .manifest-section:last-child .manifest-page:last-child { page-break-after: auto; }';
        $css .= '  .delivery-stop { page-break-inside: avoid; margin: 10px 0; }';
        $css .= '  .qr-container { page-break-inside: avoid; }';
        $css .= '}';
        return $css;
    }
    
    /**
     * Generate HTML wrapper for combined manifests
     * 
     * Wraps multiple individual manifests into a single printable HTML document
     * 
     * @param string $body_content Combined body content from all manifests
     * @param string $delivery_date Delivery date for title
     * @return string Complete HTML document
     * @since 2.2.1.236
     */
    public static function generate_combined_manifest_wrapper( $body_content, $delivery_date ) {
        $display_date = ! empty( $delivery_date ) ? esc_html( $delivery_date ) : date( 'Y-m-d' );
        
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
        $html .= '<title>Combined Delivery Manifests - ' . $display_date . '</title>';
        $html .= '<style>' . self::get_manifest_css() . '</style>';
        $html .= '</head><body>';
        $html .= '<div class="print-instructions">';
        $html .= '<h2>📄 Print Instructions</h2>';
        $html .= '<ul>';
        $html .= '<li>This document contains delivery manifests for all team members.</li>';
        $html .= '<li>Page breaks separate each seller\'s complete manifest.</li>';
        $html .= '<li>Use your browser\'s Print function (Ctrl+P / Cmd+P) or "Save as PDF" to print.</li>';
        $html .= '<li>This instruction box will not appear on the printed document.</li>';
        $html .= '</ul>';
        $html .= '</div>';
        $html .= $body_content;
        $html .= '</body></html>';
        
        return $html;
    }
    
    /**
     * Optimize delivery route using nearest-neighbor algorithm
     * 
     * @param array $orders Array of orders with lat/lng
     * @param array $start_coords Starting coordinates ['lat' => x, 'lng' => y]
     * @return array Optimized order array
     * @since 2.2.1.230
     */
    /**
     * Optimize route using Google Directions API with fallback to greedy algorithm
     * 
     * Uses driving distances instead of straight-line for better real-world routes.
     * For large routes (>23 stops), chunks into optimal segments.
     * 
     * @param array $orders Orders to optimize
     * @param array $start_coords Starting coordinates ['lat' => x, 'lng' => y]
     * @return array Optimized orders in optimal delivery sequence
     * @since 2.4.53
     */
    public static function optimize_route( $orders, $start_coords ) {
        if ( empty( $orders ) ) return array();

        // Separate orders with/without coordinates
        $orders_with_coords = array();
        $orders_without_coords = array();
        
        foreach ( $orders as $order ) {
            if ( $order['lat'] !== null && $order['lng'] !== null ) {
                $orders_with_coords[] = $order;
            } else {
                $orders_without_coords[] = $order;
            }
        }
        
        if ( empty( $orders_with_coords ) ) {
            // No coordinates to optimize - return as-is
            return array_merge( $orders_with_coords, $orders_without_coords );
        }

        $api_key = get_option( 'order_sync_google_maps_api_key', '' );
        $use_api = ! empty( $api_key );
        
        $optimized = array();
        $total_stops = count( $orders_with_coords );
        
        // Strategy depends on route size
        if ( $total_stops <= 23 && $use_api ) {
            // Small/medium routes: Use Google Directions API for optimal ordering
            subsales_log( 'INFO', 'delivery', "Optimizing {$total_stops}-stop route using Google Directions API" );
            
            $api_result = self::optimize_route_with_directions_api( $orders_with_coords, $start_coords );
            
            if ( $api_result !== false ) {
                $optimized = $api_result;
                subsales_log( 'INFO', 'delivery', "Successfully optimized {$total_stops} stops using Google Directions API" );
            } else {
                // API failed - fall back to greedy algorithm
                subsales_log( 'WARNING', 'delivery', "Google Directions API failed, falling back to greedy algorithm for {$total_stops} stops" );
                $optimized = self::optimize_route_greedy( $orders_with_coords, $start_coords );
            }
            
        } elseif ( $total_stops > 23 ) {
            // Large routes: Chunk into segments and optimize each
            subsales_log( 'INFO', 'delivery', "Large route ({$total_stops} stops) - chunking into segments for optimization" );
            
            $chunk_size = 23;
            $chunks = array_chunk( $orders_with_coords, $chunk_size );
            $current_start = $start_coords;
            
            foreach ( $chunks as $idx => $chunk ) {
                if ( $use_api ) {
                    $chunk_result = self::optimize_route_with_directions_api( $chunk, $current_start );
                    if ( $chunk_result !== false ) {
                        $optimized = array_merge( $optimized, $chunk_result );
                        // Next chunk starts from last stop of this chunk
                        $last_order = end( $chunk_result );
                        $current_start = array( 'lat' => $last_order['lat'], 'lng' => $last_order['lng'] );
                    } else {
                        // API failed for this chunk - use greedy
                        $chunk_result = self::optimize_route_greedy( $chunk, $current_start );
                        $optimized = array_merge( $optimized, $chunk_result );
                        $last_order = end( $chunk_result );
                        $current_start = array( 'lat' => $last_order['lat'], 'lng' => $last_order['lng'] );
                    }
                } else {
                    // No API key - use greedy for chunk
                    $chunk_result = self::optimize_route_greedy( $chunk, $current_start );
                    $optimized = array_merge( $optimized, $chunk_result );
                    $last_order = end( $chunk_result );
                    $current_start = array( 'lat' => $last_order['lat'], 'lng' => $last_order['lng'] );
                }
            }
            
            subsales_log( 'INFO', 'delivery', "Completed optimization of {$total_stops} stops in " . count( $chunks ) . " chunks" );
            
        } else {
            // No API key and route <= 23 stops - use greedy algorithm
            subsales_log( 'INFO', 'delivery', "Optimizing {$total_stops}-stop route using greedy algorithm (no API key)" );
            $optimized = self::optimize_route_greedy( $orders_with_coords, $start_coords );
        }
        
        // Append orders without coordinates at the end
        return array_merge( $optimized, $orders_without_coords );
    }
    
    /**
     * Optimize route using Google Directions API with waypoint optimization
     * 
     * @param array $orders Orders with valid coordinates (max 23)
     * @param array $start_coords Starting point ['lat' => x, 'lng' => y]
     * @return array|false Optimized orders or false on failure
     * @since 2.4.53
     */
    private static function optimize_route_with_directions_api( $orders, $start_coords ) {
        if ( empty( $orders ) || count( $orders ) > 23 ) {
            return false;
        }
        
        $api_key = get_option( 'order_sync_google_maps_api_key', '' );
        if ( empty( $api_key ) ) {
            return false;
        }
        
        // Build waypoints string: "optimize:true|lat1,lng1|lat2,lng2|..."
        $waypoint_coords = array();
        foreach ( $orders as $order ) {
            $waypoint_coords[] = $order['lat'] . ',' . $order['lng'];
        }
        $waypoints_param = 'optimize:true|' . implode( '|', $waypoint_coords );
        
        // API call: origin = start, destination = start (round trip), waypoints = all stops
        $origin = $start_coords['lat'] . ',' . $start_coords['lng'];
        $destination = $origin; // Round trip back to depot
        
        $url = sprintf(
            'https://maps.googleapis.com/maps/api/directions/json?origin=%s&destination=%s&waypoints=%s&key=%s',
            urlencode( $origin ),
            urlencode( $destination ),
            urlencode( $waypoints_param ),
            $api_key
        );
        
        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
        
        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'delivery', 'Google Directions API request failed: ' . $response->get_error_message() );
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( empty( $data['status'] ) || $data['status'] !== 'OK' ) {
            $error_msg = ! empty( $data['error_message'] ) ? $data['error_message'] : $data['status'];
            subsales_log( 'ERROR', 'delivery', 'Google Directions API returned error: ' . $error_msg );
            return false;
        }
        
        // Extract optimized waypoint order
        if ( empty( $data['routes'][0]['waypoint_order'] ) ) {
            subsales_log( 'ERROR', 'delivery', 'Google Directions API response missing waypoint_order' );
            return false;
        }
        
        $waypoint_order = $data['routes'][0]['waypoint_order'];
        
        // Reorder orders based on optimized sequence
        $optimized = array();
        foreach ( $waypoint_order as $idx ) {
            if ( isset( $orders[ $idx ] ) ) {
                $optimized[] = $orders[ $idx ];
            }
        }
        
        // Log distance savings if available
        if ( ! empty( $data['routes'][0]['legs'] ) ) {
            $total_distance = 0;
            $total_duration = 0;
            foreach ( $data['routes'][0]['legs'] as $leg ) {
                $total_distance += ! empty( $leg['distance']['value'] ) ? $leg['distance']['value'] : 0;
                $total_duration += ! empty( $leg['duration']['value'] ) ? $leg['duration']['value'] : 0;
            }
            
            $distance_km = round( $total_distance / 1000, 1 );
            $duration_min = round( $total_duration / 60 );
            subsales_log( 'INFO', 'delivery', "Optimized route: {$distance_km} km, {$duration_min} min driving time" );
        }
        
        return $optimized;
    }
    
    /**
     * Greedy nearest-neighbor route optimization (fallback algorithm)
     * 
     * Uses straight-line distance. Not optimal but fast and reliable.
     * 
     * @param array $orders Orders with valid coordinates
     * @param array $start_coords Starting point ['lat' => x, 'lng' => y]
     * @return array Optimized orders
     * @since 2.4.53
     */
    private static function optimize_route_greedy( $orders, $start_coords ) {
        if ( empty( $orders ) ) return array();

        $optimized = array();
        $remaining = $orders;
        $current_lat = $start_coords['lat'];
        $current_lng = $start_coords['lng'];

        while ( ! empty( $remaining ) ) {
            $nearest_idx = null;
            $nearest_dist = PHP_FLOAT_MAX;

            foreach ( $remaining as $idx => $order ) {
                $dist = self::haversine_distance( $current_lat, $current_lng, $order['lat'], $order['lng'] );
                
                if ( $dist < $nearest_dist ) {
                    $nearest_dist = $dist;
                    $nearest_idx = $idx;
                }
            }

            if ( $nearest_idx !== null ) {
                $optimized[] = $remaining[ $nearest_idx ];
                $current_lat = $remaining[ $nearest_idx ]['lat'];
                $current_lng = $remaining[ $nearest_idx ]['lng'];
                unset( $remaining[ $nearest_idx ] );
                $remaining = array_values( $remaining ); // Re-index
            } else {
                break;
            }
        }

        return $optimized;
    }
    
    /**
     * Parse a full address string into structured components for database matching
     * 
     * @param string $address Full address string (e.g., "123 Main St, Southington, CT 06489")
     * @return array|false Array with keys: house_number, street, city, state, zip, or false on failure
     * @since 2.4.20
     */
    public static function parse_address( $address ) {
        if ( empty( $address ) ) {
            return false;
        }
        
        // Normalize whitespace
        $address = trim( preg_replace( '/\s+/', ' ', $address ) );
        
        // Extract ZIP code (5 digits, possibly with -4 extension)
        $zip = '';
        if ( preg_match( '/\b(\d{5})(?:-\d{4})?\b/', $address, $zip_matches ) ) {
            $zip = $zip_matches[1];
            // Remove ZIP from address for further parsing
            $address = str_replace( $zip_matches[0], '', $address );
        }
        
        // Extract state - ONLY match valid US state codes (not street types like RD, DR, ST, etc.)
        // Common states for this area: CT, MA, NY, RI, etc.
        // Match state ONLY after a comma (after city name) to avoid matching street types like "JEANETTE CT"
        $state = '';
        $valid_states = 'AL|AK|AZ|AR|CA|CO|CT|DE|FL|GA|HI|ID|IL|IN|IA|KS|KY|LA|ME|MD|MA|MI|MN|MS|MO|MT|NE|NV|NH|NJ|NM|NY|NC|ND|OH|OK|OR|PA|RI|SC|SD|TN|TX|UT|VT|VA|WA|WV|WI|WY|DC';
        if ( preg_match( '/,\s*(' . $valid_states . ')\b/', $address, $state_matches ) ) {
            $state = $state_matches[1];
            // Remove only the first match (state after city), not all occurrences (preserves street types)
            $address = preg_replace( '/,\s*' . preg_quote($state, '/') . '\b/', '', $address, 1 );
        }
        
        // Remove country if present (English, Spanish, Italian variants)
        // USA, U.S.A., US, United States, EE. UU. (Spanish), Stati Uniti (Italian)
        // Remove with flexible boundaries to handle commas and spaces
        $address = preg_replace( '/[,\s]*(USA|U\.?S\.?A\.?|United\s+States|US|EE\.\s*UU\.|Stati\s+Uniti)[,\s]*$/i', '', $address );
        
        // Clean up multiple commas and extra spaces after removals
        $address = preg_replace( '/,\s*,+/', ',', $address ); // Replace ,, with single ,
        $address = preg_replace( '/,\s*$/', '', $address );    // Remove trailing comma
        $address = preg_replace( '/^\s*,/', '', $address );    // Remove leading comma
        $address = preg_replace( '/\s+/', ' ', $address );     // Normalize spaces
        $address = trim( $address );
        
        // Split remaining address by commas
        $parts = array_map( 'trim', explode( ',', $address ) );
        $parts = array_filter( $parts ); // Remove empty parts
        $parts = array_values( $parts );  // Re-index
        
        // City is typically the last part before state/zip
        $city = '';
        if ( count( $parts ) >= 2 ) {
            $city = array_pop( $parts );
            
            // Remove trailing junk words/numbers that sometimes appear in user data
            // Examples: "Southington 95", "Plantsville none", "Bristol early"
            // Keep only the actual city name (first word/phrase before trailing junk)
            $city = preg_replace( '/\s+(none|early|\d+|unit|apt|#).*$/i', '', $city );
            $city = trim( $city );
        } elseif ( count( $parts ) === 1 ) {
            // Sometimes city is omitted, default to Southington
            $city = 'Southington';
        }
        
        // Street address is what remains
        $street_address = implode( ', ', $parts );
        
        // Extract house number and optional hyphenated unit (e.g., "150-6H BURRITT ST")
        // Also handle standard formats: "150 Main St", "150A Main St", "119Rockwood Dr"
        $house_number = '';
        $unit = '';
        $street = $street_address;
        
        // First, try hyphenated unit format: "150-6H"
        if ( preg_match( '/^(\d+)-([A-Za-z0-9]+)\s+(.+)$/i', $street_address, $matches ) ) {
            $house_number = $matches[1];
            $unit = $matches[2]; // Capture unit from hyphenated format
            $street = $matches[3];
        }
        // Otherwise, standard format with optional suffix letter: "150A" or "150"
        elseif ( preg_match( '/^(\d+[A-Za-z]?)\s*(.+)$/', $street_address, $matches ) ) {
            $house_number = $matches[1];
            $street = $matches[2];
        }
        
        // Clean up duplicate trailing unit numbers (e.g., "150-6H BURRITT ST, ... 6H" → remove trailing "6H")
        // This happens when data entry includes unit in both street number and at end
        if ( ! empty( $unit ) ) {
            // Remove trailing occurrences of the unit number (standalone or after comma/space)
            $street = preg_replace( '/[,\s]+' . preg_quote( $unit, '/' ) . '\s*$/i', '', $street );
        }
        
        // Check for explicit unit keywords (Apt, Unit, #, etc.)
        // IMPORTANT: Require whitespace/punctuation before unit to avoid matching "ste" in "Steeplechase"
        // Only do this if we didn't already find a unit from hyphenated format
        if ( empty( $unit ) && preg_match( '/\b(?:apt|unit|#|suite|ste)[\s\.]+([A-Za-z0-9\-]+)/i', $street, $unit_matches ) ) {
            $unit = $unit_matches[1];
            // Remove unit from street
            $street = preg_replace( '/\b(?:apt|unit|#|suite|ste)[\s\.]+[A-Za-z0-9\-]+/i', '', $street );
            $street = trim( $street );
        }
        
        // Remove trailing duplicate house numbers or junk words
        // Examples: "ROCKWOOD DR 119", "BLUE HILLS DR early", "WINDING RIDGE."
        if ( ! empty( $house_number ) ) {
            // Remove house number if it appears at end of street
            $street = preg_replace( '/\s+' . preg_quote( $house_number, '/' ) . '\s*$/i', '', $street );
        }
        // Remove trailing periods, common junk words, or bare numbers
        $street = preg_replace( '/\s+(none|early|\d+|house|b)\s*\.?\s*$/i', '', $street );
        $street = preg_replace( '/\.\s*$/i', '', $street ); // Remove trailing period
        $street = trim( $street );
        
        // Normalize street types to match database abbreviations
        // Database uses: ST, RD, DR, LN, AV, CIR, CT, BLVD, WAY, PL, TER, PKWY
        $street_types = array(
            'STREET|ST\.?' => 'ST',
            'ROAD|RD\.?' => 'RD',
            'DRIVE|DR\.?' => 'DR',
            'LANE|LN\.?' => 'LN',
            'AVENUE|AVE\.?' => 'AV',
            'CIRCLE|CIR\.?' => 'CIR',
            'COURT|CT\.?' => 'CT',
            'BOULEVARD|BLVD\.?' => 'BLVD',
            'WAY' => 'WAY',
            'PLACE|PL\.?' => 'PL',
            'TERRACE|TER\.?' => 'TER',
            'PARKWAY|PKWY\.?' => 'PKWY'
        );
        
        foreach ( $street_types as $pattern => $abbr ) {
            // Match word boundary before the type to avoid matching middle of words
            // Use capturing group to ensure proper alternation grouping
            if ( preg_match( '/\b(' . $pattern . ')\b$/i', $street, $type_match ) ) {
                // Replace the matched type with standard abbreviation
                // Use capturing group in replacement regex to ensure correct matching
                $street = preg_replace( '/\b(' . $pattern . ')\b$/i', $abbr, $street );
                break; // Only normalize once
            }
        }
        
        return array(
            'house_number' => $house_number,
            'street' => trim( $street ),
            'unit' => $unit,
            'city' => $city,
            'state' => $state,
            'zip' => $zip
        );
    }
    
    /**
     * Validate if an address is deliverable (has minimum required components)
     * 
     * @param string $address Full address string
     * @return bool True if address appears deliverable, false otherwise
     * @since 2.4.21
     */
    public static function is_address_deliverable( $address ) {
        if ( empty( $address ) ) {
            return false;
        }
        
        // Check for minimum length (too short = probably garbage)
        if ( strlen( trim( $address ) ) < 10 ) {
            return false;
        }
        
        // Parse the address
        $parsed = self::parse_address( $address );
        if ( ! $parsed ) {
            return false;
        }
        
        // Require house number and street for delivery
        // City defaults to Southington, ZIP is optional (all orders from same town)
        if ( empty( $parsed['house_number'] ) ) {
            return false;
        }
        
        if ( empty( $parsed['street'] ) || strlen( $parsed['street'] ) < 3 ) {
            return false;
        }
        
        // ZIP is optional - all orders are from Southington, CT
        // If ZIP is present, validate it's 5 digits, but don't require it
        if ( ! empty( $parsed['zip'] ) && ! preg_match( '/^\d{5}$/', $parsed['zip'] ) ) {
            return false; // Invalid ZIP format if present
        }
        
        // Check for obvious junk (too many special characters, no letters, etc.)
        $letter_count = preg_match_all( '/[a-zA-Z]/', $parsed['street'] );
        if ( $letter_count < 3 ) {
            return false; // Street name should have at least 3 letters
        }
        
        return true;
    }
    
    /**
     * Calculate distance between two lat/lng points using Haversine formula
     * 
     * @param float $lat1 Starting latitude
     * @param float $lon1 Starting longitude
     * @param float $lat2 Ending latitude
     * @param float $lon2 Ending longitude
     * @return float Distance in kilometers
     * @since 2.2.1.230
     */
    public static function haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
        $earth_radius = 6371; // km

        $dLat = deg2rad( $lat2 - $lat1 );
        $dLon = deg2rad( $lon2 - $lon1 );

        $a = sin( $dLat / 2 ) * sin( $dLat / 2 ) +
             cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
             sin( $dLon / 2 ) * sin( $dLon / 2 );

        $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

        return $earth_radius * $c;
    }
    
    /**
     * Geocode address using Google Maps API
     * 
     * @param string $address Address to geocode
     * @return array|false Array with 'lat' and 'lng' keys, or false on failure
     * @since 2.2.1.230
     */
    /**
     * Geocode address using Google Maps API with geographic validation
     * 
     * Returns coordinates, formatted address, and location quality metrics.
     * Validates coordinates are within reasonable range (Connecticut area).
     * 
     * @param string $address Address to geocode
     * @param bool $strict_validation If true, reject coordinates outside Connecticut bounds
     * @return array|false Array with lat, lng, formatted_address, location_type, or false on failure
     * @since 2.4.55
     */
    public static function geocode_address( $address, $strict_validation = true ) {
        global $wpdb;
        
        // Check cache first
        $cache_table = $wpdb->prefix . 'order_sync_geocodes';
        $address_normalized = strtolower( trim( $address ) );
        
        $cached = $wpdb->get_row( $wpdb->prepare(
            "SELECT lat, lng, formatted_address, location_type FROM {$cache_table} WHERE LOWER(TRIM(address)) = %s LIMIT 1",
            $address_normalized
        ), ARRAY_A );
        
        if ( $cached && ! empty( $cached['lat'] ) && ! empty( $cached['lng'] ) ) {
            return array(
                'lat' => floatval( $cached['lat'] ),
                'lng' => floatval( $cached['lng'] ),
                'formatted_address' => ! empty( $cached['formatted_address'] ) ? $cached['formatted_address'] : null,
                'location_type' => ! empty( $cached['location_type'] ) ? $cached['location_type'] : 'APPROXIMATE'
            );
        }
        
        // Not in cache - geocode via API
        $api_key = get_option( 'order_sync_google_maps_api_key', '' );
        
        if ( empty( $api_key ) ) {
            subsales_log( 'ERROR', 'delivery', 'Google Maps API key not configured' );
            return false;
        }
        
        // Add components=country:US to bias results to United States
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode( $address ) . '&components=country:US&key=' . $api_key;
        
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        
        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'delivery', 'Geocoding request failed: ' . $response->get_error_message() );
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( empty( $data['results'][0]['geometry']['location'] ) ) {
            subsales_log( 'WARNING', 'delivery', 'Geocoding returned no results for address: ' . $address );
            return false;
        }
        
        $result = $data['results'][0];
        $lat = floatval( $result['geometry']['location']['lat'] );
        $lng = floatval( $result['geometry']['location']['lng'] );
        $formatted_address = ! empty( $result['formatted_address'] ) ? $result['formatted_address'] : '';
        $location_type = ! empty( $result['geometry']['location_type'] ) ? $result['geometry']['location_type'] : 'APPROXIMATE';
        
        // Geographic validation: Check if coordinates are within Connecticut bounds
        // CT bounds: roughly 40.95°N to 42.05°N, -73.75°W to -71.78°W
        // Expand slightly to include border areas
        $ct_lat_min = 40.8;
        $ct_lat_max = 42.2;
        $ct_lng_min = -73.9;
        $ct_lng_max = -71.6;
        
        $is_in_ct_area = ( $lat >= $ct_lat_min && $lat <= $ct_lat_max && $lng >= $ct_lng_min && $lng <= $ct_lng_max );
        
        if ( $strict_validation && ! $is_in_ct_area ) {
            subsales_log( 
                'WARNING', 
                'delivery', 
                sprintf( 
                    'Geocoding returned coordinates outside Connecticut area for "%s": lat=%f, lng=%f (formatted: %s)',
                    $address,
                    $lat,
                    $lng,
                    $formatted_address
                )
            );
            return false;
        }
        
        $coords = array(
            'lat' => $lat,
            'lng' => $lng,
            'formatted_address' => $formatted_address,
            'location_type' => $location_type
        );
        
        // Cache the result (check if table has formatted_address column)
        $column_check = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$cache_table}' 
             AND COLUMN_NAME = 'formatted_address'"
        );
        
        if ( $column_check ) {
            // New schema with formatted_address
            $wpdb->insert(
                $cache_table,
                array(
                    'address' => $address,
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                    'formatted_address' => $formatted_address,
                    'location_type' => $location_type,
                    'created_at' => current_time( 'mysql' )
                ),
                array( '%s', '%f', '%f', '%s', '%s', '%s' )
            );
        } else {
            // Legacy schema without formatted_address
            $wpdb->insert(
                $cache_table,
                array(
                    'address' => $address,
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                    'created_at' => current_time( 'mysql' )
                ),
                array( '%s', '%f', '%f', '%s' )
            );
        }
        
        return $coords;
    }
}
