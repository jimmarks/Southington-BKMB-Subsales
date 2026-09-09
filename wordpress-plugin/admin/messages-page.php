<?php
/**
 * Text Messages: what customers have sent back.
 *
 * Two views in one screen. The list is every inbound message, newest first.
 * Opening one shows the order it belongs to and the whole conversation for
 * that order, so the person answering can see what the customer was replying
 * to without cross-referencing anything.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function subsales_messages_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions' );
	}

	global $wpdb;
	$msgs   = $wpdb->prefix . 'ss_sms_messages';
	$thread = isset( $_GET['thread'] ) ? sanitize_text_field( wp_unslash( $_GET['thread'] ) ) : '';
	$phone  = isset( $_GET['phone'] ) ? sanitize_text_field( wp_unslash( $_GET['phone'] ) ) : '';

	// Marking read is a state change, so it needs a nonce and a POST-style
	// guard even though it arrives as a link.
	if ( isset( $_GET['mark_read'] ) && check_admin_referer( 'subsales_mark_read' ) ) {
		$id = intval( $_GET['mark_read'] );
		$wpdb->update( $msgs, array( 'read_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		echo '<div class="notice notice-success is-dismissible"><p>Marked as read.</p></div>';
	}
	if ( isset( $_GET['mark_all_read'] ) && check_admin_referer( 'subsales_mark_read' ) ) {
		$n = $wpdb->query( $wpdb->prepare(
			"UPDATE {$msgs} SET read_at = %s WHERE direction = 'in' AND read_at IS NULL",
			current_time( 'mysql', true )
		) );
		printf( '<div class="notice notice-success is-dismissible"><p>%d marked as read.</p></div>', intval( $n ) );
	}

	// Sending a reply. Handled before any output so a redirect is still possible
	// and a refresh cannot send the same message twice.
	if ( isset( $_POST['subsales_send_reply'] ) ) {
		check_admin_referer( 'subsales_send_reply' );
		$to   = sanitize_text_field( wp_unslash( $_POST['reply_phone'] ?? '' ) );
		$oid  = sanitize_text_field( wp_unslash( $_POST['reply_order_id'] ?? '' ) );
		$text = trim( wp_unslash( $_POST['reply_body'] ?? '' ) );

		$sent = subsales_messages_send_reply( $to, $oid, $text );

		// PRG: the browser lands on a GET, so refreshing the thread does not
		// re-post the message.
		wp_safe_redirect( add_query_arg(
			array(
				'page'   => 'subsales-messages',
				'thread' => rawurlencode( $oid ),
				'phone'  => rawurlencode( $to ),
				'sent'   => $sent,
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	subsales_messages_styles();

	echo '<div class="wrap"><h1>Text Messages</h1>';

	if ( isset( $_GET['sent'] ) ) {
		$outcome = sanitize_text_field( wp_unslash( $_GET['sent'] ) );
		$notes   = array(
			'ok'        => array( 'success', 'Reply sent.' ),
			'queued'    => array( 'success', 'Reply queued - it will go out within about 30 seconds.' ),
			'empty'     => array( 'error', 'Nothing to send - the message was empty.' ),
			'opted_out' => array( 'error', 'That customer has replied STOP, so no message was sent.' ),
			'nophone'   => array( 'error', 'No phone number to reply to.' ),
			'failed'    => array( 'error', 'The message could not be sent. Check the Text Messages settings and the logs.' ),
		);
		if ( isset( $notes[ $outcome ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $notes[ $outcome ][0] ),
				esc_html( $notes[ $outcome ][1] )
			);
		}
	}

	if ( $thread || $phone ) {
		subsales_messages_thread_view( $thread, $phone );
	} else {
		subsales_messages_list_view();
	}

	echo '</div>';
}

/** Every reply, newest first. */
function subsales_messages_list_view() {
	global $wpdb;
	$msgs   = $wpdb->prefix . 'ss_sms_messages';
	$orders = $wpdb->prefix . 'ss_orders';

	$rows = $wpdb->get_results(
		"SELECT m.id, m.phone, m.body, m.order_id, m.created_at, m.read_at,
		        o.order_data
		   FROM {$msgs} m
		   LEFT JOIN {$orders} o ON o.order_id = m.order_id AND o.deleted = 0
		  WHERE m.direction = 'in'
		  ORDER BY m.created_at DESC
		  LIMIT 200",
		ARRAY_A
	);

	$unread = Subsales_SMS_Inbound::unread_count();

	echo '<p class="description" style="margin:8px 0 14px">';
	if ( $unread > 0 ) {
		printf(
			'<strong>%d unread.</strong> ',
			intval( $unread )
		);
		printf(
			'<a href="%s" class="button button-small">Mark all read</a>',
			esc_url( wp_nonce_url( admin_url( 'admin.php?page=subsales-messages&mark_all_read=1' ), 'subsales_mark_read' ) )
		);
	} else {
		echo 'Nothing unread.';
	}
	echo '</p>';

	if ( ! $rows ) {
		echo '<div class="notice notice-info inline"><p>No replies yet. Customers who reply to their receipt will appear here.</p></div>';
		echo '<p class="description">If replies are not arriving, check that the phone number in Twilio has this address set as its messaging webhook:<br><code>' . esc_html( rest_url( 'order-manager/v1/sms/inbound' ) ) . '</code></p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	echo '<th style="width:150px">When</th><th style="width:130px">From</th><th>Message</th><th style="width:200px">Order</th><th style="width:110px"></th>';
	echo '</tr></thead><tbody>';

	foreach ( $rows as $r ) {
		$od       = $r['order_data'] ? json_decode( $r['order_data'], true ) : null;
		$customer = ( $od && ! empty( $od['customer'] ) ) ? $od['customer'] : '';
		$is_new   = empty( $r['read_at'] );

		$link = add_query_arg(
			array( 'page' => 'subsales-messages', 'thread' => rawurlencode( (string) $r['order_id'] ), 'phone' => rawurlencode( $r['phone'] ) ),
			admin_url( 'admin.php' )
		);

		printf(
			'<tr%s><td>%s</td><td>%s</td><td>%s%s</td><td>%s</td><td><a class="button button-small" href="%s">Open</a></td></tr>',
			$is_new ? ' style="font-weight:600;background:#fff8e6"' : '',
			esc_html( get_date_from_gmt( $r['created_at'], 'M j, Y g:i A' ) ),
			esc_html( subsales_format_phone( $r['phone'] ) ),
			$is_new ? '<span class="dashicons dashicons-marker" style="color:#d63638" title="Unread"></span> ' : '',
			esc_html( wp_trim_words( (string) $r['body'], 18 ) ),
			$customer ? esc_html( $customer ) : '<em style="color:#888">no matching order</em>',
			esc_url( $link )
		);
	}
	echo '</tbody></table>';
}

/** One order, its details, and the whole conversation with that customer. */
function subsales_messages_thread_view( $order_id, $phone ) {
	global $wpdb;
	$msgs   = $wpdb->prefix . 'ss_sms_messages';
	$orders = $wpdb->prefix . 'ss_orders';

	printf(
		'<p><a href="%s">&larr; All messages</a></p>',
		esc_url( admin_url( 'admin.php?page=subsales-messages' ) )
	);

	// The order this conversation is about.
	$order = $order_id
		? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$orders} WHERE order_id = %s", $order_id ), ARRAY_A )
		: null;

	// Two columns: the conversation is what you came to read, so it gets the
	// width; the order is reference material and sits beside it rather than
	// above it, where it pushed the messages off the screen. Stacks below
	// 960px, where two columns of this width stop being readable.
	echo '<div class="subsales-thread-grid">';
	echo '<div class="subsales-thread-main">';
	subsales_messages_conversation( $phone, $order_id );
	echo '</div>';
	echo '<div class="subsales-thread-side">';

	if ( $order ) {
		$od    = json_decode( $order['order_data'], true );
		$items = array();
		foreach ( (array) ( $od['products'] ?? array() ) as $p ) {
			if ( ! empty( $p['qty'] ) ) {
				$items[] = $p['name'] . ' &times; ' . intval( $p['qty'] );
			}
		}
		echo '<div class="card subsales-order-card">';
		echo '<h2 style="margin-top:0">' . esc_html( $od['customer'] ?? 'Order' ) . '</h2>';
		echo '<table class="widefat striped"><tbody>';
		printf( '<tr><th style="width:170px">Address</th><td>%s</td></tr>', esc_html( $od['address'] ?? '' ) );
		printf( '<tr><th>Items</th><td>%s</td></tr>', wp_kses_post( implode( ', ', $items ) ) );
		printf( '<tr><th>Donation</th><td>%s</td></tr>', esc_html( '$' . number_format( (float) ( $od['donationAmount'] ?? 0 ), 2 ) ) );
		printf( '<tr><th>Paid by</th><td>%s</td></tr>', esc_html( ucfirst( (string) ( $od['paymentMethod'] ?? '' ) ) ) );
		printf( '<tr><th>Delivery notes</th><td>%s</td></tr>', esc_html( $od['notes'] ?? '' ) );
		printf( '<tr><th>Taken by</th><td>%s</td></tr>', esc_html( $od['entered_by_name'] ?: ( 'user ' . $order['user_id'] ) ) );
		printf( '<tr><th>Order taken</th><td>%s</td></tr>', esc_html( get_date_from_gmt( $order['created_at'], 'M j, Y g:i A' ) ) );
		printf(
			'<tr><th>Order id</th><td><code>%s</code> &middot; <a href="%s">open in Orders</a></td></tr>',
			esc_html( $order['order_id'] ),
			esc_url( admin_url( 'admin.php?page=subsales-orders&search_query=' . rawurlencode( (string) ( $od['cellNumber'] ?? '' ) ) . '&season_id=0' ) )
		);
		echo '</tbody></table></div>';
	} elseif ( $order_id ) {
		echo '<div class="notice notice-warning inline"><p>The order this reply was matched to is no longer available.</p></div>';
	} else {
		echo '<div class="notice notice-warning inline"><p>This number has no order on file, so there is nothing to match the reply to.</p></div>';
	}

	echo '</div></div>';
	return;
}

