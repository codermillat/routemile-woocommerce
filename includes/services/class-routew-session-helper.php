<?php
/**
 * WC session helpers — defensive cleanup of customer PII session data.
 *
 * Without this helper, `customer_lat`, `customer_lng`, and
 * `routew_distance_data` written to `WC()->session` by the checkout /
 * shipping / REST flows persist across customers on the same browser
 * (shared kiosk, family device, account switch mid-flow). The next
 * customer's checkout loads the previous customer's coordinates and may
 * silently validate against them.
 *
 * The helper is hooked to lifecycle events that mark "a customer has
 * finished with this session":
 *
 *  - woocommerce_checkout_order_created (order placed)
 *  - woocommerce_thankyou                (completed browser-side)
 *  - woocommerce_order_status_cancelled  (terminal status)
 *  - wp_logout                           (account switch)
 *
 * It only clears keys the plugin itself owns; it does not touch any
 * other session data.
 *
 * @since 1.5.0
 * @package RouteMile
 */

if (!defined('ABSPATH')) {
    exit;
}

class ROUTEW_Session_Helper
{
    /**
     * Session keys owned by RouteMile. Centralised so cleanup and the
     * audit review stay in sync.
     *
     * @return string[]
     */
    public static function owned_keys()
    {
        return array(
            'customer_lat',
            'customer_lng',
            'routew_distance_data',
        );
    }

    /**
     * Remove all RouteMile-owned session keys.
     *
     * Safe to call when `WC()->session` is unavailable (CLI, REST
     * pre-init, etc.) — we just no-op.
     *
     * @return void
     */
    public static function clear_customer_session_data()
    {
        if (!class_exists('WC_Session') || null === WC()->session()) {
            return;
        }
        // WC_Session exposes an array-like interface, but each subclass
        // (WC_Session_Handler / WC_Session_Tracker) handles direct
        // property access differently. set() with null drops the key.
        $session = WC()->session();
        foreach (self::owned_keys() as $key) {
            $session->set($key, null);
        }
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->debug('RouteMile session data cleared on lifecycle event.', array('source' => 'routemile-for-woocommerce'));
        }
    }

    /**
     * Register lifecycle hooks. Idempotent — safe to call from multiple
     * loaders.
     *
     * @return void
     */
    public static function register_hooks()
    {
        add_action('woocommerce_checkout_order_created', array(__CLASS__, 'clear_customer_session_data'), 10, 0);
        add_action('woocommerce_thankyou', array(__CLASS__, 'clear_customer_session_data'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'clear_customer_session_data'), 10, 0);
        add_action('wp_logout', array(__CLASS__, 'clear_customer_session_data'));
    }
}

ROUTEW_Session_Helper::register_hooks();
