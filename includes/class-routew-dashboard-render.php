<?php
/**
 * Deliveries dashboard — page rendering.
 *
 * Registers the "Deliveries" admin menu page and renders the order
 * tables (unassigned / assigned / out for delivery). Split out of
 * ROUTEW_Dashboard in 1.2.9; markup unchanged except the HPOS-safe
 * order edit links (get_edit_post_link() returns null under HPOS).
 *
 * @since      1.2.9
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */

if (!defined('ABSPATH')) {
	exit;
}

class ROUTEW_Dashboard_Render
{

	/**
	 * Register the menu page.
	 *
	 * @since 1.2.9
	 */
	public function __construct()
	{
		add_action('admin_menu', array($this, 'add_dashboard_menu'));
	}

	/**
	 * Adds the dashboard menu page.
	 *
	 * @since    1.0.0
	 */
	public function add_dashboard_menu()
	{
		// Per WP.org Guideline 11: top-level admin menu items at high positions
		// (e.g. 25 — alongside Posts/Comments) hijack the admin dashboard. We
		// instead expose Deliveries as a WooCommerce submenu so it sits next
		// to Orders/Coupons/Reports under the WooCommerce top-level.
		add_submenu_page(
			'woocommerce',
			__('Deliveries Dashboard', 'routemile-woocommerce'),
			__('Deliveries', 'routemile-woocommerce'),
			'manage_woocommerce',
			'routew-deliveries-dashboard',
			array($this, 'render_dashboard_page')
		);
		// The legacy top-level page slug (`admin.php?page=routew-deliveries-dashboard`)
		// still appears in emails and old bookmarks — keep a hidden redirect
		// for one cycle so existing admin links don't 404.
		add_action('admin_init', array($this, 'maybe_redirect_legacy_menu_url'));
	}

	/**
	 * Redirect legacy admin URLs that pointed at the now-removed top-level
	 * Deliveries menu to its new home under the WooCommerce top-level.
	 *
	 * @since 1.5.0
	 */
	public function maybe_redirect_legacy_menu_url()
	{
		if (!isset($_GET['page'])) {
			return;
		}
		if ('routew-deliveries-dashboard' !== $_GET['page']) {
			return;
		}
		// If we got here under the WooCommerce parent menu, no redirect needed.
		if (isset($_GET['parent_page']) && 'woocommerce' === $_GET['parent_page']) {
			return;
		}
		// We are at the legacy URL — redirect to the new WC submenu URL.
		$target = admin_url('admin.php?page=routew-deliveries-dashboard&parent_page=woocommerce');
		wp_safe_redirect($target, 301);
		exit;
	}