/**
 * The messages themselves. Keyed on the phone rather than the order, because a
 * customer with two orders is still one person having one conversation.
 */
function subsales_messages_conversation( $phone, $order_id ) {
	global $wpdb;
	$msgs = $wpdb->prefix . 'ss_sms_messages';

	$thread = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, direction, body, status, skip_reason, created_at, sent_at, read_at
		   FROM {$msgs} WHERE phone = %s ORDER BY created_at ASC",
		$phone
	), ARRAY_A );

	echo '<h2>Conversation</h2>';
	if ( ! $thread ) {
		echo '<p class="description">Nothing yet.</p>';
		return;
	}

	echo '<div class="subsales-thread-scroll">';
	foreach ( $thread as $m ) {
		$inbound = ( 'in' === $m['direction'] );
		$when    = get_date_from_gmt( $m['sent_at'] ?: $m['created_at'], 'M j, g:i A' );

		printf(
			'<div style="display:flex;justify-content:%s;margin:8px 0"><div style="max-width:78%%;padding:9px 13px;border-radius:12px;background:%s;border:1px solid %s">%s<div style="font-size:11px;color:#667085;margin-top:5px">%s &middot; %s</div></div></div>',
			$inbound ? 'flex-start' : 'flex-end',
			$inbound ? '#fff8e6' : '#eef4ff',
			$inbound ? '#f5c86b' : '#c3d4f7',
			esc_html( (string) $m['body'] ) ?: '<em style="color:#888">(no message body)</em>',
			esc_html( $inbound ? 'From customer' : 'Sent' ),
			esc_html( $when . ( 'skipped' === $m['status'] ? ' — not sent: ' . $m['skip_reason'] : '' ) )
		);

		if ( $inbound && empty( $m['read_at'] ) ) {
			printf(
				'<div style="text-align:left;margin:-4px 0 10px"><a class="button button-small" href="%s">Mark read</a></div>',
				esc_url( wp_nonce_url(
					add_query_arg(
						array( 'page' => 'subsales-messages', 'thread' => rawurlencode( (string) $order_id ), 'phone' => rawurlencode( $phone ), 'mark_read' => intval( $m['id'] ) ),
						admin_url( 'admin.php' )
					),
					'subsales_mark_read'
				) )
			);
		}
	}
	echo '</div>';

	subsales_messages_reply_box( $order_id, $phone );
}

