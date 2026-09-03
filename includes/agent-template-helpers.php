<?php
/**
 * Delivery agent template helpers.
 *
 * The order card + SVG icon helpers used by the agent PWA template.
 * Extracted into a dedicated file so the AJAX action handlers can
 * render a fresh order card on demand — without the agent template
 * having to load (the template is a full HTML page, the AJAX handler
 * only wants one card's markup).
 *
 * Loaded by:
 *  - templates/delivery-dashboard-template.php  (full page render)
 *  - includes/class-routew-delivery-boy-view.php  (AJAX handler)
 *
 * @since 1.6.0
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('routew_agent_icon')) {
	/**
	 * Return an inline SVG icon for the agent PWA.
	 *
	 * @param string $name Icon key.
	 * @return string SVG markup, or '' for unknown keys.
	 */
	function routew_agent_icon($name)
	{
		$paths = array(
			'person' => '<path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm0 2c-3.3 0-8 1.7-8 5v2h16v-2c0-3.3-4.7-5-8-5Z"/>',
			'phone' => '<path d="M6.6 10.8a15.6 15.6 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.2 1.2.4 2.6.6 3.9.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.4c.6 0 1 .4 1 1 0 1.3.2 2.7.6 3.9.1.4 0 .8-.2 1.1l-2.2 2.8Z"/>',
			'home' => '<path d="M12 3.2 3 11h2.4v9h5.1v-5.4h3v5.4h5.1v-9H21L12 3.2Z"/>',
			'ruler' => '<path d="M3.8 15.2 15.2 3.8a1 1 0 0 1 1.4 0l3.6 3.6a1 1 0 0 1 0 1.4L8.8 20.2a1 1 0 0 1-1.4 0l-3.6-3.6a1 1 0 0 1 0-1.4Zm3.6 1.4 1.4-1.4-1.1-1.1 1.4-1.4 1.1 1.1 1.4-1.4-1.1-1.1 1.4-1.4 1.1 1.1 1.4-1.4-1.1-1.1 1.4-1.4 3.3 3.3L7.4 19.7l-1.1-1.1Z"/>',
			'card' => '<path d="M4 5h16a1 1 0 0 1 1 1v3H3V6a1 1 0 0 1 1-1Zm-1 6h18v7a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-7Zm3 3v2h5v-2H6Z"/>',
			'cash' => '<path d="M3 6h18v12H3V6Zm9 2.5A3.5 3.5 0 1 0 12 15.5 3.5 3.5 0 0 0 12 8.5ZM5 8v2h2V8H5Zm12 6v2h2v-2h-2Z"/>',
			'note' => '<path d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 1.5V8h4.5L14 3.5ZM7 12h10v1.6H7V12Zm0 3.4h10V17H7v-1.6Z"/>',
			'pin' => '<path d="M12 2a7 7 0 0 1 7 7c0 5.2-7 13-7 13S5 14.2 5 9a7 7 0 0 1 7-7Zm0 9.6A2.6 2.6 0 1 0 12 6.4a2.6 2.6 0 0 0 0 5.2Z"/>',
			'box' => '<path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2Zm0 2.3 6.3 3.2L12 10.7 5.7 7.5 12 4.3Zm-7 4.9 6 3v7.2l-6-3V9.2Zm8 10.2v-7.2l6-3v7.2l-6 3Z"/>',
			'flag' => '<path d="M5 3v18h2v-7h10.6l-2.2-4 2.2-4H7V3H5Z"/>',
			'bell' => '<path d="M12 22a2.3 2.3 0 0 0 2.3-2.3H9.7A2.3 2.3 0 0 0 12 22Zm7-5.4v-1l-1.7-1.7v-4A5.5 5.5 0 0 0 13 4.6V3.5a1 1 0 0 0-2 0v1.1a5.5 5.5 0 0 0-4.3 5.3v4L5 15.6v1h14Z"/>',
			'clock' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 10.6 4 2.3-1 1.7-5-2.9V6h2v6.6Z"/>',
		);

		if (!isset($paths[$name])) {
			return '';
		}

		return '<svg class="routew-svg-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
	}
}

