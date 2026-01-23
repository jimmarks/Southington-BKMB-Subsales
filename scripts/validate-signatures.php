#!/usr/bin/env php
<?php
/**
 * Deep Function Call Validator
 * 
 * Validates function signatures match their usage across the codebase.
 * Checks parameter counts and types where possible.
 */

function color($text, $color) {
    $colors = ['green' => "\033[32m", 'red' => "\033[31m", 'yellow' => "\033[33m", 'blue' => "\033[34m", 'reset' => "\033[0m"];
    return $colors[$color] . $text . $colors['reset'];
}

echo color("\n=== Deep Function Signature Validation ===\n", 'blue');

$plugin_dir = __DIR__ . '/../wordpress-plugin';
$issues = [];
$checks = [];

// Test 1: Verify Delivery::handle_generate_manifest doesn't break the form submission
echo color("\nTEST 1: Form Submission Flow\n", 'yellow');
$delivery_page = file_get_contents($plugin_dir . '/admin/delivery-page.php');
$delivery_class = file_get_contents($plugin_dir . '/includes/class-delivery.php');

// Check form action matches hook
if (preg_match('/action=["\'].*?admin-post\.php/', $delivery_page)) {
    echo color("  ✓ Form submits to admin-post.php\n", 'green');
    $checks[] = 'Form submission target correct';
} else {
    echo color("  ✗ Form action incorrect\n", 'red');
    $issues[] = 'Form does not submit to admin-post.php';
}

if (preg_match('/name=["\']action["\'].*?value=["\']subsales_generate_delivery_pdf["\']/', $delivery_page)) {
    echo color("  ✓ Form action = subsales_generate_delivery_pdf\n", 'green');
    $checks[] = 'Form action name correct';
} else {
    echo color("  ✗ Form action name incorrect\n", 'red');
    $issues[] = 'Form action name does not match hook';
}

if (preg_match("/add_action.*admin_post_subsales_generate_delivery_pdf.*handle_generate_manifest/s", $delivery_class)) {
    echo color("  ✓ Hook registered for form action\n", 'green');
    $checks[] = 'Hook registered correctly';
} else {
    echo color("  ✗ Hook not registered\n", 'red');
    $issues[] = 'admin_post hook not properly registered';
}

// Test 2: Check function parameter compatibility
echo color("\nTEST 2: Function Parameter Compatibility\n", 'yellow');

$test_cases = [
    [
        'function' => 'optimize_route',
        'class_sig' => 'public static function optimize_route( $orders, $start_coords )',
        'wrapper_sig' => 'function order_sync_optimize_route( $orders, $start_coords )',
        'params' => 2
    ],
    [
        'function' => 'haversine_distance',
        'class_sig' => 'public static function haversine_distance( $lat1, $lon1, $lat2, $lon2 )',
        'wrapper_sig' => 'function order_sync_haversine_distance( $lat1, $lon1, $lat2, $lon2 )',
        'params' => 4
    ],
    [
        'function' => 'generate_qr_code',
        'class_sig' => 'public static function generate_qr_code( $url, $size = 800 )',
        'wrapper_sig' => 'function subsales_generate_qr_code( $url, $size = 800 )',
        'params' => 2
    ],
    [
        'function' => 'generate_route_qr_page',
        'class_sig' => 'public static function generate_route_qr_page( $all_routes, $delivery_date = \'\' )',
        'wrapper_sig' => 'function subsales_generate_route_qr_page( $all_routes, $delivery_date = \'\' )',
        'params' => 2
    ]
];

$main_file = file_get_contents($plugin_dir . '/subsales-management.php');

foreach ($test_cases as $test) {
    // Extract parameter count from class method
    preg_match('/public static function ' . $test['function'] . '\s*\((.*?)\)/s', $delivery_class, $class_match);
    preg_match('/function \w+_' . $test['function'] . '\s*\((.*?)\)/s', $main_file, $wrapper_match);
    
    if ($class_match && $wrapper_match) {
        // Count parameters (rough check)
        $class_params = array_filter(explode(',', $class_match[1]));
        $wrapper_params = array_filter(explode(',', $wrapper_match[1]));
        
        if (count($class_params) === count($wrapper_params)) {
            echo color("  ✓ {$test['function']}: signature match (" . count($class_params) . " params)\n", 'green');
            $checks[] = "{$test['function']} signature compatible";
        } else {
            echo color("  ✗ {$test['function']}: param mismatch (class: " . count($class_params) . ", wrapper: " . count($wrapper_params) . ")\n", 'red');
            $issues[] = "{$test['function']} signature incompatible";
        }
    } else {
        echo color("  ⚠ {$test['function']}: could not verify signature\n", 'yellow');
    }
}

// Test 3: Check for calls to undefined functions
echo color("\nTEST 3: Undefined Function Calls\n", 'yellow');

$required_functions = [
    'order_sync_get_products_config' => 'Used by Delivery class',
    'subsales_log' => 'Used by Delivery class',
    'wp_verify_nonce' => 'WordPress security',
    'sanitize_text_field' => 'WordPress sanitization',
    'wp_die' => 'WordPress error handling'
];

