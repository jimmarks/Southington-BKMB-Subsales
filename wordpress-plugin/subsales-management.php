<?php
/**
 * Plugin Name: Subsales Management
 * Plugin URI: https://github.com/jimmarks/Southington-BKMB-Subsales
 * Description: A comprehensive order management system for mobile app synchronization with WordPress backend. Includes multi-team management, Google Maps integration, and professional admin interface. ⚠️ WARNING: By default, deleting this plugin will permanently remove ALL data. Configure deletion settings in BKMB Subsales → Settings.
 * Version: 2.2.0.5
 * Author: Jim Marks
 * Author URI: https://github.com/jimmarks
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.1
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
if ( ! defined( 'SUBSALES_VERSION' ) ) define( 'SUBSALES_VERSION', '2.2.0.5' );
if ( ! defined( 'SUBSALES_PLUGIN_URL' ) ) define( 'SUBSALES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_PATH' ) ) define( 'SUBSALES_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
if ( ! defined( 'SUBSALES_PLUGIN_BASENAME' ) ) define( 'SUBSALES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

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
    
    // Hidden page for manifest viewer (not shown in menu)
    add_submenu_page(
        null, // Hidden from menu
        'Delivery Manifest',
        'Delivery Manifest',
        'manage_options',
        'subsales-manifest-viewer',
        'subsales_manifest_viewer_page'
    );
}

// AJAX handler to search/preview address across all sources
add_action( 'wp_ajax_subsales_search_address', 'subsales_search_address_preview' );
add_action( 'wp_ajax_subsales_extract_openaddresses_zips', 'subsales_extract_openaddresses_zips' );
add_action( 'wp_ajax_subsales_download_openaddresses', 'subsales_download_openaddresses' );
add_action( 'wp_ajax_subsales_toggle_debug', 'subsales_toggle_debug_ajax' );
add_action( 'wp_ajax_subsales_get_active_sessions_count', 'subsales_get_active_sessions_count_ajax' );
add_action( 'wp_ajax_subsales_get_session_details', 'subsales_get_session_details_ajax' );
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

// AJAX handler for getting session details and heartbeat history
function subsales_get_session_details_ajax() {
    check_ajax_referer( 'subsales_session_details', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    
    $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( $_POST['session_id'] ) : '';
    
    if ( empty( $session_id ) ) {
        wp_send_json_error( 'Session ID required' );
    }
    
    // Get session data
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'ss_pwa_sessions';
    $session = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$sessions_table} WHERE session_id = %s",
        $session_id
    ), ARRAY_A );
    
    if ( ! $session ) {
        wp_send_json_error( 'Session not found' );
    }
    
    // Get heartbeat history
    $heartbeats = Subsales_Database::get_session_heartbeats( $session_id, 100 );
    
    wp_send_json_success( array(
        'session' => $session,
        'heartbeats' => $heartbeats
    ) );
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

// DATABASE WRAPPER FUNCTIONS (for backward compatibility)
function order_sync_create_table() {
    Subsales_Database::create_tables();
}

function order_sync_add_team( $name, $access_code, $description = '', $status = 'active' ) {
    return Subsales_Database::add_team( $name, $access_code, $description, $status );
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

function subsales_log_auth( $action, $user_id = null, $user_name = '', $context = array(), $source = 'pwa' ) {
    Subsales_Database::log_auth( $action, $user_id, $user_name, $context, $source );
}

function order_sync_verify_team_member( $email, $team_id ) {
    return Subsales_Database::verify_team_member( $email, $team_id );
}

// REST API ROUTES


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
        // Normalize created_at to site-local timezone for display and timestamp calculations.
        // Stored DB values may be in GMT/UTC; use get_date_from_gmt() to convert to site local time.
        $created_gmt = isset( $r['created_at'] ) ? $r['created_at'] : null;
        if ( $created_gmt ) {
            // get_date_from_gmt returns a formatted date string in the site's timezone
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

        $address = isset( $od['address'] ) ? (string) $od['address'] : '';
        $unitFloorApt = isset( $od['unitFloorApt'] ) ? (string) $od['unitFloorApt'] : '';
        if ( ! empty( $unitFloorApt ) ) {
            $address .= ', ' . $unitFloorApt;
        }
        
        if ( empty( $address ) ) continue;

        $customer = isset( $od['customer'] ) ? (string) $od['customer'] : ( isset( $od['customerName'] ) ? (string) $od['customerName'] : '' );
        $phone = isset( $od['cellNumber'] ) ? (string) $od['cellNumber'] : ( isset( $od['phone'] ) ? (string) $od['phone'] : '' );
        $notes = isset( $od['notes'] ) ? (string) $od['notes'] : '';

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

    // Generate combined HTML manifest for all individuals
    $all_manifests = array();
    
    foreach ( $by_individual as $individual_id => $data ) {
        $individual_name = $data['name'];
        $orders = $data['orders'];

        // Optimize route using nearest-neighbor algorithm
        $optimized_orders = order_sync_optimize_route( $orders, $start_coords );

        $all_manifests[] = array(
            'name' => $individual_name,
            'orders' => $optimized_orders
        );
    }

    // Generate combined HTML document
    $combined_html = order_sync_generate_combined_manifest_html( $all_manifests, $start_address, $configured_products, $delivery_date );
    
    // Store HTML in transient for retrieval
    $transient_key = 'subsales_manifest_' . md5( current_time( 'mysql' ) . wp_get_current_user()->ID );
    set_transient( $transient_key, $combined_html, 300 ); // 5 minutes expiry
    
    // Redirect to viewer page
    $viewer_url = add_query_arg( 'manifest_key', $transient_key, admin_url( 'admin.php?page=subsales-manifest-viewer' ) );
    wp_safe_redirect( admin_url( 'admin.php?page=subsales-delivery&manifest_url=' . urlencode( $viewer_url ) ) );
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

// Helper: Generate combined HTML manifest for all individuals
function order_sync_generate_combined_manifest_html( $all_manifests, $start_address, $configured_products, $delivery_date = '' ) {
    // Determine display date
    $display_date = ! empty( $delivery_date ) ? date('F j, Y', strtotime( $delivery_date ) ) : date('F j, Y');
    
    // Build HTML with print-optimized CSS
    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Manifests - ' . $display_date . '</title>
    <style>
        /* Hide all WordPress admin elements */
        #wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, 
        .wp-toolbar, #wpfooter, .update-nag, .notice, .error, 
        #wpbody-content > .wrap, #wpbody-content > h1, #wpbody-content > h2,
        .wrap > h1, .wrap > h2 {
            display: none !important;
        }
        
        /* Ensure body takes full width without admin sidebar */
        body.wp-admin { margin: 0 !important; padding: 0 !important; }
        #wpcontent, #wpbody { margin-left: 0 !important; }
        
        @media print {
            @page { margin: 0.5in 0.5in 0.75in 0.5in; }
            .page-break { page-break-after: always; }
            .manifest-section { page-break-before: always; }
            .manifest-section:first-child { page-break-before: auto; }
            .delivery-stop { page-break-inside: avoid; }
            /* Extra insurance: hide WP elements in print */
            #wpadminbar, #adminmenumain, #adminmenuback, #adminmenuwrap, 
            .wp-toolbar, #wpfooter, .update-nag, .notice, .error { 
                display: none !important; 
            }
        }
        body { font-family: Arial, Helvetica, sans-serif; margin: 20px; padding: 0 0 60px 0; font-size: 12pt; position: relative; }
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

    // Generate manifest for each individual
    $manifest_index = 0;
    foreach ( $all_manifests as $manifest ) {
        $individual_name = $manifest['name'];
        $orders = $manifest['orders'];
        
        // Calculate product totals for this individual's packing list
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

        // Calculate total pages for this seller: 2 packing lists + delivery manifest pages
        $total_pages = 2 + count( $orders );
        $current_page = 1;

        // Add section break for new seller (except first one)
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
        
        // SECOND PACKING LIST (duplicate)
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

        // DELIVERY MANIFEST
        $html .= '<div>';
        $html .= '<h1>Delivery Manifest: ' . htmlspecialchars( $individual_name, ENT_QUOTES, 'UTF-8' ) . '</h1>';
        $html .= '<div class="depot"><strong>Starting Point:</strong> ' . htmlspecialchars( (string) $start_address, ENT_QUOTES, 'UTF-8' ) . '</div>';
        $html .= '<div class="depot"><strong>Total Stops:</strong> ' . count( $orders ) . ' | <strong>Date:</strong> ' . $display_date . '</div>';

        // Delivery stops
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
        $html .= '</div>'; // end delivery manifest
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
    
    // User-based auth: X-User-ID (with optional X-Team-ID)
    $user_id = $request->get_header( 'X-User-ID' );
    $team_id = $request->get_header( 'X-Team-ID' );
    
    if ( ! empty( $user_id ) ) {
        $members_table = $wpdb->prefix . 'ss_team_members';
        
        // Verify user exists
        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$members_table} WHERE id = %d",
            intval( $user_id )
        ), ARRAY_A );
        
        if ( ! $user ) {
            error_log( 'Subsales: perm_check invalid user_id=' . $user_id );
            return false;
        }
        
        // If team_id is provided and not -1 (individual mode), verify user belongs to team
        if ( ! empty( $team_id ) && $team_id !== '-1' ) {
            $user_teams_table = $wpdb->prefix . 'ss_user_teams';
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
        
        // Individual mode (team_id is -1 or empty) - user exists, allow access
        error_log( 'Subsales: perm_check individual mode ok user_id=' . $user_id );
        return true;
    }
    
    // Legacy Team auth: X-Team-Name + X-Access-Code (both required)
    $team_name = $request->get_header( 'X-Team-Name' );
    $access_code = $request->get_header( 'X-Access-Code' );
    
    if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
        $team = order_sync_get_team_by_credentials( $team_name, $access_code );
        if ( $team ) {
            error_log( 'Subsales: perm_check team creds ok id=' . ( isset($team['id']) ? $team['id'] : 'unknown' ) );
            return true;
        }
        error_log( 'Subsales: perm_check invalid team credentials provided (team=' . $team_name . ')' );
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

