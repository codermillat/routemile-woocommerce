<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Manages the admin-specific functionality for orders.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Order_Admin
{

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		add_action('add_meta_boxes', array($this, 'add_delivery_meta_box'));
		add_action('woocommerce_process_shop_order_meta', array($this, 'save_delivery_meta_box_data'));
		add_action('template_redirect', array($this, 'print_receipt_template'));
		add_action('admin_init', array($this, 'handle_order_actions'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
	}

	/**
	 * Handle order actions.
	 *
	 * @since    1.0.0
	 */
	public function handle_order_actions()
	{
		if (isset($_GET['routew_action']) && isset($_GET['order_id']) && isset($_GET['_wpnonce'])) {
			if (!current_user_can('edit_shop_orders')) {
				wp_die(esc_html__('Unauthorized.', 'routemile-for-woocommerce'));
			}

			$nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
			if (!wp_verify_nonce($nonce, 'routew_order_action')) {
				wp_die(esc_html__('Invalid nonce.', 'routemile-for-woocommerce'));
			}

			$order_id = absint($_GET['order_id']);
			$order = wc_get_order($order_id);

			if (!$order) {
				wp_die(esc_html__('Invalid order.', 'routemile-for-woocommerce'));
			}

			switch (sanitize_text_field(wp_unslash($_GET['routew_action']))) {
				case 'reject':
					$order->update_status('cancelled', __('Order rejected by admin.', 'routemile-for-woocommerce'));
					break;
case 'reassign':
						// Reassign also drops the rider meta AND reverts
						// the order status so it doesn't sit in routew-assigned
						// with no rider (1.2.16).
						$order->delete_meta_data('_routew_delivery_boy_id');
						$revert_to = get_post_status_object('wc-routew-in-kitchen') ? 'routew-in-kitchen' : 'processing';
						$order->update_status($revert_to, __('Delivery boy has been unassigned — order returned to kitchen.', 'routemile-for-woocommerce'));
						$order->save();
						break;
			}

			wp_safe_redirect(remove_query_arg(array('routew_action', '_wpnonce')));
			exit;
		}
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook The current admin page hook.
	 * @since 1.0.0
	 */
	public function enqueue_admin_scripts($hook)
	{
		// Load on both legacy (post.php) and HPOS (woocommerce_page_wc-orders) edit pages
		$is_legacy_order = in_array($hook, array('post.php', 'post-new.php'), true);
		$is_hpos_order = 'woocommerce_page_wc-orders' === $hook;

		if (!$is_legacy_order && !$is_hpos_order) {
			return;
		}

		if ($is_legacy_order) {
			global $post;
			if (!$post || 'shop_order' !== $post->post_type) {
				return;
			}
		}

		// Enqueue delivery dashboard script for order admin pages
		wp_enqueue_script(
			'routew-delivery-dashboard',
			plugin_dir_url(__FILE__) . '../assets/js/delivery-dashboard.js',
			array('jquery'),
			ROUTEW_VERSION,
			true
		);

		// Localize script with AJAX parameters
		wp_localize_script('routew-delivery-dashboard', 'routew_checkout_params', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('routew_print_receipt'),
		));
	}

	/**
	 * Load the receipt template.
	 *
	 * @since    1.0.0
	 */
	public function print_receipt_template()
	{
		if (!isset($_GET['routew_print_receipt'])) {
			return;
		}

		// Security: Verify nonce to prevent CSRF
		if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'routew_print_receipt')) {
			wp_die(esc_html__('Security verification failed. Please try again.', 'routemile-for-woocommerce'));
		}

		if (!current_user_can('edit_shop_orders')) {
			wp_die(esc_html__('Unauthorized.', 'routemile-for-woocommerce'));
		}

		$order_id = absint($_GET['routew_print_receipt']);
		$order = wc_get_order($order_id);

		if (!$order) {
			wp_die(esc_html__('Invalid order.', 'routemile-for-woocommerce'));
		}

		// Set the global order variable for the template.
		$GLOBALS['order'] = $order;

		include_once ROUTEW_PLUGIN_DIR . 'templates/receipt-template.php';
		exit;
	}

	/**
	 * Adds the meta box to the order edit screen.
	 *
	 * @since    1.0.0
	 */
	public function add_delivery_meta_box()
	{
		// Register on both legacy post type and HPOS screen
		$screens = array('shop_order');
		if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
			$screens[] = 'woocommerce_page_wc-orders';
		}

		foreach ($screens as $screen) {
			add_meta_box(
				'routew_delivery_meta_box',
				__('RouteMile Delivery', 'routemile-for-woocommerce'),
				array($this, 'render_delivery_meta_box'),
				$screen,
				'side',
				'high'
			);
		}
	}

	/**
	 * Renders the content of the meta box.
	 *
	 * @param   WP_Post   $post    The post object.
	 * @since   1.0.0
	 */
	public function render_delivery_meta_box($post_or_order)
	{
		wp_nonce_field('routew_save_delivery_meta_box_data', 'routew_meta_box_nonce');

		// HPOS compatibility: accept WC_Order or WP_Post
		if ($post_or_order instanceof WC_Order) {
			$order = $post_or_order;
			$order_id = $order->get_id();
		} else {
			$order = wc_get_order($post_or_order->ID);
			$order_id = $post_or_order->ID;
		}

		if (!$order) {
			return;
		}
		$delivery_boy_id = $order->get_meta('_routew_delivery_boy_id', true);
		$delivery_boys = get_users(array('role__in' => array('delivery_boy')));
		$payment_method = $order->get_payment_method_title();
		$shipping_address = $order->get_formatted_shipping_address();

		// Get new delivery details
		$delivery_address = $order->get_meta('_routew_delivery_address', true);
		$delivery_lat = $order->get_meta('_routew_delivery_lat', true);
		$delivery_lng = $order->get_meta('_routew_delivery_lng', true);
		$delivery_distance = $order->get_meta('_routew_delivery_distance', true);

		// (Legacy _routew_address_unit read removed in 1.2.16 — the field was
		// never rendered, and the live _routew_address_details + _routew_landmark
		// fields cover the same need.)
		?>
		<?php if ($delivery_address): ?>
			<div style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border-left: 4px solid #0073aa;">
				<p><strong><?php esc_html_e('Delivery Address:', 'routemile-for-woocommerce'); ?></strong><br>
					<?php echo esc_html($delivery_address); ?></p>

				<?php if ($delivery_distance): ?>
					<p><strong><?php esc_html_e('Delivery Distance:', 'routemile-for-woocommerce'); ?></strong>
						<?php echo esc_html($delivery_distance); ?> km</p>
				<?php endif; ?>

				<?php if ($delivery_lat && $delivery_lng): ?>
					<p><strong><?php esc_html_e('Coordinates:', 'routemile-for-woocommerce'); ?></strong>
						<?php echo esc_html($delivery_lat); ?>, <?php echo esc_html($delivery_lng); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<p>
			<strong><?php esc_html_e('Payment Method:', 'routemile-for-woocommerce'); ?></strong><br>
			<?php echo esc_html($payment_method); ?>
			<?php if ('cod' === $order->get_payment_method()): ?>
				<br><strong><?php esc_html_e('Amount to Collect:', 'routemile-for-woocommerce'); ?></strong>
				<?php echo wp_kses_post($order->get_formatted_order_total()); ?>
			<?php endif; ?>
		</p>
		<p>
			<label for="routew_delivery_boy_id"><?php esc_html_e('Assign Delivery Boy', 'routemile-for-woocommerce'); ?></label>
			<?php if (!empty($delivery_boys)): ?>
				<select name="routew_delivery_boy_id" id="routew_delivery_boy_id" style="width:100%;">
					<option value=""><?php esc_html_e('Select a Delivery Boy', 'routemile-for-woocommerce'); ?></option>
					<?php foreach ($delivery_boys as $boy): ?>
						<option value="<?php echo esc_attr($boy->ID); ?>" <?php selected($delivery_boy_id, $boy->ID); ?>>
							<?php echo esc_html($boy->display_name); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php else: ?>
			<p><?php esc_html_e('No delivery boys found. Please create a user with the "Delivery Boy" role.', 'routemile-for-woocommerce'); ?></p>
		<?php endif; ?>
		</p>
		<p>
			<?php
			// Create precise map link using coordinates if available
			if ($delivery_lat && $delivery_lng) {
				$map_url = "https://www.google.com/maps?q=" . urlencode($delivery_lat . ',' . $delivery_lng);
				$map_label = __('Open Exact Location', 'routemile-for-woocommerce');
			} else {
				// Fallback to address-based search
				$map_url = "https://www.google.com/maps/search/?api=1&query=" . urlencode($shipping_address);
				$map_label = __('Search Location', 'routemile-for-woocommerce');
			}
			?>
			<a href="<?php echo esc_url($map_url); ?>" target="_blank" rel="noopener noreferrer" class="button"><?php echo esc_html($map_label); ?></a>
			<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('routew_print_receipt', $order_id, site_url()), 'routew_print_receipt')); ?>"
				target="_blank" class="button"><?php esc_html_e('Print Receipt', 'routemile-for-woocommerce'); ?></a>
		</p>
		<p>
			<?php
			$edit_link = get_edit_post_link($order_id, 'raw');
		if (!$edit_link) {
			$edit_link = admin_url('admin.php?page=wc-orders&action=edit&id=' . absint($order_id));
		}
			?>
			<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('routew_action', 'reject', $edit_link), 'routew_order_action')); ?>"
				class="button button-danger"><?php esc_html_e('Reject Order', 'routemile-for-woocommerce'); ?></a>
			<a href="<?php echo esc_url(wp_nonce_url(add_query_arg('routew_action', 'reassign', $edit_link), 'routew_order_action')); ?>"
				class="button"><?php esc_html_e('Re-assign', 'routemile-for-woocommerce'); ?></a>
		</p>
		<?php
	}

	/**
	 * Saves the data from the meta box.
	 *
	 * @param   int   $post_id    The post ID.
	 * @since   1.0.0
	 */
	public function save_delivery_meta_box_data($post_id)
	{
		if (!isset($_POST['routew_meta_box_nonce'])) {
			return;
		}

		$nonce = sanitize_text_field(wp_unslash($_POST['routew_meta_box_nonce']));
		if (!wp_verify_nonce($nonce, 'routew_save_delivery_meta_box_data')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (!current_user_can('edit_shop_orders')) {
			return;
		}

		$order = wc_get_order($post_id);
		if (!$order) {
			return;
		}

		// (routew_address_unit save branch removed in 1.2.16 — the input was never
// rendered in the meta box; legacy meta is preserved in WC meta store.)

		if (isset($_POST['routew_delivery_boy_id'])) {
			$new_id = absint($_POST['routew_delivery_boy_id']);
			$prev_id = (int) $order->get_meta('_routew_delivery_boy_id', true);

			if ($new_id) {
				$order->update_meta_data('_routew_delivery_boy_id', $new_id);
			} else {
				$order->delete_meta_data('_routew_delivery_boy_id');
			}

			$order->save();

			if ($new_id && $new_id !== $prev_id) {
				$options = get_option('routew_settings');
				$auto_assign = true;
				if (is_array($options) && isset($options['routew_auto_set_assigned_status'])) {
					$auto_assign = in_array($options['routew_auto_set_assigned_status'], array('yes', 'true', 1, '1'), true);
				}
				if ($auto_assign) {
					$order->update_status('routew-assigned', __('Order assigned to delivery boy.', 'routemile-for-woocommerce'));
				} else {
					$order->add_order_note(__('Delivery boy assigned (status unchanged).', 'routemile-for-woocommerce'));
				}
			}
		} else {
			$order->save();
		}
	}
}

new ROUTEW_Order_Admin();
