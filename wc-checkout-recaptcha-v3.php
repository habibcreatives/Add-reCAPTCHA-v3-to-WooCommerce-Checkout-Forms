<?php
/**
 * Plugin Name: Woo Checkout reCAPTCHA v3
 * Description: Adds Google reCAPTCHA v3 protection to WooCommerce checkout.
 * Version: 1.0.1
 * Author: Adnan Habib
 * Author URI: https://youtube.com/@adnanhabib
 * Requires PHP: 7.2
 * Requires Plugins: woocommerce
 * Requires at least: 6.0
 * WC requires at least: 9.0
 * WC tested up to: 10.3
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declare compatibility with WooCommerce HPOS (custom order tables).
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( FeaturesUtil::class ) ) {
        FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Checkout_Recaptcha_V3 {

    const OPTION_SITE_KEY   = 'wccrv3_site_key';
    const OPTION_SECRET_KEY = 'wccrv3_secret_key';
    const OPTION_THRESHOLD  = 'wccrv3_score_threshold';

    public function __construct() {
        // WooCommerce dependency check
        register_activation_hook( __FILE__, array( $this, 'on_activation' ) );
        add_action( 'plugins_loaded', array( $this, 'check_woocommerce_active' ) );

        // Admin: settings
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );

        // Frontend: scripts + field + validation
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_recaptcha_script' ) );
        add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'add_hidden_token_field' ) );
        add_action( 'woocommerce_checkout_process', array( $this, 'verify_recaptcha' ) );
    }

    /**
     * Activation: require WooCommerce.
     */
    public function on_activation() {
        if ( ! $this->is_woocommerce_active() ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die(
                'Woo Checkout reCAPTCHA v3 requires WooCommerce to be installed and active.',
                'Plugin dependency check',
                array( 'back_link' => true )
            );
        }
    }

    /**
     * Show admin notice if WooCommerce becomes inactive later.
     */
    public function check_woocommerce_active() {
        if ( ! $this->is_woocommerce_active() && is_admin() ) {
            add_action(
                'admin_notices',
                function () {
                    echo '<div class="notice notice-error"><p>';
                    echo esc_html__( 'Woo Checkout reCAPTCHA v3 requires WooCommerce to be active.', 'wccrv3' );
                    echo '</p></div>';
                }
            );
        }
    }

    private function is_woocommerce_active() {
        return class_exists( 'WooCommerce' );
    }

    /**
     * Register plugin options.
     */
    public function register_settings() {
        register_setting( 'wccrv3_settings_group', self::OPTION_SITE_KEY );
        register_setting( 'wccrv3_settings_group', self::OPTION_SECRET_KEY );
        register_setting( 'wccrv3_settings_group', self::OPTION_THRESHOLD );

        // Default threshold 0.5 if not set.
        if ( get_option( self::OPTION_THRESHOLD, '' ) === '' ) {
            update_option( self::OPTION_THRESHOLD, '0.5' );
        }
    }

    /**
     * Add submenu under WooCommerce.
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            'Checkout reCAPTCHA v3',
            'Checkout reCAPTCHA v3',
            'manage_woocommerce',
            'wccrv3-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Settings page HTML.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $site_key   = get_option( self::OPTION_SITE_KEY, '' );
        $secret_key = get_option( self::OPTION_SECRET_KEY, '' );
        $threshold  = get_option( self::OPTION_THRESHOLD, '0.5' );
        ?>
        <div class="wrap">
            <h1>Woo Checkout reCAPTCHA v3</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wccrv3_settings_group' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wccrv3_site_key">Site key</label></th>
                        <td>
                            <input type="text" id="wccrv3_site_key"
                                   name="<?php echo esc_attr( self::OPTION_SITE_KEY ); ?>"
                                   value="<?php echo esc_attr( $site_key ); ?>" class="regular-text"/>
                            <p class="description">Google reCAPTCHA v3 site key.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wccrv3_secret_key">Secret key</label></th>
                        <td>
                            <input type="text" id="wccrv3_secret_key"
                                   name="<?php echo esc_attr( self::OPTION_SECRET_KEY ); ?>"
                                   value="<?php echo esc_attr( $secret_key ); ?>" class="regular-text"/>
                            <p class="description">Google reCAPTCHA v3 secret key.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="wccrv3_score_threshold">Score threshold</label></th>
                        <td>
                            <input type="number" step="0.1" min="0" max="1"
                                   id="wccrv3_score_threshold"
                                   name="<?php echo esc_attr( self::OPTION_THRESHOLD ); ?>"
                                   value="<?php echo esc_attr( $threshold ); ?>"/>
                            <p class="description">
                                Minimum score required to pass (0–1). Default 0.5. Lower = less strict, higher = more strict.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Load reCAPTCHA v3 JS only on checkout page.
     */
    public function enqueue_recaptcha_script() {
        if ( ! $this->should_load_on_this_request() ) {
            return;
        }

        $site_key = trim( get_option( self::OPTION_SITE_KEY, '' ) );
        if ( empty( $site_key ) ) {
            return; // Not configured.
        }

        // Google reCAPTCHA v3 script.
        wp_enqueue_script(
            'wccrv3-recaptcha',
            'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ),
            array(),
            null,
            true
        );

        // JS glue: request token and place it into hidden field.
        $inline_js = <<<JS
(function() {
    var siteKey = '{$site_key}';

    function setToken(token) {
        var el = document.getElementById('wccrv3_token');
        if (el) {
            el.value = token;
        }
    }

    if (typeof grecaptcha !== 'undefined') {
        grecaptcha.ready(function () {
            grecaptcha.execute(siteKey, {action: 'checkout'}).then(setToken);

            // Refresh token right before form submit for better accuracy.
            if (typeof jQuery !== 'undefined') {
                jQuery(function($){
                    $('form.checkout').on('submit.wccrv3', function(e){
                        var currentToken = $('#wccrv3_token').val();
                        if (!currentToken) {
                            e.preventDefault();
                            grecaptcha.execute(siteKey, {action: 'checkout'}).then(function(token){
                                setToken(token);
                                $('form.checkout').off('submit.wccrv3').submit();
                            });
                        }
                    });
                });
            }
        });
    }
})();
JS;

        wp_add_inline_script( 'wccrv3-recaptcha', $inline_js );
    }

    /**
     * Add hidden field to checkout form where token will be stored.
     */
    public function add_hidden_token_field() {
        if ( ! $this->should_load_on_this_request() ) {
            return;
        }

        $site_key   = trim( get_option( self::OPTION_SITE_KEY, '' ) );
        $secret_key = trim( get_option( self::OPTION_SECRET_KEY, '' ) );

        // If not configured, silently skip so site doesn't break.
        if ( empty( $site_key ) || empty( $secret_key ) ) {
            return;
        }

        echo '<input type="hidden" name="g-recaptcha-response" id="wccrv3_token" value="" />';
        // Badge is automatically added by Google in bottom-right when script is loaded.
    }

    /**
     * Verify token server-side during checkout processing.
     */
    public function verify_recaptcha() {
        if ( ! $this->should_load_on_this_request() ) {
            return;
        }

        $site_key   = trim( get_option( self::OPTION_SITE_KEY, '' ) );
        $secret_key = trim( get_option( self::OPTION_SECRET_KEY, '' ) );

        // Don't block checkout if admin forgot to configure keys.
        if ( empty( $site_key ) || empty( $secret_key ) ) {
            return;
        }

        if ( empty( $_POST['g-recaptcha-response'] ) ) {
            wc_add_notice( __( 'reCAPTCHA verification failed. Please try again.', 'wccrv3' ), 'error' );
            return;
        }

        $token = sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) );

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            array(
                'timeout' => 8,
                'body'    => array(
                    'secret'   => $secret_key,
                    'response' => $token,
                    'remoteip' => isset( $_SERVER['REMOTE_ADDR'] )
                        ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
                        : '',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            wc_add_notice( __( 'reCAPTCHA request failed. Please try again.', 'wccrv3' ), 'error' );
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( empty( $data ) || empty( $data['success'] ) ) {
            wc_add_notice( __( 'reCAPTCHA verification failed. Please try again.', 'wccrv3' ), 'error' );
            return;
        }

        $score     = isset( $data['score'] ) ? (float) $data['score'] : 0;
        $threshold = (float) get_option( self::OPTION_THRESHOLD, '0.5' );

        if ( $score < $threshold ) {
            wc_add_notice( __( 'We could not verify that you are human. Please try again.', 'wccrv3' ), 'error' );
        }
    }

    /**
     * Only act on real checkout page (not order-received).
     */
    private function should_load_on_this_request() {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return false;
        }

        if ( function_exists( 'is_checkout' ) && is_checkout()
             && ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) ) {
            return true;
        }

        return false;
    }
}

new WC_Checkout_Recaptcha_V3();
