<?php
/**
 * Checkout — Maps / Frontend Assets
 *
 * Owns map script loading for whichever provider the store selected,
 * and the async/defer attribute for Google's loader. Split out of
 * ROUTEW_Checkout in 1.2.0; made provider-agnostic in 1.3.0.
 *
 * Two engines are supported (see ROUTEW_Map_Providers):
 *  - `google`  — Maps JavaScript API, loaded from Google with the
 *                store's browser key.
 *  - `leaflet` — bundled Leaflet 1.9.4 plus the provider's tile URL.
 *                Used by OpenStreetMap, MapTiler and Geoapify. Leaflet
 *                ships with the plugin (no CDN) so the checkout has no
 *                third-party script dependency and works offline in
 *                development.
 *
 * @since      1.2.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ROUTEW_Checkout_Maps
 *
 * @since 1.2.0
 */
class ROUTEW_Checkout_Maps
{

    /**
     * Register frontend hooks.
     *
     * @since 1.2.0
     */
    public function __construct()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('script_loader_tag', array($this, 'add_async_defer_to_maps_script'), 10, 2);
    }

    /**
     * Enqueue the map assets for the checkout page.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {
        if (!is_checkout()) {
            return;
        }

        $options = get_option('routew_settings');
        $provider = ROUTEW_Map_Providers::active($options);

        // A provider that needs a key but has none cannot render a map;
        // loading the picker anyway would show a broken box. The admin
        // sees the configuration warning instead.
        if (!empty($provider['requires_key']) && '' === $provider['key']) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('Map provider %s has no API key — map disabled', $provider['id']),
                    array('source' => 'routemile-for-woocommerce')
                );
            }
            return;
        }

        wp_enqueue_style('routew-frontend', ROUTEW_PLUGIN_URL . 'assets/css/frontend.css', array(), ROUTEW_VERSION);

        if ('leaflet' === $provider['engine']) {
            $this->enqueue_leaflet($provider, $options);
            return;
        }

        $this->enqueue_google($provider, $options);
    }

    /**
     * Bundled Leaflet + the provider's tile layer.
     *
     * @param array $provider Active provider definition.
     * @param array $options  Plugin settings.
     * @since 1.3.0
     */
    private function enqueue_leaflet($provider, $options)
    {
        wp_enqueue_style(
            'routew-leaflet',
            ROUTEW_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.css',
            array(),
            '1.9.4'
        );

        wp_register_script(
            'routew-leaflet',
            ROUTEW_PLUGIN_URL . 'assets/vendor/leaflet/leaflet.js',
            array(),
            '1.9.4',
            true
        );

        wp_register_script(
            'routew-checkout',
            ROUTEW_PLUGIN_URL . 'assets/js/checkout-leaflet.js',
            array('jquery', 'routew-leaflet'),
            ROUTEW_VERSION,
            true
        );

        wp_enqueue_script('routew-checkout');

        $params = $this->common_params($options);

        $images = ROUTEW_PLUGIN_URL . 'assets/vendor/leaflet/images/';

        $params['tile_url'] = ROUTEW_Map_Providers::with_key($provider['tile_url'], $provider['key']);
        $params['max_zoom'] = isset($provider['max_zoom']) ? (int) $provider['max_zoom'] : 19;
        // Per WP.org Guideline 10 the "Powered by ..." attribution is opt-in.
        // The default is OFF (empty string); admin enables via the Map
        // Provider section ("Show provider credit"). Note: some providers
        // (notably OpenStreetMap) require visible attribution under their
        // own licence — admin is responsible for honouring those terms.
        $show_credit = !empty($options['routew_show_map_credit']);
        $params['attribution'] = ($show_credit && isset($provider['attribution']))
            ? $provider['attribution']
            : '';
        $params['marker_icon'] = $images . 'marker-icon.png';
        $params['marker_icon_2x'] = $images . 'marker-icon-2x.png';
        $params['marker_shadow'] = $images . 'marker-shadow.png';

        wp_localize_script('routew-checkout', 'routew_checkout_params', $params);
    }

    /**
     * Google Maps JavaScript API.
     *
     * @param array $provider Active provider definition.
     * @param array $options  Plugin settings.
     * @since 1.3.0
     */
    private function enqueue_google($provider, $options)
    {
        wp_register_script('routew-checkout', ROUTEW_PLUGIN_URL . 'assets/js/checkout.js', array('jquery'), ROUTEW_VERSION, true);

        $maps_url = add_query_arg(array(
            'key' => $provider['key'],
            'v' => 'weekly',
            'libraries' => 'places,marker,geocoding',
            'callback' => 'routewInitMap',
            'loading' => 'async',
        ), 'https://maps.googleapis.com/maps/api/js');

        wp_register_script('routew-google-maps', $maps_url, array(), ROUTEW_VERSION, true);

        // Guarantee the callback exists before Google's loader runs.
        $inline_stub = '
		window.routewInitMap = window.routewInitMap || function() {
			if (typeof window.fxwInternalInit === "function") {
				try {
					window.fxwInternalInit();
				} catch(e) {
					// Silent failure in production
				}
			} else {
				var retries = 0;
				var waitForInternal = function() {
					if (typeof window.fxwInternalInit === "function") {
						try {
							window.fxwInternalInit();
						} catch(e) {
							// Silent failure in production
						}
					} else if (retries < 50) {
						retries++;
						setTimeout(waitForInternal, 100);
					}
				};
				setTimeout(waitForInternal, 100);
			}
		};';

        wp_add_inline_script('routew-google-maps', $inline_stub, 'before');

        wp_enqueue_script('routew-checkout');
        wp_enqueue_script('routew-google-maps');

        $params = $this->common_params($options);
        $params['map_id'] = isset($options['routew_google_maps_map_id']) ? trim((string) $options['routew_google_maps_map_id']) : '';

        wp_localize_script('routew-checkout', 'routew_checkout_params', $params);
    }

    /**
     * Localised data both pickers need. Deliberately minimal — never the
     * whole options array.
     *
     * @param array $options Plugin settings.
     * @return array
     * @since 1.3.0
     */
    private function common_params($options)
    {
        $saved_address = is_user_logged_in() ? get_user_meta(get_current_user_id(), '_routew_delivery_profile', true) : null;

        // Restaurant centre for the radius circle + map default (coords only;
        // pure parse when routew_restaurant_latlng is set, else 24h-cached geocode)
        $restaurant_center = (new ROUTEW_Mapping_Service())->get_restaurant_location($options);
        $restaurant_center = is_wp_error($restaurant_center) ? null : $restaurant_center;

        return array(
            'rest_url' => esc_url_raw(rest_url('routemile/v1/checkout')),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'currency_symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '',
            'saved_address' => $saved_address,
            'restaurant_center' => $restaurant_center,
            'radius_km' => isset($options['routew_delivery_zone_radius']) ? (float) $options['routew_delivery_zone_radius'] : (float) ROUTEW_Config::DEFAULT_DELIVERY_RADIUS,
            'translations' => array(
                'calculating' => __('Calculating delivery fee...', 'routemile-for-woocommerce'),
                // Success-toast labels (1.2.15): previously the toast reused
                // the "calculating" string with the ellipsis stripped.
                'delivery_fee_estimated' => __('Delivery fee:', 'routemile-for-woocommerce'),
                'free_delivery' => __('Free delivery', 'routemile-for-woocommerce'),
                'out_of_zone' => __('Sorry, we do not deliver to this location.', 'routemile-for-woocommerce'),
                'error_generic' => __('An error occurred. Please try again.', 'routemile-for-woocommerce'),
                // Distinct from error_generic: a map that will not load is a
                // store configuration problem, not something retrying fixes
                // (1.3.0 — the old message told customers to try again after
                // a Google billing error).
                'map_unavailable' => __('The delivery map could not load. Please contact the store.', 'routemile-for-woocommerce'),
                'estimated' => __('estimated', 'routemile-for-woocommerce'),
                'drag_pin' => __('Drag to set your exact delivery point', 'routemile-for-woocommerce'),
                'search_no_results' => __('No match found. Try a different search, or drag the map pin.', 'routemile-for-woocommerce'),
                'geolocation_unsupported' => __('Geolocation is not supported by your browser.', 'routemile-for-woocommerce'),
                'locating' => __('Locating…', 'routemile-for-woocommerce'),
                'location_denied' => __('Location permission denied. Please allow access in your browser settings.', 'routemile-for-woocommerce'),
                'location_unavailable' => __('Location unavailable. Please try again.', 'routemile-for-woocommerce'),
                'location_timeout' => __('Location request timed out. Please try again.', 'routemile-for-woocommerce'),
            ),
        );
    }

    /**
     * Add defer to the Google Maps loader for non-blocking execution.
     *
     * @param string $tag    The script tag.
     * @param string $handle The script handle.
     * @return string Modified script tag.
     */
    public function add_async_defer_to_maps_script($tag, $handle)
    {
        if ('routew-google-maps' === $handle) {
            $tag = str_replace('<script ', '<script defer ', $tag);
        }
        return $tag;
    }

}

new ROUTEW_Checkout_Maps();