/**
 * The reply box.
 *
 * Hidden entirely when the customer has opted out - offering a box that cannot
 * send is worse than saying plainly why, and the queue would refuse the message
 * at send time anyway.
 */
function subsales_messages_reply_box( $order_id, $phone ) {
	global $wpdb;

	if ( '' === trim( (string) $phone ) ) {
		echo '<p class="description" style="margin-top:16px">No number on this conversation, so there is nobody to reply to.</p>';
		return;
	}

	$contact = $wpdb->get_row( $wpdb->prepare(
		"SELECT opted_out_at FROM {$wpdb->prefix}ss_sms_contacts WHERE phone = %s",
		$phone
	), ARRAY_A );

	if ( $contact && ! empty( $contact['opted_out_at'] ) ) {
		printf(
			'<div class="notice notice-warning inline" style="margin-top:16px"><p><strong>This customer has opted out of texts.</strong> They replied STOP on %s, so no reply can be sent. Call them on %s instead.</p></div>',
			esc_html( get_date_from_gmt( $contact['opted_out_at'], 'M j, Y' ) ),
			esc_html( subsales_format_phone( $phone ) )
		);
		return;
	}

	?>
	<div style="max-width:720px;margin-top:18px">
		<h2 style="margin-bottom:6px">Reply</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=subsales-messages' ) ); ?>">
			<?php wp_nonce_field( 'subsales_send_reply' ); ?>
			<input type="hidden" name="reply_phone" value="<?php echo esc_attr( $phone ); ?>" />
			<input type="hidden" name="reply_order_id" value="<?php echo esc_attr( $order_id ); ?>" />
			<textarea name="reply_body" id="subsales-reply-body" rows="3" class="large-text"
				placeholder="Type your reply to <?php echo esc_attr( subsales_format_phone( $phone ) ); ?>" required></textarea>
			<p class="description" style="margin:6px 0 10px">
				<span id="subsales-reply-count">0 characters</span> &middot;
				Goes to <?php echo esc_html( subsales_format_phone( $phone ) ); ?> from the club's number.
				Sign off with your name &mdash; the customer sees the club, not you.
			</p>
			<button type="submit" name="subsales_send_reply" value="1" class="button button-primary">Send reply</button>
		</form>
	</div>
	<script>
	(function(){
		// A text costs money per 160 characters, and a chair typing a long
		// answer has no other way to know they have crossed into a second one.
		var box = document.getElementById('subsales-reply-body');
		var out = document.getElementById('subsales-reply-count');
		if (!box || !out) return;
		box.addEventListener('input', function(){
			var n = box.value.length;
			var segs = n === 0 ? 0 : (n <= 160 ? 1 : Math.ceil(n / 153));
			out.textContent = n + ' character' + (n === 1 ? '' : 's') +
				(segs > 1 ? ' — ' + segs + ' texts' : (segs === 1 ? ' — 1 text' : ''));
		});
	})();
	</script>
	<?php
}

