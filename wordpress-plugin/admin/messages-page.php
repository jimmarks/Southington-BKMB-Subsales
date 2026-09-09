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

	echo '<div class="wrap"><h1>Text Messages</h1>';

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

	if ( $order ) {
		$od    = json_decode( $order['order_data'], true );
		$items = array();
		foreach ( (array) ( $od['products'] ?? array() ) as $p ) {
			if ( ! empty( $p['qty'] ) ) {
				$items[] = $p['name'] . ' &times; ' . intval( $p['qty'] );
			}
		}
		echo '<div class="card" style="max-width:none;padding:14px 18px;margin-bottom:18px">';
		echo '<h2 style="margin-top:0">' . esc_html( $od['customer'] ?? 'Order' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
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

	// The conversation. Keyed on the phone rather than the order, because a
	// customer with two orders is still one person having one conversation.
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

	echo '<div style="max-width:720px">';
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

	echo '<p class="description" style="margin-top:16px">Replies cannot be sent from here yet. Call the customer back on ' . esc_html( subsales_format_phone( $phone ) ) . '.</p>';
}

/** (860) 555-1234 reads faster than 8605551234 when you are dialling it. */
function subsales_format_phone( $digits ) {
	$d = preg_replace( '/\D+/', '', (string) $digits );
	if ( 10 === strlen( $d ) ) {
		return sprintf( '(%s) %s-%s', substr( $d, 0, 3 ), substr( $d, 3, 3 ), substr( $d, 6 ) );
	}
	return $digits;
}
