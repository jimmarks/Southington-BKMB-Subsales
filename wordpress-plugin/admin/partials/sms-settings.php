<?php
/**
 * Text message (SMS) settings panel - reusable partial.
 *
 * Follows admin/partials/products-editor.php: own POST handler keyed on its
 * own submit button, own nonce, and action="#tab-sms" so the success notice
 * lands back on this panel instead of the first one.
 *
 * Settings only. Nothing on this page sends a text message - the sending
 * machinery is not built yet.
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
    update_option( 'subsales_sms_rate_per_second', isset( $_POST['subsales_sms_rate_per_second'] ) ? max( 1, intval( $_POST['subsales_sms_rate_per_second'] ) ) : 1 );
    update_option( 'subsales_sms_daily_cap', isset( $_POST['subsales_sms_daily_cap'] ) ? max( 0, intval( $_POST['subsales_sms_daily_cap'] ) ) : 1000 );

    echo '<div class="notice notice-success"><p>Text message settings saved.</p></div>';
}
?>
<h2 style="margin-top:18px">💬 Text Messages</h2>
<p>Send customers a text receipt after they order, so they can check their address and phone number are right.</p>
<p><strong>Not switched on yet.</strong> The part of the system that actually sends the texts is still being built. Filling this in now does nothing except save the details for later.</p>

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
                <p class="description">Leave this off until the Twilio details below have been tested. This is the master switch — with it off, no text is ever sent.</p>
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
