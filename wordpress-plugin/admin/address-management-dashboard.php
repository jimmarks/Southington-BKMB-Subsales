<?php
/**
 * Modern Address Management Dashboard
 * 
 * A clean, card-based UI for managing address data with:
 * - Combined statistics dashboard
 * - Progress indicators instead of raw percentages
 * - Status badges (Ready/In Progress/Complete)
 * - Collapsed generation history by default
 * - Compact inline ZIP edit
 * - Icon cards instead of text-heavy descriptions
 * - Empty states with actionable CTAs
 * - Consolidated logs in modal/drawer
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Handle ZIP list save
if ( isset( $_POST['subsales_zip_list'] ) && check_admin_referer( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ) ) {
    $raw = sanitize_text_field( wp_unslash( $_POST['subsales_zip_list'] ) );
    $parts = preg_split( '/[\,\s]+/', $raw );
    $zips = array();
    foreach ( $parts as $p ) {
        $pz = preg_replace( '/[^0-9]/', '', $p );
        if ( strlen( $pz ) === 5 ) $zips[] = $pz;
    }
    update_option( 'subsales_served_zips', $zips );
    update_option( 'subsales_served_zipcodes', $zips );
    subsales_update_zip_index();
    echo '<div class="updated"><p><strong>✓ ZIP codes saved successfully.</strong> ' . count( $zips ) . ' ZIP code' . ( count( $zips ) !== 1 ? 's' : '' ) . ' configured.</p></div>';
}

// Get configured ZIPs
$configured_zips = get_option( 'subsales_served_zipcodes', array() );
if ( empty( $configured_zips ) ) {
    $configured_zips = get_option( 'subsales_served_zips', array() );
}
if ( ! is_array( $configured_zips ) ) {
    $configured_zips = is_string( $configured_zips ) && ! empty( $configured_zips ) 
        ? array_filter( array_map( 'trim', explode( ',', $configured_zips ) ) ) 
        : array();
}

// Check if ZIP boundaries are loaded
$zip_polygons = get_option( 'subsales_zip_polygons', array() );
$boundaries_loaded = ! empty( $zip_polygons );
$loaded_zip_count = count( $zip_polygons );
$missing_boundaries = array_diff( $configured_zips, array_keys( $zip_polygons ) );

// Get database statistics
global $wpdb;
$addresses_table = $wpdb->prefix . 'ss_addresses';
$total_addresses = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table}" );
$residential_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE type = 'residential'" );
$matched_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE matched = 1" );
$with_zip_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table} WHERE zip IS NOT NULL AND zip != ''" );

// Calculate percentages
$match_percent = $total_addresses > 0 ? round( ( $matched_count / $total_addresses ) * 100 ) : 0;
$zip_percent = $total_addresses > 0 ? round( ( $with_zip_count / $total_addresses ) * 100 ) : 0;

// Determine status badges
$config_status = empty( $configured_zips ) ? 'warning' : 'complete';
$upload_status = $total_addresses === 0 ? 'warning' : ( $total_addresses > 1000 ? 'complete' : 'ready' );
$process_status = $match_percent >= 90 ? 'complete' : ( $match_percent > 0 ? 'in-progress' : 'ready' );

// Check for generated extracts
$upload = wp_upload_dir();
$base = trailingslashit( $upload['basedir'] ) . 'subsales-zipdata';
$extract_count = 0;
if ( is_dir( $base ) ) {
    $files = scandir( $base );
    $json_files = array_filter( $files, function( $f ) use ( $base ) {
        return is_file( $base . '/' . $f ) && substr( $f, -5 ) === '.json' && preg_match( '/^\d{5}\.json$/', $f );
    } );
    $extract_count = count( $json_files );
}
?>

<!-- Modern Dashboard Cards -->
<div class="subsales-address-dashboard">
    
    <!-- Card 1: Configuration -->
    <div class="subsales-status-card">
        <div class="subsales-card-header config">
            <div class="subsales-card-icon">⚙️</div>
            <div class="subsales-card-title">
                <h3>Configuration</h3>
                <div class="subsales-card-subtitle">Service area setup</div>
            </div>
        </div>
        <div class="subsales-card-body">
            <span class="subsales-status-badge <?php echo esc_attr( $config_status ); ?>">
                <?php if ( $config_status === 'complete' ) : ?>
                    ✓ Configured
                <?php else : ?>
                    ⚠️ Not Configured
                <?php endif; ?>
            </span>
            
            <?php if ( ! empty( $configured_zips ) ) : ?>
                <div class="subsales-stat-row">
                    <span class="subsales-stat-label">ZIP Codes</span>
                    <span class="subsales-stat-value"><?php echo count( $configured_zips ); ?></span>
                </div>
                <div class="subsales-zip-display">
                    <?php foreach ( $configured_zips as $zip ) : ?>
                        <span class="subsales-zip-tag"><?php echo esc_html( $zip ); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <div class="subsales-empty-state">
                    <div class="subsales-empty-state-icon">🗺️</div>
                    <h4>No ZIP Codes</h4>
                    <p>Configure your service area to enable address management.</p>
                </div>
            <?php endif; ?>
            
            <?php if ( ! empty( $configured_zips ) ) : ?>
                <!-- ZIP Boundaries Status -->
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #dcdcde;">
                    <div class="subsales-stat-row">
                        <span class="subsales-stat-label">ZIP Boundaries</span>
                        <span class="subsales-stat-value">
                            <?php if ( $boundaries_loaded ) : ?>
                                ✓ <?php echo $loaded_zip_count; ?> loaded
                            <?php else : ?>
                                ⚠️ Not loaded
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <?php if ( ! empty( $missing_boundaries ) ) : ?>
                        <div style="margin-top: 8px; padding: 8px; background: #fcf3cd; border-left: 3px solid #f0b849; border-radius: 3px; font-size: 12px;">
                            <strong>Missing boundaries:</strong> <?php echo implode( ', ', $missing_boundaries ); ?>
                        </div>
                    <?php endif; ?>
                    
                    <details style="margin-top: 12px;">
                        <summary style="cursor: pointer; font-size: 12px; color: #2271b1; font-weight: 600;">
                            📖 How to get ZIP boundaries
                        </summary>
                        <div style="margin-top: 12px; padding: 12px; background: #f6f7f7; border-radius: 4px; font-size: 12px; line-height: 1.6;">
                            <p style="margin: 0 0 8px 0;"><strong>Why do I need this?</strong></p>
                            <p style="margin: 0 0 12px 0;">ZIP boundary files allow instant ZIP assignment without API calls. Upload once, use forever.</p>
                            
                            <p style="margin: 0 0 8px 0;"><strong>Where to download (FREE):</strong></p>
                            <ol style="margin: 0 0 12px 0; padding-left: 20px;">
                                <li>Go to <a href="https://www.census.gov/cgi-bin/geo/shapefiles/index.php" target="_blank">Census TIGER/Line</a></li>
                                <li>Select year: <strong>2023</strong></li>
                                <li>Select layer: <strong>ZIP Code Tabulation Areas</strong></li>
                                <li>Select your state (e.g., <strong>Connecticut</strong>)</li>
                                <li>Download the ZIP file (~5-50MB)</li>
                            </ol>
                            
                            <p style="margin: 0; padding: 8px; background: #fff3cd; border-left: 2px solid #856404; border-radius: 3px;">
                                <strong>Note:</strong> This covers ALL ZIPs in your state. The system will automatically extract only the ZIPs you configured above.
                            </p>
                        </div>
                    </details>
                </div>
            <?php endif; ?>
            
            <div class="subsales-card-actions">
                <button type="button" class="button" onclick="document.getElementById('zip-config-modal').style.display='block'; document.getElementById('zip-modal-overlay').classList.add('open')">
                    ⚙️ Edit ZIP Codes
                </button>
                <?php if ( ! empty( $configured_zips ) ) : ?>
                    <button type="button" class="button button-primary" onclick="document.getElementById('boundaries-upload-modal').style.display='block'; document.getElementById('boundaries-modal-overlay').classList.add('open')" style="margin-top: 8px; width: 100%;">
                        🗺️ Upload ZIP Boundaries
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Card 2: Upload & Data -->
    <div class="subsales-status-card">
        <div class="subsales-card-header upload">
            <div class="subsales-card-icon">📤</div>
            <div class="subsales-card-title">
                <h3>Upload & Data</h3>
                <div class="subsales-card-subtitle">Address database</div>
            </div>
        </div>
        <div class="subsales-card-body">
            <span class="subsales-status-badge <?php echo esc_attr( $upload_status ); ?>">
                <?php if ( $upload_status === 'complete' ) : ?>
                    ✓ Loaded
                <?php elseif ( $upload_status === 'ready' ) : ?>
                    ● Partial
                <?php else : ?>
                    ⚠️ Empty
                <?php endif; ?>
            </span>
            
            <?php if ( $total_addresses > 0 ) : ?>
                <div class="subsales-stat-row">
                    <span class="subsales-stat-label">Total Addresses</span>
                    <span class="subsales-stat-value"><?php echo number_format( $total_addresses ); ?></span>
                </div>
                <div class="subsales-stat-row">
                    <span class="subsales-stat-label">Residential</span>
                    <span class="subsales-stat-value"><?php echo number_format( $residential_count ); ?></span>
                </div>
                <div class="subsales-stat-row">
                    <span class="subsales-stat-label">With ZIP Codes</span>
                    <span class="subsales-stat-value"><?php echo number_format( $with_zip_count ); ?> (<?php echo $zip_percent; ?>%)</span>
                </div>
                <div style="margin-top: 12px;">
                    <div style="font-size: 12px; color: #646970; margin-bottom: 4px;">ZIP Assignment</div>
                    <div class="subsales-progress-bar">
                        <div class="subsales-progress-fill" style="width: <?php echo $zip_percent; ?>%"></div>
                    </div>
                </div>
            <?php else : ?>
                <div class="subsales-empty-state">
                    <div class="subsales-empty-state-icon">📁</div>
                    <h4>No Addresses</h4>
                    <p>Upload a shapefile or CSV to get started.</p>
                </div>
            <?php endif; ?>
            
            <div class="subsales-card-actions">
                <button type="button" class="button button-primary" onclick="document.getElementById('upload-modal').style.display='block'; document.getElementById('upload-modal-overlay').classList.add('open')">
                    📤 Upload File
                </button>
            </div>
        </div>
    </div>
    
    <!-- Card 3: Process & Export -->
    <div class="subsales-status-card">
        <div class="subsales-card-header process">
            <div class="subsales-card-icon">🔧</div>
            <div class="subsales-card-title">
                <h3>Process & Export</h3>
                <div class="subsales-card-subtitle">Generate JSON extracts</div>
            </div>
        </div>
        <div class="subsales-card-body">
            <div class="subsales-stat-row">
                <span class="subsales-stat-label">With Coordinates</span>
                <span class="subsales-stat-value"><?php echo number_format( $matched_count ); ?> (<?php echo $match_percent; ?>%)</span>
            </div>
            <div style="margin: 12px 0;">
                <div style="font-size: 12px; color: #646970; margin-bottom: 4px;">Overall Progress</div>
                <div class="subsales-progress-bar">
                    <div class="subsales-progress-fill" style="width: <?php echo $match_percent; ?>%"></div>
                </div>
            </div>
            <div class="subsales-stat-row">
                <span class="subsales-stat-label">Generated Extracts</span>
                <span class="subsales-stat-value"><?php echo $extract_count; ?> file<?php echo $extract_count !== 1 ? 's' : ''; ?></span>
            </div>
            
            <div class="subsales-card-actions">
                <button id="subsales-generate-btn" class="button button-primary" type="button">
                    📦 Generate Extracts
                </button>
                <button type="button" class="button" onclick="document.getElementById('extracts-modal').style.display='block'; document.getElementById('extracts-modal-overlay').classList.add('open')">
                    📋 View Extracts
                </button>
                <button id="subsales-reassign-zips-btn" class="button" type="button" style="margin-top:8px; width:100%;" title="Re-run ZIP code assignment for all addresses with coordinates">
                    🔄 Reassign ZIP Codes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Progress/Log Areas -->
<div id="subsales-upload-progress" class="subsales-progress-widget" style="display:none; margin-top:20px; padding:20px; background:#fff; border-left:4px solid #2271b1; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
    <h3 style="margin:0 0 15px;">📤 Uploading Address Data</h3>
    <div style="margin-bottom:15px;">
        <div style="display:flex; justify-content:space-between; font-size:12px; color:#646970; margin-bottom:4px;">
            <span id="upload-progress-text">Preparing...</span>
            <span id="upload-progress-percent">0%</span>
        </div>
        <div class="subsales-progress-bar">
            <div id="upload-progress-fill" class="subsales-progress-fill" style="width:0%;"></div>
        </div>
    </div>
    <div id="upload-log" style="max-height:200px; overflow-y:auto; font-family:monospace; font-size:12px; white-space:pre-wrap; background:#f6f7f7; padding:10px; border-radius:3px;"></div>
</div>
<div id="subsales-match-log" style="margin-top:20px;display:none"></div>
<div id="subsales-generate-log" style="margin-top:20px;display:none"></div>

<!-- ZIP Configuration Modal -->
<div id="zip-modal-overlay" class="subsales-modal-overlay" onclick="this.classList.remove('open'); document.getElementById('zip-config-modal').style.display='none'"></div>
<div id="zip-config-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:30px; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.15); z-index:100000; max-width:500px; width:90%;">
    <h3 style="margin-top:0;">⚙️ Configure Service Area</h3>
    <form method="post" action="">
        <?php wp_nonce_field( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ); ?>
        <p>
            <label for="subsales_zip_list"><strong>ZIP Codes:</strong></label><br>
            <input type="text" id="subsales_zip_list" name="subsales_zip_list" value="<?php echo esc_attr( implode( ',', $configured_zips ) ); ?>" class="large-text" placeholder="06479, 06489, 06467" style="width:100%; margin-top:8px;" />
        </p>
        <p class="description">Enter comma-separated 5-digit ZIP codes.</p>
        <p style="margin-top:20px; text-align:right;">
            <button type="button" class="button" onclick="document.getElementById('zip-config-modal').style.display='none'; document.getElementById('zip-modal-overlay').classList.remove('open')">Cancel</button>
            <button type="submit" class="button button-primary">Save ZIP Codes</button>
        </p>
    </form>
</div>

<!-- ZIP Boundaries Upload Modal -->
<div id="boundaries-modal-overlay" class="subsales-modal-overlay" onclick="this.classList.remove('open'); document.getElementById('boundaries-upload-modal').style.display='none'"></div>
<div id="boundaries-upload-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:30px; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.15); z-index:100000; max-width:700px; width:90%; max-height:90vh; overflow-y:auto;">
    <h3 style="margin-top:0;">🗺️ ZIP Code Boundaries</h3>
    
    <div style="padding: 15px; background: #e7f3ff; border-left: 4px solid #2271b1; margin-bottom: 20px; border-radius: 3px;">
        <p style="margin: 0 0 10px 0; font-weight: 600;">Census ZCTA Shapefile Loading</p>
        <p style="margin: 0; font-size: 13px; line-height: 1.6;">
            This file contains ZIP code boundary polygons for your state. Once loaded, addresses will be assigned ZIPs instantly without Google API calls.
        </p>
    </div>
    
    <!-- Auto-Download Section -->
    <details open style="margin-bottom: 25px; border: 2px solid #2271b1; border-radius: 6px; overflow: hidden;">
        <summary style="cursor: pointer; font-weight: 600; padding: 12px 15px; background: #2271b1; color: #fff; user-select: none;">
            ⚡ Option 1: Auto-Download from Census (Recommended)
        </summary>
        <div style="padding: 20px; background: #f9f9f9;">
            <div style="padding: 12px; background: #d4edda; border-left: 3px solid #28a745; border-radius: 3px; margin-bottom: 15px; font-size: 13px;">
                <strong>✓ Automatic:</strong> Downloads and filters Census data for your configured ZIPs.<br>
                <strong>✓ Efficient:</strong> Only downloads your state (5-50MB instead of 500MB national file).<br>
                <strong>✓ Current:</strong> Gets latest Census boundaries automatically.
            </div>
            
            <form id="census-config-form" style="background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #ddd; margin-bottom: 15px;">
                <h4 style="margin-top: 0; margin-bottom: 12px; font-size: 14px;">Census Configuration</h4>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 12px;">
                    <div>
                        <label for="census_year" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">Year:</label>
                        <input type="number" id="census_year" name="census_year" value="<?php echo esc_attr( get_option( 'subsales_census_year', 2023 ) ); ?>" min="2010" max="<?php echo date( 'Y' ) - 1; ?>" style="width: 100%;" />
                        <p class="description" style="margin: 3px 0 0 0; font-size: 11px;">Census data year</p>
                    </div>
                    <div>
                        <label for="census_state" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">State (optional):</label>
                        <input type="text" id="census_state" name="census_state" value="<?php echo esc_attr( get_option( 'subsales_census_state', '' ) ); ?>" placeholder="CT" maxlength="2" style="width: 100%; text-transform: uppercase;" />
                        <p class="description" style="margin: 3px 0 0 0; font-size: 11px;">State abbreviation</p>
                    </div>
                </div>
                
                <div style="margin-bottom: 12px;">
                    <label for="census_zip_filter" style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">ZIP Filter (optional):</label>
                    <input type="text" id="census_zip_filter" name="census_zip_filter" value="<?php echo esc_attr( get_option( 'subsales_census_zip_filter', '' ) ); ?>" placeholder="06" style="width: 100%;" />
                    <p class="description" style="margin: 3px 0 0 0; font-size: 11px;">Filter by ZIP prefix (e.g., "06" for Connecticut). Leave empty to auto-detect from configured ZIPs.</p>
                </div>
                
                <details style="margin-top: 12px;">
                    <summary style="cursor: pointer; font-size: 12px; color: #666;">Advanced: Custom Census URL Pattern</summary>
                    <div style="margin-top: 8px;">
                        <input type="text" id="census_url_pattern" name="census_url_pattern" value="<?php echo esc_attr( get_option( 'subsales_census_url_pattern', 'https://www2.census.gov/geo/tiger/TIGER{year}/ZCTA520/tl_{year}_us_zcta520.zip' ) ); ?>" style="width: 100%; font-family: monospace; font-size: 11px;" />
                        <p class="description" style="margin: 3px 0 0 0; font-size: 10px;">Use <code>{year}</code> placeholder. Default uses Census TIGER/Line ZCTA520.</p>
                    </div>
                </details>
                
                <div style="margin-top: 15px; text-align: right;">
                    <button type="button" id="save-census-config-btn" class="button">Save Configuration</button>
                </div>
            </form>
            
            <div style="text-align: center;">
                <button type="button" id="auto-download-boundaries-btn" class="button button-primary button-hero" style="padding: 12px 30px; height: auto; font-size: 14px;">
                    🚀 Auto-Download Census Boundaries
                </button>
            </div>
            
            <div id="census-download-progress" style="display:none; margin-top: 15px; padding: 15px; background: #fff; border-radius: 4px; border: 1px solid #ddd;">
                <div style="margin-bottom: 10px;">
                    <strong id="census-progress-text">Initializing...</strong>
                </div>
                <div class="subsales-progress-bar">
                    <div id="census-progress-fill" class="subsales-progress-fill" style="width:0%;"></div>
                </div>
                <div id="census-log" style="margin-top: 10px; max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 11px; white-space: pre-wrap; background: #f6f7f7; padding: 8px; border: 1px solid #ddd; border-radius: 3px;"></div>
            </div>
        </div>
    </details>
    
    <!-- Manual Upload Section -->
    <details style="margin-bottom: 20px; border: 1px solid #ddd; border-radius: 6px; overflow: hidden;">
        <summary style="cursor: pointer; font-weight: 600; padding: 12px 15px; background: #f6f7f7; user-select: none;">
            📥 Option 2: Manual Upload
        </summary>
        <div style="padding: 20px;">
            <div style="padding: 12px; background: #fff3cd; border-left: 3px solid #856404; border-radius: 3px; margin-bottom: 15px; font-size: 12px;">
                <strong>Note:</strong> Manual upload requires downloading the file yourself first. Use auto-download instead for easier setup.
            </div>
            
            <div style="margin-bottom: 15px;">
                <h4 style="margin: 0 0 10px 0; font-size: 14px;">Download Instructions:</h4>
                <ol style="margin: 0; padding-left: 20px; line-height: 1.8; font-size: 13px;">
                    <li>Visit <a href="https://www.census.gov/cgi-bin/geo/shapefiles/index.php" target="_blank" rel="noopener">Census TIGER/Line Shapefiles</a></li>
                    <li>Select <strong>Year</strong>: Choose latest year (e.g., 2023)</li>
                    <li>Select <strong>Layer Type</strong>: Choose <strong>"ZIP Code Tabulation Areas"</strong></li>
                    <li>Select <strong>State</strong>: Choose your state or "Nationwide"</li>
                    <li>Click <strong>Download</strong> to get the ZIP file</li>
                    <li>Upload the downloaded file below</li>
                </ol>
            </div>
    
    <form id="upload-boundaries-form" enctype="multipart/form-data">
        <p>
            <label for="boundaries_file"><strong>Select ZCTA Shapefile:</strong></label><br>
            <input type="file" name="boundaries_file" id="boundaries_file" accept=".zip" required style="width:100%; margin-top:8px;" />
        </p>
        <p class="description" style="font-size: 12px; margin-bottom: 8px;">
            Upload the Census ZCTA shapefile ZIP archive. Must contain .shp, .dbf, .shx, and .prj files.
        </p>
        <?php
        $upload_max = wp_max_upload_size();
        $php_max = min(
            wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ),
            wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) )
        );
        ?>
        <p class="description" style="font-size: 11px; color: #666; background: #f9f9f9; padding: 8px; border-left: 3px solid #2271b1; border-radius: 3px;">
            <strong>ℹ️ Upload Limits:</strong><br>
            Server: <?php echo size_format( $php_max ); ?><br>
            WordPress: <?php echo size_format( $upload_max ); ?><br>
            <?php if ( $upload_max < 500 * MB_IN_BYTES ) : ?>
                <span style="color: #d63638;">⚠️ Large Census files (500MB+) may require increased PHP limits.</span>
            <?php endif; ?>
        </p>
        
        <div id="boundaries-upload-progress" style="display:none; margin: 15px 0; padding: 15px; background: #f6f7f7; border-radius: 4px;">
            <div style="margin-bottom: 10px;">
                <strong id="boundaries-progress-text">Processing...</strong>
            </div>
            <div class="subsales-progress-bar">
                <div id="boundaries-progress-fill" class="subsales-progress-fill" style="width:0%;"></div>
            </div>
            <div id="boundaries-log" style="margin-top: 10px; max-height: 150px; overflow-y: auto; font-family: monospace; font-size: 11px; white-space: pre-wrap; background: #fff; padding: 8px; border: 1px solid #dcdcde; border-radius: 3px;"></div>
        </div>
        
        <p style="margin-top:20px; text-align:right;">
            <button type="submit" id="boundaries-submit-btn" class="button button-primary">Upload and Process</button>
        </p>
    </form>
        </div>
    </details>
    
    <div style="text-align: right; padding-top: 15px; border-top: 1px solid #ddd;">
        <button type="button" class="button" onclick="document.getElementById('boundaries-upload-modal').style.display='none'; document.getElementById('boundaries-modal-overlay').classList.remove('open')">Close</button>
    </div>
</div>

<!-- Upload Modal -->
<div id="upload-modal-overlay" class="subsales-modal-overlay" onclick="this.classList.remove('open'); document.getElementById('upload-modal').style.display='none'"></div>
<div id="upload-modal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:30px; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.15); z-index:100000; max-width:500px; width:90%;">
    <h3 style="margin-top:0;">📤 Upload Address Data</h3>
    <form id="upload-address-form" enctype="multipart/form-data">
        <p>
            <label for="address_file"><strong>Select File:</strong></label><br>
            <input type="file" name="address_file" id="address_file" accept=".zip,.csv" required style="width:100%; margin-top:8px;" />
        </p>
        <p class="description">
            <strong>Supported formats:</strong><br>
            • <strong>.zip</strong> - Shapefile archive (.shp, .dbf, .prj)<br>
            • <strong>.csv</strong> - Address list with columns: street, city, state, zip, lat, lng
        </p>
        <p style="margin-top:20px; text-align:right;">
            <button type="button" class="button" onclick="document.getElementById('upload-modal').style.display='none'; document.getElementById('upload-modal-overlay').classList.remove('open')">Cancel</button>
            <button type="submit" id="upload-submit-btn" class="button button-primary">Upload and Process</button>
        </p>
    </form>
</div>

<!-- Extracts Modal (Drawer-style) -->
<div id="extracts-modal-overlay" class="subsales-modal-overlay" onclick="this.classList.remove('open'); document.getElementById('extracts-modal').style.display='none'"></div>
<div id="extracts-modal" class="subsales-log-modal">
    <div class="subsales-log-modal-header">
        <h3 style="margin:0;">📋 Generated Extracts</h3>
        <button type="button" class="button" onclick="document.getElementById('extracts-modal').style.display='none'; document.getElementById('extracts-modal-overlay').classList.remove('open')">×</button>
    </div>
    <div class="subsales-log-modal-body">
        <?php
        if ( is_dir( $base ) && ! empty( $json_files ) ) {
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>ZIP Code</th><th>File Size</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ( $json_files as $f ) {
                $path = $base . '/' . $f;
                $url = trailingslashit( $upload['baseurl'] ) . 'subsales-zipdata/' . rawurlencode( $f );
                $size = size_format( filesize( $path ) );
                $zip_code = basename( $f, '.json' );
                echo '<tr>';
                echo '<td><strong>' . esc_html( $zip_code ) . '</strong></td>';
                echo '<td>' . esc_html( $size ) . ' <a href="' . esc_url( $url ) . '" target="_blank">📄</a></td>';
                echo '<td><button type="button" class="button button-small subsales-delete-zip" data-zip="' . esc_attr( $zip_code ) . '">Delete</button></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            
            // Generation History (Collapsed)
            echo '<details style="margin-top:30px;">';
            echo '<summary style="cursor:pointer; font-weight:600; padding:12px; background:#f7f7f7; border-radius:4px;">📜 Generation History</summary>';
            echo '<div style="margin-top:12px;">';
            
            $logs = subsales_get_generation_logs( 5 );
            if ( ! empty( $logs ) ) {
                foreach ( $logs as $log ) {
                    $summary = isset( $log['summary'] ) ? $log['summary'] : array();
                    $timestamp = isset( $log['timestamp'] ) ? $log['timestamp'] : 'Unknown';
                    $total_addresses = isset( $summary['total_addresses'] ) ? $summary['total_addresses'] : 0;
                    $duration = isset( $summary['duration_seconds'] ) ? $summary['duration_seconds'] : 0;
                    
                    echo '<div style="padding:12px; margin:8px 0; background:#f9f9f9; border-left:3px solid #2271b1; border-radius:4px;">';
                    echo '<div style="font-size:13px; color:#646970;">' . esc_html( $timestamp ) . '</div>';
                    echo '<div style="margin-top:4px;"><strong>' . number_format( $total_addresses ) . '</strong> addresses in <strong>' . esc_html( $duration ) . 's</strong></div>';
                    echo '</div>';
                }
            } else {
                echo '<p style="color:#646970;">No generation history yet.</p>';
            }
            
            echo '</div></details>';
        } else {
            echo '<div class="subsales-empty-state">';
            echo '<div class="subsales-empty-state-icon">📁</div>';
            echo '<h4>No Extracts Generated</h4>';
            echo '<p>Click "Generate Extracts" to create JSON files from your address database.</p>';
            echo '</div>';
        }
        ?>
    </div>
</div>
