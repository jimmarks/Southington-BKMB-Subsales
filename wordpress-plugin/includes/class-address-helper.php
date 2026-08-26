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
     * Known trailing street-suffix tokens, uppercased.
     *
     * Both spellings of each are listed so "Oak Ave", "Oak Av" and "Oak Avenue"
     * all reduce to the same base street "OAK". Southington has 18 street names
     * that differ ONLY by this token (PINE ST and PINE DR are ~1.95 miles apart
     * and in different ZIPs), so the base street is the join key and the suffix
     * is the thing we have to disambiguate.
     */
    const STREET_SUFFIXES = array(
        'ST', 'STREET',
        'DR', 'DRIVE',
        'AVE', 'AV', 'AVENUE',
        'RD', 'ROAD',
        'LN', 'LANE',
        'CT', 'COURT',
        'CIR', 'CIRCLE',
        'PL', 'PLACE',
        'BLVD',
        'TER', 'TERRACE',
        'WAY',
        'XING', 'CROSSING',
        'HWY',
        'PKWY',
        'TRL', 'TRAIL',
        'LOOP',
        'PT', 'POINT',
        'RUN',
        'ROW',
    );

    /** GPS accuracy (metres) at or above which the device's own fix is untrustworthy. */
    const ACCURACY_POOR_M = 100;

    /**
     * Uppercase, strip punctuation, collapse whitespace.
     *
     * @param string $street
     * @return string
     */
    public static function normalize_street( $street ) {
        $street = strtoupper( trim( (string) $street ) );
        $street = preg_replace( '/[^A-Z0-9 ]+/', ' ', $street );
        return trim( preg_replace( '/\s+/', ' ', $street ) );
    }

    /**
     * Trailing suffix token of a street, or '' if it has none.
     *
     * @param string $street
     * @return string
     */
    public static function street_suffix( $street ) {
        $parts = explode( ' ', self::normalize_street( $street ) );

        if ( count( $parts ) < 2 ) {
            return '';
        }

        $last = end( $parts );

        return in_array( $last, self::STREET_SUFFIXES, true ) ? $last : '';
    }

    /**
     * Street with its trailing suffix token removed ("Oak Avenue" -> "OAK").
     *
     * @param string $street
     * @return string
     */
    public static function strip_street_suffix( $street ) {
        $normalized = self::normalize_street( $street );
        $suffix     = self::street_suffix( $normalized );

        if ( '' === $suffix ) {
            return $normalized;
        }

        return trim( substr( $normalized, 0, -strlen( $suffix ) ) );
    }

    /**
     * Tolerant address lookup: returns EVERY candidate row, not just the first.
     *
     * House number is a hard exact filter; the street is matched on its
     * suffix-stripped base, so "196 Oak Ave" finds "196 OAK AVENUE" and also
     * "196 OAK ST". Returning all candidates is the point — when a house number
     * exists on both PINE ST and PINE DR the caller needs to know there were two,
     * so it can flag the ambiguity instead of silently picking one.
     *
     * Deliberately NOT filtered by ZIP: a same-name/different-suffix street
     * frequently sits in a different ZIP, and finding it is the whole reason
     * this method exists.
     *
     * @param string|array $address Address string or parsed array.
     * @param int          $limit   Max rows to pull before PHP refinement.
     * @return array List of rows (each with an added 'suffix' key). Empty if not found.
     */
    public static function lookup_in_database_fuzzy( $address, $limit = 25 ) {
        global $wpdb;

        $parsed = is_string( $address ) ? self::parse_address( $address ) : $address;

        if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
            return array();
        }

        $base = self::strip_street_suffix( $parsed['street'] );

        if ( '' === $base ) {
            return array();
        }

        $addresses_table = $wpdb->prefix . 'ss_addresses';

        // SQL pre-filters (house number exact + street starting with the stripped
        // base, which uses idx_street); PHP refines, because the stored suffix may
        // be spelled out and because the LIKE also drags in "PINEHURST DR".
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, house_number, street, unit, city, state, zip, lat, lng, full_address, source, confidence
                 FROM {$addresses_table}
                 WHERE LOWER(TRIM(house_number)) = %s
                 AND UPPER(TRIM(street)) LIKE %s
                 LIMIT %d",
                strtolower( trim( $parsed['house_number'] ) ),
                $wpdb->esc_like( $base ) . '%',
                intval( $limit )
            ),
            ARRAY_A
        );

        $candidates = array();

        foreach ( (array) $rows as $row ) {
            if ( self::strip_street_suffix( $row['street'] ) !== $base ) {
                continue; // "PINEHURST DR" matched the LIKE but is a different street.
            }

            $row['suffix'] = self::street_suffix( $row['street'] );
            $candidates[]  = $row;
        }

        return $candidates;
    }

    /**
     * Nearest known address to a GPS point.
     *
     * This is a plain local DB query on purpose, and it replaces the Google
     * reverse-geocoding that the order-entry distance report used to do per row.
     * Google API spend is being minimised across this project (non-profit
     * fundraiser), and a nearby row from wp_ss_addresses is the more useful
     * answer anyway: it is the same data the delivery manifest routes against,
     * so "the phone was 41 ft from 196 PINE DR" is directly actionable in a way
     * that a formatted Google string is not.
     *
     * @param float $lat
     * @param float $lng
     * @param float $radius_deg Bounding-box pre-filter, degrees (~0.02 = ~1.4 mi).
     * @return array|null Row with an added 'distance_feet', or null if nothing is near.
     */
    public static function nearest_address_to_point( $lat, $lng, $radius_deg = 0.02 ) {
        global $wpdb;

        $lat = floatval( $lat );
        $lng = floatval( $lng );

        $addresses_table = $wpdb->prefix . 'ss_addresses';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, house_number, street, unit, city, state, zip, lat, lng
                 FROM {$addresses_table}
                 WHERE lat BETWEEN %f AND %f
                 AND lng BETWEEN %f AND %f
                 ORDER BY ( POW( lat - %f, 2 ) + POW( lng - %f, 2 ) ) ASC
                 LIMIT 1",
                $lat - $radius_deg,
                $lat + $radius_deg,
                $lng - $radius_deg,
                $lng + $radius_deg,
                $lat,
                $lng
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return null;
        }

        $row['distance_feet'] = self::calculate_distance(
            $lat,
            $lng,
            floatval( $row['lat'] ),
            floatval( $row['lng'] ),
            'ft'
        );

        return $row;
    }

    /**
     * One-line human label for an address row.
     *
     * @param array $row Row from wp_ss_addresses.
     * @return string
     */
    public static function format_address_row( $row ) {
        if ( ! empty( $row['full_address'] ) ) {
            return $row['full_address'];
        }

        $line = trim( ( $row['house_number'] ?? '' ) . ' ' . ( $row['street'] ?? '' ) );

        if ( ! empty( $row['unit'] ) ) {
            $line .= ' #' . $row['unit'];
        }

        $tail = trim( ( $row['city'] ?? '' ) . ', ' . ( $row['state'] ?? '' ) . ' ' . ( $row['zip'] ?? '' ), ' ,' );

        return $tail ? $line . ', ' . $tail : $line;
    }

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
