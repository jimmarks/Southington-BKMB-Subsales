<?php
/**
 * Plugin Name: Subsales Management
 * Plugin URI: https://github.com/jimmarks/Southington-BKMB-Subsales
 * Description: A comprehensive order management system for mobile app synchronization with WordPress backend. Includes multi-team management, Google Maps integration, and professional admin interface. ⚠️ WARNING: By default, deleting this plugin will permanently remove ALL data. Configure deletion settings in BKMB Subsales → Settings.
 * Version: 3.2.1
 * Author: Jim Marks
 * Author URI: https://github.com/jimmarks
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 8.0
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: subsales-management
 * Domain Path: /languages
 * Network: false
 * 
 * ============================================================
 * DEVELOPMENT STATE - Last Updated: 2026-01-06
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
if ( ! defined( 'SUBSALES_VERSION' ) ) define( 'SUBSALES_VERSION', '3.2.1' );
if ( ! defined( 'SUBSALES_PLUGIN_URL' ) ) define( 'SUBSALES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_PATH' ) ) define( 'SUBSALES_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_BASENAME' ) ) define( 'SUBSALES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---- Auto-updates via GitHub Releases (Plugin Update Checker) ----
// Southington-BKMB-Subsales is a PUBLIC repo, so no auth token is needed here
// (contrast with private-repo setups, which define a token constant in
// wp-config.php - never in plugin source). Releases are built by
// .github/workflows/release.yml on every "vX.Y.Z" tag push.
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$subsalesUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/jimmarks/Southington-BKMB-Subsales/',
    __FILE__,
    'subsales-management'
);
$subsalesUpdateChecker->getVcsApi()->enableReleaseAssets();
$subsalesUpdateChecker->setBranch( 'main' );

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
require_once SUBSALES_PLUGIN_PATH . 'includes/class-signups.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-driver-signup.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-admin-pages.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-ajax-handlers.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-zip-boundary.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-delivery.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-backup-restore.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-address-helper.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-order-helper.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-display-helper.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-points-calculator.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-square-payments.php';
require_once SUBSALES_PLUGIN_PATH . 'includes/class-payment-attempts.php';
// Season setup wizard. Must be loaded here, not only from the Settings partials -
// admin-ajax.php never loads a Settings page, so the wizard's AJAX handlers would
// otherwise never be registered.
require_once SUBSALES_PLUGIN_PATH . 'includes/class-season-setup.php';

// Initialize database
Subsales_Database::init();

// Initialize background matcher

// Initialize REST API
Subsales_REST_API::init();

// Initialize PWA
Subsales_PWA::init();

// Initialize Orders
Subsales_Orders::init();

// Initialize Delivery
Subsales_Delivery::init();

// Initialize Signups (campaigns & registration)
Subsales_Signups::init();

// Initialize Driver Signups (parent/driver self-registration)
Subsales_Driver_Signup::init();

// Initialize Admin Pages
Subsales_Admin_Pages::init();

// Initialize AJAX Handlers
Subsales_AJAX_Handlers::init();

// Initialize Census Boundaries

// Initialize Payment Attempts (hooks expire_stale_attempts onto the existing
// hourly cleanup action; Subsales_Square_Payments has no hooks of its own)
Subsales_Payment_Attempts::init();


// Activation/Deactivation hooks
register_activation_hook( __FILE__, 'subsales_activate' );
register_deactivation_hook( __FILE__, 'subsales_deactivate' );

/**
 * Run schema migrations after a plugin UPDATE, not just on activation.
 *
 * register_activation_hook() does NOT fire when a plugin is updated in place,
 * so any table added in a new version simply never got created on an existing
 * site - the admin had to know to deactivate and reactivate. That is exactly
 * how 3.2.0 shipped without wp_ss_address_review_queue: ingestion ran, found
 * addresses it couldn't place, and had nowhere to put them.
 *
 * create_tables() is idempotent (INFORMATION_SCHEMA checks + dbDelta), so
 * running it again whenever the stored version differs is safe and cheap.
 */
add_action( 'admin_init', 'subsales_maybe_upgrade_db' );
function subsales_maybe_upgrade_db() {
    if ( get_option( 'subsales_db_version' ) === SUBSALES_VERSION ) {
        return;
    }

    Subsales_Database::create_tables();
    update_option( 'subsales_db_version', SUBSALES_VERSION );

    if ( function_exists( 'subsales_log' ) ) {
        subsales_log( 'INFO', 'system', 'Database schema checked after version change', array(
            'version' => SUBSALES_VERSION,
        ) );
    }
}

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
        array( 'Subsales_Admin_Pages', 'render_main_dashboard' ),  // Function
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
        array( 'Subsales_Admin_Pages', 'render_settings_page' )
    );
    
    add_submenu_page(
        'subsales-management',
        'Teams Management',
        'Teams',
        'manage_options',
        'subsales-teams',
        'ss_teams_page'  // TODO: Extract to Subsales_Admin_Pages::render_teams_page()
    );
    
    add_submenu_page(
        'subsales-management',
        'Orders',
        'Orders',
        'manage_options',
        'subsales-orders',
        array( 'Subsales_Admin_Pages', 'render_orders_page' )
    );
    
    // Reports - Index page listing all reports
    add_submenu_page(
        'subsales-management',
        'Reports',
        'Reports',
        'manage_options',
        'subsales-reports',
        array( 'Subsales_Admin_Pages', 'render_reports_page' )
    );
    
    // Points Report - Hidden page (accessed via Reports index)
    add_submenu_page(
        null,
        'Points Report',
        'Points Report',
        'manage_options',
        'subsales-team-sales-report',
        array( 'Subsales_Admin_Pages', 'render_team_sales_report' )
    );
    
    // Address Coverage Report - Hidden page (accessed via Reports index)
    add_submenu_page(
        null,
        'Address Coverage Report',
        'Address Coverage Report',
        'manage_options',
        'subsales-address-coverage',
        'subsales_address_coverage_page'
    );
    
    // GPS Proximity Search - Hidden page (accessed via Reports index)
    add_submenu_page(
        null,
        'GPS Proximity Search',
        'GPS Proximity Search',
        'manage_options',
        'subsales-gps-proximity',
        'subsales_gps_proximity_page'
    );
    
    // Order Entry Distance - Hidden page (accessed via Reports index)
    add_submenu_page(
        null,
        'Order Entry Distance Analysis',
        'Order Entry Distance',
        'manage_options',
        'subsales-order-entry-distance',
        'subsales_order_entry_distance_page'
    );

    add_submenu_page(
        'subsales-management',
        'Delivery',
        'Delivery',
        'manage_options',
        'subsales-delivery',
        array( 'Subsales_Admin_Pages', 'render_delivery_page' )
    );
    
    // Campaign Dates is no longer a standalone item - it is the "Sales Days"
    // tab of the Seasons page (admin/seasons-page.php).

    add_submenu_page(
        'subsales-management',
        'Seasons',
        'Seasons',
        'manage_options',
        'subsales-seasons',
        array( 'Subsales_Admin_Pages', 'render_seasons_page' )
    );

    // The standalone "Address Extracts" menu is consolidated under
    // Settings → Address Management.

    // Logs also hosts the App Sessions tab, so it carries the live session badge.
    $active_pwa_count = count( Subsales_Database::get_active_pwa_sessions( 50 ) );
    $logs_menu_title  = $active_pwa_count > 0
        ? sprintf( 'Logs <span class="update-plugins count-%d"><span class="plugin-count">%d</span></span>', $active_pwa_count, $active_pwa_count )
        : 'Logs';

    add_submenu_page(
        'subsales-management',
        'System Logs',
        $logs_menu_title,
        'manage_options',
        'subsales-logs',
        'subsales_logs_page'
    );

    // Hidden submenu: Delivery distribution breakdown (accessed from delivery page)
    add_submenu_page(
        null,  // No parent - hidden from menu
        'Delivery Distribution Breakdown',
        'Distribution Breakdown',
        'manage_options',
        'subsales-delivery-breakdown',
        'subsales_delivery_breakdown_page'
    );

    // App Sessions is no longer a standalone item - it is the "App Sessions"
    // tab of the Logs page (subsales_logs_page()).
}

// AJAX handlers for address search and openaddresses (kept here due to complex logic)
add_action( 'wp_ajax_subsales_search_address', 'subsales_search_address_preview' );

// AJAX handlers for campaign management
add_action( 'wp_ajax_subsales_toggle_campaign', 'subsales_ajax_toggle_campaign' );
add_action( 'wp_ajax_subsales_delete_campaign', 'subsales_ajax_delete_campaign' );
add_action( 'wp_ajax_subsales_get_campaign_signups', 'subsales_ajax_get_campaign_signups' );
add_action( 'wp_ajax_subsales_get_campaign_counts', 'subsales_ajax_get_campaign_counts' );
add_action( 'wp_ajax_subsales_add_signup', 'subsales_ajax_add_signup' );
add_action( 'wp_ajax_subsales_remove_signup', 'subsales_ajax_remove_signup' );
add_action( 'wp_ajax_subsales_update_team_driver', 'subsales_ajax_update_team_driver' );
add_action( 'wp_ajax_subsales_search_members', 'subsales_ajax_search_members' );
add_action( 'wp_ajax_subsales_create_user_quick', 'subsales_ajax_create_user_quick' );

// NOTE: subsales_toggle_debug, subsales_get_active_sessions_count,
// subsales_refresh_zip_index and subsales_update_sales_mode are registered in
// Subsales_AJAX_Handlers::init().

// Import/Export handlers for users and teams
add_action( 'admin_post_subsales_export_users_teams', 'subsales_export_users_teams' );
add_action( 'admin_post_subsales_import_users_teams', 'subsales_import_users_teams' );

// NOTE: these handlers live in the Subsales_AJAX_Handlers class:
// - subsales_get_active_sessions_count_ajax()
// - subsales_toggle_debug_ajax()
// - subsales_refresh_zip_index_ajax()
// - subsales_update_sales_mode_ajax()


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

// NOTE: subsales_refresh_zip_index hook now registered in Subsales_AJAX_Handlers::init()

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

// Generate per-ZIP JSON extracts from the database (no API calls needed).
// This is the single producer of the PWA's {ZIP}.json files - keep it that way.
// Split out from the AJAX handler below so the ingestion pipeline can regenerate
// extracts in-process the moment it finishes writing addresses, instead of the
// browser having to fire a second request to do it.
// Returns array( 'ok' => bool, 'error' => string|null, 'results' => array, 'summary' => array ).
function subsales_generate_zip_extracts_core( $zips = null ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'ss_addresses';

    if ( $zips === null ) {
        $zips = subsales_get_served_zips();
    }
    if ( ! is_array( $zips ) || empty( $zips ) ) {
        return array(
            'ok'      => false,
            'error'   => 'No ZIPs configured. Please add ZIP codes in Settings → Overall → ZIP Codes first.',
            'results' => array(),
            'summary' => array(),
        );
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

    return array(
        'ok'      => true,
        'error'   => null,
        'results' => $results,
        'summary' => $log_entry['summary'],
    );
}

// AJAX handler wrapping the generator above.
add_action( 'wp_ajax_subsales_generate_zip_extracts', 'subsales_generate_zip_extracts' );
function subsales_generate_zip_extracts() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );
    check_ajax_referer( 'subsales_zip_generate', 'nonce' );

    $out = subsales_generate_zip_extracts_core();
    if ( empty( $out['ok'] ) ) {
        wp_send_json_error( $out['error'] );
    }
    wp_send_json_success( $out['results'] );
}

// Parse Census ZCTA shapefile and cache ZIP polygons

// Extract ZIP polygons from ZCTA shapefile for configured ZIPs only

// Parse ZCTA shapefile geometries (simplified polygon extraction)


// Border-based ZIP assignment (IMPROVED: Uses polygon matching when available, falls back to sampling)
// Checks for cached ZIP polygons first, uses precise point-in-polygon if available

// Get or build ZIP boundaries (bounding boxes for fast spatial queries)
function subsales_get_zip_boundaries( $zips ) {
    $boundary_data = get_option( 'subsales_zip_boundaries', array() );
    
    // Handle new format (with metadata) or legacy format (direct boundaries array)
    if ( isset( $boundary_data['boundaries'] ) ) {
        $boundaries = $boundary_data['boundaries'];
    } else {
        // Legacy format - just an array of boundaries
        $boundaries = $boundary_data;
    }
    
    // Filter to only requested ZIPs
    $result = array();
    foreach ( $zips as $zip ) {
        if ( isset( $boundaries[ $zip ] ) ) {
            $result[ $zip ] = $boundaries[ $zip ];
        }
    }
    
    return $result;
}

// Build ZIP boundaries from a sample of addresses (IMPROVED with randomization and validation)

// Check if a point is within bounding box

// Find nearest ZIP boundary center (fallback for edge cases)

// ===================================================================
// POLYGON-BASED ZIP MATCHING (Using Census ZCTA data)
// ===================================================================

// Find ZIP code by checking if point is inside any loaded polygon

// Check if a point is inside a polygon's bounding box (simplified for performance)
// Uses bounding box check - fast approximation suitable for ZIP codes

// Find nearest ZIP polygon center (fallback when point not in any polygon)

// ===================================================================
// END POLYGON-BASED ZIP MATCHING
// ===================================================================

// ===================================================================
// CT PARCEL INGESTION
// ===================================================================
//
// Pulls addresses straight from Connecticut's statewide parcel ArcGIS service
// (free, government-maintained) and assigns each one a ZIP by real
// point-in-polygon against Census ZCTA boundaries. Replaces the shapefile
// upload / Overpass / OpenAddresses sourcing paths.
//
// Two hard rules, both from the Buckland St data-corruption bug:
//   - A parcel whose ZIP isn't unambiguous goes to the review queue. Never a
//     default ZIP, never the nearest one, never the first one.
//   - Each ZIP is replaced wholesale, not merged, so a re-ingest can't leave
//     stale wrong-ZIP rows behind.

// CT statewide parcel layer. outSR=4326 makes the service hand back WGS84
// lat/lng directly, which is why this plugin has no reprojection code.
define( 'SUBSALES_PARCEL_SERVICE_URL', 'https://services3.arcgis.com/3FL1kr7L4LvwA2Kb/ArcGIS/rest/services/Connecticut_State_Parcel_Layer_2023/FeatureServer/0/query' );

// The service caps a page at 2000; 1000 keeps each response comfortably small.
define( 'SUBSALES_PARCEL_PAGE_SIZE', 1000 );

/**
 * Fetch one page of parcels for a town.
 *
 * @param string $town Town name as stored in Town_Name (upper case)
 * @param int $offset resultOffset
 * @param int $limit resultRecordCount
 * @return array|WP_Error Feature array, or WP_Error on failure
 */
function subsales_fetch_parcel_page( $town, $offset, $limit ) {
    $url = SUBSALES_PARCEL_SERVICE_URL . '?' . http_build_query( array(
        'where'             => "Town_Name='" . str_replace( "'", "''", $town ) . "'",
        'outFields'         => 'Town_Name,Location',
        'returnGeometry'    => 'true',
        'outSR'             => '4326',
        'f'                 => 'json',
        'resultOffset'      => intval( $offset ),
        'resultRecordCount' => intval( $limit ),
    ) );

    $response = wp_remote_get( $url, array( 'timeout' => 60 ) );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'parcel_request_failed', 'Parcel request failed: ' . $response->get_error_message() );
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( ! empty( $data['error'] ) ) {
        $message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'unknown error';
        return new WP_Error( 'parcel_service_error', 'Parcel service error: ' . $message );
    }

    if ( ! isset( $data['features'] ) || ! is_array( $data['features'] ) ) {
        return new WP_Error( 'parcel_bad_response', 'Parcel service returned no feature array.' );
    }

    return $data['features'];
}

/**
 * Write ingestion progress so the admin dashboard can poll it.
 *
 * @param string $status running|complete|error
 * @param int $percent 0-100
 * @param string $message Human-readable progress line
 * @return void
 */
function subsales_set_ingest_status( $status, $percent, $message ) {
    update_option( 'subsales_ingest_status', array(
        'status'   => $status,
        'percent'  => max( 0, min( 100, intval( $percent ) ) ),
        'message'  => $message,
        'complete' => ( 'running' !== $status ),
        'success'  => ( 'complete' === $status ),
        'updated'  => current_time( 'mysql' ),
    ), false );
}

/**
 * Ingest every parcel address for the configured town and file it under one of
 * the given ZIPs.
 *
 * @param array $zips ZIP codes to populate
 * @return array Summary: town, parcels, skipped, queued, zips (per-ZIP counts), errors
 * @since 3.2.0
 */
function subsales_ingest_zips( array $zips ) {
    global $wpdb;

    @set_time_limit( 0 );
    @ini_set( 'memory_limit', '512M' );

    $start_time = microtime( true );

    // One town covers all served ZIPs, so a single town name is enough.
    // ZIP -> town resolution is deliberately NOT built: add it only if the
    // fundraiser ever sells across a town line.
    $town = strtoupper( trim( get_option( 'subsales_parcel_town_name', 'SOUTHINGTON' ) ) );

    $summary = array(
        'town'       => $town,
        'parcels'    => 0,
        'skipped'    => 0,
        'duplicates' => 0,
        'queued'     => 0,
        'queue_failed' => 0,
        'retired'    => 0,
        'zips'       => array(),
        'errors'     => array(),
    );

    if ( empty( $zips ) ) {
        $summary['errors'][] = 'No ZIP codes supplied.';
        return $summary;
    }

    subsales_set_ingest_status( 'running', 2, 'Fetching ZIP boundaries...' );

    // Fetched fresh, held in memory only, never cached in wp_options.
    $boundaries = Subsales_Zip_Boundary::fetch_boundaries( $zips );

    if ( empty( $boundaries ) ) {
        $summary['errors'][] = 'Could not fetch ZIP boundaries. Nothing was changed.';
        subsales_set_ingest_status( 'error', 0, 'Could not fetch ZIP boundaries.' );
        subsales_log( 'ERROR', 'address', 'Parcel ingestion aborted: no ZIP boundaries', array( 'zips' => $zips ) );
        return $summary;
    }

    subsales_log( 'INFO', 'address', 'Parcel ingestion started', array(
        'town' => $town,
        'zips' => array_keys( $boundaries )
    ) );

    $by_zip = array();  // zip => rows ready for insert
    $seen = array();    // house|street keys already taken (condo collapse)
    $offset = 0;
    $fetch_failed = false;

    while ( true ) {
        subsales_set_ingest_status( 'running', min( 70, 5 + intval( $offset / 400 ) ),
            'Reading parcels ' . number_format( $offset ) . '+ for ' . $town . '...' );

        $features = subsales_fetch_parcel_page( $town, $offset, SUBSALES_PARCEL_PAGE_SIZE );

        if ( is_wp_error( $features ) ) {
            // A partial read must never reach the replace step - deleting a ZIP
            // and re-inserting half its parcels is worse than doing nothing.
            $fetch_failed = true;
            $summary['errors'][] = $features->get_error_message();
            subsales_log( 'ERROR', 'address', 'Parcel page fetch failed', array(
                'offset' => $offset,
                'error'  => $features->get_error_message()
            ) );
            break;
        }

        $page_count = count( $features );

        foreach ( $features as $feature ) {
            $summary['parcels']++;

            // Location is the property's own street address. Mailing_Address is
            // the OWNER's mailing address - frequently a different property or a
            // PO box - and must never be used here.
            $location = isset( $feature['attributes']['Location'] ) ? trim( $feature['attributes']['Location'] ) : '';
            $rings = isset( $feature['geometry']['rings'] ) ? $feature['geometry']['rings'] : array();

            if ( '' === $location || empty( $rings ) ) {
                $summary['skipped']++;
                continue;
            }

            $parsed = Subsales_Delivery::parse_address( $location );

            // parse_address() normalizes the suffix to the canonical abbreviation
            // (ST/DR/AV/...) which is what the PWA autocomplete expects to see.
            if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
                $summary['skipped']++;
                continue;
            }

            $centroid = Subsales_Zip_Boundary::centroid_of_rings( $rings );
            if ( ! $centroid ) {
                $summary['skipped']++;
                continue;
            }

            $zip = Subsales_Zip_Boundary::determine_zip( $centroid['lat'], $centroid['lng'], $boundaries );

            if ( null === $zip ) {
                // Zero matches or more than one. Queue it - never guess. A bad
                // parcel must not abort the run.
                $queued_ok = Subsales_Database::queue_address_for_review( array(
                    'reason'         => 'zip_undetermined',
                    'source_context' => 'ingestion',
                    'raw_address'    => $location,
                    'house_number'   => $parsed['house_number'],
                    'street'         => $parsed['street'],
                    'city'           => ucwords( strtolower( $town ) ),
                    'candidate_zips' => Subsales_Zip_Boundary::matching_zips( $centroid['lat'], $centroid['lng'], $boundaries ),
                    'lat'            => $centroid['lat'],
                    'lng'            => $centroid['lng'],
                ) );

                // Only count what actually landed. Reporting "238 sent to Needs
                // Review" while every insert silently failed (missing table) is
                // worse than reporting nothing.
                if ( $queued_ok ) {
                    $summary['queued']++;
                } else {
                    $summary['queue_failed']++;
                }
                continue;
            }

            // Collapse condo/multi-unit parcels to one base address. Units are
            // hand-typed by the seller into the PWA's Unit field and must never
            // be enumerated in autocomplete data. Done after ZIP resolution so an
            // unresolvable duplicate can't block the parcel that does resolve.
            $key = strtoupper( trim( $parsed['house_number'] ) ) . '|' . strtoupper( trim( $parsed['street'] ) );
            if ( isset( $seen[ $key ] ) ) {
                $summary['duplicates']++;
                continue;
            }
            $seen[ $key ] = true;

            $city = ucwords( strtolower( $town ) );

            $by_zip[ $zip ][] = array(
                'house_number' => $parsed['house_number'],
                'street'       => $parsed['street'],
                'unit'         => '',
                'city'         => $city,
                'state'        => 'CT',
                'zip'          => $zip,
                'lat'          => $centroid['lat'],
                'lng'          => $centroid['lng'],
                'source'       => 'parcel',
                'confidence'   => 'high',
                'type'         => 'residential',
                'full_address' => trim( $parsed['house_number'] . ' ' . $parsed['street'] ) . ', ' . $city . ', CT ' . $zip,
            );
        }

        if ( $page_count < SUBSALES_PARCEL_PAGE_SIZE ) {
            break; // Short page = last page.
        }

        $offset += SUBSALES_PARCEL_PAGE_SIZE;

        // Southington has ~18.4k parcels; this only trips if the service starts
        // ignoring resultOffset and paging forever.
        if ( $offset > 250000 ) {
            $fetch_failed = true;
            $summary['errors'][] = 'Pagination safety cap hit at ' . $offset . ' records.';
            break;
        }
    }

    if ( $fetch_failed ) {
        subsales_set_ingest_status( 'error', 0, 'Parcel read failed part-way through. No addresses were changed.' );
        subsales_log( 'ERROR', 'address', 'Parcel ingestion aborted before write', $summary );
        return $summary;
    }

    // Replace, don't merge - and do it per ZIP so one ZIP failing cannot wipe
    // another. Safe because ss_orders.address is free text, not a foreign key.
    $addresses_table = $wpdb->prefix . 'ss_addresses';
    $zip_total = max( 1, count( $by_zip ) );
    $zip_done = 0;

    foreach ( $by_zip as $zip => $rows ) {
        $zip_done++;
        subsales_set_ingest_status( 'running', 70 + intval( 25 * $zip_done / $zip_total ),
            'Writing ' . number_format( count( $rows ) ) . ' addresses for ' . $zip . '...' );

        // Replace only what the state records own. Anything a human added or
        // corrected by hand (source = 'manual') survives every re-ingest -
        // otherwise fixing an address would be undone the next time the ZIP was
        // refreshed, forever. The bulk insert below is INSERT IGNORE against the
        // (street, house_number, unit, zip) unique key, so a surviving manual row
        // also wins over the parcel row for the same address: the human
        // correction is the one that sticks.
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$addresses_table} WHERE zip = %s AND source <> 'manual'",
            $zip
        ) );

        if ( false === $deleted ) {
            $summary['errors'][] = 'ZIP ' . $zip . ': could not clear existing rows, left untouched.';
            subsales_log( 'ERROR', 'address', 'Parcel ingestion could not clear ZIP', array(
                'zip'   => $zip,
                'error' => $wpdb->last_error
            ) );
            continue;
        }

        $inserted = Subsales_Database::insert_addresses( $rows );

        $summary['zips'][ $zip ] = array(
            'resolved' => count( $rows ),
            'deleted'  => intval( $deleted ),
            'inserted' => intval( $inserted ),
        );
    }

    // Anything flagged on an earlier run that this run has now filed properly
    // (the classic case: the admin adds the ZIP that was missing and re-ingests)
    // stops counting as outstanding work. Runs after the writes above so it sees
    // the addresses this run just inserted.
    $summary['retired'] = Subsales_Database::retire_resolved_review_rows();

    // Refresh the PWA's zip-index.json. The per-ZIP JSON extracts are produced
    // by the unmodified subsales_generate_zip_extracts action - see the note in
    // subsales_ingest_zips_ajax().
    subsales_update_zip_index();

    $summary['duration'] = round( microtime( true ) - $start_time, 2 );

    // A failed queue write means those addresses were silently dropped - say so
    // loudly rather than reporting them as filed.
    if ( $summary['queue_failed'] > 0 ) {
        $summary['errors'][] = sprintf(
            '%s address(es) could not be saved to the review list. The review table may be missing - reload an admin page to run the schema check, then ingest again.',
            number_format( $summary['queue_failed'] )
        );
        subsales_log( 'ERROR', 'address', 'Review-queue writes failed during ingestion', array(
            'queue_failed' => $summary['queue_failed'],
        ) );
    }

    subsales_log( 'INFO', 'address', 'Parcel ingestion complete', $summary );

    $done_message = sprintf(
        '%s parcels read, %s addresses written, %s queued for review',
        number_format( $summary['parcels'] ),
        number_format( array_sum( wp_list_pluck( $summary['zips'], 'inserted' ) ) ),
        number_format( $summary['queued'] )
    );

    if ( $summary['retired'] > 0 ) {
        $done_message .= sprintf(
            ', %s previously flagged addresses are now resolved',
            number_format( $summary['retired'] )
        );
    }

    subsales_set_ingest_status(
        empty( $summary['errors'] ) ? 'complete' : 'error',
        100,
        $done_message
    );

    return $summary;
}

// AJAX handler to ingest parcel addresses for the configured ZIPs
add_action( 'wp_ajax_subsales_ingest_zips', 'subsales_ingest_zips_ajax' );
function subsales_ingest_zips_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_ingest_zips', 'nonce' );

    // Explicit ZIP list from the form, otherwise everything configured.
    $zips = array();
    if ( ! empty( $_POST['zips'] ) ) {
        $raw = is_array( $_POST['zips'] ) ? $_POST['zips'] : explode( ',', sanitize_text_field( wp_unslash( $_POST['zips'] ) ) );
        foreach ( $raw as $zip ) {
            if ( ! is_scalar( $zip ) ) {
                continue;
            }
            $zip = preg_replace( '/[^0-9]/', '', (string) $zip );
            if ( strlen( $zip ) === 5 ) {
                $zips[] = $zip;
            }
        }
        $zips = array_values( array_unique( $zips ) );
    }

    if ( empty( $zips ) ) {
        $zips = subsales_get_served_zips();
    }

    if ( empty( $zips ) ) {
        wp_send_json_error( 'No ZIPs configured. Add ZIP codes in Settings → Overall → ZIP Codes first.' );
    }

    // Optional town override, persisted so the next run reuses it. Letters,
    // spaces and hyphens only - it goes into the service's WHERE clause.
    if ( ! empty( $_POST['town'] ) ) {
        $town = preg_replace( '/[^A-Za-z \-]/', '', sanitize_text_field( wp_unslash( $_POST['town'] ) ) );
        if ( '' !== trim( $town ) ) {
            update_option( 'subsales_parcel_town_name', strtoupper( trim( $town ) ) );
        }
    }

    $summary = subsales_ingest_zips( $zips );

    if ( empty( $summary['zips'] ) ) {
        wp_send_json_error( array(
            'message' => 'Ingestion produced no addresses. ' . implode( ' ', $summary['errors'] ),
            'summary' => $summary,
        ) );
    }

    // Regenerate the PWA's per-ZIP JSON in the same request, so a successful
    // ingest always leaves the seller-facing data in sync with ss_addresses.
    // Only the ZIPs we just ingested are regenerated.
    $extracts = subsales_generate_zip_extracts_core( array_keys( $summary['zips'] ) );

    wp_send_json_success( array(
        'summary'  => $summary,
        'extracts' => $extracts,
    ) );
}

// AJAX handler to poll ingestion progress
add_action( 'wp_ajax_subsales_ingest_status', 'subsales_ingest_status_ajax' );
function subsales_ingest_status_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_ingest_zips', 'nonce' );

    wp_send_json_success( get_option( 'subsales_ingest_status', array(
        'status'   => 'idle',
        'percent'  => 0,
        'message'  => 'No ingestion in progress',
        'complete' => true,
        'success'  => false,
    ) ) );
}

// ===================================================================
// ADDRESS REVIEW QUEUE - AJAX
// ===================================================================
//
// Admin-paced. Nothing here runs on a timer and nothing is bulk.

// AJAX handler to resolve one review-queue row into the address database
add_action( 'wp_ajax_subsales_review_queue_resolve', 'subsales_review_queue_resolve_ajax' );
function subsales_review_queue_resolve_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_address_review', 'nonce' );

    $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
    if ( ! $id ) {
        wp_send_json_error( 'Missing review queue row id' );
    }

    $args = array();
    foreach ( array( 'house_number', 'street', 'city', 'zip', 'note' ) as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            $args[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
        }
    }
    if ( isset( $_POST['lat'] ) && '' !== $_POST['lat'] ) {
        $args['lat'] = floatval( $_POST['lat'] );
    }
    if ( isset( $_POST['lng'] ) && '' !== $_POST['lng'] ) {
        $args['lng'] = floatval( $_POST['lng'] );
    }

    $result = Subsales_Database::resolve_review_queue_row( $id, $args );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( array(
        'address_id'    => $result['address_id'],
        'pending_count' => Subsales_Database::count_review_queue_rows( 'pending' ),
    ) );
}

// AJAX handler to dismiss one review-queue row
add_action( 'wp_ajax_subsales_review_queue_dismiss', 'subsales_review_queue_dismiss_ajax' );
function subsales_review_queue_dismiss_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_address_review', 'nonce' );

    $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
    if ( ! $id ) {
        wp_send_json_error( 'Missing review queue row id' );
    }

    $note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';

    if ( ! Subsales_Database::dismiss_review_queue_row( $id, $note ) ) {
        wp_send_json_error( 'Could not dismiss that row' );
    }

    wp_send_json_success( array(
        'pending_count' => Subsales_Database::count_review_queue_rows( 'pending' ),
    ) );
}

// AJAX handler to geocode ONE review-queue row via Google.
//
// This is the only Google Geocoding call in the entire address pipeline: one
// row, one click, triggered by an admin. Never bulk, never automatic - Google
// pricing is a hard constraint for this fundraiser.
add_action( 'wp_ajax_subsales_review_queue_geocode', 'subsales_review_queue_geocode_ajax' );
function subsales_review_queue_geocode_ajax() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    check_ajax_referer( 'subsales_address_review', 'nonce' );

    $id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
    $row = $id ? Subsales_Database::get_review_queue_row( $id ) : null;

    if ( ! $row ) {
        wp_send_json_error( 'Review queue row not found' );
    }

    $query = trim( $row['house_number'] . ' ' . $row['street'] );
    if ( '' === $query ) {
        $query = $row['raw_address'];
    }
    $query .= ', ' . ( ! empty( $row['city'] ) ? $row['city'] : 'Southington' ) . ', CT';

    // Already transient/table-cached inside geocode_address().
    $geo = Subsales_Delivery::geocode_address( $query );

    if ( ! $geo ) {
        wp_send_json_error( 'Google could not geocode that address' );
    }

    // Coordinates only - the ZIP still comes from point-in-polygon, never from
    // Google's formatted string.
    $zip = null;
    $boundaries = Subsales_Zip_Boundary::fetch_boundaries( subsales_get_served_zips() );
    if ( ! empty( $boundaries ) ) {
        $zip = Subsales_Zip_Boundary::determine_zip( $geo['lat'], $geo['lng'], $boundaries );
    }

    wp_send_json_success( array(
        'lat'               => $geo['lat'],
        'lng'               => $geo['lng'],
        'formatted_address' => isset( $geo['formatted_address'] ) ? $geo['formatted_address'] : '',
        'zip'               => $zip,
    ) );
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

// REST API callback: serve PWA manifest dynamically
function subsales_serve_pwa_manifest( $request ) {
    $manifest_file = SUBSALES_PLUGIN_PATH . 'pwa/manifest.json';
    
    if ( ! file_exists( $manifest_file ) ) {
        return new WP_Error( 'manifest_not_found', 'Manifest file not found', array( 'status' => 404 ) );
    }
    
    // Read and parse manifest
    $manifest_raw = file_get_contents( $manifest_file );
    $manifest = json_decode( $manifest_raw, true );
    
    if ( ! is_array( $manifest ) ) {
        return new WP_Error( 'manifest_invalid', 'Invalid manifest JSON', array( 'status' => 500 ) );
    }
    
    // Rewrite icon URLs to absolute plugin paths
    if ( isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ) {
        $plugin_base = SUBSALES_PLUGIN_URL . 'pwa/';
        foreach ( $manifest['icons'] as $i => $icon ) {
            if ( isset( $icon['src'] ) ) {
                // If src is relative, make it absolute
                if ( strpos( $icon['src'], '//' ) === false && strpos( $icon['src'], 'http' ) !== 0 ) {
                    $manifest['icons'][ $i ]['src'] = $plugin_base . ltrim( $icon['src'], '/' );
                }
            }
        }
    }
    
    // Return manifest with proper headers
    $response = new WP_REST_Response( $manifest, 200 );
    $response->header( 'Content-Type', 'application/json' );
    $response->header( 'Cache-Control', 'public, max-age=3600' );
    $response->header( 'Access-Control-Allow-Origin', '*' );
    
    return $response;
}

// REST API callback: serve 192x192 PWA icon
function subsales_serve_pwa_icon_192( $request ) {
    $icon_file = SUBSALES_PLUGIN_PATH . 'pwa/icons/icon-192x192.png';
    
    if ( ! file_exists( $icon_file ) ) {
        return new WP_Error( 'icon_not_found', 'Icon file not found', array( 'status' => 404 ) );
    }
    
    // Serve the image file
    header( 'Content-Type: image/png' );
    header( 'Cache-Control: public, max-age=86400' );
    header( 'Access-Control-Allow-Origin: *' );
    readfile( $icon_file );
    exit;
}

// REST API callback: serve 512x512 PWA icon
function subsales_serve_pwa_icon_512( $request ) {
    $icon_file = SUBSALES_PLUGIN_PATH . 'pwa/icons/icon-512x512.png';
    
    if ( ! file_exists( $icon_file ) ) {
        return new WP_Error( 'icon_not_found', 'Icon file not found', array( 'status' => 404 ) );
    }
    
    // Serve the image file
    header( 'Content-Type: image/png' );
    header( 'Cache-Control: public, max-age=86400' );
    header( 'Access-Control-Allow-Origin: *' );
    readfile( $icon_file );
    exit;
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
    
    // Import modal script for Backup/Restore tab
    wp_enqueue_script( 'subsales-import-modal', SUBSALES_PLUGIN_URL . 'assets/js/subsales-import-modal.js', array( 'jquery' ), SUBSALES_VERSION, true );
    wp_localize_script( 'subsales-import-modal', 'subsalesImportData', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'subsales_import_ajax' ),
    ) );
    
    // ZIP admin JS for Address Extracts tab
    wp_enqueue_script( 'subsales-zip-admin', SUBSALES_PLUGIN_URL . 'assets/js/subsales-zip-admin.js', array( 'jquery' ), SUBSALES_VERSION, true );
    wp_localize_script( 'subsales-zip-admin', 'SubsalesZipAdmin', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'subsales_zip_generate' ),
        'deleteNonce' => wp_create_nonce( 'subsales_zip_delete' ),
        'searchNonce' => wp_create_nonce( 'subsales_address_search' ),
        'refreshIndexNonce' => wp_create_nonce( 'subsales_refresh_index' ),
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
// SANITIZATION FUNCTIONS
// ============================================================

/**
 * Sanitize team name - allows all printable characters from keyboard
 * 
 * @param string $name Team name
 * @return string Sanitized team name
 */
function subsales_sanitize_team_name( $name ) {
    // Use WordPress sanitize_text_field which removes line breaks and control chars
    // but preserves all printable characters including special symbols
    $name = sanitize_text_field( $name );
    
    // Collapse multiple spaces to single space
    $name = preg_replace( '/\s+/', ' ', $name );
    
    // Trim whitespace
    $name = trim( $name );
    
    return $name;
}

/**
 * Sanitize team access code - alphanumeric only (letters and numbers)
 * 
 * @param string $code Access code
 * @return string Sanitized access code (alphanumeric only)
 */
function subsales_sanitize_team_code( $code ) {
    // Remove all non-alphanumeric characters
    $code = preg_replace( '/[^a-zA-Z0-9]/', '', $code );
    
    return $code;
}

/**
 * Sanitize user/member name - allows all printable characters from keyboard
 * 
 * @param string $name User name
 * @return string Sanitized user name
 */
function subsales_sanitize_user_name( $name ) {
    // Use WordPress sanitize_text_field which removes line breaks and control chars
    // but preserves all printable characters including special symbols
    $name = sanitize_text_field( $name );
    
    // Collapse multiple spaces to single space
    $name = preg_replace( '/\s+/', ' ', $name );
    
    // Trim whitespace
    $name = trim( $name );
    
    return $name;
}

// ============================================================
// End of Sanitization Functions
// ============================================================


// ============================================================
// REST API ROUTES
// Routes now registered via Subsales_REST_API class
// Handler functions remain below for compatibility
// ============================================================

// NOTE: AJAX handlers now registered in Subsales_AJAX_Handlers::init()
// The following registrations have been REMOVED to prevent conflicts:
// - wp_ajax_subsales_fetch_orders (now in class-ajax-handlers.php)
// - wp_ajax_subsales_get_order_by_db_id (now in class-ajax-handlers.php)
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
                // Check must have paymentMethod='check' OR a non-empty checkNumber
                $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.paymentMethod')) = %s OR (JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.checkNumber')) IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.checkNumber')) != ''))";
                $params[] = 'check';
            } elseif ( $payment_method === 'digital' ) {
                $where[] = "JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.paymentMethod')) = %s";
                $params[] = 'digital';
            }
        } else {
            if ( $payment_method === 'cash' ) {
                $where[] = "order_data LIKE %s";
                $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"cash"%';
            } elseif ( $payment_method === 'check' ) {
                // For LIKE fallback, we need to ensure checkNumber has a value after the colon
                // Match either paymentMethod:check or checkNumber with non-empty value
                $where[] = "(order_data LIKE %s OR (order_data LIKE %s AND order_data NOT LIKE %s))";
                $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"check"%';
                $params[] = '%' . $wpdb->esc_like( '"checkNumber"' ) . '%';
                $params[] = '%' . $wpdb->esc_like( '"checkNumber":""' ) . '%';
            } elseif ( $payment_method === 'digital' ) {
                $where[] = "order_data LIKE %s";
                $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"digital"%';
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
    $totals = array( 'cash' => 0.0, 'check' => 0.0, 'digital' => 0.0, 'grand' => 0.0, 'donations' => 0.0, 'product_totals' => array() );
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
        
        $donation = 0.0;
        if ( isset( $od['donationAmount'] ) ) {
            $donation = floatval( $od['donationAmount'] );
            $order_total += $donation;
        }

        $payment = '';;
        if ( isset( $od['paymentMethod'] ) && ! empty( $od['paymentMethod'] ) ) $payment = $od['paymentMethod'];
        else if ( ! empty( $od['checkNumber'] ) ) $payment = 'check';
        else if ( ! empty( $od['payCash'] ) || ! empty( $od['pay_cash'] ) ) $payment = 'cash';

        // Only include non-deleted orders in page totals
        $is_deleted = isset( $r['deleted'] ) && intval( $r['deleted'] ) === 1;
        if ( ! $is_deleted ) {
            if ( strtolower( $payment ) === 'check' ) $totals['check'] += $order_total;
            elseif ( strtolower( $payment ) === 'cash' ) $totals['cash'] += $order_total;
            if ( strtolower( $payment ) === 'digital' ) $totals['digital'] += $order_total;
            $totals['grand'] += $order_total;
            $totals['donations'] += $donation;

            // add to page product totals
            foreach ( $products_map as $pid => $qty ) {
                if ( isset( $totals['product_totals'][ $pid ] ) ) {
                    $totals['product_totals'][ $pid ] += intval( $qty );
                } else {
                    $totals['product_totals'][ $pid ] = intval( $qty );
                }
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
            'donation_amount' => $donation,
            'payment' => $payment,
            'payment_display' => $payment ? ucfirst($payment) : '',
            'edited' => $edited,
            'deleted' => isset( $r['deleted'] ) && intval( $r['deleted'] ) === 1,
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
    $team_name = isset( $_POST['team_name'] ) ? subsales_sanitize_team_name( $_POST['team_name'] ) : '';
    $team_code = isset( $_POST['team_code'] ) ? subsales_sanitize_team_code( $_POST['team_code'] ) : '';

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
    $user_name = isset( $_POST['user_name'] ) ? subsales_sanitize_user_name( $_POST['user_name'] ) : '';
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
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s AND deleted = 0 ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY id ASC", ARRAY_A );
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

        // Get seller name - if not in order data, look up by user_id
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : '' ) );
        if ( empty( $seller ) && ! empty( $r['user_id'] ) ) {
            $member = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", intval( $r['user_id'] ) ) );
            $seller = $member ? $member->name : 'Unknown (ID: ' . $r['user_id'] . ')';
        }
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
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s AND deleted = 0 ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY id ASC", ARRAY_A );
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
        // Get seller name - if not in order data, look up by user_id
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : '' ) );
        if ( empty( $seller ) && ! empty( $r['user_id'] ) ) {
            $member = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", intval( $r['user_id'] ) ) );
            $seller = $member ? $member->name : 'Unknown (ID: ' . $r['user_id'] . ')';
        }
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

// ============================================================================
// BACKWARD COMPATIBILITY: Legacy delivery functions
// New code should use Subsales_Delivery class methods directly
// ============================================================================

/**
 * @deprecated 2.2.1.230 Use Subsales_Delivery::handle_generate_manifest()
 */
function order_sync_handle_generate_delivery_pdf() {
    // Delegate to new class-based implementation
    Subsales_Delivery::handle_generate_manifest();
}

/**
 * @deprecated 2.2.1.230 Use Subsales_Delivery::optimize_route()
 */
function order_sync_optimize_route( $orders, $start_coords ) {
    return Subsales_Delivery::optimize_route( $orders, $start_coords );
}

/**
 * @deprecated 2.2.1.230 Use Subsales_Delivery::haversine_distance()
 */
function order_sync_haversine_distance( $lat1, $lon1, $lat2, $lon2 ) {
    return Subsales_Delivery::haversine_distance( $lat1, $lon1, $lat2, $lon2 );
}

/**
 * @deprecated 2.2.1.230 Use Subsales_Delivery::generate_qr_code()
 */
if ( ! function_exists( 'subsales_generate_qr_code' ) ) {
    function subsales_generate_qr_code( $url, $size = 800 ) {
        return Subsales_Delivery::generate_qr_code( $url, $size );
    }
}

/**
 * @deprecated 2.2.1.230 Use Subsales_Delivery::generate_route_qr_page()
 */
if ( ! function_exists( 'subsales_generate_route_qr_page' ) ) {
    function subsales_generate_route_qr_page( $all_routes, $delivery_date = '' ) {
        return Subsales_Delivery::generate_route_qr_page( $all_routes, $delivery_date );
    }
}

// ============================================================================
// END BACKWARD COMPATIBILITY
// ============================================================================

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

// Helper: Generate combined HTML manifest with QR codes for all individuals
function order_sync_generate_combined_manifest_html( $all_manifests, $start_address, $configured_products, $delivery_date = '', $all_routes = array() ) {
    $display_date = ! empty( $delivery_date ) ? date('F j, Y', strtotime( $delivery_date ) ) : date('F j, Y');
    
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Manifests - ' . $display_date . '</title>
    <style>
        @media print {
            @page { margin: 0.5in 0.5in 0.75in 0.5in; }
            .page-break { page-break-after: always; }
            .manifest-section { page-break-before: always; }
            .manifest-section:first-child { page-break-before: auto; }
            .delivery-stop { page-break-inside: avoid; }
        }
        body { font-family: Arial, Helvetica, sans-serif; margin: 20px; padding: 0 0 60px 0; font-size: 12pt; }
        h1 { font-size: 24pt; margin: 0 0 10px 0; }
        h2 { font-size: 18pt; margin: 20px 0 10px 0; }
        .depot { font-size: 11pt; margin-bottom: 20px; color: #666; }
        .delivery-stop { margin-bottom: 15px; padding: 12px; border: 2px solid #ddd; background: #f9f9f9; }
        .stop-number { font-size: 16pt; font-weight: bold; color: #0073aa; margin-bottom: 5px; }
        .address { font-size: 13pt; font-weight: bold; margin: 5px 0; }
        .customer { font-size: 11pt; margin: 3px 0; }
        .products { margin: 8px 0; }
        .products-table { width: 100%; border-collapse: collapse; margin: 5px 0; }
        .products-table th, .products-table td { border: 1px solid #999; padding: 6px; text-align: left; font-size: 10pt; }
        .products-table th { background: #e0e0e0; font-weight: bold; }
        .notes { font-size: 9pt; font-style: italic; color: #666; margin-top: 5px; padding: 5px; background: #fff3cd; border-left: 3px solid #ffc107; }
        .packing-page { margin-bottom: 40px; }
        .packing-table { width: 100%; border-collapse: collapse; font-size: 22pt; margin-top: 20px; }
        .packing-table th, .packing-table td { border: 2px solid #000; padding: 12px; text-align: left; }
        .packing-table th { background: #e0e0e0; font-weight: bold; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; padding: 10px 20px; border-top: 1px solid #333; font-size: 10pt; background: white; }
        .footer-line { margin: 2px 0; }
        @media print {
            .footer { position: fixed; bottom: 0.25in; }
        }
    </style>
</head>
<body>';

    $manifest_index = 0;
    foreach ( $all_manifests as $manifest ) {
        $individual_name = $manifest['name'];
        $orders = $manifest['orders'];
        
        // Calculate product totals
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

        $total_pages = 2 + count( $orders );
        $current_page = 1;

        if ( $manifest_index > 0 ) {
            $html .= '<div class="manifest-section"></div>';
        }
        
        // FIRST PACKING LIST
        $html .= '<div class="packing-page">';
        $html .= '<h1>Packing List: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
        $html .= '<div class="depot"><strong>Date:</strong> ' . $display_date . '</div>';
        $html .= '<table class="packing-table"><thead><tr><th>Product</th><th style="width:150px;text-align:center;">Quantity</th></tr></thead><tbody>';
        foreach ( $product_totals as $pt ) {
            if ( $pt['qty'] > 0 ) {
                $html .= '<tr><td>' . htmlspecialchars( $pt['name'], ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $pt['qty'] ) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
        $html .= '<div class="footer"><div class="footer-line">Seller: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '&nbsp;&nbsp;&nbsp;Page ' . $current_page . ' of ' . $total_pages . '</div><div class="footer-line">Date: ' . $display_date . '</div></div>';
        $html .= '</div>';
        $html .= '<div class="page-break"></div>';
        $current_page++;
        
        // SECOND PACKING LIST
        $html .= '<div class="packing-page">';
        $html .= '<h1>Packing List: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
        $html .= '<div class="depot"><strong>Date:</strong> ' . $display_date . '</div>';
        $html .= '<table class="packing-table"><thead><tr><th>Product</th><th style="width:150px;text-align:center;">Quantity</th></tr></thead><tbody>';
        foreach ( $product_totals as $pt ) {
            if ( $pt['qty'] > 0 ) {
                $html .= '<tr><td>' . htmlspecialchars( $pt['name'], ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $pt['qty'] ) . '</td></tr>';
            }
        }
        $html .= '</tbody></table>';
        $html .= '<div class="footer"><div class="footer-line">Seller: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '&nbsp;&nbsp;&nbsp;Page ' . $current_page . ' of ' . $total_pages . '</div><div class="footer-line">Date: ' . $display_date . '</div></div>';
        $html .= '</div>';
        $html .= '<div class="page-break"></div>';
        $current_page++;
        
        // QR CODES PAGE
        $seller_routes = array_filter( $all_routes, function( $route ) use ( $individual_name ) {
            return isset( $route['seller'] ) && $route['seller'] === $individual_name;
        } );
        
        if ( ! empty( $seller_routes ) ) {
            $qr_page_html = subsales_generate_route_qr_page( array_values( $seller_routes ), $delivery_date );
            // Extract body content
            if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $qr_page_html, $matches ) ) {
                $html .= $matches[1];
            }
            $html .= '<div class="page-break"></div>';
        }

        // DELIVERY MANIFEST
        $html .= '<div>';
        $html .= '<h1>Delivery Manifest: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
        $html .= '<div class="depot"><strong>Starting Point:</strong> ' . htmlspecialchars( (string) $start_address, ENT_QUOTES, 'UTF-8' ) . '</div>';
        $html .= '<div class="depot"><strong>Total Stops:</strong> ' . count( $orders ) . ' | <strong>Date:</strong> ' . $display_date . '</div>';

        $stop_num = 1;
        foreach ( $orders as $order ) {
            $html .= '<div class="delivery-stop">';
            $html .= '<div class="stop-number">Stop #' . $stop_num . '</div>';
            $html .= '<div class="address">' . htmlspecialchars( (string) $order['address'], ENT_QUOTES, 'UTF-8' ) . '</div>';
            
            if ( ! empty( $order['customer'] ) ) {
                $html .= '<div class="customer"><strong>Customer:</strong> ' . htmlspecialchars( (string) $order['customer'], ENT_QUOTES, 'UTF-8' ) . '</div>';
            }
            
            if ( ! empty( $order['phone'] ) ) {
                $html .= '<div class="customer"><strong>Phone:</strong> ' . htmlspecialchars( (string) $order['phone'], ENT_QUOTES, 'UTF-8' ) . '</div>';
            }

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
                    $html .= '<tr><td>' . htmlspecialchars( (string) $product_name, ENT_QUOTES, 'UTF-8' ) . '</td><td style="text-align:center;">' . intval( $qty ) . '</td></tr>';
                    $has_products = true;
                }
            }
            if ( ! $has_products ) {
                $html .= '<tr><td colspan="2">No products</td></tr>';
            }
            $html .= '</tbody></table></div>';

            if ( ! empty( $order['notes'] ) ) {
                $html .= '<div class="notes"><strong>Delivery Notes:</strong> ' . htmlspecialchars( (string) $order['notes'], ENT_QUOTES, 'UTF-8' ) . '</div>';
            }

            $html .= '<div class="footer"><div class="footer-line">Seller: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '&nbsp;&nbsp;&nbsp;Page ' . $current_page . ' of ' . $total_pages . '</div><div class="footer-line">Date: ' . $display_date . '</div></div>';
            $html .= '</div>';
            $stop_num++;
            $current_page++;
        }
        $html .= '</div>';
        $manifest_index++;
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

/**
 * Build team sales report data
 * Used by both the admin reports page and the CSV export handler.
 * 
 * @param string $points_mode 'dollar' or 'order'
 * @param float $points_denomination Multiplier for points calculation
 * @param string $points_distribution 'individual' or 'team'
 * @param int $donation_bonus_enabled 1 or 0
 * @param float $donation_percentage Percentage of donations to add as bonus points
 * @param string $donation_distribution 'individual' or 'team'
 * @return array Report data rows
 * @since 2.2.1
 */
if ( ! function_exists( 'subsales_build_team_sales_report' ) ) {
    function subsales_build_team_sales_report( 
        $points_mode = 'dollar', 
        $points_denomination = 1.0,
        $points_distribution = 'individual',
        $donation_bonus_enabled = 0,
        $donation_percentage = 50.0,
        $donation_distribution = 'team'
    ) {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'ss_orders';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';
        $signups_table = $wpdb->prefix . 'ss_signups';
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        $products_config = order_sync_get_products_config();
        
        // Get all non-deleted orders with necessary fields
        $orders = $wpdb->get_results(
            "SELECT id, order_data, created_at, team_id, user_id FROM {$orders_table} WHERE deleted = 0 ORDER BY created_at DESC",
            ARRAY_A
        );
        
        // Get all active signups with campaign dates to ensure everyone signed up gets included
        $signups = $wpdb->get_results(
            "SELECT s.user_id, s.team_id, 
                    c.campaign_date as date,
                    t.name as team_name,
                    u.name as person_name
             FROM {$signups_table} s
             JOIN {$campaigns_table} c ON s.campaign_id = c.id
             JOIN {$teams_table} t ON s.team_id = t.id
             JOIN {$members_table} u ON s.user_id = u.id
             WHERE s.status = 'active'
             ORDER BY c.campaign_date, t.name, u.name",
            ARRAY_A
        );
        
        // First pass: Initialize all signed-up people with zero values
        $aggregated_data = array();
        $team_totals = array();
        
        foreach ( $signups as $signup ) {
            $date = $signup['date'];
            $team_name = $signup['team_name'];
            $person_name = $signup['person_name'];
            $key = $date . '|' . $team_name . '|' . $person_name;
            
            if ( ! isset( $aggregated_data[ $key ] ) ) {
                $aggregated_data[ $key ] = array(
                    'date' => $date,
                    'team_name' => $team_name,
                    'person_name' => $person_name,
                    'product_quantity' => 0,
                    'total_donations' => 0.0,
                    'order_count' => 0
                );
            }
            
            $team_key = $date . '|' . $team_name;
            if ( ! isset( $team_totals[ $team_key ] ) ) {
                $team_totals[ $team_key ] = array(
                    'product_quantity' => 0,
                    'donations' => 0.0,
                    'members' => array()
                );
            }
            
            if ( ! in_array( $person_name, $team_totals[ $team_key ]['members'] ) ) {
                $team_totals[ $team_key ]['members'][] = $person_name;
            }
        }
        
        // Second pass: aggregate order data
        foreach ( $orders as $order ) {
            $order_data = json_decode( $order['order_data'], true );
            if ( ! is_array( $order_data ) ) continue;
            
            $date = date( 'Y-m-d', strtotime( $order['created_at'] ) );
            $team_id = intval( $order['team_id'] );
            $team_name = 'Unknown Team';
            
            if ( $team_id === -1 ) {
                $team_name = 'Individual';
            } elseif ( $team_id > 0 ) {
                $team_name_result = $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$teams_table} WHERE id = %d", $team_id
                ) );
                if ( $team_name_result ) $team_name = $team_name_result;
            }
            
            $user_id = intval( $order['user_id'] );
            $person_name = 'Unknown Person';
            if ( $user_id > 0 ) {
                $person_name_result = $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$members_table} WHERE id = %d", $user_id
                ) );
                if ( $person_name_result ) $person_name = $person_name_result;
            }
            
            if ( $person_name === 'Unknown Person' ) {
                if ( isset( $order_data['customerName'] ) && ! empty( $order_data['customerName'] ) ) {
                    $person_name = $order_data['customerName'];
                } elseif ( isset( $order_data['entered_by_name'] ) && ! empty( $order_data['entered_by_name'] ) ) {
                    $person_name = $order_data['entered_by_name'];
                }
            }
            
            $key = $date . '|' . $team_name . '|' . $person_name;
            
            if ( ! isset( $aggregated_data[ $key ] ) ) {
                $aggregated_data[ $key ] = array(
                    'date' => $date,
                    'team_name' => $team_name,
                    'person_name' => $person_name,
                    'product_quantity' => 0,
                    'total_donations' => 0.0,
                    'order_count' => 0,
                    'team_id' => $team_id
                );
            }
            
            // Calculate product quantity
            $order_product_qty = 0;
            if ( isset( $order_data['products'] ) && is_array( $order_data['products'] ) ) {
                foreach ( $order_data['products'] as $product ) {
                    $qty = isset( $product['qty'] ) ? intval( $product['qty'] ) : 0;
                    $order_product_qty += $qty;
                }
            } else {
                foreach ( $products_config as $prod ) {
                    $pid = $prod['id'];
                    $qty = 0;
                    if ( isset( $order_data[ $pid . 'Qty' ] ) ) {
                        $qty = intval( $order_data[ $pid . 'Qty' ] );
                    } elseif ( isset( $order_data[ $pid . '_qty' ] ) ) {
                        $qty = intval( $order_data[ $pid . '_qty' ] );
                    }
                    $order_product_qty += $qty;
                }
            }
            
            $order_donations = isset( $order_data['donationAmount'] ) ? floatval( $order_data['donationAmount'] ) : 0.0;
            
            $aggregated_data[ $key ]['product_quantity'] += $order_product_qty;
            $aggregated_data[ $key ]['total_donations'] += $order_donations;
            $aggregated_data[ $key ]['order_count']++;
            
            $team_key = $date . '|' . $team_name;
            if ( ! isset( $team_totals[ $team_key ] ) ) {
                $team_totals[ $team_key ] = array(
                    'product_quantity' => 0,
                    'donations' => 0.0,
                    'members' => array()
                );
            }
            $team_totals[ $team_key ]['product_quantity'] += $order_product_qty;
            $team_totals[ $team_key ]['donations'] += $order_donations;
            
            if ( ! in_array( $person_name, $team_totals[ $team_key ]['members'] ) ) {
                $team_totals[ $team_key ]['members'][] = $person_name;
            }
        }
        
        // Third pass: calculate points
        $report_rows = array();
        foreach ( $aggregated_data as $row ) {
            $date = $row['date'];
            $team_name = $row['team_name'];
            $person_name = $row['person_name'];
            $team_key = $date . '|' . $team_name;
            
            $points = 0.0;
            $is_individual_mode = isset( $row['team_id'] ) && $row['team_id'] === -1;
            
            if ( $is_individual_mode || $points_distribution === 'individual' ) {
                $points = $row['product_quantity'] * $points_denomination;
            } else {
                $team_data = $team_totals[ $team_key ];
                $member_count = count( $team_data['members'] );
                if ( $member_count > 0 ) {
                    $team_points = $team_data['product_quantity'] * $points_denomination;
                    $points = $team_points / $member_count;
                }
            }
            
            $donation_bonus = 0.0;
            if ( $donation_bonus_enabled ) {
                if ( $is_individual_mode || $donation_distribution === 'individual' ) {
                    $donation_bonus = $row['total_donations'] * ( $donation_percentage / 100.0 );
                } else {
                    $team_data = $team_totals[ $team_key ];
                    $member_count = count( $team_data['members'] );
                    if ( $member_count > 0 ) {
                        $donation_bonus = ( $team_data['donations'] * ( $donation_percentage / 100.0 ) ) / $member_count;
                    }
                }
            }
            
            $total_points = $points + $donation_bonus;
            
            $tooltip_parts = array();
            if ( $is_individual_mode || $points_distribution === 'individual' ) {
                $tooltip_parts[] = sprintf(
                    'Product Points: %s products × %s = %s',
                    number_format( $row['product_quantity'], 0 ),
                    number_format( $points_denomination, 2 ),
                    number_format( $points, 2 )
                );
            } else {
                $team_data = $team_totals[ $team_key ];
                $member_count = count( $team_data['members'] );
                $tooltip_parts[] = sprintf(
                    'Product Points: %s products × %s ÷ %d members = %s',
                    number_format( $team_data['product_quantity'], 0 ),
                    number_format( $points_denomination, 2 ),
                    $member_count,
                    number_format( $points, 2 )
                );
            }
            
            if ( $donation_bonus_enabled && $donation_bonus > 0 ) {
                if ( $is_individual_mode || $donation_distribution === 'individual' ) {
                    $tooltip_parts[] = sprintf(
                        'Donation Bonus: $%s × %s%% = %s',
                        number_format( $row['total_donations'], 2 ),
                        number_format( $donation_percentage, 1 ),
                        number_format( $donation_bonus, 2 )
                    );
                } else {
                    $team_data = $team_totals[ $team_key ];
                    $member_count = count( $team_data['members'] );
                    $tooltip_parts[] = sprintf(
                        'Donation Bonus: $%s × %s%% ÷ %d members = %s',
                        number_format( $team_data['donations'], 2 ),
                        number_format( $donation_percentage, 1 ),
                        $member_count,
                        number_format( $donation_bonus, 2 )
                    );
                }
            }
            
            $tooltip_parts[] = sprintf( 'Total: %s points', number_format( $total_points, 2 ) );
            $tooltip = implode( '\n', $tooltip_parts );
            
            $report_rows[] = array(
                'date' => $row['date'],
                'team_name' => $row['team_name'],
                'person_name' => $row['person_name'],
                'product_quantity' => $row['product_quantity'],
                'total_donations' => $row['total_donations'],
                'points' => $total_points,
                'order_count' => $row['order_count'],
                'points_tooltip' => $tooltip
            );
        }
        
        // Sort by date (descending), then by team name (ascending)
        usort( $report_rows, function( $a, $b ) {
            $date_cmp = strcmp( $b['date'], $a['date'] );
            if ( $date_cmp !== 0 ) return $date_cmp;
            return strcmp( $a['team_name'], $b['team_name'] );
        } );
        
        return $report_rows;
    }
}

// Export team sales report CSV
add_action( 'admin_post_subsales_export_team_sales_report', 'subsales_export_team_sales_report_csv' );
function subsales_export_team_sales_report_csv() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    check_admin_referer( 'subsales_export_report', '_wpnonce' );
    
    // Get points calculation settings
    $points_mode = get_option( 'subsales_points_mode', 'dollar' );
    $points_denomination = floatval( get_option( 'subsales_points_denomination', 1.0 ) );
    $points_distribution = get_option( 'subsales_points_distribution', 'individual' );
    $donation_bonus_enabled = get_option( 'subsales_donation_bonus_enabled', 0 );
    $donation_percentage = floatval( get_option( 'subsales_donation_percentage', 50.0 ) );
    $donation_distribution = get_option( 'subsales_donation_distribution', 'team' );
    
    // Build report data
    $report_data = subsales_build_team_sales_report( 
        $points_mode, 
        $points_denomination, 
        $points_distribution,
        $donation_bonus_enabled,
        $donation_percentage,
        $donation_distribution
    );
    
    // Generate filename with hyphenated date format: BKMBPointsReport1-26-26.csv
    $date = date( 'n-j-y' ); // Single digit month/day, 2-digit year
    $filename = 'BKMBPointsReport' . $date . '.csv';
    
    // Set headers for CSV download
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );
    
    // Open output stream
    $output = fopen( 'php://output', 'w' );
    
    // Write header row
    fputcsv( $output, array( 'Date', 'Team', 'Person', 'Points' ) );
    
    // Write data rows
    foreach ( $report_data as $row ) {
        fputcsv( $output, array(
            $row['date'],
            $row['team_name'],
            $row['person_name'],
            number_format( $row['points'], 2 )
        ) );
    }
    
    fclose( $output );
    exit;
}

// ============================================================
// BACKUP/RESTORE - Handled by Subsales_Backup_Restore class
// ============================================================
// Export handler: admin_post_subsales_export_backup_combined
// Import handler: admin_post_subsales_import_backup
// Restore handler: admin_post_subsales_restore_and_import
// See: includes/class-backup-restore.php

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
/**
 * Backward compatibility wrapper for permission check
 * Delegates to Subsales_REST_API::check_permissions()
 * 
 * @deprecated Use Subsales_REST_API::check_permissions() instead
 */
function order_sync_check_permissions( WP_REST_Request $request ) {
    return Subsales_REST_API::check_permissions( $request );
}

/**
 * Backward compatibility wrapper for admin permission check
 * Delegates to Subsales_REST_API::check_admin_permissions()
 * 
 * @deprecated Use Subsales_REST_API::check_admin_permissions() instead
 */
function order_sync_check_admin_permissions( WP_REST_Request $request ) {
    return Subsales_REST_API::check_admin_permissions( $request );
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
    $sales_enabled = (bool) get_option( 'subsales_sales_enabled', 1 );

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
        'individualSessionDuration' => intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ),
        'authenticated' => $is_authenticated,
        'products' => $products,
        'loginMode' => $login_mode,
        'salesMode' => $sales_mode,
        'salesEnabled' => $sales_enabled,
        'debugLoggingEnabled' => $debug_logging_enabled,
        'digitalPaymentsEnabled' => (bool) get_option( 'subsales_digital_payments_enabled', false )
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
// High priority (1) to intercept before WordPress routes to 404
add_action( 'template_redirect', 'subsales_serve_portal_assets', 1 );

// Also try to catch 404s for signup
add_action( 'wp', 'subsales_catch_signup_404', 1 );

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
    $req_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    
    // Debug logging
    error_log( 'SUBSALES DEBUG: template_redirect called, req_path=' . $req_path );
    
    // Serve signup page at /signup/ endpoint (independent of portal)
    // Match: signup, signup/, signup/index.html
    if ( $req_path === 'signup' || strpos( $req_path, 'signup/' ) === 0 ) {
        error_log( 'SUBSALES DEBUG: Serving signup page' );
        subsales_serve_signup_page();
        exit;
    }
    
    // Check if portal slug is configured before serving portal assets
    $portal_slug = get_option( 'order_sync_portal_slug', '' ); 
    if ( empty( $portal_slug ) ) return;
    
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

    if ( $req_path === $portal_base . '/service-worker.js' || 
         $req_path === $portal_base . 'service-worker.js' ||
         strpos( $req_path, '/service-worker.js' ) !== false ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/service-worker.js';
        if ( file_exists( $file ) ) {
            // Serve publicly with permissive caching and CORS so browsers can register the SW without auth issues
            header( 'Content-Type: application/javascript' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            http_response_code(200);
            readfile( $file );
            exit;
        } else {
            error_log( 'SUBSALES ERROR: Service worker file not found at: ' . $file );
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
    
    // Serve other PWA files (CSS, JS) from portal base
    // Match: /portal/styles.css, /portal/pwa-logger.js, /portal/app.js, etc.
    if ( strpos( $req_path, $portal_base . '/' ) === 0 ) {
        $rel_file = substr( $req_path, strlen( $portal_base ) + 1 );
        // Only serve safe PWA files (prevent directory traversal)
        $allowed_files = array(
            'styles.css',
            'pwa-logger.js',
            'app.js',
            'address-autocomplete.js',
            'session-tracking.js',
            'global-suggestions.json',
            'zip-index.json'
        );
        
        if ( in_array( $rel_file, $allowed_files ) ) {
            $file = SUBSALES_PLUGIN_PATH . 'pwa/' . $rel_file;
            if ( file_exists( $file ) ) {
                $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                switch ( $ext ) {
                    case 'css': $ct = 'text/css'; break;
                    case 'js': $ct = 'application/javascript'; break;
                    case 'json': $ct = 'application/json'; break;
                    default: $ct = 'text/plain';
                }
                header( 'Content-Type: ' . $ct . '; charset=utf-8' );
                header( 'Cache-Control: public, max-age=3600' );
                header( 'Access-Control-Allow-Origin: *' );
                readfile( $file );
                exit;
            }
        }
    }
}

/**
 * ==============================================================================
 * SIGNUP FUNCTIONALITY - MOVED TO includes/class-signups.php
 * ==============================================================================
 * The following functions have been moved to the Subsales_Signups class:
 * - subsales_catch_signup_404()
 * - subsales_serve_signup_page()
 * - subsales_rest_signup_settings()
 * - subsales_rest_submit_signup()
 * - subsales_rest_get_my_signups()
 * - subsales_rest_get_team_roster()
 * - subsales_rest_update_team_driver()
 * - subsales_rest_get_campaigns()
 * - subsales_rest_create_campaign()
 * - subsales_rest_delete_campaign()
 * 
 * Initialize with: Subsales_Signups::init();
 * ==============================================================================
 */

/**
 * Catch signup 404s at wp hook (earlier than template_redirect)
 * NOTE: Moved to Subsales_Signups::catch_signup_404()
 */
function subsales_catch_signup_404() {
    $req_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
    error_log( 'SUBSALES DEBUG: wp hook called, req_path=' . $req_path . ', is_404=' . ( is_404() ? 'yes' : 'no' ) );
    
    if ( $req_path === 'signup' || strpos( $req_path, 'signup/' ) === 0 ) {
        error_log( 'SUBSALES DEBUG: wp hook - serving signup' );
        // Mark as not 404
        global $wp_query;
        $wp_query->is_404 = false;
        status_header( 200 );
        subsales_serve_signup_page();
        exit;
    }
}

/**
 * Serve the signup page at /signup/ endpoint
 * Self-registration interface for team members to sign up for selling dates
 * NOTE: Moved to Subsales_Signups::serve_signup_page()
 * HTML template located in admin/signup-page.php
 */
function subsales_serve_signup_page() {
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';
    $brand_name = get_option( 'subsales_branding', 'Subsales' );
    $admin_email = get_option( 'subsales_admin_email', get_option( 'admin_email' ) );
    $primary_color = get_option( 'order_sync_primary_color', '#2d6cdf' );
    
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Cache-Control: no-cache, must-revalidate' );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo esc_html( $brand_name ); ?> - Sign Up to Sell</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
                background: #f5f5f5;
                color: #333;
                line-height: 1.6;
            }
            .container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                text-align: center;
                padding: 20px 0;
                background: white;
                margin-bottom: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .header img {
                max-width: 200px;
                height: auto;
                margin-bottom: 10px;
            }
            .header h1 {
                color: <?php echo esc_attr( $primary_color ); ?>;
                font-size: 24px;
            }
            .footer {
                text-align: center;
                padding: 20px 0;
                margin-top: 20px;
            }
            .footer-email-btn {
                display: inline-block;
                padding: 10px 20px;
                background: <?php echo esc_attr( $primary_color ); ?>;
                color: white;
                text-decoration: none;
                border-radius: 6px;
                font-size: 14px;
                transition: opacity 0.2s;
            }
            .footer-email-btn:hover {
                opacity: 0.9;
            }
            .card {
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #555;
            }
            input[type="text"],
            input[type="tel"],
            select {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
            }
            input:focus, select:focus {
                outline: none;
                border-color: <?php echo esc_attr( $primary_color ); ?>;
            }
            .btn {
                width: 100%;
                padding: 14px;
                background: <?php echo esc_attr( $primary_color ); ?>;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: opacity 0.2s;
            }
            .btn:hover {
                opacity: 0.9;
            }
            .btn:disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            .btn-secondary {
                background: #6c757d;
                margin-top: 10px;
            }
            .hidden {
                display: none;
            }
            .error {
                color: #dc3545;
                font-size: 14px;
                margin-top: 5px;
            }
            .success {
                color: #28a745;
                font-size: 14px;
                margin-top: 5px;
            }
            .step-indicator {
                display: flex;
                justify-content: space-between;
                margin-bottom: 30px;
            }
            .step {
                flex: 1;
                text-align: center;
                padding: 10px;
                background: #e9ecef;
                border-radius: 4px;
                margin: 0 5px;
                font-size: 14px;
                font-weight: 600;
                color: #6c757d;
            }
            .step.active {
                background: <?php echo esc_attr( $primary_color ); ?>;
                color: white;
            }
            .step.completed {
                background: #28a745;
                color: white;
            }
            .checkbox-group {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .checkbox-item {
                flex: 1 1 calc(50% - 10px);
                min-width: 150px;
            }
            .checkbox-item label {
                display: flex;
                align-items: center;
                padding: 12px;
                border: 2px solid #ddd;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .checkbox-item input[type="checkbox"] {
                margin-right: 10px;
                width: 20px;
                height: 20px;
            }
            .checkbox-item label:hover {
                border-color: <?php echo esc_attr( $primary_color ); ?>;
            }
            .checkbox-item input[type="checkbox"]:checked + span {
                font-weight: 600;
            }
            .mini-reg {
                margin-top: 30px;
            }
            .mini-reg h3 {
                margin-bottom: 15px;
                color: #333;
            }
            .reg-table {
                width: 100%;
                border-collapse: collapse;
            }
            .reg-table th,
            .reg-table td {
                padding: 10px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            .reg-table th {
                background: #f8f9fa;
                font-weight: 600;
            }
            .help-text {
                font-size: 14px;
                color: #6c757d;
                margin-top: 5px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <?php if ( $header_image_url ) : ?>
                    <img src="<?php echo esc_url( $header_image_url ); ?>" alt="<?php echo esc_attr( $brand_name ); ?>">
                <?php endif; ?>
                <h1><?php echo esc_html( $brand_name ); ?></h1>
                <p>Sign Up to Sell</p>
            </div>

            <div class="card">
                <div class="step-indicator">
                    <div class="step active" id="step1-indicator">1</div>
                    <div class="step" id="step2-indicator">2</div>
                    <div class="step" id="step3-indicator">3</div>
                </div>

                <!-- Legacy Mode Steps -->
                <!-- Legacy Step 1: Select/Create Team -->
                <div id="legacy-step1" class="step-content hidden">
                    <h2>Step 1: Select or Create Team</h2>
                    <div class="form-group">
                        <label for="legacy-team-search">Search for Your Team</label>
                        <input type="text" id="legacy-team-search" placeholder="Start typing team name...">
                        <div id="legacy-team-results"></div>
                    </div>
                    <div class="form-group">
                        <label for="legacy-new-team">Or Create New Team</label>
                        <input type="text" id="legacy-new-team" placeholder="Enter new team name">
                        <div id="legacy-new-team-warning" class="help-text" style="color: #d63638; display: none;"></div>
                        <div class="help-text">New teams will be created with a default access code</div>
                    </div>
                    <button class="btn" id="legacy-step1-next">Next</button>
                    <div id="legacy-step1-error" class="error hidden"></div>
                </div>

                <!-- Legacy Step 2: Enter User Info -->
                <div id="legacy-step2" class="step-content hidden">
                    <h2>Step 2: Your Information</h2>
                    <div class="form-group">
                        <label for="legacy-user-name">Your Name (Required)</label>
                        <input type="text" id="legacy-user-name" placeholder="Enter your name..." required>
                        <div class="help-text">Enter your full name</div>
                    </div>
                    <div class="form-group">
                        <label for="legacy-user-phone">Phone Number (Required)</label>
                        <input type="tel" id="legacy-user-phone" placeholder="(860) 555-1234" required>
                        <div class="help-text">We'll use this to look up your existing registrations</div>
                    </div>
                    <button class="btn" id="legacy-step2-next">Next</button>
                    <button class="btn btn-secondary" id="legacy-step2-back">Back</button>
                    <div id="legacy-step2-error" class="error hidden"></div>
                </div>

                <!-- User Mode Steps -->
                <!-- User Step 1: Enter User Info -->
                <div id="user-step1" class="step-content hidden">
                    <h2>Step 1: Your Information</h2>
                    <div class="form-group">
                        <label for="user-user-name">Your Name (Required)</label>
                        <input type="text" id="user-user-name" placeholder="Start typing your name..." required>
                        <div id="user-name-results"></div>
                        <div class="help-text">Select your name or enter a new one</div>
                    </div>
                    <div class="form-group">
                        <label for="user-user-phone">Phone Number (Required)</label>
                        <input type="tel" id="user-user-phone" placeholder="(860) 555-1234" required>
                        <div class="help-text">We'll use this to look up your existing registrations</div>
                    </div>
                    <button class="btn" id="user-step1-next">Next</button>
                    <div id="user-step1-error" class="error hidden"></div>
                </div>

                <!-- User Step 2: Current Registrations & Team Selection -->
                <div id="user-step2" class="step-content hidden">
                    <h2>Step 2: Your Registrations</h2>
                    
                    <!-- Show current registrations -->
                    <div id="user-current-signups" class="current-signups">
                        <h3>Current Sign-ups</h3>
                        <div id="user-current-signups-list">Loading...</div>
                    </div>
                    
                    <hr style="margin: 20px 0; border: 1px solid #ddd;">
                    
                    <!-- Add another team/date -->
                    <h3>Sign Up for Another Date</h3>
                    <div class="form-group">
                        <label for="user-team-search">Search for Your Team</label>
                        <input type="text" id="user-team-search" placeholder="Start typing team name...">
                        <div id="user-team-results"></div>
                    </div>
                    <div class="form-group">
                        <label for="user-new-team">Or Create New Team</label>
                        <input type="text" id="user-new-team" placeholder="Enter new team name">
                        <div id="user-new-team-warning" class="help-text" style="color: #d63638; display: none;"></div>
                        <div class="help-text">New teams will be created with a default access code</div>
                    </div>
                    <button class="btn" id="user-step2-next">Next - Select Dates</button>
                    <button class="btn btn-secondary" id="user-step2-back">Back</button>
                    <div id="user-step2-error" class="error hidden"></div>
                </div>

                <!-- Shared Step 3: Select Dates -->
                <div id="step3" class="step-content hidden">
                    <h2>Step 3: Select Selling Dates</h2>
                    <div class="form-group">
                        <label>Which dates will you be selling?</label>
                        <div id="dates-checkboxes" class="checkbox-group">
                            <!-- Dynamically populated -->
                        </div>
                    </div>
                    <button class="btn" id="step3-submit">Complete Signup</button>
                    <button class="btn btn-secondary" id="step3-back">Back</button>
                    <div id="step3-error" class="error hidden"></div>
                    <div id="step3-success" class="success hidden"></div>
                </div>

                <!-- Mini Registration Page -->
                <div id="mini-reg" class="mini-reg hidden">
                    <h3>Your Current Registrations</h3>
                    <div id="reg-list"></div>
                    <button class="btn" id="add-another-team">Sign Up for Another Team</button>
                </div>
                
                <!-- Signup Details Modal -->
                <div id="signup-details-modal" class="modal hidden" style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000;">
                    <div class="modal-content" style="background: white; border-radius: 8px; padding: 25px; max-width: 450px; width: 90%; max-height: 85vh; overflow-y: auto; position: relative; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
                        <button id="modal-close" style="position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 28px; cursor: pointer; color: #999; line-height: 1;">&times;</button>
                        <div id="modal-body"></div>
                    </div>
                </div>
            </div>

            <div class="footer">
                <?php if ( $admin_email ) : ?>
                    <a href="mailto:<?php echo esc_attr( $admin_email ); ?>?subject=Sign-up%20Issue" class="footer-email-btn">✉ Email Us</a>
                <?php endif; ?>
            </div>
        </div>

        <script>
            const apiBase = <?php echo wp_json_encode( rest_url( 'order-manager/v1' ) ); ?>;
            let currentStep = 1;
            let selectedTeam = null;
            let userData = null;
            let userSignups = []; // Store user's existing signups
            let signupMode = 'legacy'; // Will be set from API
            let adminEmail = ''; // Will be set from API

            // Load signup settings on page load
            async function loadSettings() {
                try {
                    const response = await fetch(apiBase + '/signup/settings');
                    const data = await response.json();
                    signupMode = data.mode || 'legacy';
                    adminEmail = data.admin_email || '';
                    
                    // Update step indicators based on mode
                    if (signupMode === 'user') {
                        document.getElementById('step1-indicator').textContent = '1. Your Info';
                        document.getElementById('step2-indicator').textContent = '2. Team';
                        document.getElementById('step3-indicator').textContent = '3. Dates';
                    } else {
                        document.getElementById('step1-indicator').textContent = '1. Team';
                        document.getElementById('step2-indicator').textContent = '2. Your Info';
                        document.getElementById('step3-indicator').textContent = '3. Dates';
                    }
                    
                    showStep(1);
                } catch (error) {
                    console.error('Error loading settings:', error);
                    showStep(1);
                }
            }

            // Step navigation - now much simpler!
            function showStep(step) {
                document.querySelectorAll('.step-content').forEach(el => el.classList.add('hidden'));
                
                let stepDiv;
                if (signupMode === 'user') {
                    stepDiv = step === 3 ? 'step3' : `user-step${step}`;
                } else {
                    stepDiv = step === 3 ? 'step3' : `legacy-step${step}`;
                }
                
                document.getElementById(stepDiv).classList.remove('hidden');
                
                // Update step indicators
                document.querySelectorAll('.step').forEach((el, idx) => {
                    el.classList.remove('active', 'completed');
                    if (idx + 1 < step) el.classList.add('completed');
                    if (idx + 1 === step) el.classList.add('active');
                });
                
                currentStep = step;
            }

            // Setup autocomplete handlers for both modes
            function setupTeamSearch(searchFieldId, resultsFieldId, newTeamFieldId, warningFieldId) {
                // Search field autocomplete
                document.getElementById(searchFieldId).addEventListener('input', async function(e) {
                    const query = e.target.value.trim();
                    if (query.length < 2) {
                        document.getElementById(resultsFieldId).innerHTML = '';
                        return;
                    }
                    
                    try {
                        const response = await fetch(apiBase + '/teams?search=' + encodeURIComponent(query));
                        const data = await response.json();
                        
                        const resultsDiv = document.getElementById(resultsFieldId);
                        if (data.length === 0) {
                            resultsDiv.innerHTML = '<p class="help-text">No teams found. Create a new team below.</p>';
                        } else {
                            resultsDiv.innerHTML = data.map(team => 
                                `<button class="btn btn-secondary" style="margin: 5px 0;" data-team-id="${team.id}" data-team-name="${team.name}">${team.name}</button>`
                            ).join('');
                            
                            resultsDiv.querySelectorAll('button').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    selectedTeam = { id: this.dataset.teamId, name: this.dataset.teamName };
                                    document.getElementById(searchFieldId).value = selectedTeam.name;
                                    document.getElementById(newTeamFieldId).value = '';
                                    document.getElementById(resultsFieldId).innerHTML = '';
                                    if (warningFieldId) {
                                        document.getElementById(warningFieldId).style.display = 'none';
                                    }
                                });
                            });
                        }
                    } catch (error) {
                        console.error('Error searching teams:', error);
                    }
                });
                
                // New team field - check for duplicates
                if (warningFieldId) {
                    document.getElementById(newTeamFieldId).addEventListener('input', async function(e) {
                        const newName = e.target.value.trim();
                        const warningDiv = document.getElementById(warningFieldId);
                        
                        if (newName.length < 2) {
                            warningDiv.style.display = 'none';
                            return;
                        }
                        
                        try {
                            const response = await fetch(apiBase + '/teams?search=' + encodeURIComponent(newName));
                            const data = await response.json();
                            
                            // Check for case-insensitive match
                            const exactMatch = data.find(team => team.name.toLowerCase() === newName.toLowerCase());
                            
                            if (exactMatch) {
                                warningDiv.textContent = `⚠️ Team "${exactMatch.name}" already exists. Use search above to select it.`;
                                warningDiv.style.display = 'block';
                            } else {
                                warningDiv.style.display = 'none';
                            }
                        } catch (error) {
                            console.error('Error checking team name:', error);
                        }
                    });
                }
            }

            function setupUserNameSearch(nameFieldId, resultsFieldId) {
                const field = document.getElementById(nameFieldId);
                if (!field) return;
                
                field.addEventListener('input', async function(e) {
                    const query = e.target.value.trim();
                    
                    if (query.length < 2) {
                        document.getElementById(resultsFieldId).innerHTML = '';
                        return;
                    }
                    
                    try {
                        const url = apiBase + '/users/search?name=' + encodeURIComponent(query);
                        const response = await fetch(url);
                        const data = await response.json();
                        
                        const resultsDiv = document.getElementById(resultsFieldId);
                        if (data.length === 0) {
                            resultsDiv.innerHTML = '<p class="help-text">No matches found. Continue to register as new user.</p>';
                        } else {
                            resultsDiv.innerHTML = data.map(user => 
                                `<button class="btn btn-secondary" style="margin: 5px 0;" data-user-id="${user.id}" data-user-name="${user.name}">${user.name}</button>`
                            ).join('');
                            
                            resultsDiv.querySelectorAll('button').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    document.getElementById(nameFieldId).value = this.dataset.userName;
                                    userData = { 
                                        id: this.dataset.userId, 
                                        name: this.dataset.userName,
                                        existingUser: true 
                                    };
                                    document.getElementById(resultsFieldId).innerHTML = '';
                                });
                            });
                        }
                    } catch (error) {
                        console.error('Error searching users:', error);
                    }
                });
            }

            // Setup both modes
            setupTeamSearch('legacy-team-search', 'legacy-team-results', 'legacy-new-team', 'legacy-new-team-warning');
            setupTeamSearch('user-team-search', 'user-team-results', 'user-new-team', 'user-new-team-warning');
            setupUserNameSearch('user-user-name', 'user-name-results');

            // ========== LEGACY MODE HANDLERS ==========
            
            // Legacy Step 1: Team Selection → Next
            document.getElementById('legacy-step1-next').addEventListener('click', function() {
                const teamSearch = document.getElementById('legacy-team-search').value.trim();
                const newTeamName = document.getElementById('legacy-new-team').value.trim();
                const errorDiv = document.getElementById('legacy-step1-error');
                const warningDiv = document.getElementById('legacy-new-team-warning');
                
                errorDiv.classList.add('hidden');
                
                if (!selectedTeam && !newTeamName) {
                    errorDiv.textContent = 'Please select or create a team';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                // Check if warning is visible (duplicate team name)
                if (newTeamName && warningDiv.style.display !== 'none') {
                    errorDiv.textContent = 'Cannot create duplicate team. Please select existing team from search results.';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                if (newTeamName) {
                    selectedTeam = { id: null, name: newTeamName, isNew: true };
                }
                
                showStep(2);
            });
            
            // Legacy Step 2: User Info → Next
            document.getElementById('legacy-step2-next').addEventListener('click', function() {
                const name = document.getElementById('legacy-user-name').value.trim();
                const phone = document.getElementById('legacy-user-phone').value.trim();
                const errorDiv = document.getElementById('legacy-step2-error');
                
                errorDiv.classList.add('hidden');
                
                if (!name) {
                    errorDiv.textContent = 'Please enter your name';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                if (!phone) {
                    errorDiv.textContent = 'Please enter your phone number';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                const phoneDigits = phone.replace(/\D/g, '');
                if (phoneDigits.length !== 10) {
                    errorDiv.textContent = 'Phone must be 10 digits';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                userData = { name: name, phone: phoneDigits };
                loadCampaigns();
                showStep(3);
            });
            
            // Legacy Step 2: Back button
            document.getElementById('legacy-step2-back').addEventListener('click', () => showStep(1));
            
            // ========== USER MODE HANDLERS ==========
            
            // User Step 1: User Info → Next
            document.getElementById('user-step1-next').addEventListener('click', async function() {
                const name = document.getElementById('user-user-name').value.trim();
                const phone = document.getElementById('user-user-phone').value.trim();
                const errorDiv = document.getElementById('user-step1-error');
                const nextBtn = this;
                
                errorDiv.classList.add('hidden');
                
                if (!name) {
                    errorDiv.textContent = 'Please enter your name';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                if (!phone) {
                    errorDiv.textContent = 'Please enter your phone number';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                const phoneDigits = phone.replace(/\D/g, '');
                if (phoneDigits.length !== 10) {
                    errorDiv.textContent = 'Phone must be 10 digits';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                // If user was selected from autocomplete, verify phone matches
                if (userData && userData.id) {
                    // Show loading state
                    nextBtn.disabled = true;
                    nextBtn.textContent = 'Verifying...';
                    
                    try {
                        const response = await fetch(apiBase + '/signup/verify-user', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ user_id: userData.id, phone: phoneDigits })
                        });
                        
                        const data = await response.json();
                        
                        if (data.valid) {
                            userData = { id: userData.id, name: data.user.name, phone: phoneDigits };
                            errorDiv.classList.add('hidden');
                            await loadUserSignups(); // Load existing signups for verified user
                            showStep(2);
                        } else {
                            let errorMessage = data.message || 'Phone number does not match this user';
                            if (adminEmail) {
                                errorMessage += '. If you think this is an error, please <a href="mailto:' + adminEmail + '?subject=Sign-up%20Issue">Email Us</a>';
                            }
                            errorDiv.innerHTML = errorMessage;
                            errorDiv.classList.remove('hidden');
                        }
                    } catch (error) {
                        console.error('Error verifying user:', error);
                        errorDiv.textContent = 'Error verifying phone number. Please try again.';
                        errorDiv.classList.remove('hidden');
                    } finally {
                        nextBtn.disabled = false;
                        nextBtn.textContent = 'Next';
                    }
                } else {
                    // New user - just store and proceed
                    userData = { name: name, phone: phoneDigits };
                    await loadUserSignups(); // Load any existing signups
                    showStep(2);
                }
            });
            
            // Load user's existing signups
            async function loadUserSignups() {
                if (!userData || !userData.phone) {
                    console.log('loadUserSignups: No userData or phone');
                    userSignups = [];
                    return;
                }
                
                console.log('loadUserSignups: Fetching signups for phone:', userData.phone);
                
                try {
                    const response = await fetch(apiBase + '/my-signups', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ phone: userData.phone })
                    });
                    
                    if (!response.ok) {
                        console.error('loadUserSignups: Response not OK', response.status, response.statusText);
                        userSignups = [];
                        const listDiv = document.getElementById('user-current-signups-list');
                        if (listDiv) {
                            listDiv.innerHTML = '<p class="help-text">You have no current registrations.</p>';
                        }
                        return;
                    }
                    
                    const data = await response.json();
                    userSignups = data || [];
                    
                    console.log('loadUserSignups: Received', userSignups.length, 'signups:', userSignups);
                    
                    // Display in Step 2
                    const listDiv = document.getElementById('user-current-signups-list');
                    if (userSignups.length === 0) {
                        listDiv.innerHTML = '<p class="help-text">You have no current registrations.</p>';
                    } else {
                        // Fetch driver info for all signups
                        const signupsWithDrivers = await Promise.all(userSignups.map(async reg => {
                            try {
                                const rosterResponse = await fetch(apiBase + '/team-roster?team_id=' + reg.team_id + '&campaign_id=' + reg.campaign_id);
                                const roster = await rosterResponse.json();
                                return { ...reg, driver_name: roster.driver_name || '' };
                            } catch (error) {
                                console.error('Error fetching driver for signup:', error);
                                return { ...reg, driver_name: '' };
                            }
                        }));
                        
                        listDiv.innerHTML = `
                            <table class="reg-table">
                                <thead>
                                    <tr>
                                        <th>Team</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${signupsWithDrivers.map(reg => {
                                        const date = new Date(reg.campaign_date + 'T00:00:00');
                                        const formatted = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                                        const displayName = reg.campaign_name ? `${formatted} - ${reg.campaign_name}` : formatted;
                                        const driverText = reg.driver_name ? `Driver: ${reg.driver_name}` : 'Driver Missing';
                                        return `
                                            <tr>
                                                <td>${reg.team_name} <span style="font-size: 11px; color: #666;">(${driverText})</span></td>
                                                <td>${displayName}</td>
                                                <td><button class="btn-details-signup" data-signup='${JSON.stringify(reg).replace(/'/g, "&apos;")}' style="background: #007bff; color: white; border: none; padding: 5px 15px; border-radius: 3px; cursor: pointer; font-size: 12px;">Details</button></td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        `;
                        
                        // Add click handlers for details buttons
                        document.querySelectorAll('.btn-details-signup').forEach(btn => {
                            btn.addEventListener('click', async function() {
                                const reg = JSON.parse(this.getAttribute('data-signup'));
                                await showSignupDetails(reg);
                            });
                        });
                    }
                } catch (error) {
                    console.error('Error loading signups:', error);
                    userSignups = [];
                }
            }

            // Show signup details modal
            async function showSignupDetails(signup) {
                try {
                    // Fetch team roster and driver info
                    const response = await fetch(apiBase + '/team-roster?team_id=' + signup.team_id + '&campaign_id=' + signup.campaign_id);
                    const roster = await response.json();
                    
                    const date = new Date(signup.campaign_date + 'T00:00:00');
                    const formatted = date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
                    const displayName = signup.campaign_name ? `${formatted} - ${signup.campaign_name}` : formatted;
                    
                    const currentDriver = roster.driver_name || '';
                    const driverUpdatedBy = roster.driver_updated_by || '';
                    const driverUpdatedAt = roster.driver_updated_at ? new Date(roster.driver_updated_at).toLocaleString() : '';
                    
                    let modalHTML = `
                        <h3 style="margin-top: 0; margin-bottom: 20px; padding-right: 30px;">${signup.team_name}</h3>
                        <p style="margin-bottom: 20px; color: #666;"><strong>Date:</strong> ${displayName}</p>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                            <h4 style="margin-top: 0; margin-bottom: 12px; font-size: 16px;">Team Members (${roster.members.length})</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.8;">
                                ${roster.members.map(m => `<li>${m.name}${m.user_id == userData.id ? ' <strong>(You)</strong>' : ''}</li>`).join('')}
                            </ul>
                        </div>
                        
                        <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                            <h4 style="margin-top: 0; margin-bottom: 10px; font-size: 16px;">Driver</h4>
                            <input type="text" id="driver-name-input" value="${currentDriver}" placeholder="Enter driver name..." 
                                style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; margin-bottom: 8px;">
                            <button id="update-driver" style="background: #007bff; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; width: 100%;">Update Driver</button>
                    `;
                    
                    if (driverUpdatedBy) {
                        modalHTML += `<p style="margin: 10px 0 0 0; color: #856404; font-size: 12px;"><em>Last updated by ${driverUpdatedBy}`;
                        if (driverUpdatedAt) {
                            modalHTML += ` on ${driverUpdatedAt}`;
                        }
                        modalHTML += `</em></p>`;
                    }
                    
                    modalHTML += `
                            <div id="driver-status" style="margin-top: 8px; font-size: 14px;"></div>
                        </div>`;
                    
                    modalHTML += `
                        <div style="background: #f8f9fa; padding: 15px; border-radius: 6px;">
                            <details>
                                <summary style="cursor: pointer; font-weight: 600; margin-bottom: 10px;">Advanced Actions</summary>
                                <div style="padding-top: 10px;">
                                    <button id="change-team" style="background: #ffc107; color: #000; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; width: 100%; margin-bottom: 8px;">Switch to Different Team</button>
                                    <button id="delete-signup" style="background: #dc3545; color: white; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; width: 100%;">Remove This Registration</button>
                                    <div id="action-status" style="margin-top: 10px; font-size: 14px;"></div>
                                </div>
                            </details>
                        </div>
                        
                        <button id="close-modal-btn" style="background: #6c757d; color: white; border: none; padding: 12px; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; margin-top: 20px;">Close</button>
                    `;
                    
                    document.getElementById('modal-body').innerHTML = modalHTML;
                    document.getElementById('signup-details-modal').style.display = 'flex';
                    
                    // Close button handlers
                    document.getElementById('modal-close').onclick = () => {
                        document.getElementById('signup-details-modal').style.display = 'none';
                    };
                    document.getElementById('close-modal-btn').onclick = () => {
                        document.getElementById('signup-details-modal').style.display = 'none';
                    };
                    
                    // Update driver button
                    document.getElementById('update-driver').addEventListener('click', async function() {
                        const driverName = document.getElementById('driver-name-input').value.trim();
                        await updateTeamDriver(signup.team_id, signup.campaign_id, driverName);
                    });
                    
                    // Delete signup button
                    document.getElementById('delete-signup').addEventListener('click', async function() {
                        if (confirm('Are you sure you want to remove this registration?')) {
                            try {
                                const response = await fetch(apiBase + '/my-signups/' + signup.signup_id, {
                                    method: 'DELETE'
                                });
                                
                                if (response.ok) {
                                    document.getElementById('action-status').innerHTML = '<span style="color: #28a745;">✓ Registration removed successfully!</span>';
                                    setTimeout(() => {
                                        document.getElementById('signup-details-modal').style.display = 'none';
                                        loadUserSignups();
                                    }, 1500);
                                } else {
                                    const error = await response.json();
                                    document.getElementById('action-status').innerHTML = '<span style="color: #dc3545;">Error: ' + (error.message || 'Failed to remove') + '</span>';
                                }
                            } catch (error) {
                                document.getElementById('action-status').innerHTML = '<span style="color: #dc3545;">Error: ' + error.message + '</span>';
                            }
                        }
                    });
                    
                    // Change team button
                    document.getElementById('change-team').addEventListener('click', async function() {
                        const newTeamName = prompt('Enter the name of the team you want to switch to:');
                        if (newTeamName && newTeamName.trim()) {
                            try {
                                const response = await fetch(apiBase + '/my-signups/' + signup.signup_id, {
                                    method: 'PUT',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ team_name: newTeamName.trim() })
                                });
                                
                                if (response.ok) {
                                    document.getElementById('action-status').innerHTML = '<span style="color: #28a745;">✓ Team changed successfully!</span>';
                                    setTimeout(() => {
                                        document.getElementById('signup-details-modal').style.display = 'none';
                                        loadUserSignups();
                                    }, 1500);
                                } else {
                                    const error = await response.json();
                                    document.getElementById('action-status').innerHTML = '<span style="color: #dc3545;">Error: ' + (error.message || 'Failed to change team') + '</span>';
                                }
                            } catch (error) {
                                document.getElementById('action-status').innerHTML = '<span style="color: #dc3545;">Error: ' + error.message + '</span>';
                            }
                        }
                    });
                    
                } catch (error) {
                    console.error('Error loading signup details:', error);
                    alert('Failed to load signup details');
                }
            }

            // Update team driver
            async function updateTeamDriver(teamId, campaignId, driverName) {
                const statusDiv = document.getElementById('driver-status');
                statusDiv.innerHTML = '<span style="color: #666;">Saving...</span>';
                
                try {
                    const response = await fetch(apiBase + '/team-driver', {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            team_id: teamId,
                            campaign_id: campaignId,
                            driver_name: driverName,
                            updated_by: userData.name
                        })
                    });
                    
                    if (response.ok) {
                        statusDiv.innerHTML = '<span style="color: #28a745; font-weight: 600;">✓ Driver Updated!</span>';
                        statusDiv.style.background = '#d4edda';
                        statusDiv.style.padding = '8px';
                        statusDiv.style.borderRadius = '4px';
                        
                        setTimeout(() => {
                            statusDiv.style.background = 'transparent';
                            statusDiv.innerHTML = '';
                        }, 2000);
                    } else {
                        const error = await response.json();
                        statusDiv.innerHTML = '<span style="color: #dc3545;">Error: ' + (error.message || 'Failed to save') + '</span>';
                    }
                } catch (error) {
                    statusDiv.innerHTML = '<span style="color: #dc3545;">Error: ' + error.message + '</span>';
                }
            }
            
            // User Step 2: Team Selection → Next
            document.getElementById('user-step2-next').addEventListener('click', function() {
                const teamSearch = document.getElementById('user-team-search').value.trim();
                const newTeamName = document.getElementById('user-new-team').value.trim();
                const errorDiv = document.getElementById('user-step2-error');
                const warningDiv = document.getElementById('user-new-team-warning');
                
                errorDiv.classList.add('hidden');
                
                if (!selectedTeam && !newTeamName) {
                    errorDiv.textContent = 'Please select or create a team';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                // Check if warning is visible (duplicate team name)
                if (newTeamName && warningDiv.style.display !== 'none') {
                    errorDiv.textContent = 'Cannot create duplicate team. Please select existing team from search results.';
                    errorDiv.classList.remove('hidden');
                    return;
                }
                
                if (newTeamName) {
                    selectedTeam = { id: null, name: newTeamName, isNew: true };
                }
                
                loadCampaigns();
                showStep(3);
            });
            
            // User Step 2: Back button
            document.getElementById('user-step2-back').addEventListener('click', () => showStep(1));
            
            // ========== SHARED STEP 3 HANDLERS ==========
            
            // Step 3: Date Selection - Load campaigns
            async function loadCampaigns() {
                try {
                    const response = await fetch(apiBase + '/campaigns');
                    const campaigns = await response.json();
                    
                    // Get list of ALL dates user is already signed up for (any team)
                    const existingDates = userSignups.map(signup => signup.campaign_date);
                    
                    const checkboxesDiv = document.getElementById('dates-checkboxes');
                    if (!campaigns || campaigns.length === 0) {
                        checkboxesDiv.innerHTML = '<p class="help-text">No selling dates available yet. Check back soon!</p>';
                    } else {
                        const availableCampaigns = campaigns.filter(campaign => !existingDates.includes(campaign.date));
                        
                        if (availableCampaigns.length === 0) {
                            checkboxesDiv.innerHTML = '<p class="help-text">You are already signed up for all available dates!</p>';
                        } else {
                            checkboxesDiv.innerHTML = availableCampaigns.map(campaign => {
                                const date = new Date(campaign.date + 'T00:00:00');
                                const formatted = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
                                const displayName = campaign.name ? `${formatted} - ${campaign.name}` : formatted;
                                return `
                                    <label>
                                        <input type="checkbox" name="campaign" value="${campaign.id}">
                                        ${displayName}
                                    </label>
                                `;
                            }).join('');
                        }
                    }
                } catch (error) {
                    console.error('Error loading campaigns:', error);
                    document.getElementById('step3-error').textContent = 'Failed to load selling dates';
                    document.getElementById('step3-error').classList.remove('hidden');
                }
            }

            // Step 3: Submit signup
            document.getElementById('step3-submit').addEventListener('click', async function() {
                const selectedDates = Array.from(document.querySelectorAll('#dates-checkboxes input:checked')).map(cb => cb.value);
                
                if (selectedDates.length === 0) {
                    document.getElementById('step3-error').textContent = 'Please select at least one date';
                    document.getElementById('step3-error').classList.remove('hidden');
                    return;
                }
                
                try {
                    const response = await fetch(apiBase + '/signup', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            team: selectedTeam,
                            user: userData,
                            campaign_ids: selectedDates
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (response.ok) {
                        document.getElementById('step3-success').textContent = 'Signup complete!';
                        document.getElementById('step3-success').classList.remove('hidden');
                        document.getElementById('step3-error').classList.add('hidden');
                        
                        // Reload signups and return to step 2
                        setTimeout(async () => {
                            await loadUserSignups();
                            selectedTeam = null;
                            document.getElementById('user-team-search').value = '';
                            document.getElementById('user-new-team').value = '';
                            showStep(2);
                        }, 1500);
                    } else {
                        throw new Error(result.message || 'Signup failed');
                    }
                } catch (error) {
                    console.error('Error submitting signup:', error);
                    document.getElementById('step3-error').textContent = error.message;
                    document.getElementById('step3-error').classList.remove('hidden');
                }
            });

            // Step 3: Back button
            document.getElementById('step3-back').addEventListener('click', () => showStep(2));

            // ========== MINI REGISTRATION PAGE ==========
            
            async function loadUserRegistrations() {
                document.querySelectorAll('.step-content').forEach(el => el.classList.add('hidden'));
                document.getElementById('mini-reg').classList.remove('hidden');
                
                try {
                    const response = await fetch(apiBase + '/my-signups?phone=' + encodeURIComponent(userData.phone));
                    const data = await response.json();
                    
                    const regList = document.getElementById('reg-list');
                    if (!data || data.length === 0) {
                        regList.innerHTML = '<p>No registrations found.</p>';
                    } else {
                        regList.innerHTML = `
                            <table class="reg-table">
                                <thead>
                                    <tr>
                                        <th>Team</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.map(reg => {
                                        const date = new Date(reg.campaign_date + 'T00:00:00');
                                        const formatted = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                                        const teamDisplay = reg.campaign_name ? `${reg.team_name} - ${reg.campaign_name}` : reg.team_name;
                                        return `
                                            <tr>
                                                <td>${teamDisplay}</td>
                                                <td>${formatted}</td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        `;
                    }
                } catch (error) {
                    console.error('Error loading registrations:', error);
                }
            }

            document.getElementById('add-another-team').addEventListener('click', function() {
                selectedTeam = null;
                // Clear appropriate fields based on mode
                if (signupMode === 'legacy') {
                    document.getElementById('legacy-team-search').value = '';
                    document.getElementById('legacy-new-team').value = '';
                } else {
                    document.getElementById('user-team-search').value = '';
                    document.getElementById('user-new-team').value = '';
                }
                document.getElementById('mini-reg').classList.add('hidden');
                showStep(1);
            });

            // Initialize on page load
            loadSettings();
        </script>
    </body>
    </html>
    <?php
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
        address text DEFAULT NULL,
        lat double DEFAULT NULL,
        lng double DEFAULT NULL,
        status varchar(32) DEFAULT 'unknown',
        updated_at datetime DEFAULT NULL,
        created_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY address_hash (address_hash(64))
    ) {$charset};";
    $wpdb->query( $sql );
}

function order_sync_normalize_address( $addr ) {
    if ( ! $addr ) return '';
    $s = trim( preg_replace('/\s+/', ' ', str_replace( array("\n","\r"), ' ', $addr ) ) );
    
    // Remove any text after "USA" (including trailing numbers/zips) for Google Maps compatibility
    if ( preg_match( '/\bUSA\b/i', $s ) ) {
        $s = preg_replace( '/\bUSA\b.*/i', 'USA', $s );
        $s = trim( $s );
    }
    
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

/**
 * DEPRECATED: Admin Delivery page (inline version)
 * 
 * This function is NO LONGER USED as of v2.2.1.153.
 * Delivery page now rendered by: Subsales_Admin_Pages::render_delivery_page()
 * Template file: admin/delivery-page.php
 * 
 * @deprecated 2.2.1.153 Use Subsales_Admin_Pages::render_delivery_page() instead
 */
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
    </div>
    <?php
}

// Handle generate delivery export (admin POST)
add_action( 'admin_post_subsales_generate_delivery', 'order_sync_handle_generate_delivery' );
function order_sync_handle_generate_delivery() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions' );
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'subsales_generate_delivery' ) ) wp_die( 'Invalid nonce' );

    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';

    // Delivery date is optional; when omitted export ALL orders.
    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( $_POST['delivery_date'] ) : '';
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( $_POST['start_address'] ) : '';
    update_option( 'order_sync_delivery_start_address', $start_address );
    $driver_count = isset( $_POST['driver_count'] ) ? max(1, intval( $_POST['driver_count'] )) : 2;
    if ( ! empty( $delivery_date ) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s AND deleted = 0 ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        // No date supplied: export all orders
        $rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY id ASC", ARRAY_A );
    }

    $configured_products = order_sync_get_products_config();

    // Build orders list, group by normalized address
    $by_address = array();
    foreach ( $rows as $r ) {
        $od = json_decode( $r['order_data'], true );
        if ( ! is_array( $od ) ) $od = array();

        // build products_map like other code
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
            // fallback legacy fields
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

        // skip donations / orders with no product quantities
        $total_qty = array_sum( array_values( $products_map ) );
        if ( $total_qty <= 0 ) continue;

        // extract fields
        $team_name = '';
        if ( ! empty( $r['team_id'] ) ) {
            $t = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_teams WHERE id = %d", intval( $r['team_id'] ) ) );
            $team_name = $t ? $t->name : '';
        }
        // Get seller name - if not in order data, look up by user_id
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : '' ) );
        if ( empty( $seller ) && ! empty( $r['user_id'] ) ) {
            $member = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", intval( $r['user_id'] ) ) );
            $seller = $member ? $member->name : 'Unknown (ID: ' . $r['user_id'] . ')';
        }
        $customer = isset( $od['customerName'] ) ? $od['customerName'] : ( isset( $od['customer'] ) ? $od['customer'] : ( isset( $od['name'] ) ? $od['name'] : '' ) );
        $phone = isset( $od['cellNumber'] ) ? $od['cellNumber'] : ( isset( $od['cell'] ) ? $od['cell'] : ( isset( $od['phone'] ) ? $od['phone'] : '' ) );
        $address_raw = isset( $od['address'] ) ? $od['address'] : ( isset( $od['formatted_address'] ) ? $od['formatted_address'] : '' );
        $addr_norm = order_sync_normalize_address( $address_raw );
        if ( empty( $addr_norm ) ) continue; // skip if no address

        // products string
        $prod_strs = array();
        foreach ( $products_map as $pid => $q ) {
            if ( $q > 0 ) {
                // find product name
                $pname = $pid;
                foreach ( $configured_products as $pconf ) { if ( $pconf['id'] === $pid ) { $pname = $pconf['name']; break; } }
                $prod_strs[] = $pname . ' × ' . intval( $q );
            }
        }

        $entry = array(
            'order_id' => $r['order_id'],
            'team' => $team_name,
            'seller' => $seller,
            'address_raw' => $address_raw,
            'address_norm' => $addr_norm,
            'products_map' => $products_map,
            'products_str' => implode('; ', $prod_strs),
            'customer' => $customer,
            'phone' => $phone,
            'total_qty' => $total_qty,
        );

        // combine by normalized address
        if ( ! isset( $by_address[ $addr_norm ] ) ) {
            $by_address[ $addr_norm ] = array( 'address_raw' => $address_raw, 'orders' => array() );
        }
        $by_address[ $addr_norm ]['orders'][] = $entry;
    }

    // build flattened list of manifest rows: combine duplicate addresses into single row with aggregated product counts and order ids
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
            // keep first non-empty team/seller/customer/phone as representative (driver manifest will include order ids)
            if ( empty( $team ) && ! empty( $o['team'] ) ) $team = $o['team'];
            if ( empty( $seller ) && ! empty( $o['seller'] ) ) $seller = $o['seller'];
            if ( empty( $customer ) && ! empty( $o['customer'] ) ) $customer = $o['customer'];
            if ( empty( $phone ) && ! empty( $o['phone'] ) ) $phone = $o['phone'];
        }
        // build products string
        $prod_list = array();
        foreach ( $combined_products as $pid => $q ) {
            if ( $q > 0 ) {
                $pname = $pid;
                foreach ( $configured_products as $pconf ) { if ( $pconf['id'] === $pid ) { $pname = $pconf['name']; break; } }
                $prod_list[] = $pname . ' × ' . intval( $q );
            }
        }

        $manifest_rows[] = array(
            'address_raw' => $group['address_raw'],
            'address_norm' => $addr_norm,
            'products_map' => $combined_products,
            'products_str' => implode('; ', $prod_list),
            'order_ids' => $order_ids,
            'team' => $team,
            'seller' => $seller,
            'customer' => $customer,
            'phone' => $phone,
        );
    }

    // Assign to drivers evenly by order count (user requested even distribution by orders).
    // Use a greedy assignment: sort manifest rows by descending order count and assign each row
    // to the driver that currently has the fewest assigned orders. This balances number of
    // orders per driver even when some addresses represent multiple orders.
    $drivers = array();
    $driver_counts = array();
    for ( $i = 1; $i <= $driver_count; $i++ ) { $drivers[ $i ] = array(); $driver_counts[ $i ] = 0; }

    // sort manifest rows by descending number of orders at that address
    usort( $manifest_rows, function( $a, $b ) {
        $ca = isset( $a['order_ids'] ) ? count( $a['order_ids'] ) : 0;
        $cb = isset( $b['order_ids'] ) ? count( $b['order_ids'] ) : 0;
        return $cb - $ca;
    } );

    foreach ( $manifest_rows as $mr ) {
        $count_here = isset( $mr['order_ids'] ) ? count( $mr['order_ids'] ) : 1;
        // find driver with minimum assigned orders
        $min_driver = null;
        $min_count = null;
        foreach ( $driver_counts as $dnum => $cnt ) {
            if ( $min_driver === null || $cnt < $min_count ) { $min_driver = $dnum; $min_count = $cnt; }
        }
        $drivers[ $min_driver ][] = $mr;
        $driver_counts[ $min_driver ] += $count_here;
    }

    // If no orders were found or no manifest rows after filtering, redirect back with a notice
    if ( empty( $rows ) ) {
        $msg = rawurlencode( 'No orders found for ' . $delivery_date );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }
    if ( empty( $manifest_rows ) ) {
        $msg = rawurlencode( 'No valid product orders found for ' . $delivery_date . '. Check products configuration and order data.' );
        wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&subsales_delivery_result=' . $msg ) );
        exit;
    }

    // Stream CSV: single CSV with Driver column and per-product columns
    $filename = 'delivery_manifest_' . ( $delivery_date ? $delivery_date : 'all' ) . '_' . date('Ymd_His') . '.csv';
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    $out = fopen( 'php://output', 'w' );
    // header row: Driver, Address, <product columns...>, Customer, Phone, Seller
    $headers = array( 'Driver', 'Address' );
    foreach ( $configured_products as $pcol ) { $headers[] = $pcol['name']; }
    $headers = array_merge( $headers, array( 'Customer Name', 'Phone', 'Seller' ) );
    fputcsv( $out, $headers );

    foreach ( $drivers as $dnum => $drows ) {
        foreach ( $drows as $r ) {
            $row = array();
            $row[] = $dnum;
            $row[] = $r['address_raw'];
            // product columns
            foreach ( $configured_products as $pcol ) {
                $pid = $pcol['id'];
                $row[] = isset( $r['products_map'][ $pid ] ) ? intval( $r['products_map'][ $pid ] ) : 0;
            }
            $row[] = $r['customer'];
            $row[] = $r['phone'];
            $row[] = $r['seller'];
            fputcsv( $out, $row );
        }
    }
    fclose( $out );
    exit;
}

// The campaigns calendar is no longer its own admin page - it is included by the
// Seasons page's "Sales Days" tab and by the season-setup wizard. Two dead
// wrappers that also included it were removed: campaigns-page.php binds its ~17
// jQuery handlers directly rather than by delegation, so any second include in
// the same request double-fires every AJAX call it makes.

// Delivery Distribution Breakdown page - shows how orders are distributed to members
function subsales_delivery_breakdown_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    include SUBSALES_PLUGIN_PATH . 'admin/delivery-breakdown-page.php';
}

// System Logs page - view all logging activity with filters
/**
 * Address Coverage Report Page
 * Shows which orders have coordinates and which need geocoding
 * 
 * @since 2.4.19
 */
function subsales_address_coverage_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    include SUBSALES_PLUGIN_PATH . 'admin/address-coverage-report.php';
}

/**
 * Address Validation Page
 */
/**
 * GPS Proximity Search Page
 */
function subsales_gps_proximity_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    include SUBSALES_PLUGIN_PATH . 'admin/gps-proximity-report.php';
}

/**
 * Order Entry Distance Analysis Page
 * 
 * @since 2.4.65
 */
function subsales_order_entry_distance_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    include SUBSALES_PLUGIN_PATH . 'admin/order-entry-distance-report.php';
}

/**
 * Tab bar shared by the Logs page and its App Sessions tab.
 *
 * @param string $active 'logs' or 'sessions'.
 */
function subsales_logs_nav_tabs( $active ) {
    ?>
    <h2 class="nav-tab-wrapper">
        <a href="?page=subsales-logs" class="nav-tab <?php echo $active === 'logs' ? 'nav-tab-active' : ''; ?>">System Logs</a>
        <a href="?page=subsales-logs&amp;tab=sessions" class="nav-tab <?php echo $active === 'sessions' ? 'nav-tab-active' : ''; ?>">App Sessions</a>
    </h2>
    <?php
}

/**
 * Logs Page - two tabs: System Logs (default) and App Sessions.
 *
 * Server-side branching (the Teams page pattern) rather than hash tabs: only the
 * active tab's PHP runs, so the two pages' `paged` params and auto-refresh timers
 * can never collide.
 */
function subsales_logs_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    if ( isset( $_GET['tab'] ) && $_GET['tab'] === 'sessions' ) {
        subsales_pwa_sessions_page();
        return;
    }

    global $wpdb;
    $logs_table = $wpdb->prefix . 'ss_logs';
    
    // Handle debug mode toggle via AJAX (handled separately)
    $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
    $debug_started = get_option( 'subsales_debug_logging_started', 0 );
    $debug_remaining = 0;
    if ( $debug_enabled && $debug_started ) {
        $elapsed = time() - $debug_started;
        $debug_remaining = max( 0, ( 24 * 3600 ) - $elapsed );
    }
    
    // Get filter parameters
    $level_filter = isset( $_GET['level'] ) ? sanitize_text_field( $_GET['level'] ) : 'all';
    $category_filter = isset( $_GET['category'] ) ? sanitize_text_field( $_GET['category'] ) : 'all';
    $source_filter = isset( $_GET['source'] ) ? sanitize_text_field( $_GET['source'] ) : 'all';
    $date_filter = isset( $_GET['date_range'] ) ? sanitize_text_field( $_GET['date_range'] ) : 'today';
    $search_query = isset( $_GET['search'] ) ? sanitize_text_field( $_GET['search'] ) : '';
    
    // Show debug logs by default when debug mode is active, or when explicitly requested
    // User can uncheck the box to hide them
    $show_debug_default = $debug_enabled ? '1' : '0';
    $show_debug = isset( $_GET['show_debug'] ) ? ( $_GET['show_debug'] === '1' ) : ( $show_debug_default === '1' );
    
    // Build WHERE clause
    $where = array( '1=1' );
    
    if ( $level_filter !== 'all' ) {
        $where[] = $wpdb->prepare( 'log_level = %s', strtoupper( $level_filter ) );
    }
    
    if ( $category_filter !== 'all' ) {
        $where[] = $wpdb->prepare( 'category = %s', $category_filter );
    }
    
    if ( $source_filter !== 'all' ) {
        $where[] = $wpdb->prepare( 'source = %s', $source_filter );
    }
    
    // Date range filter
    switch ( $date_filter ) {
        case 'hour':
            $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            break;
        case 'today':
            $where[] = "DATE(created_at) = CURDATE()";
            break;
        case 'week':
            $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
    }
    
    // Debug filter
    if ( ! $show_debug ) {
        $where[] = "is_debug = 0";
    }
    
    // Search filter
    if ( ! empty( $search_query ) ) {
        $where[] = $wpdb->prepare( 'message LIKE %s', '%' . $wpdb->esc_like( $search_query ) . '%' );
    }
    
    $where_sql = implode( ' AND ', $where );
    
    // Pagination
    $per_page = 500;
    // Prefixed so it can't collide with the App Sessions tab's own pager.
    $page = isset( $_GET['logs_paged'] ) ? max( 1, intval( $_GET['logs_paged'] ) ) : 1;
    $offset = ( $page - 1 ) * $per_page;
    
    // Get total count
    $total_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table} WHERE {$where_sql}" );
    
    // Get logs
    $logs = $wpdb->get_results(
        "SELECT * FROM {$logs_table} 
         WHERE {$where_sql} 
         ORDER BY created_at DESC 
         LIMIT {$per_page} OFFSET {$offset}",
        ARRAY_A
    );
    
    $total_pages = ceil( $total_count / $per_page );
    
    ?>
    <div class="wrap subsales-logs-page">
        <h1>System Logs</h1>
        <?php subsales_logs_nav_tabs( 'logs' ); ?>

        <!-- Debug Diagnostics -->
        <?php if ( isset( $_GET['diagnostics'] ) && $_GET['diagnostics'] === '1' ): ?>
        <div class="notice notice-info" style="padding: 20px; margin: 20px 0;">
            <h2>🔍 Debug System Diagnostics</h2>
            
            <h3>Option Values:</h3>
            <table class="wp-list-table widefat" style="margin: 10px 0;">
                <tr>
                    <td><strong>subsales_debug_logging_enabled</strong></td>
                    <td><code><?php var_dump( get_option( 'subsales_debug_logging_enabled', 'NOT SET' ) ); ?></code></td>
                </tr>
                <tr>
                    <td><strong>subsales_debug_logging_started</strong></td>
                    <td><code><?php var_dump( get_option( 'subsales_debug_logging_started', 'NOT SET' ) ); ?></code></td>
                </tr>
                <tr>
                    <td><strong>Current Time</strong></td>
                    <td><code><?php echo time(); ?></code> (<?php echo date( 'Y-m-d H:i:s', time() ); ?>)</td>
                </tr>
                <tr>
                    <td><strong>Started Time</strong></td>
                    <td><code><?php echo $debug_started; ?></code> 
                        <?php if ( $debug_started ): ?>
                            (<?php echo date( 'Y-m-d H:i:s', $debug_started ); ?>)
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Elapsed Seconds</strong></td>
                    <td><code><?php echo $debug_started ? ( time() - $debug_started ) : 'N/A'; ?></code></td>
                </tr>
                <tr>
                    <td><strong>Remaining Seconds</strong></td>
                    <td><code><?php echo $debug_remaining; ?></code></td>
                </tr>
            </table>
            
            <h3>Calculated Values:</h3>
            <pre><?php
                echo "debug_enabled: " . var_export( $debug_enabled, true ) . "\n";
                echo "debug_started: " . var_export( $debug_started, true ) . "\n";
                echo "debug_remaining: " . var_export( $debug_remaining, true ) . "\n";
                echo "24 hours in seconds: 86400\n";
                if ( $debug_started ) {
                    echo "Should expire at: " . date( 'Y-m-d H:i:s', $debug_started + 86400 ) . "\n";
                }
            ?></pre>
            
            <h3>Test Log Entry:</h3>
            <?php
                // Write a test log with DEBUG level
                $test_time = time();
                Subsales_Database::log( 'DEBUG', 'system', 'Diagnostics test log entry at ' . date('Y-m-d H:i:s', $test_time), array(
                    'test_id' => $test_time,
                    'debug_enabled' => $debug_enabled,
                    'debug_started' => $debug_started
                ), 'diagnostics' );
                echo '<p style="color: green;">✓ Test DEBUG log written at ' . date('Y-m-d H:i:s', $test_time) . '</p>';
            ?>
            
            <h3>Recent Logs from Database:</h3>
            <?php
                global $wpdb;
                $logs_table = $wpdb->prefix . 'ss_logs';
                $recent_logs = $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY created_at DESC LIMIT 5", ARRAY_A );
                echo '<pre>';
                print_r( $recent_logs );
                echo '</pre>';
            ?>
            
            <p><a href="?page=subsales-logs" class="button">Back to Logs (without diagnostics)</a></p>
        </div>
        <?php endif; ?>
        
        <!-- Debug Mode Toggle -->
        <div class="subsales-debug-toggle" style="background: #fff; border-left: 4px solid <?php echo $debug_enabled ? '#ffc107' : '#ddd'; ?>; border: 1px solid #ddd; border-left: 4px solid <?php echo $debug_enabled ? '#ffc107' : '#ddd'; ?>; padding: 15px; margin: 20px 0; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong>Debug Logging:</strong> 
                    <span id="debug-status"><?php echo $debug_enabled ? 'ACTIVE' : 'Disabled'; ?></span>
                    <?php if ( $debug_enabled && $debug_remaining > 0 ): ?>
                        <span id="debug-timer" style="margin-left: 10px; font-family: monospace;">
                            (<?php echo gmdate( 'H:i:s', $debug_remaining ); ?> remaining)
                        </span>
                    <?php endif; ?>
                    <a href="?page=subsales-logs&diagnostics=1" class="button button-small" style="margin-left: 15px;">Run Diagnostics</a>
                </div>
                <button id="toggle-debug-btn" class="button button-<?php echo $debug_enabled ? 'secondary' : 'primary'; ?>">
                    <?php echo $debug_enabled ? 'Disable Debug Mode' : 'Enable Debug Mode'; ?>
                </button>
            </div>
            <?php if ( $debug_enabled ): ?>
                <p style="margin: 10px 0 0 0; font-size: 12px; color: #856404;">
                    ⚠️ Debug mode generates high volume logs. It will automatically disable after 24 hours.
                </p>
            <?php endif; ?>
        </div>
        
        <!-- Filters -->
        <form method="get" action="" style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
            <input type="hidden" name="page" value="subsales-logs">
            <input type="hidden" name="tab" value="logs">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin-bottom: 10px;">
                <div>
                    <label>Log Level:</label>
                    <select name="level">
                        <option value="all" <?php selected( $level_filter, 'all' ); ?>>All Levels</option>
                        <option value="debug" <?php selected( $level_filter, 'debug' ); ?>>DEBUG</option>
                        <option value="info" <?php selected( $level_filter, 'info' ); ?>>INFO</option>
                        <option value="warning" <?php selected( $level_filter, 'warning' ); ?>>WARNING</option>
                        <option value="error" <?php selected( $level_filter, 'error' ); ?>>ERROR</option>
                        <option value="critical" <?php selected( $level_filter, 'critical' ); ?>>CRITICAL</option>
                    </select>
                </div>
                
                <div>
                    <label>Category:</label>
                    <select name="category">
                        <option value="all" <?php selected( $category_filter, 'all' ); ?>>All Categories</option>
                        <option value="auth" <?php selected( $category_filter, 'auth' ); ?>>Authentication</option>
                        <option value="orders" <?php selected( $category_filter, 'orders' ); ?>>Orders</option>
                        <option value="sync" <?php selected( $category_filter, 'sync' ); ?>>Sync</option>
                        <option value="api" <?php selected( $category_filter, 'api' ); ?>>API</option>
                        <option value="zip" <?php selected( $category_filter, 'zip' ); ?>>ZIP Extracts</option>
                        <option value="system" <?php selected( $category_filter, 'system' ); ?>>System</option>
                    </select>
                </div>
                
                <div>
                    <label>Source:</label>
                    <select name="source">
                        <option value="all" <?php selected( $source_filter, 'all' ); ?>>All Sources</option>
                        <option value="admin" <?php selected( $source_filter, 'admin' ); ?>>Admin</option>
                        <option value="pwa" <?php selected( $source_filter, 'pwa' ); ?>>PWA</option>
                        <option value="api" <?php selected( $source_filter, 'api' ); ?>>API</option>
                        <option value="cron" <?php selected( $source_filter, 'cron' ); ?>>Cron</option>
                    </select>
                </div>
                
                <div>
                    <label>Time Range:</label>
                    <select name="date_range">
                        <option value="hour" <?php selected( $date_filter, 'hour' ); ?>>Last Hour</option>
                        <option value="today" <?php selected( $date_filter, 'today' ); ?>>Today</option>
                        <option value="week" <?php selected( $date_filter, 'week' ); ?>>Last 7 Days</option>
                    </select>
                </div>
                
                <div>
                    <label>
                        <input type="checkbox" name="show_debug" value="1" <?php checked( $show_debug ); ?>>
                        Include DEBUG Logs
                    </label>
                    <?php if ( $debug_enabled ): ?>
                        <br><small style="color: #28a745;">✓ Debug mode active - DEBUG logs shown by default</small>
                    <?php else: ?>
                        <br><small style="color: #6c757d;">Debug mode off - check to view old DEBUG logs</small>
                    <?php endif; ?>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; align-items: flex-end;">
                <div style="flex: 1;">
                    <label>Search Message:</label>
                    <input type="text" name="search" value="<?php echo esc_attr( $search_query ); ?>" style="width: 100%;" placeholder="Search log messages...">
                </div>
                
                <button type="submit" class="button button-primary">Apply Filters</button>
                <a href="?page=subsales-logs" class="button">Reset</a>
                <button type="button" id="download-logs-btn" class="button">Download Current View</button>
                <label style="margin-left: 15px;">
                    <input type="checkbox" id="auto-refresh-toggle" checked> Auto-refresh (5s)
                </label>
            </div>
        </form>
        
        <!-- Logs Table -->
        <div class="logs-container">
            <p><strong><?php echo number_format( $total_count ); ?></strong> log entries found</p>
            
            <table class="wp-list-table widefat fixed striped" id="logs-table">
                <thead>
                    <tr>
                        <th style="width: 140px;">Time</th>
                        <th style="width: 80px;">Level</th>
                        <th style="width: 100px;">Category</th>
                        <th style="width: 80px;">Source</th>
                        <th style="width: 120px;">User</th>
                        <th>Message</th>
                        <th style="width: 60px;">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px;">No logs found matching your filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ( $logs as $log ): 
                            $level_class = 'log-level-' . strtolower( $log['log_level'] );
                            $has_context = ! empty( $log['context_json'] );
                        ?>
                            <tr class="<?php echo esc_attr( $level_class ); ?>">
                                <td><?php echo esc_html( $log['created_at'] ); ?></td>
                                <td><span class="log-badge log-badge-<?php echo esc_attr( strtolower( $log['log_level'] ) ); ?>"><?php echo esc_html( $log['log_level'] ); ?></span></td>
                                <td><?php echo esc_html( $log['category'] ); ?></td>
                                <td><?php echo esc_html( $log['source'] ); ?></td>
                                <td><?php echo $log['user_name'] ? esc_html( $log['user_name'] ) : '-'; ?></td>
                                <td><?php echo esc_html( $log['message'] ); ?></td>
                                <td>
                                    <?php if ( $has_context ): ?>
                                        <button class="button button-small view-context-btn" data-context="<?php echo esc_attr( $log['context_json'] ); ?>">View</button>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ( $total_pages > 1 ): ?>
                <div class="tablenav" style="margin-top: 20px;">
                    <div class="tablenav-pages">
                        <?php
                        $page_links = paginate_links( array(
                            'base' => add_query_arg( 'logs_paged', '%#%' ),
                            'format' => '',
                            'prev_text' => '&laquo; Previous',
                            'next_text' => 'Next &raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ) );
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Context Modal -->
        <div id="context-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 100000; align-items: center; justify-content: center;">
            <div style="background: #fff; padding: 20px; border-radius: 4px; max-width: 800px; max-height: 80vh; overflow: auto;">
                <h2>Log Context Details</h2>
                <pre id="context-content" style="background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto;"></pre>
                <button class="button" id="close-context-modal">Close</button>
            </div>
        </div>
    </div>
    
    <style>
        .log-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .log-badge-debug { background: #6c757d; color: #fff; }
        .log-badge-info { background: #0d6efd; color: #fff; }
        .log-badge-warning { background: #ffc107; color: #000; }
        .log-badge-error { background: #dc3545; color: #fff; }
        .log-badge-critical { background: #6f2232; color: #fff; }
        
        .log-level-error td { background-color: #fff5f5; }
        .log-level-critical td { background-color: #ffe6e6; }
        .log-level-warning td { background-color: #fffbf0; }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Debug toggle
        $('#toggle-debug-btn').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).text('Processing...');
            
            $.post(ajaxurl, {
                action: 'subsales_toggle_debug',
                nonce: '<?php echo wp_create_nonce( 'subsales_toggle_debug' ); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (response.data || 'Unknown error'));
                    btn.prop('disabled', false);
                }
            });
        });
        
        // View context modal
        $('.view-context-btn').on('click', function() {
            let context = $(this).data('context');
            
            // If context is already an object (jQuery auto-parsed it), stringify it
            if (typeof context === 'object') {
                try {
                    const formatted = JSON.stringify(context, null, 2);
                    $('#context-content').text(formatted);
                } catch(e) {
                    $('#context-content').text(String(context));
                }
            } else if (typeof context === 'string') {
                // If it's a string, try to parse and format it
                try {
                    const formatted = JSON.stringify(JSON.parse(context), null, 2);
                    $('#context-content').text(formatted);
                } catch(e) {
                    $('#context-content').text(context);
                }
            } else {
                $('#context-content').text(String(context));
            }
            
            $('#context-modal').css('display', 'flex');
        });
        
        $('#close-context-modal, #context-modal').on('click', function(e) {
            if (e.target === this) {
                $('#context-modal').hide();
            }
        });
        
        // Download logs
        $('#download-logs-btn').on('click', function() {
            const params = new URLSearchParams(window.location.search);
            params.set('download', '1');
            window.location.href = '?' + params.toString();
        });
        
        // Auto-refresh
        let refreshInterval = null;
        let refreshCountdown = 5;
        
        function startAutoRefresh() {
            if (refreshInterval) return;
            refreshInterval = setInterval(function() {
                if (refreshCountdown <= 0) {
                    location.reload();
                } else {
                    refreshCountdown--;
                }
            }, 1000);
        }
        
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
                refreshCountdown = 5;
            }
        }
        
        $('#auto-refresh-toggle').on('change', function() {
            if ($(this).is(':checked')) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });
        
        // Start auto-refresh by default
        if ($('#auto-refresh-toggle').is(':checked')) {
            startAutoRefresh();
        }
        
        // Update debug timer
        <?php if ( $debug_enabled && $debug_remaining > 0 ): ?>
        let remaining = <?php echo $debug_remaining; ?>;
        setInterval(function() {
            if (remaining > 0) {
                remaining--;
                const hours = Math.floor(remaining / 3600);
                const mins = Math.floor((remaining % 3600) / 60);
                const secs = remaining % 60;
                $('#debug-timer').text('(' + 
                    String(hours).padStart(2, '0') + ':' + 
                    String(mins).padStart(2, '0') + ':' + 
                    String(secs).padStart(2, '0') + ' remaining)');
            }
        }, 1000);
        <?php endif; ?>
    });
    </script>
    <?php
}

/**
 * PWA Sessions - the "App Sessions" tab of the Logs page (?page=subsales-logs&tab=sessions).
 * Shows active and historical PWA client sessions with real-time monitoring.
 * Rendered by subsales_logs_page(); no longer a menu callback of its own.
 */
function subsales_pwa_sessions_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    // Get active sessions
    $active_sessions = Subsales_Database::get_active_pwa_sessions( 50 );
    $active_count = count( $active_sessions );

    // Get filter parameters
    $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'active';
    $team_filter = isset( $_GET['team_id'] ) ? intval( $_GET['team_id'] ) : null;
    
    // Get all sessions with pagination
    $per_page = 50;
    // Prefixed so it can't collide with the System Logs tab's own pager.
    $page = isset( $_GET['sessions_paged'] ) ? max( 1, intval( $_GET['sessions_paged'] ) ) : 1;
    $offset = ( $page - 1 ) * $per_page;
    
    // Get more sessions than needed since we'll filter by real-time status
    $fetch_limit = $status_filter === 'all' ? $per_page : $per_page * 3;
    
    $all_sessions = Subsales_Database::get_pwa_sessions( array(
        'status' => 'all', // Get all, we'll filter by real-time status below
        'team_id' => $team_filter,
        'limit' => $fetch_limit,
        'offset' => $offset
    ) );
    
    // Calculate real-time status and filter
    $sessions = array();
    $current_time = current_time( 'timestamp' );
    
    foreach ( $all_sessions as $session ) {
        $logout_time = $session['logout_at'] ? strtotime( $session['logout_at'] ) : null;
        $last_heartbeat = strtotime( $session['last_heartbeat'] );
        $minutes_since_heartbeat = ( $current_time - $last_heartbeat ) / 60;
        
        // Calculate real-time status
        if ( $session['status'] === 'ended' || $logout_time ) {
            $display_status = 'ended';
        } elseif ( $minutes_since_heartbeat <= 5 ) {
            $display_status = 'active';
        } else {
            $display_status = 'idle';
        }
        
        // Apply status filter
        if ( $status_filter === 'all' || $status_filter === $display_status ) {
            $session['_display_status'] = $display_status; // Store for display
            $sessions[] = $session;
            
            // Stop when we have enough
            if ( count( $sessions ) >= $per_page ) {
                break;
            }
        }
    }
    
    // Get available teams for filter
    global $wpdb;
    $teams_table = $wpdb->prefix . 'ss_teams';
    $teams = $wpdb->get_results( "SELECT id, name FROM {$teams_table} ORDER BY name", ARRAY_A );
    
    ?>
    <div class="wrap subsales-pwa-sessions-page">
        <h1>
            <span class="dashicons dashicons-smartphone" style="font-size: 32px; width: 32px; height: 32px;"></span>
            App Client Sessions
        </h1>
        <?php subsales_logs_nav_tabs( 'sessions' ); ?>

        <?php if ( isset( $_GET['debug'] ) && $_GET['debug'] === '1' ): ?>
        <div class="notice notice-info" style="padding: 15px; margin: 20px 0;">
            <h3>Database Debug Information</h3>
            <?php
            $table_name = $wpdb->prefix . 'subsales_pwa_sessions';
            
            // Show all sessions
            $all_sessions = $wpdb->get_results( "SELECT session_id, user_name, team_name, login_at, last_heartbeat, logout_at, status FROM {$table_name} ORDER BY last_heartbeat DESC LIMIT 10", ARRAY_A );
            echo '<h4>Last 10 Sessions (Raw from DB):</h4>';
            echo '<pre>' . print_r( $all_sessions, true ) . '</pre>';
            
            // Show the exact query being used
            $query = $wpdb->prepare(
                "SELECT * FROM {$table_name} 
                 WHERE logout_at IS NULL
                 AND last_heartbeat >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 ORDER BY last_heartbeat DESC
                 LIMIT %d",
                50
            );
            echo '<h4>Active Sessions Query:</h4>';
            echo '<pre>' . $query . '</pre>';
            
            // Show current server time
            $now = $wpdb->get_var( "SELECT NOW()" );
            echo '<h4>Database Server Time:</h4>';
            echo '<pre>' . $now . '</pre>';
            
            // Show 5 minutes ago
            $five_min_ago = $wpdb->get_var( "SELECT DATE_SUB(NOW(), INTERVAL 5 MINUTE)" );
            echo '<h4>5 Minutes Ago (cutoff time):</h4>';
            echo '<pre>' . $five_min_ago . '</pre>';
            ?>
        </div>
        <?php endif; ?>
        
        <!-- Active Sessions Summary -->
        <div class="pwa-sessions-summary" style="background: <?php echo $active_count > 0 ? '#d4edda' : '#fff3cd'; ?>; border-left: 4px solid <?php echo $active_count > 0 ? '#28a745' : '#ffc107'; ?>; padding: 15px; margin: 20px 0; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="margin: 0 0 10px 0;">
                        <span class="dashicons dashicons-yes-alt" style="color: <?php echo $active_count > 0 ? '#28a745' : '#ffc107'; ?>;"></span>
                        <strong><?php echo $active_count; ?></strong> Active Session<?php echo $active_count === 1 ? '' : 's'; ?>
                    </h2>
                    <p style="margin: 0; font-size: 13px; color: #666;">
                        Sessions with heartbeat in the last 5 minutes. Auto-refreshes every 10 seconds.
                    </p>
                </div>
                <button id="refresh-sessions-btn" class="button button-primary">
                    <span class="dashicons dashicons-update"></span> Refresh Now
                </button>
            </div>
        </div>
        
        <!-- Filters -->
        <form method="get" action="" id="pwa-sessions-filters" style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
            <input type="hidden" name="page" value="subsales-logs">
            <input type="hidden" name="tab" value="sessions">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                <div>
                    <label>Status:</label>
                    <select name="status">
                        <option value="all" <?php selected( $status_filter, 'all' ); ?>>All</option>
                        <option value="active" <?php selected( $status_filter, 'active' ); ?>>Active</option>
                        <option value="idle" <?php selected( $status_filter, 'idle' ); ?>>Idle</option>
                        <option value="ended" <?php selected( $status_filter, 'ended' ); ?>>Ended</option>
                    </select>
                </div>
                
                <div>
                    <label>Team:</label>
                    <select name="team_id">
                        <option value="">All Teams</option>
                        <?php foreach ( $teams as $team ): ?>
                        <option value="<?php echo intval( $team['id'] ); ?>" <?php selected( $team_filter, intval( $team['id'] ) ); ?>>
                            <?php echo esc_html( wp_unslash( $team['name'] ) ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px; align-items: flex-end;">
                    <button type="submit" class="button button-primary">Apply Filters</button>
                    <a href="?page=subsales-logs&amp;tab=sessions" class="button">Reset</a>
                </div>
            </div>
        </form>
        
        <!-- Sessions History Table -->
        <h2>Session History</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 120px;">Session ID</th>
                    <th style="width: 150px;">User/Team</th>
                    <th style="width: 120px;">Login</th>
                    <th style="width: 120px;">Last Heartbeat</th>
                    <th style="width: 120px;">Logout</th>
                    <th style="width: 80px;">Duration</th>
                    <th style="width: 80px;">Status</th>
                    <th>User Agent</th>
                    <th style="width: 100px;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $sessions ) ): ?>
                    <tr><td colspan="9" style="text-align: center; padding: 40px;">No sessions found.</td></tr>
                <?php else: ?>
                    <?php foreach ( $sessions as $session ): 
                        $login_time = strtotime( $session['login_at'] );
                        $logout_time = $session['logout_at'] ? strtotime( $session['logout_at'] ) : null;
                        $current_time = current_time( 'timestamp' );
                        $duration = $logout_time ? ( $logout_time - $login_time ) : ( $current_time - $login_time );
                        
                        // Use pre-calculated display status if available
                        $display_status = isset( $session['_display_status'] ) ? $session['_display_status'] : 'ended';
                        
                        $status_colors = array(
                            'active' => '#28a745',
                            'idle' => '#ffc107',
                            'ended' => '#6c757d'
                        );
                        $status_color = isset( $status_colors[ $display_status ] ) ? $status_colors[ $display_status ] : '#ccc';
                    ?>
                    <tr>
                        <td><small style="font-family: monospace;"><?php echo esc_html( substr( $session['session_id'], 0, 16 ) . '...' ); ?></small></td>
                        <td>
                            <strong><?php echo esc_html( $session['user_name'] ?: '(Unknown)' ); ?></strong><br>
                            <small style="color: #666;"><?php echo esc_html( $session['team_name'] ?: 'No Team' ); ?></small>
                        </td>
                        <td><?php echo date( 'M j, g:i a', $login_time ); ?></td>
                        <td><?php echo date( 'M j, g:i a', strtotime( $session['last_heartbeat'] ) ); ?></td>
                        <td><?php echo $logout_time ? date( 'M j, g:i a', $logout_time ) : '—'; ?></td>
                        <td><?php echo gmdate( 'H:i:s', $duration ); ?></td>
                        <td>
                            <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?php echo $status_color; ?>; margin-right: 5px;"></span>
                            <?php echo esc_html( ucfirst( $display_status ) ); ?>
                        </td>
                        <td>
                            <small style="font-family: monospace; font-size: 11px;">
                                <?php 
                                $ua = $session['user_agent'];
                                if ( preg_match( '/(iPhone|iPad|Android|Windows|Mac|Linux)/i', $ua, $matches ) ) {
                                    echo esc_html( $matches[1] );
                                } else {
                                    echo esc_html( substr( $ua, 0, 30 ) . '...' );
                                }
                                ?>
                            </small>
                        </td>
                        <td><small><?php echo esc_html( $session['ip_address'] ); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <p style="margin-top: 20px; color: #666; font-style: italic;">
            💡 <strong>Tip:</strong> Sessions are automatically marked as "idle" after 5 minutes of no heartbeat. 
            Active sessions send a heartbeat every 30 seconds from the app.
        </p>
    </div>
    
    <style>
        .pwa-sessions-summary h2 .dashicons {
            vertical-align: middle;
            margin-right: 5px;
        }
        #refresh-sessions-btn .dashicons {
            vertical-align: middle;
            margin-right: 5px;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Auto-refresh active sessions every 10 seconds
        let autoRefreshInterval = setInterval(function() {
            location.reload();
        }, 10000);
        
        // Manual refresh button
        $('#refresh-sessions-btn').on('click', function() {
            location.reload();
        });
        
        // Stop auto-refresh when user interacts with this tab's filters.
        // Scoped to the sessions filter form - an unscoped 'select, input' would
        // also catch anything else rendered on the page.
        $('#pwa-sessions-filters').find('select, input').on('focus', function() {
            clearInterval(autoRefreshInterval);
            console.log('Auto-refresh paused while editing filters');
        });
    });
    </script>
    <?php
}

/**
 * DEPRECATED: Main dashboard page (inline version)
 * 
 * This function is NO LONGER USED as of v2.2.1.153.
 * Main dashboard now rendered by: Subsales_Admin_Pages::render_main_dashboard()
 * Template file: admin/main-dashboard.php
 * 
 * @deprecated 2.2.1.153 Use Subsales_Admin_Pages::render_main_dashboard() instead
 */
function order_sync_main_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    
    ?>
    <div class="wrap">
        <h1>Subsales Management</h1>
        
        <!-- Sales Mode Toggle and Active Users -->
        <!-- OPTION 1: Compact Inline Toggle (DEFAULT - UNCOMMENT TO USE) -->
        <div class="subsales-mode-controls subsales-option-1" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; display: flex; align-items: center; gap: 30px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <strong style="font-size: 14px;">Sales Mode:</strong>
                <label class="subsales-toggle-switch">
                    <input type="checkbox" id="salesModeToggle" <?php checked( get_option( 'subsales_sales_mode', 'legacy' ), 'user' ); ?> />
                    <span class="subsales-toggle-slider"></span>
                    <span class="subsales-toggle-label-left">Team</span>
                    <span class="subsales-toggle-label-right">Individual</span>
                </label>
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-admin-users" style="color: #2271b1; font-size: 16px;"></span>
                <strong style="font-size: 14px;">Active Users:</strong>
                <span id="activeUserCount" class="subsales-chip" style="background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 13px; cursor: pointer; font-weight: 600; min-width: 24px; text-align: center;" title="Click to view app sessions">0</span>
            </div>
        </div>
        
        <!-- OPTION 2: Segmented Control Style (COMMENT OUT OPTION 1 AND UNCOMMENT TO USE)
        <div class="subsales-mode-controls subsales-option-2" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px; display: flex; align-items: center; gap: 30px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <strong style="font-size: 14px;">Sales Mode:</strong>
                <div class="subsales-segmented-control">
                    <input type="radio" name="salesModeRadio" id="salesModeTeam" value="legacy" <?php checked( get_option( 'subsales_sales_mode', 'legacy' ), 'legacy' ); ?> />
                    <label for="salesModeTeam">Team</label>
                    <input type="radio" name="salesModeRadio" id="salesModeIndividual" value="user" <?php checked( get_option( 'subsales_sales_mode', 'legacy' ), 'user' ); ?> />
                    <label for="salesModeIndividual">Individual</label>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-admin-users" style="color: #2271b1; font-size: 16px;"></span>
                <strong style="font-size: 14px;">Active Users:</strong>
                <span id="activeUserCount" class="subsales-chip" style="background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 13px; cursor: pointer; font-weight: 600; min-width: 24px; text-align: center;" title="Click to view app sessions">0</span>
            </div>
        </div>
        -->
        
        <!-- OPTION 3: Minimal Badge Style (COMMENT OUT OPTION 1 AND UNCOMMENT TO USE)
        <div class="subsales-mode-controls subsales-option-3" style="background: #f0f6fc; padding: 12px 15px; border-left: 4px solid #2271b1; margin-bottom: 20px; display: flex; align-items: center; gap: 25px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <strong style="font-size: 13px; color: #1d2327;">Sales Mode:</strong>
                <div class="subsales-badge-toggle">
                    <button type="button" class="subsales-badge-btn" data-value="legacy">Team</button>
                    <button type="button" class="subsales-badge-btn active" data-value="user">Individual</button>
                </div>
            </div>
            
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="dashicons dashicons-admin-users" style="color: #2271b1; font-size: 16px;"></span>
                <span style="font-size: 13px; color: #1d2327; font-weight: 500;">Active Users:</span>
                <span id="activeUserCount" class="subsales-chip" style="background: #2271b1; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 12px; cursor: pointer; font-weight: 600; min-width: 20px; text-align: center;" title="Click to view app sessions">0</span>
            </div>
        </div>
        -->
        
        <style>
        /* OPTION 1: Compact Inline Toggle Styles */
        .subsales-option-1 .subsales-toggle-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            width: 140px;
            height: 24px;
        }
        .subsales-option-1 .subsales-toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .subsales-option-1 .subsales-toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 38px;
            right: 60px;
            bottom: 0;
            background-color: #ddd;
            transition: .3s;
            border-radius: 12px;
        }
        .subsales-option-1 .subsales-toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .subsales-option-1 input:checked + .subsales-toggle-slider {
            background-color: #2271b1;
        }
        .subsales-option-1 input:checked + .subsales-toggle-slider:before {
            transform: translateX(17px);
        }
        .subsales-option-1 .subsales-toggle-label-left,
        .subsales-option-1 .subsales-toggle-label-right {
            position: absolute;
            font-size: 12px;
            font-weight: 500;
            z-index: 1;
            user-select: none;
        }
        .subsales-option-1 .subsales-toggle-label-left {
            left: 0;
            color: #2c3338;
        }
        .subsales-option-1 .subsales-toggle-label-right {
            right: 0;
            color: #787c82;
        }
        .subsales-option-1 input:checked ~ .subsales-toggle-label-left {
            color: #787c82;
        }
        .subsales-option-1 input:checked ~ .subsales-toggle-label-right {
            color: #2c3338;
        }
        
        /* OPTION 2: Segmented Control Styles */
        .subsales-segmented-control {
            display: inline-flex;
            background: #f0f0f0;
            border-radius: 6px;
            padding: 2px;
        }
        .subsales-segmented-control input[type="radio"] {
            display: none;
        }
        .subsales-segmented-control label {
            padding: 4px 14px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 4px;
            transition: all 0.2s;
            color: #50575e;
            margin: 0;
        }
        .subsales-segmented-control input[type="radio"]:checked + label {
            background: #2271b1;
            color: #fff;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }
        .subsales-segmented-control label:hover {
            color: #2271b1;
        }
        .subsales-segmented-control input[type="radio"]:checked + label:hover {
            color: #fff;
        }
        
        /* OPTION 3: Badge Toggle Styles */
        .subsales-badge-toggle {
            display: inline-flex;
            gap: 6px;
        }
        .subsales-badge-btn {
            padding: 4px 12px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid #c3c4c7;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            color: #2c3338;
        }
        .subsales-badge-btn:hover {
            border-color: #2271b1;
            color: #2271b1;
        }
        .subsales-badge-btn.active {
            background: #2271b1;
            border-color: #2271b1;
            color: #fff;
            box-shadow: 0 1px 3px rgba(34, 113, 177, 0.3);
        }
        
        /* Common styles */
        .subsales-chip {
            display: inline-block;
        }
        #activeUsersPopup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 999999;
        }
        #activeUsersOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999998;
        }
        </style>
        
        <script>
        (function($) {
            // OPTION 1: Toggle switch handler
            $('#salesModeToggle').on('change', function() {
                const mode = this.checked ? 'user' : 'legacy';
                updateSalesMode(mode);
            });
            
            // OPTION 2: Segmented control handler
            $('input[name="salesModeRadio"]').on('change', function() {
                const mode = this.value;
                updateSalesMode(mode);
            });
            
            // OPTION 3: Badge toggle handler
            $('.subsales-badge-btn').on('click', function() {
                const mode = $(this).data('value');
                $('.subsales-badge-btn').removeClass('active');
                $(this).addClass('active');
                updateSalesMode(mode);
            });
            
            // Initialize option 3 active state
            const currentMode = <?php echo wp_json_encode( get_option( 'subsales_sales_mode', 'legacy' ) ); ?>;
            $('.subsales-badge-btn[data-value="' + currentMode + '"]').addClass('active');
            
            // Common update function
            function updateSalesMode(mode) {
                const modeName = mode === 'user' ? 'Individual' : 'Team';
                
                $.post(ajaxurl, {
                    action: 'subsales_update_sales_mode',
                    mode: mode,
                    nonce: '<?php echo wp_create_nonce( 'subsales_sales_mode' ); ?>'
                }, function(response) {
                    if (response.success) {
                        $('<div class="notice notice-success is-dismissible"><p>Sales mode changed to <strong>' + modeName + '</strong></p></div>')
                            .insertAfter('.wrap h1')
                            .delay(3000)
                            .fadeOut(function() { $(this).remove(); });
                    } else {
                        alert('Failed to update sales mode: ' + (response.data || 'Unknown error'));
                        // Revert to previous state
                        location.reload();
                    }
                });
            }
            
            // Active users - click to view App Sessions page
            $('#activeUserCount').on('click', function() {
                window.location.href = 'admin.php?page=subsales-pwa-sessions';
            });
            
            // Update active user count with real data
            function updateActiveUserCount() {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'subsales_get_active_sessions_count',
                        nonce: '<?php echo wp_create_nonce( 'subsales_sessions_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success && typeof response.data.count !== 'undefined') {
                            $('#activeUserCount').text(response.data.count);
                        }
                    }
                });
            }
            
            updateActiveUserCount();
            // Refresh count every 30 seconds
            setInterval(updateActiveUserCount, 30000);
        })(jQuery);
        </script>
        
        <div class="subsales-compact-toggle-wrap">
            <button id="subsales-compact-toggle" class="button">Compact view</button>
            <span class="description">Toggle compact/comfortable dashboard spacing (stored in your browser).</span>
        </div>
      
        <?php
        global $wpdb;
        $orders_table = $wpdb->prefix . 'ss_orders';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        $order_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$orders_table}" );
        $team_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table} WHERE status = 'active'" );
        $team_count_inactive = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table} WHERE status = 'inactive'" );
        $member_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table} WHERE status = 'active'" );
        $member_count_inactive = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table} WHERE status = 'inactive'" );
        ?>
        
        <?php
        // Enqueue admin dashboard CSS
        // The stylesheet is registered/enqueued via admin hook; ensure it's available on this page.
        ?>

        <div class="dashboard-widgets-wrap">
            <div class="metabox-holder subsales-dashboard-grid">
                <script>
                (function(){
                    var key = 'subsales_compact';
                    function getRoot(){ return document.querySelector('.metabox-holder.subsales-dashboard-grid') || document.querySelector('.subsales-dashboard-grid'); }
                    function applyState(state){ var root = getRoot(); if(!root) return; if(state){ root.classList.add('subsales-compact'); } else { root.classList.remove('subsales-compact'); } var btn = document.getElementById('subsales-compact-toggle'); if(btn) btn.textContent = state ? 'Comfortable view' : 'Compact view'; }
                    // init from storage
                    try{ var stored = localStorage.getItem(key) === '1'; applyState(stored); }catch(e){}
                    document.addEventListener('DOMContentLoaded', function(){ var btn = document.getElementById('subsales-compact-toggle'); if(!btn) return; btn.addEventListener('click', function(){ try{ var cur = localStorage.getItem(key) === '1'; var next = !cur; localStorage.setItem(key, next ? '1' : '0'); applyState(next); }catch(e){} }); });
                })();
                </script>
                <?php
                // Compute financial summary (product sales, donations, cash, checks) by scanning existing orders
                $product_sales_total = 0.0;
                $donations_total = 0.0;
                $cash_total = 0.0;
                $check_total = 0.0;
                $rows_fin = $wpdb->get_results( "SELECT order_data FROM {$orders_table}", ARRAY_A );
                if ( $rows_fin ) {
                    $conf_prods_for_fin = order_sync_get_products_config();
                    foreach ( $rows_fin as $rf ) {
                        $od = json_decode( $rf['order_data'], true );
                        if ( ! is_array( $od ) ) continue;
                        $order_product_total = 0.0;
                        $order_donation = 0.0;
                        if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                            foreach ( $od['products'] as $pr ) {
                                $qty = isset( $pr['qty'] ) ? intval( $pr['qty'] ) : 0;
                                $price = isset( $pr['price'] ) ? floatval( $pr['price'] ) : 0.0;
                                if ( $qty > 0 ) $order_product_total += $qty * $price;
                            }
                        } else {
                            if ( is_array( $conf_prods_for_fin ) ) {
                                foreach ( $conf_prods_for_fin as $p ) {
                                    $pid = $p['id'];
                                    $price = isset( $p['price'] ) ? floatval( $p['price'] ) : 0.0;
                                    $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                                    foreach ( $labels as $k ) {
                                        if ( isset( $od[ $k ] ) ) { $q = intval( $od[ $k ] ); if ( $q > 0 ) $order_product_total += $q * $price; break; }
                                    }
                                }
                            }
                        }
                        if ( isset( $od['donationAmount'] ) ) $order_donation = floatval( $od['donationAmount'] );
                        $product_sales_total += $order_product_total;
                        $donations_total += $order_donation;
                        $order_total = $order_product_total + $order_donation;
                        $payment = '';
                        if ( isset( $od['paymentMethod'] ) && ! empty( $od['paymentMethod'] ) ) $payment = strtolower( $od['paymentMethod'] );
                        else if ( ! empty( $od['checkNumber'] ) ) $payment = 'check';
                        else if ( ! empty( $od['payCash'] ) || ! empty( $od['pay_cash'] ) ) $payment = 'cash';
                        if ( $payment === 'check' ) $check_total += $order_total;
                        elseif ( $payment === 'cash' ) $cash_total += $order_total;
                    }
                }
                ?>
                <!-- Row 1: Teams, Members, Orders, Address Data -->
                <div class="subsales-top-row">
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-groups" aria-hidden="true"></span> Teams</h2></div>
                        <div class="inside">
                            <div class="stat-container">
                                <p class="stat-value"><?php echo intval( $team_count ); ?></p>
                                <?php if ( $team_count_inactive > 0 ): ?>
                                    <p class="stat-inactive"><?php echo intval( $team_count_inactive ); ?> inactive</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-admin-users" aria-hidden="true"></span> Members</h2></div>
                        <div class="inside">
                            <div class="stat-container">
                                <p class="stat-value"><?php echo intval( $member_count ); ?></p>
                                <?php if ( $member_count_inactive > 0 ): ?>
                                    <p class="stat-inactive"><?php echo intval( $member_count_inactive ); ?> inactive</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-cart" aria-hidden="true"></span> Orders</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo intval( $order_count ); ?></p>
                        </div>
                    </div>
                    <?php
                    // ZIP Data Status Widget
                    $upload = wp_upload_dir();
                    $zipdata_dir = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata/';
                    $zip_files = is_dir( $zipdata_dir ) ? glob( $zipdata_dir . '*.json' ) : array();
                    $zip_count = is_array( $zip_files ) ? count( $zip_files ) : 0;
                    $newest_time = 0;
                    if ( is_array( $zip_files ) && ! empty( $zip_files ) ) {
                        foreach ( $zip_files as $file ) {
                            $mtime = filemtime( $file );
                            if ( $mtime > $newest_time ) $newest_time = $mtime;
                        }
                    }
                    $six_months_ago = strtotime( '-6 months' );
                    $needs_refresh = ( $zip_count > 0 && $newest_time > 0 && $newest_time < $six_months_ago );
                    $age_text = '';
                    if ( $newest_time > 0 ) {
                        $age_days = floor( ( time() - $newest_time ) / 86400 );
                        if ( $age_days < 30 ) {
                            $age_text = $age_days . ' day' . ( $age_days != 1 ? 's' : '' ) . ' old';
                        } else {
                            $age_months = floor( $age_days / 30 );
                            $age_text = $age_months . ' month' . ( $age_months != 1 ? 's' : '' ) . ' old';
                        }
                    }
                    ?>
                    <?php
                    // Get configured ZIP codes
                    $served_zips = subsales_get_served_zips();
                    $zips_configured = ! empty( $served_zips );
                    $zip_array = $served_zips;
                    ?>
                    <div class="postbox subsales-box">
                        <div class="postbox-header">
                            <h2>
                                <span class="ss-icon dashicons dashicons-location-alt" aria-hidden="true"></span> 
                                Address Data
                            </h2>
                        </div>
                        <div class="inside subsales-address-data-inside<?php echo $age_text ? ' has-age' : ''; ?>">
                            <?php if ( ! $zips_configured ) : ?>
                                <p class="subsales-address-data-warning">
                                    <span class="dashicons dashicons-warning"></span>
                                    No ZIP codes configured
                                </p>
                                <p class="subsales-address-data-action">
                                    <a href="<?php echo admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' ); ?>" class="button button-small button-primary">
                                        Configure ZIPs
                                    </a>
                                </p>
                            <?php elseif ( $zip_count === 0 ) : ?>
                                <p class="subsales-address-data-warning">
                                    <span class="dashicons dashicons-info"></span>
                                    <?php echo count( $zip_array ); ?> ZIP<?php echo count( $zip_array ) != 1 ? 's' : ''; ?> configured
                                </p>
                                <p class="subsales-address-data-label" style="margin: 4px 0; font-size: 12px; color: #666;">
                                    No data files generated yet
                                </p>
                                <p class="subsales-address-data-action">
                                    <a href="<?php echo admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' ); ?>" class="button button-small button-primary">
                                        Generate Data
                                    </a>
                                </p>
                            <?php else : ?>
                                <p class="stat-value subsales-address-data-count"><?php echo intval( $zip_count ); ?></p>
                                <p class="subsales-address-data-label">
                                    ZIP code<?php echo $zip_count != 1 ? 's' : ''; ?> loaded
                                </p>
                                <?php if ( $age_text ) : ?>
                                    <div class="subsales-address-data-age-bar<?php echo $needs_refresh ? ' needs-refresh' : ''; ?>">
                                        <?php if ( $needs_refresh ) : ?>
                                            <span class="dashicons dashicons-warning"></span>
                                        <?php endif; ?>
                                        <?php echo esc_html( $age_text ); ?>
                                    </div>
                                <?php endif; ?>
                                <p class="subsales-address-data-action">
                                    <a href="<?php echo admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' ); ?>" class="button button-small">
                                        <?php echo $needs_refresh ? 'Regenerate' : 'Manage'; ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Row 2: Product Sales, Donations, Cash, Checks -->
                <div class="subsales-financial-row">
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-cart" aria-hidden="true"></span> Product Sales</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo '$' . number_format( (float) $product_sales_total, 2 ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-heart" aria-hidden="true"></span> Donations</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo '$' . number_format( (float) $donations_total, 2 ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-money" aria-hidden="true"></span> Cash</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo '$' . number_format( (float) $cash_total, 2 ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon subsales-checkbook-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true">
                                <rect x="1" y="4" width="22" height="14" rx="2" />
                                <path d="M4 8h16" />
                                <path d="M6 14l3 3 8-8" />
                            </svg>
                        </span> Checks</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo '$' . number_format( (float) $check_total, 2 ); ?></p>
                        </div>
                    </div>
                </div>

                <?php
                // Compute aggregate totals from configured products. New orders include a `products` array in order_data.
                $products_conf = order_sync_get_products_config();
                // initialize totals
                $product_totals = array();
                foreach ( $products_conf as $p ) {
                    $product_totals[ $p['id'] ] = 0;
                }
                // Scan orders and sum quantities from order_data.products if present
                $rows = $wpdb->get_results( "SELECT order_data FROM {$orders_table}", ARRAY_A );
                if ( $rows ) {
                    foreach ( $rows as $r ) {
                        $od = json_decode( $r['order_data'], true );
                        if ( is_array( $od ) ) {
                            // If new structured `products` array exists, prefer it
                            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) {
                                foreach ( $od['products'] as $op ) {
                                    if ( isset( $op['id'] ) && isset( $op['qty'] ) ) {
                                        $pid = $op['id'];
                                        $qty = intval( $op['qty'] );
                                        if ( isset( $product_totals[ $pid ] ) ) $product_totals[ $pid ] += $qty;
                                    }
                                }
                            } else {
                                // fallback: try to match legacy qty fields (e.g., turkeyQty -> turkey) when product id equals the legacy key minus 'Qty'
                                foreach ( $products_conf as $p ) {
                                    $pid = $p['id'];
                                    // try pid + 'Qty' or pid + '_qty' variants
                                    $k1 = $pid . 'Qty'; $k2 = $pid . '_qty';
                                    if ( isset( $od[ $k1 ] ) ) $product_totals[ $pid ] += intval( $od[ $k1 ] );
                                    elseif ( isset( $od[ $k2 ] ) ) $product_totals[ $pid ] += intval( $od[ $k2 ] );
                                }
                            }
                        }
                    }
                }

                // Render a postbox for each configured product (visible or not)
                if ( ! empty( $products_conf ) ) {
                    echo '<div class="subsales-second-row">';
                    foreach ( $products_conf as $p ) {
                        $title = esc_html( $p['name'] );
                        $total = isset( $product_totals[ $p['id'] ] ) ? intval( $product_totals[ $p['id'] ] ) : 0;
                        ?>
                        <div class="postbox">
                            <div class="postbox-header"><h2><?php echo $title; ?></h2></div>
                            <div class="inside">
                                <p style="font-size: 36px; font-weight: bold; margin: 0; color: #0073aa; text-align: center;">
                                    <?php echo $total; ?>
                                </p>
                            </div>
                        </div>
                        <?php
                    }
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        
        <div style="margin-top: 30px;">
            <h2>Quick Actions</h2>
            <p>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-settings' ); ?>" class="button button-primary">Configure Settings</a>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-teams' ); ?>" class="button">Manage Teams</a>
                <a href="<?php echo admin_url( 'admin.php?page=subsales-orders' ); ?>" class="button">View Orders</a>
            </p>
        </div>
    </div>
    <?php
}

// AJAX preview for delivery routes (admin only)
add_action( 'wp_ajax_subsales_delivery_preview', 'order_sync_ajax_delivery_preview' );
function order_sync_ajax_delivery_preview() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }
    check_ajax_referer( 'subsales_delivery_preview', '_ajax_nonce' );

    global $wpdb;
    $orders_table = $wpdb->prefix . 'ss_orders';
    $driver_count = isset( $_POST['driver_count'] ) ? max(1, intval( $_POST['driver_count'] ) ) : 2;
    $start_address = isset( $_POST['start_address'] ) ? sanitize_text_field( wp_unslash( $_POST['start_address'] ) ) : '';

    // allow optional delivery_date filter (YYYY-MM-DD)
    $delivery_date = isset( $_POST['delivery_date'] ) ? sanitize_text_field( wp_unslash( $_POST['delivery_date'] ) ) : '';
    if ( $delivery_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $delivery_date) ) {
        $start_dt = $delivery_date . ' 00:00:00';
        $end_dt = $delivery_date . ' 23:59:59';
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$orders_table} WHERE created_at >= %s AND created_at <= %s AND deleted = 0 ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$orders_table} WHERE deleted = 0 ORDER BY id ASC", ARRAY_A );
    }
    if ( ! $rows ) {
        wp_send_json_error( 'No orders found' );
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
        // Get seller name - if not in order data, look up by user_id
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : '' ) );
        if ( empty( $seller ) && ! empty( $r['user_id'] ) ) {
            $member = $wpdb->get_row( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d", intval( $r['user_id'] ) ) );
            $seller = $member ? $member->name : 'Unknown (ID: ' . $r['user_id'] . ')';
        }
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

    if ( empty( $by_address ) ) wp_send_json_error( 'No valid product orders found' );

    // Build manifest rows aggregated per address
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

    // Assign to drivers using same greedy algorithm
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

    // Geocode addresses (use cache helper) and prepare payload
    $payload_drivers = array();
    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    foreach ( $drivers as $dnum => $rows_driver ) {
        $payload_drivers[ $dnum ] = array();
        foreach ( $rows_driver as $r ) {
            $geo = order_sync_geocode_address( $r['address_raw'] );
            $lat = $geo && isset( $geo['lat'] ) ? $geo['lat'] : null;
            $lng = $geo && isset( $geo['lng'] ) ? $geo['lng'] : null;
            $payload_drivers[ $dnum ][] = array_merge( $r, array( 'lat' => $lat, 'lng' => $lng ) );
        }
    }

    $result = array( 'drivers' => $payload_drivers, 'api_key' => $api_key, 'products' => $configured_products, 'start_address' => $start_address );
    wp_send_json_success( $result );
}
// Admin settings page
// Enqueue admin styles for the plugin dashboard (register at global scope)
add_action('admin_enqueue_scripts', 'subsales_enqueue_admin_assets');
function subsales_enqueue_admin_assets($hook){
    // Only load on our plugin pages (basic guard: check for our page slug in $_GET)
    $load = false;
    if ( isset($_GET['page']) && strpos($_GET['page'], 'subsales') === 0 ) $load = true;
    if ( ! $load ) return;
    
    // Enqueue jQuery for AJAX and DOM manipulation
    wp_enqueue_script('jquery');
    
    $css_path = plugin_dir_path(__FILE__) . 'assets/css/admin-dashboard.css';
    $css_url = plugin_dir_url( __FILE__ ) . 'assets/css/admin-dashboard.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style( 'subsales-admin-dashboard', $css_url, array('dashicons'), filemtime( $css_path ) );
    }
}

/**
 * DEPRECATED: Admin settings page (inline version)
 * 
 * This function is NO LONGER USED as of v2.2.1.153.
 * Settings page now rendered by: Subsales_Admin_Pages::render_settings_page()
 * Template file: admin/settings-page.php
 * 
 * @deprecated 2.2.1.153 Use Subsales_Admin_Pages::render_settings_page() instead
 */
function order_sync_settings_page() {
    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    // Handle per-panel form submission
    if ( isset( $_POST['save_overall'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';
        $sync_interval = isset( $_POST['sync_interval'] ) ? intval( $_POST['sync_interval'] ) : 300;
        $portal_slug = isset( $_POST['portal_slug'] ) ? sanitize_title( $_POST['portal_slug'] ) : 'subsales-portal';
        $session_duration = isset( $_POST['session_duration'] ) ? intval( $_POST['session_duration'] ) : 86400000;
        $individual_session_duration = isset( $_POST['individual_session_duration'] ) ? intval( $_POST['individual_session_duration'] ) : 1209600000;
        $login_mode = isset( $_POST['login_mode'] ) ? sanitize_text_field( $_POST['login_mode'] ) : 'legacy';

        $old_slug = get_option( 'order_sync_portal_slug', '' );
        update_option( 'order_sync_google_maps_api_key', $api_key );
        update_option( 'order_sync_interval', $sync_interval );
        update_option( 'order_sync_portal_slug', $portal_slug );
        update_option( 'order_sync_session_duration', $session_duration );
        update_option( 'subsales_individual_session_duration', $individual_session_duration );
        update_option( 'order_sync_login_mode', $login_mode );

        if ( $portal_slug !== $old_slug ) {
            order_sync_ensure_pwa_page( $portal_slug );
            flush_rewrite_rules();
        }

        echo '<div class="notice notice-success"><p>Overall settings saved!</p></div>';
    }

    if ( isset( $_POST['save_branding'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        $branding = isset( $_POST['subsales_branding'] ) ? sanitize_text_field( $_POST['subsales_branding'] ) : '';
        $admin_email = isset( $_POST['subsales_admin_email'] ) ? sanitize_email( $_POST['subsales_admin_email'] ) : '';
        $style_variant = isset( $_POST['style_variant'] ) ? sanitize_text_field( $_POST['style_variant'] ) : 'default';
        $primary_color = isset( $_POST['primary_color'] ) ? sanitize_text_field( $_POST['primary_color'] ) : '#2d6cdf';
        $header_image = isset( $_POST['subsales_header_image'] ) ? intval( $_POST['subsales_header_image'] ) : 0;

        update_option( 'subsales_branding', $branding );
        update_option( 'subsales_admin_email', $admin_email );
        update_option( 'order_sync_style_variant', $style_variant );
        update_option( 'order_sync_primary_color', $primary_color );
        update_option( 'subsales_header_image', $header_image );

        echo '<div class="notice notice-success"><p>Branding saved!</p></div>';
    }

    if ( isset( $_POST['save_products'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        // Handle products: repeatable fields product_name[], product_price[], product_visible[], product_id[]
        if ( isset( $_POST['product_name'] ) && is_array( $_POST['product_name'] ) ) {
            $names = $_POST['product_name'];
            $prices = isset( $_POST['product_price'] ) && is_array( $_POST['product_price'] ) ? $_POST['product_price'] : array();
            $visibles = isset( $_POST['product_visible'] ) && is_array( $_POST['product_visible'] ) ? $_POST['product_visible'] : array();
            $ids = isset( $_POST['product_id'] ) && is_array( $_POST['product_id'] ) ? $_POST['product_id'] : array();
            $products = array();
            $count = 0;
            for ( $i = 0; $i < count( $names ) && $count < 10; $i++ ) {
                $name = sanitize_text_field( $names[ $i ] );
                if ( empty( $name ) ) continue;
                $price_raw = isset( $prices[ $i ] ) ? $prices[ $i ] : '0';
                $price = floatval( preg_replace( '/[^0-9.\\-]/', '', $price_raw ) );
                $price = round( $price, 2 );
                $id = isset( $ids[ $i ] ) ? sanitize_title( $ids[ $i ] ) : sanitize_title( $name );
                if ( empty( $id ) ) $id = 'p' . time() . $i;
                $suffix = 1;
                $base_id = $id;
                while ( in_array( $id, array_column( $products, 'id' ) ) ) { $id = $base_id . '-' . $suffix; $suffix++; }
                $visible = in_array( (string)$i, $visibles, true ) || in_array( $id, $visibles, true ) || ( isset( $visibles[ $i ] ) && $visibles[ $i ] );
                $products[] = array( 'id' => $id, 'name' => $name, 'price' => number_format( $price, 2, '.', '' ), 'visible' => $visible ? 1 : 0 );
                $count++;
            }
            update_option( 'order_sync_products', wp_json_encode( $products ) );
            echo '<div class="notice notice-success"><p>Products saved!</p></div>';
        }
    }

    if ( isset( $_POST['save_zipcodes'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        // Handle ZIP codes configuration
        if ( isset( $_POST['zipcode_list'] ) ) {
            $zipcode_input = sanitize_textarea_field( $_POST['zipcode_list'] );
            // Parse comma or newline separated ZIPs
            $zipcodes = preg_split( '/[,\s\n]+/', $zipcode_input, -1, PREG_SPLIT_NO_EMPTY );
            // Validate and clean ZIPs
            $valid_zips = array();
            foreach ( $zipcodes as $zip ) {
                $zip = trim( $zip );
                if ( preg_match( '/^\d{5}$/', $zip ) ) {
                    $valid_zips[] = $zip;
                }
            }
            update_option( 'subsales_served_zips', array_unique( $valid_zips ) );
            echo '<div class="notice notice-success"><p>ZIP codes saved! (' . count( $valid_zips ) . ' valid ZIP codes)</p></div>';
        }
        
        // Handle Census boundary configuration
        if ( isset( $_POST['save_census_config'] ) ) {
            $census_year = isset( $_POST['census_year'] ) ? absint( $_POST['census_year'] ) : date( 'Y' );
            $census_state = isset( $_POST['census_state'] ) ? sanitize_text_field( $_POST['census_state'] ) : '';
            $census_zip_filter = isset( $_POST['census_zip_filter'] ) ? sanitize_text_field( $_POST['census_zip_filter'] ) : '';
            $census_url_pattern = isset( $_POST['census_url_pattern'] ) ? esc_url_raw( $_POST['census_url_pattern'] ) : '';
            
            // Validate year
            if ( $census_year < 2010 || $census_year > date( 'Y' ) + 1 ) {
                $census_year = date( 'Y' );
            }
            
            // Default URL pattern if empty
            if ( empty( $census_url_pattern ) ) {
                $census_url_pattern = 'https://www2.census.gov/geo/tiger/TIGER{year}/ZCTA520/tl_{year}_us_zcta520.zip';
            }
            
            update_option( 'subsales_census_year', $census_year );
            update_option( 'subsales_census_state', $census_state );
            update_option( 'subsales_census_zip_filter', $census_zip_filter );
            update_option( 'subsales_census_url_pattern', $census_url_pattern );
            
            echo '<div class="notice notice-success"><p>Census boundary configuration saved!</p></div>';
        }
    }
    
    if ( isset( $_POST['save_deletion_settings'] ) ) {
        check_admin_referer( 'order_sync_settings_nonce' );
        $delete_on_uninstall = isset( $_POST['subsales_delete_on_uninstall'] ) ? sanitize_text_field( $_POST['subsales_delete_on_uninstall'] ) : 'yes';
        
        if ( $delete_on_uninstall !== 'yes' && $delete_on_uninstall !== 'no' ) {
            $delete_on_uninstall = 'yes'; // Default to safe option
        }
        
        update_option( 'subsales_delete_on_uninstall', $delete_on_uninstall );
        delete_transient( 'subsales_show_deletion_prompt' ); // Clear any pending prompt
        
        subsales_log( 'INFO', 'system', 'Data deletion preference updated', array( 'choice' => $delete_on_uninstall ), 'admin' );
        
        if ( $delete_on_uninstall === 'yes' ) {
            echo '<div class="notice notice-warning"><p><strong>⚠️ Data deletion enabled.</strong> All plugin data will be permanently deleted when you delete this plugin.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p><strong>✅ Data preservation enabled.</strong> Your data will be kept safe even if you delete the plugin.</p></div>';
        }
    }

    // Handle clear data action (danger zone)
    if ( isset( $_POST['clear_data'] ) ) {
        check_admin_referer( 'order_sync_clear_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>Insufficient permissions to clear data.</p></div>';
        } else {
            global $wpdb;
            $cleared = array();
            
            // Check which items were selected
            if ( isset( $_POST['clear_orders'] ) && $_POST['clear_orders'] === '1' ) {
                $table = $wpdb->prefix . 'ss_orders';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                    $wpdb->query( "TRUNCATE TABLE {$table}" );
                    $cleared[] = 'Orders';
                }
            }
            
            if ( isset( $_POST['clear_teams'] ) && $_POST['clear_teams'] === '1' ) {
                $table = $wpdb->prefix . 'ss_teams';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                    $wpdb->query( "TRUNCATE TABLE {$table}" );
                    $cleared[] = 'Teams';
                }
            }
            
            if ( isset( $_POST['clear_members'] ) && $_POST['clear_members'] === '1' ) {
                $members_table = $wpdb->prefix . 'ss_team_members';
                $user_teams_table = $wpdb->prefix . 'ss_user_teams';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$members_table}'" ) === $members_table ) {
                    $wpdb->query( "TRUNCATE TABLE {$members_table}" );
                }
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$user_teams_table}'" ) === $user_teams_table ) {
                    $wpdb->query( "TRUNCATE TABLE {$user_teams_table}" );
                }
                $cleared[] = 'Team Members';
            }
            
            if ( isset( $_POST['clear_addresses'] ) && $_POST['clear_addresses'] === '1' ) {
                $table = $wpdb->prefix . 'ss_addresses';
                if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                    $wpdb->query( "TRUNCATE TABLE {$table}" );
                    $cleared[] = 'Addresses';
                }
            }
            
            if ( isset( $_POST['clear_settings'] ) && $_POST['clear_settings'] === '1' ) {
                order_sync_clear_settings();
                $cleared[] = 'Settings';
            }
            
            if ( ! empty( $cleared ) ) {
                $items = implode( ', ', $cleared );
                echo '<div class="notice notice-success"><p><strong>✅ Data cleared successfully:</strong> ' . esc_html( $items ) . '</p></div>';
            } else {
                echo '<div class="notice notice-warning"><p><strong>⚠️ No items selected.</strong> Please select at least one item to clear.</p></div>';
            }
        }
    }

    $api_key = get_option( 'order_sync_google_maps_api_key', '' );
    $sync_interval = get_option( 'order_sync_interval', 300 );
    $portal_slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
    $delete_on_uninstall = get_option( 'subsales_delete_on_uninstall', 0 );
    $branding = get_option( 'subsales_branding', 'Subsales' );
    $admin_email = get_option( 'subsales_admin_email', get_option( 'admin_email' ) );
    $style_variant = get_option( 'order_sync_style_variant', 'default' );
    $primary_color = get_option( 'order_sync_primary_color', '#2d6cdf' );
    $portal_url = esc_url_raw( home_url( '/' . $portal_slug . '/' ) );
    $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
    $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    ?>
    <div class="wrap">
        <h1>Subsales Settings</h1>
        
        <h2 class="nav-tab-wrapper">
            <a href="#tab-overall" class="nav-tab nav-tab-active" data-target="#tab-overall">Overall Settings</a>
            <a href="#tab-branding" class="nav-tab" data-target="#tab-branding">Branding / Look &amp; Feel</a>
            <a href="#tab-products" class="nav-tab" data-target="#tab-products">Products</a>
            <a href="#tab-address_extracts" class="nav-tab" data-target="#tab-address_extracts">Address Management</a>
            <a href="#tab-backup_restore" class="nav-tab" data-target="#tab-backup_restore">Backup / Restore</a>
            <a href="#tab-system_info" class="nav-tab" data-target="#tab-system_info">System Info</a>
        </h2>
        
        <style>
        .subsales-tab-panel { display: none; margin-top: 20px; }
        .subsales-tab-panel.active { display: block; }
        </style>
        
        <script>
        (function($) {
            $(document).ready(function() {
                function showPanel(target) {
                    $('.subsales-tab-panel').removeClass('active');
                    $(target).addClass('active');
                    $('.nav-tab').removeClass('nav-tab-active');
                    $('.nav-tab[data-target="' + target + '"]').addClass('nav-tab-active');
                }
                
                $('.nav-tab').on('click', function(e) {
                    e.preventDefault();
                    var target = $(this).data('target');
                    showPanel(target);
                    if (history && history.replaceState) {
                        history.replaceState(null, null, target);
                    }
                });
                
                // Initialize from hash or show first panel
                var hash = window.location.hash;
                if (hash && $(hash).length) {
                    showPanel(hash);
                } else {
                    showPanel('#tab-overall');
                }
            });
        })(jQuery);
        </script>
    <?php if ( ! empty( $_GET['subsales_import_result'] ) ) :
            $raw = sanitize_text_field( wp_unslash( $_GET['subsales_import_result'] ) );
            // decode if it was rawurlencoded
            $raw = rawurldecode( $raw );
        ?>
            <div class="notice notice-success"><p><strong>Import result:</strong> <?php echo esc_html( $raw ); ?></p></div>
        <?php endif; ?>
        
        
        <!-- Main Settings Panels -->
            <div class="subsales-tab-panels">
                <div id="tab-overall" class="subsales-tab-panel">
                    <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
                    <input type="hidden" name="panel" value="overall" />
                    <table class="form-table">
                        <tr>
                            <th scope="row">Google Maps API Key</th>
                            <td>
                                <input type="text" id="mapsApiKey" name="api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" />
                                <p class="description">Enter your Google Maps API key. This will be shared with mobile clients after login for map functionality.</p>
                                <p class="description">Need a key? <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Create a Google Maps API key</a> in Google Cloud Console (enable Maps JavaScript / Geocoding APIs).</p>
                                <p>
                                    <button type="button" id="test_maps_key_btn" class="button">Test key</button>
                                    <span id="maps_test_status" style="margin-left:12px; font-weight:600"></span>
                                </p>
                                <div id="maps_test_output" style="white-space:pre-wrap; border:1px solid #eee; padding:8px; margin-top:8px; display:none"></div>
                                <script>
                                (function(){
                                    const ajaxUrl = ajaxurl;
                                    const nonce = <?php echo json_encode( wp_create_nonce( 'subsales_test_maps_key' ) ); ?>;
                                    function setStatus(s){ document.getElementById('maps_test_status').textContent = s; }
                                    document.getElementById('test_maps_key_btn').addEventListener('click', function(){
                                        const key = document.getElementById('mapsApiKey').value || '';
                                        const fd = new FormData();
                                        fd.append('action','subsales_test_maps_key');
                                        fd.append('nonce', nonce);
                                        fd.append('key', key);
                                        const out = document.getElementById('maps_test_output'); out.style.display='block'; out.textContent='Testing...'; setStatus('Testing...');
                                        fetch(ajaxUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(j){
                                            if (!j) { out.textContent = 'No response'; setStatus('Error'); return; }
                                            if (!j.success) {
                                                out.textContent = 'Error: ' + (j.data || 'Unknown'); setStatus('Invalid'); return;
                                            }
                                            const d = j.data;
                                            out.textContent = JSON.stringify(d, null, 2);
                                            setStatus(d.status || 'OK');
                                        }).catch(function(e){ out.textContent = 'Fetch error: ' + e.message; setStatus('Error'); });
                                    });
                                })();
                                </script>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Sync Interval (seconds)</th>
                            <td>
                                <input type="number" name="sync_interval" value="<?php echo esc_attr( $sync_interval ); ?>" min="60" />
                                <p class="description">How often to sync orders (minimum 60 seconds).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Portal Slug</th>
                            <td>
                                <input type="text" name="portal_slug" value="<?php echo esc_attr( $portal_slug ); ?>" class="regular-text" />
                                <p class="description">The public slug (URL path) where the PWA will be available. Default: <code>subsales-portal</code>.</p>
                                <p class="description">Portal URL: <strong><?php echo esc_url( $portal_url ); ?></strong></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Team mode session duration</th>
                            <td>
                                <select name="session_duration">
                                    <option value="120000" <?php selected( 120000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 minutes (Test)</option>
                                    <option value="7200000" <?php selected( 7200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 hours</option>
                                    <option value="43200000" <?php selected( 43200000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>12 hours</option>
                                    <option value="86400000" <?php selected( 86400000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>24 hours (default)</option>
                                    <option value="172800000" <?php selected( 172800000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>2 days</option>
                                    <option value="604800000" <?php selected( 604800000, intval( get_option( 'order_sync_session_duration', 86400000 ) ) ); ?>>7 days</option>
                                </select>
                                <p class="description">Session duration for team-based login (shared devices, typically shorter for security).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Individual mode session duration</th>
                            <td>
                                <select name="individual_session_duration">
                                    <option value="120000" <?php selected( 120000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>2 minutes (Test)</option>
                                    <option value="86400000" <?php selected( 86400000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>24 hours</option>
                                    <option value="259200000" <?php selected( 259200000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>3 days</option>
                                    <option value="604800000" <?php selected( 604800000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>7 days</option>
                                    <option value="1209600000" <?php selected( 1209600000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>14 days (default)</option>
                                    <option value="1814400000" <?php selected( 1814400000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>21 days</option>
                                    <option value="2592000000" <?php selected( 2592000000, intval( get_option( 'subsales_individual_session_duration', 1209600000 ) ) ); ?>>30 days</option>
                                </select>
                                <p class="description">Session duration for individual-user login (personal devices, supports extended 2-week campaigns).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Login Mode</th>
                            <td>
                                <fieldset>
                                    <label style="display: block; margin-bottom: 10px;">
                                        <input type="radio" name="login_mode" value="legacy" <?php checked( $login_mode, 'legacy' ); ?> />
                                        <strong>Legacy Login (Team + Code)</strong>
                                        <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                            Users enter a team name and access code to login. Original authentication method.
                                        </p>
                                    </label>
                                    <label style="display: block; margin-top: 10px;">
                                        <input type="radio" name="login_mode" value="user" <?php checked( $login_mode, 'user' ); ?> />
                                        <strong>User-Based Login (Name + Phone)</strong>
                                        <p class="description" style="margin-left: 24px; margin-top: 4px;">
                                            Users search by name and verify with phone number, then select their team. Requires users to be created in the Teams → Users tab.
                                        </p>
                                    </label>
                                </fieldset>
                                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin-top: 12px;">
                                    <strong>⚠️ Important:</strong> Changing the login mode will affect how users authenticate in the PWA.
                                    Make sure your users know which method to use after switching.
                                </div>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><?php submit_button( 'Save Overall Settings', 'primary', 'save_overall', false ); ?></p>
                    </form>
                </div>

                <div id="tab-branding" class="subsales-tab-panel">
                    <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
                    <input type="hidden" name="panel" value="branding" />
                    <table class="form-table">
                        <tr>
                            <th scope="row">Branding / Group name</th>
                            <td>
                                <input type="text" name="subsales_branding" value="<?php echo esc_attr( $branding ); ?>" class="regular-text" />
                                <p class="description">Optional branding string that will be shown in the PWA header and admin pages.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Admin Contact Email</th>
                            <td>
                                <input type="email" name="subsales_admin_email" value="<?php echo esc_attr( $admin_email ); ?>" class="regular-text" />
                                <p class="description">Email address for support inquiries. Shown to users when they encounter signup errors.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Header image</th>
                            <td>
                                <input type="hidden" id="subsales_header_image" name="subsales_header_image" value="<?php echo esc_attr( $header_image_id ); ?>" />
                                <div id="subsales_header_preview" style="margin-bottom:8px;<?php echo $header_image_url ? '' : 'display:none;'; ?>">
                                    <?php if ( $header_image_url ): ?>
                                        <img src="<?php echo esc_url( $header_image_url ); ?>" style="max-width:200px;height:auto;border:1px solid #ddd;padding:4px;background:#fff" />
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="button" id="subsales_header_select">Select image</button>
                                <button type="button" class="button" id="subsales_header_remove" <?php echo $header_image_url ? '' : 'style="display:none;"'; ?>>Remove image</button>
                                <p class="description">Optional header image that will be shown in the PWA header on mobile clients.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Style options</th>
                            <td>
                                <p class="description">Choose a visual style for the PWA. Samples are shown below; primary color can be customized.</p>
                                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="default" <?php checked( $style_variant, 'default' ); ?> /> Default</label>
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="flat" <?php checked( $style_variant, 'flat' ); ?> /> Flat</label>
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="rounded" <?php checked( $style_variant, 'rounded' ); ?> /> Rounded</label>
                                    <label style="display:flex;align-items:center;gap:8px"><input type="radio" name="style_variant" value="dark" <?php checked( $style_variant, 'dark' ); ?> /> Dark</label>
                                </div>
                                <div id="branding_samples" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
                                    <div class="branding-sample-box" style="padding:8px;border:1px solid #eee;border-radius:6px;min-width:160px">
                                        <div style="margin-bottom:6px;font-weight:600">Button sample</div>
                                        <button id="branding_button_sample" class="subsales-branding-button" style="background:<?php echo esc_attr( $primary_color ); ?>;color:#fff;border:none;padding:8px 12px;border-radius:6px">Primary</button>
                                    </div>
                                    <div class="branding-sample-box" style="padding:8px;border:1px solid #eee;border-radius:6px;min-width:160px">
                                        <div style="margin-bottom:6px;font-weight:600">Header sample</div>
                                        <div id="branding_header_sample" class="subsales-branding-header" style="background:<?php echo esc_attr( $primary_color ); ?>;color:#fff;padding:8px;border-radius:4px;text-align:center"><?php echo esc_html( $branding ); ?></div>
                                    </div>
                                </div>
                                <p style="margin-top:8px">Primary color: <input type="color" name="primary_color" value="<?php echo esc_attr( $primary_color ); ?>" /></p>
                                <p class="description">These options control how the embedded PWA will style buttons and header on mobile clients.</p>
                                <script>
                                (function(){
                                    function applyBrandingVariant(variant){
                                        var btn = document.getElementById('branding_button_sample');
                                        var hdr = document.getElementById('branding_header_sample');
                                        if(!btn || !hdr) return;
                                        // remove existing variant classes
                                        btn.classList.remove('branding-variant-default','branding-variant-flat','branding-variant-rounded','branding-variant-dark');
                                        hdr.classList.remove('branding-variant-default','branding-variant-flat','branding-variant-rounded','branding-variant-dark');
                                        var cls = 'branding-variant-' + (variant || 'default');
                                        btn.classList.add(cls);
                                        hdr.classList.add(cls);
                                    }
                                    function applyPrimaryColor(color){
                                        var btn = document.getElementById('branding_button_sample');
                                        var hdr = document.getElementById('branding_header_sample');
                                        if(btn) btn.style.background = color;
                                        if(hdr) hdr.style.background = color;
                                    }
                                    // wire radio buttons
                                    var radios = document.querySelectorAll('input[name="style_variant"]');
                                    radios.forEach(function(r){ r.addEventListener('change', function(e){ applyBrandingVariant(e.target.value); }); });
                                    // wire color input
                                    var colorInput = document.querySelector('input[name="primary_color"]');
                                    if(colorInput){ colorInput.addEventListener('input', function(e){ applyPrimaryColor(e.target.value); }); }
                                    // initialize on load
                                    var sel = document.querySelector('input[name="style_variant"]:checked');
                                    if(sel) applyBrandingVariant(sel.value);
                                    if(colorInput) applyPrimaryColor(colorInput.value);
                                })();
                                </script>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><?php submit_button( 'Save Branding', 'primary', 'save_branding', false ); ?></p>
                    </form>
                </div>
                
                <?php
                // Products configuration (repeatable control)
                $configured_products = order_sync_get_products_config();
                ?>
                <div id="tab-products" class="subsales-tab-panel">
                    <form method="post" action="">
                    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
                    <input type="hidden" name="panel" value="products" />
                    <table class="form-table">
                        <tr>
                            <td>
                                <div id="products_repeatable">
                                    <table id="products_table" class="widefat subsales-products-table">
                                <thead><tr><th class="col-name">Name</th><th class="col-price">Price (USD)</th><th class="col-visible">Visible</th><th class="col-actions">Actions</th></tr></thead>
                                <tbody>
                                <?php if ( ! empty( $configured_products ) ) : ?>
                                    <?php foreach ( $configured_products as $idx => $p ) : ?>
                                        <tr data-index="<?php echo intval( $idx ); ?>">
                                            <td>
                                                <input type="text" name="product_name[]" class="regular-text product-name" value="<?php echo esc_attr( $p['name'] ?? '' ); ?>" />
                                                <input type="hidden" name="product_id[]" class="product-id" value="<?php echo esc_attr( $p['id'] ?? '' ); ?>" />
                                            </td>
                                            <td><input type="text" name="product_price[]" class="regular-text product-price" value="<?php echo esc_attr( $p['price'] ?? '0.00' ); ?>" /></td>
                                            <td class="col-center"><input type="checkbox" name="product_visible[]" value="<?php echo esc_attr( $p['id'] ?? $idx ); ?>" <?php checked( 1, intval( $p['visible'] ?? 0 ) ); ?> /></td>
                                            <td class="col-center"><button type="button" class="button button-link remove-product">Remove</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                </tbody>
                            </table>
                            <p><button type="button" id="add_product_btn" class="button">Add product</button> <span class="description">Max 10 products.</span></p>
                        </div>
                        <script>
                        (function(){
                            var maxProducts = 10;
                            function slugify(s){ return String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/(^-|-$)/g,'').substr(0,60); }
                            function createRow(name, price, id, visible){
                                var tbody = document.querySelector('#products_table tbody');
                                if (!tbody) return null;
                                var current = tbody.querySelectorAll('tr').length;
                                if (current >= maxProducts) { alert('Maximum products reached ('+maxProducts+')'); return null; }
                                var tr = document.createElement('tr');
                                var tdName = document.createElement('td');
                                var nameInput = document.createElement('input'); nameInput.type='text'; nameInput.name='product_name[]'; nameInput.className='regular-text product-name'; nameInput.value = name || '';
                                var idInput = document.createElement('input'); idInput.type='hidden'; idInput.name='product_id[]'; idInput.className='product-id'; idInput.value = id || '';
                                tdName.appendChild(nameInput); tdName.appendChild(idInput);

                                var tdPrice = document.createElement('td');
                                var priceInput = document.createElement('input'); priceInput.type='text'; priceInput.name='product_price[]'; priceInput.className='regular-text product-price'; priceInput.value = price || '0.00';
                                tdPrice.appendChild(priceInput);

                                var tdVis = document.createElement('td'); tdVis.className='col-center';
                                var visInput = document.createElement('input'); visInput.type='checkbox'; visInput.name='product_visible[]'; visInput.checked = !!visible; visInput.value = id || '';
                                tdVis.appendChild(visInput);

                                var tdAct = document.createElement('td'); tdAct.className='col-center';
                                var remBtn = document.createElement('button'); remBtn.type='button'; remBtn.className='button button-link remove-product'; remBtn.textContent='Remove';
                                tdAct.appendChild(remBtn);

                                tr.appendChild(tdName); tr.appendChild(tdPrice); tr.appendChild(tdVis); tr.appendChild(tdAct);
                                // wire events
                                nameInput.addEventListener('input', function(){
                                    var slug = slugify(nameInput.value) || ('p'+Date.now());
                                    idInput.value = slug;
                                    // keep the visible checkbox value in sync with id
                                    try{ visInput.value = slug; }catch(e){}
                                });
                                remBtn.addEventListener('click', function(){ tr.parentNode.removeChild(tr); });
                                tbody.appendChild(tr);
                                return tr;
                            }
                            document.getElementById('add_product_btn').addEventListener('click', function(){ createRow('', '0.00', 'p' + Date.now(), true); });
                            document.querySelectorAll('#products_table .remove-product').forEach(function(b){ b.addEventListener('click', function(){ var tr = b.closest('tr'); tr && tr.parentNode.removeChild(tr); }); });
                        })();
                        </script>
                            </td>
                        </tr>
                    </table>
                    <p class="submit"><?php submit_button( 'Save Products', 'primary', 'save_products', false ); ?></p>
                    </form>
                </div>

                <div id="tab-address_extracts" class="subsales-tab-panel">
                    <h2 style="margin-top:18px">📍 Address Management</h2>
                    <p>Manage your service area configuration, upload address data, and generate JSON extracts for the PWA.</p>
                    
                    <?php
                    // Include the modern dashboard
                    include plugin_dir_path( __FILE__ ) . 'admin/address-management-dashboard.php';
                    ?>
                </div>

                <div id="tab-backup_restore" class="subsales-tab-panel">
            <h2 style="margin-top:18px">💾 Backup & Restore</h2>
            <p>Export all plugin data for backup or migrate to another WordPress site. Import previously exported backups to restore data.</p>
            
            <!-- Export Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
                <h3 style="margin-top: 0;">📤 Export Data</h3>
                <?php $export_nonce = wp_create_nonce( 'subsales_export_nonce' ); ?>
                
                <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <a class="button button-primary button-hero" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_backup_combined&_wpnonce=' . $export_nonce ) ); ?>">
                        📦 Export Complete Backup (ZIP)
                    </a>
                </div>
                
                <details style="margin-top: 15px;">
                    <summary style="cursor: pointer; font-weight: 600;">Individual Exports</summary>
                    <div style="padding: 15px; margin-top: 10px; background: #f6f7f7; border-radius: 4px;">
                        <p style="margin-top: 0;">Export individual components separately:</p>
                        <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_orders&_wpnonce=' . $export_nonce ) ); ?>">Orders (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_teams&_wpnonce=' . $export_nonce ) ); ?>">Teams (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_members&_wpnonce=' . $export_nonce ) ); ?>">Members (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_addresses&_wpnonce=' . $export_nonce ) ); ?>">Addresses (CSV)</a>
                            <a class="button" href="<?php echo esc_url( admin_url( 'admin-post.php?action=subsales_export_settings&_wpnonce=' . $export_nonce ) ); ?>">Settings (CSV)</a>
                        </div>
                    </div>
                </details>
                
                <div style="background: #e7f3ff; border-left: 4px solid #0071a1; padding: 12px; margin-top: 15px;">
                    <p style="margin: 0;"><strong>💡 Tip:</strong> The complete backup ZIP includes all orders, teams, members, addresses, and settings. Perfect for site migrations or scheduled backups.</p>
                </div>
            </div>
            
            <!-- Import Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-bottom: 20px;">
                <h3 style="margin-top: 0;">📥 Import Data</h3>
                
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field( 'subsales_import_nonce' ); ?>
                    <input type="hidden" name="action" value="subsales_import_backup" />
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Backup File</th>
                            <td>
                                <input type="file" name="backup_file" accept="text/csv,text/plain,application/zip,.zip" required style="margin-bottom: 10px;" />
                                <p class="description">Upload a ZIP backup (complete) or individual CSV file</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Import Options</th>
                            <td>
                                <label style="display: block; margin-bottom: 10px;">
                                    <input type="checkbox" name="import_update_existing" value="1" />
                                    Update existing records (match by ID) - otherwise skip duplicates
                                </label>
                                <p class="description" style="margin: 8px 0 0 0; padding: 8px; background: #e7f7e7; border-left: 3px solid #46b450;">
                                    <strong>🗺️ Auto-Geocoding Enabled:</strong> Addresses without lat/lng coordinates will be automatically geocoded using Google Maps API. 
                                    ZIP codes will be validated against coordinates and auto-corrected if mismatched.
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary button-large">Import Backup</button>
                    </p>
                </form>
            </div>
            
            <!-- Destructive Restore Section -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #d63638; border-radius: 4px; padding: 20px;">
                <h3 style="margin-top: 0; color: #d63638;">⚠️ Advanced: Full Restore (Destructive)</h3>
                <p><strong>Warning:</strong> This will clear existing data before importing. Only use this when restoring from a known good backup.</p>
                
                <details>
                    <summary style="cursor: pointer; font-weight: 600; padding: 10px; background: #f6f7f7; border-radius: 4px;">Show Restore Options</summary>
                    <div style="padding: 15px; margin-top: 10px; border: 1px solid #ddd; border-radius: 4px;">
                        <form id="subsales-destructive-restore" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                            <?php wp_nonce_field( 'subsales_restore_nonce' ); ?>
                            <input type="hidden" name="action" value="subsales_restore_and_import" />
                            
                            <table class="form-table">
                                <tr>
                                    <th scope="row">Backup File</th>
                                    <td><input type="file" name="backup_file" accept="application/zip,.zip,text/csv,text/plain" required /></td>
                                </tr>
                                <tr>
                                    <th scope="row">Clear Before Import</th>
                                    <td>
                                        <label style="display: block; margin: 8px 0;">
                                            <input type="radio" name="restore_target" value="both" checked />
                                            <strong>Everything</strong> - Clear all orders, teams, members, addresses, and settings
                                        </label>
                                        <label style="display: block; margin: 8px 0;">
                                            <input type="radio" name="restore_target" value="data" />
                                            <strong>Data Only</strong> - Clear orders, teams, members, and addresses (keep settings)
                                        </label>
                                        <label style="display: block; margin: 8px 0;">
                                            <input type="radio" name="restore_target" value="settings" />
                                            <strong>Settings Only</strong> - Clear settings (keep data)
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Confirmation</th>
                                    <td>
                                        <label style="font-weight: 600;">
                                            <input type="checkbox" id="confirm_clear_restore" />
                                            I understand this will permanently delete the selected data before importing
                                        </label>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button id="subsales-restore-btn" type="submit" class="button button-large" style="background: #d63638; border-color: #d63638; color: #fff;" disabled>⚠️ Restore from Backup</button>
                            </p>
                        </form>
                        
                        <script>
                        (function(){
                            var chk = document.getElementById('confirm_clear_restore');
                            var btn = document.getElementById('subsales-restore-btn');
                            var form = document.getElementById('subsales-destructive-restore');
                            var radios = form ? form.querySelectorAll('input[name="restore_target"]') : null;
                            
                            if ( chk && btn ) {
                                chk.addEventListener('change', function(){ btn.disabled = !chk.checked; });
                            }
                            
                            if ( form ) {
                                form.addEventListener('submit', function(e){
                                    if ( ! chk.checked ) {
                                        e.preventDefault();
                                        alert('Please confirm the destructive restore by checking the box.');
                                        return false;
                                    }
                                    
                                    var sel = 'both';
                                    if ( radios ) {
                                        radios.forEach(function(r){ if ( r.checked ) sel = r.value; });
                                    }
                                    
                                    var msg = 'This will permanently delete the selected plugin data before importing. Are you sure?';
                                    if ( sel === 'both' ) {
                                        msg = '⚠️ PERMANENT ACTION\n\nThis will delete ALL plugin data (orders, teams, members, addresses, and settings) before importing.\n\nThis cannot be undone.\n\nAre you absolutely sure?';
                                    } else if ( sel === 'data' ) {
                                        msg = 'This will permanently delete ALL orders, teams, members, and addresses before importing. Settings will be preserved. Are you sure?';
                                    } else if ( sel === 'settings' ) {
                                        msg = 'This will permanently delete all plugin settings before importing. Data (orders, teams, etc.) will be preserved. Are you sure?';
                                    }
                                    
                                    if ( ! confirm(msg) ) {
                                        e.preventDefault();
                                        return false;
                                    }
                                });
                            }
                        })();
                        </script>
                    </div>
                </details>
            </div>
            
            <!-- Clear data form -->
            <div style="background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #d63638; border-radius: 4px; padding: 20px; margin-top: 20px;">
                <h3 style="margin-top: 0; color: #d63638;">🗑️ Clear Data (Danger Zone)</h3>
                <p><strong>Warning:</strong> Select what you want to permanently delete. This action cannot be undone.</p>
                
                <form id="subsales-clear-data-form" method="post" action="">
                    <?php wp_nonce_field( 'order_sync_clear_nonce' ); ?>
                    
                    <div style="background: #f6f7f7; border: 1px solid #c3c4c7; padding: 15px; margin: 15px 0; border-radius: 4px;">
                        <p style="margin: 0 0 10px 0; font-weight: 600;">Select data to clear:</p>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_orders" value="1" class="subsales-clear-checkbox" />
                            <strong>Orders</strong> - All order data and customer information
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_teams" value="1" class="subsales-clear-checkbox" />
                            <strong>Teams</strong> - All teams and access codes
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_members" value="1" class="subsales-clear-checkbox" />
                            <strong>Team Members</strong> - All users and team assignments
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_addresses" value="1" class="subsales-clear-checkbox" />
                            <strong>Addresses</strong> - All address data and geocoding results
                        </label>
                        
                        <label style="display: block; margin: 8px 0; padding: 8px; background: #fff; border-radius: 4px; cursor: pointer;">
                            <input type="checkbox" name="clear_settings" value="1" class="subsales-clear-checkbox" />
                            <strong>Settings</strong> - All plugin configuration and options
                        </label>
                        
                        <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                            <label style="cursor: pointer; font-weight: 600;">
                                <input type="checkbox" id="subsales-select-all-clear" />
                                Select All
                            </label>
                        </div>
                    </div>
                    
                    <div id="subsales-clear-warning" style="background: #fcf0f1; border: 1px solid #d63638; padding: 12px; margin: 15px 0; border-radius: 4px; display: none;">
                        <p style="margin: 0; color: #d63638;"><strong>⚠️ Warning:</strong> <span id="subsales-clear-warning-text">Please select at least one item to clear.</span></p>
                    </div>
                    
                    <p style="font-size: 12px; color: #646970; margin: 10px 0;">
                        <strong>Note:</strong> This does NOT delete plugin files, ZIP extracts, or database tables (only truncates/clears their data).
                    </p>
                    
                    <p>
                        <button id="subsales-clear-data-btn" name="clear_data" type="submit" class="button button-large" style="background: #d63638; border-color: #d63638; color: #fff;" disabled>Clear Selected Data</button>
                    </p>
                </form>
                
                <script>
                (function(){
                    var form = document.getElementById('subsales-clear-data-form');
                    var checkboxes = document.querySelectorAll('.subsales-clear-checkbox');
                    var selectAllCheckbox = document.getElementById('subsales-select-all-clear');
                    var clearBtn = document.getElementById('subsales-clear-data-btn');
                    var warningBox = document.getElementById('subsales-clear-warning');
                    var warningText = document.getElementById('subsales-clear-warning-text');
                    
                    function updateWarningAndButton() {
                        var selected = [];
                        checkboxes.forEach(function(cb) {
                            if (cb.checked) {
                                var label = cb.parentElement.querySelector('strong');
                                if (label) selected.push(label.textContent);
                            }
                        });
                        
                        if (selected.length === 0) {
                            clearBtn.disabled = true;
                            warningBox.style.display = 'none';
                        } else {
                            clearBtn.disabled = false;
                            warningBox.style.display = 'block';
                            
                            var itemsList = selected.join(', ');
                            warningText.textContent = 'You are about to permanently delete: ' + itemsList + '. This cannot be undone.';
                        }
                    }
                    
                    // Wire up checkboxes
                    checkboxes.forEach(function(cb) {
                        cb.addEventListener('change', updateWarningAndButton);
                    });
                    
                    // Wire up select all
                    if (selectAllCheckbox) {
                        selectAllCheckbox.addEventListener('change', function() {
                            checkboxes.forEach(function(cb) {
                                cb.checked = selectAllCheckbox.checked;
                            });
                            updateWarningAndButton();
                        });
                    }
                    
                    // Form submission confirmation
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            var selected = [];
                            checkboxes.forEach(function(cb) {
                                if (cb.checked) {
                                    var label = cb.parentElement.querySelector('strong');
                                    if (label) selected.push(label.textContent);
                                }
                            });
                            
                            if (selected.length === 0) {
                                e.preventDefault();
                                alert('Please select at least one item to clear.');
                                return false;
                            }
                            
                            var itemsList = selected.join(', ');
                            var msg = '⚠️ PERMANENT ACTION\n\n';
                            msg += 'This will permanently delete the following:\n';
                            msg += '• ' + selected.join('\n• ') + '\n\n';
                            msg += 'This cannot be undone.\n\n';
                            msg += 'Are you absolutely sure?';
                            
                            if (!confirm(msg)) {
                                e.preventDefault();
                                return false;
                            }
                        });
                    }
                    
                    // Initial state
                    updateWarningAndButton();
                })();
                </script>
            </div>
                </div>

                <div id="tab-system_info" class="subsales-tab-panel">
            <h2>System Information</h2>
        <table class="form-table">
            <tr>
                <th scope="row">Plugin Version</th>
                <td><?php echo esc_html( order_sync_get_plugin_version() ); ?></td>
            </tr>
            <tr>
                <th scope="row">WordPress Version</th>
                <td><?php echo get_bloginfo( 'version' ); ?></td>
            </tr>
            <tr>
                <th scope="row">PHP Version</th>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr>
                <th scope="row">PhpSpreadsheet</th>
                <td>
                    <?php
                    $ps_version = null;
                    // Try Composer InstalledVersions first (if available)
                    if ( class_exists( '\\Composer\\InstalledVersions' ) ) {
                        try {
                            if ( method_exists( '\\Composer\\InstalledVersions', 'getPrettyVersion' ) ) {
                                $ps_version = \Composer\InstalledVersions::getPrettyVersion( 'phpoffice/phpspreadsheet' );
                            }
                        } catch ( Exception $e ) {
                            $ps_version = null;
                        }
                    }
                    // Fallback: detect presence of the main class
                    if ( ! $ps_version && class_exists( 'PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
                        $ps_version = 'installed';
                    }

                    if ( $ps_version ) {
                        echo esc_html( $ps_version );
                    } else {
                        echo '<span style="color:red">Missing</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">DomPDF</th>
                <td>
                    <?php
                    $dompdf_path = plugin_dir_path( __FILE__ ) . 'vendor/dompdf/autoload.inc.php';
                    if ( file_exists( $dompdf_path ) ) {
                        echo '<span style="color:green">Installed</span>';
                        // Try to get version
                        require_once $dompdf_path;
                        if ( defined( 'Dompdf\\VERSION' ) ) {
                            echo ' (v' . esc_html( Dompdf\VERSION ) . ')';
                        }
                    } else {
                        echo '<span style="color:red">Missing</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Database Tables</th>
                <td>
                    <?php
                    global $wpdb;
                    $tables = array(
                        $wpdb->prefix . 'ss_orders' => 'Orders',
                        $wpdb->prefix . 'ss_teams' => 'Teams',
                        $wpdb->prefix . 'ss_team_members' => 'Team Members',
                        $wpdb->prefix . 'ss_user_teams' => 'User-Team Assignments'
                    );
                    
                    foreach ( $tables as $table => $name ) {
                        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
                        echo '<span style="color: ' . ( $exists ? 'green' : 'red' ) . ';">● ' . $name . '</span><br>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Database Schema</th>
                <td>
                    <p>Run this to update database constraints and fix legacy user data:</p>
                    <form method="post" action="">
                        <?php wp_nonce_field( 'subsales_migrate_db' ); ?>
                        <button type="submit" name="run_db_migration" class="button button-primary">Run Database Migration</button>
                    </form>
                    <p class="description">This will: 1) Set default phones for users with NULL/empty phones, 2) Add NOT NULL constraint to phone, 3) Add UNIQUE constraint to phone, 4) Remove UNIQUE constraint from email.</p>
                    <?php
                    if ( isset( $_POST['run_db_migration'] ) ) {
                        check_admin_referer( 'subsales_migrate_db' );
                        if ( current_user_can( 'manage_options' ) ) {
                            // Run the table creation function which includes migration logic
                            order_sync_create_table();
                            echo '<div class="notice notice-success inline" style="margin-top: 10px;"><p>Database migration completed successfully!</p></div>';
                        }
                    }
                    ?>
                </td>
            </tr>
        </table>
                </div>
    </div> <!-- .wrap -->
    <?php
}

// ========================================
// IMPORT/EXPORT FUNCTIONS FOR USERS & TEAMS
// ========================================

/**
 * Export users and teams to CSV
 */
function subsales_export_users_teams() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied' );
    }
    
    global $wpdb;
    $teams_table = $wpdb->prefix . 'ss_teams';
    $members_table = $wpdb->prefix . 'ss_team_members';
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';
    
    $export_type = isset( $_GET['export_type'] ) ? sanitize_text_field( $_GET['export_type'] ) : 'current';
    
    // Set headers for CSV download
    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="subsales-users-teams-' . date( 'Y-m-d' ) . '.csv"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );
    
    $output = fopen( 'php://output', 'w' );
    
    // Write header comments and instructions
    fwrite( $output, "# ===== SUBSALES USERS & TEAMS IMPORT/EXPORT FILE =====\n" );
    fwrite( $output, "# This file contains both team definitions and user assignments.\n" );
    fwrite( $output, "# Edit this file and re-import to update your data.\n" );
    fwrite( $output, "#\n" );
    fwrite( $output, "# IMPORTANT NOTES:\n" );
    fwrite( $output, "# - Import UPSERTS - nothing is ever deleted. Teams are always created\n" );
    fwrite( $output, "#   fresh for the current season (even reusing a name from a prior season);\n" );
    fwrite( $output, "#   members are matched by phone and reactivated if they were inactive.\n" );
    fwrite( $output, "# - Access code is optional - one is generated automatically for a new team;\n" );
    fwrite( $output, "#   any value here is ignored.\n" );
    fwrite( $output, "# - Phone numbers must be exactly 10 digits (leading 1 will be auto-stripped)\n" );
    fwrite( $output, "# - Teams in the USERS section must exist in the TEAMS section\n" );
    fwrite( $output, "# - Use 'active' or 'inactive' for status fields\n" );
    fwrite( $output, "# - Leave email blank to remove email from a user\n" );
    fwrite( $output, "#\n\n" );
    
    // ===== TEAMS SECTION =====
    fwrite( $output, "# ===== TEAMS SECTION =====\n" );
    fwrite( $output, "# Define all teams with their access codes and status\n" );
    fwrite( $output, "# Format: team_name, team_access_code, status\n" );
    
    if ( $export_type === 'template' ) {
        // Template with example data
        fwrite( $output, "# Example: Team Alpha, ALPHA123, active\n" );
        fputcsv( $output, array( 'team_name', 'team_access_code', 'status' ) );
        fputcsv( $output, array( 'Team Alpha', 'ALPHA123', 'active' ) );
        fputcsv( $output, array( 'Team Beta', 'BETA456', 'inactive' ) );
    } else {
        // Export current teams
        fputcsv( $output, array( 'team_name', 'team_access_code', 'status' ) );
        $teams = $wpdb->get_results( "SELECT name, access_code, status FROM {$teams_table} ORDER BY name ASC", ARRAY_A );
        foreach ( $teams as $team ) {
            fputcsv( $output, array(
                $team['name'],
                $team['access_code'],
                $team['status'] ?? 'active'
            ) );
        }
    }
    
    fwrite( $output, "\n" );
    
    // ===== USERS SECTION =====
    fwrite( $output, "# ===== USERS SECTION =====\n" );
    fwrite( $output, "# Assign users to teams\n" );
    fwrite( $output, "# Format: name, phone, email, status, team1, team2, ...\n" );
    fwrite( $output, "# - phone: Must be exactly 10 digits\n" );
    fwrite( $output, "# - email: Can be blank to remove email\n" );
    fwrite( $output, "# - status: 'active' or 'inactive'\n" );
    fwrite( $output, "# - teams: Each team in a separate column (quoted if team name contains commas)\n" );
    
    if ( $export_type === 'template' ) {
        // Template with example data
        fwrite( $output, "# Example: John Doe, 2035551234, john@example.com, active, \"Team Alpha\", \"Team Beta\"\n" );
        fputcsv( $output, array( 'name', 'phone', 'email', 'status', 'teams' ) );
        fputcsv( $output, array( 'John Doe', '2035551234', 'john@example.com', 'active', 'Team Alpha', 'Team Beta' ) );
        fputcsv( $output, array( 'Jane Smith', '2035555678', 'jane@example.com', 'active', 'Team Alpha' ) );
        fputcsv( $output, array( 'Bob Johnson', '2035559999', '', 'inactive', 'Team Beta' ) );
    } else {
        // Export current users with their teams
        fputcsv( $output, array( 'name', 'phone', 'email', 'status', 'teams' ) );
        
        $users = $wpdb->get_results( "SELECT DISTINCT id, name, phone, email, status FROM {$members_table} ORDER BY name ASC", ARRAY_A );
        
        foreach ( $users as $user ) {
            // Get all teams for this user
            $user_teams = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.name 
                FROM {$user_teams_table} ut
                JOIN {$teams_table} t ON ut.team_id = t.id
                WHERE ut.user_id = %d
                ORDER BY t.name ASC",
                $user['id']
            ), ARRAY_A );
            
            $team_names = array_map( function( $t ) { return $t['name']; }, $user_teams );
            
            // Build row: name, phone, email, status, then each team as a separate column
            $row = array(
                $user['name'],
                $user['phone'],
                $user['email'] ?? '',
                $user['status'] ?? 'active'
            );
            // Add each team as a separate column (fputcsv will quote if needed)
            foreach ( $team_names as $team ) {
                $row[] = $team;
            }
            
            fputcsv( $output, $row );
        }
    }
    
    fclose( $output );
    exit;
}

/**
 * Process import file and generate preview
 */
function subsales_process_import_preview( $file ) {
    if ( ! $file || $file['error'] !== UPLOAD_ERR_OK ) {
        return array( 'error' => 'File upload failed. Please try again.' );
    }
    
    if ( $file['type'] !== 'text/csv' && ! str_ends_with( $file['name'], '.csv' ) ) {
        return array( 'error' => 'Invalid file type. Please upload a CSV file.' );
    }
    
    $handle = fopen( $file['tmp_name'], 'r' );
    if ( ! $handle ) {
        return array( 'error' => 'Could not read file.' );
    }
    
    $teams = array();
    $users = array();
    $errors = array();
    $section = null; // 'teams' or 'users'
    $line_num = 0;
    $teams_header_found = false;
    $users_header_found = false;
    
    while ( ( $row = fgetcsv( $handle ) ) !== false ) {
        $line_num++;
        
        // Skip empty rows
        if ( empty( array_filter( $row ) ) ) continue;
        
        // Skip comment lines
        if ( isset( $row[0] ) && str_starts_with( trim( $row[0] ), '#' ) ) {
            // Check for section markers
            if ( stripos( $row[0], 'TEAMS SECTION' ) !== false ) {
                $section = 'teams';
            } elseif ( stripos( $row[0], 'USERS SECTION' ) !== false ) {
                $section = 'users';
            }
            continue;
        }
        
        // Check for headers
        if ( $section === 'teams' && ! $teams_header_found && isset( $row[0] ) && strtolower( trim( $row[0] ) ) === 'team_name' ) {
            $teams_header_found = true;
            continue;
        }
        if ( $section === 'users' && ! $users_header_found && isset( $row[0] ) && strtolower( trim( $row[0] ) ) === 'name' ) {
            $users_header_found = true;
            continue;
        }
        
        // Process team rows (header is optional for teams section)
        if ( $section === 'teams' ) {
            // Remove trailing empty fields from CSV (from trailing commas)
            $row = array_filter( $row, function( $val, $idx ) use ( $row ) {
                // Keep all non-empty fields
                if ( trim( $val ) !== '' ) return true;
                // Keep empty fields if they're not at the end
                for ( $i = $idx + 1; $i < count( $row ); $i++ ) {
                    if ( trim( $row[$i] ) !== '' ) return true;
                }
                return false;
            }, ARRAY_FILTER_USE_BOTH );
            $row = array_values( $row ); // Re-index
            
            if ( count( $row ) < 3 ) {
                $errors[] = "Line {$line_num}: Invalid team format (need: team_name, access_code, status)";
                continue;
            }
            
            // Smart parsing: status is last, access_code is second-to-last, everything else is team name
            // This handles team names with commas like "Me, Dom, and JJ"
            $status = trim( strtolower( $row[ count( $row ) - 1 ] ) );
            $access_code = trim( $row[ count( $row ) - 2 ] );
            
            // Team name is everything from start up to (but not including) the last 2 fields
            $team_name_parts = array_slice( $row, 0, count( $row ) - 2 );
            $team_name = trim( implode( ',', $team_name_parts ) );
            
            if ( empty( $team_name ) ) {
                $errors[] = "Line {$line_num}: Team name is required";
                continue;
            }
            // Access code is optional - every team import now always gets a
            // fresh, auto-generated code for the current season (see
            // subsales_process_import_confirm()), so a blank column here is
            // fine; any value supplied is simply ignored.
            if ( ! in_array( $status, array( 'active', 'inactive' ) ) ) {
                $errors[] = "Line {$line_num}: Invalid status '{$status}' for team '{$team_name}' (must be 'active' or 'inactive')";
                continue;
            }
            
            $teams[] = array(
                'name' => $team_name,
                'access_code' => $access_code,
                'status' => $status
            );
        }
        
        // Process user rows (header is optional for users section)
        if ( $section === 'users' ) {
            if ( count( $row ) < 5 ) {
                $errors[] = "Line {$line_num}: Invalid user format (need: name, phone, email, status, teams)";
                continue;
            }
            
            $name = trim( $row[0] );
            $phone = trim( $row[1] );
            $email = trim( $row[2] );
            $status = trim( strtolower( $row[3] ) );
            $user_teams = trim( $row[4] );
            
            // Validate name
            if ( empty( $name ) ) {
                $errors[] = "Line {$line_num}: User name is required";
                continue;
            }
            
            // Validate and normalize phone
            if ( empty( $phone ) ) {
                $errors[] = "Line {$line_num}: Phone number is required for user '{$name}'";
                continue;
            }
            
            // Strip non-digits
            $phone = preg_replace( '/[^0-9]/', '', $phone );
            
            // Strip leading 1
            if ( strlen( $phone ) === 11 && $phone[0] === '1' ) {
                $phone = substr( $phone, 1 );
            }
            
            // Validate 10 digits
            if ( strlen( $phone ) !== 10 ) {
                $errors[] = "Line {$line_num}: Phone number must be exactly 10 digits for user '{$name}' (got: {$phone})";
                continue;
            }
            
            // Validate status
            if ( ! in_array( $status, array( 'active', 'inactive' ) ) ) {
                $errors[] = "Line {$line_num}: Invalid status '{$status}' for user '{$name}' (must be 'active' or 'inactive')";
                continue;
            }
            
            // Parse teams - collect all columns from index 4 onwards
            // Each non-empty column is a team name (supports quoted team names with commas)
            $team_list = array();
            for ( $i = 4; $i < count( $row ); $i++ ) {
                $team_name = trim( $row[$i] );
                if ( ! empty( $team_name ) ) {
                    $team_list[] = $team_name;
                }
            }
            
            if ( ! empty( $team_list ) ) {
                // Validate that all teams exist in the teams section
                // Compare sanitized names to match how they'll be stored
                foreach ( $team_list as $team_name ) {
                    $sanitized_user_team = subsales_sanitize_team_name( $team_name );
                    $team_exists = false;
                    foreach ( $teams as $team ) {
                        $sanitized_team = subsales_sanitize_team_name( $team['name'] );
                        if ( $sanitized_team === $sanitized_user_team ) {
                            $team_exists = true;
                            break;
                        }
                    }
                    if ( ! $team_exists ) {
                        // Enhanced debug output
                        $available_teams = array_map( function( $t ) { 
                            return subsales_sanitize_team_name( $t['name'] ); 
                        }, $teams );
                        $errors[] = "Line {$line_num}: Team '{$team_name}' (sanitized: '{$sanitized_user_team}') not found for user '{$name}'. Available teams: " . implode( ', ', array_map( function($t) { return "'{$t}'"; }, $available_teams ) );
                    }
                }
            }
            
            $users[] = array(
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'status' => $status,
                'teams' => $team_list
            );
        }
    }
    
    fclose( $handle );
    
    if ( empty( $teams ) && empty( $users ) ) {
        $errors[] = 'No valid data found in CSV file';
    }
    
    if ( ! empty( $errors ) ) {
        return array( 'error' => implode( '<br/>', $errors ) );
    }
    
    return array(
        'teams' => $teams,
        'users' => $users
    );
}

/**
 * Confirm and execute import
 */
function subsales_process_import_confirm( $import_data ) {
    global $wpdb;
    $teams_table = $wpdb->prefix . 'ss_teams';
    $members_table = $wpdb->prefix . 'ss_team_members';
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';
    $season_id = intval( get_option( 'subsales_current_season_id' ) );

    // Start transaction
    $wpdb->query( 'START TRANSACTION' );

    try {
        // Teams: always fresh under the CURRENT season - a team name is
        // reusable season to season, but each season's row is a genuinely
        // new one (never a prior season's row reused). Re-running the same
        // CSV within the same season updates the existing row in place
        // rather than duplicating it. Nothing is ever deleted here.
        $team_id_map = array(); // Sanitized name -> id, for this season only
        foreach ( $import_data['teams'] as $team ) {
            $sanitized_name = subsales_sanitize_team_name( $team['name'] );

            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$teams_table} WHERE name = %s AND season_id = %d",
                $sanitized_name, $season_id
            ) );

            if ( $existing_id ) {
                $wpdb->update( $teams_table,
                    array( 'status' => $team['status'] ),
                    array( 'id' => intval( $existing_id ) ),
                    array( '%s' ), array( '%d' )
                );
                $team_id_map[ $sanitized_name ] = intval( $existing_id );
            } else {
                // Access code is always auto-generated on creation, same as
                // get_or_create_team() - any code supplied in the CSV is
                // ignored, matching the "optional/auto-generated" decision.
                $access_code = strtoupper( substr( md5( $sanitized_name . $season_id . microtime() ), 0, 6 ) );
                $wpdb->insert(
                    $teams_table,
                    array(
                        'name'        => $sanitized_name,
                        'access_code' => $access_code,
                        'status'      => $team['status'],
                        'season_id'   => $season_id,
                    ),
                    array( '%s', '%s', '%s', '%d' )
                );
                $team_id_map[ $sanitized_name ] = intval( $wpdb->insert_id );
            }
        }

        // Members: matched/reactivated by phone - the persistent, cross-season
        // identity. Never deleted, never re-created if a row already exists
        // (a returning kid keeps their history intact and simply comes back
        // active).
        foreach ( $import_data['users'] as $user ) {
            $sanitized_name = subsales_sanitize_user_name( $user['name'] );

            $existing_member_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$members_table} WHERE phone = %s",
                $user['phone']
            ) );

            if ( $existing_member_id ) {
                $user_id = intval( $existing_member_id );
                $wpdb->update( $members_table,
                    array(
                        'name'   => $sanitized_name,
                        'email'  => $user['email'],
                        'status' => $user['status'],
                    ),
                    array( 'id' => $user_id ),
                    array( '%s', '%s', '%s' ), array( '%d' )
                );
            } else {
                $wpdb->insert(
                    $members_table,
                    array(
                        'team_id' => 0, // Legacy field, not used in multi-team system
                        'name'    => $sanitized_name,
                        'phone'   => $user['phone'],
                        'email'   => $user['email'],
                        'role'    => 'member',
                        'status'  => $user['status'],
                    ),
                    array( '%d', '%s', '%s', '%s', '%s', '%s' )
                );
                $user_id = intval( $wpdb->insert_id );
            }

            // Team assignments: insert-if-missing for this season's teams -
            // a prior season's link is never touched or removed.
            foreach ( $user['teams'] as $team_name ) {
                $sanitized_team_name = subsales_sanitize_team_name( $team_name );
                if ( ! isset( $team_id_map[ $sanitized_team_name ] ) ) {
                    continue;
                }
                $team_id = $team_id_map[ $sanitized_team_name ];

                $already_linked = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
                    $user_id, $team_id
                ) );
                if ( ! $already_linked ) {
                    $wpdb->insert(
                        $user_teams_table,
                        array( 'user_id' => $user_id, 'team_id' => $team_id ),
                        array( '%d', '%d' )
                    );
                }
            }
        }

        // Commit transaction
        $wpdb->query( 'COMMIT' );

        subsales_log( 'INFO', 'import', 'Users and teams imported successfully (upsert, no deletions)', array(
            'teams_count' => count( $import_data['teams'] ),
            'users_count' => count( $import_data['users'] ),
            'season_id'   => $season_id,
        ), 'admin' );

    } catch ( Exception $e ) {
        $wpdb->query( 'ROLLBACK' );
        subsales_log( 'ERROR', 'import', 'Import failed', array( 'error' => $e->getMessage() ), 'admin' );
        throw $e;
    }
}

/**
 * Import users and teams from CSV
 * Handles preview and actual import
 */
function subsales_import_users_teams() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied' );
    }
    
    // This will be handled in the teams page
    // Redirect back to teams page
    wp_redirect( admin_url( 'admin.php?page=subsales-teams' ) );
    exit;
}

// Teams management admin page with tabbed interface
function ss_teams_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $teams_table = $wpdb->prefix . 'ss_teams';
    $members_table = $wpdb->prefix . 'ss_team_members';
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';

    // Determine active tab
    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'users';
    
    // Handle import preview
    $import_preview = null;
    if ( isset( $_POST['subsales_import_action'] ) && $_POST['subsales_import_action'] === 'preview' ) {
        check_admin_referer( 'subsales_import_users_teams_preview' );
        $import_preview = subsales_process_import_preview( $_FILES['import_file'] ?? null );
    }
    
    // Handle actual import
    if ( isset( $_POST['subsales_import_action'] ) && $_POST['subsales_import_action'] === 'confirm' ) {
        check_admin_referer( 'subsales_import_users_teams_confirm' );
        $import_data = isset( $_POST['import_data'] ) ? json_decode( stripslashes( $_POST['import_data'] ), true ) : null;
        if ( $import_data ) {
            subsales_process_import_confirm( $import_data );
            echo '<div class="notice notice-success"><p><strong>Import completed successfully!</strong></p></div>';
        }
    }

    // Handle user/member creation
    if ( isset( $_POST['add_user'] ) ) {
        check_admin_referer( 'order_sync_add_user' );
        $name = subsales_sanitize_user_name( $_POST['user_name'] ?? '' );
        $email = sanitize_email( $_POST['user_email'] ?? '' );
        $phone = sanitize_text_field( $_POST['user_phone'] ?? '' );
        $role = sanitize_text_field( $_POST['user_role'] ?? 'member' );
        $status = isset( $_POST['user_active'] ) ? 'active' : 'inactive';
        
        if ( empty( $name ) ) {
            echo '<div class="notice notice-error"><p>User name is required.</p></div>';
        } elseif ( empty( $phone ) ) {
            echo '<div class="notice notice-error"><p>Phone number is required.</p></div>';
        } elseif ( ! preg_match( '/^[0-9]{10}$/', preg_replace( '/[^0-9]/', '', $phone ) ) ) {
            echo '<div class="notice notice-error"><p>Phone number must be 10 digits.</p></div>';
        } else {
            // Normalize phone to 10 digits only
            $phone = preg_replace( '/[^0-9]/', '', $phone );
            $email = $email ?: '';
            // Add user without team assignment initially
            $result = $wpdb->insert(
                $members_table,
                array(
                    'team_id' => 0,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => $role,
                    'status' => $status
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            
            if ( $result ) {
                echo '<div class="notice notice-success"><p>User created successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to create user.</p></div>';
            }
        }
    }

    // Handle user update
    if ( isset( $_POST['edit_user'] ) ) {
        check_admin_referer( 'order_sync_edit_user' );
        $user_id = intval( $_POST['user_id'] ?? 0 );
        $name = subsales_sanitize_user_name( $_POST['user_name'] ?? '' );
        $email = sanitize_email( $_POST['user_email'] ?? '' );
        $phone = sanitize_text_field( $_POST['user_phone'] ?? '' );
        $role = sanitize_text_field( $_POST['user_role'] ?? 'member' );
        $status = isset( $_POST['user_active'] ) ? 'active' : 'inactive';
        
        if ( empty( $name ) ) {
            echo '<div class="notice notice-error"><p>User name is required.</p></div>';
        } elseif ( empty( $phone ) ) {
            echo '<div class="notice notice-error"><p>Phone number is required.</p></div>';
        } elseif ( ! preg_match( '/^[0-9]{10}$/', preg_replace( '/[^0-9]/', '', $phone ) ) ) {
            echo '<div class="notice notice-error"><p>Phone number must be 10 digits.</p></div>';
        } elseif ( $user_id && ! empty( $name ) ) {
            // Normalize phone to 10 digits only
            $phone = preg_replace( '/[^0-9]/', '', $phone );
            $updated = $wpdb->update(
                $members_table,
                array( 'name' => $name, 'email' => $email ?: '', 'phone' => $phone, 'role' => $role, 'status' => $status ),
                array( 'id' => $user_id ),
                array( '%s', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            
            if ( $updated !== false ) {
                echo '<div class="notice notice-success"><p>User updated successfully.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to update user.</p></div>';
            }
        }
    }

    // Handle team creation
    if ( isset( $_POST['add_team'] ) ) {
        check_admin_referer( 'order_sync_add_team' );
        $name = subsales_sanitize_team_name( $_POST['team_name'] ?? '' );
        $code = subsales_sanitize_team_code( $_POST['team_code'] ?? '' );
        $desc = sanitize_textarea_field( $_POST['team_description'] ?? '' );
        $status = isset( $_POST['team_active'] ) ? 'active' : 'inactive';
        if ( empty( $name ) || empty( $code ) ) {
            echo '<div class="notice notice-error"><p>Team name and access code are required.</p></div>';
        } else {
            $ok = order_sync_add_team( $name, $code, $desc, $status );
            if ( $ok ) echo '<div class="notice notice-success"><p>Team created.</p></div>';
            else echo '<div class="notice notice-error"><p>Failed to create team (duplicate name or code?).</p></div>';
        }
    }

    // Handle team update
    if ( isset( $_POST['edit_team'] ) ) {
        check_admin_referer( 'order_sync_edit_team' );
        $tid = intval( $_POST['team_id'] ?? 0 );
        $name = subsales_sanitize_team_name( $_POST['team_name'] ?? '' );
        $code = subsales_sanitize_team_code( $_POST['team_code'] ?? '' );
        $desc = sanitize_textarea_field( $_POST['team_description'] ?? '' );
        $status = isset( $_POST['team_active'] ) ? 'active' : 'inactive';
        if ( $tid && ( ! empty( $name ) && ! empty( $code ) ) ) {
            $updated = $wpdb->update( $teams_table, array( 'name' => $name, 'access_code' => $code, 'description' => $desc, 'status' => $status ), array( 'id' => $tid ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
            if ( $updated !== false ) {
                echo '<div class="notice notice-success"><p>Team updated.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to update team.</p></div>';
            }
        }
    }

    // Handle AJAX team assignment
    if ( isset( $_POST['assign_user_to_team'] ) ) {
        check_admin_referer( 'order_sync_team_assignment' );
        $user_id = intval( $_POST['user_id'] ?? 0 );
        $team_id = intval( $_POST['team_id'] ?? 0 );
        
        if ( $user_id ) {
            $updated = $wpdb->update(
                $members_table,
                array( 'team_id' => $team_id ),
                array( 'id' => $user_id ),
                array( '%d' ),
                array( '%d' )
            );
            
            if ( $updated !== false ) {
                wp_send_json_success( array( 'message' => 'User assigned to team successfully' ) );
            } else {
                wp_send_json_error( array( 'message' => 'Failed to assign user to team' ) );
            }
        }
        wp_send_json_error( array( 'message' => 'Invalid user ID' ) );
    }

    // Get all users and teams (sort: active first, then by name)
    $all_users = $wpdb->get_results( "SELECT * FROM {$members_table} ORDER BY status DESC, name ASC", ARRAY_A );
    $teams = order_sync_get_teams();
    
    // Check if editing a user
    $editing_user = false;
    $edit_user = array( 'id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'role' => 'member', 'status' => 'active' );
    if ( isset( $_GET['edit_user'] ) ) {
        $uid = intval( $_GET['edit_user'] );
        if ( $uid ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$members_table} WHERE id = %d", $uid ), ARRAY_A );
            if ( $row ) {
                $editing_user = true;
                $edit_user = $row;
            }
        }
    }
    
    // Check if editing a team
    $editing_team = false;
    $edit_team = array( 'id' => 0, 'name' => '', 'access_code' => '', 'description' => '', 'status' => 'active' );
    if ( isset( $_GET['edit_team'] ) ) {
        $tid = intval( $_GET['edit_team'] );
        if ( $tid ) {
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$teams_table} WHERE id = %d", $tid ), ARRAY_A );
            if ( $row ) {
                $editing_team = true;
                $edit_team = $row;
            }
        }
    }
    ?>
    <div class="wrap">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 style="margin: 0;">User &amp; Team Management</h1>
            <div>
                <a href="<?php echo admin_url( 'admin-post.php?action=subsales_export_users_teams&export_type=template' ); ?>" class="button" style="margin-right: 8px;">
                    📄 Download Template
                </a>
                <a href="<?php echo admin_url( 'admin-post.php?action=subsales_export_users_teams&export_type=current' ); ?>" class="button button-secondary" style="margin-right: 8px;">
                    📥 Export Current Data
                </a>
                <button type="button" class="button button-primary" onclick="document.getElementById('subsales-import-form').style.display='block';">
                    📤 Import Data
                </button>
            </div>
        </div>
        
        <!-- Import Form (hidden by default) -->
        <div id="subsales-import-form" style="display: none; background: #f9f9f9; border: 1px solid #ddd; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
            <h2 style="margin-top: 0;">Import Users & Teams</h2>
            <form method="post" action="<?php echo admin_url( 'admin.php?page=subsales-teams' ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'subsales_import_users_teams_preview' ); ?>
                <input type="hidden" name="subsales_import_action" value="preview" />
                <p>
                    <label for="import_file"><strong>Select CSV File:</strong></label><br/>
                    <input type="file" name="import_file" id="import_file" accept=".csv" required />
                </p>
                <p>
                    <button type="submit" class="button button-primary">Preview Import</button>
                    <button type="button" class="button" onclick="document.getElementById('subsales-import-form').style.display='none';">Cancel</button>
                </p>
            </form>
        </div>
        
        <!-- Import Preview (shown when preview is generated) -->
        <?php if ( $import_preview ) : ?>
            <?php if ( isset( $import_preview['error'] ) ) : ?>
                <div class="notice notice-error" style="margin-bottom: 20px;">
                    <p><strong>Import Error:</strong></p>
                    <p><?php echo $import_preview['error']; ?></p>
                </div>
            <?php else : ?>
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin-bottom: 20px; border-radius: 4px;">
                    <h2 style="margin-top: 0;">📋 Import Preview</h2>
                    <p><strong>Review the changes below before confirming the import.</strong></p>
                    <p><strong>This is safe to re-run:</strong> nothing is ever deleted. Teams below are created fresh for the current season; members are matched by phone and reactivated if needed. Existing history (orders, signups, prior seasons) is untouched.</p>
                    
                    <h3>Teams to Import (<?php echo count( $import_preview['teams'] ); ?>)</h3>
                    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th>Team Name</th>
                                <th>Access Code</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $import_preview['teams'] as $team ) : ?>
                                <tr>
                                    <td><?php echo esc_html( wp_unslash( $team['name'] ) ); ?></td>
                                    <td><?php echo esc_html( wp_unslash( $team['access_code'] ) ); ?></td>
                                    <td><?php echo esc_html( $team['status'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <h3>Users to Import (<?php echo count( $import_preview['users'] ); ?>)</h3>
                    <table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Teams</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $import_preview['users'] as $user ) : ?>
                                <tr>
                                    <td><?php echo esc_html( wp_unslash( $user['name'] ) ); ?></td>
                                    <td><?php echo esc_html( $user['phone'] ); ?></td>
                                    <td><?php echo esc_html( $user['email'] ); ?></td>
                                    <td><?php echo esc_html( $user['status'] ); ?></td>
                                    <td><?php echo esc_html( implode( ', ', $user['teams'] ) ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <form method="post" action="<?php echo admin_url( 'admin.php?page=subsales-teams' ); ?>">
                        <?php wp_nonce_field( 'subsales_import_users_teams_confirm' ); ?>
                        <input type="hidden" name="subsales_import_action" value="confirm" />
                        <input type="hidden" name="import_data" value="<?php echo esc_attr( json_encode( $import_preview ) ); ?>" />
                        <p>
                            <button type="submit" class="button button-primary button-large" onclick="return confirm('Import this roster? Existing members/teams will be updated, new ones created - nothing is deleted.');">
                                ✅ Confirm Import
                            </button>
                            <a href="<?php echo admin_url( 'admin.php?page=subsales-teams' ); ?>" class="button button-large">Cancel</a>
                        </p>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <h2 class="nav-tab-wrapper">
            <a href="?page=subsales-teams&tab=users" class="nav-tab <?php echo $active_tab === 'users' ? 'nav-tab-active' : ''; ?>">Users</a>
            <a href="?page=subsales-teams&tab=teams" class="nav-tab <?php echo $active_tab === 'teams' ? 'nav-tab-active' : ''; ?>">Teams</a>
        </h2>

        <?php if ( $active_tab === 'users' ) : ?>
            <!-- USERS TAB -->
            <div class="subsales-tab-content" style="margin-top: 20px;">
                <h2><?php echo $editing_user ? 'Edit User' : 'Add New User'; ?></h2>
                <form method="post" action="?page=subsales-teams&tab=users">
                    <?php if ( $editing_user ): wp_nonce_field( 'order_sync_edit_user' ); else: wp_nonce_field( 'order_sync_add_user' ); endif; ?>
                    <input type="hidden" name="user_id" value="<?php echo esc_attr( $edit_user['id'] ); ?>" />
                    <table class="form-table">
                        <tr>
                            <th><label for="user_active">Status</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="user_active" name="user_active" value="1" <?php checked( ( $edit_user['status'] ?? 'active' ), 'active' ); ?> />
                                    Active
                                </label>
                                <p class="description">Uncheck to make this user inactive. Inactive users cannot log into the PWA.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="user_name">Name *</label></th>
                            <td><input type="text" id="user_name" name="user_name" class="regular-text" required value="<?php echo esc_attr( $edit_user['name'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="user_phone">Phone *</label></th>
                            <td>
                                <input type="tel" id="user_phone" name="user_phone" class="regular-text" required value="<?php echo esc_attr( $edit_user['phone'] ?? '' ); ?>" pattern="[0-9]{3}[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}" placeholder="555-123-4567" />
                                <p class="description">Required. 10-digit phone number (unique per user).</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="user_email">Email</label></th>
                            <td><input type="email" id="user_email" name="user_email" class="regular-text" value="<?php echo esc_attr( $edit_user['email'] ); ?>" placeholder="(optional)" /></td>
                        </tr>
                        <tr>
                            <th><label for="user_role">Role</label></th>
                            <td>
                                <select id="user_role" name="user_role">
                                    <option value="member" <?php selected( $edit_user['role'], 'member' ); ?>>Member</option>
                                    <option value="manager" <?php selected( $edit_user['role'], 'manager' ); ?>>Manager</option>
                                    <option value="admin" <?php selected( $edit_user['role'], 'admin' ); ?>>Admin</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <?php if ( $editing_user ): ?>
                        <p>
                            <button name="edit_user" class="button button-primary">Update User</button>
                            <a href="?page=subsales-teams&tab=users" class="button">Cancel</a>
                        </p>
                    <?php else: ?>
                        <p><button name="add_user" class="button button-primary">Add User</button></p>
                    <?php endif; ?>
                </form>

                <h2 style="margin-top: 30px;">All Users</h2>
                <?php if ( ! empty( $all_users ) ) : ?>
                <input type="text" id="allUsersSearchBox" placeholder="Search users by name, phone, or email..." style="width: 100%; max-width: 400px; padding: 8px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 4px;" />
                <table class="wp-list-table widefat fixed striped" id="allUsersTable">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Name - Phone</th>
                            <th style="width: 25%;">Email</th>
                            <th style="width: 15%;">Role</th>
                            <th style="width: 30%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $all_users as $user ) : ?>
                        <?php $is_active = ( $user['status'] ?? 'active' ) === 'active'; ?>
                        <tr style="background-color: <?php echo $is_active ? '#e8f5e9' : '#f5f5f5'; ?>;<?php echo $is_active ? '' : ' opacity: 0.7;'; ?>">
                            <td>
                                <strong><?php echo esc_html( wp_unslash( $user['name'] ) ); ?></strong><br>
                                <span style="color: #666; font-size: 13px;">📞 <?php echo esc_html( $user['phone'] ?? 'No phone' ); ?></span>
                            </td>
                            <td><?php echo esc_html( wp_unslash( $user['email'] ?: '—' ) ); ?></td>
                            <td><?php echo esc_html( ucfirst( $user['role'] ) ); ?></td>
                            <td>
                                <a href="?page=subsales-teams&tab=users&edit_user=<?php echo intval( $user['id'] ); ?>" class="button button-small">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else : ?>
                <p>No users yet. Add your first user above.</p>
                <?php endif; ?>
            </div>

        <?php else : ?>
            <!-- TEAMS TAB -->
            <div class="subsales-tab-content" style="margin-top: 20px;">
                <h2><?php echo $editing_team ? 'Edit Team' : 'Add New Team'; ?></h2>
                <form method="post" action="?page=subsales-teams&tab=teams">
                    <?php if ( $editing_team ): wp_nonce_field( 'order_sync_edit_team' ); else: wp_nonce_field( 'order_sync_add_team' ); endif; ?>
                    <input type="hidden" name="team_id" value="<?php echo esc_attr( $edit_team['id'] ); ?>" />
                    <table class="form-table">
                        <tr>
                            <th><label for="team_active">Status</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="team_active" name="team_active" value="1" <?php checked( ( $edit_team['status'] ?? 'active' ), 'active' ); ?> />
                                    Active
                                </label>
                                <p class="description">Uncheck to make this team inactive. Inactive teams appear greyed out.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="team_name">Team Name</label></th>
                            <td><input type="text" id="team_name" name="team_name" class="regular-text" required value="<?php echo esc_attr( $edit_team['name'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="team_code">Access Code</label></th>
                            <td><input type="text" id="team_code" name="team_code" class="regular-text" required value="<?php echo esc_attr( $edit_team['access_code'] ); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="team_description">Description</label></th>
                            <td><textarea id="team_description" name="team_description" class="large-text" rows="3"><?php echo esc_textarea( $edit_team['description'] ); ?></textarea></td>
                        </tr>
                    </table>
                    <?php if ( $editing_team ): ?>
                        <p>
                            <button name="edit_team" class="button button-primary">Update Team</button>
                            <a href="?page=subsales-teams&tab=teams" class="button">Cancel</a>
                        </p>
                    <?php else: ?>
                        <p><button name="add_team" class="button button-primary">Add Team</button></p>
                    <?php endif; ?>
                </form>

                <h2 style="margin-top: 30px;">Team Management</h2>
                <p class="description">Drag users from the available users box into team boxes to assign them. Users can belong to multiple teams.</p>
                
                <style>
                    .subsales-team-grid-wrapper {
                        display: grid;
                        gap: 20px;
                        margin-top: 20px;
                    }
                    
                    /* Large screens: 2 equal columns */
                    @media (min-width: 900px) {
                        .subsales-team-grid-wrapper {
                            grid-template-columns: 1fr 1fr;
                        }
                    }
                    
                    /* Small screens: single column stacked */
                    @media (max-width: 899px) {
                        .subsales-team-grid-wrapper {
                            grid-template-columns: 1fr;
                        }
                    }
                    
                    .available-users-column {
                        background: #f9f9f9;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        padding: 15px;
                    }
                    
                    .available-teams-column {
                        background: #f9f9f9;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        padding: 15px;
                    }
                    
                    .teams-list {
                        display: grid;
                        gap: 15px;
                    }
                    
                    /* Large screens: 3 columns inside teams container */
                    @media (min-width: 1600px) {
                        .teams-list {
                            grid-template-columns: repeat(3, 1fr);
                        }
                    }
                    
                    /* Medium-large screens: 2 columns inside teams container */
                    @media (min-width: 1200px) and (max-width: 1599px) {
                        .teams-list {
                            grid-template-columns: repeat(2, 1fr);
                        }
                    }
                    
                    /* Medium and small screens: 1 column inside teams container */
                    @media (max-width: 1199px) {
                        .teams-list {
                            grid-template-columns: 1fr;
                        }
                    }
                    
                    .team-box {
                        height: fit-content;
                        margin-bottom: 0 !important;
                    }
                </style>
                
                <div class="subsales-team-grid-wrapper">
                    <!-- Available Users -->
                    <div class="available-users-column">
                        <h3 style="margin-top: 0;">Available Users</h3>
                        <input type="text" id="userSearchBox" placeholder="Search by name or phone..." style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 4px;" />
                        <div class="available-users-list" id="availableUsersList" style="min-height: 200px;">
                            <?php
                            // Show only active users in the available list
                            if ( ! empty( $all_users ) ) :
                                foreach ( $all_users as $user ) :
                                    // Skip inactive users
                                    if ( ( $user['status'] ?? 'active' ) !== 'active' ) continue;
                            ?>
                                <div class="user-card draggable" draggable="true" data-user-id="<?php echo intval( $user['id'] ); ?>" 
                                     style="background: #fff; border: 1px solid #ccc; border-radius: 4px; padding: 10px; margin-bottom: 8px; cursor: move;">
                                    <strong><?php echo esc_html( wp_unslash( $user['name'] ) ); ?></strong><br>
                                    <small style="color: #666;"><?php echo esc_html( $user['email'] ?: 'No email' ); ?></small>
                                    <?php if ( ! empty( $user['phone'] ) ) : ?>
                                        <br><small style="color: #666;">📞 <?php echo esc_html( $user['phone'] ); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php
                                endforeach;
                            else :
                            ?>
                                <p style="color: #666; font-style: italic;">No users available. Create users in the Users tab.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Available Teams -->
                    <div class="available-teams-column">
                        <h3 style="margin-top: 0;">Available Teams</h3>
                        <input type="text" id="teamSearchBox" placeholder="Search teams by name, code, or member..." 
                               style="width: 100%; padding: 8px; margin-bottom: 12px; border: 1px solid #ccc; border-radius: 4px;" />
                        <div class="teams-list" id="teamsList" style="min-height: 200px;">
                            <?php if ( ! empty( $teams ) ) : ?>
                                <?php foreach ( $teams as $team ) : ?>
                                    <?php $team_is_active = ( $team['status'] ?? 'active' ) === 'active'; ?>
                                    <div class="team-box postbox" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                         style="margin-bottom: 20px; border: 2px solid #ccc; border-radius: 6px;<?php echo $team_is_active ? '' : ' opacity: 0.6;'; ?>">
                                        <div class="postbox-header" style="background: <?php echo $team_is_active ? '#e8f5e9' : '#f5f5f5'; ?>; padding: 12px 15px; border-bottom: 1px solid #ccc; display: flex; justify-content: space-between; align-items: center;">
                                            <h3 style="margin: 0;">
                                                <?php echo esc_html( wp_unslash( $team['name'] ) ); ?>
                                                <?php if ( ! $team_is_active ) : ?>
                                                    <span style="font-size: 12px; color: #999; font-weight: normal;">(Inactive)</span>
                                                <?php endif; ?>
                                                <span style="font-weight: normal; color: #666; font-size: 14px;">
                                                    (Code: <?php echo esc_html( wp_unslash( $team['access_code'] ) ); ?>)
                                                </span>
                                            </h3>
                                            <div>
                                                <a href="?page=subsales-teams&tab=teams&edit_team=<?php echo intval( $team['id'] ); ?>" class="button button-small">Edit</a>
                                            </div>
                                        </div>
                                        <div class="inside team-dropzone" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                             style="padding: 15px; min-height: 100px; background: #fafafa;">
                                            <?php if ( ! empty( $team['description'] ) ) : ?>
                                                <p style="margin: 0 0 10px 0; font-style: italic; color: #666;"><?php echo esc_html( $team['description'] ); ?></p>
                                            <?php endif; ?>
                                            
                                            <h4 style="margin: 10px 0;">Team Members</h4>
                                            <div class="team-members-list">
                                                <?php
                                                // Canonical team membership; each member tagged is_driver
                                                // (derived from active driver signups). Sort sales first
                                                // and drivers last so we can render a divider between them.
                                                $team_members_new = Subsales_Database::get_team_membership( $team['id'] );
                                                usort( $team_members_new, function( $a, $b ) {
                                                    $ad = ! empty( $a['is_driver'] ) ? 1 : 0;
                                                    $bd = ! empty( $b['is_driver'] ) ? 1 : 0;
                                                    if ( $ad !== $bd ) { return $ad - $bd; }
                                                    return strcasecmp( $a['name'], $b['name'] );
                                                } );
                                                $driver_divider_shown = false;
                                                
                                                if ( ! empty( $team_members_new ) ) : ?>
                                                <?php foreach ( $team_members_new as $member ) : ?>
                                                    <?php $member_is_active = ( $member['status'] ?? 'active' ) === 'active'; ?>
                                                    <?php $member_is_driver = ! empty( $member['is_driver'] ); ?>
                                                    <?php if ( $member_is_driver && ! $driver_divider_shown ) : $driver_divider_shown = true; ?>
                                                        <div style="border-top: 2px solid #e0e0e0; margin: 14px 0 10px; padding-top: 8px; font-size: 12px; font-weight: 600; color: #f0a020; text-transform: uppercase; letter-spacing: 0.5px;">Drivers</div>
                                                    <?php endif; ?>
                                                    <div class="user-card team-member-card" data-user-id="<?php echo intval( $member['id'] ); ?>" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                                         style="background: <?php echo $member_is_active ? '#fff' : '#f5f5f5'; ?>; border: 1px solid <?php echo $member_is_driver ? '#f0a020' : ( $member_is_active ? '#4CAF50' : '#ccc' ); ?>; border-radius: 4px; padding: 10px; margin-bottom: 8px; position: relative;<?php echo $member_is_active ? '' : ' opacity: 0.7;'; ?>">
                                                        <button type="button" class="remove-from-team" data-user-id="<?php echo intval( $member['id'] ); ?>" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                                                style="position: absolute; top: 5px; right: 5px; background: #dc3232; color: #fff; border: none; border-radius: 3px; cursor: pointer; padding: 2px 6px; font-size: 11px;"
                                                                title="Remove from team">×</button>
                                                        <strong><?php echo esc_html( $member['name'] ); ?></strong> <?php if ( $member_is_driver ) : ?><span style="display:inline-block; margin-left:6px; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#fff; background:#f0a020; padding:2px 6px; border-radius:3px;">Driver</span><?php endif; ?>
                                                        <?php if ( ! $member_is_active ) : ?>
                                                            <span style="font-size: 11px; color: #999;">(Inactive)</span>
                                                        <?php endif; ?>
                                                        <br>
                                                        <small style="color: #666;"><?php echo esc_html( $member['email'] ?: 'No email' ); ?></small>
                                                        <?php if ( ! empty( $member['phone'] ) ) : ?>
                                                            <br><small style="color: #666;">📞 <?php echo esc_html( $member['phone'] ); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php else : ?>
                                                <p style="color: #999; font-style: italic;">No members assigned yet. Drag users here to assign.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p style="color: #666; font-style: italic;">No teams created yet. Add your first team above.</p>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Drag and Drop JavaScript -->
                <script>
                (function($) {
                    $(document).ready(function() {
                        let draggedElement = null;

                        // User search box - filters available users
                        $('#userSearchBox').on('keyup', function() {
                        const searchTerm = $(this).val().toLowerCase();
                        $('#availableUsersList .user-card').each(function() {
                            const text = $(this).text().toLowerCase();
                            if (text.indexOf(searchTerm) > -1) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    });
                    
                    // Team search box - filters team boxes
                    $('#teamSearchBox').on('keyup', function() {
                        const searchTerm = $(this).val().toLowerCase();
                        $('.team-box').each(function() {
                            const teamText = $(this).find('.postbox-header h3').text().toLowerCase();
                            const membersText = $(this).find('.team-members-list').text().toLowerCase();
                            const combinedText = teamText + ' ' + membersText;
                            
                            if (combinedText.indexOf(searchTerm) > -1) {
                                $(this).show();
                            } else {
                                $(this).hide();
                            }
                        });
                    });

                    // Make user cards draggable
                    $(document).on('dragstart', '.user-card', function(e) {
                        draggedElement = this;
                        $(this).css('opacity', '0.5');
                        e.originalEvent.dataTransfer.effectAllowed = 'move';
                        e.originalEvent.dataTransfer.setData('text/html', this.innerHTML);
                    });

                    $(document).on('dragend', '.user-card', function(e) {
                        $(this).css('opacity', '1');
                    });

                    // Handle drag over team dropzones and available users list
                    $(document).on('dragover', '.team-dropzone, #availableUsersList', function(e) {
                        if (e.preventDefault) {
                            e.preventDefault();
                        }
                        e.originalEvent.dataTransfer.dropEffect = 'move';
                        $(this).css('background', '#e8f5e9');
                        return false;
                    });

                    $(document).on('dragleave', '.team-dropzone, #availableUsersList', function(e) {
                        $(this).css('background', '');
                    });

                    // Handle drop
                    $(document).on('drop', '.team-dropzone', function(e) {
                        if (e.stopPropagation) {
                            e.stopPropagation();
                        }
                        $(this).css('background', '');

                        if (draggedElement) {
                            const userId = $(draggedElement).data('user-id');
                            const teamId = $(this).data('team-id');
                            
                            // Add to team via AJAX (don't move visually from available list)
                            $.post(ajaxurl, {
                                action: 'subsales_add_user_to_team',
                                user_id: userId,
                                team_id: teamId,
                                nonce: '<?php echo wp_create_nonce( 'subsales_team_assign' ); ?>'
                            }, function(response) {
                                if (response.success) {
                                    // Reload to show updated team membership
                                    location.reload();
                                } else {
                                    alert(response.data.message || 'Failed to assign user to team.');
                                }
                            });
                        }
                        
                        return false;
                    });

                    // Handle remove button click
                    $(document).on('click', '.remove-from-team', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        const userId = $(this).data('user-id');
                        const teamId = $(this).data('team-id');
                        
                        if (!confirm('Remove this user from the team?')) {
                            return;
                        }
                        
                        // Remove from team via AJAX
                        $.post(ajaxurl, {
                            action: 'subsales_remove_user_from_team',
                            user_id: userId,
                            team_id: teamId,
                            nonce: '<?php echo wp_create_nonce( 'subsales_team_assign' ); ?>'
                        }, function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert('Failed to remove user from team.');
                            }
                        });
                    });
                    }); // End document.ready
                })(jQuery);
                </script>
            </div>
        <?php endif; ?>
        
        <style>
        .nav-tab-wrapper {
            border-bottom: 1px solid #ccc;
            margin: 20px 0 0 0;
            padding: 0;
        }
        .nav-tab {
            border: 1px solid #ccc;
            border-bottom: none;
            background: #f1f1f1;
            color: #555;
        }
        .nav-tab-active {
            background: #fff;
            border-bottom: 1px solid #fff;
            color: #000;
            margin-bottom: -1px;
        }
        .user-card {
            transition: all 0.2s ease;
        }
        .user-card:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }
        .team-dropzone {
            transition: background 0.2s ease;
        }
        .status-active { color: #46b450; font-weight: bold; }
        .status-pending { color: #ffb900; font-weight: bold; }
        .status-inactive { color: #dc3232; font-weight: bold; }
        </style>
        
        <script>
        (function($) {
            $(document).ready(function() {
                // Search functionality for All Users table
                $('#allUsersSearchBox').on('keyup', function() {
                    const searchTerm = $(this).val().toLowerCase();
                    $('#allUsersTable tbody tr').each(function() {
                        const text = $(this).text().toLowerCase();
                        if (text.indexOf(searchTerm) > -1) {
                            $(this).show();
                        } else {
                            $(this).hide();
                        }
                    });
                });
            });
        })(jQuery);
        </script>
    </div>
    <?php
}

// AJAX handler for adding user to team (many-to-many)
add_action( 'wp_ajax_subsales_add_user_to_team', 'subsales_ajax_add_user_to_team' );
function subsales_ajax_add_user_to_team() {
    check_ajax_referer( 'subsales_team_assign', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';
    
    $user_id = intval( $_POST['user_id'] ?? 0 );
    $team_id = intval( $_POST['team_id'] ?? 0 );
    
    if ( $user_id && $team_id ) {
        // Check if already assigned
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id,
            $team_id
        ));
        
        if ( $exists ) {
            wp_send_json_error( array( 'message' => 'User is already assigned to this team' ) );
        }
        
        $result = $wpdb->insert(
            $user_teams_table,
            array( 'user_id' => $user_id, 'team_id' => $team_id ),
            array( '%d', '%d' )
        );
        
        if ( $result ) {
            wp_send_json_success( array( 'message' => 'User added to team successfully' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Database insert failed' ) );
        }
    }
    
    wp_send_json_error( array( 'message' => 'Invalid user or team ID' ) );
}

// AJAX handler for removing user from team
add_action( 'wp_ajax_subsales_remove_user_from_team', 'subsales_ajax_remove_user_from_team' );
function subsales_ajax_remove_user_from_team() {
    check_ajax_referer( 'subsales_team_assign', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    global $wpdb;
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';
    
    $user_id = intval( $_POST['user_id'] ?? 0 );
    $team_id = intval( $_POST['team_id'] ?? 0 );
    
    if ( $user_id && $team_id ) {
        $deleted = $wpdb->delete(
            $user_teams_table,
            array( 'user_id' => $user_id, 'team_id' => $team_id ),
            array( '%d', '%d' )
        );
        
        if ( $deleted ) {
            wp_send_json_success( array( 'message' => 'User removed from team successfully' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Database delete failed' ) );
        }
    }
    
    wp_send_json_error( array( 'message' => 'Invalid user or team ID' ) );
}

/**
 * DEPRECATED: Orders admin page (inline version)
 * 
 * This function is NO LONGER USED as of v2.2.1.154.
 * Orders page now rendered by: Subsales_Admin_Pages::render_orders_page()
 * Template file: admin/orders-page.php
 * 
 * @deprecated 2.2.1.154 Use Subsales_Admin_Pages::render_orders_page() instead
 */
function order_sync_orders_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'ss_orders';
    // Initial page renders minimal markup; actual data is fetched via AJAX.
    $nonce = wp_create_nonce( 'subsales_orders_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    // preload teams, members and configured products for filter UI and table columns
    $teams = order_sync_get_teams();
    global $wpdb;
    $members = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ss_team_members ORDER BY name ASC", ARRAY_A );
    $products_conf = order_sync_get_products_config();

    // Get filter parameters from request
    $start_date = isset( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : '';
    $end_date = isset( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : '';
    $filter_team = isset( $_GET['filter_team'] ) ? intval( $_GET['filter_team'] ) : 0;
    $filter_member = isset( $_GET['filter_member'] ) ? sanitize_text_field( $_GET['filter_member'] ) : '';
    $payment_method = isset( $_GET['payment_method'] ) ? sanitize_text_field( $_GET['payment_method'] ) : '';

    // Build WHERE clauses safely
    $where = array();
    $params = array();

    if ( ! empty( $start_date ) ) {
        // start of day
        $where[] = "created_at >= %s";
        $params[] = $start_date . ' 00:00:00';
    }
    if ( ! empty( $end_date ) ) {
        // end of day
        $where[] = "created_at <= %s";
        $params[] = $end_date . ' 23:59:59';
    }
    if ( $filter_team ) {
        $where[] = "team_id = %d";
        $params[] = $filter_team;
    }
    if ( ! empty( $filter_member ) ) {
        // user_id column stores the entered_by identifier
        $where[] = "user_id = %s";
        $params[] = $filter_member;
    }
    if ( ! empty( $payment_method ) ) {
        // best-effort JSON match in order_data -> search for paymentMethod or checkNumber
        if ( $payment_method === 'cash' ) {
            $where[] = "order_data LIKE %s";
            $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"cash"%';
        } elseif ( $payment_method === 'check' ) {
            $where[] = "(order_data LIKE %s OR order_data LIKE %s)";
            $params[] = '%' . $wpdb->esc_like( '"paymentMethod"' ) . '%"check"%';
            $params[] = '%' . $wpdb->esc_like( '"checkNumber"' ) . '%';
        }
    }

    // Normalize stored products option (may be JSON string or array)
    $products_conf = order_sync_get_products_config();

    // Note: initial Orders page renders an empty table; data is fetched via AJAX. Do not run server-side queries here.
    $orders = array();

    ?>
    <div class="wrap">
        <h1>Orders</h1>

        <!-- Quick Search -->
        <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
            <label for="subsales-quick-search" style="font-weight: 600; margin-right: 10px;">🔍 Quick Search:</label>
            <input type="text" id="subsales-quick-search" placeholder="Search by customer name, address, or phone..." style="width: 400px; padding: 6px 12px;" />
            <button id="subsales-clear-search" class="button" style="margin-left: 8px;">Clear</button>
            <span id="subsales-search-results" style="margin-left: 15px; color: #666; font-style: italic;"></span>
        </div>

        <form id="subsales-orders-filter" class="subsales-orders-filter" onsubmit="return false;">
            <input type="hidden" name="action" value="subsales_fetch_orders" />
            <?php wp_nonce_field( 'subsales_orders_nonce', 'subsales_orders_nonce_field' ); ?>
            <table class="form-table" style="max-width: 960px;">
                <tr>
                    <th style="width:110px">Start date</th>
                    <td><input type="date" name="start_date" /></td>
                    <th>End date</th>
                    <td><input type="date" name="end_date" /></td>
                </tr>
                <tr>
                    <th>Team</th>
                    <td>
                        <select name="team_id">
                            <option value="">All teams</option>
                            <?php foreach ( $teams as $t ) : ?>
                                <option value="<?php echo intval( $t['id'] ); ?>"><?php echo esc_html( $t['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <th>Team member</th>
                    <td>
                        <select name="entered_by_id">
                            <option value="">All members</option>
                            <?php foreach ( $members as $m ) : ?>
                                <?php $label = esc_html( $m['name'] ); $email = trim( strval( $m['email'] ?? '' ) ); if ( $email ) { $label .= ' (' . esc_html( $email ) . ')'; } ?>
                                <option value="<?php echo esc_attr( $m['id'] ); ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Payment</th>
                    <td>
                        <select name="payment_method">
                            <option value="">Any</option>
                            <option value="cash">Cash</option>
                            <option value="check">Check</option>
                        </select>
                    </td>
                    <th>Tally Status</th>
                    <td>
                        <select name="tally_status">
                            <option value="untallied">Untallied Only</option>
                            <option value="tallied">Tallied Only</option>
                            <option value="all" selected>All Orders</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Show Deleted</th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_deleted" value="1" />
                            Include deleted orders
                        </label>
                    </td>
                    <th>Page size</th>
                    <td>
                        <select name="page_size">
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100" selected>100</option>
                        </select>
                        <span class="description">(max 100)</span>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button id="subsales-filter-btn" class="button button-primary">Filter</button>
                <button id="subsales-reset-btn" type="button" class="button">Reset</button>
            </p>
        </form>

        <div style="margin-bottom: 15px;">
            <button id="subsales-bulk-tally-btn" class="button button-secondary" disabled>
                Mark Selected as Tallied
            </button>
            <span id="subsales-selected-count" style="margin-left: 10px; color: #666;"></span>
        </div>

        <div id="subsales-orders-results">
            <p id="subsales-orders-meta" style="margin-bottom:8px"></p>
            <div style="overflow-x: auto; max-width: 100%;">
            <table id="subsales-orders-table" class="widefat striped" cellspacing="0" style="table-layout: auto; min-width: 100%; width: max-content;">
                <thead>
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" id="subsales-select-all" title="Select all" /></th>
                        <th style="white-space: nowrap;">Order ID</th>
                        <th style="white-space: nowrap;">Date</th>
                        <th style="white-space: nowrap;">Member</th>
                        <th style="white-space: nowrap;">Team</th>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <th style="text-align:center; white-space: nowrap; padding: 8px 4px;" title="<?php echo esc_attr( $pcol['name'] ); ?>"><?php echo esc_html( substr( $pcol['name'], 0, 6 ) ); ?></th>
                        <?php endforeach; ?>
                        <th style="text-align:right; white-space: nowrap;">Donate</th>
                        <th style="white-space: nowrap;">Pay</th>
                        <th style="text-align:right; white-space: nowrap;">Total</th>
                        <th style="white-space: nowrap;">Tallied</th>
                        <th style="white-space: nowrap;">Actions</th>
                    </tr>
                </thead>
                <tbody id="subsales-orders-tbody">
                    <tr><td colspan="<?php echo 8 + count( $products_conf ); ?>">Use the filters above and click Filter to load orders via AJAX.</td></tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" style="text-align:right">Page totals:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td id="subsales-page-prod-<?php echo esc_attr( $pcol['id'] ); ?>" style="text-align:center">0</td>
                        <?php endforeach; ?>
                        <td id="subsales-page-donation" style="text-align:right">$0.00</td>
                        <td></td>
                        <td id="subsales-page-total" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align:right">Cash:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td></td>
                        <td id="subsales-page-cash" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                    <tr>
                        <td colspan="5" style="text-align:right">Check:</td>
                        <?php foreach ( $products_conf as $pcol ) : ?>
                            <td></td>
                        <?php endforeach; ?>
                        <td></td>
                        <td></td>
                        <td id="subsales-page-check" style="text-align:right">$0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>

            <div id="subsales-pagination" style="margin-top:12px"></div>
        </div>
    </div>

    <!-- Edit Order Modal -->
    <div id="subsales-edit-modal" class="subsales-modal" style="display:none">
        <div class="subsales-modal-backdrop"></div>
        <div class="subsales-modal-content">
            <div class="subsales-modal-header">
                <h2>Edit Order</h2>
                <button class="subsales-modal-close" onclick="SubsalesOrderEdit.closeEditModal()">&times;</button>
            </div>
            <div class="subsales-modal-body">
                <form id="subsales-edit-form">
                    <input type="hidden" name="order_db_id" />
                    <input type="hidden" name="order_id" />
                    
                    <table class="form-table">
                        <tr>
                            <th><label>Customer Name</label></th>
                            <td><input type="text" name="customer" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th><label>Address</label></th>
                            <td><input type="text" name="address" class="regular-text" required /></td>
                        </tr>
                        <tr>
                            <th><label>Unit / Floor / Apt</label></th>
                            <td><input type="text" name="unitFloorApt" class="regular-text" placeholder="Optional" /></td>
                        </tr>
                        <tr>
                            <th><label>Cell Number</label></th>
                            <td><input type="tel" name="cellNumber" class="regular-text" placeholder="Optional" /></td>
                        </tr>
                    </table>
                    
                    <h3 style="border: 2px solid #0073aa; padding: 10px; background: #f0f6fc; margin: 15px 0 5px 0; border-radius: 4px;">Products</h3>
                    <table class="form-table" style="border: 2px solid #0073aa; margin-bottom: 15px; border-radius: 4px;">
                        <tbody id="subsales-edit-products"></tbody>
                    </table>
                    
                    <table class="form-table">
                        <tr>
                            <th><label>Donation Amount (USD)</label></th>
                            <td><input type="number" name="donationAmount" class="regular-text" min="0" step="0.01" placeholder="$0.00" /></td>
                        </tr>
                        <tr>
                            <th><label>Payment Method</label></th>
                            <td>
                                <select name="paymentMethod">
                                    <option value="cash">Cash</option>
                                    <option value="check">Check</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Check Number</label></th>
                            <td><input type="text" name="checkNumber" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label>Delivery Instructions</label></th>
                            <td><textarea name="notes" class="large-text" rows="3" placeholder="House color, long driveway, etc."></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Edit Reason</label> <span style="color:red">*</span></th>
                            <td><textarea name="_edit_reason" class="large-text" rows="2" placeholder="Explain why this order is being edited..." required></textarea></td>
                        </tr>
                    </table>
                </form>
            </div>
            <div class="subsales-modal-footer">
                <button class="button button-large" onclick="SubsalesOrderEdit.closeEditModal()">Cancel</button>
                <button class="button button-primary button-large" onclick="SubsalesOrderEdit.saveOrder()">Save Changes</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="subsales-delete-modal" class="subsales-modal" style="display:none">
        <div class="subsales-modal-backdrop"></div>
        <div class="subsales-modal-content" style="max-width:500px">
            <div class="subsales-modal-header">
                <h2>Delete Order</h2>
                <button class="subsales-modal-close" onclick="SubsalesOrderEdit.closeDeleteModal()">&times;</button>
            </div>
            <div class="subsales-modal-body">
                <p><strong>Are you sure you want to delete this order?</strong></p>
                <p id="subsales-delete-order-info"></p>
                <form id="subsales-delete-form">
                    <input type="hidden" name="order_db_id" />
                    <input type="hidden" name="order_id" />
                    <table class="form-table">
                        <tr>
                            <th><label>Delete Reason</label> <span style="color:red">*</span></th>
                            <td><textarea name="delete_reason" class="large-text" rows="3" placeholder="Explain why this order is being deleted..." required></textarea></td>
                        </tr>
                    </table>
                </form>
            </div>
            <div class="subsales-modal-footer">
                <button class="button button-large" onclick="SubsalesOrderEdit.closeDeleteModal()">Cancel</button>
                <button class="button button-primary button-large" style="background:red;border-color:darkred" onclick="SubsalesOrderEdit.confirmDelete()">Delete Order</button>
            </div>
        </div>
    </div>

    <!-- History Panel -->
    <div id="subsales-history-panel" class="subsales-history-panel" style="display:none">
        <div class="subsales-history-header">
            <h3>Order Edit History</h3>
            <button class="button" onclick="SubsalesOrderEdit.closeHistoryPanel()">&times; Close</button>
        </div>
        <div id="subsales-history-content" class="subsales-history-content">
            <p>Loading...</p>
        </div>
    </div>

    <style>
        .subsales-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: 100000; }
        .subsales-modal-backdrop { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); }
        .subsales-modal-content { position: relative; max-width: 700px; margin: 40px auto; background: white; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); max-height: 90vh; display: flex; flex-direction: column; }
        .subsales-modal-header { padding: 20px 24px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
        .subsales-modal-header h2 { margin: 0; }
        .subsales-modal-close { background: none; border: none; font-size: 28px; cursor: pointer; padding: 0; line-height: 1; color: #666; }
        .subsales-modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .subsales-modal-footer { padding: 16px 24px; border-top: 1px solid #ddd; text-align: right; }
        .subsales-modal-footer button { margin-left: 8px; }
        
        .subsales-history-panel { position: fixed; top: 0; right: 0; width: 500px; height: 100%; background: white; box-shadow: -2px 0 10px rgba(0,0,0,0.3); z-index: 100001; overflow-y: auto; }
        .subsales-history-header { padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; background: white; }
        .subsales-history-content { padding: 20px; }
        .subsales-history-item { border: 1px solid #ddd; border-radius: 4px; padding: 16px; margin-bottom: 16px; background: #f9f9f9; }
        .subsales-history-item h4 { margin: 0 0 8px 0; color: #2271b1; }
        .subsales-history-item .meta { color: #666; font-size: 0.9em; margin-bottom: 8px; }
        .subsales-history-item .summary { margin-bottom: 12px; }
        .subsales-history-changes { background: white; border: 1px solid #ddd; padding: 12px; border-radius: 3px; font-size: 0.9em; }
        .subsales-history-change { margin-bottom: 8px; padding: 8px; background: #f5f5f5; border-left: 3px solid #2271b1; }
        .subsales-history-change strong { display: inline-block; min-width: 120px; }
        .subsales-change-before { color: #d63638; text-decoration: line-through; }
        .subsales-change-after { color: #00a32a; font-weight: 600; }
        
        .subsales-orders-meta-note { float: right; color: #666; font-size: 0.9em; }
        .subsales-edited-star { color: red; font-weight: bold; }
        .subsales-action-btn { 
            padding: 6px 12px; 
            font-size: 12px; 
            margin-right: 4px; 
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid #ddd;
            background: white;
        }
        .subsales-action-btn:hover { 
            background: #f0f0f1;
            border-color: #2271b1;
            color: #2271b1;
        }
        .subsales-action-btn-edit { 
            background: #2271b1;
            color: white;
            border-color: #2271b1;
        }
        .subsales-action-btn-edit:hover { 
            background: #135e96;
        }
        .subsales-action-btn-delete { 
            background: #d63638;
            color: white;
            border-color: #d63638;
        }
        .subsales-action-btn-delete:hover { 
            background: #b32d2e;
        }
        .subsales-action-btn-history {
            background: #dba617;
            color: white;
            border-color: #dba617;
        }
        .subsales-action-btn-history:hover {
            background: #c29500;
        }
    </style>

    <script>
    (function(){
        const ajaxUrl = <?php echo json_encode( $ajax_url ); ?>;
        const nonce = <?php echo json_encode( $nonce ); ?>;
        const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
        const restUrl = <?php echo json_encode( rest_url( 'order-manager/v1/orders/tally' ) ); ?>;
        const restNonce = <?php echo json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
        
        let selectedOrderIds = new Set();

        function serializeForm(form){
            const fd = new FormData();
            fd.append('action','subsales_fetch_orders');
            fd.append('nonce', nonce);
            const f = new FormData(form);
            for (const [k,v] of f.entries()){ if (v !== null) fd.append(k,v); }
            return fd;
        }
        
        function updateTallyButton(){
            const btn = document.getElementById('subsales-bulk-tally-btn');
            const countSpan = document.getElementById('subsales-selected-count');
            const count = selectedOrderIds.size;
            
            if (count > 0) {
                btn.disabled = false;
                countSpan.textContent = count;
            } else {
                btn.disabled = true;
                countSpan.textContent = '0';
            }
        }
        
        function handleCheckboxChange(orderId, checked){
            if (checked) {
                selectedOrderIds.add(orderId);
            } else {
                selectedOrderIds.delete(orderId);
            }
            updateTallyButton();
        }
        
        function handleSelectAllChange(checked){
            const checkboxes = document.querySelectorAll('.subsales-order-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checked;
                const orderId = parseInt(cb.dataset.orderId);
                if (checked) {
                    selectedOrderIds.add(orderId);
                } else {
                    selectedOrderIds.delete(orderId);
                }
            });
            updateTallyButton();
        }
        
        async function bulkTallyOrders(){
            if (selectedOrderIds.size === 0) {
                alert('Please select at least one order to tally.');
                return;
            }
            
            if (!confirm('Mark ' + selectedOrderIds.size + ' order(s) as tallied?')) {
                return;
            }
            
            const orderIdsArray = Array.from(selectedOrderIds);
            
            try {
                const resp = await fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce
                    },
                    body: JSON.stringify({ order_ids: orderIdsArray })
                });
                
                const data = await resp.json();
                
                if (data.success_count > 0) {
                    alert('Successfully tallied ' + data.success_count + ' order(s)');
                    selectedOrderIds.clear();
                    document.getElementById('subsales-select-all').checked = false;
                    updateTallyButton();
                    fetchPage(1); // Refresh the table
                } else {
                    alert('Failed to tally orders: ' + (data.errors ? data.errors.join(', ') : 'Unknown error'));
                }
            } catch (error) {
                console.error('Tally error:', error);
                alert('Error tallying orders: ' + error.message);
            }
        }

        function renderRows(orders){
            const tbody = document.getElementById('subsales-orders-tbody');
            tbody.innerHTML = '';
                if (!orders || orders.length === 0) {
                tbody.innerHTML = '<tr><td colspan="' + (10 + configuredProducts.length) + '">No orders found for the selected filters.</td></tr>';
                return;
            }
            for (const o of orders){
                const tr = document.createElement('tr');
                let html = '';
                
                // Checkbox column
                html += '<td style="text-align:center; width:30px;">';
                html += '<input type="checkbox" class="subsales-order-checkbox" data-order-id="' + o.id + '" onchange="handleCheckboxChange(' + o.id + ', this.checked)">';
                html += '</td>';
                
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.order_id) + (o.edited ? ' <span class="subsales-edited-star">*</span>' : '') + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.created_at_formatted) + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.entered_by_name || o.user_id || '') + '</td>';
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.team_name || '') + '</td>';
                // per-configured-product columns
                for (const p of configuredProducts) {
                    const pid = p.id;
                    const qty = (o.products_map && typeof o.products_map[pid] !== 'undefined') ? Number(o.products_map[pid]) : 0;
                    html += '<td style="text-align:center; white-space: nowrap; padding: 8px 4px;">' + escapeHtml(qty) + '</td>';
                }
                // Donation column
                const donationAmt = Number(o.donation_amount || 0);
                html += '<td style="text-align:right; white-space: nowrap;">' + (donationAmt > 0 ? '$' + donationAmt.toFixed(2) : '') + '</td>';
                // Items column removed; individual product columns are shown above.
                html += '<td style="white-space: nowrap;">' + escapeHtml(o.payment_display || '') + '</td>';
                html += '<td style="text-align:right; white-space: nowrap;">$' + Number(o.order_total).toFixed(2) + '</td>';
                
                // Tallied column
                html += '<td style="text-align:center; white-space: nowrap;">';
                if (o.tallied) {
                    const tallyDate = o.tallied_at ? new Date(o.tallied_at).toLocaleDateString() : '';
                    const tallyBy = o.tallied_by ? ' by ' + escapeHtml(o.tallied_by) : '';
                    html += '<span title="Tallied ' + tallyDate + tallyBy + '">✓</span>';
                } else {
                    html += '';
                }
                html += '</td>';
                
                // Actions column
                html += '<td style="white-space: nowrap;">';
                html += '<button class="subsales-action-btn subsales-action-btn-edit" onclick="SubsalesOrderEdit.editOrder(' + o.id + ',\'' + escapeHtml(o.order_id).replace(/'/g, "\\'") + '\')" title="Edit order">✏️ Edit</button>';
                html += '<button class="subsales-action-btn subsales-action-btn-delete" onclick="SubsalesOrderEdit.deleteOrder(' + o.id + ',\'' + escapeHtml(o.order_id).replace(/'/g, "\\'") + '\')" title="Delete order">🗑️ Delete</button>';
                html += '<button class="subsales-action-btn subsales-action-btn-history" onclick="SubsalesOrderEdit.viewHistory(' + o.id + ')" title="View history">📋 History</button>';
                html += '</td>';
                
                tr.innerHTML = html;
                tbody.appendChild(tr);
            }
        }

        function renderMeta(total_count, page, pages){
            const meta = document.getElementById('subsales-orders-meta');
            // Put the page meta on the left and an explanatory note on the right
            meta.innerHTML = 'Showing page ' + page + ' of ' + pages + ' — ' + total_count + ' matching orders' +
                '<span class="subsales-orders-meta-note"><span style="color: red;">*</span> indicates edited order</span>';
        }

        function renderTotals(totals){
            // totals.product_totals is expected to be a map of productId => qty for the current page
            try{
                if (totals && totals.product_totals){
                    for (const p of configuredProducts){
                        const pid = p.id;
                        const el = document.getElementById('subsales-page-prod-' + pid);
                        if (el) el.textContent = (totals.product_totals[pid] !== undefined) ? String(totals.product_totals[pid]) : '0';
                    }
                } else {
                    // clear product totals
                    for (const p of configuredProducts){ const el = document.getElementById('subsales-page-prod-' + p.id); if (el) el.textContent = '0'; }
                }
            }catch(e){ console.warn('renderTotals product totals error', e); }
            document.getElementById('subsales-page-donation').textContent = '$' + Number(totals.donations || 0).toFixed(2);
            document.getElementById('subsales-page-total').textContent = '$' + Number(totals.grand || 0).toFixed(2);
            document.getElementById('subsales-page-cash').textContent = '$' + Number(totals.cash || 0).toFixed(2);
            document.getElementById('subsales-page-check').textContent = '$' + Number(totals.check || 0).toFixed(2);
        }

        function renderPagination(page, pages){
            const el = document.getElementById('subsales-pagination');
            el.innerHTML = '';
            if (pages <= 1) return;
            for (let p=1;p<=pages;p++){
                const btn = document.createElement('button');
                btn.className = 'button';
                btn.style.marginRight = '6px';
                btn.textContent = p;
                if (p === page) btn.disabled = true;
                btn.addEventListener('click', function(){ fetchPage(p); });
                el.appendChild(btn);
            }
        }

        function escapeHtml(s){ if (!s && s !== 0) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

        async function fetchPage(page){
            const form = document.getElementById('subsales-orders-filter');
            const fd = serializeForm(form);
            fd.append('page', page);
            // page_size is included from form
            try {
                const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
                const data = await resp.json();
                console.log('AJAX Response:', data);
                if (!data || !data.success){
                    console.error('AJAX Error:', data);
                    alert('Failed to fetch orders: ' + (data && data.data ? data.data : 'Unknown error'));
                    return;
                }
                const payload = data.data;
                renderRows(payload.orders);
                renderMeta(payload.total_count, payload.page, payload.pages);
                renderTotals(payload.totals);
                renderPagination(payload.page, payload.pages);
            } catch (error) {
                console.error('Fetch error:', error);
                alert('Error loading orders: ' + error.message);
            }
        }

        document.getElementById('subsales-filter-btn').addEventListener('click', function(){ fetchPage(1); });
        document.getElementById('subsales-reset-btn').addEventListener('click', function(){
            document.getElementById('subsales-orders-filter').reset(); fetchPage(1);
        });
        
        // Select all checkbox handler
        document.getElementById('subsales-select-all').addEventListener('change', function(){
            handleSelectAllChange(this.checked);
        });
        
        // Bulk tally button handler
        document.getElementById('subsales-bulk-tally-btn').addEventListener('click', function(){
            bulkTallyOrders();
        });
        
        // Make functions globally available
        window.handleCheckboxChange = handleCheckboxChange;
        window.handleSelectAllChange = handleSelectAllChange;

        // Quick Search functionality
        let searchTimeout = null;
        const searchInput = document.getElementById('subsales-quick-search');
        const clearSearchBtn = document.getElementById('subsales-clear-search');
        const searchResults = document.getElementById('subsales-search-results');
        
        function performSearch() {
            const searchTerm = searchInput.value.trim();
            
            if (searchTerm.length === 0) {
                searchResults.textContent = '';
                fetchPage(1);
                return;
            }
            
            if (searchTerm.length < 2) {
                searchResults.textContent = 'Enter at least 2 characters to search';
                return;
            }
            
            searchResults.textContent = 'Searching...';
            
            const form = document.getElementById('subsales-orders-filter');
            const fd = serializeForm(form);
            fd.append('page', 1);
            fd.append('search_query', searchTerm);
            
            fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(resp => resp.json())
                .then(data => {
                    if (data && data.success) {
                        const payload = data.data;
                        renderRows(payload.orders);
                        renderMeta(payload.total_count, payload.page, payload.pages);
                        renderTotals(payload.totals);
                        renderPagination(payload.page, payload.pages);
                        
                        const count = payload.total_count || 0;
                        searchResults.textContent = count + ' result' + (count !== 1 ? 's' : '') + ' found';
                    } else {
                        searchResults.textContent = 'Search failed';
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    searchResults.textContent = 'Search error';
                });
        }
        
        // Debounced search as user types
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(performSearch, 500);
        });
        
        // Search on Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                clearTimeout(searchTimeout);
                performSearch();
            }
        });
        
        // Clear search button
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchResults.textContent = '';
            fetchPage(1);
        });

        // Load first page on open
        fetchPage(1);
        
        // Make fetchPage available globally for refresh after edit/delete
        window.SubsalesRefreshOrders = function(){ fetchPage(1); };
    })();
    
    // Order Edit/Delete/History Manager
    window.SubsalesOrderEdit = {
        currentOrder: null,
        
        async editOrder(orderDbId, orderId) {
            try {
                // Fetch full order data using database ID
                const resp = await fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>?action=subsales_get_order_by_db_id&id=' + orderDbId + '&nonce=<?php echo wp_create_nonce( 'wp_rest' ); ?>');
                const result = await resp.json();
                
                console.log('Edit order response:', result);
                
                if (!result || !result.success || !result.data) {
                    alert('Failed to load order: ' + (result && result.data ? result.data : 'Unknown error'));
                    return;
                }
                
                const order = result.data;
                console.log('Order object:', order);
                console.log('Order data:', order.order_data);
                
                if (!order || !order.order_data) {
                    alert('Failed to load order: Invalid data structure');
                    return;
                }
                
                this.currentOrder = order;
                const data = order.order_data;
                
                console.log('Parsed data:', data);
                console.log('customer:', data.customer);
                
                // Populate form - match PWA field structure
                const form = document.getElementById('subsales-edit-form');
                form.elements['order_db_id'].value = order.id || '';
                form.elements['order_id'].value = order.order_id || '';
                form.elements['customer'].value = data.customer || data.customerName || '';
                form.elements['address'].value = data.address || '';
                form.elements['unitFloorApt'].value = data.unitFloorApt || '';
                form.elements['cellNumber'].value = data.cellNumber || data.phone || '';
                form.elements['donationAmount'].value = data.donationAmount || '';
                form.elements['paymentMethod'].value = data.paymentMethod || 'cash';
                form.elements['checkNumber'].value = data.checkNumber || '';
                form.elements['notes'].value = data.notes || '';
                form.elements['_edit_reason'].value = '';
                
                // Populate products
                const productsContainer = document.getElementById('subsales-edit-products');
                productsContainer.innerHTML = '';
                const products = data.products || [];
                const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
                
                console.log('Products from order:', products);
                console.log('Configured products:', configuredProducts);
                
                for (const p of configuredProducts) {
                    const existing = products.find(pr => pr.id === p.id);
                    const qty = existing ? existing.qty : 0;
                    
                    const tr = document.createElement('tr');
                    tr.innerHTML = '<th><label>' + p.name + '</label></th>' +
                        '<td><input type="number" name="product_' + p.id + '" min="0" value="' + qty + '" /></td>';
                    productsContainer.appendChild(tr);
                }
                
                // Show modal
                document.getElementById('subsales-edit-modal').style.display = 'block';
            } catch (error) {
                console.error('Edit order error:', error);
                alert('Failed to load order: ' + error.message);
            }
        },
        
        closeEditModal() {
            document.getElementById('subsales-edit-modal').style.display = 'none';
            this.currentOrder = null;
        },
        
        async saveOrder() {
            const form = document.getElementById('subsales-edit-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            const orderDbId = form.elements['order_db_id'].value;
            const orderId = form.elements['order_id'].value;
            
            // Build updated order data - preserve existing metadata from original order
            const originalData = this.currentOrder.order_data || {};
            const data = {
                order_id: orderId,
                user_id: this.currentOrder.user_id,
                team_id: this.currentOrder.team_id,
                customer: form.elements['customer'].value,
                address: form.elements['address'].value,
                unitFloorApt: form.elements['unitFloorApt'].value,
                cellNumber: form.elements['cellNumber'].value,
                donationAmount: parseFloat(form.elements['donationAmount'].value) || 0,
                paymentMethod: form.elements['paymentMethod'].value,
                checkNumber: form.elements['checkNumber'].value,
                notes: form.elements['notes'].value,
                _edit_reason: form.elements['_edit_reason'].value,
                products: [],
                // Preserve metadata from original order
                createdAt: originalData.createdAt || new Date().toISOString(),
                entered_by_id: originalData.entered_by_id || '',
                entered_by_name: originalData.entered_by_name || '',
                team_name: originalData.team_name || '',
                team_code: originalData.team_code || '',
                geo: originalData.geo || null
            };
            
            // Collect products - PRESERVE ORIGINAL PRICES from price_snapshot or products array
            const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
            const originalProducts = originalData.products || [];
            const priceSnapshot = originalData.price_snapshot || {};
            
            for (const p of configuredProducts) {
                const qty = parseInt(form.elements['product_' + p.id].value) || 0;
                
                // Price priority: 1) price_snapshot, 2) original product, 3) current config
                let price = p.price; // Default to current config
                if (priceSnapshot[p.id] !== undefined) {
                    price = priceSnapshot[p.id]; // Best: use price snapshot
                } else {
                    const originalProduct = originalProducts.find(op => String(op.id) === String(p.id));
                    if (originalProduct) {
                        price = originalProduct.price; // Fallback: use original product price
                    }
                }
                
                data.products.push({
                    id: p.id,
                    name: p.name,
                    price: price,
                    qty: qty
                });
            }
            
            // Preserve price_snapshot for future edits
            if (Object.keys(priceSnapshot).length > 0) {
                data.price_snapshot = priceSnapshot;
            }
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderId, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await resp.json();
                
                if (resp.ok) {
                    alert('Order updated successfully!');
                    this.closeEditModal();
                    window.SubsalesRefreshOrders();
                } else {
                    alert('Failed to update order: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Save order error:', error);
                alert('Failed to save order: ' + error.message);
            }
        },
        
        deleteOrder(orderDbId, orderId) {
            this.currentOrder = { id: orderDbId, order_id: orderId };
            document.getElementById('subsales-delete-form').elements['order_db_id'].value = orderDbId;
            document.getElementById('subsales-delete-form').elements['order_id'].value = orderId;
            document.getElementById('subsales-delete-order-info').textContent = 'Order: ' + orderId;
            document.getElementById('subsales-delete-form').elements['delete_reason'].value = '';
            document.getElementById('subsales-delete-modal').style.display = 'block';
        },
        
        closeDeleteModal() {
            document.getElementById('subsales-delete-modal').style.display = 'none';
            this.currentOrder = null;
        },
        
        async confirmDelete() {
            const form = document.getElementById('subsales-delete-form');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            
            const orderId = form.elements['order_id'].value;
            const deleteReason = form.elements['delete_reason'].value;
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderId, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    },
                    body: JSON.stringify({ delete_reason: deleteReason })
                });
                
                const result = await resp.json();
                
                if (resp.ok) {
                    alert('Order deleted successfully!');
                    this.closeDeleteModal();
                    window.SubsalesRefreshOrders();
                } else {
                    alert('Failed to delete order: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                console.error('Delete order error:', error);
                alert('Failed to delete order: ' + error.message);
            }
        },
        
        async viewHistory(orderDbId) {
            document.getElementById('subsales-history-panel').style.display = 'block';
            document.getElementById('subsales-history-content').innerHTML = '<p>Loading history...</p>';
            
            try {
                const resp = await fetch('<?php echo esc_js( rest_url( 'order-manager/v1/orders/' ) ); ?>' + orderDbId + '/history', {
                    headers: {
                        'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>'
                    }
                });
                const data = await resp.json();
                
                if (!resp.ok || !data.history) {
                    document.getElementById('subsales-history-content').innerHTML = '<p>Failed to load history</p>';
                    return;
                }
                
                if (data.history.length === 0) {
                    document.getElementById('subsales-history-content').innerHTML = '<p>No edit history for this order.</p>';
                    return;
                }
                
                // Render history
                let html = '<div class="subsales-history-items">';
                for (const item of data.history) {
                    html += '<div class="subsales-history-item">';
                    html += '<h4>' + this.escapeHtml(item.edit_type.toUpperCase()) + '</h4>';
                    html += '<div class="meta">';
                    html += 'By: ' + this.escapeHtml(item.edited_by_name) + ' | ';
                    html += 'Date: ' + this.escapeHtml(item.edited_at) + '</div>';
                    html += '<div class="summary"><strong>Summary:</strong> ' + this.escapeHtml(item.changes_summary) + '</div>';
                    
                    if (item.edit_reason) {
                        html += '<div><strong>Reason:</strong> ' + this.escapeHtml(item.edit_reason) + '</div>';
                    }
                    
                    // Show detailed changes
                    if (item.changes_detail && item.changes_detail.changes) {
                        html += '<details style="margin-top:12px"><summary style="cursor:pointer;color:#2271b1"><strong>View Detailed Changes</strong></summary>';
                        html += '<div class="subsales-history-changes">';
                        for (const change of item.changes_detail.changes) {
                            html += '<div class="subsales-history-change">';
                            html += '<strong>' + this.escapeHtml(change.label) + ':</strong> ';
                            
                            if (change.field === 'products') {
                                html += '<br/><span class="subsales-change-before">' + this.renderProducts(change.before) + '</span>';
                                html += '<br/><span class="subsales-change-after">' + this.renderProducts(change.after) + '</span>';
                            } else {
                                html += '<span class="subsales-change-before">' + this.escapeHtml(change.before) + '</span> → ';
                                html += '<span class="subsales-change-after">' + this.escapeHtml(change.after) + '</span>';
                            }
                            html += '</div>';
                        }
                        html += '</div></details>';
                    }
                    
                    html += '</div>';
                }
                html += '</div>';
                
                document.getElementById('subsales-history-content').innerHTML = html;
            } catch (error) {
                console.error('View history error:', error);
                document.getElementById('subsales-history-content').innerHTML = '<p>Error loading history: ' + error.message + '</p>';
            }
        },
        
        closeHistoryPanel() {
            document.getElementById('subsales-history-panel').style.display = 'none';
        },
        
        escapeHtml(s) {
            if (!s && s !== 0) return '';
            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        },
        
        renderProducts(products) {
            if (!products || products.length === 0) return '(none)';
            return products.map(p => p.name + ' ×' + p.qty).join(', ');
        }
    };
</script>
<?php
}

// ========================================
// Campaign AJAX Handlers
// ========================================

/**
 * Toggle campaign date (activate/deactivate)
 */
function subsales_ajax_toggle_campaign() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $date = isset( $_POST['date'] ) ? sanitize_text_field( $_POST['date'] ) : '';
    $action = isset( $_POST['campaign_action'] ) ? sanitize_text_field( $_POST['campaign_action'] ) : '';
    
    if ( ! $date || ! $action ) {
        wp_send_json_error( array( 'message' => 'Missing parameters' ) );
    }
    
    // Validate date format
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
        wp_send_json_error( array( 'message' => 'Invalid date format' ) );
    }
    
    // Check if campaign exists
    $campaign = Subsales_Database::get_campaign_by_date( $date );
    
    if ( $action === 'activate' ) {
        if ( $campaign ) {
            // Update existing campaign to active
            $result = Subsales_Database::toggle_campaign_status( $campaign['id'], 'active' );
        } else {
            // Create new campaign
            $result = Subsales_Database::save_campaign( array(
                'campaign_date' => $date,
                'campaign_name' => '',
                'status' => 'active'
            ) );
        }
        
        if ( $result ) {
            subsales_log( 'INFO', 'campaigns', 'Campaign date activated: ' . $date, array(), 'admin' );
            wp_send_json_success( array( 'message' => 'Campaign activated' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Failed to activate campaign' ) );
        }
    } elseif ( $action === 'deactivate' ) {
        if ( $campaign ) {
            $result = Subsales_Database::toggle_campaign_status( $campaign['id'], 'inactive' );
            
            if ( $result ) {
                subsales_log( 'INFO', 'campaigns', 'Campaign date deactivated: ' . $date, array(), 'admin' );
                wp_send_json_success( array( 'message' => 'Campaign deactivated' ) );
            } else {
                wp_send_json_error( array( 'message' => 'Failed to deactivate campaign' ) );
            }
        } else {
            wp_send_json_error( array( 'message' => 'Campaign not found' ) );
        }
    } else {
        wp_send_json_error( array( 'message' => 'Invalid action' ) );
    }
}

/**
 * Delete campaign date
 */
function subsales_ajax_delete_campaign() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $campaign_id = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;
    
    if ( ! $campaign_id ) {
        wp_send_json_error( array( 'message' => 'Missing campaign ID' ) );
    }
    
    // true on success, otherwise the reason it was refused - show that verbatim
    // rather than guessing at "has signups", which was only ever one of three.
    $result = Subsales_Database::delete_campaign( $campaign_id );

    if ( true === $result ) {
        subsales_log( 'INFO', 'campaigns', 'Campaign deleted: ID ' . $campaign_id, array(), 'admin' );
        wp_send_json_success( array( 'message' => 'Campaign deleted' ) );
    } else {
        wp_send_json_error( array( 'message' => $result ) );
    }
}

/**
 * Get campaign signups for modal display
 */
function subsales_ajax_get_campaign_signups() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $campaign_id = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;
    
    if ( ! $campaign_id ) {
        wp_send_json_error( array( 'message' => 'Missing campaign ID' ) );
    }
    
    $campaign = Subsales_Database::get_campaign( $campaign_id );
    
    if ( ! $campaign ) {
        wp_send_json_error( array( 'message' => 'Campaign not found' ) );
    }
    
    global $wpdb;
    
    // Get signups with member and team info
    $signups = Subsales_Database::get_signups( array(
        'campaign_id' => $campaign_id,
        'status' => 'active'
    ) );
    
    // Get all teams for the add signup dropdown
    $teams = Subsales_Database::get_teams( 'active' );
    
    // Get driver info for each team
    $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';
    $driver_info = array();
    foreach ( $signups as $signup ) {
        $team_id = $signup['team_id'];
        if ( ! isset( $driver_info[ $team_id ] ) ) {
            $result = $wpdb->get_row( $wpdb->prepare(
                "SELECT driver_name, driver_updated_by, driver_updated_at 
                 FROM $team_campaigns_table 
                 WHERE team_id = %d AND campaign_id = %d",
                $team_id,
                $campaign_id
            ), ARRAY_A );
            $driver_info[ $team_id ] = $result ?: array( 'driver_name' => '', 'driver_updated_by' => '', 'driver_updated_at' => '' );
        }
    }
    
    // Group by team
    $team_signups = array();
    foreach ( $signups as $signup ) {
        $team_id = $signup['team_id'];
        if ( ! isset( $team_signups[ $team_id ] ) ) {
            $team_signups[ $team_id ] = array(
                'team_id' => $team_id,
                'team_name' => $signup['team_name'],
                'members' => array(),
                'driver_name' => isset( $driver_info[ $team_id ] ) ? $driver_info[ $team_id ]['driver_name'] : '',
                'driver_updated_by' => isset( $driver_info[ $team_id ] ) ? $driver_info[ $team_id ]['driver_updated_by'] : '',
                'driver_updated_at' => isset( $driver_info[ $team_id ] ) ? $driver_info[ $team_id ]['driver_updated_at'] : ''
            );
        }
        
        $team_signups[ $team_id ]['members'][] = array(
            'signup_id' => $signup['id'],
            'user_id' => $signup['user_id'],
            'name' => $signup['user_name'],
            'phone' => $signup['user_phone'],
            'email' => $signup['user_email']
        );
    }
    
    // Build HTML - Compact team list view
    $date_formatted = date( 'F j, Y', strtotime( $campaign['campaign_date'] ) );
    $html = '<div class="subsales-signups-modal-header">';
    $html .= '<h2>Signups for ' . esc_html( $date_formatted ) . '</h2>';
    $html .= '<button type="button" class="button button-primary" id="add-signup-btn">+ Add Signup</button>';
    $html .= '</div>';
    
    // Add signup form (hidden by default) - NOW WITH IMMEDIATE MEMBER ADDITION
    $html .= '<div id="add-signup-form" style="display: none; background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 15px 0;">';
    $html .= '<h3 style="margin-top: 0;">Add New Signup</h3>';
    $html .= '<div style="margin-bottom: 10px;">';
    $html .= '<label style="display: block; font-weight: 600; margin-bottom: 5px;">Team:</label>';
    $html .= '<select id="new-signup-team" style="width: 100%; padding: 6px;">';
    $html .= '<option value="">Select a team...</option>';
    foreach ( $teams as $team ) {
        $html .= '<option value="' . esc_attr( $team['id'] ) . '">' . esc_html( $team['name'] ) . '</option>';
    }
    $html .= '</select>';
    $html .= '</div>';
    
    // Member selection - search with immediate add (like Edit flow)
    $html .= '<div id="member-selection-area">';
    $html .= '<div style="margin-bottom: 10px;">';
    $html .= '<label style="display: block; font-weight: 600; margin-bottom: 5px;">Add Member:</label>';
    $html .= '<input type="text" id="new-signup-search" placeholder="Search member by name or phone..." style="width: 100%; padding: 6px;">';
    $html .= '<div id="member-search-results" style="border: 1px solid #ddd; display: none; max-height: 200px; overflow-y: auto; background: white; margin-top: 2px;"></div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Added members list (initially hidden)
    $html .= '<div id="new-signup-members-list" style="display: none; margin-bottom: 15px;">';
    $html .= '<p style="font-weight: 600; margin-bottom: 8px;">Members:</p>';
    $html .= '<ul id="new-signup-members-ul" style="margin: 0; padding: 0; list-style: none;"></ul>';
    $html .= '</div>';
    
    $html .= '<div style="display: flex; gap: 10px;">';
    $html .= '<button type="button" class="button" id="cancel-signup-btn">Close</button>';
    $html .= '</div>';
    $html .= '</div>';
    
    if ( empty( $team_signups ) ) {
        $html .= '<p style="padding: 20px; text-align: center; color: #666;">No signups yet for this date. Click "Add Signup" to add one.</p>';
    } else {
        // Compact team list
        $html .= '<div class="subsales-signups-compact-list">';
        $html .= '<table class="wp-list-table widefat fixed striped">';
        $html .= '<thead><tr>';
        $html .= '<th style="width: 40%;">Team</th>';
        $html .= '<th style="width: 20%;">Members</th>';
        $html .= '<th style="width: 25%;">Driver</th>';
        $html .= '<th style="width: 15%;">Actions</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ( $team_signups as $team ) {
            $html .= '<tr class="team-compact-row" data-team-id="' . esc_attr( $team['team_id'] ) . '">';
            $html .= '<td><strong>' . esc_html( $team['team_name'] ) . '</strong></td>';
            $html .= '<td>' . count( $team['members'] ) . ' members</td>';
            $html .= '<td>';
            if ( $team['driver_name'] ) {
                $html .= '<span class="dashicons dashicons-yes" style="color: #46b450;"></span> ' . esc_html( $team['driver_name'] );
            } else {
                $html .= '<span style="color: #999;">—</span>';
            }
            $html .= '</td>';
            $html .= '<td><button type="button" class="button button-small edit-team-btn" data-team-id="' . esc_attr( $team['team_id'] ) . '">Edit</button></td>';
            $html .= '</tr>';
            
            // Hidden expandable detail row
            $html .= '<tr class="team-detail-row" data-team-id="' . esc_attr( $team['team_id'] ) . '" style="display: none;">';
            $html .= '<td colspan="4">';
            $html .= '<div class="team-detail-content">';
            
            // Driver section
            $html .= '<div class="driver-section" style="background: #fff3cd; padding: 12px; border-radius: 4px; margin-bottom: 12px;">';
            $html .= '<label style="display: block; font-weight: 600; margin-bottom: 5px;">Driver:</label>';
            $html .= '<div style="display: flex; gap: 10px; align-items: center;">';
            $html .= '<input type="text" class="driver-name-input" data-team-id="' . esc_attr( $team['team_id'] ) . '" value="' . esc_attr( $team['driver_name'] ) . '" placeholder="Enter driver name..." style="flex: 1; padding: 6px;">';
            $html .= '<button type="button" class="button button-small update-driver-btn" data-team-id="' . esc_attr( $team['team_id'] ) . '">Update</button>';
            $html .= '</div>';
            if ( $team['driver_updated_by'] ) {
                $updated_at = $team['driver_updated_at'] ? date( 'M j, Y g:i A', strtotime( $team['driver_updated_at'] ) ) : '';
                $html .= '<div style="font-size: 11px; color: #856404; margin-top: 5px;">';
                $html .= 'Last updated by ' . esc_html( $team['driver_updated_by'] );
                if ( $updated_at ) {
                    $html .= ' on ' . esc_html( $updated_at );
                }
                $html .= '</div>';
            }
            $html .= '</div>';
            
            // Members list
            $html .= '<div class="members-list">';
            $html .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">';
            $html .= '<p style="font-weight: 600; margin: 0;">Members:</p>';
            $html .= '<button type="button" class="button button-small add-member-to-team-btn" data-team-id="' . esc_attr( $team['team_id'] ) . '" data-team-name="' . esc_attr( $team['team_name'] ) . '">+ Add Member</button>';
            $html .= '</div>';
            
            // Add member mini-form (hidden)
            $html .= '<div class="add-member-form" data-team-id="' . esc_attr( $team['team_id'] ) . '" style="display: none; background: #f0f6ff; padding: 10px; border-radius: 4px; margin-bottom: 10px;">';
            $html .= '<input type="text" class="team-member-search" placeholder="Search member by name or phone..." style="width: 100%; padding: 6px; margin-bottom: 5px;">';
            $html .= '<div class="team-member-search-results" style="border: 1px solid #ddd; display: none; max-height: 150px; overflow-y: auto; background: white; margin-bottom: 5px;"></div>';
            $html .= '<button type="button" class="button button-small cancel-add-member-btn" data-team-id="' . esc_attr( $team['team_id'] ) . '">Cancel</button>';
            $html .= '</div>';
            
            $html .= '<ul style="margin: 0; padding: 0; list-style: none;">';
            foreach ( $team['members'] as $member ) {
                $html .= '<li style="padding: 8px; background: white; border-radius: 3px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center;">';
                $html .= '<span>' . esc_html( $member['name'] );
                if ( $member['phone'] ) {
                    $html .= ' <span style="color: #666;">(' . esc_html( $member['phone'] ) . ')</span>';
                }
                $html .= '</span>';
                $html .= '<button type="button" class="button button-small button-link-delete remove-signup-btn" data-signup-id="' . esc_attr( $member['signup_id'] ) . '" data-member-name="' . esc_attr( $member['name'] ) . '">Remove</button>';
                $html .= '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
            
            $html .= '<div style="margin-top: 10px; text-align: right;">';
            $html .= '<button type="button" class="button close-team-detail-btn" data-team-id="' . esc_attr( $team['team_id'] ) . '">Close</button>';
            $html .= '</div>';
            
            $html .= '</div></td></tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
    }
    
    wp_send_json_success( array( 
        'html' => $html,
        'campaign_id' => $campaign_id
    ) );
}

/**
 * AJAX handler to add a new signup
 */
function subsales_ajax_add_signup() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $campaign_id = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;
    $team_id = isset( $_POST['team_id'] ) ? intval( $_POST['team_id'] ) : 0;
    $user_id = isset( $_POST['user_id'] ) ? intval( $_POST['user_id'] ) : 0;
    
    if ( ! $campaign_id || ! $team_id || ! $user_id ) {
        wp_send_json_error( array( 'message' => 'Missing required fields' ) );
    }
    
    global $wpdb;
    $signups_table = $wpdb->prefix . 'ss_signups';
    
    // Check if user is already signed up for this campaign (on ANY team)
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT s.id, t.name as team_name 
         FROM $signups_table s
         LEFT JOIN {$wpdb->prefix}ss_teams t ON s.team_id = t.id
         WHERE s.user_id = %d AND s.campaign_id = %d AND s.status = 'active'",
        $user_id,
        $campaign_id
    ) );
    
    if ( $existing ) {
        wp_send_json_error( array( 
            'message' => 'This member is already signed up for this campaign date with team: ' . $existing->team_name 
        ) );
    }
    
    // Insert signup
    $result = $wpdb->insert(
        $signups_table,
        array(
            'user_id' => $user_id,
            'team_id' => $team_id,
            'campaign_id' => $campaign_id,
            'status' => 'active',
            'created_at' => current_time( 'mysql' )
        ),
        array( '%d', '%d', '%d', '%s', '%s' )
    );
    
    if ( $result === false ) {
        wp_send_json_error( array( 'message' => 'Failed to add signup' ) );
    }
    
    wp_send_json_success( array( 'message' => 'Signup added successfully' ) );
}

/**
 * AJAX handler to remove a signup
 */
function subsales_ajax_remove_signup() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $signup_id = isset( $_POST['signup_id'] ) ? intval( $_POST['signup_id'] ) : 0;
    
    if ( ! $signup_id ) {
        wp_send_json_error( array( 'message' => 'Missing signup ID' ) );
    }
    
    global $wpdb;
    $signups_table = $wpdb->prefix . 'ss_signups';
    
    // Soft delete (set status to cancelled)
    $result = $wpdb->update(
        $signups_table,
        array( 'status' => 'cancelled' ),
        array( 'id' => $signup_id ),
        array( '%s' ),
        array( '%d' )
    );
    
    if ( $result === false ) {
        wp_send_json_error( array( 'message' => 'Failed to remove signup' ) );
    }
    
    wp_send_json_success( array( 'message' => 'Signup removed successfully' ) );
}

/**
 * AJAX handler to update team driver
 */
function subsales_ajax_update_team_driver() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $campaign_id = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;
    $team_id = isset( $_POST['team_id'] ) ? intval( $_POST['team_id'] ) : 0;
    $driver_name = isset( $_POST['driver_name'] ) ? sanitize_text_field( $_POST['driver_name'] ) : '';
    
    if ( ! $campaign_id || ! $team_id ) {
        wp_send_json_error( array( 'message' => 'Missing required fields' ) );
    }
    
    global $wpdb;
    $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';
    
    // Get current user info for tracking
    $current_user = wp_get_current_user();
    $updated_by = $current_user->display_name ?: $current_user->user_login;
    
    // Check if record exists
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $team_campaigns_table WHERE team_id = %d AND campaign_id = %d",
        $team_id,
        $campaign_id
    ) );
    
    if ( $existing ) {
        // Update existing record
        $result = $wpdb->update(
            $team_campaigns_table,
            array(
                'driver_name' => $driver_name,
                'driver_updated_by' => $updated_by,
                'driver_updated_at' => current_time( 'mysql' )
            ),
            array( 'id' => $existing ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );
    } else {
        // Insert new record
        $result = $wpdb->insert(
            $team_campaigns_table,
            array(
                'team_id' => $team_id,
                'campaign_id' => $campaign_id,
                'driver_name' => $driver_name,
                'driver_updated_by' => $updated_by,
                'driver_updated_at' => current_time( 'mysql' )
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    }
    
    if ( $result === false ) {
        wp_send_json_error( array( 'message' => 'Failed to update driver' ) );
    }
    
    wp_send_json_success( array( 'message' => 'Driver updated successfully' ) );
}

/**
 * AJAX handler to search for members
 */
function subsales_ajax_search_members() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $search = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
    
    subsales_log( 'DEBUG', 'signups', 'Member search called', array( 'search' => $search ) );
    
    if ( strlen( $search ) < 2 ) {
        wp_send_json_success( array( 'members' => array() ) );
    }
    
    global $wpdb;
    $members_table = $wpdb->prefix . 'ss_team_members';
    
    // Search by name or phone
    $search_like = '%' . $wpdb->esc_like( $search ) . '%';
    $members = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, name, phone, email 
         FROM $members_table 
         WHERE (name LIKE %s OR phone LIKE %s)
         ORDER BY name
         LIMIT 20",
        $search_like,
        $search_like
    ), ARRAY_A );
    
    subsales_log( 'DEBUG', 'signups', 'Member search results', array( 'count' => count( $members ), 'search' => $search ) );
    
    wp_send_json_success( array( 'members' => $members ) );
}

/**
 * AJAX handler to quickly create a new user
 */
function subsales_ajax_create_user_quick() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $name = isset( $_POST['name'] ) ? sanitize_text_field( $_POST['name'] ) : '';
    $phone = isset( $_POST['phone'] ) ? sanitize_text_field( $_POST['phone'] ) : '';
    $email = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
    
    if ( empty( $name ) ) {
        wp_send_json_error( array( 'message' => 'Name is required' ) );
    }
    
    if ( empty( $phone ) ) {
        wp_send_json_error( array( 'message' => 'Phone is required' ) );
    }
    
    // Clean phone - remove everything except digits
    $phone = preg_replace( '/[^0-9]/', '', $phone );
    
    if ( strlen( $phone ) != 10 ) {
        wp_send_json_error( array( 'message' => 'Phone must be 10 digits' ) );
    }
    
    global $wpdb;
    $members_table = $wpdb->prefix . 'ss_team_members';
    
    // Check if phone already exists
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM $members_table WHERE phone = %s AND deleted = 0",
        $phone
    ) );
    
    if ( $existing ) {
        wp_send_json_error( array( 'message' => 'A member with this phone number already exists' ) );
    }
    
    // Insert new member
    $result = $wpdb->insert(
        $members_table,
        array(
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'created_at' => current_time( 'mysql' )
        ),
        array( '%s', '%s', '%s', '%s' )
    );
    
    if ( $result === false ) {
        wp_send_json_error( array( 'message' => 'Failed to create member' ) );
    }
    
    $new_user_id = $wpdb->insert_id;
    
    wp_send_json_success( array(
        'message' => 'Member created successfully',
        'user' => array(
            'id' => $new_user_id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email
        )
    ) );
}

/**
 * AJAX handler to get campaign counts (team/member)
 */
function subsales_ajax_get_campaign_counts() {
    check_ajax_referer( 'subsales_campaign_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Unauthorized' ) );
    }
    
    $campaign_id = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;
    
    if ( ! $campaign_id ) {
        wp_send_json_error( array( 'message' => 'Missing campaign ID' ) );
    }
    
    $campaign = Subsales_Database::get_campaign( $campaign_id );
    
    if ( ! $campaign ) {
        wp_send_json_error( array( 'message' => 'Campaign not found' ) );
    }
    
    // Get signups
    $signups = Subsales_Database::get_signups( array(
        'campaign_id' => $campaign_id,
        'status' => 'active'
    ) );
    
    $member_count = count( $signups );
    $teams = array();
    foreach ( $signups as $signup ) {
        $teams[ $signup['team_id'] ] = true;
    }
    $team_count = count( $teams );
    
    wp_send_json_success( array(
        'team_count' => $team_count,
        'member_count' => $member_count,
        'campaign_date' => $campaign['campaign_date']
    ) );
}

// ============================================================
// SIGNUP / CAMPAIGN REST API HANDLERS
// NOTE: These have been moved to includes/class-signups.php
// The functions below are kept for backwards compatibility but
// the actual logic is now in Subsales_Signups class.
// ============================================================

/**
 * Get signup settings (mode, etc.)
 * GET /wp-json/order-manager/v1/signup/settings
 * NOTE: Moved to Subsales_Signups::rest_signup_settings()
 */
function subsales_rest_signup_settings( $request ) {
    // Get login mode from settings (legacy = team-based, user = user-based)
    $login_mode = get_option( 'order_sync_login_mode', 'legacy' );
    
    return rest_ensure_response( array(
        'mode' => $login_mode,
        'brand_name' => get_option( 'subsales_branding', 'Subsales' ),
        'admin_email' => get_option( 'subsales_admin_email', get_option( 'admin_email' ) ),
    ) );
}

/**
 * Search teams by name
 * GET /wp-json/order-manager/v1/teams?search=query
 */
function subsales_rest_search_teams( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_teams';
    
    $search = $request->get_param( 'search' );
    
    if ( empty( $search ) ) {
        // Return all active teams if no search query
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, status FROM {$table} WHERE status = %s ORDER BY name ASC LIMIT 50",
            'active'
        ), ARRAY_A );
    } else {
        // Search teams by name
        $teams = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, status FROM {$table} WHERE status = %s AND name LIKE %s ORDER BY name ASC LIMIT 50",
            'active',
            '%' . $wpdb->esc_like( $search ) . '%'
        ), ARRAY_A );
    }
    
    if ( ! $teams ) {
        return rest_ensure_response( array() );
    }
    
    return rest_ensure_response( $teams );
}

/**
 * Verify user ID and phone number match
 * POST /wp-json/order-manager/v1/signup/verify-user
 * Body: {"user_id": 123, "phone": "8604187663"}
 */
function subsales_rest_verify_user( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_team_members';
    
    $user_id = $request->get_param( 'user_id' );
    $phone = $request->get_param( 'phone' );
    
    // Validate inputs
    if ( empty( $user_id ) || empty( $phone ) ) {
        return new WP_Error( 'missing_params', 'User ID and phone are required', array( 'status' => 400 ) );
    }
    
    // Strip non-digits from phone
    $phone = preg_replace( '/\D/', '', $phone );
    
    if ( strlen( $phone ) !== 10 ) {
        return new WP_Error( 'invalid_phone', 'Phone must be 10 digits', array( 'status' => 400 ) );
    }
    
    // Check if user ID and phone match in database
    $user = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, name, phone FROM {$table} WHERE id = %d AND phone = %s",
        $user_id,
        $phone
    ), ARRAY_A );
    
    if ( ! $user ) {
        return rest_ensure_response( array(
            'valid' => false,
            'message' => 'Phone number does not match our records for this user'
        ) );
    }
    
    return rest_ensure_response( array(
        'valid' => true,
        'user' => array(
            'id' => $user['id'],
            'name' => $user['name']
            // Do NOT return phone for security
        )
    ) );
}

/**
 * Search users by name
 * GET /wp-json/order-manager/v1/users/search?name=query
 */
function subsales_rest_search_users( $request ) {
    // Accept both 'q' and 'name' parameters for backwards compatibility
    $search = $request->get_param( 'q' );
    if ( empty( $search ) ) {
        $search = $request->get_param( 'name' );
    }

    if ( empty( $search ) || strlen( $search ) < 2 ) {
        return rest_ensure_response( array() );
    }

    // Canonical name search (id + name only — no phone, PII protection for kids)
    return rest_ensure_response( Subsales_Database::search_members_by_name( $search, 20 ) );
}

/**
 * Get all active campaigns
 * GET /wp-json/order-manager/v1/campaigns
 */
function subsales_rest_get_campaigns( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_campaigns';
    
    // Check if table exists
    $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
    if ( ! $table_exists ) {
        // Return empty array if table doesn't exist yet
        return rest_ensure_response( array() );
    }
    
    // Scope to the current season. Without this the seller app would list last
    // season's sale days alongside this season's for as long as they stayed
    // 'active' - the kids' own screen, so the most visible place to get it wrong.
    $season_id = intval( get_option( 'subsales_current_season_id' ) );

    if ( $season_id ) {
        $campaigns = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, campaign_date as date, campaign_name as name, status
             FROM {$table} WHERE status = %s AND season_id = %d ORDER BY campaign_date ASC",
            'active',
            $season_id
        ), ARRAY_A );
    } else {
        $campaigns = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, campaign_date as date, campaign_name as name, status FROM {$table} WHERE status = %s ORDER BY campaign_date ASC",
            'active'
        ), ARRAY_A );
    }
    
    // Check for database errors
    if ( $wpdb->last_error ) {
        return new WP_Error( 'database_error', 'Failed to load campaigns: ' . $wpdb->last_error, array( 'status' => 500 ) );
    }
    
    if ( ! $campaigns ) {
        return rest_ensure_response( array() );
    }
    
    return rest_ensure_response( $campaigns );
}

/**
 * Check if name exists in system
 * GET /wp-json/order-manager/v1/signup/check-name?name=John+Doe
 */
function subsales_rest_check_name( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'ss_team_members';
    
    $name = sanitize_text_field( $request->get_param( 'name' ) );
    
    if ( empty( $name ) ) {
        return new WP_Error( 'missing_name', 'Name is required', array( 'status' => 400 ) );
    }
    
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, name, phone, email FROM {$table} WHERE name = %s LIMIT 1",
        $name
    ), ARRAY_A );
    
    if ( $existing ) {
        return rest_ensure_response( array(
            'exists' => true,
            'user' => $existing
        ) );
    }
    
    return rest_ensure_response( array( 'exists' => false ) );
}

/**
 * Submit signup (create/link user, team, signups)
 * POST /wp-json/order-manager/v1/signup
 * Body: { team: {id, name, isNew}, user: {phone, name}, campaign_ids: [1,2,3] }
 */
function subsales_rest_submit_signup( $request ) {
    global $wpdb;
    
    // Rate limiting: Check for recent signups from same IP
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $transient_key = 'signup_limit_' . md5( $ip_address );
    $recent_signups = get_transient( $transient_key );
    
    if ( $recent_signups && $recent_signups >= 5 ) {
        subsales_log( 'WARNING', 'signup', 'Rate limit exceeded', array( 'ip' => $ip_address ) );
        return new WP_Error( 'rate_limit', 'Too many signup attempts. Please try again later.', array( 'status' => 429 ) );
    }
    
    $params = $request->get_json_params();
    
    if ( ! isset( $params['team'], $params['user'], $params['campaign_ids'] ) ) {
        return new WP_Error( 'missing_data', 'Missing required fields', array( 'status' => 400 ) );
    }
    
    $team = $params['team'];
    $user = $params['user'];
    $campaign_ids = $params['campaign_ids'];
    
    // Validate and sanitize inputs
    if ( ! is_array( $campaign_ids ) || empty( $campaign_ids ) ) {
        return new WP_Error( 'invalid_dates', 'At least one date must be selected', array( 'status' => 400 ) );
    }
    
    // Validate phone number (must be exactly 10 digits)
    $phone = preg_replace( '/\D/', '', $user['phone'] ?? '' );
    if ( strlen( $phone ) !== 10 ) {
        return new WP_Error( 'invalid_phone', 'Phone must be 10 digits', array( 'status' => 400 ) );
    }
    
    // Validate and sanitize name (no HTML, max 100 chars)
    $user_name = sanitize_text_field( $user['name'] ?? '' );
    $user_name = substr( $user_name, 0, 100 );
    if ( empty( $user_name ) || strlen( $user_name ) < 2 ) {
        return new WP_Error( 'invalid_name', 'Name must be at least 2 characters', array( 'status' => 400 ) );
    }
    
    // Increment rate limit counter (expires after 15 minutes)
    set_transient( $transient_key, ( $recent_signups ?: 0 ) + 1, 15 * MINUTE_IN_SECONDS );
    
    // 1. Get or create team
    $team_id = null;
    $teams_table = $wpdb->prefix . 'ss_teams';
    
    if ( ! empty( $team['id'] ) ) {
        // Existing team - verify it exists
        $team_id = intval( $team['id'] );
        $team_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$teams_table} WHERE id = %d AND status = 'active'",
            $team_id
        ) );
        
        if ( ! $team_exists ) {
            return new WP_Error( 'invalid_team', 'Selected team does not exist', array( 'status' => 400 ) );
        }
    } elseif ( ! empty( $team['name'] ) ) {
        // Create new team - validate name
        $team_name = sanitize_text_field( $team['name'] );
        $team_name = substr( $team_name, 0, 100 );
        
        if ( empty( $team_name ) || strlen( $team_name ) < 2 ) {
            return new WP_Error( 'invalid_team_name', 'Team name must be at least 2 characters', array( 'status' => 400 ) );
        }
        
        $access_code = strtoupper( substr( md5( $team_name . time() ), 0, 8 ) );
        
        $wpdb->insert( $teams_table, array(
            'name' => $team_name,
            'access_code' => $access_code,
            'status' => 'active',
            'created_at' => current_time( 'mysql' )
        ), array( '%s', '%s', '%s', '%s' ) );
        
        $team_id = $wpdb->insert_id;
        
        subsales_log( 'INFO', 'signup', 'New team created via signup', array(
            'team_id' => $team_id,
            'team_name' => $team_name
        ) );
    }
    
    if ( ! $team_id ) {
        return new WP_Error( 'team_error', 'Failed to get or create team', array( 'status' => 500 ) );
    }
    
    // 2. Get or create user
    $members_table = $wpdb->prefix . 'ss_team_members';
    
    $existing_user = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM {$members_table} WHERE phone = %s",
        $phone
    ) );
    
    if ( $existing_user ) {
        $user_id = $existing_user->id;
        
        // Update name if different
        $wpdb->update( $members_table, 
            array( 'name' => $user_name ),
            array( 'id' => $user_id ),
            array( '%s' ),
            array( '%d' )
        );
    } else {
        // Create new user
        $wpdb->insert( $members_table, array(
            'name' => $user_name,
            'phone' => $phone,
            'email' => '',
            'created_at' => current_time( 'mysql' )
        ), array( '%s', '%s', '%s', '%s' ) );
        
        $user_id = $wpdb->insert_id;
        
        subsales_log( 'INFO', 'signup', 'New user created via signup', array(
            'user_id' => $user_id,
            'name' => $user_name,
            'phone' => $phone
        ) );
    }
    
    if ( ! $user_id ) {
        return new WP_Error( 'user_error', 'Failed to get or create user', array( 'status' => 500 ) );
    }
    
    // 3. Link user to team if not already linked
    $user_teams_table = $wpdb->prefix . 'ss_user_teams';
    
    $link_exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
        $user_id,
        $team_id
    ) );
    
    if ( ! $link_exists ) {
        $wpdb->insert( $user_teams_table, array(
            'user_id' => $user_id,
            'team_id' => $team_id,
            'assigned_at' => current_time( 'mysql' )
        ), array( '%d', '%d', '%s' ) );
    }
    
    // 4. Create signups for each campaign
    $signups_table = $wpdb->prefix . 'ss_signups';
    $created_signups = 0;
    $skipped_signups = array();
    
    foreach ( $campaign_ids as $campaign_id ) {
        $campaign_id = intval( $campaign_id );
        
        subsales_log( 'DEBUG', 'signup', 'Checking for existing signup', array(
            'user_id' => $user_id,
            'team_id' => $team_id,
            'campaign_id' => $campaign_id
        ) );
        
        // Check if signup already exists
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$signups_table} 
            WHERE user_id = %d AND team_id = %d AND campaign_id = %d",
            $user_id,
            $team_id,
            $campaign_id
        ) );
        
        subsales_log( 'DEBUG', 'signup', 'Checking campaign signup', array(
            'user_id' => $user_id,
            'team_id' => $team_id,
            'campaign_id' => $campaign_id,
            'exists' => $exists ? 'yes' : 'no'
        ) );
        
        if ( ! $exists ) {
            $result = $wpdb->insert( $signups_table, array(
                'user_id' => $user_id,
                'team_id' => $team_id,
                'campaign_id' => $campaign_id,
                'is_driver' => 0,
                'status' => 'active',
                'created_at' => current_time( 'mysql' )
            ), array( '%d', '%d', '%d', '%d', '%s', '%s' ) );
            
            if ( $result ) {
                $created_signups++;
                subsales_log( 'DEBUG', 'signup', 'Created signup', array(
                    'signup_id' => $wpdb->insert_id,
                    'campaign_id' => $campaign_id
                ) );
            } else {
                subsales_log( 'ERROR', 'signup', 'Failed to insert signup', array(
                    'campaign_id' => $campaign_id,
                    'error' => $wpdb->last_error
                ) );
            }
        } else {
            $skipped_signups[] = $campaign_id;
        }
    }
    
    subsales_log( 'INFO', 'signup', 'Signup completed', array(
        'user_id' => $user_id,
        'team_id' => $team_id,
        'campaigns_requested' => count( $campaign_ids ),
        'new_signups' => $created_signups,
        'skipped' => $skipped_signups
    ) );
    
    return rest_ensure_response( array(
        'success' => true,
        'user_id' => $user_id,
        'team_id' => $team_id,
        'signups_created' => $created_signups
    ) );
}

/**
 * Get user's signups by phone number
 * GET /wp-json/order-manager/v1/my-signups?phone=8605551234
 */
function subsales_rest_get_my_signups( $request ) {
    global $wpdb;
    
    // Accept phone from either POST body or GET query for backward compatibility
    $phone = $request->get_param( 'phone' );
    if ( empty( $phone ) ) {
        $body = $request->get_json_params();
        $phone = isset( $body['phone'] ) ? $body['phone'] : '';
    }
    $phone = preg_replace( '/\D/', '', $phone );
    
    if ( empty( $phone ) ) {
        return new WP_Error( 'missing_phone', 'Phone number is required', array( 'status' => 400 ) );
    }
    
    $members_table = $wpdb->prefix . 'ss_team_members';
    $signups_table = $wpdb->prefix . 'ss_signups';
    $teams_table = $wpdb->prefix . 'ss_teams';
    $campaigns_table = $wpdb->prefix . 'ss_campaigns';
    
    $user = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, name FROM {$members_table} WHERE phone = %s",
        $phone
    ) );
    
    if ( ! $user ) {
        return rest_ensure_response( array() );
    }
    
    $signups = $wpdb->get_results( $wpdb->prepare(
        "SELECT 
            s.id,
            s.user_id,
            s.team_id,
            s.campaign_id,
            s.is_driver,
            s.status,
            t.name AS team_name,
            c.campaign_date AS campaign_date,
            c.campaign_name AS campaign_name
        FROM {$signups_table} s
        LEFT JOIN {$teams_table} t ON s.team_id = t.id
        LEFT JOIN {$campaigns_table} c ON s.campaign_id = c.id
        WHERE s.user_id = %d AND s.status = 'active'
        ORDER BY c.campaign_date ASC",
        $user->id
    ), ARRAY_A );
    
    return rest_ensure_response( $signups );
}

/**
 * Get team roster for a specific campaign
 * GET /wp-json/order-manager/v1/team-roster?team_id=X&campaign_id=Y
 */
function subsales_rest_get_team_roster( $request ) {
    global $wpdb;
    
    $team_id = intval( $request->get_param( 'team_id' ) );
    $campaign_id = intval( $request->get_param( 'campaign_id' ) );
    
    if ( empty( $team_id ) || empty( $campaign_id ) ) {
        return new WP_Error( 'missing_params', 'Team ID and Campaign ID are required', array( 'status' => 400 ) );
    }
    
    $members_table = $wpdb->prefix . 'ss_team_members';
    $signups_table = $wpdb->prefix . 'ss_signups';
    $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';
    
    // Get all team members signed up for this campaign
    $members = $wpdb->get_results( $wpdb->prepare(
        "SELECT 
            m.id AS user_id,
            m.name,
            m.phone
        FROM {$signups_table} s
        INNER JOIN {$members_table} m ON s.user_id = m.id
        WHERE s.team_id = %d AND s.campaign_id = %d AND s.status = 'active'
        ORDER BY m.name ASC",
        $team_id,
        $campaign_id
    ), ARRAY_A );
    
    // Get driver info from team_campaigns table
    $driver_info = $wpdb->get_row( $wpdb->prepare(
        "SELECT 
            tc.driver_name,
            tc.driver_updated_by,
            tc.driver_updated_at
        FROM {$team_campaigns_table} tc
        WHERE tc.team_id = %d AND tc.campaign_id = %d",
        $team_id,
        $campaign_id
    ), ARRAY_A );
    
    $response = array(
        'members' => $members,
        'driver_name' => $driver_info ? $driver_info['driver_name'] : '',
        'driver_updated_by' => $driver_info ? $driver_info['driver_updated_by'] : '',
        'driver_updated_at' => $driver_info ? $driver_info['driver_updated_at'] : ''
    );
    
    return rest_ensure_response( $response );
}

/**
 * Update team driver for a specific campaign
 * PUT /wp-json/order-manager/v1/team-driver
 */
function subsales_rest_update_team_driver( $request ) {
    global $wpdb;
    
    $body = $request->get_json_params();
    $team_id = isset( $body['team_id'] ) ? intval( $body['team_id'] ) : 0;
    $campaign_id = isset( $body['campaign_id'] ) ? intval( $body['campaign_id'] ) : 0;
    $driver_name = isset( $body['driver_name'] ) ? sanitize_text_field( $body['driver_name'] ) : '';
    $updated_by = isset( $body['updated_by'] ) ? sanitize_text_field( $body['updated_by'] ) : '';
    
    if ( empty( $team_id ) || empty( $campaign_id ) ) {
        return new WP_Error( 'missing_params', 'Team ID and Campaign ID are required', array( 'status' => 400 ) );
    }
    
    $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';
    
    // Check if record exists
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$team_campaigns_table} WHERE team_id = %d AND campaign_id = %d",
        $team_id,
        $campaign_id
    ) );
    
    if ( $exists ) {
        // Update existing record
        $wpdb->update(
            $team_campaigns_table,
            array(
                'driver_name' => $driver_name,
                'driver_updated_by' => $updated_by,
                'driver_updated_at' => current_time( 'mysql' )
            ),
            array( 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
            array( '%s', '%s', '%s' ),
            array( '%d', '%d' )
        );
    } else {
        // Insert new record
        $wpdb->insert(
            $team_campaigns_table,
            array(
                'team_id' => $team_id,
                'campaign_id' => $campaign_id,
                'driver_name' => $driver_name,
                'driver_updated_by' => $updated_by,
                'driver_updated_at' => current_time( 'mysql' )
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    }
    
    subsales_log( 'INFO', 'signup', 'Driver updated', array(
        'team_id' => $team_id,
        'campaign_id' => $campaign_id,
        'driver_name' => $driver_name,
        'updated_by' => $updated_by
    ) );
    
    return rest_ensure_response( array(
        'success' => true,
        'message' => 'Driver updated successfully'
    ) );
}

