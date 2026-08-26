<?php
/**
 * Seasons Admin Page
 * Lists seasons and provides the "Start New Season" action.
 *
 * @package Subsales_Management
 * @since 3.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Two tabs, branched server-side (the Teams page pattern) so only the active
// tab's queries run: "Seasons" (default) and "Sales Days" (admin/campaigns-page.php).
$active_tab = ( isset( $_GET['tab'] ) && $_GET['tab'] === 'sales-days' ) ? 'sales-days' : 'seasons';

if ( $active_tab === 'seasons' ) :

$notice  = '';
$preview = null;

// Step 1: preview - validate the new label and show what's about to happen,
// without writing anything yet.
if ( isset( $_POST['subsales_season_action'] ) && $_POST['subsales_season_action'] === 'preview' ) {
    check_admin_referer( 'subsales_start_season_preview' );

    $new_label = isset( $_POST['new_season_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_season_label'] ) ) : '';

    if ( $new_label === '' ) {
        $notice = '<div class="notice notice-error"><p>Please enter a label for the new season.</p></div>';
    } else {
        $current_season_id = intval( get_option( 'subsales_current_season_id' ) );
        $current_season    = null;
        foreach ( Subsales_Database::get_seasons() as $season ) {
            if ( intval( $season['id'] ) === $current_season_id ) {
                $current_season = $season;
                break;
            }
        }

        $preview = array(
            'new_label'      => $new_label,
            'current_season' => $current_season,
            'counts'         => $current_season_id ? Subsales_Database::get_season_counts( $current_season_id ) : array( 'teams' => 0, 'campaigns' => 0, 'members' => 0 ),
        );
    }
}

// Step 2: confirm - actually start the new season.
if ( isset( $_POST['subsales_season_action'] ) && $_POST['subsales_season_action'] === 'confirm' ) {
    check_admin_referer( 'subsales_start_season_confirm' );

    $new_label = isset( $_POST['new_season_label'] ) ? sanitize_text_field( wp_unslash( $_POST['new_season_label'] ) ) : '';
    $result    = Subsales_Database::start_new_season( $new_label );

    if ( is_wp_error( $result ) ) {
        $notice = '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
    } else {
        $notice = '<div class="notice notice-success"><p><strong>New season started.</strong> '
            . esc_html( $result['teams_deactivated'] ) . ' team(s) from the prior season were marked inactive. Nothing was deleted.</p></div>';
    }
}

$seasons           = Subsales_Database::get_seasons();
$current_season_id = intval( get_option( 'subsales_current_season_id' ) );

endif; // $active_tab === 'seasons'
?>

<div class="wrap subsales-seasons-wrap">
    <h1><?php esc_html_e( 'Seasons', 'subsales-management' ); ?></h1>

    <h2 class="nav-tab-wrapper">
        <a href="?page=subsales-seasons" class="nav-tab <?php echo $active_tab === 'seasons' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Seasons', 'subsales-management' ); ?></a>
        <a href="?page=subsales-seasons&amp;tab=sales-days" class="nav-tab <?php echo $active_tab === 'sales-days' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Sales Days', 'subsales-management' ); ?></a>
    </h2>

<?php if ( $active_tab === 'sales-days' ) :
    // campaigns-page.php opens its own `.wrap` with its own <h1>. Both are
    // redundant under a tab, so strip them rather than nesting a second .wrap.
    // Include it exactly once - its jQuery handlers are direct binds.
    ob_start();
    include SUBSALES_PLUGIN_PATH . 'admin/campaigns-page.php';
    $sales_days_html = ob_get_clean();
    echo preg_replace(
        '#\A\s*<div class="wrap subsales-campaigns-wrap">\s*<h1>.*?</h1>#s',
        '<div class="subsales-campaigns-wrap">',
        $sales_days_html,
        1
    ); // phpcs:ignore -- markup produced by the included template
    ?>

<?php else : ?>

    <p><?php esc_html_e( 'Each season is a distinct year of teams and sales days. Starting a new season never deletes anything - it retires the prior season\'s teams and makes everything created from here on belong to the new one.', 'subsales-management' ); ?></p>

    <?php echo $notice; // phpcs:ignore -- built entirely from esc_html() calls above ?>

    <h2><?php esc_html_e( 'All Seasons', 'subsales-management' ); ?></h2>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Label', 'subsales-management' ); ?></th>
                <th><?php esc_html_e( 'Status', 'subsales-management' ); ?></th>
                <th><?php esc_html_e( 'Teams', 'subsales-management' ); ?></th>
                <th><?php esc_html_e( 'Campaigns', 'subsales-management' ); ?></th>
                <th><?php esc_html_e( 'Members', 'subsales-management' ); ?></th>
                <th><?php esc_html_e( 'Created', 'subsales-management' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $seasons ) ) : ?>
                <tr><td colspan="6"><?php esc_html_e( 'No seasons yet.', 'subsales-management' ); ?></td></tr>
            <?php else : ?>
                <?php foreach ( $seasons as $season ) :
                    $counts    = Subsales_Database::get_season_counts( $season['id'] );
                    $is_current = intval( $season['id'] ) === $current_season_id;
                ?>
                <tr<?php echo $is_current ? ' style="font-weight:bold;"' : ''; ?>>
                    <td><?php echo esc_html( $season['label'] ); ?></td>
                    <td><?php echo $is_current ? esc_html__( 'Current', 'subsales-management' ) : esc_html__( 'Past', 'subsales-management' ); ?></td>
                    <td><?php echo esc_html( $counts['teams'] ); ?></td>
                    <td><?php echo esc_html( $counts['campaigns'] ); ?></td>
                    <td><?php echo esc_html( $counts['members'] ); ?></td>
                    <td><?php echo esc_html( $season['created_at'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <hr />

    <?php if ( $preview ) : ?>

        <h2><?php esc_html_e( 'Confirm: Start New Season', 'subsales-management' ); ?></h2>
        <div class="notice notice-warning inline">
            <p>
                <?php if ( $preview['current_season'] ) : ?>
                    <?php
                    printf(
                        /* translators: 1: current season label, 2: team count, 3: member count, 4: campaign count, 5: new season label */
                        esc_html__( 'The current season "%1$s" has %2$d team(s), %3$d member(s), and %4$d campaign day(s). Starting the new season "%5$s" will mark all %2$d of those teams inactive. Nothing is deleted - all history stays exactly as it is and remains queryable.', 'subsales-management' ),
                        esc_html( $preview['current_season']['label'] ),
                        intval( $preview['counts']['teams'] ),
                        intval( $preview['counts']['members'] ),
                        intval( $preview['counts']['campaigns'] ),
                        esc_html( $preview['new_label'] )
                    );
                    ?>
                <?php else : ?>
                    <?php esc_html_e( 'No current season is set yet - this will become the first current season.', 'subsales-management' ); ?>
                <?php endif; ?>
            </p>
        </div>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=subsales-seasons' ) ); ?>">
            <?php wp_nonce_field( 'subsales_start_season_confirm' ); ?>
            <input type="hidden" name="subsales_season_action" value="confirm" />
            <input type="hidden" name="new_season_label" value="<?php echo esc_attr( $preview['new_label'] ); ?>" />
            <p>
                <button type="submit" class="button button-primary" onclick="return confirm('This will mark the current season\'s teams inactive. Continue?');">
                    <?php esc_html_e( 'Yes, start the new season', 'subsales-management' ); ?>
                </button>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=subsales-seasons' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'subsales-management' ); ?></a>
            </p>
        </form>

    <?php else : ?>

        <h2><?php esc_html_e( 'Start New Season', 'subsales-management' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=subsales-seasons' ) ); ?>">
            <?php wp_nonce_field( 'subsales_start_season_preview' ); ?>
            <input type="hidden" name="subsales_season_action" value="preview" />
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="new_season_label"><?php esc_html_e( 'New season label', 'subsales-management' ); ?></label></th>
                    <td>
                        <input type="text" id="new_season_label" name="new_season_label" class="regular-text" placeholder="e.g. 2026-2027" required />
                        <p class="description"><?php esc_html_e( 'A name for the upcoming season, e.g. the school year.', 'subsales-management' ); ?></p>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Review', 'subsales-management' ); ?></button>
            </p>
        </form>

    <?php endif; // $preview ?>

<?php endif; // $active_tab ?>
</div>
