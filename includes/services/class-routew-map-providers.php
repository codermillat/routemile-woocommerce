<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Map provider registry.
 *
 * Single source of truth describing every supported mapping provider:
 * which capabilities it offers (interactive map, geocoding, road
 * routing), where its endpoints live, whether it needs a key, and the
 * attribution its licence requires.
 *
 * Nothing else in the plugin hardcodes a vendor — the frontend picker,
 * the server-side distance/geocode calls, the settings UI and the
 * config-health warning all read this registry.
 *
 * @since      1.3.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Map_Providers
{

	/**
	 * Default provider for a genuinely fresh install: needs no key and no
	 * billing account, so the map picker works out of the box. Stores
	 * upgrading with a Google key already set keep Google — see
	 * active_id().
	 *
	 * @since 1.3.0
	 */
	const DEFAULT_PROVIDER = 'osm';

	/**
	 * Default multiplier applied to straight-line distance when the
	 * active provider cannot supply road routing. Road distance in built
	 * up areas typically runs 20-40% longer than the crow-flies line.
	 *
	 * @since 1.3.0
	 */
	const DEFAULT_ROAD_FACTOR = 1.3;

	/**
	 * All supported providers.
	 *
	 * Capability keys:
	 *  - `map`      renders an interactive picker
	 *  - `geocode`  forward + reverse geocoding
	 *  - `routing`  real road distance & duration
	 *
	 * @return array
	 * @since 1.3.0
	 */
	public static function all()
	{
		return array(
			'google' => array(
				'label' => __('Google Maps', 'routemile-woocommerce'),
				'engine' => 'google',
				'requires_key' => true,
				'free_tier' => __('Requires a Google Cloud billing account. Free monthly credit covers small stores.', 'routemile-woocommerce'),
				'signup_url' => 'https://console.cloud.google.com/google/maps-apis/start',
				'capabilities' => array('map', 'geocode', 'routing'),
				'geocode_url' => 'https://maps.googleapis.com/maps/api/geocode/json',
				'routing_url' => 'https://maps.googleapis.com/maps/api/distancematrix/json',
				'attribution' => '',
			),
			'osm' => array(
				'label' => __('OpenStreetMap (no key required)', 'routemile-woocommerce'),
				'engine' => 'leaflet',
				'requires_key' => false,
				'free_tier' => __('Completely free — no signup, no card. Runs on volunteer infrastructure: address lookups are throttled to one per second site-wide and the public routing server asks that busy stores not use it. Best for low-volume stores; switch to Geoapify if you outgrow it.', 'routemile-woocommerce'),
				'signup_url' => '',
				'capabilities' => array('map', 'geocode', 'routing'),
				'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'tile_subdomains' => array(),
				'max_zoom' => 19,
				'geocode_url' => 'https://nominatim.openstreetmap.org/search',
				'reverse_url' => 'https://nominatim.openstreetmap.org/reverse',
				'routing_url' => 'https://router.project-osrm.org/route/v1/driving/',
				'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				// Nominatim's usage policy caps the whole application at 1
				// request/second — not per visitor. Enforced by a global
				// lock in ROUTEW_Map_Provider_Client, since a per-user rate
				// limit does nothing under concurrency.
				'geocode_min_interval' => 1,
			),
			'maptiler' => array(
				'label' => __('MapTiler', 'routemile-woocommerce'),
				'engine' => 'leaflet',
				'requires_key' => true,
				'free_tier' => __('Free key, no card required: 100k tile loads and 100k geocoding requests per month. No road routing — distance uses the straight-line estimate.', 'routemile-woocommerce'),
				'signup_url' => 'https://cloud.maptiler.com/account/keys/',
				'capabilities' => array('map', 'geocode'),
				'tile_url' => 'https://api.maptiler.com/maps/streets-v2/{z}/{x}/{y}.png?key={key}',
				'tile_subdomains' => array(),
				'max_zoom' => 20,
				'geocode_url' => 'https://api.maptiler.com/geocoding/',
				'attribution' => '&copy; <a href="https://www.maptiler.com/copyright/">MapTiler</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			),
			'geoapify' => array(
				'label' => __('Geoapify', 'routemile-woocommerce'),
				'engine' => 'leaflet',
				'requires_key' => true,
				'free_tier' => __('Free key, no card required: 3,000 requests per day covering tiles, geocoding and road routing in one provider.', 'routemile-woocommerce'),
				'signup_url' => 'https://myprojects.geoapify.com/',
				'capabilities' => array('map', 'geocode', 'routing'),
				'tile_url' => 'https://maps.geoapify.com/v1/tile/osm-bright/{z}/{x}/{y}.png?apiKey={key}',
				'tile_subdomains' => array(),
				'max_zoom' => 20,
				'geocode_url' => 'https://api.geoapify.com/v1/geocode/search',
				'reverse_url' => 'https://api.geoapify.com/v1/geocode/reverse',
				'routing_url' => 'https://api.geoapify.com/v1/routing',
				'attribution' => 'Powered by <a href="https://www.geoapify.com/">Geoapify</a> &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			),
		);
	}

	/**
	 * The provider id the store has configured.
	 *
	 * Resolved at read time so upgrades need no migration routine:
	 * a store that has never seen this setting but does have a Google
	 * key keeps Google, and only a genuinely fresh install falls through
	 * to the keyless default. Getting this wrong would silently drop
	 * every 1.2.x store's working map.
	 *
	 * @param array|null $options Plugin settings.
	 * @return string
	 * @since 1.3.0
	 */
	public static function active_id($options = null)
	{
		if (!is_array($options)) {
			$options = get_option('routew_settings');
		}

		$all = self::all();

		$id = isset($options['routew_map_provider']) ? sanitize_key($options['routew_map_provider']) : '';
		if (isset($all[$id])) {
			return $id;
		}

		// Never configured: infer from what the store already has.
		$google_key = isset($options['routew_google_maps_api_key']) ? trim((string) $options['routew_google_maps_api_key']) : '';
		if ('' !== $google_key) {
			return 'google';
		}

		return self::DEFAULT_PROVIDER;
	}

	/**
	 * Full definition of the active provider, with the store's key and id
	 * merged in.
	 *
	 * @param array|null $options Plugin settings.
	 * @return array
	 * @since 1.3.0
	 */
	public static function active($options = null)
	{
		if (!is_array($options)) {
			$options = get_option('routew_settings');
		}

		$id = self::active_id($options);
		$provider = self::all()[$id];
		$provider['id'] = $id;
		$provider['key'] = self::key_for($id, $options);

		return $provider;
	}

	/**
	 * The API key configured for a provider.
	 *
	 * Google keeps its historical option names (and its separate
	 * server-side key) so existing installs upgrade without touching
	 * settings; every other provider uses `routew_map_provider_key`.
	 *
	 * @param string     $id      Provider id.
	 * @param array|null $options Plugin settings.
	 * @param bool       $server  Prefer the server-side key when the provider has one.
	 * @return string
	 * @since 1.3.0
	 */
	public static function key_for($id, $options = null, $server = false)
	{
		if (!is_array($options)) {
			$options = get_option('routew_settings');
		}

		if ('google' === $id) {
			if ($server) {
				$server_key = isset($options['routew_google_maps_server_key']) ? trim((string) $options['routew_google_maps_server_key']) : '';
				if ('' !== $server_key) {
					return $server_key;
				}
			}
			return isset($options['routew_google_maps_api_key']) ? trim((string) $options['routew_google_maps_api_key']) : '';
		}

		return isset($options['routew_map_provider_key']) ? trim((string) $options['routew_map_provider_key']) : '';
	}

	/**
	 * Does the active provider support a capability right now? A provider
	 * that needs a key but has none is treated as having no capabilities
	 * at all.
	 *
	 * @param string     $capability 'map'|'geocode'|'routing'.
	 * @param array|null $options    Plugin settings.
	 * @return bool
	 * @since 1.3.0
	 */
	public static function supports($capability, $options = null)
	{
		$provider = self::active($options);

		if (!empty($provider['requires_key']) && '' === $provider['key']) {
			return false;
		}

		return in_array($capability, $provider['capabilities'], true);
	}

	/**
	 * Is the active provider usable at all (i.e. can it show a map)?
	 *
	 * @param array|null $options Plugin settings.
	 * @return bool
	 * @since 1.3.0
	 */
	public static function is_configured($options = null)
	{
		return self::supports('map', $options);
	}

	/**
	 * The road-distance correction factor for straight-line fallbacks,
	 * clamped to a sane range.
	 *
	 * @param array|null $options Plugin settings.
	 * @return float
	 * @since 1.3.0
	 */
	public static function road_factor($options = null)
	{
		if (!is_array($options)) {
			$options = get_option('routew_settings');
		}

		$factor = isset($options['routew_road_distance_factor'])
			? (float) $options['routew_road_distance_factor']
			: self::DEFAULT_ROAD_FACTOR;

		if ($factor < 1.0 || $factor > 3.0) {
			$factor = self::DEFAULT_ROAD_FACTOR;
		}

		return $factor;
	}

	/**
	 * Substitute a provider key into a templated URL.
	 *
	 * @param string $url URL containing `{key}`.
	 * @param string $key Provider key.
	 * @return string
	 * @since 1.3.0
	 */
	public static function with_key($url, $key)
	{
		return str_replace('{key}', rawurlencode($key), (string) $url);
	}
}
