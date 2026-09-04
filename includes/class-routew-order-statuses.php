<?php
if (!defined('ABSPATH')) {
	exit;
}
/**
 * Manages custom order statuses for the plugin.
 *
 * @since      1.0.0
 * @package    RouteMile
 * @author     MD MILLAT HOSEN <https://millat.is-a.dev/>
 */
class ROUTEW_Order_Statuses
{

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct()
	{
		add_action('init', array($this, 'register_order_statuses'));
		add_filter('wc_order_statuses', array($this, 'add_custom_statuses_to_wc'));

		// Stamp a GMT timestamp on the order every time it enters a
		// status. The customer tracking stepper reads these metas to show
		// the REAL time each delivery step happened instead of guessing
		// from date_modified (which moves on any later edit).
		//
		// Two hooks are needed because WooCommerce fires
		// woocommerce_order_status_changed ONLY for transitions with a
		// previous status (WC_Order::set_status records none for the
		// initial assignment — a COD order created directly as
		// "processing" would otherwise never get that stamp):
		//  - woocommerce_new_order  → the status an order is CREATED with
		//  - woocommerce_order_status_changed → every later transition,
		//    whatever the source (admin dashboard, agent PWA, manual
		//    admin edit, REST, gateway payment, automation).
		add_action('woocommerce_new_order', array($this, 'record_initial_status_timestamp'), 10, 2);
		add_action('woocommerce_order_status_changed', array($this, 'record_status_timestamp'), 10, 4);
	}

	/**
	 * Record the status an order was created with (the transition hook
	 * never fires for the initial assignment).
	 *
	 * The value is only written when the meta does not exist yet, so this
	 * can never overwrite a later, more accurate transition stamp.
	 *
	 * @param int      $order_id Order ID.
	 * @param WC_Order $order    Order object.
	 * @since 1.6.0
	 */
	public function record_initial_status_timestamp($order_id, $order)
	{
		$order = ($order instanceof WC_Order) ? $order : wc_get_order($order_id);
		if (!$order) {
			return;
		}

		$status = $order->get_status();
		if ('' === $status || in_array($status, array('new', 'auto-draft', 'checkout-draft', 'draft'), true)) {
			return;
		}

		$meta_key = '_routew_status_at_' . $status;
		if ('' !== (string) $order->get_meta($meta_key, true)) {
			return; // a real transition already stamped this one
		}

		$order->update_meta_data($meta_key, time());
		$order->save();
	}

	/**
	 * Record when the order entered its (new) status, as order meta.
	 *
	 * Fired by woocommerce_order_status_changed — the single official
	 * hook that fires for EVERY transition regardless of the source
	 * (admin dashboard, agent PWA, manual admin edit, REST, automation).
	 *
	 * The meta key is `_routew_status_at_{status}` with a raw slug
	 * (e.g. `_routew_status_at_routew-picked-up`,
	 * `_routew_status_at_processing`). The value is a GMT unix
	 * timestamp; ROUTEW_Shortcodes::step_datetime() converts it to a
	 * localised WC_DateTime for display.
	 *
	 * Re-entering a status overwrites the stamp with the latest time —
	 * correct for reassignment flows (assigned → in-kitchen → assigned).
	 *
	 * save() is safe here: WC resets the transition queue before firing
	 * this hook (class-wc-order.php status_transition()), so the nested
	 * save cannot re-trigger it.
	 *
	 * @param int      $order_id    Order ID.
	 * @param string   $status_from Previous status slug.
	 * @param string   $status_to   New status slug.
	 * @param WC_Order $order       Order object (WC 3.0+).
	 * @since 1.6.0
	 */
	public function record_status_timestamp($order_id, $status_from, $status_to, $order)
	{
		$order = ($order instanceof WC_Order) ? $order : wc_get_order($order_id);
		if (!$order) {
			return;
		}

		$order->update_meta_data('_routew_status_at_' . $status_to, time());
		$order->save();
	}

	/**
	 * Register our custom order statuses with WordPress.
	 *
	 * Labels come from ROUTEW_Stage_Labels so an admin rename
	 * ("Sent to kitchen", "Out for delivery", …) flows into the WC
	 * admin status dropdown and the order list without touching the
	 * stored status slugs.
	 *
	 * @since    1.0.0
	 * @since    1.6.0 Admin-renameable labels via ROUTEW_Stage_Labels.
	 */
	public function register_order_statuses()
	{
		// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralSingular, WordPress.WP.I18n.NonSingularStringLiteralPlural
		// The label IS a single text string at runtime — the admin's saved
		// stage name (defaults are translated literals); the count span is
		// the only concatenated literal part. Re-enabled at EOF.
		$stages = array(
			'wc-routew-in-kitchen' => ROUTEW_Stage_Labels::get('kitchen'),
			'wc-routew-assigned' => ROUTEW_Stage_Labels::get('assigned'),
			'wc-routew-picked-up' => ROUTEW_Stage_Labels::get('picked_up'),
		);

		foreach ($stages as $slug => $stage) {
			$label = $stage ? $stage['label'] : $slug;
			register_post_status($slug, array(
				'label' => $label,
				'public' => true,
				'exclude_from_search' => false,
				'show_in_admin_all_list' => true,
				'show_in_admin_status_list' => true,
				// No 'label_count' here: the standard WP pattern (literal gettext
				// string) can't accommodate our admin-configurable status labels
				// (they're stored in the DB, not the .pot file, so the gettext
				// parser can't translate them). The stock post-list table will
				// show the label without a count badge; our own deliveries
				// dashboard renders per-status counts separately.
			));
		}
		// phpcs:enable
	}

	/**
	 * Add our custom statuses to the list of WooCommerce order statuses.
	 *
	 * @param   array   $order_statuses     The existing order statuses.
	 * @return  array   $order_statuses     The modified order statuses.
	 * @since   1.0.0
	 * @since   1.6.0 Admin-renameable labels via ROUTEW_Stage_Labels.
	 */
	public function add_custom_statuses_to_wc($order_statuses)
	{
		$stage_labels = array(
			'wc-routew-in-kitchen' => ROUTEW_Stage_Labels::get('kitchen'),
			'wc-routew-assigned' => ROUTEW_Stage_Labels::get('assigned'),
			'wc-routew-picked-up' => ROUTEW_Stage_Labels::get('picked_up'),
		);

		$new_order_statuses = array();
		$inserted = false;

		// Add the new statuses after 'processing'
		foreach ($order_statuses as $key => $status) {
			$new_order_statuses[$key] = $status;

			if ('wc-processing' === $key) {
				foreach ($stage_labels as $slug => $stage) {
					$new_order_statuses[$slug] = $stage ? $stage['label'] : $slug;
				}
				$inserted = true;
			}
		}

		// If we didn't find 'wc-processing', append our statuses at the end
		if (!$inserted) {
			foreach ($stage_labels as $slug => $stage) {
				$new_order_statuses[$slug] = $stage ? $stage['label'] : $slug;
			}
		}

		return $new_order_statuses;
	}
}

new ROUTEW_Order_Statuses();

// phpcs:enable
