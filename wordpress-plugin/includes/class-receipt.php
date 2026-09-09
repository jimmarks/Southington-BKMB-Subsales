<?php
/**
 * The customer's receipt page.
 *
 * The order confirmation text used to spell the whole order out, which made it
 * two segments and grew with the order - ten products would have run to four or
 * five. A link is a fixed length forever, so the text costs the same whatever
 * was bought, and the page can show far more than a text ever could.
 *
 * Deliberately NOT shown here: the seller's name. This is a public URL and the
 * seller is a child. Everything else on the page is the customer's own order,
 * which they already know.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Subsales_Receipt {

	/** Path on the main site. The short host redirects here. */
	const PATH = 'receipt';

	/** Ten characters of base62 - about 59 bits, not guessable. */
	const TOKEN_LENGTH = 10;

	const DEFAULT_DAYS = 90;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	/**
	 * Ambiguous characters left out on purpose: a customer reading the link off
	 * a screen to somebody else should not have to distinguish O from 0.
	 */
	public static function generate_token() {
		$alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
		$max      = strlen( $alphabet ) - 1;
		$token    = '';
		for ( $i = 0; $i < self::TOKEN_LENGTH; $i++ ) {
			$token .= $alphabet[ random_int( 0, $max ) ];
		}
		return $token;
	}

	/**
	 * The token for an order, created on first use.
	 *
	 * Lazy rather than only at order-creation time so an order taken before this
	 * existed can still be sent a receipt.
	 *
	 * @param string $order_id Client order id.
	 * @return string|false
	 */
	public static function token_for( $order_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'ss_orders';

		$token = $wpdb->get_var( $wpdb->prepare(
			"SELECT receipt_token FROM {$table} WHERE order_id = %s",
			$order_id
		) );

		if ( ! empty( $token ) ) {
			return $token;
		}

		// Retry on the astronomically unlikely collision rather than handing back
		// a token that belongs to somebody else's order.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate = self::generate_token();
			$updated   = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET receipt_token = %s
				  WHERE order_id = %s AND (receipt_token IS NULL OR receipt_token = '')
				    AND NOT EXISTS ( SELECT 1 FROM ( SELECT 1 FROM {$table} WHERE receipt_token = %s ) x )",
				$candidate,
				$order_id,
				$candidate
			) );

			if ( $updated ) {
				return $candidate;
			}

			// Either it collided, or another request got there first.
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT receipt_token FROM {$table} WHERE order_id = %s",
				$order_id
			) );
			if ( ! empty( $existing ) ) {
				return $existing;
			}
		}

		subsales_log( 'ERROR', 'receipt', 'Could not allocate a receipt token', array( 'order_id' => $order_id ) );
		return false;
	}

	/**
	 * The URL to put in a text.
	 *
	 * Uses the short host when one is set, because what the message costs is
	 * decided by the characters in it, not by where they lead.
	 */
	public static function url( $token ) {
		$short = trim( (string) get_option( 'subsales_receipt_short_host', '' ) );
		if ( '' !== $short ) {
			return 'https://' . rtrim( $short, '/' ) . '/' . rawurlencode( $token );
		}
		return home_url( '/' . self::PATH . '/' . rawurlencode( $token ) );
	}

	/** How long a receipt link stays alive, in days from when the order was taken. */
	public static function days() {
		$days = intval( get_option( 'subsales_receipt_link_days', self::DEFAULT_DAYS ) );
		return $days > 0 ? $days : self::DEFAULT_DAYS;
	}

	public static function maybe_render() {
		$path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
		$want = self::PATH . '/';

		if ( 0 !== strpos( $path, $want ) ) {
			return;
		}

		$token = substr( $path, strlen( $want ) );
		$token = preg_replace( '/[^A-Za-z0-9]/', '', rawurldecode( $token ) );

		if ( '' === $token ) {
			self::render_missing();
		}

		global $wpdb;
		$order = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}ss_orders WHERE receipt_token = %s AND deleted = 0",
			$token
		), ARRAY_A );

		if ( ! $order ) {
			self::render_missing();
		}

		$age_days = ( time() - strtotime( $order['created_at'] . ' UTC' ) ) / DAY_IN_SECONDS;
		if ( $age_days > self::days() ) {
			self::render_expired();
		}

		self::render( $order );
	}

	private static function render_missing() {
		status_header( 404 );
		self::page( 'Receipt not found', '<p>This link is not one of ours, or the order has been removed. If you were sent a text about an order, reply to it and we will pick it up.</p>' );
	}

	private static function render_expired() {
		status_header( 410 );
		self::page( 'This receipt has expired', '<p>This receipt is from an earlier sale and is no longer online. If you still need it, reply to the text we sent you about the order.</p>' );
	}

	private static function render( $order ) {
		// WordPress has already decided this URL matches no post and set a 404.
		// Without putting that right, a perfectly good receipt is served with a
		// "not found" status - link previews treat it as broken and caches will
		// not keep it.
		global $wp_query;
		if ( isset( $wp_query ) ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );

		$od = json_decode( $order['order_data'], true );
		if ( ! is_array( $od ) ) {
			self::render_missing();
		}

		$rows  = '';
		$total = 0.0;
		foreach ( (array) ( $od['products'] ?? array() ) as $p ) {
			$qty = intval( $p['qty'] ?? 0 );
			if ( $qty < 1 ) {
				continue;
			}
			$price = (float) ( $p['price'] ?? 0 );
			$line  = $qty * $price;
			$total += $line;
			$rows  .= sprintf(
				'<tr><td>%s</td><td class="num">%d</td><td class="num">$%s</td></tr>',
				esc_html( $p['name'] ?? 'Item' ),
				$qty,
				esc_html( number_format( $line, 2 ) )
			);
		}

		$donation = (float) ( $od['donationAmount'] ?? 0 );
		if ( $donation > 0 ) {
			$total += $donation;
			$rows  .= sprintf(
				'<tr><td>Donation</td><td class="num">&mdash;</td><td class="num">$%s</td></tr>',
				esc_html( number_format( $donation, 2 ) )
			);
		}

		$paid = ucfirst( (string) ( $od['paymentMethod'] ?? '' ) );

		$body  = '<p class="lede">Thank you for supporting the Southington BKMB sub sale.</p>';
		$body .= '<table class="items"><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Amount</th></tr></thead><tbody>';
		$body .= $rows;
		$body .= sprintf(
			'</tbody><tfoot><tr><th>Total</th><th class="num"></th><th class="num">$%s</th></tr></tfoot></table>',
			esc_html( number_format( $total, 2 ) )
		);

		$body .= '<dl class="meta">';
		$body .= '<dt>Ordered</dt><dd>' . esc_html( get_date_from_gmt( $order['created_at'], 'l j F Y, g:i A' ) ) . '</dd>';
		if ( ! empty( $od['address'] ) ) {
			$body .= '<dt>Delivering to</dt><dd>' . esc_html( $od['address'] ) . '</dd>';
		}
		if ( ! empty( $od['notes'] ) ) {
			$body .= '<dt>Delivery notes</dt><dd>' . esc_html( $od['notes'] ) . '</dd>';
		}
		if ( '' !== $paid ) {
			$body .= '<dt>Paid by</dt><dd>' . esc_html( $paid ) . '</dd>';
		}
		$body .= '</dl>';

		self::page( 'Your sub order', $body );
	}

	/**
	 * One self-contained page: no theme, no plugins, nothing that can be slow.
	 * A customer opens this at a doorstep on whatever signal they have.
	 */
	private static function page( $title, $body ) {
		$org = trim( (string) get_option( 'subsales_branding', 'Southington BKMB' ) );

		// No phone number and no email address here on purpose. Every reply to
		// the receipt text lands against this order, where the answer is written
		// with the order in front of whoever answers it. A phone number here
		// sends the customer somewhere that knows nothing about their order and
		// leaves no record - the whole point of two-way texting is that the
		// order, the question and the answer stay together.

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive' );

		?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' - ' . $org ); ?></title>
