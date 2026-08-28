<?php
/**
 * Digital payment attempts (Square Checkout)
 *
 * DB-facing class and direct REST callback target for the digital-payment
 * lifecycle - mirrors how Subsales_Orders::create_order()/get_orders() are
 * registered straight as callbacks, no extra controller layer.
 *
 * A payment attempt never writes to wp_ss_orders directly - it only ever
 * informs the seller's own device that a payment completed, so the PWA's
 * existing Storage.add() save (the same path as cash/check) remains the one
 * and only "this order is real" moment.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Payment_Attempts {

    /**
     * Initialize hooks. Registered here rather than at load time so this
     * class follows the same "call ClassName::init() from the bootstrap"
     * convention as Subsales_Database, Subsales_Orders, etc.
     */
    public static function init() {
        // Stack onto the existing hourly cleanup hook (see
        // Subsales_Database::init()) rather than scheduling a new cron event.
        add_action( 'subsales_log_cleanup', array( __CLASS__, 'expire_stale_attempts' ) );
    }

    /**
     * Create a digital payment attempt: computes amounts, creates a Square
     * payment link, and records an 'initiated' row.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function create_attempt( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_payment_attempts';

        $data = $request->get_json_params();

        $customer     = isset( $data['customer'] ) ? $data['customer'] : '';
        $address      = isset( $data['address'] ) ? $data['address'] : '';
        $cell_number  = isset( $data['cellNumber'] ) ? $data['cellNumber'] : '';
        $products     = isset( $data['products'] ) && is_array( $data['products'] ) ? $data['products'] : array();
        $notes        = isset( $data['notes'] ) ? $data['notes'] : '';

        // Resolve seller identity the same way Subsales_Orders::create_order() does:
        // user_id comes from the request body; team_id prefers an explicit body
        // value and otherwise falls back to the X-Team-Name/X-Access-Code headers.
        $user_id = isset( $data['user_id'] ) ? sanitize_text_field( $data['user_id'] ) : '';

        $team_id = null;
        if ( isset( $data['team_id'] ) ) {
            $team_id = intval( $data['team_id'] );
        } else {
            try {
                $team_name   = $request->get_header( 'X-Team-Name' );
                $access_code = $request->get_header( 'X-Access-Code' );
                if ( ! empty( $team_name ) && ! empty( $access_code ) ) {
                    $team = Subsales_Database::get_team_by_credentials( sanitize_text_field( $team_name ), sanitize_text_field( $access_code ) );
                    if ( $team && isset( $team['id'] ) ) {
                        $team_id = intval( $team['id'] );
                    }
                }
            } catch ( Exception $e ) {
                $team_id = null;
            }
        }

        // Mirror create_order()'s entered_by_name resolution: explicit body
        // value, else look up the team member's name, else a fallback label.
        $entered_by_name = isset( $data['entered_by_name'] ) ? sanitize_text_field( $data['entered_by_name'] ) : '';
        if ( empty( $entered_by_name ) && ! empty( $user_id ) ) {
            $user_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}ss_team_members WHERE id = %d",
                intval( $user_id )
            ) );
            if ( $user_row ) {
                $entered_by_name = $user_row->name;
            }
        }
        if ( empty( $entered_by_name ) ) {
            $entered_by_name = 'Mobile User ' . $user_id;
        }

        // Compute amounts
        $subtotal_amount = 0;
        foreach ( $products as $product ) {
            $qty   = isset( $product['qty'] ) ? floatval( $product['qty'] ) : 0;
            $price = isset( $product['price'] ) ? floatval( $product['price'] ) : 0;
            $subtotal_amount += $qty * $price;
        }

        $fee_enabled    = (bool) get_option( 'subsales_convenience_fee_enabled', false );
        $fee_percentage = floatval( get_option( 'subsales_convenience_fee_percentage', 0 ) );
        $convenience_fee_amount = $fee_enabled ? round( $subtotal_amount * $fee_percentage / 100, 2 ) : 0;
        $total_amount = $subtotal_amount + $convenience_fee_amount;

        // Server-generated attempt id (unlike orders, the client doesn't
        // generate this - it's created here, at "Ready" tap time, and handed
        // back for polling/cancel calls).
        $attempt_uid = 'pa-' . time() . '-' . wp_generate_password( 8, false );

        // Build Square line items from the products, plus a synthetic
        // Convenience Fee line item when the fee toggle produced one.
        $line_items = array();
        foreach ( $products as $product ) {
            $line_items[] = array(
                'name'     => isset( $product['name'] ) ? $product['name'] : 'Item',
                'quantity' => isset( $product['qty'] ) ? intval( $product['qty'] ) : 1,
                'price'    => isset( $product['price'] ) ? floatval( $product['price'] ) : 0,
            );
        }
        if ( $convenience_fee_amount > 0 ) {
            $line_items[] = array(
                'name'     => 'Convenience Fee',
                'quantity' => 1,
                'price'    => $convenience_fee_amount,
            );
        }

        $settings = Subsales_Square_Payments::get_settings();
        if ( ! $settings ) {
            return new WP_Error( 'square_not_configured', 'Digital payments are not configured yet.', array( 'status' => 500 ) );
        }

        // Matches create_order()'s GMT-based time convention (current_time('mysql', true)).
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + ( 15 * 60 ) );

        $checkout = Subsales_Square_Payments::create_payment_link( $total_amount, $line_items, $attempt_uid, $expires_at );
        if ( ! $checkout ) {
            return new WP_Error( 'square_checkout_failed', 'Could not create a Square checkout session.', array( 'status' => 500 ) );
        }

        $draft_order_data = wp_json_encode( array(
            'customer'   => $customer,
            'address'    => $address,
            'cellNumber' => $cell_number,
            'products'   => $products,
            'notes'      => $notes,
        ) );

        $insert_row = array(
            'attempt_uid'            => $attempt_uid,
            'season_id'              => Subsales_Database::current_season_id(),
            'team_id'                => intval( $team_id ),
            'user_id'                => $user_id,
            'entered_by_name'        => $entered_by_name,
            'draft_order_data'       => $draft_order_data,
            'subtotal_amount'        => $subtotal_amount,
            'convenience_fee_amount' => $convenience_fee_amount,
            'total_amount'           => $total_amount,
            'square_checkout_id'     => $checkout['checkout_id'],
            'square_order_id'        => $checkout['square_order_id'],
            'checkout_url'           => $checkout['checkout_url'],
            'status'                 => 'initiated',
            'expires_at'             => $expires_at,
        );
        $formats = array( '%s', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s' );

        $result = $wpdb->insert( $table_name, $insert_row, $formats );

        if ( $result === false ) {
            subsales_log( 'ERROR', 'square', 'Failed to insert payment attempt row', array(
                'attempt_uid' => $attempt_uid,
                'db_error'    => $wpdb->last_error,
            ) );
            // Best-effort: don't leave a live checkout link with no local record of it.
            Subsales_Square_Payments::delete_payment_link( $checkout['checkout_id'] );
            return new WP_Error( 'db_insert_failed', 'Could not record the payment attempt.', array( 'status' => 500 ) );
        }

        $qr_code_data_uri = Subsales_Delivery::generate_qr_data_uri( $checkout['checkout_url'] );

        return rest_ensure_response( array(
            'attempt_id'       => $attempt_uid,
            'checkout_url'     => $checkout['checkout_url'],
            'qr_code_data_uri' => $qr_code_data_uri,
            'expires_at'       => $expires_at,
            'amount'           => array(
                'subtotal' => $subtotal_amount,
                'fee'      => $convenience_fee_amount,
                'total'    => $total_amount,
            ),
        ) );
    }

    /**
     * Poll for an attempt's current status. Never talks to Square directly -
     * Square is only contacted at creation time and via its own webhook.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function get_status( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_payment_attempts';

        $attempt_id = $request->get_param( 'id' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT attempt_uid, status, updated_at FROM {$table_name} WHERE attempt_uid = %s",
            $attempt_id
        ), ARRAY_A );

        if ( ! $row ) {
            return new WP_Error( 'attempt_not_found', 'Payment attempt not found.', array( 'status' => 404 ) );
        }

        return rest_ensure_response( array(
            'attempt_id' => $row['attempt_uid'],
            'status'     => $row['status'],
            'paid_at'    => ( 'paid' === $row['status'] ) ? $row['updated_at'] : null,
        ) );
    }

    /**
     * Cancel an attempt from the seller side ("back out to Cash/Check").
     * Only cancels if still 'initiated', so this can't race a webhook that
     * already marked the row 'paid'.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function cancel_attempt( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_payment_attempts';

        $attempt_id = $request->get_param( 'id' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT square_checkout_id FROM {$table_name} WHERE attempt_uid = %s",
            $attempt_id
        ), ARRAY_A );

        $wpdb->update(
            $table_name,
            array( 'status' => 'cancelled_by_seller' ),
            array( 'attempt_uid' => $attempt_id, 'status' => 'initiated' ),
            array( '%s' ),
            array( '%s', '%s' )
        );

        if ( $row && ! empty( $row['square_checkout_id'] ) ) {
            // Best-effort - ignore the return value either way.
            Subsales_Square_Payments::delete_payment_link( $row['square_checkout_id'] );
        }

        return rest_ensure_response( array( 'ok' => true ) );
    }

    /**
     * Square webhook receiver. Verifies the signature, then looks for a
     * payment-completed event and marks the matching attempt 'paid'.
     *
     * Never writes to wp_ss_orders - it only ever updates our own attempt
     * row; the seller's device is what turns a paid attempt into a real order.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_webhook( $request ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_payment_attempts';

        $raw_body = $request->get_body();

        // Square's documented header name as of this writing - verify against
        // a real sandbox webhook delivery before relying on this in production.
        $signature_header = $request->get_header( 'x-square-hmacsha256-signature' );

        // Must match EXACTLY the URL configured in Square's webhook subscription
        // settings, or verification will always fail.
        $notification_url = home_url( $request->get_route() );

        $settings = Subsales_Square_Payments::get_settings();
        if ( ! $settings ) {
            subsales_log( 'ERROR', 'square', 'Webhook received but Square is not configured' );
            return new WP_Error( 'invalid_signature', 'Signature verification failed', array( 'status' => 401 ) );
        }

        // ponytail: assumes the webhook always belongs to the currently-configured
        // environment's signature key - Square doesn't obviously indicate which
        // env/location a webhook is for in a way this code checks. Fine for a
        // single-environment deployment; revisit if sandbox and production are
        // ever both live at once.
        $signature_ok = Subsales_Square_Payments::verify_webhook_signature(
            $notification_url,
            $raw_body,
            $signature_header,
            $settings['webhook_signature_key']
        );

        if ( ! $signature_ok ) {
            subsales_log( 'ERROR', 'square', 'Webhook signature verification failed' );
            return new WP_Error( 'invalid_signature', 'Signature verification failed', array( 'status' => 401 ) );
        }

        $event = json_decode( $raw_body, true );

        // Square's payment-completed event shape as of this writing is typically
        // `payment.updated`/`payment.created` with `data.object.payment.status
        // === 'COMPLETED'` - checked defensively here since this hasn't been
        // verified against a real sandbox payload yet.
        $payment = isset( $event['data']['object']['payment'] ) ? $event['data']['object']['payment'] : null;

        if ( is_array( $payment ) && isset( $payment['status'] ) && 'COMPLETED' === $payment['status'] ) {
            $square_order_id   = isset( $payment['order_id'] ) ? $payment['order_id'] : '';
            $square_payment_id = isset( $payment['id'] ) ? $payment['id'] : '';

            if ( ! empty( $square_order_id ) ) {
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, status FROM {$table_name} WHERE square_order_id = %s",
                    $square_order_id
                ), ARRAY_A );

                if ( $row && 'initiated' === $row['status'] ) {
                    $wpdb->update(
                        $table_name,
                        array(
                            'status'            => 'paid',
                            'square_payment_id' => $square_payment_id,
                        ),
                        array( 'id' => intval( $row['id'] ) ),
                        array( '%s', '%s' ),
                        array( '%d' )
                    );
                }
            }
        }

        // Square expects a 200 regardless of what we did with the event, to
        // avoid retry storms - only signature failure gets a non-200 above.
        return rest_ensure_response( array( 'received' => true ) );
    }

    /**
     * Sweep 'initiated' attempts past their expiry to 'expired'. Hooked onto
     * the existing hourly cleanup action in init() above.
     */
    public static function expire_stale_attempts() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_payment_attempts';

        $wpdb->query(
            "UPDATE {$table_name} SET status = 'expired' WHERE status = 'initiated' AND expires_at < NOW()"
        );
    }
}
