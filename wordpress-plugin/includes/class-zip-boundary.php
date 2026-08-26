<?php
/**
 * ZIP Boundary Class
 *
 * Real point-in-polygon ZIP assignment for the address pipeline.
 *
 * This replaces the old bounding-box "first match wins" approach, which filed
 * every address on a boundary street (all ~130 on Buckland St) under the wrong
 * ZIP. Two rules keep that from happening again:
 *
 * 1. Boundaries are fetched fresh per run and held in memory only. They are
 *    NEVER cached in wp_options - two code paths writing incompatible schemas
 *    into one shared option is what broke the original implementation.
 * 2. There is no default/first/nearest-ZIP fallback anywhere in this file. A
 *    point that isn't unambiguously inside exactly one ZIP returns null and the
 *    caller queues it for review. Silently guessing is the bug being removed.
 *
 * @package Subsales_Management
 * @since 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Zip_Boundary {

    /**
     * Census TIGERweb layer 2 = "2020 Census ZIP Code Tabulation Areas".
     * Public, no API key, returns real polygon rings (748 vertices for 06489).
     */
    const ZCTA_SERVICE_URL = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer/2/query';

    /**
     * Fetch ZCTA boundary polygons for the given ZIP codes.
     *
     * Requests outSR=4326 so the service returns WGS84 lat/lng directly - there
     * is deliberately no reprojection code in this plugin.
     *
     * @param array $zips ZIP codes to fetch (e.g. array( '06489', '06479' ))
     * @return array [ '06489' => [ 'bbox' => [...], 'rings' => [[[lng,lat],...],...] ], ... ]
     *               Empty array on failure. ZIPs the service doesn't know are simply absent.
     * @since 3.2.0
     */
    public static function fetch_boundaries( array $zips ) {
        // Only ever query digits - these go straight into a WHERE clause.
        $clean = array();
        foreach ( $zips as $zip ) {
            $zip = preg_replace( '/[^0-9]/', '', (string) $zip );
            if ( strlen( $zip ) === 5 && ! in_array( $zip, $clean, true ) ) {
                $clean[] = $zip;
            }
        }
        $zips = $clean;

        if ( empty( $zips ) ) {
            subsales_log( 'WARNING', 'zip', 'ZCTA boundary fetch skipped: no valid 5-digit ZIPs supplied' );
            return array();
        }

        $where = "GEOID IN ('" . implode( "','", $zips ) . "')";

        $url = self::ZCTA_SERVICE_URL . '?' . http_build_query( array(
            'where'          => $where,
            'outFields'      => 'GEOID,NAME',
            'returnGeometry' => 'true',
            'outSR'          => '4326',
            'f'              => 'json',
        ) );

        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );

        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'zip', 'ZCTA boundary request failed: ' . $response->get_error_message(), array(
                'zips' => $zips
            ) );
            return array();
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data['error'] ) ) {
            subsales_log( 'ERROR', 'zip', 'ZCTA boundary service returned an error', array(
                'zips'  => $zips,
                'error' => $data['error']
            ) );
            return array();
        }

        if ( empty( $data['features'] ) || ! is_array( $data['features'] ) ) {
            subsales_log( 'ERROR', 'zip', 'ZCTA boundary service returned no features', array( 'zips' => $zips ) );
            return array();
        }

        $boundaries = array();

        foreach ( $data['features'] as $feature ) {
            $geoid = isset( $feature['attributes']['GEOID'] ) ? trim( $feature['attributes']['GEOID'] ) : '';
            $rings = isset( $feature['geometry']['rings'] ) ? $feature['geometry']['rings'] : array();

            if ( empty( $geoid ) || empty( $rings ) || ! is_array( $rings ) ) {
                continue;
            }

            $bbox = self::bbox_of_rings( $rings );
            if ( null === $bbox ) {
                continue;
            }

            $boundaries[ $geoid ] = array(
                'bbox'  => $bbox,
                'rings' => $rings
            );
        }

        $missing = array_diff( $zips, array_keys( $boundaries ) );
        if ( ! empty( $missing ) ) {
            // Not fatal - the caller just can't resolve anything into those ZIPs,
            // so those parcels go to the review queue instead of being guessed.
            subsales_log( 'WARNING', 'zip', 'ZCTA boundary service had no polygon for some ZIPs', array(
                'missing' => array_values( $missing )
            ) );
        }

        subsales_log( 'INFO', 'zip', 'ZCTA boundaries fetched', array(
            'zips'   => array_keys( $boundaries ),
            'points' => array_map( function( $b ) {
                return array_sum( array_map( 'count', $b['rings'] ) );
            }, $boundaries )
        ) );

        return $boundaries;
    }

    /**
     * Even-odd ray casting across every ring of a polygon.
     *
     * Crossings are counted across all rings together, so donut holes fall out
     * naturally: a point inside a hole crosses both the outer and inner ring and
     * ends up even, i.e. outside.
     *
     * @param float $lat Latitude of the test point
     * @param float $lng Longitude of the test point
     * @param array $rings Array of rings, each an array of [lng, lat] pairs
     * @return bool True if the point is inside the polygon
     * @since 3.2.0
     */
    public static function point_in_rings( $lat, $lng, $rings ) {
        if ( empty( $rings ) || ! is_array( $rings ) ) {
            return false;
        }

        $lat = floatval( $lat );
        $lng = floatval( $lng );
        $inside = false;

        foreach ( $rings as $ring ) {
            $count = is_array( $ring ) ? count( $ring ) : 0;
            if ( $count < 3 ) {
                continue;
            }

            for ( $i = 0, $j = $count - 1; $i < $count; $j = $i++ ) {
                $xi = floatval( $ring[ $i ][0] ); // lng
                $yi = floatval( $ring[ $i ][1] ); // lat
                $xj = floatval( $ring[ $j ][0] );
                $yj = floatval( $ring[ $j ][1] );

                if ( ( $yi > $lat ) !== ( $yj > $lat ) ) {
                    // Edge straddles the ray; find where it crosses.
                    $x_cross = ( $xj - $xi ) * ( $lat - $yi ) / ( $yj - $yi ) + $xi;
                    if ( $lng < $x_cross ) {
                        $inside = ! $inside;
                    }
                }
            }
        }

        return $inside;
    }

    /**
     * Every ZIP whose polygon contains the point.
     *
     * The bbox is used only as a cheap pre-filter to skip the expensive ring
     * test - never as the answer. Overlapping bboxes are exactly why the old
     * code was wrong.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param array $boundaries Output of fetch_boundaries()
     * @return array Matching ZIP strings (0, 1, or more)
     * @since 3.2.0
     */
    public static function matching_zips( $lat, $lng, array $boundaries ) {
        $matches = array();

        foreach ( $boundaries as $zip => $boundary ) {
            $bbox = isset( $boundary['bbox'] ) ? $boundary['bbox'] : null;

            if ( is_array( $bbox ) && (
                $lat < $bbox['min_lat'] || $lat > $bbox['max_lat'] ||
                $lng < $bbox['min_lng'] || $lng > $bbox['max_lng']
            ) ) {
                continue; // Cheap reject only.
            }

            if ( self::point_in_rings( $lat, $lng, $boundary['rings'] ) ) {
                $matches[] = (string) $zip;
            }
        }

        return $matches;
    }

    /**
     * Resolve a point to exactly one ZIP, or nothing.
     *
     * Returns null on zero matches AND on multiple matches. There is no
     * default-ZIP, first-ZIP, or nearest-ZIP fallback here by design - callers
     * queue null results for human review. Use matching_zips() to find out what
     * (if anything) an ambiguous point matched.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param array $boundaries Output of fetch_boundaries()
     * @return string|null The ZIP code, or null if not unambiguous
     * @since 3.2.0
     */
    public static function determine_zip( $lat, $lng, array $boundaries ) {
        $matches = self::matching_zips( $lat, $lng, $boundaries );

        return ( count( $matches ) === 1 ) ? $matches[0] : null;
    }

    /**
     * Centroid of a polygon by simple vertex averaging.
     *
     * Adequate for parcel-sized polygons (this was also what the removed
     * shapefile parser did); deliberately not area-weighted.
     *
     * @param array $rings Array of rings, each an array of [lng, lat] pairs
     * @return array|null ['lat' => float, 'lng' => float] or null if no vertices
     * @since 3.2.0
     */
    public static function centroid_of_rings( $rings ) {
        if ( empty( $rings ) || ! is_array( $rings ) ) {
            return null;
        }

        $sum_lat = 0.0;
        $sum_lng = 0.0;
        $count = 0;

        foreach ( $rings as $ring ) {
            if ( ! is_array( $ring ) ) {
                continue;
            }
            foreach ( $ring as $point ) {
                if ( ! isset( $point[0], $point[1] ) ) {
                    continue;
                }
                $sum_lng += floatval( $point[0] );
                $sum_lat += floatval( $point[1] );
                $count++;
            }
        }

        if ( $count === 0 ) {
            return null;
        }

        return array(
            'lat' => $sum_lat / $count,
            'lng' => $sum_lng / $count
        );
    }

    /**
     * Bounding box of a set of rings, computed locally (the service doesn't
     * hand us one we can trust to be in the same SR).
     *
     * @param array $rings Array of rings, each an array of [lng, lat] pairs
     * @return array|null ['min_lat','max_lat','min_lng','max_lng'] or null
     * @since 3.2.0
     */
    private static function bbox_of_rings( $rings ) {
        $min_lat = $min_lng = INF;
        $max_lat = $max_lng = -INF;
        $count = 0;

        foreach ( $rings as $ring ) {
            if ( ! is_array( $ring ) ) {
                continue;
            }
            foreach ( $ring as $point ) {
                if ( ! isset( $point[0], $point[1] ) ) {
                    continue;
                }
                $lng = floatval( $point[0] );
                $lat = floatval( $point[1] );

                $min_lng = min( $min_lng, $lng );
                $max_lng = max( $max_lng, $lng );
                $min_lat = min( $min_lat, $lat );
                $max_lat = max( $max_lat, $lat );
                $count++;
            }
        }

        if ( $count === 0 ) {
            return null;
        }

        return array(
            'min_lat' => $min_lat,
            'max_lat' => $max_lat,
            'min_lng' => $min_lng,
            'max_lng' => $max_lng
        );
    }
}