/**
 * Queue an outbound reply and try to send it straight away.
 *
 * Unlike a receipt, this is somebody sitting at a screen waiting to see it go,
 * so the send is attempted in this request rather than left for the next drain.
 * It still goes through the queue, which is what re-checks opt-out immediately
 * before sending and records the outcome either way.
 *
 * @return string One of ok|queued|empty|opted_out|nophone|failed.
 */
function subsales_messages_send_reply( $phone, $order_id, $body ) {
	global $wpdb;

	$phone = Subsales_SMS_Inbound::normalize_phone( $phone );
	if ( '' === $phone ) {
		return 'nophone';
	}
	if ( '' === trim( (string) $body ) ) {
		return 'empty';
	}

	$contact = $wpdb->get_row( $wpdb->prepare(
		"SELECT opted_out_at FROM {$wpdb->prefix}ss_sms_contacts WHERE phone = %s",
		$phone
	), ARRAY_A );
	if ( $contact && ! empty( $contact['opted_out_at'] ) ) {
		return 'opted_out';
	}

	$queued = Subsales_SMS_Queue::enqueue( array(
		'direction'    => 'out',
		'message_type' => 'reply',
		'phone'        => $phone,
		'body'         => wp_strip_all_tags( $body ),
		'order_id'     => $order_id ?: null,
		'status'       => 'queued',
	) );

	if ( ! $queued ) {
		return 'failed';
	}

	subsales_log( 'INFO', 'sms', 'Admin replied to a customer', array(
		'phone'    => $phone,
		'order_id' => $order_id ?: '(none)',
		'by'       => wp_get_current_user()->user_login,
	) );

	// Send now rather than waiting for the cron. This is an admin at a desk,
	// not a seller at a door, so a Twilio round trip in this request is fine.
	Subsales_SMS_Queue::drain();

	$status = $wpdb->get_var( $wpdb->prepare(
		"SELECT status FROM {$wpdb->prefix}ss_sms_messages
		  WHERE phone = %s AND direction = 'out' AND message_type = 'reply'
		  ORDER BY id DESC LIMIT 1",
		$phone
	) );

	if ( 'sent' === $status ) {
		return 'ok';
	}
	if ( 'skipped' === $status ) {
		return 'opted_out';
	}
	if ( 'failed' === $status ) {
		return 'failed';
	}
	return 'queued';
}

