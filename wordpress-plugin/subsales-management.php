<?php
/**
 * Plugin Name: Subsales Management
 * Plugin URI: https://github.com/jimmarks/Southington-BKMB-Subsales
 * Description: A comprehensive order management system for mobile app synchronization with WordPress backend. Includes multi-team management, Google Maps integration, and professional admin interface. ⚠️ WARNING: By default, deleting this plugin will permanently remove ALL data. Configure deletion settings in BKMB Subsales → Settings.
 * Version: 2.0.0.96
 * Author: Jim Marks
 * Author URI: https://github.com/jimmarks
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: subsales-management
 * Domain Path: /languages
 * Network: false
 * 
 * ============================================================
 * DEVELOPMENT STATE - Last Updated: 2025-11-24
 * ============================================================
 * Current Phase: Phase 6 (Settings Toggle)
 * See: DEVELOPMENT-STATE.md in repo root for full context
 * 
 * LOCKED ARCHITECTURE (DO NOT CHANGE):
 * - Phone: REQUIRED (NOT NULL, UNIQUE, 10 digits normalized)
 * - Email: OPTIONAL (can be empty string)
 * - Login: Name search + Phone entry (user-based mode)
 * - Multi-team: user_teams junction table for many-to-many
 * ============================================================
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ---- Plugin constants ----
if ( ! defined( 'SUBSALES_VERSION' ) ) define( 'SUBSALES_VERSION', '2.0.0.96' );
if ( ! defined( 'SUBSALES_PLUGIN_URL' ) ) define( 'SUBSALES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_PATH' ) ) define( 'SUBSALES_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_BASENAME' ) ) define( 'SUBSALES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---- Implementation (merged from legacy bkmb file) ----

// Optional Composer autoload: if a vendor/autoload.php exists in the plugin directory
// require it so composer-installed libraries (for example PhpSpreadsheet) are available.
// This keeps the plugin functional when vendor/ isn't present (fallbacks remain).
$subsales_vendor_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $subsales_vendor_autoload ) ) {
    require_once $subsales_vendor_autoload;
}

// Load modular classes
require_once SUBSALES_PLUGIN_PATH . 'includes/class-database.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-rest-api.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-pwa.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-orders.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-teams.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/shapefile-parser.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/overpass-matcher.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-background-matcher.php';

// Initialize database
Subsales_Database::init();

// Initialize background matcher
Subsales_Background_Matcher::init();

// Initialize REST API
Subsales_REST_API::init();

// Initialize PWA
Subsales_PWA::init();

// Initialize Orders
Subsales_Orders::init();


// Activation/Deactivation hooks
register_activation_hook( __FILE__, 'subsales_activate' );
register_deactivation_hook( __FILE__, 'subsales_deactivate' );

function subsales_activate() {
    // Check WordPress version
    global $wp_version;
    if ( version_compare( $wp_version, '5.0', '<' ) ) {
        deactivate_plugins( SUBSALES_PLUGIN_BASENAME );
        wp_die( 'Subsales Management requires WordPress 5.0 or higher.' );
    }
    
    // Create database tables using Database class
    Subsales_Database::create_tables();
    
    // Ensure PWA page exists with default slug
    $slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    order_sync_ensure_pwa_page( $slug );
    
    // Set activation flag for admin notice and onboarding
    set_transient( 'subsales_activated', true, 30 );
    // Use a dedicated transient to trigger the onboarding wizard once (checked by onboarding function)
    set_transient( 'subsales_show_onboarding', true, 60 );
    
    // Test logging system - write activation log
    subsales_log( 'INFO', 'system', 'Subsales Management plugin activated', array(
        'version' => SUBSALES_VERSION,
        'php_version' => PHP_VERSION,
        'wp_version' => $wp_version
    ), 'admin' );
    
    // Also write a debug log to test debug mode
    subsales_log( 'DEBUG', 'system', 'Activation debug test - this should only appear when debug mode is enabled', array(), 'admin' );
    
    // Regenerate ZIP index to ensure it's in sync with any existing ZIP data files
    subsales_update_zip_index();
    subsales_log( 'INFO', 'system', 'ZIP index regenerated during activation', array(), 'admin' );
}

/**
 * Deactivation hook - cleanup only
 */
function subsales_deactivate() {
    // Nothing to do on deactivation
    // Data deletion preference is handled via modal before deactivation
}

/**
 * Add deactivation modal and JavaScript to plugins page
 */
add_action( 'admin_footer-plugins.php', 'subsales_deactivation_modal' );
function subsales_deactivation_modal() {
    // Check if user has already made a choice
    $delete_on_uninstall = get_option( 'subsales_delete_on_uninstall', '' );
    $has_choice = ( $delete_on_uninstall === 'yes' || $delete_on_uninstall === 'no' );
    
    ?>
    <div id="subsales-deactivation-modal" style="display:none;">
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 160000;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.3);">
                <h2 style="margin-top: 0; color: #d63638;">⚠️ Deactivating Subsales Management</h2>
                
                <?php if ( ! $has_choice ) : ?>
                    <p><strong>Before you deactivate, please tell us:</strong></p>
                    <p>Would you like to <strong>delete all plugin data</strong> when you eventually delete this plugin?</p>
                    
                    <div style="background: #f6f7f7; border: 1px solid #c3c4c7; padding: 15px; margin: 15px 0; border-radius: 4px;">
                        <p style="margin-top: 0;"><strong>This would include:</strong></p>
                        <ul style="margin-left: 20px; margin-bottom: 0;">
                            <li>All orders and customer data</li>
                            <li>All teams and team members</li>
                            <li>All activity logs</li>
                            <li>All plugin settings and configurations</li>
                            <li>PWA page and frontend portal</li>
                            <li>Uploaded ZIP data files</li>
                        </ul>
                    </div>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="button" onclick="subsalesDeactivateWithChoice('yes')" style="background: #d63638; border-color: #d63638; color: white;">
                            🗑️ Delete Data & Deactivate
                        </button>
                        <button type="button" class="button button-primary" onclick="subsalesDeactivateWithChoice('no')">
                            💾 Keep Data & Deactivate
                        </button>
                        <button type="button" class="button" onclick="subsalesCancelDeactivation()">
                            Cancel
                        </button>
                    </div>
                    
                    <p style="font-size: 12px; color: #646970; margin-top: 15px; margin-bottom: 0;">
                        You can change this setting anytime in <strong>BKMB Subsales → Settings</strong>
                    </p>
                <?php else : ?>
                    <p>Are you sure you want to deactivate Subsales Management?</p>
                    
                    <?php if ( $delete_on_uninstall === 'yes' ) : ?>
                        <div style="background: #fcf0f1; border: 1px solid #d63638; padding: 15px; margin: 15px 0; border-radius: 4px;">
                            <p style="margin: 0; color: #d63638;">
                                <strong>⚠️ Warning:</strong> Your data deletion setting is currently set to <strong>"Delete All Data"</strong>.
                                When you delete this plugin (not just deactivate), all your data will be permanently removed.
                            </p>
                        </div>
                    <?php else : ?>
                        <div style="background: #f0f6fc; border: 1px solid #0071a1; padding: 15px; margin: 15px 0; border-radius: 4px;">
                            <p style="margin: 0; color: #0071a1;">
                                <strong>✓ Your data will be kept safe.</strong> When you delete this plugin, your data will be preserved.
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="button" class="button button-primary" onclick="subsalesProceedDeactivation()">
                            Deactivate Plugin
                        </button>
                        <button type="button" class="button" onclick="subsalesCancelDeactivation()">
                            Cancel
                        </button>
                    </div>
                    
                    <p style="font-size: 12px; color: #646970; margin-top: 15px; margin-bottom: 0;">
                        Change deletion setting in <strong>BKMB Subsales → Settings</strong>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Store the deactivation URL when clicked
        let deactivationUrl = null;
        
        // Intercept deactivate link clicks
        $('tr[data-plugin="subsales-management/subsales-management.php"] .deactivate a').on('click', function(e) {
            e.preventDefault();
            deactivationUrl = $(this).attr('href');
            $('#subsales-deactivation-modal').show();
        });
    });
    
    function subsalesDeactivateWithChoice(choice) {
        // Show saving state
        const modal = document.getElementById('subsales-deactivation-modal');
        modal.querySelector('div > div').innerHTML = '<div style="text-align: center; padding: 40px;"><p style="font-size: 18px;">⏳ Saving preference and deactivating...</p></div>';
        
        // Save choice and then deactivate
        jQuery.post(ajaxurl, {
            action: 'subsales_set_deletion_option',
            choice: choice,
            nonce: '<?php echo wp_create_nonce( 'subsales_deletion_option' ); ?>'
        }, function(response) {
            if (response.success) {
                // Preference saved, now deactivate
                subsalesProceedDeactivation();
            } else {
                modal.querySelector('div > div').innerHTML = '<div style="text-align: center; padding: 40px;"><p style="color: #d63638;">❌ Error saving preference. Please try again.</p><button class="button" onclick="subsalesCancelDeactivation()">Close</button></div>';
            }
        }).fail(function() {
            modal.querySelector('div > div').innerHTML = '<div style="text-align: center; padding: 40px;"><p style="color: #d63638;">❌ Connection error. Please try again.</p><button class="button" onclick="subsalesCancelDeactivation()">Close</button></div>';
        });
    }
    
    function subsalesProceedDeactivation() {
        // Get the stored deactivation URL and proceed
        const deactivationUrl = jQuery('tr[data-plugin="subsales-management/subsales-management.php"] .deactivate a').attr('href');
        if (deactivationUrl) {
            window.location.href = deactivationUrl;
        }
    }
    
    function subsalesCancelDeactivation() {
        document.getElementById('subsales-deactivation-modal').style.display = 'none';
    }
    </script>
    <?php
}

/**
 * AJAX handler for deletion option
 */
add_action( 'wp_ajax_subsales_set_deletion_option', 'subsales_ajax_set_deletion_option' );
function subsales_ajax_set_deletion_option() {
    check_ajax_referer( 'subsales_deletion_option', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $choice = sanitize_text_field( $_POST['choice'] ?? '' );
    
    if ( $choice !== 'yes' && $choice !== 'no' ) {
        wp_send_json_error( array( 'message' => 'Invalid choice' ) );
    }
    
    update_option( 'subsales_delete_on_uninstall', $choice );
    delete_transient( 'subsales_show_deletion_prompt' );
    
    subsales_log( 'INFO', 'system', 'Data deletion preference set', array( 'choice' => $choice ), 'admin' );
    
    wp_send_json_success( array( 'choice' => $choice ) );
}

// Show admin notice on activation
add_action( 'admin_notices', 'subsales_activation_notice' );

function subsales_activation_notice() {
    if ( get_transient( 'subsales_activated' ) ) {
        delete_transient( 'subsales_activated' );
        
        global $wpdb;
    $teams_table = $wpdb->prefix . 'ss_teams';
    // Use proper string interpolation to check for table existence
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$teams_table}'" ) === $teams_table;
        
        if ( $table_exists ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Subsales Management activated successfully!</strong> Database tables created. You can now manage teams and orders from the <a href="' . admin_url( 'admin.php?page=subsales-management' ) . '">Subsales</a> menu.</p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-error is-dismissible">';
            echo '<p><strong>Subsales Management:</strong> Warning - Database tables may not have been created properly. Please deactivate and reactivate the plugin.</p>';
            echo '</div>';
        }
    }
}

// Helper: detect whether plugin is initialized (tables exist and essential options present)
function order_sync_is_initialized() {
    global $wpdb;
    $orders_table = $wpdb->prefix . 'ss_orders';
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$orders_table}'" ) === $orders_table;
    if ( ! $exists ) return false;
    // check for a few core options
    $portal = get_option( 'order_sync_portal_slug', '' );
    $products = get_option( 'order_sync_products', '' );
    if ( empty( $portal ) ) return false;
    if ( empty( $products ) ) return false;
    return true;
}

// Helper to get products configuration as an array regardless of storage format (JSON string or array)
function order_sync_get_products_config() {
    return Subsales_PWA::get_products_config();
}

// Debug mode visual indicators
add_action( 'admin_notices', 'subsales_debug_mode_notice' );
add_action( 'admin_footer', 'subsales_debug_mode_badge' );

function subsales_debug_mode_notice() {
    // Debug notice removed - using only the debug toggle box on logs page and floating badge
    return;
}

function subsales_debug_mode_badge() {
    $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
    if ( ! $debug_enabled ) {
        return;
    }
    
    ?>
    <div id="subsales-debug-badge" style="position: fixed; bottom: 20px; right: 20px; z-index: 99999; background: #dc3545; color: #fff; padding: 12px 18px; border-radius: 50px; box-shadow: 0 4px 12px rgba(220,53,69,0.4); font-weight: bold; font-size: 13px; cursor: pointer; animation: pulse 2s infinite;">
        <span style="font-size: 16px; margin-right: 5px;">🔍</span> DEBUG MODE
    </div>
    <style>
        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 4px 12px rgba(220,53,69,0.4); }
            50% { transform: scale(1.05); box-shadow: 0 6px 16px rgba(220,53,69,0.6); }
        }
        #subsales-debug-badge:hover {
            background: #bb2d3b;
            transform: scale(1.1) !important;
        }
    </style>
    <script>
    jQuery(document).ready(function($) {
        $('#subsales-debug-badge').on('click', function() {
            window.location.href = '?page=subsales-logs';
        });
    });
    </script>
    <?php
}

// Auto-regenerate ZIP index on admin_init if missing but ZIP files exist
add_action( 'admin_init', 'subsales_check_zip_index' );
function subsales_check_zip_index() {
    // Only check once per admin session to avoid overhead
    if ( get_transient( 'subsales_zip_index_checked' ) ) {
        return;
    }
    
    // Set transient to avoid checking again for 1 hour
    set_transient( 'subsales_zip_index_checked', true, HOUR_IN_SECONDS );
    
    // Check if zip-index.json exists
    $pwa_dir = plugin_dir_path( __FILE__ ) . 'pwa/';
    $index_file = $pwa_dir . 'zip-index.json';
    
    if ( file_exists( $index_file ) ) {
        return; // Index exists, nothing to do
    }
    
    // Index missing, check if we have any ZIP data files
    $upload = wp_upload_dir();
    $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
    
    if ( ! is_dir( $zipdata_dir ) ) {
        return; // No ZIP data directory, nothing to regenerate
    }
    
    $files = glob( $zipdata_dir . '*.json' );
    if ( empty( $files ) ) {
        return; // No ZIP files, nothing to regenerate
    }
    
    // We have ZIP files but no index - regenerate it
    subsales_update_zip_index();
    subsales_log( 'INFO', 'system', 'ZIP index auto-regenerated - was missing but ZIP files exist', array(
        'zip_count' => count( $files )
    ), 'admin' );
}

// If plugin is not initialized, show an onboarding modal to admins to walk through setup
add_action( 'admin_notices', 'order_sync_maybe_show_onboarding' );
function order_sync_maybe_show_onboarding() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // Only show the onboarding wizard immediately after activation. If the activation transient
    // is not present, don't display the setup modal anywhere else.
    if ( ! get_transient( 'subsales_show_onboarding' ) ) return;

    // If a recent import/set action asked to suppress onboarding, skip showing it briefly
    if ( get_transient( 'subsales_suppress_onboarding' ) ) { delete_transient( 'subsales_suppress_onboarding' ); delete_transient( 'subsales_show_onboarding' ); return; }

    // Remove the one-time activation transient now that we'll show the wizard
    delete_transient( 'subsales_show_onboarding' );

    // If initialized already for some reason, don't show
    if ( order_sync_is_initialized() ) return;

    // Output a professional initialization wizard with clear headers and better UX
    $nonce = wp_create_nonce( 'subsales_init_nonce' );
    ?>
    <style>
        #subsales-onboarding-overlay {
            position: fixed;
            left: 0;
            top: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        #subsales-onboarding-modal {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
            animation: subsalesSlideIn 0.3s ease-out;
        }
        @keyframes subsalesSlideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .subsales-onboarding-header {
            background: linear-gradient(135deg, #2d6cdf 0%, #1a4d9f 100%);
            color: #fff;
            padding: 30px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .subsales-onboarding-header h1 {
            margin: 0 0 10px 0;
            font-size: 28px;
            font-weight: 600;
        }
        .subsales-onboarding-header p {
            margin: 0;
            opacity: 0.95;
            font-size: 15px;
        }
        .subsales-onboarding-progress {
            display: flex;
            justify-content: space-between;
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }
        .subsales-progress-step {
            flex: 1;
            text-align: center;
            position: relative;
            padding: 0 10px;
        }
        .subsales-progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #ddd;
            z-index: -1;
        }
        .subsales-progress-step.active .subsales-step-number {
            background: #2d6cdf;
            color: #fff;
        }
        .subsales-progress-step.completed .subsales-step-number {
            background: #46b450;
            color: #fff;
        }
        .subsales-progress-step.completed::after {
            background: #46b450;
        }
        .subsales-step-number {
            display: inline-block;
            width: 32px;
            height: 32px;
            line-height: 32px;
            border-radius: 50%;
            background: #ddd;
            color: #666;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .subsales-step-label {
            display: block;
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }
        .subsales-onboarding-body {
            padding: 30px;
            min-height: 280px;
        }
        .subsales-step-content {
            display: none;
        }
        .subsales-step-content.active {
            display: block;
        }
        .subsales-step-title {
            font-size: 22px;
            font-weight: 600;
            margin: 0 0 8px 0;
            color: #1a1a1a;
        }
        .subsales-step-description {
            color: #666;
            margin: 0 0 24px 0;
            font-size: 14px;
        }
        .subsales-form-group {
            margin-bottom: 20px;
        }
        .subsales-form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 14px;
        }
        .subsales-form-group input[type="text"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .subsales-form-group input[type="text"]:focus {
            border-color: #2d6cdf;
            outline: none;
            box-shadow: 0 0 0 3px rgba(45, 108, 223, 0.1);
        }
        .subsales-form-group .description {
            margin: 6px 0 0 0;
            font-size: 13px;
            color: #666;
            font-style: italic;
        }
        .subsales-product-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }
        .subsales-review-section {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 20px;
        }
        .subsales-review-item {
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e0e0e0;
        }
        .subsales-review-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        .subsales-review-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .subsales-review-value {
            color: #1a1a1a;
            font-size: 15px;
        }
        .subsales-onboarding-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-radius: 0 0 8px 8px;
        }
        .subsales-onboarding-footer button {
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .subsales-btn-cancel {
            background: transparent;
            color: #666;
            border: 1px solid #ddd !important;
        }
        .subsales-btn-cancel:hover {
            background: #f5f5f5;
            color: #333;
        }
        .subsales-btn-back {
            background: #fff;
            color: #666;
            border: 1px solid #ddd !important;
        }
        .subsales-btn-back:hover:not(:disabled) {
            background: #f5f5f5;
            color: #333;
        }
        .subsales-btn-back:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        .subsales-btn-next {
            background: #2d6cdf;
            color: #fff;
            border: none !important;
        }
        .subsales-btn-next:hover {
            background: #1a4d9f;
        }
        #onb_status {
            margin-top: 16px;
            padding: 12px;
            border-radius: 4px;
            text-align: center;
            font-weight: 500;
            display: none;
        }
        #onb_status.processing {
            background: #e3f2fd;
            color: #1976d2;
            border: 1px solid #90caf9;
        }
        #onb_status.success {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #81c784;
        }
        #onb_status.error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }
    </style>
    <div id="subsales-onboarding-overlay">
        <div id="subsales-onboarding-modal">
            <!-- Header -->
            <div class="subsales-onboarding-header">
                <h1>🚀 Welcome to Subsales</h1>
                <p>Let's get your subsales system configured in just a few steps</p>
            </div>

            <!-- Progress Indicator -->
            <div class="subsales-onboarding-progress">
                <div class="subsales-progress-step active" data-step-indicator="1">
                    <span class="subsales-step-number">1</span>
                    <span class="subsales-step-label">Branding</span>
                </div>
                <div class="subsales-progress-step" data-step-indicator="2">
                    <span class="subsales-step-number">2</span>
                    <span class="subsales-step-label">Portal</span>
                </div>
                <div class="subsales-progress-step" data-step-indicator="3">
                    <span class="subsales-step-number">3</span>
                    <span class="subsales-step-label">Products</span>
                </div>
                <div class="subsales-progress-step" data-step-indicator="4">
                    <span class="subsales-step-number">4</span>
                    <span class="subsales-step-label">Teams</span>
                </div>
                <div class="subsales-progress-step" data-step-indicator="5">
                    <span class="subsales-step-number">5</span>
                    <span class="subsales-step-label">Review</span>
                </div>
            </div>

            <!-- Body -->
            <div class="subsales-onboarding-body">
                <!-- Step 1: Branding -->
                <div class="subsales-step-content active" data-step="1">
                    <h2 class="subsales-step-title">🎨 Customize Your Branding</h2>
                    <p class="subsales-step-description">Choose a brand name that will appear throughout your subsales portal and mobile app.</p>
                    <div class="subsales-form-group">
                        <label for="onb_branding">Brand Name</label>
                        <input id="onb_branding" type="text" value="<?php echo esc_attr( get_option( 'subsales_branding', 'Subsales' ) ); ?>" placeholder="e.g., BKMB Subsales" />
                        <p class="description">This will be displayed in the portal header and mobile app.</p>
                    </div>
                </div>

                <!-- Step 2: Portal & Maps -->
                <div class="subsales-step-content" data-step="2">
                    <h2 class="subsales-step-title">🌐 Portal & Maps Configuration</h2>
                    <p class="subsales-step-description">Set up your customer-facing portal and enable address autocomplete features.</p>
                    <div class="subsales-form-group">
                        <label for="onb_portal_slug">Portal URL Slug</label>
                        <input id="onb_portal_slug" type="text" value="<?php echo esc_attr( get_option( 'order_sync_portal_slug', 'subsales-portal' ) ); ?>" placeholder="subsales-portal" />
                        <p class="description">Your portal will be accessible at: <strong><?php echo esc_html( home_url( '/' ) ); ?><span id="slug_preview">subsales-portal</span>/</strong></p>
                    </div>
                    <div class="subsales-form-group">
                        <label for="onb_maps_key">Google Maps API Key (Optional)</label>
                        <input id="onb_maps_key" type="text" value="<?php echo esc_attr( get_option( 'order_sync_google_maps_api_key', '' ) ); ?>" placeholder="AIza..." />
                        <p class="description">Enables address autocomplete. <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Get your API key</a> or skip for now.</p>
                    </div>
                    <div class="subsales-form-group">
                        <label for="onb_zip_codes">ZIP Codes You Serve (Optional)</label>
                        <input id="onb_zip_codes" type="text" value="<?php echo esc_attr( get_option( 'subsales_served_zips', '' ) ); ?>" placeholder="e.g., 06489, 06479, 06451" />
                        <p class="description">Enter ZIP codes separated by commas. You can configure offline address data later in Settings → Address Extracts.</p>
                    </div>
                </div>

                <!-- Step 3: Products -->
                <div class="subsales-step-content" data-step="3">
                    <h2 class="subsales-step-title">📦 Configure Products</h2>
                    <p class="subsales-step-description">Add up to 3 sample products to get started. You can add more later in Settings.</p>
                    <div id="onb_products">
                        <!-- Column Headers -->
                        <div class="subsales-product-row" style="margin-bottom: 8px;">
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 0;">Product Name</label>
                            </div>
                            <div>
                                <label style="display: block; font-size: 13px; font-weight: 600; color: #333; margin-bottom: 0;">Price ($)</label>
                            </div>
                        </div>
                        <!-- Product Row 1 -->
                        <div class="subsales-product-row">
                            <div>
                                <input class="onb_prod_name" type="text" placeholder="e.g., Turkey" value="Turkey" />
                            </div>
                            <div>
                                <input class="onb_prod_price" type="text" placeholder="20.00" value="20.00" />
                            </div>
                        </div>
                        <!-- Product Row 2 -->
                        <div class="subsales-product-row">
                            <div>
                                <input class="onb_prod_name" type="text" placeholder="e.g., Ham" value="Ham" />
                            </div>
                            <div>
                                <input class="onb_prod_price" type="text" placeholder="18.00" value="18.00" />
                            </div>
                        </div>
                        <!-- Product Row 3 -->
                        <div class="subsales-product-row">
                            <div>
                                <input class="onb_prod_name" type="text" placeholder="e.g., Combo" value="Combo" />
                            </div>
                            <div>
                                <input class="onb_prod_price" type="text" placeholder="35.00" value="35.00" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 4: Teams -->
                <div class="subsales-step-content" data-step="4">
                    <h2 class="subsales-step-title">👥 Create Your First Team & User</h2>
                    <p class="subsales-step-description">Set up your initial team and create the first user who can log in via the mobile app.</p>
                    
                    <h3 style="margin-top: 20px; margin-bottom: 12px; font-size: 16px; color: #333;">Team Information</h3>
                    <div class="subsales-form-group">
                        <label for="onb_team_name">Team Name</label>
                        <input id="onb_team_name" type="text" value="Default Team" placeholder="e.g., Sales Team A" />
                        <p class="description">A friendly name for your team.</p>
                    </div>
                    <div class="subsales-form-group">
                        <label for="onb_team_code">Team Access Code</label>
                        <input id="onb_team_code" type="text" value="changeme" placeholder="e.g., TEAM2024" />
                        <p class="description"><strong>Important:</strong> This code is for team-based login. Change this to something secure!</p>
                    </div>
                    
                    <h3 style="margin-top: 24px; margin-bottom: 12px; font-size: 16px; color: #333;">First User</h3>
                    <div class="subsales-form-group">
                        <label for="onb_user_name">User Name *</label>
                        <input id="onb_user_name" type="text" value="" placeholder="e.g., John Doe" required />
                        <p class="description">Required. This user will be assigned to the team above.</p>
                    </div>
                    <div class="subsales-form-group">
                        <label for="onb_user_phone">User Phone Number *</label>
                        <input id="onb_user_phone" type="tel" value="" placeholder="555-123-4567" pattern="[0-9]{3}[-\.\s]?[0-9]{3}[-\.\s]?[0-9]{4}" required />
                        <p class="description">Required. 10-digit phone number. This will be used for user-based login.</p>
                    </div>
                    <div class="subsales-form-group">
                        <label for="onb_user_email">User Email (Optional)</label>
                        <input id="onb_user_email" type="email" value="" placeholder="user@example.com" />
                        <p class="description">Optional. Email address for this user.</p>
                    </div>
                </div>

                <!-- Step 5: Review -->
                <div class="subsales-step-content" data-step="5">
                    <h2 class="subsales-step-title">✅ Review Your Configuration</h2>
                    <p class="subsales-step-description">Please review your settings before completing the initialization.</p>
                    <div class="subsales-review-section" id="onb_review"></div>
                </div>

                <div id="onb_status"></div>
            </div>

            <!-- Footer -->
            <div class="subsales-onboarding-footer">
                <button id="onb_cancel" class="subsales-btn-cancel">Dismiss</button>
                <div>
                    <button id="onb_prev" class="subsales-btn-back" disabled>← Back</button>
                    <button id="onb_next" class="subsales-btn-next">Next →</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var step = 1; 
        var max = 5;
        
        // Update portal slug preview in real-time
        var portalSlugInput = document.getElementById('onb_portal_slug');
        if (portalSlugInput) {
            portalSlugInput.addEventListener('input', function() {
                var preview = document.getElementById('slug_preview');
                if (preview) {
                    preview.textContent = this.value || 'subsales-portal';
                }
            });
        }
        
        function updateProgressIndicator() {
            for (var i = 1; i <= max; i++) {
                var indicator = document.querySelector('[data-step-indicator="' + i + '"]');
                if (indicator) {
                    indicator.classList.remove('active', 'completed');
                    if (i < step) {
                        indicator.classList.add('completed');
                    } else if (i === step) {
                        indicator.classList.add('active');
                    }
                }
            }
        }
        
        function showStep() {
            // Update content visibility
            var allSteps = document.querySelectorAll('.subsales-step-content');
            allSteps.forEach(function(el) {
                el.classList.remove('active');
            });
            var currentStep = document.querySelector('[data-step="' + step + '"]');
            if (currentStep) {
                currentStep.classList.add('active');
            }
            
            // Update progress indicator
            updateProgressIndicator();
            
            // Update buttons
            document.getElementById('onb_prev').disabled = (step <= 1);
            var nextBtn = document.getElementById('onb_next');
            if (step >= max) {
                nextBtn.innerHTML = '✓ Complete Setup';
                nextBtn.style.background = '#46b450';
            } else {
                nextBtn.innerHTML = 'Next →';
                nextBtn.style.background = '';
            }
            
            // Populate review on step 5
            if (step === 5) {
                var review = document.getElementById('onb_review');
                var html = '';
                
                // Branding
                html += '<div class="subsales-review-item">';
                html += '<div class="subsales-review-label">Brand Name</div>';
                html += '<div class="subsales-review-value">' + (document.getElementById('onb_branding').value || 'Subsales') + '</div>';
                html += '</div>';
                
                // Portal
                html += '<div class="subsales-review-item">';
                html += '<div class="subsales-review-label">Portal URL</div>';
                html += '<div class="subsales-review-value"><?php echo esc_js( home_url( '/' ) ); ?>' + (document.getElementById('onb_portal_slug').value || 'subsales-portal') + '/</div>';
                html += '</div>';
                
                // Google Maps
                var mapsKey = document.getElementById('onb_maps_key').value;
                html += '<div class="subsales-review-item">';
                html += '<div class="subsales-review-label">Google Maps API</div>';
                html += '<div class="subsales-review-value">' + (mapsKey ? '✓ API key provided' : '⚠ No API key (address autocomplete disabled)') + '</div>';
                html += '</div>';
                
                // ZIP Codes
                var zipCodes = document.getElementById('onb_zip_codes').value;
                html += '<div class="subsales-review-item">';
                html += '<div class="subsales-review-label">ZIP Codes Served</div>';
                html += '<div class="subsales-review-value">' + (zipCodes ? zipCodes : '(Not configured - can be set later)') + '</div>';
                html += '</div>';
                
                // Products
                var prodNames = document.querySelectorAll('.onb_prod_name');
                var prodPrices = document.querySelectorAll('.onb_prod_price');
                var productsList = '';
                var productCount = 0;
                for (var i = 0; i < prodNames.length; i++) {
                    if (prodNames[i].value) {
                        productCount++;
                        productsList += '<li><strong>' + prodNames[i].value + '</strong> - $' + (prodPrices[i].value || '0.00') + '</li>';
                    }
                }
                html += '<div class="subsales-review-item">';
                html += '<div class="subsales-review-label">Products (' + productCount + ')</div>';
                html += '<div class="subsales-review-value">' + (productsList ? '<ul style="margin: 8px 0; padding-left: 20px;">' + productsList + '</ul>' : 'No products configured') + '</div>';
                html += '</div>';
                
                // Team
                html += '<div class="subsales-review-item">';
                html += '<div class="subsales-review-label">Initial Team</div>';
                html += '<div class="subsales-review-value"><strong>' + (document.getElementById('onb_team_name').value || 'Default Team') + '</strong><br/>';
                html += '<small style="color: #666;">Team Access Code: ' + (document.getElementById('onb_team_code').value || 'changeme') + '</small></div>';
                html += '</div>';
                
                // User
                var userName = document.getElementById('onb_user_name').value;
                var userPhone = document.getElementById('onb_user_phone').value;
                var userEmail = document.getElementById('onb_user_email').value;
                html += '<div class="subsales-review-item">';
                html += '<div class="subsales-review-label">First User</div>';
                html += '<div class="subsales-review-value">';
                if (userName && userPhone) {
                    html += '<strong>' + userName + '</strong><br/>';
                    html += '<small style="color: #666;">📞 ' + userPhone + (userEmail ? '<br/>✉️ ' + userEmail : '') + '</small>';
                } else {
                    html += '<span style="color: #d63638;">⚠️ User name and phone number are required</span>';
                }
                html += '</div></div>';
                
                review.innerHTML = html;
            }
        }
        
        showStep();
        
        document.getElementById('onb_next').addEventListener('click', function() {
            if (step < max) {
                // Validate user fields before moving to review
                if (step === 4) {
                    var userName = document.getElementById('onb_user_name').value.trim();
                    var userPhone = document.getElementById('onb_user_phone').value.trim();
                    if (!userName) {
                        alert('Please enter a user name.');
                        return;
                    }
                    if (!userPhone) {
                        alert('Please enter a phone number.');
                        return;
                    }
                    // Validate phone number format (10 digits)
                    var phoneDigits = userPhone.replace(/[^0-9]/g, '');
                    if (phoneDigits.length !== 10 && phoneDigits.length !== 11) {
                        alert('Phone number must be 10 digits (or 11 with leading 1).');
                        return;
                    }
                }
                step++;
                showStep();
            } else {
                // Apply initialization via AJAX
                var fd = new FormData();
                fd.append('action', 'subsales_run_init');
                fd.append('nonce', '<?php echo $nonce; ?>');
                fd.append('branding', document.getElementById('onb_branding').value);
                fd.append('portal_slug', document.getElementById('onb_portal_slug').value);
                fd.append('maps_key', document.getElementById('onb_maps_key').value);
                fd.append('zip_codes', document.getElementById('onb_zip_codes').value);
                fd.append('team_name', document.getElementById('onb_team_name').value);
                fd.append('team_code', document.getElementById('onb_team_code').value);
                fd.append('user_name', document.getElementById('onb_user_name').value);
                fd.append('user_phone', document.getElementById('onb_user_phone').value);
                fd.append('user_email', document.getElementById('onb_user_email').value);
                
                // Products
                var names = document.querySelectorAll('.onb_prod_name');
                var prices = document.querySelectorAll('.onb_prod_price');
                for (var i = 0; i < names.length; i++) {
                    fd.append('product_name[]', names[i].value);
                    fd.append('product_price[]', prices[i].value);
                    fd.append('product_visible[]', '1');
                }
                
                var status = document.getElementById('onb_status');
                status.style.display = 'block';
                status.className = 'processing';
                status.innerHTML = '⏳ Initializing your subsales system...<br/><small>Creating database tables, portal page, and configuring settings...</small>';
                
                // Disable buttons during processing
                document.getElementById('onb_next').disabled = true;
                document.getElementById('onb_prev').disabled = true;
                document.getElementById('onb_cancel').disabled = true;
                
                fetch(ajaxurl, { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(j) {
                        if (!j || !j.success) {
                            status.className = 'error';
                            status.innerHTML = '❌ Initialization failed<br/><small>' + (j && j.data ? JSON.stringify(j.data) : 'Unknown error') + '</small>';
                            // Re-enable buttons
                            document.getElementById('onb_next').disabled = false;
                            document.getElementById('onb_prev').disabled = false;
                            document.getElementById('onb_cancel').disabled = false;
                        } else {
                            status.className = 'success';
                            status.innerHTML = '✅ Initialization complete!<br/><small>Redirecting to dashboard...</small>';
                            setTimeout(function() {
                                document.getElementById('subsales-onboarding-overlay').style.display = 'none';
                                window.location.href = '<?php echo admin_url( 'admin.php?page=subsales-management' ); ?>';
                            }, 1500);
                        }
                    })
                    .catch(function(e) {
                        status.className = 'error';
                        status.innerHTML = '❌ Network error<br/><small>' + e.message + '</small>';
                        // Re-enable buttons
                        document.getElementById('onb_next').disabled = false;
                        document.getElementById('onb_prev').disabled = false;
                        document.getElementById('onb_cancel').disabled = false;
                    });
            }
        });
        
        document.getElementById('onb_prev').addEventListener('click', function() {
            if (step > 1) {
                step--;
                showStep();
            }
        });
        
        document.getElementById('onb_cancel').addEventListener('click', function() {
            if (confirm('Are you sure you want to dismiss the setup wizard?\n\nYou can configure these settings later in the plugin settings page.')) {
                document.getElementById('subsales-onboarding-overlay').style.display = 'none';
            }
        });
    })();
    </script>
    <?php
}

