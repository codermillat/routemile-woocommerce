<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API Controller for Checkout operations.
 *
 * @since      1.1.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_REST_Checkout_Controller extends WP_REST_Controller
{

    /**
     * Endpoint namespace.
     *
     * @var string
     */
    protected $namespace = 'routemile/v1';

    /**
     * Endpoint base.
     *
     * @var string
     */
    protected $rest_base = 'checkout';

    /**
     * Register the routes for the objects of the controller.
     */
    public function register_routes()
    {
        // GET /wp-json/routemile/v1/checkout/settings
        register_rest_route($this->namespace, '/' . $this->rest_base . '/settings', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'get_settings'),
                'permission_callback' => '__return_true', // Public endpoint for frontend config
            ),
        ));

        // POST /wp-json/routemile/v1/checkout/validate-location
        register_rest_route($this->namespace, '/' . $this->rest_base . '/validate-location', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'validate_location'),
                'permission_callback' => '__return_true', // Public endpoint but rate limited
                'args' => $this->get_validate_location_args(),
            ),
        ));

        // POST /wp-json/routemile/v1/checkout/cart-update
        //
        // Block-checkout live total refresh hook. Called from the picker
        // via `wc.blocksCheckout.extensionCartUpdate({ namespace: 'routemile-for-woocommerce',
        // data: { lat, lng } })`. The picker used to fall back to a debounced
        // `$(document.body).trigger('update_checkout')`, but the block
        // checkout's React tree does not listen for that jQuery event — the
        // fee updated in the toast and lagged in the totals. Routing the
        // pin coordinates through the documented Store API extensions
        // endpoint re-runs the cart/shipping calculation server-side and
        // returns the updated cart, which `extensionCartUpdate` applies to
        // the visible totals instantly (1.3.0).
        register_rest_route($this->namespace, '/' . $this->rest_base . '/cart-update', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => array($this, 'cart_update'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'lat' => array(
                        'required' => true,
                        'type' => 'number',
                    ),
                    'lng' => array(
                        'required' => true,
                        'type' => 'number',
                    ),
                ),
            ),
        ));

        // GET /wp-json/routemile/v1/checkout/geocode
        //
        // Address lookup for the Leaflet picker. Proxied server-side on
        // purpose: keyed providers must never expose their key to the
        // browser, and Nominatim's usage policy expects one identified
        // caller rather than every visitor's browser. (1.3.0)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/geocode', array(
            array(
                'methods' => WP_REST_Server::READABLE,
                'callback' => array($this, 'geocode'),
                'permission_callback' => '__return_true', // Public but rate limited
                'args' => array(
                    'q' => array(
                        'required' => true,
                        'type' => 'string',
                        'description' => __('Address or place to look up.', 'routemile-for-woocommerce'),
                        'sanitize_callback' => function ($param) {
                            return sanitize_text_field($param);
                        },
                    ),
                ),
            ),
        ));
    }

    /**
     * Look up coordinates for a free-text address via the store's
     * configured map provider.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     * @since 1.3.0
     */
    public function geocode($request)
    {
        if (class_exists('ROUTEW_Rate_Limiter')) {
            $limit_check = ROUTEW_Rate_Limiter::check_rate_limit('geocode_rest', 20, MINUTE_IN_SECONDS);
            if (is_wp_error($limit_check)) {
                return $limit_check;
            }
        }

        $query = trim((string) $request->get_param('q'));
        if (mb_strlen($query) < 3) {
            return new WP_Error('query_too_short', __('Please type at least 3 characters.', 'routemile-for-woocommerce'), array('status' => 400));
        }

        $coords = (new ROUTEW_Mapping_Service())->get_coords($query);
        if (is_wp_error($coords)) {
            return new WP_Error('geocode_failed', $coords->get_error_message(), array('status' => 400));
        }

        return rest_ensure_response(array(
            'lat' => (float) $coords->lat,
            'lng' => (float) $coords->lng,
        ));
    }

    /**
     * Get public settings for the checkout frontend.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_settings($request)
    {
        $options = get_option('routew_settings');

        // Return only safe, public settings
        $data = array(
            'radius' => isset($options['routew_delivery_zone_radius']) ? (float) $options['routew_delivery_zone_radius'] : 10,
            // Schedule-aware: respects both the manual Open/Closed toggle and
            // the scheduled opening hours (ROUTEW_Store_Hours) — single source of
            // truth shared with classic + blocks checkout validation (1.2.16).
            'is_open' => class_exists('ROUTEW_Checkout') ? ROUTEW_Checkout::is_store_open() : (isset($options['routew_is_open']) ? filter_var($options['routew_is_open'], FILTER_VALIDATE_BOOLEAN) : true),
            'messages' => array(
                'out_of_zone' => __('Sorry, we do not deliver to this location.', 'routemile-for-woocommerce'),
                'store_closed' => __('Sorry, we are currently closed for deliveries.', 'routemile-for-woocommerce'),
            )
        );

        return rest_ensure_response($data);
    }

    /**
     * Validate a location and calculate delivery fee.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function validate_location($request)
    {
        // Rate Limiting Check
        if (class_exists('ROUTEW_Rate_Limiter')) {
            $limit_check = ROUTEW_Rate_Limiter::check_rate_limit('validate_location_rest', 30, MINUTE_IN_SECONDS);
            if (is_wp_error($limit_check)) {
                return $limit_check;
            }
        }

        $params = $request->get_params();
        $lat = (float) $params['lat'];
        $lng = (float) $params['lng'];

        // Basic coordinate validation
        if (abs($lat) > 90 || abs($lng) > 180) {
            return new WP_Error('invalid_coordinates', __('Invalid coordinates provided.', 'routemile-for-woocommerce'), array('status' => 400));
        }

        $options = get_option('routew_settings');

        // Store open? Schedule-aware — single source of truth shared with the
        // classic + blocks checkout validators and the GET settings endpoint.
        if (class_exists('ROUTEW_Checkout') && !ROUTEW_Checkout::is_store_open()) {
            return new WP_Error('store_closed', __('Sorry, we are currently closed for deliveries.', 'routemile-for-woocommerce'), array('status' => 400));
        }

        // Restaurant coordinates — explicit setting, else geocoded address (cached)
        $mapping_service = new ROUTEW_Mapping_Service();
        $restaurant = $mapping_service->get_restaurant_location($options);

        if (is_wp_error($restaurant)) {
            return new WP_Error('configuration_error', $restaurant->get_error_message(), array('status' => 500));
        }

        $distance_data = $mapping_service->get_distance($restaurant, array('lat' => $lat, 'lng' => $lng));

        if (is_wp_error($distance_data)) {
            return $distance_data;
        }

        if (!isset($distance_data['distance']) || !is_object($distance_data['distance']) || !isset($distance_data['distance']->value)) {
            return new WP_Error('distance_error', __('Could not calculate delivery distance.', 'routemile-for-woocommerce'), array('status' => 500));
        }

        $distance_in_km = $distance_data['distance']->value / 1000;
        $radius = isset($options['routew_delivery_zone_radius']) ? (float) $options['routew_delivery_zone_radius'] : 10;

        if ($distance_in_km > $radius) {
            // 200 with in_zone:false, not a 4xx. Pinning outside the zone is
            // a normal business outcome, not a transport or request error —
            // returning 400 made the browser log a red console error for
            // expected behaviour. Malformed requests still 4xx (1.3.0).
            return rest_ensure_response(array(
                'status' => 'error',
                'in_zone' => false,
                'code' => 'out_of_zone',
                'message' => __('Sorry, we do not deliver to your location.', 'routemile-for-woocommerce'),
                'distance_km' => round($distance_in_km, 2),
            ));
        }

        // WooCommerce does NOT initialize the cart/session for custom REST
        // routes (its is_request('frontend') check excludes REST_REQUEST), so
        // WC()->session is null here by default. The entire checkout flow
        // depends on this request persisting the pinned coordinates to the
        // session — validation reads them from there and placement always
        // fails without them. Bootstrap the session exactly the way
        // WooCommerce's own Store API does (CartController::load_cart()):
        // wc_load_cart() initializes session + customer + cart, restores any
        // existing session from the cookie, and registers save_data on
        // shutdown. wc_load_cart() exists since WC 3.7; this plugin requires
        // WC 7.0+, so it is always available.
        if (!WC()->session && function_exists('wc_load_cart')) {
            wc_load_cart();
            // For a brand-new guest session the handler does not send the
            // cookie automatically on REST requests (that only happens on
            // frontend requests) — without it the data saved on shutdown
            // would be unreachable from the customer's next request. Set it
            // explicitly, mirroring what WC core does on the order-pay page.
            if (WC()->session && method_exists(WC()->session, 'set_customer_session_cookie')) {
                WC()->session->set_customer_session_cookie(true);
            }
        }

        // Calculate Fee (estimate — honors configured tiers + threshold;
        // with the session/cart loaded, the free-delivery threshold can see
        // the real cart subtotal)
        $base_fee = isset($options['routew_delivery_fee_base']) ? (float) $options['routew_delivery_fee_base'] : 5;
        $fee_per_km = isset($options['routew_delivery_fee_per_km']) ? (float) $options['routew_delivery_fee_per_km'] : 1.5;
        $cost = $base_fee + ($distance_in_km * $fee_per_km);
        if (class_exists('ROUTEW_Pricing')) {
            $tier_fee = ROUTEW_Pricing::fee_for_distance($distance_in_km, $options);
            if (null !== $tier_fee && false !== $tier_fee) {
                $cost = $tier_fee;
            }
            if (ROUTEW_Pricing::is_free_delivery()) {
                $cost = 0;
            }
        }

        // Store in session for checkout (critical for order processing):
        // the session is bootstrapped above, so these writes persist to the
        // customer's checkout request via the cookie + shutdown save.
        if (WC()->session) {
            WC()->session->set('customer_lat', $lat);
            WC()->session->set('customer_lng', $lng);
            WC()->session->set('routew_distance_data', $distance_data);
        }

        // Store-formatted fee (auto currency, decimals, symbol position).
        // Entity-decoded because the pickers render this with .textContent
        // (the safe choice for untrusted display) — wc_price() emits the
        // currency symbol as an HTML entity, which would otherwise show up
        // literally as "&#8377;12.30" (1.3.0).
        $fee_formatted = '';
        if (function_exists('wc_price')) {
            $fee_formatted = html_entity_decode(
                wp_strip_all_tags(wc_price($cost)),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
        }

        return rest_ensure_response(array(
            'status' => 'success',
            'in_zone' => true,
            'distance_km' => round($distance_in_km, 2),
            'fee' => round($cost, 2),
            'fee_formatted' => $fee_formatted,
            // True when the provider has no road routing and the distance
            // is a straight-line estimate — the picker labels it so the
            // ETA does not read as a driven route (1.3.0).
            'estimated' => !empty($distance_data['estimated']),
            'duration_text' => (isset($distance_data['duration']) && is_object($distance_data['duration']) && isset($distance_data['duration']->text)) ? $distance_data['duration']->text : ''
        ));
    }

    /**
     * Persist pin coordinates to the session and return the refreshed cart.
     *
     * The block-checkout picker fires this via
     * `extensionCartUpdate({ namespace: 'routemile-for-woocommerce', data: { lat, lng } })`
     * after every drag/geolocation. The Store API applies the returned
     * `cart` directly to the visible totals — no manual refresh needed.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     * @since 1.3.0
     */
    public function cart_update($request)
    {
        if (class_exists('ROUTEW_Rate_Limiter')) {
            $limit_check = ROUTEW_Rate_Limiter::check_rate_limit('cart_update_rest', 60, MINUTE_IN_SECONDS);
            if (is_wp_error($limit_check)) {
                return $limit_check;
            }
        }

        $lat = (float) $request->get_param('lat');
        $lng = (float) $request->get_param('lng');

        if (abs($lat) > 90 || abs($lng) > 180) {
            return new WP_Error('invalid_coordinates', __('Invalid coordinates provided.', 'routemile-for-woocommerce'), array('status' => 400));
        }

        // REST requests don't get WC()->session by default; bootstrap it the
        // same way the validate-location route does so the coordinates
        // actually persist to the customer's next request.
        if (!WC()->session && function_exists('wc_load_cart')) {
            wc_load_cart();
            if (WC()->session && method_exists(WC()->session, 'set_customer_session_cookie')) {
                WC()->session->set_customer_session_cookie(true);
            }
        }

        if (!WC()->session) {
            return new WP_Error('session_unavailable', __('Checkout session is not available.', 'routemile-for-woocommerce'), array('status' => 500));
        }

        WC()->session->set('customer_lat', $lat);
        WC()->session->set('customer_lng', $lng);

        // Recompute cart totals + shipping packages so the shipping method
        // re-reads the freshly-stored coordinates (it pulls them from the
        // session on each `calculate_shipping` pass). WC's Store API does
        // this internally for `cart/extensions` handlers; we call the same
        // primitives here.
        if (WC()->cart) {
            WC()->cart->calculate_shipping();
            WC()->cart->calculate_totals();
        }

        return rest_ensure_response(array(
            'status' => 'success',
            // Returned shape mirrors wc/store/cart so block components that
            // read the response get a real cart back, but the live total
            // refresh only requires `status`. Future extensions can attach
            // fee/distance here without changing the client.
        ));
    }

    /**
     * Get arguments for the validate location endpoint.
     *
     * @return array
     */
    public function get_validate_location_args()
    {
        return array(
            'lat' => array(
                'required' => true,
                'type' => 'number',
                'description' => __('Latitude coordinate (-90 to 90).', 'routemile-for-woocommerce'),
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param) && abs((float) $param) <= 90;
                },
                // Closure form — bare 'floatval' is now a WP-internal
                // callback that receives ($value, $request, $key) and
                // crashes on PHP 8 (v1.2.17 — caught in runtime QA).
                'sanitize_callback' => function ($param) {
                    return (float) $param;
                },
            ),
            'lng' => array(
                'required' => true,
                'type' => 'number',
                'description' => __('Longitude coordinate (-180 to 180).', 'routemile-for-woocommerce'),
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param) && abs((float) $param) <= 180;
                },
                'sanitize_callback' => function ($param) {
                    return (float) $param;
                },
            ),
        );
    }
}
