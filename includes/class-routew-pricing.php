<?php
/**
 * Admin-configurable pricing rules.
 *
 * Everything here is managed from the RouteMile settings tab — nothing
 * is hardcoded:
 *  - Fee mode: "base + per-km" (classic) or distance tiers
 *    ("up to X km → flat fee"; a tier with 0 km means "and above")
 *  - Free-delivery threshold (order subtotal at or above which delivery
 *    is free; 0 disables)
 *  - Minimum order amount (below it no delivery orders are accepted;
 *    0 disables)
 *
 * Registered via the routew_settings_register_extra_fields /
 * routew_sanitize_settings_extra extension points; the fee decisions are
 * consumed by the shipping method, checkout validation (classic +
 * blocks), the REST estimate, and the legacy cart-fee path.
 *
 * @since      1.2.13
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
	exit;
}

class ROUTEW_Pricing
{

	/** Number of tier rows offered in the settings UI. */
	const TIER_ROWS = 5;

	/**
	 * Register hooks.
	 *
	 * @since 1.2.13
	 */
	public function __construct()
	{
		add_action('routew_settings_register_extra_fields', array($this, 'register_fields'));
		add_filter('routew_sanitize_settings_extra', array($this, 'sanitize_pricing'), 10, 2);
		add_action('woocommerce_before_cart', array($this, 'render_minimum_order_notice'));
	}

	/**
	 * Register the Pricing Rules section on the RouteMile settings tab.
	 *
	 * @since 1.2.13
	 */
	public function register_fields()
	{
		add_settings_section(
			'routew_pricing_section',
			__('Pricing Rules', 'routemile-for-woocommerce'),
			array($this, 'render_section_description'),
			'routemile-settings'
		);

		add_settings_field('routew_fee_mode', __('Fee Structure', 'routemile-for-woocommerce'), array($this, 'render_mode_field'), 'routemile-settings', 'routew_pricing_section');
		add_settings_field('routew_delivery_fee_base', __('Base Fee (starting amount)', 'routemile-for-woocommerce'), array($this, 'render_perkm_amount_field'), 'routemile-settings', 'routew_pricing_section', array('id' => 'routew_delivery_fee_base', 'default' => 5.00, 'step' => 0.01, 'hint' => __('The flat starting cost of every delivery, before distance is added.', 'routemile-for-woocommerce')));
		add_settings_field('routew_delivery_fee_per_km', __('Charge per kilometre', 'routemile-for-woocommerce'), array($this, 'render_perkm_amount_field'), 'routemile-settings', 'routew_pricing_section', array('id' => 'routew_delivery_fee_per_km', 'default' => 1.50, 'step' => 0.01, 'hint' => __('Added for each kilometre between your restaurant and the customer.', 'routemile-for-woocommerce')));
		add_settings_field('routew_fee_tiers', __('Distance Tiers', 'routemile-for-woocommerce'), array($this, 'render_tiers_field'), 'routemile-settings', 'routew_pricing_section');
		add_settings_field('routew_free_delivery_threshold', __('Free Delivery From', 'routemile-for-woocommerce'), array($this, 'render_amount_field'), 'routemile-settings', 'routew_pricing_section', array('id' => 'routew_free_delivery_threshold', 'hint' => __('Order subtotal at or above this amount gets free delivery. 0 disables.', 'routemile-for-woocommerce')));
		add_settings_field('routew_minimum_order', __('Minimum Order', 'routemile-for-woocommerce'), array($this, 'render_amount_field'), 'routemile-settings', 'routew_pricing_section', array('id' => 'routew_minimum_order', 'hint' => __('Orders below this subtotal cannot be placed for delivery. 0 disables.', 'routemile-for-woocommerce')));
	}

	/** Describe the pricing section. */
	public function render_section_description()
	{
		echo '<p>' . esc_html__('All delivery pricing is configured here — tiers, free delivery, and the minimum order. Amounts are in your store currency.', 'routemile-for-woocommerce') . '</p>';
	}

	/** Render the fee-mode select. */
	public function render_mode_field()
	{
		$options = get_option('routew_settings');
		$mode = isset($options['routew_fee_mode']) ? $options['routew_fee_mode'] : 'perkm';
		echo '<select name="routew_settings[routew_fee_mode]" data-routew-fee-mode-select>';
		printf('<option value="perkm"%s>%s</option>', selected($mode, 'perkm', false), esc_html__('Base fee + charge per km (simple)', 'routemile-for-woocommerce'));
		printf('<option value="tiers"%s>%s</option>', selected($mode, 'tiers', false), esc_html__('Fixed price per distance zone (tiers)', 'routemile-for-woocommerce'));
		echo '</select>';
		echo '<p class="description">' . esc_html__('Pick how you want to charge for distance. Only the fields that belong to your choice are shown.', 'routemile-for-woocommerce') . '</p>';
	}

	/**
	 * Amount field shown only in "base + per km" mode.
	 *
	 * @since 1.4.0
	 */
	public function render_perkm_amount_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$default = isset($args['default']) ? $args['default'] : '';
		$value = isset($options[$id]) ? $options[$id] : $default;
		printf(
			'<input type="number" step="%1$s" min="0" name="routew_settings[%2$s]" value="%3$s" class="small-text" data-routew-show-when="feemode:perkm" /><p class="description">%4$s</p>',
			esc_attr(isset($args['step']) ? (string) $args['step'] : '1'),
			esc_attr($id),
			esc_attr($value),
			esc_html($args['hint'])
		);
	}

	/** Render the tier rows. */
	public function render_tiers_field()
	{
		$options = get_option('routew_settings');
		$tiers = self::effective_tiers(isset($options['routew_fee_tiers']) ? $options['routew_fee_tiers'] : array());

		echo '<div data-routew-show-when="feemode:tiers">';
		echo '<table class="routew-tiers-table"><tr><th scope="col">' . esc_html__('Up to (km)', 'routemile-for-woocommerce') . '</th><th scope="col">' . esc_html__('Delivery fee', 'routemile-for-woocommerce') . '</th></tr>';
		for ($i = 0; $i < self::TIER_ROWS; $i++) {
			$to = isset($tiers[$i]['to']) ? $tiers[$i]['to'] : '';
			$fee = isset($tiers[$i]['fee']) ? $tiers[$i]['fee'] : '';
			printf(
				'<tr><td><input type="number" step="0.1" min="0" name="routew_settings[routew_fee_tiers][%1$d][to]" value="%2$s" style="width:90px" /></td><td><input type="number" step="0.01" min="0" name="routew_settings[routew_fee_tiers][%1$d][fee]" value="%3$s" style="width:100px" /></td></tr>',
				(int) $i,
				esc_attr($to),
				esc_attr($fee)
			);
		}
		echo '</table>';
		echo '<p class="description">' . esc_html__('Example: a row "3 km → ₹40" means any order up to 3 km costs ₹40 delivery. Rows are matched top-down by distance. Enter 0 in the last used row\'s "Up to" column to mean "and above". Empty rows are ignored.', 'routemile-for-woocommerce') . '</p>';
		echo '</div>';
	}

	/** Render an amount field (threshold / minimum). */
	public function render_amount_field($args)
	{
		$options = get_option('routew_settings');
		$id = $args['id'];
		$value = isset($options[$id]) ? $options[$id] : '';
		printf(
			'<input type="number" step="0.01" min="0" name="routew_settings[%1$s]" value="%2$s" style="width:120px" /><p class="description">%3$s</p>',
			esc_attr($id),
			esc_attr($value),
			esc_html($args['hint'])
		);
	}

	/**
	 * Sanitize the pricing settings.
	 *
	 * @param array $sanitized Sanitized settings so far.
	 * @param array $input     Raw input.
	 * @return array
	 * @since 1.2.13
	 */
	public function sanitize_pricing($sanitized, $input)
	{
		// Defensive: if the whole Pricing Rules section failed to post (e.g. a
		// future regression prevents it from rendering), preserve the stored
		// values instead of silently resetting them to defaults. When the
		// section is present at least one of its fields posts.
		$section_fields = array('routew_fee_mode', 'routew_fee_tiers', 'routew_free_delivery_threshold', 'routew_minimum_order');
		$section_present = false;
		foreach ($section_fields as $field) {
			if (isset($input[$field])) {
				$section_present = true;
				break;
			}
		}
		if (!$section_present) {
			$existing = get_option('routew_settings', array());
			if (is_array($existing)) {
				foreach ($section_fields as $field) {
					if (isset($existing[$field])) {
						$sanitized[$field] = $existing[$field];
					}
				}
			}
			return $sanitized;
		}

		$sanitized['routew_fee_mode'] = (isset($input['routew_fee_mode']) && 'tiers' === $input['routew_fee_mode']) ? 'tiers' : 'perkm';
		$sanitized['routew_free_delivery_threshold'] = isset($input['routew_free_delivery_threshold']) ? (float) $input['routew_free_delivery_threshold'] : 0;
		$sanitized['routew_minimum_order'] = isset($input['routew_minimum_order']) ? (float) $input['routew_minimum_order'] : 0;
		if ($sanitized['routew_free_delivery_threshold'] < 0) {
			$sanitized['routew_free_delivery_threshold'] = 0;
		}
		if ($sanitized['routew_minimum_order'] < 0) {
			$sanitized['routew_minimum_order'] = 0;
		}

		$tiers = array();
		if (isset($input['routew_fee_tiers']) && is_array($input['routew_fee_tiers'])) {
			foreach (wp_unslash($input['routew_fee_tiers']) as $row) {
				if (!is_array($row)) {
					continue;
				}
				$to = isset($row['to']) ? (float) $row['to'] : 0;
				$fee = isset($row['fee']) ? (float) $row['fee'] : 0;
				if ($to < 0) {
					$to = 0;
				}
				if ($fee < 0) {
					$fee = 0;
				}
				// Skip fully empty rows (0 km / 0 fee means "and above, free" —
				// allow it only when at least one value was entered).
				if (0 === $to && 0 === $fee && '' === trim((string) (isset($row['to']) ? $row['to'] : '')) && '' === trim((string) (isset($row['fee']) ? $row['fee'] : ''))) {
					continue;
				}
				$tiers[] = array('to' => $to, 'fee' => $fee);
			}
			// Sort ascending by distance, catch-all (0) last.
			usort($tiers, function ($a, $b) {
				if (0 === $a['to'] && 0 !== $b['to']) {
					return 1;
				}
				if (0 === $b['to'] && 0 !== $a['to']) {
					return -1;
				}
				return $a['to'] <=> $b['to'];
			});
		}
		$sanitized['routew_fee_tiers'] = $tiers;

		return $sanitized;
	}

	/**
	 * Normalized tier list from stored (possibly legacy/mixed) data.
	 *
	 * @param mixed $tiers Stored tiers.
	 * @return array
	 * @since 1.2.13
	 */
	private static function effective_tiers($tiers)
	{
		if (!is_array($tiers)) {
			return array();
		}
		$out = array();
		foreach ($tiers as $tier) {
			if (is_array($tier) && (isset($tier['to']) || isset($tier['fee']))) {
				$out[] = array(
					'to' => isset($tier['to']) ? $tier['to'] : 0,
					'fee' => isset($tier['fee']) ? $tier['fee'] : 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Delivery fee for a distance under the configured pricing rules.
	 *
	 * Return value semantics:
	 *   float → the fee
	 *   null  → tiers not in use; caller computes base + per-km
	 *   false → tiers in use but no tier covers this distance (no rate)
	 *
	 * Free-delivery threshold is NOT applied here (it needs the cart
	 * subtotal — see is_free_delivery()).
	 *
	 * @param float     $distance_km Distance in kilometers.
	 * @param array|null $options    Plugin settings (defaults to option).
	 * @return float|null|false
	 * @since 1.2.13
	 */
	public static function fee_for_distance($distance_km, $options = null)
	{
		if (null === $options) {
			$options = get_option('routew_settings');
		}

		if (!isset($options['routew_fee_mode']) || 'tiers' !== $options['routew_fee_mode']) {
			return null;
		}

		$tiers = self::effective_tiers(isset($options['routew_fee_tiers']) ? $options['routew_fee_tiers'] : array());
		if (empty($tiers)) {
			return null; // tiers selected but none configured — fall back
		}

		foreach ($tiers as $tier) {
			$to = (float) $tier['to'];
			if ($to > 0 && (float) $distance_km <= $to) {
				return (float) $tier['fee'];
			}
		}

		// Catch-all tier ("and above").
		foreach ($tiers as $tier) {
			if (0 === (float) $tier['to']) {
				return (float) $tier['fee'];
			}
		}

		return false; // distance beyond every configured tier
	}

	/**
	 * Is this order subtotal eligible for free delivery?
	 *
	 * @param float|null $subtotal Cart subtotal; null reads WC()->cart.
	 * @return bool
	 * @since 1.2.13
	 */
	public static function is_free_delivery($subtotal = null)
	{
		$options = get_option('routew_settings');
		$threshold = isset($options['routew_free_delivery_threshold']) ? (float) $options['routew_free_delivery_threshold'] : 0;
		if ($threshold <= 0) {
			return false;
		}

		if (null === $subtotal) {
			if (!WC()->cart) {
				return false;
			}
			$subtotal = (float) WC()->cart->get_subtotal();
		}

		return (float) $subtotal >= $threshold;
	}

	/**
	 * Minimum-order error for the current cart, or null when OK.
	 *
	 * @param float|null $subtotal Cart subtotal; null reads WC()->cart.
	 * @return string|null
	 * @since 1.2.13
	 */
	public static function minimum_order_error($subtotal = null)
	{
		$options = get_option('routew_settings');
		$minimum = isset($options['routew_minimum_order']) ? (float) $options['routew_minimum_order'] : 0;
		if ($minimum <= 0) {
			return null;
		}

		if (null === $subtotal) {
			if (!WC()->cart) {
				return null; // no cart context (e.g. early REST) — don't block
			}
			$subtotal = (float) WC()->cart->get_subtotal();
		}

		if ((float) $subtotal >= $minimum) {
			return null;
		}

		$missing = $minimum - (float) $subtotal;
		return sprintf(
			/* translators: 1: minimum order amount with currency (e.g. "₹200"), 2: shortfall amount the customer must add (e.g. "₹50"). */
			__('The minimum delivery order is %1$s — please add %2$s more to your cart.', 'routemile-for-woocommerce'),
			wp_strip_all_tags(wc_price($minimum)),
			wp_strip_all_tags(wc_price($missing))
		);
	}

	/**
	 * Cart-page notice when below the minimum order amount.
	 *
	 * @since 1.2.13
	 */
	public function render_minimum_order_notice()
	{
		$error = self::minimum_order_error();
		if (null === $error) {
			return;
		}
		if (function_exists('wc_print_notice')) {
			wc_print_notice($error, 'error', array('routew-minimum-order'));
		}
	}
}

new ROUTEW_Pricing();