// Hook to add admin menu
add_action( 'admin_menu', 'order_sync_admin_menu' );

// Add admin menu item
function order_sync_admin_menu() {
    // Add main menu item at position 26 (after Comments at 25)
    add_menu_page(
        'Subsales Management',           // Page title
        'Subsales',                     // Menu title
        'manage_options',                    // Capability
        'subsales-management',          // Menu slug
        'order_sync_main_page',              // Function
        'dashicons-clipboard',               // Icon
        26                                   // Position (after Comments)
    );
    
    // Add submenu pages
    add_submenu_page(
        'subsales-management',
        'Settings',
        'Settings',
        'manage_options',
        'subsales-settings',
        'order_sync_settings_page'
    );
    
    add_submenu_page(
        'subsales-management',
        'Teams Management',
        'Teams',
        'manage_options',
        'subsales-teams',
        'ss_teams_page'
    );
    
    add_submenu_page(
        'subsales-management',
        'Orders',
        'Orders',
        'manage_options',
        'subsales-orders',
        'order_sync_orders_page'
    );

    add_submenu_page(
        'subsales-management',
        'Delivery',
        'Delivery',
        'manage_options',
        'subsales-delivery',
        'order_sync_delivery_page'
    );
    
    // REMOVED: Standalone "Address Extracts" menu - now consolidated under Settings → Address Management
    // add_submenu_page(
    //     'subsales-management',
    //     'Address Extracts',
    //     'Address Extracts',
    //     'manage_options',
    //     'subsales-address-extracts',
    //     'subsales_address_extracts_page'
    // );
    
    add_submenu_page(
        'subsales-management',
        'System Logs',
        'Logs',
        'manage_options',
        'subsales-logs',
        'subsales_logs_page'
    );
    
    // Get active PWA sessions count for menu badge
    $active_pwa_count = count( Subsales_Database::get_active_pwa_sessions( 50 ) );
    $pwa_menu_title = $active_pwa_count > 0 
        ? sprintf( 'App Sessions <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>', $active_pwa_count, $active_pwa_count )
        : 'App Sessions';
    
    add_submenu_page(
        'subsales-management',
        'App Sessions',
        $pwa_menu_title,
        'manage_options',
        'subsales-pwa-sessions',
        'subsales_pwa_sessions_page'
    );
}

// AJAX handler to search/preview address across all sources
add_action( 'wp_ajax_subsales_search_address', 'subsales_search_address_preview' );
add_action( 'wp_ajax_subsales_extract_openaddresses_zips', 'subsales_extract_openaddresses_zips' );
add_action( 'wp_ajax_subsales_download_openaddresses', 'subsales_download_openaddresses' );
add_action( 'wp_ajax_subsales_toggle_debug', 'subsales_toggle_debug_ajax' );
add_action( 'wp_ajax_subsales_get_active_sessions_count', 'subsales_get_active_sessions_count_ajax' );
add_action( 'wp_ajax_subsales_match_addresses_batch', 'subsales_match_addresses_batch_ajax' );

// Background matching AJAX handlers
add_action( 'wp_ajax_subsales_bg_match_start', 'subsales_bg_match_start_ajax' );
add_action( 'wp_ajax_subsales_bg_match_stop', 'subsales_bg_match_stop_ajax' );
add_action( 'wp_ajax_subsales_bg_match_resume', 'subsales_bg_match_resume_ajax' );
add_action( 'wp_ajax_subsales_bg_match_status', 'subsales_bg_match_status_ajax' );
add_action( 'wp_ajax_subsales_bg_match_reset', 'subsales_bg_match_reset_ajax' );

// Import/Export handlers for users and teams
add_action( 'admin_post_subsales_export_users_teams', 'subsales_export_users_teams' );
add_action( 'admin_post_subsales_import_users_teams', 'subsales_import_users_teams' );

