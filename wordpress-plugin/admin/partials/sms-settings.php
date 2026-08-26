<?php
/**
 * Text message (SMS) settings panel - reusable partial.
 *
 * Follows admin/partials/products-editor.php: own POST handler keyed on its
 * own submit button, own nonce, and action="#tab-sms" so the success notice
 * lands back on this panel instead of the first one.
 *
 * Nothing on this page sends a text message. Saving only changes settings;
 * receipts are queued by the order-created hook and sent by
 * Subsales_SMS_Queue::drain() on its own one-minute cron event. The health
 * line at the top is how the admin can tell that job is alive - without it,
 * a dead cron looks exactly like a quiet day.
 *
 * @package Subsales_Management
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Own save handler + own nonce, mirroring admin/partials/products-editor.php.
// Skipped while doing AJAX for the same reason it is there: nothing should
// save as a side effect of a panel being re-rendered inside an AJAX request.
if ( isset( $_POST['save_sms'] ) && ! wp_doing_ajax() ) {
    check_admin_referer( 'order_sync_settings_nonce' );

    update_option( 'subsales_sms_enabled', isset( $_POST['subsales_sms_enabled'] ) ? 1 : 0 );
    update_option( 'subsales_twilio_account_sid', isset( $_POST['subsales_twilio_account_sid'] ) ? sanitize_text_field( $_POST['subsales_twilio_account_sid'] ) : '' );
    update_option( 'subsales_twilio_auth_token', isset( $_POST['subsales_twilio_auth_token'] ) ? sanitize_text_field( $_POST['subsales_twilio_auth_token'] ) : '' );
    update_option( 'subsales_twilio_from_numbers', isset( $_POST['subsales_twilio_from_numbers'] ) ? sanitize_textarea_field( $_POST['subsales_twilio_from_numbers'] ) : '' );
    update_option( 'subsales_twilio_messaging_service_sid', isset( $_POST['subsales_twilio_messaging_service_sid'] ) ? sanitize_text_field( $_POST['subsales_twilio_messaging_service_sid'] ) : '' );
    update_option( 'subsales_sms_rate_per_second', isset( $_POST['subsales_sms_rate_per_second'] ) ? max( 1, intval( $_POST['subsales_sms_rate_per_second'] ) ) : 1 );
    update_option( 'subsales_sms_daily_cap', isset( $_POST['subsales_sms_daily_cap'] ) ? max( 0, intval( $_POST['subsales_sms_daily_cap'] ) ) : 1000 );
    update_option( 'subsales_sms_receipt_template', isset( $_POST['subsales_sms_receipt_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['subsales_sms_receipt_template'] ) ) : '' );
    update_option( 'subsales_sms_consent_wording', isset( $_POST['subsales_sms_consent_wording'] ) ? sanitize_text_field( wp_unslash( $_POST['subsales_sms_consent_wording'] ) ) : '' );

    echo '<div class="notice notice-success"><p>Text message settings saved.</p></div>';
}

$sms_template = get_option( 'subsales_sms_receipt_template', Subsales_SMS_Queue::DEFAULT_TEMPLATE );
$sms_wording  = get_option( 'subsales_sms_consent_wording', Subsales_SMS_Queue::DEFAULT_CONSENT_WORDING );
$last_drain   = get_option( 'subsales_sms_last_drain', array() );
?>
<h2 style="margin-top:18px">💬 Text Messages</h2>
<p>Send customers a text receipt after they order, so they can check their address and phone number are right.</p>

<?php
// The health line. Everything here depends on a background job running every
// minute; if it stops, nothing sends and nothing looks broken. This is the one
// place that tells the truth about it without opening the Logs page.
if ( empty( $last_drain['at'] ) ) {
    echo '<div class="notice notice-warning inline" style="margin:12px 0"><p><strong>Texts have never been checked for sending.</strong> The background job that sends texts has not run yet. If this still says that in a few minutes, texts will not go out and someone technical needs to look at it.</p></div>';
} else {
    $drain_age   = max( 0, time() - intval( $last_drain['at'] ) );
    $drain_stale = $drain_age > HOUR_IN_SECONDS;
    $queued      = isset( $last_drain['queued'] ) ? intval( $last_drain['queued'] ) : 0;

    $reasons = array(
        'off'               => 'Sending is switched off, so nothing was sent.',
        'not_configured'    => 'The Twilio details below are incomplete, so nothing was sent.',
        'daily_cap_reached' => "Today's limit has been reached — the rest will go out tomorrow.",
    );
    $status = isset( $last_drain['status'] ) ? $last_drain['status'] : 'ok';
    $reason = isset( $reasons[ $status ] ) ? $reasons[ $status ] : '';
    ?>
    <div class="notice <?php echo $drain_stale ? 'notice-warning' : 'notice-info'; ?> inline" style="margin:12px 0">
        <p>
            <strong>Last checked for texts to send:</strong> <?php echo esc_html( human_time_diff( intval( $last_drain['at'] ), time() ) ); ?> ago.
            <?php if ( $drain_stale ) : ?>
                <br><strong>That is longer than an hour — something is wrong.</strong> Texts are checked every minute normally. Until this clears up, texts are not going out (nothing is lost, they are waiting).
            <?php endif; ?>
            <?php if ( '' !== $reason ) : ?>
                <br><?php echo esc_html( $reason ); ?>
            <?php endif; ?>
            <br>
            Last check: sent <?php echo intval( isset( $last_drain['sent'] ) ? $last_drain['sent'] : 0 ); ?>,
            couldn't send <?php echo intval( isset( $last_drain['failed'] ) ? $last_drain['failed'] : 0 ); ?>,
            skipped <?php echo intval( isset( $last_drain['skipped'] ) ? $last_drain['skipped'] : 0 ); ?>.
            <strong><?php echo $queued; ?></strong> waiting to go out.
        </p>
    </div>
    <?php
}
?>

<?php // Posting to "#tab-sms" keeps the hash, so this panel reopens after saving and the notice above is visible. ?>
<form method="post" action="#tab-sms">
    <?php wp_nonce_field( 'order_sync_settings_nonce' ); ?>
    <table class="form-table">
        <tr>
            <th scope="row">Send text receipts</th>
            <td>
                <label>
                    <input type="checkbox" name="subsales_sms_enabled" value="1" <?php checked( get_option( 'subsales_sms_enabled', 0 ), 1 ); ?> />
                    <strong>Turn text receipts on</strong>
                </label>
                <p class="description">Leave this off until the Twilio details below have been tested. This is the master switch — with it off, no text is ever sent, and orders taken while it is off are recorded as "skipped, texting was switched off" rather than piling up to send later.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Twilio Account SID</th>
            <td>
                <input type="text" name="subsales_twilio_account_sid" value="<?php echo esc_attr( get_option( 'subsales_twilio_account_sid', '' ) ); ?>" class="regular-text" />
                <p class="description">On the Twilio Console home page. Starts with "AC".</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Twilio Auth Token</th>
            <td>
                <input type="password" name="subsales_twilio_auth_token" value="<?php echo esc_attr( get_option( 'subsales_twilio_auth_token', '' ) ); ?>" class="regular-text" autocomplete="off" />
                <p class="description">Next to the Account SID in the Twilio Console. Stored in the normal WordPress settings table like every other setting here — the dots are so nobody reads it over your shoulder, not encryption.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Send from</th>
            <td>
                <textarea name="subsales_twilio_from_numbers" rows="3" class="regular-text" placeholder="+18605551234"><?php echo esc_textarea( get_option( 'subsales_twilio_from_numbers', '' ) ); ?></textarea>
                <p class="description">The Twilio phone number customers see the text come from. One per line if you have more than one.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Messaging Service ID</th>
            <td>
                <input type="text" name="subsales_twilio_messaging_service_sid" class="regular-text" placeholder="MG…" value="<?php echo esc_attr( get_option( 'subsales_twilio_messaging_service_sid', '' ) ); ?>" />
                <p class="description">
                    Optional, and only if you set up a Messaging Service in Twilio (starts with <code>MG</code>).
                    <strong>If this is filled in, it is used instead of the phone number above.</strong>
                    A Messaging Service is what lets Twilio send faster by spreading texts across more than one
                    number &mdash; worth having if you are texting a few thousand customers on delivery day.
                    Leave it blank to send from the number above.
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">Texts per second</th>
            <td>
                <input type="number" min="1" step="1" name="subsales_sms_rate_per_second" value="<?php echo esc_attr( get_option( 'subsales_sms_rate_per_second', 1 ) ); ?>" style="width: 100px;" />
                <p class="description">How fast texts are allowed to go out. 1 per second is the safe starting point.</p>
            </td>
        </tr>
        <tr>
            <th scope="row">Most texts per day</th>
            <td>
                <input type="number" min="0" step="1" name="subsales_sms_daily_cap" value="<?php echo esc_attr( get_option( 'subsales_sms_daily_cap', 1000 ) ); ?>" style="width: 100px;" />
                <p class="description">A daily ceiling. Anything over it waits until the next day rather than being lost.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"></th>
            <td>
                <p class="description" style="max-width:640px">
                    <strong>Why these two are boxes you can type in:</strong> the phone companies decide the real limits, and Twilio only shows your actual numbers in its Console once your registration is approved. When you find out what they are, type them in here — nothing needs to be re-installed or re-released.
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">What the text says</th>
            <td>
                <textarea name="subsales_sms_receipt_template" id="subsales_sms_receipt_template" rows="4" class="large-text" style="max-width:640px"><?php echo esc_textarea( $sms_template ); ?></textarea>
                <p class="description" style="max-width:640px">
                    Write the receipt however you like. These words get swapped in for each customer:
                </p>
                <ul class="description" style="max-width:640px; list-style:disc; margin-left:20px">
                    <li><code>{customer}</code> — the customer's name, e.g. "Jane Smith"</li>
                    <li><code>{items}</code> — what they bought, with how many, e.g. "2 Italian, 1 Turkey"</li>
                    <li><code>{total}</code> — what they paid, e.g. "$36.00"</li>
                    <li><code>{org}</code> — your organization name, taken from the Branding setting on the General tab</li>
                </ul>
                <p class="description" style="max-width:640px">
                    <strong>Two things are added automatically if you leave them out:</strong> your organization name, and
                    "Reply STOP to opt out." The phone companies require both on every message, and a text without them can
                    get your number blocked. If you include them yourself, nothing extra is added — the preview below always
                    shows the real, final message.
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">Preview</th>
            <td>
                <div id="sms_preview_body" style="max-width:640px; white-space:pre-wrap; border:1px solid #dcdcde; background:#fff; border-radius:12px; padding:12px; min-height:40px">Loading…</div>
                <p class="description" id="sms_preview_counts" style="max-width:640px"></p>
                <p class="description" style="max-width:640px">
                    <strong>Why the length matters:</strong> a text is billed and sent in chunks of 160 characters (or only 70 if
                    it contains a special character — a curly apostrophe pasted from Word, an emoji, an accent). Once it goes
                    over, each chunk counts as a separate text: against the "texts per second" speed, against the daily limit,
                    and on your Twilio bill. A receipt that needs two chunks takes twice as long to get through everyone and
                    costs twice as much. Shorter is genuinely better here.
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">What the customer is told</th>
            <td>
                <input type="text" name="subsales_sms_consent_wording" value="<?php echo esc_attr( $sms_wording ); ?>" class="large-text" style="max-width:640px" />
                <p class="description" style="max-width:640px">
                    The exact sentence shown to the customer when they give their phone number. This is saved with each
                    customer's record as proof of what they agreed to, so keep it matching what the app actually shows them.
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">Test these details</th>
            <td>
                <p class="description">Checks the Account SID and Auth Token typed in above (not necessarily what's saved yet). No text message is sent.</p>
                <p>
                    <button type="button" id="test_twilio_credentials_btn" class="button">Test Twilio details</button>
                    <span id="twilio_test_status" style="margin-left:12px; font-weight:600"></span>
                </p>
                <div id="twilio_test_output" style="white-space:pre-wrap; border:1px solid #eee; padding:8px; margin-top:8px; display:none"></div>
                <script>
                (function(){
                    const ajaxUrl = ajaxurl;
                    const nonce = <?php echo wp_json_encode( wp_create_nonce( 'subsales_test_twilio_credentials' ) ); ?>;
                    function setStatus(s){ document.getElementById('twilio_test_status').textContent = s; }
                    document.getElementById('test_twilio_credentials_btn').addEventListener('click', function(){
                        const sidField = document.querySelector('input[name="subsales_twilio_account_sid"]');
                        const tokenField = document.querySelector('input[name="subsales_twilio_auth_token"]');
                        const fd = new FormData();
                        fd.append('action', 'subsales_test_twilio_credentials');
                        fd.append('nonce', nonce);
                        fd.append('account_sid', sidField ? sidField.value : '');
                        fd.append('auth_token', tokenField ? tokenField.value : '');
                        const out = document.getElementById('twilio_test_output'); out.style.display='block'; out.textContent='Testing...'; setStatus('Testing...');
                        fetch(ajaxUrl, { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(j){
                            if (!j) { out.textContent = 'No response'; setStatus('Error'); return; }
                            if (!j.success) {
                                out.textContent = 'Error: ' + (j.data && j.data.message ? j.data.message : 'Unknown'); setStatus('Invalid'); return;
                            }
                            out.textContent = (j.data && j.data.message) ? j.data.message : 'OK';
                            setStatus('Valid');
                        }).catch(function(e){ out.textContent = 'Fetch error: ' + e.message; setStatus('Error'); });
                    });
                })();
                </script>
            </td>
        </tr>
    </table>
    <p class="submit"><?php submit_button( 'Save Text Message Settings', 'primary', 'save_sms', false ); ?></p>
</form>
<script>
// The preview is rendered server-side by the same function that builds the real
// message, so there is no second copy of the template rules here to drift out of
// step. This just debounces typing and paints what comes back.
(function(){
    const field = document.getElementById('subsales_sms_receipt_template');
    if (!field) { return; }
    const bodyEl = document.getElementById('sms_preview_body');
    const countEl = document.getElementById('sms_preview_counts');
    const nonce = <?php echo wp_json_encode( wp_create_nonce( 'subsales_preview_sms_receipt' ) ); ?>;
    let timer = null;

    function refresh(){
        const fd = new FormData();
        fd.append('action', 'subsales_preview_sms_receipt');
        fd.append('nonce', nonce);
        fd.append('template', field.value);
        fetch(ajaxurl, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(j){
                if (!j || !j.success) { bodyEl.textContent = 'Could not build a preview.'; countEl.textContent = ''; return; }
                bodyEl.textContent = j.data.body;
                const seg = j.data.segments;
                countEl.textContent = j.data.chars + ' characters — sends as ' + seg + (seg === 1 ? ' text' : ' texts')
                    + ' (' + (j.data.encoding === 'GSM-7' ? 'plain text' : 'contains a special character, so chunks are smaller') + ').';
                countEl.style.color = seg > 1 ? '#b32d2e' : '';
            })
            .catch(function(){ bodyEl.textContent = 'Could not build a preview.'; countEl.textContent = ''; });
    }

    field.addEventListener('input', function(){ clearTimeout(timer); timer = setTimeout(refresh, 400); });
    refresh();
})();
</script>
