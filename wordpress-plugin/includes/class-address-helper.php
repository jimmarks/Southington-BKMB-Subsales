<?php
/**
 * Address Helper Class
 * 
 * Consolidated address validation, lookup, and GPS operations
 * Use this across all reports for consistent address handling
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Address_Helper {
    
    /**
     * Parse an address into components (house_number, street, city, state, zip, unit)
     * 
     * @param string $address Full address string
     * @return array|null Parsed components or null if parsing fails
     */
    public static function parse_address( $address ) {
        return Subsales_Delivery::parse_address( $address );
    }
    
    /**
     * Look up an address in wp_ss_addresses database
     * 
     * @param string|array $address Address string or parsed array
     * @param bool $include_coords Whether to include lat/lng in results (default true)
     * @return array|null Database row if found, null if not found
     */
    public static function lookup_in_database( $address, $include_coords = true ) {
        global $wpdb;
        
        // Parse if string provided
        if ( is_string( $address ) ) {
            $parsed = self::parse_address( $address );
        } else {
            $parsed = $address;
        }
        
        // Must have house number and street
        if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
            return null;
        }
        
        $addresses_table = $wpdb->prefix . 'ss_addresses';
        
        // Build query
        $select = $include_coords 
            ? "SELECT id, house_number, street, unit, city, state, zip, lat, lng, full_address, source, confidence"
            : "SELECT id, house_number, street, unit, city, state, zip, full_address, source, confidence";
        
        $query = "{$select} FROM {$addresses_table}
                  WHERE LOWER(TRIM(street)) = %s
                  AND LOWER(TRIM(house_number)) = %s";
        
        $params = array(
            strtolower( trim( $parsed['street'] ) ),
            strtolower( trim( $parsed['house_number'] ) )
        );
        
        // Add ZIP if available
        if ( ! empty( $parsed['zip'] ) ) {
            $query .= " AND zip = %s";
            $params[] = $parsed['zip'];
        }
        
        $query .= " LIMIT 1";
        
        return $wpdb->get_row( $wpdb->prepare( $query, $params ), ARRAY_A );
    }
    
    /**
     * Extract GPS coordinates from order entry location
     * This is where the order was ENTERED (seller's location)
     * 
     * @param array $order Order row from database
     * @return array|null ['lat' => float, 'lng' => float, 'accuracy' => float|null] or null
     */
    public static function get_order_entry_gps( $order ) {
        $order_data = is_string( $order['order_data'] ) 
            ? json_decode( $order['order_data'], true )
            : $order['order_data'];
        
        if ( ! $order_data || ! isset( $order_data['geo'] ) ) {
            return null;
        }
        
        $geo = $order_data['geo'];
        
        if ( empty( $geo['latitude'] ) || empty( $geo['longitude'] ) ) {
            return null;
        }
        
        return array(
            'lat' => floatval( $geo['latitude'] ),
            'lng' => floatval( $geo['longitude'] ),
            'accuracy' => isset( $geo['accuracy'] ) ? floatval( $geo['accuracy'] ) : null
        );
    }
    
    /**
     * Extract geocoded coordinates from validation data
     * This is the DELIVERY address location (from Google geocoding)
     * 
     * @param array $order Order row from database
     * @return array|null ['lat' => float, 'lng' => float] or null
     */
    public static function get_geocoded_delivery_gps( $order ) {
        if ( empty( $order['address_validation_data'] ) ) {
            return null;
        }
        
        $validation_data = is_string( $order['address_validation_data'] )
            ? json_decode( $order['address_validation_data'], true )
            : $order['address_validation_data'];
        
        if ( ! $validation_data || empty( $validation_data['coordinates'] ) ) {
            return null;
        }
        
        $coords = $validation_data['coordinates'];
        
        if ( empty( $coords['lat'] ) || empty( $coords['lng'] ) ) {
            return null;
        }
        
        return array(
            'lat' => floatval( $coords['lat'] ),
            'lng' => floatval( $coords['lng'] )
        );
    }
    
    /**
     * Get delivery address GPS from wp_ss_addresses database
     * 
     * @param string|array $address Address string or parsed array
     * @return array|null ['lat' => float, 'lng' => float] or null
     */
    public static function get_database_delivery_gps( $address ) {
        $db_row = self::lookup_in_database( $address, true );
        
        if ( ! $db_row || empty( $db_row['lat'] ) || empty( $db_row['lng'] ) ) {
            return null;
        }
        
        return array(
            'lat' => floatval( $db_row['lat'] ),
            'lng' => floatval( $db_row['lng'] )
        );
    }
    
    /**
     * Calculate distance between two GPS points using Haversine formula
     * 
     * @param float $lat1 Latitude of point 1
     * @param float $lng1 Longitude of point 1
     * @param float $lat2 Latitude of point 2
     * @param float $lng2 Longitude of point 2
     * @param string $unit 'mi' for miles (default), 'km' for kilometers, 'ft' for feet
     * @return float Distance in specified units
     */
    public static function calculate_distance( $lat1, $lng1, $lat2, $lng2, $unit = 'mi' ) {
        $earth_radius_mi = 3959;
        $earth_radius_km = 6371;
        
        $lat_diff = deg2rad( $lat2 - $lat1 );
        $lng_diff = deg2rad( $lng2 - $lng1 );
        
        $a = sin( $lat_diff / 2 ) * sin( $lat_diff / 2 ) +
             cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) *
             sin( $lng_diff / 2 ) * sin( $lng_diff / 2 );
        
        $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );
        
        if ( $unit === 'km' ) {
            return $earth_radius_km * $c;
        } elseif ( $unit === 'ft' ) {
            return $earth_radius_mi * $c * 5280;
        } else {
            return $earth_radius_mi * $c;
        }
    }
    
    /**
     * Get comprehensive address status for an order
     * Returns all relevant info about address validation, GPS, and database presence
     * 
     * @param array $order Order row from database
     * @return array Comprehensive address status
     */
    public static function get_order_address_status( $order ) {
        $address = $order['address'];
        $parsed = self::parse_address( $address );
        
        $status = array(
            'address' => $address,
            'parsed' => $parsed,
            'parse_success' => ! empty( $parsed ) && ! empty( $parsed['house_number'] ) && ! empty( $parsed['street'] ),
            'in_database' => false,
            'db_record' => null,
            'entry_gps' => self::get_order_entry_gps( $order ),
            'geocoded_gps' => self::get_geocoded_delivery_gps( $order ),
            'database_gps' => null,
            'validation_status' => $order['address_validation_status'] ?? null,
            'needs_geocoding' => false,
            'needs_approval' => false,
            'has_coordinates' => false
        );
        
        // Check database
        if ( $status['parse_success'] ) {
            $db_record = self::lookup_in_database( $parsed, true );
            if ( $db_record ) {
                $status['in_database'] = true;
                $status['db_record'] = $db_record;
                $status['database_gps'] = array(
                    'lat' => floatval( $db_record['lat'] ),
                    'lng' => floatval( $db_record['lng'] )
                );
            }
        }
        
        // Determine what actions are needed
        $status['has_coordinates'] = ! empty( $status['geocoded_gps'] ) || ! empty( $status['database_gps'] );
        $status['needs_geocoding'] = $status['parse_success'] && ! $status['has_coordinates'];
        $status['needs_approval'] = $status['parse_success'] 
            && ! empty( $status['geocoded_gps'] )
            && ! $status['in_database']
            && $status['validation_status'] !== 'dismissed';
        
        return $status;
    }
    
    /**
     * Calculate distance between order entry location and delivery address
     * 
     * @param array $order Order row from database
     * @param string $unit 'mi' for miles (default), 'km' for kilometers, 'ft' for feet
     * @return array|null ['distance' => float, 'entry' => array, 'delivery' => array] or null
     */
    public static function calculate_order_entry_distance( $order, $unit = 'mi' ) {
        $entry_gps = self::get_order_entry_gps( $order );
        
        if ( ! $entry_gps ) {
            return null;
        }
        
        // Try database first, then geocoded coordinates
        $delivery_gps = self::get_database_delivery_gps( $order['address'] );
        
        if ( ! $delivery_gps ) {
            $delivery_gps = self::get_geocoded_delivery_gps( $order );
        }
        
        if ( ! $delivery_gps ) {
            return null;
        }
        
        $distance = self::calculate_distance(
            $entry_gps['lat'],
            $entry_gps['lng'],
            $delivery_gps['lat'],
            $delivery_gps['lng'],
            $unit
        );
        
        return array(
            'distance' => $distance,
            'unit' => $unit,
            'entry_gps' => $entry_gps,
            'delivery_gps' => $delivery_gps
        );
    }
    
    /**
     * Check if address is in database (simple boolean check)
     * 
     * @param string|array $address Address string or parsed array
     * @return bool True if address exists in database
     */
    public static function is_in_database( $address ) {
        return self::lookup_in_database( $address, false ) !== null;
    }
    
    /**
     * Format distance for display with appropriate units
     * 
     * @param float $distance Distance value
     * @param string $unit 'mi', 'km', or 'ft'
     * @param int $decimals Number of decimal places
     * @return string Formatted distance string
     */
    public static function format_distance( $distance, $unit = 'mi', $decimals = 2 ) {
        if ( $unit === 'ft' ) {
            return number_format( $distance, 0 ) . ' ft';
        } elseif ( $unit === 'km' ) {
            return number_format( $distance, $decimals ) . ' km';
        } else {
            return number_format( $distance, $decimals ) . ' mi';
        }
    }
    
    /**
     * Get distance category for classification
     * 
     * @param float $distance_feet Distance in feet
     * @return string Category name: 'on-site', 'very-close', 'nearby', 'local', 'remote'
     */
    public static function get_distance_category( $distance_feet ) {
        if ( $distance_feet < 50 ) {
            return 'on-site';
        } elseif ( $distance_feet < 200 ) {
            return 'very-close';
        } elseif ( $distance_feet < 500 ) {
            return 'nearby';
        } elseif ( $distance_feet < 2640 ) { // 0.5 miles
            return 'local';
        } else {
            return 'remote';
        }
    }
}
