<?php
/**
 * Subsales Order Helper
 * 
 * Provides unified methods for common order data operations across the plugin.
 * Eliminates code duplication and ensures consistency in order data handling.
 * 
 * @package Subsales_Management
 * @since 2.4.74
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Order_Helper {

    /**
     * Initialize order helper
     */
    public static function init() {
        // No hooks needed - pure utility class
    }

    /**
     * Safely decode order_data JSON field
     * 
     * Handles both JSON strings and already-decoded arrays.
     * Returns empty array on failure for safe array access.
     * 
     * @param array $order Order row from database
     * @return array Decoded order data
     * 
     * @example
     * $order_data = Subsales_Order_Helper::decode_order_data( $order );
     * $customer = $order_data['customer'] ?? 'Unknown';
     */
    public static function decode_order_data( $order ) {
        if ( empty( $order['order_data'] ) ) {
            return [];
        }
        
        // Already decoded
        if ( is_array( $order['order_data'] ) ) {
            return $order['order_data'];
        }
        
        // Decode JSON string
        $decoded = json_decode( $order['order_data'], true );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            subsales_log( 
                'WARNING', 
                'orders', 
                'Failed to decode order_data JSON', 
                [ 'order_id' => $order['id'] ?? 'unknown', 'error' => json_last_error_msg() ] 
            );
            return [];
        }
        
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Get customer name from order with fallback
     * 
     * Handles inconsistent field naming (customer vs customerName).
     * Checks both order_data and top-level order fields.
     * 
     * @param array $order Order row from database
     * @param string $default Default value if customer not found
     * @return string Customer name
     * 
     * @example
     * $customer = Subsales_Order_Helper::get_customer_name( $order, 'Anonymous' );
     */
    public static function get_customer_name( $order, $default = 'Unknown' ) {
        $order_data = self::decode_order_data( $order );
        
        // Check order_data first (most common location)
        if ( ! empty( $order_data['customer'] ) ) {
            return sanitize_text_field( $order_data['customer'] );
        }
        
        if ( ! empty( $order_data['customerName'] ) ) {
            return sanitize_text_field( $order_data['customerName'] );
        }
        
        // Check top-level order fields
        if ( ! empty( $order['customer'] ) ) {
            return sanitize_text_field( $order['customer'] );
        }
        
        if ( ! empty( $order['customerName'] ) ) {
            return sanitize_text_field( $order['customerName'] );
        }
        
        return $default;
    }

    /**
     * Get team name from team_id with proper handling of special cases
     * 
     * Handles:
     * - Individual mode (team_id = -1)
     * - Invalid/null team IDs
     * - Database lookup with caching
     * 
     * @param int|string $team_id Team ID
     * @return string Team name
     * 
     * @example
     * $team = Subsales_Order_Helper::get_team_name( $order['team_id'] );
     */
    public static function get_team_name( $team_id ) {
        // Individual mode
        if ( $team_id === -1 || $team_id === '-1' ) {
            return 'Individual';
        }
        
        // Invalid team ID
        if ( empty( $team_id ) || $team_id <= 0 ) {
            return 'Unknown Team';
        }
        
        global $wpdb;
        $cache_key = 'subsales_team_name_' . $team_id;
        $cached = wp_cache_get( $cache_key, 'subsales' );
        
        if ( $cached !== false ) {
            return $cached;
        }
        
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}ss_teams WHERE id = %d",
            $team_id
        ) );
        
        $result = $name ?: 'Unknown Team';
        wp_cache_set( $cache_key, $result, 'subsales', 300 ); // Cache 5 minutes
        
        return $result;
    }

    /**
     * Get user/member name from user_id with proper handling
     * 
     * @param int|string $user_id User ID
     * @return string User name
     * 
     * @example
     * $person = Subsales_Order_Helper::get_user_name( $order['user_id'] );
     */
    public static function get_user_name( $user_id ) {
        if ( empty( $user_id ) || $user_id <= 0 ) {
            return 'Unknown Person';
        }
        
        global $wpdb;
        $cache_key = 'subsales_user_name_' . $user_id;
        $cached = wp_cache_get( $cache_key, 'subsales' );
        
        if ( $cached !== false ) {
            return $cached;
        }
        
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d",
            $user_id
        ) );
        
        $result = $name ?: 'Unknown Person';
        wp_cache_set( $cache_key, $result, 'subsales', 300 ); // Cache 5 minutes
        
        return $result;
    }

    /**
     * Get order address from order_data or top-level field
     * 
     * @param array $order Order row from database
     * @return string Address or empty string
     * 
     * @example
     * $address = Subsales_Order_Helper::get_order_address( $order );
     */
    public static function get_order_address( $order ) {
        $order_data = self::decode_order_data( $order );
        
        if ( ! empty( $order_data['address'] ) ) {
            return sanitize_text_field( $order_data['address'] );
        }
        
        if ( ! empty( $order['address'] ) ) {
            return sanitize_text_field( $order['address'] );
        }
        
        return '';
    }

    /**
     * Format order ID for display
     * 
     * @param int|string $order_id Order ID
     * @return string Formatted order ID
     */
    public static function format_order_id( $order_id ) {
        return '#' . str_pad( $order_id, 6, '0', STR_PAD_LEFT );
    }

    /**
     * Get order date in consistent format
     * 
     * @param array $order Order row from database
     * @param string $format Date format (default: 'Y-m-d H:i:s')
     * @return string Formatted date
     */
    public static function get_order_date( $order, $format = 'Y-m-d H:i:s' ) {
        $order_data = self::decode_order_data( $order );
        
        // Try created_at first (database field)
        if ( ! empty( $order['created_at'] ) ) {
            return date( $format, strtotime( $order['created_at'] ) );
        }
        
        // Try order_data fields
        if ( ! empty( $order_data['created_at'] ) ) {
            return date( $format, strtotime( $order_data['created_at'] ) );
        }
        
        if ( ! empty( $order_data['createdAt'] ) ) {
            return date( $format, strtotime( $order_data['createdAt'] ) );
        }
        
        return date( $format );
    }

    /**
     * Check if order is tallied
     * 
     * @param array $order Order row from database
     * @return bool True if tallied
     */
    public static function is_tallied( $order ) {
        $order_data = self::decode_order_data( $order );
        
        // Check order_data first
        if ( isset( $order_data['tallied'] ) ) {
            return (bool) $order_data['tallied'];
        }
        
        // Check top-level field
        if ( isset( $order['tallied'] ) ) {
            return (bool) $order['tallied'];
        }
        
        return false;
    }

    /**
     * Check if order is a donation-only order
     * 
     * @param array $order Order row from database
     * @return bool True if donation-only
     */
    public static function is_donation_only( $order ) {
        $order_data = self::decode_order_data( $order );
        return ! empty( $order_data['donationOnly'] ) || ! empty( $order_data['donation_only'] );
    }

    /**
     * Get payment method from order
     * 
     * @param array $order Order row from database
     * @return string Payment method (cash, check, or empty)
     */
    public static function get_payment_method( $order ) {
        $order_data = self::decode_order_data( $order );
        
        if ( ! empty( $order_data['paymentMethod'] ) ) {
            return sanitize_text_field( $order_data['paymentMethod'] );
        }
        
        if ( ! empty( $order_data['payment_method'] ) ) {
            return sanitize_text_field( $order_data['payment_method'] );
        }
        
        return '';
    }

    /**
     * Get entered_by name from order_data
     * 
     * @param array $order Order row from database
     * @return string Entered by name
     */
    public static function get_entered_by_name( $order ) {
        $order_data = self::decode_order_data( $order );
        
        if ( ! empty( $order_data['entered_by_name'] ) ) {
            return sanitize_text_field( $order_data['entered_by_name'] );
        }
        
        if ( ! empty( $order_data['enteredByName'] ) ) {
            return sanitize_text_field( $order_data['enteredByName'] );
        }
        
        // Fallback to user lookup
        if ( ! empty( $order['user_id'] ) ) {
            return self::get_user_name( $order['user_id'] );
        }
        
        return 'Unknown';
    }

}
