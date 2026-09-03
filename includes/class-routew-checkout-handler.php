<?php
/**
 * Checkout — Server-side Handler
 *
 * Owns all server-side checkout concerns: customer location updates,
 * delivery zone validation, address completeness checks, fee calculation,
 * shipping label enrichment, and order meta persistence. Split out of
 * ROUTEW_Checkout in 1.2.0 to keep the orchestrator file lean.
 *
 * @since      1.2.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value, WordPress.DB.SlowDBQuery.slow_db_query_meta_query
// Every method below hooks into WooCommerce's checkout pipeline
// (woocommerce_checkout_create_order, woocommerce_after_checkout_validation,
// etc.). WC's checkout enforces its own checkout nonce (wc_checkout_params
// / wp_nonce_field('woocommerce-process_checkout')) before any of these
// hooks fire, so a per-read nonce check here would be redundant and would
// fail every legitimate checkout. The single meta_key lookup reads the
// checkout-side minimum-order threshold; it is a known lookup pattern for
// per-store delivery settings stored as post meta.

require_once __DIR__ . '/services/class-routew-delivery-fee.php';
require_once __DIR__ . '/services/class-routew-address-validator.php';

/**
 * Class ROUTEW_Checkout_Handler
 *
 * Backend concerns:
 *  - AJAX: update customer location (session + WC_Customer persistence)
 *  - Hook: woocommerce_after_checkout_validation — delivery zone validation
 *  - Hook: woocommerce_checkout_update_user_meta — save delivery profile to user meta
 *  - Hook: woocommerce_checkout_create_order — persist delivery details as order meta
 *
 * Cart-fee and shipping-label hooks live on ROUTEW_Delivery_Fee (extracted
 * in 1.2.0). Address-completeness heuristic lives on ROUTEW_Address_Validator
 * (also extracted in 1.2.0).
 *
 * Each instance registers its own hooks in the constructor; no external
 * wiring required beyond `require_once`-ing this file.
 *
 * @since 1.2.0
 */
class ROUTEW_Checkout_Handler
{

    /**
     * Register AJAX + WooCommerce checkout hooks.
     *
     * @since 1.2.0
     */
    public function __construct()
    {
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_delivery_zone'), 20, 2);
        add_action('woocommerce_after_checkout_validation', array($this, 'validate_phone_number'), 20, 2);
        add_action('woocommerce_checkout_update_user_meta', array($this, 'save_customer_address'), 10, 2);
        add_action('woocommerce_checkout_create_order', array($this, 'save_delivery_details_to_order'), 10, 2);

        // One-shot data migration (1.3.9): pre-1.3.9 orders duplicated the
        // exact address into shipping address line 2, which the order
        // confirmation, receipt and admin screens rendered twice. Run once
        // on the next admin page load after the upgrade.
        add_action('admin_init', array($this, 'maybe_migrate_duplicated_address_2'), 20);
    }

    /**
     * Validate the billing phone number format on the classic checkout.
     *
     * The field's required flag is enforced by
     * ROUTEW_Checkout_Address::require_phone_field(); this adds a format
     * check on non-empty values so riders never get an unusable number.
     * Accepts 7–15 digits with common separators / leading + (E.164-ish).
     *
     * @param array    $data   Posted checkout data.
     * @param WP_Error $errors Errors object.
     * @since 1.6.0
     */
    public function validate_phone_number($data, $errors)
    {
        $phone = isset($data['billing_phone']) ? trim((string) wp_unslash($data['billing_phone'])) : '';
        if ('' === $phone) {
            return; // required-ness is handled by the field config
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            $errors->add(
                'billing_phone',
                __('Please enter a valid phone number (7-15 digits) — your rider will call it on arrival.', 'routemile-for-woocommerce')
            );
        }
    }

    /**
     * Clear the duplicated shipping-address line 2 on orders placed
     * before 1.3.9, normalising them to the new shape: line 2 is either
     * "Landmark: <landmark>" (when a landmark was entered) or empty.
     *
     * Uses the HPOS-safe order CRUD (wc_get_orders + setters) — never a
     * direct table write — and an option flag so it runs exactly once.
     *
     * @since 1.3.9
     */
    public function maybe_migrate_duplicated_address_2()
    {
        if (get_option('routew_migrated_address2_v139')) {
            return;
        }
        // Defensive guard: this is registered on admin_init but should not run
        // for users who cannot manage orders. Plugin Check / WP.org wants the
        // capability explicit, even though admin_init already runs only in
        // admin context.
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }
        if (!function_exists('wc_get_orders')) {
            return; // WooCommerce unavailable; try on the next admin hit.
        }

