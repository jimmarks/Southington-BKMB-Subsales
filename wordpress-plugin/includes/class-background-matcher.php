<?php
/**
 * Background Address Matcher
 * 
 * Handles background processing of Overpass address matching using WP-Cron.
 * Allows administrators to start matching and check status without blocking the UI.
 * 
 * @package Subsales_Management
 * @since 2.0.0.32
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Background_Matcher {
    
    /**
     * Option keys for job state tracking
     */
    const OPTION_JOB_STATUS = 'subsales_match_job_status';
    const OPTION_JOB_PROGRESS = 'subsales_match_job_progress';
    const OPTION_JOB_LOGS = 'subsales_match_job_logs';
    
    /**
     * Job statuses
     */
    const STATUS_IDLE = 'idle';
    const STATUS_RUNNING = 'running';
    const STATUS_PAUSED = 'paused';
    const STATUS_COMPLETE = 'complete';
    const STATUS_ERROR = 'error';
    
    /**
     * Cron hook name
     */
    const CRON_HOOK = 'subsales_background_match_batch';
    
    /**
     * Initialize hooks
     */
    public static function init() {
        // Register cron hook
        add_action( self::CRON_HOOK, array( __CLASS__, 'process_batch' ) );
        
        // Cleanup on plugin deactivation (optional)
        register_deactivation_hook( __FILE__, array( __CLASS__, 'cleanup' ) );
    }
    
    /**
     * Start a new background matching job
     * 
     * @return array Result with success flag and message
     */
    public static function start_job() {
        // Check if job is already running
        $status = self::get_job_status();
        if ( $status === self::STATUS_RUNNING ) {
            return array(
                'success' => false,
                'message' => 'A matching job is already running. Wait for it to complete or stop it first.'
            );
        }
        
        // Get total unmatched addresses
        global $wpdb;
        $table = $wpdb->prefix . 'ss_addresses';
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE matched = 0" );
        
        if ( $total === 0 ) {
            return array(
                'success' => false,
                'message' => 'No unmatched addresses found. All addresses are already matched.'
            );
        }
        
        // Initialize job state
        update_option( self::OPTION_JOB_STATUS, self::STATUS_RUNNING, false );
        update_option( self::OPTION_JOB_PROGRESS, array(
            'total' => $total,
            'processed' => 0,
            'matched' => 0,
            'failed' => 0,
            'batch_count' => 0,
            'started_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' )
        ), false );
        
        // Clear old logs
        delete_option( self::OPTION_JOB_LOGS );
        self::add_log( 'INFO', "Background matching job started. Total addresses: {$total}" );
        
        // Schedule first batch immediately
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }
        
        // Spawn WP-Cron immediately to ensure it runs
        spawn_cron();
        
        return array(
            'success' => true,
            'message' => "Background matching started. Processing {$total} addresses.",
            'total' => $total
        );
    }
    
    /**
     * Stop the current matching job
     * 
     * @return array Result with success flag and message
     */
    public static function stop_job() {
        $status = self::get_job_status();
        
        if ( $status !== self::STATUS_RUNNING ) {
            return array(
                'success' => false,
                'message' => 'No running job to stop.'
            );
        }
        
        // Cancel scheduled events
        wp_clear_scheduled_hook( self::CRON_HOOK );
        
        // Update status
        update_option( self::OPTION_JOB_STATUS, self::STATUS_PAUSED, false );
        self::add_log( 'INFO', 'Background matching job stopped by user.' );
        
        $progress = self::get_job_progress();
        
        return array(
            'success' => true,
            'message' => 'Background matching stopped.',
            'progress' => $progress
        );
    }
    
    /**
     * Resume a paused matching job
     * 
     * @return array Result with success flag and message
     */
    public static function resume_job() {
        $status = self::get_job_status();
        
        if ( $status !== self::STATUS_PAUSED ) {
            return array(
                'success' => false,
                'message' => 'No paused job to resume.'
            );
        }
        
        update_option( self::OPTION_JOB_STATUS, self::STATUS_RUNNING, false );
        self::add_log( 'INFO', 'Background matching job resumed.' );
        
        // Schedule next batch
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK );
        }
        
        // Spawn WP-Cron to resume processing
        spawn_cron();
        
        return array(
            'success' => true,
            'message' => 'Background matching resumed.'
        );
    }
    
    /**
     * Process one batch of addresses
     * This is called by WP-Cron
     */
    public static function process_batch() {
        // Log that batch was triggered
        self::add_log( 'DEBUG', 'process_batch() called by WP-Cron' );
        
        // Check if job should still be running
        $status = self::get_job_status();
        if ( $status !== self::STATUS_RUNNING ) {
            self::add_log( 'WARNING', "Batch processing called but job status is '{$status}', not RUNNING. Skipping." );
            return;
        }
        
        // Load Overpass matcher
        if ( ! class_exists( 'Subsales_Overpass_Matcher' ) ) {
            self::set_job_error( 'Overpass Matcher class not loaded.' );
            return;
        }
        
        // Get current progress
        $progress = self::get_job_progress();
        $batch_num = $progress['batch_count'] + 1;
        
        self::add_log( 'INFO', "Processing batch #{$batch_num}..." );
        
        // Process one batch
        $result = Subsales_Overpass_Matcher::match_addresses();
        
        if ( ! $result['success'] ) {
            self::set_job_error( 'Batch processing failed: ' . ( $result['message'] ?? 'Unknown error' ) );
            return;
        }
        
        // Update progress
        $progress['processed'] += $result['total'] ?? 0;
        $progress['matched'] += $result['matched'] ?? 0;
        $progress['failed'] += $result['failed'] ?? 0;
        $progress['batch_count'] = $batch_num;
        $progress['updated_at'] = current_time( 'mysql' );
        
        update_option( self::OPTION_JOB_PROGRESS, $progress, false );
        
        $percent = $progress['total'] > 0 ? round( ( $progress['processed'] / $progress['total'] ) * 100 ) : 0;
        self::add_log( 'SUCCESS', "Batch #{$batch_num} complete: {$result['total']} processed, {$result['matched']} matched. Progress: {$percent}% ({$progress['processed']}/{$progress['total']})" );
        
        // Check if complete
        if ( $result['complete'] || $result['remaining'] === 0 ) {
            update_option( self::OPTION_JOB_STATUS, self::STATUS_COMPLETE, false );
            $progress['completed_at'] = current_time( 'mysql' );
            update_option( self::OPTION_JOB_PROGRESS, $progress, false );
            
            $duration = strtotime( $progress['completed_at'] ) - strtotime( $progress['started_at'] );
            self::add_log( 'SUCCESS', "🎉 Background matching job COMPLETE! Total: {$progress['processed']} addresses, {$progress['matched']} matched, {$progress['failed']} failed. Duration: {$duration}s" );
        } else {
            // Schedule next batch (stagger by 2 seconds to respect rate limits)
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                $scheduled_time = time() + 2;
                wp_schedule_single_event( $scheduled_time, self::CRON_HOOK );
                self::add_log( 'DEBUG', "Next batch scheduled for " . date( 'Y-m-d H:i:s', $scheduled_time ) );
            }
            // Trigger WP-Cron to process the scheduled event
            spawn_cron();
        }
    }
    
    /**
     * Get current job status
     * 
     * @return string Status constant
     */
    public static function get_job_status() {
        return get_option( self::OPTION_JOB_STATUS, self::STATUS_IDLE );
    }
    
    /**
     * Get current job progress
     * 
     * @return array Progress data
     */
    public static function get_job_progress() {
        return get_option( self::OPTION_JOB_PROGRESS, array(
            'total' => 0,
            'processed' => 0,
            'matched' => 0,
            'failed' => 0,
            'batch_count' => 0,
            'started_at' => null,
            'updated_at' => null,
            'completed_at' => null
        ) );
    }
    
    /**
     * Get job logs
     * 
     * @param int $limit Maximum number of logs to return
     * @return array Log entries
     */
    public static function get_job_logs( $limit = 50 ) {
        $logs = get_option( self::OPTION_JOB_LOGS, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }
        
        // Return most recent logs first
        return array_slice( array_reverse( $logs ), 0, $limit );
    }
    
    /**
     * Add a log entry
     * 
     * @param string $level Log level (INFO, SUCCESS, WARNING, ERROR)
     * @param string $message Log message
     */
    private static function add_log( $level, $message ) {
        $logs = get_option( self::OPTION_JOB_LOGS, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }
        
        $logs[] = array(
            'timestamp' => current_time( 'mysql' ),
            'level' => $level,
            'message' => $message
        );
        
        // Keep only last 200 log entries
        if ( count( $logs ) > 200 ) {
            $logs = array_slice( $logs, -200 );
        }
        
        update_option( self::OPTION_JOB_LOGS, $logs, false );
    }
    
    /**
     * Set job to error state
     * 
     * @param string $message Error message
     */
    private static function set_job_error( $message ) {
        update_option( self::OPTION_JOB_STATUS, self::STATUS_ERROR, false );
        self::add_log( 'ERROR', $message );
        
        // Cancel any scheduled batches
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
    
    /**
     * Get complete job status including progress and recent logs
     * Useful for AJAX status polling
     * 
     * @return array Complete job status
     */
    public static function get_complete_status() {
        $status = self::get_job_status();
        $progress = self::get_job_progress();
        $logs = self::get_job_logs( 10 ); // Last 10 logs
        
        $percent = $progress['total'] > 0 
            ? round( ( $progress['processed'] / $progress['total'] ) * 100, 1 ) 
            : 0;
        
        return array(
            'status' => $status,
            'progress' => $progress,
            'percent' => $percent,
            'logs' => $logs,
            'is_running' => $status === self::STATUS_RUNNING,
            'is_complete' => $status === self::STATUS_COMPLETE,
            'has_error' => $status === self::STATUS_ERROR
        );
    }
    
    /**
     * Cleanup job state
     * Called on plugin deactivation
     */
    public static function cleanup() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_option( self::OPTION_JOB_STATUS );
        delete_option( self::OPTION_JOB_PROGRESS );
        delete_option( self::OPTION_JOB_LOGS );
    }
    
    /**
     * Reset job (clear all state)
     * Useful for debugging or starting fresh
     */
    public static function reset_job() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
        delete_option( self::OPTION_JOB_STATUS );
        delete_option( self::OPTION_JOB_PROGRESS );
        delete_option( self::OPTION_JOB_LOGS );
        
        return array(
            'success' => true,
            'message' => 'Background matching job reset successfully.'
        );
    }
}

// Initialize on plugin load
Subsales_Background_Matcher::init();
