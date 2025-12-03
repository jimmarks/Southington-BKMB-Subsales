<?php
/**
 * Shapefile Parser for Address Extraction
 * 
 * Parses ESRI Shapefiles to extract address data and GPS coordinates
 * Specifically designed for Connecticut State Plane NAD83(2011) projection
 * 
 * @package Subsales_Management
 * @since 2.0.0.21
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Shapefile_Parser {
    
    /**
     * Parse a shapefile ZIP archive and extract addresses with coordinates
     * 
     * @param string $zip_file_path Path to uploaded ZIP file
     * @return array|WP_Error Array of addresses or error
     */
    public static function parse_shapefile( $zip_file_path ) {
        // Create temporary extraction directory
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit( $upload_dir['basedir'] ) . 'subsales-temp-' . time();
        
        if ( ! wp_mkdir_p( $temp_dir ) ) {
            return new WP_Error( 'mkdir_failed', 'Could not create temporary directory' );
        }
        
        // Extract ZIP file
        $zip = new ZipArchive();
        if ( $zip->open( $zip_file_path ) !== true ) {
            self::cleanup_temp_dir( $temp_dir );
            return new WP_Error( 'zip_open_failed', 'Could not open ZIP file' );
        }
        
        $zip->extractTo( $temp_dir );
        $zip->close();
        
        // Find shapefile base name (file without extension)
        $files = scandir( $temp_dir );
        $base_name = null;
        
        foreach ( $files as $file ) {
            if ( pathinfo( $file, PATHINFO_EXTENSION ) === 'shp' ) {
                $base_name = pathinfo( $file, PATHINFO_FILENAME );
                break;
            }
        }
        
        if ( ! $base_name ) {
            self::cleanup_temp_dir( $temp_dir );
            return new WP_Error( 'no_shp_file', 'No .shp file found in ZIP archive' );
        }
        
        $dbf_file = $temp_dir . '/' . $base_name . '.dbf';
        $shp_file = $temp_dir . '/' . $base_name . '.shp';
        
        if ( ! file_exists( $dbf_file ) || ! file_exists( $shp_file ) ) {
            self::cleanup_temp_dir( $temp_dir );
            return new WP_Error( 'missing_files', 'Missing required .dbf or .shp files' );
        }
        
        // Parse DBF file for attributes
        $dbf_data = self::parse_dbf( $dbf_file );
        if ( is_wp_error( $dbf_data ) ) {
            self::cleanup_temp_dir( $temp_dir );
            return $dbf_data;
        }
        
        // Parse SHP file for geometries
        $geometries = self::parse_shp( $shp_file );
        if ( is_wp_error( $geometries ) ) {
            self::cleanup_temp_dir( $temp_dir );
            return $geometries;
        }
        
        // Combine attributes with coordinates
        $addresses = self::combine_data( $dbf_data, $geometries );
        
        // Cleanup
        self::cleanup_temp_dir( $temp_dir );
        
        return $addresses;
    }
    
    /**
     * Parse DBF file to extract address attributes
     * 
     * @param string $dbf_file Path to .dbf file
     * @return array|WP_Error Array of records or error
     */
    private static function parse_dbf( $dbf_file ) {
        if ( ! function_exists( 'dbase_open' ) ) {
            // Use manual DBF parser if dbase extension not available
            return self::parse_dbf_manual( $dbf_file );
        }
        
        $db = dbase_open( $dbf_file, 0 );
        if ( ! $db ) {
            return new WP_Error( 'dbf_open_failed', 'Could not open DBF file' );
        }
        
        $records = array();
        $num_records = dbase_numrecords( $db );
        
        for ( $i = 1; $i <= $num_records; $i++ ) {
            $record = dbase_get_record_with_names( $db, $i );
            if ( $record ) {
                unset( $record['deleted'] );
                $records[] = $record;
            }
        }
        
        dbase_close( $db );
        
        return $records;
    }
    
    /**
     * Manual DBF parser (fallback when dbase extension unavailable)
     * 
     * @param string $dbf_file Path to .dbf file
     * @return array|WP_Error Array of records or error
     */
    private static function parse_dbf_manual( $dbf_file ) {
        $fh = fopen( $dbf_file, 'rb' );
        if ( ! $fh ) {
            return new WP_Error( 'dbf_open_failed', 'Could not open DBF file' );
        }
        
        // Read DBF header
        $header = fread( $fh, 32 );
        $header_data = unpack( 'Cversion/C3date/Vnum_records/vheader_length/vrecord_length', $header );
        
        $num_records = $header_data['num_records'];
        $header_length = $header_data['header_length'];
        $record_length = $header_data['record_length'];
        
        // Read field descriptors
        $fields = array();
        fseek( $fh, 32 );
        
        while ( ftell( $fh ) < $header_length - 1 ) {
            $field_desc = fread( $fh, 32 );
            if ( strlen( $field_desc ) < 32 ) break;
            
            $field = unpack( 'a11name/Ctype/Vaddress/Clength/Cdecimals', $field_desc );
            $field['name'] = trim( str_replace( "\0", '', $field['name'] ) );
            $field['type'] = chr( $field['type'] );
            
            $fields[] = $field;
        }
        
        // Read records
        fseek( $fh, $header_length );
        $records = array();
        
        for ( $i = 0; $i < $num_records; $i++ ) {
            $record_data = fread( $fh, $record_length );
            if ( strlen( $record_data ) < $record_length ) break;
            
            // Skip deleted records (marked with '*')
            if ( $record_data[0] === '*' ) continue;
            
            $record = array();
            $offset = 1; // Skip deletion marker
            
            foreach ( $fields as $field ) {
                $value = substr( $record_data, $offset, $field['length'] );
                $value = trim( $value );
                
                // Convert numeric fields
                if ( $field['type'] === 'N' && is_numeric( $value ) ) {
                    $value = $field['decimals'] > 0 ? (float) $value : (int) $value;
                }
                
                $record[ $field['name'] ] = $value;
                $offset += $field['length'];
            }
            
            $records[] = $record;
        }
        
        fclose( $fh );
        
        return $records;
    }
    
    /**
     * Parse SHP file to extract polygon centroids
     * 
     * @param string $shp_file Path to .shp file
     * @return array|WP_Error Array of coordinates or error
     */
    private static function parse_shp( $shp_file ) {
        $fh = fopen( $shp_file, 'rb' );
        if ( ! $fh ) {
            return new WP_Error( 'shp_open_failed', 'Could not open SHP file' );
        }
        
        // Read SHP header
        fseek( $fh, 0 );
        $header = fread( $fh, 100 );
        
        // File code (big endian)
        $file_code = unpack( 'N', substr( $header, 0, 4 ) )[1];
        if ( $file_code !== 9994 ) {
            fclose( $fh );
            return new WP_Error( 'invalid_shp', 'Invalid SHP file header' );
        }
        
        // Shape type (little endian at byte 32)
        $shape_type = unpack( 'V', substr( $header, 32, 4 ) )[1];
        
        // Read all records
        $geometries = array();
        fseek( $fh, 100 ); // Skip header
        
        while ( ! feof( $fh ) ) {
            // Record header (8 bytes)
            $record_header = fread( $fh, 8 );
            if ( strlen( $record_header ) < 8 ) break;
            
            $record_num = unpack( 'N', substr( $record_header, 0, 4 ) )[1];
            $content_length = unpack( 'N', substr( $record_header, 4, 4 ) )[1];
            
            // Read record content
            $content = fread( $fh, $content_length * 2 ); // Length is in 16-bit words
            
            if ( strlen( $content ) < 4 ) break;
            
            // Extract shape type
            $record_shape_type = unpack( 'V', substr( $content, 0, 4 ) )[1];
            
            // Parse polygon (shape type 5)
            if ( $record_shape_type === 5 ) {
                $centroid = self::parse_polygon_centroid( $content );
                if ( $centroid ) {
                    $geometries[] = $centroid;
                }
            }
        }
        
        fclose( $fh );
        
        return $geometries;
    }
    
    /**
     * Parse polygon from SHP content and calculate centroid
     * 
     * @param string $content Binary content of polygon record
     * @return array|null Centroid coordinates [x, y] or null
     */
    private static function parse_polygon_centroid( $content ) {
        // Polygon structure: type(4) + bbox(32) + num_parts(4) + num_points(4) + parts + points
        if ( strlen( $content ) < 44 ) return null;
        
        $num_parts = unpack( 'V', substr( $content, 36, 4 ) )[1];
        $num_points = unpack( 'V', substr( $content, 40, 4 ) )[1];
        
        if ( $num_points === 0 ) return null;
        
        // Skip parts array and read points
        $points_offset = 44 + ( $num_parts * 4 );
        
        $sum_x = 0;
        $sum_y = 0;
        $count = 0;
        
        for ( $i = 0; $i < $num_points; $i++ ) {
            $point_offset = $points_offset + ( $i * 16 );
            if ( $point_offset + 16 > strlen( $content ) ) break;
            
            // Read X and Y as doubles (8 bytes each, little endian)
            $x_bytes = substr( $content, $point_offset, 8 );
            $y_bytes = substr( $content, $point_offset + 8, 8 );
            
            if ( strlen( $x_bytes ) < 8 || strlen( $y_bytes ) < 8 ) break;
            
            $x = unpack( 'd', $x_bytes )[1];
            $y = unpack( 'd', $y_bytes )[1];
            
            $sum_x += $x;
            $sum_y += $y;
            $count++;
        }
        
        if ( $count === 0 ) return null;
        
        // Calculate centroid (simple average)
        return array(
            'x' => $sum_x / $count,
            'y' => $sum_y / $count
        );
    }
    
    /**
     * Transform coordinates from CT State Plane (feet) to WGS84 (lat/lng)
     * 
     * Uses Proj4 parameters for NAD83(2011) State Plane Connecticut
     * 
     * @param float $x Easting in feet
     * @param float $y Northing in feet
     * @return array ['lat' => float, 'lng' => float]
     */
    private static function transform_coordinates( $x, $y ) {
        // Convert feet to meters
        $x_meters = $x * 0.3048;
        $y_meters = $y * 0.3048;
        
        // CT State Plane NAD83(2011) parameters
        $false_easting = 304800.6096; // 999999.999996 feet in meters
        $false_northing = 152400.3048; // 499999.999998 feet in meters
        $central_meridian = -72.75;
        $latitude_of_origin = 40.83333333333334;
        $standard_parallel_1 = 41.2;
        $standard_parallel_2 = 41.86666666666667;
        
        // Inverse Lambert Conformal Conic projection
        // This is a simplified approximation - for production, use proj4php library
        
        // Adjust for false easting/northing
        $x_adj = $x_meters - $false_easting;
        $y_adj = $y_meters - $false_northing;
        
        // Approximate inverse projection (suitable for small areas like Southington)
        // For more accuracy, this should use full LCC inverse formulas
        $lat_origin_rad = deg2rad( $latitude_of_origin );
        $meters_per_degree_lat = 111132.954 - 559.822 * cos( 2 * $lat_origin_rad ) + 1.175 * cos( 4 * $lat_origin_rad );
        $meters_per_degree_lng = 111132.954 * cos( $lat_origin_rad );
        
        $lat = $latitude_of_origin + ( $y_adj / $meters_per_degree_lat );
        $lng = $central_meridian + ( $x_adj / $meters_per_degree_lng );
        
        return array(
            'lat' => round( $lat, 8 ),
            'lng' => round( $lng, 8 )
        );
    }
    
    /**
     * Combine DBF attributes with SHP geometries
     * 
     * @param array $dbf_data Array of DBF records
     * @param array $geometries Array of centroids
     * @return array Array of addresses with coordinates
     */
    private static function combine_data( $dbf_data, $geometries ) {
        $addresses = array();
        $count = min( count( $dbf_data ), count( $geometries ) );
        
        for ( $i = 0; $i < $count; $i++ ) {
            $record = $dbf_data[ $i ];
            $geometry = $geometries[ $i ];
            
            // Extract address fields (adjust field names based on your shapefile)
            $house_number = isset( $record['HOUSE_NO'] ) ? trim( $record['HOUSE_NO'] ) : '';
            $street = isset( $record['STREET'] ) ? trim( $record['STREET'] ) : '';
            
            // Skip records without address data
            if ( empty( $street ) ) {
                continue;
            }
            
            // Transform coordinates
            $coords = self::transform_coordinates( $geometry['x'], $geometry['y'] );
            
            // Build full address
            $full_address = trim( $house_number . ' ' . $street );
            if ( ! empty( $full_address ) ) {
                $full_address .= ', Southington, CT';
            }
            
            $addresses[] = array(
                'street' => $street,
                'house_number' => $house_number,
                'unit' => '',
                'city' => 'Southington',
                'state' => 'CT',
                'zip' => '', // Will be assigned later via Overpass matching or default
                'lat' => $coords['lat'],
                'lng' => $coords['lng'],
                'source' => 'parcel',
                'confidence' => 'medium', // Will be upgraded to 'high' if matched with Overpass
                'matched' => false,
                'type' => 'residential', // Assume residential unless overridden
                'full_address' => $full_address
            );
        }
        
        return $addresses;
    }
    
    /**
     * Cleanup temporary directory
     * 
     * @param string $dir Directory to remove
     */
    private static function cleanup_temp_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        
        $files = array_diff( scandir( $dir ), array( '.', '..' ) );
        foreach ( $files as $file ) {
            $path = $dir . '/' . $file;
            is_dir( $path ) ? self::cleanup_temp_dir( $path ) : @unlink( $path );
        }
        
        @rmdir( $dir );
    }
}
