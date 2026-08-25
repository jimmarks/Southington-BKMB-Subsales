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
        
        // Schedule nightly address validation (2 AM daily)
        if ( ! wp_next_scheduled( 'subsales_nightly_address_validation' ) ) {
            wp_schedule_event( strtotime( '02:00:00' ), 'daily', 'subsales_nightly_address_validation' );
        }
        add_action( 'subsales_nightly_address_validation', array( __CLASS__, 'run_address_validation' ) );
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
            address_validation_status enum('pending','valid','geocode_failed','format_invalid','approved') DEFAULT 'pending',
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
            UNIQUE KEY name (name),
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
            UNIQUE KEY campaign_date (campaign_date),
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
        dbDelta( $campaigns_sql );
        dbDelta( $signups_sql );
        dbDelta( $team_campaigns_sql );
        
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

        // Season support - must run in this order: seasons table (and its
        // bootstrap row) before the season_id columns that backfill from it.
        self::migrate_seasons_table();
        self::migrate_teams_season_id( $teams_table_name );
        self::migrate_campaigns_season_id( $campaigns_table_name );
        self::migrate_orders_season_id( $table_name );
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

            $current_season_id = intval( get_option( 'subsales_current_season_id' ) );
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

        if ( $has_old_key && ! $has_new_key ) {
            $wpdb->query(
                "ALTER TABLE {$teams_table_name}
                 DROP INDEX name,
                 ADD UNIQUE KEY name_season (name, season_id)"
            );
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

        $current_season_id = intval( get_option( 'subsales_current_season_id' ) );
        if ( $current_season_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$campaigns_table_name} SET season_id = %d WHERE season_id = 0",
                $current_season_id
            ) );
        }
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

        $current_season_id = intval( get_option( 'subsales_current_season_id' ) );
        if ( $current_season_id ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$orders_table_name} SET season_id = %d WHERE season_id = 0",
                $current_season_id
            ) );
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
        $season_id = intval( get_option( 'subsales_current_season_id' ) );

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
    
    /**
     * Get all active teams
     * 
     * @return array Teams
     */
    public static function get_teams() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_teams';
        
        return $wpdb->get_results( 
            "SELECT * FROM {$table_name} ORDER BY status DESC, created_at DESC", 
            ARRAY_A 
        );
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
        
        if ( $table_exists ) {
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
    public static function get_campaigns( $status = 'all' ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';
        
        $where = '1=1';
        if ( $status !== 'all' ) {
            $where = $wpdb->prepare( 'status = %s', $status );
        }
        
        $campaigns = $wpdb->get_results(
            "SELECT * FROM {$table_name} WHERE {$where} ORDER BY campaign_date ASC",
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
    public static function get_campaign_by_date( $date ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';
        
        $campaign = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE campaign_date = %s",
            $date
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
        
        $campaign_data = array(
            'campaign_date' => $data['campaign_date'],
            'campaign_name' => isset( $data['campaign_name'] ) ? $data['campaign_name'] : '',
            'notes' => isset( $data['notes'] ) ? $data['notes'] : '',
            'status' => isset( $data['status'] ) ? $data['status'] : 'active',
        );
        
        if ( isset( $data['id'] ) && $data['id'] > 0 ) {
            // Update existing campaign
            $result = $wpdb->update(
                $table_name,
                $campaign_data,
                array( 'id' => $data['id'] ),
                array( '%s', '%s', '%s', '%s' ),
                array( '%d' )
            );
            
            return $result !== false ? $data['id'] : false;
        } else {
            // Create new campaign
            $result = $wpdb->insert( $table_name, $campaign_data, array( '%s', '%s', '%s', '%s' ) );
            return $result ? $wpdb->insert_id : false;
        }
    }
    
    /**
     * Delete campaign
     * 
     * @param int $campaign_id Campaign ID
     * @return bool Success
     */
    public static function delete_campaign( $campaign_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_campaigns';
        
        // Check if campaign has signups
        $signups_table = $wpdb->prefix . 'ss_signups';
        $signup_count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$signups_table} WHERE campaign_id = %d",
            $campaign_id
        ) );
        
        if ( $signup_count > 0 ) {
            return false; // Cannot delete campaign with signups
        }
        
        $result = $wpdb->delete( $table_name, array( 'id' => $campaign_id ), array( '%d' ) );
        return $result !== false;
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
    
    /**
     * Run nightly address validation for orders
     * Validates addresses that need checking, skips already-validated unless address changed
     */
    public static function run_address_validation() {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';
        
        // Find orders that need validation:
        // 1. Status = 'pending' (never validated)
        // 2. Address changed (address_hash doesn't match current address)
        // 3. All orders (including tallied/delivered) - use dismiss button to filter out unwanted addresses
        $orders = $wpdb->get_results(
            "SELECT id, order_id, order_data, address, address_hash, address_validation_status, tallied
             FROM {$table}
             WHERE deleted = 0
             AND address_validation_status != 'dismissed'
             AND (
                 address_validation_status = 'pending'
                 OR address_hash != MD5(LOWER(TRIM(COALESCE(address, ''))))
                 OR address_validation_date IS NULL
             )
             ORDER BY created_at DESC",
            ARRAY_A
        );
        
        if ( empty( $orders ) ) {
            subsales_log( 'INFO', 'system', 'Address validation: No orders need validation' );
            return;
        }
        
        subsales_log( 'INFO', 'system', 'Address validation: Starting validation for ' . count( $orders ) . ' orders' );
        
        $validated_count = 0;
        $valid_count = 0;
        $failed_count = 0;
        
        // Load delivery class for parse/geocode functions
        require_once SUBSALES_PLUGIN_PATH . 'includes/class-delivery.php';
        
        foreach ( $orders as $order ) {
            // Extract address from order_data if not in address column
            $address = $order['address'];
            if ( empty( $address ) ) {
                $order_data = json_decode( $order['order_data'], true );
                $address = ! empty( $order_data['address'] ) ? $order_data['address'] : '';
                
                // Update address column while we're at it
                if ( ! empty( $address ) ) {
                    $wpdb->update(
                        $table,
                        array( 'address' => $address ),
                        array( 'id' => $order['id'] ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }
            }
            
            if ( empty( $address ) ) {
                // No address to validate
                $wpdb->update(
                    $table,
                    array(
                        'address_validation_status' => 'format_invalid',
                        'address_validation_date' => current_time( 'mysql' ),
                        'address_validation_data' => json_encode( array( 'error' => 'No address provided' ) )
                    ),
                    array( 'id' => $order['id'] ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
                $failed_count++;
                continue;
            }
            
            // Parse address
            $parsed = Subsales_Delivery::parse_address( $address );
            if ( ! $parsed || empty( $parsed['house_number'] ) || empty( $parsed['street'] ) ) {
                // Can't parse - format invalid
                $wpdb->update(
                    $table,
                    array(
                        'address_validation_status' => 'format_invalid',
                        'address_validation_date' => current_time( 'mysql' ),
                        'address_validation_data' => json_encode( array( 'error' => 'Could not parse address', 'address' => $address ) ),
                        'address_hash' => md5( strtolower( trim( $address ) ) )
                    ),
                    array( 'id' => $order['id'] ),
                    array( '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                $failed_count++;
                continue;
            }
            
            // Check if address exists in wp_ss_addresses
            $address_table = $wpdb->prefix . 'ss_addresses';
            $query = "SELECT lat, lng FROM {$address_table} 
                      WHERE LOWER(TRIM(street)) = %s 
                      AND LOWER(TRIM(house_number)) = %s";
            $params = array(
                strtolower( trim( $parsed['street'] ) ),
                strtolower( trim( $parsed['house_number'] ) )
            );
            
            // Add unit if specified
            if ( ! empty( $parsed['unit'] ) ) {
                $query .= " AND LOWER(TRIM(unit)) = %s";
                $params[] = strtolower( trim( $parsed['unit'] ) );
            }
            
            $query .= " LIMIT 1";
            
            $address_row = $wpdb->get_row( $wpdb->prepare( $query, $params ), ARRAY_A );
            
            if ( $address_row && ! empty( $address_row['lat'] ) && ! empty( $address_row['lng'] ) ) {
                // Found in database - valid!
                $wpdb->update(
                    $table,
                    array(
                        'address_validation_status' => 'valid',
                        'address_validation_date' => current_time( 'mysql' ),
                        'address_validation_data' => json_encode( array( 
                            'source' => 'database',
                            'parsed' => $parsed,
                            'coordinates' => $address_row
                        ) ),
                        'address_hash' => md5( strtolower( trim( $address ) ) )
                    ),
                    array( 'id' => $order['id'] ),
                    array( '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                $valid_count++;
                $validated_count++;
                continue;
            }
            
            // Not in database - try geocoding
            $coords = Subsales_Delivery::geocode_address( $address );
            if ( $coords && ! empty( $coords['lat'] ) && ! empty( $coords['lng'] ) ) {
                // Geocoded successfully
                // If Google returned a formatted_address, parse that instead of user input
                // This corrects typos like "walkers crosing" → "Walkers Crossing"
                $corrected_parsed = $parsed; // Default to original parse
                $corrected_address = $address;
                
                if ( ! empty( $coords['formatted_address'] ) ) {
                    $corrected_address = $coords['formatted_address'];
                    $temp_parsed = Subsales_Delivery::parse_address( $coords['formatted_address'] );
                    
                    // Only use corrected parse if it has required fields
                    if ( $temp_parsed && ! empty( $temp_parsed['house_number'] ) && ! empty( $temp_parsed['street'] ) ) {
                        $corrected_parsed = $temp_parsed;
                    }
                }
                
                $validation_data = array( 
                    'source' => 'geocoded',
                    'original_address' => $address, // User's input with typo
                    'corrected_address' => $corrected_address, // Google's formatted address
                    'parsed' => $corrected_parsed, // Parse of corrected address
                    'coordinates' => array( 'lat' => $coords['lat'], 'lng' => $coords['lng'] ),
                    'location_type' => ! empty( $coords['location_type'] ) ? $coords['location_type'] : 'APPROXIMATE',
                    'needs_approval' => true
                );
                
                // Mark as valid (needs approval for database entry)
                $wpdb->update(
                    $table,
                    array(
                        'address_validation_status' => 'valid',
                        'address_validation_date' => current_time( 'mysql' ),
                        'address_validation_data' => json_encode( $validation_data ),
                        'address_hash' => md5( strtolower( trim( $address ) ) )
                    ),
                    array( 'id' => $order['id'] ),
                    array( '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                $valid_count++;
                $validated_count++;
            } else {
                // Geocoding failed
                $wpdb->update(
                    $table,
                    array(
                        'address_validation_status' => 'geocode_failed',
                        'address_validation_date' => current_time( 'mysql' ),
                        'address_validation_data' => json_encode( array( 
                            'error' => 'Geocoding failed',
                            'address' => $address,
                            'parsed' => $parsed
                        ) ),
                        'address_hash' => md5( strtolower( trim( $address ) ) )
                    ),
                    array( 'id' => $order['id'] ),
                    array( '%s', '%s', '%s', '%s' ),
                    array( '%d' )
                );
                $failed_count++;
                $validated_count++;
            }
        }
        
        subsales_log( 'INFO', 'system', "Address validation complete: {$validated_count} validated, {$valid_count} valid, {$failed_count} failed" );
    }
    
    /**
     * Get count of orders with address validation issues
     * 
     * @return int Count of orders needing attention
     */
    public static function get_address_validation_issues_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE deleted = 0
             AND tallied = 0
             AND address_validation_status IN ('geocode_failed', 'format_invalid')"
        );
        
        return intval( $count );
    }
    
    /**
     * Check if address validation has ever been run
     * 
     * Returns true if at least one order has been validated (has address_validation_date)
     * 
     * @return bool True if validation has run at least once
     * @since 2.4.54
     */
    public static function has_address_validation_run() {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';
        
        // Check if the column exists first
        $column_exists = $wpdb->get_var(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '{$table}' 
             AND COLUMN_NAME = 'address_validation_date'"
        );
        
        if ( ! $column_exists ) {
            return false;
        }
        
        // Check if any order has been validated
        $has_validated = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE address_validation_date IS NOT NULL
             AND deleted = 0"
        );
        
        return intval( $has_validated ) > 0;
    }
    
    /**
     * Get count of orders pending validation
     * 
     * @return int Count of orders with pending validation status
     * @since 2.4.54
     */
    public static function get_address_validation_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'ss_orders';
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table}
             WHERE deleted = 0
             AND tallied = 0
             AND (address_validation_status = 'pending' OR address_validation_status IS NULL)"
        );
        
        return intval( $count );
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
     * Get-or-create a team member, keyed by phone (unique).
     * Syncs name (and role, when provided) on an existing member.
     *
     * @param string      $name  Member name
     * @param string      $phone 10-digit phone (caller normalizes)
     * @param string|null $role  When set, update role (e.g. 'driver'); null leaves it untouched
     * @return int|false Member ID, or false on failure
     */
    public static function get_or_create_member( $name, $phone, $role = null ) {
        global $wpdb;
        $members_table = $wpdb->prefix . 'ss_team_members';

        $member = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$members_table} WHERE phone = %s",
            $phone
        ), ARRAY_A );

        if ( $member ) {
            $member_id = intval( $member['id'] );
            $update = array();
            $format = array();
            if ( $name !== '' ) { $update['name'] = $name; $format[] = '%s'; }
            if ( $role !== null && $role !== '' ) { $update['role'] = $role; $format[] = '%s'; }
            if ( ! empty( $update ) ) {
                $wpdb->update( $members_table, $update, array( 'id' => $member_id ), $format, array( '%d' ) );
            }
            return $member_id;
        }

        $wpdb->insert( $members_table, array(
            'name'   => $name,
            'phone'  => $phone,
            'role'   => ( $role !== null && $role !== '' ) ? $role : 'member',
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
        $season_id = intval( get_option( 'subsales_current_season_id' ) );

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

        // Resolve the member (sync role to 'driver' only for driver signups)
        $user_id = self::get_or_create_member( $name, $phone, $is_driver ? 'driver' : null );
        if ( ! $user_id ) {
            return new WP_Error( 'member_failed', 'Could not create the member.', array( 'status' => 500 ) );
        }

        self::link_member_to_team( $user_id, $team_id );

        $signups_table   = $wpdb->prefix . 'ss_signups';
        $signups_created = 0;
        $skipped         = array();

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
                    'total'        => 0.0,
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
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$orders_table}
             WHERE team_id = %d AND DATE(created_at) = %s AND deleted = 0
             ORDER BY created_at ASC",
            $team_id, $date
        ), ARRAY_A );

        $totals = array(
            'cash' => 0.0, 'check' => 0.0, 'donation' => 0.0, 'total' => 0.0,
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

            $is_cash  = ( $payment === 'cash' );
            $is_check = ( $payment === 'check' );

            $s = &$sellers[ $key ];
            if ( $is_cash )  { $s['cash']  += $order_total; }
            if ( $is_check ) { $s['check'] += $order_total; $s['checks_count']++; }
            $s['donation'] += $donation;
            $s['total']    += $order_total;
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

            if ( $is_cash )  { $totals['cash']  += $order_total; }
            if ( $is_check ) { $totals['check'] += $order_total; $totals['checks_count']++; }
            $totals['donation'] += $donation;
            $totals['total']    += $order_total;
            $totals['order_count']++;
            foreach ( $order_items as $ipid => $iq ) {
                $totals['items'][ $ipid ] = ( isset( $totals['items'][ $ipid ] ) ? $totals['items'][ $ipid ] : 0 ) + $iq;
            }
        }

        // Finalize sellers (round, sort by name)
        $sellers_out = array();
        foreach ( $sellers as $s ) {
            $s['cash']     = round( $s['cash'], 2 );
            $s['check']    = round( $s['check'], 2 );
            $s['donation'] = round( $s['donation'], 2 );
            $s['total']    = round( $s['total'], 2 );
            $sellers_out[] = $s;
        }
        usort( $sellers_out, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );

        foreach ( array( 'cash', 'check', 'donation', 'total' ) as $k ) {
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
             ORDER BY c.campaign_date ASC, t.name ASC",
            $user_id
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
}