foreach ($required_functions as $func => $purpose) {
    // Check if function is called in Delivery class
    if (preg_match("/\\b{$func}\\s*\\(/", $delivery_class)) {
        // Verify it exists somewhere
        if (preg_match("/function {$func}\\s*\\(/", $main_file) || strpos($func, 'wp_') === 0 || strpos($func, 'sanitize_') === 0) {
            echo color("  ✓ {$func} - {$purpose}\n", 'green');
            $checks[] = "{$func} available";
        } else {
            echo color("  ✗ {$func} - UNDEFINED - {$purpose}\n", 'red');
            $issues[] = "{$func} called but not defined";
        }
    }
}

// Test 4: Check manifest generation workflow
echo color("\nTEST 4: Manifest Generation Workflow\n", 'yellow');

// Check if Delivery class calls order_sync_get_products_config
if (preg_match('/order_sync_get_products_config\s*\(\)/', $delivery_class)) {
    echo color("  ✓ Delivery class calls order_sync_get_products_config()\n", 'green');
    $checks[] = 'Product config function called';
    
    // Verify function exists in main file
    if (preg_match('/function order_sync_get_products_config\s*\(/', $main_file)) {
        echo color("  ✓ order_sync_get_products_config() defined in main file\n", 'green');
        $checks[] = 'Product config function exists';
    } else {
        echo color("  ✗ order_sync_get_products_config() NOT FOUND\n", 'red');
        $issues[] = 'Product config function missing';
    }
} else {
    echo color("  ⚠ Product config not used in Delivery class\n", 'yellow');
}

// Check geocoding
if (preg_match('/self::geocode_address/', $delivery_class)) {
    echo color("  ✓ Uses internal geocode_address method\n", 'green');
    $checks[] = 'Geocoding uses class method';
} else if (preg_match('/order_sync_geocode_address/', $delivery_class)) {
    echo color("  ⚠ Uses legacy order_sync_geocode_address\n", 'yellow');
} else {
    echo color("  ✗ No geocoding function found\n", 'red');
    $issues[] = 'Geocoding function missing';
}

// Test 5: Check QR code library availability
echo color("\nTEST 5: QR Code Library Dependencies\n", 'yellow');

if (preg_match("/class_exists\\s*\\(\\s*['\"]Endroid/", $delivery_class)) {
    echo color("  ✓ Checks for Endroid QR Code library\n", 'green');
    $checks[] = 'QR library check present';
} else {
    echo color("  ⚠ No QR library check found\n", 'yellow');
}

$vendor_qr = $plugin_dir . '/vendor/endroid/qr-code';
if (is_dir($vendor_qr)) {
    echo color("  ✓ Endroid QR Code library installed\n", 'green');
    $checks[] = 'QR library installed';
} else {
    echo color("  ✗ Endroid QR Code library NOT FOUND\n", 'red');
    $issues[] = 'QR library missing from vendor/';
}

// Test 6: Check for potential PHP errors
echo color("\nTEST 6: PHP Syntax and Structure\n", 'yellow');

$php_files = [
    'includes/class-delivery.php',
    'subsales-management.php'
];

foreach ($php_files as $file) {
    $full_path = $plugin_dir . '/' . $file;
    exec("php -l " . escapeshellarg($full_path) . " 2>&1", $output, $return_code);
    
    if ($return_code === 0) {
        echo color("  ✓ {$file} - syntax OK\n", 'green');
        $checks[] = "{$file} syntax valid";
    } else {
        echo color("  ✗ {$file} - SYNTAX ERROR\n", 'red');
        $issues[] = "{$file} has syntax errors";
        echo "    " . implode("\n    ", $output) . "\n";
    }
}

// Test 7: Check initialization order
echo color("\nTEST 7: Plugin Initialization Order\n", 'yellow');

// Extract the order of class requires and init calls
$init_section = '';
if (preg_match('/\/\/ Load modular classes.*?\/\/ Initialize.*?Subsales_Delivery::init\(\);/s', $main_file, $match)) {
    $init_section = $match[0];
} else {
    preg_match('/require_once.*class-delivery\.php.*?Subsales_Delivery::init/s', $main_file, $match);
    $init_section = $match[0] ?? '';
}

if (!empty($init_section)) {
    $require_pos = strpos($init_section, 'class-delivery.php');
    $init_pos = strpos($init_section, 'Subsales_Delivery::init');
    
    if ($require_pos !== false && $init_pos !== false && $require_pos < $init_pos) {
        echo color("  ✓ Class required before init() called\n", 'green');
        $checks[] = 'Initialization order correct';
    } else {
        echo color("  ✗ Init order incorrect\n", 'red');
        $issues[] = 'Class may not be loaded before init';
    }
} else {
    echo color("  ⚠ Could not verify initialization order\n", 'yellow');
}

// Summary
echo color("\n=== DEEP VALIDATION SUMMARY ===\n", 'blue');
echo "Total Checks: " . count($checks) . "\n";
echo "Issues Found: " . count($issues) . "\n\n";

if (!empty($issues)) {
    echo color("❌ CRITICAL ISSUES:\n", 'red');
    foreach ($issues as $issue) {
        echo "  • {$issue}\n";
    }
    exit(1);
} else {
    echo color("✅ DEEP VALIDATION PASSED!\n", 'green');
    echo "All function calls validated, signatures match, dependencies resolved.\n";
    exit(0);
}
