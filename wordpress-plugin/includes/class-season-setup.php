<?php
/**
 * Season Setup wizard - server side.
 *
 * Backs admin/partials/season-setup.php: renders each wizard step on demand
 * (so nothing expensive runs when the Settings page merely loads) and performs
 * each step's action.
 *
 * @package Subsales_Management
 * @since 3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Subsales_Season_Setup' ) ) :

class Subsales_Season_Setup {

    /** Nonce action used by every wizard AJAX call. */
    const NONCE = 'subsales_season_setup';

    /** Option holding { time, user } of the last completed step. */
    const LAST_RUN_OPTION = 'subsales_season_setup_last_run';

    const TOTAL_STEPS = 7;

    public static function init() {
        add_action( 'wp_ajax_subsales_season_setup_step', array( __CLASS__, 'ajax_step' ) );
        add_action( 'wp_ajax_subsales_season_setup_save', array( __CLASS__, 'ajax_save' ) );
    }

    public static function step_labels() {
        return array(
            1 => 'Start the season',
            2 => 'Sales days',
            3 => 'Roster',
            4 => 'Pricing',
            5 => 'Sales mode',
            6 => 'Addresses',
            7 => 'Open sales',
        );
    }

    /* ---------------------------------------------------------------- state */

    /**
     * Everything the wizard reports as "live status". One call, so a step can
     * show the numbers that matter without each step re-querying.
     *
     * @return array
     */
    public static function status() {
        global $wpdb;

        $season_id = intval( get_option( 'subsales_current_season_id' ) );
        $season    = null;
        foreach ( Subsales_Database::get_seasons() as $row ) {
            if ( intval( $row['id'] ) === $season_id ) {
                $season = $row;
                break;
            }
        }

        $counts = $season_id
            ? Subsales_Database::get_season_counts( $season_id )
            : array( 'teams' => 0, 'campaigns' => 0, 'members' => 0 );

        $products = (array) order_sync_get_products_config();
        $visible  = 0;
        foreach ( $products as $p ) {
            if ( ! empty( $p['visible'] ) ) {
                $visible++;
            }
        }

        $addresses_table = $wpdb->prefix . 'ss_addresses';
        $address_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$addresses_table}" );

        $last_generated = '';
        if ( function_exists( 'subsales_get_generation_logs' ) ) {
            $logs = subsales_get_generation_logs( 1 );
            if ( ! empty( $logs[0]['timestamp'] ) ) {
                $last_generated = $logs[0]['timestamp'];
            }
        }

        return array(
            'season_id'        => $season_id,
            'season_label'     => $season ? $season['label'] : '',
            'season_created'   => $season && ! empty( $season['created_at'] ) ? $season['created_at'] : '',
            'sales_days'       => intval( $counts['campaigns'] ),
            'teams'            => intval( $counts['teams'] ),
            'members'          => intval( $counts['members'] ),
            'products_total'   => count( $products ),
            'products_visible' => $visible,
            'sales_mode'       => get_option( 'subsales_sales_mode', 'legacy' ),
            'addresses'        => $address_count,
            'last_generated'   => $last_generated,
            'sales_enabled'    => intval( get_option( 'subsales_sales_enabled', 1 ) ),
        );
    }

    /**
     * "Last run: <date> by <user>", or '' when the wizard has never been used.
     */
    public static function last_run_text() {
        $last = get_option( self::LAST_RUN_OPTION, array() );
        if ( empty( $last['time'] ) ) {
            return '';
        }
        $when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $last['time'] ) );
        $who = isset( $last['user'] ) ? $last['user'] : '';
        return $who ? sprintf( '%s by %s', $when, $who ) : $when;
    }

    private static function touch_last_run() {
        $user = wp_get_current_user();
        update_option( self::LAST_RUN_OPTION, array(
            'time' => time(),
            'user' => $user && $user->display_name ? $user->display_name : 'someone',
        ) );
    }

    /* -------------------------------------------------------------- actions */

    /**
     * Save the product/pricing rows out of a $_POST-shaped array.
     *
     * Shared by admin/partials/products-editor.php (plain Settings POST) and by
     * the wizard's AJAX save, so the parsing rules live in exactly one place.
     *
     * @param array $post
     * @return int Number of products stored.
     */
    public static function save_products( $post ) {
        if ( empty( $post['product_name'] ) || ! is_array( $post['product_name'] ) ) {
            return 0;
        }

        $names    = $post['product_name'];
        $prices   = isset( $post['product_price'] ) && is_array( $post['product_price'] ) ? $post['product_price'] : array();
        $visibles = isset( $post['product_visible'] ) && is_array( $post['product_visible'] ) ? $post['product_visible'] : array();
        $ids      = isset( $post['product_id'] ) && is_array( $post['product_id'] ) ? $post['product_id'] : array();

        $products = array();
        $count    = 0;
        for ( $i = 0; $i < count( $names ) && $count < 10; $i++ ) {
            $name = sanitize_text_field( $names[ $i ] );
            if ( '' === $name ) {
                continue;
            }
            $price_raw = isset( $prices[ $i ] ) ? $prices[ $i ] : '0';
            $price     = round( floatval( preg_replace( '/[^0-9.\-]/', '', $price_raw ) ), 2 );

            $id = isset( $ids[ $i ] ) ? sanitize_title( $ids[ $i ] ) : sanitize_title( $name );
            if ( '' === $id ) {
                $id = 'p' . time() . $i;
            }
            $base_id = $id;
            $suffix  = 1;
            while ( in_array( $id, array_column( $products, 'id' ), true ) ) {
                $id = $base_id . '-' . $suffix;
                $suffix++;
            }

            $visible = in_array( (string) $i, $visibles, true )
                || in_array( $id, $visibles, true )
                || ( isset( $visibles[ $i ] ) && $visibles[ $i ] );

            $products[] = array(
                'id'      => $id,
                'name'    => $name,
                'price'   => number_format( $price, 2, '.', '' ),
                'visible' => $visible ? 1 : 0,
            );
            $count++;
        }

        update_option( 'order_sync_products', wp_json_encode( $products ) );
        return count( $products );
    }

    /**
     * Step 2: make this season's sale days match the list the admin submitted.
     *
     * Adds the dates that aren't there yet and removes the ones taken off the
     * list - but only when nothing is hanging off them. Never cascades.
     *
     * @param array $posted Raw Y-m-d strings from the form.
     * @param int   $step
     * @return string Message for the wizard footer.
     */
    private static function save_sales_days( $posted, $step ) {
        $season_id = intval( get_option( 'subsales_current_season_id' ) );
        if ( ! $season_id ) {
            self::fail( 'Start the season in step 1 first - sale days are filed under a season.', $step );
        }

        // The date field is <input type="date">, so this is Y-m-d or it's junk.
        $wanted = array();
        foreach ( $posted as $raw ) {
            $date = sanitize_text_field( $raw );
            $dt   = DateTime::createFromFormat( '!Y-m-d', $date );
            if ( $dt && $dt->format( 'Y-m-d' ) === $date ) {
                $wanted[ $date ] = true;
            }
        }

        // Current season only - the same date can belong to another season.
        $existing = array();
        foreach ( Subsales_Database::get_campaigns( 'all' ) as $campaign ) {
            $existing[ $campaign['campaign_date'] ] = $campaign;
        }

        $added = 0;
        foreach ( array_keys( $wanted ) as $date ) {
            if ( isset( $existing[ $date ] ) ) {
                continue;
            }
            if ( Subsales_Database::save_campaign( array( 'campaign_date' => $date ) ) ) {
                $added++;
            }
        }

        $removed = 0;
        $kept    = array();
        foreach ( $existing as $date => $campaign ) {
            if ( isset( $wanted[ $date ] ) ) {
                continue;
            }
            // delete_campaign() is the only guard now - it refuses while signups,
            // drivers or payments point at the day and hands back the reason, so
            // the wizard and the Sales Days tab can't drift apart.
            $result = Subsales_Database::delete_campaign( intval( $campaign['id'] ) );
            if ( true === $result ) {
                $removed++;
                continue;
            }
            $kept[] = self::sale_day_label( $date ) . ' - ' . $result;
        }

        $message = sprintf( '%d sale day(s) added, %d removed.', $added, $removed );

        if ( $kept ) {
            self::touch_last_run();
            self::fail( $message . ' These could not be removed: ' . implode( ' ', $kept ), $step );
        }

        return $message;
    }

    /** "Mon Feb 15, 2027" - noon keeps the timezone shift out of it. */
    private static function sale_day_label( $date ) {
        return wp_date( 'D M j, Y', strtotime( $date . ' 12:00:00' ) );
    }

    /* ----------------------------------------------------------- rendering */

    /**
     * @param int   $step
     * @param array $args Extra variables made available to the step template.
     * @return string
     */
    public static function render_step( $step, $args = array() ) {
        $step   = max( 1, min( self::TOTAL_STEPS, intval( $step ) ) );
        $status = self::status();
        ob_start();
        include SUBSALES_PLUGIN_PATH . 'admin/partials/season-setup-steps.php';
        return ob_get_clean();
    }

    /* ---------------------------------------------------------------- ajax */

    private static function guard() {
        check_ajax_referer( self::NONCE, 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to do that.' ), 403 );
        }
    }

    public static function ajax_step() {
        self::guard();
        $step = isset( $_POST['step'] ) ? intval( $_POST['step'] ) : 1;
        wp_send_json_success( array( 'step' => $step, 'html' => self::render_step( $step ) ) );
    }

    public static function ajax_save() {
        self::guard();

        $op   = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
        $step = isset( $_POST['step'] ) ? intval( $_POST['step'] ) : 1;
        $args = array();

        switch ( $op ) {

            case 'season':
                $label  = isset( $_POST['season_label'] ) ? sanitize_text_field( wp_unslash( $_POST['season_label'] ) ) : '';
                $result = Subsales_Database::start_new_season( $label );
                if ( is_wp_error( $result ) ) {
                    self::fail( $result->get_error_message(), $step );
                }
                $message = sprintf(
                    'Season "%s" started. %d team(s) from last season were retired. Nothing was deleted.',
                    $label,
                    intval( $result['teams_deactivated'] )
                );
                break;

            case 'sales_days':
                $message = self::save_sales_days( isset( $_POST['dates'] ) ? (array) wp_unslash( $_POST['dates'] ) : array(), $step );
                break;

            case 'roster_preview':
                $preview = subsales_process_import_preview( isset( $_FILES['roster_file'] ) ? $_FILES['roster_file'] : null );
                if ( isset( $preview['error'] ) ) {
                    self::fail( $preview['error'], $step );
                }
                $args['roster_preview'] = $preview;
                $message = sprintf(
                    'Checked your file: %d team(s) and %d person(s). Nothing has been saved yet - review below, then confirm.',
                    count( $preview['teams'] ),
                    count( $preview['users'] )
                );
                break;

            case 'roster_confirm':
                $raw  = isset( $_POST['import_data'] ) ? wp_unslash( $_POST['import_data'] ) : '';
                $data = json_decode( $raw, true );
                if ( ! is_array( $data ) || ! isset( $data['teams'], $data['users'] ) ) {
                    self::fail( 'The roster preview expired. Please choose the file again.', $step );
                }
                try {
                    subsales_process_import_confirm( $data );
                } catch ( Throwable $e ) {
                    self::fail( 'The roster could not be saved: ' . $e->getMessage(), $step );
                }
                $message = 'Roster updated.';
                break;

            case 'products':
                $saved   = self::save_products( $_POST );
                $message = sprintf( '%d product(s) saved.', $saved );
                break;

            case 'mode':
                $mode = isset( $_POST['sales_mode'] ) && 'user' === $_POST['sales_mode'] ? 'user' : 'legacy';
                update_option( 'subsales_sales_mode', $mode );
                $message = 'user' === $mode
                    ? 'Sales mode set to Individual - each seller works on their own.'
                    : 'Sales mode set to Teams - sellers go out as a team.';
                break;

            case 'sales':
                $enabled = ! empty( $_POST['sales_enabled'] ) ? 1 : 0;
                update_option( 'subsales_sales_enabled', $enabled );
                $message = $enabled ? 'Sales are OPEN. Sellers can take orders now.' : 'Sales are CLOSED.';
                break;

            default:
                self::fail( 'Unknown action.', $step );
        }

        self::touch_last_run();

        wp_send_json_success( array(
            'step'    => $step,
            'message' => $message,
            'html'    => self::render_step( $step, $args ),
        ) );
    }

    private static function fail( $message, $step ) {
        wp_send_json_error( array(
            'message' => $message,
            'step'    => $step,
            'html'    => self::render_step( $step ),
        ) );
    }
}

Subsales_Season_Setup::init();

endif;