        $orders = wc_get_orders(array(
            'limit' => -1,
            'status' => 'any',
            'meta_key' => '_routew_delivery_address',
            'return' => 'objects',
        ));

        foreach ($orders as $order) {
            if (!is_a($order, 'WC_Order')) {
                continue;
            }
            $details = trim((string) $order->get_meta('_routew_address_details', true));
            $landmark = trim((string) $order->get_meta('_routew_landmark', true));
            $current_line2 = (string) $order->get_shipping_address_2();

            // Only touch the orders our old code shaped. Old line 2 was
            // either the bare details (no landmark) or
            // "details (Landmark: ...)" (with a landmark).
            if ('' === $details || '' === $current_line2) {
                continue;
            }
            $is_old_shape = ($details === $current_line2)
                || (0 === strpos($current_line2, $details . ' ('));
            if ($is_old_shape) {
                $order->set_shipping_address_2(
                    '' !== $landmark ? sprintf('%s: %s', __('Landmark', 'routemile-for-woocommerce'), $landmark) : ''
                );
                $order->save();
            }
        }

        update_option('routew_migrated_address2_v139', 1);
    }


    /**
     * Persist the delivery profile for a user — shared by the classic
     * checkout hook and the blocks Store API flow.
     *
     * @param int    $user_id      User ID.
     * @param string $details      Exact delivery address.
     * @param string $landmark     Optional landmark.
     * @param string $instructions Optional delivery instructions.
     * @since 1.2.9
     */
    public static function save_delivery_profile_for_user($user_id, $details, $landmark, $instructions)
    {
        $distance_data = WC()->session ? WC()->session->get('routew_distance_data') : null;

        $profile = array(
            'lat' => WC()->session ? WC()->session->get('customer_lat') : null,
            'lng' => WC()->session ? WC()->session->get('customer_lng') : null,
            'address_details' => $details,
            'landmark' => $landmark,
            'delivery_instructions' => $instructions,
            'distance_data' => $distance_data,
        );
        update_user_meta($user_id, '_routew_delivery_profile', $profile);
    }

    /**
     * Persist the delivery profile for logged-in customers at order time —
     * automatically, no opt-in. The saved pin + fields become the default
     * for the next checkout (auto-filled, no re-pinning required); the
     * customer can always move the pin or edit the fields.
     *
     * @param int   $customer_id The customer ID.
     * @param array $data        The posted data.
     * @since 1.0.0
     */
    public function save_customer_address($customer_id, $data)
    {
        $address_details = isset($_POST['routew_address_details']) ? sanitize_text_field(wp_unslash($_POST['routew_address_details'])) : '';
        $landmark = isset($_POST['routew_landmark']) ? sanitize_text_field(wp_unslash($_POST['routew_landmark'])) : '';
        $instructions = isset($_POST['routew_delivery_instructions']) ? sanitize_textarea_field(wp_unslash($_POST['routew_delivery_instructions'])) : '';

        self::save_delivery_profile_for_user($customer_id, $address_details, $landmark, $instructions);
    }

    /**
     * Apply all RouteMile delivery data to an order — the single shared
     * path for classic checkout (woocommerce_checkout_create_order) and
     * block-based checkout (Store API flow). Writes the `_routew_*` meta,
     * the composed display address, the address second line, coordinates,
     * distance, and the store-base country default.
     *
     * @param WC_Order $order         Order (not yet persisted in the classic flow).
     * @param string   $details       Exact delivery address.
     * @param string   $landmark      Optional landmark.
     * @param string   $instructions  Optional delivery instructions.
     * @param mixed    $fallback_lat  Optional posted latitude (classic hidden input).
     * @param mixed    $fallback_lng  Optional posted longitude (classic hidden input).
     * @since 1.2.9
     */
    public static function apply_delivery_data_to_order($order, $details, $landmark, $instructions, $fallback_lat = null, $fallback_lng = null)
    {
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $details = trim((string) $details);
        $landmark = trim((string) $landmark);
        $instructions = trim((string) $instructions);

        if ('' !== $details) {
            $order->update_meta_data('_routew_address_details', $details);
        }
        if ('' !== $landmark) {
            $order->update_meta_data('_routew_landmark', $landmark);
        }
        if ('' !== $instructions) {
            $order->update_meta_data('_routew_delivery_instructions', $instructions);
        }

        // Get coordinates from session (guard against null session in REST/CLI contexts)
        $lat = WC()->session ? WC()->session->get('customer_lat') : null;
        $lng = WC()->session ? WC()->session->get('customer_lng') : null;

        // Get distance data from session
        $distance_data = WC()->session ? WC()->session->get('routew_distance_data') : null;
        $distance_km = 0;
        if ($distance_data && isset($distance_data['distance']) && is_object($distance_data['distance']) && isset($distance_data['distance']->value)) {
            $distance_km = round($distance_data['distance']->value / 1000, 2);
        }

        // Full delivery address for display: exact-address field + landmark
        $full_delivery_address = $details;
        if ('' !== $landmark) {
            $full_delivery_address .= sprintf(' (%s: %s)', __('Landmark', 'routemile-for-woocommerce'), $landmark);
        }
        if ('' !== $full_delivery_address) {
            $order->update_meta_data('_routew_delivery_address', $full_delivery_address);
        }

        // Shipping address line 2 should carry ONLY the landmark (the extra
        // detail beyond the exact address). Since v1.3.0 the customer types
        // the exact address into WooCommerce's own address_1 field (relabeled
        // "Flat / Floor / Block / Society / Tower"), so writing the composed
        // details into address_2 duplicated the street line on the order
        // confirmation, the receipt and the order admin (both read
        // get_formatted_shipping_address() = address_1 + address_2). Address
        // line 2 stays empty unless a landmark was entered (caught
        // 2026-08-18; fixed 1.3.9).
        if ('' !== $landmark) {
            $order->set_shipping_address_2(sprintf('%s: %s', __('Landmark', 'routemile-for-woocommerce'), $landmark));
        }

        // Snapshot the live session into a guest profile key so that a guest
        // returning in the same browser on the next visit (same session
        // cookie) gets their pin pre-loaded by load_saved_address(). Logged-in
        // users get this via user meta (save_delivery_profile_for_user); the
        // session snapshot is the guest analogue. (AUDIT-FIXES L4)
        if (WC()->session && !is_user_logged_in()) {
            $lat_val         = WC()->session->get('customer_lat');
            $lng_val         = WC()->session->get('customer_lng');
            $distance_data_v = WC()->session->get('routew_distance_data');
            if ($lat_val && $lng_val && is_numeric($lat_val) && is_numeric($lng_val)) {
                WC()->session->set('routew_delivery_profile', array(
                    'lat'                  => $lat_val,
                    'lng'                  => $lng_val,
                    'address_details'      => $details,
                    'landmark'             => $landmark,
                    'delivery_instructions'=> $instructions,
                    'distance_data'        => $distance_data_v,
                ));
            }
        }

        // The checkout no longer renders WC address fields — default the
        // country to the store's base country so orders stay well-formed.
        $base_country = function_exists('wc_get_base_location') ? wc_get_base_location() : null;
        $base_country_code = ($base_country && !empty($base_country->country)) ? $base_country->country : '';
        if ('' !== $base_country_code) {
            if ('' === (string) $order->get_billing_country()) {
                $order->set_billing_country($base_country_code);
            }
            if ('' === (string) $order->get_shipping_country()) {
                $order->set_shipping_country($base_country_code);
            }
        }

        // Fallback to posted coordinates if session is empty (classic flow
        // posts the hidden routew_lat/routew_lng inputs). Validated numeric ranges.
        if (!$lat && null !== $fallback_lat && is_numeric($fallback_lat) && abs((float) $fallback_lat) <= 90) {
            $lat = (float) $fallback_lat;
        }
        if (!$lng && null !== $fallback_lng && is_numeric($fallback_lng) && abs((float) $fallback_lng) <= 180) {
            $lng = (float) $fallback_lng;
        }

        if ($lat && $lng && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            $order->update_meta_data('_routew_delivery_lat', (float) $lat);
            $order->update_meta_data('_routew_delivery_lng', (float) $lng);
        }

        if ($distance_km > 0) {
            $order->update_meta_data('_routew_delivery_distance', $distance_km);
        }

        // Log the saved data for debugging
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug(sprintf(
                'apply_delivery_data_to_order: order_id=%d, details=%s, lat=%s, lng=%s, distance=%s km',
                $order->get_id(),
                $details,
                $lat,
                $lng,
                $distance_km
            ), array('source' => 'routemile-for-woocommerce'));
        }
    }

    /**
     * Compact zone check shared with the blocks-checkout field validation:
     * returns an error message when the store is closed, the pinned
     * coordinates are missing, the service is misconfigured, or the pin
     * is outside the delivery radius; null when the location is fine.
     *
     * @return string|null
     * @since 1.2.9
     */
    public static function get_zone_error()
    {
        $options = get_option('routew_settings');

        // Map provider unusable (needs a key and has none): the map is
        // silently absent, so the "select on the map above" copy would
        // reference something the customer cannot see. Give them an
        // actionable message instead (1.2.16; provider-aware in 1.3.0).
        if (class_exists('ROUTEW_Map_Providers') && !ROUTEW_Map_Providers::is_configured($options)) {
            return __('Online ordering is currently unavailable — the delivery map is not configured. Please contact the store.', 'routemile-for-woocommerce');
        }

        if (!ROUTEW_Checkout::is_store_open()) {
            return __('We are currently closed for deliveries. Please try again later.', 'routemile-for-woocommerce');
        }

        if (class_exists('ROUTEW_Pricing')) {
            $min_error = ROUTEW_Pricing::minimum_order_error();
            if (null !== $min_error) {
                return $min_error;
            }
        }

        $customer_lat = WC()->session ? WC()->session->get('customer_lat') : null;
        $customer_lng = WC()->session ? WC()->session->get('customer_lng') : null;

        if (!$customer_lat || !$customer_lng || !is_numeric($customer_lat) || !is_numeric($customer_lng)
            || abs($customer_lat) > 90 || abs($customer_lng) > 180) {
            return __('Please select your exact location on the map above to enable delivery.', 'routemile-for-woocommerce');
        }

        $mapping_service = new ROUTEW_Mapping_Service();
        $restaurant = $mapping_service->get_restaurant_location($options);
        if (is_wp_error($restaurant)) {
            return __('Delivery service is not properly configured. Please contact support.', 'routemile-for-woocommerce');
        }

        $distance_data = $mapping_service->get_distance(
            $restaurant,
            array('lat' => $customer_lat, 'lng' => $customer_lng)
        );

        if (is_wp_error($distance_data)
            || !isset($distance_data['distance']) || !is_object($distance_data['distance']) || !isset($distance_data['distance']->value)) {
            return __('Could not calculate delivery distance. Please try again.', 'routemile-for-woocommerce');
        }

        $radius = isset($options['routew_delivery_zone_radius']) ? (float) $options['routew_delivery_zone_radius'] : ROUTEW_Config::DEFAULT_DELIVERY_RADIUS;
        if (($distance_data['distance']->value / 1000) > $radius) {
            return __('Sorry, we do not deliver to your location.', 'routemile-for-woocommerce');
        }

        return null;
    }

    /**
     * Save delivery details to order meta during checkout.
     *
     * @param WC_Order $order
     * @param array    $data
     * @since 1.0.0
     */
    public function save_delivery_details_to_order($order, $data)
    {
        if (!is_a($order, 'WC_Order')) {
            return;
        }

        $address_details = isset($_POST['routew_address_details']) ? trim(sanitize_text_field(wp_unslash($_POST['routew_address_details']))) : '';
        $landmark = isset($_POST['routew_landmark']) ? trim(sanitize_text_field(wp_unslash($_POST['routew_landmark']))) : '';
        $instructions = isset($_POST['routew_delivery_instructions']) ? trim(sanitize_textarea_field(wp_unslash($_POST['routew_delivery_instructions']))) : '';

        $post_lat = isset($_POST['routew_lat']) && is_numeric($_POST['routew_lat']) ? floatval(wp_unslash($_POST['routew_lat'])) : null;
        $post_lng = isset($_POST['routew_lng']) && is_numeric($_POST['routew_lng']) ? floatval(wp_unslash($_POST['routew_lng'])) : null;

        self::apply_delivery_data_to_order($order, $address_details, $landmark, $instructions, $post_lat, $post_lng);
    }

    /**
     * Validate that the customer's address is within the delivery zone.
     *
     * @param   array      $data      The checkout data.
     * @param   WP_Error   $errors    The errors object.
     * @since   1.0.0
     */
    public function validate_delivery_zone($data, $errors)
    {
        $options = get_option('routew_settings');
        $radius = isset($options['routew_delivery_zone_radius']) ? (float) $options['routew_delivery_zone_radius'] : ROUTEW_Config::DEFAULT_DELIVERY_RADIUS;

        // The Open/Closed switch controls order placement — checked first
        // so the closed message dominates any other validation failure.
        if (!ROUTEW_Checkout::is_store_open()) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->info('validate_delivery_zone: store closed', array('source' => 'routemile-for-woocommerce'));
            }
            $errors->add('delivery_zone', __('We are currently closed for deliveries. Please try again later.', 'routemile-for-woocommerce'));
            return;
        }

        // Admin-configurable minimum order amount
        if (class_exists('ROUTEW_Pricing')) {
            $min_error = ROUTEW_Pricing::minimum_order_error();
            if (null !== $min_error) {
                $errors->add('routew_minimum_order', $min_error);
                return;
            }
        }

        // Validate the single exact-address field (separate from map location)
        $address_details = isset($_POST['routew_address_details']) ? trim(sanitize_text_field(wp_unslash($_POST['routew_address_details']))) : '';

        if (mb_strlen($address_details) < 5) {
            $errors->add('address_details', __('Please enter your exact address (house / flat / building no.) so our delivery agent can find you.', 'routemile-for-woocommerce'));
            return;
        }

        // Enhanced coordinate validation (guard against null session)
        $customer_lat = WC()->session ? WC()->session->get('customer_lat') : null;
        $customer_lng = WC()->session ? WC()->session->get('customer_lng') : null;

        // Ensure coordinates are present and valid
        if (!$customer_lat || !$customer_lng || !is_numeric($customer_lat) || !is_numeric($customer_lng)) {
            $errors->add('delivery_zone', __('Please select your exact location on the map. This ensures accurate delivery and helps our delivery agent find you.', 'routemile-for-woocommerce'));
            return;
        }

        // Validate coordinate precision (must be reasonable GPS coordinates)
        if (abs($customer_lat) > 90 || abs($customer_lng) > 180) {
            $errors->add('delivery_zone', __('Invalid location coordinates detected. Please select your location again using the map.', 'routemile-for-woocommerce'));
            return;
        }

        // Restaurant coordinates — explicit setting, else geocoded address (cached).
        // The fee/zone check depends on coordinates only, never on customer-entered fields.
        $mapping_service = new ROUTEW_Mapping_Service();
        $restaurant = $mapping_service->get_restaurant_location($options);

        if (is_wp_error($restaurant)) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('validate_delivery_zone: ' . $restaurant->get_error_message(), array('source' => 'routemile-for-woocommerce'));
            }
            $errors->add('delivery_zone', __('Delivery service is not properly configured. Please contact support.', 'routemile-for-woocommerce'));
            return;
        }

        $customer_location = array('lat' => $customer_lat, 'lng' => $customer_lng);

        $distance_data = $mapping_service->get_distance(
            $restaurant,
            $customer_location
        );

        if (is_wp_error($distance_data)) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error('validate_delivery_zone distance error: ' . $distance_data->get_error_message(), array('source' => 'routemile-for-woocommerce'));
            }
            $errors->add('delivery_zone', $distance_data->get_error_message());
            return;
        }

        if (!isset($distance_data['distance']) || !is_object($distance_data['distance']) || !isset($distance_data['distance']->value)) {
            $errors->add('delivery_zone', __('Could not calculate delivery distance. Please try again.', 'routemile-for-woocommerce'));
            return;
        }

        $distance_in_km = $distance_data['distance']->value / 1000;

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug(sprintf('validate_delivery_zone: distance=%.3f km radius=%.3f', $distance_in_km, $radius), array('source' => 'routemile-for-woocommerce'));
        }

        if ($distance_in_km > $radius) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->info('validate_delivery_zone: out of zone', array('source' => 'routemile-for-woocommerce'));
            }
            $errors->add('delivery_zone', __('Sorry, we do not deliver to your location.', 'routemile-for-woocommerce'));
            return;
        }

        // Store the validated distance data for order processing
        WC()->session->set('routew_distance_data', $distance_data);

        // Log successful validation with delivery details
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug(sprintf(
                'validate_delivery_zone: success - distance=%.3f km, details=%s',
                $distance_in_km,
                $address_details
            ), array('source' => 'routemile-for-woocommerce'));
        }
    }

    /**
     * NOTE: `add_delivery_fee()` and `append_eta_to_label()` were extracted
     * to `ROUTEW_Delivery_Fee` in 1.2.0. They now self-register via the
     * `woocommerce_cart_calculate_fees` and
     * `woocommerce_cart_shipping_method_full_label` hooks when that class
     * is loaded.
     *
     * Address-completeness heuristic was extracted to `ROUTEW_Address_Validator`
     * in 1.2.0 as a static method. It was previously dead code (private,
     * never called) but is preserved as a public service for future use by
     * the multi-outlet editor (Phase 7) and any address-validating surface.
     */
}

new ROUTEW_Checkout_Handler();

// phpcs:enable
