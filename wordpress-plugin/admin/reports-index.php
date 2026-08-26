<?php
/**
 * Reports Index Page
 * 
 * Lists all available reports with descriptions and links
 * 
 * @package Subsales_Management
 * @since 2.4.35
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wrap">
    <h1>Subsales Reports</h1>
    <p>Select a report to view detailed information about your subsales operations.</p>
    
    <div class="report-cards" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 30px;">
        
        <!-- Points Report -->
        <div class="report-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0; color: #2271b1;">
                <span class="dashicons dashicons-chart-line" style="font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                Points Report
            </h2>
            <p style="color: #666; line-height: 1.6;">
                Detailed breakdown of sales and donations by team and individual seller. Includes points calculation, 
                date filtering, and CSV export capabilities.
            </p>
            <p style="margin-bottom: 0;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-team-sales-report' ) ); ?>" 
                   class="button button-primary">
                    View Report
                </a>
            </p>
        </div>
        
        <!-- Address Coverage Report -->
        <div class="report-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0; color: #2271b1;">
                <span class="dashicons dashicons-location" style="font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                Address Coverage Report
            </h2>
            <p style="color: #666; line-height: 1.6;">
                Analyze which order addresses are matched in your address database, cached with GPS coordinates, 
                or require geocoding. Helps identify typos and improve delivery routing.
            </p>
            <p style="margin-bottom: 0;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-address-coverage' ) ); ?>" 
                   class="button button-primary">
                    View Report
                </a>
            </p>
        </div>
        
        <!-- Addresses Needing Review -->
        <?php
        $review_pending = Subsales_Database::count_review_queue_rows( 'pending' );
        ?>
        <div class="report-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0; color: <?php echo $review_pending > 0 ? '#d63638' : '#2271b1'; ?>;">
                <span class="dashicons dashicons-location-alt" style="font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                Addresses to Review
                <?php if ( $review_pending > 0 ): ?>
                    <span class="count" style="background: #d63638; color: white; padding: 2px 8px; border-radius: 10px; font-size: 14px; margin-left: 8px;">
                        <?php echo intval( $review_pending ); ?>
                    </span>
                <?php endif; ?>
            </h2>
            <p style="color: #666; line-height: 1.6;">
                Addresses the automatic ZIP code ingestion couldn't place, plus addresses sellers entered
                that aren't in the address book yet. Nothing is blocked by this list &mdash; work through it
                whenever it suits you.
                <?php if ( $review_pending > 0 ): ?>
                    <strong style="color: #d63638;">
                        <?php echo intval( $review_pending ); ?> address<?php echo $review_pending != 1 ? 'es' : ''; ?> waiting.
                    </strong>
                <?php endif; ?>
            </p>
            <p style="margin-bottom: 0;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-settings#tab-address_extracts' ) ); ?>"
                   class="button button-primary">
                    <?php echo $review_pending > 0 ? 'Review Addresses' : 'Address Data'; ?>
                </a>
            </p>
        </div>
        
        <!-- GPS Proximity Search -->
        <div class="report-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0; color: #2271b1;">
                <span class="dashicons dashicons-location-alt" style="font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                GPS Proximity Search
            </h2>
            <p style="color: #666; line-height: 1.6;">
                Find who was near a specific address by searching PWA heartbeat GPS logs. 
                Useful when orders are missing but you know the delivery address - see which team members 
                were in that area and what orders they created around that time.
            </p>
            <p style="margin-bottom: 0;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-gps-proximity' ) ); ?>" 
                   class="button button-primary">
                    Search GPS Logs
                </a>
            </p>
        </div>
        
        <!-- Order Entry Distance Analysis -->
        <div class="report-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 20px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h2 style="margin-top: 0; color: #2271b1;">
                <span class="dashicons dashicons-location" style="font-size: 24px; vertical-align: middle; margin-right: 8px;"></span>
                Order Entry Distance
            </h2>
            <p style="color: #666; line-height: 1.6;">
                See how far away each order was entered from its actual delivery address. 
                Uses GPS coordinates captured during order entry to measure distance to official address location. 
                Helps identify on-site vs. remote entry patterns and potential address issues.
            </p>
            <p style="margin-bottom: 0;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-order-entry-distance' ) ); ?>" 
                   class="button button-primary">
                    View Analysis
                </a>
            </p>
        </div>
        
    </div>
</div>

<style>
.report-card:hover {
    box-shadow: 0 4px 8px rgba(0,0,0,0.15) !important;
    transform: translateY(-2px);
    transition: all 0.2s ease;
}

.report-card .button-primary {
    margin-top: 10px;
}
</style>
