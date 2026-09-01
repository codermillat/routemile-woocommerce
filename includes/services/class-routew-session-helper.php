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
     * Resolve the active WC session across the WC 3.6+ split between
     * method-style (`WC()->session()`) and the older direct property
     * (`WC()->session`). Returns null on any failure (uninitialised
     * session handler, subclass without override, fatal during Blocks
     * order-confirmation render, etc.) so the caller can no-op safely.
     *
     * @return \WC_Session|null
     */
    private static function safe_get_session()
    {
        if (!class_exists('WC_Session')) {
            return null;
        }
        if (!function_exists('WC')) {
            return null;
        }
        try {
            $wc = WC();
        } catch (\Throwable $e) {
            return null;
        }
        if (!$wc) {
            return null;
        }
        // Modern WC (3.6+).
        if (method_exists($wc, 'session')) {
            try {
                $session = $wc->session();
                return $session ? $session : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        // Legacy direct property access (WC < 3.6 fallback).
        if (isset($wc->session) && is_object($wc->session)) {
            return $wc->session;
        }
        return null;
    }

    /**
     * Remove all RouteMile-owned session keys.
     *
     * Safe to call when `WC()->session` is unavailable (CLI, REST
     * pre-init, Blocks order-confirmation render before the session
     * handler is bootstrapped, etc.) — we just no-op.
     *
     * @return void
     */
    public static function clear_customer_session_data()
    {
        $session = self::safe_get_session();
        if (!$session) {
            return;
        }
        // WC_Session exposes an array-like interface, but each subclass
        // (WC_Session_Handler / WC_Session_Tracker) handles direct
        // property access differently. set() with null drops the key.
        // Wrap individual set() calls — a throwing subclass should not
        // block cleanup of the remaining keys.
        foreach (self::owned_keys() as $key) {
            try {
                $session->set($key, null);
            } catch (\Throwable $e) {
                // Skip this key — defensive against subclass quirks.
            }
        }
        if (function_exists('wc_get_logger')) {
            try {
                wc_get_logger()->debug('RouteMile session data cleared on lifecycle event.', array('source' => 'routemile-for-woocommerce'));
            } catch (\Throwable $e) {
                // Logger failures must never break checkout render.
            }
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