// AJAX handler for batch address matching with auto-resume
function subsales_match_addresses_batch_ajax() {
    check_ajax_referer( 'subsales_match_addresses', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    // Call the Overpass matcher
    if ( ! class_exists( 'Subsales_Overpass_Matcher' ) ) {
        wp_send_json_error( 'Overpass Matcher class not loaded' );
    }
    
    $result = Subsales_Overpass_Matcher::match_addresses();
    
    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}

// Background matching AJAX handlers
function subsales_bg_match_start_ajax() {
    check_ajax_referer( 'subsales_bg_match', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $result = Subsales_Background_Matcher::start_job();
    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}

function subsales_bg_match_stop_ajax() {
    check_ajax_referer( 'subsales_bg_match', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $result = Subsales_Background_Matcher::stop_job();
    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}

function subsales_bg_match_resume_ajax() {
    check_ajax_referer( 'subsales_bg_match', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $result = Subsales_Background_Matcher::resume_job();
    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}

function subsales_bg_match_status_ajax() {
    check_ajax_referer( 'subsales_bg_match', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $status = Subsales_Background_Matcher::get_complete_status();
    wp_send_json_success( $status );
}

function subsales_bg_match_reset_ajax() {
    check_ajax_referer( 'subsales_bg_match', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $result = Subsales_Background_Matcher::reset_job();
    if ( $result['success'] ) {
        wp_send_json_success( $result );
    } else {
        wp_send_json_error( $result );
    }
}

// AJAX handler for getting active sessions count
function subsales_get_active_sessions_count_ajax() {
    check_ajax_referer( 'subsales_active_sessions', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $active_count = count( Subsales_Database::get_active_pwa_sessions( 50 ) );
    wp_send_json_success( array( 'count' => $active_count ) );
}

// AJAX handler for debug mode toggle
function subsales_toggle_debug_ajax() {
    check_ajax_referer( 'subsales_debug_toggle', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
    
    if ( $debug_enabled ) {
        // Disable debug mode
        update_option( 'subsales_debug_logging_enabled', false );
        delete_option( 'subsales_debug_logging_started' );
        subsales_log( 'INFO', 'system', 'Debug logging manually disabled' );
        wp_send_json_success( array( 'status' => 'disabled' ) );
    } else {
        // Enable debug mode
        update_option( 'subsales_debug_logging_enabled', true );
        update_option( 'subsales_debug_logging_started', time() );
        subsales_log( 'INFO', 'system', 'Debug logging enabled (24-hour timeout)' );
        wp_send_json_success( array( 'status' => 'enabled' ) );
    }
}

function subsales_download_openaddresses() {
    check_ajax_referer( 'subsales_zip_generate', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $state = isset( $_POST['state'] ) ? strtolower( sanitize_text_field( $_POST['state'] ) ) : 'ct';
    if ( ! preg_match( '/^[a-z]{2}$/', $state ) ) {
        wp_send_json_error( 'Invalid state code. Use 2-letter code like ct, ny, ca, etc.' );
    }

    update_option( 'subsales_openaddresses_state', $state );

    // OpenAddresses.io public S3 bucket - no authentication required
    // Try multiple possible URL patterns
    $urls = array(
        'https://s3.amazonaws.com/data.openaddresses.io/openaddr-collected-us_' . $state . '.zip',
        'https://s3.amazonaws.com/data.openaddresses.io/us/' . $state . '/statewide.csv',
        'https://openaddresses.io/download/us/' . $state . '/statewide',
    );
    
    $upload = wp_upload_dir();
    $base_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    if ( ! is_dir( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }
    
    $dest_path = trailingslashit( $base_dir ) . 'openaddresses-full.csv';
    $temp_zip = trailingslashit( $base_dir ) . 'temp-openaddresses.zip';

    // Try each URL
    $downloaded = false;
    $tried_urls = array();
    
    foreach ( $urls as $url ) {
        $tried_urls[] = $url;
        
        // Determine if it's a ZIP file
        $is_zip = strpos( $url, '.zip' ) !== false;
        $target_file = $is_zip ? $temp_zip : $dest_path;
        
        $response = wp_remote_get( $url, array(
            'timeout' => 300,
            'stream' => true,
            'filename' => $target_file
        ) );

        if ( is_wp_error( $response ) ) {
            continue;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 && file_exists( $target_file ) ) {
            // If ZIP, extract it
            if ( $is_zip ) {
                WP_Filesystem();
                global $wp_filesystem;
                
                $unzip_result = unzip_file( $temp_zip, $base_dir );
                
                if ( is_wp_error( $unzip_result ) ) {
                    @unlink( $temp_zip );
                    continue;
                }
                
                // Find the CSV file in extracted contents
                $files = glob( $base_dir . '/*.csv' );
                if ( empty( $files ) ) {
                    @unlink( $temp_zip );
                    continue;
                }
                
                // Rename first CSV to our standard name
                rename( $files[0], $dest_path );
                
                // Clean up
                @unlink( $temp_zip );
                foreach ( $files as $f ) {
                    if ( $f !== $dest_path ) @unlink( $f );
                }
            }
            
            $downloaded = true;
            break;
        }
    }

    if ( ! $downloaded ) {
        wp_send_json_error( 'Could not download from OpenAddresses.io. Tried URLs: ' . implode( ', ', $tried_urls ) . '. Please use manual upload instead.' );
    }

    if ( ! file_exists( $dest_path ) ) {
        wp_send_json_error( 'Download completed but file not found at destination' );
    }

    $size = filesize( $dest_path );
    wp_send_json_success( array(
        'message' => 'Downloaded ' . strtoupper( $state ) . ' data successfully',
        'file_size' => size_format( $size ),
        'state' => strtoupper( $state ),
        'path' => $dest_path
    ) );
}

function subsales_extract_openaddresses_zips() {
    check_ajax_referer( 'subsales_zip_generate', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $served_zips = get_option( 'subsales_served_zips', array() );
    if ( empty( $served_zips ) ) {
        wp_send_json_error( 'No ZIP codes configured. Save your ZIP list first.' );
    }

    $upload = wp_upload_dir();
    $full_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses-full.csv';
    $filtered_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses.csv';

    if ( ! file_exists( $full_path ) ) {
        wp_send_json_error( 'Full state file not found at: ' . $full_path );
    }

    $handle_in = fopen( $full_path, 'r' );
    if ( $handle_in === false ) {
        wp_send_json_error( 'Could not open full Connecticut file' );
    }

    $handle_out = fopen( $filtered_path, 'w' );
    if ( $handle_out === false ) {
        fclose( $handle_in );
        wp_send_json_error( 'Could not create filtered file' );
    }

    // Read and write header
    $header = fgetcsv( $handle_in );
    if ( ! $header ) {
        fclose( $handle_in );
        fclose( $handle_out );
        wp_send_json_error( 'Invalid CSV format - no header row' );
    }
    fputcsv( $handle_out, $header );

    // Find postcode column
    $postcode_idx = array_search( 'POSTCODE', $header );
    if ( $postcode_idx === false ) {
        fclose( $handle_in );
        fclose( $handle_out );
        wp_send_json_error( 'POSTCODE column not found in CSV' );
    }

    // Filter rows by ZIP codes
    $count = 0;
    $total_rows = 0;
    while ( ( $row = fgetcsv( $handle_in ) ) !== false ) {
        $total_rows++;
        if ( ! isset( $row[ $postcode_idx ] ) ) continue;
        
        $postcode = trim( $row[ $postcode_idx ] );
        if ( in_array( $postcode, $served_zips ) ) {
            fputcsv( $handle_out, $row );
            $count++;
        }
    }

    fclose( $handle_in );
    fclose( $handle_out );

    // Save metadata
    update_option( 'subsales_oa_filter_meta', array(
        'zips' => $served_zips,
        'count' => $count,
        'total_rows' => $total_rows,
        'date' => current_time( 'mysql' )
    ) );

    wp_send_json_success( array(
        'message' => 'Extracted ' . number_format( $count ) . ' addresses for ' . count( $served_zips ) . ' ZIP code(s)',
        'count' => $count,
        'zips' => $served_zips,
        'file_size' => size_format( filesize( $filtered_path ) )
    ) );
}
function subsales_search_address_preview() {
    check_ajax_referer( 'subsales_address_search', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $address = isset( $_POST['address'] ) ? sanitize_text_field( $_POST['address'] ) : '';
    
    if ( empty( $address ) ) {
        wp_send_json_error( 'Address required' );
    }

    // Get served ZIP codes
    $served_zips = get_option( 'subsales_served_zips', array() );
    if ( empty( $served_zips ) ) {
        wp_send_json_error( 'No ZIP codes configured. Please configure served ZIP codes first.' );
    }

    $upload = wp_upload_dir();
    $base_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    $all_results = array( 'local' => array( 'count' => 0, 'results' => array() ) );

    // Search across all served ZIP codes using local JSON files
    foreach ( $served_zips as $zip ) {
        $json_path = $base_dir . '/' . $zip . '.json';
        
        if ( ! file_exists( $json_path ) ) {
            continue;
        }
        
        $json_content = file_get_contents( $json_path );
        $addresses = json_decode( $json_content, true );
        
        if ( ! is_array( $addresses ) ) {
            continue;
        }
        
        // Search for matching addresses
        $search_lower = strtolower( trim( $address ) );
        foreach ( $addresses as $addr ) {
            if ( ! isset( $addr['label'] ) ) continue;
            
            $label_lower = strtolower( $addr['label'] );
            
            // Check if search term is in the address label
            if ( strpos( $label_lower, $search_lower ) !== false ) {
                $all_results['local']['results'][] = $addr;
                $all_results['local']['count']++;
            }
        }
    }

    $total = $all_results['local']['count'];
    wp_send_json_success( array( 'sources' => $all_results, 'total' => $total, 'zips_searched' => $served_zips ) );
}

// Helper: Search OpenAddresses CSV for specific address
function subsales_search_openaddresses( $address, $zip ) {
    $upload = wp_upload_dir();
    $csv_path = get_option( 'subsales_openaddresses_path', trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/connecticut.csv' );
    
    if ( ! file_exists( $csv_path ) ) return array();
    
    // Extract house number from address
    $number = '';
    $street_search = strtolower( trim( $address ) );
    if ( preg_match( '/^(\d+)\s+(.+)$/i', $address, $matches ) ) {
        $number = $matches[1];
        $street_search = strtolower( trim( $matches[2] ) );
    }
    
    $results = array();
    $handle = fopen( $csv_path, 'r' );
    if ( $handle === false ) return array();
    
    $header = fgetcsv( $handle );
    if ( ! $header ) {
        fclose( $handle );
        return array();
    }
    
    $col_map = array();
    foreach ( array( 'LON', 'LAT', 'NUMBER', 'STREET', 'UNIT', 'CITY', 'REGION', 'POSTCODE' ) as $col ) {
        $idx = array_search( $col, $header );
        if ( $idx !== false ) $col_map[ $col ] = $idx;
    }
    
    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( ! isset( $col_map['POSTCODE'] ) || ! isset( $row[ $col_map['POSTCODE'] ] ) ) continue;
        if ( trim( $row[ $col_map['POSTCODE'] ] ) !== $zip ) continue;
        
        $row_number = isset( $col_map['NUMBER'] ) && isset( $row[ $col_map['NUMBER'] ] ) ? trim( $row[ $col_map['NUMBER'] ] ) : '';
        $row_street = isset( $col_map['STREET'] ) && isset( $row[ $col_map['STREET'] ] ) ? strtolower( trim( $row[ $col_map['STREET'] ] ) ) : '';
        
        // Match by number and partial street name
        if ( $number && $row_number !== $number ) continue;
        if ( strpos( $row_street, $street_search ) === false && strpos( $street_search, $row_street ) === false ) continue;
        
        $unit = isset( $col_map['UNIT'] ) && isset( $row[ $col_map['UNIT'] ] ) ? trim( $row[ $col_map['UNIT'] ] ) : '';
        $city = isset( $col_map['CITY'] ) && isset( $row[ $col_map['CITY'] ] ) ? trim( $row[ $col_map['CITY'] ] ) : '';
        
        $label = $row_number . ' ' . $row[ $col_map['STREET'] ];
        if ( $unit ) $label .= ' Unit ' . $unit;
        if ( $city ) $label .= ', ' . $city;
        
        $results[] = array(
            'label' => $label,
            'housenumber' => $row_number,
            'street' => $row[ $col_map['STREET'] ],
            'unit' => $unit,
            'city' => $city,
            'state' => isset( $col_map['REGION'] ) && isset( $row[ $col_map['REGION'] ] ) ? trim( $row[ $col_map['REGION'] ] ) : 'CT',
            'zip' => $zip,
            'lat' => isset( $col_map['LAT'] ) && isset( $row[ $col_map['LAT'] ] ) ? trim( $row[ $col_map['LAT'] ] ) : null,
            'lng' => isset( $col_map['LON'] ) && isset( $row[ $col_map['LON'] ] ) ? trim( $row[ $col_map['LON'] ] ) : null,
            'source' => 'openaddresses'
        );
    }
    
    fclose( $handle );
    return $results;
}

// Helper: Search OSM Overpass for specific address
function subsales_search_osm_address( $address, $zip ) {
    // Extract street name from address
    $parts = explode( ' ', trim( $address ) );
    $number = '';
    $street = $address;
    
    if ( preg_match( '/^(\d+)\s+(.+)$/', $address, $matches ) ) {
        $number = $matches[1];
        $street = $matches[2];
    }
    
    $ql = '[out:json][timeout:10];';
    if ( $number ) {
        $ql .= '(node["addr:housenumber"="' . esc_attr( $number ) . '"]["addr:street"~"' . esc_attr( $street ) . '",i];';
        $ql .= 'way["addr:housenumber"="' . esc_attr( $number ) . '"]["addr:street"~"' . esc_attr( $street ) . '",i];);';
    } else {
        $ql .= '(node["addr:street"~"' . esc_attr( $street ) . '",i]["addr:postcode"="' . esc_attr( $zip ) . '"];';
        $ql .= 'way["addr:street"~"' . esc_attr( $street ) . '",i]["addr:postcode"="' . esc_attr( $zip ) . '"];);';
    }
    $ql .= 'out center;';
    
    $resp = wp_remote_post( 'https://overpass-api.de/api/interpreter', array(
        'body' => $ql,
        'timeout' => 15
    ) );
    
    if ( is_wp_error( $resp ) ) return array();
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    
    $results = array();
    if ( isset( $json['elements'] ) ) {
        foreach ( $json['elements'] as $el ) {
            $tags = isset( $el['tags'] ) ? $el['tags'] : array();
            if ( empty( $tags['addr:street'] ) ) continue;
            
            $results[] = array(
                'label' => ( isset( $tags['addr:housenumber'] ) ? $tags['addr:housenumber'] . ' ' : '' ) . $tags['addr:street'],
                'housenumber' => isset( $tags['addr:housenumber'] ) ? $tags['addr:housenumber'] : '',
                'street' => $tags['addr:street'],
                'city' => isset( $tags['addr:city'] ) ? $tags['addr:city'] : '',
                'state' => isset( $tags['addr:state'] ) ? $tags['addr:state'] : '',
                'zip' => isset( $tags['addr:postcode'] ) ? $tags['addr:postcode'] : $zip,
                'lat' => isset( $el['lat'] ) ? $el['lat'] : ( isset( $el['center']['lat'] ) ? $el['center']['lat'] : null ),
                'lng' => isset( $el['lon'] ) ? $el['lon'] : ( isset( $el['center']['lon'] ) ? $el['center']['lon'] : null ),
                'source' => 'osm'
            );
        }
    }
    return $results;
}

// AJAX handler to delete ZIP extract files
add_action( 'wp_ajax_subsales_delete_zip_extract', 'subsales_delete_zip_extract' );
function subsales_delete_zip_extract() {
    check_ajax_referer( 'subsales_zip_delete', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

    $zip = isset( $_POST['zip'] ) ? sanitize_text_field( $_POST['zip'] ) : '';
    if ( ! preg_match( '/^[0-9]{5}$/', $zip ) ) {
        wp_send_json_error( 'Invalid ZIP code format' );
    }

    $upload = wp_upload_dir();
    $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    $file = $base . '/' . $zip . '.json';

    if ( ! file_exists( $file ) ) {
        wp_send_json_error( 'File does not exist' );
    }

    if ( ! unlink( $file ) ) {
        wp_send_json_error( 'Failed to delete file' );
    }

    // Update zip-index.json to reflect existing extracts
    $saved_zips = get_option( 'subsales_served_zips', array() );
    $saved_zips = array_values( array_diff( $saved_zips, array( $zip ) ) );
    update_option( 'subsales_served_zips', $saved_zips );
    subsales_update_zip_index();

    wp_send_json_success( array( 'message' => 'ZIP extract deleted successfully', 'zip' => $zip ) );
}

// AJAX handler to manually refresh zip-index.json
add_action( 'wp_ajax_subsales_refresh_zip_index', 'subsales_refresh_zip_index_ajax' );
function subsales_refresh_zip_index_ajax() {
    check_ajax_referer( 'subsales_refresh_index', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );
    
    $result = subsales_update_zip_index();
    
    if ( $result ) {
        // Get the updated list
        $upload = wp_upload_dir();
        $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
        $existing_zips = array();
        if ( is_dir( $zipdata_dir ) ) {
            $files = glob( $zipdata_dir . '*.json' );
            if ( is_array( $files ) ) {
                foreach ( $files as $file ) {
                    $basename = basename( $file, '.json' );
                    if ( preg_match( '/^[0-9]{5}$/', $basename ) ) {
                        $existing_zips[] = $basename;
                    }
                }
            }
        }
        sort( $existing_zips );
        wp_send_json_success( array( 'message' => 'Index refreshed successfully', 'zips' => $existing_zips ) );
    } else {
        wp_send_json_error( 'Failed to update index file' );
    }
}

// AJAX handler for sales mode toggle
add_action( 'wp_ajax_subsales_update_sales_mode', 'subsales_update_sales_mode_ajax' );
function subsales_update_sales_mode_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    check_ajax_referer( 'subsales_sales_mode', 'nonce' );
    
    $mode = isset( $_POST['mode'] ) ? sanitize_text_field( $_POST['mode'] ) : '';
    
    if ( ! in_array( $mode, array( 'legacy', 'user' ), true ) ) {
        wp_send_json_error( 'Invalid mode. Must be "legacy" or "user".' );
    }
    
    update_option( 'subsales_sales_mode', $mode );
    
    wp_send_json_success( array( 'mode' => $mode ) );
}

// Helper: Get served ZIP codes as an array (handles both string and array formats)
function subsales_get_served_zips() {
    $zips = get_option( 'subsales_served_zips', array() );
    
    // Handle legacy string format (comma-separated)
    if ( is_string( $zips ) && ! empty( $zips ) ) {
        $zips = array_map( 'trim', explode( ',', $zips ) );
        $zips = array_filter( $zips ); // Remove empty values
    }
    
    // Ensure it's an array
    if ( ! is_array( $zips ) ) {
        $zips = array();
    }
    
    return $zips;
}

// AJAX handler to generate per-ZIP JSON extracts from database (no API calls needed)
add_action( 'wp_ajax_subsales_generate_zip_extracts', 'subsales_generate_zip_extracts' );
function subsales_generate_zip_extracts() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );
    check_ajax_referer( 'subsales_zip_generate', 'nonce' );

    global $wpdb;
    $table_name = $wpdb->prefix . 'ss_addresses';
    
    $zips = subsales_get_served_zips();
    if ( ! is_array( $zips ) || empty( $zips ) ) {
        wp_send_json_error( 'No ZIPs configured. Please add ZIP codes in Settings → Overall → ZIP Codes first.' );
    }

    $upload = wp_upload_dir(); 
    $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    if ( ! is_dir( $base ) ) wp_mkdir_p( $base );

    // Start logging
    $start_time = microtime( true );
    $user = wp_get_current_user();
    
    // Log ZIP generation start
    subsales_log( 'INFO', 'zip', 'ZIP extract generation started from database', array(
        'zip_count' => count( $zips ),
        'zips' => $zips
    ), 'admin', $user->ID, $user->display_name );
    
    $log_entry = array(
        'timestamp' => current_time( 'mysql' ),
        'user' => $user->display_name . ' (' . $user->user_login . ')',
        'zips_requested' => $zips,
        'results' => array(),
        'summary' => array()
    );

    $results = array();
    $total_addresses = 0;
    $success_count = 0;
    $error_count = 0;

    foreach ( $zips as $zip ) {
        $zip_start = microtime( true );
        
        // Query database for addresses in this ZIP that have coordinates
        $addresses = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE zip = %s AND lat IS NOT NULL AND lng IS NOT NULL ORDER BY street, house_number",
            $zip
        ), ARRAY_A );
        
        if ( empty( $addresses ) ) {
            $res = array( 
                'error' => 'no_addresses', 
                'message' => 'No addresses with coordinates found for ZIP ' . $zip . '. Upload/geocode addresses first.',
                'count' => 0
            );
            $results[ $zip ] = $res;
            $error_count++;
            continue;
        }
        
        // Convert database records to PWA-compatible format
        $formatted = array();
        foreach ( $addresses as $addr ) {
            // Use full_address if available, otherwise construct from parts
            $label = ! empty( $addr['full_address'] ) ? $addr['full_address'] : '';
            if ( empty( $label ) ) {
                $parts = array_filter( array(
                    $addr['house_number'],
                    $addr['street'],
                    ! empty( $addr['unit'] ) ? 'Unit ' . $addr['unit'] : '',
                    $addr['city'],
                    $addr['state'],
                    $addr['zip']
                ) );
                $label = implode( ', ', $parts );
            }
            
            $formatted[] = array(
                'label' => $label,
                'street' => $addr['street'] ?? '',
                'house_number' => $addr['house_number'] ?? '',
                'unit' => $addr['unit'] ?? '',
                'city' => $addr['city'] ?? '',
                'state' => $addr['state'] ?? '',
                'postcode' => $addr['zip'] ?? '',
                'lat' => floatval( $addr['lat'] ),
                'lng' => floatval( $addr['lng'] ),
                'source' => $addr['source'] ?? 'unknown',
                'type' => $addr['type'] ?? 'residential'
            );
        }
        
        // Write to file
        $file = trailingslashit( $base ) . $zip . '.json';
        $written = file_put_contents( $file, wp_json_encode( $formatted ) );
        
        $zip_duration = round( microtime( true ) - $zip_start, 2 );
        
        if ( $written === false ) {
            $res = array( 'error' => 'write_failed', 'message' => 'Failed to write JSON file' );
            $error_count++;
        } else {
            $res = array(
                'count' => count( $formatted ),
                'file' => $file,
                'message' => count( $formatted ) . ' addresses exported from database',
                'duration' => $zip_duration,
                'source' => 'database',
                'zip' => $zip
            );
            $total_addresses += count( $formatted );
            $success_count++;
        }
        
        $results[ $zip ] = $res;
    }

    // Update zip-index.json in the PWA directory with existing extract files
    subsales_update_zip_index();

    // Complete log entry
    $total_duration = round( microtime( true ) - $start_time, 2 );
    $log_entry['results'] = $results;
    $log_entry['summary'] = array(
        'total_zips' => count( $zips ),
        'successful' => $success_count,
        'failed' => $error_count,
        'total_addresses' => $total_addresses,
        'duration_seconds' => $total_duration,
        'source' => 'wp_ss_addresses database'
    );

    // Save log to database
    subsales_save_generation_log( $log_entry );
    
    // Log ZIP generation completion
    subsales_log( 'INFO', 'zip', 'ZIP extract generation completed from database', array(
        'total_zips' => count( $zips ),
        'successful' => $success_count,
        'failed' => $error_count,
        'total_addresses' => $total_addresses,
        'duration_seconds' => $total_duration
    ), 'admin', $user->ID, $user->display_name );

    wp_send_json_success( $results );
}

// AJAX handler to upload and process address files
add_action( 'wp_ajax_subsales_upload_address_file', 'subsales_upload_address_file_ajax' );
function subsales_upload_address_file_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_upload_address', 'nonce' );
    
    if ( ! isset( $_FILES['address_file'] ) || $_FILES['address_file']['error'] !== UPLOAD_ERR_OK ) {
        wp_send_json_error( 'File upload failed. Please try again.' );
    }
    
    $file = $_FILES['address_file'];
    $file_ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    
    // Initialize upload job status
    update_option( 'subsales_upload_status', array(
        'status' => 'uploading',
        'percent' => 30,
        'message' => 'File uploaded, detecting type...',
        'complete' => false,
        'success' => false
    ), false );
    
    // Detect file type
    if ( $file_ext === 'zip' ) {
        // Check if it's a shapefile (contains .shp, .dbf, .prj)
        $zip = new ZipArchive();
        if ( $zip->open( $file['tmp_name'] ) === true ) {
            $has_shp = false;
            $has_dbf = false;
            $has_prj = false;
            
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $filename = $zip->getNameIndex( $i );
                $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
                if ( $ext === 'shp' ) $has_shp = true;
                if ( $ext === 'dbf' ) $has_dbf = true;
                if ( $ext === 'prj' ) $has_prj = true;
            }
            
            $zip->close();
            
            if ( $has_shp && $has_dbf && $has_prj ) {
                // Process shapefile in background
                subsales_process_shapefile_upload( $file['tmp_name'] );
                wp_send_json_success( array( 'message' => 'Shapefile detected, processing started' ) );
            } else {
                update_option( 'subsales_upload_status', array(
                    'status' => 'error',
                    'percent' => 0,
                    'message' => 'Invalid shapefile. Must contain .shp, .dbf, and .prj files.',
                    'complete' => true,
                    'success' => false
                ), false );
                wp_send_json_error( 'Invalid shapefile. Must contain .shp, .dbf, and .prj files.' );
            }
        } else {
            update_option( 'subsales_upload_status', array(
                'status' => 'error',
                'percent' => 0,
                'message' => 'Could not open ZIP file.',
                'complete' => true,
                'success' => false
            ), false );
            wp_send_json_error( 'Could not open ZIP file.' );
        }
    } elseif ( $file_ext === 'csv' ) {
        update_option( 'subsales_upload_status', array(
            'status' => 'error',
            'percent' => 0,
            'message' => 'CSV parsing not yet implemented. Coming in Phase 8!',
            'complete' => true,
            'success' => false
        ), false );
        wp_send_json_error( 'CSV parsing not yet implemented. Coming in Phase 8!' );
    } else {
        update_option( 'subsales_upload_status', array(
            'status' => 'error',
            'percent' => 0,
            'message' => 'Invalid file type. Please upload a .zip (shapefile) or .csv file.',
            'complete' => true,
            'success' => false
        ), false );
        wp_send_json_error( 'Invalid file type. Please upload a .zip (shapefile) or .csv file.' );
    }
}

// Process shapefile upload with progress tracking
function subsales_process_shapefile_upload( $file_path ) {
    global $wpdb;
    $addresses_table = $wpdb->prefix . 'ss_addresses';
    
    // Update status: parsing
    update_option( 'subsales_upload_status', array(
        'status' => 'parsing',
        'percent' => 40,
        'status_text' => 'Parsing shapefile...',
        'message' => 'Parsing shapefile data...',
        'complete' => false,
        'success' => false
    ), false );
    
    // Parse shapefile
    $result = Subsales_Shapefile_Parser::parse_shapefile( $file_path );
    
    if ( is_wp_error( $result ) ) {
        update_option( 'subsales_upload_status', array(
            'status' => 'error',
            'percent' => 0,
            'status_text' => 'Parsing failed',
            'message' => 'Shapefile parsing failed: ' . $result->get_error_message(),
            'complete' => true,
            'success' => false
        ), false );
        return;
    }
    
    $addresses_parsed = $result;
    $count = count( $addresses_parsed );
    
    // Update status: assigning ZIP codes via border-based lookup
    update_option( 'subsales_upload_status', array(
        'status' => 'assigning_zips',
        'percent' => 50,
        'status_text' => 'Assigning ZIP codes...',
        'message' => "Parsed {$count} addresses, assigning ZIP codes via spatial query (fast method)...",
        'complete' => false,
        'success' => false
    ), false );
    
    // Assign ZIP codes using border-based spatial lookup (100x faster than individual API calls)
    $addresses_with_zips = subsales_assign_zips_by_borders( $addresses_parsed );
    
    // Update status: inserting
    update_option( 'subsales_upload_status', array(
        'status' => 'inserting',
        'percent' => 60,
        'status_text' => 'Inserting addresses...',
        'message' => "ZIP codes assigned, inserting {$count} addresses into database...",
        'complete' => false,
        'success' => false
    ), false );
    
    // Store addresses in database
    $inserted = 0;
    $skipped = 0;
    
    // Suppress duplicate key errors
    $wpdb->suppress_errors( true );
    
    foreach ( $addresses_with_zips as $index => $addr ) {
        // Update progress every 100 addresses
        if ( $index % 100 === 0 && $index > 0 ) {
            $progress = 60 + ( ( $index / $count ) * 30 ); // 60-90%
            update_option( 'subsales_upload_status', array(
                'status' => 'inserting',
                'percent' => round( $progress ),
                'status_text' => 'Inserting addresses...',
                'message' => "Inserted {$index} of {$count} addresses (skipped {$skipped} duplicates)...",
                'complete' => false,
                'success' => false
            ), false );
        }
        
        // Check for duplicates BEFORE insert (more efficient)
        // UNIQUE constraint is on (street, house_number, unit, zip)
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$addresses_table} WHERE street = %s AND house_number = %s AND unit = %s AND zip = %s LIMIT 1",
            $addr['street'],
            $addr['house_number'],
            $addr['unit'] ?: '',
            $addr['zip']
        ) );
        
        if ( $existing ) {
            // Duplicate found - skip insert
            $skipped++;
            continue;
        }
        
        // Insert into database
        $insert_result = $wpdb->insert(
            $addresses_table,
            array(
                'street' => $addr['street'],
                'house_number' => $addr['house_number'],
                'unit' => $addr['unit'],
                'city' => $addr['city'],
                'state' => $addr['state'],
                'zip' => $addr['zip'], // Border-based ZIP assignment
                'lat' => $addr['lat'],
                'lng' => $addr['lng'],
                'source' => $addr['source'],
                'confidence' => $addr['confidence'],
                'matched' => $addr['matched'],
                'type' => $addr['type'],
                'full_address' => $addr['full_address']
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%s', '%s' )
        );
        
        if ( $insert_result ) {
            $inserted++;
        } else {
            // Should rarely happen now that we check duplicates first
            error_log( 'Address insert failed: ' . $wpdb->last_error );
        }
    }
    
    // Re-enable error reporting
    $wpdb->suppress_errors( false );
    
    // Complete
    update_option( 'subsales_upload_status', array(
        'status' => 'complete',
        'percent' => 100,
        'status_text' => 'Processing complete',
        'message' => "Successfully processed! Parsed {$count} addresses, inserted {$inserted} new records, skipped {$skipped} duplicates.",
        'complete' => true,
        'success' => true,
        'parsed' => $count,
        'inserted' => $inserted,
        'skipped' => $skipped
    ), false );
}

// Border-based ZIP assignment (100x faster than individual API calls)
// Uses spatial point-in-polygon logic based on coordinate boundaries
function subsales_assign_zips_by_borders( $addresses ) {
    // Get configured ZIP codes and their boundaries
    $served_zips = subsales_get_served_zips();
    
    if ( empty( $served_zips ) ) {
        // No ZIPs configured - assign default Southington ZIP
        foreach ( $addresses as &$addr ) {
            $addr['zip'] = '06479'; // Default Southington
            $addr['confidence'] = 'low';
        }
        return $addresses;
    }
    
    // Get ZIP boundaries from database (if we have them) or use reverse geocoding for first pass
    $zip_boundaries = subsales_get_zip_boundaries( $served_zips );
    
    if ( empty( $zip_boundaries ) ) {
        // First time - build boundaries from reverse geocoding a sample
        $zip_boundaries = subsales_build_zip_boundaries_from_sample( $addresses, $served_zips );
    }
    
    // Assign ZIPs using spatial matching
    foreach ( $addresses as &$addr ) {
        $lat = floatval( $addr['lat'] );
        $lng = floatval( $addr['lng'] );
        
        // Check which ZIP boundary contains this point
        $matched_zip = null;
        foreach ( $zip_boundaries as $zip => $bounds ) {
            if ( subsales_point_in_bounds( $lat, $lng, $bounds ) ) {
                $matched_zip = $zip;
                break;
            }
        }
        
        if ( $matched_zip ) {
            $addr['zip'] = $matched_zip;
            $addr['confidence'] = 'high';
        } else {
            // Fallback: find nearest ZIP boundary
            $addr['zip'] = subsales_find_nearest_zip( $lat, $lng, $zip_boundaries );
            $addr['confidence'] = 'medium';
        }
    }
    
    return $addresses;
}

// Get or build ZIP boundaries (bounding boxes for fast spatial queries)
function subsales_get_zip_boundaries( $zips ) {
    $boundaries = get_option( 'subsales_zip_boundaries', array() );
    
    // Filter to only requested ZIPs
    $result = array();
    foreach ( $zips as $zip ) {
        if ( isset( $boundaries[ $zip ] ) ) {
            $result[ $zip ] = $boundaries[ $zip ];
        }
    }
    
    return $result;
}

// Build ZIP boundaries from a sample of addresses (one-time operation)
function subsales_build_zip_boundaries_from_sample( $addresses, $served_zips ) {
    $boundaries = array();
    $sample_size = min( 50, count( $addresses ) ); // Sample 50 addresses
    $samples = array_slice( $addresses, 0, $sample_size );
    
    // Reverse geocode sample to get initial ZIP assignments
    $zip_points = array();
    foreach ( $samples as $addr ) {
        $lat = floatval( $addr['lat'] );
        $lng = floatval( $addr['lng'] );
        
        if ( function_exists( 'order_sync_reverse_geocode' ) ) {
            $zip = order_sync_reverse_geocode( $lat, $lng );
            if ( $zip && in_array( $zip, $served_zips ) ) {
                if ( ! isset( $zip_points[ $zip ] ) ) {
                    $zip_points[ $zip ] = array();
                }
                $zip_points[ $zip ][] = array( 'lat' => $lat, 'lng' => $lng );
            }
        }
    }
    
    // Calculate bounding boxes for each ZIP
    foreach ( $zip_points as $zip => $points ) {
        if ( count( $points ) < 2 ) continue;
        
        $lats = array_column( $points, 'lat' );
        $lngs = array_column( $points, 'lng' );
        
        $boundaries[ $zip ] = array(
            'min_lat' => min( $lats ) - 0.01, // Add small buffer
            'max_lat' => max( $lats ) + 0.01,
            'min_lng' => min( $lngs ) - 0.01,
            'max_lng' => max( $lngs ) + 0.01,
            'center_lat' => array_sum( $lats ) / count( $lats ),
            'center_lng' => array_sum( $lngs ) / count( $lngs ),
        );
    }
    
    // Save for future use
    update_option( 'subsales_zip_boundaries', $boundaries, false );
    
    return $boundaries;
}

// Check if a point is within bounding box
function subsales_point_in_bounds( $lat, $lng, $bounds ) {
    return $lat >= $bounds['min_lat'] && $lat <= $bounds['max_lat'] &&
           $lng >= $bounds['min_lng'] && $lng <= $bounds['max_lng'];
}

// Find nearest ZIP boundary center (fallback for edge cases)
function subsales_find_nearest_zip( $lat, $lng, $boundaries ) {
    $nearest_zip = null;
    $min_distance = PHP_FLOAT_MAX;
    
    foreach ( $boundaries as $zip => $bounds ) {
        // Calculate distance to center of ZIP boundary
        $distance = sqrt(
            pow( $lat - $bounds['center_lat'], 2 ) +
            pow( $lng - $bounds['center_lng'], 2 )
        );
        
        if ( $distance < $min_distance ) {
            $min_distance = $distance;
            $nearest_zip = $zip;
        }
    }
    
    return $nearest_zip ?: '06479'; // Default to Southington if nothing found
}

// AJAX handler to get upload status
add_action( 'wp_ajax_subsales_upload_status', 'subsales_upload_status_ajax' );
function subsales_upload_status_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_upload_address', 'nonce' );
    
    $status = get_option( 'subsales_upload_status', array(
        'status' => 'idle',
        'percent' => 0,
        'status_text' => 'Idle',
        'message' => 'No upload in progress',
        'complete' => true,
        'success' => false
    ) );
    
    wp_send_json_success( $status );
}

// AJAX handler to re-run ZIP assignment for all addresses
add_action( 'wp_ajax_subsales_reassign_zips', 'subsales_reassign_zips_ajax' );
function subsales_reassign_zips_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_reassign_zips', 'nonce' );
    
    global $wpdb;
    $addresses_table = $wpdb->prefix . 'ss_addresses';
    
    // Get all addresses with coordinates
    $addresses = $wpdb->get_results(
        "SELECT * FROM {$addresses_table} WHERE lat IS NOT NULL AND lng IS NOT NULL",
        ARRAY_A
    );
    
    if ( empty( $addresses ) ) {
        wp_send_json_error( 'No addresses with coordinates found.' );
    }
    
    // Re-assign ZIP codes using border-based lookup
    $addresses_with_zips = subsales_assign_zips_by_borders( $addresses );
    
    // Update addresses in database
    $updated = 0;
    $failed = 0;
    
    foreach ( $addresses_with_zips as $addr ) {
        $result = $wpdb->update(
            $addresses_table,
            array(
                'zip' => $addr['zip'],
                'confidence' => $addr['confidence']
            ),
            array( 'id' => $addr['id'] ),
            array( '%s', '%s' ),
            array( '%d' )
        );
        
        if ( $result !== false ) {
            $updated++;
        } else {
            $failed++;
        }
    }
    
    wp_send_json_success( array(
        'message' => "ZIP codes reassigned successfully!",
        'total' => count( $addresses ),
        'updated' => $updated,
        'failed' => $failed
    ) );
}

// Helper: generate a single ZIP file by querying Overpass API for nodes/ways with addr:postcode and addr:housenumber
function subsales_generate_zip_from_overpass( $zip, $base_dir ) {
    $zip = preg_replace( '/[^0-9]/', '', (string) $zip );
    if ( strlen( $zip ) !== 5 ) return array( 'error' => 'invalid_zip' );

    // Two-phase query: First get addresses explicitly tagged with this postcode,
    // then get the bounding box of the postal code area and grab all addresses within it
    // This catches addresses that don't have addr:postcode explicitly tagged
    
    $ql = '[out:json][timeout:25];';
    // Phase 1: Get all elements tagged with this postcode
    $ql .= '(node["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];';
    $ql .= 'way["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];';
    $ql .= 'relation["addr:postcode"="' . esc_attr( $zip ) . '"]["addr:housenumber"];';
    // Phase 2: Get boundary/postal_code area to find addresses within it
    $ql .= 'area["postal_code"="' . esc_attr( $zip ) . '"]->.searchArea;';
    $ql .= '(node["addr:housenumber"]["addr:street"](area.searchArea);';
    $ql .= 'way["addr:housenumber"]["addr:street"](area.searchArea););';
    $ql .= ');out center;';

    $overpass_url = 'https://overpass-api.de/api/interpreter';
    $args = array(
        'body' => $ql,
        'timeout' => 60,
        'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8' ),
    );
    $resp = wp_remote_post( $overpass_url, $args );
    if ( is_wp_error( $resp ) ) return array( 'error' => 'overpass_error', 'message' => $resp->get_error_message() );
    $code = wp_remote_retrieve_response_code( $resp );
    
    // Return detailed error for non-200 responses
    if ( $code !== 200 ) {
        $body = wp_remote_retrieve_body( $resp );
        $error_msg = '';
        
        if ( $code === 429 ) {
            $error_msg = 'Rate limited - please wait a few minutes before trying again';
        } elseif ( $code === 504 || $code === 503 ) {
            $error_msg = 'Server timeout/unavailable - try again later';
        } else {
            $error_msg = 'HTTP ' . $code . ( $body ? ' - ' . substr( $body, 0, 100 ) : '' );
        }
        
        return array( 'error' => 'overpass_status', 'status' => $code, 'message' => $error_msg );
    }

    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    if ( ! $json || ! isset( $json['elements'] ) ) return array( 'error' => 'no_elements', 'message' => 'No address data returned from API' );

    $out = array(); $seen = array();
    $total_elements = count( $json['elements'] );
    $skipped = 0;
    
    foreach ( $json['elements'] as $el ) {
        $tags = isset( $el['tags'] ) ? $el['tags'] : array();
        if ( empty( $tags['addr:housenumber'] ) || empty( $tags['addr:street'] ) ) {
            $skipped++;
            continue;
        }
        
        // Build address with unit/apartment number if present
        $address_parts = array( $tags['addr:housenumber'] );
        
        // Add unit/apartment/floor/door if available
        if ( ! empty( $tags['addr:unit'] ) ) {
            $address_parts[] = 'Unit ' . $tags['addr:unit'];
        } elseif ( ! empty( $tags['addr:flr'] ) ) {
            $address_parts[] = 'Floor ' . $tags['addr:flr'];
        }
        
        if ( ! empty( $tags['addr:door'] ) ) {
            $address_parts[] = 'Door ' . $tags['addr:door'];
        }
        
        $address_parts[] = $tags['addr:street'];
        
        $label_parts = array();
        $label_parts[] = implode( ' ', $address_parts );
        
        // Add housename/building name if present
        if ( ! empty( $tags['addr:housename'] ) ) {
            $label_parts[] = $tags['addr:housename'];
        }
        
        if ( ! empty( $tags['addr:city'] ) ) $label_parts[] = $tags['addr:city'];
        if ( ! empty( $tags['addr:state'] ) ) $label_parts[] = $tags['addr:state'];
        $label_parts[] = $zip;
        $label = implode( ', ', $label_parts );

        $lat = isset( $el['lat'] ) ? $el['lat'] : ( isset( $el['center']['lat'] ) ? $el['center']['lat'] : null );
        $lon = isset( $el['lon'] ) ? $el['lon'] : ( isset( $el['center']['lon'] ) ? $el['center']['lon'] : null );

        $k = md5( $label ); if ( isset( $seen[ $k ] ) ) continue; $seen[ $k ] = true;

        $out[] = array( 
            'id' => 'osm-' . $el['type'] . '-' . $el['id'], 
            'label' => $label, 
            'street' => $tags['addr:street'], 
            'housenumber' => $tags['addr:housenumber'],
            'unit' => ( ! empty( $tags['addr:unit'] ) ? $tags['addr:unit'] : '' ),
            'floor' => ( ! empty( $tags['addr:flr'] ) ? $tags['addr:flr'] : '' ),
            'door' => ( ! empty( $tags['addr:door'] ) ? $tags['addr:door'] : '' ),
            'housename' => ( ! empty( $tags['addr:housename'] ) ? $tags['addr:housename'] : '' ),
            'city' => (isset($tags['addr:city'])?$tags['addr:city']:''), 
            'state' => (isset($tags['addr:state'])?$tags['addr:state']:''), 
            'zip' => $zip, 
            'lat' => $lat, 
            'lng' => $lon 
        );
    }

    return array( 
        'addresses' => $out,
        'total_elements' => $total_elements,
        'skipped' => $skipped
    );
}

// Helper: Fetch addresses from Nominatim geocoding service
function subsales_fetch_openaddresses_data( $zip ) {
    // OpenAddresses.io provides bulk CSV files with comprehensive address coverage
    // The admin can download the full state file and extract only needed ZIP codes
    // to create a smaller, faster-to-read filtered file
    
    $upload = wp_upload_dir();
    // First try filtered file (created by "Extract ZIP Codes" button)
    $csv_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses.csv';
    
    // Fall back to full file if filtered doesn't exist
    if ( ! file_exists( $csv_path ) ) {
        $csv_path = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/openaddresses-full.csv';
    }
    
    if ( ! file_exists( $csv_path ) ) {
        return array();
    }
    
    $out = array();
    $handle = fopen( $csv_path, 'r' );
    
    if ( $handle === false ) return array();
    
    // Read header row
    $header = fgetcsv( $handle );
    if ( ! $header ) {
        fclose( $handle );
        return array();
    }
    
    // Find column indices
    $col_map = array();
    foreach ( array( 'LON', 'LAT', 'NUMBER', 'STREET', 'UNIT', 'CITY', 'DISTRICT', 'REGION', 'POSTCODE' ) as $col ) {
        $idx = array_search( $col, $header );
        if ( $idx !== false ) $col_map[ $col ] = $idx;
    }
    
    // Read rows and filter by ZIP
    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        if ( ! isset( $col_map['POSTCODE'] ) || ! isset( $row[ $col_map['POSTCODE'] ] ) ) continue;
        
        $postcode = trim( $row[ $col_map['POSTCODE'] ] );
        if ( $postcode !== $zip ) continue;
        
        $number = isset( $col_map['NUMBER'] ) && isset( $row[ $col_map['NUMBER'] ] ) ? trim( $row[ $col_map['NUMBER'] ] ) : '';
        $street = isset( $col_map['STREET'] ) && isset( $row[ $col_map['STREET'] ] ) ? trim( $row[ $col_map['STREET'] ] ) : '';
        
        if ( empty( $number ) || empty( $street ) ) continue;
        
        $unit = isset( $col_map['UNIT'] ) && isset( $row[ $col_map['UNIT'] ] ) ? trim( $row[ $col_map['UNIT'] ] ) : '';
        $city = isset( $col_map['CITY'] ) && isset( $row[ $col_map['CITY'] ] ) ? trim( $row[ $col_map['CITY'] ] ) : '';
        $state = isset( $col_map['REGION'] ) && isset( $row[ $col_map['REGION'] ] ) ? trim( $row[ $col_map['REGION'] ] ) : 'CT';
        $lat = isset( $col_map['LAT'] ) && isset( $row[ $col_map['LAT'] ] ) ? trim( $row[ $col_map['LAT'] ] ) : null;
        $lng = isset( $col_map['LON'] ) && isset( $row[ $col_map['LON'] ] ) ? trim( $row[ $col_map['LON'] ] ) : null;
        
        $label_parts = array( $number . ' ' . $street );
        if ( $unit ) $label_parts[0] .= ' Unit ' . $unit;
        if ( $city ) $label_parts[] = $city;
        if ( $state ) $label_parts[] = $state;
        $label_parts[] = $zip;
        
        $out[] = array(
            'id' => 'oa-' . md5( $number . $street . $unit . $zip ),
            'label' => implode( ', ', $label_parts ),
            'street' => $street,
            'housenumber' => $number,
            'unit' => $unit,
            'floor' => '',
            'door' => '',
            'housename' => '',
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'lat' => $lat,
            'lng' => $lng
        );
    }
    
    fclose( $handle );
    return $out;
}

