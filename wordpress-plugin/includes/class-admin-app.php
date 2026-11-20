<?php
/**
 * Sub Sale Admin - the phone app for the people running a sale.
 *
 * A second installable app beside the seller one, served at /admin-portal/.
 * It shows what the desktop admin screens show - texts, orders, the day's
 * totals - and goes through the same code they do: the same order save, the
 * same SMS queue, the same tally arithmetic. Nothing here is a second copy of
 * a rule.
 *
 * Who gets in: a WordPress account with the subsales_admin_app capability.
 * Site administrators have it; the "Sub Sale Manager" role has it and nothing
 * else, so a parent helping on sale day never needs the keys to the website.
 *
 * Deliberately absent: deleting orders and refunding cards. Both are hard to
 * undo and stay on the desktop, where doing them is a decision, not a tap.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subsales_Admin_App {

	const CAP          = 'subsales_admin_app';
	const ROLE         = 'subsales_manager';
	const SESSION_SECS = 43200; // 12 hours from sign-in, not from last use.
	const MAX_TRIES    = 5;
	const LOCK_SECS    = 300;

	/** True only while this class is running an order save on a manager's behalf. */
	private static $acting = false;

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'catch_request' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );
	}

	/**
	 * The role and capability, created once per version rather than on
	 * activation - this plugin updates in place and is never re-activated.
	 */
	public static function ensure_role() {
		if ( get_option( 'subsales_admin_app_role' ) === SUBSALES_VERSION ) {
			return;
		}
		// No 'read': without it the account cannot open wp-admin at all.
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Sub Sale Manager', array( self::CAP => true ) );
		}
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
		update_option( 'subsales_admin_app_role', SUBSALES_VERSION, false );
	}

	public static function can() {
		return is_user_logged_in() && ( current_user_can( self::CAP ) || current_user_can( 'manage_options' ) );
	}

	/** Read by Subsales_Orders so a manager's edit is treated as an admin's. */
	public static function acting() {
		return self::$acting && self::can();
	}

	// ------------------------------------------------------------------
	// Serving the app
	// ------------------------------------------------------------------

	public static function catch_request() {
		$path = trim( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
		if ( $path !== 'admin-portal' && strpos( $path, 'admin-portal/' ) !== 0 ) {
			return;
		}

		global $wp_query;
		$wp_query->is_404 = false;
		status_header( 200 );
		nocache_headers();

		$file = substr( $path, strlen( 'admin-portal' ) );
		$file = ltrim( $file, '/' );

		if ( $file === 'manifest.json' ) {
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( array(
				'name'             => 'BKMB SubSales Admin',
				'short_name'       => 'BKMB Admin',
				'start_url'        => home_url( '/admin-portal/' ),
				'scope'            => home_url( '/admin-portal/' ),
				'display'          => 'standalone',
				'background_color' => '#7a1f1f',
				'theme_color'      => '#7a1f1f',
				'icons'            => array(
					array( 'src' => SUBSALES_PLUGIN_URL . 'pwa-admin/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png' ),
					array( 'src' => SUBSALES_PLUGIN_URL . 'pwa-admin/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png' ),
				),
			) );
			exit;
		}

		if ( $file === 'sw.js' ) {
			// Online-only: the worker exists so the app is installable, and
			// passes every request straight to the network. Nothing is cached,
			// so there is never a stale total or an old thread to explain.
			header( 'Content-Type: application/javascript; charset=utf-8' );
			echo "self.addEventListener('install',function(e){self.skipWaiting();});\n";
			echo "self.addEventListener('activate',function(e){e.waitUntil(self.clients.claim());});\n";
			echo "self.addEventListener('fetch',function(e){});\n";
			exit;
		}

		self::serve_shell();
		exit;
	}

	private static function serve_shell() {
		$user   = self::can() ? wp_get_current_user() : null;
		$config = array(
			'api'      => esc_url_raw( rest_url( 'order-manager/v1' ) ),
			'nonce'    => $user ? wp_create_nonce( 'wp_rest' ) : '',
			'user'     => $user ? $user->display_name : '',
			'brand'    => get_option( 'subsales_branding', 'Sub Sale' ),
			'version'  => SUBSALES_VERSION,
			'base'     => home_url( '/admin-portal/' ),
			// Signed in to WordPress but not allowed in here: say so, rather
			// than showing a sign-in form that would only refuse them again.
			'denied'   => ( is_user_logged_in() && ! $user ),
		);
		$v   = rawurlencode( SUBSALES_VERSION );
		$url = SUBSALES_PLUGIN_URL . 'pwa-admin/';

		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#7a1f1f">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="BKMB Admin">
<meta name="robots" content="noindex,nofollow">
<title>BKMB SubSales Admin</title>
<link rel="manifest" href="<?php echo esc_url( home_url( '/admin-portal/manifest.json' ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( $url . 'icon-192.png' ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $url . 'app.css?v=' . $v ); ?>">
</head>
<body>
<div id="app"></div>
<script>window.SUBSALES_ADMIN = <?php echo wp_json_encode( $config ); ?>;</script>
<script src="<?php echo esc_url( $url . 'app.js?v=' . $v ); ?>"></script>
</body>
</html>
		<?php
	}

	// ------------------------------------------------------------------
	// Routes
	// ------------------------------------------------------------------

	public static function register_routes() {
		$ns  = 'order-manager/v1';
		$can = array( __CLASS__, 'can' );

		register_rest_route( $ns, '/admin-app/login', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_login' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/admin-app/logout', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_logout' ), 'permission_callback' => '__return_true',
		) );
		register_rest_route( $ns, '/admin-app/dashboard', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_dashboard' ), 'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/admin-app/conversations', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_conversations' ), 'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/admin-app/thread', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_thread' ), 'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/admin-app/reply', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_reply' ), 'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/admin-app/orders', array(
			'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_orders' ), 'permission_callback' => $can,
		) );
		register_rest_route( $ns, '/admin-app/orders/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_order' ), 'permission_callback' => $can ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'rest_order_save' ), 'permission_callback' => $can ),
		) );
		register_rest_route( $ns, '/admin-app/orders/(?P<id>\d+)/lock', array(
			'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_lock' ), 'permission_callback' => $can,
		) );
	}

	// ------------------------------------------------------------------
	// Signing in
	// ------------------------------------------------------------------

	public static function rest_login( $request ) {
		$body = $request->get_json_params();
		$name = isset( $body['username'] ) ? sanitize_user( wp_unslash( $body['username'] ) ) : '';
		$pass = isset( $body['password'] ) ? (string) $body['password'] : '';

		// Five wrong tries, then a wait. Counted per address AND per account
		// name, so neither one account nor one phone can be ground through.
		$ip   = self::client_ip();
		$keys = array( 'ss_adm_try_' . md5( 'ip' . $ip ), 'ss_adm_try_' . md5( 'u' . strtolower( $name ) ) );
		foreach ( $keys as $k ) {
			if ( intval( get_transient( $k ) ) >= self::MAX_TRIES ) {
				return new WP_Error( 'locked', 'Too many tries. Wait five minutes and try again.', array( 'status' => 429 ) );
			}
		}

		$expiry = function() { return self::SESSION_SECS; };
		add_filter( 'auth_cookie_expiration', $expiry, 99 );
		$user = wp_signon( array( 'user_login' => $name, 'user_password' => $pass, 'remember' => true ), is_ssl() );
		remove_filter( 'auth_cookie_expiration', $expiry, 99 );

		$allowed = ! is_wp_error( $user ) && ( user_can( $user, self::CAP ) || user_can( $user, 'manage_options' ) );
		if ( ! $allowed ) {
			if ( ! is_wp_error( $user ) ) {
				wp_logout(); // right password, wrong person: do not leave them signed in
			}
			foreach ( $keys as $k ) {
				set_transient( $k, intval( get_transient( $k ) ) + 1, self::LOCK_SECS );
			}
			subsales_log( 'WARNING', 'admin-app', 'Sign-in refused', array( 'username' => $name ) );
			// One answer for a wrong password and a real account without access.
			return new WP_Error( 'denied', 'That username and password did not work.', array( 'status' => 401 ) );
		}

		foreach ( $keys as $k ) {
			delete_transient( $k );
		}
		subsales_log( 'INFO', 'admin-app', 'Signed in', array( 'user' => $user->user_login ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * The caller's address. Every request reaches WordPress from the proxy, so
	 * REMOTE_ADDR is the proxy's own address for everybody - counting failures
	 * against it would let five wrong guesses by anyone lock every admin out.
	 * The forwarded address is trusted only when the request really did come
	 * from a private (proxy) address.
	 */
	private static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$via_proxy = $remote !== '' && ! filter_var( $remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( $via_proxy && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$first = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return $remote;
	}

	public static function rest_logout() {
		wp_logout();
		return rest_ensure_response( array( 'success' => true ) );
	}

	// ------------------------------------------------------------------
	// Dashboard
	// ------------------------------------------------------------------

	/** The UTC window for one sale day. Orders are stored in UTC; a day is local. */
	private static function day_range_utc( $day ) {
		$tz    = wp_timezone();
		$start = new DateTime( $day . ' 00:00:00', $tz );
		$end   = new DateTime( $day . ' 23:59:59', $tz );
		$utc   = new DateTimeZone( 'UTC' );
		return array( $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
	}

	private static function local_day( $utc_datetime ) {
		$d = new DateTime( $utc_datetime, new DateTimeZone( 'UTC' ) );
		return $d->setTimezone( wp_timezone() )->format( 'Y-m-d' );
	}

	/** Attempt ids of card payments that were refunded, with what was refunded. */
	private static function refunded_attempts() {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results(
			"SELECT attempt_uid FROM {$wpdb->prefix}ss_payment_attempts WHERE refund_id IS NOT NULL AND refund_id <> ''",
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$out[ $r['attempt_uid'] ] = true;
		}
		return $out;
	}

	/** One order's money, the way the EOD tally counts it. */
	private static function order_money( $d ) {
		$subs = 0.0;
		$qty  = array();
		foreach ( (array) ( $d['products'] ?? array() ) as $p ) {
			$n = intval( $p['qty'] ?? 0 );
			if ( $n <= 0 ) {
				continue;
			}
			$subs += $n * floatval( $p['price'] ?? 0 );
			$name  = (string) ( $p['name'] ?? $p['id'] ?? 'Item' );
			$qty[ $name ] = ( $qty[ $name ] ?? 0 ) + $n;
		}
		$donation = floatval( $d['donationAmount'] ?? ( $d['donation'] ?? 0 ) );
		return array( 'subs' => $subs, 'donation' => $donation, 'total' => $subs + $donation, 'qty' => $qty );
	}

	public static function rest_dashboard( $request ) {
		global $wpdb;
		$season = intval( Subsales_Database::current_season_id() );
		$table  = $wpdb->prefix . 'ss_orders';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT order_data, created_at FROM {$table} WHERE season_id = %d AND deleted = 0",
			$season
		), ARRAY_A );

		// Sale days: the season's dates, plus any day that actually has orders.
		$today    = current_time( 'Y-m-d' );
		$per_day  = array();
		foreach ( (array) $rows as $r ) {
			$day = self::local_day( $r['created_at'] );
			$per_day[ $day ] = ( $per_day[ $day ] ?? 0 ) + 1;
		}
		$days = array_keys( $per_day );
		foreach ( (array) Subsales_Database::get_campaigns( 'active', $season ) as $c ) {
			if ( $c['campaign_date'] <= $today ) {
				$days[] = $c['campaign_date'];
			}
		}
		$days = array_values( array_unique( $days ) );
		sort( $days );

		$want = (string) $request->get_param( 'day' );
		if ( $want === 'season' ) {
			$day = 'season';
		} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $want ) ) {
			$day = $want;
		} elseif ( ! empty( $per_day[ $today ] ) ) {
			$day = $today;
		} else {
			// No sale today: the most recent day that has orders, not a
			// screen of zeros.
			$with = array_keys( $per_day );
			sort( $with );
			$day = $with ? end( $with ) : $today;
		}

		$refunded = self::refunded_attempts();
		$t = array(
			'orders' => 0, 'products' => array(), 'subs' => 0.0, 'donations' => 0.0, 'total' => 0.0,
			'cash' => 0.0, 'check' => 0.0, 'digital' => 0.0, 'none' => 0.0,
			'refunded_count' => 0, 'refunded_amount' => 0.0,
		);
		foreach ( (array) $rows as $r ) {
			if ( $day !== 'season' && self::local_day( $r['created_at'] ) !== $day ) {
				continue;
			}
			$d = json_decode( $r['order_data'], true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			$m   = self::order_money( $d );
			$uid = Subsales_Orders::payment_attempt_uid( $d );
			if ( $uid !== '' && isset( $refunded[ $uid ] ) ) {
				// Money that went back is not money collected.
				$t['refunded_count']++;
				$t['refunded_amount'] += $m['total'];
				continue;
			}
			$t['orders']++;
			foreach ( $m['qty'] as $name => $n ) {
				$t['products'][ $name ] = ( $t['products'][ $name ] ?? 0 ) + $n;
			}
			$t['subs']      += $m['subs'];
			$t['donations'] += $m['donation'];
			$t['total']     += $m['total'];
			$pm = strtolower( (string) ( $d['paymentMethod'] ?? ( $d['payment_method'] ?? '' ) ) );
			$t[ in_array( $pm, array( 'cash', 'check', 'digital' ), true ) ? $pm : 'none' ] += $m['total'];
		}

		return rest_ensure_response( array(
			'day'       => $day,
			'today'     => $today,
			'days'      => $days,
			'totals'    => $t,
			'unread'    => Subsales_SMS_Inbound::unread_count(),
			'as_of'     => time(),
		) );
	}

	// ------------------------------------------------------------------
	// Texts
	// ------------------------------------------------------------------

	/** phone => array of this season's orders for that number, newest first. */
	private static function orders_by_phone() {
		global $wpdb;
		$map  = array();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, order_id, order_data FROM {$wpdb->prefix}ss_orders WHERE season_id = %d AND deleted = 0 ORDER BY id DESC",
			intval( Subsales_Database::current_season_id() )
		), ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$d = json_decode( $r['order_data'], true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			$phone = Subsales_SMS_Inbound::normalize_phone( (string) ( $d['cellNumber'] ?? ( $d['cell'] ?? '' ) ) );
			if ( $phone === '' ) {
				continue;
			}
			$m = self::order_money( $d );
			$bits = array();
			foreach ( $m['qty'] as $name => $n ) {
				$bits[] = $n . ' ' . $name;
			}
			if ( $m['donation'] > 0 && ! $bits ) {
				$bits[] = 'Donation';
			}
			$map[ $phone ][] = array(
				'id'       => intval( $r['id'] ),
				'customer' => (string) ( $d['customer'] ?? '' ),
				'summary'  => implode( ', ', $bits ),
				'total'    => number_format( $m['total'], 2, '.', '' ),
			);
		}
		return $map;
	}

	public static function rest_conversations() {
		global $wpdb;
		$convos = (array) subsales_messages_convo_query();
		$orders = self::orders_by_phone();

		$ids = array();
		foreach ( $convos as $c ) {
			$ids[] = intval( $c['last_id'] );
		}
		$last = array();
		if ( $ids ) {
			$rows = $wpdb->get_results(
				"SELECT id, direction, body FROM {$wpdb->prefix}ss_sms_messages WHERE id IN (" . implode( ',', $ids ) . ')',
				ARRAY_A
			);
			foreach ( (array) $rows as $r ) {
				$last[ intval( $r['id'] ) ] = $r;
			}
		}

		$out = array();
		foreach ( $convos as $c ) {
			$phone = Subsales_SMS_Inbound::normalize_phone( $c['phone'] );
			$m     = $last[ intval( $c['last_id'] ) ] ?? null;
			$out[] = array(
				'phone'   => $c['phone'],
				'name'    => isset( $orders[ $phone ][0] ) ? $orders[ $phone ][0]['customer'] : '',
				'preview' => $m ? ( ( $m['direction'] === 'out' ? 'You: ' : '' ) . wp_html_excerpt( $m['body'], 70, '…' ) ) : '',
				'last_at' => strtotime( get_gmt_from_date( $c['last_at'] ) . ' UTC' ),
				'unread'  => intval( $c['unread'] ),
			);
		}
		// Newest activity first - the phone's own Messages order.
		usort( $out, function( $a, $b ) { return $b['last_at'] - $a['last_at']; } );

		return rest_ensure_response( array( 'conversations' => $out, 'unread' => Subsales_SMS_Inbound::unread_count() ) );
	}

	public static function rest_thread( $request ) {
		global $wpdb;
		$raw   = (string) $request->get_param( 'phone' );
		$phone = Subsales_SMS_Inbound::normalize_phone( $raw );
		if ( $phone === '' ) {
			return new WP_Error( 'nophone', 'No phone number.', array( 'status' => 400 ) );
		}
		$msgs = $wpdb->prefix . 'ss_sms_messages';

		// Opening a conversation reads it - for everyone, it is one inbox.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$msgs} SET read_at = %s WHERE phone = %s AND direction = 'in' AND read_at IS NULL",
			current_time( 'mysql' ), $phone
		) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, direction, body, status, created_at FROM {$msgs} WHERE phone = %s ORDER BY created_at ASC, id ASC",
			$phone
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'     => intval( $r['id'] ),
				'out'    => $r['direction'] === 'out',
				'body'   => (string) $r['body'],
				'status' => $r['status'],
				'at'     => strtotime( get_gmt_from_date( $r['created_at'] ) . ' UTC' ),
			);
		}

		$opted = $wpdb->get_var( $wpdb->prepare(
			"SELECT opted_out_at FROM {$wpdb->prefix}ss_sms_contacts WHERE phone = %s", $phone
		) );
		$orders = self::orders_by_phone();

		return rest_ensure_response( array(
			'phone'     => $phone,
			'name'      => isset( $orders[ $phone ][0] ) ? $orders[ $phone ][0]['customer'] : '',
			'messages'  => $out,
			'opted_out' => ! empty( $opted ),
			'orders'    => $orders[ $phone ] ?? array(),
		) );
	}

	public static function rest_reply( $request ) {
		global $wpdb;
		$body  = $request->get_json_params();
		$phone = isset( $body['phone'] ) ? (string) $body['phone'] : '';
		$text  = isset( $body['body'] ) ? (string) $body['body'] : '';

		// The desktop's own send: same opt-out check, same queue, same drain.
		$result = subsales_messages_send_reply( $phone, '', $text );

		if ( in_array( $result, array( 'ok', 'queued' ), true ) ) {
			// Who answered, kept on the message itself - the log is cleaned
			// out on a schedule and would forget.
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}ss_sms_messages SET sent_by = %s
				  WHERE phone = %s AND direction = 'out' AND message_type = 'reply' AND sent_by IS NULL
				  ORDER BY id DESC LIMIT 1",
				wp_get_current_user()->display_name,
				Subsales_SMS_Inbound::normalize_phone( $phone )
			) );
			return rest_ensure_response( array( 'success' => true, 'status' => $result ) );
		}

		$why = array(
			'opted_out' => 'This customer opted out of texts.',
			'empty'     => 'Type a message first.',
			'nophone'   => 'No phone number.',
		);
		return new WP_Error( $result, $why[ $result ] ?? 'The text could not be sent.', array( 'status' => 400 ) );
	}

	// ------------------------------------------------------------------
	// Orders
	// ------------------------------------------------------------------

	private static function order_row_for_app( $r, $full = false ) {
		$d = json_decode( $r['order_data'], true );
		if ( ! is_array( $d ) ) {
			$d = array();
		}
		$m   = self::order_money( $d );
		$out = array(
			'id'       => intval( $r['id'] ),
			'order_id' => $r['order_id'],
			'customer' => (string) ( $d['customer'] ?? '' ),
			'address'  => (string) ( $d['address'] ?? '' ),
			'payment'  => strtolower( (string) ( $d['paymentMethod'] ?? '' ) ),
			'total'    => number_format( $m['total'], 2, '.', '' ),
			'seller'   => (string) ( $d['entered_by_name'] ?? '' ),
			'team'     => (string) ( $d['teamName'] ?? '' ),
			'day'      => self::local_day( $r['created_at'] ),
		);
		if ( $full ) {
			$out['data'] = $d;
			$attempt     = Subsales_Orders::payment_attempt_for( $d );
			$out['digital_payment'] = $attempt ? array(
				'status'     => $attempt['status'],
				'payment_id' => $attempt['square_payment_id'],
				'total'      => number_format( floatval( $attempt['total_amount'] ), 2, '.', '' ),
				'fee'        => number_format( floatval( $attempt['convenience_fee_amount'] ), 2, '.', '' ),
				'refund_id'  => $attempt['refund_id'],
			) : null;
		}
		return $out;
	}

	public static function rest_orders( $request ) {
		global $wpdb;
		$q   = trim( (string) $request->get_param( 'q' ) );
		$day = (string) $request->get_param( 'day' );
		$pay = strtolower( (string) $request->get_param( 'pay' ) );

		$where  = array( 'season_id = %d', 'deleted = 0' );
		$values = array( intval( Subsales_Database::current_season_id() ) );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ) {
			list( $from, $to ) = self::day_range_utc( $day );
			$where[]  = 'created_at BETWEEN %s AND %s';
			$values[] = $from;
			$values[] = $to;
		}
		if ( $q !== '' ) {
			// ponytail: LIKE over the order JSON. Fine at a few thousand orders
			// a season; give customer/phone their own indexed columns if a
			// season ever outgrows it.
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$digits = preg_replace( '/\D/', '', $q );
			if ( strlen( $digits ) >= 4 ) {
				$where[]  = '( order_data LIKE %s OR order_data LIKE %s )';
				$values[] = $like;
				$values[] = '%' . $wpdb->esc_like( $digits ) . '%';
			} else {
				$where[]  = 'order_data LIKE %s';
				$values[] = $like;
			}
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, order_id, order_data, created_at FROM {$wpdb->prefix}ss_orders
			  WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT 300',
			$values
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$row = self::order_row_for_app( $r );
			if ( in_array( $pay, array( 'cash', 'check', 'digital' ), true ) && $row['payment'] !== $pay ) {
				continue;
			}
			$out[] = $row;
			if ( count( $out ) >= 100 ) {
				break;
			}
		}
		return rest_ensure_response( array( 'orders' => $out ) );
	}

	private static function order_by_db_id( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}ss_orders WHERE id = %d AND deleted = 0", intval( $id )
		), ARRAY_A );
	}

	public static function rest_order( $request ) {
		$r = self::order_by_db_id( $request->get_param( 'id' ) );
		if ( ! $r ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$out             = self::order_row_for_app( $r, true );
		$out['products'] = self::product_list();
		return rest_ensure_response( $out );
	}

	/** The current product list, as the seller app receives it. */
	private static function product_list() {
		$resp = function_exists( 'get_app_config' ) ? get_app_config( new WP_REST_Request( 'GET', '/order-manager/v1/config' ) ) : null;
		$data = $resp instanceof WP_REST_Response ? $resp->get_data() : array();
		return isset( $data['products'] ) ? $data['products'] : array();
	}

	/**
	 * Claim the edit lock - the same editing_by / editing_since marks the
	 * desktop writes - and say who held it, if someone else did recently.
	 */
	public static function rest_lock( $request ) {
		global $wpdb;
		$r = self::order_by_db_id( $request->get_param( 'id' ) );
		if ( ! $r ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$d    = json_decode( $r['order_data'], true );
		$d    = is_array( $d ) ? $d : array();
		$me   = wp_get_current_user()->display_name;
		$body = $request->get_json_params();

		if ( ! empty( $body['release'] ) ) {
			if ( ( $d['editing_by'] ?? '' ) === $me ) {
				unset( $d['editing_by'], $d['editing_since'] );
				$wpdb->update( $wpdb->prefix . 'ss_orders', array( 'order_data' => wp_json_encode( $d ) ), array( 'id' => intval( $r['id'] ) ) );
			}
			return rest_ensure_response( array( 'success' => true ) );
		}

		$held_by = '';
		$since   = ! empty( $d['editing_since'] ) ? strtotime( get_gmt_from_date( $d['editing_since'] ) . ' UTC' ) : 0;
		if ( ! empty( $d['editing_by'] ) && $d['editing_by'] !== $me && ( time() - $since ) < 900 && empty( $body['force'] ) ) {
			$held_by = $d['editing_by'];
		} else {
			$d['editing_by']    = $me;
			$d['editing_since'] = current_time( 'mysql' );
			$wpdb->update( $wpdb->prefix . 'ss_orders', array( 'order_data' => wp_json_encode( $d ) ), array( 'id' => intval( $r['id'] ) ) );
		}
		return rest_ensure_response( array( 'success' => true, 'held_by' => $held_by ) );
	}

	/**
	 * Save an order through the desktop's own update path, so the app and the
	 * desktop cannot behave differently: same validation, same history, same
	 * card-payment rules.
	 */
	public static function rest_order_save( $request ) {
		$r = self::order_by_db_id( $request->get_param( 'id' ) );
		if ( ! $r ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bad_request', 'Nothing to save.', array( 'status' => 400 ) );
		}
		unset( $data['editing_by'], $data['editing_since'] );

		$inner = new WP_REST_Request( 'PUT', '/order-manager/v1/orders/' . $r['order_id'] );
		$inner->set_param( 'id', $r['order_id'] );
		$inner->set_header( 'Content-Type', 'application/json' );
		$inner->set_body( wp_json_encode( $data ) );

		self::$acting = true;
		try {
			$result = Subsales_Orders::update_order( $inner );
		} finally {
			self::$acting = false;
		}
		return $result;
	}
}
