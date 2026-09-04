<?php
if (!defined('ABSPATH')) {
	exit;
}
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
// Delivery-agent order filtering: meta_key/meta_value/meta_query lookups
// against `_routew_delivery_*` are the documented way to filter WC orders by
// per-order delivery state. Inherent to the rider dashboard read path.
// Re-enabled at EOF.

/**
 * Manages the delivery boy view.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Delivery_Boy_View
{

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
public function __construct()
    {
        // login_redirect signature: (redirect_to, requested_redirect_to, user).
        // We previously bound only 2 args, which works but throws a
        // deprecated-notice warning in WP 6.2+. Use 3 + the user check
        // (1.2.16).
        add_filter('woocommerce_login_redirect', array($this, 'login_redirect_woocommerce'), 10, 2);
        add_filter('login_redirect', array($this, 'login_redirect_wp'), 10, 3);
        add_action('admin_post_routew_mark_picked_up', array($this, 'mark_picked_up'));
        add_action('admin_post_routew_mark_delivered', array($this, 'mark_delivered'));
        add_action('wp_ajax_routew_update_delivery_status', array($this, 'ajax_update_delivery_status'));
        add_action('wp_ajax_routew_confirm_cash_collected', array($this, 'ajax_confirm_cash_collected'));
        add_action('wp_ajax_routew_agent_dashboard_state', array($this, 'ajax_dashboard_state'));
        add_action('admin_post_routew_settle_agent_cash', array($this, 'handle_cash_settlement'));
        add_action('admin_post_routew_review_settlement', array($this, 'handle_settlement_review'));
        add_action('init', array($this, 'serve_pwa_endpoints'), 1);
    }

    /**
     * Serve the installable-app assets for the agent dashboard PWA.
     *
     * Two public, static-content endpoints on `init` (before any output):
     * `?routew_agent_manifest=1` returns the web-app manifest and
     * `?routew_agent_sw=1` streams the bundled service worker with a
     * `Service-Worker-Allowed: /` header so it can control
     * `/delivery-dashboard/` even though the script is served from the
     * home URL. No user input is reflected; nothing here is order data.
     *
     * @since 1.4.0
     */
    public function serve_pwa_endpoints()
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- static assets, no state.
        if (!isset($_GET['routew_agent_manifest']) && !isset($_GET['routew_agent_sw'])) {
            return;
        }

        if (isset($_GET['routew_agent_manifest'])) {
            $brand = ROUTEW_Brand_Color::pwa_color();
            $manifest = array(
                'name' => __('RouteMile Agent', 'routemile-for-woocommerce'),
                'short_name' => __('FX Agent', 'routemile-for-woocommerce'),
                'description' => __('Delivery agent dashboard for RouteMile orders.', 'routemile-for-woocommerce'),
                'start_url' => home_url('/delivery-dashboard/'),
                'scope' => home_url('/'),
                'display' => 'standalone',
                'orientation' => 'portrait',
                'background_color' => '#F5F5F5',
                'theme_color' => $brand,
                'icons' => array(
                    array(
                        'src' => ROUTEW_PLUGIN_URL . 'assets/img/agent-icon.svg',
                        'sizes' => 'any',
                        'type' => 'image/svg+xml',
                        'purpose' => 'any',
                    ),
                    array(
                        'src' => ROUTEW_PLUGIN_URL . 'assets/img/agent-icon-maskable.svg',
                        'sizes' => 'any',
                        'type' => 'image/svg+xml',
                        'purpose' => 'maskable',
                    ),
                ),
            );

            header('Content-Type: application/manifest+json; charset=utf-8');
            echo wp_json_encode($manifest);
            exit;
        }

        $sw_path = ROUTEW_PLUGIN_DIR . 'assets/js/routew-agent-sw.js';
        if (!is_readable($sw_path)) {
            status_header(404);
            exit;
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        readfile($sw_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a static bundled asset.
        exit;
    }

    /**
     * Lightweight dashboard-state heartbeat for the agent PWA.
     *
     * Returns tab counts, the cash-on-delivery collection total, and a
     * change signature. The frontend polls this and reloads (with a push
     * notification) whenever the signature changes, so new assignments and
     * status updates appear without the agent pulling to refresh.
     *
     * @since 1.4.0
     */
    public function ajax_dashboard_state()
    {
        check_ajax_referer('routew_agent_state', 'nonce');

        if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
            wp_send_json_error(array('message' => __('Unauthorized access.', 'routemile-for-woocommerce')), 403);
        }

        $agent_id = get_current_user_id();
        $data = self::build_dashboard_state($agent_id);
        wp_send_json_success($data);
    }

    /**
     * Shared state builder used by both the heartbeat AJAX and the
     * dashboard template (the COD summary chip renders the same numbers).
     *
     * Covers the agent's full working picture: active orders (assigned +
     * picked up), delivered history, and today's performance numbers.
     *
     * @param int $agent_id User ID of the delivery agent.
     * @return array{counts: array, cod: array, today: array, signature: string}
     * @since 1.4.0
     */
    public static function build_dashboard_state($agent_id)
    {
        $args = array(
            'limit' => 50,
            'meta_key' => '_routew_delivery_boy_id',
            'meta_value' => $agent_id,
        );

        $new_orders = wc_get_orders(array_merge($args, array('status' => 'routew-assigned')));
        $active_orders = wc_get_orders(array_merge($args, array('status' => 'routew-picked-up')));
        $delivered_orders = wc_get_orders(array_merge($args, array('status' => array('completed'), 'orderby' => 'date', 'order' => 'DESC')));

        $cod_total = 0.0;
        $cod_count = 0;
        $sig_parts = array();

        foreach (array_merge($new_orders, $active_orders) as $order) {
            $modified = $order->get_date_modified();
            $sig_parts[] = $order->get_id() . ':' . $order->get_status() . ':' . ($modified ? $modified->format('U') : '0') . ':' . ((int) $order->get_meta('_routew_cash_collected', true) > 0 ? '1' : '0');
            // The agent has already physically collected the cash for an
            // order with `_routew_cash_collected > 0` — that money is on
            // THEIR hands, but it's no longer "to collect". Exclude it from
            // the live summary so the banner stops saying "Cash to collect"
            // once the agent has tapped Cash Collected. The "you are
            // holding ৳X" banner is computed separately by
            // ROUTEW_Agent_Cash (from completed orders after the last
            // settlement) and updates when the order is delivered.
            if ('cod' === $order->get_payment_method() && (int) $order->get_meta('_routew_cash_collected', true) <= 0) {
                $cod_count++;
                $cod_total += (float) $order->get_total();
            }
        }

        $today = array(
            'delivered' => 0,
            'active' => count($new_orders) + count($active_orders),
            'collected' => 0.0,
        );

        foreach ($delivered_orders as $order) {
            $modified = $order->get_date_modified();
            $sig_parts[] = $order->get_id() . ':completed:' . ($modified ? $modified->format('U') : '0');
        }

        $day_start = strtotime('today', (int) current_time('timestamp'));
        foreach ($delivered_orders as $order) {
            $completed = $order->get_date_completed();
            if ($completed && $completed->getTimestamp() >= $day_start) {
                $today['delivered']++;
                if ('cod' === $order->get_payment_method()) {
                    $today['collected'] += (float) $order->get_total();
                }
            }
        }

        return array(
            'counts' => array(
                'new' => count($new_orders),
                'in_progress' => count($active_orders),
                'delivered' => count($delivered_orders),
            ),
            'cod' => array(
                'count' => $cod_count,
                'total' => $cod_total,
            ),
            'today' => $today,
            'signature' => md5(implode('|', $sig_parts)),
        );
    }

	/**
	 * Handle AJAX delivery status updates.
	 *
	 * @since 1.0.0
	 */
	public function ajax_update_delivery_status()
	{
		check_ajax_referer('routew_delivery_action', 'nonce');

		if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
			wp_send_json_error(array('message' => __('Unauthorized access.', 'routemile-for-woocommerce')));
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';

		if (!$order_id || !$status) {
			wp_send_json_error(array('message' => __('Missing order ID or status.', 'routemile-for-woocommerce')));
		}

        // Whitelist allowed status transitions for delivery agents.
// `completed` requires the order to already be in routew-picked-up first —
// an agent cannot jump straight from assigned to delivered (1.2.16).
$allowed_statuses = array('routew-picked-up', 'completed');
if (!in_array($status, $allowed_statuses, true)) {
    wp_send_json_error(array('message' => __('Invalid status transition.', 'routemile-for-woocommerce')), 400);
}

        // Load the order BEFORE the picked-up guard reads its status — the
        // guard previously evaluated $order->get_status() on a null $order,
        // fataling (HTTP 500, "Connection error") every Mark Delivered.
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(array('message' => __('Invalid order.', 'routemile-for-woocommerce')));
        }

