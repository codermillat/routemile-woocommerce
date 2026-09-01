<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Checkout address simplification.
 *
 * RouteMile is a single-restaurant, coordinates-only delivery plugin:
 * the delivery fee and zone check depend solely on the map pin, so the
 * customer never needs to type a city, state, country or postcode. Those
 * fields are hidden and filled from the store's own base address, and
 * WooCommerce's own Address line is relabelled to ask for the one thing
 * a rider actually needs — flat / floor / block / society / tower.
 *
 * Everything here goes through documented extension points:
 *  - `woocommerce_get_country_locale` — WooCommerce's own documented way
 *    to hide address fields and drop their required flag. It feeds both
 *    the classic and the block checkout (the block checkout respects
 *    core locale filters).
 *  - `woocommerce_default_address_fields` — relabel address_1.
 *  - `woocommerce_customer_default_location` + the Store API draft-order
 *    hooks — populate the hidden values from the store base so shipping
 *    and tax calculation still have a complete address to work with.
 *
 * WooCommerce genuinely needs a shipping address to resolve a shipping
 * zone and tax rate, so hidden fields are *populated*, never omitted.
 *
 * @since      1.3.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Checkout_Address
{

	/**
	 * Address keys the customer never fills in: the map pin supplies the
	 * location and the store base supplies the administrative parts.
	 *
	 * `state` and `country` are deliberately included — a single
	 * restaurant delivers inside one area, so asking is pointless and
	 * lets the customer break their own shipping-zone match.
	 *
	 * @since 1.3.0
	 */
	const HIDDEN_FIELDS = array('city', 'postcode', 'state', 'country', 'company', 'address_2');

	/**
	 * Register hooks.
	 *
	 * @since 1.3.0
	 */
	public function __construct()
	{
		add_filter('woocommerce_get_country_locale', array($this, 'hide_locale_fields'), 20);
		add_filter('woocommerce_default_address_fields', array($this, 'relabel_address_field'), 20);
		add_filter('woocommerce_customer_default_location', array($this, 'default_location'), 20);

		// Fill the hidden parts on both checkout flows.
		add_action('woocommerce_checkout_update_order_meta', array($this, 'noop'), 5);
		add_filter('woocommerce_checkout_posted_data', array($this, 'fill_posted_data'), 20);
		add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'fill_block_order'), 5, 2);
	}

	/**
	 * Store base address parts, used for every hidden field.
	 *
	 * @return array
	 * @since 1.3.0
	 */
	public static function store_base()
	{
		// Prefer WC()->countries when available (admin / late frontend
		// requests); fall back to the raw option otherwise because some
		// early hooks fire before WC_Countries is instantiated.
		$country = '';
		$state = '';
		$city = '';
		$postcode = '';
		if (function_exists('WC') && WC() && WC()->countries) {
			$country = (string) WC()->countries->get_base_country();
			$state = (string) WC()->countries->get_base_state();
			$city = (string) WC()->countries->get_base_city();
			$postcode = (string) WC()->countries->get_base_postcode();
		}
		if ('' === $country) {
			$raw = get_option('woocommerce_default_country', '');
			// Stored as "COUNTRY:STATE" (e.g. "IN:UP") or just "COUNTRY".
			if (is_string($raw) && '' !== $raw) {
				$parts = explode(':', $raw, 2);
				$country = $parts[0];
				if (isset($parts[1])) {
					$state = $parts[1];
				}
			}
		}

		return array(
			'country' => $country,
			'state' => $state,
			'city' => $city,
			'postcode' => $postcode,
		);
	}

	/**
	 * Hide the administrative address fields for every country.
	 *
	 * Uses WooCommerce's documented `woocommerce_get_country_locale`
	 * shape: `hidden` removes the input, `required` false stops it
	 * blocking submission. Applied to all locales because a
	 * single-restaurant store delivers within one area regardless of
	 * which country that is — this stays region-neutral.
	 *
	 * @param array $locale Country locale definitions.
	 * @return array
	 * @since 1.3.0
	 */
	public function hide_locale_fields($locale)
	{
		if (!is_array($locale)) {
			return $locale;
		}

		foreach ($locale as $code => $rules) {
			foreach (self::HIDDEN_FIELDS as $field) {
				if (!isset($locale[$code][$field]) || !is_array($locale[$code][$field])) {
					$locale[$code][$field] = array();
				}
				$locale[$code][$field]['required'] = false;
				$locale[$code][$field]['hidden'] = true;
			}
		}

		return $locale;
	}

	/**
	 * Relabel WooCommerce's own address line to ask for the delivery
	 * detail a rider needs, and keep it required.
	 *
	 * Reusing `address_1` rather than registering another custom field
	 * means the value lands in the order's real address, so admin
	 * screens, receipts, packing slips and emails all read normally with
	 * no extra plumbing.
	 *
	 * @param array $fields Default address fields.
	 * @return array
	 * @since 1.3.0
	 */
	public function relabel_address_field($fields)
	{
		if (!isset($fields['address_1']) || !is_array($fields['address_1'])) {
			return $fields;
		}

		$fields['address_1']['label'] = __('Flat / Floor / Block / Society / Tower', 'routemile-woocommerce');
		$fields['address_1']['placeholder'] = __('e.g. Flat 4B, 2nd floor, Tower A, Green Valley Society', 'routemile-woocommerce');
		$fields['address_1']['required'] = true;
		// The map pin is the location; this is the "find me in the
		// building" detail, so browsers should not autofill a street.
		$fields['address_1']['autocomplete'] = 'off';

		return $fields;
	}

	/**
	 * Default the customer's location to the store base so the hidden
	 * country/state resolve a shipping zone on the very first request.
	 *
	 * The filter value is a STRING in WooCommerce's "country:state"
	 * format — it is passed straight to wc_format_country_state_string(),
	 * which calls strstr() on it. Returning an array fatals on PHP 8.
	 *
	 * @param string $location Default location, e.g. "IN:UP" or "IN".
	 * @return string
	 * @since 1.3.0
	 */
	public function default_location($location)
	{
		$base = self::store_base();
		if ('' === $base['country']) {
			return $location;
		}

		return '' !== $base['state']
			? $base['country'] . ':' . $base['state']
			: $base['country'];
	}

	/**
	 * Fill the hidden address parts on the classic checkout before
	 * WooCommerce validates the posted data.
	 *
	 * @param array $data Posted checkout data.
	 * @return array
	 * @since 1.3.0
	 */
	public function fill_posted_data($data)
	{
		if (!is_array($data)) {
			return $data;
		}

		$base = self::store_base();

		foreach (array('billing', 'shipping') as $group) {
			foreach ($base as $key => $value) {
				$field = $group . '_' . $key;
				if ('' === $value) {
					continue;
				}
				if (empty($data[$field])) {
					$data[$field] = $value;
				}
			}
		}

		return $data;
	}

	/**
	 * Fill the hidden address parts on block-checkout orders.
	 *
	 * @param WC_Order $order Order being created by the Store API.
	 * @since 1.3.0
	 */
	public function fill_block_order($order, $request = null)
	{
		if (!is_a($order, 'WC_Order')) {
			return;
		}

		$base = self::store_base();

		if ('' !== $base['country']) {
			if ('' === (string) $order->get_shipping_country()) {
				$order->set_shipping_country($base['country']);
			}
			if ('' === (string) $order->get_billing_country()) {
				$order->set_billing_country($base['country']);
			}
		}
		if ('' !== $base['state']) {
			if ('' === (string) $order->get_shipping_state()) {
				$order->set_shipping_state($base['state']);
			}
			if ('' === (string) $order->get_billing_state()) {
				$order->set_billing_state($base['state']);
			}
		}
		if ('' !== $base['city']) {
			if ('' === (string) $order->get_shipping_city()) {
				$order->set_shipping_city($base['city']);
			}
			if ('' === (string) $order->get_billing_city()) {
				$order->set_billing_city($base['city']);
			}
		}
		if ('' !== $base['postcode']) {
			if ('' === (string) $order->get_shipping_postcode()) {
				$order->set_shipping_postcode($base['postcode']);
			}
			if ('' === (string) $order->get_billing_postcode()) {
				$order->set_billing_postcode($base['postcode']);
			}
		}
	}

	/**
	 * Intentionally empty: reserved so the classic path has a hook point
	 * if a future release needs post-save address work.
	 *
	 * @since 1.3.0
	 */
	public function noop()
	{
	}
}

new ROUTEW_Checkout_Address();
