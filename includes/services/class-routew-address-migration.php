<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resumable migration for the pre-1.3.9 duplicated address line 2.
 *
 * Processes one bounded batch per admin request. Updating shipping address 2
 * does not change the query's meta_key membership, so deterministic ID order
 * and the persisted offset remain stable between requests. Existing settings
 * and the original routew_migrated_address2_v139 completion flag are unchanged.
 */
class ROUTEW_Address_Migration
{
    const BATCH_SIZE = 100;

    /**
     * Process at most one batch per admin request.
     *
     * @return void
     */
    public static function run()
    {
        if (get_option('routew_migrated_address2_v139')) {
            return;
        }
        if (wp_doing_ajax()) {
            return;
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }
        if (!function_exists('wc_get_orders') || !function_exists('wc_get_order')) {
            return;
        }

        $offset = absint(get_option('routew_address2_migration_offset', 0));
        $page = (int) floor($offset / self::BATCH_SIZE) + 1;
        $order_ids = wc_get_orders(array(
            'limit' => self::BATCH_SIZE,
            'paged' => $page,
            'status' => 'any',
            'meta_key' => '_routew_delivery_address',
            'orderby' => 'ID',
            'order' => 'ASC',
            'return' => 'ids',
        ));

        if (!is_array($order_ids)) {
            return;
        }

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                continue;
            }
            $details = trim((string) $order->get_meta('_routew_address_details', true));
            $landmark = trim((string) $order->get_meta('_routew_landmark', true));
            $current_line2 = (string) $order->get_shipping_address_2();

            // Only normalize orders produced by the pre-1.3.9 checkout. If a
            // request dies mid-batch, the unchanged offset retries this batch
            // only; the normalization is idempotent and bounded to 100 IDs.
            if ('' === $details || '' === $current_line2) {
                continue;
            }
            $is_old_shape = ($details === $current_line2)
                || (0 === strpos($current_line2, $details . ' ('));
            if (!$is_old_shape) {
                continue;
            }

            $order->set_shipping_address_2(
                '' !== $landmark ? sprintf('%s: %s', __('Landmark', 'routemile-for-woocommerce'), $landmark) : ''
            );
            $order->save();
        }

        $batch_count = count($order_ids);
        if ($batch_count < self::BATCH_SIZE) {
            update_option('routew_migrated_address2_v139', 1);
            delete_option('routew_address2_migration_offset');
            return;
        }

        // Persist progress only after the entire batch succeeds. A fatal in a
        // later batch therefore resumes from that batch, not from order zero.
        update_option('routew_address2_migration_offset', $offset + $batch_count, false);
    }
}