if ('completed' === $status && 'routew-picked-up' !== $order->get_status()) {
    wp_send_json_error(array('message' => __('You must mark the order as picked up before marking it delivered.', 'routemile-for-woocommerce')), 400);
}

// COD gate: the agent must confirm the cash was physically collected
// before the order can be marked delivered — otherwise the settlement
// ledger counts money the agent does not have yet.
if ('completed' === $status && 'cod' === $order->get_payment_method() && !self::is_cash_collected($order)) {
    wp_send_json_error(array('message' => __('This is a cash-on-delivery order. Please confirm you collected the cash before marking it delivered.', 'routemile-for-woocommerce')), 400);
}

// AUDIT-FIX 1.6.1: refuse to push any order into `completed` via the
// agent path when payment has not been recorded. The rider can still
// confirm cash collection on COD orders (which itself sets date_paid),
// and gateway payments set date_paid themselves — this gate only
// blocks unpaid orders.
if ('completed' === $status) {
    $gate = ROUTEW_Order_Lifecycle::can_complete_order($order);
    if (is_wp_error($gate)) {
        wp_send_json_error(array('message' => $gate->get_error_message()), 403);
    }
}

		$assigned_id = $order->get_meta('_routew_delivery_boy_id', true);

		if (empty($assigned_id) || (int) $assigned_id !== (int) get_current_user_id()) {
			wp_send_json_error(array('message' => __('You are not assigned to this order.', 'routemile-for-woocommerce')));
		}

		// Update status
		if (is_callable(array($order, 'update_status'))) {
			$note = ('routew-picked-up' === $status) ? __('Order picked up by delivery agent (AJAX).', 'routemile-for-woocommerce') : __('Order delivered by delivery agent (AJAX).', 'routemile-for-woocommerce');
			if ($order->update_status($status, $note)) {
				wp_send_json_success($this->build_action_response($order));
			} else {
				wp_send_json_error(array('message' => __('Failed to update order status.', 'routemile-for-woocommerce')));
			}
		} else {
			wp_send_json_error(array('message' => __('Order update not callable.', 'routemile-for-woocommerce')));
		}
	}

	/**
	 * Build the rich success payload for an agent action.
	 *
	 * The agent PWA updates in place — no page reload — so every action
	 * response carries everything the client needs to move the card to
	 * its destination tab, refresh the tabbar counters and the header
	 * stats, and toast the result:
	 *   - `card`:   fresh order-card HTML rendered for the order's NEW
	 *               status (see includes/agent-template-helpers.php)
	 *   - `tab`:    which tab the order now lives in (new-orders |
	 *               in-progress | delivered)
	 *   - `counts`: tab counts + today's performance + COD summary
	 *   - `cash`:   the agent's unsettled/pending hand-over state (the
	 *               "cash collected" action changes it)
	 *
	 * @param WC_Order $order The order AFTER the status change.
	 * @return array Success payload for wp_send_json_success().
	 * @since 1.6.0
	 */
	private function build_action_response($order)
	{
		require_once ROUTEW_PLUGIN_DIR . 'includes/agent-template-helpers.php';

		$status = $order->get_status();
		$tab = 'new-orders';
		if ('routew-picked-up' === $status) {
			$tab = 'in-progress';
		} elseif (in_array($status, array('completed', 'cancelled', 'refunded', 'failed'), true)) {
			$tab = 'delivered';
		}

		$state = self::build_dashboard_state(get_current_user_id());

		// Cash hand-over summary (drives the "you are holding ৳X"
		// banner + the "settled/pending" state). Computing this every
		// action means the agent sees the unsettled total climb in
		// place the instant they tap Mark Delivered, without a reload.
		if (!class_exists('ROUTEW_Agent_Cash', false)) {
			require_once ROUTEW_PLUGIN_DIR . 'includes/class-routew-agent-cash.php';
		}
		$cash = ROUTEW_Agent_Cash::get_agent_cash_summary(get_current_user_id());

		return array(
			'message' => __('Status updated successfully.', 'routemile-for-woocommerce'),
			'tab' => $tab,
			'card' => routew_render_order_card($order, array('history' => ('delivered' === $tab))),
			'counts' => $state['counts'],
			'today' => $state['today'],
			'cod' => $state['cod'],
			'cash' => array(
				'unsettled' => (float) $cash['unsettled'],
				'unsettled_formatted' => wp_strip_all_tags(wc_price((float) $cash['unsettled'], array('currency' => get_woocommerce_currency()))),
				'has_pending' => !empty($cash['pending']),
				'has_last_accepted' => !empty($cash['last_accepted']),
			),
			'signature' => $state['signature'],
		);
	}

