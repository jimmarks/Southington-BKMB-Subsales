<?php
/**
 * Square Payments API integration
 *
 * Pure Square API wrapper - no DB access. Mirrors
 * Subsales_Delivery::geocode_address()'s style exactly: single-attempt
 * wp_remote_*() with an explicit timeout, is_wp_error() check,
 * wp_remote_retrieve_body() + json_decode(), subsales_log() on failure,
 * return false/WP_Error, no retries.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Square_Payments {

    /**
     * Read the currently-configured Square environment/credentials.
     *
     * @return array|false Settings array, or false if not configured.
     */
    public static function get_settings() {
        $environment = get_option( 'subsales_square_environment', 'sandbox' );

        $access_token = get_option( "subsales_square_access_token_{$environment}", '' );
        $location_id  = get_option( "subsales_square_location_id_{$environment}", '' );

        if ( empty( $access_token ) || empty( $location_id ) ) {
            subsales_log( 'ERROR', 'square', 'Square credentials not configured for environment: ' . $environment );
            return false;
        }

        return array(
            'environment'            => $environment,
            'access_token'           => $access_token,
            'location_id'            => $location_id,
            'webhook_signature_key'  => get_option( "subsales_square_webhook_signature_key_{$environment}", '' ),
        );
    }

    /**
     * Get the Square API base URL for the given environment.
     *
     * @param string $environment 'sandbox' or 'production'
     * @return string
     */
    private static function get_api_base_url( $environment ) {
        return ( 'production' === $environment )
            ? 'https://connect.squareup.com'
            : 'https://connect.squareupsandbox.com';
    }

    /**
     * Create a Square Payment Link (hosted guest checkout) for a digital payment attempt.
     *
     * @param float  $amount       Total amount in dollars.
     * @param array  $line_items   Array of ['name'=>string,'quantity'=>int,'price'=>float].
     * @param string $reference_id Idempotency key (the attempt_uid).
     * @param string $expires_at   Not currently sent to Square (see note below) - reserved
     *                              for aligning Square's own link TTL with our 15-minute sweep.
     * @return array|false ['checkout_id','checkout_url','square_order_id'] or false on failure.
     */
    public static function create_payment_link( $amount, $line_items, $reference_id, $expires_at ) {
        $settings = self::get_settings();
        if ( ! $settings ) {
            return false;
        }

        $square_line_items = array();
        foreach ( $line_items as $item ) {
            $square_line_items[] = array(
                'name'             => $item['name'],
                'quantity'         => (string) intval( $item['quantity'] ), // Square expects quantity as a STRING
                'base_price_money' => array(
                    'amount'   => intval( round( floatval( $item['price'] ) * 100 ) ), // dollars -> integer cents
                    'currency' => 'USD',
                ),
            );
        }

        $body = array(
            'idempotency_key'  => $reference_id,
            'order'            => array(
                'location_id' => $settings['location_id'],
                'line_items'  => $square_line_items,
            ),
            'checkout_options' => array(
                // Placeholder - no dedicated post-payment landing page built yet.
                'redirect_url' => home_url(),
            ),
        );

        $base_url = self::get_api_base_url( $settings['environment'] );

        $response = wp_remote_post( $base_url . '/v2/online-checkout/payment-links', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $settings['access_token'],
                // Pinned to a specific Square API version per Square's versioning convention.
                // ponytail: verify this date against Square's current docs before going live -
                // Square API versions are date-stamped and this one will age out.
                'Square-Version' => '2024-01-18',
                'Content-Type'   => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'square', 'Payment link creation request failed: ' . $response->get_error_message() );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( 200 !== $response_code || empty( $data['payment_link']['id'] ) || empty( $data['payment_link']['url'] ) || empty( $data['payment_link']['order_id'] ) ) {
            subsales_log( 'ERROR', 'square', 'Payment link creation failed', array(
                'response_code' => $response_code,
                'response_body' => $response_body,
            ) );
            return false;
        }

        return array(
            'checkout_id'     => $data['payment_link']['id'],
            'checkout_url'    => $data['payment_link']['url'],
            'square_order_id' => $data['payment_link']['order_id'],
        );
    }

    /**
     * Best-effort cleanup: invalidate a payment link when a seller backs out.
     *
     * Fire-and-forget - not load-bearing (the expiry sweep is the real
     * backstop if this fails). A failure here must never surface as an
     * error to the caller.
     *
     * @param string $checkout_id
     * @return true Always returns true; failures are logged, not surfaced.
     */
    public static function delete_payment_link( $checkout_id ) {
        $settings = self::get_settings();
        if ( ! $settings || empty( $checkout_id ) ) {
            return true;
        }

        $base_url = self::get_api_base_url( $settings['environment'] );

        $response = wp_remote_post( $base_url . '/v2/online-checkout/payment-links/' . rawurlencode( $checkout_id ), array(
            'method'  => 'DELETE',
            'timeout' => 10,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $settings['access_token'],
                'Square-Version' => '2024-01-18',
                'Content-Type'   => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            subsales_log( 'WARNING', 'square', 'Payment link deletion failed (non-fatal): ' . $response->get_error_message() );
        } elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
            subsales_log( 'WARNING', 'square', 'Payment link deletion returned non-200 (non-fatal)', array(
                'response_code' => wp_remote_retrieve_response_code( $response ),
                'response_body' => wp_remote_retrieve_body( $response ),
            ) );
        }

        return true;
    }

    /**
     * Refund a captured payment in full.
     *
     * Square keeps its processing fee on a refund, so every cancelled digital
     * order costs the organisation roughly 2.6% + 10c of the original amount.
     * That is a business decision, not a technical one - this method just does
     * what it is told and reports honestly.
     *
     * Refunds are asynchronous: Square answers PENDING and settles to COMPLETED
     * later, so the returned status is the state at request time, not the final
     * outcome.
     *
     * @param string $payment_id     Square payment id (ss_payment_attempts.square_payment_id).
     * @param float  $amount         Amount in dollars.
     * @param string $idempotency_key Stable key so a retry cannot double-refund.
     * @param string $reason         Free text stored on the Square refund.
     * @return array ['ok'=>bool,'refund_id'=>string,'status'=>string,'message'=>string]
     */
    public static function refund_payment( $payment_id, $amount, $idempotency_key, $reason = '' ) {
        $settings = self::get_settings();
        if ( ! $settings ) {
            return array( 'ok' => false, 'refund_id' => '', 'status' => '', 'message' => 'Square is not configured.' );
        }

        $url = self::get_api_base_url( $settings['environment'] ) . '/v2/refunds';

        $body = array(
            'idempotency_key' => substr( (string) $idempotency_key, 0, 45 ),
            'payment_id'      => (string) $payment_id,
            'amount_money'    => array(
                // Square works in the currency's smallest unit; round before
                // casting so 20.00 can never arrive as 1999.
                'amount'   => (int) round( floatval( $amount ) * 100 ),
                'currency' => 'USD',
            ),
        );
        if ( '' !== $reason ) {
            $body['reason'] = substr( $reason, 0, 192 );
        }

        $response = wp_remote_post( $url, array(
            'timeout' => 20,
            'headers' => array(
                'Authorization'  => 'Bearer ' . $settings['access_token'],
                'Square-Version' => '2024-06-04',
                'Content-Type'   => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'square', 'Refund transport failure', array(
                'payment_id' => $payment_id,
                'error'      => $response->get_error_message(),
            ) );
            return array( 'ok' => false, 'refund_id' => '', 'status' => '', 'message' => $response->get_error_message() );
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $parsed = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 && isset( $parsed['refund'] ) ) {
            return array(
                'ok'        => true,
                'refund_id' => isset( $parsed['refund']['id'] ) ? $parsed['refund']['id'] : '',
                'status'    => isset( $parsed['refund']['status'] ) ? $parsed['refund']['status'] : '',
                'message'   => '',
            );
        }

        $detail = 'Square rejected the refund.';
        if ( ! empty( $parsed['errors'][0]['detail'] ) ) {
            $detail = $parsed['errors'][0]['detail'];
        }
        subsales_log( 'ERROR', 'square', 'Refund rejected', array(
            'payment_id' => $payment_id,
            'http'       => $code,
            'detail'     => $detail,
        ) );

        return array( 'ok' => false, 'refund_id' => '', 'status' => '', 'message' => $detail );
    }

    /**
     * Verify a Square webhook signature.
     *
     * VERIFY against Square's current webhook signature documentation before
     * relying on this in production - confirm the exact header name (likely
     * x-square-hmacsha256-signature or similar), the exact string being
     * signed (notification URL + raw body is Square's documented v2 scheme
     * as of this writing, but confirm), and whether Square base64-encodes
     * the raw HMAC bytes (assumed here) or hex-encodes them, since this
     * plugin has no prior precedent for this kind of check and getting it
     * wrong means either rejecting all real webhooks or accepting forged ones.
     *
     * @param string $notification_url      The exact URL Square was configured to call.
     * @param string $raw_body              The raw, unparsed request body.
     * @param string $signature_header      The signature value from the request header.
     * @param string $webhook_signature_key The configured webhook signature key.
     * @return bool
     */
    public static function verify_webhook_signature( $notification_url, $raw_body, $signature_header, $webhook_signature_key ) {
        if ( empty( $webhook_signature_key ) || empty( $signature_header ) ) {
            return false;
        }

        $expected = base64_encode( hash_hmac( 'sha256', $notification_url . $raw_body, $webhook_signature_key, true ) );

        return hash_equals( $expected, $signature_header );
    }
}
