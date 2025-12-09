<?php
/**
 * Delivery Page
 * 
 * Admin page for delivery exports and driver manifest workflows.
 * Provides:
 * - Preflight summary of total orders and unique addresses
 * - Administrative CSV export (no routing)
 * - Individual delivery manifests (optimized routing per team member)
 * 
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function order_sync_delivery_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $start_addr = esc_attr( get_option( 'order_sync_delivery_start_address', '' ) );
    // Preflight summary: compute total product orders and unique addresses
    global $wpdb;
    $orders_table = $wpdb->prefix . 'ss_orders';
    $configured_products = order_sync_get_products_config();
    $product_totals = array();
    foreach ( $configured_products as $p ) { $product_totals[ $p['id'] ] = 0; }
    $rows_all = $wpdb->get_results( "SELECT * FROM {$orders_table} ORDER BY id ASC", ARRAY_A );
    $pre_total_orders = 0;
    $by_address_pf = array();
    if ( $rows_all ) {
        foreach ( $rows_all as $rr ) {
            $od = json_decode( $rr['order_data'], true );
            if ( ! is_array( $od ) ) $od = array();
            $products_map = array();
            foreach ( $configured_products as $pconf ) { $products_map[ $pconf['id'] ] = 0; }
            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                foreach ( $od['products'] as $pr ) {
                    $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                    $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                    if ( $qty > 0 && $pid ) {
                        if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                        $products_map[ $pid ] += $qty;
                    }
                }
            } else {
                foreach ( $configured_products as $p ) {
                    $pid = $p['id'];
                    $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                    foreach ( $labels as $k ) {
                        if ( isset( $od[ $k ] ) ) {
                            $q = intval( $od[ $k ] );
                            if ( $q > 0 ) {
                                if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                                $products_map[ $pid ] += $q;
                            }
                            break;
                        }
                    }
                }
            }
            $total_qty = array_sum( array_values( $products_map ) );
            if ( $total_qty <= 0 ) continue;
            $pre_total_orders++;
            $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
            $addr_norm = order_sync_normalize_address( $address_raw );
            if ( empty( $addr_norm ) ) continue;
            if ( ! isset( $by_address_pf[ $addr_norm ] ) ) $by_address_pf[ $addr_norm ] = array();
            $by_address_pf[ $addr_norm ][] = $rr['order_id'];
            // accumulate product totals
            foreach ( $products_map as $pid => $q ) { if ( $q > 0 ) $product_totals[ $pid ] += $q; }
        }
    }
    $pre_unique_addresses = count( $by_address_pf );
    ?>
    <div class="wrap">
        <h1>Delivery</h1>
        <p class="description">Delivery exports and driver manifest workflows. Donations are excluded. Addresses will be combined by normalized address. By default exports include all orders unless a delivery date is specified.</p>
        <div style="margin:12px 0; padding:12px; background:#fff; border:1px solid #e5e5e5;">
            <strong>Preflight summary</strong>
            <p style="margin:6px 0">Total product orders: <strong><?php echo intval( $pre_total_orders ); ?></strong></p>
            <p style="margin:6px 0">Unique delivery addresses: <strong><?php echo intval( $pre_unique_addresses ); ?></strong></p>
            <?php if ( ! empty( $configured_products ) ): ?>
                <table class="widefat" style="max-width:800px; margin-top:8px;">
                    <thead><tr><th>Product</th><th style="text-align:right">Total Qty</th></tr></thead>
                    <tbody>
                    <?php foreach ( $configured_products as $p ) : ?>
                        <tr>
                            <td><?php echo esc_html( $p['name'] ); ?></td>
                            <td style="text-align:right"><?php echo intval( $product_totals[ $p['id'] ] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <!-- Administrative CSV: admin creates their own routes -> simple CSV export (no driver assignment) -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'subsales_generate_admin_csv' ); ?>
            <input type="hidden" name="action" value="subsales_generate_admin_csv" />
            <table class="form-table">
                <tr>
                    <th scope="row">Administrative CSV (no routing)</th>
                    <td>
                        <p class="description">Create a CSV export you can open in a spreadsheet to design your own routes. This export contains one row per normalized address and per-product columns. Includes all orders.</p>
                    </td>
                </tr>
            </table>
            <p class="submit"><button class="button">Generate Administrative CSV</button></p>
        </form>

        <!-- Driver manifests workflow: individual-based routing and HTML generation -->
        <h2 style="margin-top:18px">Generate Individual Delivery Manifests</h2>
        <p class="description">Generate optimized delivery routes for each team member based on their orders. Creates individual PDF manifests with packing lists.</p>
        <form id="subsales-driver-manifests" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'subsales_generate_delivery' ); ?>
            <input type="hidden" name="action" value="subsales_generate_delivery_pdf" />
            <table class="form-table">
                <tr>
                    <th scope="row">Starting address (depot)</th>
                    <td><input type="text" name="start_address" id="sdm_start_address" class="regular-text" value="<?php echo $start_addr; ?>" placeholder="Street, City, ZIP" required />
                    <p class="description">All routes will start from this location</p></td>
                </tr>
                <tr>
                    <th scope="row">Delivery date for manifest header</th>
                    <td><input type="date" name="delivery_date" id="sdm_delivery_date" value="<?php echo esc_attr( date('Y-m-d') ); ?>" />
                    <p class="description">This date will appear on the printed manifests (does not filter orders)</p></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Generate Individual Manifests (HTML)</button>
            </p>
        </form>

        <div id="subsales_preview_modal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:99999;">
            <div style="position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); width:90%; max-width:1000px; height:80%; background:#fff; border-radius:6px; overflow:hidden;">
                <div style="padding:8px; background:#f5f5f5; border-bottom:1px solid #e5e5e5; display:flex; align-items:center; justify-content:space-between;">
                    <strong>Route preview</strong>
                    <div>
                        <button id="subsales_preview_close" class="button">Close</button>
                    </div>
                </div>
                <div id="subsales_preview_map" style="width:100%; height:calc(100% - 44px);"></div>
            </div>
        </div>

        <script>
        (function(){
            const ajaxUrl = ajaxurl;
            const previewNonce = <?php echo json_encode( wp_create_nonce( 'subsales_delivery_preview' ) ); ?>;
            const previewBtn = document.getElementById('sdm_preview_btn');
            const modal = document.getElementById('subsales_preview_modal');
            const closeBtn = document.getElementById('subsales_preview_close');
            let mapInstance = null;
            let googleApiLoaded = false;

            function loadGoogleMaps(key){
                return new Promise(function(resolve,reject){
                    if ( window.google && window.google.maps ) return resolve(window.google.maps);
                    const s = document.createElement('script');
                    s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key);
                    s.async = true; s.defer = true;
                    s.onload = function(){ googleApiLoaded = true; resolve(window.google.maps); };
                    s.onerror = function(){ reject(new Error('Failed to load Google Maps')); };
                    document.head.appendChild(s);
                });
            }

            function buildColor(i){
                const palette = ['#1E90FF','#FF4500','#32CD32','#FFD700','#8A2BE2','#FF1493','#00CED1','#FF8C00','#00BFFF','#228B22'];
                return palette[i % palette.length];
            }

            function renderPreview(data){
                const products = data.products || [];
                const drivers = data.drivers || {};
                const apiKey = data.api_key || '';
                if ( ! apiKey ) { alert('No Google Maps API key configured in Settings → Overall.'); return; }
                loadGoogleMaps(apiKey).then(function(gmaps){
                    modal.style.display = 'block';
                    // create map
                    if ( mapInstance ) { /* reuse center */ }
                    const mapDiv = document.getElementById('subsales_preview_map');
                    mapDiv.innerHTML = '';
                    mapInstance = new gmaps.Map(mapDiv, { zoom: 12, center: { lat: 41.0, lng: -73.0 } });
                    const bounds = new gmaps.LatLngBounds();
                    Object.keys(drivers).forEach(function(dk, idx){
                        const rows = drivers[dk];
                        const path = [];
                        rows.forEach(function(r, ridx){
                            if ( r.lat && r.lng ) {
                                const pos = { lat: parseFloat(r.lat), lng: parseFloat(r.lng) };
                                path.push(pos);
                                const marker = new gmaps.Marker({ position: pos, map: mapInstance, title: r.address_raw, label: String(idx+1) });
                                bounds.extend(pos);
                            }
                        });
                        if ( path.length > 0 ) {
                            const poly = new gmaps.Polyline({ path: path, geodesic: true, strokeColor: buildColor(idx), strokeOpacity: 0.8, strokeWeight: 3 });
                            poly.setMap(mapInstance);
                        }
                    });
                    if ( ! bounds.isEmpty() ) mapInstance.fitBounds(bounds);
                }).catch(function(err){ alert('Map load error: ' + err.message); });
            }

            previewBtn && previewBtn.addEventListener('click', function(){
                const fd = new FormData();
                fd.append('action','subsales_delivery_preview');
                fd.append('_ajax_nonce', previewNonce);
                fd.append('start_address', document.getElementById('sdm_start_address').value || '');
                fd.append('delivery_date', document.getElementById('sdm_delivery_date').value || '');
                fd.append('driver_count', document.getElementById('sdm_driver_count').value || '2');
                fetch(ajaxUrl, { method:'POST', body: fd }).then(r=>r.json()).then(function(j){
                    if ( ! j || ! j.success ) { alert('Preview error: ' + (j && j.data ? j.data : 'Unknown')); return; }
                    renderPreview(j.data);
                }).catch(function(e){ alert('Fetch error: ' + e.message); });
            });
            closeBtn && closeBtn.addEventListener('click', function(){ modal.style.display = 'none'; });
        })();
        </script>

        <h2 style="margin-top:24px">Geocoding & limits</h2>
        <p class="description">Geocoding uses the configured Google Maps API key (Settings &rarr; Overall). Results are cached to speed repeated exports. For very large exports this may run slowly due to API rate limits—consider pre-caching addresses.</p>
        
        <?php if ( isset( $_GET['manifest_url'] ) ): ?>
        <div class="notice notice-success" style="margin-top:20px; padding:12px;">
            <p style="font-size:14px; margin:0;">
                <strong>✓ Manifests generated successfully!</strong><br/>
                <a href="<?php echo esc_url( $_GET['manifest_url'] ); ?>" target="_blank" class="button button-primary" style="margin-top:8px;">
                    📄 Open Delivery Manifests (New Tab)
                </a>
            </p>
        </div>
        <script>
        (function() {
            const manifestUrl = <?php echo json_encode( esc_url_raw( $_GET['manifest_url'] ) ); ?>;
            // Try to open in new tab (may be blocked by popup blockers)
            setTimeout(function() {
                window.open(manifestUrl, '_blank');
            }, 500);
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php
}

// ========================================
// QR Code & Route Functions
// ========================================

/**
 * Generate modern QR code with branding
 * Uses endroid/qr-code library for high-quality output
 * 
 * @param string $url The URL to encode
 * @param int $size QR code size in pixels
 * @return string Data URI for embedding in HTML, or empty string on failure
 */
function subsales_generate_qr_code( $url, $size = 800 ) {
    // Check if endroid QR code library is available
    if ( ! class_exists( 'Endroid\QrCode\QrCode' ) ) {
        return ''; // Library not loaded
    }
    
    try {
        // Generate crisp QR code with no block rounding
        // Using higher error correction and larger size for quality
        
        $qrCode = \Endroid\QrCode\QrCode::create( $url )
            ->setSize( $size )
            ->setMargin( 20 )
            ->setEncoding( new \Endroid\QrCode\Encoding\Encoding( 'UTF-8' ) )
            ->setErrorCorrectionLevel( new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh() )
            ->setRoundBlockSizeMode( new \Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeNone() )
            ->setForegroundColor( new \Endroid\QrCode\Color\Color( 0, 0, 0 ) ) // Pure black for maximum contrast
            ->setBackgroundColor( new \Endroid\QrCode\Color\Color( 255, 255, 255 ) );
        
        // Future: Add logo support
        // $logo_path = get_option( 'subsales_qr_logo' );
        // if ( $logo_path && file_exists( $logo_path ) ) {
        //     $logo = \Endroid\QrCode\Logo\Logo::create( $logo_path )
        //         ->setResizeToWidth( 60 )
        //         ->setPunchoutBackground( true );
        // }
        
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write( $qrCode );
        
        // Return data URI for embedding in HTML
        return $result->getDataUri();
        
    } catch ( Exception $e ) {
        error_log( 'QR Code generation error: ' . $e->getMessage() );
        return '';
    }
}

/**
 * Generate QR code page HTML for delivery routes
 * Creates a print-friendly grid of QR codes for route navigation
 * 
 * @param array $all_routes Array of route data with addresses
 * @param string $delivery_date Display date for the routes
 * @return string Complete HTML page content
 */
function subsales_generate_route_qr_page( $all_routes, $delivery_date = '' ) {
    $display_date = ! empty( $delivery_date ) ? date( 'F j, Y', strtotime( $delivery_date ) ) : date( 'F j, Y' );
    $total_addresses = 0;
    
    // Get the URL to the admin CSS file
    $css_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/admin-dashboard.css';
    
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Route QR Codes - ' . $display_date . '</title>
    <link rel="stylesheet" href="' . esc_url( $css_url ) . '" />
</head>
<body class="subsales-qr-viewer">
    <div class="container">
        <div class="header">
            <h1>🚚 Delivery Route QR Codes</h1>
            <div class="date"><strong>Delivery Date:</strong> ' . $display_date . '</div>
        </div>
        
        <div class="route-grid">';
    
    // Generate QR code for each route
    foreach ( $all_routes as $idx => $route_info ) {
        $route_number = $idx + 1;
        $addresses = $route_info['addresses'];
        $address_count = count( $addresses );
        $total_addresses += $address_count;
        
        // Create route data for URL
        $route_data = array(
            'route' => $route_number,
            'addresses' => $addresses,
            'date' => $display_date,
        );
        
        $encoded = base64_encode( json_encode( $route_data ) );
        // Build URL without trailing slash to avoid issues with base64 padding
        $route_url = untrailingslashit( home_url() ) . '/route/' . $encoded;
        
        // Generate QR code at very high resolution (800px) for crisp display
        $qr_data_uri = subsales_generate_qr_code( $route_url, 800 );
        
        $html .= '<div class="route-card">';
        $html .= '<div class="route-title">Route ' . $route_number . '</div>';
        $html .= '<div class="route-count">' . $address_count . ' stop' . ( $address_count !== 1 ? 's' : '' ) . '</div>';
        
        if ( $qr_data_uri ) {
            $html .= '<div class="qr-container">';
            $html .= '<img src="' . esc_attr( $qr_data_uri ) . '" alt="Route ' . $route_number . ' QR Code" />';
            $html .= '</div>';
        } else {
            $html .= '<div style="color: #d63031; padding: 20px;">QR Code generation failed</div>';
        }
        
        $html .= '</div>'; // end route-card
    }
    
    $html .= '</div>'; // end route-grid
    
    $html .= '<div class="footer-info">';
    $html .= 'Generated: ' . date( 'F j, Y g:i A' ) . ' | ';
    $html .= 'Total: ' . $total_addresses . ' addresses across ' . count( $all_routes ) . ' route' . ( count( $all_routes ) !== 1 ? 's' : '' );
    $html .= '</div>';
    
    $html .= '</div>'; // end container
    $html .= '</body></html>';
    
    return $html;
}