// REST API callback: serve ZIP index dynamically (always current)
function subsales_get_zip_index_api( $request ) {
    // Get uploads directory for the ZIP data files
    $upload = wp_upload_dir();
    $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
    
    // Use relative path to avoid mixed content issues (HTTPS/HTTP)
    $zipdata_relative_path = '/wp-content/uploads/subsales-zipdata/';
    
    // Scan the uploads directory for existing .json files
    $existing_zips = array();
    if ( is_dir( $zipdata_dir ) ) {
        $files = glob( $zipdata_dir . '*.json' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                $basename = basename( $file, '.json' );
                // Validate it's a 5-digit ZIP code
                if ( preg_match( '/^[0-9]{5}$/', $basename ) ) {
                    $existing_zips[] = $basename;
                }
            }
        }
    }
    
    // Sort for consistent ordering
    sort( $existing_zips );
    
    // Create index with existing ZIP codes using relative path
    $index_data = array(
        'zips' => $existing_zips,
        'baseUrl' => $zipdata_relative_path,
        'timestamp' => current_time( 'timestamp' ),
        'count' => count( $existing_zips )
    );
    
    // Log the response for debugging
    subsales_log( 'DEBUG', 'api', 'ZIP index requested', array(
        'zip_count' => count( $existing_zips ),
        'zips' => implode( ', ', $existing_zips )
    ), 'api' );
    
    return new WP_REST_Response( $index_data, 200 );
}

// Helper: update zip-index.json in the PWA directory with the list of EXISTING extract files
function subsales_update_zip_index( $zips = null ) {
    // Get the plugin directory path
    $pwa_dir = plugin_dir_path( __FILE__ ) . 'pwa/';
    $index_file = $pwa_dir . 'zip-index.json';
    
    // Get uploads directory for the ZIP data files
    $upload = wp_upload_dir();
    $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
    
    // Use relative path to avoid mixed content issues (HTTPS/HTTP)
    // This works whether the site is on HTTP or HTTPS
    $zipdata_relative_path = '/wp-content/uploads/subsales-zipdata/';
    
    // Scan the uploads directory for existing .json files
    $existing_zips = array();
    if ( is_dir( $zipdata_dir ) ) {
        $files = glob( $zipdata_dir . '*.json' );
        if ( is_array( $files ) ) {
            foreach ( $files as $file ) {
                $basename = basename( $file, '.json' );
                // Validate it's a 5-digit ZIP code
                if ( preg_match( '/^[0-9]{5}$/', $basename ) ) {
                    $existing_zips[] = $basename;
                }
            }
        }
    }
    
    // Sort for consistent ordering
    sort( $existing_zips );
    
    // Create index with existing ZIP codes using relative path
    $index_data = array(
        'zips' => $existing_zips,
        'baseUrl' => $zipdata_relative_path
    );
    
    // Write the ZIP list as JSON
    $json = wp_json_encode( $index_data, JSON_PRETTY_PRINT );
    $written = file_put_contents( $index_file, $json );
    
    return $written !== false;
}

// Helper: save generation log to WordPress options (keep last 20 entries)
function subsales_save_generation_log( $log_entry ) {
    $logs = get_option( 'subsales_generation_logs', array() );
    
    // Prepend new entry to beginning of array
    array_unshift( $logs, $log_entry );
    
    // Keep only last 20 entries
    $logs = array_slice( $logs, 0, 20 );
    
    update_option( 'subsales_generation_logs', $logs );
}

// Helper: get generation logs
function subsales_get_generation_logs( $limit = 10 ) {
    $logs = get_option( 'subsales_generation_logs', array() );
    return array_slice( $logs, 0, $limit );
}

/**
 * Generate ZIP code JSON files from database (Phase 7)
 * Filters to residential addresses only for PWA consumption
 * 
 * @param array $zip_codes Array of ZIP codes to generate
 * @return array Results with counts per ZIP
 */
function subsales_generate_zip_json_from_database( $zip_codes = null ) {
    global $wpdb;
    $addresses_table = $wpdb->prefix . 'ss_addresses';
    
    // Use configured ZIPs if none provided
    if ( $zip_codes === null ) {
        $zip_codes = get_option( 'subsales_served_zips', array() );
        if ( ! is_array( $zip_codes ) ) {
            $zip_codes = array_filter( array_map( 'trim', explode( ',', $zip_codes ) ) );
        }
    }
    
    if ( empty( $zip_codes ) ) {
        return array( 'error' => 'No ZIP codes configured' );
    }
    
    $upload = wp_upload_dir();
    $base_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    
    // Ensure directory exists
    if ( ! is_dir( $base_dir ) ) {
        wp_mkdir_p( $base_dir );
    }
    
    $results = array();
    
    foreach ( $zip_codes as $zip ) {
        // Query RESIDENTIAL addresses only for this ZIP
        $addresses = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$addresses_table} 
             WHERE zip = %s 
             AND type = 'residential'
             ORDER BY street, house_number",
            $zip
        ), ARRAY_A );
        
        // Format for PWA compatibility
        $formatted = array();
        foreach ( $addresses as $addr ) {
            $label_parts = array( $addr['house_number'] . ' ' . $addr['street'] );
            if ( ! empty( $addr['unit'] ) ) {
                $label_parts[0] .= ' Unit ' . $addr['unit'];
            }
            if ( ! empty( $addr['city'] ) ) {
                $label_parts[] = $addr['city'];
            }
            if ( ! empty( $addr['state'] ) ) {
                $label_parts[] = $addr['state'];
            }
            $label_parts[] = $addr['zip'];
            
            $formatted[] = array(
                'id' => 'db-' . $addr['id'],
                'label' => implode( ', ', $label_parts ),
                'street' => $addr['street'],
                'housenumber' => $addr['house_number'],
                'unit' => $addr['unit'],
                'floor' => '',
                'door' => '',
                'housename' => '',
                'city' => $addr['city'],
                'state' => $addr['state'],
                'zip' => $addr['zip'],
                'lat' => (float) $addr['lat'],
                'lng' => (float) $addr['lng']
            );
        }
        
        // Write JSON file
        $file_path = trailingslashit( $base_dir ) . $zip . '.json';
        $written = file_put_contents( $file_path, wp_json_encode( $formatted, JSON_PRETTY_PRINT ) );
        
        $results[ $zip ] = array(
            'count' => count( $formatted ),
            'file' => $file_path,
            'bytes' => $written,
            'source' => 'database'
        );
    }
    
    // Update zip-index.json
    subsales_update_zip_index();
    
    return $results;
}

// Enqueue admin assets for settings page (media uploader for header image)
add_action( 'admin_enqueue_scripts', 'order_sync_admin_assets' );
function order_sync_admin_assets( $hook ) {
    // Only load on our settings page
    if ( strpos( $hook, 'subsales-settings' ) === false && strpos( $hook, 'admin.php?page=subsales-settings' ) === false ) {
        return;
    }

    // WP media scripts
    wp_enqueue_media();
    wp_enqueue_script( 'subsales-admin-header', SUBSALES_PLUGIN_URL . 'assets/js/admin-header-image.js', array( 'jquery' ), SUBSALES_VERSION, true );
    
    // ZIP admin JS for Address Extracts tab
    wp_enqueue_script( 'subsales-zip-admin', SUBSALES_PLUGIN_URL . 'assets/js/subsales-zip-admin.js', array( 'jquery' ), SUBSALES_VERSION, true );
    wp_localize_script( 'subsales-zip-admin', 'SubsalesZipAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'subsales_zip_generate' ),
        'deleteNonce' => wp_create_nonce( 'subsales_zip_delete' ),
        'searchNonce' => wp_create_nonce( 'subsales_address_search' ),
        'refreshIndexNonce' => wp_create_nonce( 'subsales_refresh_index' ),
        'matchNonce' => wp_create_nonce( 'subsales_match_addresses' ),
        'bgMatchNonce' => wp_create_nonce( 'subsales_bg_match' ),
        'uploadNonce' => wp_create_nonce( 'subsales_upload_address' ),
        'reassignZipsNonce' => wp_create_nonce( 'subsales_reassign_zips' ),
    ) );
}

// Teams and Orders pages, DB functions, REST routes, etc. (merged implementation)
// Implementation merged from legacy files; shortcode name is 'subsales_pwa'.

// -- Teams management, orders page, DB creation and helpers --
// ============================================================
// DATABASE WRAPPER FUNCTIONS (for backward compatibility)
// All database operations delegated to Subsales_Database class
// ============================================================

function order_sync_create_table() {
    Subsales_Database::create_tables();
}

function order_sync_add_team( $name, $access_code, $description = '', $status = 'active' ) {
    return Subsales_Database::add_team( $name, $access_code, $description, $status );
}

function order_sync_remove_team( $team_id ) {
    return Subsales_Database::remove_team( $team_id );
}

function order_sync_get_teams() {
    return Subsales_Database::get_teams();
}

function order_sync_get_team_by_credentials( $team_name, $access_code ) {
    return Subsales_Database::get_team_by_credentials( $team_name, $access_code );
}

function subsales_log( $level, $category, $message, $context = array(), $source = 'admin', $user_id = null, $user_name = '' ) {
    Subsales_Database::log( $level, $category, $message, $context, $source, $user_id, $user_name );
}

function subsales_log_order( $action, $order_id, $user_id = null, $user_name = '', $context = array(), $source = 'admin' ) {
    Subsales_Database::log_order( $action, $order_id, $user_id, $user_name, $context, $source );
}

function subsales_log_auth( $action, $user_id = null, $user_name = '', $context = array(), $source = 'pwa' ) {
    Subsales_Database::log_auth( $action, $user_id, $user_name, $context, $source );
}

function subsales_log_api_error( $endpoint, $error_message, $context = array(), $source = 'api' ) {
    Subsales_Database::log_api_error( $endpoint, $error_message, $context, $source );
}

function subsales_cleanup_old_logs() {
    Subsales_Database::cleanup_old_logs();
}

function subsales_check_debug_timeout() {
    Subsales_Database::check_debug_timeout();
}

function subsales_log_order_change( $order_db_id, $order_id, $before_data, $after_data, $edit_type, $user_id, $user_name, $edit_reason = '', $source = 'admin' ) {
    return Subsales_Database::log_order_change( $order_db_id, $order_id, $before_data, $after_data, $edit_type, $user_id, $user_name, $edit_reason, $source );
}

function order_sync_add_team_member( $team_id, $name, $email, $role = 'member' ) {
    return Subsales_Database::add_team_member( $team_id, $name, $email, $role );
}

function order_sync_remove_team_member( $member_id ) {
    return Subsales_Database::remove_team_member( $member_id );
}

function order_sync_get_team_members_by_team( $team_id ) {
    return Subsales_Database::get_team_members_by_team( $team_id );
}

function order_sync_verify_team_member( $email, $team_id ) {
    return Subsales_Database::verify_team_member( $email, $team_id );
}

// ============================================================
// End of Database Wrapper Functions
// ============================================================


// ============================================================
// REST API ROUTES
// Routes now registered via Subsales_REST_API class
// Handler functions remain below for compatibility
// ============================================================


// AJAX endpoint for admin orders filtering/pagination
add_action( 'wp_ajax_subsales_fetch_orders', 'order_sync_fetch_orders_ajax' );

// AJAX handler to get order by database ID
add_action( 'wp_ajax_subsales_get_order_by_db_id', 'subsales_get_order_by_db_id_ajax' );
function subsales_get_order_by_db_id_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    
    // Simple nonce check
    if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'wp_rest' ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    
    $db_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
    
    if ( ! $db_id ) {
        wp_send_json_error( 'Missing order ID' );
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';
    
    $order = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $db_id ),
        ARRAY_A
    );
    
    if ( ! $order ) {
        wp_send_json_error( 'Order not found' );
    }
    
    // Parse order_data JSON
    $order['order_data'] = json_decode( $order['order_data'], true );
    
    wp_send_json_success( $order );
}

