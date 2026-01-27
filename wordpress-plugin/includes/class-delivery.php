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

        // Group orders by individual (entered_by_id or entered_by_name)
        $by_individual = array();
        
        foreach ( $rows as $r ) {
            $od = json_decode( $r['order_data'], true );
            if ( ! is_array( $od ) ) continue;

            // Determine individual identifier
            $individual_id = '';
            $individual_name = '';
            
            if ( ! empty( $od['entered_by_id'] ) ) {
                $individual_id = $od['entered_by_id'];
                // Look up real name from database
                $user_row = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", 
                    $individual_id 
                ), ARRAY_A );
                $individual_name = ! empty( $user_row['name'] ) ? $user_row['name'] : 
                                   ( ! empty( $od['entered_by_name'] ) ? $od['entered_by_name'] : $individual_id );
            } elseif ( ! empty( $od['entered_by_name'] ) ) {
                $individual_name = $od['entered_by_name'];
                $individual_id = $individual_name;
            } elseif ( ! empty( $r['user_id'] ) ) {
                $individual_id = $r['user_id'];
                // Look up real name from database
                $user_row = $wpdb->get_row( $wpdb->prepare( 
                    "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", 
                    $individual_id 
                ), ARRAY_A );
                $individual_name = ! empty( $user_row['name'] ) ? $user_row['name'] : $individual_id;
            } else {
                $individual_id = 'unknown';
                $individual_name = 'Unknown';
            }

            $key = $individual_id;

            if ( ! isset( $by_individual[ $key ] ) ) {
                $by_individual[ $key ] = array(
                    'individual_id' => $individual_id,
                    'individual_name' => $individual_name,
                    'orders' => array()
                );
            }

            // Parse order details using correct field names from PWA
            $order_entry = array(
                'id' => $r['id'],
                'address' => ! empty( $r['address'] ) ? $r['address'] : ( ! empty( $od['address'] ) ? $od['address'] : '' ),
                'customer' => ! empty( $od['customer'] ) ? $od['customer'] : '',
                'phone' => ! empty( $od['cellNumber'] ) ? $od['cellNumber'] : '',
                'lat' => ! empty( $r['lat'] ) ? floatval( $r['lat'] ) : null,
                'lng' => ! empty( $r['lng'] ) ? floatval( $r['lng'] ) : null,
                'products' => array()
            );

            // Handle products - check for new format first (products array), then legacy format
            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                // New format: products is an array of {id, qty}
                foreach ( $od['products'] as $product ) {
                    if ( isset( $product['id'] ) && isset( $product['qty'] ) ) {
                        $pid = $product['id'];
                        $qty = intval( $product['qty'] );
                        if ( $qty > 0 ) {
                            // Find product name from configured products
                            $pname = $pid;
                            foreach ( $configured_products as $pconf ) {
                                if ( isset( $pconf['id'] ) && $pconf['id'] === $pid ) {
                                    $pname = $pconf['name'];
                                    break;
                                }
                            }
                            $order_entry['products'][ $pid ] = array(
                                'name' => $pname,
                                'qty' => $qty
                            );
                        }
                    }
                }
            } else {
                // Legacy format: products as direct keys in order_data
                foreach ( $configured_products as $pconf ) {
                    if ( ! isset( $pconf['id'] ) ) continue;
                    $pid = $pconf['id'];
                    $qty = isset( $od[ $pid ] ) ? intval( $od[ $pid ] ) : 0;
                    if ( $qty > 0 ) {
                        $order_entry['products'][ $pid ] = array(
                            'name' => $pconf['name'],
                            'qty' => $qty
                        );
                    }
                }
            }

            // Skip orders with no products
            if ( empty( $order_entry['products'] ) ) {
                continue;
            }

            $by_individual[ $key ]['orders'][] = $order_entry;
        }

        subsales_log( 'INFO', 'delivery', 'Orders grouped into ' . count( $by_individual ) . ' individual manifests' );

        // Optimize routes for each individual
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
            
            for ( $i = 0; $i < $routes_needed; $i++ ) {
                $route_orders = array_slice( $orders, $i * 10, 10 );
                
                // Build route URL with depot first, then stops
                $waypoints = array( $start_address );
                foreach ( $route_orders as $order ) {
                    $waypoints[] = $order['address'];
                }
                
                $route_url = 'https://www.google.com/maps/dir/' . implode( '/', array_map( 'rawurlencode', $waypoints ) );
                
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
     * Generate combined manifest HTML (packing list + QR codes + delivery stops)
     * 
     * @param array $orders Optimized orders for individual
     * @param string $individual_name Seller name
     * @param string $start_address Depot address
     * @param string $delivery_date Display date
     * @param array $configured_products Product configuration
     * @param array $all_routes Routes with QR code URLs
     * @return string HTML output
     * @since 2.2.1.230
     */
    public static function generate_combined_manifest_html( $orders, $individual_name, $start_address, $delivery_date, $configured_products, $all_routes ) {
        $display_date = ! empty( $delivery_date ) ? date( 'm/d/Y', strtotime( $delivery_date ) ) : date( 'm/d/Y' );
        
        // Calculate page count: 2 packing lists + QR page + delivery stops
        $total_pages = 3 + count( $orders );
        
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
        $html .= '<h1>Packing List: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
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
        $html .= '<div class="footer">';
        $html .= '<div class="footer-left"><strong>Sales Person:</strong> ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div>';
        $html .= '<div class="footer-right">Page 1 of ' . $total_pages . ' | <strong>Date:</strong> ' . $display_date . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // Second Packing List (PAGE 2)
        $html .= '<div class="manifest-page packing-list">';
        $html .= '<h1>Packing List: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
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
        $html .= '<div class="footer">';
        $html .= '<div class="footer-left"><strong>Sales Person:</strong> ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div>';
        $html .= '<div class="footer-right">Page 2 of ' . $total_pages . ' | <strong>Date:</strong> ' . $display_date . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        
        // QR Code Page (PAGE 3)
        $html .= self::generate_route_qr_page( $all_routes, $delivery_date, $individual_name );

        // Delivery stops - compact horizontal layout, multiple per page
        $html .= '<div class="manifest-page">';
        $html .= '<h1>Delivery Stops: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
        
        $total_stops = count( $orders );
        $stop_num = 1;
        
        foreach ( $orders as $order ) {
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
        
        $html .= '<div class="footer">';
        $html .= '<div class="footer-left"><strong>Sales Person:</strong> ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div>';
        $html .= '<div class="footer-right"><strong>Date:</strong> ' . $display_date . '</div>';
        $html .= '</div>';
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
            $qr_svg = self::generate_qr_code( $route['url'], 800 );
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
    public static function generate_qr_code( $url, $size = 800 ) {
        // Check if endroid QR code library is available
        if ( ! class_exists( 'Endroid\QrCode\QrCode' ) ) {
            subsales_log( 'ERROR', 'delivery', 'QR code library not loaded' );
            return '';
        }
        
        try {
            $qrCode = \Endroid\QrCode\QrCode::create( $url )
                ->setSize( $size )
                ->setMargin( 10 )
                ->setErrorCorrectionLevel( new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh() );
            
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
        $css .= '.manifest-page { page-break-after: always; padding: 40px; min-height: 1000px; position: relative; }';
        $css .= 'h1 { font-size: 28px; margin: 0 0 20px 0; border-bottom: 3px solid #333; padding-bottom: 10px; }';
        $css .= '.packing-list table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
        $css .= '.packing-list th, .packing-list td { border: 1px solid #ccc; padding: 12px; text-align: left; font-size: 22pt; }';
        $css .= '.packing-list th { background: #f5f5f5; font-weight: bold; }';
        $css .= '.packing-list .total-row { background: #e0e0e0; font-weight: bold; }';
        $css .= '.depot { font-size: 18px; margin: 10px 0; }';
        $css .= '.delivery-stop { margin: 15px 0; padding: 15px; border-bottom: 2px solid #333; }';
        $css .= '.stop-header { display: flex; justify-content: space-between; margin-bottom: 8px; }';
        $css .= '.stop-number { font-size: 18px; font-weight: bold; }';
        $css .= '.stop-info { font-size: 16px; margin: 5px 0; }';
        $css .= '.address { font-size: 16px; margin: 5px 0; }';
        $css .= '.products-horizontal { margin-top: 10px; }';
        $css .= '.products-horizontal table { width: 100%; border-collapse: collapse; }';
        $css .= '.products-horizontal th { background: #f0f0f0; padding: 6px; text-align: center; font-size: 14px; border: 1px solid #ccc; }';
        $css .= '.products-horizontal td { padding: 6px; text-align: center; font-size: 14px; border: 1px solid #ccc; }';
        $css .= '.footer { position: absolute; bottom: 20px; left: 40px; right: 40px; font-size: 14px; color: #666; border-top: 1px solid #ccc; padding-top: 10px; display: flex; justify-content: space-between; }';
        $css .= '.footer-right { text-align: right; }';
        $css .= '.qr-page { text-align: center; padding: 20px; page-break-after: always; }';
        $css .= '.qr-container { display: inline-block; width: 45%; margin: 10px 2%; vertical-align: top; text-align: center; page-break-inside: avoid; }';
        $css .= '.qr-container h2 { font-size: 16px; margin-bottom: 10px; }';
        $css .= '.qr-container img { width: 180px; height: 180px; display: block; margin: 0 auto; }';
        $css .= '.route-label { font-size: 24px; font-weight: bold; margin: 20px 0; }';
        $css .= '@media print {';
        $css .= '  .print-instructions { display: none; }';
        $css .= '  .manifest-page { page-break-after: always; }';
        $css .= '  .manifest-section:last-child .manifest-page:last-child { page-break-after: auto; }';
        $css .= '  .delivery-stop { page-break-inside: avoid; }';
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
    public static function optimize_route( $orders, $start_coords ) {
        if ( empty( $orders ) ) return array();

        $optimized = array();
        $remaining = $orders;
        $current_lat = $start_coords['lat'];
        $current_lng = $start_coords['lng'];

        while ( ! empty( $remaining ) ) {
            $nearest_idx = null;
            $nearest_dist = PHP_FLOAT_MAX;

            foreach ( $remaining as $idx => $order ) {
                if ( $order['lat'] === null || $order['lng'] === null ) {
                    // Handle orders without coordinates - add them at the end
                    continue;
                }

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
                // All remaining orders have no coordinates - add them to the end
                foreach ( $remaining as $order ) {
                    $optimized[] = $order;
                }
                break;
            }
        }

        return $optimized;
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
    public static function geocode_address( $address ) {
        $api_key = get_option( 'order_sync_google_maps_api_key', '' );
        
        if ( empty( $api_key ) ) {
            subsales_log( 'ERROR', 'delivery', 'Google Maps API key not configured' );
            return false;
        }
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode( $address ) . '&key=' . $api_key;
        
        $response = wp_remote_get( $url );
        
        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'delivery', 'Geocoding request failed: ' . $response->get_error_message() );
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( ! empty( $data['results'][0]['geometry']['location'] ) ) {
            return array(
                'lat' => $data['results'][0]['geometry']['location']['lat'],
                'lng' => $data['results'][0]['geometry']['location']['lng']
            );
        }
        
        subsales_log( 'ERROR', 'delivery', 'Geocoding failed for address: ' . $address );
        return false;
    }
}
