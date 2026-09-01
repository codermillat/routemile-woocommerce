<?php
/**
 * Checkout — Block-based Checkout support.
 *
 * Brings the full RouteMile delivery flow to the WooCommerce Checkout
 * *block* using only documented extension points:
 *
 *  - Additional Checkout Fields API (WC 8.9+): registers the exact
 *    address / landmark / instructions fields; the required field is
 *    validated through `woocommerce_validate_additional_field`, which
 *    also enforces the map pin + delivery radius via the shared
 *    `ROUTEW_Checkout_Handler::get_zone_error()` check.
 *  - `render_block`: renders the same Step-1 map picker above the
 *    Checkout block (markup shared with classic checkout via
 *    `ROUTEW_Checkout::render_location_picker()`; checkout.js boots on
 *    the #routew-map element, so both checkouts behave identically).
 *    Hooking the block itself rather than `the_content` keeps this
 *    working in block themes, where page content renders through
 *    core/post-content outside the main loop.
 *  - `woocommerce_store_api_checkout_update_order_from_request`:
 *    applies the shared `apply_delivery_data_to_order()` persistence
 *    to block orders, mirroring classic-checkout order meta exactly,
 *    and auto-saves the delivery profile for logged-in customers.
 *
 * Stores on WooCommerce older than 8.9 keep full classic-shortcode
 * support; on those versions the block fields simply don't register.
 *
 * @since      1.2.9
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

class ROUTEW_Blocks_Checkout
{

	/**
	 * Register blocks-checkout hooks.
	 *
	 * @since 1.2.9
	 */
	public function __construct()
	{
		// Hook on woocommerce_blocks_loaded (or later) — WC 11+ requires
		// the blocks runtime to be available before
		// woocommerce_register_additional_checkout_field() will accept a
		// call; woocommerce_init fires too early and the function defers
		// the registration to woocommerce_blocks_loaded, which can miss
		// the page render. v1.2.18.
		add_action('woocommerce_blocks_loaded', array($this, 'register_fields'));
		add_action('woocommerce_validate_additional_field', array($this, 'validate_address_details'), 10, 3);
		// Render the picker into the WRAPPER around the checkout block, never
		// into the block's own output. WooCommerce's Checkout block hydrates
		// by matching its server-rendered HTML; prepending anything inside
		// that output made hydration bail silently and the checkout sat on
		// its loading skeleton forever (regression in 1.2.19, fixed 1.3.0).
		//
		// core/post-content is the wrapper block through which block themes
		// render page content, so appending after its inner content places
		// the picker above the checkout without touching the checkout block
		// itself. Classic themes never reach here (the classic checkout
		// renders the picker through its own billing-form hook).
		add_filter('render_block_core/post-content', array($this, 'prepend_map_to_post_content'), 10, 2);
		add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'apply_delivery_data'), 10, 2);

		// Register the `routemile` extension namespace with the Cart/Checkout
		// Store API so client-side `extensionCartUpdate({ namespace: 'routemile-woocommerce',
		// data: { lat, lng } })` does not throw "There is no such namespace
		// registered: routemile." The callback persists the pinned coordinates
		// to the WC session and re-runs shipping + totals so the Shipping
		// Method re-reads the freshly stored coordinates. Without this
		// registration, the picker still updates the toast via the REST
		// `validate-location` route, but the block totals panel lags behind
		// (regression surfaced on 2026-08-18 right after 1.3.0 ship — fixed
		// here in the same dot-release). Hook runs on `woocommerce_blocks_loaded`
		// because the function defers to that action in WC 11+ (v1.2.18).
		add_action('woocommerce_blocks_loaded', array($this, 'register_store_api_namespace'));
	}

	/**
	 * Register the `routemile` extension callback with the documented
	 * Store API extension point.
	 *
	 * Called on `woocommerce_blocks_loaded` (loaded only on the WP frontend
	 * on cart/checkout pages and when the blocks runtime is active). The
	 * callback writes `customer_lat` / `customer_lng` to the WC session —
	 * the FX Shipping Method reads these on its next `calculate_shipping`
	 * pass — then re-runs shipping + totals so the block totals panel
	 * re-renders with the new rate.
	 *
	 * @since 1.3.1
	 */
	public function register_store_api_namespace()
	{
		if (!function_exists('woocommerce_store_api_register_update_callback')) {
			return;
		}

		woocommerce_store_api_register_update_callback(array(
			'namespace' => 'routemile-woocommerce',
			'callback'  => array($this, 'store_api_update_callback'),
		));
	}

	/**
	 * Callback executed when the Cart/Checkout block posts to
	 * `/wc/store/cart/extensions` with `namespace=routemile`. Persists
	 * the pin coordinates so the shipping method picks them up, and
	 * triggers cart total recompute.
	 *
	 * @param array $data Data passed from `extensionCartUpdate` —
	 *                    expects keys `lat` and `lng`.
	 * @return void
	 * @since 1.3.1
	 */
	public function store_api_update_callback($data)
	{
		// The Store API has already bootstrapped the cart + session via
		// `wc_load_cart()` for its own routes, but the extension callback
		// can run when the customer is anonymous and the session was never
		// created for the current request. Guard so we don't fail silently
		// — without the session we have nothing to persist into.
		if (!WC()->session && function_exists('wc_load_cart')) {
			wc_load_cart();
			if (WC()->session && method_exists(WC()->session, 'set_customer_session_cookie')) {
				WC()->session->set_customer_session_cookie(true);
			}
		}
		if (!WC()->session) {
			return;
		}

		$lat = isset($data['lat']) && is_numeric($data['lat']) ? (float) $data['lat'] : null;
		$lng = isset($data['lng']) && is_numeric($data['lng']) ? (float) $data['lng'] : null;

		if (null !== $lat && null !== $lng && abs($lat) <= 90 && abs($lng) <= 180) {
			WC()->session->set('customer_lat', $lat);
			WC()->session->set('customer_lng', $lng);
		}

		// Recompute shipping + totals so the FX Shipping Method re-reads
		// the freshly-stored coordinates and the block totals panel
		// reflects the new rate on its next render.
		if (WC()->cart) {
			// Drop any cached shipping packages so the FX Shipping Method's
			// calculate_shipping() actually runs again — without this
			// reset, WC's WC_Shipping keeps the previously-computed
			// packages in memory and the cart totals panel returns the
			// stale "No available delivery option" line even though the
			// toast knows the new rate. Surface caught 2026-08-18 right
			// after v1.3.6 landed; fixed v1.3.7.
			if (WC()->shipping && method_exists(WC()->shipping, 'reset_shipping')) {
				WC()->shipping->reset_shipping();
			}
			WC()->cart->calculate_shipping();
			WC()->cart->calculate_totals();
		}
	}

	/**
	 * Register the delivery fields via the documented Additional
	 * Checkout Fields API (no-op below WC 8.9).
	 *
	 * @since 1.2.9
	 */
	public function register_fields()
	{
		if (!function_exists('woocommerce_register_additional_checkout_field')) {
			return;
		}

		// No custom address-detail field: WooCommerce's own `address_1` is
		// relabelled to "Flat / Floor / Block / Society / Tower" by
		// ROUTEW_Checkout_Address instead. Registering a second field here put
		// two address inputs on the block checkout, and the value would not
		// have landed in the order's real address (1.3.0).
		woocommerce_register_additional_checkout_field(array(
			'id' => 'routemile/landmark',
			// The block checkout appends its own "(optional)" suffix to
			// non-required fields, so the label must not repeat it.
			'label' => __('Nearby Landmark', 'routemile-woocommerce'),
			'location' => 'address',
			'type' => 'text',
			'required' => false,
			'attributes' => array(
				'placeholder' => __('e.g., Near City Mall, Opposite Park', 'routemile-woocommerce'),
			),
		));

		woocommerce_register_additional_checkout_field(array(
			'id' => 'routemile/delivery-instructions',
			'label' => __('Delivery Instructions', 'routemile-woocommerce'),
			'location' => 'order',
			'type' => 'text',
			'required' => false,
			'attributes' => array(
				'placeholder' => __('e.g., Ring the bell twice, call before arriving...', 'routemile-woocommerce'),
			),
		));
	}

	/**
	 * Enforce the delivery-zone rules on the block checkout.
	 *
	 * The address text itself is now WooCommerce's own required
	 * `address_1` field (relabelled by ROUTEW_Checkout_Address), which core
	 * validates. What core cannot know is whether a map pin was dropped
	 * and whether it falls inside the delivery radius, so that check runs
	 * here, keyed on the landmark field — the one RouteMile field that is
	 * always present on the address form.
	 *
	 * @param WP_Error $errors      Errors object.
	 * @param string   $field_key   Field key being validated.
	 * @param string   $field_value Posted value.
	 * @since 1.2.9
	 */
	public function validate_address_details($errors, $field_key, $field_value)
	{
		if ('routemile/landmark' !== $field_key) {
			return;
		}

		$zone_error = ROUTEW_Checkout_Handler::get_zone_error();
		if (null !== $zone_error) {
			$errors->add('routemile_delivery_zone', $zone_error);
		}
	}

	/**
	 * Render the Step-1 map picker above the Checkout block on block themes.
	 *
	 * Hooked on `render_block_core/post-content` — the wrapper block through
	 * which block themes render page content. Deliberately NOT hooked on the
	 * checkout block itself: WooCommerce's Checkout block hydrates by
	 * matching its server-rendered HTML, so injecting markup inside that
	 * output makes hydration bail and the checkout never leaves its loading
	 * skeleton (1.2.19 regression, fixed in 1.3.0). Writing into the
	 * wrapper's content leaves the checkout block's own HTML byte-identical.
	 *
	 * @param string $block_content Rendered wrapper HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 * @since 1.2.9
	 */
	public function prepend_map_to_post_content($block_content, $block)
	{
		if (is_admin() || !function_exists('is_checkout') || !is_checkout()) {
			return $block_content;
		}

		// Only when this page really renders the Checkout block; a store may
		// use the classic shortcode instead, which renders its own picker.
		$post = get_post();
		if (!$post || !has_block('woocommerce/checkout', $post)) {
			return $block_content;
		}

		$prefix = '';

		// The Open/Closed switch controls order placement — when closed,
		// say so clearly on the blocks checkout too (the field validation
		// blocks placement server-side).
		if (!ROUTEW_Checkout::is_store_open()) {
			$closed_message = __('We are currently closed for deliveries. You can browse and fill your cart — ordering will be available as soon as we reopen.', 'routemile-woocommerce');
			if (class_exists('ROUTEW_Store_Hours')) {
				$hint = ROUTEW_Store_Hours::reopen_hint();
				if ('' !== $hint) {
					$closed_message .= ' ' . $hint;
				}
			}
			$prefix .= '<div class="woocommerce-error routew-store-closed-notice" role="alert">' . esc_html($closed_message) . '</div>';
		}

		$options = get_option('routew_settings');
		// Provider-aware since 1.3.0: a keyless provider (OpenStreetMap)
		// renders the picker fine, so gate on real usability instead of on
		// the presence of a Google key.
		if (class_exists('ROUTEW_Map_Providers') && ROUTEW_Map_Providers::is_configured($options)) {
			$prefix .= ROUTEW_Checkout::render_location_picker(true);
		}

		if ('' === $prefix) {
			return $block_content;
		}

		return $prefix . $block_content;
	}

	/**
	 * Apply the shared delivery-data persistence to block-checkout
	 * orders and auto-save the delivery profile for logged-in customers
	 * — mirroring the classic checkout exactly.
	 *
	 * @param WC_Order      $order   Order being created by the Store API.
	 * @param WP_REST_Request $request Checkout request.
	 * @since 1.2.9
	 */
	public function apply_delivery_data($order, $request)
	{
		if (!is_a($order, 'WC_Order')) {
			return;
		}

		// The address detail is WooCommerce's own address_1 (relabelled to
		// "Flat / Floor / Block / Society / Tower"), not a custom field —
		// read it from the order itself. Shipping first, billing as the
		// fallback for "same address for billing" flows. (1.3.0)
		$details = trim((string) $order->get_shipping_address_1());
		if ('' === $details) {
			$details = trim((string) $order->get_billing_address_1());
		}
		if ('' === $details) {
			return; // no address to work with
		}

		$landmark = $this->get_field_value($order, $request, 'routemile/landmark', 'billing');
		$instructions = $this->get_field_value($order, $request, 'routemile/delivery-instructions', 'other');

		ROUTEW_Checkout_Handler::apply_delivery_data_to_order($order, $details, $landmark, $instructions);

		if ($order->get_user_id()) {
			ROUTEW_Checkout_Handler::save_delivery_profile_for_user($order->get_user_id(), $details, $landmark, $instructions);
		}
	}

	/**
	 * Read an additional checkout field value for the order. Depending on
	 * the WooCommerce version and the Store API timing, the value may live
	 * on the order (via the CheckoutFields service or prefixed meta) or in
	 * the request body — check each documented location in turn.
	 *
	 * @param WC_Order        $order    Order.
	 * @param WP_REST_Request $request  Checkout request.
	 * @param string          $field_id Field ID (e.g. routemile/landmark).
	 * @param string          $group    'billing'|'shipping'|'other' per field location.
	 * @return string
	 * @since 1.2.9
	 */
	private function get_field_value($order, $request, $field_id, $group)
	{
		// 1. CheckoutFields service against the order (documented reader).
		if (class_exists('\Automattic\WooCommerce\Blocks\Package')
			&& class_exists('\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields')) {
			try {
				$fields = \Automattic\WooCommerce\Blocks\Package::container()
					->get(\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields::class);
				$value = $fields->get_field_from_object($field_id, $order, $group);
				if (null !== $value && '' !== (string) $value) {
					return (string) $value;
				}
			} catch (\Throwable $e) {
				if (function_exists('wc_get_logger')) {
					wc_get_logger()->debug('blocks checkout field read failed: ' . $e->getMessage(), array('source' => 'routemile-woocommerce'));
				}
			}
		}

		// 2. Prefixed order meta (storage shape used by the API).
		foreach (array('_' . $field_id, $field_id) as $meta_key) {
			$meta = $order->get_meta($meta_key, true);
			if ('' !== (string) $meta) {
				return (string) $meta;
			}
		}

		// 3. Request body (documented Store API extension context).
		if (is_a($request, 'WP_REST_Request')) {
			$body = $request->get_params();
			foreach (array('additional_fields', 'extensions') as $section) {
				if (isset($body[$section][$field_id]) && is_scalar($body[$section][$field_id])) {
					return sanitize_text_field((string) $body[$section][$field_id]);
				}
			}
		}

		return '';
	}
}

new ROUTEW_Blocks_Checkout();
