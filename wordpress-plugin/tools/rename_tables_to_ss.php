<?php
/**
 * Database Table Renaming Script
 * 
 * Renames all subsales tables from old prefixes (order_sync_*, subsales_*) to new ss_* prefix
 * 
 * IMPORTANT: Run this ONCE after uploading the updated plugin
 * 
 * Usage:
 * 1. Upload to /wp-content/plugins/subsales-management/tools/
 * 2. Access via: https://yoursite.com/wp-content/plugins/subsales-management/tools/rename_tables_to_ss.php
 * 3. Delete this file after successful execution
 * 
 * @package Subsales_Management
 * @since 1.2.0
 */

// Prevent direct access
if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Subsales Table Migration</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
            .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 20px 0; }
            .button { display: inline-block; background: #2271b1; color: white; padding: 10px 20px; text-decoration: none; border-radius: 3px; margin: 10px 5px 10px 0; }
            .button:hover { background: #135e96; }
            code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
        <h1>⚠️ Database Table Migration Tool</h1>
        <div class="warning">
            <p><strong>WARNING:</strong> This will rename database tables. Make sure you have a backup!</p>
        </div>
        <p>This script will rename the following tables:</p>
        <ul>
            <li><code>order_sync_orders</code> → <code>ss_orders</code></li>
            <li><code>order_sync_teams</code> → <code>ss_teams</code></li>
            <li><code>order_sync_team_members</code> → <code>ss_team_members</code></li>
            <li><code>order_sync_user_teams</code> → <code>ss_user_teams</code></li>
            <li><code>order_edit_history</code> → <code>ss_edit_history</code></li>
            <li><code>subsales_logs</code> → <code>ss_logs</code></li>
            <li><code>subsales_pwa_sessions</code> → <code>ss_pwa_sessions</code></li>
        </ul>
        <p><a href="?confirm=yes" class="button">Run Migration</a></p>
        <p><small>After running, delete this file from your server.</small></p>
    </body>
    </html>
    <?php
    exit;
}

// Load WordPress - search for wp-load.php
$current_dir = __DIR__;
$wp_load = null;

// Try going up directories looking for wp-load.php
for ($i = 0; $i < 10; $i++) {
    $current_dir = dirname($current_dir);
    $test_path = $current_dir . '/wp-load.php';
    if (file_exists($test_path)) {
        $wp_load = $test_path;
        break;
    }
}

if (!$wp_load) {
    die('<h1>Error</h1><p>Could not locate wp-load.php. Searched up to 10 directory levels.</p><p>Script location: ' . __DIR__ . '</p>');
}

require_once($wp_load);

// Security check
if (!current_user_can('manage_options')) {
    die('<h1>Unauthorized</h1><p>You must be logged in as an administrator to run this script.</p>');
}

global $wpdb;

echo "<h1>Subsales Table Renaming Tool</h1>\n";
echo "<p>This will rename all subsales tables to use the 'ss_' prefix.</p>\n";
echo "<hr>\n";

// Define table mappings (old => new)
$table_mappings = array(
    'order_sync_orders' => 'ss_orders',
    'order_sync_teams' => 'ss_teams',
    'order_sync_team_members' => 'ss_team_members',
    'order_sync_user_teams' => 'ss_user_teams',
    'order_edit_history' => 'ss_edit_history',
    'subsales_logs' => 'ss_logs',
    'subsales_pwa_sessions' => 'ss_pwa_sessions',
    'subsales_users' => 'ss_users',
    'subsales_user_teams' => 'ss_user_teams_old' // Duplicate, may not exist
);

$renamed = array();
$skipped = array();
$errors = array();

foreach ($table_mappings as $old_table => $new_table) {
    $old_full = $wpdb->prefix . $old_table;
    $new_full = $wpdb->prefix . $new_table;
    
    // Check if old table exists
    $table_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $old_full
    ));
    
    if (!$table_exists) {
        $skipped[] = "$old_table (does not exist)";
        echo "<p style='color: gray;'>⊘ Skipped: $old_full (table does not exist)</p>\n";
        continue;
    }
    
    // Check if new table already exists
    $new_exists = $wpdb->get_var($wpdb->prepare(
        "SHOW TABLES LIKE %s",
        $new_full
    ));
    
    if ($new_exists) {
        $skipped[] = "$old_table (target $new_table already exists)";
        echo "<p style='color: orange;'>⚠ Skipped: $old_full → $new_full (target already exists)</p>\n";
        continue;
    }
    
    // Rename the table
    $result = $wpdb->query("RENAME TABLE `{$old_full}` TO `{$new_full}`");
    
    if ($result === false) {
        $errors[] = "$old_table → $new_table (SQL error)";
        echo "<p style='color: red;'>✗ Failed: $old_full → $new_full</p>\n";
        echo "<pre style='color: red;'>" . $wpdb->last_error . "</pre>\n";
    } else {
        $renamed[] = "$old_table → $new_table";
        echo "<p style='color: green;'>✓ Renamed: $old_full → $new_full</p>\n";
    }
    
    flush();
}

// Summary
echo "<hr>\n";
echo "<h2>Summary</h2>\n";
echo "<p><strong>Renamed:</strong> " . count($renamed) . " tables</p>\n";
if (!empty($renamed)) {
    echo "<ul>\n";
    foreach ($renamed as $item) {
        echo "<li>$item</li>\n";
    }
    echo "</ul>\n";
}

echo "<p><strong>Skipped:</strong> " . count($skipped) . " tables</p>\n";
if (!empty($skipped)) {
    echo "<ul>\n";
    foreach ($skipped as $item) {
        echo "<li>$item</li>\n";
    }
    echo "</ul>\n";
}

if (!empty($errors)) {
    echo "<p style='color: red;'><strong>Errors:</strong> " . count($errors) . " tables</p>\n";
    echo "<ul style='color: red;'>\n";
    foreach ($errors as $item) {
        echo "<li>$item</li>\n";
    }
    echo "</ul>\n";
}

echo "<hr>\n";
echo "<p><strong>Next Steps:</strong></p>\n";
echo "<ol>\n";
echo "<li>Verify the plugin still works correctly</li>\n";
echo "<li>Check the logs page to ensure it's reading from ss_logs</li>\n";
echo "<li>Test creating an order to verify ss_orders table</li>\n";
echo "<li><strong>Delete this file</strong> for security: <code>/wp-content/plugins/subsales-management/tools/rename_tables_to_ss.php</code></li>\n";
echo "</ol>\n";

echo "<p style='color: blue;'><strong>IMPORTANT:</strong> Clear your browser cache and reload the PWA after this migration.</p>\n";
