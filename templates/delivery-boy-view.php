<?php
/**
 * The template for displaying the delivery boy view.
 *
 * @since      1.0.0
 * @package    RouteMile
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
	wp_redirect(home_url());
	exit;
}

get_header();
?>
<div class="routew-delivery-dashboard">
	<div class="routew-container">
		<h1><?php esc_html_e('My Assigned Deliveries', 'routemile-woocommerce'); ?></h1>
		<?php
		$delivery_boy_id = get_current_user_id();
		$orders = wc_get_orders(array(
			'limit' => 50,
			'meta_query' => array(
				array(
					'key' => '_routew_delivery_boy_id',
					'value' => $delivery_boy_id,
					'compare' => '='
				)
			),
			'status' => array('routew-assigned', 'routew-picked-up'),
		));

		if ($orders):
			?>
			<div class="routew-orders-grid">
				<?php
				foreach ($orders as $order):
					$shipping_address = $order->get_formatted_shipping_address();
					$unit = $order->get_meta('_routew_address_unit', true);
					$status = $order->get_status();
					?>
					<div class="routew-order-card">
						<div class="routew-card-header">
							<h3><?php printf(esc_html__('Order #%s', 'routemile-woocommerce'), esc_html($order->get_order_number())); ?></h3>
							<span
								class="routew-status-badge status-<?php echo esc_attr($status); ?>"><?php echo esc_html(wc_get_order_status_name($status)); ?></span>
						</div>
						<div class="routew-card-body">
							<div class="routew-card-section">
								<h4><?php esc_html_e('Customer Details', 'routemile-woocommerce'); ?></h4>
								<p><strong><?php esc_html_e('Name:', 'routemile-woocommerce'); ?></strong>
									<?php echo esc_html($order->get_formatted_billing_full_name()); ?></p>
								<p><strong><?php esc_html_e('Address:', 'routemile-woocommerce'); ?></strong>
									<?php echo wp_kses_post($shipping_address); ?></p>
								<?php if ($unit): ?>
									<p><strong><?php esc_html_e('Unit:', 'routemile-woocommerce'); ?></strong> <?php echo esc_html($unit); ?>
									</p>
								<?php endif; ?>
								<p><strong><?php esc_html_e('Phone:', 'routemile-woocommerce'); ?></strong> <a
										href="tel:<?php echo esc_attr($order->get_billing_phone()); ?>"><?php echo esc_html($order->get_billing_phone()); ?></a>
								</p>
							</div>
							<div class="routew-card-section">
								<h4><?php esc_html_e('Payment Information', 'routemile-woocommerce'); ?></h4>
								<p><strong><?php esc_html_e('Method:', 'routemile-woocommerce'); ?></strong>
									<?php echo esc_html($order->get_payment_method_title()); ?></p>
								<?php if ('cod' === $order->get_payment_method()): ?>
									<p class="routew-collect-amount"><strong><?php esc_html_e('Collect:', 'routemile-woocommerce'); ?></strong>
										<?php echo wp_kses_post($order->get_formatted_order_total()); ?></p>
								<?php endif; ?>
							</div>
						</div>
						<div class="routew-card-footer">
							<a href="<?php echo esc_url('https://www.google.com/maps/search/?api=1&query=' . urlencode(wp_strip_all_tags($shipping_address))); ?>"
								target="_blank" rel="noopener noreferrer"
								class="routew-button button-secondary"><?php esc_html_e('Open in Map', 'routemile-woocommerce'); ?></a>
							<?php if ('routew-assigned' === $status): ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
									<?php wp_nonce_field('routew_delivery_action'); ?>
									<input type="hidden" name="action" value="routew_mark_picked_up" />
									<input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
									<button type="submit"
										class="routew-button button-primary"><?php esc_html_e('Mark Picked Up', 'routemile-woocommerce'); ?></button>
								</form>
							<?php endif; ?>
							<?php if ('routew-picked-up' === $status): ?>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
									<?php wp_nonce_field('routew_delivery_action'); ?>
									<input type="hidden" name="action" value="routew_mark_delivered" />
									<input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
									<button type="submit"
										class="routew-button button-primary"><?php esc_html_e('Mark Delivered', 'routemile-woocommerce'); ?></button>
								</form>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php else: ?>
			<div class="routew-no-orders">
				<p><?php esc_html_e('You have no assigned deliveries at the moment.', 'routemile-woocommerce'); ?></p>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php
get_footer();
?>