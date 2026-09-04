<?php
/**
 * Plugin Name:       RouteMile for WooCommerce
 * Plugin URI:        https://github.com/codermillat/routemile-woocommerce
 * Description:       Map-based delivery management for single-restaurant WooCommerce stores.
 * Version:           1.6.2
 * Author:            MD MILLAT HOSEN
 * Author URI:        https://millat.is-a.dev/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       routemile-for-woocommerce
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   11.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    die;
}

if (!defined('ROUTEW_VERSION')) {
    define('ROUTEW_VERSION', '1.6.2');
}
if (!defined('ROUTEW_PLUGIN_DIR')) {
    define('ROUTEW_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('ROUTEW_PLUGIN_URL')) {
    define('ROUTEW_PLUGIN_URL', plugin_dir_url(__FILE__));
}

/**
 * Display notice when WooCommerce is not active
 */
if (!function_exists('routew_woocommerce_not_active_notice')) {
    function routew_woocommerce_not_active_notice()
    {
        ?>
        <div class="error">
            <p><?php esc_html_e('RouteMile for WooCommerce requires WooCommerce to be installed and active.', 'routemile-for-woocommerce'); ?></p>
        </div>
        <?php
    }
}

/**
 * Declare HPOS compatibility early (before_woocommerce_init fires before plugins_loaded).
 *
 * @since 1.1.0
 */
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        // The blocks integration registers its delivery fields through the
        // Additional Checkout Fields API, which needs WooCommerce 8.9+. On
        // older WC versions the fields cannot register, persist, or validate,
        // so declaring blocks compatibility there would be false advertising
        // (1.2.15).
        $routew_blocks_compat = defined('WC_VERSION') && version_compare(WC_VERSION, '8.9', '>=');
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, $routew_blocks_compat);
    }
});

/**
 * One-time migration from FoodXpress keys to RouteMile keys (v1.4.0 → v1.5.0).
 *
 * Idempotent — gated by the `routew_migrated_from_fxw` option. Stores, settings,
 * transients, post meta, user meta, and order statuses all use new prefixed
 * keys; without this migration the upgrade would silently drop rider
 * assignments and saved store hours.
 *
 * Runs at `plugins_loaded` priority 1 (before `routew_init_plugin`) so the rest
 * of the plugin reads only new keys.
 *
 * @since 1.5.0
 */
add_action('plugins_loaded', 'routew_migrate_legacy_fxw_data', 1);

