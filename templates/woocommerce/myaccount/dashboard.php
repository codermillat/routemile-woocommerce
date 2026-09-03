<?php
/**
 * My Account > Dashboard — RouteMile override of WC core's
 * templates/myaccount/dashboard.php (v4.4.0).
 *
 * The core template prints a "Hello <name> (not <name>? Log out)"
 * paragraph and a "From your account dashboard you can view…" paragraph
 * before the `woocommerce_account_dashboard` action fires. The RouteMile
 * greeting hero widget (rendered by the action) carries the user
 * identity, time-of-day greeting, avatar, reorder banner, quick actions,
 * and recent orders — so the core paragraphs are redundant noise. This
 * override drops them at the source: they never reach the browser, no
 * CSS suppression needed for the default case.
 *
 * WC's deprecated actions are preserved so older themes/plugins still
 * hooking `woocommerce_before_my_account` / `woocommerce_after_my_account`
 * keep working (defense kept from the core template).
 *
 * Loaded via ROUTEW_Shortcodes::override_wc_templates() on the
 * `woocommerce_locate_template` filter. NOTE: a theme override at
 * yourtheme/woocommerce/myaccount/dashboard.php still wins over this
 * (WC's template stack: theme → plugin → core) — the CSS-layer
 * suppression in my-account.css covers that case.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package RouteMile
 * @version 1.6.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * My Account dashboard — RouteMile's widgets hook this.
 *
 * @since 2.6.0
 */
do_action( 'woocommerce_account_dashboard' );

/**
 * Deprecated woocommerce_before_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_before_my_account' );

/**
 * Deprecated woocommerce_after_my_account action.
 *
 * @deprecated 2.6.0
 */
do_action( 'woocommerce_after_my_account' );
