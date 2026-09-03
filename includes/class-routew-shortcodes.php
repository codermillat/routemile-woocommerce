<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Manages the shortcodes for the plugin.
 *
 * Also owns the customer-facing tracking UI (order-view block + track
 * shortcode), the my-account scope wrapper, and the frontend asset graph.
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

		// Design-system scope: open a .routew-ui wrapper around the whole
		// account content column (before any endpoint output at priority 0,
		// closed after everything at 9999) so the scoped Bootstrap build
		// applies to plugin widgets AND WooCommerce's own endpoint markup
		// (orders table, address forms) without ever touching the theme.
		add_action('woocommerce_account_content', array($this, 'open_account_ui_scope'), 0);
		add_action('woocommerce_account_content', array($this, 'close_account_ui_scope'), 9999);

		// WP-native template overrides — redirect WC to load our own
		// templates for the my-account dashboard and addresses. Removes
		// WC's redundant greeting paragraphs and gives us plugin-controlled
		// grid markup on the addresses page (no more fighting `.col2-set`).
		add_filter('woocommerce_locate_template', array($this, 'override_wc_templates'), 10, 3);

		// Order-received (thank-you) page: wrap WC's order-confirmation
		// content in the .routew-ui scope so the overview strip, order
		// details table, notices, shipping/billing cards AND our tracking
		// block all get the design-system styling.
		//
		// The site uses WooCommerce's BLOCKIFIED order-confirmation template
		// (each block — status, summary, totals, addresses — is rendered
		// independently by WP_Block). The classic
		// `woocommerce_before_thankyou` / `woocommerce_thankyou` hooks fire
		// *inside* the status and additional-information blocks (captured
		// by WC's get_hook_content()) — so they split the wrapper across
		// sibling blocks instead of spanning them. The `render_block`
		// filter runs on every block's HTML and lets us add the scope
		// class directly to each block's own wrapper div, which is what
		// the CSS selectors need.
		add_filter('render_block', array($this, 'wrap_thankyou_block'), 10, 2);
	}

	/**
	 * Open the .routew-ui scope wrapper inside the account content column.
	 *
	 * Hooked at priority 0 — before WC's default content callback (10) —
	 * so every endpoint template renders inside the scope.
	 *
	 * On the dashboard root the wrapper also carries `routew-account--dashboard`
	 * so the CSS layer can suppress WC's default "Hello user (not user?
	 * Log out)" greeting paragraph (templates/myaccount/dashboard.php
	 * prints it before the woocommerce_account_dashboard action — our
	 * greeting hero replaces it).
	 *
	 * @since 1.6.0
	 */
	public function open_account_ui_scope()
	{
		$classes = 'routew-ui routew-account';
		if (function_exists('is_wc_endpoint_url') && !is_wc_endpoint_url()) {
			$classes .= ' routew-account--dashboard';
		}
		echo '<div class="' . esc_attr($classes) . '">';
	}

	/**
	 * Close the .routew-ui scope wrapper.
	 *
	 * @since 1.6.0
	 */
	public function close_account_ui_scope()
	{
		echo '</div>';
	}

	/**
	 * Inject the .routew-ui scope class onto every WC order-confirmation
	 * block on the order-received page.
	 *
	 * Each WC block (`woocommerce/order-confirmation-status`,
	 * `…-summary`, `…-totals`, `…-shipping-address`, `…-billing-address`,
	 * `…-additional-information`, etc.) renders into its own
	 * `<div class="wp-block-woocommerce-order-confirmation-…">` wrapper.
	 * Adding `routew-ui routew-account routew-account--thankyou` to
	 * those wrapper classes makes the design-system CSS scope match every
	 * block independently — `.routew-ui .routew-status-stepper`,
	 * `.routew-ui .routew-order-tracking`, `.routew-ui .woocommerce-Price-…`,
	 * etc. all apply.
	 *
	 * Replaces the earlier woocommerce_before_thankyou /
	 * woocommerce_thankyou hooks which misfired under the blockified
	 * template (they fired *inside* the status and additional-information
	 * blocks respectively, splitting the wrapper across sibling blocks).
	 *
	 * @param string $block_content Block HTML.
	 * @param array  $block         Parsed block (blockName + attrs).
	 * @return string Modified HTML.
	 * @since 1.6.0
	 */
	public function wrap_thankyou_block($block_content, $block)
	{
		if (!function_exists('is_order_received_page') || !is_order_received_page()) {
			return $block_content;
		}
		$name = isset($block['blockName']) ? (string) $block['blockName'] : '';
		if (0 !== strpos($name, 'woocommerce/order-confirmation')) {
			return $block_content;
		}

		// Each block's first element is its wrapper <div class="…">.
		// Inject the scope classes onto that wrapper (not on inner
		// markup — CSS scope must live on the outermost wrapper). The
		// leading \s* absorbs the whitespace block templates leave
		// between block comments and the rendered markup.
		$out = preg_replace_callback(
			'/^(\s*<div\b[^>]*\sclass=")([^"]*)("[^>]*>)/',
			static function ($m) {
				// Avoid double-injecting if another filter already added it.
				if (false !== strpos($m[2], 'routew-account--thankyou')) {
					return $m[0];
				}
				return $m[1] . $m[2] . ' routew-ui routew-account routew-account--thankyou' . $m[3];
			},
			$block_content,
			1
		);

		return null === $out ? $block_content : $out;
	}

	/**
	 * UI8 — emit a sticky top header for the current my-account sub-page.
	 *
	 * Echoes nothing on the dashboard root (the greeting hero + nav
	 * already fill that role). Renders a back link + page title on every
	 * other endpoint.
	 *
	 * @since 1.5.0
	 */
	public function render_subpage_header()
	{
		if (!is_user_logged_in()) {
			return;
		}
		// Skip the dashboard root — it has its own greeting hero.
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
			<nav class="routew-subpage-header" data-endpoint="<?php echo esc_attr($endpoint); ?>">
				<a class="routew-subpage-header__back" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" aria-label="<?php esc_attr_e('Back to My account', 'routemile-for-woocommerce'); ?>">
					<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.4 7.4 14 6l-6 6 6 6 1.4-1.4L10.8 12z"/></svg>
				</a>
				<span class="routew-subpage-header__title"><?php echo esc_html($title); ?></span>
			</nav>
			<?php
		}
	}

	/**
	 * UI8 — emit a profile card above the edit-account form.
	 * Shows the user's avatar, name, and email in a compact card.
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
			<span class="routew-avatar routew-avatar--md" aria-hidden="true"><?php echo esc_html($initial); ?></span>
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
	 * query var. Rendered inside the .routew-ui scope (see
	 * open_account_ui_scope), so the pill styling comes from the design
	 * system, not the theme.
	 *
	 * @since 1.5.0
	 */
	public function render_orders_filter_chips()
	{
		$endpoint = wc_get_endpoint_url('orders');
		// Read-only query var used to highlight the active chip (no state
		// change), so no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
		$chips = array(
			''          => __('All', 'routemile-for-woocommerce'),
			'pending'   => __('Pending', 'routemile-for-woocommerce'),
			'processing' => __('On the way', 'routemile-for-woocommerce'),
			'completed' => __('Delivered', 'routemile-for-woocommerce'),
			'cancelled' => __('Cancelled', 'routemile-for-woocommerce'),
		);
		?>
		<nav class="routew-orders-filter" aria-label="<?php esc_attr_e('Filter orders', 'routemile-for-woocommerce'); ?>">
			<?php foreach ($chips as $slug => $label) : ?>
				<?php
				$href = ('' === $slug) ? $endpoint : add_query_arg('status', $slug, $endpoint);
				$is_active = ($current === $slug);
				?>
				<a class="routew-orders-filter__chip<?php echo $is_active ? ' routew-orders-filter__chip--active' : ''; ?>"
				   href="<?php echo esc_url($href); ?>"
				   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
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
	 * @return array Modified body classes.
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
	 * Asset graph (v1.6.0 design system):
	 *   routew-ui            scoped Bootstrap re-skin + tokens + shared
	 *                        components + bundled font — the base handle
	 *   routew-checkout      checkout surface (is_checkout)
	 *   routew-tracking      tracking UI (order view / track shortcode)
	 *   routew-ui + routew-my-account (+ -dashboard) on account pages
	 *
	 * The old global frontend.css / unscoped bootstrap.min.css are gone —
	 * the theme can no longer collide with plugin styles.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts()
	{
		// Base design system — registered everywhere, enqueued per surface.
		wp_register_style(
			'routew-ui',
			ROUTEW_PLUGIN_URL . 'assets/css/routew-ui.css',
			array(),
			ROUTEW_VERSION
		);
		wp_register_style(
			'routew-tracking',
			ROUTEW_PLUGIN_URL . 'assets/css/tracking.css',
			array('routew-ui'),
			ROUTEW_VERSION
		);

		if (is_account_page()) {
			wp_enqueue_style('routew-ui');
			wp_enqueue_style(
				'routew-my-account',
				ROUTEW_PLUGIN_URL . 'assets/css/my-account.css',
				array('routew-ui'),
				ROUTEW_VERSION
			);
			wp_enqueue_style('routew-tracking');

			// Dashboard root: re-write the greeting in the visitor's LOCAL
			// time (the server-side render stays as a no-JS fallback).
			if (function_exists('is_wc_endpoint_url') && !is_wc_endpoint_url()) {
				wp_enqueue_script(
					'routew-greeting',
					ROUTEW_PLUGIN_URL . 'assets/js/routew-greeting.js',
					array(),
					ROUTEW_VERSION,
					true
				);
			}
		}

		// Track-order shortcode can live on any page — load the tracking
		// styles when the current post actually contains the shortcode.
		// The order-received page also needs the account surface CSS:
		// the thank-you scope wrapper reuses the account classes for the
		// overview strip / order details / notices markup.
		if ($this->is_order_received_context()) {
			wp_enqueue_style('routew-ui');
			wp_enqueue_style(
				'routew-my-account',
				ROUTEW_PLUGIN_URL . 'assets/css/my-account.css',
				array('routew-ui'),
				ROUTEW_VERSION
			);
			wp_enqueue_style('routew-tracking');
			return;
		}

		if (!$this->is_tracking_context()) {
			return;
		}
		wp_enqueue_style('routew-ui');
		wp_enqueue_style('routew-tracking');
	}

	/**
	 * Is the current request the classic checkout order-received page?
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private function is_order_received_context()
	{
		return function_exists('is_checkout')
			&& is_checkout()
			&& function_exists('is_order_received_page')
			&& is_order_received_page();
	}

	/**
	 * Redirect WC to load our own my-account templates.
	 *
	 * Called by the `woocommerce_locate_template` filter (registered in
	 * __construct). WC asks "where is the template named X?" — we answer
	 * "use ours at `templates/woocommerce/...`" if it exists. Falls back
	 * to whatever WC/the theme returned so other plugins can still
	 * override our overrides.
	 *
	 * Two templates are overridden:
	 *  - `myaccount/dashboard.php` — strips WC's greeting + description
	 *    paragraphs (the greeting hero widget replaces both).
	 *  - `myaccount/my-address.php` — uses our own `.routew-address-grid`
	 *    markup instead of WC's `.col2-set > .u-column1/.u-column2`
	 *    float layout (which fights every theme's own col2-set CSS).
	 *
	 * @param string $template      Resolved template path WC will use.
	 * @param string $template_name Template slug, e.g. `myaccount/dashboard.php`.
	 * @param string $template_path WC's templates directory path.
	 * @return string Possibly-rewritten template path.
	 * @since 1.6.0
	 */
	public function override_wc_templates($template, $template_name, $template_path)
	{
		// $template_name is relative to the theme OR WC's plugin template
		// dir. We match the bare slug.
		$overrides = array(
			'myaccount/dashboard.php'  => 'templates/woocommerce/myaccount/dashboard.php',
			'myaccount/my-address.php' => 'templates/woocommerce/myaccount/my-address.php',
		);

		if (isset($overrides[$template_name])) {
			$plugin_template = ROUTEW_PLUGIN_DIR . $overrides[$template_name];
			if (file_exists($plugin_template)) {
				return $plugin_template;
			}
		}

		return $template;
	}

	/**
	 * Is the current request a tracking context (track shortcode page,
	 * order-received, or an order view)?
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	private function is_tracking_context()	{
		if (is_account_page()) {
			return true; // order view under /my-account/orders/view-order/…
		}
		if (function_exists('is_checkout') && is_checkout() && function_exists('is_order_received_page') && is_order_received_page()) {
			return true;
		}
		$post = get_post();
		if ($post instanceof WP_Post) {
			return has_shortcode($post->post_content, 'routew_track_order');
		}
		return false;
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
	 * Output the tracking UI (status stepper + rider card).
	 * Reusable for both My Account order view and track-order shortcode.
	 *
	 * Zomato-style live tracking: a hero headline that speaks delivery
	 * ("Out for delivery"), a vertical stepper with a progress rail and
	 * REAL per-step timestamps, and a rider card with call / message
	 * actions.
	 *
	 * Real-world semantics (round 8): the delivery timeline is
	 * SEQUENTIAL — Order placed → In the kitchen → Picked up →
	 * Delivered. "Rider assigned" is NOT a timeline step: an order sits
	 * in the kitchen AND has a rider at the same time until it is picked
	 * up, so assignment is surfaced through the hero pill + the rider
	 * card (with its recorded assignment time) instead of a stepper row
	 * that would falsely mark the kitchen step done.
	 *
	 * Every WC + routew-* status carries its own pill, hero copy and
	 * stepper position (see $states), so the tracker always reports the
	 * order's true state. Step times come from the
	 * `_routew_status_at_{status}` metas stamped by ROUTEW_Order_Statuses
	 * (at creation AND on every transition).
	 *
	 * @param WC_Order $order Order object.
	 * @since 1.1.0
	 * @since 1.5.0 Timestamps per step, rider card with Call / Chat, hero header.
	 * @since 1.6.0 Design-system restyle; full status coverage + recorded timestamps.
	 * @since 1.6.0 4-step sequential timeline; assigned = still in the kitchen;
	 *               current step shows its start time; rider card shows assignment time.
	 */
	private function output_tracking_ui($order)
	{
		$current_status = $order->get_status();

		/*
		 * Full delivery-state map. Keys are raw WC status slugs.
		 *
		 * step → which of the 4 timeline steps is current (0-3); -1 means
		 *        the order ended abnormally (no stepper rendered).
		 * pill → routew-pill--{variant} design token.
		 * pill_label → the admin's stage name (ROUTEW_Stage_Labels) — a
		 *        rename like "Out for delivery" shows here instantly.
		 * hero / sub → sentence copy, kept as fixed translatable strings.
		 */
		$routew_stage_pill = array();
		foreach (ROUTEW_Stage_Labels::stages() as $routew_stage_key => $routew_stage) {
			foreach ($routew_stage['statuses'] as $routew_stage_status) {
				$routew_stage_pill[$routew_stage_status] = array($routew_stage['colour'], $routew_stage['label']);
			}
		}

		$states = array(
			'pending' => array(
				'step' => 0,
				'pill' => isset($routew_stage_pill['pending']) ? $routew_stage_pill['pending'][0] : 'placed',
				'pill_label' => isset($routew_stage_pill['pending']) ? $routew_stage_pill['pending'][1] : __('Order placed', 'routemile-for-woocommerce'),
				'hero' => __('We received your order', 'routemile-for-woocommerce'),
				'sub' => __('Waiting for confirmation.', 'routemile-for-woocommerce'),
			),
			'on-hold' => array(
				'step' => 0,
				'pill' => 'placed',
				'pill_label' => __('On hold', 'routemile-for-woocommerce'),
				'hero' => __('Your order is on hold', 'routemile-for-woocommerce'),
				'sub' => __('We will start preparing once the payment is confirmed.', 'routemile-for-woocommerce'),
			),
			'processing' => array(
				'step' => 1,
				'pill' => isset($routew_stage_pill['processing']) ? $routew_stage_pill['processing'][0] : 'preparing',
				'pill_label' => isset($routew_stage_pill['processing']) ? $routew_stage_pill['processing'][1] : __('Confirmed', 'routemile-for-woocommerce'),
				'hero' => __('Preparing your order', 'routemile-for-woocommerce'),
				'sub' => __('Your order is confirmed.', 'routemile-for-woocommerce'),
			),
			'routew-in-kitchen' => array(
				'step' => 1,
				'pill' => isset($routew_stage_pill['routew-in-kitchen']) ? $routew_stage_pill['routew-in-kitchen'][0] : 'preparing',
				'pill_label' => isset($routew_stage_pill['routew-in-kitchen']) ? $routew_stage_pill['routew-in-kitchen'][1] : __('In the kitchen', 'routemile-for-woocommerce'),
				'hero' => __('Preparing your order', 'routemile-for-woocommerce'),
				'sub' => __('Our chefs are preparing your food.', 'routemile-for-woocommerce'),
			),
			'routew-assigned' => array(
				// Still step 1 — the food is in the kitchen; the rider is
				// simply linked already and will pick it up when ready.
				'step' => 1,
				'pill' => isset($routew_stage_pill['routew-assigned']) ? $routew_stage_pill['routew-assigned'][0] : 'assigned',
				'pill_label' => isset($routew_stage_pill['routew-assigned']) ? $routew_stage_pill['routew-assigned'][1] : __('Rider assigned', 'routemile-for-woocommerce'),
				'hero' => __('Your rider is on it', 'routemile-for-woocommerce'),
				'sub' => __('Your order is still being prepared — your rider will pick it up as soon as it is ready.', 'routemile-for-woocommerce'),
			),
			'routew-picked-up' => array(
				'step' => 2,
				'pill' => isset($routew_stage_pill['routew-picked-up']) ? $routew_stage_pill['routew-picked-up'][0] : 'transit',
				'pill_label' => isset($routew_stage_pill['routew-picked-up']) ? $routew_stage_pill['routew-picked-up'][1] : __('On the way', 'routemile-for-woocommerce'),
				'hero' => __('Out for delivery', 'routemile-for-woocommerce'),
				'sub' => __('Your rider is heading your way.', 'routemile-for-woocommerce'),
			),
			'completed' => array(
				'step' => 3,
				'pill' => isset($routew_stage_pill['completed']) ? $routew_stage_pill['completed'][0] : 'delivered',
				'pill_label' => isset($routew_stage_pill['completed']) ? $routew_stage_pill['completed'][1] : __('Delivered', 'routemile-for-woocommerce'),
				'hero' => __('Delivered. Enjoy!', 'routemile-for-woocommerce'),
				'sub' => '',
			),
			'cancelled' => array(
				'step' => -1,
				'pill' => 'cancelled',
				'pill_label' => __('Cancelled', 'routemile-for-woocommerce'),
				'hero' => __('Order cancelled', 'routemile-for-woocommerce'),
				'sub' => '',
				'sub_date' => __('Cancelled', 'routemile-for-woocommerce'),
			),
			'failed' => array(
				'step' => -1,
				'pill' => 'cancelled',
				'pill_label' => __('Payment failed', 'routemile-for-woocommerce'),
				'hero' => __('Payment failed', 'routemile-for-woocommerce'),
				'sub' => __('This order could not be paid for and was not prepared.', 'routemile-for-woocommerce'),
				'sub_date' => __('Failed', 'routemile-for-woocommerce'),
			),
			'refunded' => array(
				'step' => -1,
				'pill' => 'cancelled',
				'pill_label' => __('Refunded', 'routemile-for-woocommerce'),
				'hero' => __('Order refunded', 'routemile-for-woocommerce'),
				'sub' => '',
				'sub_date' => __('Refunded', 'routemile-for-woocommerce'),
			),
		);

		// Unknown / third-party statuses still get an accurate, neutral state.
		$state = isset($states[$current_status])
			? $states[$current_status]
			: array(
				'step' => 0,
				'pill' => 'placed',
				'pill_label' => wc_get_order_status_name($current_status),
				'hero' => wc_get_order_status_name($current_status),
				'sub' => '',
			);

		$date_format = get_option('date_format') . ' ' . get_option('time_format');
		$date_placed = $order->get_date_created();
		$date_completed = $order->get_date_completed();
		$date_modified = $order->get_date_modified();

		// Hero context line: status copy first, else "Placed …". For
		// ended orders, prefer the recorded end time.
		$hero_sub = $state['sub'];
		if ('' === $hero_sub && $date_placed) {
			/* translators: %s: order placed datetime */
			$hero_sub = sprintf(__('Placed %s', 'routemile-for-woocommerce'), $date_placed->date_i18n($date_format));
		}
		if (-1 === $state['step'] && !empty($state['sub_date'])) {
			$ended_at = $this->step_datetime($order, $current_status, $date_modified);
			if ($ended_at) {
				$hero_sub = $state['sub_date'] . ' ' . $ended_at->date_i18n($date_format);
			}
		}

		// Rider (delivery agent) details — shown once a rider is linked
		// and the order reached (or passed) the assignment point. The
		// rider may already be linked while the food is still in the
		// kitchen — that is exactly the point of the assigned state.
		$delivery_boy_id = (int) $order->get_meta('_routew_delivery_boy_id', true);
		$delivery_boy = $delivery_boy_id ? get_user_by('id', $delivery_boy_id) : null;
		if ($delivery_boy && !$delivery_boy->exists()) {
			$delivery_boy = null;
		}
		// Agent mobile: dedicated profile field first, WC billing phone as
		// a legacy fallback. This is the number the customer dials.
		$delivery_phone = $delivery_boy ? trim((string) get_user_meta($delivery_boy_id, 'routew_agent_phone', true)) : '';
		if ('' === $delivery_phone && $delivery_boy) {
			$delivery_phone = trim((string) get_user_meta($delivery_boy_id, 'billing_phone', true));
		}
		// When the rider was assigned — shown on the rider card.
		$assigned_at = $this->step_datetime($order, 'routew-assigned');

		/*
		 * The sequential delivery timeline (4 steps). Times prefer the
		 * recorded `_routew_status_at_*` metas; WC's own dates cover
		 * placed / completed; date_modified is a last-resort proxy ONLY
		 * for the step the order currently sits on. The kitchen step
		 * also accepts the `processing` stamp — orders that go straight
		 * from processing to assigned never enter routew-in-kitchen,
		 * but their food was being prepared from the processing moment.
		 *
		 * Labels + icons come from ROUTEW_Stage_Labels so an admin
		 * rename ("Picked order", "Out for delivery") and icon choice
		 * mirror onto the customer timeline.
		 */
		$routew_step_stage = array(
			ROUTEW_Stage_Labels::get('placed'),
			ROUTEW_Stage_Labels::get('kitchen'),
			ROUTEW_Stage_Labels::get('picked_up'),
			ROUTEW_Stage_Labels::get('delivered'),
		);
		$kitchen_fallback = in_array($current_status, array('processing', 'routew-in-kitchen'), true) ? $date_modified : null;
		$steps = array(
			array(
				'label' => $routew_step_stage[0]['label'],
				'icon' => $routew_step_stage[0]['icon'],
				'time' => $date_placed,
			),
			array(
				'label' => $routew_step_stage[1]['label'],
				'icon' => $routew_step_stage[1]['icon'],
				'time' => $this->step_datetime($order, 'routew-in-kitchen', $this->step_datetime($order, 'processing', $kitchen_fallback)),
			),
			array(
				'label' => $routew_step_stage[2]['label'],
				'icon' => $routew_step_stage[2]['icon'],
				'time' => $this->step_datetime($order, 'routew-picked-up', ('routew-picked-up' === $current_status ? $date_modified : null)),
			),
			array(
				'label' => $routew_step_stage[3]['label'],
				'icon' => $routew_step_stage[3]['icon'],
				'time' => $date_completed ? $date_completed : $this->step_datetime($order, 'completed', ('completed' === $current_status ? $date_modified : null)),
			),
		);

		$step_icons = ROUTEW_Stage_Labels::icon_palette();
		?>
		<section class="routew-order-tracking routew-order-tracking--myaccount">
			<header class="routew-tracking-hero">
				<div class="routew-tracking-hero__meta">
					<span class="routew-tracking-hero__eyebrow"><?php esc_html_e('Delivery status', 'routemile-for-woocommerce'); ?></span>
					<h2 class="routew-tracking-hero__title"><?php echo esc_html($state['hero']); ?></h2>
					<?php if ('' !== $hero_sub) : ?>
						<p class="routew-tracking-hero__sub"><?php echo esc_html($hero_sub); ?></p>
					<?php endif; ?>
				</div>
				<span class="routew-pill routew-pill--lg routew-pill--<?php echo esc_attr($state['pill']); ?>">
					<?php echo esc_html($state['pill_label']); ?>
				</span>
			</header>

			<?php if (-1 !== $state['step']) : ?>
				<ol class="routew-status-stepper">
					<?php foreach ($steps as $index => $step) :
						$is_delivered_final = ('completed' === $current_status && (count($steps) - 1) === $index);
						$is_completed = ($state['step'] > $index) || $is_delivered_final;
						$is_current = ($state['step'] === $index) && !$is_delivered_final;

						$class = 'routew-status-step';
						if ($is_completed || $is_delivered_final) {
							$class .= ' routew-status-step--completed';
						}
						if ($is_current) {
							$class .= ' routew-status-step--current';
						}
						if ($is_delivered_final) {
							$class .= ' routew-status-step--delivered';
						}
						$step_ts = $step['time'];
						?>
						<li class="<?php echo esc_attr($class); ?>">
							<span class="routew-status-step__dot">
								<?php if ($is_completed || $is_delivered_final) : ?>
									<svg class="routew-status-step__icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>
								<?php else : ?>
									<svg class="routew-status-step__icon routew-status-step__icon--stroke" viewBox="0 0 24 24" aria-hidden="true"><path d="<?php echo esc_attr(isset($step_icons[$step['icon']]) ? $step_icons[$step['icon']] : ''); ?>"></path></svg>
								<?php endif; ?>
							</span>
							<div class="routew-status-step__body">
								<span class="routew-status-step__label"><?php echo esc_html($step['label']); ?></span>
								<?php if ($step_ts) : ?>
									<?php
									// A current step WITH a recorded start time shows
									// when it began (e.g. "In the kitchen — Started
									// 2:50 PM" under the live pulse); without one it
									// falls through to the "In progress" label.
									?>
									<span class="routew-status-step__time<?php echo $is_current ? ' routew-status-step__time--live' : ''; ?>">
										<?php
										if ($is_current) {
											/* translators: %s: step start datetime */
											printf(esc_html__('Started %s', 'routemile-for-woocommerce'), esc_html($step_ts->date_i18n($date_format)));
										} else {
											echo esc_html($step_ts->date_i18n($date_format));
										}
										?>
									</span>
								<?php elseif ($is_current) : ?>
									<span class="routew-status-step__time routew-status-step__time--live">
										<?php esc_html_e('In progress', 'routemile-for-woocommerce'); ?>
									</span>
								<?php elseif ($is_completed) : ?>
									<?php
									// A finished step with no recorded time (orders
									// created before this data was tracked): an
									// honest dash — never "Pending".
									?>
									<span class="routew-status-step__time routew-status-step__time--pending" aria-hidden="true">&mdash;</span>
								<?php else : ?>
									<span class="routew-status-step__time routew-status-step__time--pending">
										<?php esc_html_e('Pending', 'routemile-for-woocommerce'); ?>
									</span>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>

				<?php if ($delivery_boy && $state['step'] >= 1 && in_array($current_status, array('routew-assigned', 'routew-picked-up', 'completed'), true)) : ?>
					<div class="routew-rider-card">
						<h3 class="routew-rider-card__title"><?php esc_html_e('Your delivery rider', 'routemile-for-woocommerce'); ?></h3>
						<div class="routew-rider-card__body">
							<span class="routew-avatar routew-avatar--md routew-rider-card__avatar" aria-hidden="true">
								<?php echo esc_html(mb_substr($delivery_boy->display_name, 0, 1)); ?>
							</span>
							<div class="routew-rider-card__info">
								<span class="routew-rider-card__name"><?php echo esc_html($delivery_boy->display_name); ?></span>
								<span class="routew-rider-card__role">
									<svg viewBox="0 0 24 24" aria-hidden="true" class="routew-rider-card__star">
										<path d="M12 2 9.2 8.6 2 9.2l5.5 4.8L5.8 22 12 18.3 18.2 22l-1.7-8 5.5-4.8-7.2-.6Z"/>
									</svg>
									<?php esc_html_e('Verified delivery partner', 'routemile-for-woocommerce'); ?>
								</span>
								<?php if ($assigned_at) : ?>
									<span class="routew-rider-card__assigned">
										<?php
										/* translators: %s: assignment datetime */
										printf(esc_html__('Assigned %s', 'routemile-for-woocommerce'), esc_html($assigned_at->date_i18n($date_format)));
										?>
									</span>
								<?php endif; ?>
							</div>
							<?php if ($delivery_phone): ?>
								<div class="routew-rider-card__actions">
									<a href="tel:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $delivery_phone)); ?>" class="routew-rider-card__btn routew-rider-card__btn--call" aria-label="<?php esc_attr_e('Call rider', 'routemile-for-woocommerce'); ?>">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.2 1.2.4 2.6.6 3.9.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.4c.6 0 1 .4 1 1 0 1.3.2 2.7.6 3.9.1.4 0 .8-.2 1.1l-2.2 2.8Z"/></svg>
										<?php esc_html_e('Call', 'routemile-for-woocommerce'); ?>
									</a>
									<a href="sms:<?php echo esc_attr(preg_replace('/[^0-9+]/', '', $delivery_phone)); ?>" class="routew-rider-card__btn routew-rider-card__btn--msg" aria-label="<?php esc_attr_e('Message rider', 'routemile-for-woocommerce'); ?>">
										<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2Zm0 14H6l-2 2V4h16Z"/></svg>
										<?php esc_html_e('Message', 'routemile-for-woocommerce'); ?>
									</a>
								</div>
							<?php else: ?>
								<div class="routew-rider-card__no-phone">
									<?php esc_html_e('Contact via store', 'routemile-for-woocommerce'); ?>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php elseif (!$delivery_boy && in_array($current_status, array('routew-assigned', 'routew-picked-up'), true)) : ?>
					<div class="routew-rider-note">
						<?php esc_html_e('Your rider details will appear here as soon as one is assigned.', 'routemile-for-woocommerce'); ?>
					</div>
				<?php elseif (!$delivery_boy && 'completed' === $current_status) : ?>
					<div class="routew-rider-note">
						<?php esc_html_e('Delivered by our store team.', 'routemile-for-woocommerce'); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Resolve the localised datetime an order entered a given status.
	 *
	 * Reads the `_routew_status_at_{status}` meta stamped by
	 * ROUTEW_Order_Statuses::record_status_timestamp() (a GMT unix
	 * timestamp) and converts it to a WC_DateTime in the site timezone
	 * so callers can use the usual date_i18n() formatting.
	 *
	 * @param WC_Order        $order    Order object.
	 * @param string          $status   Raw status slug (e.g. "routew-picked-up").
	 * @param WC_DateTime|null $fallback Value returned when no meta exists.
	 * @return WC_DateTime|null
	 * @since 1.6.0
	 */
	private function step_datetime($order, $status, $fallback = null)
	{
		$ts = (int) $order->get_meta('_routew_status_at_' . $status, true);
		if ($ts <= 0) {
			return $fallback;
		}

		try {
			$dt = new WC_DateTime('@' . $ts, new DateTimeZone('UTC'));
			$dt->setTimezone(new DateTimeZone(wc_timezone_string()));
			return $dt;
		} catch (Exception $e) {
			return $fallback;
		}
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
		echo '<div class="routew-ui routew-track-order-page">';
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
	 * @since 1.0.0
	 * @since 1.6.0 Design-system card form.
	 */
	private function track_order_form()
	{
		?>
		<div class="routew-track-form card">
			<div class="card-body routew-track-form__body">
				<h2 class="routew-track-form__title"><?php esc_html_e('Track your order', 'routemile-for-woocommerce'); ?></h2>
				<p class="routew-track-form__sub"><?php esc_html_e('Enter your order details to see its live delivery status.', 'routemile-for-woocommerce'); ?></p>
				<form action="" method="post" class="routew-track-form__fields">
					<?php wp_nonce_field('routew_track_order', 'routew_track_order_nonce'); ?>
					<div class="routew-track-form__row">
						<label for="routew_order_id"><?php esc_html_e('Order ID', 'routemile-for-woocommerce'); ?></label>
						<input type="text" name="routew_order_id" id="routew_order_id" class="form-control"
							placeholder="<?php esc_attr_e('e.g. 123', 'routemile-for-woocommerce'); ?>" required>
					</div>
					<div class="routew-track-form__row">
						<label for="routew_billing_email"><?php esc_html_e('Billing email', 'routemile-for-woocommerce'); ?></label>
						<input type="email" name="routew_billing_email" id="routew_billing_email" class="form-control"
							placeholder="<?php esc_attr_e('you@example.com', 'routemile-for-woocommerce'); ?>" required>
					</div>
					<button type="submit" class="btn routew-btn-brand routew-track-form__submit">
						<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.6 4 2.3-1 1.7-5-2.9V6h2v6.6Z"/></svg>
						<?php esc_html_e('Track order', 'routemile-for-woocommerce'); ?>
					</button>
				</form>
			</div>
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

		echo '<div class="routew-order-status-wrapper">';
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
