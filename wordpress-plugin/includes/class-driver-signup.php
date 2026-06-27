<?php
/**
 * Driver (parent) self-registration for Subsales Management Plugin
 *
 * Parents/drivers are not in the child database, so they need their own
 * registration path. This flow lets a parent:
 *  - Identify their child (name + phone) to find which team and dates the
 *    child signed up for
 *  - Register themselves as the driver linked to that team for those dates
 *
 * Fully API-driven, mirroring the patterns in Subsales_Signups. The existing
 * /auth/login endpoint handles driver login unchanged (name + phone, user mode).
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

        // Register the parent as a driver for their child's team(s)/date(s)
        register_rest_route( 'order-manager/v1', '/driver-signup', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'rest_driver_signup' ),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * Look up a child by name + phone and return their active signups.
     *
     * Shared helper used by both REST endpoints.
     *
     * @param string $child_name  Child's name (optional; when provided, must match)
     * @param string $child_phone Child's phone (10 digits, already validated by caller)
     * @return array|WP_Error { child, signups } or WP_Error on failure
     */
    private static function find_child_signups( $child_name, $child_phone ) {
        global $wpdb;

        $members_table   = $wpdb->prefix . 'ss_team_members';
        $signups_table   = $wpdb->prefix . 'ss_signups';
        $teams_table     = $wpdb->prefix . 'ss_teams';
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';

        // Find child by phone (mirrors class-teams.php:127)
        $child = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$members_table} WHERE phone = %s",
            $child_phone
        ), ARRAY_A );

        if ( ! $child ) {
            return new WP_Error(
                'child_not_found',
                "We couldn't find a child with that phone number. Have them complete their signup first.",
                array( 'status' => 404 )
            );
        }

        // Name match: case-insensitive partial match (mirrors class-teams.php:161)
        if ( ! empty( $child_name ) ) {
            if ( stripos( $child['name'], $child_name ) === false
                && stripos( $child_name, $child['name'] ) === false ) {
                return new WP_Error(
                    'name_mismatch',
                    'The name does not match the phone number on file.',
                    array( 'status' => 401 )
                );
            }
        }

        // Child's active signups joined to teams + campaigns
        $signups = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                s.team_id,
                s.campaign_id,
                t.name AS team_name,
                c.campaign_name,
                c.campaign_date
            FROM {$signups_table} s
            INNER JOIN {$teams_table} t ON s.team_id = t.id
            INNER JOIN {$campaigns_table} c ON s.campaign_id = c.id
            WHERE s.user_id = %d AND s.status = 'active'
            ORDER BY c.campaign_date ASC, t.name ASC",
            $child['id']
        ), ARRAY_A );

        if ( empty( $signups ) ) {
            return new WP_Error(
                'no_signups',
                "Your child hasn't signed up yet, have them complete their signup first.",
                array( 'status' => 404 )
            );
        }

        return array(
            'child'   => $child,
            'signups' => $signups,
        );
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

        $result = self::find_child_signups( $child_name, $child_phone );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( array(
            'success'    => true,
            'child_id'   => intval( $result['child']['id'] ),
            'child_name' => $result['child']['name'],
            'signups'    => $result['signups'],
        ) );
    }

    /**
     * POST /driver-signup
     * Register the parent as a driver for each team/date their child signed up for.
     */
    public static function rest_driver_signup( $request ) {
        global $wpdb;

        $body         = $request->get_json_params();
        $child_phone  = isset( $body['child_phone'] ) ? preg_replace( '/\D/', '', $body['child_phone'] ) : '';
        $driver_name  = isset( $body['driver_name'] ) ? sanitize_text_field( $body['driver_name'] ) : '';
        $driver_phone = isset( $body['driver_phone'] ) ? preg_replace( '/\D/', '', $body['driver_phone'] ) : '';

        if ( empty( $child_phone ) || empty( $driver_name ) || empty( $driver_phone ) ) {
            return new WP_Error( 'missing_params', 'Child phone, driver name, and driver phone are required.', array( 'status' => 400 ) );
        }

        if ( ! preg_match( '/^[0-9]{10}$/', $child_phone ) ) {
            return new WP_Error( 'invalid_phone', 'Child phone number must be 10 digits.', array( 'status' => 400 ) );
        }

        if ( ! preg_match( '/^[0-9]{10}$/', $driver_phone ) ) {
            return new WP_Error( 'invalid_phone', 'Driver phone number must be 10 digits.', array( 'status' => 400 ) );
        }

        // Resolve the child's active signups (no name check here — phone already verified in step 1)
        $result = self::find_child_signups( '', $child_phone );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $signups = $result['signups'];

        $members_table    = $wpdb->prefix . 'ss_team_members';
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $signups_table    = $wpdb->prefix . 'ss_signups';

        // Get-or-create the driver in ss_team_members with role 'driver'.
        // Note: if driver phone == child phone, the existing member is found and
        // simply promoted to role 'driver' (allowed silently).
        $driver = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$members_table} WHERE phone = %s",
            $driver_phone
        ), ARRAY_A );

        if ( $driver ) {
            $driver_id = intval( $driver['id'] );
            $wpdb->update(
                $members_table,
                array( 'name' => $driver_name, 'role' => 'driver' ),
                array( 'id' => $driver_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $members_table,
                array(
                    'name'   => $driver_name,
                    'phone'  => $driver_phone,
                    'role'   => 'driver',
                    'status' => 'active',
                ),
                array( '%s', '%s', '%s', '%s' )
            );
            $driver_id = intval( $wpdb->insert_id );
        }

        if ( empty( $driver_id ) ) {
            subsales_log( 'ERROR', 'driver-signup', 'Failed to create/find driver', array(
                'driver_phone' => substr( $driver_phone, 0, 3 ) . 'XXXXXXX',
                'error'        => $wpdb->last_error,
            ) );
            return new WP_Error( 'driver_failed', 'Could not register the driver. Please try again.', array( 'status' => 500 ) );
        }

        // Link the driver to every unique team the child is on (check-then-insert)
        $team_ids = array_unique( array_map( 'intval', wp_list_pluck( $signups, 'team_id' ) ) );
        foreach ( $team_ids as $team_id ) {
            $link_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
                $driver_id, $team_id
            ) );
            if ( ! $link_exists ) {
                $wpdb->insert( $user_teams_table, array(
                    'user_id' => $driver_id,
                    'team_id' => $team_id,
                ), array( '%d', '%d' ) );
            }
        }

        // For each team/date, register the driver's signup row, flag them as the
        // driver, and record the driver name on the team_campaigns record.
        $processed = array();
        foreach ( $signups as $signup ) {
            $team_id     = intval( $signup['team_id'] );
            $campaign_id = intval( $signup['campaign_id'] );

            // 1. Ensure the driver has a signup row for this team/date.
            //    Must exist before set_driver(), which issues an UPDATE on it.
            $row_exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$signups_table}
                 WHERE user_id = %d AND team_id = %d AND campaign_id = %d",
                $driver_id, $team_id, $campaign_id
            ) );

            if ( ! $row_exists ) {
                $wpdb->insert( $signups_table, array(
                    'user_id'     => $driver_id,
                    'team_id'     => $team_id,
                    'campaign_id' => $campaign_id,
                    'is_driver'   => 1,
                    'status'      => 'active',
                    'created_at'  => current_time( 'mysql' ),
                ), array( '%d', '%d', '%d', '%d', '%s', '%s' ) );
            } else {
                // Reactivate if a cancelled row exists
                $wpdb->update(
                    $signups_table,
                    array( 'status' => 'active' ),
                    array( 'user_id' => $driver_id, 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
                    array( '%s' ),
                    array( '%d', '%d', '%d' )
                );
            }

            // 2. Set as the sole driver for this team/date (transactional)
            Subsales_Database::set_driver( $driver_id, $team_id, $campaign_id );

            // 3. Record driver name on the team_campaigns record (mirrors class-signups.php:492)
            self::upsert_team_campaign_driver( $team_id, $campaign_id, $driver_name );

            $processed[] = array(
                'team_id'     => $team_id,
                'campaign_id' => $campaign_id,
            );
        }

        subsales_log( 'INFO', 'driver-signup', 'Driver self-registered', array(
            'driver_id'   => $driver_id,
            'driver_name' => $driver_name,
            'team_ids'    => $team_ids,
            'processed'   => count( $processed ),
        ) );

        return rest_ensure_response( array(
            'success'   => true,
            'driver_id' => $driver_id,
            'processed' => $processed,
            'message'   => 'You are registered as the driver. Thank you!',
        ) );
    }

    /**
     * Upsert the driver_name on ss_team_campaigns (mirrors class-signups.php:492)
     */
    private static function upsert_team_campaign_driver( $team_id, $campaign_id, $driver_name ) {
        global $wpdb;
        $team_campaigns_table = $wpdb->prefix . 'ss_team_campaigns';

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$team_campaigns_table} WHERE team_id = %d AND campaign_id = %d",
            $team_id, $campaign_id
        ) );

        $fields = array(
            'driver_name'       => $driver_name,
            'driver_updated_by' => 'Driver self-signup',
            'driver_updated_at' => current_time( 'mysql' ),
        );

        if ( $exists ) {
            $wpdb->update(
                $team_campaigns_table,
                $fields,
                array( 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
                array( '%s', '%s', '%s' ),
                array( '%d', '%d' )
            );
        } else {
            $wpdb->insert(
                $team_campaigns_table,
                array_merge( array( 'team_id' => $team_id, 'campaign_id' => $campaign_id ), $fields ),
                array( '%d', '%d', '%s', '%s', '%s' )
            );
        }
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
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #555; }
        input[type="text"], input[type="tel"] { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
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
                <input type="text" id="childName" placeholder="Child's full name" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="childPhone">Your Child's Phone Number</label>
                <input type="tel" id="childPhone" placeholder="(555) 555-5555" autocomplete="off">
            </div>
            <button class="btn" id="lookupBtn">Find My Child's Team</button>
            <div class="error hidden" id="step1Error"></div>
        </div>

        <!-- Step 2: Driver details -->
        <div class="card hidden" id="step2">
            <div class="child-summary" id="childSummary"></div>
            <div class="form-group">
                <label for="driverName">Your Name (Driver)</label>
                <input type="text" id="driverName" placeholder="Your full name" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="driverPhone">Your Phone Number</label>
                <input type="tel" id="driverPhone" placeholder="(555) 555-5555" autocomplete="off">
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

        function renderSignups(signups) {
            return signups.map(s => {
                const d = s.campaign_date || '';
                const label = s.campaign_name ? (s.campaign_name + ' — ' + d) : d;
                return '<div class="signup-row"><span class="team">' + escapeHtml(s.team_name) +
                    '</span> <span class="date">' + escapeHtml(label) + '</span></div>';
            }).join('');
        }

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str == null ? '' : String(str);
            return div.innerHTML;
        }

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
                $('childSummary').innerHTML =
                    '<h3>' + escapeHtml(data.child_name) + '</h3>' + renderSignups(data.signups);
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

            if (!driver_name || !driver_phone) {
                showError($('step2Error'), 'Please enter your name and phone number.');
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
                        driver_phone
                    })
                });
                const data = await res.json();

                if (!res.ok) {
                    showError($('step2Error'), (data && data.message) || 'Registration failed.');
                    return;
                }

                $('confirmationMsg').textContent = data.message || 'You are registered as the driver.';
                $('confirmationSummary').innerHTML =
                    '<h3>' + escapeHtml(childData.child_name) + '</h3>' + renderSignups(childData.signups);
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
    </script>
</body>
</html>
        <?php
    }
}
