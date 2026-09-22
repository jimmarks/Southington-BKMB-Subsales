<?php
/**
 * Driver (parent) self-registration for Subsales Management Plugin
 *
 * Parents/drivers are not in the child database, so they need their own
 * registration path. This flow lets a parent:
 *  - Identify their child (name + phone) to find which team and dates the
 *    child signed up for
 *  - Pick which of those team/days they will drive for
 *  - Register themselves as the driver for the selected team/days
 *
 * Reuses the canonical data layer (Subsales_Database) for all reads/writes,
 * the same way the regular child signup does. The child-name field uses the
 * shared /users/search autocomplete so parents can find their kid quickly.
 *
 * Page:  /driver-signup
 * REST:  POST /order-manager/v1/driver-signup/lookup-child
 *        POST /order-manager/v1/driver-signup
 *
 * @package Subsales_Management
 * @since 2.4.115
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Driver_Signup {

    /**
     * Initialize the driver signup functionality
     */
    public static function init() {
        add_action( 'wp', array( __CLASS__, 'catch_driver_signup_404' ), 1 );
        add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 1 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    /**
     * Add rewrite rules for /driver-signup endpoint
     */
    public static function add_rewrite_rules() {
        add_rewrite_rule( '^driver-signup(/(.*))?$', 'index.php?subsales_driver_signup=1', 'top' );
        add_rewrite_tag( '%subsales_driver_signup%', '([^&]+)' );
    }

    /**
     * Catch driver-signup 404s at wp hook (earlier than template_redirect)
     */
    public static function catch_driver_signup_404() {
        $req_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );

        if ( $req_path === 'driver-signup' || strpos( $req_path, 'driver-signup/' ) === 0 ) {
            global $wp_query;
            $wp_query->is_404 = false;
            status_header( 200 );
            self::serve_driver_signup_page();
            exit;
        }
    }

    /**
     * Register REST API endpoints
     */
    public static function register_rest_routes() {
        // Look up a child's team(s) and campaign date(s) by name + phone
        register_rest_route( 'order-manager/v1', '/driver-signup/lookup-child', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_lookup_child' ),
            'permission_callback' => '__return_true',
        ));

        // Register the parent as a driver for the selected team(s)/date(s)
        register_rest_route( 'order-manager/v1', '/driver-signup', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_driver_signup' ),
            'permission_callback' => '__return_true',
        ));

        // A driver's own days, and taking themselves off one. Identified by
        // their own name + phone - the same proof the kids' page uses - and
        // every removal re-checks that the row is theirs, so this does not lean
        // on the open DELETE /my-signups/{id}.
        register_rest_route( 'order-manager/v1', '/driver-signup/my-days', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_my_days' ),
            'permission_callback' => '__return_true',
        ));
        register_rest_route( 'order-manager/v1', '/driver-signup/remove', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_remove_day' ),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * POST /driver-signup/lookup-child
     * Identify a child and return the team(s)/date(s) they signed up for.
     */
    public static function rest_lookup_child( $request ) {
        $body        = $request->get_json_params();
        $child_name  = isset( $body['child_name'] ) ? sanitize_text_field( $body['child_name'] ) : '';
        $child_phone = isset( $body['child_phone'] ) ? preg_replace( '/\D/', '', $body['child_phone'] ) : '';

        if ( empty( $child_name ) || empty( $child_phone ) ) {
            return new WP_Error( 'missing_params', 'Child name and phone number are required.', array( 'status' => 400 ) );
        }

        if ( ! preg_match( '/^[0-9]{10}$/', $child_phone ) ) {
            return new WP_Error( 'invalid_phone', 'Phone number must be 10 digits.', array( 'status' => 400 ) );
        }

        $lookup = Subsales_Database::get_member_signups_by_phone( $child_phone );

        if ( ! $lookup['user'] ) {
            return new WP_Error(
                'child_not_found',
                "We couldn't find a child with that phone number. Have them complete their signup first.",
                array( 'status' => 404 )
            );
        }

        // Name match: case-insensitive partial, both directions
        $child_actual_name = $lookup['user']['name'];
        if ( stripos( $child_actual_name, $child_name ) === false
            && stripos( $child_name, $child_actual_name ) === false ) {
            return new WP_Error(
                'name_mismatch',
                'The name does not match the phone number on file.',
                array( 'status' => 401 )
            );
        }

        if ( empty( $lookup['signups'] ) ) {
            return new WP_Error(
                'no_signups',
                "Your child hasn't signed up yet, have them complete their signup first.",
                array( 'status' => 404 )
            );
        }

        // A day that already has a driver is shown, not offered: the parent
        // sees who is driving and their number, so the two can sort it out
        // between themselves. Only after proving the child's name + phone.
        $signups = $lookup['signups'];
        foreach ( $signups as &$sig ) {
            $d = Subsales_Database::get_campaign_team_roster( intval( $sig['team_id'] ), intval( $sig['campaign_id'] ) )['driver'];
            $sig['driver_name']  = $d ? $d['name'] : '';
            $sig['driver_phone'] = $d ? self::format_phone( $d['phone'] ) : '';
        }
        unset( $sig );

        return rest_ensure_response( array(
            'success'    => true,
            'child_id'   => intval( $lookup['user']['id'] ),
            'child_name' => $child_actual_name,
            'signups'    => $signups,
        ) );
    }

    /**
     * POST /driver-signup
     * Register the parent as a driver for the team/date rows they selected.
     *
     * Body: child_phone, driver_name, driver_phone, selections[]={team_id,campaign_id}
     */
    public static function rest_driver_signup( $request ) {
        $body         = $request->get_json_params();
        $child_phone  = isset( $body['child_phone'] ) ? preg_replace( '/\D/', '', $body['child_phone'] ) : '';
        $driver_name  = isset( $body['driver_name'] ) ? sanitize_text_field( $body['driver_name'] ) : '';
        $driver_phone = isset( $body['driver_phone'] ) ? preg_replace( '/\D/', '', $body['driver_phone'] ) : '';
        $driver_email = isset( $body['driver_email'] ) ? sanitize_email( $body['driver_email'] ) : '';
        $selections   = ( isset( $body['selections'] ) && is_array( $body['selections'] ) ) ? $body['selections'] : array();

        if ( empty( $child_phone ) || empty( $driver_name ) || empty( $driver_phone ) ) {
            return new WP_Error( 'missing_params', 'Child phone, driver name, and driver phone are required.', array( 'status' => 400 ) );
        }

        // Required: it is the only way we can tell a driver their child moved
        // teams and took them along - or that they are no longer driving.
        if ( ! is_email( $driver_email ) ) {
            return new WP_Error( 'invalid_email', 'Please enter a valid email address so we can tell you about changes.', array( 'status' => 400 ) );
        }

        if ( ! preg_match( '/^[0-9]{10}$/', $driver_phone ) ) {
            return new WP_Error( 'invalid_phone', 'Driver phone number must be 10 digits.', array( 'status' => 400 ) );
        }

        if ( empty( $selections ) ) {
            return new WP_Error( 'no_selection', 'Please choose at least one day to drive.', array( 'status' => 400 ) );
        }

        // Re-derive the child's signups to build an allow-set (don't trust client team/campaign ids)
        $lookup = Subsales_Database::get_member_signups_by_phone( $child_phone );
        if ( ! $lookup['user'] ) {
            return new WP_Error( 'child_not_found', "We couldn't find a child with that phone number.", array( 'status' => 404 ) );
        }

        $allowed = array();
        foreach ( $lookup['signups'] as $s ) {
            $allowed[ intval( $s['team_id'] ) . ':' . intval( $s['campaign_id'] ) ] = true;
        }

        // Validate selections against the allow-set, group campaigns by team
        $by_team = array();
        foreach ( $selections as $sel ) {
            $team_id     = isset( $sel['team_id'] ) ? intval( $sel['team_id'] ) : 0;
            $campaign_id = isset( $sel['campaign_id'] ) ? intval( $sel['campaign_id'] ) : 0;
            if ( ! $team_id || ! $campaign_id ) {
                continue;
            }
            if ( empty( $allowed[ $team_id . ':' . $campaign_id ] ) ) {
                continue;
            }
            $by_team[ $team_id ][] = $campaign_id;
        }

        if ( empty( $by_team ) ) {
            return new WP_Error( 'invalid_selection', "The selected days could not be matched to your child's signups.", array( 'status' => 400 ) );
        }

        // One driver per team per day. set_driver() used to make whoever signed
        // up last the driver and quietly turn the previous one into a seller on
        // the roster. Re-signing yourself is fine; taking someone's day is not.
        global $wpdb;
        $me    = intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ss_team_members WHERE phone = %s", $driver_phone
        ) ) );
        $taken = array();
        foreach ( $by_team as $team_id => $campaign_ids ) {
            foreach ( $campaign_ids as $cid ) {
                $d = Subsales_Database::get_campaign_team_roster( $team_id, $cid )['driver'];
                if ( $d && intval( $d['id'] ) !== $me ) {
                    $taken[] = self::day_label( $cid ) . ' (' . $d['name'] . ', ' . self::format_phone( $d['phone'] ) . ')';
                }
            }
        }
        if ( $taken ) {
            return new WP_Error( 'already_has_driver',
                'Already has a driver: ' . implode( '; ', $taken ) . '. Call them if you would like to talk it over.',
                array( 'status' => 409 ) );
        }

        // Register the driver per team via the canonical signup writer
        $processed = array();
        foreach ( $by_team as $team_id => $campaign_ids ) {
            $result = Subsales_Database::register_member_signups( array(
                'name'               => $driver_name,
                'phone'              => $driver_phone,
                'team_id'            => $team_id,
                'campaign_ids'       => $campaign_ids,
                'is_driver'          => true,
                'driver_for_user_id' => intval( $lookup['user']['id'] ),
            ) );

            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $driver_id = intval( $result['user_id'] );

            foreach ( $campaign_ids as $cid ) {
                $processed[] = array( 'team_id' => intval( $team_id ), 'campaign_id' => intval( $cid ) );
            }
        }

        $wpdb->update( $wpdb->prefix . 'ss_team_members', array( 'email' => $driver_email ), array( 'id' => $driver_id ), array( '%s' ), array( '%d' ) );

        subsales_log( 'INFO', 'driver-signup', 'Driver self-registered', array(
            'driver_name' => $driver_name,
            'processed'   => count( $processed ),
        ) );

        $days = array();
        foreach ( $processed as $p ) {
            $days[] = '  ' . self::day_label( $p['campaign_id'] ) . ' - ' . self::team_name( $p['team_id'] );
        }
        $emailed = self::email_driver( $driver_id,
            'You are driving for the Sub Sale',
            "Hi {$driver_name},\n\n"
            . "You've committed to driving on:\n\n" . implode( "\n", $days ) . "\n\n"
            . "If you can no longer drive one of these days, take yourself off at " . home_url( '/driver-signup/' )
            . " - choose \"Already driving? Manage your days\" and use your own name and phone number.\n\n"
            . "Thank you for driving!\n"
        );

        return rest_ensure_response( array(
            'success'   => true,
            'processed' => $processed,
            'emailed'   => $emailed,
            'message'   => 'You are registered as the driver. Thank you!',
        ) );
    }

    /**
     * POST /driver-signup/my-days  { driver_name, driver_phone }
     */
    public static function rest_my_days( $request ) {
        $driver = self::verify_person( $request->get_json_params() );
        if ( is_wp_error( $driver ) ) {
            return $driver;
        }
        return rest_ensure_response( array(
            'success' => true,
            'name'    => $driver['name'],
            'days'    => self::driving_days( $driver['id'] ),
        ) );
    }

    /**
     * POST /driver-signup/remove  { driver_name, driver_phone, signup_id }
     */
    public static function rest_remove_day( $request ) {
        global $wpdb;
        $body   = $request->get_json_params();
        $driver = self::verify_person( $body );
        if ( is_wp_error( $driver ) ) {
            return $driver;
        }

        $signup_id = isset( $body['signup_id'] ) ? intval( $body['signup_id'] ) : 0;
        $t   = $wpdb->prefix . 'ss_signups';
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, campaign_id FROM {$t}
              WHERE id = %d AND user_id = %d AND is_driver = 1 AND status = 'active'",
            $signup_id, $driver['id']
        ), ARRAY_A );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'That driving day was not found on your sign-up.', array( 'status' => 404 ) );
        }

        $wpdb->update( $t, array( 'status' => 'cancelled' ), array( 'id' => $signup_id ), array( '%s' ), array( '%d' ) );
        Subsales_Database::clear_team_campaign_driver( intval( $row['team_id'] ), intval( $row['campaign_id'] ), $driver['name'] );

        subsales_log( 'INFO', 'driver-signup', 'Driver removed themselves from a day', array(
            'member_id' => $driver['id'], 'team_id' => intval( $row['team_id'] ), 'campaign_id' => intval( $row['campaign_id'] ),
        ) );

        return rest_ensure_response( array( 'success' => true, 'days' => self::driving_days( $driver['id'] ) ) );
    }

    /**
     * Name + phone -> member, with the same partial name match as
     * rest_lookup_child(). Returns { id, name } or a WP_Error.
     */
    private static function verify_person( $body ) {
        global $wpdb;
        $name  = isset( $body['driver_name'] ) ? sanitize_text_field( $body['driver_name'] ) : '';
        $phone = isset( $body['driver_phone'] ) ? preg_replace( '/\D/', '', $body['driver_phone'] ) : '';
        if ( $name === '' || ! preg_match( '/^[0-9]{10}$/', $phone ) ) {
            return new WP_Error( 'missing_params', 'Enter your name and 10-digit phone number.', array( 'status' => 400 ) );
        }
        $m = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}ss_team_members WHERE phone = %s", $phone
        ), ARRAY_A );
        if ( ! $m || ( stripos( $m['name'], $name ) === false && stripos( $name, $m['name'] ) === false ) ) {
            // One message for both: which half was wrong is not ours to confirm.
            return new WP_Error( 'not_found', 'We could not find a driver with that name and phone number.', array( 'status' => 404 ) );
        }
        return array( 'id' => intval( $m['id'] ), 'name' => $m['name'] );
    }

    /** Active driving days for a member, current season only. */
    private static function driving_days( $member_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id AS signup_id, s.campaign_id, t.name AS team_name
               FROM {$wpdb->prefix}ss_signups s
               JOIN {$wpdb->prefix}ss_teams t ON t.id = s.team_id
               JOIN {$wpdb->prefix}ss_campaigns c ON c.id = s.campaign_id
              WHERE s.user_id = %d AND s.is_driver = 1 AND s.status = 'active' AND c.season_id = %d
              ORDER BY c.campaign_date",
            $member_id, Subsales_Database::current_season_id()
        ), ARRAY_A );
        foreach ( $rows as &$r ) {
            $r['day'] = self::day_label( $r['campaign_id'] );
        }
        return $rows;
    }

    /** "Tuesday, September 22, 2026" (plus the sale's name when it has one). */
    public static function day_label( $campaign_id ) {
        global $wpdb;
        $c = $wpdb->get_row( $wpdb->prepare(
            "SELECT campaign_date, campaign_name FROM {$wpdb->prefix}ss_campaigns WHERE id = %d", $campaign_id
        ), ARRAY_A );
        if ( ! $c ) {
            return '';
        }
        $label = date_i18n( 'l, F j, Y', strtotime( $c['campaign_date'] ) );
        return $c['campaign_name'] ? $label . ' (' . $c['campaign_name'] . ')' : $label;
    }

    /** 8605551234 -> (860) 555-1234; anything else unchanged. */
    public static function format_phone( $phone ) {
        $d = preg_replace( '/\D/', '', (string) $phone );
        return strlen( $d ) === 10 ? sprintf( '(%s) %s-%s', substr( $d, 0, 3 ), substr( $d, 3, 3 ), substr( $d, 6 ) ) : (string) $phone;
    }

    public static function team_name( $team_id ) {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}ss_teams WHERE id = %d", $team_id ) );
    }

    /**
     * Email a driver. Returns whether it was handed to the mail transport.
     *
     * Every failure is logged, because the failure mode here is silence: no
     * address on file, or a site with no working mailer, both look exactly
     * like "sent" to the person who triggered it. The address itself is not
     * logged - it is a parent's personal contact detail.
     */
    public static function email_driver( $member_id, $subject, $body ) {
        global $wpdb;
        $email = $wpdb->get_var( $wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}ss_team_members WHERE id = %d", $member_id
        ) );
        if ( ! $email || ! is_email( $email ) ) {
            subsales_log( 'WARNING', 'driver-email', 'No email on file - driver not told', array( 'member_id' => $member_id, 'subject' => $subject ) );
            return false;
        }

        $headers = array();
        $reply   = get_option( 'subsales_admin_email' );
        if ( $reply && is_email( $reply ) ) {
            $headers[] = 'Reply-To: ' . $reply;
        }
        $body .= "\n-- \n" . get_option( 'subsales_branding', 'Southington BKMB' ) . "\n";

        $sent = wp_mail( $email, $subject, $body, $headers );
        subsales_log( $sent ? 'INFO' : 'ERROR', 'driver-email',
            $sent ? 'Driver emailed' : 'wp_mail failed - driver not told; check the mail setup',
            array( 'member_id' => $member_id, 'subject' => $subject ) );
        return $sent;
    }

    /**
     * Serve the driver signup page at /driver-signup endpoint.
     * Standalone HTML, same scaffold/options as subsales_serve_signup_page().
     */
    public static function serve_driver_signup_page() {
        $header_image_id  = intval( get_option( 'subsales_header_image', 0 ) );
        $header_image_url = $header_image_id ? wp_get_attachment_url( $header_image_id ) : '';
        $brand_name       = get_option( 'subsales_branding', 'Subsales' );
        $admin_email      = get_option( 'subsales_admin_email', get_option( 'admin_email' ) );
        $primary_color    = get_option( 'order_sync_primary_color', '#2d6cdf' );

        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'Cache-Control: no-cache, must-revalidate' );
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $brand_name ); ?> - Driver Sign Up</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { text-align: center; padding: 20px 0; background: white; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .header img { max-width: 200px; height: auto; margin-bottom: 10px; }
        .header h1 { color: <?php echo esc_attr( $primary_color ); ?>; font-size: 24px; }
        .header p { color: #777; font-size: 15px; margin-top: 4px; }
        .card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; position: relative; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #555; }
        input[type="text"], input[type="tel"], input[type="email"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        input:focus { outline: none; border-color: <?php echo esc_attr( $primary_color ); ?>; }
        .btn { width: 100%; padding: 14px; background: <?php echo esc_attr( $primary_color ); ?>; color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: 600; cursor: pointer; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { background: #ccc; cursor: not-allowed; }
        .btn-secondary { background: #6c757d; margin-top: 10px; }
        .hidden { display: none; }
        .error { color: #dc3545; font-size: 14px; margin-top: 10px; }
        .success { color: #28a745; font-size: 14px; margin-top: 5px; }
        .child-summary { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 6px; padding: 16px; margin-bottom: 20px; }
        .child-summary h3 { font-size: 16px; margin-bottom: 10px; color: <?php echo esc_attr( $primary_color ); ?>; }
        .signup-row { padding: 6px 0; border-bottom: 1px solid #eee; font-size: 15px; }
        .signup-row:last-child { border-bottom: none; }
        .signup-row .team { font-weight: 600; }
        .signup-row .date { color: #666; }
        /* Per-day driver selection (reuses the campaign-picker idea from kid signup) */
        .day-picker { margin-bottom: 20px; }
        .day-option { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 8px; cursor: pointer; }
        .day-option input { width: 18px; height: 18px; flex: 0 0 auto; }
        .day-option .day-team { font-weight: 600; }
        .day-option .day-date { color: #666; font-size: 14px; }
        .day-option.day-taken { background: #f8f9fa; cursor: default; }
        .day-option .day-driver { font-size: 14px; color: #555; }
        .day-option .day-driver a { color: inherit; }
        /* Name autocomplete dropdown */
        .autocomplete-results { border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px; max-height: 220px; overflow-y: auto; background: #fff; }
        .autocomplete-results button { display: block; width: 100%; text-align: left; padding: 10px 12px; background: #fff; border: none; border-bottom: 1px solid #f0f0f0; font-size: 15px; cursor: pointer; }
        .autocomplete-results button:hover { background: #f5f7ff; }
        .autocomplete-results .ac-help { padding: 8px 12px; color: #777; font-size: 13px; }
        .link-btn { background: none; border: none; color: <?php echo esc_attr( $primary_color ); ?>; font-size: 15px; text-decoration: underline; cursor: pointer; padding: 0; margin-top: 16px; }
        .my-day { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-bottom: 1px solid #eee; }
        .my-day:last-child { border-bottom: none; }
        .my-day .btn { width: auto; padding: 8px 14px; font-size: 14px; background: #dc3545; flex: 0 0 auto; }
        .footer { text-align: center; padding: 20px 0; margin-top: 20px; }
        .footer-email-btn { display: inline-block; padding: 10px 20px; background: <?php echo esc_attr( $primary_color ); ?>; color: white; text-decoration: none; border-radius: 6px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <?php if ( $header_image_url ) : ?>
                <img src="<?php echo esc_url( $header_image_url ); ?>" alt="<?php echo esc_attr( $brand_name ); ?>">
            <?php endif; ?>
            <h1>Driver Sign Up</h1>
            <p>Volunteer to drive for your child's team</p>
        </div>

        <!-- Step 1: Identify the child -->
        <div class="card" id="step1">
            <div class="form-group">
                <label for="childName">Your Child's Name</label>
                <input type="text" id="childName" placeholder="Start typing your child's name" autocomplete="new-password" autocorrect="off" spellcheck="false" autocapitalize="words">
                <div class="autocomplete-results hidden" id="childNameResults"></div>
            </div>
            <div class="form-group">
                <label for="childPhone">Your Child's Phone Number</label>
                <input type="text" inputmode="tel" id="childPhone" placeholder="(555) 555-5555" autocomplete="new-password" autocorrect="off" spellcheck="false">
            </div>
            <button class="btn" id="lookupBtn">Find My Child's Team</button>
            <div class="error hidden" id="step1Error"></div>
            <button type="button" class="link-btn" id="manageLink">Already driving? Manage your days</button>
        </div>

        <!-- A driver's own days: identified by THEIR name + phone -->
        <div class="card hidden" id="manage">
            <h2 style="font-size: 20px; margin-bottom: 16px;">Your driving days</h2>
            <div id="manageLogin">
                <div class="form-group">
                    <label for="mName">Your Name (Driver)</label>
                    <input type="text" id="mName" placeholder="Your full name" autocomplete="name" autocapitalize="words">
                </div>
                <div class="form-group">
                    <label for="mPhone">Your Phone Number</label>
                    <input type="text" inputmode="tel" id="mPhone" placeholder="(555) 555-5555" autocomplete="tel">
                </div>
                <button class="btn" id="mFindBtn">Show My Days</button>
            </div>
            <div id="mDays"></div>
            <div class="error hidden" id="mError"></div>
            <button class="btn btn-secondary" id="mBackBtn">Back</button>
        </div>

        <!-- Step 2: Driver details -->
        <div class="card hidden" id="step2">
            <div class="child-summary" id="childSummary"></div>
            <div class="form-group">
                <label>Which days will you drive?</label>
                <div class="day-picker" id="dayPicker"></div>
            </div>
            <div class="form-group">
                <label for="driverName">Your Name (Driver)</label>
                <input type="text" id="driverName" placeholder="Your full name" autocomplete="new-password" autocorrect="off" spellcheck="false" autocapitalize="words">
            </div>
            <div class="form-group">
                <label for="driverPhone">Your Phone Number</label>
                <input type="text" inputmode="tel" id="driverPhone" placeholder="(555) 555-5555" autocomplete="new-password" autocorrect="off" spellcheck="false">
            </div>
            <div class="form-group">
                <label for="driverEmail">Your Email</label>
                <input type="email" id="driverEmail" placeholder="you@example.com" autocomplete="email" autocapitalize="off" spellcheck="false">
                <p style="font-size: 13px; color: #777; margin-top: 6px;">We'll email you which days you're driving, and if your child changes teams.</p>
            </div>
            <button class="btn" id="registerBtn">Register as Driver</button>
            <button class="btn btn-secondary" id="backBtn">Back</button>
            <div class="error hidden" id="step2Error"></div>
        </div>

        <!-- Confirmation -->
        <div class="card hidden" id="confirmation">
            <h2 style="color: <?php echo esc_attr( $primary_color ); ?>; margin-bottom: 12px;">You're all set!</h2>
            <p id="confirmationMsg"></p>
            <div class="child-summary" id="confirmationSummary" style="margin-top:16px;"></div>
        </div>

        <div class="footer">
            <a class="footer-email-btn" href="mailto:<?php echo esc_attr( $admin_email ); ?>">Need help? Email us</a>
        </div>
    </div>

    <script>
        const apiBase = <?php echo wp_json_encode( rest_url( 'order-manager/v1' ) ); ?>;
        let childData = null;

        const $ = (id) => document.getElementById(id);

        function showError(el, msg) { el.textContent = msg; el.classList.remove('hidden'); }
        function hideError(el) { el.textContent = ''; el.classList.add('hidden'); }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str == null ? '' : String(str);
            return div.innerHTML;
        }

        function dayLabel(s) {
            const d = s.campaign_date || '';
            return s.campaign_name ? (s.campaign_name + ' — ' + d) : d;
        }

        // Read-only summary list (used in confirmation)
        function renderSignups(signups) {
            return signups.map(s =>
                '<div class="signup-row"><span class="team">' + escapeHtml(s.team_name) +
                '</span> <span class="date">' + escapeHtml(dayLabel(s)) + '</span></div>'
            ).join('');
        }

        // Per-day checkboxes, checked by default - except days that already
        // have a driver, which show who it is and how to reach them instead.
        function renderDayPicker(signups) {
            return signups.map((s, i) => {
                const head = '<span class="day-team">' + escapeHtml(s.team_name) + '</span><br>' +
                             '<span class="day-date">' + escapeHtml(dayLabel(s)) + '</span>';
                if (s.driver_name) {
                    return '<div class="day-option day-taken">' +
                        '<span>' + head + '<br><span class="day-driver">Already has a driver: <strong>' +
                        escapeHtml(s.driver_name) + '</strong>' +
                        (s.driver_phone ? ' &mdash; <a href="tel:' + escapeHtml(s.driver_phone.replace(/\D/g, '')) + '">' + escapeHtml(s.driver_phone) + '</a>' : '') +
                        '</span></span></div>';
                }
                return '<label class="day-option">' +
                    '<input type="checkbox" class="day-check" data-team-id="' + parseInt(s.team_id, 10) +
                        '" data-campaign-id="' + parseInt(s.campaign_id, 10) + '" checked>' +
                    '<span>' + head + '</span></label>';
            }).join('');
        }

        // ---- Child-name autocomplete (shared /users/search endpoint) ----
        let acTimer = null;
        $('childName').addEventListener('input', () => {
            const query = $('childName').value.trim();
            const box = $('childNameResults');
            childData = null; // typing invalidates a prior selection
            if (acTimer) clearTimeout(acTimer);
            if (query.length < 2) { box.classList.add('hidden'); box.innerHTML = ''; return; }
            acTimer = setTimeout(async () => {
                try {
                    const res = await fetch(apiBase + '/users/search?name=' + encodeURIComponent(query));
                    const data = await res.json();
                    if (!Array.isArray(data) || data.length === 0) {
                        box.innerHTML = '<div class="ac-help">No matches yet — keep typing your child’s name.</div>';
                        box.classList.remove('hidden');
                        return;
                    }
                    box.innerHTML = data.map(u =>
                        '<button type="button" data-name="' + escapeHtml(u.name) + '">' + escapeHtml(u.name) + '</button>'
                    ).join('');
                    box.classList.remove('hidden');
                    box.querySelectorAll('button').forEach(btn => {
                        btn.addEventListener('click', () => {
                            $('childName').value = btn.dataset.name;
                            box.classList.add('hidden');
                            box.innerHTML = '';
                            $('childPhone').focus();
                        });
                    });
                } catch (e) {
                    box.classList.add('hidden');
                }
            }, 200);
        });

        // Step 1: look up child
        $('lookupBtn').addEventListener('click', async () => {
            hideError($('step1Error'));
            const child_name = $('childName').value.trim();
            const child_phone = $('childPhone').value.trim();

            if (!child_name || !child_phone) {
                showError($('step1Error'), 'Please enter your child’s name and phone number.');
                return;
            }

            $('lookupBtn').disabled = true;
            $('lookupBtn').textContent = 'Searching…';

            try {
                const res = await fetch(apiBase + '/driver-signup/lookup-child', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ child_name, child_phone })
                });
                const data = await res.json();

                if (!res.ok) {
                    showError($('step1Error'), (data && data.message) || 'We could not find that child.');
                    return;
                }

                childData = data;
                const open = data.signups.filter(s => !s.driver_name).length;
                const taken = data.signups.length - open;
                $('childSummary').innerHTML = '<h3>' + escapeHtml(data.child_name) + '</h3>' +
                    '<p style="font-size:14px;color:#666;">' +
                    (open ? 'Uncheck any days you are not driving.' : 'Every day already has a driver.') +
                    (taken ? ' If you would like to talk about a day that already has a driver, call them.' : '') + '</p>';
                $('dayPicker').innerHTML = renderDayPicker(data.signups);
                $('registerBtn').disabled = !open;
                $('step1').classList.add('hidden');
                $('step2').classList.remove('hidden');
            } catch (e) {
                showError($('step1Error'), 'Something went wrong. Please try again.');
            } finally {
                $('lookupBtn').disabled = false;
                $('lookupBtn').textContent = 'Find My Child’s Team';
            }
        });

        // Step 2: register driver
        $('registerBtn').addEventListener('click', async () => {
            hideError($('step2Error'));
            const driver_name = $('driverName').value.trim();
            const driver_phone = $('driverPhone').value.trim();
            const driver_email = $('driverEmail').value.trim();

            if (!driver_name || !driver_phone || !driver_email) {
                showError($('step2Error'), 'Please enter your name, phone number and email.');
                return;
            }

            const selections = Array.from(document.querySelectorAll('.day-check'))
                .filter(c => c.checked)
                .map(c => ({ team_id: parseInt(c.dataset.teamId, 10), campaign_id: parseInt(c.dataset.campaignId, 10) }));

            if (selections.length === 0) {
                showError($('step2Error'), 'Please choose at least one day to drive.');
                return;
            }

            $('registerBtn').disabled = true;
            $('registerBtn').textContent = 'Registering…';

            try {
                const res = await fetch(apiBase + '/driver-signup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        child_phone: $('childPhone').value.trim(),
                        driver_name,
                        driver_phone,
                        driver_email,
                        selections
                    })
                });
                const data = await res.json();

                if (!res.ok) {
                    showError($('step2Error'), (data && data.message) || 'Registration failed.');
                    return;
                }

                // Show only the days they registered for
                const chosen = new Set(selections.map(s => s.team_id + ':' + s.campaign_id));
                const chosenSignups = childData.signups.filter(s => chosen.has(parseInt(s.team_id, 10) + ':' + parseInt(s.campaign_id, 10)));

                $('confirmationMsg').textContent = data.message || 'You are registered as the driver.';
                $('confirmationSummary').innerHTML = '<h3>' + escapeHtml(driver_name) + ' — driving for ' +
                    escapeHtml(childData.child_name) + '</h3>' + renderSignups(chosenSignups);
                $('step2').classList.add('hidden');
                $('confirmation').classList.remove('hidden');
            } catch (e) {
                showError($('step2Error'), 'Something went wrong. Please try again.');
            } finally {
                $('registerBtn').disabled = false;
                $('registerBtn').textContent = 'Register as Driver';
            }
        });

        $('backBtn').addEventListener('click', () => {
            $('step2').classList.add('hidden');
            $('step1').classList.remove('hidden');
            hideError($('step2Error'));
        });

        // ---- Manage your days ----
        $('manageLink').addEventListener('click', () => {
            $('step1').classList.add('hidden');
            $('manage').classList.remove('hidden');
            $('mName').focus();
        });
        $('mBackBtn').addEventListener('click', () => {
            $('manage').classList.add('hidden');
            $('step1').classList.remove('hidden');
            hideError($('mError'));
        });

        function driverCreds() {
            return { driver_name: $('mName').value.trim(), driver_phone: $('mPhone').value.trim() };
        }

        function renderMyDays(days) {
            if (!days.length) {
                $('mDays').innerHTML = '<p style="color:#666;">You are not signed up to drive any days.</p>';
                return;
            }
            $('mDays').innerHTML = days.map(d =>
                '<div class="my-day"><span><strong>' + escapeHtml(d.team_name) + '</strong><br>' +
                '<span style="color:#666;font-size:14px;">' + escapeHtml(d.day) + '</span></span>' +
                '<button class="btn" data-id="' + parseInt(d.signup_id, 10) + '">Remove</button></div>'
            ).join('');
            $('mDays').querySelectorAll('button').forEach(btn => btn.addEventListener('click', async () => {
                if (!confirm('Stop driving this day? The team will show that it needs a driver.')) return;
                btn.disabled = true;
                hideError($('mError'));
                try {
                    const res = await fetch(apiBase + '/driver-signup/remove', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(Object.assign(driverCreds(), { signup_id: parseInt(btn.dataset.id, 10) }))
                    });
                    const data = await res.json();
                    if (!res.ok) { showError($('mError'), (data && data.message) || 'Could not remove that day.'); btn.disabled = false; return; }
                    renderMyDays(data.days || []);
                } catch (e) {
                    showError($('mError'), 'Something went wrong. Please try again.');
                    btn.disabled = false;
                }
            }));
        }

        $('mFindBtn').addEventListener('click', async () => {
            hideError($('mError'));
            $('mFindBtn').disabled = true;
            try {
                const res = await fetch(apiBase + '/driver-signup/my-days', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(driverCreds())
                });
                const data = await res.json();
                if (!res.ok) { showError($('mError'), (data && data.message) || 'We could not find you.'); return; }
                $('manageLogin').classList.add('hidden');
                renderMyDays(data.days || []);
            } catch (e) {
                showError($('mError'), 'Something went wrong. Please try again.');
            } finally {
                $('mFindBtn').disabled = false;
            }
        });
    </script>
</body>
</html>
        <?php
    }
}
