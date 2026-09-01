<?php
if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/class-routew-map-providers.php';
require_once __DIR__ . '/class-routew-map-provider-client.php';

/**
 * Handles all communication with the configured mapping provider.
 *
 * Owns caching, input normalisation and the public API used across the
 * plugin; the per-provider HTTP shapes live in ROUTEW_Map_Provider_Client.
 * Any provider is usable — see ROUTEW_Map_Providers — and when the active
 * provider has no road routing, distance falls back to a straight-line
 * estimate with the admin's road-correction factor so ordering never
 * breaks. (Provider-agnostic since 1.3.0.)
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Mapping_Service
{

	/**
	 * Active provider definition, including its key.
	 *
	 * @since    1.3.0
	 * @access   private
	 * @var      array
	 */
	private $provider;

	/**
	 * Provider HTTP client.
	 *
	 * @since    1.3.0
	 * @access   private
	 * @var      ROUTEW_Map_Provider_Client
	 */
	private $client;

	/**
	 * Cache lifetime for geocoding results (addresses rarely move).
	 *
	 * @since 1.2.1
	 */
	const GEOCODE_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Cache lifetime for distance results (traffic-dependent, keep short).
	 *
	 * @since 1.2.1
	 */
	const DISTANCE_CACHE_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		$options = get_option('routew_settings');

		// Server-side calls prefer a dedicated server key where the
		// provider offers one, so merchants can restrict the browser key
		// by referrer (Google only in practice).
		$this->provider = ROUTEW_Map_Providers::active($options);
		$this->provider['key'] = ROUTEW_Map_Providers::key_for($this->provider['id'], $options, true);

		$this->client = new ROUTEW_Map_Provider_Client($this->provider);
	}

	/**
	 * Is the active provider missing a key it requires?
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	private function key_missing()
	{
		return !empty($this->provider['requires_key']) && '' === (string) $this->provider['key'];
	}

	/**
	 * @return WP_Error
	 * @since 1.3.0
	 */
	private function key_missing_error()
	{
		return new WP_Error('api_key_missing', sprintf(
			/* translators: %s: map provider name. */
			__('%s requires an API key. Add one in WooCommerce → Settings → RouteMile, or switch to OpenStreetMap which needs no key.', 'routemile-for-woocommerce'),
			isset($this->provider['label']) ? $this->provider['label'] : __('The map provider', 'routemile-for-woocommerce')
		));
	}

	/**
	 * Get the distance between two points.
	 *
	 * Uses the provider's road routing when available. When the provider
	 * has none (or routing fails), returns a straight-line estimate scaled
	 * by the admin's road-correction factor, flagged with
	 * `['estimated' => true]` so callers can label the ETA honestly.
	 *
	 * @param   string|array  $origin         Origin coordinates or address.
	 * @param   string|array  $destination    Destination coordinates or address.
	 * @return  array|WP_Error                The distance and duration, or an error.
	 * @since   1.0.0
	 */
	public function get_distance($origin, $destination)
	{
		if ($this->key_missing()) {
			return $this->key_missing_error();
		}

		$orig = $this->to_coords($origin);
		if (is_wp_error($orig)) {
			return $orig;
		}

		$dest = $this->to_coords($destination);
		if (is_wp_error($dest)) {
			return $dest;
		}

		// Serve from cache when possible — calculate_shipping() runs on
		// every cart/checkout render, so an uncached API call here burns
		// quota and adds latency on each one.
		// Cache key includes the restaurant pin so that when the operator
		// moves the store the cache invalidates immediately. (AUDIT-FIXES M1)
		$restaurant_pin = trim((string) get_option('routew_restaurant_latlng', ''));
		$cache_key = 'routew_dist_' . md5(
			$this->provider['id'] . '|' . $restaurant_pin . '|' . $this->coord_hash($orig) . '|' . $this->coord_hash($dest)
		);
		$cached = get_transient($cache_key);
		if (false !== $cached && is_array($cached) && isset($cached['distance'], $cached['duration'])) {
			return $this->shape($cached);
		}

		$result = $this->client->route($orig, $dest);

		if (is_wp_error($result)) {
			$code = $result->get_error_code();

			// Routing genuinely unavailable → estimate rather than block
			// the order. A hard provider error still estimates, but is
			// logged so the operator can see the provider is failing.
			if ('routing_unsupported' !== $code && function_exists('wc_get_logger')) {
				wc_get_logger()->warning(
					'Routing failed (' . $code . '), using straight-line estimate: ' . $result->get_error_message(),
					array('source' => 'routemile-for-woocommerce')
				);
			}

			$result = ROUTEW_Map_Provider_Client::haversine($orig, $dest, ROUTEW_Map_Providers::road_factor());
		}

		set_transient($cache_key, $result, self::DISTANCE_CACHE_TTL);

		return $this->shape($result);
	}

	/**
	 * Convert an internal distance array into the object shape the rest of
	 * the plugin expects (`->value` / `->text`, mirroring the historical
	 * Google Distance Matrix element).
	 *
	 * @param array $result array('distance'=>metres,'duration'=>seconds).
	 * @return array
	 * @since 1.3.0
	 */
	private function shape($result)
	{
		$metres = (int) $result['distance'];
		$seconds = (int) $result['duration'];
		$estimated = !empty($result['estimated']);

		$km = $metres / 1000;
		$mins = (int) max(1, round($seconds / 60));

		return array(
			'distance' => (object) array(
				'value' => $metres,
				'text' => sprintf(
					/* translators: %s: distance in kilometres. */
					__('%s km', 'routemile-for-woocommerce'),
					number_format_i18n($km, ($km < 10 ? 1 : 0))
				),
			),
			'duration' => (object) array(
				'value' => $seconds,
				'text' => sprintf(
					/* translators: %d: duration in minutes. */
					_n('%d min', '%d mins', $mins, 'routemile-for-woocommerce'),
					$mins
				),
			),
			'estimated' => $estimated,
		);
	}

	/**
	 * Resolve the restaurant's coordinates from plugin settings.
	 *
	 * Uses the explicit `routew_restaurant_latlng` setting ("lat, lng") when
	 * present; otherwise falls back to geocoding `routew_restaurant_address`
	 * (cached 24 h via get_coords). Distance/fee calculations must always
	 * go through this so they depend on coordinates only — never on any
	 * customer-entered field.
	 *
	 * @param   array|mixed      $options    Plugin settings (routew_settings option).
	 * @return  array|WP_Error               array('lat' => float, 'lng' => float) or error.
	 * @since   1.2.3
	 */
	public function get_restaurant_location($options = null)
	{
		if (!is_array($options)) {
			$options = get_option('routew_settings');
		}

		$raw = isset($options['routew_restaurant_latlng']) ? trim((string) $options['routew_restaurant_latlng']) : '';
		if (preg_match('/^(-?\d{1,3}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $raw, $m)) {
			$lat = (float) $m[1];
			$lng = (float) $m[2];
			if (abs($lat) <= 90 && abs($lng) <= 180) {
				return array('lat' => $lat, 'lng' => $lng);
			}
		}

		$address = isset($options['routew_restaurant_address']) ? trim((string) $options['routew_restaurant_address']) : '';
		if ('' === $address) {
			return new WP_Error('restaurant_location_missing', __('Restaurant address or coordinates are not configured.', 'routemile-for-woocommerce'));
		}

		$coords = $this->get_coords($address);
		if (is_wp_error($coords)) {
			return $coords;
		}

		$lat = is_array($coords) ? $coords['lat'] : $coords->lat;
		$lng = is_array($coords) ? $coords['lng'] : $coords->lng;

		return array('lat' => (float) $lat, 'lng' => (float) $lng);
	}

	/**
	 * Produce a stable cache identity for a coordinate pair.
	 *
	 * Rounded to 4 decimals (~11 m) so the same drop on the map reuses the
	 * cached result across page loads.
	 *
	 * @param   array  $coords    array('lat'=>float,'lng'=>float).
	 * @return  string
	 * @since   1.2.1
	 */
	private function coord_hash($coords)
	{
		return round((float) $coords['lat'], 4) . ',' . round((float) $coords['lng'], 4);
	}

	/**
	 * Normalize any accepted location value into a coordinate pair,
	 * geocoding an address when necessary.
	 *
	 * @param mixed $value array('lat','lng'), "lat,lng" string, or address.
	 * @return array|WP_Error array('lat'=>float,'lng'=>float).
	 * @since 1.3.0
	 */
	private function to_coords($value)
	{
		if (is_array($value)) {
			if (!isset($value['lat'], $value['lng'])) {
				return new WP_Error('invalid_location', __('Invalid location array. Expected lat,lng.', 'routemile-for-woocommerce'));
			}
			return array('lat' => (float) $value['lat'], 'lng' => (float) $value['lng']);
		}

		if (!is_string($value)) {
			return new WP_Error('invalid_location', __('Invalid location type.', 'routemile-for-woocommerce'));
		}

		$value = trim($value);

		if (preg_match('/^(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)$/', $value, $m)) {
			return array('lat' => (float) $m[1], 'lng' => (float) $m[2]);
		}

		$coords = $this->get_coords($value);
		if (is_wp_error($coords)) {
			return $coords;
		}

		return array('lat' => (float) $coords->lat, 'lng' => (float) $coords->lng);
	}

	/**
	 * Get the coordinates for an address.
	 *
	 * @param   string  $address    The address.
	 * @return  object|WP_Error     Object with ->lat and ->lng, or an error.
	 * @since   1.0.0
	 */
	public function get_coords($address)
	{
		if ($this->key_missing()) {
			return $this->key_missing_error();
		}

		if (!in_array('geocode', (array) $this->provider['capabilities'], true)) {
			return new WP_Error('geocoding_unsupported', __('The configured map provider cannot look up addresses.', 'routemile-for-woocommerce'));
		}

		$cache_key = 'routew_geo_' . md5($this->provider['id'] . '|' . strtolower(trim($address)));
		$cached = get_transient($cache_key);
		if (false !== $cached && is_object($cached) && isset($cached->lat, $cached->lng)) {
			return $cached;
		}

		$location = $this->client->geocode($address);

		if (is_wp_error($location)) {
			if (function_exists('wc_get_logger')) {
				wc_get_logger()->error(
					'Geocoding failed (' . $this->provider['id'] . '): ' . $location->get_error_message(),
					array('source' => 'routemile-for-woocommerce')
				);
			}
			return $location;
		}

		set_transient($cache_key, $location, self::GEOCODE_CACHE_TTL);

		return $location;
	}
}
