<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Customer My Account enhancements.
 *
 * Renders the RouteMile-flavored dashboard widgets on the WooCommerce
 * My Account page:
 *   - Welcome banner (greeting + reorder CTA)
 *   - Quick stats row (orders in progress, completed, saved addresses)
 *   - Recent orders card with status pills and a "view all" link
 *   - Saved address peek with an "edit" shortcut
 *
 * Hooks `woocommerce_account_dashboard` (fires inside WooCommerce's
 * templates/myaccount/dashboard.php) so the default greeting is replaced
 * rather than appended. The widgets are scoped to the dashboard view
 * only — Orders, Addresses, Account details endpoints keep their native
 * WooCommerce markup.
 *
 * @since 1.4.0
 * @package RouteMile
 */
class ROUTEW_My_Account
{

	/**
	 * Constructor. Wire hooks.
	 *
	 * @since 1.4.0
	 */
	public function __construct()
	{
		// Replace the default WC dashboard greeting + descriptive paragraph.
		add_action('woocommerce_account_dashboard', array($this, 'render_dashboard_widgets'), 1);

		// Enqueue the dashboard-specific stylesheet on the My Account page.
		add_action('wp_enqueue_scripts', array($this, 'enqueue_dashboard_assets'));

		// Trim the default My Account menu to only what a single-restaurant
		// delivery store needs. Downloads is meaningless when no products
		// are virtual + downloadable; the WC wiki documents this filter as
		// the official way to alter the menu
		// (https://github.com/woocommerce/woocommerce/wiki/Customising-account-page-tabs).
		// Late priority so other plugins (e.g. subscriptions, memberships)
		// that filter the menu first still see a clean array.
		add_filter('woocommerce_account_menu_items', array($this, 'filter_account_menu_items'), 999);
	}

	/**
	 * Remove the Downloads menu item from the My Account navigation.
	 *
	 * A single-restaurant delivery store doesn't sell downloadable products;
	 * the menu item is dead weight that also pushes the nav past the
	 * mobile breakpoint into a horizontal scroll. We don't `unset()`
	 * anything else — keeping Payment methods available is useful for
	 * COD customers who want to manage methods, and Edit Address / Edit
	 * Account / Orders are the rest of the delivery customer journey.
	 *
	 * Hooked at a late priority (999) so other plugins (e.g. subscriptions,
	 * memberships) that want to filter first still get a clean array.
	 *
	 * @param array $items Menu items keyed by endpoint slug.
	 * @return array Filtered menu items.
	 * @since 1.4.0
	 */
	public function filter_account_menu_items($items)
	{
		if (isset($items['downloads'])) {
			unset($items['downloads']);
		}
		return $items;
	}

	/**
	 * Enqueue the my-account dashboard stylesheet.
	 *
	 * Loads only on the account page so we don't ship 4KB of CSS to the
	 * rest of the storefront. The base my-account.css is enqueued by
	 * ROUTEW_Shortcodes and provides the layout shell; this stylesheet adds
	 * the dashboard-only widgets.
	 *
	 * @since 1.4.0
	 */
	public function enqueue_dashboard_assets()
	{
		if (!function_exists('is_account_page') || !is_account_page()) {
			return;
		}

		// Only on the dashboard endpoint — Orders / Addresses / Account
		// details keep their own styling and the widget stylesheet would
		// just be dead weight there. is_wc_endpoint_url() returns true on
		// /my-account/orders/ etc., false on the /my-account/ root.
		if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url()) {
			return;
		}

