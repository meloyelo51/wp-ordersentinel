<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class OS_AbuseIPDB_Actions {
    const ACTION = 'ordersentinel_report_ip';
    const NONCE  = 'ordersentinel_report_ip_nonce';

    public static function bootstrap() {
        add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_report_ip' ] );
        add_action( 'admin_footer', [ __CLASS__, 'render_hidden_form' ] );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_show_notice' ] );
    }

    public static function button_html( $order_id, $ip, $label = 'Report to AbuseIPDB' ) {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_shop_orders' ) ) { return ''; }
        $nonce   = wp_create_nonce( self::NONCE );
        $form_id = 'os-report-ip-form';
        $action  = admin_url( 'admin-post.php?action=' . self::ACTION );
        ob_start(); ?>
        <button type="submit" class="button button-secondary"
                form="<?php echo esc_attr( $form_id ); ?>"
                formaction="<?php echo esc_url( $action ); ?>"
                formmethod="post">
            <?php echo esc_html( $label ); ?>
        </button>
        <input type="hidden" form="<?php echo esc_attr( $form_id ); ?>" name="order_id" value="<?php echo esc_attr( (int) $order_id ); ?>" />
        <input type="hidden" form="<?php echo esc_attr( $form_id ); ?>" name="ip" value="<?php echo esc_attr( $ip ); ?>" />
        <input type="hidden" form="<?php echo esc_attr( $form_id ); ?>" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
        <?php return trim( ob_get_clean() );
    }

    public static function render_hidden_form() { static $printed=false; if($printed){return;} $printed=true; ?>
        <form id="os-report-ip-form" action="<?php echo esc_url( admin_url( 'admin-post.php?action=' . self::ACTION ) ); ?>" method="post" style="display:none"></form>
    <?php }

    public static function handle_report_ip() {
        if ( ! ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' ) ) ) { wp_die( __( 'Insufficient permissions.', 'order-sentinel' ) ); }
        check_admin_referer( self::NONCE );
        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $ip       = isset( $_POST['ip'] ) ? sanitize_text_field( wp_unslash( $_POST['ip'] ) ) : '';
        $ref = wp_get_referer();
        if ( ! $ref && $order_id ) { $ref = admin_url( 'post.php?post=' . $order_id . '&action=edit' ); }
        if ( ! $ref ) { $ref = admin_url(); }
        $ref = add_query_arg( array( 'os_report_ip' => '1', 'os_report_ip_id' => $order_id ), $ref );
        wp_safe_redirect( $ref ); exit;
    }

    public static function maybe_show_notice() {
        if ( isset( $_GET['os_report_ip'] ) && '1' === $_GET['os_report_ip'] ) {
            $order_id = isset( $_GET['os_report_ip_id'] ) ? absint( $_GET['os_report_ip_id'] ) : 0; ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo esc_html( sprintf( __( 'Reported IP for order #%d to AbuseIPDB (handler executed).', 'order-sentinel' ), $order_id ) ); ?></p>
            </div>
        <?php }
    }
}
OS_AbuseIPDB_Actions::bootstrap();

if ( ! function_exists( 'os_render_abuseipdb_button' ) ) {
    function os_render_abuseipdb_button( $order_id, $ip, $label = 'Report to AbuseIPDB' ) {
        return OS_AbuseIPDB_Actions::button_html( $order_id, $ip, $label );
    }
}
