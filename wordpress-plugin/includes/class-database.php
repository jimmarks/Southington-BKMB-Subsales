<?php
/**
 * Database Management Class
 * 
 * Handles all database operations including:
 * - Table creation and schema management
 * - Database migrations
 * - Team CRUD operations
 * - Logging system
 * 
 * @package Subsales_Management
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Database {
    
    /**
     * Initialize database hooks
     */
    public static function init() {
        // Schedule log cleanup cron
        if ( ! wp_next_scheduled( 'subsales_log_cleanup' ) ) {
            wp_schedule_event( time(), 'hourly', 'subsales_log_cleanup' );
        }
        add_action( 'subsales_log_cleanup', array( __CLASS__, 'cleanup_old_logs' ) );
        add_action( 'subsales_log_cleanup', array( __CLASS__, 'check_debug_timeout' ) );
        add_action( 'subsales_log_cleanup', array( __CLASS__, 'cleanup_stale_pwa_sessions' ) );
        // Read-only flagging of order addresses missing from ss_addresses.
        // Stacked on the existing hourly hook rather than its own schedule.
        add_action( 'subsales_log_cleanup', array( __CLASS__, 'scan_unmatched_order_addresses' ) );

        // The old nightly address-validation job (geocode every new order, then
        // make an admin approve each one by hand) was removed - it stalled after
        // about a month of real use. Sites upgrading from an older version still
        // have the event scheduled, so clear it rather than leaving it firing
        // into a handler that no longer exists.
        if ( wp_next_scheduled( 'subsales_nightly_address_validation' ) ) {
            wp_clear_scheduled_hook( 'subsales_nightly_address_validation' );
        }
    }
    
    /**
     * Create all database tables
     */
    public static function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ss_orders';
        $teams_table_name = $wpdb->prefix . 'ss_teams';
        $team_members_table_name = $wpdb->prefix . 'ss_team_members';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id varchar(255) NOT NULL,
            user_id varchar(255) NOT NULL,
            team_id mediumint(9),
            order_data text NOT NULL,
            sync_status varchar(50) DEFAULT 'pending',
            deleted tinyint(1) DEFAULT 0,
            deleted_at datetime DEFAULT NULL,
            deleted_by_user_id bigint(20) unsigned DEFAULT NULL,
            delete_reason text,
            tallied tinyint(1) DEFAULT 0,
            tallied_at datetime DEFAULT NULL,
            tallied_by_user_id bigint(20) unsigned DEFAULT NULL,
            address varchar(500) DEFAULT NULL,
            address_entry_method enum('autocomplete','manual','gps','unknown') DEFAULT 'unknown',
            address_validation_status enum('pending','valid','geocode_failed','format_invalid','approved','dismissed') DEFAULT 'pending',
            address_validation_date datetime DEFAULT NULL,
            address_validation_data text DEFAULT NULL,
            address_hash varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY team_id (team_id),
            KEY deleted (deleted),
            KEY tallied (tallied),
            KEY address_validation_status (address_validation_status),
            KEY address_hash (address_hash)
        ) $charset_collate;";
        
        $teams_sql = "CREATE TABLE $teams_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            access_code varchar(255) NOT NULL,
            description text,
            status varchar(50) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY access_code (access_code)
        ) $charset_collate;";
        
        $team_members_sql = "CREATE TABLE $team_members_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            team_id mediumint(9) NOT NULL DEFAULT 0,
            name varchar(255) NOT NULL,
            email varchar(255) DEFAULT '',
            phone varchar(50) NOT NULL,
            role varchar(50) NOT NULL DEFAULT 'member',
            status varchar(50) NOT NULL DEFAULT 'active',
            last_login datetime,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY phone (phone),
            KEY team_id (team_id)
        ) $charset_collate;";
        
        // Junction table for many-to-many user-team relationships
        $user_teams_table_name = $wpdb->prefix . 'ss_user_teams';
        $user_teams_sql = "CREATE TABLE $user_teams_table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            user_id mediumint(9) NOT NULL,
            team_id mediumint(9) NOT NULL,
            assigned_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_team (user_id, team_id),
            KEY user_id (user_id),
            KEY team_id (team_id)
        ) $charset_collate;";
        
        // Edit history table for order auditing
        $edit_history_table_name = $wpdb->prefix . 'ss_edit_history';
        $edit_history_sql = "CREATE TABLE $edit_history_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            edited_by_user_id bigint(20) unsigned NOT NULL,
            edited_by_name varchar(255) DEFAULT '',
            edit_type enum('create','update','delete','restore') NOT NULL,
            edit_reason text,
            changes_summary varchar(500) DEFAULT '',
            changes_detail longtext,
            source varchar(20) DEFAULT 'admin',
            edited_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY edited_at (edited_at),
            KEY edited_by_user_id (edited_by_user_id)
        ) $charset_collate;";
        
        // Logs table for system-wide logging with debug mode support
        $logs_table_name = $wpdb->prefix . 'ss_logs';
        $logs_sql = "CREATE TABLE $logs_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            log_level enum('DEBUG','INFO','WARNING','ERROR','CRITICAL') NOT NULL DEFAULT 'INFO',
            category varchar(50) NOT NULL DEFAULT 'system',
            message text NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            user_name varchar(255) DEFAULT '',
            source varchar(20) DEFAULT 'admin',
            context_json longtext,
            created_at datetime NOT NULL,
            is_debug tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id),
            KEY log_level (log_level),
            KEY category (category),
            KEY created_at (created_at),
            KEY is_debug (is_debug),
            KEY user_id (user_id)
        ) $charset_collate;";
        
        // PWA Sessions table for tracking active PWA clients
        $pwa_sessions_table_name = $wpdb->prefix . 'ss_pwa_sessions';
        $pwa_sessions_sql = "CREATE TABLE $pwa_sessions_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            user_name varchar(255) DEFAULT '',
            team_id bigint(20) unsigned DEFAULT NULL,
            team_name varchar(255) DEFAULT '',
            user_agent text,
            ip_address varchar(45) DEFAULT '',
            login_at datetime NOT NULL,
            last_heartbeat datetime NOT NULL,
            logout_at datetime DEFAULT NULL,
            session_expiry datetime DEFAULT NULL,
            session_data longtext,
            status enum('active','idle','ended') NOT NULL DEFAULT 'active',
            PRIMARY KEY  (id),
            UNIQUE KEY session_id (session_id),
            KEY user_id (user_id),
            KEY team_id (team_id),
            KEY status (status),
            KEY last_heartbeat (last_heartbeat),
            KEY login_at (login_at)
        ) $charset_collate;";
        
        // PWA Session Heartbeats table for tracking heartbeat history and GPS
        $pwa_heartbeats_table_name = $wpdb->prefix . 'ss_pwa_heartbeats';
        $pwa_heartbeats_sql = "CREATE TABLE $pwa_heartbeats_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id varchar(64) NOT NULL,
            heartbeat_at datetime NOT NULL,
            gps_latitude decimal(10, 8) DEFAULT NULL,
            gps_longitude decimal(11, 8) DEFAULT NULL,
            gps_accuracy decimal(10, 2) DEFAULT NULL,
            activity_data longtext,
            PRIMARY KEY  (id),
            KEY session_id (session_id),
            KEY heartbeat_at (heartbeat_at),
            KEY gps_coordinates (gps_latitude, gps_longitude)
        ) $charset_collate;";
        
        // Address Lookup table for validated addresses with GPS coordinates
        $addresses_table_name = $wpdb->prefix . 'ss_addresses';
        $addresses_sql = "CREATE TABLE $addresses_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            street varchar(255) NOT NULL,
            house_number varchar(20) DEFAULT '',
            unit varchar(20) DEFAULT '',
            city varchar(100) NOT NULL DEFAULT 'Southington',
            state varchar(2) NOT NULL DEFAULT 'CT',
            zip varchar(10) NOT NULL,
            lat decimal(10, 8) NOT NULL,
            lng decimal(11, 8) NOT NULL,
            source enum('parcel','overpass','csv','manual') NOT NULL DEFAULT 'manual',
            confidence enum('high','medium','low') NOT NULL DEFAULT 'medium',
            matched tinyint(1) DEFAULT 0,
            type enum('residential','commercial','other') NOT NULL DEFAULT 'residential',
            full_address text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_address (street, house_number, unit, zip),
            KEY idx_zip (zip),
            KEY idx_street (street),
            KEY idx_type (type),
            KEY idx_source (source),
            KEY idx_coordinates (lat, lng)
        ) $charset_collate;";
        
        // Address Review Queue for addresses the pipeline could not resolve on its own.
        // Nothing unresolved is ever written to ss_addresses - it lands here instead and
        // waits for an admin, so a half-resolved address can never reach the PWA.
        $address_review_queue_table_name = $wpdb->prefix . 'ss_address_review_queue';
        $address_review_queue_sql = "CREATE TABLE $address_review_queue_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            reason enum('zip_undetermined','not_in_database') NOT NULL,
            source_context enum('ingestion','order_entry') NOT NULL,
            raw_address varchar(500) NOT NULL,
            house_number varchar(20) DEFAULT '',
            street varchar(255) DEFAULT '',
            city varchar(100) DEFAULT 'Southington',
            candidate_zips_json text DEFAULT NULL,
            lat decimal(10, 8) DEFAULT NULL,
            lng decimal(11, 8) DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            address_hash varchar(64) NOT NULL,
            status enum('pending','resolved','dismissed') NOT NULL DEFAULT 'pending',
            resolution_note text DEFAULT NULL,
            resolved_address_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY address_hash (address_hash),
            KEY idx_status (status),
            KEY idx_order_id (order_id)
        ) $charset_collate;";

        // Campaign Dates table for managing selling dates
        $campaigns_table_name = $wpdb->prefix . 'ss_campaigns';
        $campaigns_sql = "CREATE TABLE $campaigns_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_date date NOT NULL,
            campaign_name varchar(255) DEFAULT '',
            notes text,
            status enum('active','inactive','completed') NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset_collate;";
        
        // Team Signups table for date-based team registration
        $signups_table_name = $wpdb->prefix . 'ss_signups';
        $signups_sql = "CREATE TABLE $signups_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            team_id bigint(20) unsigned NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            is_driver tinyint(1) DEFAULT 0,
            notes text,
            status enum('active','cancelled') NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY user_team_campaign (user_id, team_id, campaign_id),
            KEY user_id (user_id),
            KEY team_id (team_id),
            KEY campaign_id (campaign_id),
            KEY is_driver (is_driver),
            KEY status (status)
        ) $charset_collate;";
        
        // Team Campaigns table for team/date-specific data (driver info)
        $team_campaigns_table_name = $wpdb->prefix . 'ss_team_campaigns';
        $team_campaigns_sql = "CREATE TABLE $team_campaigns_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            driver_name varchar(255) DEFAULT '',
            driver_updated_by varchar(255) DEFAULT '',
            driver_updated_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY team_campaign (team_id, campaign_id),
            KEY team_id (team_id),
            KEY campaign_id (campaign_id)
        ) $charset_collate;";
        
        // SMS contacts - one row per PHONE NUMBER, not per order. A household
        // orders across seasons and from different children; consent and
        // opt-out have to survive that, so they live on the number.
        // assigned_number pins our own sending number to the contact rather
        // than trusting Twilio's Sticky Sender to remember across a year-long
        // gap, so a returning customer always sees the same number.
        $sms_contacts_table_name = $wpdb->prefix . 'ss_sms_contacts';
        $sms_contacts_sql = "CREATE TABLE $sms_contacts_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            phone varchar(20) NOT NULL,
            consent_transactional tinyint(1) NOT NULL DEFAULT 0,
            consent_source varchar(50) NOT NULL DEFAULT '',
            consent_wording text,
            consent_at datetime DEFAULT NULL,
            opted_out_at datetime DEFAULT NULL,
            assigned_number varchar(20) DEFAULT NULL,
            last_message_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY phone (phone),
            KEY idx_opted_out (opted_out_at)
        ) $charset_collate;";

        // SMS messages - the outbox AND the log, one table.
        //
        // UNIQUE KEY one_per_order_type (order_id, message_type) is the receipt
        // idempotency guarantee: an order can only ever produce one receipt row
        // however many times the offline-first PWA re-syncs it. MySQL allows
        // multiple NULLs in a unique key and order_id is nullable, so inbound
        // rows (order_id NULL) are unaffected by it.
        $sms_messages_table_name = $wpdb->prefix . 'ss_sms_messages';
        $sms_messages_sql = "CREATE TABLE $sms_messages_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            direction enum('out','in') NOT NULL,
            message_type enum('receipt','reply','system') NOT NULL,
            phone varchar(20) NOT NULL,
            body text,
            order_id varchar(255) DEFAULT NULL,
            status enum('queued','sending','sent','delivered','failed','received','skipped') NOT NULL DEFAULT 'queued',
            skip_reason varchar(100) DEFAULT NULL,
            attempts smallint NOT NULL DEFAULT 0,
            next_attempt_at datetime DEFAULT NULL,
            last_error text,
            twilio_sid varchar(64) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY one_per_order_type (order_id, message_type),
            KEY idx_status_next (status, next_attempt_at),
            KEY idx_phone (phone)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        dbDelta( $teams_sql );
        dbDelta( $team_members_sql );
        dbDelta( $user_teams_sql );
        dbDelta( $edit_history_sql );
        dbDelta( $logs_sql );
        dbDelta( $pwa_sessions_sql );
        dbDelta( $pwa_heartbeats_sql );
        dbDelta( $addresses_sql );
        dbDelta( $address_review_queue_sql );
        dbDelta( $campaigns_sql );
        dbDelta( $signups_sql );
        dbDelta( $team_campaigns_sql );
        dbDelta( $sms_contacts_sql );
        dbDelta( $sms_messages_sql );

        // Run schema migrations
        self::migrate_phone_column( $team_members_table_name );
        self::migrate_soft_delete_columns( $table_name );
        self::migrate_tally_columns( $table_name );
        self::migrate_user_teams( $team_members_table_name, $user_teams_table_name );
        self::migrate_edit_type_enum( $edit_history_table_name );
        self::migrate_team_campaigns_table( $team_campaigns_table_name );
        self::migrate_address_validation_columns( $table_name );
        self::migrate_geocode_cache_columns();
        self::migrate_address_validation_dismissed_status( $table_name );
        self::migrate_sms_sending_status( $sms_messages_table_name );

        // Season support - must run in this order: seasons table (and its
        // bootstrap row) before the season_id columns that backfill from it.
        self::migrate_seasons_table();
        self::migrate_teams_season_id( $teams_table_name );
        self::migrate_campaigns_season_id( $campaigns_table_name );
        self::migrate_campaigns_season_unique_key( $campaigns_table_name );
        self::migrate_orders_season_id( $table_name );
        self::migrate_payment_attempts_table();
    }

    /**
     * Normalize a phone number to bare 10 digits.
     *
     * Same expression the plugin already inlines in ~8 places for MEMBER
     * phones - the difference is that customer phones (order_data.cellNumber)
     * are stored exactly as typed, so admin-entered ones carry "(203) 555-1234"
     * formatting and have to be normalized at READ time. Trailing 10 digits, so
     * a leading country code ("1-203-555-1234") is dropped rather than
     * producing an 11-digit string that won't match a stored contact.
     *
     * @param string $phone Raw phone as entered.
     * @return string 10 digits, or '' / a short string if there weren't 10.
     */
    public static function normalize_phone( $phone ) {
        $digits = preg_replace( '/\D/', '', (string) $phone );

        return strlen( $digits ) > 10 ? substr( $digits, -10 ) : $digits;
    }

    /**
     * Schema migration: Ensure seasons table exists, with a bootstrap
     * "legacy" row so every pre-existing team/campaign/order can be
     * backfilled to a real season instead of being left at season_id 0.
     */
    private static function migrate_seasons_table() {
        global $wpdb;
        $seasons_table_name = $wpdb->prefix . 'ss_seasons';

        $table_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$seasons_table_name}'"
        );

        if ( ! $table_exists ) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $seasons_table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                label varchar(255) NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY label (label)
            ) $charset_collate;";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );

            subsales_log( 'INFO', 'system', 'Created seasons table via migration' );
        }

        // Bootstrap row: everything that existed before seasons existed
        // belongs to one legacy season. Only ever created once - if the
        // option is already set, a season (bootstrap or a real one the
        // admin since started) is already current and must not be touched.
        if ( ! get_option( 'subsales_current_season_id' ) ) {
            $existing_season = $wpdb->get_var( "SELECT id FROM {$seasons_table_name} ORDER BY id ASC LIMIT 1" );
            if ( $existing_season ) {
                $bootstrap_id = intval( $existing_season );
            } else {
                $wpdb->insert( $seasons_table_name, array(
                    'label' => '2025-2026',
                ), array( '%s' ) );
                $bootstrap_id = intval( $wpdb->insert_id );
                subsales_log( 'INFO', 'system', 'Created bootstrap legacy season via migration', array( 'season_id' => $bootstrap_id ) );
            }
            update_option( 'subsales_current_season_id', $bootstrap_id );
        }
    }

    /**
     * Schema migration: Add season_id to teams, backfill existing rows to
     * the current (bootstrap, on first run) season, and tighten the
     * uniqueness constraint from global-unique-by-name to
     * unique-by-(name, season) so a team name can be reused every season.
     */
    private static function migrate_teams_season_id( $teams_table_name ) {
        global $wpdb;

        $column_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$teams_table_name}'
             AND COLUMN_NAME = 'season_id'"
        );

        if ( ! $column_exists ) {
            $wpdb->query(
                "ALTER TABLE {$teams_table_name}
                 ADD COLUMN season_id mediumint(9) NOT NULL DEFAULT 0 AFTER access_code,
                 ADD INDEX season_id (season_id)"
            );

            $current_season_id = Subsales_Database::current_season_id();
            if ( $current_season_id ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$teams_table_name} SET season_id = %d WHERE season_id = 0",
                    $current_season_id
                ) );
            }
        }

        // Tighten uniqueness: name alone -> (name, season_id), so the same
        // team name can exist fresh in a later season without colliding
        // with a prior season's row of the same name.
        $has_old_key = $wpdb->get_var(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$teams_table_name}'
             AND INDEX_NAME = 'name'"
        );
        $has_new_key = $wpdb->get_var(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$teams_table_name}'
             AND INDEX_NAME = 'name_season'"
        );

        // Must be checked independently, not as "old && !new". dbDelta re-adds
        // any index the CREATE TABLE string still declares, so it resurrected
        // UNIQUE KEY name after this migration had dropped it - leaving both
        // keys live and silently restoring the very constraint seasons exist to
        // remove (a team name could still never repeat in a later season).
        // The declaration has been removed from the schema above; this repairs
        // installs that already have the stale key.
        if ( ! $has_new_key ) {
            $wpdb->query(
                "ALTER TABLE {$teams_table_name}
                 ADD UNIQUE KEY name_season (name, season_id)"
            );
        }

        if ( $has_old_key ) {
            $wpdb->query( "ALTER TABLE {$teams_table_name} DROP INDEX name" );
            subsales_log( 'INFO', 'system', 'Dropped stale UNIQUE KEY name on teams; (name, season_id) is authoritative' );
        }
    }

    /**
     * Schema migration: Add season_id to campaigns, backfilling existing
     * rows to the current (bootstrap, on first run) season.
     */
    private static function migrate_campaigns_season_id( $campaigns_table_name ) {
        global $wpdb;

        $column_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$campaigns_table_name}'
             AND COLUMN_NAME = 'season_id'"
        );

        if ( $column_exists ) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$campaigns_table_name}
             ADD COLUMN season_id mediumint(9) NOT NULL DEFAULT 0 AFTER campaign_date,
             ADD INDEX season_id (season_id)"
        );

        $current_season_id = Subsales_Database::current_season_id();
        if ( $current_season_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$campaigns_table_name} SET season_id = %d WHERE season_id = 0",
                $current_season_id
            ) );
        }
    }

    /**
     * Schema migration: campaign_date was UNIQUE on its own, so a given date
     * could only ever exist once across every season. Teams were tightened to
     * (name, season_id) when seasons were introduced; campaigns were missed.
     *
     * Any season_id = 0 rows are adopted into the current season first -
     * otherwise they could collide with a real row and fail the ALTER.
     */
    private static function migrate_campaigns_season_unique_key( $campaigns_table_name ) {
        global $wpdb;

        $has_old_key = $wpdb->get_var(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$campaigns_table_name}'
             AND INDEX_NAME = 'campaign_date'"
        );
        $has_new_key = $wpdb->get_var(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$campaigns_table_name}'
             AND INDEX_NAME = 'campaign_date_season'"
        );

        if ( ! $has_old_key && $has_new_key ) {
            return;
        }

        $current_season_id = Subsales_Database::current_season_id();
        if ( $current_season_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$campaigns_table_name} SET season_id = %d WHERE season_id = 0",
                $current_season_id
            ) );
        }

        // Checked independently rather than as one ALTER, for the same reason as
        // the teams key above: dbDelta re-adds anything the CREATE TABLE string
        // still declares, so these two states drift apart and the migration has
        // to be able to repair either one on its own.
        if ( ! $has_new_key ) {
            $result = $wpdb->query(
                "ALTER TABLE {$campaigns_table_name}
                 ADD UNIQUE KEY campaign_date_season (campaign_date, season_id)"
            );

            if ( false === $result ) {
                subsales_log( 'ERROR', 'system', 'Campaigns unique-key migration failed', array(
                    'error' => $wpdb->last_error,
                ) );
                return;
            }
        }

        if ( $has_old_key ) {
            $wpdb->query( "ALTER TABLE {$campaigns_table_name} DROP INDEX campaign_date" );
        }

        subsales_log( 'INFO', 'system', 'Campaigns unique key is now (campaign_date, season_id)' );
    }

    /**
     * Schema migration: Add season_id to orders, backfilling existing rows
     * to the current (bootstrap, on first run) season.
     */
    private static function migrate_orders_season_id( $orders_table_name ) {
        global $wpdb;

        $column_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$orders_table_name}'
             AND COLUMN_NAME = 'season_id'"
        );

        if ( $column_exists ) {
            return;
        }

        $wpdb->query(
            "ALTER TABLE {$orders_table_name}
             ADD COLUMN season_id mediumint(9) NOT NULL DEFAULT 0 AFTER team_id,
             ADD INDEX season_id (season_id)"
        );

        $current_season_id = Subsales_Database::current_season_id();
        if ( $current_season_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$orders_table_name} SET season_id = %d WHERE season_id = 0",
                $current_season_id
            ) );
        }
    }

    /**
     * Schema migration: Ensure the payment_attempts table exists.
     *
     * Brand-new table (Square digital-payment checkout attempts), no
     * legacy rows to backfill - unlike the season_id migrations above,
     * this is a pure existence check + create, same shape as
     * migrate_seasons_table().
     */
    private static function migrate_payment_attempts_table() {
        global $wpdb;
        $payment_attempts_table_name = $wpdb->prefix . 'ss_payment_attempts';

        $table_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$payment_attempts_table_name}'"
        );

        if ( ! $table_exists ) {
            $charset_collate = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE $payment_attempts_table_name (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                attempt_uid varchar(64) NOT NULL,
                season_id mediumint(9) NOT NULL DEFAULT 0,
                team_id mediumint(9) NOT NULL DEFAULT 0,
                campaign_id mediumint(9) NOT NULL DEFAULT 0,
                user_id varchar(255) NOT NULL DEFAULT '',
                entered_by_name varchar(255) NOT NULL DEFAULT '',
                draft_order_data longtext NOT NULL,
                subtotal_amount decimal(10,2) NOT NULL DEFAULT 0,
                convenience_fee_amount decimal(10,2) NOT NULL DEFAULT 0,
                total_amount decimal(10,2) NOT NULL DEFAULT 0,
                square_checkout_id varchar(255) DEFAULT NULL,
                square_order_id varchar(255) DEFAULT NULL,
                square_payment_id varchar(255) DEFAULT NULL,
                checkout_url text DEFAULT NULL,
                status enum('initiated','paid','cancelled_by_seller','expired','failed','refunded') NOT NULL DEFAULT 'initiated',
                finalized_order_id varchar(255) DEFAULT NULL,
                refund_id varchar(64) DEFAULT NULL,
                refunded_at datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY attempt_uid (attempt_uid),
                KEY team_id (team_id),
                KEY season_id (season_id),
                KEY status (status),
                KEY square_checkout_id (square_checkout_id)
            ) $charset_collate;";

            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );

            subsales_log( 'INFO', 'system', 'Created payment_attempts table via migration' );
        }
    }

    /**
     * Schema migration: Ensure team_campaigns table exists
     */
    private static function migrate_team_campaigns_table( $team_campaigns_table_name ) {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var( 
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$team_campaigns_table_name}'"
        );
        
        if ( $table_exists ) {
            return; // Table already exists
        }
        
        // Create the table
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $team_campaigns_table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            driver_name varchar(255) DEFAULT '',
            driver_updated_by varchar(255) DEFAULT '',
            driver_updated_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY team_campaign (team_id, campaign_id),
            KEY team_id (team_id),
            KEY campaign_id (campaign_id)
        ) $charset_collate;";
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        
        subsales_log( 'INFO', 'system', 'Created team_campaigns table via migration' );
    }
    
    /**
     * Schema migration: Fix phone column constraints
     */
    private static function migrate_phone_column( $team_members_table_name ) {
        global $wpdb;
        
        $phone_column_exists = $wpdb->get_var( 
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$team_members_table_name}' 
             AND COLUMN_NAME = 'phone'"
        );
        
        if ( ! $phone_column_exists ) {
            return;
        }
        
        // Check if phone column allows NULL or has DEFAULT ''
        $phone_column_info = $wpdb->get_row(
            "SELECT COLUMN_DEFAULT, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$team_members_table_name}' 
             AND COLUMN_NAME = 'phone'",
            ARRAY_A
        );
        
        // If phone is nullable or has empty default, update it
        if ( $phone_column_info && ( $phone_column_info['IS_NULLABLE'] === 'YES' || $phone_column_info['COLUMN_DEFAULT'] === '' ) ) {
            // First, set default phone for any users with NULL/empty phone
            $wpdb->query(
                "UPDATE {$team_members_table_name} 
                 SET phone = CONCAT('000000', LPAD(id, 4, '0')) 
                 WHERE phone IS NULL OR phone = ''"
            );
            
            // Now alter the column to NOT NULL
            $wpdb->query(
                "ALTER TABLE {$team_members_table_name} 
                 MODIFY COLUMN phone varchar(50) NOT NULL"
            );
        }
        
        // Check if UNIQUE constraint exists on phone
        $phone_index = $wpdb->get_var(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$team_members_table_name}' 
             AND COLUMN_NAME = 'phone' 
             AND NON_UNIQUE = 0"
        );
        
        if ( ! $phone_index ) {
            // Add UNIQUE constraint on phone
            $wpdb->query( "ALTER TABLE {$team_members_table_name} ADD UNIQUE KEY phone (phone)" );
        }
        
        // Check if email UNIQUE constraint exists (should be removed)
        $email_index = $wpdb->get_var(
            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$team_members_table_name}' 
             AND COLUMN_NAME = 'email' 
             AND NON_UNIQUE = 0"
        );
        
        if ( $email_index ) {
            // Remove UNIQUE constraint from email
            $wpdb->query( "ALTER TABLE {$team_members_table_name} DROP INDEX {$email_index}" );
        }
    }
    
    /**
     * Schema migration: Add soft delete columns if missing
     */
    private static function migrate_soft_delete_columns( $table_name ) {
        global $wpdb;
        
        $deleted_column_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$table_name}' 
             AND COLUMN_NAME = 'deleted'"
        );
        
        if ( ! $deleted_column_exists ) {
            // Add deleted columns
            $wpdb->query(
                "ALTER TABLE {$table_name} 
                 ADD COLUMN deleted tinyint(1) DEFAULT 0 AFTER sync_status,
                 ADD COLUMN deleted_at datetime DEFAULT NULL AFTER deleted,
                 ADD COLUMN deleted_by_user_id bigint(20) unsigned DEFAULT NULL AFTER deleted_at,
                 ADD COLUMN delete_reason text AFTER deleted_by_user_id,
                 ADD INDEX deleted (deleted)"
            );
        } else {
            // Column exists, but ensure existing rows have deleted = 0
            $wpdb->query(
                "UPDATE {$table_name} 
                 SET deleted = 0 
                 WHERE deleted IS NULL"
            );
        }
    }
    
    /**
     * Schema migration: Add tally columns if missing
     */
    private static function migrate_tally_columns( $table_name ) {
        global $wpdb;
        
        $tallied_column_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$table_name}' 
             AND COLUMN_NAME = 'tallied'"
        );
        
        if ( ! $tallied_column_exists ) {
            $wpdb->query(
                "ALTER TABLE {$table_name} 
                 ADD COLUMN tallied tinyint(1) DEFAULT 0 AFTER delete_reason,
                 ADD COLUMN tallied_at datetime DEFAULT NULL AFTER tallied,
                 ADD COLUMN tallied_by_user_id bigint(20) unsigned DEFAULT NULL AFTER tallied_at,
                 ADD INDEX tallied (tallied)"
            );
        }
    }
    
    /**
     * Schema migration: Migrate existing team_id assignments to junction table
     */
    private static function migrate_user_teams( $team_members_table_name, $user_teams_table_name ) {
        global $wpdb;
        
        $existing_assignments = $wpdb->get_results( 
            "SELECT id, team_id FROM {$team_members_table_name} WHERE team_id > 0", 
            ARRAY_A 
        );
        
        if ( ! empty( $existing_assignments ) ) {
            foreach ( $existing_assignments as $assignment ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$user_teams_table_name} (user_id, team_id) VALUES (%d, %d)",
                    $assignment['id'],
                    $assignment['team_id']
                ));
            }
        }
    }
    
    /**
     * Schema migration: Update edit_type enum to include 'create'
     */
    private static function migrate_edit_type_enum( $edit_history_table_name ) {
        global $wpdb;
        
        $edit_type_column_info = $wpdb->get_row(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$edit_history_table_name}' 
             AND COLUMN_NAME = 'edit_type'",
            ARRAY_A
        );
        
        if ( $edit_type_column_info && strpos( $edit_type_column_info['COLUMN_TYPE'], 'create' ) === false ) {
            // Update enum to include 'create'
            $wpdb->query(
                "ALTER TABLE {$edit_history_table_name} 
                 MODIFY COLUMN edit_type enum('create','update','delete','restore') NOT NULL"
            );
        }
    }
    
    /**
     * Schema migration: Add address validation columns
     */
    private static function migrate_address_validation_columns( $table_name ) {
        global $wpdb;
        
        // Check if address column exists
        $address_column_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$table_name}' 
             AND COLUMN_NAME = 'address'"
        );
        
        if ( ! $address_column_exists ) {
            // Add all address validation columns at once
            $wpdb->query(
                "ALTER TABLE {$table_name} 
                 ADD COLUMN address varchar(500) DEFAULT NULL AFTER tallied_by_user_id,
                 ADD COLUMN address_entry_method enum('autocomplete','manual','gps','unknown') DEFAULT 'unknown' AFTER address,
                 ADD COLUMN address_validation_status enum('pending','valid','geocode_failed','format_invalid','approved') DEFAULT 'pending' AFTER address_entry_method,
                 ADD COLUMN address_validation_date datetime DEFAULT NULL AFTER address_validation_status,
                 ADD COLUMN address_validation_data text DEFAULT NULL AFTER address_validation_date,
                 ADD COLUMN address_hash varchar(64) DEFAULT NULL AFTER address_validation_data,
                 ADD INDEX address_validation_status (address_validation_status),
                 ADD INDEX address_hash (address_hash)"
            );
            
            // Backfill address column from order_data for existing orders
            $orders = $wpdb->get_results( "SELECT id, order_data FROM {$table_name}", ARRAY_A );
            foreach ( $orders as $order ) {
                $order_data = json_decode( $order['order_data'], true );
                if ( ! empty( $order_data['address'] ) ) {
                    $address = $order_data['address'];
                    $address_hash = md5( strtolower( trim( $address ) ) );
                    
                    $wpdb->update(
                        $table_name,
                        array( 
                            'address' => $address,
                            'address_hash' => $address_hash
                        ),
                        array( 'id' => $order['id'] ),
                        array( '%s', '%s' ),
                        array( '%d' )
                    );
                }
            }
        }
    }
    
    /**
     * Schema migration: Add formatted_address and location_type to geocode cache
     * 
     * @since 2.4.55
     */
    private static function migrate_geocode_cache_columns() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'order_sync_geocodes';
        
        // Check if table exists
        $table_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$table_name}'"
        );
        
        if ( ! $table_exists ) {
            return; // Table doesn't exist yet, will be created with proper schema
        }
        
        // Check if formatted_address column exists
        $formatted_address_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$table_name}' 
             AND COLUMN_NAME = 'formatted_address'"
        );
        
        if ( ! $formatted_address_exists ) {
            // Add formatted_address and location_type columns
            $wpdb->query(
                "ALTER TABLE {$table_name} 
                 ADD COLUMN formatted_address TEXT DEFAULT NULL AFTER lng,
                 ADD COLUMN location_type VARCHAR(32) DEFAULT 'APPROXIMATE' AFTER formatted_address"
            );
            
            subsales_log( 'INFO', 'system', 'Geocode cache table migrated: added formatted_address and location_type columns' );
        }

        // Subsales_Delivery::geocode_address() caches using `address` and `created_at`
        // columns that the original schema never created. Add them idempotently so
        // the cache read/write stops failing with "Unknown column 'address'".
        $address_col = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$table_name}'
             AND COLUMN_NAME = 'address'"
        );
        if ( ! $address_col ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN address TEXT DEFAULT NULL" );
            subsales_log( 'INFO', 'system', 'Geocode cache table migrated: added address column' );
        }

        $created_at_col = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$table_name}'
             AND COLUMN_NAME = 'created_at'"
        );
        if ( ! $created_at_col ) {
            $wpdb->query( "ALTER TABLE {$table_name} ADD COLUMN created_at datetime DEFAULT NULL" );
            subsales_log( 'INFO', 'system', 'Geocode cache table migrated: added created_at column' );
        }
    }
    
    /**
     * Schema migration: Add 'dismissed' to address_validation_status enum
     * 
     * @since 2.4.60
     */
    private static function migrate_address_validation_dismissed_status( $table_name ) {
        global $wpdb;
        
        // Check if address_validation_status column exists
        $column_info = $wpdb->get_row(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$table_name}' 
             AND COLUMN_NAME = 'address_validation_status'",
            ARRAY_A
        );
        
        if ( ! $column_info ) {
            return; // Column doesn't exist yet
        }
        
        // Check if 'dismissed' is already in the enum
        if ( strpos( $column_info['COLUMN_TYPE'], "'dismissed'" ) !== false ) {
            return; // Already migrated
        }
        
        // Add 'dismissed' to the enum
        $wpdb->query(
            "ALTER TABLE {$table_name} 
             MODIFY COLUMN address_validation_status 
             enum('pending','valid','geocode_failed','format_invalid','approved','dismissed') 
             DEFAULT 'pending'"
        );
        
        subsales_log( 'INFO', 'system', 'Address validation status enum migrated: added dismissed status' );
    }

    /**
     * Schema migration: Add 'sending' to the SMS message status enum.
     *
     * 'sending' is the claim marker the outbox worker sets before it hands a
     * message to Twilio, so two overlapping runs can't send the same receipt
     * twice. Sites that installed the tables before the worker existed have the
     * old enum and would silently drop the claim to '' - hence this migration.
     *
     * NOTE: the CREATE TABLE string in create_tables() lists 'sending' too, and
     * the two MUST stay in step. This plugin has twice been bitten by dbDelta
     * re-applying a schema string that still declared the old column and quietly
     * undoing a migration on the next version bump.
     */
    private static function migrate_sms_sending_status( $sms_messages_table_name ) {
        global $wpdb;

        $column_info = $wpdb->get_row(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = '{$sms_messages_table_name}'
             AND COLUMN_NAME = 'status'",
            ARRAY_A
        );

        if ( ! $column_info ) {
            return; // Table/column not there yet - dbDelta will create it correctly.
        }

        if ( strpos( $column_info['COLUMN_TYPE'], "'sending'" ) !== false ) {
            return; // Already migrated.
        }

        $wpdb->query(
            "ALTER TABLE {$sms_messages_table_name}
             MODIFY COLUMN status
             enum('queued','sending','sent','delivered','failed','received','skipped')
             NOT NULL DEFAULT 'queued'"
        );

        subsales_log( 'INFO', 'system', 'SMS message status enum migrated: added sending status' );
    }

    /**
     * Add a new team
     * 
     * @param string $name Team name
     * @param string $access_code Access code
     * @param string $description Optional description
     * @return bool Success
     */
    public static function add_team( $name, $access_code, $description = '', $status = 'active' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_teams';
        $season_id = Subsales_Database::current_season_id();

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE (name = %s AND season_id = %d) OR access_code = %s",
                $name,
                $season_id,
                $access_code
            )
        );

        if ( $existing ) {
            error_log( 'Subsales: Team creation failed - name or access code already exists: ' . $name );
            return false;
        }

        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $name,
                'access_code' => $access_code,
                'description' => $description,
                'status' => $status,
                'season_id' => $season_id
            ),
            array( '%s', '%s', '%s', '%s', '%d' )
        );
        
        if ( $result === false ) {
            error_log( 'Subsales: Database error creating team: ' . $wpdb->last_error );
            return false;
        }
        
        return true;
    }
    
    /**
     * Remove a team and its members
     * 
     * @param int $team_id Team ID
     * @return int|false Number of rows deleted or false on failure
     */
    public static function remove_team( $team_id ) {
        global $wpdb;
        $teams_table = $wpdb->prefix . 'ss_teams';
        $members_table = $wpdb->prefix . 'ss_team_members';

        $wpdb->delete( $members_table, array( 'team_id' => $team_id ), array( '%d' ) );
        return $wpdb->delete( $teams_table, array( 'id' => $team_id ), array( '%d' ) );
    }

    // ============================================================
    // SEASONS
    // ============================================================

    /**
     * List all seasons, newest first.
     *
     * @return array
     */
    public static function get_seasons() {
        global $wpdb;
        $seasons_table = $wpdb->prefix . 'ss_seasons';
        return $wpdb->get_results( "SELECT * FROM {$seasons_table} ORDER BY id DESC", ARRAY_A );
    }

    /**
     * Team/campaign/member counts for one season - used on the "Start New
     * Season" confirmation screen so the admin sees what they're about to
     * retire before confirming. Member count is distinct members currently
     * linked to that season's teams (members themselves aren't season-scoped).
     *
     * @param int $season_id
     * @return array { teams, campaigns, members }
     */
    public static function get_season_counts( $season_id ) {
        global $wpdb;
        $teams_table      = $wpdb->prefix . 'ss_teams';
        $campaigns_table  = $wpdb->prefix . 'ss_campaigns';
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $season_id = intval( $season_id );

        return array(
            'teams'     => intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$teams_table} WHERE season_id = %d", $season_id
            ) ) ),
            'campaigns' => intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$campaigns_table} WHERE season_id = %d", $season_id
            ) ) ),
            'members'   => intval( $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT ut.user_id) FROM {$user_teams_table} ut
                 INNER JOIN {$teams_table} t ON ut.team_id = t.id
                 WHERE t.season_id = %d", $season_id
            ) ) ),
        );
    }

    /**
     * Start a new season: create the season row, make it current, and
     * retire (never delete) the prior season's teams by flipping them
     * inactive. Nothing about members, campaigns, signups, or orders is
     * touched - they stay exactly as they are, forever queryable as history.
     *
     * @param string $label New season label, e.g. "2026-2027"
     * @return array|WP_Error { new_season_id, old_season_id, teams_deactivated }
     */
    /**
     * The current season id, with a self-healing fallback.
     *
     * subsales_current_season_id is written once by migrate_seasons_table() and
     * nothing re-establishes it if it is lost. When it was missing, callers
     * disagreed about what 0 meant: some failed open (the kids' app listed every
     * sale day ever recorded), others failed closed (the admin dashboard read
     * zero orders), and create_order() stamped season_id = 0 on live orders,
     * orphaning them from every season-scoped query with no way back.
     *
     * Falling back to the newest season is the safe reading: a site with seasons
     * is always operating in the most recent one, and writing the option back
     * means the inconsistency is repaired rather than re-derived on every call.
     *
     * @return int Season id, or 0 only when no seasons exist at all.
     */
    public static function current_season_id() {
        $id = intval( get_option( 'subsales_current_season_id' ) );
        if ( $id > 0 ) {
            return $id;
        }

        global $wpdb;
        $seasons_table = $wpdb->prefix . 'ss_seasons';
        $newest = intval( $wpdb->get_var( "SELECT id FROM {$seasons_table} ORDER BY id DESC LIMIT 1" ) );

        if ( $newest > 0 ) {
            update_option( 'subsales_current_season_id', $newest, false );
            subsales_log( 'WARNING', 'seasons', 'subsales_current_season_id was missing; healed to newest season', array(
                'season_id' => $newest,
            ) );
        }

        return $newest;
    }

    public static function start_new_season( $label ) {
        global $wpdb;
        $label = sanitize_text_field( $label );

        if ( $label === '' ) {
            return new WP_Error( 'missing_label', 'A season label is required.', array( 'status' => 400 ) );
        }

        $seasons_table = $wpdb->prefix . 'ss_seasons';
        $teams_table   = $wpdb->prefix . 'ss_teams';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$seasons_table} WHERE label = %s", $label
        ) );
        if ( $existing ) {
            return new WP_Error( 'duplicate_label', 'A season with that label already exists.', array( 'status' => 409 ) );
        }

        $old_season_id = Subsales_Database::current_season_id();

        $inserted = $wpdb->insert( $seasons_table, array( 'label' => $label ), array( '%s' ) );
        if ( ! $inserted ) {
            return new WP_Error( 'insert_failed', 'Could not create the new season.', array( 'status' => 500 ) );
        }
        $new_season_id = intval( $wpdb->insert_id );

        update_option( 'subsales_current_season_id', $new_season_id );

        $teams_deactivated = 0;
        if ( $old_season_id ) {
            $teams_deactivated = intval( $wpdb->query( $wpdb->prepare(
                "UPDATE {$teams_table} SET status = 'inactive' WHERE season_id = %d",
                $old_season_id
            ) ) );
        }

        subsales_log( 'INFO', 'system', 'Started new season', array(
            'new_season_id'      => $new_season_id,
            'old_season_id'      => $old_season_id,
            'teams_deactivated'  => $teams_deactivated,
        ) );

        return array(
            'new_season_id'     => $new_season_id,
            'old_season_id'     => $old_season_id,
            'teams_deactivated' => $teams_deactivated,
        );
    }


    /**
     * Get all active teams
     * 
     * @return array Teams
     */
    /**
     * Teams, optionally filtered by status and season.
     *
     * Callers were already passing 'active' - the signature just ignored it and
     * returned every team of every season and status. Season defaults to the
     * current one: with seasons in play, "the teams" means this year's unless
     * something deliberately asks otherwise ($season_id = 0 for all seasons).
     *
     * @param string|null $status    e.g. 'active'; null for any status.
     * @param int|null    $season_id Season to scope to; 0 for all; null = current.
     */
    public static function get_teams( $status = null, $season_id = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_teams';

        if ( null === $season_id ) {
            $season_id = self::current_season_id();
        }

        $where  = array( '1=1' );
        $params = array();

        if ( ! empty( $status ) ) {
            $where[]  = 'status = %s';
            $params[] = $status;
        }
        if ( $season_id > 0 ) {
            $where[]  = 'season_id = %d';
            $params[] = $season_id;
        }

        $sql = "SELECT * FROM {$table_name} WHERE " . implode( ' AND ', $where )
             . " ORDER BY status DESC, created_at DESC";

        if ( $params ) {
            return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        }
        return $wpdb->get_results( $sql, ARRAY_A );
    }
    
    /**
     * Get team by credentials
     * 
     * @param string $team_name Team name
     * @param string $access_code Access code
     * @return array|null Team data or null
     */
    public static function get_team_by_credentials( $team_name, $access_code ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_teams';
        
        return $wpdb->get_row( 
            $wpdb->prepare( 
                "SELECT * FROM {$table_name} WHERE name = %s AND access_code = %s AND status = 'active'", 
                $team_name, 
                $access_code 
            ),
            ARRAY_A
        );
    }
    
    /**
     * Add team member
     * 
     * @param int $team_id Team ID
     * @param string $name Member name
     * @param string $email Member email
     * @param string $role Member role
     * @return bool Success
     */
    public static function add_team_member( $team_id, $name, $email, $role = 'member' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_team_members';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'team_id' => $team_id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'status' => 'active'
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );
        
        return $result !== false;
    }
    
    /**
     * Remove team member
     * 
     * @param int $member_id Member ID
     * @return int|false Number of rows deleted or false on failure
     */
    public static function remove_team_member( $member_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_team_members';
        
        return $wpdb->delete(
            $table_name,
            array( 'id' => $member_id ),
            array( '%d' )
        );
    }
    
    /**
     * Get team members by team ID
     * 
     * @param int $team_id Team ID
     * @return array Members
     */
    public static function get_team_members_by_team( $team_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_team_members';
        
        return $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE team_id = %d ORDER BY created_at DESC", 
                $team_id
            ),
            ARRAY_A 
        );
    }
    
    /**
     * Verify team member and update last login
     * 
     * @param string $email Member email
     * @param int $team_id Team ID
     * @return array|null Member data or null
     */
    public static function verify_team_member( $email, $team_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_team_members';
        
        $member = $wpdb->get_row( 
            $wpdb->prepare( 
                "SELECT * FROM {$table_name} WHERE email = %s AND team_id = %d AND status = 'active'", 
                $email, 
                $team_id 
            ),
            ARRAY_A
        );
        
        if ( $member ) {
            $wpdb->update(
                $table_name,
                array( 'last_login' => current_time( 'mysql' ) ),
                array( 'id' => $member['id'] ),
                array( '%s' ),
                array( '%d' )
            );
            
            return $member;
        }
        
        return null;
    }
    
    /**
     * Main logging function
     * 
     * @param string $level One of: DEBUG, INFO, WARNING, ERROR, CRITICAL
     * @param string $category Category: auth, orders, sync, api, system, zip
     * @param string $message Log message
     * @param array $context Additional context data
     * @param string $source Source: admin, pwa, cron, api
     * @param int $user_id Optional user ID
     * @param string $user_name Optional user name
     */
    public static function log( $level, $category, $message, $context = array(), $source = 'admin', $user_id = null, $user_name = '' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_logs';
        
        // Validate log level
        $valid_levels = array( 'DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL' );
        if ( ! in_array( $level, $valid_levels ) ) {
            $level = 'INFO';
        }
        
        // Check if debug logging is enabled
        $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
        
        // Only DEBUG level logs are marked as debug logs
        $is_debug = ( $level === 'DEBUG' ) ? 1 : 0;
        
        // Skip DEBUG logs if debug mode is not enabled
        if ( $level === 'DEBUG' && ! $debug_enabled ) {
            return;
        }
        
        // Prepare context JSON
        $context_json = ! empty( $context ) ? wp_json_encode( $context ) : null;
        
        // Insert log entry
        $wpdb->insert(
            $table_name,
            array(
                'log_level' => $level,
                'category' => sanitize_text_field( $category ),
                'message' => $message,
                'user_id' => $user_id,
                'user_name' => sanitize_text_field( $user_name ),
                'source' => sanitize_text_field( $source ),
                'context_json' => $context_json,
                'created_at' => current_time( 'mysql' ),
                'is_debug' => $is_debug
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
        );
    }
    
    /**
     * Log order-related events
     */
    public static function log_order( $action, $order_id, $user_id = null, $user_name = '', $context = array(), $source = 'admin' ) {
        $messages = array(
            'created' => 'Order created',
            'updated' => 'Order updated',
            'deleted' => 'Order deleted',
            'restored' => 'Order restored'
        );
        
        $message = isset( $messages[ $action ] ) ? $messages[ $action ] : 'Order action';
        $context['order_id'] = $order_id;
        $context['action'] = $action;
        
        self::log( 'INFO', 'orders', $message, $context, $source, $user_id, $user_name );
    }
    
    /**
     * Log authentication events
     */
    public static function log_auth( $action, $user_id = null, $user_name = '', $context = array(), $source = 'pwa' ) {
        $messages = array(
            'login' => 'User logged in',
            'logout' => 'User logged out',
            'failed' => 'Authentication failed'
        );
        
        $message = isset( $messages[ $action ] ) ? $messages[ $action ] : 'Auth action';
        $level = ( $action === 'failed' ) ? 'WARNING' : 'INFO';
        
        self::log( $level, 'auth', $message, $context, $source, $user_id, $user_name );
    }
    
    /**
     * Log API errors
     */
    public static function log_api_error( $endpoint, $error_message, $context = array(), $source = 'api' ) {
        $context['endpoint'] = $endpoint;
        self::log( 'ERROR', 'api', $error_message, $context, $source );
    }
    
    /**
     * Auto-cleanup old logs - called by cron
     */
    public static function cleanup_old_logs() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_logs';
        
        // Delete debug logs older than 24 hours
        $wpdb->query(
            "DELETE FROM {$table_name} 
             WHERE is_debug = 1 
             AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        // Delete normal logs older than 7 days
        $wpdb->query(
            "DELETE FROM {$table_name} 
             WHERE is_debug = 0 
             AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
    }
    
    /**
     * Auto-disable debug mode after 24 hours
     */
    public static function check_debug_timeout() {
        $debug_enabled = get_option( 'subsales_debug_logging_enabled', false );
        if ( ! $debug_enabled ) {
            return;
        }
        
        $debug_started = get_option( 'subsales_debug_logging_started', 0 );
        if ( $debug_started && ( time() - $debug_started ) > ( 24 * 3600 ) ) {
            update_option( 'subsales_debug_logging_enabled', false );
            delete_option( 'subsales_debug_logging_started' );
            self::log( 'INFO', 'system', 'Debug logging automatically disabled after 24 hours' );
        }
    }
    
    /**
     * Log order changes to edit history table with field-by-field comparison
     * 
     * @param int $order_db_id Database ID of the order
     * @param string $order_id Order ID (from order_data)
     * @param array $before_data Previous order_data
     * @param array $after_data New order_data
     * @param string $edit_type Type: 'update', 'delete', 'restore', 'create'
     * @param int $user_id WordPress user ID
     * @param string $user_name Display name
     * @param string $edit_reason Optional reason
     * @param string $source Source: 'admin' or 'pwa'
     * @return bool Success
     */
    public static function log_order_change( $order_db_id, $order_id, $before_data, $after_data, $edit_type, $user_id, $user_name, $edit_reason = '', $source = 'admin' ) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'ss_edit_history';
        
        // Ensure data is in array format
        if ( ! is_array( $before_data ) ) {
            $before_data = array();
        }
        if ( ! is_array( $after_data ) ) {
            $after_data = array();
        }
        
        // Build field-by-field comparison
        $changes = array();
        $summary_parts = array();
        
        // Define all possible order fields to compare
        // Note: Some fields have multiple names (PWA vs admin) - we check both
        $fields_to_compare = array(
            'customerName' => 'Customer Name',
            'customer' => 'Customer Name', // PWA uses 'customer'
            'address' => 'Address',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP',
            'phone' => 'Phone',
            'cellNumber' => 'Phone', // PWA uses 'cellNumber'
            'email' => 'Email',
            'deliveryDate' => 'Delivery Date',
            'driver' => 'Driver',
            'notes' => 'Notes',
            'donationAmount' => 'Donation Amount',
            'paymentMethod' => 'Payment Method',
            'checkNumber' => 'Check Number',
            'products' => 'Products',
            'strawberryQty' => 'Strawberry Qty',
            'blueberryQty' => 'Blueberry Qty',
            'raspberryQty' => 'Raspberry Qty'
        );
        
        // Compare simple fields
        // Track which labels we've already logged to avoid duplicates (e.g., 'customer' and 'customerName' both map to 'Customer Name')
        $logged_labels = array();
        
        foreach ( $fields_to_compare as $field => $label ) {
            if ( $field === 'products' ) continue; // Handle separately
            
            $before_val = isset( $before_data[ $field ] ) ? $before_data[ $field ] : '';
            $after_val = isset( $after_data[ $field ] ) ? $after_data[ $field ] : '';
            
            // Normalize for comparison
            if ( $field === 'donationAmount' ) {
                $before_val = floatval( $before_val );
                $after_val = floatval( $after_val );
            }
            
            if ( $before_val !== $after_val ) {
                // Skip if we already logged a change for this label (avoid duplicate entries)
                if ( isset( $logged_labels[ $label ] ) ) {
                    continue;
                }
                $logged_labels[ $label ] = true;
                
                $changes[] = array(
                    'field' => $field,
                    'label' => $label,
                    'before' => $before_val,
                    'after' => $after_val
                );
                
                // Build summary (limit to first 3 changes)
                if ( count( $summary_parts ) < 3 ) {
                    if ( $field === 'donationAmount' ) {
                        $summary_parts[] = sprintf( '%s: $%.2f → $%.2f', $label, $before_val, $after_val );
                    } else {
                        $before_preview = strlen( $before_val ) > 30 ? substr( $before_val, 0, 30 ) . '...' : $before_val;
                        $after_preview = strlen( $after_val ) > 30 ? substr( $after_val, 0, 30 ) . '...' : $after_val;
                        $summary_parts[] = sprintf( '%s: "%s" → "%s"', $label, $before_preview, $after_preview );
                    }
                }
            }
        }
        
        // Compare products array
        $before_products = isset( $before_data['products'] ) && is_array( $before_data['products'] ) ? $before_data['products'] : array();
        $after_products = isset( $after_data['products'] ) && is_array( $after_data['products'] ) ? $after_data['products'] : array();
        
        if ( $before_products !== $after_products ) {
            $changes[] = array(
                'field' => 'products',
                'label' => 'Products',
                'before' => $before_products,
                'after' => $after_products
            );
            
            // Add to summary
            if ( count( $summary_parts ) < 3 ) {
                $before_summary = array();
                $after_summary = array();
                
                foreach ( $before_products as $p ) {
                    if ( isset( $p['name'] ) && isset( $p['qty'] ) && intval( $p['qty'] ) > 0 ) {
                        $before_summary[] = $p['name'] . ' ×' . $p['qty'];
                    }
                }
                
                foreach ( $after_products as $p ) {
                    if ( isset( $p['name'] ) && isset( $p['qty'] ) && intval( $p['qty'] ) > 0 ) {
                        $after_summary[] = $p['name'] . ' ×' . $p['qty'];
                    }
                }
                
                $summary_parts[] = sprintf( 
                    'Products: [%s] → [%s]', 
                    implode( ', ', $before_summary ),
                    implode( ', ', $after_summary )
                );
            }
        }
        
        // Build changes summary
        $changes_summary = '';
        if ( $edit_type === 'delete' ) {
            $changes_summary = 'Order marked as deleted';
        } elseif ( $edit_type === 'restore' ) {
            $changes_summary = 'Order restored from deleted status';
        } else {
            $change_count = count( $changes );
            if ( $change_count === 0 ) {
                $changes_summary = 'No changes detected';
            } else {
                $changes_summary = sprintf( '%d field%s changed', $change_count, $change_count === 1 ? '' : 's' );
                if ( ! empty( $summary_parts ) ) {
                    $changes_summary .= ': ' . implode( '; ', $summary_parts );
                    if ( $change_count > 3 ) {
                        $changes_summary .= sprintf( ' (+%d more)', $change_count - 3 );
                    }
                }
            }
        }
        
        // Limit summary to 500 chars
        if ( strlen( $changes_summary ) > 500 ) {
            $changes_summary = substr( $changes_summary, 0, 497 ) . '...';
        }
        
        // Insert history record
        $result = $wpdb->insert(
            $history_table,
            array(
                'order_id' => $order_db_id,
                'edited_by_user_id' => $user_id,
                'edited_by_name' => $user_name,
                'edit_type' => $edit_type,
                'edit_reason' => $edit_reason,
                'changes_summary' => $changes_summary,
                'changes_detail' => wp_json_encode( array(
                    'before' => $before_data,
                    'after' => $after_data,
                    'changes' => $changes
                ) ),
                'source' => $source,
                'edited_at' => current_time( 'mysql' )
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
        
        // Also log to main logging system
        if ( $result !== false ) {
            self::log( 'INFO', 'orders', sprintf( 'Order %s: %s', $edit_type, $changes_summary ), array(
                'order_id' => $order_id,
                'order_db_id' => $order_db_id,
                'edit_type' => $edit_type,
                'changes_count' => count( $changes ),
                'reason' => $edit_reason
            ), $source, $user_id, $user_name );
        }
        
        return $result !== false;
    }
    
    /**
     * ====================================================================
     * PWA SESSION TRACKING
     * ====================================================================
     */
    
    /**
     * Start a new PWA session
     * 
     * @param string $session_id Unique session identifier (generated client-side)
     * @param int $user_id Optional user ID
     * @param string $user_name User display name
     * @param int $team_id Team ID
     * @param string $team_name Team name
     * @param array $session_data Additional session metadata
     * @return int|false Session record ID or false on failure
     */
    public static function start_pwa_session( $session_id, $user_id = null, $user_name = '', $team_id = null, $team_name = '', $session_data = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        
        // Get client info
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ) : '';
        $ip_address = self::get_client_ip();
        
        // Prepare session data JSON
        $session_data_json = ! empty( $session_data ) ? wp_json_encode( $session_data ) : null;
        
        $now = current_time( 'mysql' );
        
        // End any existing active sessions for this user (prevent duplicates)
        if ( $user_id ) {
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'ended',
                    'logout_at' => $now
                ),
                array(
                    'user_id' => $user_id,
                    'status' => 'active'
                ),
                array( '%s', '%s' ),
                array( '%d', '%s' )
            );
            
            self::log( 'DEBUG', 'pwa', 'Ended previous sessions for user', array(
                'user_id' => $user_id,
                'user_name' => $user_name
            ), 'pwa', $user_id, $user_name );
        }
        
        // Insert or update session
        $existing = $wpdb->get_var( $wpdb->prepare( 
            "SELECT id FROM {$table_name} WHERE session_id = %s", 
            $session_id 
        ) );
        
        if ( $existing ) {
            // Update existing session (reactivate if ended)
            $result = $wpdb->update(
                $table_name,
                array(
                    'user_id' => $user_id,
                    'user_name' => sanitize_text_field( $user_name ),
                    'team_id' => $team_id,
                    'team_name' => sanitize_text_field( $team_name ),
                    'user_agent' => $user_agent,
                    'ip_address' => $ip_address,
                    'login_at' => $now,
                    'last_heartbeat' => $now,
                    'logout_at' => null,
                    'session_data' => $session_data_json,
                    'status' => 'active'
                ),
                array( 'session_id' => $session_id ),
                array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
                array( '%s' )
            );
            
            self::log( 'DEBUG', 'pwa', 'PWA session reactivated', array(
                'session_id' => $session_id,
                'user_name' => $user_name,
                'team_name' => $team_name,
                'ip' => $ip_address
            ), 'pwa', $user_id, $user_name );
            
            return $existing;
        } else {
            // Create new session
            $result = $wpdb->insert(
                $table_name,
                array(
                    'session_id' => $session_id,
                    'user_id' => $user_id,
                    'user_name' => sanitize_text_field( $user_name ),
                    'team_id' => $team_id,
                    'team_name' => sanitize_text_field( $team_name ),
                    'user_agent' => $user_agent,
                    'ip_address' => $ip_address,
                    'login_at' => $now,
                    'last_heartbeat' => $now,
                    'session_data' => $session_data_json,
                    'status' => 'active'
                ),
                array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
            );
            
            self::log( 'DEBUG', 'pwa', 'PWA session started', array(
                'session_id' => $session_id,
                'user_name' => $user_name,
                'team_name' => $team_name,
                'ip' => $ip_address
            ), 'pwa', $user_id, $user_name );
            
            return $result !== false ? $wpdb->insert_id : false;
        }
    }
    
    /**
     * Update PWA session heartbeat
     * 
     * @param string $session_id Session identifier
     * @param array $activity_data Optional activity data (clicks, navigation, etc)
     * @param string $session_expiry Optional session expiry timestamp
     * @param array $gps Optional GPS location data
     * @return bool Success
     */
    public static function update_pwa_heartbeat( $session_id, $activity_data = array(), $session_expiry = null, $gps = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        $heartbeats_table = $wpdb->prefix . 'ss_pwa_heartbeats';
        
        $now = current_time( 'mysql' );
        
        // Get existing session data
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_data FROM {$table_name} WHERE session_id = %s",
            $session_id
        ), ARRAY_A );
        
        if ( ! $session ) {
            return false;
        }
        
        // Merge activity data into session data
        $session_data = $session['session_data'] ? json_decode( $session['session_data'], true ) : array();
        
        if ( ! isset( $session_data['activity'] ) ) {
            $session_data['activity'] = array();
        }
        
        if ( ! empty( $activity_data ) ) {
            $session_data['activity'][] = array_merge( $activity_data, array( 'timestamp' => $now ) );
            // Keep only last 50 activity events
            if ( count( $session_data['activity'] ) > 50 ) {
                $session_data['activity'] = array_slice( $session_data['activity'], -50 );
            }
        }
        
        // Prepare update data for sessions table
        $update_data = array(
            'last_heartbeat' => $now,
            'status' => 'active',
            'session_data' => wp_json_encode( $session_data )
        );
        $update_format = array( '%s', '%s', '%s' );
        
        // Add session expiry if provided
        if ( $session_expiry ) {
            // Convert ISO 8601 timestamp to MySQL datetime
            $expiry_timestamp = strtotime( $session_expiry );
            if ( $expiry_timestamp ) {
                $update_data['session_expiry'] = gmdate( 'Y-m-d H:i:s', $expiry_timestamp );
                $update_format[] = '%s';
            }
        }
        
        $result = $wpdb->update(
            $table_name,
            $update_data,
            array( 'session_id' => $session_id ),
            $update_format,
            array( '%s' )
        );
        
        // Check if heartbeats table exists before inserting
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$heartbeats_table}'" ) === $heartbeats_table;

        if ( ! $table_exists ) {
            // Rate-limit: only log this once per request/process so a persistently-missing
            // table doesn't spam wp_ss_logs on every single heartbeat.
            static $missing_table_logged = false;
            if ( ! $missing_table_logged ) {
                self::log( 'ERROR', 'pwa', 'PWA heartbeat GPS/activity history skipped - heartbeats table missing', array(
                    'session_id' => $session_id,
                    'table' => $heartbeats_table
                ), 'pwa' );
                $missing_table_logged = true;
            }
        } else {
            // Store heartbeat in history table with GPS data
            $heartbeat_data = array(
                'session_id' => $session_id,
                'heartbeat_at' => $now,
                'activity_data' => ! empty( $activity_data ) ? wp_json_encode( $activity_data ) : null
            );
            $heartbeat_format = array( '%s', '%s', '%s' );
            
            if ( $gps && isset( $gps['latitude'] ) && isset( $gps['longitude'] ) ) {
                $heartbeat_data['gps_latitude'] = floatval( $gps['latitude'] );
                $heartbeat_data['gps_longitude'] = floatval( $gps['longitude'] );
                $heartbeat_data['gps_accuracy'] = isset( $gps['accuracy'] ) ? floatval( $gps['accuracy'] ) : null;
                $heartbeat_format[] = '%f';
                $heartbeat_format[] = '%f';
                $heartbeat_format[] = '%f';
            }
            
            $insert_result = $wpdb->insert( $heartbeats_table, $heartbeat_data, $heartbeat_format );
            
            // Log insert errors for debugging
            if ( $insert_result === false && ! empty( $wpdb->last_error ) ) {
                error_log( 'Subsales: PWA heartbeat insert failed: ' . $wpdb->last_error );
                error_log( 'Subsales: Heartbeat data: ' . wp_json_encode( $heartbeat_data ) );

                self::log( 'ERROR', 'pwa', 'PWA heartbeat insert failed', array(
                    'session_id' => $session_id,
                    'db_error' => $wpdb->last_error,
                    'heartbeat_data' => $heartbeat_data
                ), 'pwa' );
            }
            
            // Clean up old heartbeats (keep only last 100 per session)
            // Use a simpler approach to avoid MySQL subquery limitations
            $old_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$heartbeats_table} 
                 WHERE session_id = %s 
                 ORDER BY heartbeat_at DESC 
                 LIMIT 100, 999999",
                $session_id
            ) );
            
            if ( ! empty( $old_ids ) ) {
                // WordPress 6.2+ requires each value to be passed as a separate parameter
                // Build the query safely without using prepare() with array
                $ids_str = implode( ',', array_map( 'absint', $old_ids ) );
                if ( ! empty( $ids_str ) ) {
                    $wpdb->query( "DELETE FROM {$heartbeats_table} WHERE id IN ({$ids_str})" );
                }
            }
        }
        
        return $result !== false;
    }
    
    /**
     * End PWA session (logout)
     * 
     * @param string $session_id Session identifier
     * @return bool Success
     */
    public static function end_pwa_session( $session_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        
        $now = current_time( 'mysql' );
        
        // Get session info for logging
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id, user_name, team_name FROM {$table_name} WHERE session_id = %s",
            $session_id
        ), ARRAY_A );
        
        $result = $wpdb->update(
            $table_name,
            array(
                'logout_at' => $now,
                'status' => 'ended'
            ),
            array( 'session_id' => $session_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );
        
        if ( $session ) {
            self::log( 'DEBUG', 'pwa', 'PWA session ended', array(
                'session_id' => $session_id,
                'user_name' => $session['user_name'],
                'team_name' => $session['team_name']
            ), 'pwa', $session['user_id'], $session['user_name'] );
        }
        
        return $result !== false;
    }
    
    /**
     * Get active PWA sessions
     * 
     * @param int $limit Optional limit
     * @return array Active sessions
     */
    public static function get_active_pwa_sessions( $limit = 100 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        
        // Consider sessions active if:
        // 1. Not logged out (logout_at IS NULL)
        // 2. Heartbeat within last 5 minutes
        // Use current_time() to match WordPress timezone used when storing
        $five_min_ago = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        
        $sessions = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE logout_at IS NULL
             AND last_heartbeat >= %s
             ORDER BY last_heartbeat DESC
             LIMIT %d",
            $five_min_ago,
            $limit
        ), ARRAY_A );
        
        return $sessions;
    }
    
    /**
     * Get count of active PWA sessions
     * 
     * @return int Number of active sessions
     */
    public static function get_active_pwa_sessions_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        
        // Consider sessions active if:
        // 1. Not logged out (logout_at IS NULL)
        // 2. Heartbeat within last 5 minutes
        $five_min_ago = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE logout_at IS NULL
             AND last_heartbeat >= %s",
            $five_min_ago
        ) );
        
        return intval( $count );
    }
    
    /**
     * Get all PWA sessions (with pagination)
     * 
     * @param array $args Query arguments
     * @return array Sessions
     */
    public static function get_pwa_sessions( $args = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        
        $defaults = array(
            'status' => 'all',
            'team_id' => null,
            'user_id' => null,
            'limit' => 100,
            'offset' => 0,
            'orderby' => 'last_heartbeat',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args( $args, $defaults );
        
        $where = array( '1=1' );
        
        if ( $args['status'] !== 'all' ) {
            $where[] = $wpdb->prepare( 'status = %s', $args['status'] );
        }
        
        if ( $args['team_id'] ) {
            $where[] = $wpdb->prepare( 'team_id = %d', $args['team_id'] );
        }
        
        if ( $args['user_id'] ) {
            $where[] = $wpdb->prepare( 'user_id = %d', $args['user_id'] );
        }
        
        $where_sql = implode( ' AND ', $where );
        $orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );
        
        $sessions = $wpdb->get_results(
            "SELECT * FROM {$table_name} 
             WHERE {$where_sql} 
             ORDER BY {$orderby}
             LIMIT {$args['limit']} OFFSET {$args['offset']}",
            ARRAY_A
        );
        
        return $sessions;
    }
    
    /**
     * Cleanup stale PWA sessions
     * Marks sessions as idle if no heartbeat for 5 minutes
     * 
     * @return int Number of sessions marked idle
     */
    public static function cleanup_stale_pwa_sessions() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        
        // Use current_time() to match WordPress timezone
        $five_min_ago = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 300 );
        
        $result = $wpdb->query( $wpdb->prepare(
            "UPDATE {$table_name} 
             SET status = 'idle' 
             WHERE status = 'active' 
             AND last_heartbeat < %s
             AND logout_at IS NULL",
            $five_min_ago
        ) );
        
        if ( $result > 0 ) {
            self::log( 'DEBUG', 'pwa', "Marked {$result} stale PWA sessions as idle", array(), 'cron' );
        }
        
        return $result;
    }
    
    /**
     * Get heartbeat history for a session
     * 
     * @param string $session_id Session identifier
     * @param int $limit Maximum number of heartbeats to return
     * @return array Heartbeat history
     */
    public static function get_session_heartbeats( $session_id, $limit = 100 ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_heartbeats';
        
        $heartbeats = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE session_id = %s 
             ORDER BY heartbeat_at DESC 
             LIMIT %d",
            $session_id,
            $limit
        ), ARRAY_A );
        
        return $heartbeats ? $heartbeats : array();
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private static function get_client_ip() {
        $ip = '';
        
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return sanitize_text_field( $ip );
    }
    
    // ========================================
    // Campaign Management Methods
    // ========================================
    
    /**
     * Get all campaigns
     * 
     * @param string $status Filter by status (active, inactive, completed, or 'all')
     * @return array Campaign records
     */
    /**
     * @param string          $status    'all' or a status value.
     * @param int|null|string $season_id Defaults to the current season. Pass
     *                                   'all' for every season (history views).
     */
    public static function get_campaigns( $status = 'all', $season_id = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';

        $where = array( '1=1' );

        if ( $status !== 'all' ) {
            $where[] = $wpdb->prepare( 'status = %s', $status );
        }

        // Sales days belong to a season. Without this the campaigns list showed
        // every season's dates forever.
        if ( 'all' !== $season_id ) {
            $season_id = ( null === $season_id )
                ? Subsales_Database::current_season_id()
                : intval( $season_id );
            if ( $season_id ) {
                $where[] = $wpdb->prepare( 'season_id = %d', $season_id );
            }
        }

        $campaigns = $wpdb->get_results(
            "SELECT * FROM {$table_name} WHERE " . implode( ' AND ', $where ) . " ORDER BY campaign_date ASC",
            ARRAY_A
        );

        return $campaigns ? $campaigns : array();
    }
    
    /**
     * Get campaign by ID
     * 
     * @param int $campaign_id Campaign ID
     * @return array|null Campaign record
     */
    public static function get_campaign( $campaign_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';
        
        $campaign = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $campaign_id
        ), ARRAY_A );
        
        return $campaign;
    }
    
    /**
     * Get campaign by date
     * 
     * @param string $date Date in Y-m-d format
     * @return array|null Campaign record
     */
    public static function get_campaign_by_date( $date, $season_id = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';

        // Season-scoped: dates recur across seasons, and unscoped this matched a
        // retired season's sale day. The calendar would then show the date empty
        // while toggling it silently reactivated last year's campaign.
        if ( null === $season_id ) {
            $season_id = self::current_season_id();
        }

        $campaign = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE campaign_date = %s AND season_id = %d",
            $date,
            $season_id
        ), ARRAY_A );
        
        return $campaign;
    }
    
    /**
     * Create or update campaign
     * 
     * @param array $data Campaign data (id, campaign_date, campaign_name, notes, status)
     * @return int|false Campaign ID on success, false on failure
     */
    public static function save_campaign( $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';
        
        // season_id was missing here entirely, so every sales day created from the
        // calendar landed at season_id = 0 - invisible to get_season_counts() and
        // never scoped to the season it belongs to.
        $season_id = isset( $data['season_id'] )
            ? intval( $data['season_id'] )
            : Subsales_Database::current_season_id();

        $campaign_data = array(
            'campaign_date' => $data['campaign_date'],
            'campaign_name' => isset( $data['campaign_name'] ) ? $data['campaign_name'] : '',
            'notes' => isset( $data['notes'] ) ? $data['notes'] : '',
            'status' => isset( $data['status'] ) ? $data['status'] : 'active',
            'season_id' => $season_id,
        );

        if ( isset( $data['id'] ) && $data['id'] > 0 ) {
            // Update existing campaign
            $result = $wpdb->update(
                $table_name,
                $campaign_data,
                array( 'id' => $data['id'] ),
                array( '%s', '%s', '%s', '%s', '%d' ),
                array( '%d' )
            );

            return $result !== false ? $data['id'] : false;
        } else {
            // Create new campaign
            $result = $wpdb->insert( $table_name, $campaign_data, array( '%s', '%s', '%s', '%s', '%d' ) );
            return $result ? $wpdb->insert_id : false;
        }
    }
    
    /**
     * Delete campaign
     *
     * Refuses while anything still points at the sale day. Signups were the only
     * thing checked here, so deleting from the Sales Days tab could orphan driver
     * assignments (ss_team_campaigns) and Square checkout attempts
     * (ss_payment_attempts) - both carry a campaign_id. ss_orders does not link to
     * a sale day at all (orders hang off teams), so there is nothing to check there.
     *
     * @param int $campaign_id Campaign ID
     * @return true|string True on success, otherwise the plain-language reason it
     *                     was refused (safe to show a volunteer parent as-is)
     */
    public static function delete_campaign( $campaign_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';
        $campaign_id = intval( $campaign_id );

        // Each row: table, plain-language reason. First hit wins - the admin only
        // needs one thing to go fix, not all three.
        $blockers = array(
            array(
                'table'  => $wpdb->prefix . 'ss_signups',
                'reason' => '%d seller(s) are already signed up for this sale day.',
            ),
            array(
                'table'  => $wpdb->prefix . 'ss_team_campaigns',
                'reason' => '%d team(s) already have a driver set for this sale day.',
            ),
            array(
                'table'  => $wpdb->prefix . 'ss_payment_attempts',
                'reason' => '%d card payment(s) are attached to this sale day.',
            ),
        );

        foreach ( $blockers as $blocker ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$blocker['table']} WHERE campaign_id = %d",
                $campaign_id
            ) );

            if ( $count > 0 ) {
                return sprintf( $blocker['reason'], $count );
            }
        }

        $result = $wpdb->delete( $table_name, array( 'id' => $campaign_id ), array( '%d' ) );

        if ( false === $result ) {
            return 'This sale day could not be deleted. Please try again.';
        }

        return true;
    }
    
    /**
     * Toggle campaign status
     * 
     * @param int $campaign_id Campaign ID
     * @param string $new_status New status (active, inactive, completed)
     * @return bool Success
     */
    public static function toggle_campaign_status( $campaign_id, $new_status ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';
        
        $result = $wpdb->update(
            $table_name,
            array( 'status' => $new_status ),
            array( 'id' => $campaign_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        return $result !== false;
    }
    
    // ========================================
    // Signup Management Methods
    // ========================================
    
    /**
     * Get signups with optional filters
     * 
     * @param array $filters Filter options (user_id, team_id, campaign_id, status)
     * @return array Signup records with related data
     */
    public static function get_signups( $filters = array() ) {
        global $wpdb;
        $signups_table = $wpdb->prefix . 'ss_signups';
        $users_table = $wpdb->prefix . 'ss_team_members';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        
        $where = array( '1=1' );
        
        if ( ! empty( $filters['user_id'] ) ) {
            $where[] = $wpdb->prepare( 's.user_id = %d', $filters['user_id'] );
        }
        
        if ( ! empty( $filters['team_id'] ) ) {
            $where[] = $wpdb->prepare( 's.team_id = %d', $filters['team_id'] );
        }
        
        if ( ! empty( $filters['campaign_id'] ) ) {
            $where[] = $wpdb->prepare( 's.campaign_id = %d', $filters['campaign_id'] );
        }
        
        if ( ! empty( $filters['status'] ) ) {
            $where[] = $wpdb->prepare( 's.status = %s', $filters['status'] );
        }

        // Exclude drivers (is_driver=1) from sales/member-oriented queries
        if ( ! empty( $filters['exclude_drivers'] ) ) {
            $where[] = 's.is_driver = 0';
        }

        $where_sql = implode( ' AND ', $where );
        
        $signups = $wpdb->get_results(
            "SELECT s.*, 
                    u.name as user_name, u.phone as user_phone, u.email as user_email,
                    t.name as team_name, t.access_code as team_code,
                    c.campaign_date, c.campaign_name
             FROM {$signups_table} s
             LEFT JOIN {$users_table} u ON s.user_id = u.id
             LEFT JOIN {$teams_table} t ON s.team_id = t.id
             LEFT JOIN {$campaigns_table} c ON s.campaign_id = c.id
             WHERE {$where_sql}
             ORDER BY c.campaign_date ASC, t.name ASC, u.name ASC",
            ARRAY_A
        );
        
        return $signups ? $signups : array();
    }
    
    /**
     * Get signup by ID
     * 
     * @param int $signup_id Signup ID
     * @return array|null Signup record
     */
    public static function get_signup( $signup_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_signups';
        
        $signup = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $signup_id
        ), ARRAY_A );
        
        return $signup;
    }
    
    /**
     * Create or update signup
     * 
     * @param array $data Signup data (user_id, team_id, campaign_id, is_driver, notes, status)
     * @return int|false Signup ID on success, false on failure
     */
    public static function save_signup( $data ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_signups';
        
        // Check if user is already signed up for this team+date
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$table_name} 
             WHERE user_id = %d AND team_id = %d AND campaign_id = %d",
            $data['user_id'],
            $data['team_id'],
            $data['campaign_id']
        ), ARRAY_A );
        
        $signup_data = array(
            'user_id' => $data['user_id'],
            'team_id' => $data['team_id'],
            'campaign_id' => $data['campaign_id'],
            'is_driver' => isset( $data['is_driver'] ) ? $data['is_driver'] : 0,
            'notes' => isset( $data['notes'] ) ? $data['notes'] : '',
            'status' => isset( $data['status'] ) ? $data['status'] : 'active',
        );
        
        if ( $existing ) {
            // Update existing signup
            $result = $wpdb->update(
                $table_name,
                $signup_data,
                array( 'id' => $existing['id'] ),
                array( '%d', '%d', '%d', '%d', '%s', '%s' ),
                array( '%d' )
            );
            
            return $result !== false ? $existing['id'] : false;
        } else {
            // Create new signup
            $result = $wpdb->insert( $table_name, $signup_data, array( '%d', '%d', '%d', '%d', '%s', '%s' ) );
            return $result ? $wpdb->insert_id : false;
        }
    }
    
    /**
     * Delete signup
     * 
     * @param int $signup_id Signup ID
     * @return bool Success
     */
    public static function delete_signup( $signup_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_signups';
        
        $result = $wpdb->delete( $table_name, array( 'id' => $signup_id ), array( '%d' ) );
        return $result !== false;
    }
    
    /**
     * Set driver for a team+campaign
     * Ensures only one driver per team+campaign by clearing others
     * 
     * @param int $user_id User ID to set as driver
     * @param int $team_id Team ID
     * @param int $campaign_id Campaign ID
     * @return bool Success
     */
    public static function set_driver( $user_id, $team_id, $campaign_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_signups';
        
        // Start transaction
        $wpdb->query( 'START TRANSACTION' );
        
        // Clear any existing driver for this team+campaign
        $wpdb->update(
            $table_name,
            array( 'is_driver' => 0 ),
            array( 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
            array( '%d' ),
            array( '%d', '%d' )
        );
        
        // Set new driver
        $result = $wpdb->update(
            $table_name,
            array( 'is_driver' => 1 ),
            array( 'user_id' => $user_id, 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
            array( '%d' ),
            array( '%d', '%d', '%d' )
        );
        
        if ( $result !== false ) {
            $wpdb->query( 'COMMIT' );
            return true;
        } else {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
    }
    
    /**
     * Get signups grouped by team and campaign
     * Useful for displaying rosters
     * 
     * @param int $campaign_id Campaign ID
     * @return array Grouped signups
     */
    public static function get_signups_by_team_campaign( $campaign_id = null ) {
        global $wpdb;
        $signups_table = $wpdb->prefix . 'ss_signups';
        $users_table = $wpdb->prefix . 'ss_team_members';
        $teams_table = $wpdb->prefix . 'ss_teams';
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';
        
        $where = 's.status = "active"';
        if ( $campaign_id ) {
            $where .= $wpdb->prepare( ' AND s.campaign_id = %d', $campaign_id );
        }
        
        $signups = $wpdb->get_results(
            "SELECT s.*, 
                    u.name as user_name, u.phone as user_phone,
                    t.name as team_name,
                    c.campaign_date, c.campaign_name
             FROM {$signups_table} s
             LEFT JOIN {$users_table} u ON s.user_id = u.id
             LEFT JOIN {$teams_table} t ON s.team_id = t.id
             LEFT JOIN {$campaigns_table} c ON s.campaign_id = c.id
             WHERE {$where}
             ORDER BY c.campaign_date ASC, t.name ASC, s.is_driver DESC, u.name ASC",
            ARRAY_A
        );
        
        // Group by campaign and team
        $grouped = array();
        foreach ( $signups as $signup ) {
            $date = $signup['campaign_date'];
            $team = $signup['team_name'];
            
            if ( ! isset( $grouped[$date] ) ) {
                $grouped[$date] = array();
            }
            
            if ( ! isset( $grouped[$date][$team] ) ) {
                $grouped[$date][$team] = array(
                    'team_id' => $signup['team_id'],
                    'campaign_id' => $signup['campaign_id'],
                    'campaign_name' => $signup['campaign_name'],
                    'members' => array(),
                    'driver' => null,
                );
            }
            
            if ( $signup['is_driver'] ) {
                $grouped[$date][$team]['driver'] = array(
                    'id' => $signup['user_id'],
                    'name' => $signup['user_name'],
                    'phone' => $signup['user_phone'],
                );
            } else {
                $grouped[$date][$team]['members'][] = array(
                    'id' => $signup['user_id'],
                    'name' => $signup['user_name'],
                    'phone' => $signup['user_phone'],
                );
            }
        }
        
        return $grouped;
    }
    
    
    
    

    // ============================================================
    // Canonical Member / Roster Access (single source of truth)
    //
    // Every feature should read/write team-member & signup data
    // through these methods instead of hand-writing SQL.
    // `ss_signups.is_driver` is authoritative for driver status;
    // `ss_team_members.role` is kept in sync for display only.
    // ============================================================

    /**
     * Look up a member by phone for the KID SIGNUP path only. Roster-preload
     * enforcement: this never creates a new member - an unrecognized phone
     * is a hard rejection, not a fabricated record. On a match, syncs name
     * and reactivates the member (status='active') so a kid deactivated at
     * the end of a prior season isn't silently locked out after
     * re-signing-up this season.
     *
     * NOT for driver signup - a driver's own phone is intentionally not
     * roster-gated (see get_or_create_driver_by_phone()). The security gate
     * for driver signup is knowing the CHILD's phone number and being
     * restricted to days the child actually signed up for (enforced in
     * Subsales_Driver_Signup::rest_driver_signup()); the driver's own phone
     * is just contact info, not a credential that must be pre-loaded.
     *
     * The roster-import admin tool (which IS allowed to insert brand-new
     * members) has its own separate upsert - this method must stay
     * rejection-only since it backs a public, unauthenticated REST endpoint.
     *
     * @param string $name  Member name
     * @param string $phone 10-digit phone (caller normalizes)
     * @return int|WP_Error Member ID, or WP_Error if the phone isn't on record
     */
    public static function get_active_member_by_phone( $name, $phone ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';

        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$members_table} WHERE phone = %s",
            $phone
        ), ARRAY_A );

        if ( ! $member ) {
            return new WP_Error(
                'phone_not_registered',
                "We couldn't find that phone number. Please contact an admin to be added to the roster.",
                array( 'status' => 404 )
            );
        }

        $member_id = intval( $member['id'] );
        $update = array( 'status' => 'active' );
        $format = array( '%s' );
        if ( $name !== '' ) { $update['name'] = $name; $format[] = '%s'; }
        $wpdb->update( $members_table, $update, array( 'id' => $member_id ), $format, array( '%d' ) );

        return $member_id;
    }

    /**
     * Get-or-create a member by phone for the DRIVER SIGNUP path. Unlike
     * get_active_member_by_phone(), this MAY create a brand-new member -
     * drivers (usually parents) are not expected to be pre-loaded on the
     * roster. The security gate for driver signup happens upstream, in
     * Subsales_Driver_Signup::rest_driver_signup(): the caller must already
     * know the CHILD's phone number, and the selected team/day pairs are
     * re-validated against that child's actual signups before this is ever
     * called - so an unrecognized driver phone is safe to create here.
     *
     * @param string $name  Driver name
     * @param string $phone 10-digit phone (caller normalizes)
     * @return int|false Member ID, or false on failure
     */
    public static function get_or_create_driver_by_phone( $name, $phone ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';

        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$members_table} WHERE phone = %s",
            $phone
        ), ARRAY_A );

        if ( $member ) {
            $member_id = intval( $member['id'] );
            $update = array( 'status' => 'active', 'role' => 'driver' );
            $format = array( '%s', '%s' );
            if ( $name !== '' ) { $update['name'] = $name; $format[] = '%s'; }
            $wpdb->update( $members_table, $update, array( 'id' => $member_id ), $format, array( '%d' ) );
            return $member_id;
        }

        $wpdb->insert( $members_table, array(
            'name'   => $name,
            'phone'  => $phone,
            'role'   => 'driver',
            'status' => 'active',
        ), array( '%s', '%s', '%s', '%s' ) );

        return $wpdb->insert_id ? intval( $wpdb->insert_id ) : false;
    }

    /**
     * Get-or-create a team by name (case-insensitive). Preserves the
     * original casing of an existing team.
     *
     * @param string $team_name
     * @return array { id, name }
     */
    public static function get_or_create_team( $team_name ) {
        global $wpdb;
        $teams_table = $wpdb->prefix . 'ss_teams';
        $season_id = Subsales_Database::current_season_id();

        // Scoped to the current season only - a same-named team from a
        // prior (now-inactive) season must never be matched here, or a kid
        // signing up this year would silently get attached to last year's
        // team instead of a fresh one for the current season.
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$teams_table} WHERE LOWER(name) = LOWER(%s) AND season_id = %d",
            $team_name, $season_id
        ), ARRAY_A );

        if ( $team ) {
            return array( 'id' => intval( $team['id'] ), 'name' => $team['name'] );
        }

        $access_code = strtoupper( substr( md5( $team_name . time() ), 0, 6 ) );
        $wpdb->insert( $teams_table, array(
            'name'        => $team_name,
            'access_code' => $access_code,
            'status'      => 'active',
            'season_id'   => $season_id,
        ), array( '%s', '%s', '%s', '%d' ) );

        return array( 'id' => intval( $wpdb->insert_id ), 'name' => $team_name );
    }

    /**
     * Idempotently link a member to a team (ss_user_teams).
     */
    public static function link_member_to_team( $user_id, $team_id ) {
        global $wpdb;
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$user_teams_table} WHERE user_id = %d AND team_id = %d",
            $user_id, $team_id
        ) );

        if ( ! $exists ) {
            $wpdb->insert( $user_teams_table, array(
                'user_id' => $user_id,
                'team_id' => $team_id,
            ), array( '%d', '%d' ) );
        }
    }

    /**
     * Single write path for signups — used by BOTH kid signup and driver
     * signup. Creates/links the member and team, then creates a signup per
     * campaign.
     *
     * Sales (is_driver=false) preserves the original kid-signup semantics:
     * a duplicate signup (any status) for the same user/team/campaign is
     * skipped.
     *
     * Driver (is_driver=true) ensures an active driver signup row, makes the
     * member the sole driver for that team+campaign (set_driver), and records
     * the driver name on ss_team_campaigns.
     *
     * @param array $args { name, phone, team_name|team_id, campaign_ids[], is_driver }
     * @return array|WP_Error { user_id, team_id, team_name, signups_created, skipped[], is_driver }
     */
    public static function register_member_signups( $args ) {
        global $wpdb;

        $name         = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
        $phone        = isset( $args['phone'] ) ? preg_replace( '/\D/', '', $args['phone'] ) : '';
        $campaign_ids = isset( $args['campaign_ids'] ) ? array_map( 'intval', (array) $args['campaign_ids'] ) : array();
        $is_driver    = ! empty( $args['is_driver'] );

        if ( $name === '' || $phone === '' || empty( $campaign_ids ) ) {
            return new WP_Error( 'missing_params', 'Name, phone, and at least one campaign are required.', array( 'status' => 400 ) );
        }

        // Resolve the team (by id or get-or-create by name)
        if ( ! empty( $args['team_id'] ) ) {
            $team_id     = intval( $args['team_id'] );
            $teams_table = $wpdb->prefix . 'ss_teams';
            $team_name   = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$teams_table} WHERE id = %d", $team_id ) );
            if ( ! $team_name ) {
                return new WP_Error( 'invalid_team', 'Team not found.', array( 'status' => 404 ) );
            }
        } elseif ( ! empty( $args['team_name'] ) ) {
            $team      = self::get_or_create_team( sanitize_text_field( $args['team_name'] ) );
            $team_id   = $team['id'];
            $team_name = $team['name'];
        } else {
            return new WP_Error( 'missing_team', 'A team is required.', array( 'status' => 400 ) );
        }

        // Resolve the member. Kid signup: roster-preload enforced, an
        // unrecognized phone is rejected. Driver signup: the phone is NOT
        // roster-gated - the driver's own phone is just contact info, not a
        // credential, and the real security check (knowing the child's
        // phone, restricted to the child's actual signups) already happened
        // upstream in Subsales_Driver_Signup::rest_driver_signup() before
        // this was ever called.
        if ( $is_driver ) {
            $user_id = self::get_or_create_driver_by_phone( $name, $phone );
            if ( ! $user_id ) {
                return new WP_Error( 'member_failed', 'Could not create the driver.', array( 'status' => 500 ) );
            }
        } else {
            $user_id = self::get_active_member_by_phone( $name, $phone );
        }
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        self::link_member_to_team( $user_id, $team_id );

        $signups_table   = $wpdb->prefix . 'ss_signups';
        $signups_created = 0;
        $skipped         = array();

        // A member can sign up with different teams on different days, but
        // never two teams for the SAME day. Checked up front, for every
        // requested campaign at once, before any writes happen below - so a
        // conflict on one selected day rejects the whole call cleanly
        // instead of leaving earlier days already written while reporting
        // total failure.
        $campaign_id_list = implode( ',', array_map( 'intval', $campaign_ids ) );
        $cross_team = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$signups_table}
             WHERE user_id = %d AND team_id != %d AND status = 'active'
             AND campaign_id IN ({$campaign_id_list})",
            $user_id, $team_id
        ) );
        if ( $cross_team ) {
            return new WP_Error(
                'already_signed_up_different_team',
                "You're already signed up with a different team today — contact your team lead.",
                array( 'status' => 409 )
            );
        }

        foreach ( $campaign_ids as $campaign_id ) {
            $existing = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, status FROM {$signups_table}
                 WHERE user_id = %d AND team_id = %d AND campaign_id = %d",
                $user_id, $team_id, $campaign_id
            ), ARRAY_A );

            if ( ! $is_driver ) {
                // Kid-signup semantics: skip if a signup row already exists
                if ( $existing ) {
                    $skipped[] = $campaign_id;
                    continue;
                }
                $inserted = $wpdb->insert( $signups_table, array(
                    'user_id'    => $user_id,
                    'team_id'    => $team_id,
                    'campaign_id'=> $campaign_id,
                    'status'     => 'active',
                    'created_at' => current_time( 'mysql' ),
                ), array( '%d', '%d', '%d', '%s', '%s' ) );

                if ( $inserted ) {
                    $signups_created++;
                } else {
                    subsales_log( 'ERROR', 'signup', 'Failed to insert signup', array(
                        'user_id' => $user_id, 'team_id' => $team_id, 'campaign_id' => $campaign_id,
                        'error' => $wpdb->last_error,
                    ) );
                }
                continue;
            }

            // Driver-signup semantics: ensure an active row exists, then make sole driver
            if ( ! $existing ) {
                $wpdb->insert( $signups_table, array(
                    'user_id'    => $user_id,
                    'team_id'    => $team_id,
                    'campaign_id'=> $campaign_id,
                    'is_driver'  => 1,
                    'status'     => 'active',
                    'created_at' => current_time( 'mysql' ),
                ), array( '%d', '%d', '%d', '%d', '%s', '%s' ) );
            } else {
                $wpdb->update( $signups_table,
                    array( 'status' => 'active' ),
                    array( 'user_id' => $user_id, 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
                    array( '%s' ), array( '%d', '%d', '%d' )
                );
            }

            // Make this member the sole driver for the team+campaign, and record the name
            self::set_driver( $user_id, $team_id, $campaign_id );
            self::upsert_team_campaign_driver( $team_id, $campaign_id, $name );
            $signups_created++;
        }

        return array(
            'user_id'         => $user_id,
            'team_id'         => $team_id,
            'team_name'       => $team_name,
            'signups_created' => $signups_created,
            'skipped'         => $skipped,
            'is_driver'       => $is_driver,
        );
    }

    /**
     * Record/refresh the driver name on ss_team_campaigns for a team+campaign.
     */
    public static function upsert_team_campaign_driver( $team_id, $campaign_id, $driver_name ) {
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
            $wpdb->update( $team_campaigns_table, $fields,
                array( 'team_id' => $team_id, 'campaign_id' => $campaign_id ),
                array( '%s', '%s', '%s' ), array( '%d', '%d' ) );
        } else {
            $wpdb->insert( $team_campaigns_table,
                array_merge( array( 'team_id' => $team_id, 'campaign_id' => $campaign_id ), $fields ),
                array( '%d', '%d', '%s', '%s', '%s' ) );
        }
    }

    /**
     * Canonical roster for a single team+campaign (active signups only),
     * split into sales members and the (single) driver.
     *
     * @return array { members: [ {id,name,phone}, ... ], driver: {id,name,phone}|null }
     */
    public static function get_campaign_team_roster( $team_id, $campaign_id ) {
        global $wpdb;
        $signups_table = $wpdb->prefix . 'ss_signups';
        $members_table = $wpdb->prefix . 'ss_team_members';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.user_id, s.is_driver, m.name, m.phone
             FROM {$signups_table} s
             INNER JOIN {$members_table} m ON s.user_id = m.id
             WHERE s.team_id = %d AND s.campaign_id = %d AND s.status = 'active'
             ORDER BY s.is_driver ASC, m.name ASC",
            $team_id, $campaign_id
        ), ARRAY_A );

        $roster = array( 'members' => array(), 'driver' => null );
        foreach ( (array) $rows as $r ) {
            $person = array(
                'id'    => intval( $r['user_id'] ),
                'name'  => $r['name'],
                'phone' => $r['phone'],
            );
            if ( intval( $r['is_driver'] ) === 1 ) {
                $roster['driver'] = $person;
            } else {
                $roster['members'][] = $person;
            }
        }
        return $roster;
    }

    /**
     * Sales members for a team+campaign. Excludes the driver by default.
     *
     * @return array List of { id, name, phone }
     */
    public static function get_campaign_team_members( $team_id, $campaign_id, $include_driver = false ) {
        $roster  = self::get_campaign_team_roster( $team_id, $campaign_id );
        $members = $roster['members'];
        if ( $include_driver && $roster['driver'] ) {
            $members[] = $roster['driver'];
        }
        return $members;
    }

    /**
     * Count of sales members (drivers excluded) for a team+campaign.
     */
    public static function get_campaign_team_member_count( $team_id, $campaign_id ) {
        return count( self::get_campaign_team_members( $team_id, $campaign_id, false ) );
    }

    /**
     * The driver for a team+campaign, or null.
     */
    public static function get_campaign_team_driver( $team_id, $campaign_id ) {
        $roster = self::get_campaign_team_roster( $team_id, $campaign_id );
        return $roster['driver'];
    }

    /**
     * Driver money-accountability tally for a team on a given day.
     *
     * Single source of truth for "how much money should the driver have": per
     * seller and team cash/check/donation/total, item counts, and each seller's
     * order list for drill-down. Includes sellers with $0 so the driver sees
     * every child. Uses the same money formula as the EOD tally: per order
     * total = SUM(qty * price) + donation, bucketed cash/check by payment
     * method. Includes ALL of the day's orders regardless of tally state.
     *
     * @param int         $team_id
     * @param string|null $date  Y-m-d; defaults to site "today".
     * @return array|WP_Error
     */
    public static function get_team_tally( $team_id, $date = null ) {
        global $wpdb;

        $team_id = intval( $team_id );
        if ( ! $team_id ) {
            return new WP_Error( 'missing_team', 'A team is required.', array( 'status' => 400 ) );
        }
        if ( empty( $date ) ) {
            $date = date_i18n( 'Y-m-d', current_time( 'timestamp' ) );
        }

        $teams_table  = $wpdb->prefix . 'ss_teams';
        $orders_table = $wpdb->prefix . 'ss_orders';

        $team_name   = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$teams_table} WHERE id = %d", $team_id ) );
        $campaign    = self::get_campaign_by_date( $date );
        $campaign_id = $campaign ? intval( $campaign['id'] ) : 0;

        // Product label map (id => name) from the canonical products config
        $product_names = array();
        $products_out  = array();
        if ( function_exists( 'order_sync_get_products_config' ) ) {
            foreach ( (array) order_sync_get_products_config() as $p ) {
                if ( ! isset( $p['id'] ) ) { continue; }
                $pid   = (string) $p['id'];
                $pname = isset( $p['name'] ) ? $p['name'] : $pid;
                $product_names[ $pid ] = $pname;
                $products_out[] = array( 'id' => $pid, 'name' => $pname );
            }
        }

        // Seed sellers from the roster so every child appears (even at $0)
        $sellers = array(); // keyed by seller id (string)
        $seed_seller = function( $id, $name ) use ( &$sellers ) {
            $key = (string) $id;
            if ( ! isset( $sellers[ $key ] ) ) {
                $sellers[ $key ] = array(
                    'user_id'      => intval( $id ) ? intval( $id ) : $id,
                    'name'         => $name !== '' ? $name : 'Unknown',
                    'cash'         => 0.0,
                    'check'        => 0.0,
                    'donation'     => 0.0,
                    'digital'      => 0.0,
                    'total'        => 0.0,
                    'sales_total'  => 0.0,
                    'order_count'  => 0,
                    'checks_count' => 0,
                    'items'        => array(),
                    'orders'       => array(),
                );
            }
            return $key;
        };

        if ( $campaign_id ) {
            foreach ( self::get_campaign_team_members( $team_id, $campaign_id ) as $m ) {
                $seed_seller( $m['id'], $m['name'] );
            }
        }

        // All of the day's team orders (any tally state), not deleted.
        // Matches the "today" comparison used by Subsales_Orders::get_orders().
        // Season-scoped internally (every caller means "right now", which
        // always means the current season) rather than threaded through
        // this function's signature.
        $current_season_id = Subsales_Database::current_season_id();
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$orders_table}
             WHERE team_id = %d AND DATE(created_at) = %s AND deleted = 0 AND season_id = %d
             ORDER BY created_at ASC",
            $team_id, $date, $current_season_id
        ), ARRAY_A );

        $totals = array(
            'cash' => 0.0, 'check' => 0.0, 'donation' => 0.0, 'digital' => 0.0,
            'total' => 0.0, 'sales_total' => 0.0,
            'order_count' => 0, 'checks_count' => 0, 'items' => array(),
        );

        foreach ( (array) $orders as $order ) {
            $od = Subsales_Order_Helper::decode_order_data( $order );
            if ( ! is_array( $od ) ) { $od = array(); }

            // Seller attribution: entered_by_id, else the order's user_id column.
            // NOTE: driver edits never change these, so an edited order still
            // counts under the original child.
            $seller_id   = ( isset( $od['entered_by_id'] ) && $od['entered_by_id'] !== '' ) ? $od['entered_by_id'] : ( isset( $order['user_id'] ) ? $order['user_id'] : '' );
            $seller_name = ( isset( $od['entered_by_name'] ) && $od['entered_by_name'] !== '' ) ? $od['entered_by_name'] : Subsales_Order_Helper::get_user_name( $seller_id );
            $key = $seed_seller( $seller_id, $seller_name );

            $donation = floatval( isset( $od['donationAmount'] ) ? $od['donationAmount'] : ( isset( $od['donation'] ) ? $od['donation'] : ( isset( $od['donation_amount'] ) ? $od['donation_amount'] : 0 ) ) );
            $payment  = isset( $od['paymentMethod'] ) ? $od['paymentMethod'] : ( isset( $od['payment_method'] ) ? $od['payment_method'] : ( isset( $od['payment'] ) ? $od['payment'] : '' ) );
            $check_no = isset( $od['checkNumber'] ) ? $od['checkNumber'] : ( isset( $od['check_number'] ) ? $od['check_number'] : '' );

            $order_total     = 0.0;
            $order_items     = array();
            $products_detail = array();
            $products_list   = ( isset( $od['products'] ) && is_array( $od['products'] ) ) ? $od['products'] : array();
            foreach ( $products_list as $pi ) {
                $pid   = isset( $pi['id'] ) ? (string) $pi['id'] : ( isset( $pi['product_id'] ) ? (string) $pi['product_id'] : ( isset( $pi['name'] ) ? (string) $pi['name'] : '' ) );
                $qty   = intval( isset( $pi['qty'] ) ? $pi['qty'] : ( isset( $pi['quantity'] ) ? $pi['quantity'] : ( isset( $pi['qty_sold'] ) ? $pi['qty_sold'] : 0 ) ) );
                $price = floatval( isset( $pi['price'] ) ? $pi['price'] : ( isset( $pi['unit_price'] ) ? $pi['unit_price'] : 0 ) );
                if ( $qty <= 0 ) { continue; }
                $order_total += $qty * $price;
                if ( $pid !== '' ) {
                    $order_items[ $pid ] = ( isset( $order_items[ $pid ] ) ? $order_items[ $pid ] : 0 ) + $qty;
                }
                $products_detail[] = array(
                    'id'    => $pid,
                    'name'  => isset( $product_names[ $pid ] ) ? $product_names[ $pid ] : ( isset( $pi['name'] ) ? $pi['name'] : $pid ),
                    'qty'   => $qty,
                    'price' => $price,
                );
            }
            $order_total += $donation;

            $is_cash    = ( $payment === 'cash' );
            $is_check   = ( $payment === 'check' );
            $is_digital = ( $payment === 'digital' );

            $s = &$sellers[ $key ];
            if ( $is_cash )    { $s['cash']    += $order_total; }
            if ( $is_check )   { $s['check']   += $order_total; $s['checks_count']++; }
            if ( $is_digital ) { $s['digital'] += $order_total; }
            $s['donation'] += $donation;
            // 'total' stays the driver's physical-collection figure (cash+check
            // only) — digital money never passes through the driver's hands.
            if ( ! $is_digital ) { $s['total'] += $order_total; }
            $s['sales_total'] += $order_total;
            $s['order_count']++;
            foreach ( $order_items as $ipid => $iq ) {
                $s['items'][ $ipid ] = ( isset( $s['items'][ $ipid ] ) ? $s['items'][ $ipid ] : 0 ) + $iq;
            }
            $s['orders'][] = array(
                'order_id'     => isset( $order['order_id'] ) ? $order['order_id'] : ( isset( $order['id'] ) ? $order['id'] : '' ),
                // created_at is stored in GMT; emit a UTC ISO string so the
                // client can render it in the device's local time.
                'created_at'   => ( isset( $order['created_at'] ) && $order['created_at'] ) ? ( str_replace( ' ', 'T', $order['created_at'] ) . 'Z' ) : '',
                'payment'      => $payment,
                'check_number' => $check_no,
                'total'        => round( $order_total, 2 ),
                'donation'     => round( $donation, 2 ),
                'products'     => $products_detail,
            );
            unset( $s );

            if ( $is_cash )    { $totals['cash']    += $order_total; }
            if ( $is_check )   { $totals['check']   += $order_total; $totals['checks_count']++; }
            if ( $is_digital ) { $totals['digital'] += $order_total; }
            $totals['donation'] += $donation;
            if ( ! $is_digital ) { $totals['total'] += $order_total; }
            $totals['sales_total'] += $order_total;
            $totals['order_count']++;
            foreach ( $order_items as $ipid => $iq ) {
                $totals['items'][ $ipid ] = ( isset( $totals['items'][ $ipid ] ) ? $totals['items'][ $ipid ] : 0 ) + $iq;
            }
        }

        // Finalize sellers (round, sort by name)
        $sellers_out = array();
        foreach ( $sellers as $s ) {
            $s['cash']        = round( $s['cash'], 2 );
            $s['check']       = round( $s['check'], 2 );
            $s['donation']    = round( $s['donation'], 2 );
            $s['digital']     = round( $s['digital'], 2 );
            $s['total']       = round( $s['total'], 2 );
            $s['sales_total'] = round( $s['sales_total'], 2 );
            $sellers_out[] = $s;
        }
        usort( $sellers_out, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );

        foreach ( array( 'cash', 'check', 'donation', 'digital', 'total', 'sales_total' ) as $k ) {
            $totals[ $k ] = round( $totals[ $k ], 2 );
        }

        return array(
            'team_id'       => $team_id,
            'team_name'     => $team_name ? $team_name : '',
            'campaign_id'   => $campaign_id,
            'campaign_date' => $date,
            'generated_at'  => current_time( 'mysql' ),
            'totals'        => $totals,
            'products'      => $products_out,
            'sellers'       => $sellers_out,
        );
    }

    /**
     * A member's active signups with team & campaign info. Canonical "my
     * signups" reader (uses the real campaign_name / campaign_date columns).
     *
     * @return array List of signup rows
     */
    /**
     * Season scope comes from the team, not the signup: ss_signups has no
     * season_id column, so t.season_id is the only way to tell this year's
     * registrations from last year's. Without it a returning family saw every
     * season's signups stacked together.
     */
    public static function get_member_signups( $user_id ) {
        global $wpdb;
        $signups_table   = $wpdb->prefix . 'ss_signups';
        $teams_table     = $wpdb->prefix . 'ss_teams';
        $campaigns_table = $wpdb->prefix . 'ss_campaigns';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id AS signup_id, s.campaign_id, s.team_id, s.is_driver,
                    t.name AS team_name,
                    c.campaign_name, c.campaign_date
             FROM {$signups_table} s
             INNER JOIN {$teams_table} t ON s.team_id = t.id
             INNER JOIN {$campaigns_table} c ON s.campaign_id = c.id
             WHERE s.user_id = %d AND s.status = 'active'
               AND t.season_id = %d
             ORDER BY c.campaign_date ASC, t.name ASC",
            $user_id,
            Subsales_Database::current_season_id()
        ), ARRAY_A );

        return $rows ? $rows : array();
    }

    /**
     * Look up a member by phone and return them with their active signups.
     *
     * @return array { user: {id,name}|null, signups: [...] }
     */
    public static function get_member_signups_by_phone( $phone ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        $phone = preg_replace( '/\D/', '', $phone );

        $user = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$members_table} WHERE phone = %s",
            $phone
        ), ARRAY_A );

        if ( ! $user ) {
            return array( 'user' => null, 'signups' => array() );
        }

        return array(
            'user'    => array( 'id' => intval( $user['id'] ), 'name' => $user['name'] ),
            'signups' => self::get_member_signups( intval( $user['id'] ) ),
        );
    }

    /**
     * Active signups across the system, optionally excluding drivers.
     * Thin wrapper over get_signups() defaulting status to 'active'.
     */
    public static function get_active_signups( $filters = array() ) {
        if ( ! isset( $filters['status'] ) ) {
            $filters['status'] = 'active';
        }
        return self::get_signups( $filters );
    }

    /**
     * Member name search for autocomplete (returns id + name only).
     */
    public static function search_members_by_name( $query, $limit = 20 ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';
        $query = trim( (string) $query );
        if ( $query === '' ) {
            return array();
        }
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name FROM {$members_table} WHERE name LIKE %s ORDER BY name ASC LIMIT %d",
            '%' . $wpdb->esc_like( $query ) . '%',
            intval( $limit )
        ), ARRAY_A );
        return $rows ? $rows : array();
    }

    /**
     * Persistent team membership (from ss_user_teams), each member tagged
     * with a derived is_driver flag (true if they hold any active driver
     * signup for this team). Drives the admin Teams roster display.
     *
     * @return array List of member rows, each with an added 'is_driver' key
     */
    public static function get_team_membership( $team_id ) {
        global $wpdb;
        $user_teams_table = $wpdb->prefix . 'ss_user_teams';
        $members_table    = $wpdb->prefix . 'ss_team_members';
        $signups_table    = $wpdb->prefix . 'ss_signups';

        $members = $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*
             FROM {$user_teams_table} ut
             INNER JOIN {$members_table} m ON ut.user_id = m.id
             WHERE ut.team_id = %d
             ORDER BY m.name ASC",
            $team_id
        ), ARRAY_A );

        if ( ! $members ) {
            return array();
        }

        $driver_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$signups_table}
             WHERE team_id = %d AND is_driver = 1 AND status = 'active'",
            $team_id
        ) );
        $driver_ids = array_map( 'intval', (array) $driver_ids );

        foreach ( $members as &$m ) {
            $m['is_driver'] = in_array( intval( $m['id'] ), $driver_ids, true ) ? 1 : 0;
        }
        unset( $m );

        return $members;
    }

    // ============================================================
    // ADDRESS REVIEW QUEUE
    // ============================================================
    //
    // Anything the address pipeline cannot resolve on its own lands here, and
    // an admin works it at their own pace. This queue takes no action and
    // blocks nothing - the old flow forced a nightly geocode plus a manual
    // approve gate and stalled after about a month of real use.

    /**
     * Canonical hash for a queue row.
     *
     * Both producers (parcel ingestion and the order-entry scan) use the same
     * formula so the same physical address is one queue entry no matter which
     * one found it first. Falls back to the raw string when the address is too
     * mangled to parse into parts.
     *
     * @param string $house_number House number
     * @param string $street Street name (canonical suffix)
     * @param string $raw_address Original string, used when parts are empty
     * @return string 32-char md5
     * @since 3.2.0
     */
    public static function review_queue_hash( $house_number, $street, $raw_address = '' ) {
        $key = trim( trim( (string) $house_number ) . ' ' . trim( (string) $street ) );

        if ( '' === $key ) {
            $key = trim( (string) $raw_address );
        }

        return md5( strtolower( $key ) );
    }

    /**
     * Upsert a row into the review queue.
     *
     * Idempotent via the address_hash unique key - re-running ingestion or the
     * order scan touches updated_at instead of piling up duplicates.
     *
     * @param array $args reason, source_context, raw_address (required);
     *                    house_number, street, city, candidate_zips (array),
     *                    lat, lng, order_id (optional)
     * @return bool True on success
     * @since 3.2.0
     */
    public static function queue_address_for_review( $args ) {
        global $wpdb;

        $args = wp_parse_args( $args, array(
            'reason'          => 'zip_undetermined',
            'source_context'  => 'ingestion',
            'raw_address'     => '',
            'house_number'    => '',
            'street'          => '',
            'city'            => 'Southington',
            'candidate_zips'  => array(),
            'lat'             => null,
            'lng'             => null,
            'order_id'        => null,
        ) );

        if ( '' === trim( (string) $args['raw_address'] ) ) {
            return false;
        }

        if ( ! in_array( $args['reason'], array( 'zip_undetermined', 'not_in_database' ), true ) ) {
            return false;
        }
        if ( ! in_array( $args['source_context'], array( 'ingestion', 'order_entry' ), true ) ) {
            return false;
        }

        $table = $wpdb->prefix . 'ss_address_review_queue';

        $hash = self::review_queue_hash( $args['house_number'], $args['street'], $args['raw_address'] );

        $candidates = ( is_array( $args['candidate_zips'] ) && ! empty( $args['candidate_zips'] ) )
            ? wp_json_encode( array_values( $args['candidate_zips'] ) )
            : '';

        // NULLIF(%s,'') on the nullable columns because $wpdb->prepare() coerces
        // a PHP null to an empty string, which would land as 0 in lat/lng.
        //
        // ON DUPLICATE KEY UPDATE rather than $wpdb->insert() so repeat scans
        // are a no-op touch - same upsert-by-hash idiom as ss_orders.address_hash.
        $sql = $wpdb->prepare(
            "INSERT INTO {$table}
                ( reason, source_context, raw_address, house_number, street, city,
                  candidate_zips_json, lat, lng, order_id, address_hash, status, created_at, updated_at )
             VALUES ( %s, %s, %s, %s, %s, %s, NULLIF(%s,''), NULLIF(%s,''), NULLIF(%s,''), NULLIF(%s,''), %s, 'pending', NOW(), NOW() )
             ON DUPLICATE KEY UPDATE updated_at = NOW()",
            $args['reason'],
            $args['source_context'],
            substr( trim( (string) $args['raw_address'] ), 0, 500 ),
            substr( trim( (string) $args['house_number'] ), 0, 20 ),
            substr( trim( (string) $args['street'] ), 0, 255 ),
            substr( trim( (string) $args['city'] ), 0, 100 ),
            $candidates,
            ( null === $args['lat'] || '' === $args['lat'] ) ? '' : (string) floatval( $args['lat'] ),
            ( null === $args['lng'] || '' === $args['lng'] ) ? '' : (string) floatval( $args['lng'] ),
            ( null === $args['order_id'] || '' === $args['order_id'] ) ? '' : (string) intval( $args['order_id'] ),
            $hash
        );

        return false !== $wpdb->query( $sql );
    }

    /**
     * Retire pending rows whose address has since landed in ss_addresses.
     *
     * The queue only ever grew: queue_address_for_review() upserts on
     * address_hash and nothing marked a row done when the address arrived by
     * another route - the admin adds the ZIP that was missing, the next ingest
     * files the address properly, and the flag stays pending forever. So the
     * Needs Review count overstates the outstanding work and drifts further
     * every run.
     *
     * One set-based UPDATE ... JOIN: a few hundred queue rows against ~18k
     * addresses is a single pass, not a row-by-row PHP loop. Matching is on
     * house number + street only (case-insensitive, trimmed - same comparison
     * style as Subsales_Address_Helper::lookup_in_database()), because the ZIP
     * is exactly what was unknown when the row was queued.
     *
     * 'dismissed' rows are never touched - the admin dismissed those on purpose.
     *
     * @return int Number of rows retired
     * @since 3.3.0
     */
    public static function retire_resolved_review_rows() {
        global $wpdb;

        $table = $wpdb->prefix . 'ss_address_review_queue';
        $addresses_table = $wpdb->prefix . 'ss_addresses';

        // MIN(id) so an address listed under more than one ZIP still resolves to
        // a single, stable address id rather than whichever row MySQL saw first.
        // No user input in this statement, so nothing to prepare().
        $retired = $wpdb->query(
            "UPDATE {$table} q
             INNER JOIN (
                 SELECT LOWER(TRIM(house_number)) AS house_key,
                        LOWER(TRIM(street)) AS street_key,
                        MIN(id) AS address_id
                 FROM {$addresses_table}
                 GROUP BY house_key, street_key
             ) a
                ON a.house_key = LOWER(TRIM(q.house_number))
               AND a.street_key = LOWER(TRIM(q.street))
             SET q.status = 'resolved',
                 q.resolved_address_id = a.address_id,
                 q.resolved_at = NOW()
             WHERE q.status = 'pending'
               AND TRIM(q.house_number) <> ''
               AND TRIM(q.street) <> ''"
        );

        if ( false === $retired ) {
            subsales_log( 'ERROR', 'address', 'Review queue reconciliation failed', array(
                'error' => $wpdb->last_error
            ) );
            return 0;
        }

        if ( $retired > 0 ) {
            subsales_log( 'INFO', 'address', 'Review queue rows retired as already resolved', array(
                'retired' => intval( $retired )
            ) );
        }

        return intval( $retired );
    }

    /**
     * Producer B: flag orders whose address doesn't resolve against ss_addresses.
     *
     * Read-only. It adds a line to a list and does nothing else - no geocoding,
     * no order mutation, no approval gate. Stacked on the existing hourly cron.
     *
     * Only orders touched since the last scan are examined, so the hourly run
     * stays cheap and edited addresses still get re-checked.
     *
     * @return array Summary: scanned, queued
     * @since 3.2.0
     */
    public static function scan_unmatched_order_addresses() {
        global $wpdb;

        $orders_table = $wpdb->prefix . 'ss_orders';
        $watermark = get_option( 'subsales_address_scan_watermark', '' );

        $season_id = Subsales_Database::current_season_id();

        if ( ! empty( $watermark ) ) {
            $orders = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_id, order_data, address
                 FROM {$orders_table}
                 WHERE deleted = 0 AND season_id = %d AND updated_at > %s
                 ORDER BY updated_at ASC",
                $season_id,
                $watermark
            ), ARRAY_A );
        } else {
            // First run: the whole current season, older seasons stay out of the
            // queue rather than dumping years of history on the admin.
            $orders = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_id, order_data, address
                 FROM {$orders_table}
                 WHERE deleted = 0 AND season_id = %d
                 ORDER BY updated_at ASC",
                $season_id
            ), ARRAY_A );
        }

        $scanned = 0;
        $queued = 0;

        foreach ( $orders as $order ) {
            $address = ! empty( $order['address'] ) ? $order['address'] : '';

            if ( empty( $address ) ) {
                $order_data = json_decode( $order['order_data'], true );
                $address = ! empty( $order_data['address'] ) ? $order_data['address'] : '';
            }

            if ( empty( trim( $address ) ) ) {
                continue;
            }

            $scanned++;

            if ( Subsales_Address_Helper::lookup_in_database( $address, false ) ) {
                continue; // Resolves fine, nothing to flag.
            }

            $parsed = Subsales_Delivery::parse_address( $address );

            $ok = self::queue_address_for_review( array(
                'reason'         => 'not_in_database',
                'source_context' => 'order_entry',
                'raw_address'    => $address,
                'house_number'   => $parsed ? $parsed['house_number'] : '',
                'street'         => $parsed ? $parsed['street'] : '',
                'city'           => ( $parsed && ! empty( $parsed['city'] ) ) ? $parsed['city'] : 'Southington',
                'order_id'       => intval( $order['id'] ),
            ) );

            if ( $ok ) {
                $queued++;
            }
        }

        update_option( 'subsales_address_scan_watermark', current_time( 'mysql' ), false );

        // Same pass in reverse: anything already fixed elsewhere stops counting
        // as outstanding, so the list self-heals between ingests too.
        $retired = self::retire_resolved_review_rows();

        if ( $scanned > 0 ) {
            subsales_log( 'INFO', 'address', 'Order address scan complete', array(
                'scanned' => $scanned,
                'queued'  => $queued,
                'retired' => $retired
            ), 'cron' );
        }

        return array( 'scanned' => $scanned, 'queued' => $queued, 'retired' => $retired );
    }

    /**
     * Pending review rows, newest first.
     *
     * @param int $limit Rows per page
     * @param int $offset Offset
     * @param string $status Queue status to fetch
     * @return array Rows as associative arrays
     * @since 3.2.0
     */
    public static function get_review_queue_rows( $limit = 50, $offset = 0, $status = 'pending' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ss_address_review_queue';

        if ( ! in_array( $status, array( 'pending', 'resolved', 'dismissed' ), true ) ) {
            $status = 'pending';
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $status,
            max( 1, intval( $limit ) ),
            max( 0, intval( $offset ) )
        ), ARRAY_A );
    }

    /**
     * Count queue rows in a given status (defaults to the pending badge count).
     *
     * @param string $status Queue status
     * @return int Row count
     * @since 3.2.0
     */
    public static function count_review_queue_rows( $status = 'pending' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ss_address_review_queue';

        if ( ! in_array( $status, array( 'pending', 'resolved', 'dismissed' ), true ) ) {
            $status = 'pending';
        }

        return intval( $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            $status
        ) ) );
    }

    /**
     * Fetch a single queue row.
     *
     * @param int $id Queue row id
     * @return array|null Row or null
     * @since 3.2.0
     */
    public static function get_review_queue_row( $id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ss_address_review_queue';

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            intval( $id )
        ), ARRAY_A );
    }

    /**
     * Resolve a queue row by committing the address to ss_addresses.
     *
     * Uses the same insert path as parcel ingestion so a hand-resolved address
     * is indistinguishable from an ingested one.
     *
     * @param int $id Queue row id
     * @param array $args zip, lat, lng (required); house_number, street, city,
     *                    state, source, confidence, note (optional)
     * @return array|WP_Error ['address_id' => int] or WP_Error
     * @since 3.2.0
     */
    public static function resolve_review_queue_row( $id, $args ) {
        global $wpdb;

        $row = self::get_review_queue_row( $id );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Review queue row not found.' );
        }

        $args = wp_parse_args( $args, array(
            'house_number' => $row['house_number'],
            'street'       => $row['street'],
            'city'         => ! empty( $row['city'] ) ? $row['city'] : 'Southington',
            'state'        => 'CT',
            'zip'          => '',
            'lat'          => $row['lat'],
            'lng'          => $row['lng'],
            'source'       => 'manual',
            'confidence'   => 'medium',
            'note'         => '',
        ) );

        if ( empty( $args['house_number'] ) || empty( $args['street'] ) ) {
            return new WP_Error( 'incomplete', 'House number and street are required to resolve an address.' );
        }
        if ( ! preg_match( '/^\d{5}$/', (string) $args['zip'] ) ) {
            return new WP_Error( 'invalid_zip', 'A 5-digit ZIP code is required to resolve an address.' );
        }
        if ( ! is_numeric( $args['lat'] ) || ! is_numeric( $args['lng'] ) ) {
            return new WP_Error( 'no_coordinates', 'Coordinates are required. Geocode the row first.' );
        }

        $address_row = array(
            'house_number' => $args['house_number'],
            'street'       => $args['street'],
            'unit'         => '', // Units are typed by the seller, never stored here.
            'city'         => $args['city'],
            'state'        => $args['state'],
            'zip'          => $args['zip'],
            'lat'          => $args['lat'],
            'lng'          => $args['lng'],
            'source'       => $args['source'],
            'confidence'   => $args['confidence'],
            'type'         => 'residential',
        );

        if ( ! self::insert_addresses( array( $address_row ) ) ) {
            // INSERT IGNORE swallows an already-present row; that still counts
            // as resolved, so fall through to the lookup below.
            subsales_log( 'DEBUG', 'address', 'Review queue resolve inserted no new address row', array(
                'queue_id' => intval( $id )
            ) );
        }

        $existing = Subsales_Address_Helper::lookup_in_database( array(
            'house_number' => $args['house_number'],
            'street'       => $args['street'],
            'zip'          => $args['zip'],
        ), false );

        if ( ! $existing ) {
            return new WP_Error( 'insert_failed', 'Address could not be written to the address database.' );
        }

        $table = $wpdb->prefix . 'ss_address_review_queue';
        $wpdb->update(
            $table,
            array(
                'status'              => 'resolved',
                'resolved_address_id' => intval( $existing['id'] ),
                'resolution_note'     => $args['note'],
                'resolved_at'         => current_time( 'mysql' ),
            ),
            array( 'id' => intval( $id ) ),
            array( '%s', '%d', '%s', '%s' ),
            array( '%d' )
        );

        subsales_log( 'INFO', 'address', 'Review queue row resolved', array(
            'queue_id'   => intval( $id ),
            'address_id' => intval( $existing['id'] ),
            'zip'        => $args['zip']
        ) );

        return array( 'address_id' => intval( $existing['id'] ) );
    }

    /**
     * Dismiss a queue row (junk, duplicate, or not worth chasing).
     *
     * @param int $id Queue row id
     * @param string $note Optional reason
     * @return bool True on success
     * @since 3.2.0
     */
    public static function dismiss_review_queue_row( $id, $note = '' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'ss_address_review_queue';

        $updated = $wpdb->update(
            $table,
            array(
                'status'          => 'dismissed',
                'resolution_note' => $note,
                'resolved_at'     => current_time( 'mysql' ),
            ),
            array( 'id' => intval( $id ) ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        return false !== $updated;
    }

    /**
     * Bulk-insert address rows into ss_addresses.
     *
     * The single write path into that table for the whole rebuilt pipeline -
     * parcel ingestion passes thousands of rows, a queue resolve passes one.
     * INSERT IGNORE so a duplicate against unique_address skips instead of
     * aborting the batch.
     *
     * @param array $rows Each with house_number, street, unit, city, state, zip,
     *                    lat, lng, source, confidence, type
     * @return int Number of rows inserted
     * @since 3.2.0
     */
    public static function insert_addresses( $rows ) {
        global $wpdb;

        if ( empty( $rows ) ) {
            return 0;
        }

        $table = $wpdb->prefix . 'ss_addresses';
        $inserted = 0;

        // Batched so an 18k-parcel ingest isn't 18k round trips.
        foreach ( array_chunk( array_values( $rows ), 200 ) as $chunk ) {
            $placeholders = array();
            $values = array();

            foreach ( $chunk as $row ) {
                $row = wp_parse_args( $row, array(
                    'house_number' => '',
                    'street'       => '',
                    'unit'         => '',
                    'city'         => 'Southington',
                    'state'        => 'CT',
                    'zip'          => '',
                    'lat'          => 0,
                    'lng'          => 0,
                    'source'       => 'manual',
                    'confidence'   => 'medium',
                    'type'         => 'residential',
                    'full_address' => '',
                ) );

                if ( '' === $row['full_address'] ) {
                    $row['full_address'] = trim( $row['house_number'] . ' ' . $row['street'] )
                        . ', ' . $row['city'] . ', ' . $row['state'] . ' ' . $row['zip'];
                }

                $placeholders[] = '(%s, %s, %s, %s, %s, %s, %f, %f, %s, %s, %s, %s)';

                $values[] = substr( (string) $row['house_number'], 0, 20 );
                $values[] = substr( (string) $row['street'], 0, 255 );
                $values[] = substr( (string) $row['unit'], 0, 20 );
                $values[] = substr( (string) $row['city'], 0, 100 );
                $values[] = substr( (string) $row['state'], 0, 2 );
                $values[] = substr( (string) $row['zip'], 0, 10 );
                $values[] = floatval( $row['lat'] );
                $values[] = floatval( $row['lng'] );
                $values[] = (string) $row['source'];
                $values[] = (string) $row['confidence'];
                $values[] = (string) $row['type'];
                $values[] = (string) $row['full_address'];
            }

            $sql = "INSERT IGNORE INTO {$table}
                        ( house_number, street, unit, city, state, zip, lat, lng, source, confidence, type, full_address )
                    VALUES " . implode( ', ', $placeholders );

            $result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

            if ( false === $result ) {
                subsales_log( 'ERROR', 'address', 'Address batch insert failed: ' . $wpdb->last_error, array(
                    'batch_size' => count( $chunk )
                ) );
                continue;
            }

            $inserted += intval( $result );
        }

        return $inserted;
    }
}
