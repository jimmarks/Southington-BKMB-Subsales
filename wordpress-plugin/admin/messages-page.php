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

/**
 * Send a reply, then redirect.
 *
 * On admin_init rather than inside the page callback. The callback runs after
 * WordPress has begun printing the admin page, so wp_safe_redirect() there hits
 * "headers already sent", returns false, and the exit that follows truncates the
 * page - the message sends and the user gets a blank screen.
 */
function subsales_messages_handle_reply() {
	if ( ! isset( $_POST['subsales_send_reply'] ) ) {
		return;
	}
	if ( ! isset( $_GET['page'] ) || 'subsales-messages' !== $_GET['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions' );
	}
	check_admin_referer( 'subsales_send_reply' );

	$to   = sanitize_text_field( wp_unslash( $_POST['reply_phone'] ?? '' ) );
	$oid  = sanitize_text_field( wp_unslash( $_POST['reply_order_id'] ?? '' ) );
	$text = trim( wp_unslash( $_POST['reply_body'] ?? '' ) );

	$sent = subsales_messages_send_reply( $to, $oid, $text );

	// PRG: the browser lands on a GET, so refreshing does not resend.
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
add_action( 'admin_init', 'subsales_messages_handle_reply' );

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
	if ( isset( $_GET['mark_all_read'] ) && check_admin_referer( 'subsales_mark_read' ) ) {
		$n = $wpdb->query( $wpdb->prepare(
			"UPDATE {$msgs} SET read_at = %s WHERE direction = 'in' AND read_at IS NULL",
			current_time( 'mysql', true )
		) );
		printf( '<div class="notice notice-success is-dismissible"><p>%d marked as read.</p></div>', intval( $n ) );
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

/**
 * One row per conversation, not one per message.
 *
 * A list of individual messages made every reply look like a separate piece of
 * work; what an admin actually has is a handful of ongoing conversations. Rows
 * are keyed on the phone number - one person, one row - carrying their latest
 * message, when it happened, and how many of theirs are still unread.
 */
function subsales_messages_list_view() {
	global $wpdb;
	$msgs   = $wpdb->prefix . 'ss_sms_messages';
	$orders = $wpdb->prefix . 'ss_orders';

	$convos = subsales_messages_convo_query();

	if ( ! $convos ) {
		echo '<div class="notice notice-info inline"><p>No conversations yet. Once a customer replies to their receipt they will appear here.</p></div>';
		echo '<p class="description">If replies are not arriving, check that the phone number in Twilio has this address set as its messaging webhook:<br><code>' . esc_html( rest_url( 'order-manager/v1/sms/inbound' ) ) . '</code></p>';
		return;
	}

	// The latest message on each conversation, in one query rather than one per row.
	$last_ids = array_map( 'intval', wp_list_pluck( $convos, 'last_id' ) );
	$in       = implode( ',', $last_ids );
	$latest   = array();
	foreach ( $wpdb->get_results( "SELECT id, body, direction, order_id, status FROM {$msgs} WHERE id IN ({$in})", ARRAY_A ) as $row ) {
		$latest[ intval( $row['id'] ) ] = $row;
	}

	// And the customer's name, from their most recent order.
	$names = array();
	$rows  = $wpdb->get_results(
		"SELECT o.order_id,
		        JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.cellNumber')) AS ph,
		        JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.customer'))   AS cust
		   FROM {$orders} o
		   JOIN ( SELECT JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.cellNumber')) AS ph2,
		                 MAX(id) AS mid
		            FROM {$orders} WHERE deleted = 0
		           GROUP BY ph2 ) x
		     ON x.mid = o.id",
		ARRAY_A
	);
	foreach ( $rows as $r ) {
		if ( ! empty( $r['ph'] ) ) {
			$names[ $r['ph'] ] = array( 'customer' => $r['cust'], 'order_id' => $r['order_id'] );
		}
	}

	$total_unread = Subsales_SMS_Inbound::unread_count();
	echo '<p class="description" style="margin:8px 0 14px">';
	if ( $total_unread > 0 ) {
		printf(
			'<strong>%d unread.</strong> <a href="%s" class="button button-small">Mark all read</a>',
			intval( $total_unread ),
			esc_url( wp_nonce_url( admin_url( 'admin.php?page=subsales-messages&mark_all_read=1' ), 'subsales_mark_read' ) )
		);
	} else {
		echo 'Nothing unread.';
	}
	echo '</p>';

	echo '<table class="widefat striped subsales-convo-table"><thead><tr>';
	echo '<th>Customer</th><th style="width:140px">Phone</th><th>Last message</th>';
	echo '<th style="width:170px">Last activity</th><th style="width:80px">Unread</th><th style="width:90px"></th>';
	echo '</tr></thead><tbody id="subsales-convo-body">';
	echo subsales_messages_convo_rows(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	echo '</tbody></table>';

	subsales_messages_list_poller();
}

/** Waiting longest first, then most recent. Shared by the page and the refresh. */
function subsales_messages_convo_rows() {
	global $wpdb;
	$msgs   = $wpdb->prefix . 'ss_sms_messages';
	$orders = $wpdb->prefix . 'ss_orders';

	$convos = subsales_messages_convo_query();
	if ( ! $convos ) {
		return '<tr><td colspan="6">No conversations yet.</td></tr>';
	}

	$last_ids = array_map( 'intval', wp_list_pluck( $convos, 'last_id' ) );
	$in       = implode( ',', $last_ids );
	$latest   = array();
	foreach ( $wpdb->get_results( "SELECT id, body, direction, order_id, status FROM {$msgs} WHERE id IN ({$in})", ARRAY_A ) as $row ) {
		$latest[ intval( $row['id'] ) ] = $row;
	}

	$names = array();
	$rows  = $wpdb->get_results(
		"SELECT o.order_id,
		        JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.cellNumber')) AS ph,
		        JSON_UNQUOTE(JSON_EXTRACT(o.order_data, '$.customer'))   AS cust
		   FROM {$orders} o
		   JOIN ( SELECT JSON_UNQUOTE(JSON_EXTRACT(order_data, '$.cellNumber')) AS ph2,
		                 MAX(id) AS mid
		            FROM {$orders} WHERE deleted = 0
		           GROUP BY ph2 ) x
		     ON x.mid = o.id",
		ARRAY_A
	);
	foreach ( $rows as $r ) {
		if ( ! empty( $r['ph'] ) ) {
			$names[ $r['ph'] ] = array( 'customer' => $r['cust'], 'order_id' => $r['order_id'] );
		}
	}

	$out = '';
	foreach ( $convos as $c ) {
		$last   = isset( $latest[ intval( $c['last_id'] ) ] ) ? $latest[ intval( $c['last_id'] ) ] : array();
		$known  = isset( $names[ $c['phone'] ] ) ? $names[ $c['phone'] ] : null;
		$unread = intval( $c['unread'] );

		$order_id = $last['order_id'] ?? ( $known['order_id'] ?? '' );
		$link     = add_query_arg(
			array( 'page' => 'subsales-messages', 'thread' => rawurlencode( (string) $order_id ), 'phone' => rawurlencode( $c['phone'] ) ),
			admin_url( 'admin.php' )
		);

		$body = trim( (string) ( $last['body'] ?? '' ) );
		if ( '' === $body ) {
			$body = 'skipped' === ( $last['status'] ?? '' ) ? '(not sent)' : '(no message)';
		}
		$prefix = ( 'out' === ( $last['direction'] ?? '' ) ) ? '<span class="subsales-convo-dir">You:</span> ' : '';

		$out .= sprintf(
			'<tr%s><td><strong>%s</strong></td><td class="subsales-convo-phone">%s</td><td>%s%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Open</a></td></tr>',
			$unread > 0 ? ' class="subsales-convo-unread"' : '',
			$known ? esc_html( $known['customer'] ) : '<span class="subsales-convo-unknown">Unknown</span>',
			esc_html( subsales_format_phone( $c['phone'] ) ),
			$prefix,
			esc_html( wp_trim_words( $body, 14 ) ),
			esc_html( get_date_from_gmt( $c['last_at'], 'M j, Y g:i A' ) ),
			$unread > 0 ? '<span class="subsales-convo-badge">' . $unread . '</span>' : '',
			esc_url( $link )
		);
	}

	return $out;
}

/** The grouped query, in one place so the page and the refresh cannot diverge. */
function subsales_messages_convo_query() {
	global $wpdb;
	$msgs = $wpdb->prefix . 'ss_sms_messages';
	return $wpdb->get_results(
		"SELECT phone,
		        MAX(id) AS last_id,
		        MAX(created_at) AS last_at,
		        COUNT(*) AS total,
		        SUM(CASE WHEN direction = 'in' AND read_at IS NULL THEN 1 ELSE 0 END) AS unread,
		        MIN(CASE WHEN direction = 'in' AND read_at IS NULL THEN created_at END) AS oldest_unread
		   FROM {$msgs}
		  WHERE phone <> ''
		  GROUP BY phone
		  ORDER BY (SUM(CASE WHEN direction = 'in' AND read_at IS NULL THEN 1 ELSE 0 END) > 0) DESC,
		           oldest_unread ASC,
		           last_at DESC
		  LIMIT 200",
		ARRAY_A
	);
}

/** Refresh the list in place, so a new reply arrives without a page reload. */
function subsales_messages_list_poller() {
	?>
	<script>
	(function(){
		var nonce = <?php echo wp_json_encode( wp_create_nonce( 'subsales_thread_since' ) ); ?>;
		var body  = document.getElementById('subsales-convo-body');
		if (!body) { return; }
		var busy = false;
		function poll(){
			if (busy || document.hidden) { return; }
			busy = true;
			var p = new URLSearchParams();
			p.append('action', 'subsales_convo_list');
			p.append('nonce', nonce);
			fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: p })
				.then(function(r){ return r.json(); })
				.then(function(j){
					busy = false;
					if (!j || !j.success) { return; }
					// Replace only when something changed, so a row is never
					// redrawn under a cursor that is about to click it.
					if (j.data.html && j.data.html !== body.innerHTML) { body.innerHTML = j.data.html; }
					var bar = document.querySelector('#wp-admin-bar-subsales-inbound .ab-label');
					if (bar) { bar.textContent = j.data.unread; }
					var chip = document.getElementById('unreadSmsCount');
					if (chip) { chip.textContent = j.data.unread; }
				})
				.catch(function(){ busy = false; });
		}
		setInterval(poll, 10000);
		document.addEventListener('visibilitychange', function(){ if (!document.hidden) { poll(); } });
	})();
	</script>
	<?php
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

	// Opening the thread is reading it. Making somebody click "mark read" on
	// each message is bookkeeping for the app's benefit, not the reader's.
	subsales_messages_mark_thread_read( $phone );

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
		// Order total, worked out the same way the Orders screen does it:
		// products plus the donation, which is part of what was charged.
		$order_total = 0.0;
		foreach ( (array) ( $od['products'] ?? array() ) as $p ) {
			$order_total += intval( $p['qty'] ?? 0 ) * floatval( $p['price'] ?? 0 );
		}
		$donation     = (float) ( $od['donationAmount'] ?? 0 );
		$order_total += $donation;

		printf( '<tr><th style="width:40%%">Address</th><td>%s</td></tr>', esc_html( $od['address'] ?? '' ) );
		printf( '<tr><th>Phone</th><td>%s</td></tr>', esc_html( subsales_format_phone( $od['cellNumber'] ?? '' ) ) );
		printf( '<tr><th>Items</th><td>%s</td></tr>', wp_kses_post( $items ? implode( '<br>', $items ) : '&mdash;' ) );
		if ( $donation > 0 ) {
			printf( '<tr><th>Donation</th><td>%s</td></tr>', esc_html( '$' . number_format( $donation, 2 ) ) );
		}
		printf( '<tr><th>Total</th><td><strong>%s</strong></td></tr>', esc_html( '$' . number_format( $order_total, 2 ) ) );
		printf( '<tr><th>Paid by</th><td>%s</td></tr>', esc_html( ucfirst( (string) ( $od['paymentMethod'] ?? '' ) ) ?: '&mdash;' ) );
		if ( ! empty( $od['notes'] ) ) {
			printf( '<tr><th>Delivery notes</th><td>%s</td></tr>', esc_html( $od['notes'] ) );
		}
		printf( '<tr><th>Taken by</th><td>%s</td></tr>', esc_html( $od['entered_by_name'] ?: ( 'user ' . $order['user_id'] ) ) );
		printf( '<tr><th>Order taken</th><td>%s</td></tr>', esc_html( get_date_from_gmt( $order['created_at'], 'M j, Y g:i A' ) ) );
		printf( '<tr><th>Order id</th><td><code style="font-size:11px">%s</code></td></tr>', esc_html( $order['order_id'] ) );
		echo '</tbody></table>';

		// Opens the same dialog the Orders screen uses, here, without leaving the
		// conversation - answering "I need to change my order" should not start
		// by navigating away from the person asking.
		printf(
			'<p style="margin:12px 0 4px"><button type="button" class="button button-primary" onclick="SubsalesOrderEdit.editOrder(%d, %s)">Edit this order</button></p>',
			intval( $order['id'] ),
			wp_json_encode( (string) $order['order_id'] )
		);

		subsales_messages_order_history( intval( $order['id'] ) );

		echo '</div>';
	} elseif ( $order_id ) {
		echo '<div class="notice notice-warning inline"><p>The order this reply was matched to is no longer available.</p></div>';
	} else {
		echo '<div class="notice notice-warning inline"><p>This number has no order on file, so there is nothing to match the reply to.</p></div>';
	}

	echo '</div></div>';

	// The dialog itself, and a redraw after a save - on this screen the panel
	// beside the conversation is what needs refreshing, and it is server-rendered.
	global $wpdb;
	$teams         = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}ss_teams ORDER BY name ASC", ARRAY_A );
	$products_conf = function_exists( 'order_sync_get_products_config' ) ? order_sync_get_products_config() : array();
	echo '<script>window.fetchPage = function(){ location.reload(); };</script>';
	include SUBSALES_PLUGIN_PATH . 'admin/partials/order-edit-modal.php';

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

	$max_rendered = 0;
	foreach ( $thread as $m ) {
		$max_rendered = max( $max_rendered, intval( $m['id'] ) );
	}

	echo '<div class="subsales-thread-scroll" id="subsales-thread-scroll">';
	foreach ( $thread as $m ) {
		echo subsales_messages_bubble( $m, $order_id, $phone ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	}
	echo '</div>';

	subsales_messages_thread_poller( $phone, $max_rendered, $order_id );
	subsales_messages_reply_box( $order_id, $phone );
}