if (!function_exists('routew_migrate_legacy_fxw_data')) {
    function routew_migrate_legacy_fxw_data()
    {
        if (get_option('routew_migrated_from_fxw')) {
            return;
        }

        global $wpdb;

        // LEGACY = pre-1.5.0 keys (the database key as it actually exists
        // from v1.4.x installs). We use literal strings here so they are NOT
        // touched by the codename rename and survive the global sed pass.
        $LEGACY_OPTION         = 'fxw_settings';
        $LEGACY_MIGRATION_FLAG = 'fxw_migrated_address2_v139';

        // 1. Options.
        $opt_map = array(
            'routew_settings'               => $LEGACY_OPTION,
            'routew_migrated_address2_v139' => $LEGACY_MIGRATION_FLAG,
        );
        foreach ($opt_map as $new_key => $old_key) {
            if (false === get_option($new_key) && false !== ($val = get_option($old_key))) {
                update_option($new_key, $val);
                // Keep legacy as a safety net for one cycle; remove it so a
                // re-install test doesn't carry forward stale state.
                delete_option($old_key);
            }
        }

        // 2. Transients.
        $transient_map = array(
            'routew_admin_notice'         => 'fxw_admin_notice',
            'routew_zone_sync_done'       => 'fxw_zone_sync_done',
            'routew_admin_cap_checked'    => 'fxw_admin_cap_checked',
            'routew_delivery_cap_checked' => 'fxw_delivery_cap_checked',
        );
        foreach ($transient_map as $new_key => $old_key) {
            $val = get_transient($old_key);
            if (false !== $val) {
                // Re-issue with a conservative 5-minute TTL — old transients
                // may have many hours left; the new code re-issues them on
                // each subsequent visit anyway.
                set_transient($new_key, $val, 5 * MINUTE_IN_SECONDS);
                delete_transient($old_key);
            }
        }

        // 3. Post + user meta keys.
        $meta_map = array(
            '_routew_delivery_boy_id'      => '_fxw_delivery_boy_id',
            '_routew_delivery_address'     => '_fxw_delivery_address',
            '_routew_delivery_lat'         => '_fxw_delivery_lat',
            '_routew_delivery_lng'         => '_fxw_delivery_lng',
            '_routew_delivery_distance'    => '_fxw_delivery_distance',
            '_routew_delivery_instructions'=> '_fxw_delivery_instructions',
            '_routew_address_details'      => '_fxw_address_details',
            '_routew_landmark'             => '_fxw_landmark',
            '_routew_delivery_profile'     => '_fxw_delivery_profile',
            // Split-address legacy fields — privacy module declares these as
            // personal data (class-routew-privacy.php) but the migration was
            // missing entries. Without these, FXW→RouteMile upgrades lose
            // the rows silently and the privacy export/erase misses them.
            // (AUDIT-FIXES H5)
            '_routew_house_flat_no'        => '_fxw_house_flat_no',
            '_routew_floor_no'             => '_fxw_floor_no',
            '_routew_society_building'     => '_fxw_society_building',
            '_routew_block_tower_area'     => '_fxw_block_tower_area',
        );
        foreach ($meta_map as $new_key => $old_key) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // One-time FXW→RouteMile meta-key migration; runs once per install
            // at plugins_loaded priority 1 and never on the request hot path.
            $count = (int) $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
                    $new_key,
                    $old_key
                )
            );
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->usermeta} SET meta_key = %s WHERE meta_key = %s",
                    $new_key,
                    $old_key
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ($count > 0 && defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('routew migration: rewrote %d post meta rows from %s to %s', $count, $old_key, $new_key));
            }
        }

        // 4. Custom order statuses — `wc-fxw-*` -> `wc-routew-*`. Stored in
        // `posts.post_status` and (HPOS) `wc_orders.status`/`wc_order_stats`.
        $status_map = array(
            'wc-routew-in-kitchen' => 'wc-fxw-in-kitchen',
            'wc-routew-assigned'   => 'wc-fxw-assigned',
            'wc-routew-picked-up'  => 'wc-fxw-picked-up',
        );
        foreach ($status_map as $new_status => $old_status) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->posts} SET post_status = %s WHERE post_status = %s",
                    $new_status,
                    $old_status
                )
            );
            // Trash meta follow-up (for orders trashed under the old status).
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_wp_trash_meta_status' AND meta_value = %s",
                    $new_status,
                    $old_status
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // Debug-only row count; not on the request hot path.
                $count = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = %s",
                        $new_status
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ($count > 0) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log(sprintf('routew migration: %d legacy orders are now on status %s', $count, $new_status));
                }
            }
        }

        update_option('routew_migrated_from_fxw', array(
            'at'      => time(),
            'version' => ROUTEW_VERSION,
        ));
    }
}

add_action('plugins_loaded', 'routew_init_plugin');

