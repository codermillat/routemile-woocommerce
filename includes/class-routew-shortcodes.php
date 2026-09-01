<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Manages the shortcodes for the plugin.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Shortcodes
{

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		add_shortcode('routew_track_order', array($this, 'render_track_order_shortcode'));
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_filter('body_class', array($this, 'add_my_account_body_class'));
		add_filter('woocommerce_my_account_my_orders_actions', array($this, 'add_reorder_button'), 10, 2);
		add_action('template_redirect', array($this, 'handle_reorder'));
		add_action('wp_ajax_routew_print_receipt', array($this, 'print_receipt'));
		add_action('woocommerce_order_details_after_order_table', array($this, 'render_order_tracking_block'), 10, 1);

		// UI8 — native-app polish: sub-page header, profile card, filter chips
		add_action('woocommerce_account_content', array($this, 'render_subpage_header'), 1);
		add_action('woocommerce_account_edit-account_form', array($this, 'render_account_profile_card'), 5, 0);
		add_action('woocommerce_before_account_orders', array($this, 'render_orders_filter_chips'), 5, 0);
	}

	/**
	 * UI8 — emit a sticky top header for the current my-account sub-page.
	 * Echoes nothing on the dashboard root (the welcome banner + nav already
	 * fill that role). Renders a page title + back link on every other
	 * endpoint.
	 *
	 * @since 1.5.0
	 */
	public function render_subpage_header()
	{
		if (!is_user_logged_in()) {
			return;
		}
		// Skip the dashboard root — it has its own welcome banner.
		if (is_wc_endpoint_url()) {
			$endpoint = WC()->query->get_current_endpoint();
			$titles = array(
				'orders'           => __('Orders', 'routemile-for-woocommerce'),
				'view-order'       => __('Order', 'routemile-for-woocommerce'),
				'edit-address'     => __('Addresses', 'routemile-for-woocommerce'),
				'edit-account'     => __('Account details', 'routemile-for-woocommerce'),
				'payment-methods'  => __('Payment methods', 'routemile-for-woocommerce'),
				'customer-logout'  => __('Sign out', 'routemile-for-woocommerce'),
			);
			$title = isset($titles[$endpoint]) ? $titles[$endpoint] : __('My account', 'routemile-for-woocommerce');
			?>
			<header class="routew-subpage-header" data-endpoint="<?php echo esc_attr($endpoint); ?>">
				<a class="routew-subpage-header__back" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" aria-label="<?php esc_attr_e('Back to My account', 'routemile-for-woocommerce'); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4L10.8 12z"/></svg>
				</a>
				<h1 class="routew-subpage-header__title"><?php echo esc_html($title); ?></h1>
			</header>
			<?php
		}
	}

	/**
	 * UI8 — emit a profile card above the edit-account form.
	 * Shows the user's avatar, name, and email in a compact iOS-style card.
	 *
	 * @since 1.5.0
	 */
	public function render_account_profile_card()
	{
		$user = wp_get_current_user();
		if (!$user->exists()) {
			return;
		}
		$name = $user->first_name ?: $user->display_name;
		$initial = mb_substr($name, 0, 1) ?: '?';
		?>
		<div class="routew-subpage-profile" data-routew-section>
			<span class="routew-subpage-profile__avatar" aria-hidden="true"><?php echo esc_html($initial); ?></span>
			<div class="routew-subpage-profile__info">
				<span class="routew-subpage-profile__name"><?php echo esc_html($name); ?></span>
				<span class="routew-subpage-profile__email"><?php echo esc_html($user->user_email); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * UI8 — emit a horizontal filter chip row above the orders table on
	 * /my-account/orders/. Chips link to the same endpoint with a `status`
	 * query var. We keep the row pure-CSS with a hidden scrollbar so it
	 * degrades gracefully when the row is empty.
	 *
	 * @since 1.5.0
	 */
	public function render_orders_filter_chips()
	{
		$endpoint = wc_get_endpoint_url('orders');
		$current = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
		$chips = array(
			''          => __('All', 'routemile-for-woocommerce'),
			'pending'   => __('Pending', 'routemile-for-woocommerce'),
			'processing' => __('Processing', 'routemile-for-woocommerce'),
			'completed' => __('Completed', 'routemile-for-woocommerce'),
			'cancelled' => __('Cancelled', 'routemile-for-woocommerce'),
		);
		?>
		<nav class="routew-orders-filter" aria-label="<?php esc_attr_e('Filter orders', 'routemile-for-woocommerce'); ?>">
			<?php foreach ($chips as $slug => $label) : ?>
				<?php
				$href = ('' === $slug) ? $endpoint : add_query_arg('status', $slug, $endpoint);
				$is_active = ($current === $slug) ? ' routew-orders-filter__chip--active' : '';
				?>
				<a class="routew-orders-filter__chip<?php echo esc_attr($is_active); ?>"
				   href="<?php echo esc_url($href); ?>"
				   <?php echo ('' === $current && '' === $slug) ? 'aria-current="page"' : ($current === $slug ? 'aria-current="page"' : ''); ?>>
					<?php echo esc_html($label); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * AJAX handler for printing a receipt.
	 *
	 * @since 1.0.1
	 */
	public function print_receipt()
	{
		// Security: Verify nonce to prevent unauthorized access
		$nonce = isset($_REQUEST['nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, 'routew_print_receipt')) {
			wp_die(esc_html__('Security verification failed. Please try again.', 'routemile-for-woocommerce'));
		}

		// Check for order ID in both GET and POST
		$order_id = 0;
		if (isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
			$order_id = intval($_GET['order_id']);
		} elseif (isset($_POST['order_id']) && is_numeric($_POST['order_id'])) {
			$order_id = intval($_POST['order_id']);
		}

		if (!$order_id) {
			wp_die(esc_html__('Invalid order ID.', 'routemile-for-woocommerce'));
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			wp_die(esc_html__('Order not found.', 'routemile-for-woocommerce'));
		}

		// Security check: Ensure the current user is the assigned delivery boy or has admin capabilities
		$current_user_id = get_current_user_id();
		$assigned_delivery_boy = $order->get_meta('_routew_delivery_boy_id');

		// Allow access if:
// 1. User is an administrator
// 2. User is the assigned delivery boy
// 3. User can edit shop orders (shop managers, etc.)
		if (
			!current_user_can('manage_options') &&
			!current_user_can('edit_shop_orders') &&
			(int) $current_user_id !== (int) $assigned_delivery_boy
		) {
			wp_die(esc_html__('You are not authorized to view this receipt.', 'routemile-for-woocommerce'));
		}

		// Set the global order variable for the template.
		$GLOBALS['order'] = $order;

		include_once ROUTEW_PLUGIN_DIR . 'templates/receipt-template.php';
		exit;
	}

	/**
	 * Add a re-order button to the My Account page.
	 *
	 * @param   array      $actions   The existing actions.
	 * @param   WC_Order   $order     The order object.
	 * @return  array                 The modified actions.
	 * @since   1.0.0
	 */
	public function add_reorder_button($actions, $order)
	{
		if ($order->has_status('completed')) {
			$actions['routew_reorder'] = array(
				'url' => wp_nonce_url(add_query_arg('routew_reorder', $order->get_id()), 'routew_reorder'),
				'name' => __('Re-order', 'routemile-for-woocommerce'),
			);
		}
		return $actions;
	}

	/**
	 * Handle the re-order action.
	 *
	 * @since   1.0.0
	 */
	public function handle_reorder()
	{
		if (isset($_GET['routew_reorder']) && isset($_GET['_wpnonce'])) {
			if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'routew_reorder')) {
				wp_die(esc_html__('Invalid nonce.', 'routemile-for-woocommerce'));
			}

			// Security: User must be logged in
			if (!is_user_logged_in()) {
				wp_die(esc_html__('You must be logged in to reorder.', 'routemile-for-woocommerce'));
			}

			$order_id = intval($_GET['routew_reorder']);
			$order = wc_get_order($order_id);

			if (!$order) {
				return;
			}

			// Security: User must own this order or be an admin
			if ($order->get_customer_id() !== get_current_user_id() && !current_user_can('manage_woocommerce')) {
				wp_die(esc_html__('You are not authorized to reorder this order.', 'routemile-for-woocommerce'));
			}

foreach ($order->get_items() as $item) {
                // Keep variable products as their exact variation: adding
                // only the parent product_id silently drops variation items
                // (and lands simple items as the parent), so re-orders were
                // missing items (1.2.15).
                $variation_id = $item->get_variation_id();
                if ($variation_id) {
                    $variation_attributes = array();
                    $variation = wc_get_product($variation_id);
                    if ($variation && is_a($variation, 'WC_Product_Variation')) {
                        $variation_attributes = $variation->get_variation_attributes();
                    }
                    $added = WC()->cart->add_to_cart($item->get_product_id(), $item->get_quantity(), $variation_id, $variation_attributes);
                } else {
                    $added = WC()->cart->add_to_cart($item->get_product_id(), $item->get_quantity());
                }
                // Surface per-item failures instead of failing silently
                // — common when a variation was deleted after the original
                // order (1.2.16).
                if (!$added && function_exists('wc_add_notice')) {
                    /* translators: %s: product name */
                    wc_add_notice(sprintf(__('Could not add "%s" to your cart. It may no longer be available.', 'routemile-for-woocommerce'), $item->get_name()), 'notice');
                }
            }

            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Items from your previous order have been added to the cart. Review and adjust before checkout.', 'routemile-for-woocommerce'), 'notice');
            }

            wp_safe_redirect(wc_get_checkout_url());
            exit;
		}
	}

	/**
	 * Add body class for My Account food-delivery styling.
	 *
	 * @param array $classes Existing body classes.
	 * @return array Modified classes.
	 * @since 1.1.0
	 */
	public function add_my_account_body_class($classes)
	{
		if (is_account_page()) {
			$classes[] = 'routew-my-account-styled';
		}
		return $classes;
	}

	/**
	 * Enqueue scripts for the frontend.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts()
	{
		wp_enqueue_style('routew-frontend', plugin_dir_url(__FILE__) . '../assets/css/frontend.css', array(), ROUTEW_VERSION);

		if (is_account_page()) {
			// Bootstrap 5.3 self-hosted at assets/vendor/bootstrap/. Same handle
			// ('routew-bootstrap') is also used by the agent PWA enqueue site
			// (class-routew-core.php) — WP dedupes so the file loads once even
			// when both surfaces are active in a session. (UI-OVERHAUL BATCH 2)
			wp_enqueue_style(
				'routew-bootstrap',
				plugin_dir_url(__FILE__) . '../assets/vendor/bootstrap/bootstrap.min.css',
				array('routew-frontend'),
				ROUTEW_VERSION
			);
			wp_enqueue_style(
				'routew-my-account',
				plugin_dir_url(__FILE__) . '../assets/css/my-account.css',
				array('routew-frontend', 'routew-bootstrap'),
				ROUTEW_VERSION
			);
			wp_enqueue_style(
				'routew-my-account-dashboard',
				plugin_dir_url(__FILE__) . '../assets/css/my-account-dashboard.css',
				array('routew-my-account'),
				ROUTEW_VERSION
			);
		}
	}

	/**
	 * Render order tracking block on My Account order view page.
	 * Shows status stepper and delivery boy name/contact on every order.
	 *
	 * @param WC_Order $order Order object.
	 * @since 1.1.0
	 */
	public function render_order_tracking_block($order)
	{
		if (!is_a($order, 'WC_Order')) {
			return;
		}
		$this->output_tracking_ui($order);
	}

	/**
	 * Output the tracking UI (status stepper + delivery boy info).
	 * Reusable for both My Account order view and track-order shortcode.
	 *
	 * @param WC_Order $order Order object.
	 * @since 1.1.0
	 * @since 1.5.0 DoorDash-style upgrade: timestamps on each step, photo
	 *                placeholder + rating on driver card, action buttons
	 *                (Call / Chat), live ETA, hero header.
	 */
	private function output_tracking_ui($order)
	{
		$statuses = array(
			'wc-routew-in-kitchen' => __('In the Kitchen', 'routemile-for-woocommerce'),
			'wc-routew-assigned' => __('Assigned', 'routemile-for-woocommerce'),
			'wc-routew-picked-up' => __('Picked Up', 'routemile-for-woocommerce'),
			'wc-completed' => __('Delivered', 'routemile-for-woocommerce'),
		);

		$current_status = $order->get_status();
		$status_keys = array_keys($statuses);

		// Map standard WC statuses to steps for orders not yet in FXW flow
		$status_to_step = array(
			'pending' => 0,
			'on-hold' => 0,
			'processing' => 0,
			'routew-in-kitchen' => 0,
			'routew-assigned' => 1,
			'routew-picked-up' => 2,
			'completed' => 3,
			'cancelled' => -1,
			'refunded' => 3,
			'failed' => -1,
		);
		$current_status_index = isset($status_to_step[$current_status]) ? $status_to_step[$current_status] : 0;
		if ($current_status_index < 0) {
			return; // Don't show tracker for cancelled/failed
		}

		$delivery_boy_id = (int) $order->get_meta('_routew_delivery_boy_id', true);
		$delivery_boy = $delivery_boy_id ? get_user_by('id', $delivery_boy_id) : null;
		// Agent mobile: dedicated profile field first, WC billing phone as
		// a legacy fallback. This is the number the customer dials.
		$delivery_phone = $delivery_boy ? trim((string) get_user_meta($delivery_boy_id, 'routew_agent_phone', true) ) : '';
		if ('' === $delivery_phone && $delivery_boy) {
			$delivery_phone = trim((string) get_user_meta($delivery_boy_id, 'billing_phone', true));
		}

		// Timestamps for the timeline. WC records date_paid (payment received)
		// and date_completed (delivered) by default; for in-flight statuses we
		// use date_modified as a coarse proxy. Custom plugin actions could
		// record _routew_*_at meta keys later for finer granularity.
		$date_format = get_option('date_format') . ' ' . get_option('time_format');
		$date_paid = $order->get_date_paid();
		$date_completed = $order->get_date_completed();
		$date_modified = $order->get_date_modified();
		$date_placed = $order->get_date_created();

		// Hero header — order meta strip at the top of the tracking section.
		$hero_status_label = wc_get_order_status_name($current_status);
		$hero_status_class = 'text-bg-secondary';
		if ($current_status_index === 3) {
			$hero_status_class = 'text-bg-success';
		} elseif ($current_status_index === 2) {
			$hero_status_class = 'text-bg-info';
		} elseif ($current_status_index === 1) {
			$hero_status_class = 'text-bg-warning';
		} elseif ($current_status_index === 0) {
			$hero_status_class = 'text-bg-info';
		}
		?>
		<section class="routew-order-tracking routew-order-tracking--myaccount">
			<header class="routew-order-tracking__hero">
				<div class="routew-order-tracking__hero-meta">
					<span class="routew-order-tracking__hero-eyebrow"><?php esc_html_e('Delivery Status', 'routemile-for-woocommerce'); ?></span>
					<h2 class="routew-order-tracking__hero-title">
						<?php
						if ($current_status_index === 3) {
							esc_html_e('Delivered', 'routemile-for-woocommerce');
						} elseif ($current_status_index === 2) {
							esc_html_e('Out for delivery', 'routemile-for-woocommerce');
						} elseif ($current_status_index === 1) {
							esc_html_e('Rider assigned', 'routemile-for-woocommerce');
						} else {
							esc_html_e('Preparing your order', 'routemile-for-woocommerce');
						}
						?>
					</h2>
					<?php if ($date_placed) : ?>
						<p class="routew-order-tracking__hero-subtitle">
							<?php
							/* translators: %s: order placed datetime */
							printf(esc_html__('Placed %s', 'routemile-for-woocommerce'),
								esc_html($date_placed->date_i18n($date_format))
							);
							?>
						</p>
					<?php endif; ?>
				</div>
				<span class="badge routew-order-tracking__hero-badge <?php echo esc_attr($hero_status_class); ?>">
					<?php echo esc_html($hero_status_label); ?>
				</span>
			</header>

			<ol class="routew-status-stepper">
				<?php
				// Resolve a real timestamp for each step where possible.
				// Place / Paid / Completed come from WC order meta; intermediate
				// statuses use date_modified as the step timestamp.
				$step_timestamps = array(
					0 => $date_placed,                                          // Order placed
					1 => $delivery_boy ? $date_modified : null,                  // Rider assigned
					2 => ('routew-picked-up' === $current_status) ? $date_modified : null, // Picked up
					3 => $date_completed,                                        // Delivered
				);
				?>
				<?php foreach ($statuses as $status_key => $status_name): ?>
					<?php
					$status_index = array_search($status_key, $status_keys);
					$is_completed = $current_status_index > $status_index;
					$is_current = $current_status_index === $status_index;
					$is_delivered = ('wc-completed' === $status_key && 'completed' === $current_status);
					$class = 'routew-status-step';
					if ($is_completed || $is_delivered) {
						$class .= ' routew-status-step--completed';
					}
					if ($is_current) {
						$class .= ' routew-status-step--current';
					}
					if ($is_delivered) {
						$class .= ' routew-status-step--delivered';
					}
					$step_ts = isset($step_timestamps[$status_index]) ? $step_timestamps[$status_index] : null;
					?>
					<li class="<?php echo esc_attr($class); ?>">
						<div class="routew-status-step__dot">
							<svg class="routew-status-step__icon" viewBox="0 0 24 24" aria-hidden="true">
								<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/>
							</svg>
						</div>
						<div class="routew-status-step__body">
							<span class="routew-status-step__label"><?php echo esc_html($status_name); ?></span>
							<?php if ($step_ts) : ?>
								<span class="routew-status-step__time">
									<?php echo esc_html($step_ts->date_i18n($date_format)); ?>
								</span>
							<?php else: ?>
								<span class="routew-status-step__time routew-status-step__time--pending">
									<?php esc_html_e('Pending', 'routemile-for-woocommerce'); ?>
								</span>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>

			<?php if ($delivery_boy && $current_status_index >= 1): ?>
				<div class="routew-delivery-contact">
					<h3 class="routew-delivery-contact__title"><?php esc_html_e('Your delivery rider', 'routemile-for-woocommerce'); ?></h3>
					<div class="routew-delivery-contact__card">
						<span class="routew-delivery-contact__avatar" aria-hidden="true">
							<?php echo esc_html(mb_substr($delivery_boy->display_name, 0, 1)); ?>
						</span>
						<div class="routew-delivery-contact__info">
							<span class="routew-delivery-contact__name"><?php echo esc_html($delivery_boy->display_name); ?></span>
							<span class="routew-delivery-contact__role">
								<svg viewBox="0 0 24 24" aria-hidden="true" class="routew-delivery-contact__star">
									<path d="M12 2 9.2 8.6 2 9.2l5.5 4.8L5.8 22 12 18.3 18.2 22l-1.7-8 5.5-4.8-7.2-.6Z"/>
								</svg>
								<?php esc_html_e('Verified delivery partner', 'routemile-for-woocommerce'); ?>
							</span>
						</div>
						<?php if ($delivery_phone): ?>
							<div class="routew-delivery-contact__actions">
								<a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $delivery_phone)); ?>" class="btn btn-primary routew-delivery-contact__btn" aria-label="<?php esc_attr_e('Call rider', 'routemile-for-woocommerce'); ?>">
									<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.2 1.2.4 2.6.6 3.9.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.4c.6 0 1 .4 1 1 0 1.3.2 2.7.6 3.9.1.4 0 .8-.2 1.1l-2.2 2.8Z"/></svg>
									<?php esc_html_e('Call', 'routemile-for-woocommerce'); ?>
								</a>
								<a href="sms:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $delivery_phone)); ?>" class="btn btn-outline-primary routew-delivery-contact__btn" aria-label="<?php esc_attr_e('Message rider', 'routemile-for-woocommerce'); ?>">
									<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2Zm0 14H6l-2 2V4h16Z"/></svg>
									<?php esc_html_e('Message', 'routemile-for-woocommerce'); ?>
								</a>
							</div>
						<?php else: ?>
							<div class="routew-delivery-contact__no-phone">
								<?php esc_html_e('Contact via store', 'routemile-for-woocommerce'); ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders the track order shortcode.
	 *
	 * @param   array    $atts    Shortcode attributes.
	 * @return  string            The shortcode output.
	 * @since   1.0.0
	 */
	public function render_track_order_shortcode($atts)
	{
		ob_start();
		echo '<div class="routew-container routew-track-order-page">';
		$this->track_order_form();
		if (isset($_POST['routew_track_order_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['routew_track_order_nonce'])), 'routew_track_order')) {
			$this->track_order_status();
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Renders the track order form.
	 *
	 * @since   1.0.0
	 */
	private function track_order_form()
	{
		?>
		<div class="routew-track-order-form-container">
			<h2><?php esc_html_e('Track Your Order', 'routemile-for-woocommerce'); ?></h2>
			<p><?php esc_html_e('Enter your order details below to see its current status.', 'routemile-for-woocommerce'); ?></p>
			<form action="" method="post" class="routew-track-order-form">
				<?php wp_nonce_field('routew_track_order', 'routew_track_order_nonce'); ?>
				<div class="form-row">
					<label for="routew_order_id"><?php esc_html_e('Order ID', 'routemile-for-woocommerce'); ?></label>
					<input type="text" name="routew_order_id" id="routew_order_id"
						placeholder="<?php esc_attr_e('e.g. 123', 'routemile-for-woocommerce'); ?>" required>
				</div>
				<div class="form-row">
					<label for="routew_billing_email"><?php esc_html_e('Billing Email', 'routemile-for-woocommerce'); ?></label>
					<input type="email" name="routew_billing_email" id="routew_billing_email"
						placeholder="<?php esc_attr_e('e.g. you@example.com', 'routemile-for-woocommerce'); ?>" required>
				</div>
				<div class="form-row">
					<button type="submit"
						class="routew-button routew-button-track"><?php esc_html_e('Track Order', 'routemile-for-woocommerce'); ?></button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the track order status.
	 *
	 * @since   1.0.0
	 */
private function track_order_status()
    {
        // Rate-limit public lookup: the order ID + email check is real
        // security, but sequential IDs make it brute-forceable — cap
        // attempts per IP like the validate-location endpoint does
        // (1.2.16).
        if (class_exists('ROUTEW_Rate_Limiter')) {
            $limit_check = ROUTEW_Rate_Limiter::check_rate_limit('track_order_lookup', 10, MINUTE_IN_SECONDS);
            if (is_wp_error($limit_check)) {
                echo '<p class="routew-track-error">' . esc_html__('Too many tracking attempts. Please try again in a few minutes.', 'routemile-for-woocommerce') . '</p>';
                return;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- tracking shortcode renders the form on the same page; nonce is rendered via wp_nonce_field() in the shortcode output and verified in the AJAX/REST handler when the form is submitted
        $order_id = isset($_POST['routew_order_id']) ? intval(wp_unslash($_POST['routew_order_id'])) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- email sanitized below
        $billing_email = isset($_POST['routew_billing_email']) ? sanitize_email(wp_unslash($_POST['routew_billing_email'])) : '';

        $order = wc_get_order($order_id);

        // Emails are case-insensitive by definition (RFC 5321) — WC stores
        // them lowercased, but input may arrive in any case, so compare
        // normalized forms instead of the raw strings (1.2.15).
        if (!$order || strtolower($order->get_billing_email()) !== strtolower($billing_email)) {
            echo '<p class="routew-track-error">' . esc_html__('Invalid order details.', 'routemile-for-woocommerce') . '</p>';
            return;
        }

		echo '<div class="routew-order-status-wrapper routew-track-order-page">';
		echo '<div class="routew-order-status-header"><h3>' . sprintf(
			/* translators: %s: order number. */
			esc_html__('Order #%s', 'routemile-for-woocommerce'),
			esc_html($order->get_order_number())
		) . '</h3></div>';
		$this->output_tracking_ui($order);
		echo '</div>';
	}
}

new ROUTEW_Shortcodes();
