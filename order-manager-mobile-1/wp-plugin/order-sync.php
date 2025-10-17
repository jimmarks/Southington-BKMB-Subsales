<?php
/**
 * Plugin Name: Order Sync
 * Description: A plugin to sync orders from the mobile app to the WordPress backend.
 * Version: 1.0
 * Author: Your Name
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include necessary files
require_once plugin_dir_path( __FILE__ ) . 'includes/api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';

// Register activation hook
function order_sync_activate() {
    // Activation code here
}
register_activation_hook( __FILE__, 'order_sync_activate' );

// Register deactivation hook
function order_sync_deactivate() {
    // Deactivation code here
}
register_deactivation_hook( __FILE__, 'order_sync_deactivate' );

// Add settings link on plugin page
function order_sync_settings_link( $links ) {
    $settings_link = '<a href="options-general.php?page=order_sync_settings">Settings</a>';
    array_push( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'order_sync_settings_link' );

// Enqueue scripts and styles if needed
function order_sync_enqueue_scripts() {
    // Enqueue code here
}
add_action( 'wp_enqueue_scripts', 'order_sync_enqueue_scripts' );
?>