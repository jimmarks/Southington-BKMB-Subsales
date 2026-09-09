<?php
/**
 * Inbound SMS: Twilio's webhook, the message store, and the unread count.
 *
 * Until this existed, a customer who replied to their receipt reached nobody -
 * the message sat in Twilio's console unread, and the receipt invites a reply
 * by saying "Reply STOP". Every inbound message is now stored against the order
 * it belongs to, so a chair can read the whole conversation for an order in one
 * place instead of guessing which "can you come after 5?" belongs to whom.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subsales_SMS_Inbound {

	/**
	 * Twilio's opt-out and help keywords. Twilio's Advanced Opt-Out handles
	 * these at the carrier level before we ever see them, so this is a record
	 * of what happened, not the mechanism - never the only thing standing
	 * between a customer and another text.
	 */
	const STOP_WORDS = array( 'STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'OPTOUT' );
	const START_WORDS = array( 'START', 'YES', 'UNSTOP' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_node' ), 80 );
		add_action( 'wp_ajax_subsales_unread_sms_count', array( __CLASS__, 'ajax_unread_count' ) );
		add_action( 'wp_ajax_subsales_thread_since', array( __CLASS__, 'ajax_thread_since' ) );
	}

	/**
	 * Unread count for the dashboard's polling loop, so a reply that arrives
	 * while somebody is looking at the screen actually shows up there.
	 */
	public static function ajax_unread_count() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}
		check_ajax_referer( 'subsales_unread_sms', 'nonce' );
		wp_send_json_success( array( 'count' => self::unread_count() ) );
	}

	public static function register_routes() {
		register_rest_route(
			'order-manager/v1',
			'/sms/inbound',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				// Twilio cannot authenticate; the signature check inside the
				// handler is the gate, and it runs before anything is stored.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify Twilio's signature over the request.
	 *
	 * Twilio signs the full URL it posted to, with every POST parameter sorted
	 * by name and appended as key then value, HMAC-SHA1 with the account's auth
	 * token, base64. Getting the URL wrong is the classic failure - it must be
	 * the address Twilio was configured with, character for character.
	 *
	 * @param string $url    The URL Twilio posted to.
	 * @param array  $params POST parameters.
	 * @param string $sig    Value of the X-Twilio-Signature header.
	 * @param string $token  Twilio auth token.
	 * @return bool
	 */
	public static function verify_signature( $url, $params, $sig, $token ) {
		if ( empty( $sig ) || empty( $token ) ) {
			return false;
		}
		$data = $url;
		ksort( $params );
		foreach ( $params as $k => $v ) {
			$data .= $k . ( is_scalar( $v ) ? (string) $v : '' );
		}
		$expected = base64_encode( hash_hmac( 'sha1', $data, $token, true ) );
		return hash_equals( $expected, (string) $sig );
	}

	/**
	 * The webhook itself.
	 *
	 * Always answers Twilio with empty TwiML and a 200 once the signature is
	 * good. Twilio retries anything else, and a retry storm on a message we
	 * have already stored helps nobody - the UNIQUE key on twilio_sid means a
	 * retry that does arrive is stored once.
	 */
	public static function handle_webhook( $request ) {
		$params = $request->get_body_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		$settings = Subsales_Twilio_SMS::get_settings();
		if ( ! $settings || empty( $settings['auth_token'] ) ) {
			subsales_log( 'ERROR', 'sms', 'Inbound SMS received but Twilio is not configured' );
			return new WP_Error( 'not_configured', 'Not configured', array( 'status' => 403 ) );
		}

		$url = rest_url( ltrim( $request->get_route(), '/' ) );
		$sig = $request->get_header( 'x-twilio-signature' );

		if ( ! self::verify_signature( $url, $params, $sig, $settings['auth_token'] ) ) {
			subsales_log( 'ERROR', 'sms', 'Inbound SMS signature verification failed', array( 'url' => $url ) );
			return new WP_Error( 'invalid_signature', 'Signature verification failed', array( 'status' => 403 ) );
		}

		$from = isset( $params['From'] ) ? self::normalize_phone( $params['From'] ) : '';
		$body = isset( $params['Body'] ) ? trim( (string) $params['Body'] ) : '';
		$sid  = isset( $params['MessageSid'] ) ? sanitize_text_field( $params['MessageSid'] ) : '';

		if ( '' === $from ) {
			subsales_log( 'WARNING', 'sms', 'Inbound SMS with no sender, ignored' );
			return self::twiml();
		}

		$order_id = self::latest_order_id_for_phone( $from );

		Subsales_SMS_Queue::enqueue( array(
			'direction'    => 'in',
			'message_type' => 'reply',
			'phone'        => $from,
			'body'         => $body,
			'order_id'     => $order_id,
			'status'       => 'received',
			'twilio_sid'   => $sid,
		) );

		self::record_keyword( $from, $body );

		subsales_log( 'INFO', 'sms', 'Inbound SMS stored', array(
			'phone'    => $from,
			'order_id' => $order_id ? $order_id : '(no matching order)',
		) );

		return self::twiml();
	}

	/**
	 * Empty TwiML. Replying with message text here would send a text back on
	 * our dime and, worse, would be an automated reply nobody wrote.
	 */
	private static function twiml() {
		// Returning the XML as a value gets it JSON-encoded by the REST server -
		// Twilio then receives a quoted string instead of TwiML and logs a
		// content-type error against every single message. Serve it directly and
		// tell the server it has already been served.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_twiml' ) );
		return new WP_REST_Response( null, 200 );
	}

	public static function serve_twiml( $served ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/xml; charset=utf-8' );
		}
		echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
		return true;
	}

	/**
	 * Keep the contact's opt-out state in step with what the customer said.
	 * The carrier has already acted on it; this stops us showing a chair a
	 * contact as reachable when they are not.
	 */
	private static function record_keyword( $phone, $body ) {
		$word = strtoupper( trim( preg_replace( '/[^A-Za-z]/', '', $body ) ) );
		if ( '' === $word ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'ss_sms_contacts';

		if ( in_array( $word, self::STOP_WORDS, true ) ) {
			$wpdb->update( $table, array( 'opted_out_at' => current_time( 'mysql', true ) ), array( 'phone' => $phone ), array( '%s' ), array( '%s' ) );
			subsales_log( 'INFO', 'sms', 'Contact opted out by replying ' . $word, array( 'phone' => $phone ) );
		} elseif ( in_array( $word, self::START_WORDS, true ) ) {
			$wpdb->update( $table, array( 'opted_out_at' => null ), array( 'phone' => $phone ), array( '%s' ), array( '%s' ) );
			subsales_log( 'INFO', 'sms', 'Contact opted back in by replying ' . $word, array( 'phone' => $phone ) );
		}
	}

	/**
	 * Ten digits, the same shape the order form stores, so a reply from
	 * "+18605551234" matches an order saved as "8605551234".
	 */
	public static function normalize_phone( $raw ) {
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
			$digits = substr( $digits, 1 );
		}
		return ( 10 === strlen( $digits ) ) ? $digits : '';
	}

	/**
	 * The order a reply most likely refers to: that number's most recent one.
	 * A customer texting back is answering the last thing we sent them.
	 */
	public static function latest_order_id_for_phone( $phone ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT order_id FROM {$wpdb->prefix}ss_orders
			  WHERE deleted = 0
			    AND JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.cellNumber')) = %s
			  ORDER BY created_at DESC LIMIT 1",
			$phone
		) );
	}

	/**
	 * Is there anything in this conversation newer than what the page is
	 * showing? Returns the highest message id for the number, so the browser
	 * can compare against what it rendered without shipping the messages twice.
	 */
	public static function ajax_thread_since() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions' );
		}
		check_ajax_referer( 'subsales_thread_since', 'nonce' );

		global $wpdb;
		$phone = self::normalize_phone( wp_unslash( $_POST['phone'] ?? '' ) );
		if ( '' === $phone ) {
			wp_send_json_error( 'No phone' );
		}

		$max = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(MAX(id), 0) FROM {$wpdb->prefix}ss_sms_messages WHERE phone = %s",
			$phone
		) );

		wp_send_json_success( array( 'max_id' => $max, 'unread' => self::unread_count() ) );
	}

	/** Unread inbound messages. Cheap: covered by idx_inbound_unread. */
	public static function unread_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}ss_sms_messages WHERE direction = 'in' AND read_at IS NULL"
		);
	}

	/**
	 * A count in the admin bar, so a reply is noticed without anyone
	 * remembering to go and look for it.
	 */
	public static function admin_bar_node( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n     = self::unread_count();
		$label = $n > 0
			? sprintf( '<span class="ab-icon dashicons dashicons-email-alt" style="top:2px"></span><span class="ab-label">%d</span>', $n )
			: '<span class="ab-icon dashicons dashicons-email-alt" style="top:2px"></span><span class="ab-label">0</span>';

		$bar->add_node( array(
			'id'    => 'subsales-inbound',
			'title' => $label,
			'href'  => admin_url( 'admin.php?page=subsales-messages' ),
			'meta'  => array(
				'title' => $n > 0
					? sprintf( _n( '%d unread text reply', '%d unread text replies', $n, 'subsales' ), $n )
					: 'No unread text replies',
			),
		) );

		if ( $n > 0 ) {
			add_action( 'admin_head', array( __CLASS__, 'admin_bar_style' ) );
			add_action( 'wp_head', array( __CLASS__, 'admin_bar_style' ) );
		}
	}

	/** Unread is worth seeing from across a room; read is not. */
	public static function admin_bar_style() {
		echo '<style>#wpadminbar #wp-admin-bar-subsales-inbound .ab-label{background:#d63638;color:#fff;border-radius:9px;padding:0 7px;margin-left:2px;font-weight:600;}</style>';
	}
}
