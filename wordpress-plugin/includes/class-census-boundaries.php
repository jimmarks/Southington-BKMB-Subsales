<?php
/**
 * Census Boundary Management
 *
 * Handles auto-download, filtering, and processing of Census ZCTA (ZIP Code Tabulation Area)
 * shapefiles. Provides both automated download from Census Bureau FTP and manual upload support.
 *
 * @package Subsales_Management
 * @since 2.2.1.142
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Census_Boundaries {
    
    /**
     * Initialize Census boundary functionality
     * Registers AJAX handlers for auto-download and configuration
     */
    public static function init() {
        add_action( 'wp_ajax_subsales_auto_download_census', array( __CLASS__, 'ajax_auto_download' ) );
        add_action( 'wp_ajax_subsales_save_census_config', array( __CLASS__, 'ajax_save_config' ) );
    }
    
    /**
     * AJAX handler for auto-downloading Census ZCTA boundaries
     * Downloads, filters, and imports Census shapefiles automatically
     */
    public static function ajax_auto_download() {
        error_log( '=== CENSUS AUTO-DOWNLOAD STARTED ===' );
        
        // Increase PHP limits for large Census Bureau files
        @ini_set( 'memory_limit', '512M' );
        @ini_set( 'max_execution_time', '600' );  // 10 minutes
        
        error_log( 'PHP limits set - memory: 512M, time: 600s' );
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            error_log( 'Census download FAILED: Permission denied' );
            wp_send_json_error( 'Permission denied', 403 );
            return;
        }
        
        error_log( 'Permission check passed' );
        
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'subsales_upload_address' ) ) {
            wp_send_json_error( 'Invalid security token. Please refresh the page and try again.', 403 );
            return;
        }
        
        // Get Census configuration
        $census_year = absint( get_option( 'subsales_census_year', 2023 ) );
        $census_state = get_option( 'subsales_census_state', '' );
        $census_zip_filter = get_option( 'subsales_census_zip_filter', '' );
        $census_url_pattern = get_option( 'subsales_census_url_pattern', 'https://www2.census.gov/geo/tiger/TIGER{year}/ZCTA520/tl_{year}_us_zcta520.zip' );
        
        error_log( 'Census config - year: ' . $census_year . ', state: ' . $census_state );
        
        // Build Census URL
        $census_url = str_replace( '{year}', $census_year, $census_url_pattern );
        
        error_log( 'Census URL: ' . $census_url );
        
        // Get configured ZIPs to filter
        $configured_zips = subsales_get_served_zips();
        if ( empty( $configured_zips ) ) {
            error_log( 'Census download FAILED: No ZIP codes configured' );
            wp_send_json_error( 'No ZIP codes configured. Please configure ZIP codes first in Settings.', 400 );
            return;
        }
        
        error_log( 'Found ' . count( $configured_zips ) . ' configured ZIPs' );
        
        // Build ZIP filter from configuration
        $zip_filter_prefixes = self::build_zip_filter_prefixes( $census_zip_filter, $configured_zips );
        
        subsales_log( 'INFO', 'zip', 'Starting Census auto-download', array(
            'url' => $census_url,
            'year' => $census_year,
            'zip_filters' => $zip_filter_prefixes
        ), 'admin' );
        
        // Clean up any old temp directories from previous failed runs
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit( $upload_dir['basedir'] );
        foreach ( glob( $base_dir . 'subsales-census-temp-*' ) as $old_temp_dir ) {
            self::cleanup_temp_dir( $old_temp_dir );
            subsales_log( 'INFO', 'zip', 'Cleaned up old temp directory: ' . basename( $old_temp_dir ), array(), 'admin' );
        }
        
        // Create temporary directory
        $temp_dir = $base_dir . 'subsales-census-temp-' . time();
        
        if ( ! wp_mkdir_p( $temp_dir ) ) {
            wp_send_json_error( 'Could not create temporary directory', 500 );
            return;
        }
        
        // Register shutdown handler to cleanup on fatal errors (timeout, memory limit, etc.)
        register_shutdown_function( function() use ( $temp_dir ) {
            self::cleanup_temp_dir( $temp_dir );
        } );
        
        // Download Census file
        $temp_zip = $temp_dir . '/census.zip';
        
        error_log( 'Starting download from: ' . $census_url );
        error_log( 'Temp file: ' . $temp_zip );
        
        $download_result = self::download_census_file( $census_url, $temp_zip );
        
        if ( is_wp_error( $download_result ) ) {
            error_log( 'Download FAILED: ' . $download_result->get_error_message() );
            self::cleanup_temp_dir( $temp_dir );
            wp_send_json_error( 'Download failed: ' . $download_result->get_error_message(), 500 );
            return;
        }
        
        $file_size = file_exists( $temp_zip ) ? filesize( $temp_zip ) : 0;
        error_log( 'Download SUCCESS - file size: ' . round( $file_size / 1024 / 1024, 2 ) . ' MB' );
        
        // Extract and filter shapefile
        error_log( 'Starting extraction and filtering...' );
        
        $result = self::extract_and_filter_census( $temp_zip, $temp_dir, $zip_filter_prefixes, $configured_zips );
        
        // Cleanup
        self::cleanup_temp_dir( $temp_dir );
        
        if ( is_wp_error( $result ) ) {
            error_log( 'Processing FAILED: ' . $result->get_error_message() );
            wp_send_json_error( $result->get_error_message(), 500 );
            return;
        }
        
        error_log( 'Census download SUCCESS - ' . $result['zip_count'] . ' ZIPs loaded' );
        error_log( '=== CENSUS AUTO-DOWNLOAD COMPLETE ===' );
        
        // Log success
        subsales_log( 'INFO', 'zip', 'Census boundaries auto-downloaded successfully', array(
            'zip_count' => $result['zip_count'],
            'zips' => $result['zips']
        ), 'admin' );
        
        wp_send_json_success( $result );
    }
    
    /**
     * AJAX handler for saving Census configuration
     * Saves year, state, ZIP filter, and URL pattern settings
     */
    public static function ajax_save_config() {
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied', 403 );
            return;
        }
        
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'subsales_upload_address' ) ) {
            wp_send_json_error( 'Invalid security token', 403 );
            return;
        }
        
        $census_year = isset( $_POST['census_year'] ) ? absint( $_POST['census_year'] ) : 2023;
        $census_state = isset( $_POST['census_state'] ) ? sanitize_text_field( $_POST['census_state'] ) : '';
        $census_zip_filter = isset( $_POST['census_zip_filter'] ) ? sanitize_text_field( $_POST['census_zip_filter'] ) : '';
        $census_url_pattern = isset( $_POST['census_url_pattern'] ) ? esc_url_raw( $_POST['census_url_pattern'] ) : '';
        
        // Validate year (Census data typically lags 1-2 years)
        if ( $census_year < 2010 || $census_year > date( 'Y' ) - 1 ) {
            $census_year = 2023;
        }
        
        // Default URL pattern if empty
        if ( empty( $census_url_pattern ) ) {
            $census_url_pattern = 'https://www2.census.gov/geo/tiger/TIGER{year}/ZCTA520/tl_{year}_us_zcta520.zip';
        }
        
        update_option( 'subsales_census_year', $census_year );
        update_option( 'subsales_census_state', $census_state );
        update_option( 'subsales_census_zip_filter', $census_zip_filter );
        update_option( 'subsales_census_url_pattern', $census_url_pattern );
        
        subsales_log( 'INFO', 'system', 'Census configuration saved', array(
            'year' => $census_year,
            'state' => $census_state,
            'zip_filter' => $census_zip_filter
        ), 'admin' );
        
        wp_send_json_success( array( 'message' => 'Configuration saved successfully' ) );
    }
    
    /**
     * Build ZIP filter prefixes from configuration
     * Auto-detects from configured ZIPs if filter is empty
     *
     * @param string $census_zip_filter Configured ZIP filter (comma-separated prefixes)
     * @param array $configured_zips Array of configured ZIP codes
     * @return array Array of ZIP prefixes to filter by
     */
    private static function build_zip_filter_prefixes( $census_zip_filter, $configured_zips ) {
        $zip_filter_prefixes = array();
        
        if ( ! empty( $census_zip_filter ) ) {
            $filters = preg_split( '/[,\s]+/', $census_zip_filter, -1, PREG_SPLIT_NO_EMPTY );
            foreach ( $filters as $filter ) {
                $zip_filter_prefixes[] = trim( $filter );
            }
        } else {
            // Auto-detect prefixes from configured ZIPs
            foreach ( $configured_zips as $zip ) {
                $prefix = substr( $zip, 0, 2 );  // First 2 digits
                if ( ! in_array( $prefix, $zip_filter_prefixes ) ) {
                    $zip_filter_prefixes[] = $prefix;
                }
            }
        }
        
        return $zip_filter_prefixes;
    }
    
    /**
     * Download Census file from URL
     * Uses WordPress HTTP API with streaming to handle large files
     *
     * @param string $url Census file URL
     * @param string $dest_path Destination file path
     * @return true|WP_Error True on success, WP_Error on failure
     */
    private static function download_census_file( $url, $dest_path ) {
        // Use WordPress HTTP API for reliable downloads
        // Disable caching to prevent 404 responses from being cached
        $response = wp_remote_get( $url, array(
            'timeout' => 600,  // 10 minutes
            'stream' => true,
            'filename' => $dest_path,
            'sslverify' => true,
            'headers' => array(
                'Cache-Control' => 'no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0'
            )
        ) );
        
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'download_failed', 'Failed to download: ' . $response->get_error_message() );
        }
        
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            return new WP_Error( 'download_failed', 'HTTP Error ' . $response_code . ': File not found on Census server. Check the year and URL.' );
        }
        
        if ( ! file_exists( $dest_path ) || filesize( $dest_path ) === 0 ) {
            return new WP_Error( 'download_failed', 'Downloaded file is empty or missing' );
        }
        
        return true;
    }
    
    /**
     * Extract Census ZIP and filter to relevant ZIPs only
     *
     * @param string $zip_path Path to downloaded ZIP file
     * @param string $temp_dir Temporary directory for extraction
     * @param array $filter_prefixes ZIP prefixes to filter by
     * @param array $configured_zips Configured ZIP codes
     * @return array|WP_Error Result array with statistics or WP_Error on failure
     */
    private static function extract_and_filter_census( $zip_path, $temp_dir, $filter_prefixes, $configured_zips ) {
        $zip = new ZipArchive();
        if ( $zip->open( $zip_path ) !== true ) {
            return new WP_Error( 'zip_open_failed', 'Could not open downloaded ZIP file' );
        }
        
        $zip->extractTo( $temp_dir );
        $zip->close();
        
        // Find shapefile
        $files = scandir( $temp_dir );
        $base_name = null;
        
        foreach ( $files as $file ) {
            if ( pathinfo( $file, PATHINFO_EXTENSION ) === 'shp' ) {
                $base_name = pathinfo( $file, PATHINFO_FILENAME );
                break;
            }
        }
        
        if ( ! $base_name ) {
            return new WP_Error( 'no_shapefile', 'No shapefile found in downloaded ZIP archive' );
        }
        
        // Process shapefile with filtering
        $shp_file = $temp_dir . '/' . $base_name . '.shp';
        $dbf_file = $temp_dir . '/' . $base_name . '.dbf';
        
        if ( ! file_exists( $dbf_file ) || ! file_exists( $shp_file ) ) {
            return new WP_Error( 'missing_files', 'Shapefile components missing (.shp or .dbf)' );
        }
        
        // Parse with filtering
        return self::parse_filtered_zcta_shapefile( $shp_file, $dbf_file, $filter_prefixes, $configured_zips );
    }
    
    /**
     * Parse ZCTA shapefile with filtering during read (memory-efficient)
     *
     * @param string $shp_file Path to .shp file
     * @param string $dbf_file Path to .dbf file
     * @param array $filter_prefixes ZIP prefixes to filter by
     * @param array $configured_zips Configured ZIP codes
     * @return array|WP_Error Result array with statistics or WP_Error on failure
     */
    private static function parse_filtered_zcta_shapefile( $shp_file, $dbf_file, $filter_prefixes, $configured_zips ) {
        $dbf = dbase_open( $dbf_file, 0 );
        if ( ! $dbf ) {
            return new WP_Error( 'dbf_open_failed', 'Could not open shapefile database (.dbf)' );
        }
        
        $record_count = dbase_numrecords( $dbf );
        $zip_polygons = array();
        $loaded_zips = array();
        $skipped = 0;
        
        for ( $i = 1; $i <= $record_count; $i++ ) {
            $record = dbase_get_record_with_names( $dbf, $i );
            if ( ! $record ) continue;
            
            // Get ZCTA code (ZIP)
            $zcta_code = isset( $record['ZCTA5CE20'] ) ? trim( $record['ZCTA5CE20'] ) : 
                         ( isset( $record['ZCTA5CE10'] ) ? trim( $record['ZCTA5CE10'] ) : 
                         ( isset( $record['ZCTA'] ) ? trim( $record['ZCTA'] ) : '' ) );
            
            if ( empty( $zcta_code ) ) {
                $skipped++;
                continue;
            }
            
            // Apply filter: check prefixes
            $matches_filter = false;
            foreach ( $filter_prefixes as $prefix ) {
                if ( substr( $zcta_code, 0, strlen( $prefix ) ) === $prefix ) {
                    $matches_filter = true;
                    break;
                }
            }
            
            if ( ! $matches_filter ) {
                $skipped++;
                continue;  // Skip ZIPs that don't match filter
            }
            
            // Only save if it's in configured ZIPs
            if ( in_array( $zcta_code, $configured_zips ) ) {
                // Read geometry from .shp file (simplified - just store bounds)
                $bounds = self::read_shp_record_bounds( $shp_file, $i );
                if ( $bounds ) {
                    $zip_polygons[ $zcta_code ] = array(
                        'bounds' => $bounds,
                        'center' => array(
                            'lat' => ( $bounds['min_lat'] + $bounds['max_lat'] ) / 2,
                            'lng' => ( $bounds['min_lng'] + $bounds['max_lng'] ) / 2
                        )
                    );
                    $loaded_zips[] = $zcta_code;
                }
            }
        }
        
        dbase_close( $dbf );
        
        if ( empty( $zip_polygons ) ) {
            return new WP_Error( 'no_zips_found', 'No matching ZIP codes found in Census data. Check your ZIP filter configuration.' );
        }
        
        // Save to options
        update_option( 'subsales_zip_polygons', $zip_polygons );
        
        // Find missing ZIPs
        $missing_zips = array_diff( $configured_zips, $loaded_zips );
        
        return array(
            'zip_count' => count( $loaded_zips ),
            'zips' => $loaded_zips,
            'missing_zips' => array_values( $missing_zips ),
            'skipped' => $skipped,
            'message' => 'Successfully loaded ' . count( $loaded_zips ) . ' ZIP boundary polygons'
        );
    }
    
    /**
     * Read bounding box from SHP file for a specific record (simplified geometry)
     *
     * @param string $shp_file Path to .shp file
     * @param int $record_num Record number to read
     * @return array|null Array with min/max lat/lng or null on failure
     */
    private static function read_shp_record_bounds( $shp_file, $record_num ) {
        $fp = fopen( $shp_file, 'rb' );
        if ( ! $fp ) return null;
        
        // Read SHP header (100 bytes)
        fseek( $fp, 0 );
        $header = fread( $fp, 100 );
        
        // Skip to records (after 100-byte header)
        fseek( $fp, 100 );
        
        // Find the specific record (simplified - assumes sequential)
        $current_record = 1;
        while ( ! feof( $fp ) && $current_record <= $record_num ) {
            // Record header: 8 bytes (record number + content length)
            $rec_header = fread( $fp, 8 );
            if ( strlen( $rec_header ) < 8 ) break;
            
            $rec_data = unpack( 'Nrec_num/Nrec_len', $rec_header );
            $content_length = $rec_data['rec_len'] * 2;  // Words to bytes
            
            if ( $current_record === $record_num ) {
                // Read shape type and bounds
                $shape_type = unpack( 'V', fread( $fp, 4 ) )[1];
                
                if ( $shape_type === 5 || $shape_type === 15 || $shape_type === 25 ) {  // Polygon types
                    $bounds = unpack( 'dmin_lng/dmin_lat/dmax_lng/dmax_lat', fread( $fp, 32 ) );
                    fclose( $fp );
                    return $bounds;
                }
            }
            
            // Skip to next record
            fseek( $fp, ftell( $fp ) + $content_length - 4 );  // -4 because we read shape type
            $current_record++;
        }
        
        fclose( $fp );
        return null;
    }
    
    /**
     * Cleanup temporary directory recursively
     *
     * @param string $dir Directory path to clean up
     */
    private static function cleanup_temp_dir( $dir ) {
        if ( ! file_exists( $dir ) ) return;
        
        try {
            $files = @scandir( $dir );
            if ( $files === false ) return;
            
            $files = array_diff( $files, array( '.', '..' ) );
            foreach ( $files as $file ) {
                $path = $dir . '/' . $file;
                if ( is_dir( $path ) ) {
                    self::cleanup_temp_dir( $path );
                } else {
                    @unlink( $path );
                }
            }
            @rmdir( $dir );
        } catch ( Exception $e ) {
            // Suppress cleanup errors - not critical
            subsales_log( 'WARNING', 'zip', 'Failed to cleanup temp directory: ' . $e->getMessage(), array( 'dir' => $dir ), 'system' );
        }
    }
}
