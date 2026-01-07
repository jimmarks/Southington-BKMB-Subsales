<?php
/**
 * Campaign Dates Admin Page
 * Visual calendar interface for managing selling dates
 * 
 * @package Subsales_Management
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get current month/year from query params or default to current month
$current_month = isset( $_GET['month'] ) ? intval( $_GET['month'] ) : intval( date( 'n' ) );
$current_year = isset( $_GET['year'] ) ? intval( $_GET['year'] ) : intval( date( 'Y' ) );

// Validate month/year
if ( $current_month < 1 || $current_month > 12 ) {
    $current_month = intval( date( 'n' ) );
}
if ( $current_year < 2020 || $current_year > 2099 ) {
    $current_year = intval( date( 'Y' ) );
}

// Get all campaigns for this month
$campaigns = Subsales_Database::get_campaigns( 'all' );
$campaign_dates = array();
foreach ( $campaigns as $campaign ) {
    $campaign_dates[ $campaign['campaign_date'] ] = $campaign;
}

// Calculate calendar data
$first_day = mktime( 0, 0, 0, $current_month, 1, $current_year );
$days_in_month = date( 't', $first_day );
$day_of_week = date( 'w', $first_day );
$month_name = date( 'F', $first_day );

// Previous/next month links
$prev_month = $current_month - 1;
$prev_year = $current_year;
if ( $prev_month < 1 ) {
    $prev_month = 12;
    $prev_year--;
}

$next_month = $current_month + 1;
$next_year = $current_year;
if ( $next_month > 12 ) {
    $next_month = 1;
    $next_year++;
}

$prev_url = add_query_arg( array( 'month' => $prev_month, 'year' => $prev_year ) );
$next_url = add_query_arg( array( 'month' => $next_month, 'year' => $next_year ) );
?>

<div class="wrap subsales-campaigns-wrap">
    <h1><?php esc_html_e( 'Campaign Dates', 'subsales-management' ); ?></h1>
    <p><?php esc_html_e( 'Click any date to add/remove it as a selling date. Active dates are marked with a checkmark.', 'subsales-management' ); ?></p>
    
    <div class="subsales-campaigns-container">
        
        <!-- Calendar Header -->
        <div class="subsales-calendar-header">
            <a href="<?php echo esc_url( $prev_url ); ?>" class="button">
                <span class="dashicons dashicons-arrow-left-alt2"></span> Previous
            </a>
            <h2><?php echo esc_html( $month_name . ' ' . $current_year ); ?></h2>
            <a href="<?php echo esc_url( $next_url ); ?>" class="button">
                Next <span class="dashicons dashicons-arrow-right-alt2"></span>
            </a>
        </div>
        
        <!-- Calendar Grid -->
        <table class="subsales-calendar">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Sun', 'subsales-management' ); ?></th>
                    <th><?php esc_html_e( 'Mon', 'subsales-management' ); ?></th>
                    <th><?php esc_html_e( 'Tue', 'subsales-management' ); ?></th>
                    <th><?php esc_html_e( 'Wed', 'subsales-management' ); ?></th>
                    <th><?php esc_html_e( 'Thu', 'subsales-management' ); ?></th>
                    <th><?php esc_html_e( 'Fri', 'subsales-management' ); ?></th>
                    <th><?php esc_html_e( 'Sat', 'subsales-management' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $day = 1;
                $weeks = ceil( ( $days_in_month + $day_of_week ) / 7 );
                
                for ( $week = 0; $week < $weeks; $week++ ) {
                    echo '<tr>';
                    
                    for ( $dow = 0; $dow < 7; $dow++ ) {
                        if ( ( $week == 0 && $dow < $day_of_week ) || $day > $days_in_month ) {
                            echo '<td class="empty-day"></td>';
                        } else {
                            $date_str = sprintf( '%04d-%02d-%02d', $current_year, $current_month, $day );
                            $is_campaign = isset( $campaign_dates[ $date_str ] );
                            $campaign = $is_campaign ? $campaign_dates[ $date_str ] : null;
                            $is_active = $is_campaign && $campaign['status'] === 'active';
                            $is_past = strtotime( $date_str ) < strtotime( date( 'Y-m-d' ) );
                            
                            // Get signup count for this date
                            $signup_count = 0;
                            $team_count = 0;
                            if ( $is_campaign ) {
                                $signups = Subsales_Database::get_signups( array(
                                    'campaign_id' => $campaign['id'],
                                    'status' => 'active'
                                ) );
                                $signup_count = count( $signups );
                                $teams = array();
                                foreach ( $signups as $signup ) {
                                    $teams[ $signup['team_id'] ] = true;
                                }
                                $team_count = count( $teams );
                            }
                            
                            $cell_classes = array( 'calendar-day' );
                            if ( $is_campaign ) {
                                $cell_classes[] = 'has-campaign';
                            }
                            if ( $is_active ) {
                                $cell_classes[] = 'active-campaign';
                            }
                            if ( $is_past ) {
                                $cell_classes[] = 'past-date';
                            }
                            
                            echo '<td class="' . esc_attr( implode( ' ', $cell_classes ) ) . '" data-date="' . esc_attr( $date_str ) . '">';
                            echo '<div class="day-content">';
                            echo '<span class="day-number">' . esc_html( $day ) . '</span>';
                            
                            if ( $is_active ) {
                                echo '<span class="dashicons dashicons-yes campaign-checkmark"></span>';
                            }
                            
                            if ( $signup_count > 0 ) {
                                echo '<div class="campaign-stats">';
                                echo '<span class="stat-teams" title="' . esc_attr( sprintf( __( '%d teams', 'subsales-management' ), $team_count ) ) . '">' . esc_html( $team_count ) . ' teams</span>';
                                echo '<span class="stat-members" title="' . esc_attr( sprintf( __( '%d members', 'subsales-management' ), $signup_count ) ) . '">' . esc_html( $signup_count ) . ' members</span>';
                                echo '</div>';
                            }
                            
                            echo '</div>';
                            echo '</td>';
                            
                            $day++;
                        }
                    }
                    
                    echo '</tr>';
                }
                ?>
            </tbody>
        </table>
        
        <!-- Legend -->
        <div class="subsales-calendar-legend">
            <h3><?php esc_html_e( 'Legend', 'subsales-management' ); ?></h3>
            <ul>
                <li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Active selling date', 'subsales-management' ); ?></li>
                <li><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Past date', 'subsales-management' ); ?></li>
                <li><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e( 'Click to toggle date', 'subsales-management' ); ?></li>
            </ul>
        </div>
        
        <!-- Campaign List -->
        <div class="subsales-campaign-list">
            <h3><?php esc_html_e( 'All Campaign Dates', 'subsales-management' ); ?></h3>
            
            <?php if ( empty( $campaigns ) ) : ?>
                <p><?php esc_html_e( 'No campaign dates yet. Click a date on the calendar to add one.', 'subsales-management' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'subsales-management' ); ?></th>
                            <th><?php esc_html_e( 'Name', 'subsales-management' ); ?></th>
                            <th><?php esc_html_e( 'Teams', 'subsales-management' ); ?></th>
                            <th><?php esc_html_e( 'Members', 'subsales-management' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'subsales-management' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'subsales-management' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $campaigns as $campaign ) : 
                            $signups = Subsales_Database::get_signups( array(
                                'campaign_id' => $campaign['id'],
                                'status' => 'active'
                            ) );
                            $member_count = count( $signups );
                            $teams = array();
                            foreach ( $signups as $signup ) {
                                $teams[ $signup['team_id'] ] = true;
                            }
                            $team_count = count( $teams );
                            $date_formatted = date( 'F j, Y', strtotime( $campaign['campaign_date'] ) );
                        ?>
                            <tr data-campaign-id="<?php echo esc_attr( $campaign['id'] ); ?>">
                                <td><strong><?php echo esc_html( $date_formatted ); ?></strong></td>
                                <td><?php echo esc_html( $campaign['campaign_name'] ?: '—' ); ?></td>
                                <td><?php echo esc_html( $team_count ); ?></td>
                                <td><?php echo esc_html( $member_count ); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr( $campaign['status'] ); ?>">
                                        <?php echo esc_html( ucfirst( $campaign['status'] ) ); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="#" class="button button-small view-signups" data-campaign-id="<?php echo esc_attr( $campaign['id'] ); ?>">
                                        <?php esc_html_e( 'View Signups', 'subsales-management' ); ?>
                                    </a>
                                    <?php if ( $member_count == 0 ) : ?>
                                        <a href="#" class="button button-small delete-campaign" data-campaign-id="<?php echo esc_attr( $campaign['id'] ); ?>">
                                            <?php esc_html_e( 'Delete', 'subsales-management' ); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<!-- Campaign Details Modal -->
<div id="subsales-campaign-modal" class="subsales-modal" style="display: none;">
    <div class="subsales-modal-content">
        <span class="subsales-modal-close">&times;</span>
        <div id="subsales-campaign-modal-body">
            <!-- Dynamic content loaded here -->
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    
    // Toggle campaign date on click
    $('.calendar-day.has-campaign, .calendar-day:not(.past-date):not(.empty-day)').on('click', function() {
        var $cell = $(this);
        var date = $cell.data('date');
        
        if (!date) return;
        
        var isActive = $cell.hasClass('active-campaign');
        var action = isActive ? 'deactivate' : 'activate';
        
        // Confirm if deactivating with signups
        if (isActive && $cell.find('.campaign-stats').length > 0) {
            if (!confirm('This date has signups. Are you sure you want to deactivate it?')) {
                return;
            }
        }
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'subsales_toggle_campaign',
                date: date,
                campaign_action: action,
                nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + response.data.message);
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
            }
        });
    });
    
    // View signups for a campaign
    $('.view-signups').on('click', function(e) {
        e.preventDefault();
        var campaignId = $(this).data('campaign-id');
        
        $('#subsales-campaign-modal-body').html('<p>Loading...</p>');
        $('#subsales-campaign-modal').fadeIn();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'subsales_get_campaign_signups',
                campaign_id: campaignId,
                nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $('#subsales-campaign-modal-body').html(response.data.html);
                } else {
                    $('#subsales-campaign-modal-body').html('<p>Error loading signups.</p>');
                }
            }
        });
    });
    
    // Delete campaign
    $('.delete-campaign').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to delete this campaign date?')) {
            return;
        }
        
        var campaignId = $(this).data('campaign-id');
        var $row = $(this).closest('tr');
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'subsales_delete_campaign',
                campaign_id: campaignId,
                nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
            },
            success: function(response) {
                if (response.success) {
                    $row.fadeOut(function() {
                        $(this).remove();
                    });
                } else {
                    alert('Error: ' + response.data.message);
                }
            }
        });
    });
    
    // Close modal
    $('.subsales-modal-close, .subsales-modal').on('click', function(e) {
        if (e.target === this) {
            $('#subsales-campaign-modal').fadeOut();
        }
    });
    
});
</script>
