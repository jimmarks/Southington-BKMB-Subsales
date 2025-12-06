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

// Load Masterminds HTML5 parser
if ( ! class_exists( 'Masterminds\\HTML5' ) ) {
    require_once dirname( $base ) . '/masterminds-html5/HTML5.php';
}

// Load Cpdf class (base PDF generation class) - MUST be loaded before Dompdf classes
if ( ! class_exists( 'Dompdf\\Cpdf' ) ) {
    require_once $base . '/lib/Cpdf.php';
}

// Register DomPDF autoloader
if ( ! function_exists( 'dompdf_autoloader' ) ) {
    function dompdf_autoloader( $class ) {
        static $base = null;
        if ( $base === null ) {
            $base = dirname( __FILE__ );
        }
        
        $prefix = 'Dompdf\\';
        $len = strlen( $prefix );
        
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }
        
        $relative_class = substr( $class, $len );
        $file = $base . '/src/' . str_replace( '\\', '/', $relative_class ) . '.php';
        
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
    
    spl_autoload_register( 'dompdf_autoloader' );
}

// Define version constant
if (!defined('Dompdf\\VERSION')) {
    $version_file = $base . '/VERSION';
    if (file_exists($version_file)) {
        define('Dompdf\\VERSION', trim(file_get_contents($version_file)));
    } else {
        define('Dompdf\\VERSION', '3.0.0');
    }
}
