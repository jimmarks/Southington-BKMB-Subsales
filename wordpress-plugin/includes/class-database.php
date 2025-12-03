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
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY team_id (team_id),
            KEY deleted (deleted),
            KEY tallied (tallied)
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
        
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
        dbDelta( $teams_sql );
        dbDelta( $team_members_sql );
        dbDelta( $user_teams_sql );
        dbDelta( $edit_history_sql );
        dbDelta( $logs_sql );
        dbDelta( $pwa_sessions_sql );
        dbDelta( $addresses_sql );
        
        // Run schema migrations
        self::migrate_phone_column( $team_members_table_name );
        self::migrate_soft_delete_columns( $table_name );
        self::migrate_tally_columns( $table_name );
        self::migrate_user_teams( $team_members_table_name, $user_teams_table_name );
        self::migrate_edit_type_enum( $edit_history_table_name );
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
        
        $existing = $wpdb->get_row( 
            $wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE name = %s OR access_code = %s",
                $name,
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
                'status' => $status
            ),
            array( '%s', '%s', '%s', '%s' )
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
        
        // Build field-by-field comparison
        $changes = array();
        $summary_parts = array();
        
        // Define all possible order fields to compare
        $fields_to_compare = array(
            'customerName' => 'Customer Name',
            'address' => 'Address',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP',
            'phone' => 'Phone',
            'email' => 'Email',
            'deliveryDate' => 'Delivery Date',
            'driver' => 'Driver',
            'notes' => 'Notes',
            'donationAmount' => 'Donation Amount',
            'paymentMethod' => 'Payment Method',
            'products' => 'Products',
            'strawberryQty' => 'Strawberry Qty',
            'blueberryQty' => 'Blueberry Qty',
            'raspberryQty' => 'Raspberry Qty'
        );
        
        // Compare simple fields
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
     * @return bool Success
     */
    public static function update_pwa_heartbeat( $session_id, $activity_data = array() ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ss_pwa_sessions';
        
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
        
        $result = $wpdb->update(
            $table_name,
            array(
                'last_heartbeat' => $now,
                'status' => 'active',
                'session_data' => wp_json_encode( $session_data )
            ),
            array( 'session_id' => $session_id ),
            array( '%s', '%s', '%s' ),
            array( '%s' )
        );
        
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
}
