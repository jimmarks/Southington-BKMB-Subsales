<?php
/**
 * Twilio SMS API integration
 *
 * Pure Twilio API wrapper - no DB access, no hooks, no retries (retrying is
 * the queue worker's job). Mirrors Subsales_Square_Payments' style exactly:
 * single-attempt wp_remote_*() with an explicit timeout, is_wp_error() check,
 * wp_remote_retrieve_body() + json_decode(), subsales_log() on failure,
 * return false, no state.
 *
 * Differences from Square, all forced by Twilio's API:
 * - auth is HTTP Basic (base64 "AccountSid:AuthToken"), not a bearer token
 * - request bodies are form-encoded, not JSON
 * - there is one base URL, so no sandbox/production split
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Twilio_SMS {

    /**
     * Twilio's only API base. No sandbox/production split to switch on.
     */
    const API_BASE_URL = 'https://api.twilio.com/2010-04-01';

    /**
     * Read the currently-configured Twilio credentials and send settings.
     *
     * @return array|false Settings array, or false if not configured.
     */
    public static function get_settings() {
        $account_sid = get_option( 'subsales_twilio_account_sid', '' );
        $auth_token  = get_option( 'subsales_twilio_auth_token', '' );

        if ( empty( $account_sid ) || empty( $auth_token ) ) {
            subsales_log( 'ERROR', 'sms', 'Twilio credentials not configured' );
            return false;
        }

        // One number per line in the settings field; a pool is anticipated, so
        // this is always an array even when there is only one sender.
        $from_numbers = array_values( array_filter( array_map(
            'trim',
            preg_split( '/[\r\n,]+/', (string) get_option( 'subsales_twilio_from_numbers', '' ) )
        ) ) );

        return array(
            'enabled'         => (bool) get_option( 'subsales_sms_enabled', 0 ),
            'account_sid'     => $account_sid,
            'auth_token'      => $auth_token,
            'from_numbers'    => $from_numbers,
            'rate_per_second' => max( 1, intval( get_option( 'subsales_sms_rate_per_second', 1 ) ) ),
            'daily_cap'       => max( 0, intval( get_option( 'subsales_sms_daily_cap', 1000 ) ) ),
        );
    }

    /**
     * Send one SMS.
     *
     * SUCCESS: returns the Twilio message SID (a non-empty string).
     *
     * FAILURE: returns an array, never false, because the queue worker has to
     * be able to tell a retryable rejection (throughput exceeded, daily cap
     * reached - the message should go out later, or tomorrow) from a permanent
     * one (invalid number, unsubscribed recipient - retrying can only waste
     * attempts and never succeed). The shape is:
     *
     *   array(
     *     'error_code'    => int|null  Twilio's own numeric code (e.g. 21610
     *                                  "unsubscribed recipient", 21211 "invalid
     *                                  'To' number", 20429 "too many requests"),
     *                                  or null when the failure happened before
     *                                  Twilio answered (transport error, or a
     *                                  response we couldn't parse).
     *     'http_status'   => int       HTTP status, 0 if the request never landed.
     *     'message'       => string    Human-readable, safe to store in last_error.
     *   )
     *
     * Classification is deliberately NOT done here - the wrapper reports what
     * Twilio said, the queue decides what that means for retrying.
     *
     * @param string      $to   Destination number.
     * @param string      $body Message text.
     * @param string|null $from Sender number. Defaults to the first configured
     *                          sender; pass the contact's pinned assigned_number
     *                          to keep a returning customer on the same number.
     * @return string|array Message SID on success, failure array on failure.
     */
    public static function send( $to, $body, $from = null ) {
        $settings = self::get_settings();
        if ( ! $settings ) {
            return array(
                'error_code'  => null,
                'http_status' => 0,
                'message'     => 'Twilio credentials not configured',
            );
        }

        if ( empty( $from ) ) {
            $from = isset( $settings['from_numbers'][0] ) ? $settings['from_numbers'][0] : '';
        }

        if ( empty( $from ) ) {
            subsales_log( 'ERROR', 'sms', 'No Twilio sender number configured' );
            return array(
                'error_code'  => null,
                'http_status' => 0,
                'message'     => 'No Twilio sender number configured',
            );
        }

        $response = wp_remote_post( self::API_BASE_URL . '/Accounts/' . rawurlencode( $settings['account_sid'] ) . '/Messages.json', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => self::basic_auth_header( $settings['account_sid'], $settings['auth_token'] ),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            // Form-encoded explicitly rather than handing wp_remote_post an
            // array and relying on it to encode - the Content-Type above is
            // set by hand, so the body has to match it by hand too.
            'body'    => http_build_query( array(
                'To'   => $to,
                'From' => $from,
                'Body' => $body,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'sms', 'Twilio send request failed: ' . $response->get_error_message() );
            return array(
                'error_code'  => null,
                'http_status' => 0,
                'message'     => $response->get_error_message(),
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( $response_code < 200 || $response_code > 299 || empty( $data['sid'] ) ) {
            subsales_log( 'ERROR', 'sms', 'Twilio send failed', array(
                'response_code' => $response_code,
                'response_body' => $response_body,
            ) );

            return array(
                'error_code'  => isset( $data['code'] ) ? intval( $data['code'] ) : null,
                'http_status' => intval( $response_code ),
                'message'     => isset( $data['message'] ) ? $data['message'] : ( 'Twilio send failed (HTTP ' . $response_code . ')' ),
            );
        }

        return $data['sid'];
    }

    /**
     * Verify a Twilio webhook signature (X-Twilio-Signature).
     *
     * VERIFY against a real Twilio delivery before relying on this - it is
     * written from Twilio's documented scheme and has never seen an actual
     * request. The documented scheme: take the full URL Twilio was configured
     * to call INCLUDING its query string, append each POST parameter as
     * key immediately followed by value, sorted by key, HMAC-SHA1 the result
     * with the auth token, base64 the raw bytes. Getting the URL half wrong
     * (dropping the query string, or a http/https or www mismatch with what is
     * configured in the Twilio Console) rejects every genuine webhook, which
     * is the failure mode to expect first.
     *
     * @param string $url       The exact URL Twilio was configured to call, query string included.
     * @param array  $params    The POST parameters as received.
     * @param string $signature The X-Twilio-Signature header value.
     * @return bool
     */
    public static function verify_webhook_signature( $url, $params, $signature ) {
        $settings = self::get_settings();
        if ( ! $settings || empty( $signature ) ) {
            return false;
        }

        $data = (string) $url;

        $params = (array) $params;
        ksort( $params );
        foreach ( $params as $key => $value ) {
            $data .= $key . $value;
        }

        $expected = base64_encode( hash_hmac( 'sha1', $data, $settings['auth_token'], true ) );

        return hash_equals( $expected, $signature );
    }

    /**
     * Cheap read-only credential probe for the settings page's Test button.
     *
     * Fetches the account itself - no message is sent and nothing is charged.
     *
     * @param string $sid   Account SID to test.
     * @param string $token Auth token to test.
     * @return array|false ['friendly_name','status'] on success, false on failure.
     */
    public static function test_credentials( $sid, $token ) {
        if ( empty( $sid ) || empty( $token ) ) {
            return false;
        }

        $response = wp_remote_get( self::API_BASE_URL . '/Accounts/' . rawurlencode( $sid ) . '.json', array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => self::basic_auth_header( $sid, $token ),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            subsales_log( 'ERROR', 'sms', 'Twilio credential test request failed: ' . $response->get_error_message() );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        if ( 200 !== $response_code || empty( $data['sid'] ) ) {
            subsales_log( 'ERROR', 'sms', 'Twilio credential test failed', array(
                'response_code' => $response_code,
                'response_body' => $response_body,
            ) );
            return false;
        }

        return array(
            'friendly_name' => isset( $data['friendly_name'] ) ? $data['friendly_name'] : $data['sid'],
            'status'        => isset( $data['status'] ) ? $data['status'] : '',
        );
    }

    /**
     * Build the HTTP Basic Authorization header value.
     *
     * @param string $sid
     * @param string $token
     * @return string
     */
    private static function basic_auth_header( $sid, $token ) {
        return 'Basic ' . base64_encode( $sid . ':' . $token );
    }
}