function order_sync_fetch_orders_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }

    check_ajax_referer( 'subsales_orders_nonce', 'nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';

    $start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
    $end_date = isset( $_POST['end_date'] ) ? sanitize_text_field( $_POST['end_date'] ) : '';
    $filter_team = isset( $_POST['team_id'] ) ? intval( $_POST['team_id'] ) : 0;
    $filter_member = isset( $_POST['entered_by_id'] ) ? sanitize_text_field( $_POST['entered_by_id'] ) : '';
    $payment_method = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : '';
    $tally_status = isset( $_POST['tally_status'] ) ? sanitize_text_field( $_POST['tally_status'] ) : 'all';
    $show_deleted = isset( $_POST['show_deleted'] ) && $_POST['show_deleted'] === '1';
    $search_query = isset( $_POST['search_query'] ) ? sanitize_text_field( $_POST['search_query'] ) : '';
    $page = isset( $_POST['page'] ) ? max(1,intval($_POST['page'])) : 1;
    $page_size = isset( $_POST['page_size'] ) ? intval( $_POST['page_size'] ) : 100;
    if ( $page_size <= 0 || $page_size > 100 ) $page_size = 100;

    $where = array();
    $params = array();
    
    // Exclude deleted orders by default
    if ( ! $show_deleted ) {
        $where[] = "deleted = 0";
    }
    
    // Tally filter
    if ( $tally_status === 'untallied' ) {
        $where[] = "tallied = 0";
    } elseif ( $tally_status === 'tallied' ) {
        $where[] = "tallied = 1";
    }
    // 'all' means no tally filter
    
    // Quick search filter (customer name, address, or phone)
    if ( ! empty( $search_query ) ) {
        $search_like = '%' . $wpdb->esc_like( $search_query ) . '%';
        $where[] = "(order_data LIKE %s OR order_data LIKE %s OR order_data LIKE %s)";
        // Search in customer, address, and cellNumber fields
        $params[] = '%' . $wpdb->esc_like( '"customer"' ) . '%' . $wpdb->esc_like( $search_query ) . '%';
        $params[] = '%' . $wpdb->esc_like( '"address"' ) . '%' . $wpdb->esc_like( $search_query ) . '%';
        $params[] = '%' . $wpdb->esc_like( '"cellNumber"' ) . '%' . $wpdb->esc_like( $search_query ) . '%';
    }

    if ( ! empty( $start_date ) ) {
        $where[] = "created_at >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if ( ! empty( $end_date ) ) {
        $where[] = "created_at <= %s";
        $params[] = $end_date . ' 23:59:59';
    }
    if ( $filter_team ) {
        $where[] = "team_id = %d";
        $params[] = $filter_team;
    }
    if ( ! empty( $filter_member ) ) {
        $where[] = "user_id = %s";
        $params[] = $filter_member;
    }

    // Improved payment filtering: try JSON_EXTRACT when available, otherwise fallback to LIKE
    $use_json = false;
    try {
        $res = $wpdb->get_results( "SELECT JSON_EXTRACT('{\"a\":1}','$.a') as jtest LIMIT 1" );
        if ( $res !== null ) $use_json = true;
    } catch ( Exception $e ) {
        $use_json = false;
    }

    if ( ! empty( $payment_method ) ) {
        if ( $use_json ) {
            if ( $payment_method === 'cash' ) {
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.paymentMethod')) = %s";
                $params[] = 'cash';
            } elseif ( $payment_method === 'check' ) {
                $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.paymentMethod')) = %s OR JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.checkNumber')) IS NOT NULL)";
                $params[] = 'check';
            }
        } else {
            if ( $payment_method === 'cash' ) {
                $where[] = "order_data LIKE %s";
                $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"cash"%';
            } elseif ( $payment_method === 'check' ) {
                $where[] = "(order_data LIKE %s OR order_data LIKE %s)";
                $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"check"%';
                $params[] = '%' . $wpdb->esc_like( '"checkNumber"' ) . '%';
            }
        }
    }

    $where_sql = '';
    if ( ! empty( $where ) ) $where_sql = 'WHERE ' . implode( ' AND ', $where );

    // total count for pagination - prepare if we have params
    if ( ! empty( $params ) ) {
        $count_prepared = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( "SELECT COUNT(*) FROM {$table} {$where_sql}" ), $params ) );
        $count = intval( $wpdb->get_var( $count_prepared ) );
    } else {
        $count = intval( $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" ) );
    }

    $pages = max(1, ceil( $count / $page_size ));
    if ( $page > $pages ) $page = $pages;
    $offset = ( $page - 1 ) * $page_size;

    // fetch page
    $select_sql = "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    $select_args = array_merge( array( $select_sql ), $params );
    $select_args[] = $page_size; $select_args[] = $offset;
    $prepared = call_user_func_array( array( $wpdb, 'prepare' ), $select_args );
    $rows = $wpdb->get_results( $prepared, ARRAY_A );

    // Load configured products once for building products_map
    $configured_products = order_sync_get_products_config();

    $orders = array();
    $totals = array( 'cash' => 0.0, 'check' => 0.0, 'grand' => 0.0, 'donations' => 0.0, 'product_totals' => array() );
    // initialize product totals for the page
    foreach ( $configured_products as $pconf ) {
        $totals['product_totals'][ $pconf['id'] ] = 0;
    }

    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) $od = array();

        $order_total = 0.0;
        $items_arr = array();
        // initialize products map with zeros for configured products
        $products_map = array();
        foreach ( $configured_products as $pconf ) {
            $products_map[ $pconf['id'] ] = 0;
        }

        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $price = isset( $pr['price'] ) ? floatval( $pr['price'] ) : 0.0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                $name = isset( $pr['name'] ) ? $pr['name'] : ( $pid ? $pid : 'item' );
                if ( $qty > 0 ) {
                    $items_arr[] = $name . ' × ' . $qty;
                    $order_total += $qty * $price;
                    if ( $pid ) {
                        if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                        $products_map[ $pid ] += $qty;
                    }
                }
            }
        } else {
            // fallback: legacy qty fields -> populate products_map based on configured products
            if ( is_array( $configured_products ) ) {
                foreach ( $configured_products as $p ) {
                    $pid = $p['id'];
                    $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                    foreach ( $labels as $k ) {
                        if ( isset( $od[ $k ] ) ) {
                            $q = intval( $od[ $k ] );
                            if ( $q > 0 ) {
                                $items_arr[] = $p['name'] . ' × ' . $q;
                                $price = isset( $p['price'] ) ? floatval( $p['price'] ) : 0.0;
                                $order_total += $q * $price;
                                if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0;
                                $products_map[ $pid ] += $q;
                            }
                            break;
                        }
                    }
                }
            }
        }
        if ( isset( $od['donationAmount'] ) ) {
            $donation = floatval( $od['donationAmount'] );
            $order_total += $donation;
            $totals['donations'] += $donation;
        }

        $payment = '';;
        if ( isset( $od['paymentMethod'] ) && ! empty( $od['paymentMethod'] ) ) $payment = $od['paymentMethod'];
        else if ( ! empty( $od['checkNumber'] ) ) $payment = 'check';
        else if ( ! empty( $od['payCash'] ) || ! empty( $od['pay_cash'] ) ) $payment = 'cash';

        if ( strtolower( $payment ) === 'check' ) $totals['check'] += $order_total;
        elseif ( strtolower( $payment ) === 'cash' ) $totals['cash'] += $order_total;
        $totals['grand'] += $order_total;

            // add to page product totals
            foreach ( $products_map as $pid => $qty ) {
                if ( isset( $totals['product_totals'][ $pid ] ) ) {
                    $totals['product_totals'][ $pid ] += intval( $qty );
                } else {
                    $totals['product_totals'][ $pid ] = intval( $qty );
                }
            }

        $team_name = '';
        if ( ! empty( $r['team_id'] ) ) {
            $t = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_teams WHERE id = %d", intval( $r['team_id'] ) ) );
            $team_name = $t ? $t->name : '';
        }

        // Get entered_by_name - try from order_data first, then lookup by user_id
        $entered_by_name = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : '';
        if ( empty( $entered_by_name ) && ! empty( $r['user_id'] ) ) {
            // Try to look up user name from team_members table
            $user_row = $wpdb->get_row( $wpdb->prepare( 
                "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", 
                intval( $r['user_id'] ) 
            ) );
            if ( $user_row ) {
                $entered_by_name = $user_row->name;
            }
        }
        // Normalize created_at to site-local timezone for display.
        // Orders are stored in GMT, convert to local time for display.
        $created_gmt = isset( $r['created_at'] ) ? $r['created_at'] : null;
        if ( $created_gmt ) {
            // Convert from GMT to local time using WordPress timezone
            $created_local_str = get_date_from_gmt( $created_gmt );
            $created_ts = strtotime( $created_local_str );
            $created_formatted = date_i18n( 'M j, Y g:i A', $created_ts );
        } else {
            $created_local_str = $created_gmt;
            $created_ts = $created_gmt ? strtotime( $created_gmt ) : null;
            $created_formatted = $created_ts ? date_i18n( 'M j, Y g:i A', $created_ts ) : '';
        }

        // Determine whether this order has been edited by checking sync_status
        // 'synced' = original order from PWA, 'updated' = edited by admin
        $edited = ( isset( $r['sync_status'] ) && $r['sync_status'] === 'updated' );
        
        // Get tallied info
        $tallied = isset( $r['tallied'] ) && intval( $r['tallied'] ) === 1;
        $tallied_at = isset( $r['tallied_at'] ) ? $r['tallied_at'] : null;
        $tallied_by = null;
        if ( $tallied && isset( $r['tallied_by_user_id'] ) ) {
            $tallied_user = get_userdata( intval( $r['tallied_by_user_id'] ) );
            if ( $tallied_user ) {
                $tallied_by = $tallied_user->display_name;
            }
        }

        $orders[] = array(
            'id' => isset( $r['id'] ) ? intval( $r['id'] ) : null,
            'order_id' => $r['order_id'],
            'created_at' => $created_local_str,
            'created_at_formatted' => $created_formatted,
            'created_at_ts' => $created_ts,
            'user_id' => $r['user_id'],
            'entered_by_name' => $entered_by_name,
            'team_name' => $team_name,
            'items' => implode( ', ', $items_arr ),
            'order_total' => round( $order_total, 2 ),
            'donation_amount' => isset( $od['donationAmount'] ) ? floatval( $od['donationAmount'] ) : 0.0,
            'payment' => $payment,
            'payment_display' => $payment ? ucfirst($payment) : '',
            'edited' => $edited,
            'tallied' => $tallied,
            'tallied_at' => $tallied_at,
            'tallied_by' => $tallied_by,
            // map of configured product id => quantity for this order
            'products_map' => $products_map
        );
    }

    $response = array(
        'orders' => $orders,
        'totals' => $totals,
        'total_count' => $count,
        'page' => $page,
        'pages' => $pages,
        'page_size' => $page_size,
    );

    wp_send_json_success( $response );
}

// AJAX endpoint to run migration helper from the admin UI (runs under current user, requires manage_options)
/* Migration AJAX handler removed per request. Migration tools (if any) should be removed separately. */

// AJAX endpoint to run initialization from the onboarding wizard
add_action( 'wp_ajax_subsales_run_init', 'order_sync_run_init_ajax' );
function order_sync_run_init_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'subsales_init_nonce', 'nonce' );

    global $wpdb;

    // Basic inputs
    $branding = isset( $_POST['branding'] ) ? sanitize_text_field( $_POST['branding'] ) : '';
    $portal_slug = isset( $_POST['portal_slug'] ) ? sanitize_text_field( $_POST['portal_slug'] ) : 'subsales-portal';
    $maps_key = isset( $_POST['maps_key'] ) ? sanitize_text_field( $_POST['maps_key'] ) : '';

    // products arrays
    $product_names = isset( $_POST['product_name'] ) ? (array) $_POST['product_name'] : array();
    $product_prices = isset( $_POST['product_price'] ) ? (array) $_POST['product_price'] : array();
    $product_vis = isset( $_POST['product_visible'] ) ? (array) $_POST['product_visible'] : array();

    // team
    $team_name = isset( $_POST['team_name'] ) ? sanitize_text_field( $_POST['team_name'] ) : '';
    $team_code = isset( $_POST['team_code'] ) ? sanitize_text_field( $_POST['team_code'] ) : '';

    // Create DB tables
    try {
        order_sync_create_table();
    } catch ( Exception $e ) {
        // non-fatal
    }

    // Save basic options
    if ( ! empty( $branding ) ) update_option( 'subsales_branding', $branding );
    update_option( 'order_sync_portal_slug', $portal_slug );
    if ( ! empty( $maps_key ) ) update_option( 'order_sync_google_maps_api_key', $maps_key );
    
    // Save ZIP codes if provided
    $zip_codes = isset( $_POST['zip_codes'] ) ? sanitize_text_field( $_POST['zip_codes'] ) : '';
    if ( ! empty( $zip_codes ) ) {
        // Convert comma-separated string to array
        $zip_array = array_map( 'trim', explode( ',', $zip_codes ) );
        $zip_array = array_filter( $zip_array ); // Remove empty values
        update_option( 'subsales_served_zips', $zip_array );
    }

    // Products
    $products = array();
    for ( $i = 0; $i < min( 10, count( $product_names ) ); $i++ ) {
        $name = sanitize_text_field( $product_names[ $i ] );
        if ( empty( $name ) ) continue;
        $price_raw = isset( $product_prices[ $i ] ) ? $product_prices[ $i ] : '0';
        $price = floatval( preg_replace( '/[^0-9.\-]/', '', $price_raw ) );
        $id = sanitize_title( $name ); if ( empty( $id ) ) $id = 'p' . time() . $i;
        $visible = in_array( (string) $i, $product_vis, true ) || in_array( $id, $product_vis, true ) || true;
        $products[] = array( 'id' => $id, 'name' => $name, 'price' => number_format( $price, 2, '.', '' ), 'visible' => $visible ? 1 : 0 );
    }
    if ( ! empty( $products ) ) update_option( 'order_sync_products', wp_json_encode( $products ) );

    // Ensure portal page
    order_sync_ensure_pwa_page( $portal_slug );

    // Create sample team if requested
    $team_id = null;
    if ( ! empty( $team_name ) && ! empty( $team_code ) ) {
        // If team exists, get its ID; otherwise create it
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ss_teams WHERE name = %s", $team_name ), ARRAY_A );
        if ( $existing ) {
            $team_id = $existing['id'];
        } else {
            $result = order_sync_add_team( $team_name, $team_code, 'Created by setup wizard' );
            if ( $result ) {
                $team_id = $wpdb->insert_id;
            }
        }
    }

    // Create user if requested and assign to team
    $user_name = isset( $_POST['user_name'] ) ? sanitize_text_field( $_POST['user_name'] ) : '';
    $user_phone = isset( $_POST['user_phone'] ) ? sanitize_text_field( $_POST['user_phone'] ) : '';
    $user_email = isset( $_POST['user_email'] ) ? sanitize_email( $_POST['user_email'] ) : '';
    
    if ( ! empty( $user_name ) && ! empty( $user_phone ) && $team_id ) {
        // Normalize phone to 10 digits
        $phone_normalized = preg_replace( '/[^0-9]/', '', $user_phone );
        if ( strlen( $phone_normalized ) === 11 && substr( $phone_normalized, 0, 1 ) === '1' ) {
            $phone_normalized = substr( $phone_normalized, 1 );
        }
        
        if ( strlen( $phone_normalized ) === 10 ) {
            // Create user
            $user_insert = $wpdb->insert(
                $wpdb->prefix . 'ss_team_members',
                array(
                    'team_id' => 0,  // Will use junction table for team assignment
                    'name' => $user_name,
                    'email' => $user_email ?: '',
                    'phone' => $phone_normalized,
                    'role' => 'member',
                    'status' => 'active'
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            
            if ( $user_insert ) {
                $user_id = $wpdb->insert_id;
                // Assign user to team via junction table
                $wpdb->insert(
                    $wpdb->prefix . 'ss_user_teams',
                    array(
                        'user_id' => $user_id,
                        'team_id' => $team_id
                    ),
                    array( '%d', '%d' )
                );
            }
        }
    }
    
    // Set default login mode to 'user'
    update_option( 'order_sync_login_mode', 'user' );

    wp_send_json_success( array( 'message' => 'Initialization completed' ) );
}

// Admin-post handler: export orders CSV
add_action( 'admin_post_subsales_export_orders', 'order_sync_admin_export_orders' );
function order_sync_admin_export_orders() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';
    $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=subsales-orders-' . date('Ymd_His') . '.csv' );
    $out = fopen( 'php://output', 'w' );
    // header
    fputcsv( $out, array( 'id','order_id','user_id','team_id','order_data','sync_status','created_at','updated_at' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, array( $r['id'], $r['order_id'], $r['user_id'], $r['team_id'], $r['order_data'], $r['sync_status'], $r['created_at'], $r['updated_at'] ) );
    }
    fclose( $out );
    exit;
}

// Handle administrative CSV export (no routing) - one row per normalized address with aggregated product counts
add_action( 'admin_post_subsales_generate_admin_csv', 'order_sync_handle_generate_admin_csv' );
function order_sync_handle_generate_admin_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'subsales_generate_admin_csv' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';
    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
    }

    $configured_products = order_sync_get_products_config();

    // group by normalized address
    $by_address = array();
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
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
                        if ( $q > 0 ) { if ( ! isset( $products_map[ $pid ] ) ) $products_map[ $pid ] = 0; $products_map[ $pid ] += $q; }
                        break;
                    }
                }
            }
        }

        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue; // skip donations/empty

        $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
        $addr_norm = order_sync_normalize_address( $address_raw );
        if ( empty( $addr_norm ) ) continue;

        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : $r['user_id'] ) );
        $customer = isset( $od['customerName'] ) ? $od['customerName'] : ( isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['name'] ) ? $od['name'] : '' ) );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['cell'] ) ? $od['cell'] : ( isset( $od['phone'] ) ? $od['phone'] : '' ) );

        if ( ! isset( $by_address[ $addr_norm ] ) ) $by_address[ $addr_norm ] = array( 'address_raw' => $address_raw, 'products' => array(), 'order_ids' => array(), 'customer' => $customer, 'phone' => $phone, 'seller' => $seller );
        foreach ( $products_map as $pid => $q ) { if ( ! isset( $by_address[ $addr_norm ]['products'][ $pid ] ) ) $by_address[ $addr_norm ]['products'][ $pid ] = 0; $by_address[ $addr_norm ]['products'][ $pid ] += intval( $q ); }
        $by_address[ $addr_norm ]['order_ids'][] = $r['order_id'];
    }

    if ( empty( $by_address ) ) {
        $msg = rawurlencode( 'No valid product orders found for ' . $delivery_date );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    // Stream CSV
    $filename = 'administrative_delivery_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    $out = fopen( 'php://output', 'w' );
    $headers = array( 'Address' );
    foreach ( $configured_products as $pcol ) { $headers[] = $pcol['name']; }
    $headers = array_merge( $headers, array( 'Customer Name', 'Phone', 'Seller', 'Order IDs' ) );
    fputcsv( $out, $headers );

    foreach ( $by_address as $addr_norm => $g ) {
        $row = array();
        $row[] = $g['address_raw'];
        foreach ( $configured_products as $pcol ) { $pid = $pcol['id']; $row[] = isset( $g['products'][ $pid ] ) ? intval( $g['products'][ $pid ] ) : 0; }
        $row[] = $g['customer'];
        $row[] = $g['phone'];
        $row[] = $g['seller'];
        $row[] = implode('|', $g['order_ids']);
        fputcsv( $out, $row );
    }
    fclose( $out );
    exit;
}

// XLSX (printable workbook) generation — try PhpSpreadsheet and fall back to CSV
add_action( 'admin_post_subsales_generate_delivery_xlsx', 'order_sync_handle_generate_delivery_xlsx' );
function order_sync_handle_generate_delivery_xlsx() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce_xlsx'] ) || ! wp_verify_nonce( $_POST['_wpnonce_xlsx'], 'subsales_generate_delivery_xlsx' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';

    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( $_POST['start_address'] ) : '';
    update_option( 'order_sync_delivery_start_address', $start_address );
    $driver_count = isset( $_POST['driver_count'] ) ? max(1, intval( $_POST['driver_count'] )) : 2;
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
    }

    $configured_products = order_sync_get_products_config();

    // Build orders grouped by normalized address (reusing CSV logic)
    $by_address = array();
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
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
        $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
        $addr_norm = order_sync_normalize_address( $address_raw );
        if ( empty( $addr_norm ) ) continue;
        $team_name = '';
        if ( ! empty( $r['team_id'] ) ) {
            $t = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_teams WHERE id = %d", intval( $r['team_id'] ) ) );
            $team_name = $t ? $t->name : '';
        }
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : $r['user_id'] ) );
        $customer = isset( $od['customerName'] ) ? $od['customerName'] : ( isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['name'] ) ? $od['name'] : '' ) );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['cell'] ) ? $od['cell'] : ( isset( $od['phone'] ) ? $od['phone'] : '' ) );

        $entry = array(
            'order_id' => $r['order_id'],
            'team' => $team_name,
            'seller' => $seller,
            'address_raw' => $address_raw,
            'address_norm' => $addr_norm,
            'products_map' => $products_map,
            'customer' => $customer,
            'phone' => $phone,
            'total_qty' => $total_qty,
        );

        if ( ! isset( $by_address[ $addr_norm ] ) ) {
            $by_address[ $addr_norm ] = array( 'address_raw' => $address_raw, 'orders' => array() );
        }
        $by_address[ $addr_norm ]['orders'][] = $entry;
    }

    if ( empty( $by_address ) ) {
        $msg = rawurlencode( 'No valid product orders found for ' . $delivery_date );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    $manifest_rows = array();
    foreach ( $by_address as $addr_norm => $group ) {
        $combined_products = array();
        foreach ( $configured_products as $p ) { $combined_products[ $p['id'] ] = 0; }
        $order_ids = array();
        $team = '';
        $seller = '';
        $customer = '';
        $phone = '';
        foreach ( $group['orders'] as $o ) {
            $order_ids[] = $o['order_id'];
            foreach ( $o['products_map'] as $pid => $q ) { if ( ! isset( $combined_products[ $pid ] ) ) $combined_products[ $pid ] = 0; $combined_products[ $pid ] += intval( $q ); }
            if ( empty( $team ) && ! empty( $o['team'] ) ) $team = $o['team'];
            if ( empty( $seller ) && ! empty( $o['seller'] ) ) $seller = $o['seller'];
            if ( empty( $customer ) && ! empty( $o['customer'] ) ) $customer = $o['customer'];
            if ( empty( $phone ) && ! empty( $o['phone'] ) ) $phone = $o['phone'];
        }
        $manifest_rows[] = array(
            'address_raw' => $group['address_raw'],
            'address_norm' => $addr_norm,
            'products_map' => $combined_products,
            'order_ids' => $order_ids,
            'team' => $team,
            'seller' => $seller,
            'customer' => $customer,
            'phone' => $phone,
        );
    }

    // Assign drivers
    $drivers = array(); $driver_counts = array();
    for ( $i = 1; $i <= $driver_count; $i++ ) { $drivers[ $i ] = array(); $driver_counts[ $i ] = 0; }
    usort( $manifest_rows, function( $a, $b ) {
        $ca = isset( $a['order_ids'] ) ? count( $a['order_ids'] ) : 0;
        $cb = isset( $b['order_ids'] ) ? count( $b['order_ids'] ) : 0;
        return $cb - $ca;
    } );
    foreach ( $manifest_rows as $mr ) {
        $count_here = isset( $mr['order_ids'] ) ? count( $mr['order_ids'] ) : 1;
        $min_driver = null; $min_count = null;
        foreach ( $driver_counts as $dnum => $cnt ) { if ( $min_driver === null || $cnt < $min_count ) { $min_driver = $dnum; $min_count = $cnt; } }
        $drivers[ $min_driver ][] = $mr;
        $driver_counts[ $min_driver ] += $count_here;
    }

    // If PhpSpreadsheet available, generate XLSX with one sheet per driver, formatted for printing
    if ( class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheetIndex = 0;
            foreach ( $drivers as $dnum => $drows ) {
                if ( $sheetIndex > 0 ) $spreadsheet->createSheet();
                $sheet = $spreadsheet->setActiveSheetIndex( $sheetIndex );
                $title = 'Driver ' . $dnum;
                // Excel sheet title max length 31
                $sheet->setTitle( substr( $title, 0, 31 ) );

                // Build header columns: Address, <products...>, Customer Name, Phone, Seller
                $columns = array( 'Address' );
                foreach ( $configured_products as $pcol ) { $columns[] = $pcol['name']; }
                $columns = array_merge( $columns, array( 'Customer Name', 'Phone', 'Seller' ) );
                $colCount = count( $columns );

                // Merge top row for driver/title
                $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colCount );
                $sheet->mergeCells( 'A1:' . $lastColLetter . '1' );
                $sheet->setCellValue( 'A1', 'Driver ' . $dnum . ( $delivery_date ? ' — ' . $delivery_date : '' ) );

                // Header labels on row 2
                $r = 2; $c = 1;
                foreach ( $columns as $col ) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $r;
                    $sheet->setCellValue( $cell, $col );
                    $c++;
                }

                // Data rows starting at row 3
                $rowNum = 3;
                foreach ( $drows as $rdata ) {
                    $c = 1;
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['address_raw'] ); $c++;
                    foreach ( $configured_products as $pcol ) {
                        $pid = $pcol['id'];
                        $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, isset( $rdata['products_map'][ $pid ] ) ? intval( $rdata['products_map'][ $pid ] ) : 0 ); $c++;
                    }
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['customer'] ); $c++;
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['phone'] ); $c++;
                    $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $rowNum, $rdata['seller'] );
                    $rowNum++;
                }

                // Totals row
                $totalsRow = $rowNum;
                $c = 1;
                $sheet->setCellValue( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c ) . $totalsRow, 'Totals' ); $c++;
                $startDataRow = 3;
                $endDataRow = $rowNum - 1;
                foreach ( $configured_products as $pcol ) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $c );
                    if ( $endDataRow >= $startDataRow ) {
                        $sheet->setCellValue( $colLetter . $totalsRow, "=SUM({$colLetter}{$startDataRow}:{$colLetter}{$endDataRow})" );
                    } else {
                        $sheet->setCellValue( $colLetter . $totalsRow, 0 );
                    }
                    $c++;
                }

                // Column widths: Address wider, product columns moderate, others wider
                $sheet->getColumnDimension( 'A' )->setWidth( 50 );
                $colIndex = 2;
                foreach ( $configured_products as $pcol ) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex );
                    $sheet->getColumnDimension( $colLetter )->setWidth( 14 );
                    $colIndex++;
                }
                    $sheet->getColumnDimension( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex ) )->setWidth( 28 ); $colIndex++;
                $sheet->getColumnDimension( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex ) )->setWidth( 18 ); $colIndex++;
                $sheet->getColumnDimension( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $colIndex ) )->setWidth( 22 );

                // Page setup: landscape, fit to width
                $sheet->getPageSetup()->setOrientation( \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE );
                $sheet->getPageSetup()->setPaperSize( \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LETTER );
                $sheet->getPageSetup()->setFitToPage( true );
                $sheet->getPageSetup()->setFitToWidth( 1 );
                $sheet->getPageSetup()->setFitToHeight( 0 );
                $sheet->getPageSetup()->setHorizontalCentered( true );

                // Styling: bold for title and headers
                $sheet->getStyle( 'A1:' . $lastColLetter . '2' )->getFont()->setBold( true );

                $sheetIndex++;
            }

            // send workbook
            $spreadsheet->setActiveSheetIndex( 0 );
            $filename = 'delivery_manifest_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.xlsx';
            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
            $writer->save( 'php://output' );
            exit;
        } catch ( Exception $e ) {
            // fall through to CSV fallback
        }
    }

    // Fallback: stream CSV (same format as existing CSV export)
    $filename = 'delivery_manifest_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    $out = fopen( 'php://output', 'w' );
    $headers = array( 'Driver', 'Address' );
    foreach ( $configured_products as $pcol ) { $headers[] = $pcol['name']; }
    $headers = array_merge( $headers, array( 'Customer Name', 'Phone', 'Seller' ) );
    fputcsv( $out, $headers );
    foreach ( $drivers as $dnum => $drows ) {
        foreach ( $drows as $r ) {
            $row = array();
            $row[] = $dnum;
            $row[] = $r['address_raw'];
            foreach ( $configured_products as $pcol ) { $pid = $pcol['id']; $row[] = isset( $r['products_map'][ $pid ] ) ? intval( $r['products_map'][ $pid ] ) : 0; }
            $row[] = $r['customer'];
            $row[] = $r['phone'];
            $row[] = $r['seller'];
            fputcsv( $out, $row );
        }
    }
    fclose( $out );
    exit;
}