if (!function_exists('routew_render_order_card')) {
	/**
	 * Render one agent order card.
	 *
	 * @param WC_Order $order Order assigned to the current agent.
	 * @param array    $args  Optional flags: 'history' renders the compact
	 *                        delivered view (no status buttons, delivered date
	 *                        and collected amount shown instead).
	 * @return string Rendered card HTML.
	 * @since 1.0.0
	 */
	function routew_render_order_card($order, $args = array())
	{
		$history = !empty($args['history']);
		$shipping_address = $order->get_formatted_shipping_address();

		// Get new delivery details
		$delivery_address = $order->get_meta('_routew_delivery_address', true);
		$delivery_lat = $order->get_meta('_routew_delivery_lat', true);
		$delivery_lng = $order->get_meta('_routew_delivery_lng', true);
		$delivery_distance = $order->get_meta('_routew_delivery_distance', true);

		// Fallback to old unit field for backward compatibility
		$unit = $order->get_meta('_routew_address_unit', true);
		$status = $order->get_status();
		$is_cod = 'cod' === $order->get_payment_method();
		// COD confirmation state: > 0 = the agent confirmed the cash
		// was physically collected (value = GMT timestamp).
		$cash_collected_at = (int) $order->get_meta('_routew_cash_collected', true);
		$cash_collected = $cash_collected_at > 0;

		// Delivery instructions ONLY for the agent. The checkout "order
		// note" (customer_note) is a kitchen note — the manager/admin reads
		// it on the order screen and passes it to the kitchen; it must not
		// reach the delivery agent's phone.
		$instructions = trim((string) $order->get_meta('_routew_delivery_instructions', true));

		$completed = $order->get_date_completed();

		// Map WC order status to the design-system pill variant + the
		// admin's stage label (tinted delivery-state pills — see
		// tools/ui/scss/_components.scss; renames come from
		// ROUTEW_Stage_Labels so manager wording like "Out for
		// delivery" shows here too).
		$status_pill_variant = ROUTEW_Stage_Labels::status_colour($status);
		$status_pill_label = ROUTEW_Stage_Labels::status_label($status);

		ob_start();
		?>
		<div class="card routew-order-card <?php echo $is_cod ? 'routew-order-card--cod' : ''; ?> <?php echo $history ? 'routew-order-card--history' : ''; ?>" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-order-status="<?php echo esc_attr($status); ?>">
			<div class="card-header routew-card-header">
				<h3 class="h6 mb-0">
					<?php printf(
						/* translators: %s: order number. */
						esc_html__('Order #%s', 'routemile-for-woocommerce'),
						esc_html($order->get_order_number())
					); ?>
					<?php if ($history && $completed): ?>
						<span class="routew-card-header__date small text-secondary ms-2">
							<?php echo esc_html($completed->date_i18n(get_option('date_format') . ' ' . get_option('time_format'))); ?>
						</span>
					<?php endif; ?>
				</h3>
				<span class="routew-pill routew-pill--<?php echo esc_attr($status_pill_variant); ?>"><?php echo esc_html($status_pill_label); ?></span>
			</div>
			<?php if ($is_cod): ?>
				<?php if ($cash_collected): ?>
					<div class="routew-cod-strip routew-cod-strip--collected" role="status">
						<?php echo routew_agent_icon('cash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<span class="routew-cod-strip__label">
							<?php
							if ($cash_collected_at > 0) {
								/* translators: %s: collection datetime */
								printf(esc_html__('Cash collected %s', 'routemile-for-woocommerce'), esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $cash_collected_at + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS))));
							} else {
								esc_html_e('Cash collected', 'routemile-for-woocommerce');
							}
							?>
						</span>
						<span class="routew-cod-strip__amount"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
					</div>
				<?php else: ?>
					<div class="routew-cod-strip <?php echo $history ? 'routew-cod-strip--settled' : ''; ?>" role="status">
						<?php echo routew_agent_icon('cash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<span class="routew-cod-strip__label"><?php echo $history ? esc_html__('Collected on delivery', 'routemile-for-woocommerce') : esc_html__('Collect on delivery', 'routemile-for-woocommerce'); ?></span>
						<span class="routew-cod-strip__amount"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></span>
						<span class="routew-cod-strip__method"><?php echo esc_html($order->get_payment_method_title()); ?></span>
					</div>
				<?php endif; ?>
			<?php else: ?>
				<div class="routew-prepaid-strip" role="status">
					<?php echo routew_agent_icon('card'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
					<span class="routew-prepaid-strip__label">
						<?php
						$routew_method_title = $order->get_payment_method_title();
						if ('' !== $routew_method_title) {
							// translators: %s = payment method title, e.g. "Direct bank transfer".
							printf(esc_html__('Prepaid — %s. Nothing to collect.', 'routemile-for-woocommerce'), esc_html($routew_method_title));
						} else {
							esc_html_e('Prepaid. Nothing to collect.', 'routemile-for-woocommerce');
						}
						?>
					</span>
				</div>
			<?php endif; ?>
			<div class="card-body routew-card-body">
				<div class="routew-card-section">
					<p class="mb-2"><?php echo routew_agent_icon('person'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<strong><?php esc_html_e('Customer:', 'routemile-for-woocommerce'); ?></strong>
						<?php echo esc_html($order->get_formatted_billing_full_name()); ?></p>
					<p class="mb-2"><?php echo routew_agent_icon('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<strong><?php esc_html_e('Phone:', 'routemile-for-woocommerce'); ?></strong>
						<a class="routew-call-link"
							href="tel:<?php echo esc_attr($order->get_billing_phone()); ?>"><?php echo esc_html($order->get_billing_phone()); ?></a>
					</p>

					<?php if ($delivery_address): ?>
						<p class="mb-2"><?php echo routew_agent_icon('home'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
							<strong><?php esc_html_e('Delivery Address:', 'routemile-for-woocommerce'); ?></strong>
							<?php echo esc_html($delivery_address); ?></p>
						<?php if ($delivery_distance): ?>
							<p class="mb-2"><?php echo routew_agent_icon('ruler'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
								<strong><?php esc_html_e('Distance:', 'routemile-for-woocommerce'); ?></strong>
								<?php echo esc_html($delivery_distance); ?> km</p>
						<?php endif; ?>
					<?php else: ?>
						<p class="mb-2"><?php echo routew_agent_icon('home'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
							<strong><?php esc_html_e('Address:', 'routemile-for-woocommerce'); ?></strong>
							<?php echo wp_kses_post($shipping_address); ?></p>
						<?php if ($unit): ?>
							<p class="mb-2"><?php echo routew_agent_icon('box'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
								<strong><?php esc_html_e('Unit:', 'routemile-for-woocommerce'); ?></strong>
								<?php echo esc_html($unit); ?></p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
				<?php if ($instructions): ?>
					<div class="routew-customer-note">
						<div class="routew-customer-note__title">
							<?php echo routew_agent_icon('note'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
							<?php esc_html_e('Delivery instructions', 'routemile-for-woocommerce'); ?>
						</div>
						<p class="routew-customer-note__line"><?php echo esc_html($instructions); ?></p>
					</div>
				<?php endif; ?>
			</div>
			<div class="card-footer routew-card-footer">
				<?php
				// Create precise map link using coordinates if available
				if ($delivery_lat && $delivery_lng && is_numeric($delivery_lat) && is_numeric($delivery_lng)) {
					// Use precise coordinate-based map link for exact pinpoint location
					$map_url = "https://www.google.com/maps?q=" . urlencode(trim($delivery_lat) . ',' . trim($delivery_lng));
					$map_label = __('Open Exact Location', 'routemile-for-woocommerce');
					$map_class = 'routew-button-map-precise';
				} elseif ($delivery_address) {
					// Use delivery address if coordinates are missing but delivery address is set
					$map_url = "https://www.google.com/maps/search/?api=1&query=" . urlencode(trim(wp_strip_all_tags($delivery_address)));
					$map_label = __('Search Delivery Address', 'routemile-for-woocommerce');
					$map_class = 'routew-button-map-address';
				} else {
					// Final fallback to shipping address
					$map_url = "https://www.google.com/maps/search/?api=1&query=" . urlencode(trim(wp_strip_all_tags($shipping_address)));
					$map_label = __('Search Location', 'routemile-for-woocommerce');
					$map_class = 'routew-button-map-fallback';
				}
				?>
				<a href="<?php echo esc_url($map_url); ?>" target="_blank" rel="noopener noreferrer"
					class="btn routew-button routew-button-map <?php echo esc_attr($map_class); ?>">
					<?php echo routew_agent_icon('pin'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
					<?php echo esc_html($map_label); ?></a>
				<a href="tel:<?php echo esc_attr($order->get_billing_phone()); ?>"
					class="btn routew-button routew-button-call" aria-label="<?php echo esc_attr__('Call customer', 'routemile-for-woocommerce'); ?>">
					<?php echo routew_agent_icon('phone'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
					<span class="routew-button-call__label"><?php esc_html_e('Call', 'routemile-for-woocommerce'); ?></span></a>
				<?php if (!$history && 'routew-assigned' === $status): ?>
					<button type="button" class="btn btn-primary routew-button routew-button-pickup routew-action-btn"
						data-action="routew_update_delivery_status" data-status="routew-picked-up"
						data-order-id="<?php echo esc_attr($order->get_id()); ?>"
						data-nonce="<?php echo esc_attr(wp_create_nonce('routew_delivery_action')); ?>">
						<?php echo routew_agent_icon('box'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<?php esc_html_e('Mark Picked Up', 'routemile-for-woocommerce'); ?>
					</button>
				<?php endif; ?>
				<?php if (!$history && 'routew-picked-up' === $status && $is_cod && !$cash_collected): ?>
					<?php
					// COD gate: the agent must confirm the cash was
					// physically collected BEFORE the order can be
					// marked delivered (enforced server-side too —
					// see ROUTEW_Delivery_Boy_View::ajax_confirm_cash_collected).
					?>
					<button type="button" class="btn btn-warning routew-button routew-button-cash routew-action-btn"
						data-action="routew_confirm_cash_collected"
						data-order-id="<?php echo esc_attr($order->get_id()); ?>"
						data-nonce="<?php echo esc_attr(wp_create_nonce('routew_delivery_action')); ?>"
						data-confirm="<?php esc_attr_e('Confirm you received the full cash amount for this order?', 'routemile-for-woocommerce'); ?>">
						<?php echo routew_agent_icon('cash'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
					<?php
					/* translators: %s: formatted order total */
					printf(esc_html__('Cash Collected (%s)', 'routemile-for-woocommerce'), esc_html(wp_strip_all_tags($order->get_formatted_order_total())));
					?>
					</button>
					<button type="button" class="btn btn-success routew-button routew-button-deliver routew-button-deliver--locked" disabled
						aria-describedby="routew-cash-hint-<?php echo esc_attr($order->get_id()); ?>">
						<?php echo routew_agent_icon('flag'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<?php esc_html_e('Mark Delivered', 'routemile-for-woocommerce'); ?>
					</button>
					<p class="routew-cash-hint" id="routew-cash-hint-<?php echo esc_attr($order->get_id()); ?>">
						<?php esc_html_e('Confirm the cash was collected to unlock marking this order delivered.', 'routemile-for-woocommerce'); ?>
					</p>
				<?php elseif (!$history && 'routew-picked-up' === $status): ?>
					<button type="button" class="btn btn-success routew-button routew-button-deliver routew-action-btn"
						data-action="routew_update_delivery_status" data-status="completed"
						data-order-id="<?php echo esc_attr($order->get_id()); ?>"
						data-nonce="<?php echo esc_attr(wp_create_nonce('routew_delivery_action')); ?>">
						<?php echo routew_agent_icon('flag'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG ?>
						<?php esc_html_e('Mark Delivered', 'routemile-for-woocommerce'); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
