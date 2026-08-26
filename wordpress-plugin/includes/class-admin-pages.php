<?php
/**
 * Admin Pages Handler
 *
 * Handles rendering of all admin pages in the WordPress dashboard.
 *
 * @package SubsalesManagement
 * @since 2.2.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Subsales_Admin_Pages class
 *
 * Centralizes all admin page rendering logic.
 */
class Subsales_Admin_Pages {

    /**
     * Initialize the class
     */
    public static function init() {
        // Currently no hooks needed - pages are called directly
        // Future: Could add hooks for page-specific assets, etc.
    }

    /**
     * Render the main dashboard page
     *
     * @return void
     */
    public static function render_main_dashboard() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/main-dashboard.php';
    }

    /**
     * Render the orders page
     *
     * @return void
     */
    public static function render_orders_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/orders-page.php';
    }

    /**
     * Render the reports index page
     *
     * @return void
     */
    public static function render_reports_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/reports-index.php';
    }
    
    /**
     * Render the team sales report page
     *
     * @return void
     */
    public static function render_team_sales_report() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/reports-page.php';
    }

    /**
     * Render the settings page
     *
     * @return void
     */
    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/settings-page.php';
    }

    /**
     * Render the teams page
     *
     * @return void
     */
    public static function render_teams_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/teams-page.php';
    }

    /**
     * Render the delivery page
     *
     * @return void
     */
    public static function render_delivery_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/delivery-page.php';
    }

    /**
     * Render the seasons page
     *
     * @return void
     */
    public static function render_seasons_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        include SUBSALES_PLUGIN_PATH . 'admin/seasons-page.php';
    }

    /**
     * Render the logs page
     *
     * @return void
     */
    public static function render_logs_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/logs-page.php';
    }

    /**
     * Render the PWA sessions page
     *
     * @return void
     */
    public static function render_pwa_sessions_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/pwa-sessions-page.php';
    }

    /**
     * Render the address extracts page
     *
     * @return void
     */
    public static function render_address_extracts_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/address-management-dashboard.php';
    }

    /**
     * Render the delivery manifest viewer
     *
     * @return void
     */
    public static function render_manifest_viewer() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        include SUBSALES_PLUGIN_PATH . 'admin/manifest-viewer.php';
    }
}
