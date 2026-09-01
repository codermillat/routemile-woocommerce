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

// Opt-in data removal (1.2.16). When the admin has NOT checked
// routew_remove_on_uninstall, leave everything in place: settings, role, and
// saved delivery profiles. Order meta is NEVER removed either way (see the
// commented block below). Flush rewrite rules unconditionally.
$options = get_option('routew_settings', array());
$wipe_data = is_array($options) && isset($options['routew_remove_on_uninstall']) && 'yes' === $options['routew_remove_on_uninstall'];

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
