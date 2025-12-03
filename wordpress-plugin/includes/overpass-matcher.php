<?php
/**
 * Overpass API Address Matcher
 * 
 * Queries OpenStreetMap's Overpass API to match addresses and assign ZIP codes.
 * Uses configured ZIP codes from WordPress settings instead of hardcoded values.
 * 
 * @package Subsales_Management
 * @since 2.0.0.26
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Overpass_Matcher {
    
    /**
     * Overpass API endpoint
     */
    const OVERPASS_API = 'https://overpass-api.de/api/interpreter';
    
    /**
     * Rate limit: minimum seconds between requests
     * Overpass allows ~1 req/sec for sustained usage
     */
    const RATE_LIMIT_SECONDS = 1;
    
    /**
     * Maximum addresses to process in one batch
     * Increased for better throughput with batch queries
     */
    const BATCH_SIZE = 100;
    
    /**
     * Number of addresses to query in a single Overpass request
     * Overpass can handle multiple queries in one request efficiently
     */
    const MULTI_QUERY_SIZE = 10;
    
    /**
     * Get configured ZIP codes from settings
     * 
     * @return array List of served ZIP codes
     */
    private static function get_served_zipcodes() {
        // Try new option first (array format)
        $zips = get_option( 'subsales_served_zipcodes', array() );
        
        // Fallback to old option (comma-separated string)
        if ( empty( $zips ) ) {
            $old_zips = get_option( 'subsales_served_zips', '' );
            if ( ! empty( $old_zips ) ) {
                $zips = is_array( $old_zips ) ? $old_zips : explode( ',', $old_zips );
                $zips = array_filter( array_map( 'trim', $zips ) );
            }
        }
        
        // Default to Southington ZIPs if nothing configured
        if ( empty( $zips ) ) {
            $zips = array( '06479', '06489', '06467' );
        }
        
        return $zips;
    }
    
    /**
     * Get bounding box for configured ZIP codes
     * Uses Google Maps Geocoding API if available, otherwise returns null
     * 
     * @return array|null [min_lat, min_lon, max_lat, max_lon] or null
     */
    private static function get_bounding_box() {
        $api_key = get_option( 'order_sync_google_maps_api_key', '' );
        
        if ( empty( $api_key ) ) {
            return null; // No API key, can't geocode
        }
        
        $zips = self::get_served_zipcodes();
        if ( empty( $zips ) ) {
            return null;
        }
        
        // Use transient to cache bounding box for 7 days
        $cache_key = 'subsales_bbox_' . md5( implode( ',', $zips ) );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }
        
        $min_lat = 90;
        $max_lat = -90;
        $min_lon = 180;
        $max_lon = -180;
        
        foreach ( $zips as $zip ) {
            $url = sprintf(
                'https://maps.googleapis.com/maps/api/geocode/json?address=%s&key=%s',
                urlencode( $zip ),
                $api_key
            );
            
            $response = wp_remote_get( $url );
            if ( is_wp_error( $response ) ) {
                continue;
            }
            
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( isset( $data['results'][0]['geometry']['bounds'] ) ) {
                $bounds = $data['results'][0]['geometry']['bounds'];
                $min_lat = min( $min_lat, $bounds['southwest']['lat'] );
                $max_lat = max( $max_lat, $bounds['northeast']['lat'] );
                $min_lon = min( $min_lon, $bounds['southwest']['lng'] );
                $max_lon = max( $max_lon, $bounds['northeast']['lng'] );
            }
        }
        
        if ( $min_lat < 90 && $max_lat > -90 ) {
            $bbox = array( $min_lat, $min_lon, $max_lat, $max_lon );
            set_transient( $cache_key, $bbox, 7 * DAY_IN_SECONDS );
            return $bbox;
        }
        
        return null;
    }
    
    /**
     * Match addresses against Overpass API
     * 
     * @param int $limit Maximum number of addresses to process per batch (0 = use BATCH_SIZE)
     * @return array Results with counts and details
     */
    public static function match_addresses( $limit = 0 ) {
        global $wpdb;
        $addresses_table = $wpdb->prefix . 'ss_addresses';
        
        // Extend execution time to prevent timeout
        @set_time_limit( 300 ); // 5 minutes
        @ini_set( 'max_execution_time', '300' );
        
        // Use BATCH_SIZE if no limit specified, or the smaller of the two
        $batch_limit = $limit > 0 ? min( $limit, self::BATCH_SIZE ) : self::BATCH_SIZE;
        
        // Get unmatched addresses (limited to batch size to prevent timeout)
        $query = "SELECT * FROM {$addresses_table} WHERE matched = 0 AND confidence != 'high' ORDER BY id ASC LIMIT " . intval( $batch_limit );
        
        $addresses = $wpdb->get_results( $query, ARRAY_A );
        
        // Count total remaining
        $total_remaining = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE matched = 0 AND confidence != 'high'" );
        
        if ( empty( $addresses ) ) {
            return array(
                'success' => true,
                'message' => 'All addresses have been processed',
                'matched' => 0,
                'failed' => 0,
                'total' => 0,
                'remaining' => 0,
                'complete' => true
            );
        }
        
        $matched_count = 0;
        $failed_count = 0;
        $processed = 0;
        
        // Process in multi-query batches for speed
        $chunks = array_chunk( $addresses, self::MULTI_QUERY_SIZE );
        
        foreach ( $chunks as $chunk_index => $chunk ) {
            // Query multiple addresses at once
            $batch_results = self::query_overpass_batch( $chunk );
            
            // Process each address in this chunk
            foreach ( $chunk as $address ) {
                $processed++;
                $overpass_data = isset( $batch_results[ $address['id'] ] ) ? $batch_results[ $address['id'] ] : false;
                
                if ( $overpass_data ) {
                // Match found - update with verified data
                $updated = $wpdb->update(
                    $addresses_table,
                    array(
                        'zip' => $overpass_data['zip'],
                        'lat' => $overpass_data['lat'] ?: $address['lat'], // Prefer OSM coordinates
                        'lng' => $overpass_data['lng'] ?: $address['lng'],
                        'matched' => 1,
                        'confidence' => 'high'
                    ),
                    array( 'id' => $address['id'] ),
                    array( '%s', '%f', '%f', '%d', '%s' ),
                    array( '%d' )
                );
                
                if ( $updated !== false ) {
                    $matched_count++;
                }
            } else {
                // No match - try geocoding fallback
                $zip_guess = self::guess_zip_from_coordinates(
                    $address['lat'],
                    $address['lng']
                );
                
                if ( $zip_guess ) {
                    // Update with guessed ZIP (medium confidence, mark as matched)
                    $wpdb->update(
                        $addresses_table,
                        array(
                            'zip' => $zip_guess,
                            'matched' => 1,
                            'confidence' => 'medium'
                        ),
                        array( 'id' => $address['id'] ),
                        array( '%s', '%d', '%s' ),
                        array( '%d' )
                    );
                    $matched_count++;
                } else {
                    // Complete failure - mark as matched but with low confidence to avoid reprocessing
                    $wpdb->update(
                        $addresses_table,
                        array(
                            'matched' => 1,
                            'confidence' => 'low'
                        ),
                        array( 'id' => $address['id'] ),
                        array( '%d', '%s' ),
                        array( '%d' )
                    );
                    $failed_count++;
                }
            }
            
            }
            
            // Rate limiting between batch queries (not per address)
            if ( $chunk_index < count( $chunks ) - 1 ) {
                sleep( self::RATE_LIMIT_SECONDS );
            }
        }
        
        // Calculate remaining after this batch
        $remaining_after = $total_remaining - $processed;
        
        return array(
            'success' => true,
            'message' => sprintf(
                'Batch complete: Processed %d addresses (%d matched, %d failed). %d remaining.',
                $processed,
                $matched_count,
                $failed_count,
                $remaining_after
            ),
            'matched' => $matched_count,
            'failed' => $failed_count,
            'total' => $processed,
            'remaining' => $remaining_after,
            'complete' => ( $remaining_after === 0 )
        );
    }
    
    /**
     * Query Overpass API for multiple addresses at once (FASTER)
     * 
     * @param array $addresses Array of address data
     * @return array Results indexed by address ID
     */
    private static function query_overpass_batch( $addresses ) {
        if ( empty( $addresses ) ) {
            return array();
        }
        
        $bbox = self::get_bounding_box();
        $bbox_str = $bbox ? sprintf( '(%s)', implode( ',', $bbox ) ) : '';
        
        // Build union query for all addresses
        $queries = array();
        foreach ( $addresses as $addr ) {
            $queries[] = sprintf(
                'node["addr:housenumber"="%s"]["addr:street"="%s"]%s;',
                self::escape_overpass( $addr['house_number'] ),
                self::escape_overpass( $addr['street'] ),
                $bbox_str
            );
            $queries[] = sprintf(
                'way["addr:housenumber"="%s"]["addr:street"="%s"]%s;',
                self::escape_overpass( $addr['house_number'] ),
                self::escape_overpass( $addr['street'] ),
                $bbox_str
            );
        }
        
        $query = '[out:json][timeout:60];(' . implode( '', $queries ) . ');out center;';
        
        $response = wp_remote_post( self::OVERPASS_API, array(
            'timeout' => 90,
            'body' => $query,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' )
        ) );
        
        if ( is_wp_error( $response ) ) {
            return array();
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( empty( $data['elements'] ) ) {
            return array();
        }
        
        // Match results back to input addresses
        $results = array();
        foreach ( $data['elements'] as $element ) {
            $house = isset( $element['tags']['addr:housenumber'] ) ? $element['tags']['addr:housenumber'] : null;
            $street = isset( $element['tags']['addr:street'] ) ? $element['tags']['addr:street'] : null;
            $zip = isset( $element['tags']['addr:postcode'] ) ? $element['tags']['addr:postcode'] : null;
            
            if ( ! $house || ! $street ) {
                continue;
            }
            
            // Find matching input address
            foreach ( $addresses as $addr ) {
                if ( strcasecmp( $addr['house_number'], $house ) === 0 && strcasecmp( $addr['street'], $street ) === 0 ) {
                    $lat = isset( $element['lat'] ) ? $element['lat'] : ( isset( $element['center']['lat'] ) ? $element['center']['lat'] : null );
                    $lng = isset( $element['lon'] ) ? $element['lon'] : ( isset( $element['center']['lon'] ) ? $element['center']['lon'] : null );
                    
                    $results[ $addr['id'] ] = array(
                        'zip' => $zip,
                        'lat' => $lat,
                        'lng' => $lng
                    );
                    break;
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Query Overpass API for a specific address (legacy single-address method)
     * 
     * @param string $house_number House number
     * @param string $street Street name
     * @param string $city City name
     * @return array|false Address data or false on failure
     */
    private static function query_overpass_address( $house_number, $street, $city ) {
        // Build Overpass query
        // Search for nodes/ways with matching address tags
        $bbox = self::get_bounding_box();
        $bbox_str = $bbox ? sprintf( '(%s)', implode( ',', $bbox ) ) : '';
        
        $query = sprintf(
            '[out:json][timeout:25];
            (
              node["addr:housenumber"="%s"]["addr:street"="%s"]%s;
              way["addr:housenumber"="%s"]["addr:street"="%s"]%s;
            );
            out center;',
            self::escape_overpass( $house_number ),
            self::escape_overpass( $street ),
            $bbox_str,
            self::escape_overpass( $house_number ),
            self::escape_overpass( $street ),
            $bbox_str
        );
        
        // Make API request
        $response = wp_remote_post( self::OVERPASS_API, array(
            'timeout' => 30,
            'body' => $query,
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded'
            )
        ) );
        
        if ( is_wp_error( $response ) ) {
            error_log( 'Overpass API error: ' . $response->get_error_message() );
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( empty( $data['elements'] ) ) {
            return false;
        }
        
        // Get first matching element
        $element = $data['elements'][0];
        
        // Extract coordinates
        $lat = null;
        $lng = null;
        
        if ( isset( $element['lat'] ) && isset( $element['lon'] ) ) {
            // Node with direct coordinates
            $lat = floatval( $element['lat'] );
            $lng = floatval( $element['lon'] );
        } elseif ( isset( $element['center'] ) ) {
            // Way with center point
            $lat = floatval( $element['center']['lat'] );
            $lng = floatval( $element['center']['lon'] );
        }
        
        // Extract ZIP code
        $zip = null;
        if ( isset( $element['tags']['addr:postcode'] ) ) {
            $zip = $element['tags']['addr:postcode'];
        }
        
        // If no ZIP in OSM, guess from coordinates
        if ( ! $zip && $lat && $lng ) {
            $zip = self::guess_zip_from_coordinates( $lat, $lng );
        }
        
        if ( ! $zip ) {
            return false;
        }
        
        return array(
            'zip' => $zip,
            'lat' => $lat,
            'lng' => $lng
        );
    }
    
    /**
     * Guess ZIP code from GPS coordinates using Google Maps Geocoding API
     * Falls back to configured ZIPs if API unavailable
     * 
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @return string|false ZIP code or false if cannot determine
     */
    private static function guess_zip_from_coordinates( $lat, $lng ) {
        if ( ! $lat || ! $lng ) {
            return false;
        }
        
        // Try Google Maps Geocoding API first
        $api_key = get_option( 'order_sync_google_maps_api_key', '' );
        if ( ! empty( $api_key ) ) {
            $url = sprintf(
                'https://maps.googleapis.com/maps/api/geocode/json?latlng=%s,%s&key=%s&result_type=postal_code',
                $lat,
                $lng,
                $api_key
            );
            
            $response = wp_remote_get( $url );
            if ( ! is_wp_error( $response ) ) {
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( isset( $data['results'][0] ) ) {
                    foreach ( $data['results'][0]['address_components'] as $component ) {
                        if ( in_array( 'postal_code', $component['types'] ) ) {
                            $zip = $component['long_name'];
                            // Verify it's one of our served ZIPs
                            $served_zips = self::get_served_zipcodes();
                            if ( in_array( $zip, $served_zips ) ) {
                                return $zip;
                            }
                        }
                    }
                }
            }
        }
        
        // Fallback: Return first configured ZIP (better than nothing)
        $served_zips = self::get_served_zipcodes();
        return ! empty( $served_zips ) ? $served_zips[0] : false;
    }
    
    /**
     * Escape string for Overpass query
     * 
     * @param string $str String to escape
     * @return string Escaped string
     */
    private static function escape_overpass( $str ) {
        // Remove quotes and backslashes
        return str_replace( array( '"', '\\' ), '', $str );
    }
    
    /**
     * Get matching statistics
     * 
     * @return array Statistics about matched addresses
     */
    public static function get_statistics() {
        global $wpdb;
        $addresses_table = $wpdb->prefix . 'ss_addresses';
        
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table}" );
        $matched = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE matched = 1" );
        $high_confidence = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE confidence = 'high'" );
        $medium_confidence = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE confidence = 'medium'" );
        $with_zip = $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE zip IS NOT NULL AND zip != ''" );
        
        $zip_breakdown = $wpdb->get_results(
            "SELECT zip, COUNT(*) as count FROM {$addresses_table} WHERE zip IS NOT NULL GROUP BY zip ORDER BY count DESC",
            ARRAY_A
        );
        
        return array(
            'total' => intval( $total ),
            'matched' => intval( $matched ),
            'high_confidence' => intval( $high_confidence ),
            'medium_confidence' => intval( $medium_confidence ),
            'with_zip' => intval( $with_zip ),
            'zip_breakdown' => $zip_breakdown
        );
    }
}