// PDF Individual Manifest Generation - group by entered_by and optimize routes
add_action( 'admin_post_subsales_generate_delivery_pdf', 'order_sync_handle_generate_delivery_pdf' );
function order_sync_handle_generate_delivery_pdf() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'subsales_generate_delivery' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';

    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( $_POST['start_address'] ) : '';
    
    if ( empty( $start_address ) ) {
        wp_die( 'Starting address (depot) is required' );
    }
    
    update_option( 'order_sync_delivery_start_address', $start_address );
    
    // Geocode starting address
    $start_coords = order_sync_geocode_address( $start_address );
    if ( ! $start_coords ) {
        wp_die( 'Could not geocode starting address. Please check your Google Maps API key and address.' );
    }

    // Fetch orders
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s AND deleted = 0 ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY id ASC", ARRAY_A );
    }

    if ( empty( $rows ) ) {
        $msg = rawurlencode( 'No orders found for ' . ( $delivery_date ?: 'all dates' ) );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

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
            $individual_name = ! empty( $od['entered_by_name'] ) ? $od['entered_by_name'] : $individual_id;
        } elseif ( ! empty( $od['entered_by_name'] ) ) {
            $individual_name = $od['entered_by_name'];
            $individual_id = $individual_name;
        } elseif ( ! empty( $r['user_id'] ) ) {
            $individual_id = $r['user_id'];
            $individual_name = $r['user_id'];
        } else {
            $individual_id = 'unknown';
            $individual_name = 'Unknown';
        }

        // Extract product quantities
        $products_map = array();
        foreach ( $configured_products as $pconf ) {
            $products_map[ $pconf['id'] ] = 0;
        }

        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
            foreach ( $od['products'] as $pr ) {
                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                $pid = isset( $pr['id'] ) ? $pr['id'] : null;
                if ( $qty > 0 && $pid && isset( $products_map[ $pid ] ) ) {
                    $products_map[ $pid ] += $qty;
                }
            }
        }

        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue;

        $address = isset( $od['address'] ) ? $od['address'] : '';
        $unitFloorApt = isset( $od['unitFloorApt'] ) ? $od['unitFloorApt'] : '';
        if ( ! empty( $unitFloorApt ) ) {
            $address .= ', ' . $unitFloorApt;
        }
        
        if ( empty( $address ) ) continue;

        $customer = isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['customerName'] ) ? $od['customerName'] : '' );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['phone'] ) ? $od['phone'] : '' );
        $notes = isset( $od['notes'] ) ? $od['notes'] : '';

        // Geocode address
        $coords = order_sync_geocode_address( $address );

        $order_entry = array(
            'order_id' => $r['order_id'],
            'address' => $address,
            'customer' => $customer,
            'phone' => $phone,
            'notes' => $notes,
            'products_map' => $products_map,
            'lat' => $coords ? $coords['lat'] : null,
            'lng' => $coords ? $coords['lng'] : null,
        );

        if ( ! isset( $by_individual[ $individual_id ] ) ) {
            $by_individual[ $individual_id ] = array(
                'name' => $individual_name,
                'orders' => array(),
            );
        }

        $by_individual[ $individual_id ]['orders'][] = $order_entry;
    }

    if ( empty( $by_individual ) ) {
        $msg = rawurlencode( 'No valid orders with products found' );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    // Generate HTML manifests for each individual
    $html_files = array();
    
    foreach ( $by_individual as $individual_id => $data ) {
        $individual_name = $data['name'];
        $orders = $data['orders'];

        // Optimize route using nearest-neighbor algorithm
        $optimized_orders = order_sync_optimize_route( $orders, $start_coords );

        // Generate HTML for printing
        $html_content = order_sync_generate_manifest_html( $individual_name, $optimized_orders, $start_address, $configured_products, $delivery_date );
        
        $filename = 'manifest-' . sanitize_file_name( $individual_name ) . '-' . date('Ymd') . '.html';
        $html_files[ $filename ] = $html_content;
    }

    // If single individual, show HTML page directly
    if ( count( $html_files ) === 1 ) {
        $content = array_values( $html_files )[0];
        
        header( 'Content-Type: text/html; charset=UTF-8' );
        echo $content;
        exit;
    }

    // Multiple individuals - create ZIP
    $zipname = sys_get_temp_dir() . '/manifests-' . time() . '.zip';
    $za = new ZipArchive();
    if ( $za->open( $zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        wp_die( 'Could not create zip' );
    }

    foreach ( $html_files as $filename => $content ) {
        $za->addFromString( $filename, $content );
    }
    $za->close();

    // Send ZIP
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="delivery-manifests-' . date('Ymd_His') . '.zip"' );
    header( 'Content-Length: ' . filesize( $zipname ) );
    readfile( $zipname );
    @unlink( $zipname );
    exit;
}

// Helper: Optimize delivery route using nearest-neighbor algorithm
function order_sync_optimize_route( $orders, $start_coords ) {
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

            $dist = order_sync_haversine_distance( $current_lat, $current_lng, $order['lat'], $order['lng'] );
            
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

// Helper: Calculate distance between two lat/lng points (Haversine formula)
function order_sync_haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
    $earth_radius = 6371; // km

    $dLat = deg2rad( $lat2 - $lat1 );
    $dLon = deg2rad( $lon2 - $lon1 );

    $a = sin( $dLat / 2 ) * sin( $dLat / 2 ) +
         cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
         sin( $dLon / 2 ) * sin( $dLon / 2 );

    $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

    return $earth_radius * $c;
}

// Helper: Generate HTML manifest for printing
function order_sync_generate_manifest_html( $individual_name, $orders, $start_address, $configured_products, $delivery_date = '' ) {
    
    // Calculate product totals for packing list
    $product_totals = array();
    foreach ( $configured_products as $p ) {
        $product_totals[ $p['id'] ] = array( 'name' => $p['name'], 'qty' => 0 );
    }

    foreach ( $orders as $order ) {
        foreach ( $order['products_map'] as $pid => $qty ) {
            if ( isset( $product_totals[ $pid ] ) ) {
                $product_totals[ $pid ]['qty'] += $qty;
            }
        }
    }

    // Calculate total pages: 2 for packing lists + number of delivery stops
    $total_pages = 2 + count( $orders );
    
    // Determine display date
    $display_date = ! empty( $delivery_date ) ? date('F j, Y', strtotime( $delivery_date ) ) : date('F j, Y');
    
    // Build HTML content with print-optimized CSS
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Manifest - ' . esc_html( $individual_name ) . '</title>
    <style>
        /* Print-optimized styles */
        @media print {
            @page { 
                margin: 0.5in 0.5in 1in 0.5in; 
                size: letter portrait;
            }
            body { margin: 0; }
            .no-print { display: none !important; }
            .manifest-section { page-break-after: always; }
            .manifest-section:last-child { page-break-after: auto; }
        }
        body { font-family: Arial, Helvetica, sans-serif; margin: 0; padding: 0; font-size: 12pt; }
        h1 { font-size: 24pt; margin: 0 0 10px 0; }
        h2 { font-size: 18pt; margin: 20px 0 10px 0; }
        .depot { font-size: 11pt; margin-bottom: 20px; color: #666; }
        .page-number { font-size: 14pt; color: #666; margin-top: 10px; text-align: center; }
        .delivery-stop { margin-bottom: 15px; padding: 12px; border: 2px solid #ddd; page-break-inside: avoid; background: #f9f9f9; }
        .stop-number { font-size: 16pt; font-weight: bold; color: #0073aa; margin-bottom: 5px; }
        .address { font-size: 13pt; font-weight: bold; margin: 5px 0; }
        .customer { font-size: 11pt; margin: 3px 0; }
        .products { margin: 8px 0; }
        .products-table { width: 100%; border-collapse: collapse; margin: 5px 0; }
        .products-table th, .products-table td { border: 1px solid #999; padding: 6px; text-align: left; font-size: 10pt; }
        .products-table th { background: #e0e0e0; font-weight: bold; }
        .notes { font-size: 9pt; font-style: italic; color: #666; margin-top: 5px; padding: 5px; background: #fff3cd; border-left: 3px solid #ffc107; }
        .packing-list { margin-top: 40px; }
        .manifest-section { page-break-before: always; }
        .packing-table { width: 100%; border-collapse: collapse; font-size: 22pt; margin-top: 20px; }
        .packing-table th, .packing-table td { border: 3px solid #000; padding: 15px; text-align: left; }
        .packing-table th { background: #f0f0f0; font-weight: bold; }
        .packing-table .total-row { font-weight: bold; background: #d0d0d0; font-size: 24pt; }
        .footer { position: fixed; bottom: 0; left: 0.5in; right: 0.5in; height: 0.6in; font-size: 10pt; border-top: 1px solid #999; padding-top: 10px; }
        .footer-left { float: left; width: 50%; text-align: left; }
        .footer-right { float: right; width: 50%; text-align: right; }
    </style>
</head>
<body>';

    // First Packing List (PAGE 1)
    $html .= '<div class="manifest-page packing-list">';
    $html .= '<h1>Packing List: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
    $html .= '<table class="packing-table">';
    $html .= '<thead><tr><th>Product</th><th style="width:150px;text-align:center;">Quantity</th></tr></thead>';
    $html .= '<tbody>';
    
    $grand_total = 0;
    foreach ( $product_totals as $pid => $data ) {
        if ( $data['qty'] > 0 ) {
            $html .= '<tr><td>' . htmlspecialchars( $data['name'], ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $data['qty'] ) . '</td></tr>';
            $grand_total += $data['qty'];
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

    // Delivery Manifest (SUBSEQUENT PAGES)
    $html .= '<div class="manifest-page">';
    $html .= '<h1>Delivery Manifest: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
    $html .= '<div class="depot"><strong>Starting Point:</strong> ' . htmlspecialchars( $start_address, ENT_QUOTES, 'UTF-8' ) . '</div>';
    $html .= '<div class="depot"><strong>Total Stops:</strong> ' . count( $orders ) . ' | <strong>Date:</strong> ' . $display_date . '</div>';

    $html .= '</div>'; // Close delivery manifest header page
    
    // Delivery stops - each on its own page
    $stop_num = 1;
    $page_num = 3; // Start at page 3 (two packing lists are pages 1-2)
    foreach ( $orders as $order ) {
        $html .= '<div class="manifest-page">';
        $html .= '<div class="delivery-stop">';
        $html .= '<div class="stop-number">Stop #' . $stop_num . '</div>';
        $html .= '<div class="address">' . htmlspecialchars( $order['address'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        
        if ( ! empty( $order['customer'] ) ) {
            $html .= '<div class="customer"><strong>Customer:</strong> ' . htmlspecialchars( $order['customer'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        }
        
        if ( ! empty( $order['phone'] ) ) {
            $html .= '<div class="customer"><strong>Phone:</strong> ' . htmlspecialchars( $order['phone'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        }

        // Products for this stop
        $html .= '<div class="products"><strong>Products:</strong>';
        $html .= '<table class="products-table">';
        $html .= '<thead><tr><th>Product</th><th style="width:80px;text-align:center;">Qty</th></tr></thead><tbody>';
        $has_products = false;
        foreach ( $order['products_map'] as $pid => $qty ) {
            if ( $qty > 0 ) {
                $product_name = '';
                foreach ( $configured_products as $p ) {
                    if ( $p['id'] === $pid ) {
                        $product_name = $p['name'];
                        break;
                    }
                }
                $html .= '<tr><td>' . htmlspecialchars( $product_name, ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $qty ) . '</td></tr>';
                $has_products = true;
            }
        }
        if ( ! $has_products ) {
            $html .= '<tr><td colspan="2">No products</td></tr>';
        }
        $html .= '</tbody></table></div>';

        if ( ! empty( $order['notes'] ) ) {
            $html .= '<div class="notes"><strong>Delivery Notes:</strong> ' . htmlspecialchars( $order['notes'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        }

        $html .= '</div>'; // Close delivery-stop
        $html .= '<div class="footer">';
        $html .= '<div class="footer-left"><strong>Sales Person:</strong> ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</div>';
        $html .= '<div class="footer-right">Page ' . $page_num . ' of ' . $total_pages . ' | <strong>Date:</strong> ' . $display_date . '</div>';
        $html .= '</div>';
        $html .= '</div>'; // Close manifest-page
        $stop_num++;
        $page_num++;
    }
    
    $html .= '</body></html>';
    
    return $html;
}

// Admin-post handler: export settings CSV (key,value)
add_action( 'admin_post_subsales_export_settings', 'order_sync_admin_export_settings' );
function order_sync_admin_export_settings() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );
    $keys = array(
        'order_sync_portal_slug','order_sync_google_maps_api_key','subsales_branding','order_sync_products',
        'order_sync_primary_color','order_sync_style_variant','order_sync_interval','order_sync_session_duration',
        'order_sync_login_mode','subsales_header_image','subsales_served_zipcodes','subsales_delete_on_uninstall'
    );
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=subsales-settings-' . date('Ymd_His') . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'option_key','option_value' ) );
    foreach ( $keys as $k ) {
        $v = get_option( $k );
        if ( is_array( $v ) || is_object( $v ) ) $v = wp_json_encode( $v );
        fputcsv( $out, array( $k, $v ) );
    }
    fclose( $out );
    exit;
}

// Export teams CSV
add_action( 'admin_post_subsales_export_teams', 'subsales_export_teams_csv' );
function subsales_export_teams_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'ss_teams';
    $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=subsales-teams-' . date('Ymd_His') . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'id','team_name','access_code','created_at' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, array( $r['id'], $r['team_name'], $r['access_code'], $r['created_at'] ) );
    }
    fclose( $out );
    exit;
}

// Export team members CSV
add_action( 'admin_post_subsales_export_members', 'subsales_export_members_csv' );
function subsales_export_members_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'ss_team_members';
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';
    
    // Get all members with their team assignments
    $rows = $wpdb->get_results( "
        SELECT m.*, GROUP_CONCAT(ut.team_id) as team_ids
        FROM {$table} m
        LEFT JOIN {$user_teams_table} ut ON m.id = ut.user_id
        GROUP BY m.id
        ORDER BY m.id ASC
    ", ARRAY_A );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=subsales-members-' . date('Ymd_His') . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'id','name','phone','email','team_ids','created_at' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, array( $r['id'], $r['name'], $r['phone'], $r['email'] ?? '', $r['team_ids'] ?? '', $r['created_at'] ) );
    }
    fclose( $out );
    exit;
}

// Export addresses CSV
add_action( 'admin_post_subsales_export_addresses', 'subsales_export_addresses_csv' );
function subsales_export_addresses_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );
    global $wpdb;
    $table = $wpdb->prefix . 'ss_addresses';
    $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 50000", ARRAY_A );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=subsales-addresses-' . date('Ymd_His') . '.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, array( 'id','address','city','state','zip','lat','lng','source','created_at' ) );
    foreach ( $rows as $r ) {
        fputcsv( $out, array( 
            $r['id'], 
            $r['address'] ?? '', 
            $r['city'] ?? '', 
            $r['state'] ?? '', 
            $r['zip'] ?? '', 
            $r['lat'] ?? '', 
            $r['lng'] ?? '', 
            $r['source'] ?? '', 
            $r['created_at'] ?? ''
        ) );
    }
    fclose( $out );
    exit;
}

// Admin-post handler: export combined backup (orders + settings) as a ZIP
add_action( 'admin_post_subsales_export_backup_combined', 'order_sync_admin_export_backup_combined' );
function order_sync_admin_export_backup_combined() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_nonce' );

    global $wpdb;
    
    // Helper function to build CSV in-memory
    $build_csv = function( $headers, $rows ) {
        $out = fopen('php://temp', 'r+');
        fputcsv( $out, $headers );
        foreach ( $rows as $r ) {
            fputcsv( $out, $r );
        }
        rewind( $out );
        $csv = stream_get_contents( $out );
        fclose( $out );
        return $csv;
    };
    
    // Build orders CSV
    $orders_table = $wpdb->prefix . 'ss_orders';
    $orders = $wpdb->get_results( "SELECT * FROM {$orders_table} ORDER BY created_at DESC", ARRAY_A );
    $orders_csv = $build_csv( 
        array( 'id','order_id','user_id','team_id','order_data','sync_status','created_at','updated_at' ),
        array_map( function($r) {
            return array( $r['id'], $r['order_id'], $r['user_id'], $r['team_id'], $r['order_data'], $r['sync_status'], $r['created_at'], $r['updated_at'] );
        }, $orders )
    );

    // Build teams CSV
    $teams_table = $wpdb->prefix . 'ss_teams';
    $teams = $wpdb->get_results( "SELECT * FROM {$teams_table} ORDER BY id ASC", ARRAY_A );
    $teams_csv = $build_csv(
        array( 'id','team_name','access_code','created_at' ),
        array_map( function($r) {
            return array( $r['id'], $r['team_name'], $r['access_code'], $r['created_at'] );
        }, $teams )
    );
    
    // Build members CSV (with team assignments)
    $members_table = $wpdb->prefix . 'ss_team_members';
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';
    $members = $wpdb->get_results( "
        SELECT m.*, GROUP_CONCAT(ut.team_id) as team_ids
        FROM {$members_table} m
        LEFT JOIN {$user_teams_table} ut ON m.id = ut.user_id
        GROUP BY m.id
        ORDER BY m.id ASC
    ", ARRAY_A );
    $members_csv = $build_csv(
        array( 'id','name','phone','email','team_ids','created_at' ),
        array_map( function($r) {
            return array( $r['id'], $r['name'], $r['phone'], $r['email'] ?? '', $r['team_ids'] ?? '', $r['created_at'] );
        }, $members )
    );
    
    // Build addresses CSV (limit to 50k for performance)
    $addresses_table = $wpdb->prefix . 'ss_addresses';
    $addresses = $wpdb->get_results( "SELECT * FROM {$addresses_table} ORDER BY id ASC LIMIT 50000", ARRAY_A );
    $addresses_csv = $build_csv(
        array( 'id','address','city','state','zip','lat','lng','source','created_at' ),
        array_map( function($r) {
            return array( $r['id'], $r['address'] ?? '', $r['city'] ?? '', $r['state'] ?? '', $r['zip'] ?? '', $r['lat'] ?? '', $r['lng'] ?? '', $r['source'] ?? '', $r['created_at'] ?? '' );
        }, $addresses )
    );

    // Build settings CSV
    $keys = array(
        'order_sync_portal_slug','order_sync_google_maps_api_key','subsales_branding','order_sync_products',
        'order_sync_primary_color','order_sync_style_variant','order_sync_interval','order_sync_session_duration',
        'order_sync_login_mode','subsales_header_image','subsales_served_zipcodes','subsales_delete_on_uninstall'
    );
    $settings_csv = $build_csv(
        array( 'option_key','option_value' ),
        array_map( function($k) {
            $v = get_option( $k );
            if ( is_array( $v ) || is_object( $v ) ) $v = wp_json_encode( $v );
            return array( $k, $v );
        }, $keys )
    );

    // Create ZIP in temp file
    $zipname = sys_get_temp_dir() . '/subsales-backup-' . time() . '.zip';
    $za = new ZipArchive();
    if ( $za->open( $zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        wp_die( 'Could not create zip' );
    }
    $za->addFromString( 'orders.csv', $orders_csv );
    $za->addFromString( 'teams.csv', $teams_csv );
    $za->addFromString( 'members.csv', $members_csv );
    $za->addFromString( 'addresses.csv', $addresses_csv );
    $za->addFromString( 'settings.csv', $settings_csv );
    $za->close();

    // Send file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename=subsales-backup-' . date('Ymd_His') . '.zip');
    header('Content-Length: ' . filesize( $zipname ) );
    readfile( $zipname );
    @unlink( $zipname );
    exit;
}

// Admin-post handler: import backup CSV
add_action( 'admin_post_subsales_import_backup', 'order_sync_admin_import_backup' );
function order_sync_admin_import_backup() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_import_nonce' );
    if ( ! isset( $_FILES['backup_file'] ) || ! is_uploaded_file( $_FILES['backup_file']['tmp_name'] ) ) {
        wp_redirect( add_query_arg( 'subsales_import_error', 'nofile', wp_get_referer() ) ); exit;
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    $update_existing = isset( $_POST['import_update_existing'] ) && intval( $_POST['import_update_existing'] ) === 1;

    $result = order_sync_process_import_file( $tmp, $update_existing );

    $msg_parts = array();
    $msg_parts[] = sprintf( 'Imported=%d', $result['imported'] );
    $msg_parts[] = sprintf( 'Updated=%d', $result['updated'] );
    $msg_parts[] = sprintf( 'Skipped=%d', $result['skipped'] );
    if ( isset( $result['geocoded'] ) && $result['geocoded'] > 0 ) {
        $msg_parts[] = sprintf( 'Geocoded=%d', $result['geocoded'] );
    }
    if ( isset( $result['zip_corrected'] ) && $result['zip_corrected'] > 0 ) {
        $msg_parts[] = sprintf( 'ZIPs_Corrected=%d', $result['zip_corrected'] );
    }
    $msg = implode( ', ', $msg_parts );
    
    // Suppress onboarding modal on the immediate redirect after an import/restore
    set_transient( 'subsales_suppress_onboarding', true, 30 );
    wp_redirect( add_query_arg( 'subsales_import_result', rawurlencode($msg), wp_get_referer() ) ); exit;
}

// Reusable import processor: accepts a path to uploaded file (tmp) and returns totals/errors
function order_sync_process_import_file( $tmp, $update_existing = false ) {
    $total_imported = 0; $total_updated = 0; $total_skipped = 0; $total_errors = array();
    $geocoded_count = 0; $zip_corrections = 0;
    global $wpdb;

    // Helper to process a CSV file path. Returns array(imported, updated, skipped, errors, geocoded, zip_corrected)
    $process_csv = function( $filepath, $update_existing ) use ( $wpdb ) {
        $imported = 0; $updated = 0; $skipped = 0; $errors = array(); $geocoded = 0; $zip_corrected = 0;
        if ( ! file_exists( $filepath ) ) return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('file_missing'),'geocoded'=>0,'zip_corrected'=>0 );
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('openfail'),'geocoded'=>0,'zip_corrected'=>0 );
        $header = fgetcsv( $handle );
        if ( ! $header ) { fclose($handle); return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('invalid'),'geocoded'=>0,'zip_corrected'=>0 ); }
        $lower = array_map('strtolower',$header);
        $map = array(); foreach ( $header as $i => $h ) $map[strtolower($h)] = $i;
        
        // Detect table type by headers
        if ( in_array( 'order_id', $lower ) ) {
            // Orders table
            $table = $wpdb->prefix . 'ss_orders';
            while ( ($row = fgetcsv( $handle )) !== false ) {
                $order_id = isset( $map['order_id'] ) ? $row[$map['order_id']] : '';
                if ( empty( $order_id ) ) { $skipped++; continue; }
                $user_id = isset( $map['user_id'] ) ? $row[$map['user_id']] : '';
                $team_id = isset( $map['team_id'] ) ? intval( $row[$map['team_id']] ) : 0;
                $order_data = isset( $map['order_data'] ) ? $row[$map['order_data']] : '{}';
                $sync_status = isset( $map['sync_status'] ) ? $row[$map['sync_status']] : 'synced';
                $created_at = isset( $map['created_at'] ) ? $row[$map['created_at']] : current_time('mysql');
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_id = %s", $order_id ) );
                if ( $existing ) {
                    if ( $update_existing ) {
                        $res = $wpdb->update( $table, array( 'user_id'=>$user_id,'team_id'=>$team_id,'order_data'=>$order_data,'sync_status'=>$sync_status,'updated_at'=>current_time('mysql') ), array( 'order_id'=>$order_id ), array('%s','%d','%s','%s','%s'), array('%s') );
                        if ( $res !== false ) $updated++; else $skipped++;
                    } else { $skipped++; }
                } else {
                    $ins = $wpdb->insert( $table, array( 'order_id'=>$order_id,'user_id'=>$user_id,'team_id'=>$team_id,'order_data'=>$order_data,'sync_status'=>$sync_status,'created_at'=>$created_at ), array('%s','%s','%d','%s','%s','%s') );
                    if ( $ins !== false ) $imported++; else $skipped++;
                }
            }
        } else if ( in_array( 'team_name', $lower ) ) {
            // Teams table
            $table = $wpdb->prefix . 'ss_teams';
            while ( ($row = fgetcsv( $handle )) !== false ) {
                $team_name = isset( $map['team_name'] ) ? $row[$map['team_name']] : '';
                if ( empty( $team_name ) ) { $skipped++; continue; }
                $access_code = isset( $map['access_code'] ) ? $row[$map['access_code']] : '';
                $created_at = isset( $map['created_at'] ) ? $row[$map['created_at']] : current_time('mysql');
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE team_name = %s", $team_name ) );
                if ( $existing ) {
                    if ( $update_existing ) {
                        $res = $wpdb->update( $table, array( 'access_code'=>$access_code ), array( 'team_name'=>$team_name ), array('%s'), array('%s') );
                        if ( $res !== false ) $updated++; else $skipped++;
                    } else { $skipped++; }
                } else {
                    $ins = $wpdb->insert( $table, array( 'team_name'=>$team_name,'access_code'=>$access_code,'created_at'=>$created_at ), array('%s','%s','%s') );
                    if ( $ins !== false ) $imported++; else $skipped++;
                }
            }
        } else if ( in_array( 'phone', $lower ) && in_array( 'name', $lower ) ) {
            // Team members table
            $members_table = $wpdb->prefix . 'ss_team_members';
            $user_teams_table = $wpdb->prefix . 'ss_user_teams';
            while ( ($row = fgetcsv( $handle )) !== false ) {
                $name = isset( $map['name'] ) ? $row[$map['name']] : '';
                $phone = isset( $map['phone'] ) ? $row[$map['phone']] : '';
                if ( empty( $name ) || empty( $phone ) ) { $skipped++; continue; }
                $email = isset( $map['email'] ) ? $row[$map['email']] : '';
                $team_ids = isset( $map['team_ids'] ) ? $row[$map['team_ids']] : '';
                $created_at = isset( $map['created_at'] ) ? $row[$map['created_at']] : current_time('mysql');
                
                // Check if member exists by phone (unique)
                $existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$members_table} WHERE phone = %s", $phone ) );
                if ( $existing_id ) {
                    if ( $update_existing ) {
                        $res = $wpdb->update( $members_table, array( 'name'=>$name,'email'=>$email ), array( 'id'=>$existing_id ), array('%s','%s'), array('%d') );
                        if ( $res !== false ) $updated++; else $skipped++;
                        $user_id = $existing_id;
                    } else {
                        $skipped++;
                        continue;
                    }
                } else {
                    $ins = $wpdb->insert( $members_table, array( 'name'=>$name,'phone'=>$phone,'email'=>$email,'created_at'=>$created_at ), array('%s','%s','%s','%s') );
                    if ( $ins !== false ) {
                        $imported++;
                        $user_id = $wpdb->insert_id;
                    } else {
                        $skipped++;
                        continue;
                    }
                }
                
                // Handle team assignments (team_ids is comma-separated list)
                if ( ! empty( $team_ids ) && $user_id ) {
                    $wpdb->query( $wpdb->prepare( "DELETE FROM {$user_teams_table} WHERE user_id = %d", $user_id ) );
                    $team_ids_array = explode( ',', $team_ids );
                    foreach ( $team_ids_array as $tid ) {
                        $tid = intval( trim( $tid ) );
                        if ( $tid > 0 ) {
                            $wpdb->insert( $user_teams_table, array( 'user_id'=>$user_id,'team_id'=>$tid ), array('%d','%d') );
                        }
                    }
                }
            }
        } else if ( in_array( 'address', $lower ) && in_array( 'zip', $lower ) ) {
            // Addresses table
            $table = $wpdb->prefix . 'ss_addresses';
            while ( ($row = fgetcsv( $handle )) !== false ) {
                $address = isset( $map['address'] ) ? $row[$map['address']] : '';
                $city = isset( $map['city'] ) ? $row[$map['city']] : 'Southington';
                $state = isset( $map['state'] ) ? $row[$map['state']] : 'CT';
                $zip = isset( $map['zip'] ) ? $row[$map['zip']] : '';
                if ( empty( $address ) || empty( $zip ) ) { $skipped++; continue; }
                
                // Parse street/house_number from address (simple split)
                $street = $address;
                $house_number = '';
                if ( preg_match( '/^(\d+[A-Za-z]?)\s+(.+)$/', $address, $matches ) ) {
                    $house_number = $matches[1];
                    $street = $matches[2];
                }
                
                $unit = isset( $map['unit'] ) ? $row[$map['unit']] : '';
                $lat = isset( $map['lat'] ) ? $row[$map['lat']] : null;
                $lng = isset( $map['lng'] ) ? $row[$map['lng']] : null;
                $source = isset( $map['source'] ) ? $row[$map['source']] : 'csv';
                $type = isset( $map['type'] ) ? $row[$map['type']] : 'residential';
                $created_at = isset( $map['created_at'] ) ? $row[$map['created_at']] : current_time('mysql');
                
                // Auto-geocode if coordinates are missing
                if ( ( empty( $lat ) || empty( $lng ) ) && function_exists( 'order_sync_geocode_address' ) ) {
                    $full_address = trim( $address . ', ' . $city . ', ' . $state . ' ' . $zip );
                    $coords = order_sync_geocode_address( $full_address );
                    if ( $coords && isset( $coords['lat'] ) && isset( $coords['lng'] ) ) {
                        $lat = $coords['lat'];
                        $lng = $coords['lng'];
                        if ( $source === 'csv' ) $source = 'geocoded';
                        $geocoded++;
                    }
                }
                
                // Validate ZIP against coordinates (if we have both)
                $original_zip = $zip;
                if ( ! empty( $lat ) && ! empty( $lng ) && function_exists( 'order_sync_reverse_geocode' ) ) {
                    $geocoded_zip = order_sync_reverse_geocode( $lat, $lng );
                    if ( $geocoded_zip && $geocoded_zip !== $zip ) {
                        // ZIP mismatch - use coordinate-based ZIP as authoritative
                        $zip = $geocoded_zip;
                        if ( strpos( $source, 'corrected' ) === false ) {
                            $source = $source . '_zip_corrected';
                        }
                        $zip_corrected++;
                    }
                }
                
                // Check if address exists (by street, house_number, zip)
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE street = %s AND house_number = %s AND zip = %s", $street, $house_number, $zip ) );
                if ( $existing ) {
                    if ( $update_existing ) {
                        $res = $wpdb->update( $table, array( 'city'=>$city,'state'=>$state,'lat'=>$lat,'lng'=>$lng,'source'=>$source,'unit'=>$unit,'type'=>$type,'full_address'=>$address ), array( 'id'=>$existing ), array('%s','%s','%s','%s','%s','%s','%s','%s'), array('%d') );
                        if ( $res !== false ) $updated++; else $skipped++;
                    } else { $skipped++; }
                } else {
                    $ins = $wpdb->insert( $table, array( 'street'=>$street,'house_number'=>$house_number,'unit'=>$unit,'city'=>$city,'state'=>$state,'zip'=>$zip,'lat'=>$lat,'lng'=>$lng,'source'=>$source,'type'=>$type,'full_address'=>$address,'created_at'=>$created_at ), array('%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s') );
                    if ( $ins !== false ) $imported++; else $skipped++;
                }
            }
        } else if ( in_array( 'option_key', $lower ) || in_array( 'key', $lower ) ) {
            // Settings (options)
            while ( ($row = fgetcsv( $handle )) !== false ) {
                $key = isset( $map['option_key'] ) ? $row[$map['option_key']] : ( isset($map['key']) ? $row[$map['key']] : '' );
                $val = isset( $map['option_value'] ) ? $row[$map['option_value']] : ( isset($map['value']) ? $row[$map['value']] : '' );
                if ( empty( $key ) ) { $skipped++; continue; }
                $maybe = json_decode( $val, true );
                if ( json_last_error() === JSON_ERROR_NONE ) $val = $maybe;
                update_option( $key, $val );
                $imported++;
            }
        } else {
            fclose($handle); return array( 'imported'=>0,'updated'=>0,'skipped'=>0,'errors'=>array('unknownformat'),'geocoded'=>0,'zip_corrected'=>0 );
        }

        fclose( $handle );
        return array( 'imported'=>$imported,'updated'=>$updated,'skipped'=>$skipped,'errors'=>$errors,'geocoded'=>$geocoded,'zip_corrected'=>$zip_corrected );
    };

    // If the uploaded file is a zip, extract and process contained CSVs
    $is_zip = false;
    if ( class_exists( 'ZipArchive' ) ) {
        $za = new ZipArchive();
        if ( $za->open( $tmp ) === true ) { $is_zip = true; $za->close(); }
    }

    if ( $is_zip ) {
        $tmpdir = wp_tempnam();
        if ( ! $tmpdir ) $tmpdir = sys_get_temp_dir() . '/' . uniqid('subsales_');
        if ( ! file_exists( $tmpdir ) ) wp_mkdir_p( $tmpdir );
        $za = new ZipArchive();
        if ( $za->open( $tmp ) === true ) {
            for ( $i = 0; $i < $za->numFiles; $i++ ) {
                $name = $za->getNameIndex( $i );
                if ( preg_match('/\.(csv)$/i', $name) ) {
                    $outpath = $tmpdir . '/' . basename( $name );
                    copy( 'zip://' . $tmp . '#' . $name, $outpath );
                    $res = $process_csv( $outpath, $update_existing );
                    $total_imported += $res['imported'];
                    $total_updated += $res['updated'];
                    $total_skipped += $res['skipped'];
                    $geocoded_count += isset( $res['geocoded'] ) ? $res['geocoded'] : 0;
                    $zip_corrections += isset( $res['zip_corrected'] ) ? $res['zip_corrected'] : 0;
                    if ( ! empty( $res['errors'] ) ) $total_errors = array_merge( $total_errors, $res['errors'] );
                }
            }
            $za->close();
        }
    } else {
        $res = $process_csv( $tmp, $update_existing );
        $total_imported += $res['imported'];
        $total_updated += $res['updated'];
        $total_skipped += $res['skipped'];
        $geocoded_count += isset( $res['geocoded'] ) ? $res['geocoded'] : 0;
        $zip_corrections += isset( $res['zip_corrected'] ) ? $res['zip_corrected'] : 0;
        if ( ! empty( $res['errors'] ) ) $total_errors = array_merge( $total_errors, $res['errors'] );
    }

    return array( 'imported'=>$total_imported, 'updated'=>$total_updated, 'skipped'=>$total_skipped, 'errors'=>$total_errors, 'geocoded'=>$geocoded_count, 'zip_corrected'=>$zip_corrections );
}

// Admin-post handler: destructive restore (clear plugin data then import uploaded CSV/ZIP)
add_action( 'admin_post_subsales_restore_and_import', 'order_sync_admin_restore_and_import' );
function order_sync_admin_restore_and_import() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_restore_nonce' );
    if ( ! isset( $_FILES['backup_file'] ) || ! is_uploaded_file( $_FILES['backup_file']['tmp_name'] ) ) {
        wp_redirect( add_query_arg( 'subsales_import_error', 'nofile', wp_get_referer() ) ); exit;
    }

    // Determine clear scope: data, settings, or both (default: both)
    $restore_target = isset( $_POST['restore_target'] ) ? sanitize_text_field( $_POST['restore_target'] ) : 'both';
    if ( $restore_target === 'both' ) {
        if ( function_exists( 'order_sync_clear_data' ) ) order_sync_clear_data();
    } else if ( $restore_target === 'data' ) {
        if ( function_exists( 'order_sync_clear_orders' ) ) order_sync_clear_orders();
    } else if ( $restore_target === 'settings' ) {
        if ( function_exists( 'order_sync_clear_settings' ) ) order_sync_clear_settings();
    } else {
        // Fallback to full clear when unknown value provided
        if ( function_exists( 'order_sync_clear_data' ) ) order_sync_clear_data();
    }

    $tmp = $_FILES['backup_file']['tmp_name'];
    // For restore we always want to import everything; update_existing doesn't make sense after clear
    $result = order_sync_process_import_file( $tmp, true );

    $msg = sprintf( 'Restored: Imported=%d, Updated=%d, Skipped=%d', $result['imported'], $result['updated'], $result['skipped'] );
    // Suppress onboarding modal on the immediate redirect after a restore
    set_transient( 'subsales_suppress_onboarding', true, 30 );
    wp_redirect( add_query_arg( 'subsales_import_result', rawurlencode($msg), wp_get_referer() ) ); exit;
}

// AJAX endpoint to validate a Google Maps API key from admin UI
add_action( 'wp_ajax_subsales_test_maps_key', 'order_sync_test_maps_key_ajax' );
function order_sync_test_maps_key_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'subsales_test_maps_key', 'nonce' );

    $key = isset( $_POST['key'] ) ? sanitize_text_field( $_POST['key'] ) : '';
    if ( empty( $key ) ) {
        // fall back to stored option
        $key = get_option( 'order_sync_google_maps_api_key', '' );
    }
    if ( empty( $key ) ) {
        wp_send_json_error( 'No API key provided' );
    }

    // Perform a lightweight Geocoding API request to validate the key
    $test_address = '1600 Amphitheatre Parkway, Mountain View, CA';
    $url = add_query_arg( array( 'address' => urlencode( $test_address ), 'key' => $key ), 'https://maps.googleapis.com/maps/api/geocode/json' );
    // Use wp_remote_get with a short timeout
    $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $resp ) ) {
        wp_send_json_error( 'Request error: ' . $resp->get_error_message() );
    }
    $code = wp_remote_retrieve_response_code( $resp );
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    if ( ! is_array( $json ) ) {
        wp_send_json_error( 'Invalid response from Google Maps API (HTTP ' . $code . ')' );
    }
    // Interpret response
    $status = isset( $json['status'] ) ? $json['status'] : 'UNKNOWN';
    $message = '';
    if ( isset( $json['error_message'] ) ) $message = $json['error_message'];
    if ( $status === 'OK' || $status === 'ZERO_RESULTS' ) {
        $first = isset( $json['results'][0]['formatted_address'] ) ? $json['results'][0]['formatted_address'] : '';
        wp_send_json_success( array( 'status' => $status, 'message' => $message, 'first_result' => $first, 'raw' => $json ) );
    }
    // Common errors: REQUEST_DENIED (invalid key/permissions), OVER_QUERY_LIMIT, etc.
    wp_send_json_error( array( 'status' => $status, 'message' => $message, 'raw' => $json ) );
}
// Permission callback
function order_sync_check_permissions( WP_REST_Request $request ) {
    global $wpdb;
    
    if ( strpos( $request->get_route(), '/config' ) !== false ) {
        $team_name = $request->get_header( 'X-Team-Name' );
        $access_code = $request->get_header( 'X-Access-Code' );
        
        if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
            $team = order_sync_get_team_by_credentials( $team_name, $access_code );
            if ( $team ) {
                return true;
            }
        }
    }
    
    // Legacy auth: X-Team-Name + X-Access-Code
    $team_name = $request->get_header( 'X-Team-Name' );
    $access_code = $request->get_header( 'X-Access-Code' );
    
    if ( ! empty( $team_name ) || ! empty( $access_code ) ) {
        $team = order_sync_get_team_by_credentials( $team_name, $access_code );
        if ( $team ) {
            error_log( 'Subsales: perm_check team creds ok id=' . ( isset($team['id']) ? $team['id'] : 'unknown' ) );
            return true;
        }
        error_log( 'Subsales: perm_check invalid team credentials provided (team=' . $team_name . ')' );
        return false;
    }
    
    // User-based auth: X-User-ID + X-Team-ID (Phase 4)
    $user_id = $request->get_header( 'X-User-ID' );
    $team_id = $request->get_header( 'X-Team-ID' );
    
    if ( ! empty( $user_id ) && ! empty( $team_id ) ) {
        $members_table = $wpdb->prefix . 'ss_team_members';
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        
        // Verify user exists
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            intval( $user_id )
        ), ARRAY_A );
        
        if ( ! $user ) {
            error_log( 'Subsales: perm_check invalid user_id=' . $user_id );
            return false;
        }
        
        // Verify user belongs to the team
        $assignment = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
            intval( $user_id ),
            intval( $team_id )
        ));
        
        if ( $assignment ) {
            error_log( 'Subsales: perm_check user-based auth ok user_id=' . $user_id . ' team_id=' . $team_id );
            return true;
        }
        
        error_log( 'Subsales: perm_check user not in team user_id=' . $user_id . ' team_id=' . $team_id );
        return false;
    }
    
    // Legacy: X-Team-Email + X-Team-ID
    $team_email = $request->get_header( 'X-Team-Email' );
    $team_id = $request->get_header( 'X-Team-ID' );
    
    if ( ! empty( $team_email ) && ! empty( $team_id ) ) {
        $member = order_sync_verify_team_member( $team_email, $team_id );
        if ( $member ) {
            error_log( 'Subsales: perm_check team member ok id=' . ( isset($member['id']) ? $member['id'] : 'unknown' ) );
            return true;
        }
    }
    
    return current_user_can( 'edit_posts' );
}

// Admin-only permission callback for sensitive operations (edit, delete, restore, history)
function order_sync_check_admin_permissions( WP_REST_Request $request ) {
    // Check if user is logged into WordPress admin with edit permissions
    if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
        return true;
    }
    
    // Optionally support API key authentication for admin operations
    $api_key = $request->get_header( 'X-API-Key' );
    $stored_key = get_option( 'order_sync_api_key', '' );
    
    if ( ! empty( $api_key ) && ! empty( $stored_key ) && hash_equals( $stored_key, $api_key ) ) {
        return true;
    }
    
    return false;
}

// Orders REST callbacks (get_orders, get_order_by_id, create_order, update_order, delete_order)
/**
 * Order REST API Handler Functions (Backward Compatibility Wrappers)
 * All order functionality now handled by Subsales_Orders class
 */

function get_orders( $request ) {
    return Subsales_Orders::get_orders( $request );
}

function get_order_by_id( $request ) {
    return Subsales_Orders::get_order_by_id( $request );
}

function create_order( $request ) {
    return Subsales_Orders::create_order( $request );
}

function update_order( $request ) {
    return Subsales_Orders::update_order( $request );
}

function delete_order( $request ) {
    return Subsales_Orders::delete_order( $request );
}

function get_order_history( $request ) {
    return Subsales_Orders::get_order_history( $request );
}

function restore_order( $request ) {
    return Subsales_Orders::restore_order( $request );
}

function tally_orders( $request ) {
    return Subsales_Orders::tally_orders( $request );
}

// Server time endpoint: returns current date and timestamp in site timezone plus GMT offset
function order_manager_get_server_time( WP_REST_Request $request ) {
    $ts = current_time( 'timestamp' );
    $date = date( 'Y-m-d', $ts );
    $gmt_offset = floatval( get_option( 'gmt_offset', 0 ) );
    return new WP_REST_Response( array( 'server_date' => $date, 'server_timestamp' => $ts, 'gmt_offset' => $gmt_offset ), 200 );
}

/**
 * Teams & User Management Functions (Backward Compatibility Wrappers)
 * All team/user functionality now handled by Subsales_Teams class
 */

function team_member_login( WP_REST_Request $request ) {
    return Subsales_Teams::team_member_login( $request );
}

function verify_team_access( WP_REST_Request $request ) {
    return Subsales_Teams::verify_team_access( $request );
}

function order_sync_get_team_members_endpoint( WP_REST_Request $request ) {
    return Subsales_Teams::get_team_members_endpoint( $request );
}

function order_sync_create_user( WP_REST_Request $request ) {
    return Subsales_Teams::create_user( $request );
}

function order_sync_get_users( WP_REST_Request $request ) {
    return Subsales_Teams::get_users( $request );
}

function order_sync_get_user_by_id( WP_REST_Request $request ) {
    return Subsales_Teams::get_user_by_id( $request );
}

function order_sync_update_user( WP_REST_Request $request ) {
    return Subsales_Teams::update_user( $request );
}

function order_sync_delete_user( WP_REST_Request $request ) {
    return Subsales_Teams::delete_user( $request );
}

function order_sync_search_users( WP_REST_Request $request ) {
    return Subsales_Teams::search_users( $request );
}

function order_sync_get_user_teams( WP_REST_Request $request ) {
    return Subsales_Teams::get_user_teams( $request );
}

function order_sync_assign_user_to_team( WP_REST_Request $request ) {
    return Subsales_Teams::assign_user_to_team( $request );
}

function order_sync_remove_user_from_team( WP_REST_Request $request ) {
    return Subsales_Teams::remove_user_from_team( $request );
}

function order_sync_get_team_users( WP_REST_Request $request ) {
    return Subsales_Teams::get_team_users( $request );
}

/**
 * Get application configuration
 * Returns config including login mode, sales mode, branding, and sensitive data (for authenticated requests)
 * 
 * @param WP_REST_Request $request REST request
 * @return WP_REST_Response Configuration object
 */
function get_app_config( WP_REST_Request $request ) {
    // Only expose sensitive values (like Google Maps key) when the request includes valid team headers
    $is_authenticated = false;
    try{
        $is_authenticated = order_sync_check_permissions( $request ) === true;
    }catch(Exception $e){ $is_authenticated = false; }

    $google_maps_api_key = $is_authenticated ? get_option( 'order_sync_google_maps_api_key', '' ) : '';
    $portal_slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    $portal_url = esc_url_raw( home_url( '/' . $portal_slug . '/' ) );

    $branding = get_option( 'subsales_branding', 'Subsales' );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

    $products = order_sync_get_products_config();
    
    // Get login mode - ALWAYS return this (not sensitive)
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    
    // Get sales mode (Team vs Individual) - ALWAYS return this (not sensitive)
    $sales_mode = get_option( 'subsales_sales_mode', 'legacy' );
    
    // Get debug logging status - ALWAYS return this (not sensitive, just a boolean)
    // This allows PWA to know whether to send logs before authentication
    $debug_logging_enabled = get_option( 'subsales_debug_logging_enabled', false );

    return new WP_REST_Response( array(
        'google_maps_api_key' => $google_maps_api_key,
        'app_version' => SUBSALES_VERSION,
        'portal_url' => $portal_url,
        'brandName' => $branding,
        'brandingImage' => $header_image_url,
        'styleVariant' => get_option( 'order_sync_style_variant', 'default' ),
        'primaryColor' => get_option( 'order_sync_primary_color', '#2d6cdf' ),
        'sessionDuration' => intval( get_option( 'order_sync_session_duration', 86400000 ) ),
        'authenticated' => $is_authenticated,
        'products' => $products,
        'loginMode' => $login_mode,
        'salesMode' => $sales_mode,
        'debugLoggingEnabled' => $debug_logging_enabled
    ), 200 );
}

/**
 * PWA Functions (Backward Compatibility Wrappers)
    
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    
    // Legacy mode: Team + Access Code
    if ( $login_mode === 'legacy' ) {
        if ( ! isset( $data['team_name'] ) || ! isset( $data['access_code'] ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Missing team name or access code'
            ), 400 );
        }
        
        $team_name = sanitize_text_field( $data['team_name'] );
        $access_code = sanitize_text_field( $data['access_code'] );
        
        $team = order_sync_get_team_by_credentials( $team_name, $access_code );
        
        if ( $team ) {
            // Log successful legacy login
            subsales_log_auth( 'login', null, $team_name, array(
                'mode' => 'legacy',
                'team_id' => $team['id']
            ), 'pwa' );
            
            return new WP_REST_Response( array(
                'success' => true,
                'mode' => 'legacy',
                'team' => array(
                    'id' => $team['id'],
                    'name' => $team['name'],
                    'access_code' => $team['access_code']
                ),
                'message' => 'Team login successful'
            ), 200 );
        }
        
        // Log failed legacy login
        subsales_log_auth( 'failed', null, $team_name, array(
            'mode' => 'legacy',
            'reason' => 'invalid_credentials'
        ), 'pwa' );
        
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Invalid team name or access code'
        ), 401 );
    }
    
    // User-based mode: Name + Phone + optional Team ID
    if ( $login_mode === 'user' ) {
        if ( ! isset( $data['name'] ) || ! isset( $data['phone'] ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Missing name or phone number'
            ), 400 );
        }
        
        $name = sanitize_text_field( $data['name'] );
        $phone = preg_replace( '/[^0-9]/', '', sanitize_text_field( $data['phone'] ) );
        $team_id = isset( $data['team_id'] ) ? intval( $data['team_id'] ) : 0;
        
        if ( ! preg_match( '/^[0-9]{10}$/', $phone ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Phone number must be 10 digits'
            ), 400 );
        }
        
        // Find user by phone
        $members_table = $wpdb->prefix . 'ss_team_members';
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE phone = %s",
            $phone
        ), ARRAY_A );
        
        if ( ! $user ) {
            // Log failed user login - phone not found
            subsales_log_auth( 'failed', null, '', array(
                'mode' => 'user',
                'phone' => substr( $phone, 0, 3 ) . 'XXXXXXX', // Partial phone for privacy
                'reason' => 'invalid_phone'
            ), 'pwa' );
            
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid phone number'
            ), 401 );
        }
        
        // Verify name matches (case-insensitive partial match)
        if ( stripos( $user['name'], $name ) === false && stripos( $name, $user['name'] ) === false ) {
            // Log failed user login - name mismatch
            subsales_log_auth( 'failed', $user['id'], $user['name'], array(
                'mode' => 'user',
                'reason' => 'name_mismatch',
                'provided_name' => $name
            ), 'pwa' );
            
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Name does not match'
            ), 401 );
        }
        
        // Get user's teams
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $teams_table = $wpdb->prefix . 'ss_teams';
        
        $team_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT team_id FROM {$user_teams_table} WHERE user_id = %d",
            $user['id']
        ));
        
        if ( empty( $team_ids ) ) {
            // Log failed user login - no teams
            subsales_log_auth( 'failed', $user['id'], $user['name'], array(
                'mode' => 'user',
                'reason' => 'no_teams_assigned'
            ), 'pwa' );
            
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'User is not assigned to any teams'
            ), 403 );
        }
        
        $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        $teams = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, name, access_code FROM {$teams_table} WHERE id IN ({$placeholders})",
                $team_ids
            ),
            ARRAY_A
        );
        
        // If team_id provided, verify user belongs to it
        $selected_team = null;
        if ( $team_id > 0 ) {
            foreach ( $teams as $team ) {
                if ( $team['id'] == $team_id ) {
                    $selected_team = $team;
                    break;
                }
            }
            
            if ( ! $selected_team ) {
                // Log failed user login - wrong team
                subsales_log_auth( 'failed', $user['id'], $user['name'], array(
                    'mode' => 'user',
                    'reason' => 'invalid_team',
                    'requested_team_id' => $team_id
                ), 'pwa' );
                
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'User does not belong to the selected team'
                ), 403 );
            }
        }
        
        // Log successful user login
        subsales_log_auth( 'login', $user['id'], $user['name'], array(
            'mode' => 'user',
            'team_count' => count( $teams ),
            'selected_team_id' => $selected_team ? $selected_team['id'] : null
        ), 'pwa' );
        
        return new WP_REST_Response( array(
            'success' => true,
            'mode' => 'user',
            'user' => array(
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $user['role']
            ),
            'teams' => $teams,
            'selected_team' => $selected_team,
            'message' => 'User login successful'
        ), 200 );
    }
    
    return new WP_REST_Response( array(
        'success' => false,
        'message' => 'Invalid login mode configuration'
    ), 500 );
}

/**
 * PWA Functions (Backward Compatibility Wrappers)
 * All PWA functionality now handled by Subsales_PWA class
 */

// Wrapper functions maintained for backward compatibility
// These are intentionally left empty as the PWA class registers hooks directly
// during Subsales_PWA::init() which is called early in plugin bootstrap.
// The add_action and add_shortcode calls below are also removed since they're
// now handled by the class.

function subsales_register_pwa_scripts() {
    // Deprecated: Now handled by Subsales_PWA::register_pwa_scripts() via class init hook
    // This wrapper kept for backward compatibility in case external code calls it directly
    Subsales_PWA::register_pwa_scripts();
}
// Note: add_action() removed - now handled by Subsales_PWA::init()

function subsales_pwa_shortcode( $atts = array() ) {
    // Deprecated: Now handled by Subsales_PWA::pwa_shortcode() via class init shortcode
    // This wrapper kept for backward compatibility in case external code calls it directly
    return Subsales_PWA::pwa_shortcode( $atts );
}
// Note: add_shortcode() removed - now handled by Subsales_PWA::init()

function order_sync_ensure_pwa_page( $slug = 'subsales-portal' ) {
    return Subsales_PWA::ensure_pwa_page( $slug );
}

// Clear only orders/teams/members/addresses tables (preserves options)
function order_sync_clear_orders() {
    global $wpdb;
    $tables = array(
        $wpdb->prefix . 'ss_orders',
        $wpdb->prefix . 'ss_teams',
        $wpdb->prefix . 'ss_team_members',
        $wpdb->prefix . 'ss_user_teams',
        $wpdb->prefix . 'ss_addresses'
    );

    foreach ( $tables as $table ) {
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
        if ( $exists ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }
    }
}

// Clear only plugin options/settings (preserves orders/tables)
function order_sync_clear_settings() {
    // Remove persisted plugin options so the plugin is fully reset
    $option_keys = array(
        'order_sync_pwa_page_id',
        'order_sync_google_maps_api_key',
        'order_sync_interval',
        'order_sync_portal_slug',
        'order_sync_products',
        'order_sync_session_duration',
        'order_sync_style_variant',
        'order_sync_primary_color',
        'order_sync_login_mode',
        'subsales_branding',
        'subsales_delete_on_uninstall',
        'subsales_header_image',
        'subsales_served_zipcodes'
    );

    foreach ( $option_keys as $ok ) {
        delete_option( $ok );
    }
}

// Backwards-compatible helper that clears both orders and settings
function order_sync_clear_data() {
    order_sync_clear_orders();
    order_sync_clear_settings();
}

function subsales_uninstall() {
    $delete = get_option( 'subsales_delete_on_uninstall', 0 );
    if ( ! $delete ) return;

    global $wpdb;
    $tables = array(
        $wpdb->prefix . 'ss_orders',
        $wpdb->prefix . 'ss_teams',
        $wpdb->prefix . 'ss_team_members',
        $wpdb->prefix . 'ss_user_teams',
        $wpdb->prefix . 'ss_addresses'
    );

    foreach ( $tables as $table ) {
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
        if ( $exists ) $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
    }

    delete_option( 'order_sync_google_maps_api_key' );
    delete_option( 'order_sync_interval' );
    delete_option( 'order_sync_portal_slug' );
    delete_option( 'order_sync_pwa_page_id' );
    delete_option( 'subsales_delete_on_uninstall' );
}
register_uninstall_hook( __FILE__, 'subsales_uninstall' );

/**
 * Get plugin version from plugin header (preferred) or fallback to SUBSALES_VERSION.
 * Uses get_file_data when available; falls back to parsing the file header.
 */
function order_sync_get_plugin_version() {
    $file = __FILE__;
    // Try WP helper if available
    if ( ! function_exists( 'get_file_data' ) ) {
        if ( file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
    if ( function_exists( 'get_file_data' ) ) {
        $data = get_file_data( $file, array( 'Version' => 'Version' ) );
        if ( ! empty( $data['Version'] ) ) return $data['Version'];
    }

    // Fallback: parse header comment for Version: line
    $contents = @file_get_contents( $file );
    if ( $contents ) {
        if ( preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $m ) ) {
            return trim( $m[1] );
        }
    }

    if ( defined( 'SUBSALES_VERSION' ) ) return SUBSALES_VERSION;
    return '';
}

// Serve portal assets from plugin folder at portal path
add_action( 'template_redirect', 'subsales_serve_portal_assets', 1 );

// REST endpoint: nearby addresses by lat/lng + radius (meters)
add_action( 'rest_api_init', function(){
    register_rest_route( 'subsales/v1', '/nearby', array(
        'methods' => 'GET',
        'callback' => 'subsales_rest_nearby',
        'permission_callback' => '__return_true',
    ) );
} );

function subsales_rest_nearby( WP_REST_Request $req ){
    $lat = floatval( $req->get_param('lat') );
    $lng = floatval( $req->get_param('lng') );
    $radius = intval( $req->get_param('r') ?: $req->get_param('radius') ?: 500 ); // meters
    $max = intval( $req->get_param('max') ?: 50 );

    if( !$lat || !$lng ){
        return new WP_REST_Response( array('error'=>'missing lat/lng'), 400 );
    }

    $upload = wp_upload_dir();
    $base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
    if ( ! is_dir( $base ) ) return rest_ensure_response( array('results'=>array()) );

    // bounding box approximation
    $degLat = $radius / 111320.0; // ~ meters per degree lat
    $degLng = $radius / (111320.0 * max(0.000001, cos( deg2rad( $lat ) )) );
    $minLat = $lat - $degLat; $maxLat = $lat + $degLat;
    $minLng = $lng - $degLng; $maxLng = $lng + $degLng;

    $files = glob( $base . '/*.json' );
    $out = array();

    foreach( $files as $f ){
        if( !is_readable($f) ) continue;
        $json = @file_get_contents($f);
        if( !$json ) continue;
        $arr = json_decode($json, true);
        if( !is_array($arr) ) continue;
        foreach( $arr as $rec ){
            if( !isset($rec['lat']) || !isset($rec['lng']) ) continue;
            $rlat = floatval($rec['lat']); $rlng = floatval($rec['lng']);
            if( $rlat < $minLat || $rlat > $maxLat || $rlng < $minLng || $rlng > $maxLng ) continue;
            // compute haversine distance
            $d = subsales_haversine_distance( $lat, $lng, $rlat, $rlng );
            if( $d <= $radius ){
                $rec['_distance_m'] = round($d);
                $out[] = $rec;
                if( count($out) >= $max ) break 2;
            }
        }
    }

    // sort by distance
    usort( $out, function($a,$b){ return ($a['_distance_m'] ?? 0) - ($b['_distance_m'] ?? 0); } );

    return rest_ensure_response( array('results'=>$out) );
}

function subsales_haversine_distance( $lat1, $lon1, $lat2, $lon2 ){
    // returns distance in meters
    $R = 6371000; // earth radius meters
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlambda = deg2rad($lon2 - $lon1);
    $a = sin($dphi/2) * sin($dphi/2) + cos($phi1) * cos($phi2) * sin($dlambda/2) * sin($dlambda/2);
    $c = 2 * atan2( sqrt($a), sqrt(1-$a) );
    return $R * $c;
}
function subsales_serve_portal_assets() {
    $portal_slug = get_option( 'order_sync_portal_slug', '' ); if ( empty( $portal_slug ) ) return;
    $req_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    $portal_base = trim( parse_url( home_url( '/' . $portal_slug . '/' ), PHP_URL_PATH ), '/' );

    // Also serve manifest at the site root (/manifest.json) to handle cases where the browser requests it from /
    if ( $req_path === 'manifest.json' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/manifest.json';
        if ( file_exists( $file ) ) {
            $manifest_raw = file_get_contents( $file );
            $manifest = json_decode( $manifest_raw, true );
            if ( is_array( $manifest ) && isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ) {
                $plugin_base = SUBSALES_PLUGIN_URL . 'pwa/';
                foreach ( $manifest['icons'] as $i => $icon ) {
                    if ( isset( $icon['src'] ) ) {
                        if ( strpos( $icon['src'], '//' ) === false && strpos( $icon['src'], 'http' ) !== 0 ) {
                            $manifest['icons'][ $i ]['src'] = $plugin_base . ltrim( $icon['src'], '/' );
                        }
                    }
                }
            }
            header( 'Content-Type: application/json' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            http_response_code(200);
            echo wp_json_encode( $manifest );
            exit;
        }
    }

    if ( $req_path === $portal_base . '/service-worker.js' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/service-worker.js';
        if ( file_exists( $file ) ) {
            // Serve publicly with permissive caching and CORS so browsers can register the SW without auth issues
            header( 'Content-Type: application/javascript' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            readfile( $file );
            exit;
        }
    }

    if ( $req_path === $portal_base || $req_path === $portal_base . '/index.html' || $req_path === $portal_base . '/' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/index.html';
        if ( file_exists( $file ) ) {
            $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
            $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

            $settings = array(
                'apiBase' => esc_url_raw( rest_url( 'order-manager/v1' ) ),
                'pluginBase' => SUBSALES_PLUGIN_URL . 'pwa/',
                'portalBase' => esc_url_raw( home_url( '/' . get_option( 'order_sync_portal_slug', 'subsales-portal' ) . '/' ) ),
                'googleMapsApiKey' => get_option( 'order_sync_google_maps_api_key', '' ),
                'brandName' => get_option( 'subsales_branding', 'Subsales' ),
                'brandingImage' => $header_image_url
            );
            // Include configured products so portal bootstraps with current product list
            $settings['products'] = order_sync_get_products_config();

            $html = file_get_contents( $file );
            $inject = "<script>window.SUBSALES_PWA_CONFIG = " . wp_json_encode( $settings ) . ";</script>";
            $app_src = esc_url( $settings['pluginBase'] . 'app.js' );
            // Rewrite relative stylesheet hrefs to absolute plugin path to avoid portal-relative 404s
            $html = str_replace( 'href="styles.css"', 'href="' . esc_url( $settings['pluginBase'] . 'styles.css' ) . '"', $html );
            $new_html = str_replace( '<script src="app.js"></script>', $inject . "\n<script src=\"" . $app_src . "\"></script>", $html );
            // If replacement didn't find the exact marker (different spacing/paths), inject config before </head>
            if ( $new_html === $html ) {
                $pos = stripos( $html, '</head>' );
                if ( $pos !== false ) {
                    $new_html = substr_replace( $html, $inject . "\n<script src=\"" . $app_src . "\"></script>\n", $pos, 0 );
                } else {
                    // fallback: prepend to document
                    $new_html = $inject . "\n<script src=\"" . $app_src . "\"></script>\n" . $html;
                }
            }
            $html = $new_html;
            // Serve index publicly so the portal can be loaded without authentication
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Cache-Control: public, max-age=300' );
            header( 'Access-Control-Allow-Origin: *' );
            echo $html; exit;
        }
    }

    if ( $req_path === $portal_base . '/manifest.json' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/manifest.json';
        if ( file_exists( $file ) ) {
            // Read and rewrite icon URLs to absolute plugin paths so browsers fetch icons directly
            $manifest_raw = file_get_contents( $file );
            $manifest = json_decode( $manifest_raw, true );
            if ( is_array( $manifest ) && isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ) {
                $plugin_base = SUBSALES_PLUGIN_URL . 'pwa/';
                foreach ( $manifest['icons'] as $i => $icon ) {
                    if ( isset( $icon['src'] ) ) {
                        // If src is already absolute, leave it alone
                        if ( strpos( $icon['src'], '//' ) === false && strpos( $icon['src'], 'http' ) !== 0 ) {
                            $manifest['icons'][ $i ]['src'] = $plugin_base . ltrim( $icon['src'], '/' );
                        }
                    }
                }
            }
            header( 'Content-Type: application/json' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            // Explicit 200 in case other hooks attempted auth handling
            http_response_code(200);
            echo wp_json_encode( $manifest );
            exit;
        }
    }
    
    // Serve any other portal assets (icons, styles, images, etc.) from the pwa/ folder
    // More permissive: match any request containing '/icons/...'
    if ( preg_match('#/icons/(.+)$#', '/' . $req_path, $m) ) {
        $rel = 'icons/' . $m[1];
        $file = SUBSALES_PLUGIN_PATH . 'pwa/' . $rel;
        if ( file_exists( $file ) ) {
            $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
            switch ( $ext ) {
                case 'svg': $ct = 'image/svg+xml'; break;
                case 'png': $ct = 'image/png'; break;
                case 'jpg':
                case 'jpeg': $ct = 'image/jpeg'; break;
                case 'webp': $ct = 'image/webp'; break;
                case 'ico': $ct = 'image/x-icon'; break;
                case 'css': $ct = 'text/css'; break;
                case 'js': $ct = 'application/javascript'; break;
                case 'json': $ct = 'application/json'; break;
                default: $ct = 'application/octet-stream';
            }
            header( 'Content-Type: ' . $ct );
            header( 'Cache-Control: public, max-age=86400' );
            header( 'Access-Control-Allow-Origin: *' );
            readfile( $file );
            exit;
        }
    }
}

// Ensure geocode cache table exists (lightweight, called on demand)
function order_sync_ensure_geocode_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'order_sync_geocodes';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        address_hash varchar(64) NOT NULL,
        address_normalized text NOT NULL,
        lat double DEFAULT NULL,
        lng double DEFAULT NULL,
        status varchar(32) DEFAULT 'unknown',
        updated_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY address_hash (address_hash(64))
    ) {$charset};";
    $wpdb->query( $sql );
}

function order_sync_normalize_address( $addr ) {
    if ( ! $addr ) return '';
    $s = trim( preg_replace('/\s+/', ' ', str_replace( array("\n","\r"), ' ', $addr ) ) );
    $s = strtolower( $s );
    return $s;
}

function order_sync_geocode_address( $address ) {
    global $wpdb;
    $address_norm = order_sync_normalize_address( $address );
    $hash = md5( $address_norm );
    $table = $wpdb->prefix . 'order_sync_geocodes';

    // ensure table exists
    order_sync_ensure_geocode_table();

    // check cache
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE address_hash = %s LIMIT 1", $hash ), ARRAY_A );
    if ( $row && ! empty( $row['lat'] ) && ! empty( $row['lng'] ) && isset( $row['status'] ) && $row['status'] === 'OK' ) {
        return array( 'lat' => floatval( $row['lat'] ), 'lng' => floatval( $row['lng'] ), 'cached' => true );
    }

    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    if ( empty( $api_key ) ) {
        // store unknown status and return null
        $now = current_time( 'mysql' );
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'status' => 'no_key', 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'status' => 'no_key', 'updated_at' => $now ), array( '%s', '%s', '%s', '%s' ) );
        }
        return null;
    }

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode( $address ) . '&key=' . rawurlencode( $api_key );
    $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $resp ) ) return null;
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    $now = current_time( 'mysql' );
    if ( ! is_array( $json ) || ! isset( $json['status'] ) ) {
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'status' => 'error', 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'status' => 'error', 'updated_at' => $now ), array( '%s', '%s', '%s', '%s' ) );
        }
        return null;
    }
    if ( isset( $json['status'] ) && $json['status'] === 'OK' && ! empty( $json['results'][0]['geometry']['location'] ) ) {
        $loc = $json['results'][0]['geometry']['location'];
        $lat = floatval( $loc['lat'] );
        $lng = floatval( $loc['lng'] );
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'lat' => $lat, 'lng' => $lng, 'status' => 'OK', 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%f', '%f', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'lat' => $lat, 'lng' => $lng, 'status' => 'OK', 'updated_at' => $now ), array( '%s', '%s', '%f', '%f', '%s', '%s' ) );
        }
        return array( 'lat' => $lat, 'lng' => $lng, 'cached' => false );
    } else {
        $status = isset( $json['status'] ) ? $json['status'] : 'error';
        if ( $row ) {
            $wpdb->update( $table, array( 'address_normalized' => $address_norm, 'status' => $status, 'updated_at' => $now ), array( 'id' => intval( $row['id'] ) ), array( '%s', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'address_hash' => $hash, 'address_normalized' => $address_norm, 'status' => $status, 'updated_at' => $now ), array( '%s', '%s', '%s', '%s' ) );
        }
        return null;
    }
}

