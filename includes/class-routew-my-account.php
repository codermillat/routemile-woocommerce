<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Customer My Account enhancements.
 *
 * Renders the RouteMile-flavored dashboard widgets on the WooCommerce
 * My Account page:
 *   - Greeting hero (time-aware greeting + contextual line)
 *   - Reorder / browse-menu banner
 *   - Quick actions grid (Orders, Addresses, Profile, Payments)
 *   - Recent orders card with delivery-native status pills and a
 *     first-item summary line ("Chicken Biryani ×2 +1 more")
 *   - Saved address peek with a "manage" shortcut
 *   - Settings list + sign out
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

		// Filter the orders table on /my-account/orders/ by the ?status= query
		// var emitted by render_orders_filter_chips(). Without this hook the
		// chips navigate but the table shows the unfiltered list.
		add_filter('woocommerce_my_account_my_orders_query', array($this, 'filter_my_orders_by_chip_status'));
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
	 * Restrict the orders table query on /my-account/orders/ by the ?status
	 * chip. The chip row in render_orders_filter_chips() emits links of the
	 * form /my-account/orders/?status={slug}; this filter reads the same var
	 * and sets WC_Query's status arg so the table only shows matching orders.
	 *
	 * Status → WC slug mapping:
	 *   pending    → pending + on-hold
	 *   processing → processing + any routew-* route-side status that's still
	 *                in flight (routew-assigned / routew-in-kitchen /
	 *                routew-picked-up)
	 *   completed  → completed
	 *   cancelled  → cancelled + failed + refunded
	 *
	 * Returns the array unchanged for unknown / empty slugs (the unfiltered
	 * "All" view) and unsets the status arg so WC's default applies.
	 *
	 * @param array $query_args WC_Query args passed by WC core.
	 * @return array Filtered query args with `status` set when relevant.
	 * @since 1.5.0
	 */
	public function filter_my_orders_by_chip_status($query_args)
	{
		// Only act on the my-account orders endpoint, not on every WC_Query.
		// The endpoint check uses global $wp to avoid loading is_wc_endpoint_url
		// in code paths that should be untouched.
		if (!function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('orders')) {
			return $query_args;
		}

		// Read + sanitize the chip slug. Read-only query var for filtering
		// the orders table (no state change), so no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$slug = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
		if ('' === $slug) {
			// "All" chip: drop any leftover status filter that WC may have set.
			unset($query_args['status']);
			return $query_args;
		}

		$status_map = array(
			'pending'    => array('pending', 'on-hold'),
			'processing' => array('processing', 'routew-assigned', 'routew-in-kitchen', 'routew-picked-up'),
			'completed'  => array('completed'),
			'cancelled'  => array('cancelled', 'failed', 'refunded'),
		);

		if (isset($status_map[$slug])) {
			$query_args['status'] = $status_map[$slug];
		} else {
			// Unknown slug — ignore, leave the default query.
			unset($query_args['status']);
		}

		return $query_args;
	}

	/**
	 * Enqueue the my-account dashboard stylesheet.
	 *
	 * Loads only on the account page so we don't ship CSS to the rest of
	 * the storefront. The base my-account.css is enqueued by
	 * ROUTEW_Shortcodes and provides the layout shell + sub-page styling;
	 * this stylesheet adds the dashboard-only widgets.
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
		$first_name = $this->first_name($user);
		?>
		<section class="routew-dashboard" aria-label="<?php esc_attr_e('Account overview', 'routemile-for-woocommerce'); ?>">

			<?php /* ----- Section 1: greeting hero ----- */ ?>
			<section class="routew-section routew-rise routew-rise-1">
				<header class="routew-hero">
					<div class="routew-hero__text">
						<span class="routew-hero__eyebrow" data-routew-greeting>
							<?php echo esc_html($this->time_greeting()); ?>
						</span>
						<h2 class="routew-hero__title">
							<?php
							/* translators: %s: customer first name */
							printf(esc_html__('Hi %s', 'routemile-for-woocommerce'), esc_html($first_name));
							?>
							<span aria-hidden="true">👋</span>
						</h2>
						<p class="routew-hero__sub">
						<?php
						if ($stats['active'] > 0) {
							/* translators: %d: number of orders currently being delivered. */
							printf(esc_html(_n('%d order is on its way to you.', '%d orders are on their way to you.', (int) $stats['active'], 'routemile-for-woocommerce')), (int) $stats['active']);
							} elseif ($stats['total'] > 0) {
								esc_html_e('Hungry again? Reorder your favourites in a tap.', 'routemile-for-woocommerce');
							} else {
								esc_html_e('Browse the menu and your first order will be at your door.', 'routemile-for-woocommerce');
							}
							?>
						</p>
					</div>
					<span class="routew-avatar routew-avatar--lg routew-hero__avatar" aria-hidden="true">
						<?php echo esc_html(mb_substr($first_name, 0, 1)); ?>
					</span>
				</header>
			</section>

			<?php /* ----- Section 2: reorder / browse banner ----- */ ?>
			<?php if ($last_completed || $stats['total'] === 0) : ?>
				<section class="routew-section routew-rise routew-rise-2">
					<?php if ($last_completed) : ?>
						<div class="routew-reorder-banner">
							<div class="routew-reorder-banner__text">
								<span class="routew-reorder-banner__title"><?php esc_html_e('Order again', 'routemile-for-woocommerce'); ?></span>
								<span class="routew-reorder-banner__sub"><?php esc_html_e('Your last order, one tap away.', 'routemile-for-woocommerce'); ?></span>
							</div>
							<a class="btn routew-btn-brand routew-reorder-banner__cta"
							   href="<?php echo esc_url(wp_nonce_url(add_query_arg('routew_reorder', $last_completed->get_id()), 'routew_reorder')); ?>">
								<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5V1L7 6l5 5V7a5 5 0 1 1-5 5H5a7 7 0 1 0 7-7Z"/></svg>
								<?php esc_html_e('Reorder', 'routemile-for-woocommerce'); ?>
							</a>
						</div>
					<?php else : ?>
						<div class="routew-reorder-banner routew-reorder-banner--ghost">
							<div class="routew-reorder-banner__text">
								<span class="routew-reorder-banner__title"><?php esc_html_e('Craving something?', 'routemile-for-woocommerce'); ?></span>
								<span class="routew-reorder-banner__sub"><?php esc_html_e('The kitchen is just a few taps away.', 'routemile-for-woocommerce'); ?></span>
							</div>
							<a class="btn routew-btn-brand routew-reorder-banner__cta"
							   href="<?php echo esc_url(function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('shop')) : home_url('/shop')); ?>">
								<span aria-hidden="true">→</span>
								<?php esc_html_e('Browse menu', 'routemile-for-woocommerce'); ?>
							</a>
						</div>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php /* ----- Section 3: quick actions grid ----- */ ?>
			<section class="routew-section routew-rise routew-rise-3">
				<div class="routew-quick-actions">
					<a class="routew-quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders') : home_url('/my-account/orders/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--brand" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M5 4h14v3H5zm0 5h14v3H5zm0 5h14v3H5zm0 5h14v3H5z"/></svg>
						</span>
						<span class="routew-quick-action__label"><?php esc_html_e('Orders', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-quick-action__hint"><?php
						/* translators: %d: total number of orders placed. */
						echo esc_html(sprintf(_n('%d order', '%d orders', (int) $stats['total'], 'routemile-for-woocommerce'), (int) $stats['total']));
						?></span>
					</a>
					<a class="routew-quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-address') : home_url('/my-account/edit-address/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--transit" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z"/></svg>
						</span>
						<span class="routew-quick-action__label"><?php esc_html_e('Addresses', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-quick-action__hint"><?php
						/* translators: %d: number of saved addresses. */
						echo esc_html(sprintf(_n('%d saved', '%d saved', (int) $stats['addresses'], 'routemile-for-woocommerce'), (int) $stats['addresses']));
						?></span>
					</a>
					<a class="routew-quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-account') : home_url('/my-account/edit-account/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--delivered" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v2h16v-2c0-3.3-4.7-5-8-5Z"/></svg>
						</span>
						<span class="routew-quick-action__label"><?php esc_html_e('Profile', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-quick-action__hint"><?php esc_html_e('Name, email, password', 'routemile-for-woocommerce'); ?></span>
					</a>
					<a class="routew-quick-action" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('payment-methods') : home_url('/my-account/payment-methods/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--assigned" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M4 5h16a1 1 0 0 1 1 1v3H3V6a1 1 0 0 1 1-1Zm-1 6h18v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7Z"/></svg>
						</span>
						<span class="routew-quick-action__label"><?php esc_html_e('Payments', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-quick-action__hint"><?php esc_html_e('Saved cards & wallets', 'routemile-for-woocommerce'); ?></span>
					</a>
				</div>
			</section>

			<?php /* ----- Section 4: recent orders ----- */ ?>
			<section class="routew-section routew-rise routew-rise-4" aria-labelledby="routew-dashboard-recent-orders-heading">
				<div class="routew-section-title">
					<h2 class="routew-section-title__label" id="routew-dashboard-recent-orders-heading">
						<?php esc_html_e('Recent orders', 'routemile-for-woocommerce'); ?>
					</h2>
					<a class="routew-section-title__action"
					   href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders') : home_url('/my-account/orders/')); ?>">
						<?php esc_html_e('View all', 'routemile-for-woocommerce'); ?>
					</a>
				</div>
				<?php if (empty($recent_orders)) : ?>
					<div class="routew-empty">
						<div class="routew-empty__icon" aria-hidden="true">🛒</div>
						<p class="routew-empty__title"><?php esc_html_e('No orders yet', 'routemile-for-woocommerce'); ?></p>
						<p class="routew-empty__text"><?php esc_html_e('Browse the menu and your first order will appear here.', 'routemile-for-woocommerce'); ?></p>
					</div>
				<?php else : ?>
					<div class="card routew-order-list">
						<?php foreach ($recent_orders as $order) : ?>
							<?php $this->render_recent_order_item($order); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<?php /* ----- Section 5: default address ----- */ ?>
			<?php if (!empty($default_address)) : ?>
				<section class="routew-section routew-rise routew-rise-5" aria-labelledby="routew-dashboard-address-heading">
					<div class="routew-section-title">
						<h2 class="routew-section-title__label" id="routew-dashboard-address-heading">
							<?php esc_html_e('Default address', 'routemile-for-woocommerce'); ?>
						</h2>
						<a class="routew-section-title__action"
						   href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-address') : home_url('/my-account/edit-address/')); ?>">
							<?php esc_html_e('Manage', 'routemile-for-woocommerce'); ?>
						</a>
					</div>
					<div class="card routew-address-card">
						<span class="routew-tile-icon routew-tile-icon--brand" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z"/></svg>
						</span>
						<div class="routew-address-card__body">
							<span class="routew-address-card__name"><?php echo esc_html($default_address['name']); ?></span>
							<span class="routew-address-card__line"><?php echo esc_html($default_address['line1']); ?></span>
							<?php if (!empty($default_address['line2'])) : ?>
								<span class="routew-address-card__line"><?php echo esc_html($default_address['line2']); ?></span>
							<?php endif; ?>
							<?php if (!empty($default_address['city'])) : ?>
								<span class="routew-address-card__line"><?php echo esc_html($default_address['city']); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php /* ----- Section 6: settings list ----- */ ?>
			<section class="routew-section" aria-labelledby="routew-dashboard-settings-heading">
				<div class="routew-section-title">
					<h2 class="routew-section-title__label" id="routew-dashboard-settings-heading">
						<?php esc_html_e('Settings', 'routemile-for-woocommerce'); ?>
					</h2>
				</div>
				<div class="list-group list-group-flush routew-list">
					<a class="list-group-item list-group-item-action routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('orders') : home_url('/my-account/orders/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--brand" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M5 4h14v3H5zm0 5h14v3H5zm0 5h14v3H5zm0 5h14v3H5z"/></svg>
						</span>
						<span class="routew-list-item__label"><?php esc_html_e('My orders', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-list-item__hint"><?php
						/* translators: %d: total number of orders placed. */
						echo esc_html(sprintf(_n('%d order', '%d orders', (int) $stats['total'], 'routemile-for-woocommerce'), (int) $stats['total']));
						?></span>
						<span class="routew-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9.4 18.4 8 17l5-5-5-5 1.4-1.4L15.8 12Z"/></svg></span>
					</a>
					<a class="list-group-item list-group-item-action routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-address') : home_url('/my-account/edit-address/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--transit" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z"/></svg>
						</span>
						<span class="routew-list-item__label"><?php esc_html_e('Saved addresses', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-list-item__hint"><?php
						/* translators: %d: number of saved addresses. */
						echo esc_html(sprintf(_n('%d saved', '%d saved', (int) $stats['addresses'], 'routemile-for-woocommerce'), (int) $stats['addresses']));
						?></span>
						<span class="routew-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9.4 18.4 8 17l5-5-5-5 1.4-1.4L15.8 12Z"/></svg></span>
					</a>
					<a class="list-group-item list-group-item-action routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('edit-account') : home_url('/my-account/edit-account/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--delivered" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v2h16v-2c0-3.3-4.7-5-8-5Z"/></svg>
						</span>
						<span class="routew-list-item__label"><?php esc_html_e('Personal information', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-list-item__hint"><?php esc_html_e('Profile, email, password', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9.4 18.4 8 17l5-5-5-5 1.4-1.4L15.8 12Z"/></svg></span>
					</a>
					<a class="list-group-item list-group-item-action routew-list-item" href="<?php echo esc_url(function_exists('wc_get_endpoint_url') ? wc_get_endpoint_url('payment-methods') : home_url('/my-account/payment-methods/')); ?>">
						<span class="routew-tile-icon routew-tile-icon--assigned" aria-hidden="true">
							<svg viewBox="0 0 24 24"><path d="M4 5h16a1 1 0 0 1 1 1v3H3V6a1 1 0 0 1 1-1Zm-1 6h18v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7Z"/></svg>
						</span>
						<span class="routew-list-item__label"><?php esc_html_e('Payment methods', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-list-item__hint"><?php esc_html_e('Saved cards & wallets', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9.4 18.4 8 17l5-5-5-5 1.4-1.4L15.8 12Z"/></svg></span>
					</a>
				</div>
			</section>

			<?php /* ----- Section 7: sign out ----- */ ?>
			<section class="routew-section">
				<a class="btn routew-btn-signout w-100" href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 17v-2H5V9h5V7l-6 5Zm5-13h-6v2h6v12h-6v2h6a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Z"/></svg>
					<?php esc_html_e('Sign out', 'routemile-for-woocommerce'); ?>
				</a>
			</section>

		</section>
		<?php
	}

	/**
	 * Render a single recent-order row inside the shared order list card.
	 *
	 * @param WC_Order $order Order object.
	 * @return void
	 * @since 1.4.0
	 */
	private function render_recent_order_item($order)
	{
		$status = $order->get_status();
		$pill = $this->order_status_pill($status);
		$item_count = $order->get_item_count();
		$total = $order->get_formatted_order_total();
		$date = $order->get_date_created();
		$date_str = $date ? $date->date_i18n(get_option('date_format')) : '';
		$order_url = $order->get_view_order_url();
		$summary = $this->order_item_summary($order);
		?>
		<a class="routew-order-row routew-order-row--<?php echo esc_attr($pill[0]); ?>" href="<?php echo esc_url($order_url); ?>">
			<div class="routew-order-row__main">
				<span class="routew-order-row__number">
					<?php
					/* translators: %d: order number */
					printf(esc_html__('Order #%d', 'routemile-for-woocommerce'), (int) $order->get_order_number());
					?>
				</span>
				<?php if ('' !== $summary) : ?>
					<span class="routew-order-row__summary"><?php echo esc_html($summary); ?></span>
				<?php endif; ?>
				<span class="routew-order-row__meta">
					<?php echo esc_html($date_str); ?>
					<?php if ($item_count) : ?>
						<?php
						/* translators: %d: item count */
						printf(esc_html(_n('· %d item', '· %d items', (int) $item_count, 'routemile-for-woocommerce')), (int) $item_count);
						?>
					<?php endif; ?>
				</span>
			</div>
			<div class="routew-order-row__side">
				<span class="routew-pill routew-pill--<?php echo esc_attr($pill[0]); ?>">
					<?php echo esc_html($pill[1]); ?>
				</span>
				<span class="routew-order-row__total"><?php echo wp_kses_post($total); ?></span>
			</div>
			<span class="routew-chevron routew-order-row__chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M9.4 18.4 8 17l5-5-5-5 1.4-1.4L15.8 12Z"/></svg></span>
		</a>
		<?php
	}

	/**
	 * Map a WC order status to a delivery-native pill variant + label.
	 *
	 * The pill vocabulary lives in the shared design system
	 * (tools/ui/scss/_components.scss): placed / preparing / assigned /
	 * transit / delivered / cancelled. Labels and colours come from
	 * ROUTEW_Stage_Labels so an admin rename ("Sent to kitchen",
	 * "Out for delivery") mirrors onto the customer dashboard, while
	 * statuses outside the delivery flow fall back to WooCommerce's
	 * own translated names.
	 *
	 * @param string $status Order status slug (e.g. "processing", "routew-in-kitchen").
	 * @return array{0:string,1:string} Pill variant + label.
	 * @since 1.4.0
	 * @since 1.6.0 Delivery-native labels + tinted pill variants (replaces Bootstrap text-bg-* pills).
	 * @since 1.6.0 Admin-renameable via ROUTEW_Stage_Labels.
	 */
	private function order_status_pill($status)
	{
		return array(ROUTEW_Stage_Labels::status_colour($status), ROUTEW_Stage_Labels::status_label($status));
	}

	/**
	 * One-line order summary for the recent-orders row — the first item
	 * name plus overflow ("Chicken Biryani ×2 +2 more"), the way every
	 * food app shows it.
	 *
	 * @param WC_Order $order Order object.
	 * @return string Summary, '' when the order has no items left.
	 * @since 1.6.0
	 */
	private function order_item_summary($order)
	{
		$items = array_values($order->get_items());
		if (empty($items)) {
			return '';
		}
		$first = $items[0];
		$summary = $first->get_name();
		$qty = $first->get_quantity();
		if ($qty > 1) {
			/* translators: %d: quantity */
			$summary .= ' ' . sprintf(__('×%d', 'routemile-for-woocommerce'), (int) $qty);
		}
		if (count($items) > 1) {
			/* translators: %d: remaining item count */
			$summary .= ' ' . sprintf(__('+%d more', 'routemile-for-woocommerce'), count($items) - 1);
		}
		return $summary;
	}

	/**
	 * Time-aware greeting for the dashboard hero.
	 *
	 * @return string "Good morning" / "Good afternoon" / "Good evening".
	 * @since 1.6.0
	 */
	private function time_greeting()
	{
		$hour = (int) current_time('H');
		if ($hour < 12) {
			return __('Good morning', 'routemile-for-woocommerce');
		}
		if ($hour < 17) {
			return __('Good afternoon', 'routemile-for-woocommerce');
		}
		return __('Good evening', 'routemile-for-woocommerce');
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
		if (!empty($shipping['line1'])) {
			$chosen = $shipping;
		} elseif (!empty($billing['line1'])) {
			$chosen = $billing;
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
			'name'  => $name,
			'line1' => (string) $chosen['line1'],
			'line2' => (string) $chosen['line2'],
			'city'  => (string) $chosen['city'],
		);
	}
}

new ROUTEW_My_Account();
