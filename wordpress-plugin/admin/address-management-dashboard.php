<?php
/**
 * Address Management Dashboard
 *
 * Three cards, matching the rebuilt address pipeline:
 *  1. Ingest ZIP Codes  - pull addresses from CT's statewide parcel service
 *  2. Needs Review      - the admin-paced queue of addresses we couldn't place
 *  3. PWA Data          - regenerate the per-ZIP JSON the seller app reads
 *
 * The old upload/shapefile/Census/reassign UI is gone along with its backend.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Handle ZIP list + town save
if ( isset( $_POST['subsales_zip_list'] ) && check_admin_referer( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ) ) {
    $raw = sanitize_text_field( wp_unslash( $_POST['subsales_zip_list'] ) );
    $parts = preg_split( '/[\,\s]+/', $raw );
    $zips = array();
    foreach ( $parts as $p ) {
        $pz = preg_replace( '/[^0-9]/', '', $p );
        if ( strlen( $pz ) === 5 ) $zips[] = $pz;
    }
    $zips = array_values( array_unique( $zips ) );
    update_option( 'subsales_served_zips', $zips );
    update_option( 'subsales_served_zipcodes', $zips );

    if ( isset( $_POST['subsales_parcel_town'] ) ) {
        $town = preg_replace( '/[^A-Za-z \-]/', '', sanitize_text_field( wp_unslash( $_POST['subsales_parcel_town'] ) ) );
        if ( '' !== trim( $town ) ) {
            update_option( 'subsales_parcel_town_name', strtoupper( trim( $town ) ) );
        }
    }

    subsales_update_zip_index();
    echo '<div class="updated"><p><strong>&#10003; Saved.</strong> ' . count( $zips ) . ' ZIP code' . ( count( $zips ) !== 1 ? 's' : '' ) . ' configured.</p></div>';
}

// Configured ZIPs
$configured_zips = get_option( 'subsales_served_zipcodes', array() );
if ( empty( $configured_zips ) ) {
    $configured_zips = get_option( 'subsales_served_zips', array() );
}
if ( ! is_array( $configured_zips ) ) {
    $configured_zips = is_string( $configured_zips ) && ! empty( $configured_zips )
        ? array_filter( array_map( 'trim', explode( ',', $configured_zips ) ) )
        : array();
}

$town_name = get_option( 'subsales_parcel_town_name', 'SOUTHINGTON' );

// Address database stats
global $wpdb;
$addresses_table = $wpdb->prefix . 'ss_addresses';
$total_addresses = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table}" );
$zip_counts = $wpdb->get_results( "SELECT zip, COUNT(*) AS c FROM {$addresses_table} WHERE zip <> '' GROUP BY zip ORDER BY zip", ARRAY_A );

// Last time the PWA JSON was generated (helper lives in subsales-management.php)
$last_generated = '';
if ( function_exists( 'subsales_get_generation_logs' ) ) {
    $gen_logs = subsales_get_generation_logs( 1 );
    if ( ! empty( $gen_logs[0]['timestamp'] ) ) {
        $last_generated = $gen_logs[0]['timestamp'];
    }
}

// Review queue
$review_per_page = 20;
$review_paged    = isset( $_GET['arq_paged'] ) ? max( 1, intval( $_GET['arq_paged'] ) ) : 1;
$review_pending  = Subsales_Database::count_review_queue_rows( 'pending' );
$review_pages    = (int) ceil( $review_pending / $review_per_page );
if ( $review_pages > 0 && $review_paged > $review_pages ) {
    $review_paged = $review_pages;
}
$review_rows = $review_pending > 0
    ? Subsales_Database::get_review_queue_rows( $review_per_page, ( $review_paged - 1 ) * $review_per_page, 'pending' )
    : array();
?>

<div id="subsales-address-admin"
     data-ingest-nonce="<?php echo esc_attr( wp_create_nonce( 'subsales_ingest_zips' ) ); ?>"
     data-review-nonce="<?php echo esc_attr( wp_create_nonce( 'subsales_address_review' ) ); ?>"
     data-generate-nonce="<?php echo esc_attr( wp_create_nonce( 'subsales_zip_generate' ) ); ?>">

<div class="subsales-address-dashboard">

    <!-- Card 1: Ingest ZIP Codes -->
    <div class="subsales-status-card" style="grid-column: span 2;">
        <div class="subsales-card-header config">
            <div class="subsales-card-icon">&#128506;</div>
            <div class="subsales-card-title">
                <h3>Ingest ZIP Codes</h3>
                <div class="subsales-card-subtitle">Get addresses for your selling area</div>
            </div>
        </div>
        <div class="subsales-card-body">
            <p style="margin-top:0; color:#50575e;">
                Type the ZIP codes you sell in and press <strong>Ingest Addresses</strong>. The site pulls every
                property address for your town straight from Connecticut's official property records &mdash;
                nothing to download, no maps or map files to find.
            </p>

            <form method="post" action="">
                <?php wp_nonce_field( 'subsales_zip_list_save', 'subsales_zip_list_nonce' ); ?>
                <p>
                    <label for="subsales_zip_list"><strong>ZIP codes</strong></label><br>
                    <textarea id="subsales_zip_list" name="subsales_zip_list" rows="2" class="large-text" style="width:100%; margin-top:6px;" placeholder="06489, 06479"><?php echo esc_textarea( implode( ', ', $configured_zips ) ); ?></textarea>
                </p>
                <p class="description" style="margin-top:-6px;">Separate them with commas or put each on its own line.</p>

                <p style="margin-top:14px;">
                    <label for="subsales_parcel_town"><strong>Town</strong></label><br>
                    <input type="text" id="subsales_parcel_town" name="subsales_parcel_town" value="<?php echo esc_attr( $town_name ); ?>" class="regular-text" style="margin-top:6px;" />
                </p>
                <p class="description" style="margin-top:-6px;">The Connecticut town these ZIP codes belong to.</p>

                <p style="margin-top:16px;">
                    <button type="submit" class="button">Save</button>
                    <button type="button" id="subsales-ingest-btn" class="button button-primary" style="margin-left:8px;">
                        &#11015; Ingest Addresses
                    </button>
                </p>
            </form>

            <div style="margin-top:6px; padding:12px; background:#fcf3cd; border-left:3px solid #f0b849; border-radius:3px; font-size:13px; line-height:1.6;">
                <strong>Heads up:</strong> ingesting a ZIP code <strong>replaces</strong> the addresses that came from
                the state records with a fresh copy. That's on purpose &mdash; it's how old, wrong addresses get cleaned
                out. Two things are never touched: <strong>addresses you fixed or added by hand are kept</strong> (and
                they win over the state's version of the same address), and orders your sellers already took are not
                affected at all.
            </div>

            <!-- Progress -->
            <div id="subsales-ingest-progress" style="display:none; margin-top:20px;">
                <div style="display:flex; justify-content:space-between; font-size:12px; color:#646970; margin-bottom:4px;">
                    <span id="subsales-ingest-progress-text">Starting&hellip;</span>
                    <span id="subsales-ingest-progress-percent">0%</span>
                </div>
                <div class="subsales-progress-bar">
                    <div id="subsales-ingest-progress-fill" class="subsales-progress-fill" style="width:0%;"></div>
                </div>
                <p class="description" style="margin-top:8px;">This can take several minutes. You can leave this page open.</p>
            </div>

            <!-- Results -->
            <div id="subsales-ingest-results" style="display:none; margin-top:20px;"></div>
        </div>
    </div>

    <!-- Card 3: PWA Data -->
    <div class="subsales-status-card">
        <div class="subsales-card-header process">
            <div class="subsales-card-icon">&#128241;</div>
            <div class="subsales-card-title">
                <h3>PWA Data</h3>
                <div class="subsales-card-subtitle">What sellers' phones download</div>
            </div>
        </div>
        <div class="subsales-card-body">
            <div class="subsales-stat-row">
                <span class="subsales-stat-label">Addresses stored</span>
                <span class="subsales-stat-value"><?php echo esc_html( number_format( $total_addresses ) ); ?></span>
            </div>
            <?php foreach ( $zip_counts as $zc ) : ?>
                <div class="subsales-stat-row">
                    <span class="subsales-stat-label"><?php echo esc_html( $zc['zip'] ); ?></span>
                    <span class="subsales-stat-value"><?php echo esc_html( number_format( (int) $zc['c'] ) ); ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ( empty( $zip_counts ) ) : ?>
                <div class="subsales-empty-state">
                    <div class="subsales-empty-state-icon">&#128193;</div>
                    <h4>No addresses yet</h4>
                    <p>Use <strong>Ingest Addresses</strong> to load them.</p>
                </div>
            <?php endif; ?>
            <?php if ( $last_generated ) : ?>
                <div class="subsales-stat-row">
                    <span class="subsales-stat-label">Last refreshed</span>
                    <span class="subsales-stat-value" style="font-size:13px;"><?php echo esc_html( $last_generated ); ?></span>
                </div>
            <?php endif; ?>

            <div class="subsales-card-actions">
                <button id="subsales-generate-btn" class="button" type="button">&#128260; Regenerate PWA Data</button>
                <p class="description" style="margin:8px 0 0;">
                    Ingesting already does this for you. Use this only if the seller app looks out of date.
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Card 2: Needs Review -->
<div class="subsales-status-card" style="margin:30px 0;">
    <div class="subsales-card-header upload">
        <div class="subsales-card-icon">&#128203;</div>
        <div class="subsales-card-title">
            <h3>Needs Review
                <span id="subsales-review-badge" class="subsales-status-badge <?php echo $review_pending > 0 ? 'in-progress' : 'complete'; ?>" style="margin:0 0 0 8px; vertical-align:middle;"><?php echo esc_html( number_format( $review_pending ) ); ?></span>
            </h3>
            <div class="subsales-card-subtitle">A to-do list, not an alarm</div>
        </div>
    </div>
    <div class="subsales-card-body">
        <p style="margin-top:0; color:#50575e;">
            A handful of addresses can't be matched up automatically. They land here so you can look at them
            whenever you have a minute. <strong>Nothing is broken and nothing is waiting on you</strong> &mdash;
            sellers, orders and deliveries all keep working while this list sits here.
        </p>

        <?php if ( empty( $review_rows ) ) : ?>
            <div class="subsales-empty-state">
                <div class="subsales-empty-state-icon">&#9989;</div>
                <h4>Nothing to review</h4>
                <p>Every address so far has been placed automatically.</p>
            </div>
        <?php else : ?>
            <table class="widefat striped" id="subsales-review-table">
                <thead>
                    <tr>
                        <th style="width:28%;">Address</th>
                        <th style="width:32%;">Why it's here</th>
                        <th style="width:14%;">Came from</th>
                        <th style="width:12%;">Added</th>
                        <th style="width:14%;">What you can do</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $review_rows as $row ) :
                    $rid = (int) $row['id'];

                    $candidates = array();
                    if ( ! empty( $row['candidate_zips_json'] ) ) {
                        $decoded = json_decode( $row['candidate_zips_json'], true );
                        if ( is_array( $decoded ) ) {
                            foreach ( $decoded as $cz ) {
                                if ( is_scalar( $cz ) && preg_match( '/^\d{5}$/', (string) $cz ) ) {
                                    $candidates[] = (string) $cz;
                                }
                            }
                        }
                    }

                    $label = trim( $row['house_number'] . ' ' . $row['street'] );
                    if ( '' === $label ) {
                        $label = $row['raw_address'];
                    }

                    $has_coords = ( '' !== (string) $row['lat'] && null !== $row['lat'] && '' !== (string) $row['lng'] && null !== $row['lng'] );
                ?>
                    <tr class="subsales-review-row" data-id="<?php echo esc_attr( $rid ); ?>">
                        <td>
                            <strong><?php echo esc_html( $label ); ?></strong>
                            <?php if ( ! empty( $row['city'] ) ) : ?>
                                <div style="color:#646970; font-size:12px;"><?php echo esc_html( $row['city'] ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( 'not_in_database' === $row['reason'] ) : ?>
                                A seller typed an address we don't have on file.
                            <?php else : ?>
                                We couldn't tell for sure which ZIP code this address is in.
                                <?php if ( ! empty( $candidates ) ) : ?>
                                    <div style="margin-top:4px; font-size:12px; color:#646970;">
                                        It's most likely <?php echo esc_html( implode( ' or ', $candidates ) ); ?>.
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ( ! $has_coords ) : ?>
                                <div style="margin-top:4px; font-size:12px; color:#646970;">
                                    No map location yet &mdash; use <em>Look up</em> first.
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo 'order_entry' === $row['source_context'] ? 'A seller\'s order' : 'Address load'; ?>
                            <?php if ( ! empty( $row['order_id'] ) ) : ?>
                                <div style="color:#646970; font-size:12px;">Order #<?php echo esc_html( (int) $row['order_id'] ); ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="color:#646970; font-size:12px;"><?php echo esc_html( $row['created_at'] ); ?></td>
                        <td>
                            <div style="display:flex; flex-wrap:wrap; gap:4px; align-items:flex-start;">
                                <button type="button" class="button button-small subsales-review-toggle">Fix it</button>
                                <button type="button" class="button button-small subsales-review-geocode" title="Ask Google where this address is. Costs a small amount each time.">Look up</button>
                                <button type="button" class="button button-small subsales-review-dismiss" title="Hide this from the list without adding it.">Ignore</button>
                            </div>
                        </td>
                    </tr>
                    <tr class="subsales-review-editor" data-id="<?php echo esc_attr( $rid ); ?>" style="display:none;">
                        <td colspan="5" style="background:#f6f7f7;">
                            <div style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
                                <label style="font-size:12px;">Number<br>
                                    <input type="text" class="subsales-review-house" value="<?php echo esc_attr( $row['house_number'] ); ?>" style="width:90px;">
                                </label>
                                <label style="font-size:12px;">Street<br>
                                    <input type="text" class="subsales-review-street" value="<?php echo esc_attr( $row['street'] ); ?>" style="width:220px;">
                                </label>
                                <label style="font-size:12px;">Town<br>
                                    <input type="text" class="subsales-review-city" value="<?php echo esc_attr( $row['city'] ); ?>" style="width:140px;">
                                </label>
                                <label style="font-size:12px;">ZIP code<br>
                                    <select class="subsales-review-zip" style="width:120px;">
                                        <option value="">Choose&hellip;</option>
                                        <?php
                                        $zip_options = array_values( array_unique( array_merge( $candidates, $configured_zips ) ) );
                                        foreach ( $zip_options as $zo ) : ?>
                                            <option value="<?php echo esc_attr( $zo ); ?>"<?php echo ( count( $candidates ) === 1 && $zo === $candidates[0] ) ? ' selected' : ''; ?>>
                                                <?php echo esc_html( $zo ); ?><?php echo in_array( $zo, $candidates, true ) ? ' (likely)' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label style="font-size:12px;">Latitude<br>
                                    <input type="text" class="subsales-review-lat" value="<?php echo esc_attr( (string) $row['lat'] ); ?>" style="width:120px;">
                                </label>
                                <label style="font-size:12px;">Longitude<br>
                                    <input type="text" class="subsales-review-lng" value="<?php echo esc_attr( (string) $row['lng'] ); ?>" style="width:120px;">
                                </label>
                                <label style="font-size:12px; flex:1 1 200px;">Note (optional)<br>
                                    <input type="text" class="subsales-review-note" style="width:100%;">
                                </label>
                                <span>
                                    <button type="button" class="button button-primary subsales-review-save">Save address</button>
                                    <button type="button" class="button subsales-review-cancel">Cancel</button>
                                </span>
                            </div>
                            <p class="description" style="margin:10px 0 0;">
                                A latitude and longitude are required. If they're blank, press <em>Look up</em> to fill them in.
                            </p>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $review_pages > 1 ) : ?>
                <div class="tablenav" style="margin-top:12px;">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo esc_html( number_format( $review_pending ) ); ?> to review</span>
                        <?php
                        echo paginate_links( array(
                            'base'      => esc_url_raw( add_query_arg( 'arq_paged', '%#%' ) ),
                            'format'    => '',
                            'current'   => $review_paged,
                            'total'     => $review_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                        ) );
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</div>
