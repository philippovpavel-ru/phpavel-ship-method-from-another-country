<?php
/**
 * Cart rules: keep the cart homogeneous with respect to warehouse groups.
 *
 * @package PHPAVEL_SFC
 */

defined( 'ABSPATH' ) || exit;

final class PHPAVEL_SFC_Cart_Rules {

	/** @var bool Avoid repeating the cart-state notice on the same page. */
	private static $notice_added = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 6 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_clear_cart' ) );
		add_action( 'wp_ajax_phpavel_sfc_clear_cart', array( __CLASS__, 'ajax_clear_cart' ) );
		add_action( 'wp_ajax_nopriv_phpavel_sfc_clear_cart', array( __CLASS__, 'ajax_clear_cart' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/**
	 * Frontend script for clearing the cart without leaving the current page.
	 */
	public static function enqueue_scripts() {
		wp_enqueue_script(
			'phpavel-sfc-clear-cart',
			plugins_url( 'assets/js/clear-cart.js', PHPAVEL_SFC_FILE ),
			array(),
			PHPAVEL_SFC_VERSION,
			true
		);

		wp_localize_script(
			'phpavel-sfc-clear-cart',
			'phpavelSfcClearCart',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'phpavel_sfc_clear' ),
			)
		);
	}

	/**
	 * AJAX handler: empty the cart and let the browser reload the current page.
	 */
	public static function ajax_clear_cart() {
		check_ajax_referer( 'phpavel_sfc_clear', 'nonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error();
		}

		WC()->cart->empty_cart();
		wc_clear_notices();

		wp_send_json_success();
	}

	/**
	 * Rules 7–9: a cart can contain either products of one warehouse group,
	 * or products that belong to no group. Mixing is refused.
	 *
	 * @param bool  $passed       Validation result.
	 * @param int   $product_id   Product ID.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation ID.
	 * @param array $variation    Variation data.
	 * @param array $cart_item_data Cart item data.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variation = null, $cart_item_data = null ) {
		if ( ! $passed ) {
			return $passed;
		}

		// Do not interfere with the admin order editor.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $passed;
		}

		$state = PHPAVEL_SFC::cart_state();
		$added = PHPAVEL_SFC::groups_containing( PHPAVEL_SFC::product_key( $product_id, $variation_id ) );

		switch ( $state['mode'] ) {
			case 'empty':
				return $passed;

			case 'plain':
				if ( ! empty( $added ) ) {
					wc_add_notice( self::notice( 'plain_plus_foreign', $product_id ), 'error' );
					return false;
				}
				return $passed;

			case 'foreign':
				if ( ! empty( $added ) && array_intersect( $state['groups'], $added ) ) {
					return $passed;
				}

				wc_add_notice(
					self::notice( empty( $added ) ? 'foreign_plus_plain' : 'other_warehouse', $product_id ),
					'error'
				);
				return false;

			case 'stale':
				wc_add_notice( self::notice( 'stale_cart', 0, $state['stale'] ), 'error' );
				return false;

			case 'mixed':
				wc_add_notice( self::notice( 'mixed_cart' ), 'error' );
				return false;
		}

		return $passed;
	}

	/**
	 * Re-check the cart on the cart and checkout pages (stale session, changed settings).
	 */
	public static function validate_cart() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( self::$notice_added ) {
			return;
		}

		$state = PHPAVEL_SFC::cart_state();

		if ( 'stale' === $state['mode'] ) {
			self::$notice_added = true;
			wc_add_notice( self::notice( 'stale_cart', 0, $state['stale'] ), 'error' );
			return;
		}

