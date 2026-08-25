<?php
/**
 * Uninstall script for Subsales Management plugin
 * 
 * This file is automatically executed by WordPress when the plugin is deleted.
 * Data deletion is now OPTIONAL based on user preference.
 * 
 * @package Subsales_Management
 */

// Exit if accessed directly or not during uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Check if user wants to delete data on uninstall
// Default to 'no' (preserve data) for safety
$delete_on_uninstall = get_option( 'subsales_delete_on_uninstall', 'no' );

if ( $delete_on_uninstall !== 'yes' ) {
    // User chose to preserve data - only clean up the deletion option itself
    delete_option( 'subsales_delete_on_uninstall' );
    exit; // Keep all other data
}

global $wpdb;

// Drop all database tables
$tables = array(
    $wpdb->prefix . 'ss_orders',
    $wpdb->prefix . 'ss_teams',
    $wpdb->prefix . 'ss_team_members',
    $wpdb->prefix . 'ss_user_teams',
    $wpdb->prefix . 'ss_logs'
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Delete all plugin options
$options = array(
    'order_sync_google_maps_api_key',
    'order_sync_interval',
    'order_sync_portal_slug',
    'order_sync_products',
    'order_sync_style_variant',
    'order_sync_primary_color',
    'order_sync_login_mode',
    'subsales_branding',
    'subsales_header_image',
    'subsales_delete_on_uninstall',
    'subsales_served_zips',
    'subsales_debug_logging_enabled',
    'subsales_debug_logging_started',
    'subsales_initialized'
);

foreach ( $options as $option ) {
    delete_option( $option );
}

// Delete PWA page
$slug = get_option( 'order_sync_portal_slug', 'subsales-portal' );
$page = get_page_by_path( $slug );
if ( $page ) {
    wp_delete_post( $page->ID, true );
}

// Delete uploaded ZIP data files
$upload_dir = wp_upload_dir();
$zipdata_dir = $upload_dir['basedir'] . '/subsales-zipdata';
if ( is_dir( $zipdata_dir ) ) {
    subsales_recursive_rmdir_uninstall( $zipdata_dir );
}

/**
 * Helper function to recursively delete a directory
 * Defined here since the main plugin file won't be loaded during uninstall
 */
function subsales_recursive_rmdir_uninstall( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return false;
    }
    
    $items = scandir( $dir );
    foreach ( $items as $item ) {
        if ( $item === '.' || $item === '..' ) {
            continue;
        }
        
        $path = $dir . '/' . $item;
        if ( is_dir( $path ) ) {
            subsales_recursive_rmdir_uninstall( $path );
        } else {
            @unlink( $path );
        }
    }
    
    return @rmdir( $dir );
}
