<?php
/**
 * Plugin Name: [PHPAVEL] Кастомный метод доставки с указанием страны-склада
 * Description: Создаёт метод доставки «Доставка из другой страны»: фиксированная стоимость, страна-склад, список товаров и статус заказа после оформления. Корзина не может смешивать товары из разных складов, а способы доставки скрываются в зависимости от содержимого корзины.
 * Author URI:  https://philippovpavel.ru
 * Author:      PhilippovPavel
 *
 * Requires PHP: 8.3
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 *
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Version:     1.1.1
 * Text Domain: phpavel-sfc
 */

defined( 'ABSPATH' ) || exit;

define( 'PHPAVEL_SFC_VERSION', '1.1.1' );
define( 'PHPAVEL_SFC_FILE', __FILE__ );
define( 'PHPAVEL_SFC_PATH', plugin_dir_path( __FILE__ ) );

require_once PHPAVEL_SFC_PATH . 'includes/class-core.php';

/**
 * Load the parts that depend on WooCommerce.
 */
function phpavel_sfc_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Shipping_Method' ) ) {
		add_action( 'admin_notices', 'phpavel_sfc_woocommerce_notice' );
		return;
	}

	require_once PHPAVEL_SFC_PATH . 'includes/class-shipping-method.php';
	require_once PHPAVEL_SFC_PATH . 'includes/class-cart-rules.php';
	require_once PHPAVEL_SFC_PATH . 'includes/class-shipping-filter.php';
	require_once PHPAVEL_SFC_PATH . 'includes/class-order-status.php';

	PHPAVEL_SFC_Cart_Rules::init();
	PHPAVEL_SFC_Shipping_Filter::init();
	PHPAVEL_SFC_Order_Status::init();

	add_filter(
		'woocommerce_shipping_' . PHPAVEL_SFC::METHOD_ID . '_instance_settings_values',
		array( 'PHPAVEL_SFC_Shipping_Method', 'sanitize_settings' ),
		10,
		2
	);
}
add_action( 'plugins_loaded', 'phpavel_sfc_bootstrap', 20 );

/**
 * Register the shipping method.
 *
 * @param array $methods Shipping methods.
 * @return array
 */
function phpavel_sfc_register_method( $methods ) {
	if ( ! class_exists( 'PHPAVEL_SFC_Shipping_Method' ) && class_exists( 'WC_Shipping_Method' ) ) {
		require_once PHPAVEL_SFC_PATH . 'includes/class-shipping-method.php';
	}

	$methods[ PHPAVEL_SFC::METHOD_ID ] = 'PHPAVEL_SFC_Shipping_Method';

	return $methods;
}
add_filter( 'woocommerce_shipping_methods', 'phpavel_sfc_register_method' );

/**
 * Initialize select2 inside the shipping method settings modal.
 *
 * WooCommerce renders the modal markup after the page has loaded, so the
 * enhanced-select initializer has to be triggered again.
 */
function phpavel_sfc_admin_scripts( $hook ) {
	if ( 'woocommerce_page_wc-settings' !== $hook ) {
		return;
	}

	wp_enqueue_script( 'wc-enhanced-select' );
	wp_add_inline_script(
		'wc-enhanced-select',
		'jQuery(function($){$(document.body).on("wc_backbone_modal_loaded",function(){$(document.body).trigger("wc-enhanced-select-init");});});',
		'after'
	);
}
add_action( 'admin_enqueue_scripts', 'phpavel_sfc_admin_scripts' );

/**
 * Tell the merchant that WooCommerce is required.
 */
function phpavel_sfc_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>' . esc_html__( 'Для плагина «Доставка из другой страны» требуется активный WooCommerce.', 'phpavel-sfc' ) . '</p></div>';
}

/**
 * Declare compatibility with HPOS.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
