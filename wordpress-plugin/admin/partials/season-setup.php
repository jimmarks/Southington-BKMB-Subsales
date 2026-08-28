<?php
/**
 * "Set Up Season" Settings tab.
 *
 * Deliberately tiny: every Settings panel's PHP runs on every page load, so
 * this renders a few option reads and a button. All the real work lives in the
 * modal, whose steps load over AJAX (Subsales_Season_Setup).
 *
 * @package Subsales_Management
 * @since 3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SUBSALES_PLUGIN_PATH . 'includes/class-season-setup.php';

$season_setup_id = Subsales_Database::current_season_id();
$season_setup_label = '';
foreach ( Subsales_Database::get_seasons() as $season_setup_row ) {
    if ( intval( $season_setup_row['id'] ) === $season_setup_id ) {
        $season_setup_label = $season_setup_row['label'];
        break;
    }
}
$season_setup_last  = Subsales_Season_Setup::last_run_text();
$season_setup_steps = Subsales_Season_Setup::step_labels();
$season_setup_nonce = wp_create_nonce( Subsales_Season_Setup::NONCE );
?>

<h2 style="margin-top:18px">&#127793; Set Up Season</h2>
<p style="max-width:70ch">
    Everything you need to do to get a new year of the sub sale running &mdash; in order, in one place.
    You can open this any time: it always shows what's set up right now, so it also works as a
    mid-season check that nothing is missing.
</p>

<div class="subsales-status-card" style="max-width:70ch">
    <div class="subsales-card-header">
        <span class="subsales-card-icon">&#128197;</span>
        <div class="subsales-card-title">
            <h3>Current season</h3>
        </div>
    </div>
    <div style="padding:20px">
        <p style="margin-top:0;font-size:15px">
            <strong><?php echo $season_setup_label !== '' ? esc_html( $season_setup_label ) : 'No season started yet'; ?></strong>
        </p>
        <p class="description" style="margin-bottom:16px">
            <?php if ( $season_setup_last ) : ?>
                Setup last run: <?php echo esc_html( $season_setup_last ); ?>
            <?php else : ?>
                Setup has not been run yet.
            <?php endif; ?>
        </p>
        <p style="margin-bottom:0">
            <button type="button" class="button button-primary button-hero" id="subsales-newseason-open">
                Set Up Season
            </button>
        </p>
    </div>
</div>

<div id="subsales-newseason-modal" class="subsales-newseason-modal" style="display:none" role="dialog" aria-modal="true" aria-label="Set Up Season">
    <div class="subsales-newseason-modal-content">
        <div class="subsales-newseason-modal-header">
            <div class="subsales-newseason-modal-title">
                <h2>Set Up Season</h2>
                <button type="button" class="subsales-newseason-modal-close" aria-label="Close">&times;</button>
            </div>
            <ol class="subsales-newseason-progress">
                <?php foreach ( $season_setup_steps as $season_setup_n => $season_setup_text ) : ?>
                    <li>
                        <button type="button" class="subsales-newseason-progress-step" data-goto="<?php echo intval( $season_setup_n ); ?>">
                            <span class="subsales-newseason-progress-num"><?php echo intval( $season_setup_n ); ?></span>
                            <span class="subsales-newseason-progress-label"><?php echo esc_html( $season_setup_text ); ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>

        <div class="subsales-newseason-modal-body">
            <p>Loading&hellip;</p>
        </div>

        <div class="subsales-newseason-modal-footer">
            <button type="button" class="button subsales-newseason-back">&larr; Back</button>
            <div class="subsales-newseason-msg" aria-live="polite"></div>
            <button type="button" class="button subsales-newseason-modal-close">Close</button>
            <button type="button" class="button button-primary subsales-newseason-next">Next &rarr;</button>
        </div>
    </div>
</div>

<script>
(function($){
    var TOTAL = <?php echo intval( Subsales_Season_Setup::TOTAL_STEPS ); ?>;
    var NONCE = <?php echo wp_json_encode( $season_setup_nonce ); ?>;
    var $modal = $('#subsales-newseason-modal');
    var $body  = $modal.find('.subsales-newseason-modal-body');
    var $msg   = $modal.find('.subsales-newseason-msg');
    var step   = 1;
    var moved  = false;

    function say(text, isError) {
        $msg.text(text || '').toggleClass('is-error', !!isError);
    }

    function paint() {
        $modal.find('.subsales-newseason-progress-step')
            .removeClass('is-current')
            .filter('[data-goto="' + step + '"]').addClass('is-current');
        $modal.find('.subsales-newseason-back').prop('disabled', step <= 1);
        $modal.find('.subsales-newseason-next').prop('disabled', step >= TOTAL);
        $body.scrollTop(0);
    }

    function load(n, extra) {
        step = Math.max(1, Math.min(TOTAL, n));
        paint();
        $body.html('<p>Loading&hellip;</p>');
        $.post(ajaxurl, $.extend({
            action: 'subsales_season_setup_step',
            step: step,
            nonce: NONCE
        }, extra || {}))
        .done(function(r){
            if (r && r.success) {
                $body.html(r.data.html);
            } else {
                $body.html('<p>Sorry, that step could not be loaded.</p>');
            }
        })
        .fail(function(){ $body.html('<p>Sorry, that step could not be loaded.</p>'); });
    }

    $('#subsales-newseason-open').on('click', function(){
        // Move out of the (hidden) tab panel so the fixed overlay is never
        // trapped in an ancestor's stacking/display context.
        if (!moved) { $('body').append($modal); moved = true; }
        $modal.show();
        say('');
        load(step);
    });

    function close() { $modal.hide(); say(''); }

    // Scoped strictly to this modal - the campaigns page binds close-on-click to
    // '.subsales-modal-close, .subsales-modal', which we deliberately do not use.
    $modal.on('click', '.subsales-newseason-modal-close', close);
    $modal.on('click', function(e){ if (e.target === this) close(); });
    $(document).on('keydown.subsalesNewSeason', function(e){
        if (e.key === 'Escape' && $modal.is(':visible')) close();
    });

    $modal.on('click', '.subsales-newseason-back', function(){ say(''); load(step - 1); });
    $modal.on('click', '.subsales-newseason-next', function(){ say(''); load(step + 1); });
    $modal.on('click', '.subsales-newseason-progress-step', function(){
        say('');
        load(parseInt($(this).data('goto'), 10) || 1);
    });

    // Step 6's shortcut: close and switch to the Address Management tab, which
    // is already on this same Settings screen.
    $modal.on('click', '.js-newseason-goto-addresses', function(){
        close();
        $('.nav-tab[data-target="#tab-address_extracts"]').trigger('click');
    });

    // Step 2's sale-day list. Delegated, so it survives every re-render.
    function paintDays() {
        $body.find('.js-newseason-noday').toggle(!$body.find('.js-newseason-datelist').children().length);
    }

    $modal.on('click', '.js-newseason-adddate', function(){
        var $field = $body.find('.js-newseason-datefield');
        var date = $field.val();            // <input type="date"> - always YYYY-MM-DD
        if (!date) { say('Pick a date first.', true); return; }

        var $list = $body.find('.js-newseason-datelist');
        if ($list.find('input[value="' + date + '"]').length) {
            say('That day is already on the list.', true);
            return;
        }

        var label = new Date(date + 'T00:00:00')
            .toLocaleDateString(undefined, { weekday:'short', month:'short', day:'numeric', year:'numeric' });
        var $row = $('<li><input type="hidden" name="dates[]" /><span></span>' +
            '<button type="button" class="button-link js-newseason-removedate">Remove</button></li>');
        $row.find('input').val(date);
        $row.find('span').text(label);

        // ISO dates sort as plain strings, so keep the list in date order.
        var $after = $list.children().filter(function(){
            return $(this).find('input').val() > date;
        }).first();
        if ($after.length) { $row.insertBefore($after); } else { $list.append($row); }

        $field.val('');
        say('Added ' + label + '. Click "Save the sale days" when the list is right.');
        paintDays();
    });

    $modal.on('click', '.js-newseason-removedate', function(){
        $(this).closest('li').remove();
        say('Removed from the list. Click "Save the sale days" to apply it.');
        paintDays();
    });

    // Every step's action is a <form data-op="...">, so one handler covers them all.
    $modal.on('submit', 'form[data-op]', function(e){
        e.preventDefault();
        var form = this;
        var $submit = $(form).find('[type="submit"]').prop('disabled', true);
        var fd = new FormData(form);
        fd.append('action', 'subsales_season_setup_save');
        fd.append('op', $(form).data('op'));
        fd.append('nonce', NONCE);
        say('Working…');
        $.ajax({ url: ajaxurl, method: 'POST', data: fd, processData: false, contentType: false })
        .done(function(r){
            var d = (r && r.data) || {};
            if (d.html) { $body.html(d.html); $body.scrollTop(0); }
            say(d.message || (r && r.success ? 'Saved.' : 'Something went wrong.'), !(r && r.success));
        })
        .fail(function(){ say('Something went wrong. Please try again.', true); })
        .always(function(){ $submit.prop('disabled', false); });
    });

    paint();
})(jQuery);
</script>