/**
 * Watch for anything new in this conversation.
 *
 * Polls for the highest message id rather than re-fetching the messages, so the
 * common answer - nothing new - costs one small query. When something does
 * arrive it reloads once, which keeps one renderer for the thread instead of a
 * second copy of the markup living in JavaScript.
 */
function subsales_messages_thread_poller( $phone, $max_rendered, $order_id = '' ) {
	?>
	<script>
	(function(){
		var phone   = <?php echo wp_json_encode( $phone ); ?>;
		var orderId = <?php echo wp_json_encode( (string) $order_id ); ?>;
		var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'subsales_thread_since' ) ); ?>;
		var seen    = <?php echo intval( $max_rendered ); ?>;
		var box     = document.getElementById('subsales-thread-scroll');
		if (!box) { return; }

		// Open at the newest message, the way every messaging app does.
		box.scrollTop = box.scrollHeight;

		function atBottom(){
			// Within a few pixels counts as "following along" - if the reader has
			// scrolled up to re-read something, do not yank them back down.
			return (box.scrollHeight - box.scrollTop - box.clientHeight) < 40;
		}

		var busy = false;
		function poll(){
			if (busy || document.hidden) { return; }   // a hidden tab polls nothing
			busy = true;
			var body = new URLSearchParams();
			body.append('action', 'subsales_thread_new');
			body.append('nonce', nonce);
			body.append('phone', phone);
			body.append('order_id', orderId);
			body.append('since_id', seen);
			fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: body })
				.then(function(r){ return r.json(); })
				.then(function(j){
					busy = false;
					if (!j || !j.success) { return; }
					if (j.data.count > 0 && j.data.html) {
						var stick = atBottom();
						box.insertAdjacentHTML('beforeend', j.data.html);
						seen = parseInt(j.data.max_id, 10) || seen;
						if (stick) { box.scrollTop = box.scrollHeight; }
					}
					var bar = document.querySelector('#wp-admin-bar-subsales-inbound .ab-label');
					if (bar) { bar.textContent = j.data.unread; }
				})
				.catch(function(){ busy = false; });
		}

		setInterval(poll, 10000);
		// Catch up straight away when the tab comes back rather than waiting.
		document.addEventListener('visibilitychange', function(){ if (!document.hidden) { poll(); } });
	})();
	</script>
	<?php
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
		<form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => 'subsales-messages', 'thread' => rawurlencode( (string) $order_id ), 'phone' => rawurlencode( $phone ) ), admin_url( 'admin.php' ) ) ); ?>">
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

	/* Conversation list: one row per person. Unread rows carry weight so a
	   glance down the column finds them without reading anything. */
	.subsales-convo-table td{ vertical-align:middle; }
	.subsales-convo-unread td{ background:#fff8e6; font-weight:600; }
	.subsales-convo-phone{ font-variant-numeric:tabular-nums; white-space:nowrap; }
	.subsales-convo-unknown{ color:#8c8f94; font-style:italic; font-weight:400; }
	.subsales-convo-dir{ color:#667085; font-weight:400; }
	.subsales-convo-badge{
		display:inline-block; min-width:20px; padding:1px 7px; border-radius:10px;
		background:#d63638; color:#fff; font-size:11px; font-weight:700; text-align:center;
	}

	/* Message bubbles. Theirs on the left in warm grey, ours on the right in
	   blue - the arrangement everyone already knows from their phone. */
	.subsales-msg{ display:flex; margin:8px 0; }
	.subsales-msg.is-in{ justify-content:flex-start; }
	.subsales-msg.is-out{ justify-content:flex-end; }
	.subsales-msg .subsales-bubble{
		max-width:78%; padding:9px 13px; border-radius:12px;
		border:1px solid #f5c86b; background:#fff8e6;
	}
	.subsales-msg.is-out .subsales-bubble{ border-color:#c3d4f7; background:#eef4ff; }
	.subsales-msg-meta{ font-size:11px; color:#667085; margin-top:5px; }
	.subsales-msg-empty{ color:#8c8f94; }
	.subsales-msg-markread{ text-align:left; margin:-4px 0 10px; }

	/* History, under the order details. Compact - it is reference, not the
	   subject of the page. */
	.subsales-order-history{ margin:0; padding:0; list-style:none; }
	.subsales-order-history li{
		padding:7px 0;
		border-top:1px solid #f0f0f1;
		font-size:12px;
		line-height:1.45;
	}
	.subsales-order-history li:first-child{ border-top:0; }
	.subsales-hist-meta{ color:#667085; }
	.subsales-hist-type{
		display:inline-block;
		font-size:10px;
		text-transform:uppercase;
		letter-spacing:.04em;
		font-weight:700;
		padding:1px 6px;
		border-radius:8px;
		margin-right:5px;
		vertical-align:1px;
	}
	.subsales-hist-create{ background:#e7f5ec; color:#116533; }
	.subsales-hist-update{ background:#eef4ff; color:#1d4ed8; }
	.subsales-hist-delete{ background:#fdecec; color:#a4161a; }
	.subsales-hist-restore{ background:#fff4e5; color:#7c4a03; }

	@media (max-width:960px){
		.subsales-thread-grid{ grid-template-columns:1fr; }
		.subsales-thread-side .subsales-order-card{ position:static; }
		.subsales-thread-scroll{ max-height:none; overflow:visible; }
	}
	</style>
	<?php
}

/**
 * What has happened to this order since it was taken.
 *
 * Sits under the order details rather than behind another click - somebody
 * reading "I need to change my order" wants to know whether it has already been
 * changed, and by whom, before they reply.
 */
function subsales_messages_order_history( $order_db_id ) {
	global $wpdb;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT edit_type, edited_by_name, changes_summary, edit_reason, edited_at
		   FROM {$wpdb->prefix}ss_edit_history
		  WHERE order_id = %d
		  ORDER BY edited_at DESC
		  LIMIT 12",
		$order_db_id
	), ARRAY_A );

	echo '<h3 style="margin:14px 0 6px;font-size:0.95rem">History</h3>';

	if ( ! $rows ) {
		echo '<p class="description" style="margin:0">No changes since it was taken.</p>';
		return;
	}

	echo '<ul class="subsales-order-history">';
	foreach ( $rows as $r ) {
		printf(
			'<li><span class="subsales-hist-type subsales-hist-%1$s">%1$s</span> %2$s<br><span class="subsales-hist-meta">%3$s &middot; %4$s</span>%5$s</li>',
			esc_attr( $r['edit_type'] ),
			esc_html( $r['changes_summary'] ?: '' ),
			esc_html( $r['edited_by_name'] ?: 'unknown' ),
			esc_html( get_date_from_gmt( $r['edited_at'], 'M j, g:i A' ) ),
			$r['edit_reason'] ? '<br><span class="subsales-hist-meta">Reason: ' . esc_html( $r['edit_reason'] ) . '</span>' : ''
		);
	}
	echo '</ul>';
}

/**
 * One message bubble.
 *
 * Shared by the page and the live update so a message that arrives while you
 * are reading looks exactly like the ones already there - the alternative is a
 * second copy of this markup in JavaScript, drifting out of step.
 */
function subsales_messages_bubble( $m, $order_id, $phone ) {
	$inbound = ( 'in' === $m['direction'] );
	$when    = get_date_from_gmt( $m['sent_at'] ?: $m['created_at'], 'M j, g:i A' );
	$body    = trim( (string) $m['body'] );

	$html = sprintf(
		'<div class="subsales-msg %s" data-id="%d"><div class="subsales-bubble">%s<div class="subsales-msg-meta">%s &middot; %s</div></div></div>',
		$inbound ? 'is-in' : 'is-out',
		intval( $m['id'] ),
		'' !== $body ? esc_html( $body ) : '<em class="subsales-msg-empty">(no message body)</em>',
		esc_html( $inbound ? 'From customer' : 'Sent' ),
		esc_html( $when . ( 'skipped' === $m['status'] ? ' - not sent: ' . $m['skip_reason'] : '' ) )
	);

	return $html;
}

/**
 * Mark every inbound message on a conversation as read.
 *
 * Called when the thread is opened and again as live messages arrive while it
 * is on screen - if you are looking at the conversation, you have read it.
 */
function subsales_messages_mark_thread_read( $phone ) {
	if ( ! current_user_can( 'manage_options' ) || '' === trim( (string) $phone ) ) {
		return 0;
	}
	global $wpdb;
	return (int) $wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->prefix}ss_sms_messages
		    SET read_at = %s
		  WHERE phone = %s AND direction = 'in' AND read_at IS NULL",
		current_time( 'mysql', true ),
		$phone
	) );
}
