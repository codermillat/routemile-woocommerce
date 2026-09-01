<?php
/**
 * ROUTEW_Settings_Extra — Settings extension points.
 *
 * Self-registers three self-contained settings affordances that previously
 * bloated the main ROUTEW_Settings class past its 500-LOC cap (1.2.16):
 *   - lat/lng range validation with a transient admin notice
 *   - uninstall-data opt-in checkbox (saved + preserved when not re-POSTed)
 *   - explicit per-handler `manage_woocommerce` cap check on save
 *
 * All hooks land on the same two extension points the rest of the
 * settings code already uses (woocommerce_update_options_routemile +
 * routew_sanitize_settings_extra), so ROUTEW_Settings remains the single owner
 * of the tab.
 *
 * @since      1.2.16
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
	exit;
}

class ROUTEW_Settings_Extra
{

	/**
	 * Wire extension points into the save + sanitize flow.
	 *
	 * @since 1.2.16
	 */
	public function __construct()
	{
		// Explicit per-handler cap check, matching the repo rule (1.2.16).
		add_action('woocommerce_update_options_routemile', array($this, 'verify_capability'), 1);
		// Run after the core sanitize + the other extensions so the
		// transient + preserve list land at the end of the sanitize.
		add_filter('routew_sanitize_settings_extra', array($this, 'sanitize_extra'), 100, 2);
		// Preserve routew_is_open + routew_remove_on_uninstall when the form
		// does not post them.
		add_filter('routew_sanitize_settings_extra', array($this, 'preserve_keys'), 999, 2);
	}

	/**
	 * Early cap guard on the WC settings save action.
	 *
	 * @since 1.2.16
	 */
	public function verify_capability()
	{
		if (!current_user_can('manage_woocommerce')) {
			// No UI; the action just bails. WC's own gate is the primary
			// check; this is the explicit per-handler guard the repo rule
			// requires.
			remove_action('woocommerce_update_options_routemile', 'ROUTEW_Settings::save_wc_settings_tab', 10);
		}
	}

	/**
	 * Range-check restaurant lat/lng; opt-in uninstall-data field.
	 *
	 * @param array $sanitized Sanitized settings so far.
	 * @param array $input     Raw input.
	 * @return array
	 * @since 1.2.16
	 */
	public function sanitize_extra($sanitized, $input)
	{
		if (!is_array($sanitized)) {
			$sanitized = array();
		}

		if (isset($input['routew_restaurant_latlng'])) {
			$raw = sanitize_text_field($input['routew_restaurant_latlng']);
			$parts = preg_split('/\s*,\s*/', $raw, 2);
			$lat = isset($parts[0]) ? (float) $parts[0] : 0;
			$lng = isset($parts[1]) ? (float) $parts[1] : 0;
			// Regex matches ±999; range-check (1.2.16).
			if (preg_match('/^-?\d{1,3}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?$/', $raw) && abs($lat) <= 90 && abs($lng) <= 180) {
				$sanitized['routew_restaurant_latlng'] = $raw;
			} else {
				set_transient('routew_admin_notice', __('Restaurant Coordinates were out of range or malformed; previous value kept.', 'routemile-for-woocommerce'), 30);
			}
		}

		// Uninstall opt-in: 'yes' when checked, 'no' when absent.
		$sanitized['routew_remove_on_uninstall'] = isset($input['routew_remove_on_uninstall']) ? 'yes' : 'no';

		return $sanitized;
	}

	/**
	 * Preserve keys the form never posts (admin-bar toggle, uninstall opt-in
	 * when save somehow misses it, etc.) so an unrelated save cannot wipe
	 * them — defense in depth beyond the explicit sanitize above.
	 *
	 * @param array $sanitized Sanitized settings so far.
	 * @param array $input     Raw input.
	 * @return array
	 * @since 1.2.16
	 */
	public function preserve_keys($sanitized, $input)
	{
		$existing = get_option('routew_settings', array());
		if (!is_array($existing)) {
			return $sanitized;
		}
		foreach (array('routew_is_open', 'routew_remove_on_uninstall') as $key) {
			if (isset($existing[$key]) && empty($sanitized[$key])) {
				$sanitized[$key] = $existing[$key];
			}
		}
		return $sanitized;
	}
}

new ROUTEW_Settings_Extra();
