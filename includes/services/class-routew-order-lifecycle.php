<?php
/**
 * Order lifecycle helpers — clear location PII meta on terminal events.
 *
 * Without this helper, location meta (`_routew_delivery_lat`,
 * `_routew_delivery_lng`, `_routew_delivery_distance`,
 * `_routew_delivery_address`, `_routew_address_details`,
 * `_routew_landmark`, `_routew_delivery_instructions`) is written at
 * checkout time and never removed — completed orders hold the customer's
 * GPS coordinates forever, which is hostile to GDPR/CCPA "storage
 * limitation" principles even though the privacy module exposes an
 * erase flow.
 *
 * Removal triggers:
 *
 *   - Rider unassigned (3 call sites see HOOKS below)
 *   - woocommerce_order_status_completed
 *   - woocommerce_order_status_cancelled
 *
 * Refunds and partial refunds deliberately keep the meta so the customer
 * or rider can dispute a delivery later.
 *
 * @since 1.5.0
 * @package RouteMile
 */

if (!defined('ABSPATH')) {
    exit;
}

class ROUTEW_Order_Lifecycle
{
    /**
     * Meta keys that store customer location data and should be purged on
     * terminal events. Keep in sync with the meta writes in
     * apply_delivery_data_to_order() and the REST controller.
     *
     * @return string[]
     */
    public static function location_meta_keys()
    {
        return array(
            '_routew_delivery_lat',
            '_routew_delivery_lng',
            '_routew_delivery_distance',
            '_routew_delivery_address',
            '_routew_address_details',
            '_routew_landmark',
            '_routew_delivery_instructions',
        );
    }

    /**
     * Remove location PII from an order. Tolerates missing orders,
     * HPOS/legacy both, and partial saves.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public static function clear_delivery_location_meta($order_id)
    {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        foreach (self::location_meta_keys() as $key) {
            $order->delete_meta_data($key);
        }
        $order->save();
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug(sprintf('RouteMile location meta cleared for order #%d', $order_id), array('source' => 'routemile-for-woocommerce'));
        }
    }

    /**
     * Hook terminal status transitions. Idempotent.
     *
     * @return void
     */
    public static function register_hooks()
    {
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'clear_delivery_location_meta'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'clear_delivery_location_meta'), 10, 1);
    }
}

ROUTEW_Order_Lifecycle::register_hooks();
