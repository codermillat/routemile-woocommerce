<?php
/**
 * Delivery Fee Service — shipping label only.
 *
 * The delivery charge itself belongs to ROUTEW_Shipping_Method, which adds
 * it through the documented Shipping Method API (`calculate_shipping()`
 * + `add_rate()`). That is WooCommerce's single supported place for a
 * per-order delivery cost, and it makes WooCommerce tax the charge with
 * shipping tax rules rather than fee rules.
 *
 * Until 1.3.0 this class ALSO added the same charge as a cart fee on
 * `woocommerce_cart_calculate_fees`. A guard was supposed to skip the
 * fee whenever a RouteMile shipping rate was selected, but on any store
 * without a RouteMile shipping zone another method wins (e.g. core Free
 * shipping) and the guard never fires — so the customer was charged the
 * delivery cost twice, once as shipping and once as a fee, with the two
 * figures often disagreeing because they were computed at different
 * moments. The cart-fee path is therefore removed entirely; this class
 * now only appends the ETA to our own shipping label.
 *
 * @since      1.2.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class ROUTEW_Delivery_Fee
 *
 * Backend concerns:
 *  - Filter: woocommerce_cart_shipping_method_full_label — append ETA minutes
 *
 * Self-registers at the bottom of the file; no external wiring required.
 *
 * @since 1.2.0
 */
class ROUTEW_Delivery_Fee
{

    /**
     * Register the label hook.
     *
     * @since 1.2.0
     */
    public function __construct()
    {
        add_filter('woocommerce_cart_shipping_method_full_label', array($this, 'append_eta_to_label'), 10, 2);
    }

    /**
     * Append ETA minutes to our shipping method label.
     *
     * @param string           $label
     * @param WC_Shipping_Rate $rate
     * @return string
     */
    public function append_eta_to_label($label, $rate)
    {
        // Identify our shipping method
        $method_id = '';
        if (is_object($rate)) {
            if (isset($rate->method_id)) {
                $method_id = $rate->method_id;
            } elseif (method_exists($rate, 'get_method_id')) {
                $method_id = $rate->get_method_id();
            } elseif (isset($rate->id) && is_string($rate->id)) {
                $method_id = strpos($rate->id, ':') !== false ? substr($rate->id, 0, strpos($rate->id, ':')) : $rate->id;
            }
        }
        if ($method_id !== 'routemile_delivery') {
            return $label;
        }

        $distance_data = WC()->session ? WC()->session->get('routew_distance_data') : null;
        if (!$distance_data || !isset($distance_data['duration']) || !is_object($distance_data['duration']) || !isset($distance_data['duration']->value)) {
            return $label;
        }

        $options = get_option('routew_settings');
        $prep_time = isset($options['routew_preparation_time']) ? (int) $options['routew_preparation_time'] : ROUTEW_Config::DEFAULT_PREP_TIME;
        $seconds = (int) $distance_data['duration']->value + ($prep_time * 60);
        $mins = max(1, (int) round($seconds / 60));

        // When the provider has no road routing the travel time was derived
        // from a straight-line estimate, so the label must not read as a
        // driven-route promise (1.3.0).
        $text = !empty($distance_data['estimated'])
            ? sprintf(
                /* translators: %d: estimated travel time in minutes. */
                __('ETA ~ %d mins (estimated)', 'routemile-for-woocommerce'),
                $mins
            )
            : sprintf(
                /* translators: %d: estimated travel time in minutes. */
                __('ETA ~ %d mins', 'routemile-for-woocommerce'),
                $mins
            );

        $eta_html = sprintf(' <small class="routew-eta-label">%s</small>', esc_html($text));
        return $label . $eta_html;
    }
}

new ROUTEW_Delivery_Fee();