if (!function_exists('routew_init_plugin')) {
    function routew_init_plugin()
    {
        // Runtime PHP version check.
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>';
                esc_html_e('RouteMile for WooCommerce requires PHP 7.4 or higher.', 'routemile-for-woocommerce');
                echo '</p></div>';
            });
            return;
        }

        // Runtime WordPress version check.
        global $wp_version;
        if (version_compare($wp_version, '6.0', '<')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>';
                esc_html_e('RouteMile for WooCommerce requires WordPress 6.0 or higher.', 'routemile-for-woocommerce');
                echo '</p></div>';
            });
            return;
        }

        // Check if WooCommerce is active.
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', 'routew_woocommerce_not_active_notice');
            return;
        }

        // Check WooCommerce version.
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '7.0', '<')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>';
                esc_html_e('RouteMile for WooCommerce requires WooCommerce 7.0 or higher.', 'routemile-for-woocommerce');
                echo '</p></div>';
            });
            return;
        }

        // Translations are loaded automatically by WordPress.org for plugins
        // hosted there; no manual load_plugin_textdomain() call needed (since WP 4.6).

        require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-core.php';

        $plugin = new ROUTEW_Core();
    }
}

/**
 * Flush rewrite rules on activation.
 *
 * @since    1.0.0
 */
if (!function_exists('routew_activate')) {
    function routew_activate()
    {
        // Register rewrite rules first, then flush so they're persisted.
        if (class_exists('ROUTEW_Core')) {
            $core = new ROUTEW_Core();
            $core->add_rewrite_rules();
        } else {
            add_rewrite_rule('^delivery-dashboard/?$', 'index.php?is_delivery_dashboard=true', 'top');
        }
        flush_rewrite_rules();

        routew_ensure_shipping_method_registered();
    }
}

/**
 * Make sure RouteMile Delivery is enabled on every shipping zone that
 * exists today. Idempotent — re-running never creates a duplicate.
 *
 * The shipping method itself only adds a rate when WC is matching a
 * package to a zone that has the method enabled — without a zone that
 * has the method enabled, even a working shipping method renders as
 * "No available delivery option" in the Order summary panel. This used
 * to be a manual configuration step most stores missed, surfacing as
 * a stale rate from a previous session. (v1.3.5)
 *
 * @return bool True when the method is enabled on at least one zone
 *              after the call.
 */
if (!function_exists('routew_ensure_shipping_method_registered')) {
    function routew_ensure_shipping_method_registered()
    {
        if (!class_exists('WC_Shipping_Zones') || !class_exists('WC_Shipping_Zone')) {
            return false;
        }

        $touched_any = false;

        // Every named shipping zone.
        $zones = WC_Shipping_Zones::get_zones();
        foreach ($zones as $zone_data) {
            if (!isset($zone_data['id'])) continue;
            $zone = new WC_Shipping_Zone($zone_data['id']);
            $methods = $zone->get_shipping_methods();
            $has_fxw = false;
            foreach ($methods as $method) {
                if (isset($method->id) && 'routemile_delivery' === $method->id) {
                    $has_fxw = true;
                    break;
                }
            }
            if (!$has_fxw) {
                try {
                    $zone->add_shipping_method('routemile_delivery');
                    $touched_any = true;
                } catch (\Throwable $e) {
                    // Zone data is transient; admin warning surfaces
                    // this. Log for diagnosis via WC's logger so the
                    // admin can view/filter via WC → Status → Logs.
                    if (function_exists('wc_get_logger')) {
                        wc_get_logger()->error(
                            sprintf('[RouteMile] %s: %s', __METHOD__, $e->getMessage()),
                            array('source' => 'routew')
                        );
                    }
                }
            } else {
                $touched_any = true;
            }
        }

        // Zone 0 — synthetic "Rest of the world" — not returned by
        // get_zones(). Handle it explicitly so stores shipping
        // everywhere have a fallback rate.
        $rest = new WC_Shipping_Zone(0);
        $methods = $rest->get_shipping_methods();
        $has_fxw = false;
        foreach ($methods as $method) {
            if (isset($method->id) && 'routemile_delivery' === $method->id) {
                $has_fxw = true;
                break;
            }
        }
        if (!$has_fxw) {
            try {
                $rest->add_shipping_method('routemile_delivery');
                $touched_any = true;
            } catch (\Throwable $e) {
                // Same recovery path as above. Log for diagnosis via
                // WC's logger so the admin can view/filter via
                // WC → Status → Logs.
                if (function_exists('wc_get_logger')) {
                    wc_get_logger()->error(
                        sprintf('[RouteMile] %s: %s', __METHOD__, $e->getMessage()),
                        array('source' => 'routew')
                    );
                }
            }
        } else {
            $touched_any = true;
        }

        return $touched_any;
    }
}

