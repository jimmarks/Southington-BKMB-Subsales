<?php
/**
 * Test WordPress Loading
 */

// Try to find wp-load.php
$paths_to_try = array(
    '../../../../wp-load.php',      // 4 levels up (correct for /bitnami/wordpress)
    '../../../../../wp-load.php',
    '../../../wp-load.php',
    '../../../../../../wp-load.php',
);

echo "<h1>WordPress Load Path Test</h1>\n";
echo "<p>Script location: " . __FILE__ . "</p>\n";
echo "<p>Script directory: " . __DIR__ . "</p>\n";
echo "<hr>\n";

foreach ($paths_to_try as $path) {
    $full_path = __DIR__ . '/' . $path;
    $real_path = realpath($full_path);
    
    echo "<p><strong>Testing:</strong> $path</p>\n";
    echo "<p>Full path: $full_path</p>\n";
    echo "<p>Real path: " . ($real_path ?: 'NOT FOUND') . "</p>\n";
    echo "<p>Exists: " . (file_exists($full_path) ? 'YES' : 'NO') . "</p>\n";
    echo "<hr>\n";
}

echo "<p><strong>Current working directory:</strong> " . getcwd() . "</p>\n";
echo "<p><strong>PHP version:</strong> " . phpversion() . "</p>\n";
echo "<hr>\n";

// Search for wp-load.php by walking up directories
echo "<h2>Searching for wp-load.php by walking up directories...</h2>\n";
$current_dir = __DIR__;
$wp_load = null;

for ($i = 0; $i < 10; $i++) {
    $test_path = $current_dir . '/wp-load.php';
    echo "<p><strong>Level $i:</strong> $current_dir</p>\n";
    echo "<p>Testing: $test_path</p>\n";
    echo "<p>Exists: " . (file_exists($test_path) ? '<span style="color: green;">YES ✓</span>' : '<span style="color: red;">NO</span>') . "</p>\n";
    
    if (file_exists($test_path)) {
        $wp_load = $test_path;
        echo "<p style='color: green; font-weight: bold;'>✓ FOUND wp-load.php at: $wp_load</p>\n";
        break;
    }
    
    $current_dir = dirname($current_dir);
    echo "<hr>\n";
}

if (!$wp_load) {
    echo "<p style='color: red;'><strong>✗ Could not find wp-load.php in 10 directory levels</strong></p>\n";
    exit;
}

// Try to load WordPress with the found path
echo "<h2>Loading WordPress...</h2>\n";
// Try to load WordPress with the found path
echo "<h2>Loading WordPress...</h2>\n";
echo "<p style='color: green;'>Attempting to load WordPress from: $wp_load</p>\n";
try {
    require_once($wp_load);
    echo "<p style='color: green;'>✓ WordPress loaded successfully!</p>\n";
    echo "<p>WordPress version: " . get_bloginfo('version') . "</p>\n";
    echo "<p>Can manage options: " . (current_user_can('manage_options') ? 'YES' : 'NO') . "</p>\n";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Error loading WordPress: " . $e->getMessage() . "</p>\n";
}
?>
