<?php
/**
 * Subsales Display Helper
 * 
 * Provides unified methods for common UI rendering across admin pages.
 * Eliminates HTML duplication and ensures consistent formatting.
 * 
 * @package Subsales_Management
 * @since 2.4.74
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Display_Helper {

    /**
     * Initialize display helper
     */
    public static function init() {
        // No hooks needed - pure utility class
    }

    /**
     * Render WordPress-style table start with headers
     * 
     * @param array $columns Column headers ['Column 1', 'Column 2', ...]
     * @param string $id Optional table ID attribute
     * @param array $options Optional settings:
     *                       - 'class' => Additional CSS classes
     *                       - 'style' => Inline styles
     * 
     * @example
     * Subsales_Display_Helper::render_table_start( 
     *     ['Order ID', 'Customer', 'Address', 'Total'],
     *     'orders-table'
     * );
     */
    public static function render_table_start( $columns, $id = '', $options = [] ) {
        $id_attr = $id ? 'id="' . esc_attr( $id ) . '"' : '';
        $class = 'wp-list-table widefat fixed striped';
        
        if ( ! empty( $options['class'] ) ) {
            $class .= ' ' . esc_attr( $options['class'] );
        }
        
        $style = ! empty( $options['style'] ) ? 'style="' . esc_attr( $options['style'] ) . '"' : '';
        
        echo "<table {$id_attr} class=\"{$class}\" {$style}>";
        echo '<thead><tr>';
        
        foreach ( $columns as $col ) {
            echo '<th>' . esc_html( $col ) . '</th>';
        }
        
        echo '</tr></thead>';
        echo '<tbody>';
    }

    /**
     * Render table end tags
     */
    public static function render_table_end() {
        echo '</tbody></table>';
    }

    /**
     * Render badge (status indicator)
     * 
     * @param string $text Badge text
     * @param string $type Badge type: success, warning, danger, info
     * @return string HTML badge
     * 
     * @example
     * echo Subsales_Display_Helper::badge( 'Valid', 'success' );
     */
    public static function badge( $text, $type = 'info' ) {
        $colors = [
            'success' => 'background:#10b981;color:white;',
            'warning' => 'background:#f59e0b;color:white;',
            'danger' => 'background:#ef4444;color:white;',
            'info' => 'background:#3b82f6;color:white;',
            'default' => 'background:#6b7280;color:white;'
        ];
        
        $style = $colors[ $type ] ?? $colors['default'];
        
        return sprintf(
            '<span style="padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;%s">%s</span>',
            esc_attr( $style ),
            esc_html( $text )
        );
    }

    /**
     * Render entry method badge
     * 
     * Shows how GPS coordinates were obtained
     * 
     * @param string $method Entry method: 'gps', 'manual', 'address_autocomplete', etc.
     * @return string HTML badge
     */
    public static function entry_method_badge( $method ) {
        $labels = [
            'gps' => [ 'GPS', 'success' ],
            'manual' => [ 'Manual', 'warning' ],
            'address_autocomplete' => [ 'Autocomplete', 'info' ],
            'geocoded' => [ 'Geocoded', 'info' ],
            '' => [ 'Unknown', 'default' ]
        ];
        
        $label_data = $labels[ $method ] ?? $labels[''];
        return self::badge( $label_data[0], $label_data[1] );
    }

    /**
     * Render validation status badge
     * 
     * @param string $status Validation status
     * @return string HTML badge
     */
    public static function validation_status_badge( $status ) {
        $labels = [
            'valid' => [ 'Valid', 'success' ],
            'approved' => [ 'Approved', 'success' ],
            'pending' => [ 'Pending', 'warning' ],
            'geocode_failed' => [ 'Geocode Failed', 'danger' ],
            'format_invalid' => [ 'Invalid Format', 'danger' ],
            'dismissed' => [ 'Dismissed', 'default' ]
        ];
        
        $label_data = $labels[ $status ] ?? [ $status, 'default' ];
        return self::badge( $label_data[0], $label_data[1] );
    }

    /**
     * Render empty state message
     * 
     * @param string $message Message to display
     * @param string $icon Optional dashicon class (without 'dashicons-' prefix)
     */
    public static function empty_state( $message, $icon = 'info' ) {
        ?>
        <div style="text-align:center;padding:60px 20px;color:#6b7280;">
            <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="font-size:48px;width:48px;height:48px;margin-bottom:16px;"></span>
            <p style="font-size:16px;margin:0;"><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Render loading spinner
     * 
     * @param string $message Optional loading message
     */
    public static function loading_spinner( $message = 'Loading...' ) {
        ?>
        <div style="text-align:center;padding:40px 20px;">
            <div class="spinner is-active" style="float:none;margin:0 auto 16px;"></div>
            <p style="color:#6b7280;"><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Render error message box
     * 
     * @param string $message Error message
     * @param bool $dismissible Whether error is dismissible
     */
    public static function error_box( $message, $dismissible = false ) {
        $class = 'notice notice-error' . ( $dismissible ? ' is-dismissible' : '' );
        ?>
        <div class="<?php echo esc_attr( $class ); ?>">
            <p><strong>Error:</strong> <?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Render success message box
     * 
     * @param string $message Success message
     * @param bool $dismissible Whether message is dismissible
     */
    public static function success_box( $message, $dismissible = true ) {
        $class = 'notice notice-success' . ( $dismissible ? ' is-dismissible' : '' );
        ?>
        <div class="<?php echo esc_attr( $class ); ?>">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Render info message box
     * 
     * @param string $message Info message
     * @param bool $dismissible Whether message is dismissible
     */
    public static function info_box( $message, $dismissible = false ) {
        $class = 'notice notice-info' . ( $dismissible ? ' is-dismissible' : '' );
        ?>
        <div class="<?php echo esc_attr( $class ); ?>">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Render warning message box
     * 
     * @param string $message Warning message
     * @param bool $dismissible Whether message is dismissible
     */
    public static function warning_box( $message, $dismissible = false ) {
        $class = 'notice notice-warning' . ( $dismissible ? ' is-dismissible' : '' );
        ?>
        <div class="<?php echo esc_attr( $class ); ?>">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    }

    /**
     * Render distance with appropriate unit and formatting
     * 
     * @param float $distance_miles Distance in miles
     * @return string Formatted distance
     */
    public static function format_distance( $distance_miles ) {
        if ( $distance_miles < 0.1 ) {
            // Show feet for very short distances
            $feet = round( $distance_miles * 5280 );
            return $feet . ' ft';
        } else {
            return number_format( $distance_miles, 2 ) . ' mi';
        }
    }

    /**
     * Render money amount with proper formatting
     * 
     * @param float $amount Amount in dollars
     * @return string Formatted money string
     */
    public static function format_money( $amount ) {
        return '$' . number_format( $amount, 2 );
    }

    /**
     * Render page section header
     * 
     * @param string $title Section title
     * @param string $description Optional description
     * @param array $actions Optional action buttons [['label' => '...', 'url' => '...', 'class' => '...']]
     */
    public static function section_header( $title, $description = '', $actions = [] ) {
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:12px;border-bottom:2px solid #e5e7eb;">
            <div>
                <h2 style="margin:0;font-size:23px;"><?php echo esc_html( $title ); ?></h2>
                <?php if ( $description ) : ?>
                    <p style="margin:4px 0 0;color:#6b7280;"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $actions ) ) : ?>
                <div>
                    <?php foreach ( $actions as $action ) : ?>
                        <a href="<?php echo esc_url( $action['url'] ); ?>" 
                           class="button <?php echo esc_attr( $action['class'] ?? '' ); ?>">
                            <?php echo esc_html( $action['label'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

}
