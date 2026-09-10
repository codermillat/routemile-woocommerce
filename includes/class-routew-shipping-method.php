<?php
/**
 * Manages the custom shipping method.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guard against loading before WooCommerce is initialized
if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	return;
}

if ( ! class_exists( 'ROUTEW_Shipping_Method' ) ) {
	class ROUTEW_Shipping_Method extends WC_Shipping_Method {
		/**
		 * Constructor for your shipping class
		 *
		 * @access public
		 * @return void
		 */
		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'routemile_delivery';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'RouteMile Delivery', 'routemile-for-woocommerce' );
			$this->method_description = __( 'Dynamic distance-based shipping for RouteMile.', 'routemile-for-woocommerce' );
			$this->supports           = array(
				'shipping-zones',
				'instance-settings',
				'instance-settings-modal',
			);
			$this->init();
		}

		/**
		 * Init your settings
		 *
		 * @access public
		 * @return void
		 */
		function init() {
			$this->init_form_fields();
			$this->init_settings();

			// Define user set variables
			$this->title      = $this->get_option( 'title' );
			$this->enabled    = $this->get_option( 'enabled' );

			// Save settings in admin if you have any defined
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		/**
		 * Define settings field for this shipping
		 * @return void
		 */
		function init_form_fields() {
			$this->instance_form_fields = array(
				'title' => array(
					'title'       => __( 'Title', 'routemile-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'This controls the title which the user sees during checkout.', 'routemile-for-woocommerce' ),
					'default'     => __( 'RouteMile Delivery', 'routemile-for-woocommerce' ),
					'desc_tip'    => true,
				),
			);
		}

		/**
		 * Calculate the shipping cost from the restaurant's coordinates to
		 * the customer's pinned coordinates. Nothing else influences the
		 * fee — by design, no address field ever reaches this method.
		 *
		 * @param array $package
		 */
		public function calculate_shipping( $package = array() ) {
			$options = get_option( 'routew_settings' );

			// Skip rate calculation only when no usable map provider is
			// configured at all. Provider-aware: Leaflet/OSM providers work
			// without `routew_google_maps_api_key`, so requiring that key
			// unconditionally bails every keyless install (v1.3.4 intent).
			//
			// IMPORTANT: the capability checked here must be one every
			// configured provider actually lists. 'distance' is NOT a
			// provider capability (the registry lists map/geocode/routing),
			// so checking supports('distance') returned false for OSM and
			// this method silently added no rate at all — the exact cause
			// of "No shipping options are available for this address."
			// after v1.3.4 (found 2026-08-18 via the WC log: the last
			// "adding rate" entry predates the v1.3.4 deploy; fixed 1.3.8).
			if ( class_exists( 'ROUTEW_Map_Providers' ) && ! ROUTEW_Map_Providers::supports( 'map', $options ) ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( 'calculate_shipping: no configured map provider — no rate', array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return;
			}
			if ( ! class_exists( 'ROUTEW_Map_Providers' ) && empty( $options['routew_google_maps_api_key'] ) ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( 'calculate_shipping: no Google key (legacy path) — no rate', array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return;
			}

			if ( ! WC()->session ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( 'calculate_shipping: no session — no rate', array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return;
			}

			// The Open/Closed switch controls order placement (no rate when
			// closed). Read via ROUTEW_Store_Hours: it is loaded on every
			// request, while the checkout classes are frontend/AJAX only —
			// admin-created orders also calculate rates (v1.2.19).
			if ( class_exists( 'ROUTEW_Store_Hours' ) && ! ROUTEW_Store_Hours::is_store_open() ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( 'calculate_shipping: store closed (routew_is_open=false)', array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return;
			}

			// Customer's pinned coordinates (session, set by the map/REST flow)
			$customer_lat = WC()->session->get( 'customer_lat' );
			$customer_lng = WC()->session->get( 'customer_lng' );

			if ( ! $customer_lat || ! $customer_lng ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( 'calculate_shipping: no pinned location yet — no rate', array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return; // Can't calculate shipping without a pinned location
			}

			// Restaurant coordinates — explicit setting, else geocoded address (cached)
			$mapping_service = new ROUTEW_Mapping_Service();
			$restaurant      = $mapping_service->get_restaurant_location( $options );

			if ( is_wp_error( $restaurant ) ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( 'calculate_shipping: ' . $restaurant->get_error_message(), array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return;
			}

			// Prefer the toast's quoted fee when it covers THESE exact coords:
			// validate_location() stored distance_km + cost for the pin the
			// customer just dropped. Reusing the quote (instead of recomputing
			// a possibly different distance) keeps the order summary identical
			// to the toast (2026-09-10: toast said $17.65, totals charged
			// $15.73 for the same pin). Falls through to a fresh quote when
			// the pin moved or no quote exists.
			$distance_in_km = null;
			$quoted_cost = null;
			$quoted_is_free = false;
			$last_quote = WC()->session->get( 'routew_last_quote' );
			if ( is_array( $last_quote )
				&& isset( $last_quote['lat'], $last_quote['lng'], $last_quote['distance_km'], $last_quote['cost'] )
				&& abs( (float) $last_quote['lat'] - (float) $customer_lat ) < 0.00005
				&& abs( (float) $last_quote['lng'] - (float) $customer_lng ) < 0.00005 ) {
				$distance_in_km = (float) $last_quote['distance_km'];
				$quoted_cost = (float) $last_quote['cost'];
				$quoted_is_free = ! empty( $last_quote['is_free'] );
			}

			if ( null === $distance_in_km ) {
				$distance_data = $mapping_service->get_distance(
					$restaurant,
					array( 'lat' => $customer_lat, 'lng' => $customer_lng )
				);

			if ( is_wp_error( $distance_data ) ) {
				// Log and surface a user-friendly error at checkout
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error( 'RouteMile distance error: ' . $distance_data->get_error_message(), array( 'source' => 'routemile-for-woocommerce' ) );
				}
				if ( function_exists( 'wc_add_notice' ) && function_exists( 'is_checkout' ) && is_checkout() ) {
					wc_add_notice( __( 'We could not calculate delivery distance. Please verify your location and try again.', 'routemile-for-woocommerce' ), 'error' );
				}
				return;
			}

		if ( ! isset( $distance_data['distance'] ) || ! is_object( $distance_data['distance'] ) || ! isset( $distance_data['distance']->value ) ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'calculate_shipping: distance response missing distance value', array( 'source' => 'routemile-for-woocommerce' ) );
			}
			return;
		}

		$distance_in_km = round( $distance_data['distance']->value / 1000, 2 );

			// Store distance data in session for UI/ETA reuse
			if ( WC()->session ) {
				WC()->session->set( 'routew_distance_data', array(
					'distance' => $distance_data['distance'],
					'duration' => $distance_data['duration'],
					// Carried through so the shipping label can mark a
					// straight-line estimate honestly (1.3.0).
					'estimated' => ! empty( $distance_data['estimated'] ),
				) );
			}
		} // end fresh-distance fetch (quoted path skips the API call)

		$radius = isset( $options['routew_delivery_zone_radius'] ) ? (float) $options['routew_delivery_zone_radius'] : 10;

		if ( $distance_in_km > $radius ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->info( sprintf( 'calculate_shipping: out of zone (distance=%.3f km, radius=%.3f)', $distance_in_km, $radius ), array( 'source' => 'routemile-for-woocommerce' ) );
			}
			return; // Outside delivery zone
		}

		// Admin-configurable pricing rules (ROUTEW_Pricing): minimum order,
		// distance tiers, free-delivery threshold.
		if ( class_exists( 'ROUTEW_Pricing' ) ) {
			$min_error = ROUTEW_Pricing::minimum_order_error();
			if ( null !== $min_error ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( 'calculate_shipping: below minimum order — no rate', array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return;
			}
		}

		// Fee via the shared quote helper (same function the REST toast
		// calls) so the rate matches the toast exactly. When the toast
		// quoted these exact coords, reuse its cost; otherwise quote fresh.
		if ( null !== $quoted_cost ) {
			$cost = $quoted_cost;
			$is_free = $quoted_is_free;
			if ( class_exists( 'ROUTEW_Pricing' ) ) {
				$live_quote = ROUTEW_Pricing::quote_for_distance( $distance_in_km, $options );
				if ( ! empty( $live_quote['beyond_tiers'] ) ) {
					if ( function_exists( 'wc_get_logger' ) ) {
						wc_get_logger()->info( sprintf( 'calculate_shipping: distance %.2f km beyond all tiers — no rate', $distance_in_km ), array( 'source' => 'routemile-for-woocommerce' ) );
					}
					return;
				}
				if ( $live_quote['is_free'] !== $is_free ) {
					$cost = (float) $live_quote['cost'];
					$is_free = (bool) $live_quote['is_free'];
				}
				$base_fee = isset( $options['routew_delivery_fee_base'] ) ? (float) $options['routew_delivery_fee_base'] : 5;
				$fee_per_km = isset( $options['routew_delivery_fee_per_km'] ) ? (float) $options['routew_delivery_fee_per_km'] : 1.5;
			}
		} else {
			$quote = class_exists( 'ROUTEW_Pricing' )
				? ROUTEW_Pricing::quote_for_distance( $distance_in_km, $options )
				: array( 'cost' => 0, 'is_free' => false, 'beyond_tiers' => false );
			if ( ! empty( $quote['beyond_tiers'] ) ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->info( sprintf( 'calculate_shipping: distance %.2f km beyond all tiers — no rate', $distance_in_km ), array( 'source' => 'routemile-for-woocommerce' ) );
				}
				return;
			}
			$cost = (float) $quote['cost'];
			$is_free = ! empty( $quote['is_free'] );
			$base_fee = isset( $options['routew_delivery_fee_base'] ) ? (float) $options['routew_delivery_fee_base'] : 5;
			$fee_per_km = isset( $options['routew_delivery_fee_per_km'] ) ? (float) $options['routew_delivery_fee_per_km'] : 1.5;
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( sprintf( 'calculate_shipping: adding rate cost=%.2f (distance=%.3f km, base=%.2f, per_km=%.2f)', $cost, $distance_in_km, $base_fee, $fee_per_km ), array( 'source' => 'routemile-for-woocommerce' ) );
		}

		$rate_id = $this->id . ( $this->instance_id ? ':' . $this->instance_id : '' );

		// When the free-delivery threshold zeroed the cost, say so in the
		// label itself — the confirmation screen otherwise renders the
		// shipping row with a blank/zero amount and customers read that
		// as a broken order rather than a discount (1.3.9).
		$label = $is_free
			? sprintf(
				/* translators: %s: shipping method title shown to the customer. */
				__( '%s (Free delivery)', 'routemile-for-woocommerce' ),
				$this->title
			)
			: $this->title;

		$this->add_rate( array(
			'id'      => $rate_id,
			'label'   => $label,
			'cost'    => $cost,
			'package' => $package,
		) );
		}
	}
}