/**
 * Check if user has admin permissions for WordPress REST API requests
 * Used by admin-only REST endpoints like order history, restore, tally
 */
function order_sync_check_admin_permissions( WP_REST_Request $request ) {
    return current_user_can( 'manage_options' );
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
// Use 'init' hook with priority 0 to intercept before WordPress routing
add_action( 'init', 'subsales_serve_portal_assets', 0 );

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
    // Skip during installation, updates, and admin operations
    if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
        return;
    }
    
    // Early exit for admin pages, wp-json, and other non-portal requests
    $req_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    if ( empty( $req_uri ) ||
         strpos( $req_uri, '/wp-admin/' ) !== false || 
         strpos( $req_uri, '/wp-json/' ) !== false || 
         strpos( $req_uri, 'wp-login.php' ) !== false ||
         $req_uri === '/favicon.ico' ) {
        return;
    }
    
    $portal_slug = get_option( 'order_sync_portal_slug', '' );
    $req_path_raw = parse_url( $req_uri, PHP_URL_PATH );
    $req_path = $req_path_raw ? trim( $req_path_raw, '/' ) : '';
    
    // Redirect /subsales-portal to /subsales-portal/ for service worker scope consistency
    if ( $portal_slug && $req_path === $portal_slug && substr( $req_uri, -1 ) !== '/' ) {
        wp_redirect( home_url( '/' . $portal_slug . '/' ), 301 );
        exit;
    }
    
    // Handle direct PWA access at /wp-content/plugins/subsales-management/pwa/
    $pwa_base_path_raw = parse_url( SUBSALES_PLUGIN_URL . 'pwa/', PHP_URL_PATH );
    $pwa_base_path = $pwa_base_path_raw ? trim( $pwa_base_path_raw, '/' ) : '';
    
    // Serve service worker for direct PWA access
    if ( $req_path === $pwa_base_path . '/service-worker.js' || $req_path === rtrim($pwa_base_path, '/') . '/service-worker.js' ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/service-worker.js';
        if ( file_exists( $file ) ) {
            header( 'Content-Type: application/javascript' );
            header( 'Cache-Control: public, max-age=3600' );
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Service-Worker-Allowed: /' );
            readfile( $file );
            exit;
        }
    }
    
    // Serve other PWA assets (styles, scripts, manifest, icons) for direct PWA access
    if ( strpos( $req_path, $pwa_base_path ) === 0 ) {
        $rel_path = substr( $req_path, strlen( $pwa_base_path ) );
        $rel_path = ltrim( $rel_path, '/' );
        
        if ( $rel_path && $rel_path !== 'index.html' ) {
            $file = SUBSALES_PLUGIN_PATH . 'pwa/' . $rel_path;
            if ( file_exists( $file ) && is_file( $file ) ) {
                $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                switch ( $ext ) {
                    case 'js': $ct = 'application/javascript'; break;
                    case 'css': $ct = 'text/css'; break;
                    case 'json': $ct = 'application/json'; break;
                    case 'svg': $ct = 'image/svg+xml'; break;
                    case 'png': $ct = 'image/png'; break;
                    case 'jpg':
                    case 'jpeg': $ct = 'image/jpeg'; break;
                    case 'webp': $ct = 'image/webp'; break;
                    case 'ico': $ct = 'image/x-icon'; break;
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
    
    if ( $req_path === $pwa_base_path || $req_path === $pwa_base_path . '/index.html' || rtrim($req_path, '/') === rtrim($pwa_base_path, '/') ) {
        $file = SUBSALES_PLUGIN_PATH . 'pwa/index.html';
        if ( file_exists( $file ) ) {
            $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
            $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

            $settings = array(
                'apiBase' => esc_url_raw( rest_url( 'order-manager/v1' ) ),
                'pluginBase' => SUBSALES_PLUGIN_URL . 'pwa/',
                'portalBase' => esc_url_raw( home_url( '/' . ( $portal_slug ?: 'subsales-portal' ) . '/' ) ),
                'googleMapsApiKey' => get_option( 'order_sync_google_maps_api_key', '' ),
                'brandName' => get_option( 'subsales_branding', 'Subsales' ),
                'brandingImage' => $header_image_url
            );
            // Include configured products so portal bootstraps with current product list
            $settings['products'] = order_sync_get_products_config();

            $html_content = file_get_contents( $file );
            if ( $html_content === false ) {
                error_log( '[Subsales] Failed to read index.html' );
                return;
            }
            $html = $html_content;
            $inject = "<script>window.SUBSALES_PWA_CONFIG = " . wp_json_encode( $settings ) . ";</script>";
            $app_src = esc_url( $settings['pluginBase'] . 'app.js' );
            // Rewrite relative stylesheet hrefs to absolute plugin path
            $html = str_replace( 'href="styles.css"', 'href="' . esc_url( $settings['pluginBase'] . 'styles.css' ) . '"', $html );
            $new_html = str_replace( '<script src="app.js"></script>', $inject . "\n<script src=\"" . $app_src . "\"></script>", $html );
            $inject = "<script>window.SUBSALES_PWA_CONFIG = " . wp_json_encode( $settings ) . ";</script>";
            $app_src = esc_url( $settings['pluginBase'] . 'app.js' );
            // Rewrite relative stylesheet hrefs to absolute plugin path
            $html = str_replace( 'href="styles.css"', 'href="' . esc_url( $settings['pluginBase'] . 'styles.css' ) . '"', $html );
            $new_html = str_replace( '<script src="app.js"></script>', $inject . "\n<script src=\"" . $app_src . "\"></script>", $html );
            // If replacement didn't find the exact marker, inject config before </head>
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
            // Serve index publicly
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Cache-Control: public, max-age=300' );
            header( 'Access-Control-Allow-Origin: *' );
            echo $html;
            exit;
        }
    }
    
    if ( empty( $portal_slug ) ) return;
    $portal_base_raw = parse_url( home_url( '/' . $portal_slug . '/' ), PHP_URL_PATH );
    $portal_base = $portal_base_raw ? trim( $portal_base_raw, '/' ) : '';

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

    // Serve service worker for portal access (check both with and without leading slash)
    if ( $req_path === $portal_base . '/service-worker.js' || $req_path === rtrim($portal_base, '/') . '/service-worker.js' ) {
        error_log( '[Subsales Debug] Service worker requested at portal path - serving from: ' . SUBSALES_PLUGIN_PATH . 'pwa/service-worker.js' );
        $file = SUBSALES_PLUGIN_PATH . 'pwa/service-worker.js';
        if ( file_exists( $file ) ) {
            // Clear any previous output and set explicit 200 status
            status_header( 200 );
            header( 'Content-Type: application/javascript; charset=utf-8' );
            header( 'Cache-Control: no-cache, must-revalidate' );
            header( 'Access-Control-Allow-Origin: *' );
            header( 'Service-Worker-Allowed: /' );
            readfile( $file );
            exit;
        } else {
            error_log( '[Subsales Debug] Service worker file NOT FOUND at: ' . $file );
        }
    }

    // Serve other PWA assets at portal path (app.js, styles.css, manifest.json, icons, etc.)
    if ( strpos( $req_path, $portal_base ) === 0 && $req_path !== $portal_base && $req_path !== $portal_base . '/' && $req_path !== $portal_base . '/index.html' ) {
        $rel_path = substr( $req_path, strlen( $portal_base ) );
        $rel_path = ltrim( $rel_path, '/' );
        
        if ( $rel_path ) {
            $file = SUBSALES_PLUGIN_PATH . 'pwa/' . $rel_path;
            if ( file_exists( $file ) && is_file( $file ) ) {
                $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                switch ( $ext ) {
                    case 'js': $ct = 'application/javascript'; break;
                    case 'css': $ct = 'text/css'; break;
                    case 'json': $ct = 'application/json'; break;
                    case 'svg': $ct = 'image/svg+xml'; break;
                    case 'png': $ct = 'image/png'; break;
                    case 'jpg':
                    case 'jpeg': $ct = 'image/jpeg'; break;
                    case 'webp': $ct = 'image/webp'; break;
                    case 'ico': $ct = 'image/x-icon'; break;
                    default: $ct = 'application/octet-stream';
                }
                status_header( 200 );
                header( 'Content-Type: ' . $ct );
                header( 'Cache-Control: public, max-age=86400' );
                header( 'Access-Control-Allow-Origin: *' );
                readfile( $file );
                exit;
            }
        }
    }

    if ( $req_path === $portal_base || $req_path === $portal_base . '/index.html' || $req_path === $portal_base . '/' ) {
        // Serve PWA directly instead of redirecting
        $file = SUBSALES_PLUGIN_PATH . 'pwa/index.html';
        if ( file_exists( $file ) ) {
            $header_image_id = intval( get_option( 'subsales_header_image', 0 ) );
            $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';

            $settings = array(
                'apiBase' => esc_url_raw( rest_url( 'order-manager/v1' ) ),
                'pluginBase' => SUBSALES_PLUGIN_URL . 'pwa/',
                'portalBase' => esc_url_raw( home_url( '/' . $portal_slug . '/' ) ),
                'googleMapsApiKey' => get_option( 'order_sync_google_maps_api_key', '' ),
                'brandName' => get_option( 'subsales_branding', 'Subsales' ),
                'brandingImage' => $header_image_url
            );
            // Include configured products
            $settings['products'] = order_sync_get_products_config();

            $html = file_get_contents( $file );
            $inject = "<script>window.SUBSALES_PWA_CONFIG = " . wp_json_encode( $settings ) . ";</script>";
            $app_src = esc_url( $settings['pluginBase'] . 'app.js' );
            // Rewrite relative stylesheet hrefs to absolute plugin path
            $html = str_replace( 'href="styles.css"', 'href="' . esc_url( $settings['pluginBase'] . 'styles.css' ) . '"', $html );
            $new_html = str_replace( '<script src="app.js"></script>', $inject . "\n<script src=\"" . $app_src . "\"></script>", $html );
            // If replacement didn't find the exact marker, inject config before </head>
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
            // Serve index publicly
            header( 'Content-Type: text/html; charset=utf-8' );
            header( 'Cache-Control: public, max-age=300' );
            header( 'Access-Control-Allow-Origin: *' );
            echo $html;
            exit;
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

// Admin Delivery page - Moved to admin/delivery-page.php
require_once SUBSALES_PLUGIN_PATH . 'admin/delivery-page.php';

// Manifest viewer page (hidden, accessed via transient key)
function subsales_manifest_viewer_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions' );
    }
    
    $manifest_key = isset( $_GET['manifest_key'] ) ? sanitize_text_field( $_GET['manifest_key'] ) : '';
    
    if ( empty( $manifest_key ) ) {
        wp_die( 'No manifest key provided' );
    }
    
    $html = get_transient( $manifest_key );
    
    if ( $html === false ) {
        wp_die( 'Manifest not found or expired. Please generate a new manifest.' );
    }
    
    // Delete transient after retrieval for security
    delete_transient( $manifest_key );
    
    // Clear all output buffers to prevent WordPress from wrapping the HTML
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }
    
    // Send headers to prevent caching
    nocache_headers();
    
    // Output the HTML directly as a standalone document
    // This prevents WordPress admin wrapper from loading
    header( 'Content-Type: text/html; charset=UTF-8' );
    echo $html;
    exit;
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
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        // No date supplied: export all orders
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
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
        $seller = isset( $od['entered_by_name'] ) ? $od['entered_by_name'] : ( isset( $od['seller'] ) ? $od['seller'] : ( isset( $od['user'] ) ? $od['user'] : $r['user_id'] ) );
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

// System Logs page - view all logging activity with filters
function subsales_logs_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
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
    $page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
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
                            'base' => add_query_arg( 'paged', '%#%' ),
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
                nonce: '<?php echo wp_create_nonce( 'subsales_debug_toggle' ); ?>'
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
            const context = $(this).data('context');
            try {
                const formatted = JSON.stringify(JSON.parse(context), null, 2);
                $('#context-content').text(formatted);
            } catch(e) {
                $('#context-content').text(context);
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
 * PWA Sessions Admin Page
 * Shows active and historical PWA client sessions with real-time monitoring
 */
function subsales_pwa_sessions_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    
    // Get active sessions
    $active_sessions = Subsales_Database::get_active_pwa_sessions( 50 );
    $active_count = count( $active_sessions );
    
    // DEBUG: Output raw query result for troubleshooting
    if ( current_user_can( 'manage_options' ) ) {
        error_log( 'PWA Sessions Debug - Active Sessions Count: ' . $active_count );
        error_log( 'PWA Sessions Debug - Active Sessions Data: ' . print_r( $active_sessions, true ) );
    }
    
    // Get filter parameters
    $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'active';
    $team_filter = isset( $_GET['team_id'] ) ? intval( $_GET['team_id'] ) : null;
    
    // Get all sessions with pagination
    $per_page = 50;
    $page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
    $offset = ( $page - 1 ) * $per_page;
    
    $sessions = Subsales_Database::get_pwa_sessions( array(
        'status' => $status_filter === 'all' ? 'all' : $status_filter,
        'team_id' => $team_filter,
        'limit' => $per_page,
        'offset' => $offset
    ) );
    
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
        <form method="get" action="" style="background: #fff; padding: 15px; border: 1px solid #ddd; margin-bottom: 20px;">
            <input type="hidden" name="page" value="subsales-pwa-sessions">
            
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
                            <?php echo esc_html( $team['name'] ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px; align-items: flex-end;">
                    <button type="submit" class="button button-primary">Apply Filters</button>
                    <a href="?page=subsales-pwa-sessions" class="button">Reset</a>
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
                    <th style="width: 120px;">Session Expires</th>
                    <th style="width: 80px;">Status</th>
                    <th>User Agent</th>
                    <th style="width: 100px;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $sessions ) ): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 40px;">No sessions found.</td></tr>
                <?php else: ?>
                    <?php foreach ( $sessions as $session ): 
                        $login_time = strtotime( $session['login_at'] );
                        $logout_time = $session['logout_at'] ? strtotime( $session['logout_at'] ) : null;
                        $current_time = current_time( 'timestamp' );
                        $duration = $logout_time ? ( $logout_time - $login_time ) : ( $current_time - $login_time );
                        
                        // Calculate real-time status based on last heartbeat
                        $last_heartbeat = strtotime( $session['last_heartbeat'] );
                        $minutes_since_heartbeat = ( $current_time - $last_heartbeat ) / 60;
                        
                        if ( $session['status'] === 'ended' || $logout_time ) {
                            $display_status = 'ended';
                        } elseif ( $minutes_since_heartbeat <= 5 ) {
                            $display_status = 'active';
                        } else {
                            $display_status = 'idle';
                        }
                        
                        $status_colors = array(
                            'active' => '#28a745',
                            'idle' => '#ffc107',
                            'ended' => '#6c757d'
                        );
                        $status_color = isset( $status_colors[ $display_status ] ) ? $status_colors[ $display_status ] : '#ccc';
                        
                        // Calculate session expires remaining time
                        $expiry_display = '—';
                        if ( $session['session_expiry'] && $display_status !== 'ended' ) {
                            $expiry_time = strtotime( $session['session_expiry'] );
                            $time_remaining = $expiry_time - $current_time;
                            
                            if ( $time_remaining > 0 ) {
                                $hours = floor( $time_remaining / 3600 );
                                $minutes = floor( ( $time_remaining % 3600 ) / 60 );
                                $expiry_display = sprintf( '%dh %dm', $hours, $minutes );
                            } else {
                                $expiry_display = '<span style="color: #dc3545;">Expired</span>';
                            }
                        }
                    ?>
                    <tr>
                        <td>
                            <a href="#" class="view-session-details" data-session-id="<?php echo esc_attr( $session['session_id'] ); ?>" style="text-decoration: none; color: #2271b1;">
                                <small style="font-family: monospace;"><?php echo esc_html( substr( $session['session_id'], 0, 16 ) . '...' ); ?></small>
                            </a>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $session['user_name'] ?: '(Unknown)' ); ?></strong><br>
                            <small style="color: #666;"><?php echo esc_html( $session['team_name'] ?: 'No Team' ); ?></small>
                        </td>
                        <td><?php echo date( 'M j, g:i a', $login_time ); ?></td>
                        <td><?php echo date( 'M j, g:i a', strtotime( $session['last_heartbeat'] ) ); ?></td>
                        <td><?php echo $expiry_display; ?></td>
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
            Active sessions send a heartbeat every 30 seconds from the app. Click a Session ID to view heartbeat history and GPS tracking.
        </p>
    </div>
    
    <!-- Session Detail Modal -->
    <div id="session-detail-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 100000; overflow: auto;">
        <div style="max-width: 900px; margin: 40px auto; background: #fff; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.2);">
            <div style="padding: 20px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0;">Session Details</h2>
                <button id="close-session-modal" class="button" style="font-size: 20px; line-height: 1;">&times;</button>
            </div>
            <div id="session-detail-content" style="padding: 20px;">
                <div style="text-align: center; padding: 40px;">
                    <span class="spinner is-active" style="float: none; margin: 0;"></span>
                    <p>Loading session data...</p>
                </div>
            </div>
        </div>
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
        .view-session-details:hover {
            text-decoration: underline !important;
        }
    </style>
    
    <script>
    jQuery(document).ready(function($) {
        // Auto-refresh active sessions every 10 seconds
        let autoRefreshInterval = setInterval(function() {
            // Don't refresh if modal is open
            if ($('#session-detail-modal').is(':visible')) {
                console.log('Auto-refresh skipped - modal is open');
                return;
            }
            location.reload();
        }, 10000);
        
        // Manual refresh button
        $('#refresh-sessions-btn').on('click', function() {
            location.reload();
        });
        
        // Stop auto-refresh when user interacts with filters or modal
        $('select, input').on('focus', function() {
            clearInterval(autoRefreshInterval);
            console.log('Auto-refresh paused while editing filters');
        });
        
        // View session details
        $('.view-session-details').on('click', function(e) {
            e.preventDefault();
            const sessionId = $(this).data('session-id');
            
            // Show modal
            $('#session-detail-modal').fadeIn(200);
            
            // Load session data via AJAX
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'subsales_get_session_details',
                    nonce: '<?php echo wp_create_nonce( 'subsales_session_details' ); ?>',
                    session_id: sessionId
                },
                success: function(response) {
                    if (response.success) {
                        displaySessionDetails(response.data);
                    } else {
                        $('#session-detail-content').html('<div class="notice notice-error"><p>Error loading session data.</p></div>');
                    }
                },
                error: function() {
                    $('#session-detail-content').html('<div class="notice notice-error"><p>Failed to load session data.</p></div>');
                }
            });
        });
        
        // Close modal
        $('#close-session-modal, #session-detail-modal').on('click', function(e) {
            if (e.target === this) {
                $('#session-detail-modal').fadeOut(200);
            }
        });
        
        function displaySessionDetails(data) {
            const session = data.session;
            const heartbeats = data.heartbeats;
            
            let html = '<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px;">';
            html += '<div><strong>User:</strong> ' + (session.user_name || 'Unknown') + '</div>';
            html += '<div><strong>Team:</strong> ' + (session.team_name || 'No Team') + '</div>';
            html += '<div><strong>Login:</strong> ' + session.login_at + '</div>';
            html += '<div><strong>Last Heartbeat:</strong> ' + session.last_heartbeat + '</div>';
            html += '<div><strong>Session Expires:</strong> ' + (session.session_expiry || 'N/A') + '</div>';
            html += '<div><strong>Status:</strong> ' + session.status + '</div>';
            html += '<div><strong>IP Address:</strong> ' + session.ip_address + '</div>';
            html += '<div><strong>User Agent:</strong> ' + session.user_agent + '</div>';
            html += '</div>';
            
            html += '<h3 style="margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px;">Heartbeat History (' + heartbeats.length + ' records)</h3>';
            
            if (heartbeats.length > 0) {
                html += '<div style="max-height: 400px; overflow-y: auto;">';
                html += '<table class="widefat striped">';
                html += '<thead><tr>';
                html += '<th>Timestamp</th>';
                html += '<th>GPS Location</th>';
                html += '<th>Accuracy</th>';
                html += '<th>Activity</th>';
                html += '</tr></thead><tbody>';
                
                heartbeats.forEach(function(hb) {
                    html += '<tr>';
                    html += '<td>' + hb.heartbeat_at + '</td>';
                    
                    if (hb.gps_latitude && hb.gps_longitude) {
                        const mapsUrl = 'https://www.google.com/maps?q=' + hb.gps_latitude + ',' + hb.gps_longitude;
                        html += '<td><a href="' + mapsUrl + '" target="_blank">' + hb.gps_latitude + ', ' + hb.gps_longitude + '</a></td>';
                        html += '<td>' + (hb.gps_accuracy ? Math.round(hb.gps_accuracy) + 'm' : 'N/A') + '</td>';
                    } else {
                        html += '<td>—</td><td>—</td>';
                    }
                    
                    const activity = hb.activity_data ? JSON.parse(hb.activity_data) : {};
                    let activityDisplay = 'None';
                    
                    if (activity.type) {
                        // Auto heartbeat
                        activityDisplay = '<span style="color: #666;">Auto (' + activity.type + ')</span>';
                    } else if (activity.events && Array.isArray(activity.events)) {
                        // User activity events
                        const eventTypes = activity.events.map(e => e.action).join(', ');
                        activityDisplay = '<strong>' + activity.events.length + ' event' + (activity.events.length > 1 ? 's' : '') + ':</strong> ' + eventTypes;
                    } else if (Object.keys(activity).length > 0) {
                        // Unknown activity format - show keys
                        activityDisplay = Object.keys(activity).join(', ');
                    }
                    
                    html += '<td>' + activityDisplay + '</td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                html += '</div>';
            } else {
                html += '<p style="color: #666; font-style: italic;">No heartbeat data available.</p>';
            }
            
            $('#session-detail-content').html(html);
        }
    });
    </script>
    <?php
}

// Main dashboard page
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
            // Toggle switch handler
            $('#salesModeToggle').on('change', function() {
                const mode = this.checked ? 'user' : 'legacy';
                updateSalesMode(mode);
            });
            
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
                        nonce: '<?php echo wp_create_nonce( 'subsales_active_sessions' ); ?>'
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
        $team_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$teams_table}" );
        $member_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$members_table}" );
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
                <!-- Row 1: Orders, Teams, Members, Address -->
                <div class="subsales-top-row">
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-cart" aria-hidden="true"></span> Orders</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo intval( $order_count ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-groups" aria-hidden="true"></span> Teams</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo intval( $team_count ); ?></p>
                        </div>
                    </div>
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-admin-users" aria-hidden="true"></span> Members</h2></div>
                        <div class="inside">
                            <p class="stat-value"><?php echo intval( $member_count ); ?></p>
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
                
                <!-- Row 2: Sales, Donations, Cash, Checks -->
                <div class="subsales-financial-row">
                    <div class="postbox subsales-box">
                        <div class="postbox-header"><h2><span class="ss-icon dashicons dashicons-chart-line" aria-hidden="true"></span> Sales</h2></div>
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
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$orders_table} WHERE created_at >= %s AND created_at <= %s ORDER BY id ASC", $start_dt, $end_dt ), ARRAY_A );
    } else {
        $rows = $wpdb->get_results( "SELECT * FROM {$orders_table} ORDER BY id ASC", ARRAY_A );
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

// Admin settings page - extracted to separate file
require_once SUBSALES_PLUGIN_PATH . 'admin/settings-page.php';

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
    fwrite( $output, "# - Import OVERWRITES all data - what you import becomes the new official data\n" );
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
    fwrite( $output, "# Format: name, phone, email, status, teams\n" );
    fwrite( $output, "# - phone: Must be exactly 10 digits\n" );
    fwrite( $output, "# - email: Can be blank to remove email\n" );
    fwrite( $output, "# - status: 'active' or 'inactive'\n" );
    fwrite( $output, "# - teams: Comma-separated team names (must match teams defined above)\n" );
    
    if ( $export_type === 'template' ) {
        // Template with example data
        fwrite( $output, "# Example: John Doe, 2035551234, john@example.com, active, \"Team Alpha, Team Beta\"\n" );
        fputcsv( $output, array( 'name', 'phone', 'email', 'status', 'teams' ) );
        fputcsv( $output, array( 'John Doe', '2035551234', 'john@example.com', 'active', 'Team Alpha, Team Beta' ) );
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
            $teams_string = implode( ', ', $team_names );
            
            fputcsv( $output, array(
                $user['name'],
                $user['phone'],
                $user['email'] ?? '',
                $user['status'] ?? 'active',
                $teams_string
            ) );
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
        
        // Process team rows
        if ( $section === 'teams' && $teams_header_found ) {
            if ( count( $row ) < 3 ) {
                $errors[] = "Line {$line_num}: Invalid team format (need: team_name, access_code, status)";
                continue;
            }
            
            $team_name = trim( $row[0] );
            $access_code = trim( $row[1] );
            $status = trim( strtolower( $row[2] ) );
            
            if ( empty( $team_name ) ) {
                $errors[] = "Line {$line_num}: Team name is required";
                continue;
            }
            if ( empty( $access_code ) ) {
                $errors[] = "Line {$line_num}: Access code is required for team '{$team_name}'";
                continue;
            }
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
        
        // Process user rows
        if ( $section === 'users' && $users_header_found ) {
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
            
            // Parse teams
            $team_list = array();
            if ( ! empty( $user_teams ) ) {
                $team_list = array_map( 'trim', explode( ',', $user_teams ) );
                
                // Validate that all teams exist in the teams section
                foreach ( $team_list as $team_name ) {
                    $team_exists = false;
                    foreach ( $teams as $team ) {
                        if ( $team['name'] === $team_name ) {
                            $team_exists = true;
                            break;
                        }
                    }
                    if ( ! $team_exists ) {
                        $errors[] = "Line {$line_num}: Team '{$team_name}' not found in TEAMS section for user '{$name}'";
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
    
    // Start transaction
    $wpdb->query( 'START TRANSACTION' );
    
    try {
        // Clear existing data (complete overwrite as specified)
        $wpdb->query( "DELETE FROM {$user_teams_table}" );
        $wpdb->query( "DELETE FROM {$members_table}" );
        $wpdb->query( "DELETE FROM {$teams_table}" );
        
        // Insert teams
        $team_id_map = array(); // Map team names to IDs
        foreach ( $import_data['teams'] as $team ) {
            $wpdb->insert(
                $teams_table,
                array(
                    'name' => $team['name'],
                    'access_code' => $team['access_code'],
                    'status' => $team['status']
                ),
                array( '%s', '%s', '%s' )
            );
            $team_id_map[ $team['name'] ] = $wpdb->insert_id;
        }
        
        // Insert users and their team assignments
        foreach ( $import_data['users'] as $user ) {
            // Insert user
            $wpdb->insert(
                $members_table,
                array(
                    'team_id' => 0, // Legacy field, not used in multi-team system
                    'name' => $user['name'],
                    'phone' => $user['phone'],
                    'email' => $user['email'],
                    'role' => 'member',
                    'status' => $user['status']
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
            $user_id = $wpdb->insert_id;
            
            // Insert team assignments
            foreach ( $user['teams'] as $team_name ) {
                if ( isset( $team_id_map[ $team_name ] ) ) {
                    $wpdb->insert(
                        $user_teams_table,
                        array(
                            'user_id' => $user_id,
                            'team_id' => $team_id_map[ $team_name ]
                        ),
                        array( '%d', '%d' )
                    );
                }
            }
        }
        
        // Commit transaction
        $wpdb->query( 'COMMIT' );
        
        subsales_log( 'INFO', 'import', 'Users and teams imported successfully', array(
            'teams_count' => count( $import_data['teams'] ),
            'users_count' => count( $import_data['users'] )
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
        $name = sanitize_text_field( $_POST['user_name'] ?? '' );
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
        $name = sanitize_text_field( $_POST['user_name'] ?? '' );
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
        $name = sanitize_text_field( $_POST['team_name'] ?? '' );
        $code = sanitize_text_field( $_POST['team_code'] ?? '' );
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
        $name = sanitize_text_field( $_POST['team_name'] ?? '' );
        $code = sanitize_text_field( $_POST['team_code'] ?? '' );
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
                    <p style="color: #d63638;"><strong>⚠️ WARNING:</strong> This will completely replace all existing users and teams with the data shown below.</p>
                    
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
                                    <td><?php echo esc_html( $team['name'] ); ?></td>
                                    <td><?php echo esc_html( $team['access_code'] ); ?></td>
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
                                    <td><?php echo esc_html( $user['name'] ); ?></td>
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
                            <button type="submit" class="button button-primary button-large" onclick="return confirm('Are you sure? This will completely replace all existing users and teams!');">
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
                                <strong><?php echo esc_html( $user['name'] ); ?></strong><br>
                                <span style="color: #666; font-size: 13px;">📞 <?php echo esc_html( $user['phone'] ?? 'No phone' ); ?></span>
                            </td>
                            <td><?php echo esc_html( $user['email'] ?: '—' ); ?></td>
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
                                    <strong><?php echo esc_html( $user['name'] ); ?></strong><br>
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
                                                <?php echo esc_html( $team['name'] ); ?>
                                                <?php if ( ! $team_is_active ) : ?>
                                                    <span style="font-size: 12px; color: #999; font-weight: normal;">(Inactive)</span>
                                                <?php endif; ?>
                                                <span style="font-weight: normal; color: #666; font-size: 14px;">
                                                    (Code: <?php echo esc_html( $team['access_code'] ); ?>)
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
                                                // Get team members via junction table
                                                $user_teams_table = $wpdb->prefix . 'ss_user_teams';
                                                $team_member_ids = $wpdb->get_col( $wpdb->prepare(
                                                    "SELECT user_id FROM {$user_teams_table} WHERE team_id = %d",
                                                    $team['id']
                                                ));
                                                
                                                $team_members_new = array();
                                                if ( ! empty( $team_member_ids ) ) {
                                                    $team_members_new = $wpdb->get_results(
                                                        "SELECT * FROM {$members_table} WHERE id IN (" . implode( ',', array_map( 'intval', $team_member_ids ) ) . ")",
                                                        ARRAY_A
                                                    );
                                                }
                                                
                                                if ( ! empty( $team_members_new ) ) : ?>
                                                <?php foreach ( $team_members_new as $member ) : ?>
                                                    <?php $member_is_active = ( $member['status'] ?? 'active' ) === 'active'; ?>
                                                    <div class="user-card team-member-card" data-user-id="<?php echo intval( $member['id'] ); ?>" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                                         style="background: <?php echo $member_is_active ? '#fff' : '#f5f5f5'; ?>; border: 1px solid <?php echo $member_is_active ? '#4CAF50' : '#ccc'; ?>; border-radius: 4px; padding: 10px; margin-bottom: 8px; position: relative;<?php echo $member_is_active ? '' : ' opacity: 0.7;'; ?>">
                                                        <button type="button" class="remove-from-team" data-user-id="<?php echo intval( $member['id'] ); ?>" data-team-id="<?php echo intval( $team['id'] ); ?>" 
                                                                style="position: absolute; top: 5px; right: 5px; background: #dc3232; color: #fff; border: none; border-radius: 3px; cursor: pointer; padding: 2px 6px; font-size: 11px;"
                                                                title="Remove from team">×</button>
                                                        <strong><?php echo esc_html( $member['name'] ); ?></strong>
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

// Orders admin page
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
            
            // Collect products
            const configuredProducts = <?php echo json_encode( array_values( $products_conf ) ); ?>;
            for (const p of configuredProducts) {
                const qty = parseInt(form.elements['product_' + p.id].value) || 0;
                data.products.push({
                    id: p.id,
                    name: p.name,
                    price: p.price,
                    qty: qty
                });
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

