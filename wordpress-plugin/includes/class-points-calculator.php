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
        $points_mode = 'product',
        $points_denomination = 1.0,
        $points_distribution = 'individual',
        $donation_bonus_enabled = 0,
        $donation_percentage = 50.0,
        $donation_distribution = 'team',
        $season_id = null
    ) {
        global $wpdb;
        $orders_table    = $wpdb->prefix . 'ss_orders';
        $products_config = order_sync_get_products_config();

        if ( null === $season_id ) {
            $season_id = Subsales_Database::current_season_id();
        }

        $orders = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, order_data, created_at, team_id, user_id FROM {$orders_table}
                 WHERE deleted = 0 AND season_id = %d ORDER BY created_at DESC",
                $season_id
            ),
            ARRAY_A
        );

        // Signups seed a row for everyone who worked, so a member who sold
        // nothing still appears and still receives their share of the team.
        //
        // ss_signups has no season column - the season only exists on the
        // campaign - so this has to be filtered through the join. Without it,
        // every signup ever made seeds a row, and the first report of a new
        // season lists last season's members.
        $signups = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.user_id, s.team_id, s.campaign_id, c.campaign_date,
                        t.name AS team_name, m.name AS user_name
                 FROM {$wpdb->prefix}ss_signups s
                 INNER JOIN {$wpdb->prefix}ss_campaigns c ON c.id = s.campaign_id
                 LEFT JOIN {$wpdb->prefix}ss_teams t ON t.id = s.team_id
                 LEFT JOIN {$wpdb->prefix}ss_team_members m ON m.id = s.user_id
                 WHERE s.status = 'active' AND s.is_driver = 0 AND c.season_id = %d",
                $season_id
            ),
            ARRAY_A
        );

        // Rows are keyed on ids, never on names. Keying on the displayed name
        // merged two members who happen to share one, and split a team's
        // history in half the moment it was renamed.
        $aggregated_data = array();
        $team_totals     = array();
        $campaign_for    = array();

        $blank_row = array(
            'product_quantity' => 0,
            'sale_value'       => 0.0,
            'total_donations'  => 0.0,
            'order_count'      => 0,
        );

        foreach ( $signups as $signup ) {
            $date     = $signup['campaign_date'];
            $team_id  = intval( $signup['team_id'] );
            $user_id  = intval( $signup['user_id'] );
            $team_key = $date . '|' . $team_id;
            $key      = $team_key . '|' . $user_id;

            $campaign_for[ $team_key ] = intval( $signup['campaign_id'] );

            if ( ! isset( $aggregated_data[ $key ] ) ) {
                $aggregated_data[ $key ] = array_merge( $blank_row, array(
                    'date'        => $date,
                    'team_id'     => $team_id,
                    'team_name'   => $signup['team_name'] ? $signup['team_name'] : Subsales_Order_Helper::get_team_name( $team_id ),
                    'user_id'     => $user_id,
                    'person_name' => $signup['user_name'] ? $signup['user_name'] : Subsales_Order_Helper::get_user_name( $user_id ),
                ) );
            }

            if ( ! isset( $team_totals[ $team_key ] ) ) {
                $team_totals[ $team_key ] = array(
                    'product_quantity' => 0,
                    'sale_value'       => 0.0,
                    'donations'        => 0.0,
                    'members'          => array(),
                    'sellers'          => array(),
                );
            }
            $team_totals[ $team_key ]['members'][ $user_id ] = true;
        }

        foreach ( $orders as $order ) {
            $order_data = Subsales_Order_Helper::decode_order_data( $order );
            if ( ! is_array( $order_data ) ) {
                continue;
            }

            $date     = date( 'Y-m-d', strtotime( $order['created_at'] ) );
            $team_id  = intval( $order['team_id'] );
            $user_id  = intval( $order['user_id'] );
            $team_key = $date . '|' . $team_id;
            $key      = $team_key . '|' . $user_id;

            if ( ! isset( $aggregated_data[ $key ] ) ) {
                $person_name = Subsales_Order_Helper::get_user_name( $user_id );
                if ( 'Unknown Person' === $person_name ) {
                    $person_name = ! empty( $order_data['entered_by_name'] )
                        ? $order_data['entered_by_name']
                        : Subsales_Order_Helper::get_customer_name( $order );
                }
                $aggregated_data[ $key ] = array_merge( $blank_row, array(
                    'date'        => $date,
                    'team_id'     => $team_id,
                    'team_name'   => Subsales_Order_Helper::get_team_name( $team_id ),
                    'user_id'     => $user_id,
                    'person_name' => $person_name,
                ) );
            }

            $qty   = self::calculate_product_quantity( $order_data, $products_config );
            $value = self::calculate_sale_value( $order_data, $products_config );
            $don   = isset( $order_data['donationAmount'] ) ? floatval( $order_data['donationAmount'] ) : 0.0;

            $aggregated_data[ $key ]['product_quantity'] += $qty;
            $aggregated_data[ $key ]['sale_value']       += $value;
            $aggregated_data[ $key ]['total_donations']  += $don;
            $aggregated_data[ $key ]['order_count']++;

            if ( ! isset( $team_totals[ $team_key ] ) ) {
                $team_totals[ $team_key ] = array(
                    'product_quantity' => 0,
                    'sale_value'       => 0.0,
                    'donations'        => 0.0,
                    'members'          => array(),
                    'sellers'          => array(),
                );
            }
            $team_totals[ $team_key ]['product_quantity'] += $qty;
            $team_totals[ $team_key ]['sale_value']       += $value;
            $team_totals[ $team_key ]['donations']        += $don;
            $team_totals[ $team_key ]['sellers'][ $user_id ] = true;
        }

        // The divisor is the roster for that team on that day - the same list
        // the delivery manifest divides across. Building it from "whoever
        // entered an order" instead meant someone who sold under a team they
        // had not signed up for both diluted that team's real members and
        // collected a share of their points.
        foreach ( $team_totals as $team_key => $totals ) {
            list( $date, $team_id ) = explode( '|', $team_key );
            $team_id = intval( $team_id );
            if ( $team_id <= 0 ) {
                continue;
            }
            $campaign_id = isset( $campaign_for[ $team_key ] )
                ? $campaign_for[ $team_key ]
                : $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ss_campaigns WHERE campaign_date = %s AND season_id = %d",
                    $date,
                    $season_id
                ) );
            if ( ! $campaign_id ) {
                continue;
            }
            $roster = Subsales_Database::get_campaign_team_members( $team_id, $campaign_id );
            if ( ! empty( $roster ) ) {
                $team_totals[ $team_key ]['members'] = array_fill_keys(
                    array_map( 'intval', wp_list_pluck( $roster, 'id' ) ),
                    true
                );
            }
        }

        // A team day with no roster at all - nobody signed up, or the sale day
        // was never created - would otherwise divide by zero members and score
        // every real sale as nothing. Fall back to whoever actually sold, so
        // the work still counts and the gap shows up as a missing signup
        // rather than as silently vanished points.
        foreach ( $team_totals as $team_key => $totals ) {
            if ( empty( $totals['members'] ) && ! empty( $totals['sellers'] ) ) {
                $team_totals[ $team_key ]['members'] = $totals['sellers'];
            }
        }

        $report_rows = array();
        foreach ( $aggregated_data as $key => $row ) {
            $team_key   = $row['date'] . '|' . $row['team_id'];
            $team_data  = isset( $team_totals[ $team_key ] ) ? $team_totals[ $team_key ] : array(
                'product_quantity' => 0, 'sale_value' => 0.0, 'donations' => 0.0, 'members' => array(),
            );
            $individual = ( -1 === $row['team_id'] );

            // A team day is divided among the people signed up for that team
            // that day. Someone who sold under a team they had not signed up
            // for still credits the team with their sales, but takes no share -
            // otherwise they collect points from a team they never joined.
            $off_roster = ( ! $individual && $row['team_id'] > 0
                && ! empty( $team_data['members'] )
                && ! isset( $team_data['members'][ $row['user_id'] ] ) );

            $points = $off_roster ? 0.0 : self::calculate_base_points(
                $row, $team_data, $individual, $points_distribution, $points_denomination, $points_mode
            );

            $donation_bonus = 0.0;
            if ( $donation_bonus_enabled && ! $off_roster ) {
                $donation_bonus = self::calculate_donation_bonus(
                    $row, $team_data, $individual, $donation_distribution, $donation_percentage
                );
            }

            $total_points = $points + $donation_bonus;

            $report_rows[] = array(
                'date'             => $row['date'],
                'team_id'          => $row['team_id'],
                'team_name'        => $row['team_name'],
                'user_id'          => $row['user_id'],
                'person_name'      => $row['person_name'],
                'product_quantity' => $row['product_quantity'],
                'sale_value'       => $row['sale_value'],
                'total_donations'  => $row['total_donations'],
                'points'           => $total_points,
                'order_count'      => $row['order_count'],
                'off_roster'       => $off_roster,
                'points_tooltip'   => $off_roster
                    ? sprintf(
                        'No points: sold under %s on %s but was not signed up for that team that day. The sales still count toward the team total.',
                        $row['team_name'],
                        $row['date']
                    )
                    : self::build_points_tooltip(
                        $row, $team_data, $individual, $points_distribution, $points_denomination,
                        $points, $donation_bonus_enabled, $donation_distribution, $donation_percentage,
                        $donation_bonus, $total_points, $points_mode
                    ),
            );
        }

        usort( $report_rows, function ( $a, $b ) {
            $date_cmp = strcmp( $b['date'], $a['date'] );
            if ( 0 !== $date_cmp ) {
                return $date_cmp;
            }
            $team_cmp = strcmp( $a['team_name'], $b['team_name'] );
            return 0 !== $team_cmp ? $team_cmp : strcmp( $a['person_name'], $b['person_name'] );
        } );

        return $report_rows;
    }

    /**
     * Dollar value of the products on an order.
     *
     * Prefers the price recorded on the line, then the order's price_snapshot,
     * then the current product config - so a later price change never rewrites
     * the value of an order already taken.
     */
    private static function calculate_sale_value( $order_data, $products_config ) {
        $total    = 0.0;
        $snapshot = isset( $order_data['price_snapshot'] ) && is_array( $order_data['price_snapshot'] )
            ? $order_data['price_snapshot'] : array();

        if ( isset( $order_data['products'] ) && is_array( $order_data['products'] ) ) {
            foreach ( $order_data['products'] as $product ) {
                $qty = isset( $product['qty'] ) ? intval( $product['qty'] ) : 0;
                if ( $qty <= 0 ) {
                    continue;
                }
                $pid   = isset( $product['id'] ) ? $product['id'] : '';
                $price = null;
                if ( isset( $product['price'] ) ) {
                    $price = floatval( $product['price'] );
                } elseif ( '' !== $pid && isset( $snapshot[ $pid ] ) ) {
                    $price = floatval( $snapshot[ $pid ] );
                } else {
                    foreach ( $products_config as $conf ) {
                        if ( isset( $conf['id'] ) && $conf['id'] === $pid ) {
                            $price = isset( $conf['price'] ) ? floatval( $conf['price'] ) : 0.0;
                            break;
                        }
                    }
                }
                $total += $qty * floatval( $price );
            }
            return $total;
        }

        foreach ( $products_config as $conf ) {
            if ( ! isset( $conf['id'] ) ) {
                continue;
            }
            $pid = $conf['id'];
            $qty = 0;
            if ( isset( $order_data[ $pid . 'Qty' ] ) ) {
                $qty = intval( $order_data[ $pid . 'Qty' ] );
            } elseif ( isset( $order_data[ $pid . '_qty' ] ) ) {
                $qty = intval( $order_data[ $pid . '_qty' ] );
            }
            if ( $qty > 0 ) {
                $price  = isset( $snapshot[ $pid ] ) ? floatval( $snapshot[ $pid ] )
                    : ( isset( $conf['price'] ) ? floatval( $conf['price'] ) : 0.0 );
                $total += $qty * $price;
            }
        }
        return $total;
    }

    /**
     * Total product quantity on an order.
     */
    private static function calculate_product_quantity( $order_data, $products_config ) {
        $total_qty = 0;

        if ( isset( $order_data['products'] ) && is_array( $order_data['products'] ) ) {
            foreach ( $order_data['products'] as $product ) {
                $total_qty += isset( $product['qty'] ) ? intval( $product['qty'] ) : 0;
            }
            return $total_qty;
        }

        foreach ( $products_config as $prod ) {
            if ( ! isset( $prod['id'] ) ) {
                continue;
            }
            $pid = $prod['id'];
            if ( isset( $order_data[ $pid . 'Qty' ] ) ) {
                $total_qty += intval( $order_data[ $pid . 'Qty' ] );
            } elseif ( isset( $order_data[ $pid . '_qty' ] ) ) {
                $total_qty += intval( $order_data[ $pid . '_qty' ] );
            }
        }
        return $total_qty;
    }

    /**
     * Base points for a row.
     *
     * $points_mode picks what is being scored: 'dollar' scores the value of
     * what was sold, anything else scores the number of items. It used to be
     * accepted and ignored, so the setting looked like it did something.
     */
    private static function calculate_base_points( $row, $team_data, $is_individual_mode, $points_distribution, $points_denomination, $points_mode = 'product' ) {
        $dollars   = ( 'dollar' === $points_mode );
        $own_units = $dollars ? $row['sale_value'] : $row['product_quantity'];

        if ( $is_individual_mode || 'individual' === $points_distribution ) {
            return $own_units * $points_denomination;
        }

        $member_count = count( $team_data['members'] );
        if ( $member_count < 1 ) {
            return 0.0;
        }
        $team_units = $dollars ? $team_data['sale_value'] : $team_data['product_quantity'];
        return ( $team_units * $points_denomination ) / $member_count;
    }

    /**
     * Donation bonus. Donations convert at a percentage regardless of the
     * points mode - they are money, not items.
     */
    private static function calculate_donation_bonus( $row, $team_data, $is_individual_mode, $donation_distribution, $donation_percentage ) {
        if ( $is_individual_mode || 'individual' === $donation_distribution ) {
            return $row['total_donations'] * ( $donation_percentage / 100.0 );
        }

        $member_count = count( $team_data['members'] );
        if ( $member_count < 1 ) {
            return 0.0;
        }
        return ( $team_data['donations'] * ( $donation_percentage / 100.0 ) ) / $member_count;
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
        $total_points, $points_mode = 'product'
    ) {
        $tooltip_parts = array();

        // Say which thing is being scored, so the admin reading the breakdown
        // can tell dollar mode from item mode without opening Settings.
        $dollars = ( 'dollar' === $points_mode );
        $label   = $dollars ? 'Sales Points' : 'Product Points';
        $fmt     = function ( $n ) use ( $dollars ) {
            return $dollars ? '$' . number_format( $n, 2 ) : number_format( $n, 0 ) . ' products';
        };

        if ( $is_individual_mode || $points_distribution === 'individual' ) {
            $units = $dollars ? $row['sale_value'] : $row['product_quantity'];
            $tooltip_parts[] = sprintf(
                '%s: %s × %s = %s',
                $label,
                $fmt( $units ),
                number_format( $points_denomination, 2 ),
                number_format( $points, 2 )
            );
        } else {
            $member_count = count( $team_data['members'] );
            $units = $dollars ? $team_data['sale_value'] : $team_data['product_quantity'];
            $tooltip_parts[] = sprintf(
                '%s: %s × %s ÷ %d members = %s',
                $label,
                $fmt( $units ),
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
