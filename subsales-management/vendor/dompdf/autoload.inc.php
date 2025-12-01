<?php
/**
 * DomPDF Autoloader for WordPress Plugin
 * Loads DomPDF and its dependencies
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$base = dirname( __FILE__ );

// Load dependencies first
require_once dirname( $base ) . '/phenx-php-font-lib/src/FontLib/Autoloader.php';
require_once dirname( $base ) . '/phenx-php-svg-lib/src/autoload.php';

// Register DomPDF autoloader
spl_autoload_register(function ($class) use ($base) {
    $prefix = 'Dompdf\\';
    $len = strlen($prefix);
    
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base . '/src/' . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
    }
});

// Define version constant
if (!defined('Dompdf\\VERSION')) {
    $version_file = $base . '/VERSION';
    if (file_exists($version_file)) {
        define('Dompdf\\VERSION', trim(file_get_contents($version_file)));
    } else {
        define('Dompdf\\VERSION', '3.0.0');
    }
}