// Reverse geocode: Get ZIP code from lat/lng coordinates
function order_sync_reverse_geocode( $lat, $lng ) {
    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    if ( empty( $api_key ) ) return null;

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?latlng=' . floatval($lat) . ',' . floatval($lng) . '&key=' . rawurlencode( $api_key );
    $resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $resp ) ) return null;
    
    $body = wp_remote_retrieve_body( $resp );
    $json = json_decode( $body, true );
    
    if ( ! is_array( $json ) || ! isset( $json['status'] ) || $json['status'] !== 'OK' ) {
        return null;
    }
    
    // Extract ZIP code from address components
    if ( ! empty( $json['results'][0]['address_components'] ) ) {
        foreach ( $json['results'][0]['address_components'] as $component ) {
            if ( in_array( 'postal_code', $component['types'] ) ) {
                return $component['short_name'];
            }
        }
    }
    
    return null;
}

// Address Extracts admin page
function subsales_address_extracts_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have sufficient permissions to access this page.' );
    }
    
    global $wpdb;
    $addresses_table = $wpdb->prefix . 'ss_addresses';
    
    // Handle Overpass matching request
    $matching_result = null;
    if ( isset( $_POST['run_overpass_matching'] ) && check_admin_referer( 'subsales_overpass_matching' ) ) {
        $limit = isset( $_POST['match_limit'] ) ? intval( $_POST['match_limit'] ) : 100;
        $matching_result = Subsales_Overpass_Matcher::match_addresses( $limit );
    }
    
    // Get database statistics
    $total_addresses = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table}" );
    $residential_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE type = 'residential'" );
    $commercial_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE type = 'commercial'" );
    $matched_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE matched = 1" );
    $high_confidence_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE confidence = 'high'" );
    
    // Get ZIP breakdown
    $zip_breakdown = $wpdb->get_results(
        "SELECT zip, COUNT(*) as count FROM {$addresses_table} GROUP BY zip ORDER BY count DESC",
        ARRAY_A
    );
    
    // Get source breakdown
    $source_breakdown = $wpdb->get_results(
        "SELECT source, COUNT(*) as count FROM {$addresses_table} GROUP BY source ORDER BY count DESC",
        ARRAY_A
    );
    
    ?>
    <div class="wrap">
        <h1>Address Extracts</h1>
        <p class="description">Upload shapefiles or CSV files to populate the address database for PWA autocomplete.</p>
        
        <!-- Overpass Matching Results -->
        <?php if ( $matching_result ) : ?>
            <div class="notice notice-<?php echo $matching_result['success'] ? 'success' : 'error'; ?>" style="margin-top: 20px;">
                <p><strong><?php echo esc_html( $matching_result['message'] ); ?></strong></p>
                <?php if ( $matching_result['success'] && $matching_result['total'] > 0 ) : ?>
                    <ul style="margin: 10px 0;">
                        <li>✅ Matched: <?php echo number_format( $matching_result['matched'] ); ?></li>
                        <li>❌ Failed: <?php echo number_format( $matching_result['failed'] ); ?></li>
                        <li>📊 Success Rate: <?php echo round( ( $matching_result['matched'] / $matching_result['total'] ) * 100 ); ?>%</li>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Overpass Matching Tool -->
        <div class="subsales-card" style="margin-top: 20px; padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107;">
            <h2 style="margin-top: 0;">🗺️ Overpass Address Matching</h2>
            <p class="description">Cross-reference addresses with OpenStreetMap to assign verified ZIP codes and improve coordinates.</p>
            
            <?php
            $stats = Subsales_Overpass_Matcher::get_statistics();
            $unmatched = $stats['total'] - $stats['matched'];
            ?>
            
            <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin: 15px 0;">
                <h3 style="margin-top: 0;">Current Status:</h3>
                <table class="form-table">
                    <tr>
                        <th>Total Addresses:</th>
                        <td><strong><?php echo number_format( $stats['total'] ); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Matched to OSM:</th>
                        <td><?php echo number_format( $stats['matched'] ); ?> (<?php echo $stats['total'] > 0 ? round( ( $stats['matched'] / $stats['total'] ) * 100 ) : 0; ?>%)</td>
                    </tr>
                    <tr>
                        <th>Unmatched:</th>
                        <td><strong style="color: #d63638;"><?php echo number_format( $unmatched ); ?></strong></td>
                    </tr>
                    <tr>
                        <th>High Confidence:</th>
                        <td><?php echo number_format( $stats['high_confidence'] ); ?></td>
                    </tr>
                    <tr>
                        <th>Medium Confidence:</th>
                        <td><?php echo number_format( $stats['medium_confidence'] ); ?></td>
                    </tr>
                    <tr>
                        <th>With ZIP Codes:</th>
                        <td><?php echo number_format( $stats['with_zip'] ); ?> (<?php echo $stats['total'] > 0 ? round( ( $stats['with_zip'] / $stats['total'] ) * 100 ) : 0; ?>%)</td>
                    </tr>
                </table>
            </div>
            
            <?php if ( $unmatched > 0 ) : ?>
                <form method="post" action="" style="margin-top: 15px;">
                    <?php wp_nonce_field( 'subsales_overpass_matching' ); ?>
                    <p>
                        <label for="match_limit"><strong>Number of addresses to match:</strong></label><br>
                        <input type="number" id="match_limit" name="match_limit" value="100" min="1" max="1000" style="width: 100px;" />
                        <span class="description">(Recommended: 100-500 per batch. Rate limit: 2 seconds per address.)</span>
                    </p>
                    <p>
                        <button type="submit" name="run_overpass_matching" class="button button-primary button-large">
                            🚀 Start Matching (<?php echo min( 100, $unmatched ); ?> addresses)
                        </button>
                        <span class="description" style="margin-left: 15px; color: #666;">
                            ⏱️ Estimated time: ~<?php echo ceil( min( 100, $unmatched ) * 2 / 60 ); ?> minutes
                        </span>
                    </p>
                    <p class="description" style="color: #856404;">
                        <strong>Note:</strong> This queries OpenStreetMap's Overpass API. Please be patient and respect rate limits.
                        The process may take several minutes depending on batch size.
                    </p>
                </form>
            <?php else : ?>
                <p style="color: #28a745; font-weight: bold;">✅ All addresses have been matched!</p>
            <?php endif; ?>
        </div>
        
        <!-- Data Summary -->
        <div class="subsales-dashboard-grid" style="margin-top: 20px;">
            <div class="subsales-card">
                <h2>📊 Database Summary</h2>
                <table class="form-table">
                    <tr>
                        <th>Total Addresses:</th>
                        <td><strong><?php echo number_format( $total_addresses ); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Residential:</th>
                        <td><?php echo number_format( $residential_count ); ?> (<?php echo $total_addresses > 0 ? round( ( $residential_count / $total_addresses ) * 100 ) : 0; ?>%)</td>
                    </tr>
                    <tr>
                        <th>Commercial:</th>
                        <td><?php echo number_format( $commercial_count ); ?> (<?php echo $total_addresses > 0 ? round( ( $commercial_count / $total_addresses ) * 100 ) : 0; ?>%)</td>
                    </tr>
                    <tr>
                        <th>With GPS Coordinates:</th>
                        <td><?php echo number_format( $total_addresses ); ?> (100%)</td>
                    </tr>
                    <tr>
                        <th>Matched to Overpass:</th>
                        <td><?php echo number_format( $matched_count ); ?> (<?php echo $total_addresses > 0 ? round( ( $matched_count / $total_addresses ) * 100 ) : 0; ?>%)</td>
                    </tr>
                    <tr>
                        <th>High Confidence:</th>
                        <td><?php echo number_format( $high_confidence_count ); ?> (<?php echo $total_addresses > 0 ? round( ( $high_confidence_count / $total_addresses ) * 100 ) : 0; ?>%)</td>
                    </tr>
                    <tr>
                        <th>Last Updated:</th>
                        <td><?php echo date( 'M j, Y g:i A' ); ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="subsales-card">
                <h2>📍 ZIP Code Breakdown</h2>
                <?php if ( ! empty( $zip_breakdown ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>ZIP Code</th>
                                <th style="text-align: right;">Addresses</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $zip_breakdown as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $row['zip'] ); ?></td>
                                    <td style="text-align: right;"><?php echo number_format( $row['count'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p style="color: #666; font-style: italic;">No ZIP data yet.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="subsales-dashboard-grid" style="margin-top: 20px;">
            <div class="subsales-card">
                <h2>📦 Data Sources</h2>
                <?php if ( ! empty( $source_breakdown ) ) : ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th style="text-align: right;">Addresses</th>
                                <th style="text-align: right;">Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $source_breakdown as $row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( ucfirst( $row['source'] ) ); ?></td>
                                    <td style="text-align: right;"><?php echo number_format( $row['count'] ); ?></td>
                                    <td style="text-align: right;"><?php echo $total_addresses > 0 ? round( ( $row['count'] / $total_addresses ) * 100 ) : 0; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <p style="color: #666; font-style: italic;">No data sources yet.</p>
                <?php endif; ?>
            </div>
            
            <div class="subsales-card">
                <h2>📤 Upload Address Data</h2>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'subsales_upload_address_file' ); ?>
                    
                    <p>
                        <label for="address_file"><strong>Select File:</strong></label><br>
                        <input type="file" name="address_file" id="address_file" accept=".zip,.csv" required style="margin-top: 8px;" />
                    </p>
                    
                    <p class="description">
                        <strong>Supported formats:</strong><br>
                        • <strong>.zip</strong> - Shapefile archive (must contain .shp, .dbf, .prj files)<br>
                        • <strong>.csv</strong> - Address list with columns: street, city, state, zip, lat, lng
                    </p>
                    
                    <p>
                        <button type="submit" name="upload_file" class="button button-primary">
                            📤 Upload and Process
                        </button>
                    </p>
                </form>
                
                <hr style="margin: 20px 0;">
                
                <h3>Actions</h3>
                <p>
                    <button class="button" disabled title="Coming in Phase 7">🔄 Regenerate JSON Files</button>
                    <button class="button" disabled title="Coming in Phase 9">📥 Export All Addresses</button>
                    <button class="button button-link-delete" disabled title="Coming in Phase 9">🗑️ Clear Address Database</button>
                </p>
            </div>
        </div>
        
        <!-- Processing Status (shown when file is being processed) -->
        <?php if ( ! empty( $processing_status ) ) : ?>
            <div class="subsales-card" style="margin-top: 20px; background: #f0f6fc; border-left: 4px solid #2271b1;">
                <h2>⚙️ Processing Status</h2>
                <div id="processing-status">
                    <p><strong>File Type:</strong> <?php echo esc_html( ucfirst( $processing_status['file_type'] ) ); ?></p>
                    <p><strong>Status:</strong> <?php echo esc_html( ucfirst( $processing_status['status'] ) ); ?></p>
                    
                    <div class="progress-bar" style="background: #e0e0e0; height: 20px; border-radius: 10px; overflow: hidden; margin: 10px 0;">
                        <div class="progress-fill" style="background: #2271b1; height: 100%; width: 10%; transition: width 0.3s;"></div>
                    </div>
                    
                    <p class="description">Processing is not yet implemented. This will be available in Phase 3 (Shapefile) and Phase 8 (CSV).</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <style>
    .subsales-dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 20px;
    }
    .subsales-card {
        background: #fff;
        border: 1px solid #c3c4c7;
        border-radius: 4px;
        padding: 20px;
    }
    .subsales-card h2 {
        margin-top: 0;
        padding-bottom: 10px;
        border-bottom: 1px solid #e0e0e0;
    }
    .subsales-card .form-table th {
        padding: 10px 0;
        width: 60%;
    }
    .subsales-card .form-table td {
        padding: 10px 0;
    }
    </style>
    <?php
}

// Admin Delivery page: single-day delivery exports and preview
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
                        <p class="description">Create a CSV export you can open in a spreadsheet to design your own routes. This export contains one row per normalized address and per-product columns.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Delivery date (optional)</th>
                    <td><input type="date" name="delivery_date" value="" /></td>
                </tr>
            </table>
            <p class="submit"><button class="button">Generate Administrative CSV</button></p>
        </form>

        <!-- Driver manifests workflow: individual-based routing and PDF generation -->
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
                    <th scope="row">Delivery date (optional)</th>
                    <td><input type="date" name="delivery_date" id="sdm_delivery_date" value="" />
                    <p class="description">Leave blank to include all orders</p></td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary">Generate Individual Manifests (PDF)</button>
            </p>
        </form>

        <div id="subsales_preview_modal" style="display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rg