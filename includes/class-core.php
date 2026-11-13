<?php
/**
 * Shared helpers: shipping method instances, product groups and cart state.
 *
 * @package PHPAVEL_SFC
 */

defined( 'ABSPATH' ) || exit;

final class PHPAVEL_SFC {

	const METHOD_ID = 'phpavel_ship_from_country';

	/** @var array|null Unique product lists: signature => int[] product IDs. */
	private static $groups = null;

	/** @var array|null All configured instances of the shipping method. */
	private static $instances = null;

	/**
	 * Reset per-request caches (called after settings are saved).
	 */
	public static function flush_cache() {
		self::$groups    = null;
		self::$instances = null;
	}

	/**
	 * Every instance of this shipping method, in every zone (including "Other locations").
	 *
	 * @return WC_Shipping_Method[]
	 */
	public static function instances() {
		if ( null !== self::$instances ) {
			return self::$instances;
		}

		self::$instances = array();

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return self::$instances;
		}

		$zones = WC_Shipping_Zones::get_zones();

		$rest_of_world = WC_Shipping_Zones::get_zone( 0 );
		if ( $rest_of_world ) {
			$zones[] = array( 'shipping_methods' => $rest_of_world->get_shipping_methods() );
		}

		foreach ( $zones as $zone ) {
			if ( empty( $zone['shipping_methods'] ) ) {
				continue;
			}

			foreach ( (array) $zone['shipping_methods'] as $method ) {
				if ( isset( $method->id ) && self::METHOD_ID === $method->id ) {
					self::$instances[] = $method;
				}
			}
		}

		return self::$instances;
	}

	/**
	 * Unique product lists configured for the method.
	 *
	 * Instances with identical lists are merged, so the same warehouse duplicated
	 * across several shipping zones counts as one group.
	 *
	 * @return array<string,int[]> Signature => product IDs.
	 */
	public static function groups() {
		if ( null !== self::$groups ) {
			return self::$groups;
		}

		self::$groups = array();

		foreach ( self::instances() as $instance ) {
			if ( ! method_exists( $instance, 'get_option' ) ) {
				continue;
			}

			$products = self::normalize_product_ids( (array) $instance->get_option( 'products', array() ) );

			if ( empty( $products ) ) {
				continue;
			}

			sort( $products );
			self::$groups[ implode( ',', $products ) ] = $products;
		}

		return self::$groups;
	}

	/**
	 * Group signatures that contain a product.
	 *
	 * @param int $product_key Normalized (parent) product ID.
	 * @return string[]
	 */
	public static function groups_containing( $product_key ) {
		$product_key = (int) $product_key;
		$found       = array();

		foreach ( self::groups() as $signature => $products ) {
			if ( in_array( $product_key, $products, true ) ) {
				$found[] = (string) $signature;
			}
		}

		return $found;
	}

	/**
	 * Normalize a product ID: variations are compared by their parent product.
	 *
	 * @param int $product_id   Product or variation ID.
	 * @param int $variation_id Variation ID (optional).
	 * @return int
	 */
	public static function product_key( $product_id, $variation_id = 0 ) {
		$id = $variation_id ? (int) $variation_id : (int) $product_id;

		if ( ! $id ) {
			return 0;
		}

		$parent = wp_get_post_parent_id( $id );

		return $parent ? (int) $parent : $id;
	}

	/**
	 * Normalize a list of product IDs, mapping variations to their parents.
	 *
	 * @param array $ids Product IDs.
	 * @return int[]
	 */
	public static function normalize_product_ids( $ids ) {
		$normalized = array();

		foreach ( (array) $ids as $id ) {
			$id = self::product_key( absint( $id ) );
			if ( $id ) {
				$normalized[] = $id;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	/**
	 * Current cart mode.
	 *
	 * - empty:   no items.
	 * - plain:   only products that belong to no group.
	 * - foreign: all products share at least one group.
	 * - stale:   some products belong to a group, some do not. This happens when
	 *            the merchant removes a product from the method's list after the
	 *            customer has already added it to the cart.
	 * - mixed:   incompatible products from different warehouse groups.
	 *
	 * @return array{mode:string,groups:string[],stale:int[]}
	 */
	public static function cart_state() {
		$cart = function_exists( 'WC' ) ? WC()->cart : null;

		if ( ! $cart || ! $cart->get_cart() ) {
			return array(
				'mode'   => 'empty',
				'groups' => array(),
				'stale'  => array(),
			);
		}

		$common    = null;
		$has_plain = false;
		$plain     = array();
		$mixed     = false;

		foreach ( $cart->get_cart() as $item ) {
			$key = self::product_key(
				isset( $item['product_id'] ) ? $item['product_id'] : 0,
				isset( $item['variation_id'] ) ? $item['variation_id'] : 0
			);

			$sets = self::groups_containing( $key );

			if ( empty( $sets ) ) {
				$has_plain = true;
				$plain[]   = $key;
				continue;
			}

			$common = ( null === $common ) ? $sets : array_values( array_intersect( $common, $sets ) );

			if ( empty( $common ) ) {
				$mixed = true;
			}
		}

		$plain = array_values( array_unique( array_filter( $plain ) ) );

		if ( $mixed ) {
			return array(
				'mode'   => 'mixed',
				'groups' => array(),
				'stale'  => $plain,
			);
		}

		if ( $has_plain && null === $common ) {
			return array(
				'mode'   => 'plain',
				'groups' => array(),
				'stale'  => array(),
			);
		}

		if ( $has_plain ) {
			return array(
				'mode'   => 'stale',
				'groups' => array(),
				'stale'  => $plain,
			);
		}

		if ( empty( $common ) ) {
			return array(
				'mode'   => 'mixed',
				'groups' => array(),
				'stale'  => array(),
			);
		}

		return array(
			'mode'   => 'foreign',
			'groups' => $common,
			'stale'  => array(),
		);
	}

	/**
	 * Whether a shipping rate belongs to this plugin's method.
	 *
	 * @param WC_Shipping_Rate $rate Rate.
	 * @return bool
	 */
	public static function is_own_rate( $rate ) {
		return is_object( $rate ) && method_exists( $rate, 'get_method_id' ) && self::METHOD_ID === $rate->get_method_id();
	}
}
