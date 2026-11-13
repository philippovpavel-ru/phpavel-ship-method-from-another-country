<?php
/**
 * The "Shipping from another country" method.
 *
 * @package PHPAVEL_SFC
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	return;
}

class PHPAVEL_SFC_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Fixed shipping cost.
	 *
	 * Declared explicitly: without it PHP 8.2+ reports a dynamic property
	 * deprecation when the value is assigned in init().
	 *
	 * @var string
	 */
	public $cost = '0';

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping method instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = PHPAVEL_SFC::METHOD_ID;
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Доставка из другой страны', 'phpavel-sfc' );
		$this->method_description = __( 'Доставка товаров из указанных стран-складов. Способ показывается только для товаров из своего списка; остальные способы доставки в это время скрываются.', 'phpavel-sfc' );
		$this->supports           = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );

		$this->init();

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Load settings.
	 */
	public function init() {
		$this->instance_form_fields = $this->fields();

		$this->title      = $this->get_option( 'title', $this->method_title );
		$this->tax_status = $this->get_option( 'tax_status', 'taxable' );
		$this->cost       = $this->get_option( 'cost', '0' );
	}

	/**
	 * Instance form fields.
	 *
	 * @return array
	 */
	private function fields() {
		$countries = array();

		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
		}

		return array(
			'title'        => array(
				'title'       => __( 'Название способа', 'phpavel-sfc' ),
				'type'        => 'text',
				'description' => __( 'Показывается покупателю при оформлении заказа.', 'phpavel-sfc' ),
				'default'     => __( 'Доставка из другой страны', 'phpavel-sfc' ),
				'desc_tip'    => true,
			),
			'tax_status'   => array(
				'title'   => __( 'Статус налога', 'phpavel-sfc' ),
				'type'    => 'select',
				'class'   => 'wc-enhanced-select',
				'default' => 'taxable',
				'options' => array(
					'taxable' => __( 'Облагается налогом', 'phpavel-sfc' ),
					'none'    => __( 'Не облагается', 'phpavel-sfc' ),
				),
			),
			'cost'         => array(
				'title'       => __( 'Стоимость', 'phpavel-sfc' ),
				'type'        => 'text',
				'placeholder' => '0',
				'description' => __( 'Фиксированная стоимость доставки за заказ.', 'phpavel-sfc' ),
				'default'     => '0',
				'desc_tip'    => true,
			),
			'order_status' => array(
				'title'       => __( 'Статус заказа после оформления', 'phpavel-sfc' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => '',
				'options'     => self::order_status_options(),
				'description' => __( 'Если выбрать статус, заказ с этим способом доставки получит его сразу после оформления. Оставьте поле пустым, чтобы WooCommerce использовал стандартное поведение.', 'phpavel-sfc' ),
				'desc_tip'    => true,
			),
			'country_from' => array(
				'title'             => __( 'Страна-склад', 'phpavel-sfc' ),
				'type'              => 'multiselect',
				'class'             => 'wc-enhanced-select',
				'default'           => array(),
				'options'           => $countries,
				'description'       => __( 'Страны, откуда доставляются товары. Информация добавляется к названию способа доставки.', 'phpavel-sfc' ),
				'desc_tip'          => true,
				'custom_attributes' => array(
					'data-placeholder' => __( 'Выберите страны…', 'phpavel-sfc' ),
				),
			),
			'products'     => array(
				'title'       => __( 'Товары', 'phpavel-sfc' ),
				'type'        => 'products',
				'default'     => array(),
				'description' => __( 'Товары, к которым применяется этот способ доставки. Начните вводить название или SKU.', 'phpavel-sfc' ),
			),
		);
	}

	/**
	 * Calculate the rate. The method is offered only when every item in the
	 * package belongs to this instance's product list.
	 *
	 * @param array $package Shipping package.
	 */
	public function calculate_shipping( $package = array() ) {
		if ( empty( $package['contents'] ) ) {
			return;
		}

		$products = PHPAVEL_SFC::normalize_product_ids( (array) $this->get_option( 'products', array() ) );

		if ( empty( $products ) ) {
			return;
		}

		foreach ( $package['contents'] as $item ) {
			$key = PHPAVEL_SFC::product_key(
				isset( $item['product_id'] ) ? $item['product_id'] : 0,
				isset( $item['variation_id'] ) ? $item['variation_id'] : 0
			);

			if ( ! in_array( $key, $products, true ) ) {
				return;
			}
		}

		$label = $this->title ? $this->title : $this->method_title;
		$names = $this->country_names();

		if ( ! empty( $names ) ) {
			$label .= ' (' . implode( ', ', $names ) . ')';
		}

		$this->add_rate(
			array(
				'id'      => $this->get_rate_id(),
				'label'   => $label,
				'cost'    => $this->cost,
				'taxes'   => ( 'taxable' === $this->tax_status ) ? '' : false,
				'package' => $package,
			)
		);
	}

	/**
	 * Order statuses available in the store, including custom ones.
	 *
	 * @return array<string,string>
	 */
	private static function order_status_options() {
		$options = array(
			'' => __( '— Не менять (как в WooCommerce) —', 'phpavel-sfc' ),
		);

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$options += wc_get_order_statuses();
		}

		return $options;
	}

	/**
	 * Human-readable warehouse country names.
	 *
	 * @return string[]
	 */
	private function country_names() {
		$codes = array_filter( array_map( 'strtoupper', (array) $this->get_option( 'country_from', array() ) ) );

		if ( empty( $codes ) ) {
			return array();
		}

		$all   = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_countries() : array();
		$names = array();

		foreach ( $codes as $code ) {
			$names[] = isset( $all[ $code ] ) ? $all[ $code ] : $code;
		}

		return $names;
	}

	/**
	 * Product selector with select2 (AJAX search).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_products_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'class'       => '',
			'description' => '',
			'desc_tip'    => false,
			'default'     => array(),
		);

		$data  = wp_parse_args( $data, $defaults );
		$value = PHPAVEL_SFC::normalize_product_ids( (array) $this->get_option( $key, array() ) );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<select multiple="multiple"
						class="wc-product-search <?php echo esc_attr( $data['class'] ); ?>"
						name="<?php echo esc_attr( $field_key ); ?>[]"
						id="<?php echo esc_attr( $field_key ); ?>"
						data-action="woocommerce_json_search_products_and_variations"
						data-placeholder="<?php esc_attr_e( 'Найти товар…', 'phpavel-sfc' ); ?>"
						style="width:100%">
						<?php
						foreach ( $value as $product_id ) {
							$product = wc_get_product( $product_id );

							if ( ! $product ) {
								continue;
							}

							printf(
								'<option value="%s" selected="selected">%s</option>',
								esc_attr( $product_id ),
								esc_html( wp_strip_all_tags( $product->get_formatted_name() ) )
							);
						}
						?>
					</select>
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Sanitize the "products" field before it is saved.
	 *
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return int[]
	 */
	public function validate_products_field( $key, $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return PHPAVEL_SFC::normalize_product_ids( $value );
	}

	/**
	 * Final cleanup of the instance settings.
	 *
	 * @param array                     $settings Instance settings.
	 * @param PHPAVEL_SFC_Shipping_Method $method   Method instance.
	 * @return array
	 */
	public static function sanitize_settings( $settings, $method = null ) {
		$settings = (array) $settings;

		$countries = array();
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = array_keys( WC()->countries->get_countries() );
		}

		$from = array();
		$raw  = isset( $settings['country_from'] ) ? (array) $settings['country_from'] : array();

		foreach ( $raw as $code ) {
			$code = strtoupper( sanitize_text_field( $code ) );

			if ( '' !== $code && ( empty( $countries ) || in_array( $code, $countries, true ) ) ) {
				$from[] = $code;
			}
		}

		$settings['country_from'] = array_values( array_unique( $from ) );
		$settings['products']     = PHPAVEL_SFC::normalize_product_ids( isset( $settings['products'] ) ? (array) $settings['products'] : array() );
		$settings['cost']         = wc_format_decimal( isset( $settings['cost'] ) ? $settings['cost'] : '0' );
		$settings['tax_status']   = ( isset( $settings['tax_status'] ) && 'none' === $settings['tax_status'] ) ? 'none' : 'taxable';

		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$status   = isset( $settings['order_status'] ) ? sanitize_text_field( $settings['order_status'] ) : '';

		if ( '' !== $status && ! isset( $statuses[ $status ] ) && isset( $statuses[ 'wc-' . $status ] ) ) {
			$status = 'wc-' . $status;
		}

		$settings['order_status'] = ( '' !== $status && isset( $statuses[ $status ] ) ) ? $status : '';

		PHPAVEL_SFC::flush_cache();

		return $settings;
	}
}
