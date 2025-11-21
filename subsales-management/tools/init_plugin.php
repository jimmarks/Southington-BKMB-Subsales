<?php
/**
 * Plugin initialization helper (skeleton)
 * Intended to be expanded: create pages, default options, default teams, and other first-run tasks.
 * This file is a placeholder and should be used by WP-CLI or included by admin flows when creating an interactive setup wizard.
 */

if ( ! function_exists( 'subsales_plugin_initialize' ) ) {
    function subsales_plugin_initialize( $dry_run = true ) {
        $results = array();
        if ( $dry_run ) {
            $results[] = 'Dry-run: would create portal page, default options, and a sample team.';
            return array( 'success' => true, 'actions' => $results );
        }

        // Example actions (implement as needed):
        // - order_sync_ensure_pwa_page( $slug )
        // - update_option( 'order_sync_google_maps_api_key', '' )
        // - create a default team via order_sync_add_team()

        // For now, just return a not-implemented response
        return array( 'success' => false, 'error' => 'Initialization steps not implemented yet.' );
    }
}

// CLI helper
if ( php_sapi_name() === 'cli' && isset( $argv ) && basename( $argv[0] ) === basename( __FILE__ ) ) {
    $apply = in_array( '--apply', $argv, true );
    $res = subsales_plugin_initialize( ! $apply );
    if ( ! $res['success'] ) {
        fwrite( STDERR, "Init helper: " . ( $res['error'] ?? 'failed' ) . "\n" );
        exit(1);
    }
    echo "Init helper: " . implode("\n", $res['actions'] ) . "\n";
    exit(0);
}
