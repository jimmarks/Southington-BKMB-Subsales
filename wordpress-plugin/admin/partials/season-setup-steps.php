<?php
/**
 * Season Setup wizard - one step's body.
 *
 * Rendered only through Subsales_Season_Setup::render_step(), i.e. only when
 * someone actually opens that step in the modal. Nothing in here runs on a
 * normal Settings page load.
 *
 * In scope: $step (int 1-7), $status (array from Subsales_Season_Setup::status()),
 *           $args (array, step-specific extras).
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$season_label = $status['season_label'] !== '' ? $status['season_label'] : 'no season yet';
?>
<div class="subsales-newseason-step" data-step="<?php echo intval( $step ); ?>">

<?php if ( 1 === $step ) : ?>

    <h2>Step 1 &mdash; Start the season</h2>
    <p class="subsales-newseason-status">
        Right now everything is filed under: <strong><?php echo esc_html( $season_label ); ?></strong>
        <?php if ( $status['season_id'] ) : ?>
            &nbsp;&mdash;&nbsp;<?php echo esc_html( $status['teams'] ); ?> team(s),
            <?php echo esc_html( $status['sales_days'] ); ?> sale day(s).
        <?php endif; ?>
    </p>
    <p>A season is one year of the fundraiser. Starting a new one keeps all the old records &mdash;
       it just retires last year's teams and files everything you do from now on under the new name.
       <strong>Only do this once a year.</strong></p>

    <form data-op="season" class="subsales-newseason-form">
        <input type="hidden" name="step" value="1" />
        <p>
            <label for="subsales_new_season_label"><strong>Name for this season</strong></label><br />
            <input type="text" id="subsales_new_season_label" name="season_label" class="regular-text"
                   placeholder="e.g. <?php echo esc_attr( date( 'Y' ) . '-' . ( intval( date( 'Y' ) ) + 1 ) ); ?>" />
        </p>
        <p><button type="submit" class="button button-primary">Start this season</button>
           <span class="description">Skip this if you already started the season above.</span></p>
    </form>

<?php elseif ( 2 === $step ) : ?>

    <?php
    // Only this season's days - get_campaigns() defaults to the current season,
    // and without a season it would hand back every season's dates at once.
    $sale_days = $status['season_id'] ? Subsales_Database::get_campaigns( 'all' ) : array();
    ?>
    <h2>Step 2 &mdash; Set the sale days</h2>
    <p class="subsales-newseason-status">
        <?php if ( ! $status['season_id'] ) : ?>
            <span class="subsales-newseason-todo">!</span>
            Start the season in step 1 first &mdash; sale days are filed under a season.
        <?php elseif ( count( $sale_days ) > 0 ) : ?>
            <span class="subsales-newseason-ok">&#10003;</span>
            <?php echo esc_html( count( $sale_days ) ); ?> sale day(s) set for
            <strong><?php echo esc_html( $season_label ); ?></strong>.
        <?php else : ?>
            <span class="subsales-newseason-todo">!</span>
            No sale days yet for <strong><?php echo esc_html( $season_label ); ?></strong>.
            Sellers can't sign up until you pick at least one.
        <?php endif; ?>
    </p>

    <?php if ( $status['season_id'] ) : ?>
        <p>Pick each day the kids are going out, then click Save. To take a day off the list,
           click Remove next to it &mdash; days that already have sellers signed up can't be
           removed here.</p>

        <p class="subsales-newseason-addday">
            <label for="subsales-newseason-date"><strong>Sale day</strong></label>
            <input type="date" id="subsales-newseason-date" class="js-newseason-datefield" />
            <button type="button" class="button js-newseason-adddate">Add</button>
        </p>

        <form data-op="sales_days" class="subsales-newseason-form">
            <input type="hidden" name="step" value="2" />
            <ul class="subsales-newseason-days js-newseason-datelist">
                <?php foreach ( $sale_days as $sale_day ) : ?>
                    <li>
                        <input type="hidden" name="dates[]" value="<?php echo esc_attr( $sale_day['campaign_date'] ); ?>" />
                        <span><?php echo esc_html( wp_date( 'D M j, Y', strtotime( $sale_day['campaign_date'] . ' 12:00:00' ) ) ); ?></span>
                        <button type="button" class="button-link js-newseason-removedate">Remove</button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="js-newseason-noday"<?php echo count( $sale_days ) ? ' style="display:none"' : ''; ?>>
                No sale days on the list yet.</p>
            <p><button type="submit" class="button button-primary">Save the sale days</button></p>
        </form>
    <?php endif; ?>

<?php elseif ( 3 === $step ) : ?>

    <h2>Step 3 &mdash; Update the roster</h2>
    <p class="subsales-newseason-status">
        <span class="<?php echo $status['members'] > 0 ? 'subsales-newseason-ok' : 'subsales-newseason-todo'; ?>"><?php echo $status['members'] > 0 ? '&#10003;' : '!'; ?></span>
        <?php echo esc_html( $status['members'] ); ?> people on the roster across
        <?php echo esc_html( $status['teams'] ); ?> team(s) for
        <strong><?php echo esc_html( $season_label ); ?></strong>.
    </p>
    <p>Upload this year's spreadsheet (a <code>.csv</code> file with a teams section and a people section).
       You'll see exactly what will change before anything is saved. Nothing is ever deleted &mdash;
       people and teams already there are updated, and new ones are added.</p>

    <div id="subsales-roster-upload">
        <form data-op="roster_preview" class="subsales-newseason-form" enctype="multipart/form-data">
            <input type="hidden" name="step" value="3" />
            <p><input type="file" name="roster_file" accept=".csv,text/csv" required /></p>
            <p><button type="submit" class="button">Check this file</button></p>
        </form>

        <?php $preview = isset( $args['roster_preview'] ) ? $args['roster_preview'] : null; ?>
        <?php if ( $preview ) : ?>
            <h3>Here's what's in the file</h3>
            <div class="subsales-newseason-preview">
                <h4>Teams (<?php echo count( $preview['teams'] ); ?>)</h4>
                <table class="widefat striped">
                    <thead><tr><th>Team</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ( $preview['teams'] as $team ) : ?>
                        <tr>
                            <td><?php echo esc_html( $team['name'] ); ?></td>
                            <td><?php echo esc_html( $team['status'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <h4>People (<?php echo count( $preview['users'] ); ?>)</h4>
                <table class="widefat striped">
                    <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Status</th><th>Teams</th></tr></thead>
                    <tbody>
                    <?php foreach ( $preview['users'] as $user ) : ?>
                        <tr>
                            <td><?php echo esc_html( $user['name'] ); ?></td>
                            <td><?php echo esc_html( $user['phone'] ); ?></td>
                            <td><?php echo esc_html( $user['email'] ); ?></td>
                            <td><?php echo esc_html( $user['status'] ); ?></td>
                            <td><?php echo esc_html( implode( ', ', (array) $user['teams'] ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form data-op="roster_confirm" class="subsales-newseason-form">
                <input type="hidden" name="step" value="3" />
                <input type="hidden" name="import_data" value="<?php echo esc_attr( wp_json_encode( array( 'teams' => $preview['teams'], 'users' => $preview['users'] ) ) ); ?>" />
                <p><button type="submit" class="button button-primary">Looks right &mdash; save the roster</button></p>
            </form>
        <?php endif; ?>
    </div>

<?php elseif ( 4 === $step ) : ?>

    <h2>Step 4 &mdash; Check the pricing</h2>
    <p class="subsales-newseason-status">
        <span class="<?php echo $status['products_visible'] > 0 ? 'subsales-newseason-ok' : 'subsales-newseason-todo'; ?>"><?php echo $status['products_visible'] > 0 ? '&#10003;' : '!'; ?></span>
        <?php echo esc_html( $status['products_visible'] ); ?> of
        <?php echo esc_html( $status['products_total'] ); ?> item(s) are showing in the seller app.
    </p>
    <p>These are the items and prices sellers see when they take an order. Untick "Visible" to hide
       an item without deleting it. Up to 10 items.</p>
    <?php
    // Namespaced ids: the Settings "Products" panel is always in the page too.
    $products_editor_prefix    = 'wiz_';
    $products_editor_in_wizard = true;
    include SUBSALES_PLUGIN_PATH . 'admin/partials/products-editor.php';
    ?>

<?php elseif ( 5 === $step ) : ?>

    <?php $is_individual = ( 'user' === $status['sales_mode'] ); ?>
    <h2>Step 5 &mdash; How are people selling this year?</h2>
    <p class="subsales-newseason-status">
        <span class="subsales-newseason-ok">&#10003;</span>
        Currently set to <strong><?php echo $is_individual ? 'Individual' : 'Teams'; ?></strong>.
    </p>
    <form data-op="mode" class="subsales-newseason-form">
        <input type="hidden" name="step" value="5" />
        <p>
            <label><input type="radio" name="sales_mode" value="legacy" <?php checked( ! $is_individual ); ?> />
                <strong>Teams</strong> &mdash; kids go out together on a sale day and the team's orders are tallied together.</label>
        </p>
        <p>
            <label><input type="radio" name="sales_mode" value="user" <?php checked( $is_individual ); ?> />
                <strong>Individual</strong> &mdash; each kid sells on their own and their own orders are tallied to them.</label>
        </p>
        <p><button type="submit" class="button button-primary">Save this choice</button></p>
    </form>

<?php elseif ( 6 === $step ) : ?>

    <h2>Step 6 &mdash; Refresh the addresses</h2>
    <p class="subsales-newseason-status">
        <span class="<?php echo $status['addresses'] > 0 ? 'subsales-newseason-ok' : 'subsales-newseason-todo'; ?>"><?php echo $status['addresses'] > 0 ? '&#10003;' : '!'; ?></span>
        <?php echo esc_html( number_format_i18n( $status['addresses'] ) ); ?> addresses loaded.
        <?php if ( $status['last_generated'] ) : ?>
            Last sent to the seller app: <strong><?php echo esc_html( $status['last_generated'] ); ?></strong>.
        <?php else : ?>
            They have not been sent to the seller app yet.
        <?php endif; ?>
    </p>
    <p>Addresses usually only need attention if the town added streets or the seller app is missing houses.
       If the count above looks right, you can move on.</p>
    <p><button type="button" class="button js-newseason-goto-addresses">Open Address Management</button>
       <span class="description">This closes the setup window and takes you to the Address Management tab on this same page.</span></p>

<?php elseif ( 7 === $step ) :
    $admin_phone = (string) get_option( 'subsales_admin_contact_phone', '' );
?>

    <h2>Step 7 &mdash; Who do customers call?</h2>

    <p class="subsales-newseason-msg">
        The subsales chair changes from year to year, so this is set per season.
    </p>

    <form class="subsales-newseason-form" method="post" data-op="admin_contact">
        <?php wp_nonce_field( Subsales_Season_Setup::NONCE ); ?>
        <input type="hidden" name="step" value="7" />

        <label for="subsales_admin_contact_phone"><strong>Subsales admin phone number</strong></label>
        <input type="tel"
               id="subsales_admin_contact_phone"
               name="admin_contact_phone"
               value="<?php echo esc_attr( $admin_phone ); ?>"
               placeholder="8604187663"
               inputmode="tel"
               maxlength="14"
               class="subsales-newseason-phone" />

        <p class="description subsales-newseason-fieldnote">
            This phone number will be displayed on customer receipts and listed as the admin
            number to call if there is a sales issue. Please enter a number that you'd like to
            be used for those purposes.
        </p>

        <h3 class="subsales-newseason-previewhead">Where it shows up</h3>

        <div class="subsales-preview-grid">
            <div class="subsales-preview-card">
                <div class="subsales-preview-cap">1 &middot; The text receipt a customer gets</div>
                <div class="subsales-sms-bubble" id="adminPhoneSmsPreview">Loading&hellip;</div>
                <p class="subsales-preview-note" id="adminPhoneSmsCounts"></p>
            </div>

            <div class="subsales-preview-card">
                <div class="subsales-preview-cap">2 &middot; What a seller sees on a card-paid order</div>
                <div class="subsales-phone-mock">
                    <div class="subsales-phone-mock-label">Customer name</div>
                    <div class="subsales-phone-mock-input">Jane Doe</div>
                    <div class="subsales-phone-mock-label">Address</div>
                    <div class="subsales-phone-mock-input">14 Maple St, Southington, CT</div>
                    <div class="subsales-phone-mock-lock" id="adminPhoneLockPreview">
                        This order was paid by card, so the items can't be changed here.
                        Please ask the customer to contact the subsales administrator.
                    </div>
                    <div class="subsales-phone-mock-row">
                        <div><div class="subsales-phone-mock-label">Turkey</div><div class="subsales-phone-mock-input is-locked">2</div></div>
                        <div><div class="subsales-phone-mock-label">Ham</div><div class="subsales-phone-mock-input is-locked">0</div></div>
                        <div><div class="subsales-phone-mock-label">Combo</div><div class="subsales-phone-mock-input is-locked">1</div></div>
                    </div>
                </div>
            </div>
        </div>

        <p class="submit"><button type="submit" class="button button-primary">Save contact number</button></p>
    </form>

    <script>
    (function(){
        var input   = document.getElementById('subsales_admin_contact_phone');
        var smsEl   = document.getElementById('adminPhoneSmsPreview');
        var cntEl   = document.getElementById('adminPhoneSmsCounts');
        var lockEl  = document.getElementById('adminPhoneLockPreview');
        if (!input || !smsEl) { return; }

        var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'subsales_preview_sms_receipt' ) ); ?>;
        var ajaxUrl  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
        // Whatever the receipt template actually is right now, with the token
        // appended if it is not already used - so the preview shows the number
        // in place even before the admin edits their template.
        var template = <?php
            $tpl = (string) get_option( 'subsales_sms_receipt_template', Subsales_SMS_Queue::DEFAULT_TEMPLATE );
            if ( false === strpos( $tpl, '{adminphone}' ) ) {
                $tpl = rtrim( $tpl ) . ' Questions? Call {adminphone}.';
            }
            echo wp_json_encode( $tpl );
        ?>;

        function fmt(d){
            d = (d || '').replace(/\D/g, '');
            return d.length === 10 ? (d.slice(0,3) + '-' + d.slice(3,6) + '-' + d.slice(6)) : '';
        }

        function renderLock(){
            var p = fmt(input.value);
            lockEl.textContent = p
                ? ("This order was paid by card, so the items can't be changed here. Please ask the customer to call the subsales administrator at " + p + ".")
                : "This order was paid by card, so the items can't be changed here. Please ask the customer to contact the subsales administrator.";
        }

        var timer = null;
        function renderSms(){
            var fd = new FormData();
            fd.append('action', 'subsales_preview_sms_receipt');
            fd.append('nonce', nonce);
            fd.append('template', template);
            fd.append('admin_phone', input.value);
            fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(j){
                    if (!j || !j.success) { smsEl.textContent = 'Could not build a preview.'; cntEl.textContent = ''; return; }
                    smsEl.textContent = j.data.body;
                    cntEl.textContent = j.data.chars + ' characters, ' + j.data.segments +
                        ' segment' + (j.data.segments === 1 ? '' : 's') + ' (' + j.data.encoding + ')';
                })
                .catch(function(){ smsEl.textContent = 'Could not build a preview.'; cntEl.textContent = ''; });
        }

        function refresh(){ renderLock(); clearTimeout(timer); timer = setTimeout(renderSms, 250); }
        input.addEventListener('input', refresh);
        refresh();
    })();
    </script>

<?php elseif ( 8 === $step ) : ?>

    <?php $open = ( 1 === $status['sales_enabled'] ); ?>
    <h2>Step 7 &mdash; Open sales</h2>
    <p class="subsales-newseason-status">
        <span class="<?php echo $open ? 'subsales-newseason-ok' : 'subsales-newseason-todo'; ?>"><?php echo $open ? '&#10003;' : '!'; ?></span>
        Sales are currently <strong><?php echo $open ? 'OPEN' : 'CLOSED'; ?></strong>.
    </p>
    <p>This is the master switch. When it's off, nobody can take an order in the seller app &mdash;
       useful between sale days or once the fundraiser is over.</p>
    <form data-op="sales" class="subsales-newseason-form">
        <input type="hidden" name="step" value="7" />
        <p>
            <label><input type="checkbox" name="sales_enabled" value="1" <?php checked( $open ); ?> />
                <strong>Sellers can take orders</strong></label>
        </p>
        <p><button type="submit" class="button button-primary">Save</button></p>
    </form>

<?php endif; ?>

</div>
