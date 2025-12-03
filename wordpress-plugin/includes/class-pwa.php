<?php
/**
 * PWA (Progressive Web App) Management
 *
 * Handles PWA script registration, shortcode rendering, page management,
 * and configuration for the Subsales PWA interface.
 *
 * @package Subsales_Management
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_PWA {
    
    /**
     * Initialize PWA functionality
     * Hooks into WordPress to register scripts and shortcodes
     */
    public static function init() {
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_pwa_scripts' ) );
        add_shortcode( 'subsales_pwa', array( __CLASS__, 'pwa_shortcode' ) );
    }
    
    /**
     * Register PWA scripts and styles
     * Enqueues PWA app.js and styles.css, localizes configuration
     */
    public static function register_pwa_scripts() {
        wp_register_script( 'subsales-pwa-session-tracking', SUBSALES_PLUGIN_URL . 'pwa/session-tracking.js', array(), SUBSALES_VERSION, true );
        wp_register_script( 'subsales-pwa-app', SUBSALES_PLUGIN_URL . 'pwa/app.js', array( 'subsales-pwa-session-tracking' ), SUBSALES_VERSION, true );
        wp_register_style( 'subsales-pwa-style', SUBSALES_PLUGIN_URL . 'pwa/styles.css', array(), SUBSALES_VERSION );
        $portal_base = esc_url_raw( home_url( '/' . get_option( 'order_sync_portal_slug', 'subsales-portal' ) . '/' ) );
        $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
        $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

        $settings = array(
            'apiBase' => esc_url_raw( rest_url( 'order-manager/v1' ) ),
            'pluginBase' => SUBSALES_PLUGIN_URL . 'pwa/',
            'portalBase' => $portal_base,
            'googleMapsApiKey' => get_option( 'order_sync_google_maps_api_key', '' ),
            'sessionDuration' => intval( get_option( 'order_sync_session_duration', 86400000 ) ),
            'styleVariant' => get_option( 'order_sync_style_variant', 'default' ),
            'primaryColor' => get_option( 'order_sync_primary_color', '#2d6cdf' ),
            'brandName' => get_option( 'subsales_branding', 'Subsales' ),
            'brandingImage' => $header_image_url
        );
        // Include configured products (global, not per-team). Stored as option 'order_sync_products'.
        $settings['products'] = self::get_products_config();

        wp_localize_script( 'subsales-pwa-app', 'SUBSALES_PWA_CONFIG', $settings );
        // Enqueue style for PWA UI (also useful for shortcode pages)
        wp_enqueue_style( 'subsales-pwa-style' );
    }
    
    /**
     * PWA shortcode handler
     * Renders the PWA interface with login and order management UI
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output for PWA interface
     */
    public static function pwa_shortcode( $atts = array() ) {
        wp_enqueue_script( 'subsales-pwa-app' );

        add_action( 'wp_head', function() {
            $portal_slug = get_option( 'order_sync_portal_slug', '' );
            if ( $portal_slug ) {
                echo '<link rel="manifest" href="' . esc_url( home_url( '/' . $portal_slug . '/manifest.json' ) ) . '">';
            } else {
                echo '<link rel="manifest" href="' . esc_url( SUBSALES_PLUGIN_URL . 'pwa/manifest.json' ) . '">';
            }
            echo '<meta name="theme-color" content="#2d6cdf">';
        } );

        ob_start();
        ?>
    <?php $subsales_primary = esc_attr( get_option( 'order_sync_primary_color', '#2d6cdf' ) ); $subsales_variant = esc_attr( get_option( 'order_sync_style_variant', 'default' ) ); ?>
    <div id="subsales-pwa-root" class="sm-variant-<?php echo $subsales_variant; ?>" style="--sm-primary: <?php echo $subsales_primary; ?>;">
            <style>/* Ensure auth-only controls are hidden server-side until client reveals them */
                .sm-auth-hidden{display:none!important;visibility:hidden!important}
                /* Force branding image visible in case theme defines a generic .hidden */
                .sm-brand-image.hidden, #brandHeaderImage.hidden { display:block!important; visibility:visible!important; opacity:1!important; max-width:40%!important; height:auto!important; }
            </style>
                .sm-auth-hidden{display:none!important;visibility:hidden!important}
            </style>
            <header class="sm-header" role="banner">
                <div class="sm-header-left"></div>
                <div class="sm-header-center">
                    <?php $header_image_id = intval( get_option( 'subsales_header_image', 0 ) ); $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : ''; ?>
                    <img id="brandHeaderImage" class="sm-brand-image" src="<?php echo esc_url( $header_image_url ); ?>" alt="<?php echo esc_attr( get_option( 'subsales_branding', 'Subsales' ) ); ?>" />
                    <h1 class="sm-brand-name"><?php echo esc_html( get_option( 'subsales_branding', 'Subsales' ) ); ?></h1>
                </div>
                <div class="sm-header-right">
                    <div id="headerStatus" class="sm-auth-hidden" title="Network status" aria-live="polite" aria-label="Network status"><span id="headerDot" class="sm-header-dot"></span><span id="headerStatusText">Offline</span></div>
                    <button id="forceSyncBtn" class="sm-btn sm-auth-hidden" title="Force sync offline orders">Sync now</button>
                    <button id="viewOnlineBtn" class="sm-btn sm-auth-hidden" style="margin-right:8px">View online orders</button>
                    <span id="installBox" class="hidden sm-auth-hidden"><button id="installBtn" class="sm-btn">Install App</button></span>
                    <button id="myOrdersBtn" class="sm-btn sm-auth-hidden">My orders</button>
                    <button id="eodBtn" class="sm-btn sm-auth-hidden">EOD Tally</button>
                    <button id="logoutBtn" class="sm-btn sm-auth-hidden" title="Log out">Log out</button>
                    <button id="openInlayBtn" class="sm-btn hidden sm-auth-hidden">Queued Orders</button>
                </div>
            </header>

            <section id="loginSection" class="sm-login-section">
                <!-- Legacy login (team+code) -->
                <div id="legacyLogin" class="sm-login-mode">
                    <h2>Team Login</h2>
                    <p>Sign in with your team name and access code.</p>
                    <div class="row" style="margin-bottom:8px">
                        <img id="brandHeaderImage" class="sm-brand-image hidden" src="<?php echo esc_url( $header_image_url ); ?>" alt="<?php echo esc_attr( get_option( 'subsales_branding', 'Subsales' ) ); ?>" />
                        <div style="flex:1"></div>
                    </div>
                    <input id="teamName" placeholder="Team name" />
                    <input id="teamCode" placeholder="Access code" />
                    <div id="loginError" class="hidden" style="color:#c00;margin-top:8px"></div>
                    <div class="btn-row"><button id="loginBtn" class="sm-btn">Login</button></div>
                </div>
                
                <!-- User login (name+phone) -->
                <div id="userLogin" class="sm-login-mode hidden">
                    <h2>Login</h2>
                    <p>Sign in with your name and phone number.</p>
                    <div class="row" style="margin-bottom:8px">
                        <img id="brandHeaderImageUser" class="sm-brand-image hidden" src="<?php echo esc_url( $header_image_url ); ?>" alt="<?php echo esc_attr( get_option( 'subsales_branding', 'Subsales' ) ); ?>" />
                        <div style="flex:1"></div>
                    </div>
                    <label>Full Name</label>
                    <input id="userName" list="userSuggestions" placeholder="Start typing your name..." autocomplete="off" />
                    <datalist id="userSuggestions"></datalist>
                    <label>Phone Number</label>
                    <input id="userPhone" type="tel" inputmode="tel" placeholder="10 digits" maxlength="10" pattern="[0-9]{10}" autocomplete="off" />
                    <div id="teamSelectRow" class="sm-row hidden">
                        <label>Select Team</label>
                        <select id="userTeamSelect">
                            <option value="">-- Select team --</option>
                        </select>
                    </div>
                    <div id="individualSalesRow" class="sm-row hidden">
                        <label><input type="checkbox" id="individualSales" /> Individual Sales (not for a team)</label>
                    </div>
                    <div id="loginError" class="hidden" style="color:#c00;margin-top:8px"></div>
                    <div class="btn-row"><button id="userLoginBtn" class="sm-btn">Login</button></div>
                </div>
            </section>

            <section id="appSection" class="hidden sm-app-section" style="display:none">
                <div class="row">
                    <div class="col-2">
                        <h2>Create Order</h2>
                        <label>Customer name</label>
                        <input id="customerName" placeholder="Customer name" />
                        <label>Address</label>
                        <input id="address" placeholder="Address" />
                        <label>Cell number</label>
                        <input id="cellNumber" type="tel" inputmode="numeric" pattern="[0-9]*" placeholder="Cell number" />
                        <div class="row row-spaced">
                            <div id="productsContainer" class="row row-products" style="width:100%; display:flex; gap:8px; flex-wrap:wrap"></div>
                        </div>
                        <label>Donation amount (USD)</label>
                        <input id="donationAmount" type="number" inputmode="decimal" min="0" step="0.01" placeholder="$0.00" />
                        <div class="order-total"><strong>Order total: $<span id="orderTotal">0.00</span></strong></div>
                        <div class="pay-options">
                            <label><input type="checkbox" id="payCheck" /> <span>Pay by check</span></label>
                            <label><input type="checkbox" id="payCash" /> <span>Pay by cash</span></label>
                        </div>
                        <div id="checkNumberRow" class="check-number-row hidden"><label>Check number</label><input id="checkNumber" type="text" inputmode="numeric" pattern="[0-9]*" placeholder="Check number" /></div>
                        <label>Delivery Instructions</label>
                        <textarea id="notes" placeholder="house color, long driveway etc"></textarea>
                        <div class="btn-row"><button id="saveOrderBtn" class="sm-btn">Save Order</button></div>
                    </div>
                </div>
                <!-- Local orders removed from main view (displayed via My Orders modal/inlay) -->
                <div style="margin-top:12px; border-top:1px solid #eee; padding-top:8px">
                    <h3>Status</h3>
                    <div id="networkStatus">Offline</div>
                    <div id="syncStatus">Not synced</div>
                </div>
            </section>
        </div>
        <?php
        // Add a small script to set the page title and show branding if available in the localized config
        // Also add a robust inline fallback to hide the app section until the PWA client explicitly shows it
        echo "<script>try{const cfg=window.SUBSALES_PWA_CONFIG||{}; if(cfg.brandName){document.title=cfg.brandName+' — PWA'; const h=document.querySelector('#subsales-pwa-root h1'); if(h) h.textContent=cfg.brandName;} if(cfg.brandingImage){const img=document.getElementById('brandHeaderImage'); if(img){img.src=cfg.brandingImage; img.classList.remove('hidden');}} // robust fallback: hide app section until client shows it\n    (function(){ try{ var app=document.getElementById('appSection'); if(app){ app.style.display='none'; app.dataset.smFallbackHidden='1'; } var login=document.getElementById('loginSection'); if(login){ login.style.display='block'; } }catch(e){} })(); }catch(e){};</script>";
        return ob_get_clean();
    }
    
    /**
     * Ensure PWA page exists and is published
     * Creates or updates the portal page with the subsales_pwa shortcode
     *
     * @param string $slug Page slug (default: 'subsales-portal')
     * @return int|false Page ID on success, false on failure
     */
    public static function ensure_pwa_page( $slug = 'subsales-portal' ) {
        $slug = sanitize_title( $slug );
        $page_id = get_option( 'order_sync_pwa_page_id', 0 );
        if ( $page_id ) {
            $page = get_post( $page_id );
            if ( $page && 'publish' === $page->post_status ) {
                if ( $page->post_name !== $slug ) wp_update_post( array( 'ID' => $page_id, 'post_name' => $slug ) );
                return $page_id;
            }
        }

        $existing = get_page_by_path( $slug );
        if ( $existing ) { update_option( 'order_sync_pwa_page_id', $existing->ID ); return $existing->ID; }

        $postarr = array(
            'post_title'   => 'Subsales Portal',
            'post_name'    => $slug,
            'post_content' => '[subsales_pwa]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        );

        $new_id = wp_insert_post( $postarr );
        if ( $new_id && ! is_wp_error( $new_id ) ) { update_option( 'order_sync_pwa_page_id', $new_id ); return $new_id; }
        return false;
    }
    
    /**
     * Get products configuration
     * Returns products as an array, normalizing JSON storage format
     *
     * @return array Products configuration
     */
    public static function get_products_config() {
        $raw = get_option( 'order_sync_products', '[]' );
        if ( is_array( $raw ) ) return $raw;
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return array();
        return $decoded;
    }
}
