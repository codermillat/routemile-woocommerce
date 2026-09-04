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

    /**
     * Whether an order may be advanced to `completed` via the plugin's
     * manual-transition endpoints (manager dashboard, rider PWA).
     *
     * Refusing to set `completed` on unpaid orders prevents a manager
     * or delivery agent from forging a "paid" state through RouteMile's
     * own endpoints. WooCommerce's own `payment_complete()` (driven by a
     * real payment gateway webhook) is NOT blocked by this check — it
     * routes through `maybe_set_date_paid()` + `set_status('completed')`
     * and is the legitimate path. (AUDIT-FIX 1.6.1 — CSRF audits #3, #4.)
     *
     * @param WC_Order $order The order being transitioned.
     * @return true|WP_Error True if the transition is allowed; WP_Error
     *                      with a localized admin-facing message if the
     *                      order still needs payment.
     * @since 1.6.1
     */
    public static function can_complete_order($order)
    {
        if (!$order instanceof WC_Order) {
            return new WP_Error('routew_invalid_order', __('Order could not be loaded.', 'routemile-for-woocommerce'));
        }

        // Order has zero total (e.g. fully refunded, manual zero-out) → no payment was ever owed.
        if ((float) $order->get_total() <= 0) {
            return true;
        }

        // Order has already been paid (gateway webhook fired payment_complete).
        if ($order->is_paid() || $order->get_date_paid('edit')) {
            return true;
        }

        // Special-case: COD with cash physically collected by the rider.
        // The rider's Confirm-Cash action already set date_paid in
        // ajax_confirm_cash_collected (1.6.0), so is_paid() returns true
        // above — this branch is defensive; kept explicit for clarity.
        if ('cod' === $order->get_payment_method()) {
            $cash = (int) $order->get_meta('_routew_cash_collected', true);
            if ($cash > 0) {
                return true;
            }
        }

        return new WP_Error(
            'routew_unpaid',
            sprintf(
                /* translators: 1: order id. */
                __('Order #%1$s has not been paid. Mark it completed only after payment (gateway webhook) is received; otherwise the store records a "paid" status without real money.', 'routemile-for-woocommerce'),
                $order->get_id()
            ),
            array('status' => 403)
        );
    }
}

ROUTEW_Order_Lifecycle::register_hooks();