		wp_enqueue_style(
			'routew-my-account-dashboard',
			ROUTEW_PLUGIN_URL . 'assets/css/my-account-dashboard.css',
			array('routew-my-account'),
			ROUTEW_VERSION
		);
	}

	/**
	 * Render the dashboard widgets in place of the default WC greeting.
	 *
	 * WC's templates/myaccount/dashboard.php calls this action after
	 * printing "Hello <name>" and the descriptive paragraph. Rendering at
	 * priority 1 means our widgets appear above any third-party output
	 * that also hooks the same action.
	 *
	 * @since 1.4.0
	 */
	public function render_dashboard_widgets()
	{
		if (!is_user_logged_in()) {
			return;
		}

		$user = wp_get_current_user();
		$customer_id = (int) $user->ID;

		$stats = $this->collect_customer_stats($customer_id);
		$recent_orders = $this->collect_recent_orders($customer_id, 3);
		$default_address = $this->collect_default_address($customer_id);
		$last_completed = $this->find_last_completed_order($customer_id);

		?>
		<section class="routew-dashboard routew-native" aria-label="<?php esc_attr_e('Account overview', 'routemile-for-woocommerce'); ?>">

			<?php /* ----- Section 1: profile card (top of dashboard) ----- */ ?>
			<section class="routew-section">
				<div class="routew-profile-card">
					<span class="routew-profile-card__avatar" aria-hidden="true">
						<?php echo esc_html(mb_substr($this->first_name($user) ?: ($user->display_name ?: '?'), 0, 1)); ?>
					</span>
					<div class="routew-profile-card__info">
						<h2 class="routew-profile-card__name">
							<?php
							/* translators: %s: customer first name */
							printf(esc_html__('%s', 'routemile-for-woocommerce'), esc_html($this->first_name($user)));
							?>
						</h2>
						<p class="routew-profile-card__email"><?php echo esc_html($user->user_email); ?></p>
					</div>
					<a class="routew-ios-card__edit"
					   href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-account') : home_url('/my-account/edit-account/')); ?>">
						<?php esc_html_e('Edit', 'routemile-for-woocommerce'); ?>
					</a>
				</div>
			</section>

			<?php /* ----- Section 2: welcome banner / reorder ----- */ ?>
			<?php if ($last_completed || $stats['total'] === 0) : ?>
				<section class="routew-section">
					<header class="routew-dashboard__welcome">
						<div class="routew-dashboard__welcome-text">
							<span class="routew-dashboard__greeting-eyebrow">
								<?php esc_html_e('Welcome back', 'routemile-for-woocommerce'); ?>
							</span>
							<h3 class="routew-dashboard__greeting">
								<?php
								/* translators: %s: customer first name */
								printf(esc_html__('Hi, %s 👋', 'routemile-for-woocommerce'), esc_html($this->first_name($user)));
								?>
							</h3>
							<p class="routew-dashboard__subgreeting">
								<?php
								if ($stats['active'] > 0) {
									printf(esc_html(_n('You have %d order on the way.', 'You have %d orders on the way.', (int) $stats['active'], 'routemile-for-woocommerce')), (int) $stats['active']);
								} elseif ($stats['total'] > 0) {
									esc_html_e('Hungry again? Reorder your favourites in a tap.', 'routemile-for-woocommerce');
								} else {
									esc_html_e('Browse the menu and your first order is on the way to your door.', 'routemile-for-woocommerce');
								}
								?>
							</p>
						</div>
						<?php if ($last_completed) : ?>
							<a class="routew-dashboard__reorder"
							   href="<?php echo esc_url(wp_nonce_url(add_query_arg('routew_reorder', $last_completed->get_id()), 'routew_reorder')); ?>">
								<span class="routew-dashboard__reorder-icon" aria-hidden="true">↻</span>
								<?php esc_html_e('Reorder last order', 'routemile-for-woocommerce'); ?>
							</a>
						<?php else : ?>
							<a class="routew-dashboard__reorder routew-dashboard__reorder--ghost"
							   href="<?php echo esc_url(function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/shop')); ?>">
								<span class="routew-dashboard__reorder-icon" aria-hidden="true">→</span>
								<?php esc_html_e('Browse menu', 'routemile-for-woocommerce'); ?>
							</a>
						<?php endif; ?>
					</header>
				</section>
			<?php endif; ?>

			<?php /* ----- Section 3: quick actions grid ----- */ ?>
			<section class="routew-section">
				<div class="routew-dashboard__quick-actions">
					<a class="routew-dashboard__quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders') : home_url('/my-account/orders/')); ?>">
						<span class="routew-dashboard__quick-action__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M5 4h14v3H5zm0 5h14v3H5zm0 5h14v3H5zm0 5h14v3H5z"/></svg>
						</span>
						<span class="routew-dashboard__quick-action__label"><?php esc_html_e('Orders', 'routemile-for-woocommerce'); ?></span>
					</a>
					<a class="routew-dashboard__quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-address') : home_url('/my-account/edit-address/')); ?>">
						<span class="routew-dashboard__quick-action__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z"/></svg>
						</span>
						<span class="routew-dashboard__quick-action__label"><?php esc_html_e('Addresses', 'routemile-for-woocommerce'); ?></span>
					</a>
					<a class="routew-dashboard__quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-account') : home_url('/my-account/edit-account/')); ?>">
						<span class="routew-dashboard__quick-action__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v2h16v-2c0-3.3-4.7-5-8-5Z"/></svg>
						</span>
						<span class="routew-dashboard__quick-action__label"><?php esc_html_e('Profile', 'routemile-for-woocommerce'); ?></span>
					</a>
					<a class="routew-dashboard__quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('payment-methods') : home_url('/my-account/payment-methods/')); ?>">
						<span class="routew-dashboard__quick-action__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M4 5h16a1 1 0 0 1 1 1v3H3V6a1 1 0 0 1 1-1Zm-1 6h18v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7Z"/></svg>
						</span>
						<span class="routew-dashboard__quick-action__label"><?php esc_html_e('Payments', 'routemile-for-woocommerce'); ?></span>
					</a>
				</div>
			</section>

			<?php /* ----- Section 4: recent orders (iOS-style rows) ----- */ ?>
			<section class="routew-section" aria-labelledby="routew-dashboard-recent-orders-heading">
				<div class="routew-section-title">
					<h2 class="routew-section-title__label" id="routew-dashboard-recent-orders-heading">
						<?php esc_html_e('Recent orders', 'routemile-for-woocommerce'); ?>
					</h2>
					<a class="routew-section-title__action"
					   href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders') : home_url('/my-account/orders/')); ?>">
						<?php esc_html_e('View all →', 'routemile-for-woocommerce'); ?>
					</a>
				</div>
				<?php if (empty($recent_orders)) : ?>
					<div class="routew-empty-state">
						<div class="routew-empty-state__icon" aria-hidden="true">🛒</div>
						<p class="routew-empty-state__title"><?php esc_html_e('No orders yet', 'routemile-for-woocommerce'); ?></p>
						<p class="routew-empty-state__text"><?php esc_html_e('Browse the menu and your first order will appear here.', 'routemile-for-woocommerce'); ?></p>
					</div>
				<?php else : ?>
					<div class="routew-ios-card routew-dashboard__orders">
						<?php foreach ($recent_orders as $order) : ?>
							<?php $this->render_recent_order_item($order); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<?php /* ----- Section 5: default address ----- */ ?>
			<?php if (!empty($default_address)) : ?>
				<section class="routew-section" aria-labelledby="routew-dashboard-address-heading">
					<div class="routew-section-title">
						<h2 class="routew-section-title__label" id="routew-dashboard-address-heading">
							<?php esc_html_e('Default address', 'routemile-for-woocommerce'); ?>
						</h2>
						<a class="routew-section-title__action"
						   href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-address') : home_url('/my-account/edit-address/')); ?>">
							<?php esc_html_e('Manage →', 'routemile-for-woocommerce'); ?>
						</a>
					</div>
					<div class="routew-ios-card">
						<div class="routew-ios-card__body">
							<address class="routew-dashboard__address">
								<span class="routew-dashboard__address-name"><?php echo esc_html($default_address['label']); ?> · <?php echo esc_html($default_address['name']); ?></span>
								<span class="routew-dashboard__address-line"><?php echo esc_html($default_address['line1']); ?></span>
								<?php if (!empty($default_address['line2'])) : ?>
									<span class="routew-dashboard__address-line"><?php echo esc_html($default_address['line2']); ?></span>
								<?php endif; ?>
								<?php if (!empty($default_address['city'])) : ?>
									<span class="routew-dashboard__address-line"><?php echo esc_html($default_address['city']); ?></span>
								<?php endif; ?>
							</address>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php /* ----- Section 6: settings list (iOS-style link rows) ----- */ ?>
			<section class="routew-section" aria-labelledby="routew-dashboard-settings-heading">
				<h2 class="routew-section-title">
					<span class="routew-section-title__label" id="routew-dashboard-settings-heading">
						<?php esc_html_e('Settings', 'routemile-for-woocommerce'); ?>
					</span>
				</h2>
				<div class="routew-list" role="list">
					<a class="routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders') : home_url('/my-account/orders/')); ?>" role="listitem">
						<span class="routew-list-item__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M5 4h14v3H5zm0 5h14v3H5zm0 5h14v3H5zm0 5h14v3H5z"/></svg>
						</span>
						<div class="routew-list-item__body">
							<span class="routew-list-item__label"><?php esc_html_e('My orders', 'routemile-for-woocommerce'); ?></span>
							<span class="routew-list-item__hint"><?php echo esc_html(sprintf(_n('%d order', '%d orders', (int) $stats['total'], 'routemile-for-woocommerce'), (int) $stats['total'])); ?></span>
						</div>
						<span class="routew-list-item__chevron" aria-hidden="true">›</span>
					</a>
					<a class="routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-address') : home_url('/my-account/edit-address/')); ?>" role="listitem">
						<span class="routew-list-item__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z"/></svg>
						</span>
						<div class="routew-list-item__body">
							<span class="routew-list-item__label"><?php esc_html_e('Saved addresses', 'routemile-for-woocommerce'); ?></span>
							<span class="routew-list-item__hint"><?php echo esc_html(sprintf(_n('%d saved', '%d saved', (int) $stats['addresses'], 'routemile-for-woocommerce'), (int) $stats['addresses'])); ?></span>
						</div>
						<span class="routew-list-item__chevron" aria-hidden="true">›</span>
					</a>
					<a class="routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-account') : home_url('/my-account/edit-account/')); ?>" role="listitem">
						<span class="routew-list-item__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v2h16v-2c0-3.3-4.7-5-8-5Z"/></svg>
						</span>
						<div class="routew-list-item__body">
							<span class="routew-list-item__label"><?php esc_html_e('Personal information', 'routemile-for-woocommerce'); ?></span>
							<span class="routew-list-item__hint"><?php echo esc_html__('Profile, email, password', 'routemile-for-woocommerce'); ?></span>
						</div>
						<span class="routew-list-item__chevron" aria-hidden="true">›</span>
					</a>
					<a class="routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('payment-methods') : home_url('/my-account/payment-methods/')); ?>" role="listitem">
						<span class="routew-list-item__icon" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M4 5h16a1 1 0 0 1 1 1v3H3V6a1 1 0 0 1 1-1Zm-1 6h18v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7Z"/></svg>
						</span>
						<div class="routew-list-item__body">
							<span class="routew-list-item__label"><?php esc_html_e('Payment methods', 'routemile-for-woocommerce'); ?></span>
							<span class="routew-list-item__hint"><?php esc_html_e('Saved cards & wallets', 'routemile-for-woocommerce'); ?></span>
						</div>
						<span class="routew-list-item__chevron" aria-hidden="true">›</span>
					</a>
				</div>
			</section>

			<?php /* ----- Section 7: sign out ----- */ ?>
			<section class="routew-section">
				<a class="routew-signout" href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17v-2H5V9h5V7l-6 5Zm5-13h-6v2h6v12h-6v2h6a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Z"/></svg>
					<?php esc_html_e('Sign out', 'routemile-for-woocommerce'); ?>
				</a>
			</section>

		</section>
		<?php
	}

	/**
	 * Render a single recent-order item row.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 * @since 1.4.0
	 */
	private function render_recent_order_item($order)
	{
		$status = $order->get_status();
		$label = wc_get_order_status_name($status);
		$pill_class = $this->status_pill_class($status);
		$item_count = $order->get_item_count();
		$total = $order->get_formatted_order_total();
		$date = $order->get_date_created();
		$date_str = $date ? $date->date_i18n(get_option('date_format')) : '';
		$order_url = $order->get_view_order_url();

		?>
		<li class="routew-dashboard__orders-item">
			<div class="routew-dashboard__order-meta">
				<a class="routew-dashboard__order-number" href="<?php echo esc_url($order_url); ?>">
					<?php
					/* translators: %d: order number */
					printf(esc_html__('#%d', 'routemile-for-woocommerce'), (int) $order->get_order_number());
					?>
				</a>
				<span class="routew-dashboard__order-date">
					<?php echo esc_html($date_str); ?>
					<?php if ($item_count) : ?>
						<span class="text-secondary">
							<?php
							/* translators: %d: item count */
							printf(esc_html(_n('· %d item', '· %d items', (int) $item_count, 'routemile-for-woocommerce')), (int) $item_count);
							?>
						</span>
					<?php endif; ?>
				</span>
			</div>
			<span class="badge routew-dashboard__status-pill <?php echo esc_attr($pill_class); ?>">
				<?php echo esc_html($label); ?>
			</span>
			<span class="routew-dashboard__order-total"><?php echo wp_kses_post($total); ?></span>
		</li>
		<?php
	}

	/**
	 * Map a WC order status to a Bootstrap 5.3 utility class for status pills.
	 *
	 * Returns the tail of a `.text-bg-{variant}` Bootstrap utility, which we
	 * compose with `.badge` + `.routew-dashboard__status-pill` in the markup.
	 * Bootstrap variants: success / info / warning / danger / secondary / primary.
	 *
	 * @param string $status Order status slug (e.g. "processing", "routew-in-kitchen").
	 * @return string Bootstrap text-bg-* utility suffix (e.g. "text-bg-info").
	 * @since 1.4.0
	 * @since 1.5.0 Returns Bootstrap utility class instead of bespoke modifier.
	 */
	private function status_pill_class($status)
	{
		$map = array(
			'pending'            => 'text-bg-secondary',
			'on-hold'            => 'text-bg-secondary',
			'processing'         => 'text-bg-info',
			'routew-in-kitchen'  => 'text-bg-primary',
			'routew-assigned'    => 'text-bg-warning',
			'routew-picked-up'   => 'text-bg-info',
			'completed'          => 'text-bg-success',
			'cancelled'          => 'text-bg-danger',
			'refunded'           => 'text-bg-secondary',
			'failed'             => 'text-bg-danger',
		);
		return isset($map[$status]) ? $map[$status] : 'text-bg-secondary';
	}

	/**
	 * Get a friendly first name for the current user.
	 *
	 * Falls back to display name, then to a generic greeting, so the page
	 * never shows a literal "%s" placeholder.
	 *
	 * @param WP_User $user Current user.
	 * @return string Display name.
	 * @since 1.4.0
	 */
	private function first_name($user)
	{
		if (!empty($user->first_name)) {
			return $user->first_name;
		}
		$display = trim((string) $user->display_name);
		if ($display !== '') {
			// Use the first token of the display name.
			$parts = preg_split('/\s+/', $display, 2);
			return $parts[0];
		}
		return __('there', 'routemile-for-woocommerce');
	}

	/**
	 * Aggregate counts shown in the dashboard stats row.
	 *
	 * "Active" = any non-completed, non-cancelled, non-refunded, non-failed order.
	 * Performance: WC_Order_Query fetches only IDs (no full hydration),
	 * keeping the dashboard cheap even on customers with hundreds of orders.
	 *
	 * @param int $customer_id Customer user ID.
	 * @return array<string,int> Keys: active, completed, total, addresses.
	 * @since 1.4.0
	 */
	private function collect_customer_stats($customer_id)
	{
		if (!function_exists('wc_get_orders')) {
			return array('active' => 0, 'completed' => 0, 'total' => 0, 'addresses' => 0);
		}

		$active_statuses = array('pending', 'on-hold', 'processing', 'routew-in-kitchen', 'routew-assigned', 'routew-picked-up');
		$done_statuses   = array('completed');

		$active = $this->count_orders_for_customer($customer_id, $active_statuses);
		$completed = $this->count_orders_for_customer($customer_id, $done_statuses);
		$total = $active + $completed
			+ $this->count_orders_for_customer($customer_id, array('cancelled'))
			+ $this->count_orders_for_customer($customer_id, array('refunded'))
			+ $this->count_orders_for_customer($customer_id, array('failed'));

		$addresses = 0;
		if (class_exists('WC_Customer')) {
			$customer = new WC_Customer($customer_id);
			if (!empty($customer->get_shipping_address())) {
				$addresses++;
			}
			if (!empty($customer->get_billing_address())) {
				$addresses++;
			}
		}

		return array(
			'active'    => $active,
			'completed' => $completed,
			'total'     => $total,
			'addresses' => $addresses,
		);
	}

	/**
	 * Count orders for one customer, optionally filtered by status.
	 *
	 * @param int   $customer_id Customer user ID.
	 * @param array $statuses    Status slugs to include.
	 * @return int Count.
	 * @since 1.4.0
	 */
	private function count_orders_for_customer($customer_id, $statuses)
	{
		if (!$customer_id || !function_exists('wc_get_orders')) {
			return 0;
		}
		$query = new WC_Order_Query(array(
			'customer_id' => $customer_id,
			'status'      => $statuses,
			'return'      => 'ids',
			'limit'       => -1,
		));
		$ids = $query->get_orders();
		return is_array($ids) ? count($ids) : 0;
	}

	/**
	 * Get the customer's N most recent orders, hydrated.
	 *
	 * @param int $customer_id Customer user ID.
	 * @param int $limit       Number of orders to return.
	 * @return WC_Order[] Order objects.
	 * @since 1.4.0
	 */
	private function collect_recent_orders($customer_id, $limit = 3)
	{
		if (!$customer_id || !function_exists('wc_get_orders')) {
			return array();
		}
		$ids = wc_get_orders(array(
			'customer_id' => $customer_id,
			'limit'       => max(1, (int) $limit),
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'ids',
		));
		if (!is_array($ids)) {
			return array();
		}
		$orders = array();
		foreach ($ids as $id) {
			$order = wc_get_order($id);
			if ($order instanceof WC_Order) {
				$orders[] = $order;
			}
		}
		return $orders;
	}

	/**
	 * Find the most recent completed order, used to build the reorder CTA.
	 *
	 * @param int $customer_id Customer user ID.
	 * @return WC_Order|null
	 * @since 1.4.0
	 */
	private function find_last_completed_order($customer_id)
	{
		if (!$customer_id || !function_exists('wc_get_orders')) {
			return null;
		}
		$ids = wc_get_orders(array(
			'customer_id' => $customer_id,
			'status'      => array('completed'),
			'limit'       => 1,
			'orderby'     => 'date',
			'order'       => 'DESC',
			'return'      => 'ids',
		));
		if (empty($ids) || !is_array($ids)) {
			return null;
		}
		$order = wc_get_order($ids[0]);
		return ($order instanceof WC_Order) ? $order : null;
	}

	/**
	 * Build a display-friendly snapshot of the customer's default address.
	 *
	 * Prefers the shipping address (delivery context) and falls back to
	 * billing when no shipping is set.
	 *
	 * @param int $customer_id Customer user ID.
	 * @return array<string,string>|empty array
	 * @since 1.4.0
	 */
	private function collect_default_address($customer_id)
	{
		if (!$customer_id || !class_exists('WC_Customer')) {
			return array();
		}
		$customer = new WC_Customer($customer_id);

		$shipping = array(
			'line1' => $customer->get_shipping_address_1(),
			'line2' => $customer->get_shipping_address_2(),
			'city'  => $customer->get_shipping_city(),
		);
		$billing = array(
			'line1' => $customer->get_billing_address_1(),
			'line2' => $customer->get_billing_address_2(),
			'city'  => $customer->get_billing_city(),
		);

		$chosen = null;
		$label  = '';
		if (!empty($shipping['line1'])) {
			$chosen = $shipping;
			$label  = __('Shipping', 'routemile-for-woocommerce');
		} elseif (!empty($billing['line1'])) {
			$chosen = $billing;
			$label  = __('Billing', 'routemile-for-woocommerce');
		} else {
			return array();
		}

		$name = trim($customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name());
		if ($name === '') {
			$name = trim($customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
		}
		if ($name === '') {
			$name = trim($customer->get_display_name());
		}

		return array(
			'label' => $label,
			'name'  => $name,
			'line1' => (string) $chosen['line1'],
			'line2' => (string) $chosen['line2'],
			'city'  => (string) $chosen['city'],
		);
	}
}

new ROUTEW_My_Account();
