<?php
/**
 * RouteMile Uninstall
 *
 * Fired when the plugin is deleted.
 * Cleans up options, user meta, and custom data.
 *
 * @package RouteMile
 * @since   1.1.0
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Perform the actual uninstall work. Wrapped in a function so every variable
 * is function-scoped (silences PHPCS NonPrefixedVariableFound warnings that
 * fire for variables at file scope).
 *
 * Opt-in data removal (1.2.16): when the admin has NOT checked
 * routew_remove_on_uninstall, leave settings, role, and saved delivery
 * profiles in place. Order meta is NEVER removed either way (see the
 * commented block below). Flush rewrite rules unconditionally.
 */
function routew_uninstall()
{
    // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
    // Uninstall-time meta_key lookup to find users with saved delivery
    // profiles. Runs once at deletion time, never on the hot path.
    $routew_options = get_option('routew_settings', array());
    $wipe_data = is_array($routew_options) && isset($routew_options['routew_remove_on_uninstall']) && 'yes' === $routew_options['routew_remove_on_uninstall'];

    if ($wipe_data) {
        delete_option('routew_settings');
        remove_role('delivery_boy');
        $users = get_users(array('meta_key' => '_routew_delivery_profile'));
        foreach ($users as $user) {
            delete_user_meta($user->ID, '_routew_delivery_profile');
        }
    }

    // Clean up order meta (optional: only if full cleanup is desired).
    // Order meta keys: _routew_delivery_boy_id, _routew_delivery_boy_name, _routew_delivery_status
    // Note: Uncomment below for complete cleanup. Left commented to preserve order history by default.
    // IMPORTANT: use HPOS-aware order CRUD (never $wpdb->postmeta — order meta may
    // live in custom order tables under HPOS):
    // $orders = wc_get_orders(array('limit' => -1, 'status' => 'all'));
    // foreach ($orders as $order) {
    //     $order->delete_meta_data('_routew_delivery_boy_id');
    //     $order->delete_meta_data('_routew_delivery_boy_name');
    //     $order->delete_meta_data('_routew_delivery_status');
    //     $order->save();
    // }

    flush_rewrite_rules();
    // phpcs:enable
}

routew_uninstall();
