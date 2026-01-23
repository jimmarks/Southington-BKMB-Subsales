#!/usr/bin/env php
<?php
/**
 * Function Dependency Validator
 * 
 * Validates that all function calls in the codebase reference defined functions.
 * Run from repo root: php scripts/validate-functions.php
 */

// Color output helpers
function color_output($text, $color) {
    $colors = [
        'green' => "\033[32m",
        'red' => "\033[31m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'reset' => "\033[0m"
    ];
    return $colors[$color] . $text . $colors['reset'];
}

echo color_output("\n=== Subsales Plugin Function Validator ===\n", 'blue');
echo "Checking function dependencies...\n\n";

$plugin_dir = __DIR__ . '/../wordpress-plugin';
$errors = [];
$warnings = [];
$successes = [];

// Define all functions we expect to exist
$defined_functions = [
    // Core delivery functions (now in class)
    'Subsales_Delivery::handle_generate_manifest',
    'Subsales_Delivery::generate_combined_manifest_html',
    'Subsales_Delivery::generate_qr_code',
    'Subsales_Delivery::generate_route_qr_page',
    'Subsales_Delivery::optimize_route',
    'Subsales_Delivery::haversine_distance',
    'Subsales_Delivery::geocode_address',
    
    // Backward compatibility wrappers
    'order_sync_handle_generate_delivery_pdf',
    'order_sync_optimize_route',
    'order_sync_haversine_distance',
    'subsales_generate_qr_code',
    'subsales_generate_route_qr_page',
    
    // Legacy functions still in main file
    'order_sync_generate_manifest_html',
    'order_sync_generate_combined_manifest_html',
    'order_sync_geocode_address',
    'order_sync_get_products_config',
    'subsales_log',
];

// Check 1: Verify Delivery class exists and has required methods
echo color_output("CHECK 1: Delivery Class Structure\n", 'yellow');
$delivery_class_file = $plugin_dir . '/includes/class-delivery.php';
if (!file_exists($delivery_class_file)) {
    $errors[] = "class-delivery.php not found!";
    echo color_output("  ✗ class-delivery.php missing\n", 'red');
} else {
    $content = file_get_contents($delivery_class_file);
    
    $required_methods = [
        'init',
        'handle_generate_manifest',
        'generate_combined_manifest_html',
        'generate_qr_code',
        'generate_route_qr_page',
        'optimize_route',
        'haversine_distance',
        'geocode_address'
    ];
    
    foreach ($required_methods as $method) {
        if (preg_match("/public static function {$method}/", $content)) {
            $successes[] = "Subsales_Delivery::{$method} defined";
            echo color_output("  ✓ Subsales_Delivery::{$method}\n", 'green');
        } else {
            $errors[] = "Subsales_Delivery::{$method} not found";
            echo color_output("  ✗ Subsales_Delivery::{$method} missing\n", 'red');
        }
    }
}

// Check 2: Verify backward compatibility wrappers exist
echo color_output("\nCHECK 2: Backward Compatibility Wrappers\n", 'yellow');
$main_file = $plugin_dir . '/subsales-management.php';
$main_content = file_get_contents($main_file);

$bc_functions = [
    'order_sync_handle_generate_delivery_pdf',
    'order_sync_optimize_route',
    'order_sync_haversine_distance',
    'subsales_generate_qr_code',
    'subsales_generate_route_qr_page'
];

foreach ($bc_functions as $func) {
    if (preg_match("/function {$func}\s*\(/", $main_content)) {
        // Check if it delegates to new class
        if (preg_match("/Subsales_Delivery::/", $main_content)) {
            $successes[] = "{$func} wrapper exists and delegates";
            echo color_output("  ✓ {$func} (delegates to Subsales_Delivery)\n", 'green');
        } else {
            $warnings[] = "{$func} exists but may not delegate properly";
            echo color_output("  ⚠ {$func} (check delegation)\n", 'yellow');
        }
    } else {
        $errors[] = "{$func} wrapper not found";
        echo color_output("  ✗ {$func} missing\n", 'red');
    }
}

// Check 3: Verify hook registration
echo color_output("\nCHECK 3: WordPress Hook Registration\n", 'yellow');
if (preg_match("/Subsales_Delivery::init\(\)/", $main_content)) {
    $successes[] = "Subsales_Delivery::init() called in main file";
    echo color_output("  ✓ Subsales_Delivery::init() called\n", 'green');
} else {
    $errors[] = "Subsales_Delivery::init() not called - delivery won't work!";
    echo color_output("  ✗ Subsales_Delivery::init() not called!\n", 'red');
}

$delivery_content = file_get_contents($delivery_class_file);
if (preg_match("/add_action\(\s*'admin_post_subsales_generate_delivery_pdf'/", $delivery_content)) {
    $successes[] = "admin_post hook registered in Delivery class";
    echo color_output("  ✓ admin_post_subsales_generate_delivery_pdf hook registered\n", 'green');
} else {
    $errors[] = "admin_post hook not registered in Delivery class";
    echo color_output("  ✗ admin_post hook missing!\n", 'red');
}

// Check 4: Verify dependent function calls
echo color_output("\nCHECK 4: Required Dependencies\n", 'yellow');
$deps = [
    'order_sync_get_products_config' => 'Product configuration',
    'subsales_log' => 'Logging system',
    'order_sync_geocode_address' => 'Address geocoding (legacy - should use Delivery::geocode_address)'
];

foreach ($deps as $func => $desc) {
    if (preg_match("/function {$func}\s*\(/", $main_content)) {
        $successes[] = "{$func} exists ({$desc})";
        echo color_output("  ✓ {$func} - {$desc}\n", 'green');
    } else {
        $errors[] = "{$func} not found - {$desc}";
        echo color_output("  ✗ {$func} missing - {$desc}!\n", 'red');
    }
}

// Check 5: Look for orphaned function calls
echo color_output("\nCHECK 5: Orphaned Function Calls\n", 'yellow');
$php_files = shell_exec("find {$plugin_dir} -name '*.php' -type f");
$files = explode("\n", trim($php_files));

$delivery_related_calls = [];
foreach ($files as $file) {
    if (empty($file)) continue;
    $content = file_get_contents($file);
    $relative_path = str_replace($plugin_dir . '/', '', $file);
    
    // Check for calls to old delivery functions
    if (preg_match_all('/\b(order_sync_handle_generate_delivery_pdf|order_sync_optimize_route|order_sync_haversine_distance|subsales_generate_qr_code|subsales_generate_route_qr_page)\s*\(/', $content, $matches)) {
        foreach ($matches[1] as $func_call) {
            $delivery_related_calls[] = "{$relative_path}: {$func_call}()";
        }
    }
}

if (empty($delivery_related_calls)) {
    echo color_output("  ✓ No orphaned delivery function calls found\n", 'green');
} else {
    echo color_output("  ℹ Found delivery function calls (OK if using wrappers):\n", 'blue');
    foreach (array_unique($delivery_related_calls) as $call) {
        echo "    - {$call}\n";
    }
}

// Check 6: Class file requires
echo color_output("\nCHECK 6: Class File Includes\n", 'yellow');
if (preg_match("/require_once.*class-delivery\.php/", $main_content)) {
    $successes[] = "class-delivery.php required in main file";
    echo color_output("  ✓ class-delivery.php included\n", 'green');
} else {
    $errors[] = "class-delivery.php not required - class won't load!";
    echo color_output("  ✗ class-delivery.php not included!\n", 'red');
}

// Check 7: Duplicate function definitions
echo color_output("\nCHECK 7: Duplicate Function Checks\n", 'yellow');
$delivery_page = $plugin_dir . '/admin/delivery-page.php';
if (file_exists($delivery_page)) {
    $dp_content = file_get_contents($delivery_page);
    $duplicates = [];
    
    if (preg_match("/^function subsales_generate_qr_code/m", $dp_content)) {
        $duplicates[] = "subsales_generate_qr_code in delivery-page.php";
    }
    if (preg_match("/^function subsales_generate_route_qr_page/m", $dp_content)) {
        $duplicates[] = "subsales_generate_route_qr_page in delivery-page.php";
    }
    
    if (!empty($duplicates)) {
        echo color_output("  ⚠ Found functions in delivery-page.php (OK if wrappers have function_exists):\n", 'yellow');
        foreach ($duplicates as $dup) {
            echo "    - {$dup}\n";
        }
        // Check if main file has function_exists guards
        if (preg_match("/if\s*\(\s*!\s*function_exists\s*\(\s*'subsales_generate_qr_code'/", $main_content)) {
            echo color_output("  ✓ function_exists() guards present - duplicates safe\n", 'green');
        } else {
            $warnings[] = "Duplicate functions without guards - may cause conflicts";
            echo color_output("  ⚠ No function_exists() guards - potential conflict!\n", 'yellow');
        }
    } else {
        echo color_output("  ✓ No duplicate function definitions\n", 'green');
    }
}

// Summary
echo color_output("\n=== VALIDATION SUMMARY ===\n", 'blue');
echo color_output("Successes: " . count($successes) . "\n", 'green');
echo color_output("Warnings: " . count($warnings) . "\n", 'yellow');
echo color_output("Errors: " . count($errors) . "\n", 'red');

if (!empty($errors)) {
    echo color_output("\n❌ ERRORS FOUND:\n", 'red');
    foreach ($errors as $error) {
        echo "  • {$error}\n";
    }
    exit(1);
}

if (!empty($warnings)) {
    echo color_output("\n⚠️  WARNINGS:\n", 'yellow');
    foreach ($warnings as $warning) {
        echo "  • {$warning}\n";
    }
}

if (empty($errors) && empty($warnings)) {
    echo color_output("\n✅ ALL CHECKS PASSED! Plugin structure is valid.\n", 'green');
    exit(0);
} else if (empty($errors)) {
    echo color_output("\n✅ No critical errors. Warnings are informational.\n", 'green');
    exit(0);
}
