<?php
/**
 * Deliveries dashboard — write handlers.
 *
 * Owns every state-changing action for the admin deliveries dashboard:
 * the form-POST handlers (progressive enhancement off) and their AJAX
 * twins (modern experience). Split out of ROUTEW_Dashboard in 1.2.9 to keep
 * every class under the 500-LOC cap; logic is unchanged.
 *
 * @since      1.2.9
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
	exit;
}

class ROUTEW_Dashboard_Actions
{

	/**
	 * Register the form-POST and AJAX handlers.
	 *
	 * @since 1.2.9
	 */
	public function __construct()
	{
		add_action('admin_post_routew_assign_delivery', array($this, 'assign_delivery'));
		add_action('admin_post_routew_update_order_status', array($this, 'update_order_status'));
		add_action('wp_ajax_routew_ajax_assign_delivery', array($this, 'ajax_assign_delivery'));
		add_action('wp_ajax_routew_ajax_update_status', array($this, 'ajax_update_status'));
	}

	/**
	 * Handle delivery assignment from dashboard (form POST).
	 *
	 * @since 1.0.0
	 */
	public function assign_delivery()
	{
		if (!current_user_can('edit_shop_orders')) {
			wp_die(esc_html__('Unauthorized.', 'routemile-for-woocommerce'));
		}

		$nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
		if (!wp_verify_nonce($nonce, 'routew_assign_delivery')) {
			wp_die(esc_html__('Invalid nonce.', 'routemile-for-woocommerce'));
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$delivery_boy_id = isset($_POST['delivery_boy_id']) ? absint($_POST['delivery_boy_id']) : 0;

		$order = wc_get_order($order_id);
		if (!$order) {
			wp_die(esc_html__('Invalid order.', 'routemile-for-woocommerce'));
		}

		// Log before assignment
		if (function_exists('wc_get_logger')) {
			wc_get_logger()->debug(sprintf('assign_delivery: Order #%d, delivery_boy_id=%d, current_status=%s', $order_id, $delivery_boy_id, $order->get_status()), array('source' => 'routemile-for-woocommerce'));
		}

		if ($delivery_boy_id) {
			$delivery_boy = get_user_by('id', $delivery_boy_id);
			$delivery_boy_name = $delivery_boy ? $delivery_boy->display_name : "ID: {$delivery_boy_id}";

			$order->update_meta_data('_routew_delivery_boy_id', $delivery_boy_id);
			/* translators: %s: delivery agent display name. */
			$order->update_status('routew-assigned', sprintf(__('Order assigned to delivery boy: %s', 'routemile-for-woocommerce'), $delivery_boy_name));
			$order->save();

			if (function_exists('wc_get_logger')) {
				wc_get_logger()->debug(sprintf('assign_delivery: Order #%d saved, new_status=%s, delivery_boy_id=%d', $order_id, $order->get_status(), $delivery_boy_id), array('source' => 'routemile-for-woocommerce'));
			}

			/* translators: 1: order number, 2: delivery agent display name. */
			set_transient('routew_admin_notice', sprintf(__('Order #%1$s successfully assigned to %2$s', 'routemile-for-woocommerce'), $order->get_order_number(), $delivery_boy_name), 30);
		} else {
			// Unassign: drop the rider meta AND revert the order to a
			// pre-assignment status so it stops showing on the assigned
			// rider's dashboard and doesn't get stuck in routew-assigned with
			// no rider (1.2.16). Choose between in-kitchen (the prior FXW
			// step) and processing (the pre-FXW WC default) based on which
			// custom status is registered; fall back to processing for
			// safety.
			$order->delete_meta_data('_routew_delivery_boy_id');
			$revert_to = get_post_status_object('wc-routew-in-kitchen') ? 'routew-in-kitchen' : 'processing';
			$order->update_status($revert_to, __('Delivery boy unassigned — order returned to kitchen.', 'routemile-for-woocommerce'));
			$order->save();
			// Wipe the now-stale location PII so a future re-assignment
			// starts clean and so we don't hold the previous rider's PII
			// against a customer that may go to a different rider next.
			// (AUDIT-FIXES M2)
			ROUTEW_Order_Lifecycle::clear_delivery_location_meta($order_id);

			/* translators: %s: order number. */
			set_transient('routew_admin_notice', sprintf(__('Order #%s unassigned from delivery boy', 'routemile-for-woocommerce'), $order->get_order_number()), 30);
		}

		// Clear WooCommerce caches for this order
		if (function_exists('wc_delete_shop_order_transients')) {
			wc_delete_shop_order_transients($order_id);
		}

		$referer = wp_get_referer();
		wp_safe_redirect($referer ? $referer : admin_url());
		exit;
	}

	/**
	 * Handle order status updates from dashboard (form POST).
	 *
	 * @since 1.0.0
	 */
	public function update_order_status()
	{
		if (!current_user_can('edit_shop_orders')) {
			wp_die(esc_html__('Unauthorized.', 'routemile-for-woocommerce'));
		}

		$nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
		if (!wp_verify_nonce($nonce, 'routew_update_status')) {
			wp_die(esc_html__('Invalid nonce.', 'routemile-for-woocommerce'));
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$new_status = isset($_POST['new_status']) ? sanitize_text_field(wp_unslash($_POST['new_status'])) : '';

		$order = wc_get_order($order_id);
		if (!$order) {
			wp_die(esc_html__('Invalid order.', 'routemile-for-woocommerce'));
		}

		$valid_statuses = array('routew-in-kitchen', 'routew-assigned', 'routew-picked-up', 'completed', 'cancelled');
		if (in_array($new_status, $valid_statuses, true)) {
			$order->update_status($new_status, __('Status updated from dashboard.', 'routemile-for-woocommerce'));
		}

		$referer = wp_get_referer();
		wp_safe_redirect($referer ? $referer : admin_url());
		exit;
	}

	/**
	 * AJAX handler for delivery assignment.
	 *
	 * @since 1.1.0
	 */
	public function ajax_assign_delivery()
	{
		// Security checks
		if (!current_user_can('edit_shop_orders')) {
			wp_send_json_error(array('message' => __('Unauthorized.', 'routemile-for-woocommerce')), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'routew_dashboard_nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'routemile-for-woocommerce')), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$delivery_boy_id = isset($_POST['delivery_boy_id']) ? absint($_POST['delivery_boy_id']) : 0;

		$order = wc_get_order($order_id);
		if (!$order) {
			wp_send_json_error(array('message' => __('Invalid order.', 'routemile-for-woocommerce')), 400);
		}

		if ($delivery_boy_id) {
			$delivery_boy = get_user_by('id', $delivery_boy_id);
			$delivery_boy_name = $delivery_boy ? $delivery_boy->display_name : "ID: {$delivery_boy_id}";

			$order->update_meta_data('_routew_delivery_boy_id', $delivery_boy_id);
			/* translators: %s: delivery agent display name. */
			$order->update_status('routew-assigned', sprintf(__('Order assigned to delivery boy: %s', 'routemile-for-woocommerce'), $delivery_boy_name));
			$order->save();

			if (function_exists('wc_delete_shop_order_transients')) {
				wc_delete_shop_order_transients($order_id);
			}

			wp_send_json_success(array(
				/* translators: 1: order number, 2: delivery agent display name. */
				'message' => sprintf(__('Order #%1$s assigned to %2$s', 'routemile-for-woocommerce'), $order->get_order_number(), $delivery_boy_name),
				'order_id' => $order_id,
				'delivery_boy_id' => $delivery_boy_id,
				'delivery_boy_name' => $delivery_boy_name,
				'new_status' => 'routew-assigned',
				'status_label' => wc_get_order_status_name('routew-assigned'),
			));
		} else {
			// Unassign: revert status so the order is not stuck in
			// routew-assigned with no rider (1.2.16).
			$order->delete_meta_data('_routew_delivery_boy_id');
			$revert_to = get_post_status_object('wc-routew-in-kitchen') ? 'routew-in-kitchen' : 'processing';
			$order->update_status($revert_to, __('Delivery boy unassigned — order returned to kitchen.', 'routemile-for-woocommerce'));
			$order->save();
			// Wipe the now-stale location PII. (AUDIT-FIXES M2)
			ROUTEW_Order_Lifecycle::clear_delivery_location_meta($order_id);

			if (function_exists('wc_delete_shop_order_transients')) {
				wc_delete_shop_order_transients($order_id);
			}

			wp_send_json_success(array(
				/* translators: %s: order number. */
				'message' => sprintf(__('Order #%s unassigned', 'routemile-for-woocommerce'), $order->get_order_number()),
				'order_id' => $order_id,
				'delivery_boy_id' => 0,
				'delivery_boy_name' => '',
				'new_status' => $revert_to,
				'status_label' => wc_get_order_status_name($revert_to),
			));
		}
	}

	/**
	 * AJAX handler for order status updates.
	 *
	 * @since 1.1.0
	 */
	public function ajax_update_status()
	{
		// Security checks
		if (!current_user_can('edit_shop_orders')) {
			wp_send_json_error(array('message' => __('Unauthorized.', 'routemile-for-woocommerce')), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'routew_dashboard_nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'routemile-for-woocommerce')), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$new_status = isset($_POST['new_status']) ? sanitize_text_field(wp_unslash($_POST['new_status'])) : '';

		$order = wc_get_order($order_id);
		if (!$order) {
			wp_send_json_error(array('message' => __('Invalid order.', 'routemile-for-woocommerce')), 400);
		}

		$valid_statuses = array('routew-in-kitchen', 'routew-assigned', 'routew-picked-up', 'completed', 'cancelled');
		if (!in_array($new_status, $valid_statuses, true)) {
			wp_send_json_error(array('message' => __('Invalid status.', 'routemile-for-woocommerce')), 400);
		}

		$order->update_status($new_status, __('Status updated from dashboard.', 'routemile-for-woocommerce'));

		wp_send_json_success(array(
			/* translators: 1: order number, 2: new order status label. */
			'message' => sprintf(__('Order #%1$s updated to %2$s', 'routemile-for-woocommerce'), $order->get_order_number(), wc_get_order_status_name($new_status)),
			'order_id' => $order_id,
			'new_status' => $new_status,
			'status_label' => wc_get_order_status_name($new_status),
		));
	}
}

new ROUTEW_Dashboard_Actions();
