<?php
/**
 * SMS outbox queue + worker.
 *
 * Everything with state lives here; Subsales_Twilio_SMS stays a pure API
 * wrapper. Three jobs:
 *
 *   1. enqueue()              - write a row. Called from the order-created hook.
 *                               Never talks to the network.
 *   2. drain()                - the worker. Claims queued rows, sends them,
 *                               classifies failures. Driven by WP-Cron every
 *                               minute (host cron drives WP-Cron).
 *   3. render_receipt()       - turns an order into the message text, from the
 *                               admin-editable template. ONE code path, used by
 *                               both the settings-page preview and the real send.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_SMS_Queue {

    /**
     * Wall-clock budget for one drain() run, in seconds.
     *
     * Sized so a run always finishes well inside a one-minute cron tick and
     * nowhere near PHP's execution limit, so two runs can never overlap in
     * practice. Anything not sent stays queued for the next tick - there is no
     * such thing as "the last run of the day".
     */
    const RUN_SECONDS = 25;

    /** Hard ceiling on messages per run, whatever the configured rate says. */
    const MAX_PER_RUN = 300;

    /** Attempts before a retryable failure is given up on as failed. */
    const MAX_ATTEMPTS = 6;

    /**
     * Backoff per attempt number: 1m, 5m, 15m, 1h, 4h, 12h.
     *
     * The tail end is deliberately long. If we are being throttled by a limit
     * nobody told us about, the right symptom is "the last few hundred receipts
     * arrive tomorrow morning", so the retry window has to span a night.
     */
    const BACKOFF = array( 60, 300, 900, 3600, 14400, 43200 );

    /**
     * A claimed row whose run died mid-send is unstuck after this many seconds.
     * Comfortably longer than RUN_SECONDS plus one send timeout (15s).
     */
    const STUCK_SECONDS = 900;

    /**
     * Twilio error codes that can never succeed on a retry.
     *
     * Everything NOT listed here is treated as retryable - transport errors,
     * 5xx, 429, throughput and daily-limit rejections, and any code Twilio adds
     * that we have not seen yet. Defaulting to "retry" is deliberate: the worst
     * case for a wrongly-retried message is MAX_ATTEMPTS wasted API calls, the
     * worst case for a wrongly-discarded one is a customer who never gets their
     * receipt and nobody knowing.
     *
     *   21211 invalid 'To' number
     *   21214 'To' number cannot be reached
     *   21217 'To' number failed validation
     *   21219 'To' number unverified (trial account)
     *   21408 not permitted to send to this region
     *   21610 recipient has unsubscribed  <- also flips the contact to opted out
     *   21612 cannot route to this number
     *   21614 'To' number is not SMS-capable (landline)
     *   30003 destination handset unreachable
     *   30004 message blocked by the recipient/carrier
     *   30005 unknown destination handset
     *   30006 landline or unreachable carrier
     *   30007 carrier filtered as spam
     */
    const PERMANENT_CODES = array( 21211, 21214, 21217, 21219, 21408, 21610, 21612, 21614, 30003, 30004, 30005, 30006, 30007 );

    /** Twilio's "recipient has unsubscribed" - the only code that proves opt-out. */
    const OPT_OUT_CODE = 21610;

    const DEFAULT_TEMPLATE = "Thanks {customer}! {org} has your order: {items}. Total {total}. We'll be in touch about delivery. Reply STOP to opt out.";

    const DEFAULT_CONSENT_WORDING = "We'll text you a receipt and delivery updates. Reply STOP anytime.";

    /**
     * Register hooks.
     */
    public static function init() {
        // The drain needs one-minute granularity, which WP has no built-in
        // schedule for. Registered before wp_schedule_event() below, which
        // validates the schedule name against this filter's output.
        add_filter( 'cron_schedules', array( __CLASS__, 'add_cron_schedule' ) );

        if ( ! wp_next_scheduled( 'subsales_sms_drain' ) ) {
            wp_schedule_event( time(), 'subsales_minute', 'subsales_sms_drain' );
        }
        add_action( 'subsales_sms_drain', array( __CLASS__, 'drain' ) );

        // Housekeeping only - stacked on the existing hourly hook, the house
        // convention. The send drain is NOT stacked here; hourly receipts would
        // be worse than no receipts.
        add_action( 'subsales_log_cleanup', array( __CLASS__, 'cleanup' ) );

        // The receipt trigger. Fired once per successful order INSERT.
        add_action( 'subsales_order_created', array( __CLASS__, 'handle_order_created' ), 10, 3 );
    }

    /**
     * Add the one-minute schedule.
     *
     * Host cron is what will actually drive this. Keeping it a real WP-Cron
     * event means that if the host crontab is ever removed or the box is
     * rebuilt, site traffic still fires the drain - late, but never never.
     *
     * @param array $schedules
     * @return array
     */
    public static function add_cron_schedule( $schedules ) {
        $schedules['subsales_minute'] = array(
            'interval' => 60,
            'display'  => 'Every Minute (Subsales SMS)',
        );
        return $schedules;
    }

    /* ------------------------------------------------------------------ */
    /* Enqueue                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Insert one outbox row.
     *
     * Safe to call repeatedly for the same order. The UNIQUE KEY
     * (order_id, message_type) does the deduplication, so a second call for an
     * order that already has a receipt row is a no-op that reports success -
     * the PWA re-syncs the same order routinely and that is not an error.
     *
     * @param array $args phone, body, order_id, message_type, direction, status, skip_reason
     * @return bool True if a row exists for this order/type afterwards.
     */
    public static function enqueue( $args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_sms_messages';

        $row = array(
            'direction'    => isset( $args['direction'] ) ? $args['direction'] : 'out',
            'message_type' => isset( $args['message_type'] ) ? $args['message_type'] : 'receipt',
            'phone'        => isset( $args['phone'] ) ? $args['phone'] : '',
            'body'         => isset( $args['body'] ) ? $args['body'] : '',
            'order_id'     => isset( $args['order_id'] ) ? $args['order_id'] : null,
            'status'       => isset( $args['status'] ) ? $args['status'] : 'queued',
            'skip_reason'  => isset( $args['skip_reason'] ) ? $args['skip_reason'] : null,
            'created_at'   => current_time( 'mysql', true ),
        );

        // The duplicate-key collision is an expected outcome here, not a fault,
        // so it must not surface as a visible wpdb error on an admin screen or
        // in the REST response the PWA is waiting on.
        $suppressed = $wpdb->suppress_errors( true );
        $result     = $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        $error      = $wpdb->last_error;
        $wpdb->suppress_errors( $suppressed );

        if ( false !== $result ) {
            return true;
        }

        if ( false !== stripos( $error, 'duplicate' ) ) {
            return true; // Already queued/sent for this order. Nothing to do.
        }

        subsales_log( 'ERROR', 'sms', 'Failed to queue SMS', array(
            'order_id' => $row['order_id'],
            'type'     => $row['message_type'],
            'db_error' => $error,
        ) );

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* The receipt trigger (Phase 4)                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Handler for `subsales_order_created`.
     *
     * ENQUEUES ONLY. No network call, no Twilio, nothing that can be slow or
     * fail in a way the order-sync response would have to wait on. The order is
     * already durably inserted by the time this runs, and the 201 goes out
     * immediately after it returns.
     *
     * Every outcome writes a row - a receipt that was never going to be sent is
     * recorded as `skipped` with a reason, so "why didn't this customer get a
     * text" is answerable from the messages table instead of from guesswork.
     *
     * @param string $order_id Client-generated order id.
     * @param int    $db_id    Row id in ss_orders.
     * @param array  $data     The order payload as submitted.
     */
    public static function handle_order_created( $order_id, $db_id, $data ) {
        if ( ! is_array( $data ) ) {
            return;
        }

        $raw_phone = '';
        foreach ( array( 'cellNumber', 'cell', 'phone' ) as $key ) {
            if ( ! empty( $data[ $key ] ) ) {
                $raw_phone = $data[ $key ];
                break;
            }
        }

        $phone = Subsales_Database::normalize_phone( $raw_phone );

        if ( 10 !== strlen( $phone ) ) {
            self::enqueue( array(
                'order_id'    => $order_id,
                'phone'       => $phone,
                'body'        => '',
                'status'      => 'skipped',
                'skip_reason' => 'no_phone',
            ) );
            return;
        }

        // A donation with nobody to text: someone hands a child $10 at the door
        // and closes it. There is no customer to send a receipt to, so this is
        // recorded as its own reason rather than looking like a data problem.
        if ( ! empty( $data['donationOnly'] ) ) {
            self::enqueue( array(
                'order_id'    => $order_id,
                'phone'       => $phone,
                'body'        => '',
                'status'      => 'skipped',
                'skip_reason' => 'donation_only',
            ) );
            return;
        }

        // Separately: a real sale to a named customer who would not give a
        // number. Sellers type all-zeros for this, and a mis-key can produce
        // the same shape. Texting it would fail permanently at Twilio and leave
        // a junk contact behind. Kept distinct from donation_only on purpose -
        // last season 549 orders had a placeholder number but only ~361 were
        // donations, so roughly 190 were customers who simply declined.
        if ( preg_match( '/^(\d)\1{9}$/', $phone ) ) {
            self::enqueue( array(
                'order_id'    => $order_id,
                'phone'       => $phone,
                'body'        => '',
                'status'      => 'skipped',
                'skip_reason' => 'no_phone_given',
            ) );
            return;
        }

        // Consent is recorded whether or not sending is switched on - the
        // customer was shown the wording and gave the number either way, and
        // that record is what an A2P registration or an attorney asks about.
        $opted_out = self::upsert_contact( $phone );

        $body = self::render_receipt( $data );

        if ( $opted_out ) {
            self::enqueue( array(
                'order_id'    => $order_id,
                'phone'       => $phone,
                'body'        => $body,
                'status'      => 'skipped',
                'skip_reason' => 'opted_out',
            ) );
            return;
        }

        if ( ! get_option( 'subsales_sms_enabled', 0 ) ) {
            self::enqueue( array(
                'order_id'    => $order_id,
                'phone'       => $phone,
                'body'        => $body,
                'status'      => 'skipped',
                'skip_reason' => 'sms_disabled',
            ) );
            return;
        }

        self::enqueue( array(
            'order_id' => $order_id,
            'phone'    => $phone,
            'body'     => $body,
            'status'   => 'queued',
        ) );
    }

    /**
     * Create or refresh the contact row for a phone number.
     *
     * An opt-out is never downgraded: if opted_out_at is set, the consent
     * columns are left exactly as they are and the row keeps its opt-out. A
     * customer who texted STOP and then ordered again from a different child
     * still does not get a text.
     *
     * @param string $phone Normalized 10-digit phone.
     * @return bool True if this contact is opted out.
     */
    private static function upsert_contact( $phone ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_sms_contacts';

        $wording = (string) get_option( 'subsales_sms_consent_wording', self::DEFAULT_CONSENT_WORDING );
        $now     = current_time( 'mysql', true );

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$table} (phone, consent_transactional, consent_source, consent_wording, consent_at, created_at)
             VALUES (%s, 1, 'order_entry', %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                consent_transactional = IF(opted_out_at IS NULL, 1, consent_transactional),
                consent_source        = IF(opted_out_at IS NULL, 'order_entry', consent_source),
                consent_wording       = IF(opted_out_at IS NULL, VALUES(consent_wording), consent_wording),
                consent_at            = IF(opted_out_at IS NULL, VALUES(consent_at), consent_at)",
            $phone,
            $wording,
            $now,
            $now
        ) );

        $opted_out = $wpdb->get_var( $wpdb->prepare(
            "SELECT opted_out_at FROM {$table} WHERE phone = %s",
            $phone
        ) );

        return ! empty( $opted_out );
    }

    /* ------------------------------------------------------------------ */
    /* The template                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Render the receipt text for an order.
     *
     * The single code path: the settings-page preview and the real send both
     * call this, so what the admin sees is exactly what the customer gets,
     * compliance additions included.
     *
     * @param array       $order_data The order payload (products, customer, donationAmount...).
     * @param string|null $template   Override the saved template - used by the live
     *                                preview so an unsaved edit can be previewed.
     * @return string
     */
    public static function render_receipt( $order_data, $template = null ) {
        if ( null === $template ) {
            $template = (string) get_option( 'subsales_sms_receipt_template', self::DEFAULT_TEMPLATE );
        }
        if ( '' === trim( $template ) ) {
            $template = self::DEFAULT_TEMPLATE;
        }

        $order_data = is_array( $order_data ) ? $order_data : array();

        $names = array();
        if ( function_exists( 'order_sync_get_products_config' ) ) {
            foreach ( (array) order_sync_get_products_config() as $p ) {
                if ( isset( $p['id'] ) ) {
                    $names[ (string) $p['id'] ] = isset( $p['name'] ) ? $p['name'] : (string) $p['id'];
                }
            }
        }

        // Same field fallbacks the tally uses (class-database.php) - the payload
        // shape has drifted over versions and both spellings are in live data.
        $parts = array();
        $total = 0.0;
        $products = ( isset( $order_data['products'] ) && is_array( $order_data['products'] ) ) ? $order_data['products'] : array();
        foreach ( $products as $item ) {
            $id    = isset( $item['id'] ) ? (string) $item['id'] : ( isset( $item['product_id'] ) ? (string) $item['product_id'] : '' );
            $qty   = intval( isset( $item['qty'] ) ? $item['qty'] : ( isset( $item['quantity'] ) ? $item['quantity'] : 0 ) );
            $price = floatval( isset( $item['price'] ) ? $item['price'] : ( isset( $item['unit_price'] ) ? $item['unit_price'] : 0 ) );
            if ( $qty <= 0 ) {
                continue;
            }
            $total  += $qty * $price;
            $label   = isset( $names[ $id ] ) ? $names[ $id ] : ( isset( $item['name'] ) ? $item['name'] : $id );
            $parts[] = $qty . ' ' . $label;
        }

        $donation = floatval( isset( $order_data['donationAmount'] ) ? $order_data['donationAmount'] : ( isset( $order_data['donation'] ) ? $order_data['donation'] : 0 ) );
        if ( $donation > 0 ) {
            $total  += $donation;
            $parts[] = '$' . number_format( $donation, 2 ) . ' donation';
        }

        $customer = Subsales_Order_Helper::get_customer_name( array( 'order_data' => $order_data ), 'there' );

        // No number set: drop the whole sentence that would have carried it,
        // rather than substituting an empty string and sending "Questions? Call ."
        // to a customer. Sentence-level so a template can phrase the invitation
        // however it likes as long as it keeps it to its own sentence.
        $admin_phone = Subsales_Season_Setup::format_phone( get_option( 'subsales_admin_contact_phone', '' ) );
        if ( '' === $admin_phone && false !== strpos( $template, '{adminphone}' ) ) {
            $kept = array();
            foreach ( preg_split( '/(?<=[.!?])\s+/', $template ) as $sentence ) {
                if ( false === strpos( $sentence, '{adminphone}' ) ) {
                    $kept[] = $sentence;
                }
            }
            $template = trim( implode( ' ', $kept ) );
        }

        $body = strtr( $template, array(
            '{customer}' => $customer,
            '{items}'    => $parts ? implode( ', ', $parts ) : 'your order',
            '{total}'    => '$' . number_format( $total, 2 ),
            '{org}'      => (string) get_option( 'subsales_branding', 'Subsales' ),
            // Set per season in the setup wizard (step 7).
            '{adminphone}' => Subsales_Season_Setup::format_phone( get_option( 'subsales_admin_contact_phone', '' ) ),
        ) );

        return self::apply_compliance( $body );
    }

    /**
     * Make sure the message identifies the sender and says how to stop.
     *
     * Appended rather than enforced by validation, because the failure mode of
     * rejecting the admin's template is a fundraiser with no receipts, and the
     * failure mode of sending an unbranded message with no opt-out wording is a
     * carrier complaint. The settings page says plainly that this happens - it
     * is never a silent rewrite.
     *
     * @param string $body
     * @return string
     */
    private static function apply_compliance( $body ) {
        $body = trim( $body );
        $org  = trim( (string) get_option( 'subsales_branding', 'Subsales' ) );

        if ( '' !== $org && false === stripos( $body, $org ) ) {
            $body = $org . ': ' . $body;
        }

        if ( false === stripos( $body, 'STOP' ) ) {
            $body = rtrim( $body ) . ' Reply STOP to opt out.';
        }

        return $body;
    }

    /**
     * Character count, encoding and segment count for a message body.
     *
     * Carriers bill and throttle per SEGMENT, not per message, so a wordy
     * template halves throughput and doubles cost. GSM-7 fits 160 in one
     * segment and 153 each once it splits; a single non-GSM character (a curly
     * apostrophe pasted from Word, an emoji) forces UCS-2 and drops that to
     * 70/67. The seven GSM "extended" characters take two slots each.
     *
     * @param string $body
     * @return array encoding, chars, segments
     */
    public static function segments( $body ) {
        $gsm = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
        $ext = array( '^', '{', '}', '\\', '[', ']', '~', '|', '€' );

        $chars   = preg_split( '//u', $body, -1, PREG_SPLIT_NO_EMPTY );
        $chars   = is_array( $chars ) ? $chars : array();
        $is_gsm  = true;
        $units   = 0;

        foreach ( $chars as $char ) {
            if ( in_array( $char, $ext, true ) ) {
                $units += 2;
                continue;
            }
            if ( false === mb_strpos( $gsm, $char ) ) {
                $is_gsm = false;
                break;
            }
            $units++;
        }

        if ( ! $is_gsm ) {
            $units = count( $chars );
            $single = 70;
            $multi  = 67;
        } else {
            $single = 160;
            $multi  = 153;
        }

        $segments = ( $units <= $single ) ? 1 : (int) ceil( $units / $multi );

        return array(
            'encoding' => $is_gsm ? 'GSM-7' : 'Unicode',
            'chars'    => count( $chars ),
            'units'    => $units,
            'segments' => max( 1, $segments ),
        );
    }

    /* ------------------------------------------------------------------ */
    /* The worker                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Send whatever is due, within the run's budget.
     */
    public static function drain() {
        global $wpdb;
        $table   = $wpdb->prefix . 'ss_sms_messages';
        $started = microtime( true );
        $counts  = array( 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'retry' => 0 );

        // Checked before get_settings() so a site with sending switched off
        // does not write an ERROR log row every single minute forever.
        if ( ! get_option( 'subsales_sms_enabled', 0 ) ) {
            self::record_drain( 'off', $counts );
            return;
        }

        if ( ! get_option( 'subsales_twilio_account_sid', '' ) || ! get_option( 'subsales_twilio_auth_token', '' ) ) {
            self::record_drain( 'not_configured', $counts );
            return;
        }

        // A Messaging Service is a valid sender on its own - under A2P 10DLC it
        // is the normal setup, and the numbers box is then left empty. Requiring
        // from_numbers here meant the worker reported 'not_configured' and sent
        // nothing, forever, with no error to explain it.
        $settings = Subsales_Twilio_SMS::get_settings();
        if ( ! $settings || ( empty( $settings['from_numbers'] ) && empty( $settings['messaging_service_sid'] ) ) ) {
            self::record_drain( 'not_configured', $counts );
            return;
        }

        // Daily cap, counted in the site's own timezone so "today" means what
        // the admin means by it. Cap reached = stop; the rest stay queued and
        // go out on the first run after midnight.
        $budget = min( self::MAX_PER_RUN, max( 1, (int) ceil( $settings['rate_per_second'] * self::RUN_SECONDS ) ) );

        if ( $settings['daily_cap'] > 0 ) {
            $day_start  = get_gmt_from_date( current_time( 'Y-m-d' ) . ' 00:00:00' );
            // ponytail: counts messages, not segments. A multi-segment receipt
            // uses more of the carrier's real allowance than this cap knows
            // about - set the cap below the true limit, or store a segment
            // count per row if that ever gets tight.
            $sent_today = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE direction = 'out' AND sent_at >= %s",
                $day_start
            ) );

            if ( $sent_today >= $settings['daily_cap'] ) {
                self::record_drain( 'daily_cap_reached', $counts );
                return;
            }

            $budget = min( $budget, $settings['daily_cap'] - $sent_today );
        }

        $now  = current_time( 'mysql', true );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, phone, body, order_id, attempts FROM {$table}
             WHERE direction = 'out' AND status = 'queued'
               AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
             ORDER BY id ASC
             LIMIT %d",
            $now,
            $budget
        ), ARRAY_A );

        $sent_in_run = 0;

        foreach ( (array) $rows as $row ) {
            if ( microtime( true ) - $started >= self::RUN_SECONDS ) {
                break;
            }

            // Pace to the configured per-second rate by holding the Nth send
            // until N/rate seconds into the run. No tight loop, and the run
            // still cannot outlive RUN_SECONDS because of the check above.
            $due  = $started + ( $sent_in_run / $settings['rate_per_second'] );
            $wait = $due - microtime( true );
            if ( $wait > 0 ) {
                usleep( (int) round( $wait * 1000000 ) );
            }

            $outcome = self::process_row( $row, $settings );
            if ( isset( $counts[ $outcome ] ) ) {
                $counts[ $outcome ]++;
            }
            if ( 'sent' === $outcome || 'failed' === $outcome || 'retry' === $outcome ) {
                $sent_in_run++; // A rejected send still consumed an API call.
            }
        }

        self::record_drain( 'ok', $counts );
    }

    /**
     * Claim one row and send it.
     *
     * @param array $row
     * @param array $settings
     * @return string sent|failed|retry|skipped|none
     */
    private static function process_row( $row, $settings ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'ss_sms_messages';
        $contacts = $wpdb->prefix . 'ss_sms_contacts';
        $now      = current_time( 'mysql', true );
        $id       = intval( $row['id'] );

        // THE CLAIM. Conditional on the row still being 'queued', so if two
        // runs ever do overlap, exactly one of them gets the row and the other
        // sees 0 affected and moves on. attempts is incremented here rather
        // than after the send, so a run that dies mid-send burns an attempt
        // instead of leaving a row that can be retried forever.
        $claimed = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'sending', attempts = attempts + 1, updated_at = %s
             WHERE id = %d AND status = 'queued'",
            $now,
            $id
        ) );

        if ( 1 !== intval( $claimed ) ) {
            return 'none';
        }

        $attempts = intval( $row['attempts'] ) + 1;
        $contact  = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, opted_out_at, assigned_number FROM {$contacts} WHERE phone = %s",
            $row['phone']
        ), ARRAY_A );

        // Re-checked here, immediately before the send, and not just at enqueue
        // time - a STOP may have arrived in between.
        if ( $contact && ! empty( $contact['opted_out_at'] ) ) {
            $wpdb->update(
                $table,
                array( 'status' => 'skipped', 'skip_reason' => 'opted_out', 'updated_at' => $now ),
                array( 'id' => $id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
            return 'skipped';
        }

        $from = self::sender_for( $row['phone'], $contact, $settings['from_numbers'] );

        $result = Subsales_Twilio_SMS::send( $row['phone'], $row['body'], $from );

        if ( is_string( $result ) ) {
            $wpdb->update(
                $table,
                array( 'status' => 'sent', 'twilio_sid' => $result, 'sent_at' => $now, 'last_error' => null, 'updated_at' => $now ),
                array( 'id' => $id ),
                array( '%s', '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$contacts} SET last_message_at = %s WHERE phone = %s",
                $now,
                $row['phone']
            ) );

            return 'sent';
        }

        return self::record_failure( $id, $row, $result, $attempts );
    }

    /**
     * Decide what a failure means and write it down.
     *
     * @param int   $id
     * @param array $row
     * @param array $error    ['error_code','http_status','message'] from the wrapper.
     * @param int   $attempts Attempts including this one.
     * @return string failed|retry
     */
    private static function record_failure( $id, $row, $error, $attempts ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'ss_sms_messages';
        $contacts = $wpdb->prefix . 'ss_sms_contacts';
        $now      = current_time( 'mysql', true );

        $code    = isset( $error['error_code'] ) ? intval( $error['error_code'] ) : 0;
        $message = isset( $error['message'] ) ? (string) $error['message'] : 'Unknown error';
        $note    = $code ? ( '[' . $code . '] ' . $message ) : $message;

        // The recipient told a carrier to stop. Record it on the contact, not
        // just on this message - it applies to every future send to that number.
        if ( self::OPT_OUT_CODE === $code ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$contacts} SET opted_out_at = %s WHERE phone = %s AND opted_out_at IS NULL",
                $now,
                $row['phone']
            ) );
            subsales_log( 'INFO', 'sms', 'Contact marked opted out after Twilio 21610', array( 'phone' => $row['phone'] ) );
        }

        $permanent = in_array( $code, self::PERMANENT_CODES, true );

        if ( $permanent || $attempts >= self::MAX_ATTEMPTS ) {
            $wpdb->update(
                $table,
                array( 'status' => 'failed', 'last_error' => $note, 'updated_at' => $now ),
                array( 'id' => $id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            subsales_log( 'ERROR', 'sms', $permanent ? 'SMS permanently failed' : 'SMS gave up after max attempts', array(
                'order_id' => $row['order_id'],
                'attempts' => $attempts,
                'error'    => $note,
            ) );

            return 'failed';
        }

        $delay = isset( self::BACKOFF[ $attempts - 1 ] ) ? self::BACKOFF[ $attempts - 1 ] : self::BACKOFF[ count( self::BACKOFF ) - 1 ];

        $wpdb->update(
            $table,
            array(
                'status'          => 'queued',
                'last_error'      => $note,
                'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
                'updated_at'      => $now,
            ),
            array( 'id' => $id ),
            array( '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );

        return 'retry';
    }

    /**
     * Pick the sending number for a contact, and pin it.
     *
     * A returning customer must always see the same number, including next
     * season - which is well past anything Twilio's Sticky Sender remembers, so
     * the choice is stored on the contact. The fallback pick is derived from the
     * phone number rather than random, so it is stable even for a message with
     * no contact row.
     *
     * @param string     $phone
     * @param array|null $contact
     * @param array      $from_numbers
     * @return string
     */
    private static function sender_for( $phone, $contact, $from_numbers ) {
        // No pool: a Messaging Service is in play and Twilio picks the sender
        // (Sticky Sender keeps a returning customer on the same number). Returning
        // null makes send() fall through to MessagingServiceSid. Without this the
        // modulo below is a division by zero.
        if ( empty( $from_numbers ) ) {
            return null;
        }

        if ( $contact && ! empty( $contact['assigned_number'] ) && in_array( $contact['assigned_number'], $from_numbers, true ) ) {
            return $contact['assigned_number'];
        }

        $from = $from_numbers[ abs( crc32( $phone ) ) % count( $from_numbers ) ];

        if ( $contact ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'ss_sms_contacts',
                array( 'assigned_number' => $from ),
                array( 'id' => intval( $contact['id'] ) ),
                array( '%s' ),
                array( '%d' )
            );
        }

        return $from;
    }

    /**
     * Stamp the outcome of every run, however boring.
     *
     * The whole feature depends on cron firing. Without this the admin has no
     * way to tell "nothing to send" from "the worker has not run since Tuesday"
     * short of reading logs, and both look identical from the outside.
     *
     * @param string $status
     * @param array  $counts
     */
    private static function record_drain( $status, $counts ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_sms_messages';

        $queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE direction = 'out' AND status = 'queued'" );

        update_option( 'subsales_sms_last_drain', array_merge(
            array( 'at' => time(), 'status' => $status, 'queued' => $queued ),
            $counts
        ), false );
    }

    /* ------------------------------------------------------------------ */
    /* Housekeeping (hourly)                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Hourly maintenance: unstick abandoned claims, drop ancient rows.
     */
    public static function cleanup() {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_sms_messages';

        // A row left 'sending' means the run that claimed it died. Its attempt
        // was already counted, so putting it back in the queue cannot loop.
        $stuck = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'queued', last_error = 'Interrupted mid-send, requeued'
             WHERE status = 'sending' AND updated_at < %s",
            gmdate( 'Y-m-d H:i:s', time() - self::STUCK_SECONDS )
        ) );

        if ( $stuck ) {
            subsales_log( 'WARNING', 'sms', 'Requeued interrupted SMS sends', array( 'count' => intval( $stuck ) ) );
        }

        // Finished rows older than a year. A season is the unit of memory here;
        // last season's receipts are of no use and the table is the log too.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
             WHERE status IN ('sent','delivered','failed','skipped')
               AND created_at < %s",
            gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS )
        ) );
    }
}