		if ( 'mixed' === $state['mode'] ) {
			self::$notice_added = true;
			wc_add_notice( self::notice( 'mixed_cart' ), 'error' );
		}
	}

	/**
	 * Handle the "Clear cart" link from the notice.
	 */
	public static function maybe_clear_cart() {
		if ( is_admin() || empty( $_GET['phpavel_sfc_clear'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! wp_verify_nonce( $nonce, 'phpavel_sfc_clear' ) ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		WC()->cart->empty_cart();
		wc_clear_notices();

		// Stay on the page where the button was clicked. Remove the checkout
		// "add-to-cart" argument as well, otherwise the page could add the
		// product to the cart again on reload.
		$redirect = remove_query_arg( array( 'phpavel_sfc_clear', '_wpnonce', 'add-to-cart' ) );

		if ( ! $redirect ) {
			$redirect = wc_get_cart_url();
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Build the customer-facing notice with action links.
	 *
	 * @param string $case       Notice key.
	 * @param int    $product_id Product ID for a personalised message.
	 * @param int[]  $stale      Product IDs that are no longer in the method's list.
	 * @return string
	 */
	private static function notice( $case, $product_id = 0, $stale = array() ) {
		$name = '';

		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$name = $product->get_name();
			}
		}

		$buttons = '<br><a class="button" href="' . esc_url( wc_get_checkout_url() ) . '">'
			. esc_html__( 'Перейти к оформлению', 'phpavel-sfc' ) . '</a> '
			. '<a class="button" data-phpavel-sfc-clear="1" href="' . esc_url( self::clear_cart_url() ) . '">'
			. esc_html__( 'Очистить корзину', 'phpavel-sfc' ) . '</a>';

		$cart_buttons = '<br><a class="button" href="' . esc_url( wc_get_cart_url() ) . '">'
			. esc_html__( 'Перейти в корзину', 'phpavel-sfc' ) . '</a> '
			. '<a class="button" data-phpavel-sfc-clear="1" href="' . esc_url( self::clear_cart_url() ) . '">'
			. esc_html__( 'Очистить корзину', 'phpavel-sfc' ) . '</a>';

		switch ( $case ) {
			case 'plain_plus_foreign':
				$message = $name
					/* translators: %s: product name. */
					? sprintf( __( 'Товар «%s» доставляется из другой страны и не может находиться в одной корзине с текущими товарами.', 'phpavel-sfc' ), esc_html( $name ) )
					: __( 'Этот товар доставляется из другой страны и не может находиться в одной корзине с текущими товарами.', 'phpavel-sfc' );
				return $message . ' ' . __( 'Завершите оформление текущего заказа или очистите корзину.', 'phpavel-sfc' ) . $buttons;

			case 'foreign_plus_plain':
				return __( 'В корзине есть товары, доставляемые из другой страны. К ним можно добавлять только товары из того же склада.', 'phpavel-sfc' )
					. ' ' . __( 'Завершите текущий заказ или очистите корзину.', 'phpavel-sfc' ) . $buttons;

			case 'other_warehouse':
				$message = $name
					/* translators: %s: product name. */
					? sprintf( __( 'Товар «%s» доставляется из другого склада и несовместим с товарами в корзине.', 'phpavel-sfc' ), esc_html( $name ) )
					: __( 'Этот товар доставляется из другого склада и несовместим с товарами в корзине.', 'phpavel-sfc' );
				return $message . ' ' . __( 'Завершите текущий заказ или очистите корзину.', 'phpavel-sfc' ) . $buttons;

			case 'stale_cart':
				$names = array();

				foreach ( (array) $stale as $stale_id ) {
					$product = wc_get_product( $stale_id );

					if ( $product ) {
						$names[] = esc_html( $product->get_name() );
					}
				}

				if ( empty( $names ) ) {
					$message = __( 'Условия доставки изменились: часть товаров в корзине больше не доставляется выбранным способом.', 'phpavel-sfc' );
				} elseif ( 1 === count( $names ) ) {
					/* translators: %s: product name. */
					$message = sprintf( __( 'Условия доставки изменились: товар «%s» больше не доставляется выбранным способом.', 'phpavel-sfc' ), $names[0] );
				} else {
					$message = sprintf(
						/* translators: %s: comma-separated product names. */
						__( 'Условия доставки изменились: товары «%s» больше не доставляются выбранным способом.', 'phpavel-sfc' ),
						implode( '», «', $names )
					);
				}

				return $message . ' ' . __( 'Удалите эти товары из корзины, чтобы продолжить оформление.', 'phpavel-sfc' ) . $cart_buttons;

			case 'mixed_cart':
			default:
				return __( 'В корзине товары из разных складов — их нельзя оформить одной доставкой. Удалите лишние товары или очистите корзину.', 'phpavel-sfc' ) . $buttons;
		}
	}

	/**
	 * Nonced fallback URL that empties the cart.
	 *
	 * The frontend script handles the click via AJAX and reloads the current
	 * page. Without JavaScript this link falls back to the cart page.
	 *
	 * @return string
	 */
	private static function clear_cart_url() {
		return wp_nonce_url(
			add_query_arg( 'phpavel_sfc_clear', '1', wc_get_cart_url() ),
			'phpavel_sfc_clear'
		);
	}
}