/**
 * Redirect delivery boys to their dashboard after a wp-login.php login
 * (3-param signature: redirect_to, requested_redirect_to, user — 1.2.16).
 *
 * @param string          $redirect_to
 * @param string          $requested_redirect_to
 * @param WP_User|WP_Error $user
 * @return string
 */
public function login_redirect_wp($redirect_to, $requested_redirect_to, $user)
{
    if (!($user instanceof \WP_User) || !isset($user->roles) || !is_array($user->roles)) {
        return $redirect_to;
    }
    if (in_array('delivery_boy', $user->roles, true)) {
        return home_url('/delivery-dashboard/');
    }
    return $redirect_to;
}

/**
 * WC My-Account login redirect — keep the historical 2-param signature so
 * we don't change behaviour for any other plugin hooking into it.
 *
 * @param string  $redirect
 * @param WP_User $user
 * @return string
 * @since 1.0.0
 */
public function login_redirect_woocommerce($redirect, $user)
{
    if (isset($user->roles) && is_array($user->roles) && in_array('delivery_boy', $user->roles, true)) {
        $redirect = home_url('/delivery-dashboard/');
    }
    return $redirect;
}


	/**
	 * Was the cash for a COD order confirmed as collected?
	 *
	 * The meta value is the GMT unix timestamp of the confirmation (a
	 * positive int), set by confirm_cash_collected(); it doubles as the
	 * flag and the audit time.
	 *
	 * @param WC_Order $order Order object.
	 * @return bool
	 * @since 1.6.0
	 */
	public static function is_cash_collected($order)
	{
		return ((int) $order->get_meta('_routew_cash_collected', true)) > 0;
	}

	/**
	 * AJAX: the delivery agent confirms the COD cash was collected.
	 *
	 * Records `_routew_cash_collected` (timestamp), sets the order's
	 * paid date (the money physically changed hands — this is the
	 * accounting-truth moment for COD) and leaves an order-note audit
	 * trail. Only then does the UI (and the server-side gate in
	 * ajax_update_delivery_status / mark_delivered) allow marking the
	 * order delivered.
	 *
	 * @since 1.6.0
	 */
	public function ajax_confirm_cash_collected()
	{
		check_ajax_referer('routew_delivery_action', 'nonce');

		if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
			wp_send_json_error(array('message' => __('Unauthorized access.', 'routemile-for-woocommerce')), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;

		if (!$order) {
			wp_send_json_error(array('message' => __('Invalid order.', 'routemile-for-woocommerce')));
		}

		$assigned_id = $order->get_meta('_routew_delivery_boy_id', true);
		if (empty($assigned_id) || (int) $assigned_id !== (int) get_current_user_id()) {
			wp_send_json_error(array('message' => __('You are not assigned to this order.', 'routemile-for-woocommerce')), 403);
		}

		if ('cod' !== $order->get_payment_method()) {
			wp_send_json_error(array('message' => __('This order is not cash on delivery.', 'routemile-for-woocommerce')), 400);
		}

		if ('routew-picked-up' !== $order->get_status()) {
			wp_send_json_error(array('message' => __('Cash can only be confirmed once the order is picked up.', 'routemile-for-woocommerce')), 400);
		}

		if (self::is_cash_collected($order)) {
			// Idempotent: already confirmed — treat as success so a
			// double-tap never blocks the agent. Still return the fresh
			// card so the client unlocks the deliver button.
			wp_send_json_success($this->build_action_response($order));
		}

		$order->update_meta_data('_routew_cash_collected', time());

		// Accounting truth: for COD the payment is received the moment
		// the agent collects the cash. Only set when not already set —
		// never rewrite payment history.
		if (!$order->get_date_paid()) {
			$order->set_date_paid(time());
		}

		$order->save();

		$order->add_order_note(sprintf(
			/* translators: 1: formatted amount, 2: agent name. */
			__('Cash of %1$s collected by delivery agent %2$s.', 'routemile-for-woocommerce'),
			wp_strip_all_tags($order->get_formatted_order_total()),
			wp_get_current_user()->display_name
		));

		wp_send_json_success($this->build_action_response($order));
	}

	/**
	 * Handle "Picked Up" action from Delivery Dashboard.
	 *
	 * @since 1.0.0
	 */
public function mark_picked_up()
    {
        $order = $this->verify_delivery_action();
        // State-guard: only allow the "picked up" transition from the
        // pre-pickup states. mark_delivered() guards its transition; this
        // one didn't, so a stale form submission or a duplicate form
        // could roll back an already-completed order to routew-picked-up.
        // (AUDIT-FIXES M4)
        $valid_from = array('routew-assigned', 'routew-in-kitchen');
        if (!in_array($order->get_status(), $valid_from, true)) {
            wp_die(esc_html__('Order cannot be marked picked up from its current status.', 'routemile-for-woocommerce'));
        }
        $order->update_status('routew-picked-up', __('Order picked up by delivery agent.', 'routemile-for-woocommerce'));

        wp_safe_redirect(home_url('/delivery-dashboard/?updated=1'));
        exit;
    }

    /**
     * Handle "Delivered" action from Delivery Dashboard.
     *
     * @since 1.0.0
     */
    public function mark_delivered()
    {
        $order = $this->verify_delivery_action();
        // Must already be picked up (1.2.16).
        if ('routew-picked-up' !== $order->get_status()) {
            wp_die(esc_html__('You must mark the order as picked up before marking it delivered.', 'routemile-for-woocommerce'));
        }
        // COD gate — same rule the AJAX path enforces.
        if ('cod' === $order->get_payment_method() && !self::is_cash_collected($order)) {
            wp_die(esc_html__('This is a cash-on-delivery order. Please confirm you collected the cash before marking it delivered.', 'routemile-for-woocommerce'));
        }
        // AUDIT-FIX 1.6.1: never let a rider force `completed` on an
        // unpaid order — the order's payment_method decides whether the
        // customer already paid (gateway) or the agent collected cash.
        $gate = ROUTEW_Order_Lifecycle::can_complete_order($order);
        if (is_wp_error($gate)) {
            wp_die(esc_html($gate->get_error_message()));
        }
        $order->update_status('completed', __('Order delivered by delivery agent.', 'routemile-for-woocommerce'));

        wp_safe_redirect(home_url('/delivery-dashboard/?delivered=1'));
        exit;
    }

	/**
	 * Verify delivery action: auth, nonce, order ownership.
	 *
	 * @return WC_Order Validated order object.
	 * @since 1.1.0
	 */
	private function verify_delivery_action()
	{
		if (!is_user_logged_in() || !current_user_can('routew_delivery_access')) {
			wp_die(esc_html__('Unauthorized.', 'routemile-for-woocommerce'));
		}

		$nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
		if (!wp_verify_nonce($nonce, 'routew_delivery_action')) {
			wp_die(esc_html__('Invalid nonce.', 'routemile-for-woocommerce'));
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;

		if (!$order) {
			wp_die(esc_html__('Invalid order.', 'routemile-for-woocommerce'));
		}

		$assigned_id = $order->get_meta('_routew_delivery_boy_id', true);
		if (empty($assigned_id) || (int) $assigned_id !== (int) get_current_user_id()) {
			wp_die(esc_html__('You are not assigned to this order.', 'routemile-for-woocommerce'));
		}

		return $order;
	}

	/**
	 * Agent initiates a cash hand-over request.
	 *
	 * Only the agent themself can request their own settlement; the
	 * amount is computed server-side by ROUTEW_Agent_Cash. A manager or
	 * admin then accepts or rejects it from the deliveries dashboard.
	 *
	 * @since 1.4.0
	 */
	public function handle_cash_settlement()
	{
		$agent_id = isset($_POST['agent_id']) ? absint(wp_unslash($_POST['agent_id'])) : 0;
		$nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

		if (!$agent_id || !wp_verify_nonce($nonce, 'routew_settle_cash_' . $agent_id)) {
			wp_die(esc_html__('Security verification failed. Please try again.', 'routemile-for-woocommerce'));
		}

		if (!is_user_logged_in() || get_current_user_id() !== $agent_id || !current_user_can('routew_delivery_access')) {
			wp_die(esc_html__('Only the delivery agent can request a hand-over of their own collected cash.', 'routemile-for-woocommerce'));
		}

		$amount = ROUTEW_Agent_Cash::create_request($agent_id, get_current_user_id());

		if (null === $amount) {
			// Nothing outstanding, or a request is already awaiting review.
			$back = wp_get_referer() ? wp_get_referer() : home_url('/delivery-dashboard/');
			wp_safe_redirect(add_query_arg('settled', 'none', remove_query_arg(array('settled', 'settle_error'), $back)));
			exit;
		}

		$note = sprintf(
			/* translators: 1: formatted amount, 2: agent name. */
			__('Cash hand-over of %1$s requested by %2$s — awaiting manager approval.', 'routemile-for-woocommerce'),
			wp_strip_all_tags(wc_price($amount)),
			wp_get_current_user()->display_name
		);

		// Order note on the newest covered order only, to avoid spam.
		$newest = wc_get_orders(array(
			'limit' => 1,
			'meta_key' => '_routew_delivery_boy_id',
			'meta_value' => $agent_id,
			'status' => array('completed'),
		));
		if (!empty($newest)) {
			$newest[0]->add_order_note($note);
		}

		$back = wp_get_referer() ? wp_get_referer() : home_url('/delivery-dashboard/');
		wp_safe_redirect(add_query_arg('settled', '1', remove_query_arg(array('settled', 'settle_error'), $back)));
		exit;
	}

	/**
	 * Manager/admin accepts or rejects a pending hand-over request.
	 *
	 * @since 1.4.0
	 */
	public function handle_settlement_review()
	{
		$id = isset($_POST['settlement_id']) ? sanitize_text_field(wp_unslash($_POST['settlement_id'])) : '';
		$decision = isset($_POST['decision']) ? sanitize_key(wp_unslash($_POST['decision'])) : '';
		$nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';

		if (!$id || !in_array($decision, array(ROUTEW_Agent_Cash::STATUS_ACCEPTED, ROUTEW_Agent_Cash::STATUS_REJECTED), true)
			|| !wp_verify_nonce($nonce, 'routew_review_cash_' . $id)) {
			wp_die(esc_html__('Security verification failed. Please try again.', 'routemile-for-woocommerce'));
		}

		if (!current_user_can('edit_shop_orders')) {
			wp_die(esc_html__('Only managers and admins can approve cash hand-overs.', 'routemile-for-woocommerce'));
		}

		$result = ROUTEW_Agent_Cash::review_request($id, $decision, get_current_user_id());
		if (null === $result) {
			$back = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=routew-deliveries-dashboard&parent_page=woocommerce');
			wp_safe_redirect($back);
			exit;
		}

		$reviewer = wp_get_current_user()->display_name;
		if (ROUTEW_Agent_Cash::STATUS_ACCEPTED === $decision) {
			$note = sprintf(
				/* translators: 1: formatted amount, 2: reviewer name. */
				__('Cash hand-over of %1$s ACCEPTED by %2$s. Agent balance cleared.', 'routemile-for-woocommerce'),
				wp_strip_all_tags(wc_price($result['amount'])),
				$reviewer
			);
		} else {
			$note = sprintf(
				/* translators: 1: formatted amount, 2: reviewer name. */
				__('Cash hand-over request of %1$s REJECTED by %2$s. Amount stays on the agent\'s balance.', 'routemile-for-woocommerce'),
				wp_strip_all_tags(wc_price($result['amount'])),
				$reviewer
			);
		}

		// Notify both sides via an order note on the newest covered order.
		$newest = wc_get_orders(array(
			'limit' => 1,
			'meta_key' => '_routew_delivery_boy_id',
			'meta_value' => $result['agent_id'],
			'status' => array('completed'),
		));
		if (!empty($newest)) {
			$newest[0]->add_order_note($note);
		}

		$back = wp_get_referer() ? wp_get_referer() : admin_url('admin.php?page=routew-deliveries-dashboard&parent_page=woocommerce');
		wp_safe_redirect(add_query_arg('cash_reviewed', $decision, remove_query_arg('cash_reviewed', $back)));
		exit;
	}
}

new ROUTEW_Delivery_Boy_View();

// phpcs:enable
