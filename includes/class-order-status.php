<?php
/**
 * Apply the configured order status after checkout.
 *
 * @package PHPAVEL_SFC
 */

defined( 'ABSPATH' ) || exit;

final class PHPAVEL_SFC_Order_Status {

	/** @var array<int,bool> Orders processed during the current request. */
	private static $orders = array();

	/** @var array<int,bool> Orders whose status has already been restored once. */
	private static $reapplied = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		// Classic checkout.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_classic_checkout' ), 999, 3 );
		// Block checkout / Store API.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_checkout' ), 999, 1 );

		// Payment gateways (BACS, COD, ...) can change the status after the
		// checkout hook. Re-apply our status once in the same request, but let
		// later payment updates (webhooks, retries) behave normally.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'reenforce' ), 999, 4 );
		add_action( 'shutdown', array( __CLASS__, 'lock' ) );
	}

	/**
	 * Classic checkout callback.
	 *
	 * @param int      $order_id    Order ID.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order       Order object.
	 */
	public static function on_classic_checkout( $order_id, $posted_data = array(), $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		self::apply( $order );
	}

	/**
	 * Block checkout / Store API callback.
	 *
	 * The same hook is fired by the "pay for order" endpoint, so only apply the
	 * status when the order is the draft order of the current checkout session.
	 *
	 * @param WC_Order $order Order object.
	 */
	public static function on_store_api_checkout( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( (int) WC()->session->get( 'store_api_draft_order', 0 ) !== $order->get_id() ) {
			return;
		}

		self::apply( $order );
	}

	/**
	 * Store the configured status when an order is placed.
	 *
	 * @param WC_Order $order Order object.
	 */
	private static function apply( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Only the first checkout request for an order may change its status.
		// Order-pay, retries and later requests keep standard WooCommerce flow.
		if ( 'yes' === $order->get_meta( '_phpavel_sfc_status_applied' ) ) {
			return;
		}

		$target = self::target_status( $order );

		if ( '' === $target ) {
			return;
		}

		$order->update_meta_data( '_phpavel_sfc_target_status', $target );
		$order->save();

		self::$orders[ $order->get_id() ] = true;

		if ( self::bare( $target ) !== $order->get_status() ) {
			$order->update_status( $target );
		}
	}

	/**
	 * Re-apply the configured status when a payment gateway changes it in the
	 * same request (for example BACS moves the order to "on hold").
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param WC_Order $order      Order object.
	 */
	public static function reenforce( $order_id, $old_status, $new_status, $order ) {
		if ( empty( self::$orders[ $order_id ] ) ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( '_phpavel_sfc_status_applied' ) ) {
			return;
		}

		// Do not turn a failed/cancelled payment into a successful-looking order.
		if ( in_array( $new_status, array( 'failed', 'cancelled', 'refunded' ), true ) ) {
			return;
		}

		if ( ! empty( self::$reapplied[ $order_id ] ) ) {
			return;
		}

		$target = (string) $order->get_meta( '_phpavel_sfc_target_status' );

		if ( '' === $target || self::bare( $target ) === $new_status ) {
			return;
		}

		self::$reapplied[ $order_id ] = true;
		$order->update_status( $target );
	}

	/**
	 * Mark orders processed in this request as handled, so a payment webhook in
	 * a later request can use its own status.
	 */
	public static function lock() {
		foreach ( array_keys( self::$orders ) as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				$order->update_meta_data( '_phpavel_sfc_status_applied', 'yes' );
				$order->save();
			}
		}

		self::$orders     = array();
		self::$reapplied  = array();
	}

	/**
	 * Configured status of the plugin's shipping method used by the order.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Status with the "wc-" prefix, or an empty string.
	 */
	private static function target_status( $order ) {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		if ( empty( $statuses ) ) {
			return '';
		}

		foreach ( $order->get_shipping_methods() as $shipping ) {
			if ( PHPAVEL_SFC::METHOD_ID !== $shipping->get_method_id() ) {
				continue;
			}

			foreach ( PHPAVEL_SFC::instances() as $instance ) {
				if ( (int) $instance->instance_id !== (int) $shipping->get_instance_id() ) {
					continue;
				}

				$status = (string) $instance->get_option( 'order_status', '' );

				if ( '' !== $status && isset( $statuses[ $status ] ) ) {
					return $status;
				}
			}
		}

		return '';
	}

	/**
	 * Remove the "wc-" prefix used by status keys.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function bare( $status ) {
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}
}