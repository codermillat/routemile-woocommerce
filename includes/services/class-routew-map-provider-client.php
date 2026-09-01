<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Provider-specific geocoding and routing calls.
 *
 * ROUTEW_Mapping_Service owns caching, normalisation and the public API;
 * this class owns the per-provider HTTP shapes so neither file grows
 * past the 500-LOC cap. Every request goes through WP's HTTP API.
 *
 * @since      1.3.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Map_Provider_Client
{

	/**
	 * Provider definition (output of ROUTEW_Map_Providers::active()).
	 *
	 * @var array
	 */
	private $provider;

	/**
	 * @param array $provider Active provider definition.
	 * @since 1.3.0
	 */
	public function __construct($provider)
	{
		$this->provider = is_array($provider) ? $provider : array();
	}

	/**
	 * GET a provider endpoint with one retry on transient failure.
	 *
	 * Nominatim's usage policy requires an identifying User-Agent; we send
	 * the site URL for every provider so operators can be contacted.
	 *
	 * @param string $url Absolute https URL.
	 * @return array|WP_Error
	 * @since 1.3.0
	 */
	private function get($url)
	{
		if (!$this->is_allowed_url($url)) {
			return new WP_Error('invalid_endpoint', __('Map provider endpoint is not allowed.', 'routemile-for-woocommerce'));
		}

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'User-Agent' => 'RouteMile/' . ROUTEW_VERSION . ' (WordPress; ' . home_url('/') . ')',
				'Accept' => 'application/json',
			),
		);

		$response = wp_remote_get($url, $args);

		$transient_failure = is_wp_error($response)
			|| (is_array($response) && isset($response['response']['code']) && (int) $response['response']['code'] >= 500);

		if ($transient_failure) {
			usleep(300000); // 300 ms
			$response = wp_remote_get($url, $args);
		}

		if (is_wp_error($response)) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if (200 !== $code) {
			return new WP_Error('provider_http_error', sprintf(
				/* translators: %d: HTTP status code. */
				__('Map provider returned HTTP %d.', 'routemile-for-woocommerce'),
				$code
			));
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($data)) {
			return new WP_Error('provider_bad_body', __('Map provider returned an unreadable response.', 'routemile-for-woocommerce'));
		}

		return $data;
	}

	/**
	 * Only https endpoints on public hosts are callable. Blocks loopback,
	 * private and reserved ranges so a tampered provider URL cannot be
	 * used to probe the local network.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 * @since 1.3.0
	 */
	private function is_allowed_url($url)
	{
		$parts = wp_parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return false;
		}

		if ('https' !== strtolower($parts['scheme'])) {
			return false;
		}

		$host = strtolower($parts['host']);
		if ('localhost' === $host || 'localhost.localdomain' === $host || '' === $host) {
			return false;
		}

		// Reject literal IPs in loopback / private / reserved ranges.
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			$public = filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
			if (false === $public) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Honour a provider's site-wide minimum interval between requests.
	 *
	 * Nominatim's usage policy limits the whole *application* to one
	 * request per second, so a per-visitor rate limit is not enough — two
	 * simultaneous customers would each be within their own budget while
	 * together breaching the policy. A short-lived transient acts as the
	 * shared lock.
	 *
	 * @return true|WP_Error
	 * @since 1.3.0
	 */
	private function reserve_slot()
	{
		$interval = isset($this->provider['geocode_min_interval'])
			? (int) $this->provider['geocode_min_interval']
			: 0;

		if ($interval < 1) {
			return true;
		}

		$lock = 'routew_provider_slot_' . (isset($this->provider['id']) ? $this->provider['id'] : 'x');

		// Atomic acquire: wp_cache_add() returns false when another
		// request just inserted the key, so two concurrent callers cannot
		// both pass the previous get/set race. (AUDIT-FIXES M8)
		if (!wp_cache_add($lock, time(), 'routew_provider_lock', $interval)) {
			return new WP_Error('provider_busy', __('Address lookups are busy for a moment — please try again, or drag the map pin instead.', 'routemile-for-woocommerce'));
		}

		return true;
	}

	/**
	 * Forward geocode an address to coordinates.
	 *
	 * @param string $address Free-form address.
	 * @return object|WP_Error Object with ->lat and ->lng.
	 * @since 1.3.0
	 */
	public function geocode($address)
	{
		$slot = $this->reserve_slot();
		if (is_wp_error($slot)) {
			return $slot;
		}

		$id = isset($this->provider['id']) ? $this->provider['id'] : '';
		$key = isset($this->provider['key']) ? $this->provider['key'] : '';

		switch ($id) {
			case 'google':
				$url = add_query_arg(
					array('address' => $address, 'key' => $key),
					$this->provider['geocode_url']
				);
				$data = $this->get($url);
				if (is_wp_error($data)) {
					return $data;
				}
				if (empty($data['results'][0]['geometry']['location'])) {
					return $this->no_result();
				}
				$loc = $data['results'][0]['geometry']['location'];
				return $this->coords($loc['lat'], $loc['lng']);

			case 'osm':
				$url = add_query_arg(
					array('q' => $address, 'format' => 'jsonv2', 'limit' => 1),
					$this->provider['geocode_url']
				);
				$data = $this->get($url);
				if (is_wp_error($data)) {
					return $data;
				}
				if (empty($data[0]['lat']) || empty($data[0]['lon'])) {
					return $this->no_result();
				}
				return $this->coords($data[0]['lat'], $data[0]['lon']);

			case 'maptiler':
				$url = trailingslashit($this->provider['geocode_url']) . rawurlencode($address) . '.json';
				$url = add_query_arg(array('key' => $key, 'limit' => 1), $url);
				$data = $this->get($url);
				if (is_wp_error($data)) {
					return $data;
				}
				// GeoJSON: coordinates are [lng, lat].
				if (empty($data['features'][0]['geometry']['coordinates'][1])) {
					return $this->no_result();
				}
				$coords = $data['features'][0]['geometry']['coordinates'];
				return $this->coords($coords[1], $coords[0]);

			case 'geoapify':
				$url = add_query_arg(
					array('text' => $address, 'limit' => 1, 'format' => 'json', 'apiKey' => $key),
					$this->provider['geocode_url']
				);
				$data = $this->get($url);
				if (is_wp_error($data)) {
					return $data;
				}
				if (!isset($data['results'][0]['lat'], $data['results'][0]['lon'])) {
					return $this->no_result();
				}
				return $this->coords($data['results'][0]['lat'], $data['results'][0]['lon']);
		}

		return new WP_Error('provider_unsupported', __('The configured map provider cannot geocode addresses.', 'routemile-for-woocommerce'));
	}

	/**
	 * Road distance and duration between two coordinate pairs.
	 *
	 * Returns a WP_Error with code `routing_unsupported` when the provider
	 * has no routing capability — callers fall back to the straight-line
	 * estimate rather than failing the order.
	 *
	 * @param array $origin      array('lat'=>float,'lng'=>float).
	 * @param array $destination array('lat'=>float,'lng'=>float).
	 * @return array|WP_Error array('distance'=>metres,'duration'=>seconds).
	 * @since 1.3.0
	 */
	public function route($origin, $destination)
	{
		$id = isset($this->provider['id']) ? $this->provider['id'] : '';
		$key = isset($this->provider['key']) ? $this->provider['key'] : '';

		if (!in_array('routing', (array) $this->provider['capabilities'], true)) {
			return new WP_Error('routing_unsupported', __('Provider has no road routing.', 'routemile-for-woocommerce'));
		}

		switch ($id) {
			case 'google':
				$url = add_query_arg(
					array(
						'origins' => $origin['lat'] . ',' . $origin['lng'],
						'destinations' => $destination['lat'] . ',' . $destination['lng'],
						'units' => 'metric',
						'mode' => 'driving',
						'key' => $key,
					),
					$this->provider['routing_url']
				);
				$data = $this->get($url);
				if (is_wp_error($data)) {
					return $data;
				}
				$element = isset($data['rows'][0]['elements'][0]) ? $data['rows'][0]['elements'][0] : array();
				if (!isset($element['status']) || 'OK' !== $element['status']) {
					return $this->no_result();
				}
				return array(
					'distance' => (int) $element['distance']['value'],
					'duration' => (int) $element['duration']['value'],
				);

			case 'osm':
				// OSRM path order is lng,lat.
				$path = $origin['lng'] . ',' . $origin['lat'] . ';' . $destination['lng'] . ',' . $destination['lat'];
				$url = add_query_arg(
					array('overview' => 'false', 'alternatives' => 'false'),
					$this->provider['routing_url'] . $path
				);
				$data = $this->get($url);
				if (is_wp_error($data)) {
					return $data;
				}
				if (!isset($data['routes'][0]['distance'], $data['routes'][0]['duration'])) {
					return $this->no_result();
				}
				return array(
					'distance' => (int) round($data['routes'][0]['distance']),
					'duration' => (int) round($data['routes'][0]['duration']),
				);

			case 'geoapify':
				$url = add_query_arg(
					array(
						'waypoints' => $origin['lat'] . ',' . $origin['lng'] . '|' . $destination['lat'] . ',' . $destination['lng'],
						'mode' => 'drive',
						'apiKey' => $key,
					),
					$this->provider['routing_url']
				);
				$data = $this->get($url);
				if (is_wp_error($data)) {
					return $data;
				}
				$props = isset($data['features'][0]['properties']) ? $data['features'][0]['properties'] : array();
				if (!isset($props['distance'], $props['time'])) {
					return $this->no_result();
				}
				return array(
					'distance' => (int) round($props['distance']),
					'duration' => (int) round($props['time']),
				);
		}

		return new WP_Error('routing_unsupported', __('Provider has no road routing.', 'routemile-for-woocommerce'));
	}

	/**
	 * Straight-line distance with the admin's road-correction factor
	 * applied. Duration is derived from an average urban delivery speed.
	 *
	 * @param array $origin      array('lat'=>float,'lng'=>float).
	 * @param array $destination array('lat'=>float,'lng'=>float).
	 * @param float $factor      Road correction multiplier.
	 * @return array array('distance'=>metres,'duration'=>seconds,'estimated'=>true).
	 * @since 1.3.0
	 */
	public static function haversine($origin, $destination, $factor)
	{
		$earth = 6371000.0; // metres

		$lat1 = deg2rad((float) $origin['lat']);
		$lat2 = deg2rad((float) $destination['lat']);
		$dlat = $lat2 - $lat1;
		$dlng = deg2rad((float) $destination['lng'] - (float) $origin['lng']);

		$a = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;
		$straight = $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));

		$distance = $straight * (float) $factor;

		// 20 km/h average including stops — deliberately conservative so
		// the customer-facing ETA does not read as a promise.
		$duration = $distance > 0 ? (int) round($distance / (20000 / 3600)) : 0;

		return array(
			'distance' => (int) round($distance),
			'duration' => $duration,
			'estimated' => true,
		);
	}

	/**
	 * @return WP_Error
	 * @since 1.3.0
	 */
	private function no_result()
	{
		return new WP_Error('no_results', __('Could not find that location. Please try a different address or move the map pin.', 'routemile-for-woocommerce'));
	}

	/**
	 * @param mixed $lat Latitude.
	 * @param mixed $lng Longitude.
	 * @return object
	 * @since 1.3.0
	 */
	private function coords($lat, $lng)
	{
		return (object) array('lat' => (float) $lat, 'lng' => (float) $lng);
	}
}
