<?php
/**
 * Address Validator Service
 *
 * Heuristic delivery-address completeness check used to give customers
 * actionable feedback when their address is too vague for the delivery
 * agent to find them. Pure (no instance state, no WordPress hooks) — call
 * as a static method.
 *
 * @since      1.2.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ROUTEW_Address_Validator
 *
 * Stateless service. Originally a private method on ROUTEW_Checkout; promoted
 * to a service in 1.2.0 to be reusable by the multi-outlet editor (Phase 7)
 * and any future address-validating surface.
 *
 * @since 1.2.0
 */
class ROUTEW_Address_Validator
{

    /**
     * Validate address completeness for delivery with detailed feedback.
     *
     * @param string $address The delivery address to validate.
     * @return array Array with 'is_complete' boolean and 'message' string.
     * @since 1.0.0
     */
    public static function validate_address_completeness($address)
    {
        if (empty($address)) {
            return array(
                'is_complete' => false,
                'message' => __('address field is empty', 'routemile-for-woocommerce')
            );
        }

        if (strlen($address) < 20) {
            return array(
                'is_complete' => false,
                'message' => __('address is too short - please provide more details', 'routemile-for-woocommerce')
            );
        }

        // Check for essential address components
        $address_lower = strtolower($address);
        $has_building_info = false;
        $has_location_info = false;
        $has_numbers = false;

        // Enhanced building/location indicators
        $building_keywords = array('flat', 'apartment', 'apt', 'floor', 'building', 'block', 'house', 'home', 'tower', 'complex', 'society', 'villa', 'bungalow', 'street', 'road', 'lane', 'avenue', 'plaza', 'square', 'mall', 'shop', 'office');
        $location_keywords = array('near', 'opposite', 'behind', 'next to', 'beside', 'landmark', 'gate', 'entrance', 'main', 'sector', 'area', 'locality', 'colony', 'nagar', 'city', 'town', 'metro', 'station', 'market', 'hospital', 'school');

        foreach ($building_keywords as $keyword) {
            if (strpos($address_lower, $keyword) !== false) {
                $has_building_info = true;
                break;
            }
        }

        foreach ($location_keywords as $keyword) {
            if (strpos($address_lower, $keyword) !== false) {
                $has_location_info = true;
                break;
            }
        }

        // Check for numbers (house/flat numbers, postal codes)
        $has_numbers = preg_match('/\d+/', $address);

        // Specific validation checks with helpful messages
        if (!$has_numbers) {
            return array(
                'is_complete' => false,
                'message' => __('please include building/house/flat number', 'routemile-for-woocommerce')
            );
        }

        if (!$has_building_info && !$has_location_info) {
            return array(
                'is_complete' => false,
                'message' => __('please add building name, street name, or nearby landmark', 'routemile-for-woocommerce')
            );
        }

        // Check for delivery instructions or additional details
        $has_delivery_hints = false;
        $delivery_keywords = array('floor', 'gate', 'entrance', 'lift', 'stairs', 'parking', 'security', 'guard', 'bell', 'intercom', 'buzzer', 'call', 'ring', 'door', 'left', 'right', 'behind', 'front');

        foreach ($delivery_keywords as $keyword) {
            if (strpos($address_lower, $keyword) !== false) {
                $has_delivery_hints = true;
                break;
            }
        }

        // All basic checks passed
        if (strlen($address) > 30 && $has_building_info && $has_numbers) {
            return array(
                'is_complete' => true,
                'message' => __('address looks complete', 'routemile-for-woocommerce')
            );
        }

        // Moderate completeness - warn but allow
        if ($has_building_info && $has_numbers) {
            return array(
                'is_complete' => true,
                'message' => __('address is acceptable but could use more detail', 'routemile-for-woocommerce')
            );
        }

        // Fallback - minimal requirements met
        return array(
            'is_complete' => ($has_building_info || $has_location_info) && $has_numbers,
            'message' => __('please add more specific delivery details like floor, gate number, or landmarks', 'routemile-for-woocommerce')
        );
    }
}
