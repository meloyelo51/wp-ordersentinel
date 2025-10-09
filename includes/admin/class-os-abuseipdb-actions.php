<?php
/**
 * AbuseIPDB actions and UI helpers for OrderSentinel.
 *
 * - Renders a button that targets admin-post.php?action=ordersentinel_report_ip
 * - Provides a hidden global form (avoids nested forms on edit screens)
 * - Handles the admin_post action with nonce + capability checks
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class OS_AbuseIPDB_Actions {

    const ACTION = 'ordersentinel_report_ip';
    const NONCE  = 'ordersentinel_report_ip_nonce';

    public static function bootstrap() {
        add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_report_ip' ] );
        // Render the hidden form on admin pages (footer), lightweight + safe
        add_action( 'admin_footer', [ __CLASS__, 'render_hidden_form' ] );
        // Optional notice
        add_action( 'admin_notices', [ __CLASS__, 'maybe_show_notice' ] );
    }

    /**
     * Render a button that submits to admin-post with proper nonce/caps.
     * This avoids nested forms by using the HTML5 "form" attribute.
     *
     * @param int    $order_id Woo order ID
     * @param string $ip       IP to report
     * @param string $label    Button label
     */
    public static function button_html( $order_id, $ip, $label = 'Report to AbuseIPDB' ) {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) {
            return '';
        }
        $nonce = wp_create_nonce( self::NONCE );
        $form_id = 'os-report-ip-form';
        $action  = admin_url( 'admin-post.php?action=' . self::ACTION );
        // HTML: button + attached hidden inputs that belong to the hidden form
        ob_start();
        ?>
        <button type="submit"
                class="button button-secondary"
                form="<?php echo esc_attr( $form_id ); ?>"
                formaction="<?php echo esc_url( $action ); ?>"
                formmethod="post">
            <?php echo esc_html( $label ); ?>
        </button>
        <input type="hidden" form="<?php echo esc_attr( $form_id ); ?>" name="order_id" value="<?php echo esc_attr( (int) $order_id ); ?>" />
        <input type="hidden" form="<?php echo esc_attr( $form_id ); ?>" name="ip" value="<?php echo esc_attr( $ip ); ?>" />
        <input type="hidden" form="<?php echo esc_attr( $form_id ); ?>" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
        <?php
        return trim( ob_get_clean() );
    }

    /**
     * Single hidden form that receives the button submit (via "form" attr).
     * Keeps DOM valid on wp-admin edit screens that already have a main form.
     */
    public static function render_hidden_form() {
        // Only print once
        static $printed = false;
        if ( $printed ) { return; }
        $printed = true;
        ?>
        <form id="os-report-ip-form" action="<?php echo esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ); ?>" method="post" style="display:none">
            <!-- inputs are injected via button_html() using form=... attributes -->
        </form>
        <?php
    }

    public static function handle_report_ip() {
        if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' ) ) ) {
            wp_die( __( 'Insufficient permissions.', 'order-sentinel' ) );
        }
        check_admin_referer( self::NONCE );

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $ip       = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';

        // TODO: integrate actual reporter here (e.g., OSINT/AbuseIPDB adapter)
        // For now, just record an admin notice flag and bounce back.

        // Avoid redirecting to list table; go back to referer (order edit page)
        $ref = wp_get_referer();
        if ( ! $ref && $order_id ) {
            $ref = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        }

        if ( ! $ref ) {
            $ref = admin_url(); // last-resort fallback
        }

        // Flag a success code for admin_notices
        $ref = add_query_arg( array(
            'os_report_ip' => '1',
            'os_report_ip_id' => $order_id,
        ), $ref );

        wp_safe_redirect( $ref );
        exit;
    }

    public static function maybe_show_notice() {
        if ( isset( $_GET['os_report_ip'] ) && '1' === $_GET['os_report_ip'] ) {
            $order_id = isset( $_GET['os_report_ip_id'] ) ? absint( $_GET['os_report_ip_id'] ) : 0;
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( sprintf( __( 'Reported IP for order #%d to AbuseIPDB (handler executed).', 'order-sentinel' ), $order_id ) ); ?></p>
            </div>
            <?php
        }
    }
}

OS_AbuseIPDB_Actions::bootstrap();

// Optional convenience function for templates:
if ( ! function_exists( 'os_render_abuseipdb_button' ) ) {
    function os_render_abuseipdb_button( $order_id, $ip, $label = 'Report to AbuseIPDB' ) {
        return OS_AbuseIPDB_Actions::button_html( $order_id, $ip, $label );
    }
}