<style>
  :root{ --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f8fafc; --card:#fff; --accent:#1d4ed8; }
  *{ box-sizing:border-box; }
  body{ margin:0; background:var(--bg); color:var(--ink);
        font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
  .wrap{ max-width:34rem; margin:0 auto; padding:24px 18px 48px; }
  .card{ background:var(--card); border:1px solid var(--line); border-radius:14px; padding:22px; }
  h1{ font-size:1.35rem; margin:0 0 4px; }
  .org{ color:var(--muted); font-size:.9rem; margin:0 0 18px; }
  .lede{ margin:0 0 18px; }
  table.items{ width:100%; border-collapse:collapse; margin:0 0 18px; }
  table.items th, table.items td{ padding:9px 0; border-bottom:1px solid var(--line); text-align:left; }
  table.items thead th{ font-size:.75rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); }
  table.items tfoot th{ border-bottom:0; border-top:2px solid var(--ink); font-size:1.05rem; }
  .num{ text-align:right; font-variant-numeric:tabular-nums; }
  dl.meta{ margin:0; display:grid; grid-template-columns:auto 1fr; gap:6px 14px; font-size:.92rem; }
  dl.meta dt{ color:var(--muted); }
  dl.meta dd{ margin:0; }
  code{ background:var(--bg); padding:2px 6px; border-radius:5px; font-size:.85rem; }
  .contact{ margin-top:22px; font-size:.92rem; color:var(--muted); }
  .contact a{ color:var(--accent); }
  .fine{ margin-top:20px; font-size:.78rem; color:var(--muted); line-height:1.45; }
  @media (prefers-color-scheme: dark){
    :root{ --ink:#e5e7eb; --muted:#9ca3af; --line:#374151; --bg:#0f172a; --card:#1e293b; --accent:#93b4fd; }
  }
</style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <h1><?php echo esc_html( $title ); ?></h1>
      <p class="org"><?php echo esc_html( $org ); ?></p>
      <?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts above. ?>
      <div class="contact">
        <strong>Need to change something, or have a question?</strong><br>
        Just reply to the text message we sent you. It comes straight to us and stays
        with your order, so whoever picks it up can see exactly what you ordered.
        <br>Please don't ask the student who took your order &mdash; they can't change it once it's placed.
      </div>
    </div>
  </div>
</body>
</html>
		<?php
		exit;
	}
}