/**
 * Run the zone auto-registration lazily — fires on the next request
 * after the activation, whether the request comes from the WP admin
 * (`admin_init`) or from the frontend (`init`, including cart /
 * checkout / Store API requests). Gated by a 6-hour transient so the
 * actual work happens at most once per that window. Excluded: AJAX
 * requests, REST requests, WP-CRON — none of those need the sync, and
 * registering zones inside a Store API request would slow checkout
 * shipping calculation. WC_Shipping_Zone::add_shipping_method is
 * idempotent so re-running is safe even before the transient flips.
 *
 * @since 1.3.5
 * @since 1.3.6 also fires on frontend `init` because stores that hit
 *                checkout BEFORE any admin visit were never seeing the
 *                sync run (admin_init never fired for them).
 */
if (!function_exists('routew_maybe_sync_shipping_zones')) {
    function routew_maybe_sync_shipping_zones()
    {
        // Self-healing: even when the transient says "already synced", if
        // zone 0 (Rest of the world) lacks RouteMile Delivery the customer
        // sees "No shipping options are available for this address" the
        // moment their address doesn't match a specific zone. The transient
        // is stale at that point — clear it and re-sync. (REGRESSION-FIX R3)
        if (get_transient('routew_zone_sync_done') && class_exists('WC_Shipping_Zone') && !defined('DOING_AJAX') && !defined('DOING_CRON') && (!function_exists('wp_doing_rest') || !wp_doing_rest())) {
            $rest_zone = new WC_Shipping_Zone(0);
            $has_routew_on_rest = false;
            foreach ($rest_zone->get_shipping_methods() as $m) {
                if (isset($m->id) && 'routemile_delivery' === $m->id) {
                    $has_routew_on_rest = true;
                    break;
                }
            }
            if (!$has_routew_on_rest) {
                delete_transient('routew_zone_sync_done');
            } else {
                return;
            }
        } elseif (get_transient('routew_zone_sync_done')) {
            return;
        }
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if (function_exists('wp_doing_rest') && wp_doing_rest()) {
            return;
        }
        if (!class_exists('WC_Shipping_Zone')) {
            return;
        }
        routew_ensure_shipping_method_registered();
        set_transient('routew_zone_sync_done', 1, 6 * HOUR_IN_SECONDS);
    }
}
add_action('admin_init', 'routew_maybe_sync_shipping_zones');
add_action('init', 'routew_maybe_sync_shipping_zones', 5); // before ROUTEW_Checkout / Shipping add their hooks
// Reactive sync: when the admin adds/updates a zone via the WC UI, the
// transient is stale (it was set the last time the function ran, possibly
// before this zone existed). Bust it so the next request re-syncs and the
// new zone gets RouteMile Delivery added automatically. (REGRESSION-FIX R3)
add_action('woocommerce_shipping_zone_added', 'routew_invalidate_zone_sync');
add_action('woocommerce_shipping_zone_updated', 'routew_invalidate_zone_sync');

if (!function_exists('routew_invalidate_zone_sync')) {
    function routew_invalidate_zone_sync()
    {
        delete_transient('routew_zone_sync_done');
    }
}

/**
 * Clean up rewrite rules on deactivation.
 *
 * @since    1.0.0
 */
if (!function_exists('routew_deactivate')) {
    function routew_deactivate()
    {
        flush_rewrite_rules();
    }
}

register_activation_hook(__FILE__, 'routew_activate');
register_deactivation_hook(__FILE__, 'routew_deactivate');