	/**
	 * HPOS-safe admin edit URL for an order. get_edit_post_link()
	 * returns null for orders stored in custom order tables.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 * @since 1.2.9
	 */
	private function get_order_edit_url($order_id)
	{
		$link = get_edit_post_link($order_id);
		return $link ? $link : admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id);
	}

	/**
	 * Renders the content of the dashboard page.
	 *
	 * @since    1.0.0
	 */
	public function render_dashboard_page()
	{
		// Unassigned orders: exclude routew-assigned status to prevent overlap
		$unassigned_orders = wc_get_orders(array(
			'limit' => 100,
			'status' => array('pending', 'processing', 'on-hold', 'routew-in-kitchen'),
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key' => '_routew_delivery_boy_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key' => '_routew_delivery_boy_id',
					'value' => array('', '0', 0),
					'compare' => 'IN',
				),
			),
		));

		// Add orphaned orders (routew-assigned status but no delivery boy)
		$orphaned_orders = wc_get_orders(array(
			'limit' => 100,
			'status' => array('routew-assigned'),
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key' => '_routew_delivery_boy_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key' => '_routew_delivery_boy_id',
					'value' => array('', '0', 0),
					'compare' => 'IN',
				),
			),
		));

		// Combine unassigned and orphaned orders
		$unassigned_orders = array_merge($unassigned_orders, $orphaned_orders);

		$assigned_orders = wc_get_orders(array(
			'limit' => 100,
			'status' => array('routew-assigned'),
			'meta_query' => array(
				array(
					'key' => '_routew_delivery_boy_id',
					'value' => 0,
					'type' => 'NUMERIC',
					'compare' => '>',
				),
			),
		));

		$out_for_delivery = wc_get_orders(array(
			'limit' => 100,
			'status' => array('routew-picked-up'),
			'meta_query' => array(
				array(
					'key' => '_routew_delivery_boy_id',
					'value' => 0,
					'type' => 'NUMERIC',
					'compare' => '>',
				),
			),
		));
		?>
		<div class="wrap">
			<h1><?php echo esc_html(get_admin_page_title()); ?></h1>
			<?php do_action('routew_dashboard_content'); ?>

			<h2><?php esc_html_e('Unassigned Orders', 'routemile-woocommerce'); ?></h2>
			<table class="widefat fixed" cellspacing="0">
				<thead>
					<tr>
						<th class="manage-column"><?php esc_html_e('Order', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Customer', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Status', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Payment', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Kitchen Note', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Actions', 'routemile-woocommerce'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($unassigned_orders)): ?>
						<?php $delivery_boys = get_users(array('role' => 'delivery_boy')); ?>
						<?php foreach ($unassigned_orders as $order): ?>
							<tr>
								<td><a
										href="<?php echo esc_url($this->get_order_edit_url($order->get_id())); ?>">#<?php echo esc_html($order->get_order_number()); ?></a>
								</td>
								<td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
								<td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
								<td>
									<?php echo esc_html($order->get_payment_method_title()); ?>
									<?php if ('cod' === $order->get_payment_method()): ?>
										<br><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
									<?php endif; ?>
								</td>
								<?php $this->kitchen_note_cell($order); ?>
								<?php $this->kitchen_note_cell($order); ?>
								<td>
									<div class="routew-action-buttons">
										<button type="button" class="button button-small routew-print-receipt"
											data-order-id="<?php echo esc_attr($order->get_id()); ?>"
											title="<?php esc_attr_e('Print Receipt', 'routemile-woocommerce'); ?>">
											<span class="dashicons dashicons-media-text"></span>
											<span class="button-text"><?php esc_html_e('Print Receipt', 'routemile-woocommerce'); ?></span>
										</button>
										<?php if (!empty($delivery_boys)): ?>
											<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
												style="display:inline-block;">
												<?php wp_nonce_field('routew_assign_delivery'); ?>
												<input type="hidden" name="action" value="routew_assign_delivery" />
												<input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
												<select name="delivery_boy_id" style="width:140px; margin-right:5px;"
													onchange="this.form.submit();">
													<option value=""><?php esc_html_e('Select Delivery Boy...', 'routemile-woocommerce'); ?></option>
													<?php foreach ($delivery_boys as $boy): ?>
														<option value="<?php echo esc_attr($boy->ID); ?>">
															<?php echo esc_html($boy->display_name); ?> (ID:
															<?php echo esc_html($boy->ID); ?>)
														</option>
													<?php endforeach; ?>
												</select>
												<noscript>
													<button type="submit"
														class="button button-small"><?php esc_html_e('Assign', 'routemile-woocommerce'); ?></button>
												</noscript>
											</form>
										<?php else: ?>
											<span style="color:#999;"><?php esc_html_e('No delivery boys available', 'routemile-woocommerce'); ?></span>
										<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="6"><?php esc_html_e('No unassigned orders.', 'routemile-woocommerce'); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px;"><?php esc_html_e('Assigned Orders', 'routemile-woocommerce'); ?></h2>
			<table class="widefat fixed" cellspacing="0">
				<thead>
					<tr>
						<th class="manage-column"><?php esc_html_e('Order', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Customer', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Delivery Boy', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Status', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Payment', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Kitchen Note', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Actions', 'routemile-woocommerce'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($assigned_orders)): ?>
						<?php foreach ($assigned_orders as $order): ?>
							<?php
							$delivery_boy_id = $order->get_meta('_routew_delivery_boy_id', true);
							$delivery_boy = $delivery_boy_id ? get_user_by('id', $delivery_boy_id) : null;
							?>
							<tr>
								<td><a
										href="<?php echo esc_url($this->get_order_edit_url($order->get_id())); ?>">#<?php echo esc_html($order->get_order_number()); ?></a>
								</td>
								<td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
								<td><?php echo esc_html($delivery_boy ? $delivery_boy->display_name : '-'); ?></td>
								<td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
								<td>
									<?php echo esc_html($order->get_payment_method_title()); ?>
									<?php if ('cod' === $order->get_payment_method()): ?>
										<br><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
									<?php endif; ?>
								</td>
								<?php $this->kitchen_note_cell($order); ?>
								<td>
									<div class="routew-action-buttons">
										<button type="button" class="button button-small routew-print-receipt"
											data-order-id="<?php echo esc_attr($order->get_id()); ?>"
											title="<?php esc_attr_e('Print Receipt', 'routemile-woocommerce'); ?>">
											<span class="dashicons dashicons-media-text"></span>
											<span class="button-text"><?php esc_html_e('Print Receipt', 'routemile-woocommerce'); ?></span>
										</button>
										<?php if ('routew-assigned' === $order->get_status()): ?>
											<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
												style="display:inline-block; margin-right:5px;">
												<?php wp_nonce_field('routew_update_status'); ?>
												<input type="hidden" name="action" value="routew_update_order_status" />
												<input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
												<input type="hidden" name="new_status" value="routew-picked-up" />
												<button type="submit"
													class="button button-small"><?php esc_html_e('Picked Up', 'routemile-woocommerce'); ?></button>
											</form>
										<?php endif; ?>
										<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
											style="display:inline-block;">
											<?php wp_nonce_field('routew_assign_delivery'); ?>
											<input type="hidden" name="action" value="routew_assign_delivery" />
											<input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
											<input type="hidden" name="delivery_boy_id" value="" />
											<button type="submit"
												class="button button-small button-link-delete"><?php esc_html_e('Reassign', 'routemile-woocommerce'); ?></button>
										</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="7"><?php esc_html_e('No assigned orders.', 'routemile-woocommerce'); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px;"><?php esc_html_e('Out for Delivery', 'routemile-woocommerce'); ?></h2>
			<table class="widefat fixed" cellspacing="0">
				<thead>
					<tr>
						<th class="manage-column"><?php esc_html_e('Order', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Customer', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Delivery Boy', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Status', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Payment', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Kitchen Note', 'routemile-woocommerce'); ?></th>
						<th class="manage-column"><?php esc_html_e('Actions', 'routemile-woocommerce'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($out_for_delivery)): ?>
						<?php foreach ($out_for_delivery as $order): ?>
							<?php
							$delivery_boy_id = $order->get_meta('_routew_delivery_boy_id', true);
							$delivery_boy = $delivery_boy_id ? get_user_by('id', $delivery_boy_id) : null;
							?>
							<tr>
								<td><a
										href="<?php echo esc_url($this->get_order_edit_url($order->get_id())); ?>">#<?php echo esc_html($order->get_order_number()); ?></a>
								</td>
								<td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
								<td><?php echo esc_html($delivery_boy ? $delivery_boy->display_name : '-'); ?></td>
								<td><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
								<td>
									<?php echo esc_html($order->get_payment_method_title()); ?>
									<?php if ('cod' === $order->get_payment_method()): ?>
										<br><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
									<?php endif; ?>
								</td>
								<?php $this->kitchen_note_cell($order); ?>
								<td>
									<div class="routew-action-buttons">
										<button type="button" class="button button-small routew-print-receipt"
											data-order-id="<?php echo esc_attr($order->get_id()); ?>"
											title="<?php esc_attr_e('Print Receipt', 'routemile-woocommerce'); ?>">
											<span class="dashicons dashicons-media-text"></span>
											<span class="button-text"><?php esc_html_e('Print Receipt', 'routemile-woocommerce'); ?></span>
										</button>
										<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
											style="display:inline-block;">
											<?php wp_nonce_field('routew_update_status'); ?>
											<input type="hidden" name="action" value="routew_update_order_status" />
											<input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>" />
											<input type="hidden" name="new_status" value="completed" />
											<button type="submit"
												class="button button-small button-primary"><?php esc_html_e('Delivered', 'routemile-woocommerce'); ?></button>
										</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="7"><?php esc_html_e('No orders out for delivery.', 'routemile-woocommerce'); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php if (class_exists('ROUTEW_Dashboard_Agents')) { ROUTEW_Dashboard_Agents::render(); } ?>
		<?php
	}

	/**
	 * Compact kitchen-note cell: the customer's checkout order note.
	 * The manager/admin reads it here and passes it to the kitchen —
	 * it is deliberately NOT shown to delivery agents.
	 *
	 * @param WC_Order $order Order being rendered.
	 * @since 1.4.0
	 */
	private function kitchen_note_cell($order)
	{
		$note = trim((string) $order->get_customer_note());
		if ('' === $note) {
			echo '<td><span class="routew-muted">—</span></td>';
			return;
		}
		echo '<td class="routew-kitchen-note" title="' . esc_attr($note) . '">' . esc_html(wp_html_excerpt($note, 60, '…')) . '</td>';
	}
}

new ROUTEW_Dashboard_Render();