<?php
/**
 * Backup and Restore functionality
 * Handles exporting and importing all plugin data
 *
 * @package Subsales_Management
 * @since 2.4.81
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Backup_Restore {
    
    /**
     * Initialize the class
     */
    public static function init() {
        // Register admin-post handlers
        add_action( 'admin_post_subsales_export_backup_combined', array( __CLASS__, 'handle_export' ) );
        add_action( 'admin_post_subsales_import_backup', array( __CLASS__, 'handle_import' ) );
        add_action( 'admin_post_subsales_restore_and_import', array( __CLASS__, 'handle_restore' ) );
    }
    
    /**
     * Handle export request
     */
    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        check_admin_referer( 'subsales_export_nonce' );
        
        self::export_full_backup();
    }
    
    /**
     * Handle non-destructive import request
     */
    public static function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        check_admin_referer( 'subsales_import_nonce' );
        
        if ( ! isset( $_FILES['backup_file'] ) || ! is_uploaded_file( $_FILES['backup_file']['tmp_name'] ) ) {
            wp_redirect( add_query_arg( 'subsales_import_error', 'nofile', wp_get_referer() ) );
            exit;
        }
        
        $tmp = $_FILES['backup_file']['tmp_name'];
        $update_existing = isset( $_POST['import_update_existing'] ) && intval( $_POST['import_update_existing'] ) === 1;
        
        $result = self::import_file( $tmp, $update_existing );
        
        $msg = self::format_import_message( $result, false );
        
        set_transient( 'subsales_suppress_onboarding', true, 30 );
        wp_redirect( add_query_arg( 'subsales_import_result', rawurlencode( $msg ), wp_get_referer() ) );
        exit;
    }
    
    /**
     * Handle destructive restore request
     */
    public static function handle_restore() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        check_admin_referer( 'subsales_restore_nonce' );
        
        if ( ! isset( $_FILES['backup_file'] ) || ! is_uploaded_file( $_FILES['backup_file']['tmp_name'] ) ) {
            subsales_log( 'ERROR', 'system', 'Restore failed: No file uploaded', array() );
            wp_redirect( add_query_arg( 'subsales_import_error', 'nofile', wp_get_referer() ) );
            exit;
        }
        
        subsales_log( 'INFO', 'system', 'Restore started', array(
            'file' => $_FILES['backup_file']['name'],
            'size' => $_FILES['backup_file']['size']
        ) );
        
        // Determine clear scope
        $restore_target = isset( $_POST['restore_target'] ) ? sanitize_text_field( $_POST['restore_target'] ) : 'both';
        
        // Perform clear operation
        try {
            if ( $restore_target === 'both' ) {
                subsales_log( 'INFO', 'system', 'Clearing all data and settings', array() );
                if ( function_exists( 'order_sync_clear_data' ) ) {
                    order_sync_clear_data();
                }
            } else if ( $restore_target === 'data' ) {
                subsales_log( 'INFO', 'system', 'Clearing data only', array() );
                if ( function_exists( 'order_sync_clear_orders' ) ) {
                    order_sync_clear_orders();
                }
            } else if ( $restore_target === 'settings' ) {
                subsales_log( 'INFO', 'system', 'Clearing settings only', array() );
                if ( function_exists( 'order_sync_clear_settings' ) ) {
                    order_sync_clear_settings();
                }
            }
        } catch ( Exception $e ) {
            subsales_log( 'ERROR', 'system', 'Clear operation failed', array( 'error' => $e->getMessage() ) );
            wp_redirect( add_query_arg( 'subsales_import_error', 'clear_failed', wp_get_referer() ) );
            exit;
        }
        
        $tmp = $_FILES['backup_file']['tmp_name'];
        $result = self::import_file( $tmp, true );
        
        subsales_log( 'INFO', 'system', 'Import completed', array(
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped']
        ) );
        
        $msg = self::format_import_message( $result, true );
        
        set_transient( 'subsales_suppress_onboarding', true, 30 );
        wp_redirect( add_query_arg( 'subsales_import_result', rawurlencode( $msg ), wp_get_referer() ) );
        exit;
    }
    
    /**
     * Export full backup of all plugin data
     */
    public static function export_full_backup() {
        global $wpdb;
        
        subsales_log( 'INFO', 'system', 'Full backup export started', array() );
        
        // Get selected tables from POST (or all if not specified)
        $selected_tables = isset( $_POST['selected_tables'] ) ? (array) $_POST['selected_tables'] : array();
        
        // Define all available tables
        $available_tables = array(
            'orders' => 'Orders',
            'teams' => 'Teams',
            'team_members' => 'Team Members',
            'user_teams' => 'User Team Assignments',
            'addresses' => 'Addresses',
            'edit_history' => 'Order Edit History',
            'logs' => 'System Logs',
            'pwa_sessions' => 'PWA Sessions',
            'pwa_heartbeats' => 'PWA Heartbeats',
            'campaigns' => 'Campaigns',
            'signups' => 'Campaign Signups',
            'team_campaigns' => 'Team Campaign Assignments',
            'settings' => 'Plugin Settings'
        );
        
        // If no tables selected, export all
        if ( empty( $selected_tables ) ) {
            $selected_tables = array_keys( $available_tables );
        }
        
        $exported_files = array();
        $export_counts = array();
        
        $tmpdir = sys_get_temp_dir() . '/subsales-export-' . time();
        if ( ! wp_mkdir_p( $tmpdir ) ) {
            subsales_log( 'ERROR', 'system', 'Failed to create temp directory', array() );
            wp_die( 'Could not create temporary directory for export.' );
        }
        
        // Export each selected table
        foreach ( $selected_tables as $table_key ) {
            if ( ! isset( $available_tables[ $table_key ] ) ) {
                continue;
            }
            
            if ( $table_key === 'settings' ) {
                // Special handler for settings
                $result = self::export_settings( $tmpdir );
                if ( $result['csv_file'] ) {
                    $exported_files[] = $result['csv_file'];
                }
                if ( ! empty( $result['image_files'] ) ) {
                    $exported_files = array_merge( $exported_files, $result['image_files'] );
                }
                $export_counts['settings'] = $result['count'];
            } else {
                // Generic table export
                $csv_file = self::export_table_to_csv( $table_key, $tmpdir );
                if ( $csv_file ) {
                    $exported_files[] = $csv_file;
                    // Count rows
                    $table_name = $wpdb->prefix . 'ss_' . $table_key;
                    $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
                    $export_counts[ $table_key ] = $count;
                }
            }
        }
        
        if ( empty( $exported_files ) ) {
            subsales_log( 'ERROR', 'system', 'No files to export', array() );
            wp_die( 'No data to export.' );
        }
        
        $tables = array();
        
        // 1. Orders table
        $orders_table = $wpdb->prefix . 'ss_orders';
        $orders = $wpdb->get_results( "SELECT * FROM {$orders_table} ORDER BY created_at DESC", ARRAY_A );
        $tables['orders'] = $build_csv(
            array( 'id', 'order_id', 'user_id', 'team_id', 'order_data', 'sync_status', 'deleted', 'deleted_at', 'deleted_by_user_id', 'delete_reason', 'tallied', 'tallied_at', 'tallied_by_user_id', 'address', 'address_entry_method', 'address_validation_status', 'address_validation_date', 'address_validation_data', 'address_hash', 'created_at', 'updated_at' ),
            array_map( function($r) {
                return array(
                    $r['id'], $r['order_id'], $r['user_id'], $r['team_id'], $r['order_data'], $r['sync_status'],
                    $r['deleted'] ?? 0, $r['deleted_at'] ?? '', $r['deleted_by_user_id'] ?? '', $r['delete_reason'] ?? '',
                    $r['tallied'] ?? 0, $r['tallied_at'] ?? '', $r['tallied_by_user_id'] ?? '',
                    $r['address'] ?? '', $r['address_entry_method'] ?? 'unknown',
                    $r['address_validation_status'] ?? 'pending', $r['address_validation_date'] ?? '',
                    $r['address_validation_data'] ?? '', $r['address_hash'] ?? '',
                    $r['created_at'], $r['updated_at']
                );
            }, $orders )
        );
        
        // 2. Teams table
        $teams_table = $wpdb->prefix . 'ss_teams';
        $teams = $wpdb->get_results( "SELECT * FROM {$teams_table} ORDER BY id ASC", ARRAY_A );
        $tables['teams'] = $build_csv(
            array( 'id', 'name', 'access_code', 'description', 'status', 'created_at', 'updated_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['name'], $r['access_code'], $r['description'] ?? '', $r['status'] ?? 'active', $r['created_at'], $r['updated_at'] ?? '' );
            }, $teams )
        );
        
        // 3. Team members table
        $members_table = $wpdb->prefix . 'ss_team_members';
        $members = $wpdb->get_results( "SELECT * FROM {$members_table} ORDER BY id ASC", ARRAY_A );
        $tables['members'] = $build_csv(
            array( 'id', 'team_id', 'name', 'email', 'phone', 'role', 'status', 'last_login', 'created_at', 'updated_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['team_id'] ?? 0, $r['name'], $r['email'] ?? '', $r['phone'], $r['role'] ?? 'member', $r['status'] ?? 'active', $r['last_login'] ?? '', $r['created_at'], $r['updated_at'] ?? '' );
            }, $members )
        );
        
        // 4. User-teams junction table
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $user_teams = $wpdb->get_results( "SELECT * FROM {$user_teams_table} ORDER BY id ASC", ARRAY_A );
        $tables['user_teams'] = $build_csv(
            array( 'id', 'user_id', 'team_id', 'assigned_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['user_id'], $r['team_id'], $r['assigned_at'] ?? '' );
            }, $user_teams )
        );
        
        // 5. Addresses table
        $addresses_table = $wpdb->prefix . 'ss_addresses';
        $addresses = $wpdb->get_results( "SELECT * FROM {$addresses_table} ORDER BY id ASC LIMIT 50000", ARRAY_A );
        $tables['addresses'] = $build_csv(
            array( 'id', 'street', 'house_number', 'unit', 'city', 'state', 'zip', 'lat', 'lng', 'source', 'confidence', 'matched', 'type', 'full_address', 'created_at', 'updated_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['street'] ?? '', $r['house_number'] ?? '', $r['unit'] ?? '', $r['city'] ?? 'Southington', $r['state'] ?? 'CT', $r['zip'] ?? '', $r['lat'] ?? '', $r['lng'] ?? '', $r['source'] ?? 'manual', $r['confidence'] ?? 'medium', $r['matched'] ?? 0, $r['type'] ?? 'residential', $r['full_address'] ?? '', $r['created_at'] ?? '', $r['updated_at'] ?? '' );
            }, $addresses )
        );
        
        // 6. Edit history table
        $edit_history_table = $wpdb->prefix . 'ss_edit_history';
        $edit_history = $wpdb->get_results( "SELECT * FROM {$edit_history_table} ORDER BY id ASC", ARRAY_A );
        $tables['edit_history'] = $build_csv(
            array( 'id', 'order_id', 'edited_by_user_id', 'edited_by_name', 'edit_type', 'edit_reason', 'changes_summary', 'changes_detail', 'source', 'edited_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['order_id'], $r['edited_by_user_id'], $r['edited_by_name'] ?? '', $r['edit_type'], $r['edit_reason'] ?? '', $r['changes_summary'] ?? '', $r['changes_detail'] ?? '', $r['source'] ?? 'admin', $r['edited_at'] );
            }, $edit_history )
        );
        
        // 7. Logs table
        $logs_table = $wpdb->prefix . 'ss_logs';
        $logs = $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY id DESC LIMIT 10000", ARRAY_A );
        $tables['logs'] = $build_csv(
            array( 'id', 'log_level', 'category', 'message', 'user_id', 'user_name', 'source', 'context_json', 'created_at', 'is_debug' ),
            array_map( function($r) {
                return array( $r['id'], $r['log_level'], $r['category'], $r['message'], $r['user_id'] ?? '', $r['user_name'] ?? '', $r['source'] ?? 'admin', $r['context_json'] ?? '', $r['created_at'], $r['is_debug'] ?? 0 );
            }, $logs )
        );
        
        // 8. PWA Sessions table
        $pwa_sessions_table = $wpdb->prefix . 'ss_pwa_sessions';
        $pwa_sessions = $wpdb->get_results( "SELECT * FROM {$pwa_sessions_table} ORDER BY id ASC", ARRAY_A );
        $tables['pwa_sessions'] = $build_csv(
            array( 'id', 'session_id', 'user_id', 'user_name', 'team_id', 'team_name', 'user_agent', 'ip_address', 'login_at', 'last_heartbeat', 'logout_at', 'session_expiry', 'session_data', 'status' ),
            array_map( function($r) {
                return array( $r['id'], $r['session_id'], $r['user_id'] ?? '', $r['user_name'] ?? '', $r['team_id'] ?? '', $r['team_name'] ?? '', $r['user_agent'] ?? '', $r['ip_address'] ?? '', $r['login_at'], $r['last_heartbeat'], $r['logout_at'] ?? '', $r['session_expiry'] ?? '', $r['session_data'] ?? '', $r['status'] ?? 'active' );
            }, $pwa_sessions )
        );
        
        // 9. PWA Heartbeats table
        $pwa_heartbeats_table = $wpdb->prefix . 'ss_pwa_heartbeats';
        $pwa_heartbeats = $wpdb->get_results( "SELECT * FROM {$pwa_heartbeats_table} ORDER BY id ASC", ARRAY_A );
        $tables['pwa_heartbeats'] = $build_csv(
            array( 'id', 'session_id', 'heartbeat_at', 'gps_latitude', 'gps_longitude', 'gps_accuracy', 'activity_data' ),
            array_map( function($r) {
                return array( $r['id'], $r['session_id'], $r['heartbeat_at'], $r['gps_latitude'] ?? '', $r['gps_longitude'] ?? '', $r['gps_accuracy'] ?? '', $r['activity_data'] ?? '' );
            }, $pwa_heartbeats )
        );
        
        // 10. Campaigns table
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        $campaigns = $wpdb->get_results( "SELECT * FROM {$campaigns_table} ORDER BY id ASC", ARRAY_A );
        $tables['campaigns'] = $build_csv(
            array( 'id', 'campaign_date', 'campaign_name', 'notes', 'status', 'created_at', 'updated_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['campaign_date'], $r['campaign_name'] ?? '', $r['notes'] ?? '', $r['status'] ?? 'active', $r['created_at'], $r['updated_at'] ?? '' );
            }, $campaigns )
        );
        
        // 11. Signups table
        $signups_table = $wpdb->prefix . 'ss_signups';
        $signups = $wpdb->get_results( "SELECT * FROM {$signups_table} ORDER BY id ASC", ARRAY_A );
        $tables['signups'] = $build_csv(
            array( 'id', 'user_id', 'team_id', 'campaign_id', 'is_driver', 'notes', 'status', 'created_at', 'updated_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['user_id'], $r['team_id'], $r['campaign_id'], $r['is_driver'] ?? 0, $r['notes'] ?? '', $r['status'] ?? 'active', $r['created_at'], $r['updated_at'] ?? '' );
            }, $signups )
        );
        
        // 12. Team campaigns table
        $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';
        $team_campaigns = $wpdb->get_results( "SELECT * FROM {$team_campaigns_table} ORDER BY id ASC", ARRAY_A );
        $tables['team_campaigns'] = $build_csv(
            array( 'id', 'team_id', 'campaign_id', 'driver_name', 'driver_updated_by', 'driver_updated_at', 'created_at', 'updated_at' ),
            array_map( function($r) {
                return array( $r['id'], $r['team_id'], $r['campaign_id'], $r['driver_name'] ?? '', $r['driver_updated_by'] ?? '', $r['driver_updated_at'] ?? '', $r['created_at'], $r['updated_at'] ?? '' );
            }, $team_campaigns )
        );
        
        // 13. Settings
        $all_option_keys = array(
            'order_sync_portal_slug',
            'order_sync_google_maps_api_key',
            'subsales_branding',
            'order_sync_products',
            'order_sync_primary_color',
            'order_sync_style_variant',
            'order_sync_interval',
            'order_sync_session_duration',
            'order_sync_login_mode',
            'subsales_header_image',
            'subsales_served_zipcodes',
            'subsales_delete_on_uninstall',
            'order_sync_pwa_page_id',
            'subsales_debug_mode',
            'subsales_debug_mode_until',
            'subsales_db_version'
        );
        
        $tables['settings'] = $build_csv(
            array( 'option_key', 'option_value' ),
            array_map( function($k) {
                $v = get_option( $k );
                if ( is_array( $v ) || is_object( $v ) ) {
                    $v = wp_json_encode( $v );
                }
                return array( $k, $v );
            }, $all_option_keys )
        );
        
        // Create metadata
        $metadata = array(
            'export_date' => current_time( 'mysql' ),
            'plugin_version' => defined( 'SUBSALES_VERSION' ) ? SUBSALES_VERSION : 'unknown',
            'wordpress_version' => get_bloginfo( 'version' ),
            'site_url' => get_site_url(),
            'tables' => array(
                'orders' => count( $orders ),
                'teams' => count( $teams ),
                'members' => count( $members ),
                'user_teams' => count( $user_teams ),
                'addresses' => count( $addresses ),
                'edit_history' => count( $edit_history ),
                'logs' => count( $logs ),
                'pwa_sessions' => count( $pwa_sessions ),
                'pwa_heartbeats' => count( $pwa_heartbeats ),
                'campaigns' => count( $campaigns ),
                'signups' => count( $signups ),
                'team_campaigns' => count( $team_campaigns )
            )
        );
        
        // Create ZIP
        $zipname = sys_get_temp_dir() . '/subsales-backup-' . time() . '.zip';
        $za = new ZipArchive();
        if ( $za->open( $zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            subsales_log( 'ERROR', 'system', 'Failed to create backup ZIP', array() );
            wp_die( 'Could not create backup ZIP file.' );
        }
        
        // Add all CSVs
        foreach ( $tables as $name => $csv ) {
            $za->addFromString( $name . '.csv', $csv );
        }
        
        // Add metadata
        $za->addFromString( 'BACKUP_INFO.json', wp_json_encode( $metadata, JSON_PRETTY_PRINT ) );
        $za->close();
        
        subsales_log( 'INFO', 'system', 'Backup export completed', $metadata['tables'] );
        
        // Send file
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename=subsales-backup-' . date( 'Ymd_His' ) . '.zip' );
        header( 'Content-Length: ' . filesize( $zipname ) );
        readfile( $zipname );
        @unlink( $zipname );
        exit;
    }
    
    /**
     * Import file (ZIP or CSV)
     *
     * @param string $filepath Path to uploaded file
     * @param bool $update_existing Whether to update existing records
     * @return array Import statistics
     */
    public static function import_file( $filepath, $update_existing = false ) {
        global $wpdb;
        
        $totals = array(
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
            'geocoded' => 0,
            'zip_corrected' => 0,
            'byTable' => array()
        );
        
        // Check if ZIP
        $is_zip = false;
        if ( class_exists( 'ZipArchive' ) ) {
            $za = new ZipArchive();
            if ( $za->open( $filepath ) === true ) {
                $is_zip = true;
                $za->close();
            }
        }
        
        if ( $is_zip ) {
            // Extract and process all CSVs
            $tmpdir = sys_get_temp_dir() . '/' . uniqid( 'subsales_import_' );
            if ( ! wp_mkdir_p( $tmpdir ) ) {
                $totals['errors'][] = 'temp_dir_failed';
                return $totals;
            }
            
            $za = new ZipArchive();
            if ( $za->open( $filepath ) === true ) {
                for ( $i = 0; $i < $za->numFiles; $i++ ) {
                    $name = $za->getNameIndex( $i );
                    if ( preg_match( '/\.(csv)$/i', $name ) ) {
                        $outpath = $tmpdir . '/' . basename( $name );
                        if ( copy( 'zip://' . $filepath . '#' . $name, $outpath ) ) {
                            $result = self::process_csv_file( $outpath, $update_existing );
                            $totals['imported'] += $result['imported'];
                            $totals['updated'] += $result['updated'];
                            $totals['skipped'] += $result['skipped'];
                            $totals['geocoded'] += $result['geocoded'];
                            $totals['zip_corrected'] += $result['zip_corrected'];
                            $totals['errors'] = array_merge( $totals['errors'], $result['errors'] );
                            
                            // Track per-table stats
                            $table_name = basename( $name, '.csv' );
                            if ( $result['imported'] > 0 || $result['updated'] > 0 ) {
                                $totals['byTable'][ $table_name ] = array(
                                    'imported' => $result['imported'],
                                    'updated' => $result['updated'],
                                    'skipped' => $result['skipped']
                                );
                            }
                            
                            @unlink( $outpath );
                        } else {
                            $totals['errors'][] = 'extract_failed_' . basename( $name );
                        }
                    }
                }
                $za->close();
                @rmdir( $tmpdir );
            }
        } else {
            // Single CSV file
            $result = self::process_csv_file( $filepath, $update_existing );
            $totals['imported'] += $result['imported'];
            $totals['updated'] += $result['updated'];
            $totals['skipped'] += $result['skipped'];
            $totals['geocoded'] += $result['geocoded'];
            $totals['zip_corrected'] += $result['zip_corrected'];
            $totals['errors'] = array_merge( $totals['errors'], $result['errors'] );
        }
        
        return $totals;
    }
    
    /**
     * Process a single CSV file based on filename
     *
     * @param string $filepath Path to CSV file
     * @param bool $update_existing Whether to update existing records
     * @return array Processing statistics
     */
    private static function process_csv_file( $filepath, $update_existing ) {
        $result = array(
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
            'geocoded' => 0,
            'zip_corrected' => 0
        );
        
        if ( ! file_exists( $filepath ) ) {
            $result['errors'][] = 'file_not_found';
            return $result;
        }
        
        $filename = basename( $filepath, '.csv' );
        
        // Route to appropriate processor based on filename
        switch ( $filename ) {
            case 'orders':
                return self::import_orders( $filepath, $update_existing );
            case 'teams':
                return self::import_teams( $filepath, $update_existing );
            case 'members':
                return self::import_members( $filepath, $update_existing );
            case 'user_teams':
                return self::import_user_teams( $filepath, $update_existing );
            case 'addresses':
                return self::import_addresses( $filepath, $update_existing );
            case 'edit_history':
                return self::import_edit_history( $filepath, $update_existing );
            case 'logs':
                return self::import_logs( $filepath, $update_existing );
            case 'pwa_sessions':
                return self::import_pwa_sessions( $filepath, $update_existing );
            case 'pwa_heartbeats':
                return self::import_pwa_heartbeats( $filepath, $update_existing );
            case 'campaigns':
                return self::import_campaigns( $filepath, $update_existing );
            case 'signups':
                return self::import_signups( $filepath, $update_existing );
            case 'team_campaigns':
                return self::import_team_campaigns( $filepath, $update_existing );
            case 'settings':
                return self::import_settings( $filepath );
            default:
                $result['errors'][] = 'unknown_file_' . $filename;
                return $result;
        }
    }
    
    /**
     * Import orders table
     */
    private static function import_orders( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';
        
        subsales_log( 'INFO', 'system', 'Starting orders import', array( 'file' => $filepath, 'update' => $update_existing ) );
        
        // Use compound key: user_id + team_id + created_at for uniqueness 
        // (order_id can be empty for PWA orders)
        $result = self::import_generic_table( $filepath, $table, array( 'user_id', 'team_id', 'created_at' ), array(
            'order_id', 'user_id', 'team_id', 'order_data', 'sync_status', 'deleted', 'deleted_at',
            'deleted_by_user_id', 'delete_reason', 'tallied', 'tallied_at', 'tallied_by_user_id',
            'address', 'address_entry_method', 'address_validation_status', 'address_validation_date',
            'address_validation_data', 'address_hash', 'created_at', 'updated_at'
        ), $update_existing );
        
        subsales_log( 'INFO', 'system', 'Orders import completed', array( 
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped']
        ) );
        
        return $result;
    }
    
    /**
     * Import teams table
     */
    private static function import_teams( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_teams';
        
        return self::import_generic_table( $filepath, $table, 'name', array(
            'name', 'access_code', 'description', 'status', 'created_at', 'updated_at'
        ), $update_existing );
    }
    
    /**
     * Import members table
     */
    private static function import_members( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_team_members';
        
        return self::import_generic_table( $filepath, $table, 'phone', array(
            'team_id', 'name', 'email', 'phone', 'role', 'status', 'last_login', 'created_at', 'updated_at'
        ), $update_existing );
    }
    
    /**
     * Import user_teams junction table
     */
    private static function import_user_teams( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_user_teams';
        
        // Use compound key: user_id + team_id
        return self::import_generic_table( $filepath, $table, array( 'user_id', 'team_id' ), array(
            'user_id', 'team_id', 'assigned_at'
        ), $update_existing );
    }
    
    /**
     * Import addresses table with geocoding support
     */
    private static function import_addresses( $filepath, $update_existing ) {
        // Use special handler for addresses due to geocoding
        $result = self::import_addresses_with_geocoding( $filepath, $update_existing );
        return $result;
    }
    
    /**
     * Import edit history table
     */
    private static function import_edit_history( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_edit_history';
        
        // Edit history doesn't really need updates, just inserts
        return self::import_generic_table( $filepath, $table, null, array(
            'order_id', 'edited_by_user_id', 'edited_by_name', 'edit_type', 'edit_reason',
            'changes_summary', 'changes_detail', 'source', 'edited_at'
        ), false ); // Never update edit history
    }
    
    /**
     * Import logs table
     */
    private static function import_logs( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_logs';
        
        // Logs are append-only
        return self::import_generic_table( $filepath, $table, null, array(
            'log_level', 'category', 'message', 'user_id', 'user_name', 'source',
            'context_json', 'created_at', 'is_debug'
        ), false );
    }
    
    /**
     * Import PWA sessions table
     */
    private static function import_pwa_sessions( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_pwa_sessions';
        
        return self::import_generic_table( $filepath, $table, 'session_id', array(
            'session_id', 'user_id', 'user_name', 'team_id', 'team_name', 'user_agent',
            'ip_address', 'login_at', 'last_heartbeat', 'logout_at', 'session_expiry',
            'session_data', 'status'
        ), $update_existing );
    }
    
    /**
     * Import PWA heartbeats table
     */
    private static function import_pwa_heartbeats( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_pwa_heartbeats';
        
        // Heartbeats are append-only
        return self::import_generic_table( $filepath, $table, null, array(
            'session_id', 'heartbeat_at', 'gps_latitude', 'gps_longitude', 'gps_accuracy', 'activity_data'
        ), false );
    }
    
    /**
     * Import campaigns table
     */
    private static function import_campaigns( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_campaigns';
        
        return self::import_generic_table( $filepath, $table, 'campaign_date', array(
            'campaign_date', 'campaign_name', 'notes', 'status', 'created_at', 'updated_at'
        ), $update_existing );
    }
    
    /**
     * Import signups table
     */
    private static function import_signups( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_signups';
        
        // Use compound key: user_id + team_id + campaign_id
        return self::import_generic_table( $filepath, $table, array( 'user_id', 'team_id', 'campaign_id' ), array(
            'user_id', 'team_id', 'campaign_id', 'is_driver', 'notes', 'status', 'created_at', 'updated_at'
        ), $update_existing );
    }
    
    /**
     * Import team campaigns table
     */
    private static function import_team_campaigns( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_team_campaigns';
        
        // Use compound key: team_id + campaign_id
        return self::import_generic_table( $filepath, $table, array( 'team_id', 'campaign_id' ), array(
            'team_id', 'campaign_id', 'driver_name', 'driver_updated_by', 'driver_updated_at', 'created_at', 'updated_at'
        ), $update_existing );
    }
    
    /**
     * Import settings
     */
    private static function import_settings( $filepath ) {
        $result = array(
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
            'geocoded' => 0,
            'zip_corrected' => 0
        );
        
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            $result['errors'][] = 'cannot_open_settings';
            return $result;
        }
        
        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            $result['errors'][] = 'invalid_settings_csv';
            return $result;
        }
        
        $map = array();
        foreach ( $header as $i => $h ) {
            $map[ strtolower( $h ) ] = $i;
        }
        
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $key = isset( $map['option_key'] ) ? $row[ $map['option_key'] ] : '';
            $value = isset( $map['option_value'] ) ? $row[ $map['option_value'] ] : '';
            
            if ( empty( $key ) ) {
                continue;
            }
            
            // Try to decode JSON if it looks like JSON
            $decoded = json_decode( $value, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $value = $decoded;
            }
            
            update_option( $key, $value );
            $result['imported']++;
        }
        
        fclose( $handle );
        return $result;
    }
    
    /**
     * Generic table import handler
     *
     * @param string $filepath Path to CSV
     * @param string $table Table name
     * @param string|array|null $unique_key Column(s) to check for duplicates
     * @param array $columns Columns to import (excluding id)
     * @param bool $update_existing Whether to update duplicates
     * @return array Statistics
     */
    private static function import_generic_table( $filepath, $table, $unique_key, $columns, $update_existing ) {
        global $wpdb;
        
        $result = array(
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
            'geocoded' => 0,
            'zip_corrected' => 0
        );
        
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            $result['errors'][] = 'cannot_open_file';
            return $result;
        }
        
        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            $result['errors'][] = 'empty_file';
            return $result;
        }
        
        // Build column map
        $map = array();
        foreach ( $header as $i => $h ) {
            $map[ strtolower( $h ) ] = $i;
        }
        
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            // Build data array
            $data = array();
            foreach ( $columns as $col ) {
                $key = strtolower( $col );
                $data[ $col ] = isset( $map[ $key ] ) && isset( $row[ $map[ $key ] ] ) ? $row[ $map[ $key ] ] : '';
            }
            
            // Check for existing record
            $existing_id = null;
            if ( $unique_key !== null ) {
                if ( is_array( $unique_key ) ) {
                    // Compound key - check all parts are present
                    $has_all_keys = true;
                    foreach ( $unique_key as $key_col ) {
                        if ( empty( $data[ $key_col ] ) ) {
                            $has_all_keys = false;
                            break;
                        }
                    }
                    
                    if ( $has_all_keys ) {
                        $where = array();
                        foreach ( $unique_key as $key_col ) {
                            $where[ $key_col ] = $data[ $key_col ];
                        }
                        $existing_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$table} WHERE " . implode( ' AND ', array_map( function( $k ) {
                                return "{$k} = %s";
                            }, array_keys( $where ) ) ),
                            array_values( $where )
                        ) );
                    }
                } else {
                    // Single key
                    if ( empty( $data[ $unique_key ] ) ) {
                        // If unique key is empty, just insert as new record
                        $existing_id = null;
                    } else {
                        $existing_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$table} WHERE {$unique_key} = %s",
                            $data[ $unique_key ]
                        ) );
                    }
                }
            }
            
            if ( $existing_id ) {
                if ( $update_existing ) {
                    $res = $wpdb->update( $table, $data, array( 'id' => $existing_id ) );
                    if ( $res !== false ) {
                        $result['updated']++;
                    } else {
                        $result['skipped']++;
                    }
                } else {
                    $result['skipped']++;
                }
            } else {
                $ins = $wpdb->insert( $table, $data );
                if ( $ins !== false ) {
                    $result['imported']++;
                } else {
                    $result['skipped']++;
                }
            }
        }
        
        fclose( $handle );
        return $result;
    }
    
    /**
     * Import addresses with geocoding support
     */
    private static function import_addresses_with_geocoding( $filepath, $update_existing ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_addresses';
        
        $result = array(
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => array(),
            'geocoded' => 0,
            'zip_corrected' => 0
        );
        
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            $result['errors'][] = 'cannot_open_addresses';
            return $result;
        }
        
        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return $result;
        }
        
        $map = array();
        foreach ( $header as $i => $h ) {
            $map[ strtolower( $h ) ] = $i;
        }
        
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $street = isset( $map['street'] ) ? $row[ $map['street'] ] : '';
            $house_number = isset( $map['house_number'] ) ? $row[ $map['house_number'] ] : '';
            $zip = isset( $map['zip'] ) ? $row[ $map['zip'] ] : '';
            
            if ( empty( $street ) || empty( $zip ) ) {
                $result['skipped']++;
                continue;
            }
            
            $data = array(
                'street' => $street,
                'house_number' => $house_number,
                'unit' => isset( $map['unit'] ) ? $row[ $map['unit'] ] : '',
                'city' => isset( $map['city'] ) ? $row[ $map['city'] ] : 'Southington',
                'state' => isset( $map['state'] ) ? $row[ $map['state'] ] : 'CT',
                'zip' => $zip,
                'lat' => isset( $map['lat'] ) ? $row[ $map['lat'] ] : '',
                'lng' => isset( $map['lng'] ) ? $row[ $map['lng'] ] : '',
                'source' => isset( $map['source'] ) ? $row[ $map['source'] ] : 'csv',
                'confidence' => isset( $map['confidence'] ) ? $row[ $map['confidence'] ] : 'medium',
                'matched' => isset( $map['matched'] ) ? $row[ $map['matched'] ] : 0,
                'type' => isset( $map['type'] ) ? $row[ $map['type'] ] : 'residential',
                'full_address' => isset( $map['full_address'] ) ? $row[ $map['full_address'] ] : '',
                'created_at' => isset( $map['created_at'] ) ? $row[ $map['created_at'] ] : current_time( 'mysql' ),
                'updated_at' => isset( $map['updated_at'] ) ? $row[ $map['updated_at'] ] : current_time( 'mysql' )
            );
            
            // Auto-geocode if missing coordinates
            if ( ( empty( $data['lat'] ) || empty( $data['lng'] ) ) && function_exists( 'order_sync_geocode_address' ) ) {
                $full_address = trim( $house_number . ' ' . $street . ', ' . $data['city'] . ', ' . $data['state'] . ' ' . $zip );
                $coords = order_sync_geocode_address( $full_address );
                if ( $coords && isset( $coords['lat'] ) && isset( $coords['lng'] ) ) {
                    $data['lat'] = $coords['lat'];
                    $data['lng'] = $coords['lng'];
                    if ( $data['source'] === 'csv' ) {
                        $data['source'] = 'geocoded';
                    }
                    $result['geocoded']++;
                }
            }
            
            // Check for existing
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE street = %s AND house_number = %s AND zip = %s",
                $street, $house_number, $zip
            ) );
            
            if ( $existing ) {
                if ( $update_existing ) {
                    $res = $wpdb->update( $table, $data, array( 'id' => $existing ) );
                    if ( $res !== false ) {
                        $result['updated']++;
                    } else {
                        $result['skipped']++;
                    }
                } else {
                    $result['skipped']++;
                }
            } else {
                $ins = $wpdb->insert( $table, $data );
                if ( $ins !== false ) {
                    $result['imported']++;
                } else {
                    $result['skipped']++;
                }
            }
        }
        
        fclose( $handle );
        return $result;
    }
    
    /**
     * Format import message for user display
     *
     * @param array $result Import statistics
     * @param bool $is_restore Whether this was a destructive restore
     * @return string HTML formatted message
     */
    private static function format_import_message( $result, $is_restore ) {
        $output = array();
        
        // Header
        $output[] = '<strong>' . ( $is_restore ? 'Restore completed' : 'Import completed' ) . '</strong>';
        
        // Summary statistics with bullet points
        $summary = array();
        if ( $result['imported'] > 0 ) {
            $summary[] = sprintf( '• <strong>%s</strong> new records imported', number_format( $result['imported'] ) );
        }
        if ( $result['updated'] > 0 ) {
            $summary[] = sprintf( '• <strong>%s</strong> existing records updated', number_format( $result['updated'] ) );
        }
        if ( $result['skipped'] > 0 ) {
            $summary[] = sprintf( '• %s records skipped (duplicates)', number_format( $result['skipped'] ) );
        }
        if ( $result['geocoded'] > 0 ) {
            $summary[] = sprintf( '• %s addresses geocoded', number_format( $result['geocoded'] ) );
        }
        if ( $result['zip_corrected'] > 0 ) {
            $summary[] = sprintf( '• %s ZIP codes corrected', number_format( $result['zip_corrected'] ) );
        }
        
        if ( ! empty( $summary ) ) {
            $output[] = implode( '<br>', $summary );
        }
        
        // Error messages
        if ( ! empty( $result['errors'] ) ) {
            $output[] = '<br><span style="color: #d63638;"><strong>Errors:</strong><br>' . implode( '<br>', $result['errors'] ) . '</span>';
        }
        
        // Per-table breakdown with indentation
        if ( ! empty( $result['byTable'] ) ) {
            $table_lines = array();
            foreach ( $result['byTable'] as $table_name => $stats ) {
                if ( $stats['imported'] > 0 || $stats['updated'] > 0 ) {
                    $table_lines[] = sprintf(
                        '&nbsp;&nbsp;• %s: %s imported, %s updated',
                        ucfirst( str_replace( '_', ' ', $table_name ) ),
                        number_format( $stats['imported'] ),
                        number_format( $stats['updated'] )
                    );
                }
            }
            if ( ! empty( $table_lines ) ) {
                $output[] = '<br><strong>Tables processed:</strong>';
                $output[] = implode( '<br>', $table_lines );
            }
        }
        
        if ( empty( $summary ) && empty( $result['byTable'] ) ) {
            return 'Import completed but no data was found in the backup file. Please check that the backup file contains valid CSV data.';
        }
        
        return implode( '<br>', $output );
    }
}

// Initialize on plugin load
Subsales_Backup_Restore::init();
