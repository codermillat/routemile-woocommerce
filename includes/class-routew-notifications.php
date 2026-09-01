<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Manages the notification functionality for the plugin.
 *
 * Customer-facing status emails use WooCommerce's email system — the
 * WC_Email classes registered in class-routew-core.php trigger
 * automatically on status changes. The one gap that left behind was
 * the DELIVERY AGENT: none of those emails are addressed to the rider,
 * so assignments happened silently. As of 1.2.15 this class sends the
 * assigned rider a direct wp_mail notification when an order
 * transitions to routew-assigned.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Notifications
{

	/**
	 * Register notification hooks.
	 *
	 * Customer-facing emails remain WC_Email classes (registered in
	 * class-routew-core.php, customizable in WooCommerce → Settings →
	 * Emails):
	 * - ROUTEW_Email_In_Kitchen (triggered on routew-in-kitchen status)
	 * - ROUTEW_Email_Assigned (triggered on routew-assigned status)
	 * - ROUTEW_Email_Picked_Up (triggered on routew-picked-up status)
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		// Assignment notice to the rider (1.2.15). Every assignment path —
		// dashboard form, dashboard AJAX, and the order-edit meta box —
		// stores _routew_delivery_boy_id and THEN transitions the order to
		// routew-assigned, so the status hook covers all of them uniformly.
		add_action('woocommerce_order_status_routew-assigned', array('ROUTEW_Notifications', 'notify_assigned_agent'), 10, 2);
	}

	/**
	 * Email the assigned delivery agent about their new delivery.
	 *
	 * Uses wp_mail directly: WooCommerce's email classes are all
	 * customer-facing, and a plain, always-on operational notice to the
	 * rider should not depend on store email configuration. The result is
	 * recorded as an order note either way, so staff can see when the
	 * rider was (or could not be) notified.
	 *
	 * @param int             $order_id Order ID.
	 * @param WC_Order|false  $order    Order object (WC passes it since 3.0).
	 * @since 1.2.15
	 */
	public static function notify_assigned_agent($order_id, $order = false)
	{
		if (!$order) {
			$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
		}
		if (!$order || !is_a($order, 'WC_Order')) {
			return;
		}

		$agent_id = (int) $order->get_meta('_routew_delivery_boy_id', true);
		if (!$agent_id) {
			return; // Status changed without a rider — nothing to notify.
		}

		$agent = get_user_by('id', $agent_id);
		if (!$agent || empty($agent->user_email) || !is_email($agent->user_email)) {
			/* translators: %d: delivery agent user ID */
			$order->add_order_note(sprintf(__('Assignment notification skipped: no valid email address for delivery agent (user #%d).', 'routemile-for-woocommerce'), $agent_id));
			return;
		}

		$order_number = $order->get_order_number();
		$site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

		$subject = sprintf(
			/* translators: 1: site name, 2: order number */
			__('[%1$s] New delivery assigned to you — Order #%2$s', 'routemile-for-woocommerce'),
			$site_name,
			$order_number
		);

		$message = sprintf(
			/* translators: %s: delivery agent display name. */
			__('Hello %s,', 'routemile-for-woocommerce'),
			$agent->display_name
		) . "\r\n\r\n";
		$message .= sprintf(
			/* translators: %s: order number */
			__('You have been assigned to deliver Order #%s.', 'routemile-for-woocommerce'),
			$order_number
		) . "\r\n\r\n";

		$shipping_address = trim((string) $order->get_formatted_shipping_address());
		if ('' !== $shipping_address) {
			$message .= __('Delivery address:', 'routemile-for-woocommerce') . "\r\n";
			$message .= str_replace(array('<br/>', '<br />', '<br>'), "\r\n", $shipping_address) . "\r\n\r\n";
		}

		/* translators: %s: formatted order total */
		$message .= sprintf(__('Order total: %s', 'routemile-for-woocommerce'), wp_strip_all_tags($order->get_formatted_order_total())) . "\r\n";
		if ('cod' === $order->get_payment_method()) {
			$message .= __('Payment: Cash on Delivery — collect the order total from the customer.', 'routemile-for-woocommerce') . "\r\n";
		}

		$message .= "\r\n" . __('Your delivery dashboard:', 'routemile-for-woocommerce') . "\r\n" . home_url('/delivery-dashboard/') . "\r\n";

		$sent = wp_mail($agent->user_email, $subject, $message);

		if ($sent) {
			/* translators: %s: delivery agent display name */
			$order->add_order_note(sprintf(__('Assignment notification emailed to %s.', 'routemile-for-woocommerce'), $agent->display_name));
		} else {
			/* translators: %s: delivery agent display name */
			$order->add_order_note(sprintf(__('Could not email the assignment notification to %s.', 'routemile-for-woocommerce'), $agent->display_name));
		}
	}
}

new ROUTEW_Notifications();
