<?php
/**
 * The page a customer lands on after paying by card.
 *
 * Square sends them somewhere the moment the payment clears, and that used to be
 * the site root - somebody who had just handed money to a child at their door got
 * a homepage and no confirmation of anything.
 *
 * Rendered from a shortcode rather than typed into page content, so the season's
 * admin contact number stays current without anyone editing a page, and so the
 * text-receipt line disappears when SMS is switched off rather than promising a
 * message that will never arrive.
 *
 * @package Subsales_Management
 * @since 3.48.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Subsales_Thank_You {

    const PAGE_OPTION = 'subsales_thank_you_page_id';

    public static function init() {
        add_shortcode( 'subsales_thank_you', array( __CLASS__, 'render' ) );
    }

    /**
     * Where Square should send a paying customer.
     *
     * @return string The thank-you page, or the site root if one has not been made yet.
     */
    public static function url() {
        $page_id = intval( get_option( self::PAGE_OPTION, 0 ) );
        if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
            return get_permalink( $page_id );
        }
        return home_url();
    }

    public static function render() {
        $org   = get_option( 'subsales_branding', 'Southington Subsales' );
        $phone = (string) get_option( 'subsales_admin_contact_phone', '' );
        $pretty_phone = ( class_exists( 'Subsales_Season_Setup' ) && '' !== $phone )
            ? Subsales_Season_Setup::format_phone( $phone )
            : $phone;

        $sms_on = (bool) get_option( 'subsales_sms_enabled', false );

        // Square appends its own ids to the redirect. Show a short reference so
        // somebody with a question has something to quote - and nothing that
        // says who they are.
        $ref = '';
        foreach ( array( 'orderId', 'order_id', 'checkoutId', 'transactionId' ) as $key ) {
            if ( ! empty( $_GET[ $key ] ) ) {
                $ref = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) ), 0, 8 ) );
                break;
            }
        }

        ob_start();
        ?>
        <div class="subsales-ty">
            <div class="subsales-ty-card">
                <div class="subsales-ty-tick" aria-hidden="true">&#10003;</div>
                <h1>Thank you!</h1>
                <p class="subsales-ty-lead">Your payment went through and your sub order is in.</p>

                <?php if ( '' !== $ref ) : ?>
                    <p class="subsales-ty-ref">Reference <strong><?php echo esc_html( $ref ); ?></strong></p>
                <?php endif; ?>

                <h2>What happens now</h2>
                <ul>
                    <li>Your subs are made fresh and delivered on delivery day. The student who took your order brings them to the address you gave.</li>
                    <li>Nothing else is needed from you &mdash; you have already paid, so there is nothing to hand over at the door.</li>
                    <?php if ( $sms_on ) : ?>
                        <li>If you asked us to text you, a receipt is on its way to that number, and we will message you about delivery.</li>
                    <?php endif; ?>
                    <li>Square has emailed its own card receipt to the address you gave them.</li>
                </ul>

                <?php if ( '' !== $pretty_phone ) : ?>
                    <div class="subsales-ty-contact">
                        <h2>Something not right?</h2>
                        <p>Call or text the sub sale organiser on <a href="tel:<?php echo esc_attr( preg_replace( '/\D/', '', $phone ) ); ?>"><?php echo esc_html( $pretty_phone ); ?></a>. Please don't chase the student &mdash; a paid order can't be changed on their phone.</p>
                    </div>
                <?php endif; ?>

                <p class="subsales-ty-thanks">Thank you for supporting <?php echo esc_html( $org ); ?>.</p>
            </div>
        </div>
        <style>
            .subsales-ty{max-width:640px;margin:0 auto;padding:24px 16px 48px;
                font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
            .subsales-ty-card{background:#fff;border:1px solid #e3e6ea;border-radius:12px;padding:28px 24px;
                box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.06)}
            .subsales-ty-tick{width:56px;height:56px;border-radius:50%;background:#e8f5ec;color:#1e7a44;
                font-size:30px;line-height:56px;text-align:center;margin:0 auto 16px}
            .subsales-ty h1{text-align:center;margin:0 0 8px;font-size:1.9rem;color:#12263f}
            .subsales-ty-lead{text-align:center;margin:0 0 20px;font-size:1.05rem;color:#4a5568}
            .subsales-ty-ref{text-align:center;margin:0 0 20px;font-size:.85rem;color:#6b7280;
                font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
            .subsales-ty h2{font-size:1.05rem;margin:22px 0 8px;color:#12263f}
            .subsales-ty ul{margin:0;padding-left:20px;color:#374151}
            .subsales-ty li{margin:8px 0;line-height:1.5}
            .subsales-ty-contact{margin-top:8px;padding:14px 16px;background:#f7f9fc;border-left:4px solid #2d6cdf;border-radius:4px}
            .subsales-ty-contact p{margin:0;color:#374151;line-height:1.5}
            .subsales-ty-contact a{color:#2d6cdf;font-weight:600;white-space:nowrap}
            .subsales-ty-thanks{margin:24px 0 0;text-align:center;color:#4a5568;font-weight:600}
            @media (max-width:480px){ .subsales-ty-card{padding:22px 18px} .subsales-ty h1{font-size:1.6rem} }
        </style>
        <?php
        return ob_get_clean();
    }
}
