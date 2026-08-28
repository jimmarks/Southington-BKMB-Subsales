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
            'brandingImage' => $header_image_url,
            'digitalPaymentsEnabled' => (bool) get_option( 'subsales_digital_payments_enabled', false ),
            // Set per season (Set Up Season, step 7). Empty until an admin fills
            // it in, in which case the seller's message says "the subsales
            // administrator" rather than showing a blank where a number goes.
            'adminContactPhone' => Subsales_Season_Setup::format_phone( get_option( 'subsales_admin_contact_phone', '' ) ),
        );
        // Include configured products (global, not per-team). Stored as option 'order_sync_products'.
        $settings['products'] = self::get_products_config();

        wp_localize_script( 'subsales-pwa-app', 'SUBSALES_PWA_CONFIG', $settings );
        // Enqueue style for PWA UI (also useful for shortcode pages)
        wp_enqueue_style( 'subsales-pwa-style' );
    }
    
    /**
     * PWA shortcode handler
     * Redirects to the direct PWA path for proper service worker scope
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output with redirect message and script
     */
    public static function pwa_shortcode( $atts = array() ) {
        // Build the direct PWA URL
        $pwa_url = SUBSALES_PLUGIN_URL . 'pwa/';
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Redirecting to Subsales PWA...</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                    background: #f5f5f5;
                }
                .redirect-container {
                    text-align: center;
                    padding: 2rem;
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    max-width: 400px;
                }
                .redirect-container h1 {
                    margin: 0 0 1rem 0;
                    color: #2d6cdf;
                }
                .redirect-container p {
                    margin: 0 0 1.5rem 0;
                    color: #666;
                }
                .redirect-container a {
                    color: #2d6cdf;
                    text-decoration: none;
                    font-weight: 500;
                }
                .redirect-container a:hover {
                    text-decoration: underline;
                }
                .spinner {
                    margin: 1rem auto;
                    width: 40px;
                    height: 40px;
                    border: 4px solid #f3f3f3;
                    border-top: 4px solid #2d6cdf;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                }
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
            </style>
        </head>
        <body>
            <div class="redirect-container">
                <h1><?php echo esc_html( get_option( 'subsales_branding', 'Subsales' ) ); ?></h1>
                <div class="spinner"></div>
                <p>Redirecting to PWA...</p>
                <p><a href="<?php echo esc_url( $pwa_url ); ?>" id="manualLink">Click here if not redirected automatically</a></p>
            </div>
            <script>
                // Immediate redirect to PWA
                window.location.href = <?php echo json_encode( $pwa_url ); ?>;
            </script>
        </body>
        </html>
        <?php
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
