<?php
/**
 * Backup and Restore System
 *
 * Handles export/import of all plugin data with future-proof generic table handling
 *
 * @package Subsales_Management
 * @since 2.4.85
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Backup_Restore {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        // Register admin-post handlers
        add_action( 'admin_post_subsales_export_backup', array( __CLASS__, 'handle_export' ) );
        add_action( 'admin_post_subsales_import_backup', array( __CLASS__, 'handle_import' ) );
        add_action( 'admin_post_subsales_restore_backup', array( __CLASS__, 'handle_restore' ) );
        
        // Register AJAX handlers for modal-based import
        add_action( 'wp_ajax_subsales_import_ajax', array( __CLASS__, 'handle_ajax_import' ) );
    }
    
    /**
     * Handle export request
     */
    public static function handle_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        check_admin_referer( 'subsales_export_backup' );
        
        self::export_full_backup();
    }
    
    /**
     * Handle import request (merge mode)
     */
    public static function handle_import() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        check_admin_referer( 'subsales_import_backup' );
        
        subsales_log( 'INFO', 'system', 'Import started', array(
            'file' => $_FILES['backup_file']['name'] ?? 'unknown'
        ) );
        
        if ( ! isset( $_FILES['backup_file'] ) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK ) {
            wp_redirect( add_query_arg( 'subsales_import_error', 'upload_failed', wp_get_referer() ) );
            exit;
        }
        
        $tmp = $_FILES['backup_file']['tmp_name'];
        $result = self::import_file( $tmp, false ); // Merge mode - don't update existing
        
        $msg = self::format_import_message( $result, false );
        
        // Store in transient instead of URL param (preserves HTML formatting)
        set_transient( 'subsales_import_message', $msg, 60 );
        
        wp_redirect( wp_get_referer() );
        exit;
    }
    
    /**
     * Handle restore request (destructive mode)
     */
    public static function handle_restore() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        
        check_admin_referer( 'subsales_restore_backup' );
        
        if ( ! isset( $_FILES['backup_file'] ) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK ) {
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
        $result = self::import_file( $tmp, true, $restore_target );
        
        subsales_log( 'INFO', 'system', 'Import completed', array(
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => count( $result['errors'] )
        ) );
        
        $msg = self::format_import_message( $result, true );
        
        // Store in transient instead of URL param (preserves HTML formatting)
        set_transient( 'subsales_import_message', $msg, 60 );
        set_transient( 'subsales_suppress_onboarding', true, 30 );
        
        wp_redirect( wp_get_referer() );
        exit;
    }
    
    /**
     * Handle AJAX import/restore request (for modal interface)
     */
    public static function handle_ajax_import() {
        // Enable error display for troubleshooting
        @ini_set( 'display_errors', 0 );
        @ini_set( 'display_startup_errors', 0 );
        error_reporting( E_ALL );
        
        // Catch ALL errors including fatal ones
        try {
            // Check permissions
            if ( ! current_user_can( 'manage_options' ) ) {
                self::send_error_and_exit( 'Unauthorized' );
            }
        
            // Verify nonce
            if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'subsales_import_ajax' ) ) {
                self::send_error_and_exit( 'Invalid nonce' );
            }
            
            // Check file upload
            if ( ! isset( $_FILES['backup_file'] ) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK ) {
                self::send_error_and_exit( 'File upload failed: ' . ( isset( $_FILES['backup_file']['error'] ) ? $_FILES['backup_file']['error'] : 'unknown' ) );
            }
        
            $is_restore = isset( $_POST['is_restore'] ) && $_POST['is_restore'] === '1';
            $restore_target = isset( $_POST['restore_target'] ) ? sanitize_text_field( $_POST['restore_target'] ) : 'both';
            
            // CRITICAL: Set headers BEFORE any output (prevents "headers already sent" errors)
            while ( ob_get_level() ) {
                ob_end_clean();
            }
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'X-Accel-Buffering: no' );
            header( 'Cache-Control: no-cache' );
            @ini_set( 'output_buffering', 'off' );
            @ini_set( 'zlib.output_compression', 0 );
            
            // Conditionally disable compression on Apache servers
            if ( function_exists( 'apache_setenv' ) ) {
                @apache_setenv( 'no-gzip', '1' );
            }
            
            // Now we can send output - headers are already set
            echo json_encode( array(
                'type' => 'progress',
                'message' => 'Server received file, initializing...',
                'level' => 'info',
                'percent' => 21,
                'timestamp' => current_time( 'mysql' ),
                'php_start_microtime' => $php_start_time
            ) ) . "\n";
            flush();
            
            // DIAGNOSTIC: If client sent upload_complete_time, calculate gap
            if ( isset( $_POST['upload_complete_time'] ) ) {
                $upload_complete = floatval( $_POST['upload_complete_time'] ) / 1000;
                $gap_seconds = $php_start_time - $upload_complete;
                error_log( '[SUBSALES DIAGNOSTIC] Upload completed at client: ' . $upload_complete . ', PHP started: ' . $php_start_time . ', GAP: ' . round( $gap_seconds, 2 ) . ' seconds' );
            }
            
            $logs = array();
            $errors_by_table = array();
        
        // Helper function to send progress update
        $send_progress = function( $message, $level = 'info', $percent = null ) {
            $data = array(
                'type' => 'progress',
                'message' => $message,
                'level' => $level,
                'percent' => $percent,
                'timestamp' => current_time( 'mysql' )
            );
            echo json_encode( $data ) . "\n";
            if ( ob_get_level() ) {
                ob_flush();
            }
            flush();
        };
        
        // Get file info
        $filesize = isset( $_FILES['backup_file']['size'] ) ? $_FILES['backup_file']['size'] : 0;
        $filesize_mb = round( $filesize / 1024 / 1024, 2 );
        
        // Log start with file size
        $send_progress( 
            ( $is_restore ? 'Starting restore' : 'Starting import' ) . ' (' . $filesize_mb . ' MB backup file)',
            'info', 
            23 
        );
        
        // Perform clear if restore
        if ( $is_restore ) {
            try {
                if ( $restore_target === 'both' ) {
                    $send_progress( 'Clearing all data and settings...', 'warning', 30 );
                    if ( function_exists( 'order_sync_clear_data' ) ) {
                        order_sync_clear_data();
                    }
                } else if ( $restore_target === 'data' ) {
                    $send_progress( 'Clearing data only...', 'warning', 30 );
                    if ( function_exists( 'order_sync_clear_orders' ) ) {
                        order_sync_clear_orders();
                    }
                } else if ( $restore_target === 'settings' ) {
                    $send_progress( 'Clearing settings only...', 'warning', 30 );
                    if ( function_exists( 'order_sync_clear_settings' ) ) {
                        order_sync_clear_settings();
                    }
                }
                $send_progress( 'Clear operation completed', 'success', 35 );
            } catch ( Exception $e ) {
                $send_progress( 'Clear operation failed: ' . $e->getMessage(), 'error' );
                echo json_encode( array( 'type' => 'complete', 'success' => false ) ) . "\n";
                exit;
            }
        }
        
        // Process import
        $tmp = $_FILES['backup_file']['tmp_name'];
        $send_progress( 'Preparing to extract backup file...', 'info', 38 );
        
        // Create progress callback
        $table_count = 0;
        $progress_callback = function( $table_name, $stats ) use ( &$table_count, $send_progress ) {
            // Handle special messages (preparing, extracted)
            if ( isset( $stats['message'] ) ) {
                $send_progress( $stats['message'], 'info', 39 );
                return;
            }
            
            // Handle table completion
            $table_count++;
            $percent = 40 + ( $table_count *  5 ); // Start at 40%, increment by 5%
            if ( $percent > 95 ) $percent = 95; // Cap at 95%
            $message = ucfirst( str_replace( '_', ' ', $table_name ) ) . ': ' . 
                      number_format( $stats['imported'] ) . ' imported, ' . 
                      number_format( $stats['updated'] ) . ' updated';
            $send_progress( $message, 'success', $percent );
        };
        
        $result = self::import_file( $tmp, $is_restore, $is_restore ? $restore_target : null, $progress_callback );
        
        // Group errors by table
        if ( ! empty( $result['errors'] ) ) {
            foreach ( $result['errors'] as $error ) {
                // Extract table name from error message
                if ( preg_match( '/^(Insert|Update) failed for (\w+):/', $error, $matches ) ) {
                    $table = $matches[2];
                } else {
                    $table = 'General';
                }
                
                if ( ! isset( $errors_by_table[ $table ] ) ) {
                    $errors_by_table[ $table ] = array();
                }
                $errors_by_table[ $table ][] = $error;
            }
        }
        
        // Summary
        $summary_parts = array();
        if ( $result['imported'] > 0 ) {
            $summary_parts[] = number_format( $result['imported'] ) . ' new records imported';
        }
        if ( $result['updated'] > 0 ) {
            $summary_parts[] = number_format( $result['updated'] ) . ' records updated';
        }
        if ( $result['skipped'] > 0 ) {
            $summary_parts[] = number_format( $result['skipped'] ) . ' records skipped';
        }
        
        $summary = implode( ', ', $summary_parts );
        
        // Final message
        if ( ! empty( $errors_by_table ) ) {
            $send_progress( 'Import completed with ' . count( $result['errors'] ) . ' errors', 'warning', 100 );
        } else {
            $send_progress( 'Import completed successfully!', 'success', 100 );
        }
        
        // Send completion with all data
        echo json_encode( array(
            'type' => 'complete',
            'success' => true,
            'errors' => $errors_by_table,
            'summary' => $summary,
            'stats' => array(
                'imported' => $result['imported'],
                'updated' => $result['updated'],
                'skipped' => $result['skipped']
            )
        ) ) . "\n";
        exit;
        
        } catch ( Exception $e ) {
            // Catch ANY exception and report it properly
            error_log( '[SUBSALES CRITICAL ERROR] ' . $e->getMessage() );
            error_log( '[SUBSALES CRITICAL ERROR] Stack trace: ' . $e->getTraceAsString() );
            self::send_error_and_exit( 'Import failed: ' . $e->getMessage() );
        }
    }
    
    /**
     * Send error message and exit cleanly (prevents WordPress WSOD)
     */
    private static function send_error_and_exit( $message ) {
        @ob_end_clean();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo json_encode( array(
            'type' => 'error',
            'message' => $message,
            'timestamp' => current_time( 'mysql' )
        ) ) . "\n";
        exit;
    }
    
    /**
     * Export full backup with table selection
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
        
        // Create metadata
        $metadata = array(
            'export_date' => current_time( 'mysql' ),
            'plugin_version' => defined( 'SUBSALES_VERSION' ) ? SUBSALES_VERSION : 'unknown',
            'wordpress_version' => get_bloginfo( 'version' ),
            'site_url' => get_site_url(),
            'tables' => $export_counts
        );
        
        // Single file or ZIP?
        if ( count( $exported_files ) === 1 && pathinfo( $exported_files[0], PATHINFO_EXTENSION ) === 'csv' ) {
            // Single CSV - send directly
            $file = $exported_files[0];
            $filename = basename( $file );
            
            header( 'Content-Type: text/csv' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Cache-Control: no-cache, must-revalidate' );
            header( 'Expires: 0' );
            readfile( $file );
            
            @unlink( $file );
            @rmdir( $tmpdir );
            
            subsales_log( 'INFO', 'system', 'Single CSV export completed', $export_counts );
            exit;
        }
        
        // Multiple files - create ZIP
        $zipname = $tmpdir . '/subsales-backup-' . date( 'Y-m-d-His' ) . '.zip';
        $za = new ZipArchive();
        if ( $za->open( $zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            subsales_log( 'ERROR', 'system', 'Failed to create backup ZIP', array() );
            wp_die( 'Could not create backup ZIP file.' );
        }
        
        // Add all exported files to ZIP
        foreach ( $exported_files as $file ) {
            $za->addFile( $file, basename( $file ) );
        }
        
        // Add metadata
        $za->addFromString( 'BACKUP_INFO.json', wp_json_encode( $metadata, JSON_PRETTY_PRINT ) );
        $za->close();
        
        subsales_log( 'INFO', 'system', 'Backup export completed', $metadata['tables'] );
        
        // Send ZIP file
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . basename( $zipname ) . '"' );
        header( 'Content-Length: ' . filesize( $zipname ) );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Expires: 0' );
        readfile( $zipname );
        
        // Cleanup
        foreach ( $exported_files as $file ) {
            @unlink( $file );
        }
        @unlink( $zipname );
        @rmdir( $tmpdir );
        
        exit;
    }
    
    /**
     * Export a single table to CSV file (generic, auto-detects columns)
     *
     * @param string $table_key Table key (without wp_ss_ prefix)
     * @param string $output_dir Directory to save CSV
     * @return string|false Path to CSV file or false on failure
     */
    private static function export_table_to_csv( $table_key, $output_dir ) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ss_' . $table_key;
        
        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( 
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ) );
        
        if ( ! $table_exists ) {
            subsales_log( 'WARNING', 'system', 'Table does not exist for export', array( 'table' => $table_key ) );
            return false;
        }
        
        // Get all data from table (SELECT * automatically gets all columns)
        $rows = $wpdb->get_results( "SELECT * FROM {$table_name}", ARRAY_A );
        
        if ( empty( $rows ) ) {
            subsales_log( 'INFO', 'system', 'Empty table skipped', array( 'table' => $table_key ) );
            return false;
        }
        
        // Get column names from first row
        $columns = array_keys( $rows[0] );
        
        // Create CSV file
        $csv_file = $output_dir . '/' . $table_key . '.csv';
        $handle = fopen( $csv_file, 'w' );
        
        if ( ! $handle ) {
            subsales_log( 'ERROR', 'system', 'Failed to create CSV file', array( 'table' => $table_key, 'file' => $csv_file ) );
            return false;
        }
        
        // Write header
        fputcsv( $handle, $columns );
        
        // Write data rows
        foreach ( $rows as $row ) {
            fputcsv( $handle, $row );
        }
        
        fclose( $handle );
        
        subsales_log( 'INFO', 'system', 'Table exported', array( 'table' => $table_key, 'rows' => count( $rows ) ) );
        
        return $csv_file;
    }
    
    /**
     * Export settings and header image
     *
     * @param string $output_dir Directory to save files
     * @return array Array with 'csv_file', 'image_files', and 'count'
     */
    private static function export_settings( $output_dir ) {
        $result = array(
            'csv_file' => false,
            'image_files' => array(),
            'count' => 0
        );
        
        // List of all plugin settings keys
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
            'subsales_db_version'
        );
        
        // Create settings CSV
        $csv_file = $output_dir . '/settings.csv';
        $handle = fopen( $csv_file, 'w' );
        
        if ( ! $handle ) {
            subsales_log( 'ERROR', 'system', 'Failed to create settings CSV', array() );
            return $result;
        }
        
        // Write header
        fputcsv( $handle, array( 'option_key', 'option_value' ) );
        
        // Export each setting
        foreach ( $all_option_keys as $key ) {
            $value = get_option( $key );
            
            // Special handling for header image - save actual file
            if ( $key === 'subsales_header_image' && ! empty( $value ) ) {
                // Value is attachment ID, convert to file path
                $attachment_id = intval( $value );
                $image_path = get_attached_file( $attachment_id );
                
                if ( $image_path && file_exists( $image_path ) ) {
                    $image_result = self::copy_header_image_to_export( $image_path, $output_dir );
                    if ( $image_result['success'] ) {
                        $result['image_files'][] = $image_result['file'];
                        // Store relative path in CSV
                        $value = 'EXPORTED:' . $image_result['filename'];
                    }
                }
            }
            
            // Encode arrays/objects as JSON
            if ( is_array( $value ) || is_object( $value ) ) {
                $value = wp_json_encode( $value );
            }
            
            fputcsv( $handle, array( $key, $value ) );
            $result['count']++;
        }
        
        fclose( $handle );
        $result['csv_file'] = $csv_file;
        
        subsales_log( 'INFO', 'system', 'Settings exported', array( 'count' => $result['count'], 'images' => count( $result['image_files'] ) ) );
        
        return $result;
    }
    
    /**
     * Copy header image file to export directory
     *
     * @param string $source_file Absolute path to image file
     * @param string $output_dir Output directory
     * @return array Result with 'success', 'file', 'filename'
     */
    private static function copy_header_image_to_export( $source_file, $output_dir ) {
        $result = array(
            'success' => false,
            'file' => false,
            'filename' => ''
        );
        
        if ( ! file_exists( $source_file ) ) {
            subsales_log( 'WARNING', 'system', 'Header image file not found', array( 'path' => $source_file ) );
            return $result;
        }
        
        $filename = 'header-image-' . basename( $source_file );
        $dest_file = $output_dir . '/' . $filename;
        
        if ( copy( $source_file, $dest_file ) ) {
            $result = array(
                'success' => true,
                'file' => $dest_file,
                'filename' => $filename
            );
            subsales_log( 'INFO', 'system', 'Header image copied to export', array( 'file' => $filename, 'size' => filesize( $dest_file ) ) );
        } else {
            subsales_log( 'ERROR', 'system', 'Failed to copy header image', array( 'source' => $source_file, 'dest' => $dest_file ) );
        }
        
        return $result;
    }
    
    /**
     * Import file (ZIP or CSV)
     *
     * @param string $filepath Path to uploaded file
     * @param bool $update_existing Whether to update existing records
     * @param string $restore_target Filter: 'both', 'data', 'settings', or null (import all)
     * @return array Import statistics
     */
    public static function import_file( $filepath, $update_existing = false, $restore_target = null, $progress_callback = null ) {
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
            // Extract and process all CSVs (and images)
            $tmpdir = sys_get_temp_dir() . '/' . uniqid( 'subsales_import_' );
            if ( ! wp_mkdir_p( $tmpdir ) ) {
                $totals['errors'][] = 'temp_dir_failed';
                return $totals;
            }
            
            $za = new ZipArchive();
            if ( $za->open( $filepath ) === true ) {
                $num_files = $za->numFiles;
                
                // Notify about ZIP extraction (this can take time for large files)
                if ( $progress_callback && is_callable( $progress_callback ) ) {
                    call_user_func( $progress_callback, 'preparing', array(
                        'imported' => 0,
                        'updated' => 0,
                        'message' => 'Extracting backup archive (' . $num_files . ' files)...'
                    ) );
                }
                
                // FIRST: Extract all files (CSV and images)
                for ( $i = 0; $i < $za->numFiles; $i++ ) {
                    $name = $za->getNameIndex( $i );
                    $outpath = $tmpdir . '/' . basename( $name );
                    
                    // Extract CSV files and header images
                    if ( preg_match( '/\.csv$/i', $name ) || preg_match( '/^header-image-/i', basename( $name ) ) ) {
                        copy( 'zip://' . $filepath . '#' . $name, $outpath );
                    }
                }
                $za->close();
                
                // Notify extraction complete
                if ( $progress_callback && is_callable( $progress_callback ) ) {
                    call_user_func( $progress_callback, 'extracted', array(
                        'imported' => $num_files,
                        'updated' => 0,
                        'message' => 'Extracted ' . $num_files . ' files from archive'
                    ) );
                }
                
                // SECOND: Process all CSV files (now images are available)
                $csv_files = glob( $tmpdir . '/*.csv' );
                foreach ( $csv_files as $csv_file ) {
                    $result = self::process_csv_file( $csv_file, $tmpdir, $update_existing, $restore_target );
                    $totals['imported'] += $result['imported'];
                    $totals['updated'] += $result['updated'];
                    $totals['skipped'] += $result['skipped'];
                    $totals['geocoded'] += $result['geocoded'];
                    $totals['zip_corrected'] += $result['zip_corrected'];
                    $totals['errors'] = array_merge( $totals['errors'], $result['errors'] );
                    
                    // Track per-table stats
                    $table_name = basename( $csv_file, '.csv' );
                    if ( $result['imported'] > 0 || $result['updated'] > 0 ) {
                        $totals['byTable'][ $table_name ] = array(
                            'imported' => $result['imported'],
                            'updated' => $result['updated'],
                            'skipped' => $result['skipped']
                        );
                        
                        // Call progress callback if provided
                        if ( $progress_callback && is_callable( $progress_callback ) ) {
                            call_user_func( $progress_callback, $table_name, $totals['byTable'][ $table_name ] );
                        }
                    }
                }
                
                // Cleanup temp directory
                $files = glob( $tmpdir . '/*' );
                foreach ( $files as $file ) {
                    @unlink( $file );
                }
                @rmdir( $tmpdir );
            }
        } else {
            // Single CSV file
            $result = self::process_csv_file( $filepath, dirname( $filepath ), $update_existing, $restore_target );
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
     * Process a single CSV file based on filename (generic import)
     *
     * @param string $filepath Path to CSV file
     * @param string $extract_dir Directory where ZIP was extracted (for finding images)
     * @param bool $update_existing Whether to update existing records
     * @param string $restore_target Filter: 'both', 'data', 'settings', or null (import all)
     * @return array Processing statistics
     */
    private static function process_csv_file( $filepath, $extract_dir, $update_existing, $restore_target = null ) {
        global $wpdb;
        
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
        
        // Filter based on restore target
        if ( $restore_target !== null && $restore_target !== 'both' ) {
            $is_settings_file = ( $filename === 'settings' );
            $is_data_file = ! $is_settings_file;
            
            // Skip if file type doesn't match restore target
            if ( $restore_target === 'data' && $is_settings_file ) {
                subsales_log( 'INFO', 'system', 'Skipping settings file (data-only restore)', array( 'file' => $filename ) );
                return $result; // Return empty result
            }
            if ( $restore_target === 'settings' && $is_data_file ) {
                subsales_log( 'INFO', 'system', 'Skipping data file (settings-only restore)', array( 'file' => $filename ) );
                return $result; // Return empty result
            }
        }
        
        // Special handlers
        if ( $filename === 'settings' ) {
            return self::import_settings( $filepath, $extract_dir );
        }
        
        // Generic table import
        $table_name = $wpdb->prefix . 'ss_' . $filename;
        
        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( 
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            DB_NAME,
            $table_name
        ) );
        
        if ( ! $table_exists ) {
            $result['errors'][] = 'table_not_found_' . $filename;
            return $result;
        }
        
        subsales_log( 'INFO', 'system', 'Starting table import', array( 'table' => $filename ) );
        
        // Get unique key configuration for this table
        $unique_key = self::get_table_unique_key( $filename );
        
        // Open CSV
        $handle = fopen( $filepath, 'r' );
        if ( ! $handle ) {
            $result['errors'][] = 'cannot_open_file';
            return $result;
        }
        
        // Read header
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
        
        // Process each row
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            // Build data array from CSV row
            $data = array();
            foreach ( $header as $i => $col ) {
                $data[ $col ] = isset( $row[ $i ] ) ? $row[ $i ] : '';
            }
            
            // Remove 'id' column - we never import IDs (auto-increment)
            unset( $data['id'] );
            
            // Check for existing record
            $existing_id = null;
            if ( $unique_key !== null ) {
                if ( is_array( $unique_key ) ) {
                    // Compound key
                    $has_all_keys = true;
                    foreach ( $unique_key as $key_col ) {
                        if ( empty( $data[ $key_col ] ) ) {
                            $has_all_keys = false;
                            break;
                        }
                    }
                    
                    if ( $has_all_keys ) {
                        $where_sql = array();
                        $where_values = array();
                        foreach ( $unique_key as $key_col ) {
                            $where_sql[] = "{$key_col} = %s";
                            $where_values[] = $data[ $key_col ];
                        }
                        $existing_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$table_name} WHERE " . implode( ' AND ', $where_sql ),
                            $where_values
                        ) );
                    }
                } else {
                    // Single key
                    if ( ! empty( $data[ $unique_key ] ) ) {
                        $existing_id = $wpdb->get_var( $wpdb->prepare(
                            "SELECT id FROM {$table_name} WHERE {$unique_key} = %s",
                            $data[ $unique_key ]
                        ) );
                    }
                }
            }
            
            // Insert or update
            if ( $existing_id ) {
                if ( $update_existing ) {
                    $res = $wpdb->update( $table_name, $data, array( 'id' => $existing_id ) );
                    if ( $res !== false ) {
                        $result['updated']++;
                    } else {
                        $result['skipped']++;
                        if ( $wpdb->last_error ) {
                            $error_msg = sprintf( 'Update failed for %s (ID %d): %s', $filename, $existing_id, $wpdb->last_error );
                            $result['errors'][] = $error_msg;
                            subsales_log( 'ERROR', 'system', 'Database update failed', array(
                                'table' => $filename,
                                'id' => $existing_id,
                                'error' => $wpdb->last_error
                            ) );
                        }
                    }
                } else {
                    $result['skipped']++;
                }
            } else {
                $ins = $wpdb->insert( $table_name, $data );
                if ( $ins !== false ) {
                    $result['imported']++;
                } else {
                    $result['skipped']++;
                    if ( $wpdb->last_error ) {
                        $error_msg = sprintf( 'Insert failed for %s: %s', $filename, $wpdb->last_error );
                        $result['errors'][] = $error_msg;
                        subsales_log( 'ERROR', 'system', 'Database insert failed', array(
                            'table' => $filename,
                            'error' => $wpdb->last_error
                        ) );
                    }
                }
            }
        }
        
        fclose( $handle );
        
        subsales_log( 'INFO', 'system', 'Table import completed', array( 
            'table' => $filename,
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
            'errors' => count( $result['errors'] )
        ) );
        
        return $result;
    }
    
    /**
     * Get unique key configuration for a table
     *
     * @param string $table_key Table key (without prefix)
     * @return string|array|null Unique key column(s) or null for append-only
     */
    private static function get_table_unique_key( $table_key ) {
        $unique_keys = array(
            'orders' => array( 'user_id', 'team_id', 'created_at' ), // Compound key (order_id can be empty)
            'teams' => 'name',
            'team_members' => 'phone',
            'user_teams' => array( 'user_id', 'team_id' ),
            'addresses' => array( 'street', 'house_number', 'zip' ), // Physical address compound key
            'edit_history' => null, // Append-only
            'logs' => null, // Append-only
            'pwa_sessions' => 'session_id',
            'pwa_heartbeats' => null, // Append-only
            'campaigns' => 'campaign_date',
            'signups' => array( 'user_id', 'team_id', 'campaign_id' ),
            'team_campaigns' => array( 'team_id', 'campaign_id' )
        );
        
        return isset( $unique_keys[ $table_key ] ) ? $unique_keys[ $table_key ] : null;
    }
    
    /**
     * Import settings (with header image restoration)
     *
     * @param string $filepath Path to settings CSV
     * @param string $extract_dir Directory where ZIP was extracted
     * @return array Import statistics
     */
    private static function import_settings( $filepath, $extract_dir ) {
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
            
            // Special handling for header image
            if ( $key === 'subsales_header_image' ) {
                if ( strpos( $value, 'EXPORTED:' ) === 0 ) {
                    // Image file was exported - restore it
                    $image_filename = str_replace( 'EXPORTED:', '', $value );
                    $image_path = $extract_dir . '/' . $image_filename;
                    
                    if ( file_exists( $image_path ) ) {
                        // Upload to WordPress media library and get new attachment ID
                        $uploaded = self::import_header_image_file( $image_path );
                        if ( $uploaded !== false ) {
                            $value = $uploaded; // Store new attachment ID (integer)
                            subsales_log( 'INFO', 'system', 'Header image restored', array( 'attachment_id' => $value ) );
                        } else {
                            // Upload failed, clear the option
                            $value = 0;
                            subsales_log( 'WARNING', 'system', 'Header image upload failed', array( 'file' => $image_filename ) );
                        }
                    } else {
                        // Image file not found in backup
                        $value = 0;
                        subsales_log( 'WARNING', 'system', 'Header image file not found in backup', array( 'expected' => $image_filename ) );
                    }
                } else {
                    // Value is an old attachment ID from source site - clear it
                    // (attachment IDs don't transfer between sites)
                    $value = 0;
                    subsales_log( 'INFO', 'system', 'Header image not exported, clearing old attachment ID', array( 'old_id' => $value ) );
                }
            }
            
            // Try to decode JSON
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
     * Import header image file to WordPress media library
     *
     * @param string $filepath Path to image file
     * @return int|false Attachment ID of uploaded image or false on failure
     */
    private static function import_header_image_file( $filepath ) {
        if ( ! file_exists( $filepath ) ) {
            return false;
        }
        
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        
        $filename = basename( $filepath );
        $upload_dir = wp_upload_dir();
        
        // Use WordPress's unique filename function to prevent overwrites
        // If header-image.jpg exists, becomes header-image-1.jpg, etc.
        $unique_filename = wp_unique_filename( $upload_dir['path'], $filename );
        $dest = $upload_dir['path'] . '/' . $unique_filename;
        
        // Copy file to uploads with unique name
        if ( ! copy( $filepath, $dest ) ) {
            return false;
        }
        
        // Create attachment
        $filetype = wp_check_filetype( $unique_filename, null );
        $attachment = array(
            'post_mime_type' => $filetype['type'],
            'post_title' => sanitize_file_name( pathinfo( $unique_filename, PATHINFO_FILENAME ) ),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attach_id = wp_insert_attachment( $attachment, $dest );
        if ( is_wp_error( $attach_id ) ) {
            return false;
        }
        
        // Generate metadata
        $attach_data = wp_generate_attachment_metadata( $attach_id, $dest );
        wp_update_attachment_metadata( $attach_id, $attach_data );
        
        // Return attachment ID (not URL) - plugin stores ID in option
        return $attach_id;
    }
    
    /**
     * Format import result message for display
     *
     * @param array $result Import result statistics
     * @param bool $is_restore Whether this was a restore (vs import)
     * @return string HTML formatted message
     */
    private static function format_import_message( $result, $is_restore ) {
        $lines = array();
        
        // Header
        $has_errors = ! empty( $result['errors'] );
        if ( $has_errors ) {
            $lines[] = '||ERROR_MARKER||<strong>' . ( $is_restore ? 'Restore completed with errors' : 'Import completed with errors' ) . '</strong>';
        } else {
            $lines[] = '<strong>' . ( $is_restore ? 'Restore completed successfully' : 'Import completed successfully' ) . '</strong>';
        }
        $lines[] = '';
        
        // Summary statistics
        if ( $result['imported'] > 0 ) {
            $lines[] = '• ' . number_format( $result['imported'] ) . ' new records imported';
        }
        if ( $result['updated'] > 0 ) {
            $lines[] = '• ' . number_format( $result['updated'] ) . ' existing records updated';
        }
        if ( $result['skipped'] > 0 ) {
            $lines[] = '• ' . number_format( $result['skipped'] ) . ' records skipped (duplicates)';
        }
        if ( $result['geocoded'] > 0 ) {
            $lines[] = '• ' . number_format( $result['geocoded'] ) . ' addresses geocoded';
        }
        if ( $result['zip_corrected'] > 0 ) {
            $lines[] = '• ' . number_format( $result['zip_corrected'] ) . ' ZIP codes corrected';
        }
        
        // Group and display errors
        if ( ! empty( $result['errors'] ) ) {
            $error_count = count( $result['errors'] );
            $lines[] = '';
            $lines[] = '<strong>⚠ ' . $error_count . ' Error' . ( $error_count > 1 ? 's' : '' ) . ' Detected:</strong>';
            
            // Group errors by type/table
            $error_groups = array();
            foreach ( $result['errors'] as $error ) {
                if ( preg_match( '/^(Insert|Update) failed for (\w+): (.+)$/', $error, $matches ) ) {
                    $table = $matches[2];
                    $error_type = $matches[3];
                    
                    // Simplify duplicate key errors
                    if ( strpos( $error_type, 'Duplicate entry' ) !== false ) {
                        $key = $table . '_duplicate';
                        if ( ! isset( $error_groups[ $key ] ) ) {
                            $error_groups[ $key ] = array(
                                'count' => 0,
                                'message' => "Duplicate key violations in '{$table}' table"
                            );
                        }
                        $error_groups[ $key ]['count']++;
                    } else {
                        // Other errors - show uniquely
                        $key = $table . '_' . substr( md5( $error_type ), 0, 8 );
                        if ( ! isset( $error_groups[ $key ] ) ) {
                            $error_groups[ $key ] = array(
                                'count' => 0,
                                'message' => $table . ': ' . $error_type
                            );
                        }
                        $error_groups[ $key ]['count']++;
                    }
                } else {
                    // Generic error
                    $key = 'err_' . substr( md5( $error ), 0, 8 );
                    if ( ! isset( $error_groups[ $key ] ) ) {
                        $error_groups[ $key ] = array(
                            'count' => 0,
                            'message' => $error
                        );
                    }
                    $error_groups[ $key ]['count']++;
                }
            }
            
            // Show grouped errors
            $shown = 0;
            foreach ( $error_groups as $group ) {
                if ( $shown >= 5 ) break;
                $count_text = $group['count'] > 1 ? ' (' . $group['count'] . ' occurrences)' : '';
                $lines[] = '  • ' . esc_html( $group['message'] ) . $count_text;
                $shown++;
            }
            
            if ( count( $error_groups ) > 5 ) {
                $remaining = count( $error_groups ) - 5;
                $lines[] = '  ... and ' . $remaining . ' more error type' . ( $remaining > 1 ? 's' : '' ) . '. Check system logs for details.';
            }
        }
        
        // Table breakdown
        if ( ! empty( $result['byTable'] ) ) {
            $lines[] = '';
            $lines[] = '<strong>Tables processed:</strong>';
            foreach ( $result['byTable'] as $table_name => $stats ) {
                if ( $stats['imported'] > 0 || $stats['updated'] > 0 ) {
                    $lines[] = '  • ' . ucfirst( str_replace( '_', ' ', $table_name ) ) . ': ' . 
                               number_format( $stats['imported'] ) . ' imported, ' . 
                               number_format( $stats['updated'] ) . ' updated';
                }
            }
        }
        
        if ( empty( $result['imported'] ) && empty( $result['updated'] ) && empty( $result['byTable'] ) ) {
            return 'Import completed but no data was found. Please check that the backup file contains valid CSV data.';
        }
        
        return implode( "\n", $lines );
    }
}

// Initialize on plugin load
Subsales_Backup_Restore::init();
