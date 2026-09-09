<?php
/**
 * Reconciliation invariants for the admin Orders totals.
 *
 * The seller's EOD tally and this screen are read side by side at handoff, so
 * the arithmetic on both has to hold. Run against any filter:
 *
 *   wp eval-file tools/check-order-totals.php
 *
 * Exits non-zero on the first broken invariant.
 */
// WP-CLI only. This file elevates to an administrator to call the handler, so
// it must never be reachable any other way.
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( intval( $admin[0] ) );

$_POST = array(
	'season_id' => 0,
	'page'      => 1,
	'page_size' => 100,
	'nonce'     => wp_create_nonce( 'subsales_orders_nonce' ),
);
$_REQUEST = $_POST;

// The handler ends in wp_send_json_success(). Outside an AJAX request that is a
// bare die() with no hook to grab, so claim to be AJAX - then it routes through
// wp_die() and the filter below turns the exit into something catchable.
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', function () {
	return function () { throw new Exception( '__json_done__' ); };
} );

ob_start();
try {
	order_sync_fetch_orders_ajax();
} catch ( Exception $e ) {
	if ( '__json_done__' !== $e->getMessage() ) { throw $e; }
}
$payload = json_decode( ob_get_clean(), true );
if ( empty( $payload['data']['filtered_totals'] ) ) {
	echo "FAIL\n  - handler returned no totals\n";
	exit( 1 );
}

$ft   = $payload['data']['filtered_totals'];
$fail = array();

// Every order lands in the total, whether or not it has a payment method.
$bucketed   = $ft['cash'] + $ft['check'] + $ft['digital'];
$unassigned = round( $ft['grand'] - $bucketed, 2 );
if ( $unassigned < 0 ) {
	$fail[] = sprintf( 'buckets (%.2f) exceed grand (%.2f)', $bucketed, $ft['grand'] );
}

// Donations live inside the order totals; they are never added on top.
if ( $ft['donations'] > $ft['grand'] + 0.005 ) {
	$fail[] = sprintf( 'donations (%.2f) exceed grand (%.2f) - counted twice?', $ft['donations'], $ft['grand'] );
}

// The rows on screen have to add up to the footer.
$page_sum = 0.0;
foreach ( $payload['data']['orders'] as $o ) {
	$page_sum += floatval( $o['order_total'] );
}
if ( count( $payload['data']['orders'] ) === intval( $ft['order_count'] )
	&& abs( $page_sum - $ft['grand'] ) > 0.005 ) {
	$fail[] = sprintf( 'rows sum to %.2f but footer says %.2f', $page_sum, $ft['grand'] );
}

printf(
	"orders=%d cash=%.2f check=%.2f digital=%.2f unassigned=%.2f donations=%.2f grand=%.2f collect=%.2f\n",
	$ft['order_count'], $ft['cash'], $ft['check'], $ft['digital'],
	$unassigned, $ft['donations'], $ft['grand'], $ft['cash'] + $ft['check']
);

if ( $fail ) {
	echo "FAIL\n  - " . implode( "\n  - ", $fail ) . "\n";
	exit( 1 );
}
echo "OK\n";