/** (860) 555-1234 reads faster than 8605551234 when you are dialling it. */
function subsales_format_phone( $digits ) {
	$d = preg_replace( '/\D+/', '', (string) $digits );
	if ( 10 === strlen( $d ) ) {
		return sprintf( '(%s) %s-%s', substr( $d, 0, 3 ), substr( $d, 3, 3 ), substr( $d, 6 ) );
	}
	return $digits;
}

/**
 * Screen styles.
 *
 * Two columns, conversation first because that is what the page is for. The
 * order sits beside it and sticks while the messages scroll, so the details you
 * need while typing a reply stay on screen. Below 960px they stack - two
 * columns of this width stop being readable before that.
 */
function subsales_messages_styles() {
	?>
	<style>
	.subsales-thread-grid{
		display:grid;
		grid-template-columns:2fr 1fr;   /* 66 / 33 */
		gap:22px;
		align-items:start;
		margin-top:12px;
	}
	.subsales-thread-side .subsales-order-card{
		padding:14px 16px;
		margin:0;
		max-width:none;
		position:sticky;
		top:46px;                        /* clears the admin bar */
	}
	.subsales-thread-side .subsales-order-card h2{ margin:0 0 10px; font-size:1.1rem; }
	.subsales-thread-side .subsales-order-card th{ width:38%; font-weight:600; }
	.subsales-thread-side .subsales-order-card td,
	.subsales-thread-side .subsales-order-card th{ padding:6px 8px; font-size:13px; line-height:1.4; }
	.subsales-thread-side .subsales-order-card code{ font-size:11px; word-break:break-all; }

	/* The messages get their own scroll rather than the page growing without
	   limit - a long conversation should not push the reply box off screen. */
	.subsales-thread-scroll{
		max-height:min(58vh, 620px);
		overflow-y:auto;
		overscroll-behavior:contain;
		padding-right:6px;
	}

	.subsales-thread-main h2{ margin-top:0; }

	@media (max-width:960px){
		.subsales-thread-grid{ grid-template-columns:1fr; }
		.subsales-thread-side .subsales-order-card{ position:static; }
		.subsales-thread-scroll{ max-height:none; overflow:visible; }
	}
	</style>
	<?php
}
