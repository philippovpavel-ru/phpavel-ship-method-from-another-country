<?php
/**
 * Hide shipping rates that do not match the current cart mode.
 *
 * @package PHPAVEL_SFC
 */

defined( 'ABSPATH' ) || exit;

final class PHPAVEL_SFC_Shipping_Filter {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_rates' ), 20, 2 );
		add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'no_shipping_available' ), 20 );
	}

	/**
	 * When the cart is made of "foreign" products, only this method's rates are
	 * offered. When the cart is made of regular products, this method's rates
	 * are hidden.
	 *
	 * @param array $rates   Shipping rates.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public static function filter_rates( $rates, $package ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $rates;
		}

		$state = PHPAVEL_SFC::cart_state();

		if ( 'empty' === $state['mode'] ) {
			return $rates;
		}

		// There is no valid delivery configuration for a stale or mixed cart:
		// showing regular rates would let the customer order incompatible items.
		if ( 'stale' === $state['mode'] || 'mixed' === $state['mode'] ) {
			return array();
		}

		$filtered = array();

		foreach ( (array) $rates as $rate_id => $rate ) {
			$own = PHPAVEL_SFC::is_own_rate( $rate );

			if ( 'foreign' === $state['mode'] && $own ) {
				$filtered[ $rate_id ] = $rate;
			}

			if ( 'plain' === $state['mode'] && ! $own ) {
				$filtered[ $rate_id ] = $rate;
			}
		}

		return $filtered;
	}

	/**
	 * Explain the "no shipping options" message for foreign carts.
	 *
	 * @param string $message Default message.
	 * @return string
	 */
	public static function no_shipping_available( $message ) {
		$state = PHPAVEL_SFC::cart_state();

		if ( 'foreign' === $state['mode'] ) {
			return wpautop(
				esc_html__( 'Товары в корзине доставляются из другой страны, но для указанного адреса нет подходящего способа доставки. Пожалуйста, свяжитесь с нами, чтобы согласовать доставку.', 'phpavel-sfc' )
			);
		}

		if ( 'stale' === $state['mode'] ) {
			return wpautop(
				esc_html__( 'Условия доставки изменились: часть товаров в корзине больше не доставляется выбранным способом. Удалите эти товары из корзины, чтобы продолжить оформление.', 'phpavel-sfc' )
			);
		}

		if ( 'mixed' === $state['mode'] ) {
			return wpautop(
				esc_html__( 'В корзине товары из разных складов, их нельзя доставить одной посылкой. Удалите лишние товары.', 'phpavel-sfc' )
			);
		}

		return $message;
	}
}
