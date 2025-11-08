<?php
/**
 * Plugin deletion helper (skeleton)
 * Use to perform careful teardown steps (backups, optional table drops, option cleanup) when requested.
 * Prefer WP-CLI invocation or an admin confirmation dialog before running destructive actions.
 */

if ( ! function_exists( 'subsales_plugin_teardown' ) ) {
    function subsales_plugin_teardown( $dry_run = true ) {
        $results = array();
        if ( $dry_run ) {
            $results[] = 'Dry-run: would remove options, optionally drop tables, and remove portal page.';
            return array( 'success' => true, 'actions' => $results );
        }

        // Implement safe teardown here, e.g., create backups, then drop tables if explicitly requested.
        return array( 'success' => false, 'error' => 'Teardown not implemented yet.' );
    }
}

// CLI helper
if ( php_sapi_name() === 'cli' && isset( $argv ) && basename( $argv[0] ) === basename( __FILE__ ) ) {
    $apply = in_array( '--apply', $argv, true );
    $res = subsales_plugin_teardown( ! $apply );
    if ( ! $res['success'] ) {
        fwrite( STDERR, "Teardown helper: " . ( $res['error'] ?? 'failed' ) . "\n" );
        exit(1);
    }
    echo "Teardown helper: " . implode("\n", $res['actions'] ) . "\n";
    exit(0);
}
