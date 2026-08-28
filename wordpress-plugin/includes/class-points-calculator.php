<?php
/**
 * Points Calculator Class
 * 
 * Calculates team sales report with points distribution based on settings.
 * Handles both team-based and individual selling modes with signup integration.
 * 
 * @package Subsales_Management
 * @since 2.4.114
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Points_Calculator {
    
    /**
     * Build team sales report with points calculation
     *
     * @param string $points_mode 'dollar' or 'item'
     * @param float $points_denomination Multiplier for points calculation
     * @param string $points_distribution 'individual' or 'team'
     * @param int $donation_bonus_enabled 1 or 0
     * @param float $donation_percentage Percentage of donations to add as bonus points
     * @param string $donation_distribution 'individual' or 'team'
     * @return array Report data
     */
    public static function build_report( 
        $points_mode = 'dollar', 
        $points_denomination = 1.0,
        $points_distribution = 'individual',
        $donation_bonus_enabled = 0,
        $donation_percentage = 50.0,
        $donation_distribution = 'team'
    ) {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'ss_orders';
        $signups_table = $wpdb->prefix . 'ss_signups';
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';
        $products_config = order_sync_get_products_config();
        
        // Get all non-deleted orders with necessary fields, scoped to the
        // current season so this report doesn't mix in prior-season sales.
        $current_season_id = Subsales_Database::current_season_id();
        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, order_data, created_at, team_id, user_id FROM {$orders_table} WHERE deleted = 0 AND season_id = %d ORDER BY created_at DESC",
                $current_season_id
            ),
            ARRAY_A
        );
        
        // Get all active signups so everyone signed up is included. Drivers are
        // excluded — they are not sales members and must not affect team counts.
        $signups = Subsales_Database::get_active_signups( array(
            'status'          => 'active',
            'exclude_drivers' => true,
        ) );
        
        // Initialize data structures
        $aggregated_data = array();
        $team_totals = array(); // [date|team_name] = ['product_quantity' => X, 'donations' => Y, 'members' => []]
        
        // First pass: Initialize all signed-up people with zero values
        foreach ( $signups as $signup ) {
            $date = $signup['campaign_date'];
            $team_name = $signup['team_name'];
            $person_name = $signup['user_name'];
            
            $key = $date . '|' . $team_name . '|' . $person_name;
            
            if ( ! isset( $aggregated_data[ $key ] ) ) {
                $aggregated_data[ $key ] = array(
                    'date' => $date,
                    'team_name' => $team_name,
                    'person_name' => $person_name,
                    'product_quantity' => 0,
                    'total_donations' => 0.0,
                    'order_count' => 0
                );
            }
            
            // Initialize team totals
            $team_key = $date . '|' . $team_name;
            if ( ! isset( $team_totals[ $team_key ] ) ) {
                $team_totals[ $team_key ] = array(
                    'product_quantity' => 0,
                    'donations' => 0.0,
                    'members' => array()
                );
            }
            
            if ( ! in_array( $person_name, $team_totals[ $team_key ]['members'] ) ) {
                $team_totals[ $team_key ]['members'][] = $person_name;
            }
        }
        
        // Second pass: aggregate order data using helper classes
        foreach ( $orders as $order ) {
            $order_data = Subsales_Order_Helper::decode_order_data( $order );
            if ( ! is_array( $order_data ) ) continue;
            
            // Get date
            $date = date( 'Y-m-d', strtotime( $order['created_at'] ) );
            
            // Get team name using cached helper (90% query reduction!)
            $team_id = intval( $order['team_id'] );
            $team_name = Subsales_Order_Helper::get_team_name( $team_id );
            
            // Get person name using cached helper (90% query reduction!)
            $user_id = intval( $order['user_id'] );
            $person_name = Subsales_Order_Helper::get_user_name( $user_id );
            
            // Fallback to order data if user lookup failed
            if ( $person_name === 'Unknown Person' ) {
                if ( isset( $order_data['entered_by_name'] ) && ! empty( $order_data['entered_by_name'] ) ) {
                    $person_name = $order_data['entered_by_name'];
                } else {
                    // Last resort: use customer name
                    $person_name = Subsales_Order_Helper::get_customer_name( $order );
                }
            }
            
            // Create unique key for aggregation
            $key = $date . '|' . $team_name . '|' . $person_name;
            
            // Initialize if not exists (for people not in signup system)
            if ( ! isset( $aggregated_data[ $key ] ) ) {
                $aggregated_data[ $key ] = array(
                    'date' => $date,
                    'team_name' => $team_name,
                    'person_name' => $person_name,
                    'product_quantity' => 0,
                    'total_donations' => 0.0,
                    'order_count' => 0,
                    'team_id' => $team_id
                );
            }
            
            // Calculate product quantity for this order
            $order_product_qty = self::calculate_product_quantity( $order_data, $products_config );
            
            // Get donations for this order
            $order_donations = isset( $order_data['donationAmount'] ) ? floatval( $order_data['donationAmount'] ) : 0.0;
            
            // Aggregate individual data
            $aggregated_data[ $key ]['product_quantity'] += $order_product_qty;
            $aggregated_data[ $key ]['total_donations'] += $order_donations;
            $aggregated_data[ $key ]['order_count']++;
            
            // Track team totals
            $team_key = $date . '|' . $team_name;
            if ( ! isset( $team_totals[ $team_key ] ) ) {
                $team_totals[ $team_key ] = array(
                    'product_quantity' => 0,
                    'donations' => 0.0,
                    'members' => array()
                );
            }
            $team_totals[ $team_key ]['product_quantity'] += $order_product_qty;
            $team_totals[ $team_key ]['donations'] += $order_donations;
            
            if ( ! in_array( $person_name, $team_totals[ $team_key ]['members'] ) ) {
                $team_totals[ $team_key ]['members'][] = $person_name;
            }
        }
        
        // Third pass: calculate points and build report rows
        $report_rows = array();
        foreach ( $aggregated_data as $row ) {
            $date = $row['date'];
            $team_name = $row['team_name'];
            $person_name = $row['person_name'];
            $team_key = $date . '|' . $team_name;
            $is_individual_mode = isset( $row['team_id'] ) && $row['team_id'] === -1;
            
            // Calculate base points
            $points = self::calculate_base_points(
                $row,
                $team_totals[ $team_key ],
                $is_individual_mode,
                $points_distribution,
                $points_denomination
            );
            
            // Calculate donation bonus
            $donation_bonus = 0.0;
            if ( $donation_bonus_enabled ) {
                $donation_bonus = self::calculate_donation_bonus(
                    $row,
                    $team_totals[ $team_key ],
                    $is_individual_mode,
                    $donation_distribution,
                    $donation_percentage
                );
            }
            
            // Total points
            $total_points = $points + $donation_bonus;
            
            // Build tooltip breakdown
            $tooltip = self::build_points_tooltip(
                $row,
                $team_totals[ $team_key ],
                $is_individual_mode,
                $points_distribution,
                $points_denomination,
                $points,
                $donation_bonus_enabled,
                $donation_distribution,
                $donation_percentage,
                $donation_bonus,
                $total_points
            );
            
            $report_rows[] = array(
                'date' => $row['date'],
                'team_name' => $row['team_name'],
                'person_name' => $row['person_name'],
                'product_quantity' => $row['product_quantity'],
                'total_donations' => $row['total_donations'],
                'points' => $total_points,
                'order_count' => $row['order_count'],
                'points_tooltip' => $tooltip
            );
        }
        
        // Sort by date (descending), then by team name (ascending)
        usort( $report_rows, function( $a, $b ) {
            $date_cmp = strcmp( $b['date'], $a['date'] );
            if ( $date_cmp !== 0 ) {
                return $date_cmp;
            }
            return strcmp( $a['team_name'], $b['team_name'] );
        } );
        
        return $report_rows;
    }
    
    /**
     * Calculate product quantity from order data
     *
     * @param array $order_data Decoded order data
     * @param array $products_config Product configuration
     * @return int Total product quantity
     */
    private static function calculate_product_quantity( $order_data, $products_config ) {
        $total_qty = 0;
        
        if ( isset( $order_data['products'] ) && is_array( $order_data['products'] ) ) {
            // New format
            foreach ( $order_data['products'] as $product ) {
                $qty = isset( $product['qty'] ) ? intval( $product['qty'] ) : 0;
                $total_qty += $qty;
            }
        } else {
            // Legacy format
            foreach ( $products_config as $prod ) {
                $pid = $prod['id'];
                $qty = 0;
                
                if ( isset( $order_data[ $pid . 'Qty' ] ) ) {
                    $qty = intval( $order_data[ $pid . 'Qty' ] );
                } elseif ( isset( $order_data[ $pid . '_qty' ] ) ) {
                    $qty = intval( $order_data[ $pid . '_qty' ] );
                }
                
                $total_qty += $qty;
            }
        }
        
        return $total_qty;
    }
    
    /**
     * Calculate base points for a person/team
     *
     * @param array $row Individual row data
     * @param array $team_data Team totals
     * @param bool $is_individual_mode Whether team_id = -1
     * @param string $points_distribution 'individual' or 'team'
     * @param float $points_denomination Points per product
     * @return float Base points
     */
    private static function calculate_base_points( $row, $team_data, $is_individual_mode, $points_distribution, $points_denomination ) {
        if ( $is_individual_mode || $points_distribution === 'individual' ) {
            // Individual-based: each person gets points for their own products
            return $row['product_quantity'] * $points_denomination;
        } else {
            // Team-based: divide team total by number of members
            $member_count = count( $team_data['members'] );
            
            if ( $member_count > 0 ) {
                $team_points = $team_data['product_quantity'] * $points_denomination;
                return $team_points / $member_count;
            }
        }
        
        return 0.0;
    }
    
    /**
     * Calculate donation bonus points
     *
     * @param array $row Individual row data
     * @param array $team_data Team totals
     * @param bool $is_individual_mode Whether team_id = -1
     * @param string $donation_distribution 'individual' or 'team'
     * @param float $donation_percentage Percentage of donations to convert to points
     * @return float Donation bonus points
     */
    private static function calculate_donation_bonus( $row, $team_data, $is_individual_mode, $donation_distribution, $donation_percentage ) {
        if ( $is_individual_mode || $donation_distribution === 'individual' ) {
            // Individual donation bonus: dollar value at percentage becomes point value
            return $row['total_donations'] * ( $donation_percentage / 100.0 );
        } else {
            // Team-based donation bonus: split team donations by members
            $member_count = count( $team_data['members'] );
            
            if ( $member_count > 0 ) {
                return ( $team_data['donations'] * ( $donation_percentage / 100.0 ) ) / $member_count;
            }
        }
        
        return 0.0;
    }
    
    /**
     * Build tooltip explaining points calculation
     *
     * @param array $row Individual row data
     * @param array $team_data Team totals
     * @param bool $is_individual_mode Whether team_id = -1
     * @param string $points_distribution 'individual' or 'team'
     * @param float $points_denomination Points per product
     * @param float $points Base points calculated
     * @param int $donation_bonus_enabled Whether donation bonus is enabled
     * @param string $donation_distribution 'individual' or 'team'
     * @param float $donation_percentage Percentage of donations
     * @param float $donation_bonus Donation bonus calculated
     * @param float $total_points Total points
     * @return string Tooltip text with calculation breakdown
     */
    private static function build_points_tooltip( 
        $row, $team_data, $is_individual_mode, 
        $points_distribution, $points_denomination, $points,
        $donation_bonus_enabled, $donation_distribution, $donation_percentage, $donation_bonus,
        $total_points 
    ) {
        $tooltip_parts = array();
        
        // Product points calculation
        if ( $is_individual_mode || $points_distribution === 'individual' ) {
            $tooltip_parts[] = sprintf(
                'Product Points: %s products × %s = %s',
                number_format( $row['product_quantity'], 0 ),
                number_format( $points_denomination, 2 ),
                number_format( $points, 2 )
            );
        } else {
            $member_count = count( $team_data['members'] );
            $tooltip_parts[] = sprintf(
                'Product Points: %s products × %s ÷ %d members = %s',
                number_format( $team_data['product_quantity'], 0 ),
                number_format( $points_denomination, 2 ),
                $member_count,
                number_format( $points, 2 )
            );
        }
        
        // Donation bonus calculation
        if ( $donation_bonus_enabled && $donation_bonus > 0 ) {
            if ( $is_individual_mode || $donation_distribution === 'individual' ) {
                $tooltip_parts[] = sprintf(
                    'Donation Bonus: $%s × %s%% = %s',
                    number_format( $row['total_donations'], 2 ),
                    number_format( $donation_percentage, 1 ),
                    number_format( $donation_bonus, 2 )
                );
            } else {
                $member_count = count( $team_data['members'] );
                $tooltip_parts[] = sprintf(
                    'Donation Bonus: $%s × %s%% ÷ %d members = %s',
                    number_format( $team_data['donations'], 2 ),
                    number_format( $donation_percentage, 1 ),
                    $member_count,
                    number_format( $donation_bonus, 2 )
                );
            }
        }
        
        $tooltip_parts[] = sprintf( 'Total: %s points', number_format( $total_points, 2 ) );
        
        return implode( '\n', $tooltip_parts );
    }
}
