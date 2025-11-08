<?php
/**
 * Migration helper: convert legacy per-field qtys into structured `products` array inside order_data
 * Exposes function migrate_orders_products($apply = false) which returns an array summary of results.
 * The script still supports being run via CLI as before.
 */

if ( ! function_exists( 'migrate_orders_products' ) ) {
    function migrate_orders_products( $apply = false ) {
        // Try to bootstrap WP if not already loaded (supports running from WP root)
        if ( ! function_exists( 'add_action' ) ) {
            // attempt to locate wp-load.php up to 6 levels up
            $p = __DIR__;
            $found = false;
            for ( $i = 0; $i < 6; $i++ ) {
                // dirname requires level >= 1, so use $i + 1
                $try = dirname( $p, $i + 1 ) . '/wp-load.php';
                if ( file_exists( $try ) ) {
                    require_once $try;
                    $found = true;
                    break;
                }
            }
            if ( ! $found ) {
                return array( 'success' => false, 'error' => 'Could not find wp-load.php. Run from WP root or use wp eval-file.' );
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'order_sync_orders';

        // Load configured products (use helper if available to normalize storage format)
        if ( function_exists( 'order_sync_get_products_config' ) ) {
            $products_conf = order_sync_get_products_config();
        } else {
            $products_raw = get_option( 'order_sync_products', '[]' );
            $products_conf = json_decode( $products_raw, true );
            if ( ! is_array( $products_conf ) ) $products_conf = array();
        }

        // Backup table name
        $ts = date( 'Ymd_His' );
        $backup_table = $wpdb->prefix . 'order_sync_orders_backup_' . $ts;
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $backup_table ) );
        if ( $exists ) {
            return array( 'success' => false, 'error' => "Backup table {$backup_table} already exists." );
        }

        if ( $apply ) {
            $wpdb->query( "CREATE TABLE {$backup_table} LIKE {$table}" );
            $wpdb->query( "INSERT INTO {$backup_table} SELECT * FROM {$table}" );
        }

        $rows = $wpdb->get_results( "SELECT id, order_data FROM {$table}", ARRAY_A );
        $total = count( $rows );
        $updated = 0;
        $skipped = 0;
        $errors = array();

        foreach ( $rows as $r ) {
            $id = $r['id'];
            $od = json_decode( $r['order_data'], true );
            if ( ! is_array( $od ) ) $od = array();

            // If products array already present and looks valid, skip
            if ( isset( $od['products'] ) && is_array( $od['products'] ) ) { $skipped++; continue; }

            $products = array();
            foreach ( $products_conf as $p ) {
                $pid = $p['id'];
                $labels = array( $pid . 'Qty', $pid . '_qty', $pid );
                $qty = 0;
                foreach ( $labels as $k ) {
                    if ( isset( $od[ $k ] ) ) {
                        $qty = intval( $od[ $k ] );
                        break;
                    }
                }
                if ( $qty > 0 ) {
                    $products[] = array(
                        'id' => $pid,
                        'name' => $p['name'],
                        'qty' => $qty,
                        'price' => isset( $p['price'] ) ? floatval( $p['price'] ) : 0.0
                    );
                }
            }

            if ( empty( $products ) ) { $skipped++; continue; }

            // attach products array
            $od['products'] = $products;
            $new_json = wp_json_encode( $od );

            if ( $apply ) {
                $res = $wpdb->update( $table, array( 'order_data' => $new_json ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
                if ( $res !== false ) $updated++; else $errors[] = "Failed to update row id={$id}: {$wpdb->last_error}";
            } else {
                $updated++;
            }
        }

        $result = array(
            'success' => true,
            'total_rows' => $total,
            'would_update' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        );
        if ( $apply ) $result['backup_table'] = $backup_table;

        return $result;
    }
}

// If run directly via CLI, execute and print
if ( php_sapi_name() === 'cli' && isset( $argv ) ) {
    $apply = in_array( '--apply', $argv, true ) || in_array( '-a', $argv, true );
    $res = migrate_orders_products( $apply );
    if ( ! $res['success'] ) {
        fwrite( STDERR, "ERROR: " . ( $res['error'] ?? 'Unknown error' ) . "\n" );
        exit(1);
    }
    echo "Processed {$res['total_rows']} rows. Would update: {$res['would_update']}. Skipped: {$res['skipped']}.\n";
    if ( ! empty( $res['errors'] ) ) {
        foreach ( $res['errors'] as $e ) fwrite( STDERR, "{$e}\n" );
    }
    if ( $apply ) echo "Applied changes. Backup table: " . ( $res['backup_table'] ?? '(unknown)' ) . "\n";
    exit(0);
}
