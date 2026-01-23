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
        var $row = $(this).closest('tr');
        
        $('#subsales-campaign-modal-body').html('<p>Loading...</p>');
        $('#subsales-campaign-modal').fadeIn();
        $('#subsales-campaign-modal').data('campaign-id', campaignId);
        $('#subsales-campaign-modal').data('campaign-row', $row);
        
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
                    $('#subsales-campaign-modal-body').data('campaign-id', response.data.campaign_id);
                    bindSignupModalHandlers();
                } else {
                    $('#subsales-campaign-modal-body').html('<p>Error loading signups.</p>');
                }
            }
        });
    });
    
    // Bind handlers for signup modal interactions
    function bindSignupModalHandlers() {
        var campaignId = $('#subsales-campaign-modal-body').data('campaign-id');
        
        // Show add signup form
        $('#add-signup-btn').on('click', function() {
            $('#add-signup-form').slideDown();
            $('#new-signup-search').focus();
        });
        
        // Cancel add signup
        $('#cancel-signup-btn').on('click', function() {
            $('#add-signup-form').slideUp();
            resetAddSignupForm();
        });
        
        function resetAddSignupForm() {
            $('#new-signup-team').val('');
            $('#new-signup-search').val('');
            $('#member-search-results').hide();
            $('#new-signup-members-ul').empty();
            $('#new-signup-members-list').hide();
        }
        
        // Edit team - expand detail row
        $('.edit-team-btn').on('click', function() {
            var teamId = $(this).data('team-id');
            // Hide all other detail rows
            $('.team-detail-row').not('[data-team-id="' + teamId + '"]').slideUp();
            $('.add-member-form').hide(); // Hide any open add member forms
            // Show this team's detail row
            $('.team-detail-row[data-team-id="' + teamId + '"]').slideDown();
        });
        
        // Close team detail row
        $('.close-team-detail-btn').on('click', function() {
            var teamId = $(this).data('team-id');
            $('.team-detail-row[data-team-id="' + teamId + '"]').slideUp();
        });
        
        // Add member to team button
        $('.add-member-to-team-btn').on('click', function() {
            var teamId = $(this).data('team-id');
            $('.add-member-form').not('[data-team-id="' + teamId + '"]').hide();
            $('.add-member-form[data-team-id="' + teamId + '"]').slideDown();
            $('.add-member-form[data-team-id="' + teamId + '"] .team-member-search').focus();
        });
        
        // Cancel add member to team
        $('.cancel-add-member-btn').on('click', function() {
            var teamId = $(this).data('team-id');
            $('.add-member-form[data-team-id="' + teamId + '"]').slideUp();
            $('.add-member-form[data-team-id="' + teamId + '"] .team-member-search').val('');
            $('.add-member-form[data-team-id="' + teamId + '"] .team-member-search-results').hide();
        });
        
        // Team member search
        var teamSearchTimer;
        $('.team-member-search').on('input', function() {
            var $input = $(this);
            var teamId = $input.closest('.add-member-form').data('team-id');
            var $results = $input.siblings('.team-member-search-results');
            var search = $input.val().trim();
            
            clearTimeout(teamSearchTimer);
            
            if (search.length < 2) {
                $results.hide();
                return;
            }
            
            console.log('Team member search:', search);
            
            teamSearchTimer = setTimeout(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'subsales_search_members',
                        search: search,
                        nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                    },
                    success: function(response) {
                        console.log('Team search response:', response);
                        if (response.success && response.data.members && response.data.members.length > 0) {
                            var html = '';
                            response.data.members.forEach(function(member) {
                                html += '<div class="team-member-result" data-member-id="' + member.id + '" data-team-id="' + teamId + '" style="padding: 6px; cursor: pointer; border-bottom: 1px solid #eee;">';
                                html += '<strong>' + member.name + '</strong>';
                                if (member.phone) {
                                    html += ' <span style="color: #666; font-size: 12px;">(' + member.phone + ')</span>';
                                }
                                html += '</div>';
                            });
                            $results.html(html).show();
                            bindTeamMemberResultClick();
                        } else {
                            console.log('No team members found');
                            $results.html('<div style="padding: 6px; color: #666; font-size: 12px;">No members found</div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Team search error:', error, xhr.responseText);
                        $results.html('<div style="padding: 6px; color: #d63638; font-size: 12px;">Error searching</div>').show();
                    }
                });
            }, 300);
        });
        
        function bindTeamMemberResultClick() {
            $('.team-member-result').on('click', function() {
                var memberId = $(this).data('member-id');
                var memberName = $(this).text().trim().split('(')[0].trim(); // Extract just the name
                var teamId = $(this).data('team-id');
                var $form = $('.add-member-form[data-team-id="' + teamId + '"]');
                var $teamDetailRow = $('.team-detail-row[data-team-id="' + teamId + '"]');
                
                // Add this member to the team
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'subsales_add_signup',
                        campaign_id: campaignId,
                        team_id: teamId,
                        user_id: memberId,
                        nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Show success message
                            var $existingMessage = $form.find('.success-message');
                            if ($existingMessage.length) {
                                $existingMessage.remove();
                            }
                            var $message = $('<div class="success-message" style="background: #d4edda; color: #155724; padding: 8px; border-radius: 4px; margin-bottom: 8px;">✓ ' + memberName + ' added!</div>');
                            $form.prepend($message);
                            setTimeout(function() {
                                $message.fadeOut(function() { $(this).remove(); });
                            }, 2000);
                            
                            // Fetch the member's phone for display
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'subsales_search_members',
                                    search: memberName,
                                    nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                                },
                                success: function(memberResponse) {
                                    var memberData = null;
                                    if (memberResponse.success && memberResponse.data.members) {
                                        memberData = memberResponse.data.members.find(m => m.id == memberId);
                                    }
                                    
                                    // Add member to the visible list
                                    var $membersList = $teamDetailRow.find('.members-list ul');
                                    var phoneDisplay = memberData && memberData.phone ? ' <span style="color: #666;">(' + memberData.phone + ')</span>' : '';
                                    var newMemberHtml = '<li style="padding: 8px; background: white; border-radius: 3px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; border-left: 3px solid #46b450;">' +
                                        '<span>' + memberName + phoneDisplay + '</span>' +
                                        '<span style="color: #46b450; font-size: 11px; font-weight: 600;">JUST ADDED</span>' +
                                        '</li>';
                                    $membersList.prepend(newMemberHtml);
                                    
                                    // Update team count in compact list
                                    var $teamRow = $('.subsales-signups-compact-list tbody tr[data-team-id="' + teamId + '"]');
                                    if ($teamRow.length) {
                                        var currentCount = parseInt($teamRow.find('td:eq(1)').text()) || 0;
                                        $teamRow.find('td:eq(1)').text(currentCount + 1);
                                    }
                                }
                            });
                            
                            // Clear the search but keep form open
                            $form.find('.team-member-search').val('');
                            $form.find('.team-member-search-results').hide();
                            
                            // Focus back on search to add another member
                            $form.find('.team-member-search').focus();
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                    }
                });
            });
        }
        
        // Member search (for main add signup form)
        var searchTimer;
        $('#new-signup-search').on('input', function() {
            var search = $(this).val().trim();
            clearTimeout(searchTimer);
            
            if (search.length < 2) {
                $('#member-search-results').hide();
                return;
            }
            
            console.log('Searching for:', search);
            
            searchTimer = setTimeout(function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'subsales_search_members',
                        search: search,
                        nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                    },
                    success: function(response) {
                        console.log('Search response:', response);
                        if (response.success && response.data.members && response.data.members.length > 0) {
                            var html = '';
                            response.data.members.forEach(function(member) {
                                html += '<div class="member-search-result" data-member-id="' + member.id + '" data-member-name="' + member.name + '" data-member-phone="' + member.phone + '" style="padding: 8px; cursor: pointer; border-bottom: 1px solid #eee;">';
                                html += '<strong>' + member.name + '</strong>';
                                if (member.phone) {
                                    html += ' <span style="color: #666;">(' + member.phone + ')</span>';
                                }
                                html += '</div>';
                            });
                            $('#member-search-results').html(html).show();
                            
                            // Handle result click - ADD IMMEDIATELY like Edit flow
                            $('.member-search-result').on('click', function() {
                                var teamId = $('#new-signup-team').val();
                                if (!teamId) {
                                    alert('Please select a team first');
                                    return;
                                }
                                
                                var memberId = $(this).data('member-id');
                                var memberName = $(this).data('member-name');
                                var memberPhone = $(this).data('member-phone');
                                
                                // Add member immediately via AJAX
                                $.ajax({
                                    url: ajaxurl,
                                    type: 'POST',
                                    data: {
                                        action: 'subsales_add_signup',
                                        campaign_id: campaignId,
                                        team_id: teamId,
                                        user_id: memberId,
                                        nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                                    },
                                    success: function(response) {
                                        if (response.success) {
                                            // Show success and add to visible list
                                            var phoneDisplay = memberPhone ? ' <span style="color: #666;">(' + memberPhone + ')</span>' : '';
                                            var newMemberHtml = '<li style="padding: 8px; background: white; border-radius: 3px; margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; border-left: 3px solid #46b450;">' +
                                                '<span>' + memberName + phoneDisplay + '</span>' +
                                                '<span style="color: #46b450; font-size: 11px; font-weight: 600;">JUST ADDED</span>' +
                                                '</li>';
                                            $('#new-signup-members-ul').append(newMemberHtml);
                                            $('#new-signup-members-list').show();
                                            
                                            // Update member count in compact list for this team
                                            var $teamRow = $('.subsales-signups-compact-list tbody tr[data-team-id="' + teamId + '"]');
                                            if ($teamRow.length) {
                                                var currentCount = parseInt($teamRow.find('td:eq(1)').text()) || 0;
                                                $teamRow.find('td:eq(1)').text(currentCount + 1);
                                            }
                                            
                                            // Clear search and focus back to add more
                                            $('#new-signup-search').val('');
                                            $('#member-search-results').hide();
                                            $('#new-signup-search').focus();
                                        } else {
                                            alert('Error: ' + response.data.message);
                                        }
                                    },
                                    error: function() {
                                        alert('An error occurred adding the member.');
                                    }
                                });
                            });
                        } else {
                            console.log('No results found');
                            $('#member-search-results').html('<div style="padding: 8px; color: #666;">No members found.</div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Search error:', error, xhr.responseText);
                        $('#member-search-results').html('<div style="padding: 8px; color: #d63638;">Error searching. Check console.</div>').show();
                    }
                });
            }, 300);
        });
        
        // Remove signup
        $('.remove-signup-btn').on('click', function() {
            var signupId = $(this).data('signup-id');
            var memberName = $(this).data('member-name');
            
            if (!confirm('Remove ' + memberName + ' from this campaign?')) {
                return;
            }
            
            var $btn = $(this);
            $btn.prop('disabled', true).text('Removing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'subsales_remove_signup',
                    signup_id: signupId,
                    nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        // Reload the modal
                        $('.view-signups[data-campaign-id="' + campaignId + '"]').trigger('click');
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false).text('Remove');
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $btn.prop('disabled', false).text('Remove');
                }
            });
        });
        
        // Update driver
        $('.update-driver-btn').on('click', function() {
            var teamId = $(this).data('team-id');
            var driverName = $('.driver-name-input[data-team-id="' + teamId + '"]').val().trim();
            var $btn = $(this);
            
            $btn.prop('disabled', true).text('Updating...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'subsales_update_team_driver',
                    campaign_id: campaignId,
                    team_id: teamId,
                    driver_name: driverName,
                    nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $btn.text('✓ Updated');
                        setTimeout(function() {
                            $btn.prop('disabled', false).text('Update');
                        }, 2000);
                        
                        // Update the compact list driver display
                        var $compactRow = $('.team-compact-row[data-team-id="' + teamId + '"]');
                        var $driverCell = $compactRow.find('td:eq(2)');
                        if (driverName) {
                            $driverCell.html('<span class="dashicons dashicons-yes" style="color: #46b450;"></span> ' + driverName);
                        } else {
                            $driverCell.html('<span style="color: #999;">—</span>');
                        }
                    } else {
                        alert('Error: ' + response.data.message);
                        $btn.prop('disabled', false).text('Update');
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $btn.prop('disabled', false).text('Update');
                }
            });
        });
    }
    
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
            var campaignId = $('#subsales-campaign-modal').data('campaign-id');
            var $row = $('#subsales-campaign-modal').data('campaign-row');
            
            // Refresh counts for the campaign
            if (campaignId && $row) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'subsales_get_campaign_counts',
                        campaign_id: campaignId,
                        nonce: '<?php echo wp_create_nonce( 'subsales_campaign_nonce' ); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update table row
                            $row.find('td:eq(2)').text(response.data.team_count);
                            $row.find('td:eq(3)').text(response.data.member_count);
                            
                            // Update calendar cell if visible
                            var campaignDate = response.data.campaign_date;
                            var $calendarCell = $('.calendar-day[data-date="' + campaignDate + '"]');
                            if ($calendarCell.length) {
                                var $stats = $calendarCell.find('.campaign-stats');
                                if (response.data.member_count > 0) {
                                    if ($stats.length === 0) {
                                        $calendarCell.find('.day-content').append('<div class="campaign-stats"></div>');
                                        $stats = $calendarCell.find('.campaign-stats');
                                    }
                                    $stats.html(
                                        '<span class="stat-teams" title="' + response.data.team_count + ' teams">' + response.data.team_count + ' teams</span>' +
                                        '<span class="stat-members" title="' + response.data.member_count + ' members">' + response.data.member_count + ' members</span>'
                                    );
                                } else {
                                    $stats.remove();
                                }
                            }
                        }
                    }
                });
            }
            
            $('#subsales-campaign-modal').fadeOut();
        }
    });
    
});
</script>
